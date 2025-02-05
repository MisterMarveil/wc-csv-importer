 
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