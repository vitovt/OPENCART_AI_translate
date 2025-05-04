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

$STORE_TYPE = 'sports equipment and accessories';
$SRC_LANG = 'Russian';
$DST_LANG = 'Ukrainian';

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

// 2. Load OpenCart config
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: config.php not found.\n");
    exit(1);
}
require $configFile; // defines DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT, DB_PREFIX

// Validate config constants
foreach (['DB_HOSTNAME','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_PORT','DB_PREFIX'] as $const) {
    if (!defined($const)) {
        fwrite(STDERR, "ERROR: {$const} not defined in config.php\n");
        exit(1);
    }
}

$host   = DB_HOSTNAME;
$user   = DB_USERNAME;
$pass   = DB_PASSWORD;
$dbname = DB_DATABASE;
$port   = DB_PORT;
$prefix = DB_PREFIX;

// 3. Connect via PDO
$dsn = "mysql:host={$host};dbname={$dbname};port={$port};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 4. Fetch source rows
$sql = "SELECT category_id, name, description, description_bottom, meta_title, meta_description, meta_keyword, meta_h1
        FROM {$prefix}category_description
        WHERE language_id = :src";
$stmt = $pdo->prepare($sql);
$stmt->execute([':src' => $srcLangId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo "Found {$total} categories with language_id={$srcLangId}.\n";

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
            'content' => 'You are a translation assistant for a ' . $STORE_TYPE . ' store. ' .
                         'Translate the following JSON from ' . $SRC_LANG . ' to ' . $DST_LANG . '. ' .
                         'Preserve JSON structure; translate only values; leave empty fields empty.'
        ],
        [
            'role'    => 'user',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]
    ];

    if ($verbose) {
        echo '$messages:' . var_export($messages, true);
    }
    if (!$execute) {
        continue;
    }
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
    $content = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
    $translated = json_decode($content, true);
    if (!is_array($translated)) {
        fwrite(STDERR, "Invalid JSON for Cat {$catId}: " . substr($content,0,200) . "\n");
        continue;
    }

    // Upsert into DB
    $fields = array_keys($payload);
    $setClauses = array_map(function($f) { return "`{$f}`=:{$f}"; }, $fields);
    $setClauses[] = "language_id=:dest";
    $setClauses[] = "category_id=:cid";

    // exists?
    $chk = $pdo->prepare("SELECT 1 FROM {$prefix}category_description WHERE category_id=:cid AND language_id=:dest");
    $chk->execute([':cid'=>$catId, ':dest'=>$destLangId]);
    if ($chk->fetch()) {
        $sqlUp = "UPDATE {$prefix}category_description SET " . implode(',', $setClauses) .
                 " WHERE category_id=:cid AND language_id=:dest";
        $stm = $pdo->prepare($sqlUp);
    } else {
        $cols = array_merge($fields, ['language_id','category_id']);
        $phs = array_map(function($c) { return ":{$c}"; }, $cols);
        $sqlUp = "INSERT INTO {$prefix}category_description (`" . implode('`,`',$cols) . "`) VALUES (".
                 implode(',',$phs) . ")";
        $stm = $pdo->prepare($sqlUp);
    }

    $params = [':dest'=>$destLangId, ':cid'=>$catId];
    foreach ($fields as $f) {
        $params[":{$f}"] = isset($translated[$f]) ? $translated[$f] : '';
    }
    if ($execute) {
        $stm->execute($params);
    }
    if ($verbose) echo "done.\n";
}

echo "Processed {$count}/{$total} categories.\n";
?>
