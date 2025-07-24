# AI Book Processing - Multi-Provider Support (Gemini + Claude + ChatGPT)

## Overview

This system uses AI to automatically extract and improve book metadata from directory paths, filenames, and audio file tags. It supports Google's Gemini models, Anthropic's Claude models, and OpenAI's ChatGPT models with different pricing tiers and capabilities.

## Supported Models

### Google Gemini Models (Official Documentation)

### Gemini 2.5 Flash Lite (Default - Recommended)
- **Free tier**: 15 RPM, **1,000 RPD**, 250K TPM
- **Paid tier**: 1,000 RPM, 4M TPM, no daily limit
- **Pricing**: $0.10/1M input, $0.40/1M output
- **Best for**: Highest daily limit, cost-effective processing

### Gemini 2.0 Flash Lite (Best RPM for Free Tier)
- **Free tier**: **30 RPM**, 200 RPD, 1M TPM
- **Paid tier**: 1,000 RPM, 4M TPM, no daily limit
- **Pricing**: **$0.075/1M input, $0.30/1M output** (Cheapest!)
- **Best for**: Lowest cost + fastest processing rate (30 RPM)

### Gemini 2.0 Flash
- **Free tier**: 15 RPM, 200 RPD, 1M TPM
- **Paid tier**: 1,000 RPM, 4M TPM, no daily limit
- **Pricing**: $0.10/1M input, $0.40/1M output
- **Best for**: Standard processing with 2.0 features

### Gemini 2.5 Flash (Most Expensive)
- **Free tier**: 10 RPM, 250 RPD, 250K TPM
- **Paid tier**: 1,000 RPM, 4M TPM, no daily limit
- **Pricing**: **$0.30/1M input, $2.50/1M output** (8x more expensive output!)
- **Best for**: Advanced processing, thinking mode capabilities

### Gemini 2.5 Pro (Premium Model)
- **Free tier**: 5 RPM, 100 RPD, 250K TPM
- **Paid tier**: 360 RPM, 4M TPM, no daily limit
- **Pricing**: $1.25/1M input, $10.00/1M output (≤200K), $2.50/$15.00 (>200K)
- **Best for**: Complex tasks requiring highest quality

### Anthropic Claude Models (Official Documentation)

#### Claude 3.5 Haiku (Recommended for Cost)
- **Paid tier only**: ~100 RPM, ~300K TPM (estimates)
- **Pricing**: **$0.80/1M input, $4.00/1M output** (Most cost-effective)
- **Best for**: Fast, cost-effective processing with good quality

#### Claude 3.5 Sonnet (Balanced Performance)
- **Paid tier only**: ~50 RPM, ~200K TPM (estimates)
- **Pricing**: $3.00/1M input, $15.00/1M output
- **Best for**: High-quality results when cost is less of a concern

#### Claude 4 Sonnet (Latest Model)
- **Paid tier only**: ~50 RPM, ~200K TPM (estimates)
- **Pricing**: $3.00/1M input, $15.00/1M output
- **Best for**: Latest capabilities and highest quality

#### Claude 4 Opus (Premium Model)
- **Paid tier only**: ~20 RPM, ~100K TPM (estimates)
- **Pricing**: **$15.00/1M input, $75.00/1M output** (Most expensive)
- **Best for**: Maximum quality when cost is not a concern

### OpenAI ChatGPT Models (Official Documentation)

#### GPT-4o Mini (Recommended for Cost)
- **Paid tier only**: 500 RPM, 200K TPM (Tier 1 limits)
- **Pricing**: **$0.15/1M input, $0.60/1M output** (Best ChatGPT value)  
- **Best for**: Cost-effective processing with good quality

#### GPT-3.5 Turbo (Balanced Performance)
- **Paid tier only**: 3,500 RPM, 160K TPM (Tier 1 limits)
- **Pricing**: $0.50/1M input, $1.50/1M output
- **Best for**: Fast processing with decent quality

#### GPT-4o (Latest Model)
- **Paid tier only**: 500 RPM, 30K TPM (Tier 1 limits)
- **Pricing**: $2.50/1M input, $10.00/1M output
- **Best for**: Latest capabilities and high quality

#### GPT-4 Turbo (Advanced Model)
- **Paid tier only**: 500 RPM, 30K TPM (Tier 1 limits)
- **Pricing**: **$10.00/1M input, $30.00/1M output** (Most expensive)
- **Best for**: Maximum quality when cost is not a concern

## Model Selection Guide

### Gemini Models:
**For highest daily volume:** Choose `gemini-2.5-flash-lite` (1,000 RPD)
**For fastest processing:** Choose `gemini-2.0-flash-lite` (30 RPM)
**For lowest cost:** Choose `gemini-2.0-flash-lite` ($0.075/$0.30 - cheapest!)
**For balanced use:** Choose `gemini-2.0-flash` or `gemini-2.5-flash-lite` ($0.10/$0.40)
**For premium quality:** Choose `gemini-2.5-pro` (but very expensive)
**⚠️ Avoid for budget use:** `gemini-2.5-flash` (8x more expensive output tokens)

