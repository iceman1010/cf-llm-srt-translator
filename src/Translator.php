<?php

namespace CloudflareSrt;

use Done\Subtitles\Subtitles;

class Translator
{
    private string $apiToken;
    private string $accountId;
    private string $targetLanguage;
    private string $inputFile;
    private string $modelKey;
    private string $configPath;
    private int $batchSize;
    private float $temperature;
    private ?string $description;
    private string $outputFile;
    private int $maxTokens;

    private array $modelConfig;
    private string $modelId;
    private CloudflareClient $client;

    private bool $enableThinking;
    private int $contextWindow;
    private string $responseFormat;
    private bool $debugMode;

    private int $consecutiveErrors = 0;
    private int $maxConsecutiveErrors = 3;
    private int $rateLimitErrors = 0;

    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private int $totalThinkTokens = 0;
    private int $totalApiCalls = 0;
    private int $partialBatches = 0;
    private int $qualityIssues = 0;
    private int $retryCount = 1;
    private int $currentRetry = 0;

    public function __construct(array $options)
    {
        $this->apiToken = $options['api_token'];
        $this->accountId = $options['account_id'];
        $this->targetLanguage = $options['target_language'];
        $this->inputFile = $options['input_file'];
        $this->modelKey = $options['model'] ?? 'qwen3-30b';
        $this->configPath = $options['config_path'] ?? __DIR__ . '/../llm-models.json';
        $this->description = $options['description'] ?? null;
        $this->retryCount = max(0, (int)($options['retry'] ?? 1));

        // Load model config
        $configJson = file_get_contents($this->configPath);
        if ($configJson === false) {
            throw new \RuntimeException("Cannot read config file: {$this->configPath}");
        }
        $config = json_decode($configJson, true);
        if (!isset($config['models'][$this->modelKey])) {
            throw new \RuntimeException("Unknown model: {$this->modelKey}. Available: " . implode(', ', array_keys($config['models'])));
        }

        $this->modelConfig = $config['models'][$this->modelKey];
        $this->modelId = $this->modelConfig['model_id'];
        $this->batchSize = $options['batch_size'] ?? $this->modelConfig['batch_size'];
        $this->temperature = $options['temperature'] ?? $config['api']['default_temperature'];
        $this->maxTokens = $options['max_tokens'] ?? $config['api']['default_max_tokens'];
        $this->contextWindow = $this->modelConfig['context_window'];
        $this->enableThinking = $options['think'] ?? false;
        $this->responseFormat = $options['format'] ?? 'json';
        if (!in_array($this->responseFormat, ['json', 'simple'])) {
            throw new \RuntimeException("Invalid format: {$this->responseFormat}. Must be 'json' or 'simple'.");
        }
        $this->debugMode = $options['debug'] ?? false;

        // Output file
        if (isset($options['output_file'])) {
            $this->outputFile = $options['output_file'];
        } else {
            $pathInfo = pathinfo($this->inputFile);
            $this->outputFile = $pathInfo['dirname'] . '/' . $pathInfo['filename']
                . '.' . strtolower(str_replace(' ', '_', $this->targetLanguage))
                . '.' . ($pathInfo['extension'] ?? 'srt');
        }

        $this->client = new CloudflareClient($this->apiToken, $this->accountId);
    }

