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