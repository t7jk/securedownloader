# Secure Downloader — WordPress Plugin

WordPress plugin that allows managers to upload documents and clients to download them after identity verification.

## What it does

- **Managers** upload PDF documents (e.g. PIT-11 tax forms) through a dedicated admin panel
- **Clients** download their documents after verifying identity with PESEL, first name, and last name
- **Three access levels:** Administrator, Manager, Client

## Installation

1. Copy the `securedownloader/` folder to `/wp-content/plugins/` on your WordPress server
2. Activate the plugin in WordPress admin → Plugins
3. Configure user roles (Administrator, Manager, Client) in plugin settings
4. Create WordPress pages with the shortcodes below

## Shortcodes

| Shortcode | Purpose |
|-----------|---------|
| `[pit_client_page]` | Client-facing download form (PESEL + name verification) |
| `[pit_accountant_panel]` | Manager panel for uploading and managing documents |

## Requirements

- WordPress 5.0+
- PHP 8.0+

## License

GPL-2.0+
