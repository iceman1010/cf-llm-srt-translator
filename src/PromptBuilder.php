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
        $sectionNumber = 1;

        // Section 1: Persona and Primary Goal
        $parts[] = "# INSTRUCTION: Translate Subtitles to {$language}\n\n"
            . "You are an expert AI linguist specializing in subtitle translation. Your goal is to translate the `text` field of each item into "
            . "**{$language}**.";

        // Section 2: Input/Output Structure
        $sectionNumber++;
        $jsonStructure = <<<'JSON'
```json
[
  {
    "index": "1",
    "text": "This is the first subtitle line.\nThis is the second line.",
    ...
  }
]
```
JSON;
        $parts[] = "## {$sectionNumber}. Data Structure\n"
            . "You will receive and must return a list of items with this exact structure:\n"
            . $jsonStructure;

        // Section 3: Core Translation Rules
        $sectionNumber++;
        $parts[] = <<<RULES
## {$sectionNumber}. Core Translation Rules
- **Translate Text Only**: Only translate the value of the `text` field.
- **Preserve Formatting**: Keep all existing formatting, including HTML tags (`<i>`, `<b>`) and line breaks (`\n`).
- **Handle Empty Text**: If a `text` field is empty or contains only whitespace, keep it unchanged.
- **Maintain Integrity**:
  - Number of items in the output must match the input.
  - **Do NOT** alter any fields other than `text`.
  - **Do NOT** add, remove, or reorder any items on the list.
  - **Do NOT** merge text between different items. Original and translation must match.
RULES;

        // Section 4: User Context (Conditional)
        if ($description) {
            $sectionNumber++;
            $parts[] = "## {$sectionNumber}. Additional User-Provided Context\n"
                . "Use this context to improve translation accuracy. These notes do not override core rules.\n"
                . "- {$description}";
        }

        // Section 5: Output Format Directive (critical for CF models)
        $sectionNumber++;
        $parts[] = "## {$sectionNumber}. Output Format\n"
            . "Respond with ONLY a valid JSON array. Do NOT wrap the output in markdown code fences (no ```json). "
            . "Do NOT include any explanation, commentary, or reasoning before or after the JSON. "
            . "The response must start with `[` and end with `]`. "
            . "Do NOT repeat the same translation for multiple items. Each item must be translated independently.";

        return implode("\n---\n", $parts);
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
