<?php
/**
 * Plugin Name: WooCommerce CSV Importer
 * Description: Un plugin pour importer des produits WooCommerce à partir d'un fichier CSV via une URL.
 * Version: 1.0
 * Author: Merveil EWONI (BRIDGE SOLUTIONS LTD)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Inclure les fichiers nécessaires
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-product-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-csv-category-handler.php';

// Initialiser le plugin
function wc_csv_importer_init() {
    $importer = new WC_CSV_Importer();
}
add_action('plugins_loaded', 'wc_csv_importer_init');