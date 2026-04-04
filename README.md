# AI Subtitle Translator - Translate Subtitles with LLMs

**Part of [ai.opensubtitles.com](https://ai.opensubtitles.com)** - AI-powered subtitle translation and audio transcription for movies and TV shows.

A PHP CLI tool to translate subtitle files (SRT, VTT, ASS, SSA, SUB, DFXP, TTML) into 100+ languages using large language models via Cloudflare Workers AI. Choose from 6 LLMs including Qwen, GPT-OSS, Llama 4, Gemma, Mistral, and SEA-LION. Available as a single-file PHAR executable - no installation required.

## Why Use AI to Translate Subtitles?

Traditional subtitle translation tools rely on rule-based machine translation that often misses context, idioms, and tone. By using large language models (LLMs), this tool produces translations that understand dialogue context, preserve character voice, and handle slang, humor, and cultural references naturally. With 6 models to choose from, you can balance between cost, speed, and translation quality.

## Features

- **102 Languages Tested** - Translate subtitles to any major world language, from French and Spanish to Khmer, Yoruba, and Esperanto
- **6 AI Models** - Choose the best LLM for your needs: budget-friendly Qwen, high-quality GPT-OSS, or specialized SEA-LION for Southeast Asian languages
- **All Subtitle Formats** - SRT, VTT (WebVTT), ASS, SSA, SUB, DFXP, TTML
- **Language Auto-Detection** - Accept full names (French), ISO 639-1 (fr), or ISO 639-3 (fra) codes
- **Reasoning Mode** - LLM reasoning improves translation quality for complex dialogue (disable with `--no-think` for speed)
- **Resume Interrupted Jobs** - Progress is saved automatically; resume large files after network errors
- **Partial Result Recovery** - Truncated LLM responses are salvaged; batch size auto-reduces
- **RTL Language Support** - Automatic BiDi wrapping for Arabic, Hebrew, Persian, Urdu, and other right-to-left languages
- **Cost Tracking** - Per-run token usage and cost estimates
- **Self-Update** - Update the PHAR to the latest version with `--update`
- **Setup API** - Interactive credential setup with `--setup-api`
- **List Languages** - Query supported languages per model with `--list-languages`
- **List Models** - Browse available models with `--list-models`
- **Single-File Executable** - Download one PHAR file and run it anywhere with PHP 8.1+

## Supported Languages

Translate subtitles between 102 tested languages including:

**European:** French, German, Spanish, Portuguese, Italian, Dutch, Russian, Polish, Czech, Romanian, Hungarian, Bulgarian, Croatian, Slovak, Slovenian, Serbian, Bosnian, Macedonian, Albanian, Greek, Turkish, Swedish, Danish, Norwegian, Finnish, Estonian, Latvian, Lithuanian, Ukrainian, Belarusian, Georgian, Armenian, Icelandic, Irish, Welsh, Basque, Catalan, Galician, Maltese, Luxembourgish, Scottish Gaelic

**Asian:** Chinese, Japanese, Korean, Thai, Vietnamese, Indonesian, Malay, Tagalog, Hindi, Bengali, Tamil, Telugu, Marathi, Gujarati, Kannada, Malayalam, Punjabi, Sinhala, Nepali, Urdu, Mongolian, Khmer, Lao, Burmese, Javanese, Sundanese, Cebuano

**Middle Eastern:** Arabic, Hebrew, Persian, Kurdish, Pashto, Sindhi

**Central Asian / Turkic:** Azerbaijani, Uzbek, Kazakh, Tajik, Turkmen, Kyrgyz, Tatar, Uyghur

**African:** Swahili, Afrikaans, Amharic, Hausa, Yoruba, Igbo, Zulu, Xhosa, Somali, Malagasy, Kinyarwanda, Shona, Chichewa

**Other:** Esperanto, Latin, Yiddish, Haitian Creole, Hawaiian, Maori, Samoan

## Requirements

- PHP 8.1+
- Cloudflare Account with Workers AI API access

## Installation

### Option 1: PHAR executable (recommended)
Download `cf-llm-srt-translate.phar` from the [latest release](https://github.com/iceman1010/cf-llm-srt-translator/releases) and run it directly:
```bash
php cf-llm-srt-translate.phar --input=movie.srt --language=French
```

### Option 2: From source
```bash
git clone https://github.com/iceman1010/cf-llm-srt-translator.git
cd cf-llm-srt-translator
composer install
```

## Configuration

### Interactive setup (recommended)
```bash
php cf-llm-srt-translate.phar --setup-api
```
This prompts for your credentials and saves them to `~/.cf-llm-srt-translate/.env`.

### Manual setup

Set your Cloudflare credentials via environment variables:
```bash
export CLOUDFLARE_API_TOKEN=your_api_token
export CLOUDFLARE_ACCOUNT_ID=your_account_id
```

Or create a `.env` file in the working directory:
```bash
CLOUDFLARE_API_TOKEN=your_api_token
CLOUDFLARE_ACCOUNT_ID=your_account_id
```

Credential priority: environment variables > local `.env` > `~/.cf-llm-srt-translate/.env`

## Usage

### Translate subtitles to any language
```bash
php translate.php --input=movie.srt --language=German
```

### Use ISO language codes
```bash
php translate.php --input=movie.srt --language=fr
```

### Choose a specific AI model
```bash
php translate.php --input=movie.srt --language=Spanish --model=mistral-small-3.1
```

### Disable reasoning for faster, cheaper translation
```bash
php translate.php --input=movie.srt --language=French --model=qwen3-30b --no-think
```

### List available models
```bash
php cf-llm-srt-translate.phar --list-models
```

### List supported languages for a model
```bash
php cf-llm-srt-translate.phar --list-languages --model=qwen3-30b
```

### Self-update to latest version
```bash
php cf-llm-srt-translate.phar --update
```

### Setup API credentials
```bash
php cf-llm-srt-translate.phar --setup-api
```

### All Options
```
php translate.php --input=<file> --language=<lang> [options]

Required:
  --input=<file>           Input subtitle file (.srt, .vtt, .ass, etc.)
  --language=<lang>        Target language name or ISO code (e.g., French, fr, fra)

Optional:
  --output=<file>          Output file path (default: auto-generated)
  --model=<key>            Model key (default: qwen3-30b)
  --batch-size=<n>         Override batch size from model config
  --temperature=<float>    Override temperature (default: 0.6)
  --max-tokens=<n>         Override max tokens (default: 8192)
  --description=<text>     Additional context for translation
  --no-think               Disable reasoning for reasoning models
  --list-models            List available models and exit
  --list-languages         List languages for a model (requires --model) and exit
  --update                 Update PHAR to latest release and exit
  --setup-api              Interactive credential setup and exit
  --version                Show version and exit
```

## AI Models for Subtitle Translation

| Model | Cost (in/out per M tokens) | Context | Reasoning | Languages |
|-------|---------------------------|---------|-----------|-----------|
| **Qwen 3 30B** (default) | $0.051 / $0.34 | 32K | Yes | 99/102 |
| **GPT-OSS 120B** | $0.35 / $0.75 | 128K | Yes | 102/102 |
| **Llama 4 Scout 17B** | $0.27 / $0.85 | 131K | No | 102/102 |
| **Gemma 3 12B** | $0.35 / $0.56 | 80K | No | 96/102 |
| **Mistral Small 3.1** | $0.35 / $0.56 | 128K | No | 101/102 |
| **SEA-LION 27B** | $0.35 / $0.56 | 128K | No | 102/102 |

### Which model should I use?

- **qwen3-30b** - Best value. 15x cheaper than alternatives. Reasoning mode improves translation quality significantly.
- **gpt-oss-120b** - Highest quality. OpenAI's 120B reasoning model. Perfect on all 102 languages.
- **llama-4-scout** - Best balance. Meta's Llama 4 MoE with 131K context for very large subtitle files. Perfect language score.
- **gemma-3-12b** - Fast and affordable. Google's Gemma 3 without reasoning overhead.
- **mistral-small-3.1** - Reliable all-rounder. 128K context, 101/102 languages.
- **sea-lion-27b** - Best for Southeast Asian languages (Thai, Vietnamese, Indonesian, Malay, Tagalog, Khmer, Lao, Burmese). Perfect language score.

## Error Handling

- **Partial results** - If a model truncates output, valid translations are kept and batch size auto-reduces
- **Rate limiting (429)** - Exponential backoff up to 120s
- **Server errors (500/503)** - 60s pause and retry
- **Malformed JSON** - Multi-step extraction (strip fences, think tags, bracket extraction, repair)
- **Debug logging** - Raw responses saved to `.debug-response.txt` on JSON errors
- **Consecutive error limit** - 3 failures on the same batch before aborting (progress is saved)

## Building the PHAR

Requires [Box](https://github.com/box-project/box) installed globally:
```bash
composer global require humbug/box
make
```

Or via Composer script:
```bash
composer build
```

## How It Works

1. Parse input subtitle file (SRT, VTT, ASS, or other format)
2. Batch subtitles as JSON arrays for efficient LLM processing
3. Send each batch to Cloudflare Workers AI with a translation prompt
4. Extract and validate JSON response (handles markdown fences, think tags, truncated output)
5. Accept partial results if the model truncates, auto-reduce batch size on errors
6. Apply RTL BiDi wrapping for right-to-left languages
7. Save translated subtitle file and clean up progress

## Project Structure

```
├── translate.php            # CLI entry point
├── llm-models.json          # Model registry, API config, and tested languages
├── box.json                 # PHAR build configuration
├── Makefile                 # Build automation
├── .env                     # Cloudflare credentials
├── src/
│   ├── Translator.php       # Main orchestrator (batch loop, progress, error handling)
│   ├── CloudflareClient.php # HTTP client for CF Workers AI REST API
│   └── PromptBuilder.php    # System instruction + JSON batch formatting
└── composer.json            # Dependencies (PSR-4 autoload)
```

## About ai.opensubtitles.com

This tool is part of the [ai.opensubtitles.com](https://ai.opensubtitles.com) platform, which provides AI-powered tools for subtitle translation and audio transcription of movies and TV shows. The platform offers users a wide choice of large language models to translate subtitles or transcribe audio tracks to generate subtitles automatically.

## License

MIT
