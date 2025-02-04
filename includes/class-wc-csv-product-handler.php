 
<?php

class WC_CSV_Product_Handler {
     
    public function upload_image($image_url) {
        // Vérifie si l'URL est valide
        if (empty($image_url)) {
            return;
        }

        // Obtenir le contenu de l'image
        $response = wp_remote_get($image_url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            return; // Gérer l'erreur
        }

        $image_data = wp_remote_retrieve_body($response);

        // Générer un nom de fichier unique
        $filename = basename($image_url);
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['path'] . '/' . $filename;

        // Enregistrer l'image dans le répertoire local
        file_put_contents($file_path, $image_data);

        // Préparer les données pour l'insertion dans la bibliothèque de médias
        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        // Insérer dans la base de données
        $attach_id = wp_insert_attachment($attachment, $file_path);
        
        // Inclure le fichier dans les métadonnées
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id; // Retourner l'ID de l'attachement
    }

    public function update_image($attach_id, $new_image_url) {
        // Supprimer l'ancienne image
        $old_image_path = get_attached_file($attach_id);
        if (file_exists($old_image_path)) {
            unlink($old_image_path);
        }

        // Mettre à jour l'image avec la nouvelle
        wp_delete_attachment($attach_id, true); // Supprime l'attachement

        // Télécharger la nouvelle image
        return $this->upload_image($new_image_url);
    }

    public function create_product($data) {
        // Créer un produit WooCommerce
        $post_data = array(
            'post_title'  => $data['name'],
            'post_content'=> $data['description'],
            'post_status' => 'publish',
            'post_type'   => 'product',
        );

        $post_id = wp_insert_post($post_data);

        // Ajouter les métadonnées du produit
        update_post_meta($post_id, '_regular_price', $data['price']);
        update_post_meta($post_id, '_price', $data['price']);
        update_post_meta($post_id, '_sku', $data['sku']);
        
        
         // Gérer les images
        if (!empty($data['main_image_url'])) {
            $attach_id = $this->upload_image($data['main_image_url']);
            if ($attach_id) {
                set_post_thumbnail($post_id, $attach_id); // Définir l'image à la une
            }
        }

        // Si vous souhaitez gérer la galerie d'images
        if (!empty($data['images_csv'])) {
            $gallery_ids = [];
            foreach (explode('|', $data['images_csv']) as $image_url) {
                $gallery_ids[] = $this->upload_image($image_url);
            }
            update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    public function bulk_import() {
        global $wpdb;
    
        // Supprimer tous les produits
        $products = $wpdb->get_results("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product'");
        foreach ($products as $product) {
            // Supprimer la galerie d'images
            $attachment_ids = get_post_meta($product->ID, '_product_image_gallery', true);
            if ($attachment_ids) {
                $attachments = explode(',', $attachment_ids);
                foreach ($attachments as $attachment_id) {
                    wp_delete_attachment($attachment_id, true); // Supprime l'attachement
                }
            }
            wp_delete_post($product->ID, true); // Supprime le produit
        }
    
        // Réinitialiser l'auto-increment
        $wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 1");
    
        // Supprimer toutes les catégories
        $wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id IN (SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat')");
        $wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat'");
    
        // Lancer l'importation du CSV
        if (isset($_POST['csv_url'])) {
            $csv_url = sanitize_text_field($_POST['csv_url']);
            $this->import_csv($csv_url);
        }
    }
}