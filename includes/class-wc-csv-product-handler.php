 
<?php

class WC_CSV_Product_Handler {
    public function import_products($batch, $header) {
        $insertionCount = 0;
        $updateCount = 0;
        $badCount = 0;
        $variation_groups = [];
        $processed_variable_products = [];

         // First pass: Collect variations grouped by item_group_id
        foreach ($batch as $row) {
            $product_data = array_combine($header, $row);
            if (!empty($product_data['variations_info_xml'])) {
                $xml = simplexml_load_string($product_data['variations_info_xml']);
                if ($xml && isset($xml->variant)) {
                    foreach ($xml->variant as $variant) {
                        $group_id = (string) $variant->item_group_id;
                        if (!isset($variation_groups[$group_id])) {
                            $variation_groups[$group_id] = [
                                'common_names' => [],
                                'variations' => []
                            ];
                        }

                        if(isset($variant->common_title) && !empty($variant->common_title)){
                            $variation_groups[$group_id]['common_names'][] = (string) $variant->common_title;
                        }else if (!empty($product_data['name'])) {
                            $variation_groups[$group_id]['common_names'][] = $product_data['name'];
                        }
                        
                        // Enrich the variant with additional fields from product_data and the XML variant object
                        /*$enriched_variant = [
                            'variant' => $variant,
                            'name' => (string) $product_data['name'],
                            'sku' => (string) $product_data['sku'],
                            'description' => (string) $product_data['description'],
                            'html_description' => (string) $product_data['html_description'],
                            'dealer_price' => (string) $product_data['dealer_price'],
                            'price' => (string) $product_data['price'],
                            'vat_percentage' => (string) $product_data['vat_percentage'],
                            'shipping_costs' => (string) $product_data['shipping_costs'],
                            'availability' => (string) $product_data['availability'],
                            'there_is_stock' => (string) $product_data['there_is_stock'],
                            'available_stock' => (string) $product_data['available_stock'],
                            'main_category' => (string) $product_data['main_category'],
                            'brand' => (string) $product_data['brand'],
                            'ean' => (string) $product_data['ean'],
                            'delivery_term' => (string) $product_data['delivery_term'],
                            'main_image_url' => (string) $product_data['main_image_url'],
                            'main_image_url_big' => (string) $product_data['main_image_url_big'],
                            'minimum_units_per_order' => (string) $product_data['minimum_units_per_order'],
                            'maximum_units_per_order' => (string) $product_data['maximum_units_per_order'],
                            'brand_hierarchy' => (string) $product_data['brand_hierarchy'],
                            'weight_info_xml' => (string) $product_data['weight_info_xml'],
                            'dimensions_info_xml' => (string) $product_data['dimensions_info_xml'],
                            'novelty_info_xml' => (string) $product_data['novelty_info_xml'],
                            'barcode_info_xml' => (string) $product_data['barcode_info_xml'],
                            'categories_info_xml' => (string) $product_data['categories_info_xml'],
                            'translations_xml' => (string) $product_data['translations_xml'],
                            'images_csv' => (string) $product_data['images_csv'],
                            'recommended_sale_price' => (string) $product_data['recommended_sale_price'],
                            'hs_intrastat_code' => (string) $product_data['hs_intrastat_code'],
                        ];
                        
                        $variation_groups[$group_id]['variations'][] = $enriched_variant;*/
                    }
                }
            }
        }

        
        foreach ($batch as $row) {  
            //se déclenche si par exemple le caractère csv n'est pas respecté          
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
            if (!empty($product_data['variations_info_xml'])) {
                
                        wp_die();
                $xml = simplexml_load_string($product_data['variations_info_xml']);
                if ($xml && isset($xml->variant)) {
                    foreach ($xml->variant as $variant) {
                        $group_id = (string) $variant->item_group_id;
                        $parentProduct = null;
                        
                        if (!isset($processed_variable_products[$group_id])) {

                            $parentProduct = $this->import_variable_product($group_id, $variation_groups[$group_id]);
                            $processed_variable_products[$group_id] = $parentProduct;
                        }
                        
                        $parent_id = wc_get_product_id_by_sku($group_id);
                        

                        if ($parent_id) {
                            $this->import_variation($parent_id, $product_data);
                        }
                    }
                }
                continue;
            }

            
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
            } else{
                return $this->import_product($product_data);
                $insertionCount++;
            }
        }

