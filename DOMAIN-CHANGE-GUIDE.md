# Domain Change Friendly Design - Implementation Guide

## Overview

This plugin is designed to be completely domain-agnostic. It can be moved between environments (dev/staging/production) or have its domain changed without requiring any code modifications.

## Architecture

### 1. Central Configuration

All configurable values are centralized in `pc_get_plugin_config()`:

```php
$config = pc_get_plugin_config();
// Access: $config['github_repo_owner'], $config['plugin_slug'], etc.
```

**Location:** `dw-wholesale.php`

**Benefits:**
- Single source of truth for all plugin settings
- Easy to modify for different environments
- No scattered configuration values

### 2. URL Generation

**NEVER use hardcoded URLs.** Always use WordPress functions:

#### ✅ CORRECT - Domain Agnostic:
```php
// Admin URLs
admin_url( 'admin.php?page=settings' );

// Plugin URLs
plugin_dir_url( __FILE__ );
plugin_dir_path( __FILE__ );

// Site URLs
home_url( '/products' );
site_url( '/api' );

// AJAX URLs
admin_url( 'admin-ajax.php' );

// REST API URLs
rest_url( 'pc/v1/products' );
```

#### ❌ WRONG - Hardcoded (DO NOT USE):
```php
// NEVER DO THIS:
'https://johnk598.sg-host.com/wp-admin/...'
'https://example.com/wp-content/plugins/...'
```

### 3. URL Helper Class

Use `PC_URL_Helper` for common URL operations:

```php
// Get admin URL
PC_URL_Helper::get_admin_url( 'admin.php?page=settings' );

// Get plugin assets
PC_URL_Helper::get_css_url( 'style.css' );
PC_URL_Helper::get_js_url( 'script.js' );

// Get AJAX URL
PC_URL_Helper::get_ajax_url();

// Get REST API URL
PC_URL_Helper::get_rest_url( 'pc/v1/products' );
```

**Location:** `includes/class-pc-url-helper.php`

### 4. GitHub Updater

The GitHub updater is completely domain-independent:

- Uses GitHub API only (external service)
- Relies on plugin file path (relative to WordPress)
- Uses plugin slug (identifier, not URL)
- No site domain dependencies

**Location:** `includes/class-pc-github-updater.php`

## Database Storage Rules

### ✅ CORRECT - Store Only Relative Data:
```php
// Store plugin version
update_option( 'pc_plugin_version', '1.0.0' );

// Store attachment ID (not URL)
update_post_meta( $post_id, '_product_image_id', $attachment_id );

// Store relative paths
update_option( 'pc_upload_path', 'products' );
```

### ❌ WRONG - Never Store Absolute URLs:
```php
// NEVER DO THIS:
update_option( 'pc_site_url', 'https://example.com' );
update_post_meta( $post_id, '_product_image_url', 'https://example.com/wp-content/uploads/...' );
```

## Image Handling

Always use WordPress attachment IDs, never absolute URLs:

```php
// ✅ CORRECT
$image_id = get_post_meta( $post_id, '_product_image_id', true );
$image_url = wp_get_attachment_image_url( $image_id, 'full' );

// ❌ WRONG
$image_url = get_post_meta( $post_id, '_product_image_url', true ); // Hardcoded URL
```

## Verification Checklist

Before releasing, verify:

- [ ] No hardcoded site URLs in PHP files
- [ ] No hardcoded site URLs in JavaScript files
- [ ] No hardcoded site URLs in CSS files
- [ ] All URLs use WordPress functions (`site_url()`, `home_url()`, `admin_url()`, `plugin_dir_url()`)
- [ ] GitHub updater works after domain change
- [ ] No absolute URLs stored in database
- [ ] Images use attachment IDs, not URLs
- [ ] Plugin activation doesn't store domain-specific data
- [ ] All AJAX calls use `admin_url( 'admin-ajax.php' )`
- [ ] All REST API calls use `rest_url()`

## Testing Domain Changes

1. **Change Site URL:**
   ```php
   // In wp-config.php
   define( 'WP_HOME', 'https://newdomain.com' );
   define( 'WP_SITEURL', 'https://newdomain.com' );
   ```

2. **Or in Database:**
   ```sql
   UPDATE wp_options SET option_value = 'https://newdomain.com' WHERE option_name = 'home';
   UPDATE wp_options SET option_value = 'https://newdomain.com' WHERE option_name = 'siteurl';
   ```

3. **Verify:**
   - Plugin still functions correctly
   - GitHub updater still works
   - All URLs generate correctly
   - No broken links or images

## Common Patterns

### Enqueue Scripts/Styles
```php
function pc_enqueue_assets() {
    wp_enqueue_style(
        'pc-style',
        PC_URL_Helper::get_css_url( 'style.css' ),
        array(),
        pc_get_plugin_config()['plugin_version']
    );
    
    wp_enqueue_script(
        'pc-script',
        PC_URL_Helper::get_js_url( 'script.js' ),
        array( 'jquery' ),
        pc_get_plugin_config()['plugin_version'],
        true
    );
    
    // Localize script with domain-agnostic URLs
    wp_localize_script( 'pc-script', 'pcData', array(
        'ajaxUrl' => PC_URL_Helper::get_ajax_url(),
        'restUrl' => PC_URL_Helper::get_rest_url( 'pc/v1/' ),
    ) );
}
```

### AJAX Handler
```php
// ✅ CORRECT
add_action( 'wp_ajax_pc_action', 'pc_ajax_handler' );
function pc_ajax_handler() {
    // Handler code
    wp_send_json_success( array( 'message' => 'Success' ) );
}

// In JavaScript:
jQuery.post( pcData.ajaxUrl, {
    action: 'pc_action',
    // ... data
} );
```

### REST API Endpoint
```php
// ✅ CORRECT
add_action( 'rest_api_init', function() {
    register_rest_route( 'pc/v1', '/products', array(
        'methods' => 'GET',
        'callback' => 'pc_get_products',
    ) );
} );

function pc_get_products( $request ) {
    // Return data - no URLs
    return rest_ensure_response( array(
        'products' => array( /* data */ ),
    ) );
}
```

## External URLs (Acceptable)

These external URLs are acceptable as they are not site-specific:

- GitHub API: `https://api.github.com` (external service)
- GitHub repository URLs: `https://github.com/owner/repo` (external)
- License URLs: `https://www.gnu.org/licenses/gpl-2.0.html` (external)

## Summary

**Golden Rule:** If it's related to YOUR site, use WordPress functions. If it's external (GitHub, licenses, etc.), hardcoded URLs are acceptable.

The plugin will work seamlessly across any domain or environment without code changes.