### Claude Models:
**For cost-effective quality:** Choose `claude-3-5-haiku` ($0.80/$4.00 - best Claude value)
**For balanced performance:** Choose `claude-3-5-sonnet` ($3.00/$15.00)
**For latest capabilities:** Choose `claude-4-sonnet` ($3.00/$15.00)
**For maximum quality:** Choose `claude-4-opus` ($15/$75 - very expensive)

### ChatGPT Models:
**For cost-effective quality:** Choose `gpt-4o-mini` ($0.15/$0.60 - best ChatGPT value)
**For fast processing:** Choose `gpt-3.5-turbo` ($0.50/$1.50 - highest RPM)
**For latest capabilities:** Choose `gpt-4o` ($2.50/$10.00)
**For maximum quality:** Choose `gpt-4-turbo` ($10/$30 - most expensive)

### Overall Recommendations:
**Free tier + highest volume:** `gemini-2.5-flash-lite` (1,000 requests/day)
**Cheapest paid option:** `gemini-2.0-flash-lite` ($0.075/$0.30)
**Best overall value:** `gpt-4o-mini` ($0.15/$0.60) 
**Best paid quality/cost:** `claude-3-5-haiku` ($0.80/$4.00)
**Premium quality:** `claude-4-sonnet`, `gpt-4o`, or `gemini-2.5-pro`

## Setup Instructions

### 1. Get API Keys

