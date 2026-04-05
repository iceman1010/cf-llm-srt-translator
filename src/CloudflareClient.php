<?php

namespace CloudflareSrt;

class CloudflareClient
{
    private string $apiToken;
    private string $accountId;
    private int $timeout;

    public function __construct(string $apiToken, string $accountId, int $timeout = 300)
    {
        $this->apiToken = $apiToken;
        $this->accountId = $accountId;
        $this->timeout = $timeout;
    }

    /**
     * Send a chat completion request to Cloudflare Workers AI.
     *
     * @return array The parsed response result
     * @throws \RuntimeException on HTTP or API errors
     */
    public function chatCompletion(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        array $options = []
    ): array {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run/{$modelId}";

        $body = [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiToken,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if (str_contains($curlError, 'timed out') || str_contains($curlError, 'Timeout')) {
                throw new \RuntimeException("408 Timeout: {$curlError}");
            }
            throw new \RuntimeException("cURL error: {$curlError}");
        }

        if ($httpCode === 429) {
            throw new \RuntimeException("429 Rate limited");
        }

        if ($httpCode >= 500) {
            $preview = mb_substr($response, 0, 200);
            throw new \RuntimeException("{$httpCode} Server error: {$preview}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse API response JSON: " . json_last_error_msg());
        }

        if (!($data['success'] ?? false)) {
            $errors = $data['errors'] ?? [];
            $errorMsg = !empty($errors) ? json_encode($errors) : 'Unknown API error';
            throw new \RuntimeException("API error: {$errorMsg}");
        }

        // Normalize response: CF has two formats
        // Old format: result.response (string)
        // New format (OpenAI-compatible): result.choices[0].message.content
        $responseText = null;

        // Some models (e.g., Llama 4) return result.response as a parsed array
        if (isset($data['result']['response']) && is_array($data['result']['response'])) {
            $responseText = json_encode($data['result']['response'], JSON_UNESCAPED_UNICODE);
        } elseif (isset($data['result']['response']) && is_string($data['result']['response'])) {
            $responseText = $data['result']['response'];
        } elseif (isset($data['result']['choices'][0]['message']['content'])) {
            $responseText = $data['result']['choices'][0]['message']['content'];
        }

        if ($responseText === null || $responseText === '') {
            // Check if reasoning model consumed all tokens with no content
            $reasoning = $data['result']['choices'][0]['message']['reasoning_content'] ?? null;
            if ($reasoning) {
                throw new \RuntimeException("Model returned reasoning only, no content. May need more max_tokens or disable thinking.");
            }
            // Dump response structure for debugging
            $debug = json_encode($data['result'] ?? $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException("No response content in API result. Response structure:\n{$debug}");
        }

        // Normalize to result.response for consistent downstream access
        $data['result']['response'] = $responseText;

        // Extract reasoning content for token tracking
        $data['result']['reasoning_content'] = $data['result']['choices'][0]['message']['reasoning_content'] ?? null;

        return $data;
    }
}
