# CF LLM SRT Translator

A PHP CLI tool that translates subtitle files (SRT, VTT, ASS, etc.) using Cloudflare Workers AI LLMs. Supports 70+ languages with multiple model choices.

## Features

- **70+ Language Support** - Translate subtitles to nearly every major language
- **6 LLM Models** - Qwen, GPT-OSS, Llama 4, Gemma, Mistral, SEA-LION
- **Reasoning Mode** - Enabled by default for reasoning models (use `--no-think` to disable)
- **Partial Result Recovery** - Accepts truncated responses and auto-reduces batch size
- **Resume Support** - Progress file (`.progress`) allows resuming interrupted translations
- **Cost Tracking** - Per-run token usage and cost estimates

## Requirements

- PHP 8.1+
- Composer
- Cloudflare Account with Workers AI API access

## Installation

```bash
git clone https://github.com/iceman1010/cf-llm-srt-translator.git
cd cf-llm-srt-translator
composer install
```

## Configuration

Create a `.env` file with your Cloudflare credentials:
```bash
CLOUDFLARE_API_TOKEN=your_api_token
CLOUDFLARE_ACCOUNT_ID=your_account_id
```

## Usage

### Basic Translation
```bash
php translate.php --input=file.srt --language=German
```

### Choose a Model
```bash
php translate.php --input=file.srt --language=Spanish --model=mistral-small-3.1
```

### Disable Reasoning (faster, cheaper, lower quality)
```bash
php translate.php --input=file.srt --language=French --model=qwen3-30b --no-think
```

### All Options
```
php translate.php --input=<file> --language=<lang> [options]

Required:
  --input=<file>           Input subtitle file (.srt, .vtt, .ass, etc.)
  --language=<lang>        Target language (e.g., French, Spanish, Arabic)

Optional:
  --output=<file>          Output file path (default: auto-generated)
  --model=<key>            Model key (default: qwen3-30b)
  --batch-size=<n>         Override batch size from model config
  --temperature=<float>    Override temperature (default: 0.6)
  --max-tokens=<n>         Override max tokens (default: 8192)
  --description=<text>     Additional context for translation
  --no-think               Disable reasoning for reasoning models
```

## Supported Models

| Model | Cost (in/out per M tokens) | Context | Reasoning | Languages |
|-------|---------------------------|---------|-----------|-----------|
| **Qwen 3 30B** (default) | $0.051 / $0.34 | 32K | Yes | 69 tested |
| **GPT-OSS 120B** | $0.35 / $0.75 | 128K | Yes | Untested |
| **Llama 4 Scout 17B** | $0.27 / $0.85 | 131K | No | Untested |
| **Gemma 3 12B** | $0.35 / $0.56 | 80K | No | 71/72 tested |
| **Mistral Small 3.1** | $0.35 / $0.56 | 128K | No | 72/72 tested |
| **SEA-LION 27B** | $0.35 / $0.56 | 128K | No | 72/72 tested |

### Model Selection Guide

- **qwen3-30b** - Cheapest by far. Reasoning enabled by default improves quality significantly.
- **gpt-oss-120b** - OpenAI's 120B reasoning model. Higher quality, higher cost.
- **llama-4-scout** - Meta's Llama 4 MoE. Large context, competitive pricing.
- **gemma-3-12b** - Google's model. No reasoning overhead.
- **mistral-small-3.1** - Perfect language test score (72/72). Largest context (128K).
- **sea-lion-27b** - Optimized for Southeast Asian languages. Perfect score (72/72).

## Error Handling

- **Partial results** - If a model truncates output, valid translations are kept and batch size auto-reduces
- **Rate limiting (429)** - Exponential backoff up to 120s
- **Server errors (500/503)** - 60s pause and retry
- **Malformed JSON** - Multi-step extraction (strip fences, think tags, bracket extraction, repair)
- **Debug logging** - Raw responses saved to `.debug-response.txt` on JSON errors
- **Consecutive error limit** - 3 failures on the same batch before aborting (progress is saved)

## Project Structure

```
├── translate.php            # CLI entry point
├── llm-models.json          # Model registry and API config
├── .env                     # Cloudflare credentials
├── src/
│   ├── Translator.php       # Main orchestrator (batch loop, progress, error handling)
│   ├── CloudflareClient.php # HTTP client for CF Workers AI REST API
│   └── PromptBuilder.php    # System instruction + JSON batch formatting
└── composer.json            # Dependencies (PSR-4 autoload)
```

## Translation Flow

1. Parse input subtitle file via `mantas-done/subtitles`
2. Batch subtitles as JSON arrays
3. Send each batch to CF Workers AI with translation prompt
4. Extract and validate JSON response (handles multiple response formats)
5. Accept partial results if model truncates, auto-reduce batch size
6. Apply RTL BiDi wrapping where needed
7. Save output and clean up progress file

## License

[Add your chosen license]
