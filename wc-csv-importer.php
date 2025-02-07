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
define('BATCH_SIZE', 5);
define('INSERTION_COUNT_OPTION', 'wc_csv_import_last_insert_count');
define('UPDATE_COUNT_OPTION', 'wc_csv_import_last_update_count');
define('LAST_CRON_TIME_OPTION', 'wc_csv_import_last_cron_timestamp');
define('INVALID_ENTITY_COUNT_OPTION', 'wc_csv_import_last_bad_entity');

// Inclure les classes nécessaires
include_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-importer.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-product-handler.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-category-handler.php';

// Initialisation du plugin
function wc_csv_importer_init() {
    new WC_CSV_Importer();
}
add_action('plugins_loaded', 'wc_csv_importer_init');

function wc_importer_scripts() {
    wp_register_style('percicle_style', plugins_url( 'wc-csv-importer/assets/percircle/percircle.css'));
    wp_enqueue_style('percicle_style');
    
    wp_register_script( 'percicle-script', plugins_url( 'wc-csv-importer/assets/percircle/percicle.js'), array ('jquery')  );
    wp_enqueue_script( 'percircle-script' );


    wp_register_script( 'ajax-importer-script', plugins_url( 'wc-csv-importer/assets/js/importer_script.js'), array ('percircle-script')  );
    wp_enqueue_script( 'ajax-importer-script' );
}
 add_action( 'admin_enqueue_scripts', 'wc_importer_scripts');

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

    $csv_url = get_option('wc_csv_import_url', '');
    if (empty($csv_url)) {
        wp_die('No CSV URL defined');
    }

    $csv_file = wp_tempnam($csv_url);
    $response = wp_remote_get($csv_url, array(
        'timeout'  => 1500,
        'stream'   => true,
        'filename' => $csv_file
    ));

    if (is_wp_error($response)) {
        wp_die('Error downloading CSV: ' . $response->get_error_message());
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        wp_die('HTTP error while downloading CSV: ' . wp_remote_retrieve_response_message($response));
    }

    $handler = new WC_CSV_Product_Handler();
    $handler->import_products($csv_file);
    unlink($csv_file);

    $end = new \DateTime();
    echo 'Import completed successfully in '.($end->getTimestamp() - $start->getTimestamp()).'seconds';
    exit;
}