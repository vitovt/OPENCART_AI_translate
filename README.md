# Opecart\_AI\_Translate

## Description

Opecart\_AI\_Translate is a PHP CLI script that helps OpenCart store owners automatically translate category descriptions (name, SEO metadata, HTML blocks, etc.) from one language to another using the OpenAI ChatGPT API (model `gpt-4o`). By default it runs in dry‑run mode, counting only how many categories will be processed, and it offers a safe `--no-dry-run` switch to apply real translations.

### Key Features

* Reads OpenCart DB credentials directly from `config.php` (supports `DB_PREFIX`).
* Fetches only non-empty fields
  * oc_translate_categories - translete "Category" entity, fileds: `name`, `description`, `description_bottom`, `meta_title`, `meta_description`, `meta_keyword`, `meta_h1`.
* Translates sports-store content 
  * In this implementation from Russian (default `language_id = 2`) to Ukrainian (default `language_id = 3`).
* Dry-run mode by default; use `--no-dry-run` to apply changes.
* `--verbose`, `--source-lang-id`, and `--dest-lang-id` options for flexibility.
* Inserts or updates translated rows in `oc_category_description`.

### Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/<your-username>/Opecart_AI_Translate.git
   cd Opecart_AI_Translate
   ```
2. Ensure `translate_categories.php` is executable:

   ```bash
   chmod +x translate_categories.php
   ```
3. Set your OpenAI API key:

   ```bash
   export OPENAI_API_KEY="sk-..."
   ```
4. Verify your `config.php` contains:

   ```php
   define('DB_HOSTNAME', 'hostname');
   define('DB_USERNAME', 'username');
   define('DB_PASSWORD', 'password');
   define('DB_DATABASE', 'database');
   define('DB_PORT',     '3306');
   define('DB_PREFIX',   'oc_');
   ```

### Usage Examples

* Dry-run (count only):

  ```bash
  php translate_categories.php
  ```
* Real translation + verbose logging:

  ```bash
  php translate_categories.php --no-dry-run --verbose
  ```
* Custom source/destination languages:

  ```bash
  php translate_categories.php --no-dry-run --source-lang-id=1 --dest-lang-id=4
  ```

### Prompt Template

\*.prompt - are prompts for generating this and similar scripts for tranlations. Feel free to customize it for other languages, entities or stores.


### Contributing

If you find bugs or want new features (e.g. custom HTML handling, batch parallel calls), please open an issue or submit a pull request.

### License

This project is released under the MIT License. Feel free to use and adapt it for your own needs.

