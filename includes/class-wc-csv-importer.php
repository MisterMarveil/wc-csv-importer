 
<?php
// Définition des classes
class WC_CSV_Importer {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_import_page']);
        add_action('wp_ajax_initialize_import', [$this, 'initialize_csv_import']);
        add_action('wp_ajax_process_import_batch', [$this, 'process_csv_import']);
        add_action('admin_post_wc_csv_reset', [$this, 'reset_woocommerce_data']);
        add_action('admin_post_wc_csv_save_url', [$this, 'wc_csv_save_url']);
        add_action( 'admin_enqueue_scripts', [$this, 'wc_importer_scripts']);

    }

    public function wc_importer_scripts() {
        wp_register_style('percircle_style', plugins_url( 'wc-csv-importer/assets/percircle/percircle.css'));
        wp_enqueue_style('percircle_style');
        
        wp_register_script( 'percircle-script', plugins_url( 'wc-csv-importer/assets/percircle/percircle.js'), array ('jquery')  );
        wp_enqueue_script( 'percircle-script' );


        wp_register_script( 'ajax-importer-script', plugins_url( 'wc-csv-importer/assets/js/importer_script.js'), array ('percircle-script')  );
        $values_array = array(
            'offset' => get_option(PRODUCT_OFFSET_OPTION, 0),
            'file' => get_option(PRODUCT_FILE_OPTION, '/var/www/clients/client0/web1/tmp/data.csv')
        );
        wp_localize_script( 'ajax-importer-script', 'CSV', $values_array );
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
        echo '<button id="start-import" class="button button-primary">* Start Import</button>';
        
        // Progress Bar
        echo '<div style="margin-top: 20px; width: 100%; background-color: #ddd; height: 20px; border-radius: 5px;">';
        echo '<div id="import-progress" data-percent="0" style="margin-top: 7%" class="hidden big red">0%</div>';       
        echo '</div>';
        
        // Import Status
        echo '<div id="import-status" style="margin-top: 10px;"></div>';
        echo '</div>';        
        echo '</div>';
    }

    public function initialize_csv_import() { 
        $csv_data = $this->retrieveCsvData();       
        $total_rows = count($csv_data) - 1; // Exclude header row
        
        wp_send_json([
            'total_rows' => $total_rows,            
        ]);
        wp_die;
    }

    private function retrieveCsvData(){
        if (!isset($_POST['csv_url']) || empty($_POST['csv_url'])) {
            $csv_url = get_option('wc_csv_import_url', CSV_URL_DEFAULT_VALUE);
            if(empty($csv_url))
                wp_die(__('Aucune URL de fichier CSV spécifiée.'));
        }else{
            $csv_url = esc_url_raw($_POST['csv_url']);
            update_option('wc_csv_import_url', esc_url_raw($csv_url));        
        }

        $offset = get_option('wc_csv_import_offset', 0);

        if($offset == 0){
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
        }else{
            $csv_file = get_option('wc_csv_import_file', CSV_FILE_DEFAULT_VALUE);        
            if (!is_file($csv_file)) {
                wp_die("Error retrieving csv file while offset was $offset");
            }
        }
    
        $fileContentArray = file($csv_file);
        
        $separators = array();
        for($i = 0; $i < count($fileContentArray); $i++) {
            $separators[] = ";";
        }        
        return  array_map('str_getcsv', $fileContentArray, $separators);
    }

    public function process_csv_import() {        
        $csv_data = $this->retrieveCsvData();
        $header = array_shift($csv_data);
        $offset = get_option('wc_csv_import_offset', 0);
        $batch = array_slice($csv_data, $offset, BATCH_SIZE);
        

        $handler = new WC_CSV_Product_Handler();
        $result = $handler->import_products($batch, $header);
        //wp_send_json(array("success" => true, "result"=> $result));
        //wp_die();
        $rowCount = count($csv_data);
        

        $progress = min($offset + BATCH_SIZE, $rowCount);
        update_option(PRODUCT_OFFSET_OPTION, $progress);

        if ($progress >= $rowCount) {
            $now = new \DateTime();
            update_option(LAST_CRON_TIME_OPTION, $now->getTimestamp());
        
            $csv_file = get_option('wc_csv_import_file', '');        
            if(is_file($csv_file))
                unlink($csv_file); // Delete file when done
            wp_send_json(['completed' => true]);
            wp_die();
        }

        wp_send_json([
            'completed' => false,
            'next_offset' => $progress,
            'total_rows' => $rowCount,
        ]);
        wp_die();
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
        global $wpdb;

        // Get all product IDs
        $product_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')");
        
        // Delete product images
        foreach ($product_ids as $product_id) {
            $thumbnail_id = get_post_meta($product_id, '_thumbnail_id', true);
            if ($thumbnail_id) {
                wp_delete_attachment($thumbnail_id, true);
            }
            
            $gallery_ids = get_post_meta($product_id, '_product_image_gallery', true);
            if (!empty($gallery_ids)) {
                $gallery_ids_array = explode(',', $gallery_ids);
                foreach ($gallery_ids_array as $image_id) {
                    wp_delete_attachment($image_id, true);
                }
            }
        }
        
        // Delete all products
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        
        
        // Delete all brands
        $brand_taxonomy = 'product_brand';
        if (taxonomy_exists($brand_taxonomy)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $brand_taxonomy));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})"));
        }

        $brand_taxonomy = 'pa_brand';
        if (taxonomy_exists($brand_taxonomy)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $brand_taxonomy));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})"));
        }
        
        // Delete all product categories
        $category_taxonomy = 'product_cat';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $category_taxonomy));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})"));
       
               
       
        // Reset Auto Increment
        $wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 1");
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} AUTO_INCREMENT = 1");
        $wpdb->query("ALTER TABLE {$wpdb->term_relationships} AUTO_INCREMENT = 1");
        $wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 1");
        $wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 1");

         update_option(INSERTION_COUNT_OPTION,0);
        update_option(PRODUCT_OFFSET_OPTION,1);
        update_option(UPDATE_COUNT_OPTION,0);
        
        wp_redirect(admin_url('admin.php?page=wc_csv_importer&reset_success=1'));
    }
}