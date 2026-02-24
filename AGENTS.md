# AGENTS.md - Guidelines for AI Coding Agents

## Project Overview

**Obsługa dokumentów księgowych** is a WordPress plugin for managing PIT-11 tax documents. It allows accountants to upload PDF files and taxpayers to download them after identity verification.

### Directory Structure
```
obsluga-dokumentow-ksiegowych/
├── obsluga-dokumentow-ksiegowych.php       # Main plugin file (entry point)
├── uninstall.php         # Plugin uninstall handler
├── readme.txt            # WordPress plugin readme
├── assets/
│   ├── style.css         # Frontend/admin styles
│   └── script.js         # Frontend/admin JavaScript
└── includes/
    ├── class-database.php    # Database operations (singleton)
    ├── class-admin.php       # WordPress admin panel (singleton)
    ├── class-accountant.php  # Accountant panel shortcode (singleton)
    └── class-client.php      # Taxpayer panel shortcode (singleton)
```

## Build/Test Commands

This is a WordPress plugin - no build step required. For development:

```bash
# Create installable ZIP
zip -r obsluga-dokumentow-ksiegowych.zip obsluga-dokumentow-ksiegowych -x "*.git*"

# PHP syntax check (single file)
php -l obsluga-dokumentow-ksiegowych/includes/class-database.php

# PHP syntax check (all files)
find obsluga-dokumentow-ksiegowych -name "*.php" -exec php -l {} \;

# WordPress coding standards (if phpcs installed)
phpcs --standard=WordPress obsluga-dokumentow-ksiegowych/

# Fix coding standards automatically
phpcbf --standard=WordPress obsluga-dokumentow-ksiegowych/
```

### Testing in WordPress
1. Upload ZIP via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Configure at Tools > Obsługa dokumentów księgowych
4. Create pages with shortcodes: `[pit_accountant_panel]` and `[pit_client_page]`

## Code Style Guidelines

### PHP

#### File Header
```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class description.
 */
class PIT_ClassName {
```

#### Naming Conventions
- **Classes**: `PIT_` prefix, PascalCase: `PIT_Database`, `PIT_Admin`
- **Functions**: `pit_` prefix, snake_case: `pit_activate_plugin()`, `pit_get_upload_dir()`
- **Methods**: camelCase: `get_instance()`, `handle_upload()`
- **Variables**: snake_case: `$file_path`, `$tax_year`, `$is_downloaded`
- **Constants**: UPPERCASE: `PIT_VERSION`, `PIT_PLUGIN_DIR`
- **Hooks/Options**: `pit_` prefix: `pit_accountant_users`, `pit_company_name`

#### Formatting
- **Indentation**: Tabs (not spaces)
- **Spacing**: Space inside parentheses: `function_call( $arg )`, `if ( $condition )`
- **Braces**: Same line for control structures
- **Arrays**: Short syntax `[]`, aligned values for readability

```php
// Correct formatting
if ( ! empty( $files ) ) {
    foreach ( $files as $file ) {
        $db->delete_file( $file->id );
    }
}

$options = [
    'sanitize_callback' => [ $this, 'sanitize_user_ids' ],
    'default'           => [],
];
```

#### Type Declarations
- Use PHP 8.0+ type hints for all parameters and return types
- Use nullable types: `?string`, `?int`
- Use `void` for methods with no return

```php
public function get_file_by_id( int $file_id ): ?object {
    // ...
}

public function delete_file( int $file_id ): bool {
    // ...
}
```

#### Singleton Pattern
All main classes use singleton pattern:
```php
private static ?PIT_ClassName $instance = null;

public static function get_instance(): self {
    if ( null === self::$instance ) {
        self::$instance = new self();
    }
    return self::$instance;
}
```

### WordPress Functions
- Always escape output: `esc_html()`, `esc_attr()`, `esc_url()`
- Use `__()` and `esc_html_e()` for translations with text domain `obsluga-dokumentow-ksiegowych`
- Sanitize input: `sanitize_text_field()`, `(int)`, `absint()`
- Use nonces for forms: `wp_nonce_field()`, `wp_verify_nonce()`
- Use `$wpdb->prepare()` for SQL queries

### JavaScript

#### Structure
- IIFE with jQuery: `(function($) { ... })(jQuery);`
- Use `'use strict';`
- Initialize on `$(document).ready()`
- Separate functions for each feature

```javascript
(function($) {
    'use strict';

    $(document).ready(function() {
        initFeature();
    });

    function initFeature() {
        var $element = $('#element-id');
        if ($element.length === 0) {
            return;
        }
        // ...
    }
})(jQuery);
```

### CSS

#### Variables
Use CSS custom properties in `:root`:
```css
:root {
    --pit-accent: #2c5282;
    --pit-border: #d0d0d0;
}
```

#### Naming
- Prefix all classes with `pit-`: `.pit-table`, `.pit-form-row`
- Use BEM-like naming: `.pit-client-form`, `.pit-submit-btn`

## Error Handling

- Use `wp_die()` for fatal errors with translated messages
- Redirect with query params for user feedback: `?pit_error=1`, `?pit_uploaded=5`
- Validate all user input before processing
- Check capabilities: `current_user_can( 'manage_options' )`

```php
if ( ! $this->check_access() ) {
    wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
}

if ( ! wp_verify_nonce( $_POST['pit_nonce'] ?? '', 'pit_action' ) ) {
    wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
}
```

## Database Operations

- Use `$wpdb->prepare()` for all queries with variables
- Use `%i` placeholder for table names (WordPress 6.2+)
- Use transactions for multi-step operations
- Always check return values

```php
$wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM %i WHERE tax_year = %d",
        self::$table_files,
        $tax_year
    )
);
```

## Important Notes

1. **Text Domain**: Always use `'obsluga-dokumentow-ksiegowych'` for translations
2. **Uploads**: Files stored in `wp-content/plugins/obsluga-dokumentow-ksiegowych/uploads/`
3. **Shortcodes**: `[pit_accountant_panel]` for accountants, `[pit_client_page]` for taxpayers
4. **Capabilities**: Custom capability `pit_upload_documents` for accountants
5. **Uninstall**: Uses `uninstall.php` (not `register_uninstall_hook`)
