 
<?php


// Définition des classes
class WC_CSV_Importer {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_import_page']);
        add_action('admin_post_wc_csv_import', [$this, 'process_csv_import']);
        add_action('admin_post_wc_csv_reset', [$this, 'reset_woocommerce_data']);
    }

    public function add_import_page() {
        add_menu_page('Import CSV', 'Import CSV', 'manage_options', 'wc_csv_importer', [$this, 'render_import_page']);
    }

    public function render_import_page() {
        
        echo '<form method="post" action="'.admin_url('admin-post.php').'" style="margin-top: 20px;">';
        echo '<input type="hidden" name="action" value="wc_csv_import">';
        echo '<input type="submit" name="import_csv" value="Importer" class="button button-primary" />';
        echo '</form>';
        
        echo '</div>';
    }

    public function render_import_page() {
        $saved_csv_url = get_option('wc_csv_import_url', '');
        echo '<div class="wrap"><h1>Importation CSV WooCommerce</h1>';

        // Afficher le formulaire d'importation
        echo '<form method="post" action="'.admin_url('admin-post.php').'">';
        echo '<input type="hidden" name="action" value="wc_csv_import">';
        echo '<label for="csv_url">URL du fichier CSV :</label> ';
        echo '<input type="text" name="csv_url" id="csv_url" value="' . esc_url($saved_csv_url) . '" required style="width: 100%; max-width: 600px;" />';
        echo '<br><br><input type="submit" name="import_csv" value="Importer" class="button button-primary" />';
        echo '</form>';
        
        // Ajouter le bouton de réinitialisation
        echo '<form method="post" action="'.admin_url('admin-post.php').'" style="margin-top: 20px;">';
        echo '<input type="hidden" name="action" value="wc_csv_reset">';
        echo '<input type="submit" name="reset_db" value="Vider la base de données" class="button button-secondary" onclick="return confirm(\'Êtes-vous sûr de vouloir supprimer tous les produits et leurs données associées ? Cette action est irréversible.\')" />';
        echo '</form>';
        
        echo '</div>';
    }

    public function process_csv_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas la permission d’effectuer cette action.'));
        }

        if (!isset($_POST['csv_url']) || empty($_POST['csv_url'])) {
            wp_die(__('Aucune URL de fichier CSV spécifiée.'));
        }

        $csv_url = esc_url_raw($_POST['csv_url']);
        $csv_file = wp_tempnam($csv_url);

        $response = wp_remote_get($csv_url, array(
            'timeout'  => 30,
            'stream'   => true,
            'filename' => $csv_file
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wp_die(__('Erreur lors du téléchargement du fichier CSV : ' . $error_message));
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            wp_die(__('Erreur HTTP lors du téléchargement du fichier CSV : ' . wp_remote_retrieve_response_message($response)));
        }

        update_option('wc_csv_import_url', esc_url_raw($csv_url));
        

        $handler = new WC_CSV_Product_Handler();
        $handler->import_products($csv_file);

        unlink($csv_file); // Supprime le fichier après traitement

        wp_redirect(admin_url('admin.php?page=wc_csv_importer&import_success=1'));
        exit;
    }

    public function reset_woocommerce_data() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas la permission d’effectuer cette action.'));
        }

        global $wpdb;
        
        // Supprimer tous les produits et leurs métadonnées
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        
        // Supprimer les termes liés aux produits (catégories, tags...)
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        $wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->terms})");
        $wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})");
        
        // Supprimer toutes les images associées aux produits
        $attachments = get_posts([ 'post_type' => 'attachment', 'numberposts' => -1 ]);
        foreach ($attachments as $attachment) {
            wp_delete_attachment($attachment->ID, true);
        }
        
        wp_redirect(admin_url('admin.php?page=wc_csv_importer&reset_success=1'));
        exit;
    }
}