        return ["insert_count" => $insertionCount, "update_count" => $updateCount];
    }

     private function find_common_string_in_array($names) {
        if (count($names) === 1) {
            return $names[0];
        }
        $common = array_shift($names);
        foreach ($names as $name) {
            $common = $this->find_common_string($common, $name);
        }
        return trim($common);
    }

    private function find_common_string($str1, $str2) {
        $common = '';
        $len = min(strlen($str1), strlen($str2));
        for ($i = 0; $i < $len; $i++) {
            if ($str1[$i] === $str2[$i]) {
                $common .= $str1[$i];
            } else {
                break;
            }
        }
        return trim($common);
    }

    private function import_variable_product($group_id, $variation_group) {
        $existing_product_id = wc_get_product_id_by_sku($group_id);
        if ($existing_product_id) {
            return wc_get_product($existing_product_id);
        }
        
        $product = new WC_Product_Variable();
        $product->set_name($this->find_common_string_in_array($variation_group['common_names']));
        $product->set_sku($group_id);
        $product->save();
        
        return $product;
    }

    private function import_product($data, $update = false, $product_id = false, $isVariable = false) {
        if($update){
            if (!$product_id) {
                wp_die(__('besoin d\'un product id pour la mise à jour. aucun fourni.'));
            }
    
            $product = wc_get_product($product_id);           
            $this->remove_old_images($product_id);
        }else{
            $product = $isVariable ? new WC_Product_Variable() : new WC_Product_Simple();            
        }
 
        wp_send_json(array("success" => true, "result" => $data));
        wp_die();

        $product->set_name($data['name']);
        $product->set_sku($data['sku']);
        $product->set_short_description($data['description']); // Short description
        $product->set_description($data['html_description']); // Long description
        $product->set_regular_price($data['recommended_sale_price']);
        $product->set_min_purchase_quantity($data['minimum_units_per_order']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($data['available_stock']);
        $product->set_stock_status($data['stock_status']);
        
        // Assign categories
        if (!empty($data['main_category'])) {
            $category_ids = $this->create_and_assign_categories($data['main_category']);
            $product->set_category_ids($category_ids);
        }
        
        // Set images
        if (!empty($data['main_image_url'])) {
            $this->set_product_image($product, $data['main_image_url']);
        }
        if (!empty($data['images_csv'])) {
            $this->set_product_gallery($product, explode('|', $data['images_csv']));
        }

        $product->save();

        // Assign purchase price
        update_post_meta($product->get_id(), '_purchase_price', $data['dealer_price']);
        
        // Assign brand
        if (!empty($data['brand'])) {
            $brand_ids = $this->create_and_assign_brand_with_hierarchy($data['brand'], explode("|", $data["brand_hierarchy"]));

            if(count($brand_ids)){
		        wp_set_object_terms( $product->get_id(), $brand_ids, 'product_brand' );
            }            
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
        
        
            // Assign shipping costs
         if (!empty($data['hs_intrastat_code'])) {
            update_post_meta($product->get_id(), '_hs_intrastat_code', $data['hs_intrastat_code']);
        }
        
        // Assign barcodes
        if (!empty($data['barcode_info_xml'])) {
            update_post_meta($product->get_id(), '_ean_code', $data['barcode_info_xml']);
        }
       
        /*if($isVariable){
            $this->process_variations($product->get_id(), $data['variations_info_xml']);
        }*/
        
        // Multi-language support
        if (!empty($data['translations_xml'])) {
            update_post_meta($product->get_id(), '_translations', $data['translations_xml']);
        }  
        $product->save();      
    }

    private function import_variation($product_id, $product_data) {
        $xml = simplexml_load_string($product_data['variations_info_xml']);
        if (!$xml) {
            return;
        }

        $attributes = [];
        $variations = [];
        foreach ($xml->variant as $variant) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            
            $sku = (string) $variant->item_group_id;
            $variation->set_sku($product_data['sku']);
            $variation->set_name($product_data['name']);
            $variation->set_description($product_data['html_description']);
            $variation->set_short_description($product_data['description']);
            $variation->set_min_purchase_quantity($product_data['minimum_units_per_order']);
            $variation->set_regular_price((string) $product_data['recommended_sale_price']);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity((int) $product_data['available_stock']);
            $variation->set_stock_status((string) $product_data['stock_status']);

            $var_attributes = [];
            for ($i = 1; $i <= 3; $i++) {
                $var_groupname = (string) $variant->{'var_groupname_' . $i};
                $var_name = (string) $variant->{'var_name_' . $i};
                $var_value = (string) $variant->{'var_value_' . $i};
                
                if (!empty($var_groupname) && !empty($var_name) && !empty($var_value)) {
                    $attributes[$var_name] = $var_groupname;
                    $var_attributes[$var_name] = $var_value;
                }
            }
            
            $variation->set_attributes($var_attributes);
            
            // Assign categories
            if (!empty($product_data['main_category'])) {
                $category_ids = $this->create_and_assign_categories($product_data['main_category']);
                $variation->set_category_ids($category_ids);
            }
            
            // Set images
            if (!empty($product_data['main_image_url'])) {
                $this->set_product_image($variation, $product_data['main_image_url']);
            }
            if (!empty($product_data['images_csv'])) {
                $this->set_product_gallery($variation, explode('|', $product_data['images_csv']));
            }

            $variation->save();

            // Assign purchase price
            update_post_meta($variation->get_id(), '_purchase_price', $product_data['dealer_price']);
            
            // Assign brand
            if (!empty($product_data['brand'])) {
                $brand_ids = $this->create_and_assign_brand_with_hierarchy($product_data['brand'], explode("|", $product_data["brand_hierarchy"]));

                if(count($brand_ids)){
                    wp_set_object_terms( $variation->get_id(), $brand_ids, 'product_brand' );
                }            
            }
            
            // Assign EAN code
            if (!empty($product_data['ean'])) {
                update_post_meta($variation->get_id(), '_ean_code', $product_data['ean']);
            }

            // Assign VAT percentage
            if (!empty($product_data['vat_percentage'])) {
                update_post_meta($variation->get_id(), '_vat_percentage', $product_data['vat_percentage']);
            }

            // Assign shipping costs
            if (!empty($product_data['shipping_costs'])) {
                update_post_meta($variation->get_id(), '_shipping_costs', $product_data['shipping_costs']);
            }
            
            
                // Assign shipping costs
            if (!empty($product_data['hs_intrastat_code'])) {
                update_post_meta($variation->get_id(), '_hs_intrastat_code', $product_data['hs_intrastat_code']);
            }
            
            // Assign barcodes
            if (!empty($product_data['barcode_info_xml'])) {
                update_post_meta($variation->get_id(), '_ean_code', $product_data['barcode_info_xml']);
            }

            $variation->save();
            $variations[] = $variation;
        }
        
        // Assign attributes to the parent variable product
        $product = wc_get_product($product_id);
        $product->set_attributes($this->prepare_variation_attributes($attributes));
        $product->save();
    }

    private function prepare_variation_attributes($attributes) {
        $product_attributes = [];
        foreach ($attributes as $name => $groupname) {
            $product_attributes[$name] = [
                'name'         => $groupname,
                'value'        => '',
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 0,
            ];
        }
        return $product_attributes;
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

    /*
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
    }   */

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
    
    /**
     * Function to create a brand (with hierarchy) and return an id array.
     *
     * @param string $brand_name The name of the brand.
     * @param array  $brand_hierarchy (Optional) Array of parent brands in hierarchical order.
     *                                Example: ['Grand Parent Brand', 'parent Brand'].
     */
    private function create_and_assign_brand_with_hierarchy($brand_name, $brand_hierarchy = []) {
        
        // Initialize parent term ID
        $parent_term_id = 0;
    
        // Process parent brands in hierarchy
        foreach ( $brand_hierarchy as $parent_brand_name ) {
            // Check if the parent brand already exists
            $parent_term = get_term_by( 'name', $parent_brand_name, 'product_brand' );
            //$parent_term = get_term_by( 'name', $parent_brand_name, 'product_brand' );
    
            if ( ! $parent_term ) {                
                // Create the parent brand if it doesn't exist
                $parent_term_data = wp_insert_term(
                    $parent_brand_name,
                    'product_brand',
                    [
                        'parent' => $parent_term_id, // Link to the previous parent in the hierarchy
                        "slug" => $this->slugify($parent_brand_name),
                    ]
                );
    
                if ( is_wp_error( $parent_term_data ) ) {
                    $error = "Error creating parent brand '{$parent_brand_name}': " . $parent_term_data->get_error_message();
                    error_log($error);
                    wp_die( $error );
                }
    
                // Get the newly created parent's term ID
                $parent_term_id = $parent_term_data['term_id'];
            } else {
                // If the parent brand exists, use its term ID
                $parent_term_id = $parent_term->term_id;
            }
        }
    
        // Check if the main brand already exists
        $brand_term = get_term_by( 'name', $brand_name, 'product_brand' );
    
        if ( ! $brand_term ) {
            // Create the main brand if it doesn't exist
            $args = [
                "slug" => $this->slugify($brand_name),
                'parent'      => $parent_term_id, // Link to the last parent in the hierarchy
            ];
    
            $brand_term_data = wp_insert_term( $brand_name, 'product_brand', $args );
    
            if ( is_wp_error( $brand_term_data ) ) {
                $error =  "Error creating brand '{$brand_name}': " . $brand_term_data->get_error_message() ;
                error_log($error);
                wp_die($error);
            }
    
            // Get the newly created brand's term ID
            $term_id = $brand_term_data['term_id'];
        } else {
            // If the brand already exists, use its term ID
            $term_id = $brand_term->term_id;
        }
    
       return [intval($term_id)];
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

    private function slugify($text, string $divider = '-')
    {
        // replace non letter or digits by divider
        $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, $divider);

        // remove duplicate divider
        $text = preg_replace('~-+~', $divider, $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }
}
