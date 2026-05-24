# Installation

## Requirements

- WordPress 6.5+
- WooCommerce 8.0+
- PHP 8.0+

## Install

1. Download the `droplock-for-woocommerce.zip` file.
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip and click **Install Now**.
4. Click **Activate Plugin**.

## Manual install via FTP

1. Unzip `droplock-for-woocommerce.zip`.
2. Upload the `droplock-for-woocommerce/` folder to `/wp-content/plugins/`.
3. In WordPress admin, go to **Plugins** and activate **DropLock for WooCommerce**.

## After activation

- A new menu appears at **WooCommerce → DropLock**.
- New fields appear in the **General** tab of every product.
- A custom DB table is created automatically: `wp_droplock_blocked_log` (prefix may differ).

## Updating

Until an automatic update channel is configured, updates are delivered by re-uploading a new zip from the download portal. You can safely overwrite the plugin folder — your product settings are stored in postmeta and will be preserved.
