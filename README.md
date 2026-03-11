# CF LLM SRT Translator

A PHP application that translates SRT (SubRip) subtitle files using Cloudflare's AI API with support for multiple LLM models and 70+ languages.

## Features

- 🌍 **70+ Language Support** - Translate subtitles to nearly every major language
- ⚡ **Multiple LLM Models** - Choose between Qwen, Gemma, Mistral, or SEA-LION
- 💰 **Cost Comparison** - Test different models to optimize quality vs. cost
- 🧠 **Reasoning Mode** - Optional reasoning for Qwen (append "/no_think" to disable)
- 📝 **Large Context Windows** - Handle substantial SRT files (up to 128K tokens)
- 🔄 **Batch Processing** - Efficient batch handling for each model

## Requirements

- PHP 8.0+
- Composer
- Cloudflare Account with AI API access

## Installation

```bash
git clone https://github.com/iceman1010/cf-llm-srt-translator.git
cd cf-llm-srt-translator
composer install
```

## Configuration

1. Set your Cloudflare credentials as environment variables:
```bash
export CF_ACCOUNT_ID="your_cloudflare_account_id"
export CF_API_TOKEN="your_cloudflare_api_token"
```

2. Update `llm-models.json` with your preferred model settings if needed.

## Usage

### Basic Translation
```bash
php translate.php input.srt --language de
```

### Using a Specific Model
```bash
php translate.php input.srt --language de --model mistral-small-3.1
```

### Test Language Support
```bash
php test-languages.php
```

### Debug Mode
```bash
php test-debug.php
```

## Supported Models

| Model | Model ID | Cost | Context | Languages | Reasoning |
|-------|----------|------|---------|-----------|-----------|
| **Qwen 3 30B** | `@cf/qwen/qwen3-30b-a3b-fp8` | $0.051/$0.34 | 32K | 69 | ✅ |
| **Gemma 3 12B** | `@cf/google/gemma-3-12b-it` | $0.35/$0.56 | 80K | 71/72 | ❌ |
| **Mistral Small 3.1** | `@cf/mistralai/mistral-small-3.1-24b-instruct` | $0.35/$0.56 | 128K | 72/72 | ❌ |
| **SEA-LION 27B** | `@cf/aisingapore/gemma-sea-lion-v4-27b-it` | $0.35/$0.56 | 128K | 72/72 | ❌ |

### Model Selection Guide

- **Qwen 3 30B**: Best for budget-conscious projects. Has reasoning capability.
- **Gemma 3 12B**: Google's model. Good balance of quality and cost.
- **Mistral Small 3.1**: Recommended for most use cases. Perfect language test score (72/72).
- **SEA-LION 27B**: Optimized for Southeast Asian languages.

## API Configuration

The application connects to Cloudflare's AI API:
- **Base URL**: `https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/{model_id}`
- **Default Temperature**: 0.6 (balanced creativity/consistency)
- **Default Max Tokens**: 8,192

## Project Structure

```
.
├── translate.php              # Main translation script
├── test-languages.php         # Test language support across models
├── test-languages-retry.php   # Retry failed language tests
├── test-debug.php             # Debug mode for troubleshooting
├── llm-models.json            # Model configurations
├── src/                       # Source code (classes/utilities)
├── composer.json              # Composer configuration
├── test.srt                   # Sample SRT file for testing
└── output-*.srt               # Example output translations
```

## Example Output Files

The repository includes sample translations:
- `output-qwen3.de.srt` - Qwen 3 German translation
- `output-qwen3-think.de.srt` - Qwen 3 with reasoning (German)
- `output-mistral.de.srt` - Mistral Small 3.1 (German)
- `output-gemma.de.srt` - Gemma 3 (German)
- `output-sealion.th.srt` - SEA-LION (Thai)

## Troubleshooting

### API Authentication Failed
- Verify `CF_ACCOUNT_ID` and `CF_API_TOKEN` environment variables are set correctly
- Check that your Cloudflare account has AI API enabled

### Language Not Supported
- Review the tested languages for your chosen model in `llm-models.json`
- Some models have perfect support (Mistral, SEA-LION), others have partial support

### Rate Limiting
- Adjust batch sizes in `llm-models.json` if hitting rate limits
- Implement retry logic using `test-languages-retry.php`

## Performance Tips

- Use **Qwen 3 30B** for best cost-efficiency
- Use **Mistral Small 3.1** for highest language support and 128K context
- Batch multiple subtitles to reduce API calls
- Test with small SRT files first

## Contributing

Contributions are welcome! Feel free to submit issues and pull requests.

## License

[Add your chosen license]

## Support

For questions or issues:
- Open an issue on GitHub
- Check existing test results in output files
