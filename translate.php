<?php

require_once __DIR__ . '/vendor/autoload.php';

use CloudflareSrt\Translator;
use Dotenv\Dotenv;
use WhiteCube\Lingua\Service as Lingua;

// Load credentials: env vars take priority, fall back to .env
if (empty(getenv('CLOUDFLARE_API_TOKEN')) || empty(getenv('CLOUDFLARE_ACCOUNT_ID'))) {
    $envDir = str_starts_with(__DIR__, 'phar://') ? getcwd() : __DIR__;
    if (file_exists($envDir . '/.env')) {
        $dotenv = Dotenv::createImmutable($envDir);
        $dotenv->load();
    } else {
        echo "Error: Set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID as env vars or in .env\n";
        exit(1);
    }
}

// CLI argument parsing
$options = getopt('', [
    'input:',
    'output::',
    'language:',
    'model::',
    'batch-size::',
    'temperature::',
    'max-tokens::',
    'description::',
    'no-think',
    'list-models',
    'list-languages',
]);

// List available models
if (isset($options['list-models'])) {
    $modelsPath = str_starts_with(__DIR__, 'phar://') ? 'phar://' . Phar::running(false) . '/llm-models.json' : __DIR__ . '/llm-models.json';
    $config = json_decode(file_get_contents($modelsPath), true);
    $models = $config['models'];

    echo "Available models:\n\n";
    foreach ($models as $key => $model) {
        $reasoning = $model['reasoning'] ? 'yes' : 'no';
        $langs = count($model['languages']);
        echo sprintf(
            "  %-20s context: %6dK  batch: %4d  reasoning: %-3s  languages: %d\n",
            $key,
            (int)($model['context_window'] / 1000),
            $model['batch_size'],
            $reasoning,
            $langs
        );
    }
    echo "\n";
    foreach ($models as $key => $model) {
        if (!empty($model['notes'])) {
            echo sprintf("  %-20s %s\n", $key, $model['notes']);
        }
    }
    exit(0);
}

// List languages for a specific model
if (isset($options['list-languages'])) {
    if (empty($options['model'])) {
        echo "Error: --model is required when using --list-languages.\n";
        echo "Usage: php translate.php --list-languages --model=<model_key>\n";
        exit(1);
    }
    try {
        $languages = Translator::listLanguages($options['model']);
        echo json_encode($languages) . "\n";
    } catch (\RuntimeException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

// Validate required arguments
if (empty($options['input']) || empty($options['language'])) {
    echo "Usage: php translate.php --input=<file> --language=<target_language> [options]\n\n";
    echo "Required:\n";
    echo "  --input=<file>           Input subtitle file (.srt, .vtt, .ass, etc.)\n";
    echo "  --language=<lang>        Target language name or ISO code (e.g., French, fr, fra)\n\n";
    echo "Optional:\n";
    echo "  --output=<file>          Output file path (default: auto-generated)\n";
    echo "  --model=<key>            Model key (default: qwen3-30b)\n";
    echo "                           Available: qwen3-30b, gpt-oss-120b, llama-4-scout, gemma-3-12b, mistral-small-3.1, sea-lion-27b\n";
    echo "  --batch-size=<n>         Override batch size from model config\n";
    echo "  --temperature=<float>    Override temperature (default: 0.6)\n";
    echo "  --max-tokens=<n>         Override max tokens (default: 8192)\n";
    echo "  --description=<text>     Additional context for translation\n";
    echo "  --no-think               Disable reasoning for reasoning models (faster, cheaper, lower quality)\n";
    echo "  --list-models            List available models and exit\n";
    exit(1);
}

// Validate and resolve language
try {
    $lingua = Lingua::create($options['language']);
    $targetLanguage = ucfirst($lingua->toName());
} catch (\Exception $e) {
    echo "Error: Unknown language \"{$options['language']}\". Use a language name (e.g., French) or ISO code (e.g., fr, fra).\n";
    exit(1);
}

echo "Target language: {$targetLanguage}\n";

try {
    $translator = new Translator([
        'api_token' => $_ENV['CLOUDFLARE_API_TOKEN'],
        'account_id' => $_ENV['CLOUDFLARE_ACCOUNT_ID'],
        'target_language' => $targetLanguage,
        'input_file' => $options['input'],
        'model' => $options['model'] ?? 'qwen3-30b',
        'output_file' => $options['output'] ?? null,
        'batch_size' => isset($options['batch-size']) ? (int)$options['batch-size'] : null,
        'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : null,
        'max_tokens' => isset($options['max-tokens']) ? (int)$options['max-tokens'] : null,
        'description' => $options['description'] ?? null,
        'no_think' => isset($options['no-think']),
    ]);

    $translator->translate();
} catch (\Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    exit(1);
}
