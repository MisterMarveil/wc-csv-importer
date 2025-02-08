 
<?php

class WC_CSV_Product_Handler {
    public function import_products($batch, $header) {
       
        $insertionCount = 0;
        $updateCount = 0;
        $badCount = 0;
        foreach ($batch as $row) {            
            if(count($header) != count($row)){
                var_dump($header);
                echo nl2br("\n--------------------------------\n");
                var_dump($row);
                $badCount++;
                if($badCount == 2){
                    wp_die("bad count: " . $badCount);
                }
                continue;
            }

            $product_data = array_combine($header, $row);
            
            $sku = $product_data['sku'];
        
            $last_modification = strtotime($product_data['date_of_last_modification']);
            $current_time = time();
            
            // Vérifier si le produit existe déjà
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                // Vérifier si la modification est récente
                if (($current_time - $last_modification) <= TIME_TO_CHECK) {
                   return $this->import_product($product_data, true, $product_id);   
                    $updateCount++;                 
                }
            } else {
                return $this->import_product($product_data);
                $insertionCount++;
            }
        }

        return ["insert_count" => $insertionCount, "update_count" => $updateCount];
    }

    private function import_product($data, $update = false, $product_id = false) {
        if($update){
            if (!$product_id) {
                wp_die(__('besoin d\'un product id pour la mise à jour. aucun fourni.'));
            }
    
            $product = wc_get_product($product_id);           
            $this->remove_old_images($product_id);
        }else{
            $product = new WC_Product_Simple();            
        }
 
        $product->set_name($data['name']);
        $product->set_sku($data['sku']);
        $product->set_short_description($data['description']); // Short description
        $product->set_description($data['html_description']); // Long description
        $product->set_regular_price($data['price']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($data['available_stock']);
        $product->set_stock_status($data['stock_status']);
         return ["product" => $product];
       
        
        // Assign purchase price
        update_post_meta($product->get_id(), '_purchase_price', $data['dealer_price']);
        
        // Assign categories
        if (!empty($data['main_category'])) {
            $category_ids = $this->create_and_assign_categories($data['main_category']);
            $product->set_category_ids($category_ids);
        }
        
        // Assign brand
        if (!empty($data['brand'])) {
            $this->set_product_brand($product, $data['brand']);
        }
        
        // Assign barcodes
        if (!empty($data['barcode_info_xml'])) {
            update_post_meta($product->get_id(), '_ean_code', $data['barcode_info_xml']);
        }
        
        // Map shipping & customs information
        update_post_meta($product->get_id(), '_hs_intrastat_code', $data['hs_intrastat_code']);
        update_post_meta($product->get_id(), '_shipping_costs', $data['shipping_costs']);
        
        // Integrate variations
        if (!empty($data['variations_info_xml'])) {
            $this->process_variations($product->get_id(), $data['variations_info_xml']);
        }
        
        // Multi-language support
        if (!empty($data['translations_xml'])) {
            update_post_meta($product->get_id(), '_translations', $data['translations_xml']);
        }
        return $product;

        // Set images
        if (!empty($data['main_image_url'])) {
            $this->set_product_image($product, $data['main_image_url']);
        }
        if (!empty($data['images_csv'])) {
            $this->set_product_gallery($product, explode('|', $data['images_csv']));
        }

        $product->save();
    }

    private function remove_old_images($product_id) {
        // Get the main image ID
        $main_image_id = get_post_thumbnail_id($product_id);
        
        // Remove and delete the main image if it exists
        if ($main_image_id) {
            wp_delete_attachment($main_image_id, true);
            delete_post_meta($product_id, '_thumbnail_id');
        }
    
        // Get gallery image IDs
        $gallery_image_ids = get_post_meta($product_id, '_product_image_gallery', true);
        
        if (!empty($gallery_image_ids)) {
            $gallery_image_ids_array = explode(',', $gallery_image_ids);
    
            foreach ($gallery_image_ids_array as $image_id) {
                wp_delete_attachment($image_id, true);
            }
    
            delete_post_meta($product_id, '_product_image_gallery');
        }
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

    private function set_product_brand($product, $brand_name) {
        $attribute_name = 'pa_brand';

        if (!taxonomy_exists($attribute_name)) {
            wp_insert_term($brand_name, $attribute_name);
        }

        wp_set_object_terms($product->get_id(), $brand_name, $attribute_name);
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
