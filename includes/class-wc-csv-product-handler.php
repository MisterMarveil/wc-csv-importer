 
<?php

class WC_CSV_Product_Handler {
    public function import_products($file_path) {
        $csv_data = array_map('str_getcsv', file($file_path));
        $header = array_shift($csv_data);

        foreach ($csv_data as $row) {
            $product_data = array_combine($header, $row);
            $this->import_product($product_data);
        }
    }

    private function import_product($data) {
        $product_id = wc_get_product_id_by_sku($data['sku']);
        if (!$product_id) {
            $product = new WC_Product_Simple();
        } else {
            $product = wc_get_product($product_id);
        }

        $product->set_name($data['name']);
        $product->set_sku($data['sku']);
        $product->set_description($data['description']);
        $product->set_regular_price($data['price']);

        // Créer et assigner les catégories
        if (!empty($data['main_category'])) {
            $category_ids = $this->create_and_assign_categories($data['main_category']);
            $product->set_category_ids($category_ids);
        }

        // Télécharger et ajouter l'image principale
        if (!empty($data['main_image_url'])) {
            $this->set_product_image($product, $data['main_image_url']);
        }

        // Ajouter la galerie d'images
        if (!empty($data['images_csv'])) {
            $this->set_product_gallery($product, explode('|', $data['images_csv']));
        }

        $product->save();
    }

    private function create_and_assign_categories($category_string) {
        $categories = explode('|', $category_string);
        $parent_id = 0;
        $category_ids = [];

        foreach ($categories as $category_name) {
            $term = term_exists($category_name, 'product_cat', $parent_id);
            if (!$term) {
                $term = wp_insert_term($category_name, 'product_cat', ['parent' => $parent_id]);
            }
            $parent_id = $term['term_id'];
            $category_ids[] = $parent_id;
        }

        return $category_ids;
    }

    private function set_product_image($product, $image_url) {
        $attachment_id = media_sideload_image($image_url, 0, '', 'id');
        if (!is_wp_error($attachment_id)) {
            $product->set_image_id($attachment_id);
        }
    }

    private function set_product_gallery($product, $image_urls) {
        $gallery_ids = [];
        foreach ($image_urls as $image_url) {
            $attachment_id = media_sideload_image($image_url, 0, '', 'id');
            if (!is_wp_error($attachment_id)) {
                $gallery_ids[] = $attachment_id;
            }
        }
        $product->set_gallery_image_ids($gallery_ids);
    }
}