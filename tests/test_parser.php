<?php

require_once __DIR__ . '/../vendor/autoload.php';

function parseSimpleResponse(string $text): array
{
    $text = preg_replace('/^```(?:txt|text)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = trim($text);
    
    $result = [];
    $currentIndex = null;
    $currentText = [];
    
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        if (preg_match('/^\[(\d+)\]:\s*(.*)$/', $line, $matches)) {
            if ($currentIndex !== null) {
                $text = trim(implode("\n", $currentText));
                if ($text !== '') {
                    $result[] = ['index' => $currentIndex, 'text' => $text];
                }
            }
            $currentIndex = $matches[1];
            $currentText = $matches[2] !== '' ? [$matches[2]] : [];
        } elseif ($currentIndex !== null) {
            $currentText[] = $line;
        }
    }
    
    if ($currentIndex !== null) {
        $text = trim(implode("\n", $currentText));
        if ($text !== '') {
            $result[] = ['index' => $currentIndex, 'text' => $text];
        }
    }
    
    return $result;
}

$tests = [
    [
        'name' => 'Basic single line',
        'input' => "[0]:\nHello world",
        'expected' => [['index' => '0', 'text' => 'Hello world']]
    ],
    [
        'name' => 'Multi-line text',
        'input' => "[0]:\nLine one\nLine two",
        'expected' => [['index' => '0', 'text' => "Line one\nLine two"]]
    ],
    [
        'name' => 'Multiple subtitles',
        'input' => "[0]:\nFirst\n\n[1]:\nSecond\n\n[2]:\nThird",
        'expected' => [
            ['index' => '0', 'text' => 'First'],
            ['index' => '1', 'text' => 'Second'],
            ['index' => '2', 'text' => 'Third']
        ]
    ],
    [
        'name' => 'Inline format (no newline after index)',
        'input' => "[0]: Hello\n[1]: World",
        'expected' => [
            ['index' => '0', 'text' => 'Hello'],
            ['index' => '1', 'text' => 'World']
        ]
    ],
    [
        'name' => 'German translation (real output)',
        'input' => "[0]:\nEs ist ein Unterhaltungs-\nund Informationsfilm,\n\n[1]:\nes werden keine medizinischen\nEmpfehlungen gegeben.",
        'expected' => [
            ['index' => '0', 'text' => "Es ist ein Unterhaltungs-\nund Informationsfilm,"],
            ['index' => '1', 'text' => "es werden keine medizinischen\nEmpfehlungen gegeben."]
        ]
    ],
    [
        'name' => 'With markdown fences',
        'input' => "```\n[0]:\nHello\n[1]:\nWorld\n```",
        'expected' => [
            ['index' => '0', 'text' => 'Hello'],
            ['index' => '1', 'text' => 'World']
        ]
    ],
    [
        'name' => 'JSON output (should fail)',
        'input' => '[{"index":"0","text":"Hello"},{"index":"1","text":"World"}]',
        'should_fail' => true
    ],
    [
        'name' => 'Mixed line endings',
        'input' => "[0]:\nFirst line\r\n\r\n[1]:\nSecond line",
        'expected' => [
            ['index' => '0', 'text' => 'First line'],
            ['index' => '1', 'text' => 'Second line']
        ]
    ],
];

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    try {
        $result = parseSimpleResponse($test['input']);
        
        if (isset($test['should_fail'])) {
            if (empty($result)) {
                echo "✓ {$test['name']} (correctly returned empty)\n";
                $passed++;
            } else {
                echo "✗ {$test['name']} - should have returned empty but got: " . json_encode($result) . "\n";
                $failed++;
            }
        } elseif ($result === $test['expected']) {
            echo "✓ {$test['name']}\n";
            $passed++;
        } else {
            echo "✗ {$test['name']}\n";
            echo "  Expected: " . json_encode($test['expected']) . "\n";
            echo "  Got:      " . json_encode($result) . "\n";
            $failed++;
        }
    } catch (Exception $e) {
        echo "✗ {$test['name']}: EXCEPTION - {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n";
echo "Passed: $passed, Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