    /**
     * Run the translation process.
     */
    public function translate(): void
    {
        // Parse input file
        if (!file_exists($this->inputFile)) {
            throw new \RuntimeException("Input file not found: {$this->inputFile}");
        }

        echo "Loading: {$this->inputFile}\n";
        $subtitles = Subtitles::loadFromFile($this->inputFile);
        $internalFormat = $subtitles->getInternalFormat();

        $total = count($internalFormat);
        echo "Subtitles loaded: {$total}\n";
        echo "Model: {$this->modelKey} ({$this->modelId})\n";
        echo "Target language: {$this->targetLanguage}\n";
        echo "Batch size: {$this->batchSize}\n";
        if ($this->modelConfig['reasoning'] ?? false) {
            echo "Reasoning: " . ($this->enableThinking ? "enabled (--think)" : "disabled") . "\n";
        }
        echo "Output: {$this->outputFile}\n\n";

        // Start timing
        $startTime = microtime(true);

        // Build system prompt based on format
        if ($this->responseFormat === 'simple') {
            $systemPrompt = PromptBuilder::buildSimpleSystemPrompt($this->targetLanguage);
        } else {
            $systemPrompt = PromptBuilder::buildSystemPrompt($this->targetLanguage);
        }

        if ($this->debugMode) {
            echo "\n=== SYSTEM PROMPT ===\n";
            echo $systemPrompt . "\n";
            echo "=== END SYSTEM PROMPT ===\n\n";
        }

        // Check for progress file
        $progressFile = $this->inputFile . '.progress';
        $startIndex = 0;
        $translatedFormat = $internalFormat; // copy structure

        if (file_exists($progressFile)) {
            $progressData = json_decode(file_get_contents($progressFile), true);
            // Validate progress file matches current job parameters
            $isValidProgress = false;
            if ($progressData && isset($progressData['index'])) {
                $modelMatch = ($progressData['model'] ?? '') === $this->modelKey;
                $langMatch = ($progressData['target_language'] ?? '') === $this->targetLanguage;
                if ($modelMatch && $langMatch) {
                    $isValidProgress = true;
                    $startIndex = $progressData['index'];
                    // Restore previously translated lines
                    if (isset($progressData['translations'])) {
                        foreach ($progressData['translations'] as $idx => $text) {
                            $translatedFormat[$idx]['lines'] = $this->textToLines($text);
                        }
                    }
                    echo "Resuming from subtitle {$startIndex}/{$total}\n";
                }
            }
            // Delete stale progress file if it doesn't match current job
            if (!$isValidProgress) {
                unlink($progressFile);
                echo "Progress file cleared (job parameters changed).\n";
            }
        }

        $translations = []; // index => translated text (for progress)
        // Load existing translations from progress
        if ($startIndex > 0 && isset($progressData['translations'])) {
            $translations = $progressData['translations'];
        }

        $i = $startIndex;
        while ($i < $total) {
            // Calculate batch size that fits within context window
            $effectiveBatchSize = $this->fitBatchToContext($internalFormat, $i, $this->batchSize, $systemPrompt);
            $batchEnd = min($i + $effectiveBatchSize, $total);
            $batch = [];

            $stripHtml = $this->modelConfig['strip_html'] ?? false;
            $htmlMap = []; // index => ['before' => [], 'after' => []] tag positions

            for ($j = $i; $j < $batchEnd; $j++) {
                $sub = $internalFormat[$j];
                $text = $this->linesToText($sub['lines']);

                if ($stripHtml) {
                    // Store HTML tags with positions so we can re-insert after translation
                    $htmlMap[(string)$j] = $this->extractHtmlTags($text);
                    $text = strip_tags($text);
                }

                $batch[] = [
                    'index' => (string)$j,
                    'text' => $text,
                ];
            }

            if ($this->responseFormat === 'simple') {
                $userMessage = PromptBuilder::formatBatchAsSimple($batch);
            } else {
                $userMessage = PromptBuilder::formatBatchAsJson($batch);
            }

            // Append /no_think for reasoning models when thinking is disabled (default)
            if (($this->modelConfig['no_think_suffix'] ?? false) && !$this->enableThinking) {
                $userMessage .= ' /no_think';
            }

            if ($this->debugMode && $i === 0) {
                echo "=== FIRST USER MESSAGE ===\n";
                echo $userMessage . "\n";
                echo "=== END USER MESSAGE ===\n\n";
            }

            echo sprintf(
                "Translating batch %d-%d / %d (%d%%)...",
                $i + 1,
                $batchEnd,
                $total,
                (int)round($batchEnd / $total * 100)
            );

            try {
                $response = $this->client->chatCompletion(
                    $this->modelId,
                    $systemPrompt,
                    $userMessage,
                    [
                        'temperature' => $this->temperature,
                        'max_tokens' => $this->maxTokens,
                    ]
                );

                $responseText = $response['result']['response'] ?? '';

                // Track token usage if available
                if (isset($response['result']['usage'])) {
                    $this->totalInputTokens += $response['result']['usage']['prompt_tokens'] ?? 0;
                    $this->totalOutputTokens += $response['result']['usage']['completion_tokens'] ?? 0;
                    // Estimate think tokens from reasoning_content (Cloudflare doesn't expose this in usage)
                    $reasoningContent = $response['result']['reasoning_content'] ?? '';
                    if ($reasoningContent !== '') {
                        $this->totalThinkTokens += (int)ceil(mb_strlen($reasoningContent) / 3.5);
                    }
                }

                // Track API call
                $this->totalApiCalls++;

                // Parse response based on format
                if ($this->responseFormat === 'simple') {
                    $translatedLines = $this->parseSimpleResponse($responseText);
                } else {
                    $translatedLines = $this->extractJson($responseText);
                }

                // Validate — returns valid items and issues
                $validation = $this->validateBatch($translatedLines, $batch);
                $validLines = $validation['valid'];
                $issues = $validation['issues'];

                // Check for quality issues that warrant retry
                // Log finish reason
                $finishReason = $response['result']['choices'][0]['finish_reason'] ?? 'unknown';
                $stopReason = $response['result']['choices'][0]['stop_reason'] ?? null;
                $reason = $stopReason ?: $finishReason;
                $outputLen = strlen($responseText);

                $partialPrefix = "";
                if ($validation['validCount'] < $validation['expectedCount']) {
                    $this->partialBatches++;
                    $partialPrefix = sprintf(" Partial: %d/%d", $validation['validCount'], $validation['expectedCount']);
                    $this->batchSize = max(1, $validation['validCount']);
                }

                if (!empty($issues)) {
                    $issueStr = " [" . implode(', ', $issues) . "]";
                    echo "{$partialPrefix}{$issueStr} [stop: {$reason}, {$outputLen} chars].";
                } else {
                    echo "{$partialPrefix} [stop: {$reason}, {$outputLen} chars].";
                }

                // Apply translations
                $maxTranslatedIdx = $i;
                foreach ($validLines as $line) {
                    $idx = (int)$line['index'];
                    $text = $line['text'];

                    // Re-insert HTML tags if they were stripped
                    if ($stripHtml && isset($htmlMap[(string)$idx])) {
                        $text = $this->reinsertHtmlTags($text, $htmlMap[(string)$idx]);
                    }

                    // RTL BiDi wrapping
                    if ($this->isDominantRtl($text)) {
                        $text = "\u{202B}" . $text . "\u{202C}";
                    }

                    $translatedFormat[$idx]['lines'] = $this->textToLines($text);
                    $translations[$idx] = $text;
                    $maxTranslatedIdx = max($maxTranslatedIdx, $idx + 1);
                }

                // Advance to the next untranslated subtitle
                $i = $maxTranslatedIdx;

                // Save progress
                $this->saveProgress($progressFile, $i, $translations);

                $this->consecutiveErrors = 0;
                echo " Done.\n";

            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();

                // Abort immediately on merged/duplicate content - don't retry
                if (str_contains($msg, 'merged/duplicate content')) {
                    echo "\nAborted: {$msg}\n";
                    throw $e;
                }

                $isJsonError = str_contains($msg, 'JSON') || str_contains($msg, 'Count mismatch');

                // Dump raw response for debugging JSON/validation errors
                if ($isJsonError && isset($responseText)) {
                    $debugFile = $this->inputFile . ".{$this->modelKey}.debug.txt";
                    $timestamp = date('H:i:s');
                    $batchInfo = "=== Batch starting at index {$i} @ {$timestamp} ===\n";
                    file_put_contents($debugFile, $batchInfo . $responseText . "\n\n", FILE_APPEND);
                    echo " (raw response appended to {$debugFile})\n";
                }
                $isTimeout = str_contains($msg, '408') || str_contains($msg, 'Timeout') || str_contains($msg, 'timed out');

                // JSON errors are transient — use separate counter with higher tolerance
                if ($isJsonError) {
                    $this->consecutiveErrors++;
                    echo " JSON error (attempt {$this->consecutiveErrors}/{$this->maxConsecutiveErrors}). Retrying...\n";
                    sleep(2);
                } elseif ($isTimeout) {
                    $this->consecutiveErrors++;
                    $oldBatchSize = $this->batchSize;
                    $this->batchSize = max(1, (int)($this->batchSize * 0.5));
                    echo " Timeout. Batch size: {$oldBatchSize} -> {$this->batchSize}. Retrying...\n";
                    sleep(5);
                } elseif (str_contains($msg, '429 Rate Limited')) {
                    $this->consecutiveErrors++;
                    $this->rateLimitErrors++;
                    // Extract wait time from message if present
                    if (preg_match('/waiting (\d+)/', $msg, $matches)) {
                        $wait = (int)$matches[1];
                    } else {
                        $wait = min(30 * pow(2, $this->rateLimitErrors - 1), 300);
                    }
                    echo " Rate limited ({$this->rateLimitErrors} total). Waiting {$wait}s...\n";
                    sleep((int)$wait);
                } elseif (str_contains($msg, '429 Quota Exceeded')) {
                    echo " Daily API quota exhausted. Cannot continue.\n";
                    $this->saveProgress($progressFile, $i, $translations);
                    echo "Progress saved. Resume tomorrow or when quota resets.\n";
                    return;
                } elseif (str_contains($msg, '500') || str_contains($msg, '503')) {
                    $this->consecutiveErrors++;
                    echo " Server error. Waiting 60s...\n";
                    sleep(60);
                } else {
                    $this->consecutiveErrors++;
                    echo " Error: {$msg}\nRetrying...\n";
                    sleep(5);
                }

                if ($this->consecutiveErrors >= $this->maxConsecutiveErrors) {
                    echo "Too many consecutive errors ({$this->consecutiveErrors}). Saving progress and aborting.\n";
                    $this->saveProgress($progressFile, $i, $translations);
                    throw $e;
                }

                continue;
            }
        }

        // Write output file
        $outputSubtitles = new Subtitles();
        foreach ($translatedFormat as $sub) {
            $outputSubtitles->add(
                $sub['start'],
                $sub['end'],
                $this->linesToText($sub['lines'])
            );
        }
        $outputSubtitles->save($this->outputFile);

        // Clean up progress file
        if (file_exists($progressFile)) {
            unlink($progressFile);
        }

        echo "\nTranslation completed successfully!\n";
        $elapsed = round(microtime(true) - $startTime, 2);
        echo "Output saved to: {$this->outputFile}\n";
        echo "Time: {$elapsed}s\n";
        $this->logTokenUsage();

        // Model recommendation if quality issues detected
        if ($this->qualityIssues > 0) {
            echo "\n⚠ Model {$this->modelKey} had {$this->qualityIssues} quality issue(s) (merged/duplicate content).\n";
            echo "Consider using one of these alternatives for better reliability:\n";
            $alternatives = ['llama-4-scout', 'mistral-small-3.1', 'gemma-3-12b'];
            foreach ($alternatives as $alt) {
                if ($alt !== $this->modelKey) {
                    echo "  - {$alt}\n";
                }
            }
        }
    }

