# WooCommerce CSV Importer

## Description

WooCommerce CSV Importer is a WordPress plugin that allows importing WooCommerce products from a CSV file provided via a URL. It supports automatic updates, category assignments, image handling, and a cron job feature to automate the import process.

## Features

- Import products from a CSV file
- Automatically update existing products based on last modification date
- Assign products to hierarchical categories
- Download and set product images (main & gallery)
- Store and update product barcodes (EAN)
- Handle stock levels dynamically
- Map shipping & customs data for international trade
- Integrate product variations
- Secure cron job integration for automated imports

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **WooCommerce CSV Importer** under the WordPress admin menu.
4. Enter the **CSV file URL** and save it.
5. Click **Import** to manually run the import process.

## CSV File Format

Ensure your CSV file contains the following headers:

```
sku, name, description, price, date_of_last_modification, main_category, brand, main_image_url, images_csv, dealer_price, recommended_sale_price, recommended_sale_price_with_taxes, available_stock, there_is_stock, shipping_costs, hs_intrastat_code, variations_info_xml, barcode_info_xml
```

- `sku`: Unique identifier for the product.
- `name`: Product name.
- `description`: Product description.
- `price`: Product price.
- `date_of_last_modification`: Timestamp of the last modification.
- `main_category`: Categories (separated by `|` for hierarchy).
- `brand`: Product brand.
- `main_image_url`: URL of the main product image.
- `images_csv`: URLs of additional product images (separated by `|`).
- `dealer_price`: Cost price for suppliers.
- `recommended_sale_price`: Suggested retail price.
- `recommended_sale_price_with_taxes`: Retail price including VAT.
- `available_stock`: Stock level.
- `there_is_stock`: Boolean indicating stock availability.
- `shipping_costs`: Shipping costs associated with the product.
- `hs_intrastat_code`: International trade code for customs.
- `variations_info_xml`: XML structure detailing product variations.
- `barcode_info_xml`: XML containing barcode details (EAN, UPC, etc.).

## Automating Imports with Cron Jobs

You can schedule automatic imports by calling the plugin’s import function via a **cron job**.

### Step 1: Retrieve the Secure Cron Job URL

The plugin generates a **secret key** stored in WordPress options. Retrieve it with the following command:

```php
get_option('wc_csv_cron_secret_key');
```

Or check your WordPress settings.

### Step 2: Set Up a Cron Job

Use a server-side cron job or external service (like cron-job.org) to trigger the import periodically.

#### Example Cron Job (Every Hour)

```sh
wget -qO- "https://jardinsucre.fr/wp-admin/admin-ajax.php?action=wc_csv_cron_import&cron_key=kxIdq1eKQKl1iZrEJ6SmUoqNcmFmAlXc"
wget -qO- "https://yourwebsite.com/wp-admin/admin-ajax.php?action=wc_csv_cron_import&cron_key=YOUR_SECRET_KEY"
```

OR

```sh
curl -s "https://yourwebsite.com/wp-admin/admin-ajax.php?action=wc_csv_cron_import&cron_key=YOUR_SECRET_KEY"
```

Replace `YOUR_SECRET_KEY` with the actual generated key.

### Alternative: WP-Cron

If using WordPress Cron, schedule a hook in your `functions.php`:

```php
if (!wp_next_scheduled('wc_csv_scheduled_import')) {
    wp_schedule_event(time(), 'hourly', 'wc_csv_scheduled_import');
}
add_action('wc_csv_scheduled_import', 'wc_csv_cron_import');
```

## Security Considerations

- The cron job requires a **secure key** to prevent unauthorized access.
- Always keep WordPress and WooCommerce updated to ensure compatibility.
- Ensure your server allows external URL fetching (if importing from a remote source).

## Support

For any issues or feature requests, please open a ticket on the plugin’s repository or contact the developer.

## License

This plugin is licensed under the GPL2.

