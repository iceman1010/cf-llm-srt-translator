<?php
/**
 * Test which languages each CF model can translate to.
 * Sends a simple subtitle batch and checks if valid JSON comes back with non-empty translations.
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$accountId = $_ENV['CLOUDFLARE_ACCOUNT_ID'];
$apiToken = $_ENV['CLOUDFLARE_API_TOKEN'];

// Test languages: code => name
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'de' => 'German',
    'es' => 'Spanish',
    'pt' => 'Portuguese',
    'it' => 'Italian',
    'nl' => 'Dutch',
    'ru' => 'Russian',
    'pl' => 'Polish',
    'cs' => 'Czech',
    'ro' => 'Romanian',
    'hu' => 'Hungarian',
    'bg' => 'Bulgarian',
    'hr' => 'Croatian',
    'sk' => 'Slovak',
    'sl' => 'Slovenian',
    'sr' => 'Serbian',
    'bs' => 'Bosnian',
    'mk' => 'Macedonian',
    'sq' => 'Albanian',
    'el' => 'Greek',
    'tr' => 'Turkish',
    'sv' => 'Swedish',
    'da' => 'Danish',
    'no' => 'Norwegian',
    'fi' => 'Finnish',
    'et' => 'Estonian',
    'lv' => 'Latvian',
    'lt' => 'Lithuanian',
    'uk' => 'Ukrainian',
    'be' => 'Belarusian',
    'ka' => 'Georgian',
    'hy' => 'Armenian',
    'is' => 'Icelandic',
    'ga' => 'Irish',
    'cy' => 'Welsh',
    'eu' => 'Basque',
    'ca' => 'Catalan',
    'gl' => 'Galician',
    'mt' => 'Maltese',
    'lb' => 'Luxembourgish',
    'af' => 'Afrikaans',
    'ar' => 'Arabic',
    'he' => 'Hebrew',
    'fa' => 'Persian',
    'ur' => 'Urdu',
    'hi' => 'Hindi',
    'bn' => 'Bengali',
    'ta' => 'Tamil',
    'te' => 'Telugu',
    'mr' => 'Marathi',
    'gu' => 'Gujarati',
    'kn' => 'Kannada',
    'ml' => 'Malayalam',
    'pa' => 'Punjabi',
    'si' => 'Sinhala',
    'ne' => 'Nepali',
    'zh' => 'Chinese',
    'ja' => 'Japanese',
    'ko' => 'Korean',
    'th' => 'Thai',
    'vi' => 'Vietnamese',
    'id' => 'Indonesian',
    'ms' => 'Malay',
    'tl' => 'Tagalog',
    'sw' => 'Swahili',
    'mn' => 'Mongolian',
    'km' => 'Khmer',
    'lo' => 'Lao',
    'my' => 'Burmese',
    'az' => 'Azerbaijani',
    'uz' => 'Uzbek',
    'kk' => 'Kazakh',
];

$models = [
    'llama-3.1-8b' => [
        'model_id' => '@cf/meta/llama-3.1-8b-instruct-awq',
        'no_think' => false,
        'reasoning' => false,
    ],
    'qwen3-30b' => [
        'model_id' => '@cf/qwen/qwen3-30b-a3b-fp8',
        'no_think' => true,
        'reasoning' => true,
    ],
    'glm-4.7-flash' => [
        'model_id' => '@cf/zai-org/glm-4.7-flash',
        'no_think' => false,
        'reasoning' => true,
    ],
    'deepseek-r1-32b' => [
        'model_id' => '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b',
        'no_think' => false,
        'reasoning' => true,
    ],
];

// Select model from CLI arg
$modelKey = $argv[1] ?? null;
if (!$modelKey || !isset($models[$modelKey])) {
    echo "Usage: php test-languages.php <model-key>\n";
    echo "Available: " . implode(', ', array_keys($models)) . "\n";
    exit(1);
}

$model = $models[$modelKey];
$modelId = $model['model_id'];

// Test batch - 3 simple English subtitles
$batch = [
    ['index' => '0', 'text' => 'Hello, how are you?'],
    ['index' => '1', 'text' => "I'm fine, thank you.\nHow about you?"],
    ['index' => '2', 'text' => '<i>Goodbye!</i>'],
];

$results = [];
$passed = [];
$failed = [];

echo "Testing model: {$modelKey} ({$modelId})\n";
echo str_repeat('=', 70) . "\n";

foreach ($languages as $code => $langName) {
    if ($code === 'en') continue; // Skip translating to English from English

    $systemPrompt = "You are a subtitle translator. Translate the `text` field of each JSON item to {$langName}. "
        . "Respond with ONLY a valid JSON array. No markdown fences. No explanation. "
        . "Keep the same structure: [{\"index\":\"0\",\"text\":\"...\"},...]";

    $userMessage = json_encode($batch, JSON_UNESCAPED_UNICODE);
    if ($model['no_think']) {
        $userMessage .= ' /no_think';
    }

    $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$modelId}";
    $body = json_encode([
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'temperature' => 0.3,
        'max_tokens' => 2048,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$apiToken}",
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        echo sprintf("  %-4s %-15s FAIL (HTTP %d)\n", $code, $langName, $httpCode);
        $failed[] = $code;
        if ($httpCode === 429) {
            echo "  Rate limited! Waiting 30s...\n";
            sleep(30);
        }
        continue;
    }

    $data = json_decode($response, true);

    // Extract response text from either format
    $responseText = null;
    if (isset($data['result']['response'])) {
        $responseText = $data['result']['response'];
    } elseif (isset($data['result']['choices'][0]['message']['content'])) {
        $responseText = $data['result']['choices'][0]['message']['content'];
    }

    if (!$responseText) {
        echo sprintf("  %-4s %-15s FAIL (no content)\n", $code, $langName);
        $failed[] = $code;
        continue;
    }

    // Try to extract JSON
    $translated = null;

    // Strip think tags
    $cleaned = preg_replace('/<think>.*?<\/think>/s', '', $responseText);
    $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
    $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
    $cleaned = trim($cleaned);

    $translated = json_decode($cleaned, true);

    // Try bracket extraction
    if (!is_array($translated)) {
        $first = strpos($cleaned, '[');
        $last = strrpos($cleaned, ']');
        if ($first !== false && $last !== false && $last > $first) {
            $translated = json_decode(substr($cleaned, $first, $last - $first + 1), true);
        }
    }

    if (!is_array($translated) || count($translated) !== 3) {
        echo sprintf("  %-4s %-15s FAIL (bad JSON, got: %.60s)\n", $code, $langName, $cleaned);
        $failed[] = $code;
        continue;
    }

    // Check all 3 items have non-empty text that differs from English
    $allGood = true;
    $sample = '';
    foreach ($translated as $idx => $item) {
        if (empty($item['text'])) {
            $allGood = false;
            break;
        }
        if ($idx === 0) $sample = $item['text'];
    }

    if ($allGood) {
        echo sprintf("  %-4s %-15s OK   \"%s\"\n", $code, $langName, mb_substr($sample, 0, 45));
        $passed[] = $code;
    } else {
        echo sprintf("  %-4s %-15s FAIL (empty translation)\n", $code, $langName);
        $failed[] = $code;
    }

    // Small delay to avoid rate limits
    usleep(500000); // 0.5s
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Model: {$modelKey}\n";
echo "Passed: " . count($passed) . " / " . (count($languages) - 1) . "\n";
echo "Languages: " . implode(', ', $passed) . "\n";
echo "\nFailed: " . count($failed) . "\n";
if ($failed) {
    echo "Failed codes: " . implode(', ', $failed) . "\n";
}
