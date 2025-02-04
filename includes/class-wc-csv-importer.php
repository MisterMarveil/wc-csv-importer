 
<?php

class WC_CSV_Importer {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_csv_import'));
    }

    public function add_admin_menu() {
        add_menu_page('CSV Importer', 'CSV Importer', 'manage_options', 'csv-importer', array($this, 'import_page'));
    }

    public function import_page() {
        include(plugin_dir_path(__FILE__) . '../templates/import-page.php');
    }

    public function handle_csv_import() {
        if (isset($_POST['import_csv'])) {
            $csv_url = sanitize_text_field($_POST['csv_url']);
            $this->import_csv($csv_url);
        }
    }

    public function import_csv($url) {
        // Récupérer le contenu du fichier CSV
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return; // Gérer l'erreur
        }

        $csv_data = wp_remote_retrieve_body($response);
        $lines = explode("\n", $csv_data);
        $header = str_getcsv(array_shift($lines)); // Obtenir l'en-tête

        foreach ($lines as $line) {
            if (empty($line)) continue; // Ignorer les lignes vides
            $data = str_getcsv($line);
            $product_data = array_combine($header, $data);



            $this->create_category($product_data);
            $this->create_product($product_data);
        }
    }
}