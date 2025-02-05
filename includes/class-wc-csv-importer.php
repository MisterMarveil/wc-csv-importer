 
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
        echo '<form method="post" action="'.admin_url('admin-post.php').'">';
        echo '<input type="hidden" name="action" value="wc_csv_import">';
        echo '<label for="csv_url">URL du fichier CSV :</label> ';
        echo '<input type="text" name="csv_url" id="csv_url" required style="width: 100%; max-width: 600px;" />';
        echo '<br><br><input type="submit" name="import_csv" value="Importer" class="button button-primary" />';
        echo '</form></div>';
    }

    public function process_csv_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas la permission d’effectuer cette action.'));
        }

        if (!isset($_POST['csv_url']) || empty($_POST['csv_url'])) {
            wp_die(__('Aucune URL de fichier CSV spécifiée.'));
        }

        $csv_url = esc_url_raw($_POST['csv_url']);
        $csv_file = download_url($csv_url);

        if (is_wp_error($csv_file)) {
            $error_message = $csv_file->get_error_message();
            wp_die(__('Erreur lors du téléchargement du fichier CSV : ' . $error_message));
        }

        $handler = new WC_CSV_Product_Handler();
        $handler->import_products($csv_file);

        unlink($csv_file); // Supprime le fichier après traitement

        wp_redirect(admin_url('admin.php?page=wc_csv_importer&import_success=1'));
        exit;
    }
}