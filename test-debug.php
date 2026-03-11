<?php
/**
 * Debug a single language to see the raw response and attempt repair.
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$accountId = $_ENV['CLOUDFLARE_ACCOUNT_ID'];
$apiToken = $_ENV['CLOUDFLARE_API_TOKEN'];

$allLanguages = [
    'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
    'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian',
    'pl' => 'Polish', 'cs' => 'Czech', 'ro' => 'Romanian', 'hu' => 'Hungarian',
    'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
    'sr' => 'Serbian', 'bs' => 'Bosnian', 'mk' => 'Macedonian', 'sq' => 'Albanian',
    'el' => 'Greek', 'tr' => 'Turkish', 'sv' => 'Swedish', 'da' => 'Danish',
    'no' => 'Norwegian', 'fi' => 'Finnish', 'et' => 'Estonian', 'lv' => 'Latvian',
    'lt' => 'Lithuanian', 'uk' => 'Ukrainian', 'be' => 'Belarusian', 'ka' => 'Georgian',
    'hy' => 'Armenian', 'is' => 'Icelandic', 'ga' => 'Irish', 'cy' => 'Welsh',
    'eu' => 'Basque', 'ca' => 'Catalan', 'gl' => 'Galician', 'mt' => 'Maltese',
    'lb' => 'Luxembourgish', 'af' => 'Afrikaans', 'ar' => 'Arabic', 'he' => 'Hebrew',
    'fa' => 'Persian', 'ur' => 'Urdu', 'hi' => 'Hindi', 'bn' => 'Bengali',
    'ta' => 'Tamil', 'te' => 'Telugu', 'mr' => 'Marathi', 'gu' => 'Gujarati',
    'kn' => 'Kannada', 'ml' => 'Malayalam', 'pa' => 'Punjabi', 'si' => 'Sinhala',
    'ne' => 'Nepali', 'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
    'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay',
    'tl' => 'Tagalog', 'sw' => 'Swahili', 'mn' => 'Mongolian', 'km' => 'Khmer',
    'lo' => 'Lao', 'my' => 'Burmese', 'az' => 'Azerbaijani', 'uz' => 'Uzbek',
    'kk' => 'Kazakh',
];

$models = [
    'llama-3.1-8b' => ['id' => '@cf/meta/llama-3.1-8b-instruct-awq', 'no_think' => false],
    'qwen3-30b' => ['id' => '@cf/qwen/qwen3-30b-a3b-fp8', 'no_think' => true],
    'glm-4.7-flash' => ['id' => '@cf/zai-org/glm-4.7-flash', 'no_think' => false],
    'deepseek-r1-32b' => ['id' => '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b', 'no_think' => false],
];

$modelKey = $argv[1] ?? null;
$langCode = $argv[2] ?? null;

if (!$modelKey || !$langCode || !isset($models[$modelKey]) || !isset($allLanguages[$langCode])) {
    echo "Usage: php test-debug.php <model> <lang-code>\n";
    exit(1);
}

$model = $models[$modelKey];
$langName = $allLanguages[$langCode];

$batch = [
    ['index' => '0', 'text' => 'Hello, how are you?'],
    ['index' => '1', 'text' => "I'm fine, thank you.\nHow about you?"],
    ['index' => '2', 'text' => '<i>Goodbye!</i>'],
];

$systemPrompt = "You are a subtitle translator. Translate the `text` field of each JSON item to {$langName}. "
    . "Respond with ONLY a valid JSON array. No markdown fences. No explanation. No reasoning. "
    . "Keep the same structure: [{\"index\":\"0\",\"text\":\"...\"},...]";

$userMessage = json_encode($batch, JSON_UNESCAPED_UNICODE);
if ($model['no_think']) {
    $userMessage .= ' /no_think';
}

$url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model['id']}";
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

echo "HTTP: {$httpCode}\n\n";

$data = json_decode($response, true);

// Extract response text
$responseText = $data['result']['response']
    ?? $data['result']['choices'][0]['message']['content']
    ?? null;

$reasoning = $data['result']['choices'][0]['message']['reasoning_content'] ?? null;

if ($reasoning) {
    echo "=== REASONING ===\n{$reasoning}\n\n";
}

echo "=== RAW RESPONSE TEXT ===\n";
echo $responseText ?? "(null)";
echo "\n\n";

if (!$responseText) {
    echo "=== FULL RESULT ===\n";
    echo json_encode($data['result'] ?? $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

// Step 1: Direct decode
$result = json_decode($responseText, true);
echo "=== DIRECT json_decode ===\n";
echo $result ? "OK: " . count($result) . " items\n" : "FAILED: " . json_last_error_msg() . "\n";

// Step 2: Strip think tags + fences
$cleaned = preg_replace('/<think>.*?<\/think>/s', '', $responseText);
$cleaned = preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
$cleaned = preg_replace('/```\s*$/m', '', $cleaned);
$cleaned = trim($cleaned);

if ($cleaned !== $responseText) {
    echo "\n=== AFTER STRIP ===\n{$cleaned}\n";
    $result = json_decode($cleaned, true);
    echo $result ? "OK: " . count($result) . " items\n" : "FAILED: " . json_last_error_msg() . "\n";
}

// Step 3: Bracket extraction
$first = strpos($cleaned, '[');
$last = strrpos($cleaned, ']');
if ($first !== false && $last !== false && $last > $first) {
    $extracted = substr($cleaned, $first, $last - $first + 1);
    if ($extracted !== $cleaned) {
        echo "\n=== BRACKET EXTRACTION ===\n{$extracted}\n";
        $result = json_decode($extracted, true);
        echo $result ? "OK: " . count($result) . " items\n" : "FAILED: " . json_last_error_msg() . "\n";
    }
}

// Step 4: Repair - fix literal newlines inside string values
// Replace actual newlines that appear between quotes with \n
echo "\n=== NEWLINE REPAIR ===\n";
$repaired = $cleaned;
// Replace literal newlines that are inside JSON string values with \n
$repaired = preg_replace_callback('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', function($m) {
    // Replace actual newlines inside the captured text value with \n
    $fixed = str_replace(["\r\n", "\r", "\n"], '\\n', $m[1]);
    return '"text":"' . $fixed . '"';
}, $repaired);
echo $repaired . "\n";
$result = json_decode($repaired, true);
echo $result ? "OK: " . count($result) . " items\n" : "FAILED: " . json_last_error_msg() . "\n";

if ($result) {
    echo "\n=== PARSED RESULT ===\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
