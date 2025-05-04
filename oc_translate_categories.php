<?php
#!/usr/bin/env php
/**
 * translate_categories.php
 *
 * CLI script to translate OpenCart category descriptions from one language to another
 * via OpenAI ChatGPT (gpt-4o). By default runs in dry-run mode.
 *
 * Usage:
 *   php translate_categories.php [--no-dry-run] [--verbose] [--source-lang-id=2] [--dest-lang-id=3]
 */

// 1. Parse CLI options
$options = getopt('', [
    'no-dry-run',
    'verbose',
    'source-lang-id::',
    'dest-lang-id::'
]);
$execute     = isset($options['no-dry-run']);
$verbose     = isset($options['verbose']);
$srcLangId   = isset($options['source-lang-id']) ? (int)$options['source-lang-id'] : 2;
$destLangId  = isset($options['dest-lang-id'])   ? (int)$options['dest-lang-id']   : 3;

// 2. Load DB config
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: config.php not found.\n");
    exit(1);
}
$dbConfig = require $configFile;
$host   = $dbConfig['host'] ?? '127.0.0.1';
$dbname = $dbConfig['name'] ?? die("Missing 'name' in config.php\n");
$user   = $dbConfig['user'] ?? die("Missing 'user' in config.php\n");
$pass   = $dbConfig['pass'] ?? '';
$port   = $dbConfig['port'] ?? 3306;

// 3. Connect to the database
$dsn = "mysql:host={$host};dbname={$dbname};port={$port};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 4. Fetch source-language rows
$sql = "SELECT category_id, name, description, description_bottom,
               meta_title, meta_description, meta_keyword, meta_h1
        FROM oc_category_description
        WHERE language_id = :src";
$stmt = $pdo->prepare($sql);
$stmt->execute([':src' => $srcLangId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo "Found {$total} categories with language_id={$srcLangId}.\n";
if (!$execute) {
    echo "[DRY RUN] No changes will be made. Use --no-dry-run to execute translations and update DB.\n";
    exit(0);
}

// 5. Ensure OpenAI key
$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "ERROR: Please set your OPENAI_API_KEY environment variable.\n");
    exit(1);
}
$openAiUrl = 'https://api.openai.com/v1/chat/completions';

// 6. Process each category
$count = 0;
foreach ($rows as $row) {
    $count++;
    $catId = $row['category_id'];
    if ($verbose) {
        echo "[{$count}/{$total}] Category ID {$catId}: ";
    }

    // Prepare payload of non-empty fields
    $payload = [];
    foreach (['name','description','description_bottom','meta_title','meta_description','meta_keyword','meta_h1'] as $field) {
        if (strlen(trim($row[$field])) > 0) {
            $payload[$field] = $row[$field];
        }
    }
    if (empty($payload)) {
        if ($verbose) echo "no content to translate, skipping.\n";
        continue;
    }

    // Build ChatGPT messages
    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are a translation assistant for a sports equipment and accessories store. ' .
                         'Translate the following JSON from Russian to Ukrainian. ' .
                         'Preserve JSON structure; translate only values; leave empty fields empty.'
        ],
        [
            'role'    => 'user',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]
    ];

    // Call OpenAI API
    $ch = curl_init($openAiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'gpt-4o',
            'messages'    => $messages,
            'temperature' => 0.2
        ], JSON_UNESCAPED_UNICODE)
    ]);
    $resp = curl_exec($ch);
    if (!$resp) {
        fwrite(STDERR, "OpenAI API error for Cat {$catId}: " . curl_error($ch) . "\n");
        curl_close($ch);
        continue;
    }
    curl_close($ch);

    $decoded = json_decode($resp, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $translated = json_decode($content, true);
    if (!is_array($translated)) {
        fwrite(STDERR, "Invalid JSON for Cat {$catId}: " . substr($content,0,200) . "\n");
        continue;
    }

    // Upsert into DB
    $fields = array_keys($payload);
    // prepare SET clauses
    $setParts = array_map(fn($f) => "`{$f}` = :{$f}", $fields);
    $setParts[] = "`language_id` = :dest";
    $setParts[] = "`category_id` = :cid";

    // check existing
    $check = $pdo->prepare("SELECT 1 FROM oc_category_description WHERE category_id=:cid AND language_id=:dest");
    $check->execute([':cid'=>$catId, ':dest'=>$destLangId]);

    if ($check->fetch()) {
        $sqlUp = "UPDATE oc_category_description SET " . implode(',', $setParts) .
                 " WHERE category_id=:cid AND language_id=:dest";
        $stmtUp = $pdo->prepare($sqlUp);
        $params = [':cid'=>$catId, ':dest'=>$destLangId];
        foreach ($fields as $f) {
            $params[":{$f}"] = $translated[$f] ?? '';
        }
        $stmtUp->execute($params);
        if ($verbose) echo "updated.\n";
    } else {
        $cols  = array_merge($fields, ['language_id','category_id']);
        $ph    = array_map(fn($c)=>":{$c}", $cols);
        $sqlIn = "INSERT INTO oc_category_description (`" . implode('`,`',$cols) . "`) VALUES (" . implode(',',$ph) . ")";
        $stmtIn = $pdo->prepare($sqlIn);
        $params = [':language_id'=>$destLangId, ':category_id'=>$catId];
        foreach ($fields as $f) {
            $params[":{$f}"] = $translated[$f] ?? '';
        }
        $stmtIn->execute($params);
        if ($verbose) echo "inserted.\n";
    }
}

echo "Translation complete. Processed {$count} categories.\n";

