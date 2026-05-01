# AI Schema Generator

Auto-generate JSON-LD schema markup for every WordPress page/post using Claude or Gemini AI.

## Overview

AI Schema Generator uses advanced AI models (Claude or Gemini) to intelligently analyze your page content and automatically generate accurate, SEO-optimized JSON-LD schema markup. No manual configuration needed — just install, configure your API key, and let AI do the heavy lifting.

### Key Features

- **🤖 AI-Powered**: Uses Claude (Sonnet/Opus) or Gemini 3.x models for intelligent schema generation
- **📊 25+ Schema Types**: Supports Organization, LocalBusiness, Person, Article, FAQ, Product, Service, Event, and more
- **🏢 Smart Address Extraction**: Auto-fetches from Yoast SEO or intelligently parses footer HTML via AI
- **✅ Intelligent Post-Processing**: Auto-fixes BreadcrumbList, Organization, LocalBusiness, FAQPage issues
- **🔧 Auto-Corrections**: Deduplicates fields, validates addresses, strips HTML from FAQ answers
- **🔄 Auto-Generation**: Optional automatic schema generation on publish
- **📈 Bulk Processing**: Generate schemas for all existing posts in batches
- **🛡️ Conflict Resolution**: Automatically detects and disables competing plugins (Yoast, RankMath, etc.)
- **📱 REST API**: Full REST endpoints for programmatic access
- **🔍 Diagnostics**: Built-in diagnostic tools and WP-CLI commands
- **🌍 Multi-Language**: Supports Italian and English content analysis
- **💾 Encrypted Keys**: API keys encrypted with WordPress salt for security

## Installation

1. Download or clone this plugin to `wp-content/plugins/ai-schema-generator/`
2. Activate the plugin from WordPress admin
3. Go to **Settings → AI Schema Generator**
4. Configure your API key (Claude or Gemini)
5. Enter your business brief and site context

## Smart Post-Processing

The plugin automatically detects and fixes common schema generation issues:

| Issue | Solution |
|-------|----------|
| Missing BreadcrumbList `item` URLs | Auto-populates from breadcrumb names |
| Duplicate fields (`logo`/`image`, multiple addresses) | Deduplicates, keeps primary field |
| Incomplete addresses | Validates completeness, removes if too sparse |
| HTML in FAQ answers | Strips tags, decodes entities, normalizes whitespace |
| Missing phone numbers | Auto-fetches from Yoast SEO or footer settings |
| Organization name duplicates | Keeps official name, removes abbreviations |

## Address Extraction

The plugin automatically extracts business address from:

1. **Yoast SEO** (primary) — Local business settings (address, city, zipcode, country, phone)
2. **Footer HTML** (fallback) — Intelligently parses footer using AI to identify address components

If neither source has complete data, the plugin includes what's available and prompts you to fill in missing fields.

## Configuration

### API Keys

The plugin supports two AI providers:

**Claude (Anthropic)**
- Model: `claude-sonnet-4-20250514` (default) or `claude-opus-4-20250805`
- Get API key: [console.anthropic.com](https://console.anthropic.com)
- Max tokens: 4096 per request
- Retry policy: 3 attempts with exponential backoff (2s, 4s, 8s)

**Gemini (Google)**
- Models: `gemini-3.1-pro-preview` (default) or `gemini-3.5-flash-preview`
- Get API key: [aistudio.google.com](https://aistudio.google.com)
- Max tokens: 4096 per request
- Retry policy: 3 attempts with exponential backoff

## How It Works

```
1. Analyze Page Content
   └─ Extract text, metadata, FAQs, images

2. Extract Business Address
   ├─ Check Yoast SEO settings (primary)
   └─ Parse footer HTML via AI (fallback)

3. Build AI Prompt
   ├─ Include page content
   ├─ Include extracted address data
   └─ Include business brief + llms.txt context

4. Generate Schema
   └─ Call Claude or Gemini API

5. Post-Process & Validate
   ├─ Fix BreadcrumbList item URLs
   ├─ Deduplicate addresses and fields
   ├─ Validate address completeness
   ├─ Strip HTML from FAQ answers
   └─ Add missing fields

6. Inject into wp_head
   └─ Output as JSON-LD <script> tag
```

## Usage

### Dashboard

Go to **AI Schema Generator → Dashboard** to:
- View schema coverage statistics
- Monitor bulk generation progress
- Check recent activity logs
- Detect and manage conflicting plugins

### Bulk Generation

Generate schemas for existing posts in three modes:

- **Generate Missing**: Only posts without schemas
- **Rigenerate Outdated**: Posts modified since schema was generated
- **Generate All**: All posts, overwriting existing schemas

### WP-CLI Commands

**View Diagnostics**
```bash
wp aisg diagnostics
```

**View Recent Logs**
```bash
wp aisg logs [--limit=50]
```

## REST API

#### Get Schema
```bash
GET /wp-json/aisg/v1/schema/{post_id}
```

#### Regenerate Schema
```bash
POST /wp-json/aisg/v1/regenerate/{post_id}
# Requires: manage_options capability
```

## Database Structure

### Post Meta
- `_aisg_schema_json`: Generated JSON-LD schema
- `_aisg_schema_generated_at`: Timestamp of generation

### Options
- `aisg_provider`: Active AI provider (claude/gemini)
- `aisg_model_claude`: Claude model in use
- `aisg_model_gemini`: Gemini model in use
- `aisg_business_brief`: Business context
- `aisg_log`: Activity log (max 1000 entries)
- `aisg_bulk_queue`: Batch processing queue
- `aisg_bulk_progress`: Progress tracking

## Security

- **API Keys**: Encrypted with `openssl_encrypt()` using WordPress salt
- **Nonce Validation**: All AJAX actions protected with nonces
- **Capability Checks**: Regenerate endpoint requires `manage_options`
- **URL Escaping**: All URLs sanitized with `esc_url_raw()`
- **Text Sanitization**: All text fields sanitized

## Project Structure

```
ai-schema-generator/
├── ai-schema-generator.php          # Plugin entry point
├── includes/
│   ├── class-settings.php           # Settings API & encryption
│   ├── class-llms-fetcher.php       # llms.txt caching
│   ├── class-ai-engine.php          # AI provider factory
│   ├── class-claude-provider.php    # Anthropic API integration
│   ├── class-gemini-provider.php    # Google Generative AI integration
│   ├── class-page-analyzer.php      # Content analysis & type inference
│   ├── class-faq-extractor.php      # FAQ pattern detection
│   ├── class-schema-builder.php     # Schema generation orchestration
│   ├── class-bulk-processor.php     # Batch queue processing
│   ├── class-output-injector.php    # Schema injection & REST endpoints
│   ├── class-schema-conflict-resolver.php  # Competing plugin detection
│   ├── class-diagnostics.php        # Diagnostic utilities
│   └── class-cli.php                # WP-CLI commands
├── admin/
│   ├── class-admin-menu.php         # Admin pages & meta boxes
│   └── views/
│       ├── settings.php             # Settings page
│       ├── dashboard.php            # Main dashboard
│       └── meta-box.php             # Post meta box
└── README.md                        # This file
```

## License

GPL-2.0+ - See plugin header

## Version

1.0.0 - Initial release

---

Made with ❤️ for Inhertzweb Agency
