# Schema Conflict Resolution

## Overview

The AI Schema Generator plugin includes an automatic **Schema Conflict Resolver** that detects and disables all competing schema markup sources to ensure only our AI-generated schema appears in the page `<head>`.

## How It Works

### Detection
The plugin scans for active plugins and detects if any are installed:
- Yoast SEO
- RankMath
- All-in-One SEO Pack
- SEOPress
- The SEO Framework
- Elementor
- Genesis Framework
- Divi/Extra
- Custom theme schema files

### Disabling
For each detected plugin, the resolver:
1. **Identifies the specific hooks** used for schema output
2. **Removes those hooks** from WordPress' action queue
3. **Logs the action** for transparency in the admin panel

### Verification
All actions are logged and can be viewed in two places:
1. **Settings → AI Schema Generator** - Shows summary of conflicts
2. **Settings → AI Schema Dashboard** - Shows detailed log

## Supported Plugins & Hooks Disabled

### Yoast SEO (`wordpress-seo/wp-seo.php`)
```php
remove_action('wp_head', 'wpseo_json_ld_output', 99);
remove_action('wp_head', 'wpseo_output_structured_data', 21);
remove_action('wp_head', 'wpseo_frontend_presenter', 10);
```

### RankMath (`seo-by-rank-math/rank-math.php`)
```php
remove_action('wp_head', 'rank_math_json_ld', 50);
remove_action('wp_head', 'rank_math_schema_output', 10);
remove_action('wp_head', 'rank_math_output_schema', 10);
```

### All-in-One SEO (`all-in-one-seo-pack/all_in_one_seo_pack.php`)
```php
remove_action('wp_head', 'aioseo_output_json_ld_markup', 10);
remove_action('wp_head', 'aioseo_output_schema_markup', 10);
```

### SEOPress (`seopress/seopress.php`)
```php
remove_action('wp_head', 'seopress_display_json_ld', 100);
remove_action('wp_head', 'seopress_json_ld', 1);
```

### The SEO Framework (`autodescription/autodescription.php`)
```php
remove_action('wp_head', 'tsf_output_structured_data', 9);
remove_action('wp_head', 'tsf_json_ld_output', 10);
```

### Elementor (`elementor/elementor.php`)
```php
remove_action('wp_head', 'elementor_print_schema_markup', 1);
remove_action('wp_head', 'elementor_output_schema', 10);
```

### Genesis Framework (`genesis/genesis.php`)
```php
remove_action('wp_head', 'genesis_output_json_ld', 5);
```

### Divi/Extra (`divi-builder/divi-builder.php`)
```php
remove_action('wp_head', 'et_output_schema', 10);
remove_action('wp_head', 'et_json_ld_output', 10);
```

### Theme Schema
Detects and disables schema from theme files:
- `inc/schema.php`
- `inc/schema-json-ld.php`
- `lib/schema.php`
- `includes/schema.php`

Specifically disables the `inhertzweb` theme hooks:
```php
remove_action('wp_head', 'ihw_output_schema_org', 99);
remove_action('wp_head', 'ihw_output_schema_local_business', 10);
```

## Runtime Execution

The conflict resolver runs at two critical points:

### 1. `plugins_loaded` Hook (Priority 5)
- Early enough to catch most plugin-registered hooks
- Plugins typically register on `plugins_loaded` at default priority (10)
- Our resolver runs first and can safely remove their hooks

### 2. `after_setup_theme` Hook (Priority 5)
- Catches any theme-registered hooks
- Runs after all theme setup is complete

## Logging

Every conflict resolution is logged with:
- **Timestamp**: When the conflict was detected
- **Plugin**: Which plugin/file was generating schema
- **Hook**: Which WordPress hook was removed
- **Function**: The callback function that was removed
- **Priority**: The WordPress action priority

### Example Log Entry
```
2026-04-24 14:14:27 - conflict_resolver: Theme schema file found and disabled: inc/schema-json-ld.php
```

### View Logs
```php
// Get all disabled conflicts
$conflicts = get_option('aisg_disabled_conflicts');

// Get log entries
$log = get_option('aisg_log');
$recent = array_filter($log, fn($e) => $e['type'] === 'conflict_resolver');
```

## API Usage

### Get Disabled Conflicts
```php
use IHW_AISG\SchemaConflictResolver;

$disabled = SchemaConflictResolver::get_disabled_conflicts();
// Returns: [
//   ['plugin' => 'yoast...', 'hook' => 'wp_head', 'function' => '...', 'priority' => 99],
//   ...
// ]
```

### Get Summary
```php
$summary = SchemaConflictResolver::get_summary();
// Returns: ['yoast-seo' => 3, 'rankmath' => 2, ...]
```

### Check if Plugin Schema Disabled
```php
if (SchemaConflictResolver::is_plugin_schema_disabled('yoast')) {
    // Yoast schema has been disabled
}
```

## Testing

### Verify Conflict Detection

1. **Install a test plugin** with schema output (e.g., Yoast SEO)
2. **Activate AI Schema Generator**
3. **Check Settings page** - Should show conflict summary
4. **View page source** - Should have ONLY ONE `<script type="application/ld+json">` block

### Manual Test
```bash
# Check if conflicts were detected
studio wp eval 'var_dump(get_option("aisg_disabled_conflicts"));'

# Check log for conflict_resolver entries
studio wp eval 'foreach((array)get_option("aisg_log") as $entry) { if($entry["type"]=="conflict_resolver") echo $entry["message"]."\n"; }'
```

## Troubleshooting

### "Schema from other plugin still appears"
- Run: `studio wp plugin deactivate ai-schema-generator && studio wp plugin activate ai-schema-generator`
- This re-triggers the conflict resolution
- Check logs for any errors

### "Too many conflicting plugins"
- Recommendation: Disable all SEO plugins except AI Schema Generator
- AI Schema Generator provides complete schema coverage

### "Custom theme schema not detected"
- Edit theme schema file location in `SchemaConflictResolver::$conflicts`
- File must use a recognized hook name (see list above)
- Manually test: `remove_action('wp_head', 'your_function_name', your_priority)`

## FAQ

**Q: Will disabling other schema hurt SEO?**
A: No. AI Schema Generator produces better schema than most plugins. Our schema is:
- Context-aware (reads your llms.txt + brief)
- AI-optimized for E-E-A-T signals
- Cleaner and more comprehensive

**Q: Can I keep Yoast/RankMath for other features?**
A: Yes! The plugin only disables their *schema* output hooks. All other features (sitemap, redirects, readability, etc.) remain active.

**Q: What if a new SEO plugin is released?**
A: Add its hooks to `SchemaConflictResolver::$conflicts` in the format shown above, or contact support for a plugin update.

**Q: Can conflicts be re-enabled?**
A: Not recommended, as it will create duplicate schema. If needed, manually add hooks back in your custom plugin.

## Code Reference

See `includes/class-schema-conflict-resolver.php` for implementation details.
