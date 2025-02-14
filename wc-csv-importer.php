<?php
/**
 * Plugin Name: WooCommerce CSV Importer
 * Description: Plugin permettant d'importer des produits WooCommerce depuis un fichier CSV.
 * Version: 1.0
 * Author: Merveil EWONI <BRIDGE SOLUTIONS LTD>
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité
}

// Définir la constante TIME_TO_CHECK (en secondes)
define('TIME_TO_CHECK', 3600);
define('BATCH_SIZE', 10);
define('BATCH_CATEGORY_SIZE', 50);  //pour gérer les variations, nous récupérons batch_category_size de produits, sachant que juste batch_size vont être traité par appel de la logique d'import
define('INSERTION_COUNT_OPTION', 'wc_csv_import_last_insert_count');
define('UPDATE_COUNT_OPTION', 'wc_csv_import_last_update_count');
define('LAST_CRON_TIME_OPTION', 'wc_csv_import_last_cron_timestamp');
define('INVALID_ENTITY_COUNT_OPTION', 'wc_csv_import_last_bad_entity');
define('PRODUCT_OFFSET_OPTION', 'wc_csv_import_offset');
define('PRODUCT_TOTAL_ROWS_COUNT', 'wc_csv_line_count');
define('CSV_SEPARATOR', ';');
define('PRODUCT_FILE_OPTION', 'wc_csv_import_file');
define('CSV_URL_DEFAULT_VALUE', 'https://store.dreamlove.es/dyndata/exportaciones/csvzip/catalog_1_52_125_2_dd65d46c9efc3d9364272c55399d5b56_csv_plain.csv');
define('CSV_FILE_DEFAULT_VALUE', '/var/www/clients/client0/web1/tmp/data.csv');

// Inclure les classes nécessaires
include_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-importer.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-product-handler.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-category-handler.php';

// Initialisation du plugin
function wc_csv_importer_init() {
    new WC_CSV_Importer();
}
add_action('plugins_loaded', 'wc_csv_importer_init');


// Activation du plugin
function wc_csv_importer_activate() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('WooCommerce doit être activé pour utiliser ce plugin.');
    }
}
register_activation_hook(__FILE__, 'wc_csv_importer_activate');

function wc_csv_generate_secret_key() {
    if (!get_option('wc_csv_cron_secret_key')) {
        update_option('wc_csv_cron_secret_key', wp_generate_password(32, false));
    }
}
register_activation_hook(__FILE__, 'wc_csv_generate_secret_key');

// Définition d'une action pour permettre l'import via une URL pour un cron job
add_action('wp_ajax_nopriv_wc_csv_cron_import', 'wc_csv_cron_import');
add_action('wp_ajax_wc_csv_cron_import', 'wc_csv_cron_import');

function wc_csv_cron_import() {
    $start = new \DateTime();
    if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== get_option('wc_csv_cron_secret_key')) {
        wp_die('Unauthorized', 401);
    }

    $handler = new WC_CSV_Importer();
    $handler->process_csv_import();
    $offset = get_option(PRODUCT_OFFSET_OPTION, 0);

    $end = new \DateTime();
    echo 'Imported  '+($offset == 0  ? 'completed successfully' : $offset+' products') +' in '.($end->getTimestamp() - $start->getTimestamp()).'seconds';
    exit;
}