#### For Gemini (Google):
1. Go to [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Click "Create API Key"
3. Copy your API key

#### For Claude (Anthropic):
1. Go to [Anthropic Console](https://console.anthropic.com/)
2. Create an account or sign in
3. Navigate to API Keys section
4. Generate a new API key
5. Copy your API key

#### For ChatGPT (OpenAI):
1. Go to [OpenAI Platform](https://platform.openai.com/api-keys)
2. Create an account or sign in
3. Click "Create new secret key"
4. Copy your API key
5. Set up billing if you haven't already

### 2. Configure Environment

Add to your `.env` file:

```bash
# AI Configuration (choose your preferred provider and model)
AI_DEFAULT_MODEL=gemini-2.5-flash-lite
AI_DEFAULT_PROVIDER=gemini

# Gemini AI Configuration (Google)
GEMINI_API_KEY=your_gemini_api_key_here
GEMINI_MODEL=gemini-2.5-flash-lite
GEMINI_PAID_TIER=false
GEMINI_TIMEOUT=30

# Claude AI Configuration (Anthropic) - Optional
CLAUDE_API_KEY=your_claude_api_key_here
CLAUDE_MODEL=claude-3-5-haiku
CLAUDE_TIMEOUT=30

# OpenAI ChatGPT Configuration - Optional
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_MODEL=gpt-4o-mini
OPENAI_TIMEOUT=30
OPENAI_ORGANIZATION=your_org_id_here  # Optional
```

For paid tier usage:
- **Gemini**: Set `GEMINI_PAID_TIER=true` (requires billing setup in Google AI Studio)
- **Claude**: Always paid tier (no free option available)
- **ChatGPT**: Always paid tier (requires billing setup in OpenAI Platform)

### 3. Run Database Migration

```bash
php artisan migrate
```

This adds columns for AI processing tracking:
- `ai_processed` - Whether book was processed by AI
- `ai_confidence` - AI confidence score (0-100)
- `ai_processed_at` - When AI processing occurred
- `ai_suggestions` - JSON of AI suggestions for manual review
- `language` - Book language code
- `publisher` - Publisher name

## Usage

### Free Tier Usage (Gemini Models Only)

```bash
# Process 10 books with default model (gemini-2.5-flash-lite - highest daily limit)
php artisan books:process-ai

# Use different Gemini models based on your needs
php artisan books:process-ai --model=gemini-2.0-flash-lite   # 30 RPM, 200 RPD - fastest
php artisan books:process-ai --model=gemini-2.5-flash        # 10 RPM, 250 RPD - expensive
php artisan books:process-ai --model=gemini-2.0-flash        # 15 RPM, 200 RPD - standard
php artisan books:process-ai --model=gemini-2.5-pro          # 5 RPM, 100 RPD - premium

# Dry run to see what would be processed
php artisan books:process-ai --dry-run

# Process specific books
php artisan books:process-ai --book=123 --book=456

# Process with higher confidence threshold
php artisan books:process-ai --min-confidence=85

# High-volume free tier processing (up to 1,000 requests/day)
php artisan books:process-ai --limit=50 --model=gemini-2.5-flash-lite

# Fast processing for smaller batches
php artisan books:process-ai --limit=20 --model=gemini-2.0-flash-lite  # 30 RPM rate
```

### Paid Tier Usage (Gemini, Claude, and ChatGPT)

```bash
# Use paid tier with Gemini models (requires billing setup)
php artisan books:process-ai --paid-tier --model=gemini-2.5-flash-lite

# Use Claude models (always paid tier, no free option)
php artisan books:process-ai --model=claude-3-5-haiku        # Most cost-effective Claude model
php artisan books:process-ai --model=claude-3-5-sonnet      # Balanced performance
php artisan books:process-ai --model=claude-4-sonnet        # Latest Claude model
php artisan books:process-ai --model=claude-4-opus          # Premium Claude model (expensive)

# Use ChatGPT models (always paid tier, requires billing)
php artisan books:process-ai --model=gpt-4o-mini            # Most cost-effective ChatGPT
php artisan books:process-ai --model=gpt-3.5-turbo          # Fast processing
php artisan books:process-ai --model=gpt-4o                 # Latest capabilities
php artisan books:process-ai --model=gpt-4-turbo            # Premium quality (expensive)

# High-volume processing with cost estimation
php artisan books:process-ai --limit=100 --model=gpt-4o-mini  # Best overall value

# Force processing even for high-cost operations
php artisan books:process-ai --limit=500 --force --model=gpt-4o
```

### Cost Safety Features

- **$1+ operations require confirmation** (unless `--force` is used)
- **Cost estimates shown** before processing starts
- **Actual costs displayed** after completion
- **Per-book cost breakdown** in paid mode

### Rate Limiting Features

The system automatically:
- Tracks requests per minute and per day
- Waits when rate limits are reached
- Shows estimated processing time
- Warns about free tier limits

### Processing Modes

1. **High Confidence (90%+)**: Auto-applied immediately
2. **Medium Confidence (70-89%)**: Applied if above `--min-confidence`
3. **Low Confidence (<70%)**: Saved for manual review

### Admin Review Interface

Access at `/admin/ai-review` to:
- Review low-confidence AI suggestions
- Selectively apply suggested changes
- Bulk apply high-confidence suggestions
- Reject inappropriate suggestions

## Free Tier Optimizations

### Prompt Optimization
- Concise prompts to reduce token usage
- Limited to first 3 filenames
- Only important audio tags included
- Reduced output token limit (1024 vs 2048)

### Processing Efficiency
- Default limit of 10 books per run
- Automatic rate limiting with sleep delays
- Caching of request counts
- Fallback to basic extraction if AI fails

### Cost Control
- Processing time estimates
- Clear warnings about rate limits
- Conservative defaults
- Batch processing recommendations

## Best Practices

### For Large Libraries

1. **Process in small batches**:
   ```bash
   php artisan books:process-ai --limit=10
   ```

2. **Use high confidence thresholds**:
   ```bash
   php artisan books:process-ai --min-confidence=90
   ```

3. **Schedule processing**:
   ```bash
   # Process 10 books every hour
   0 * * * * cd /path/to/app && php artisan books:process-ai --limit=10
   ```

### Monitoring Usage

Check logs for rate limiting:
```bash
tail -f storage/logs/laravel.log | grep "Gemini"
```

### Database Queries

Check AI processing status:
```sql
SELECT 
    COUNT(*) as total,
    SUM(ai_processed) as processed,
    AVG(ai_confidence) as avg_confidence
FROM books;
```

## Error Handling

The system includes comprehensive error handling:
- Rate limit detection and waiting
- API failure fallback to basic extraction
- Detailed logging of all issues
- Graceful degradation when AI unavailable

## Troubleshooting

### "No API key configured"
- For Gemini: Ensure `GEMINI_API_KEY` is set in `.env`
- For Claude: Ensure `CLAUDE_API_KEY` is set in `.env`
- For ChatGPT: Ensure `OPENAI_API_KEY` is set in `.env`
- Restart your application after adding the key

### "Rate limit reached"
- System will automatically wait and retry
- Consider reducing `--limit` parameter
- Check daily usage hasn't exceeded limits (1,000 requests/day for Gemini free tier)

### "Failed to parse AI response"
- AI sometimes returns malformed JSON (Gemini, Claude, and ChatGPT)
- System falls back to basic extraction
- Check logs for specific parsing errors

### Low Confidence Scores
- Review directory structure and filenames
- Ensure audio files have good ID3 tags
- Complex or unusual naming patterns may confuse AI

## Integration with Existing System

The AI processing integrates seamlessly with:
- Existing book management system
- MongoDB and MySQL data stores
- Cover image processing
- Title cleanup commands

AI suggestions are stored separately until manually reviewed or automatically applied based on confidence thresholds.

## Cost Comparison

### Per 1,000 books processed (estimated):
- **Gemini 2.0 Flash Lite (Free)**: $0.00
- **Gemini 2.0 Flash Lite (Paid)**: ~$0.11
- **Gemini 2.5 Flash Lite (Paid)**: ~$0.14
- **GPT-4o Mini**: ~$0.22 ⭐ (Best overall value)
- **GPT-3.5 Turbo**: ~$0.60
- **Claude 3.5 Haiku**: ~$1.20
- **GPT-4o**: ~$3.75
- **Claude 3.5 Sonnet**: ~$4.50
- **GPT-4 Turbo**: ~$12.00
- **Claude 4 Opus**: ~$22.50

**Recommendation**: Start with Gemini free tier, then upgrade to GPT-4o Mini for best paid value, or Claude 3.5 Haiku for premium quality.