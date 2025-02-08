 
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
            return $product_data;
            // Vérifier si le produit existe déjà
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                // Vérifier si la modification est récente
                if (($current_time - $last_modification) <= TIME_TO_CHECK) {
                    $this->import_product($product_data, true, $product_id);   
                    $updateCount++;                 
                }
            } else {
                $this->import_product($product_data);
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
        $product->set_regular_price($data['recommended_sale_price']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($data['available_stock']);
        $product->set_stock_status($data['stock_status']);
         
        
        // Assign purchase price
        update_post_meta($product->get_id(), '_purchase_price', $data['dealer_price']);
        
        // Assign categories
        if (!empty($data['main_category'])) {
            $category_ids = $this->create_and_assign_categories($data['main_category']);
            $product->set_category_ids($category_ids);
        }
        
        // Assign brand
        if (!empty($data['brand'])) {
            $this->set_product_brand($product->get_id(), $data['brand']);
        }

         // Assign EAN code
         if (!empty($data['ean'])) {
            update_post_meta($product->get_id(), '_ean_code', $data['ean']);
        }

        // Assign VAT percentage
        if (!empty($data['vat_percentage'])) {
            update_post_meta($product->get_id(), '_vat_percentage', $data['vat_percentage']);
        }

         // Assign shipping costs
         if (!empty($data['shipping_costs'])) {
            update_post_meta($product->get_id(), '_shipping_costs', $data['shipping_costs']);
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

    private function process_variations($product_id, $variations_xml) {
        $xml = simplexml_load_string($variations_xml);
        if (!$xml) {
            return;
        }

        foreach ($xml->variant as $variant) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            
            $sku = (string) $variant->item_group_id;
            $variation->set_sku($sku);
            
            $attributes = [];
            for ($i = 1; $i <= 2; $i++) {
                $groupname = (string) $variant->{'var_groupname_' . $i};
                $var_name = (string) $variant->{'var_name_' . $i};
                $var_value = (string) $variant->{'var_value_' . $i};
                
                if (!empty($var_name) && !empty($var_value)) {
                    $attributes[$var_name] = $var_value;
                }
            }
            
            $variation->set_attributes($attributes);
            $variation->save();
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

    private function set_product_brand($product_id, $brand_name) {
        $attribute_name = 'product_brand';
        
        if (!taxonomy_exists($attribute_name)) {
            register_taxonomy($attribute_name, 'product', [
                'label' => __('Brand', 'woocommerce'),
                'rewrite' => false,
                'hierarchical' => false,
            ]);        
        }
        
         // Check if the brand exists, if not create it
         $brand_term = term_exists($brand_name, $attribute_name);
         if (!$brand_term) {
             $brand_term = wp_insert_term($brand_name, $attribute_name);
         }
         
         // Ensure brand is linked to the product
         if (!is_wp_error($brand_term)) {
             wp_set_object_terms($product->get_id(), (int) $brand_term['term_id'], $attribute_name);
         }
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
