 
<?php


// Définition des classes
class WC_CSV_Importer {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_import_page']);
        add_action('wp_ajax_initialize_import', [$this, 'initialize_csv_import']);
        add_action('wp_ajax_process_import_batch', [$this, 'process_csv_import']);
        add_action('admin_post_wc_csv_reset', [$this, 'reset_woocommerce_data']);
        add_action('admin_post_wc_csv_save_url', [$this, 'wc_csv_save_url']);
        add_action( 'wp_enqueue_scripts', [$this, 'wc_importer_scripts']);
    }

    public function wc_importer_scripts() {
        wp_register_style('percicle_style', plugins_url( '../assets/percircle/percircle.css', __FILE__));
        wp_enqueue_style('percicle_style');
        
        wp_register_script( 'percicle-script', plugins_url( '../assets/percircle/percicle.js', __FILE__), array ('jquery')  );
        wp_enqueue_script( 'percircle-script' );
    

        wp_register_script( 'ajax-importer-script', plugins_url( '../assets/js/importer_script.js', __FILE__), array ('jquery', 'percircle-script')  );
        wp_enqueue_script( 'ajax-importer-script' );
    }
   
   

    public function add_import_page() {
        add_menu_page('Import CSV', 'Import CSV', 'manage_options', 'wc_csv_importer', [$this, 'render_import_page']);
    }

    public function render_import_page() {        
        $insCount = get_option(INSERTION_COUNT_OPTION, 0);
        $upCount = get_option(UPDATE_COUNT_OPTION, 0);
        $badCount = get_option(INVALID_ENTITY_COUNT_OPTION, 0);

        $lastTime = get_option(LAST_CRON_TIME_OPTION, 0);
        $dateStr = "";
        if($lastTime){
            $now = new \DateTime();
            $now->setTimestamp($lastTime);
            $dateStr = '<div class="notice notice-success is-dismissible"><p>Dernier import csv - ' . $now->format("Y-m-d H:i:s"). "$insCount nouveau(x) produit(s) | $upCount produit(s) mis à jour | $badCount lignes incohérentes détectées</p></div>";
        }

        $saved_csv_url = get_option('wc_csv_import_url', '');

        echo '<div class="wrap"><h1>Importation CSV WooCommerce</h1>';
        echo '<div class="help-block">';
        echo $dateStr;
        if(isset($_GET['import_success']) && $_GET['import_success']) {
            echo '<div class="notice notice-success is-dismissible"><p>Importation réussie.</p></div>';
        }else if(isset($_GET['reset_success']) && $_GET['reset_success']) {
            echo '<div class="notice notice-success is-dismissible"><p>Données réinitialisées.</p></div>';
        }else if(isset($_GET['url_saved']) && $_GET['url_saved']) {
            echo '<div class="notice notice-success is-dismissible"><p>URL sauvegardée.</p></div>';
        }
        echo '</div>';

        // Afficher le formulaire de sauvegarde de l'URL du CSV
        echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display: inline-block; margin-right: 10px;">';
        echo '<input type="hidden" name="action" value="wc_csv_save_url">';
        echo '<label for="csv_url">URL du fichier CSV :</label> ';
        echo '<input type="text" name="csv_url" id="csv_url" value="' . esc_url($saved_csv_url) . '" required style="width: 100%; max-width: 600px;" />';
        echo '<br><br><input type="submit" name="save_csv_url" value="Sauvegarder l\'URL" class="button button-secondary" />';
        echo '</form>';

        // Ajouter le bouton de réinitialisation
        echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display: inline-block; margin-right: 10px;">';
        echo '<input type="hidden" name="action" value="wc_csv_reset">';
        echo '<input type="submit" name="reset_db" value="Vider la base de données" class="button button-secondary" onclick="return confirm(\'Êtes-vous sûr de vouloir supprimer tous les produits et leurs données associées ? Cette action est irréversible.\')" />';
        echo '</form>';

        // Import Button
        echo '<button id="start-import" class="button button-primary">Start Import</button>';
        
        // Progress Bar
        echo '<div style="margin-top: 20px; width: 100%; background-color: #ddd; height: 20px; border-radius: 5px;">';
        echo '<div id="import-progress" data-percent="0" class="hidden big dark blue">0%</div>';       
        echo '</div>';
        
        // Import Status
        echo '<div id="import-status" style="margin-top: 10px;"></div>';
        echo plugins_url( 'wc-csv-importer/assets/js/importer_script.js');
        echo '</div>';        
        echo '</div>';
    }

    public function initialize_import() {
        if (!isset($_POST['csv_url']) || empty($_POST['csv_url'])) {
            $csv_url = get_option('wc_csv_import_url', '');
            if(empty($csv_url))
                wp_die(__('Aucune URL de fichier CSV spécifiée.'));
        }else{
            $csv_url = esc_url_raw($_POST['csv_url']);
            update_option('wc_csv_import_url', esc_url_raw($csv_url));        
        }

        $csv_file = wp_tempnam($csv_url);
        $response = wp_remote_get($csv_url, array(
            'timeout'  => 30,
            'stream'   => true,
            'filename' => $csv_file
        ));

        if (is_wp_error($response)) {
            return ['error' => 'Error downloading CSV: ' . $response->get_error_message()];
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return ['error' => 'HTTP error: ' . wp_remote_retrieve_response_message($response)];
        }

        $fileContentArray = file($csv_file);
        $separators = array();
        for($i = 0; $i < count($fileContentArray); $i++) {
            $separators[] = ";";
        }

        $csv_data = array_map('str_getcsv', $fileContentArray, $separators);
        $total_rows = count($csv_data) - 1; // Exclude header row
        $header = array_shift($csv_data);
        
        file_put_contents($csv_file, json_encode(['header' => $header, 'rows' => $csv_data]));

        return [
            'file_path' => $csv_file,
            'total_rows' => $total_rows,
        ];
    }


    public function process_csv_import($file_path, $offset, $insert_count, $update_count) {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas la permission d’effectuer cette action.'));
        }

        $file_content = json_decode(file_get_contents($file_path), true);
        $header = $file_content['header'];
        $rows = $file_content['rows'];
        $batch = array_slice($rows, $offset, BATCH_SIZE);

        $handler = new WC_CSV_Product_Handler();
        $result = $handler->import_products($batch, $header);
        $insert_count += $result['insert_count'];
        $update_count += $result['update_count'];
        

        $progress = min($offset + BATCH_SIZE, count($rows));
        if ($progress >= count($rows)) {
            $now = new \DateTime();
            update_option(INSERTION_COUNT_OPTION, $insert_count);
            update_option(UPDATE_COUNT_OPTION, $update_count);
            update_option(LAST_CRON_TIME_OPTION, $now->getTimestamp());
        
            unlink($file_path); // Delete file when done
            return ['completed' => true];
        }

        return [
            'completed' => false,
            'next_offset' => $progress,
            'total_rows' => count($rows),
            'insert_count' => $insert_count,
            'update_count' => $update_count,
        ];
    }

    function wc_csv_save_url() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas la permission d’effectuer cette action.'));
        }
    
        if (!isset($_POST['csv_url']) || empty($_POST['csv_url'])) {
            wp_die(__('Aucune URL de fichier CSV spécifiée.'));
        }
    
        update_option('wc_csv_import_url', esc_url_raw($_POST['csv_url']));
        wp_redirect(admin_url('admin.php?page=wc_csv_importer&url_saved=1'));
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