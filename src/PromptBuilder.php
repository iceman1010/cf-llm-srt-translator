<?php

namespace CloudflareSrt;

class PromptBuilder
{
    /**
     * Build the system instruction prompt for subtitle translation.
     * Adapted from gemini-srt-translator's helpers.py get_translate_instruction().
     */
    public static function buildSystemInstruction(string $language, ?string $description = null): string
    {
        $parts = [];

        // Direct instruction
        $parts[] = "Translate these subtitles to {$language}.";

        // Core rules - concise
        $parts[] = "RULES:\n"
            . "- Return EXACTLY the same number of items with the SAME indexes.\n"
            . "- Only translate the 'text' field. Keep formatting (line breaks, tags).\n"
            . "- Do NOT merge, skip, reorder, or duplicate any subtitles.\n"
            . "- Each subtitle must be translated independently.";

        // Output format
        $parts[] = "Output: JSON array only, no markdown fences, start with '[' end with ']'.";

        // User context
        if ($description) {
            $parts[] = "Context: {$description}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Format a batch of subtitles as a JSON string for the user message.
     *
     * @param array $batch Array of ['index' => string, 'text' => string]
     */
    public static function formatBatchAsJson(array $batch): string
    {
        return json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