    /**
     * Extract JSON array from LLM response text.
     * Handles markdown fences, <think> tags, and bracket extraction.
     */
    private function extractJson(string $text): array
    {
        // Step 1: Try direct decode
        $result = json_decode($text, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        // Step 2: Strip <think>...</think> tags (DeepSeek R1)
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $text = trim($text);

        // Step 3: Strip markdown code fences
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        // Step 4: Try decode again
        $result = json_decode($text, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        // Step 5: Bracket extraction - find the outermost [ ... ]
        $firstBracket = strpos($text, '[');
        $lastBracket = strrpos($text, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $extracted = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
            $result = json_decode($extracted, true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        // Step 6: Simple repair - try to fix common issues
        $repaired = $this->repairJson($text);
        if ($repaired !== null) {
            return $repaired;
        }

        // Log the raw response for debugging
        $preview = mb_substr($text, 0, 300);
        throw new \RuntimeException("Failed to extract valid JSON from response. Preview: {$preview}");
    }

    /**
     * Attempt simple JSON repairs.
     */
    private function repairJson(string $text): ?array
    {
        // Extract bracket content if present
        $firstBracket = strpos($text, '[');
        $lastBracket = strrpos($text, ']');
        if ($firstBracket !== false && $lastBracket !== false) {
            $text = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        // Try fixing trailing commas before ]
        $fixed = preg_replace('/,\s*]/', ']', $text);
        $result = json_decode($fixed, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        // Fix "text"," instead of "text":" (common typo)
        $fixed = preg_replace('/"text","/', '"text":"', $text);
        $result = json_decode($fixed, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        // Fix missing } before ] — e.g. "text":"...!"] → "text":"...!"}]
        $fixed = preg_replace('/"\s*]$/', '"}]', $text);
        $result = json_decode($fixed, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        // Fix swapped }] → ]} at end
        if (str_ends_with(rtrim($text), ']}')) {
            $fixed = substr(rtrim($text), 0, -2) . '}]';
            $result = json_decode($fixed, true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        // Try fixing missing closing bracket
        if ($firstBracket !== false && $lastBracket === false) {
            $result = json_decode($text . '"}]', true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
            $result = json_decode($text . ']', true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Parse simple format response ([N]:\ntext\n\n[N]:\ntext).
     * Returns array of ['index' => string, 'text' => string].
     */
    private function parseSimpleResponse(string $text): array
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
        
        if (empty($result)) {
            $preview = mb_substr($text, 0, 300);
            throw new \RuntimeException("Failed to parse simple format from response. Preview: {$preview}");
        }
        
        return $result;
    }

    private function isListOfDicts(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        foreach ($data as $item) {
            if (!is_array($item) || array_keys($item) === range(0, count($item) - 1)) {
                // Sequential array (not assoc), flatten attempt
                return false;
            }
        }
        return true;
    }

    /**
     * Validate translated batch against original batch.
     * Returns array with 'valid' items and 'issues' found.
     */
    private function validateBatch(array $translated, array $original): array
    {
        $originalIndexes = array_column($original, 'index');
        $originalByIndex = [];
        foreach ($original as $item) {
            $originalByIndex[$item['index']] = $item;
        }

        $expectedMinIndex = min(array_map('intval', $originalIndexes));
        $expectedMaxIndex = max(array_map('intval', $originalIndexes));
        $expectedCount = count($original);

        $valid = [];
        $seenIndexes = [];
        $duplicates = [];
        $textLengths = [];
        $returnedIndexes = [];

        foreach ($translated as $line) {
            if (!isset($line['index']) || !isset($line['text'])) {
                continue;
            }
            if (!in_array($line['index'], $originalIndexes, true)) {
                continue;
            }

            $idx = (int)$line['index'];
            $returnedIndexes[] = $idx;

            if (isset($seenIndexes[$line['index']])) {
                $duplicates[] = $line['index'];
            }
            $seenIndexes[$line['index']] = true;

            $textLengths[] = mb_strlen($line['text']);

            if ($line['text'] === '' && ($originalByIndex[$line['index']]['text'] ?? '') !== '') {
                $line['text'] = $originalByIndex[$line['index']]['text'];
            }
            $valid[] = $line;
        }

        if (empty($valid)) {
            throw new \RuntimeException("No valid translations in response");
        }

        $issues = [];

        if (!empty($duplicates)) {
            $issues[] = "duplicates: " . implode(',', array_unique($duplicates));
        }

        sort($returnedIndexes);
        $returnedMin = $returnedIndexes[0] ?? $expectedMinIndex;
        if ($returnedMin > $expectedMinIndex) {
            $skipped = $returnedMin - $expectedMinIndex;
            $issues[] = "skipped first {$skipped}";
        }

        $returnedSet = array_flip($returnedIndexes);
        $expectedSet = range($expectedMinIndex, $expectedMaxIndex);
        $missingIndexes = [];
        foreach ($expectedSet as $idx) {
            if (!isset($returnedSet[$idx])) {
                $missingIndexes[] = $idx;
            }
        }
        if (count($missingIndexes) > 0 && count($missingIndexes) < $expectedCount) {
            $issues[] = "missing: " . implode(',', array_slice($missingIndexes, 0, 5)) . (count($missingIndexes) > 5 ? "..." : "");
        }

        return [
            'valid' => $valid,
            'issues' => $issues,
            'validCount' => count($valid),
            'expectedCount' => $expectedCount,
        ];
    }

    /**
     * Determine if text is dominantly RTL.
     */
    private function isDominantRtl(string $text): bool
    {
        $rtlCount = 0;
        $ltrCount = 0;

        // Check Unicode bidirectional categories
        $len = mb_strlen($text);
        for ($k = 0; $k < $len; $k++) {
            $char = mb_substr($text, $k, 1);
            $code = mb_ord($char);

            // Arabic range: U+0600-U+06FF, U+0750-U+077F, U+08A0-U+08FF, U+FB50-U+FDFF, U+FE70-U+FEFF
            // Hebrew range: U+0590-U+05FF, U+FB1D-U+FB4F
            if (($code >= 0x0590 && $code <= 0x05FF) ||
                ($code >= 0x0600 && $code <= 0x06FF) ||
                ($code >= 0x0750 && $code <= 0x077F) ||
                ($code >= 0x08A0 && $code <= 0x08FF) ||
                ($code >= 0xFB1D && $code <= 0xFB4F) ||
                ($code >= 0xFB50 && $code <= 0xFDFF) ||
                ($code >= 0xFE70 && $code <= 0xFEFF)) {
                $rtlCount++;
            } elseif (($code >= 0x0041 && $code <= 0x005A) ||
                      ($code >= 0x0061 && $code <= 0x007A) ||
                      ($code >= 0x00C0 && $code <= 0x024F)) {
                $ltrCount++;
            }
        }

        return $rtlCount > $ltrCount;
    }

    /**
     * Convert subtitle lines array to single text string.
     */
    private function linesToText(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * Convert text string to subtitle lines array.
     */
    private function textToLines(string $text): array
    {
        $text = stripcslashes($text);
        return explode("\n", $text);
    }

    /**
     * Extract HTML tags from text, returning the tag info needed to re-insert them.
     * Returns an array of ['tag' => string, 'position' => 'start'|'end'|'wrap', 'offset' => int].
     *
     * Simple approach: tracks which tags wrap the entire text vs inline tags.
     */
    private function extractHtmlTags(string $text): array
    {
        $tags = [];
        // Match all HTML tags with their positions
        if (preg_match_all('/<\/?[a-zA-Z][^>]*>/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $tags[] = [
                    'tag' => $match[0],
                    'offset' => $match[1],
                ];
            }
        }
        return $tags;
    }

    /**
     * Re-insert HTML tags into translated text.
     * Uses a simple heuristic: if original had wrapping tags (e.g., <i>...</i>),
     * wrap the translated text the same way.
     */
    private function reinsertHtmlTags(string $translatedText, array $tags): string
    {
        if (empty($tags)) {
            return $translatedText;
        }

        // Group opening and closing tags
        $openTags = [];
        $closeTags = [];
        foreach ($tags as $t) {
            if (str_starts_with($t['tag'], '</')) {
                $closeTags[] = $t['tag'];
            } else {
                $openTags[] = $t['tag'];
            }
        }

        // Simple re-wrap: prepend opening tags, append closing tags
        return implode('', $openTags) . $translatedText . implode('', $closeTags);
    }

    /**
     * Estimate token count from text using a ~4 chars per token heuristic.
     * This is conservative — real tokenizers vary, but 4 chars/token is a safe average.
     */
    private function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / 4);
    }

    /**
     * Calculate the maximum batch size that fits within the context window.
     * Context must fit: system prompt tokens + user message tokens + max_tokens (for response).
     * We use 90% of context window as safety margin.
     */
    private function fitBatchToContext(array $allSubs, int $startIdx, int $requestedBatchSize, string $systemPrompt): int
    {
        $availableContext = (int)($this->contextWindow * 0.9);
        $systemTokens = $this->estimateTokens($systemPrompt);
        // Reserve space for the response (translated text is roughly same size as input, plus JSON overhead)
        $budgetForInput = $availableContext - $systemTokens - $this->maxTokens;

        if ($budgetForInput <= 0) {
            echo "Warning: System prompt + max_tokens already exceeds context window. Using batch size 1.\n";
            return 1;
        }

        $total = count($allSubs);
        $batch = [];
        for ($j = $startIdx; $j < min($startIdx + $requestedBatchSize, $total); $j++) {
            $sub = $allSubs[$j];
            $text = $this->linesToText($sub['lines']);
            $batch[] = ['index' => (string)$j, 'text' => $text];

            $batchJson = PromptBuilder::formatBatchAsJson($batch);
            $inputTokens = $this->estimateTokens($batchJson);

            if ($inputTokens > $budgetForInput) {
                // This subtitle pushed us over — remove it
                array_pop($batch);
                break;
            }
        }

        $fitted = count($batch);
        if ($fitted === 0) {
            // Single subtitle exceeds budget — send it anyway (1 at minimum)
            return 1;
        }

        if ($fitted < $requestedBatchSize && $fitted < ($total - $startIdx)) {
            echo "Batch size reduced from {$requestedBatchSize} to {$fitted} to fit context window ({$this->contextWindow} tokens).\n";
        }

        return $fitted;
    }

    /**
     * Save translation progress to file.
     */
    private function saveProgress(string $path, int $index, array $translations): void
    {
        $data = [
            'index' => $index,
            'model' => $this->modelKey,
            'target_language' => $this->targetLanguage,
            'translations' => $translations,
        ];
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * List supported languages for a given model.
     *
     * @param string $modelKey Model key (e.g., 'qwen3-30b')
     * @param string|null $configPath Optional path to llm-models.json
     * @return array List of ISO language codes
     * @throws \RuntimeException if model not found or config unreadable
     */
    public static function listLanguages(string $modelKey, ?string $configPath = null): array
    {
        $configPath = $configPath ?? __DIR__ . '/../llm-models.json';
        $configJson = file_get_contents($configPath);
        if ($configJson === false) {
            throw new \RuntimeException("Cannot read config file: {$configPath}");
        }
        $config = json_decode($configJson, true);
        if (!isset($config['models'][$modelKey])) {
            throw new \RuntimeException("Unknown model: {$modelKey}. Available: " . implode(', ', array_keys($config['models'])));
        }
        return $config['models'][$modelKey]['languages'] ?? [];
    }

    /**
     * Log token usage and estimated cost.
     */
    private function logTokenUsage(): void
    {
        if ($this->totalInputTokens === 0 && $this->totalOutputTokens === 0) {
            echo "Token usage: not available from API\n";
            return;
        }

        $inputCost = ($this->totalInputTokens / 1_000_000) * $this->modelConfig['input_cost_per_million'];
        $outputCost = ($this->totalOutputTokens / 1_000_000) * $this->modelConfig['output_cost_per_million'];
        $thinkCost = ($this->totalThinkTokens / 1_000_000) * $this->modelConfig['output_cost_per_million'];
        $totalCost = $inputCost + $outputCost + $thinkCost;

        $outputParts = [];
        $costParts = [];
        $outputParts[] = sprintf("%d output", $this->totalOutputTokens);
        $costParts[] = sprintf("\$%.4f output", $outputCost);

        if ($this->totalThinkTokens > 0) {
            $outputParts[] = sprintf("%d think", $this->totalThinkTokens);
            $costParts[] = sprintf("\$%.4f think", $thinkCost);
        }

        echo sprintf(
            "Token usage: %d input, %s\n",
            $this->totalInputTokens,
            implode(", ", $outputParts)
        );
        echo sprintf(
            "Estimated cost: \$%.4f (input: \$%.4f, %s)\n",
            $totalCost,
            $inputCost,
            implode(", ", $costParts)
        );

        // API call statistics
        echo sprintf(
            "API calls: %d total, %d partial\n",
            $this->totalApiCalls,
            $this->partialBatches
        );
    }
}
