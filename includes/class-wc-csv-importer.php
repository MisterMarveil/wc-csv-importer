 
<?php

// Définition des classes
class WC_CSV_Importer {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_import_page']);
        add_action('admin_post_wc_csv_import', [$this, 'process_csv_import']);
    }

    public function add_import_page() {
        add_menu_page('Import CSV', 'Import CSV', 'manage_options', 'wc_csv_importer', [$this, 'render_import_page']);
    }

    public function render_import_page() {
        echo '<div class="wrap"><h1>Importation CSV WooCommerce</h1>';
        echo '<form method="post" action="'.admin_url('admin-post.php').'" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="wc_csv_import">';
        echo '<input type="file" name="csv_file" required />';
        echo '<input type="submit" name="import_csv" value="Importer" class="button button-primary" />';
        echo '</form></div>';
    }

    public function process_csv_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas la permission d’effectuer cette action.'));
        }

        if (!isset($_FILES['csv_file']) || empty($_FILES['csv_file']['tmp_name'])) {
            wp_die(__('Aucun fichier n’a été téléchargé.'));
        }

        set_time_limit(0);
        $csv_file = $_FILES['csv_file']['tmp_name'];
        $handler = new WC_CSV_Product_Handler();
        $handler->import_products($csv_file);

        wp_redirect(admin_url('admin.php?page=wc_csv_importer&import_success=1'));
        exit;
    }
}
