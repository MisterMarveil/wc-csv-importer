 
<?php

class WC_CSV_Product_Handler {
    //private static $instance = null;
    private static $lock_file = '/tmp/csv_import.lock';

    /*
    private function __construct() {}

    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }*/

    private function is_locked() {
        return file_exists(self::$lock_file);
    }

    private function lock() {
        file_put_contents(self::$lock_file, "locked");
    }

    public function unlock() {
        if ($this->is_locked()) {
            unlink(self::$lock_file);
        }
    }

    public function import_products($batch, $header, $offset) {
        /*if ($this->is_locked()) {
            error_log("CSV import is already running. Aborting new import.");
            wp_die("CSV import is already running. Aborting new import.");
        }        
        $this->lock();*/

        $products_by_category = [];     
        $variationSkus = []; 
    
        // Step 1: Group products by category
        foreach ($batch as $row) {//batch has a size of batch_category_size
            $product_data = array_combine($header, $row);
            $category = $product_data['main_category'];
            
            if (!isset($products_by_category[$category])) {
                $products_by_category[$category] = [];
            }

            $products_by_category[$category][] = $product_data;
        }
        
        $insertedGroupIds = array();
        $insertedGroupsOption = get_option(INSERTED_GROUP_ID, '');

        if(!empty($insertedGroupsOption))
            $insertedGroupIds = explode("|", $insertedGroupsOption);

        
        // Step 2: Detect Variations and Prepare Variable Products
        $categoriesCount = count($products_by_category);
        $compteur = 0;
        foreach ($products_by_category as $category => $products) {
            $compteur++;
            $detected_variations = $this->detect_variations($products); 
            $variationGroupsCount = count($detected_variations);
            $varCount = 0;
        
            //return array("_good_" => true, "detected" => $detected_variations);
            foreach ($detected_variations as $group_id => $data) {    
                $varCount++;

                if(!in_array($group_id, $insertedGroupIds)){                                                    
                    $sku_list = array_column($data['variations'], 'sku');                
                    $variable_sku = implode('.', $sku_list);
                    $variationSkus = array_merge($variationSkus, $sku_list);
                    
                    // Check if a variable product already exists using SKU LIKE query
                    $existing_product_id = $this->find_existing_variable_product($sku_list);
                    
                    if ($existing_product_id) {
                        // Fetch existing concatenated SKU
                        $existing_sku = get_post_meta($existing_product_id, '_sku', true);
                        
                        if ($existing_sku !== $variable_sku) {
                            // SKU changed -> update the variable product
                            update_post_meta($existing_product_id, '_sku', $variable_sku);                        
                        }
                    }

                    // Create new variable product                    
                    return $this->import_variable_product($variable_sku, [
                        'name' => $data["common_name"],                        
                        'variations' => $data['variations']
                    ]);

                    $insertedGroupIds[] = $group_id;
                    update_option(INSERTED_GROUP_ID, implode("|", $insertedGroupIds));
                    return array(
                        'is_variation' => true,
                        'categories_count' => $categoriesCount,
                        'current' => $compteur,
                        'current_category_var_groups_count' => $variationGroupsCount,
                        'current_category_var_group' => $varCount,
                       'latest_introduced_group_id' => $group_id
                    );
                }
            }
            
        }
        

       $csv_data = array_slice($batch, $offset, BATCH_SIZE);
       return array("good" => 1, "data" => $csv_data);
      
       // Step 3: Import Products
       foreach ($csv_data as $row) {
           $product_data = array_combine($header, $row);
           $sku = $product_data['sku'];

           // Prevent duplicate insertions
           if ($this->product_exists($sku) || in_array($sku, $variationSkus)) {
                continue;
            }

           // Vérifier si le produit a été modifié récemment
            $last_modification = strtotime($product_data['date_of_last_modification']);
            $current_time = time();
            
            // Vérifier si le produit existe déjà
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                // Vérifier si la modification est récente
                if (($current_time - $last_modification) <= TIME_TO_CHECK) {
                    $this->import_product($product_data, true, $product_id);   
                }
            } else{
                $this->import_product($product_data);
            }
        }
        
        $this->unlock();
    }

    private function remove_existing_variations($product_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent = %d", $product_id));

        return wc_get_product($product_id);           
    }

    private function product_exists($sku) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s", $sku));
    }
     
    public function detect_variations($csv_data) {
        $variation_groups = [];

        foreach ($csv_data as $product_data) {
            $sku = $product_data['sku'];
            $name = $product_data['name'];
            $item_group_id = null;

            // Check if variations_info_xml is provided and not empty
            if (!empty($product_data['variations_info_xml'])) {
                $xml = simplexml_load_string($product_data['variations_info_xml']);
                if ($xml && isset($xml->variant->item_group_id)) {
                    $item_group_id = (string) $xml->variant->item_group_id;
                }
            }

            if ($item_group_id) {
                // If item_group_id exists, group by it directly
                if (!isset($variation_groups[$item_group_id])) {
                    $variation_groups[$item_group_id] = [
                        'common_name' => isset($xml->variant->common_title) ? (string)$xml->variant->common_title : $name,
                        'variations' => []
                    ];
                }
                $variation_groups[$item_group_id]['variations'][] = $product_data;
            } else {
                // Try to infer variations by comparing product names
                $matched_group = null;
                foreach ($variation_groups as $group_id => $group_data) {
                    $common_part = $this->extract_common_name($group_data['common_name'], $name);                    
                    
                    // Ensure that the common part is significant (at least 60% of the shortest name)
                    $min_length = min(strlen($group_data['common_name']), strlen($name));
                    if ($common_part && strlen($common_part) >= 0.6 * $min_length) {
                        $variation_groups[$group_id]['common_name'] = $common_part;

                        $matched_group = $group_id;
                        $variation_groups[$group_id]['values'][] = str_replace($common_part, '', $name);
                        break;
                    }
                }
                
                if ($matched_group) {
                    $variation_groups[$matched_group]['variations'][] = $product_data;
                } else {
                    $new_group_id = 'auto_' . md5($name);
                    $variation_groups[$new_group_id] = [
                        'common_name' => $name,
                        'variations' => [$product_data]                        
                    ];                   
                }
            }
        }
      
        foreach($variation_groups as $groupId => $group){
            if(count($group['variations']) <= 1){
                unset($variation_groups[$groupId]);
            }  
        }
        
        return $variation_groups;
    }

    private function extract_common_name($name1, $name2) {
        $length1 = strlen($name1);
        $length2 = strlen($name2);
        $max_length = min($length1, $length2);
        
        $common_part = '';
        for ($i = 0; $i < $max_length; $i++) {
            if ($name1[$i] === $name2[$i]) {
                $common_part .= $name1[$i];
            } else {
                break;
            }
        }
        
        return trim($common_part);
    }

    private function import_variable_product($sku, $variable_data) {
        
        $existing_product_id = wc_get_product_id_by_sku($sku);
        if (!$existing_product_id) {            
            $product = new WC_Product_Variable();
            //$before = $variable_data['name'];
            $extract = $this->extract_attribute_from_common_name($variable_data['name']);
            //$after = $variable_data['name'];
            //return ["before" => $before, "extract" => $extract, "after" => $after];

            $product->set_name($extract !== false ? $extract['common_name'] : $variable_data['name']);
            $product->set_sku($sku);
            
            // Assign main image from first variation
            if (!empty($variable_data['variations'][0]['main_image_url'])) {
                $this->set_product_image($product, $variable_data['variations'][0]['main_image_url']);
            }
            
            // Collect up to 2 gallery images from each variation
            $gallery_images = [];
            $isbrandSetted = false;
            $brand_ids = [];

            foreach ($variable_data['variations'] as $variation) {
                if (!empty($variation['images_csv'])) {
                    $images = explode('|', $variation['images_csv']);
                    $gallery_images = array_merge($gallery_images, array_slice($images, 0, 2));
                }

                // Assign brand
                if (!$isbrandSetted && !empty($variation['brand'])) {
                    $isbrandSetted = true;
                    return array("good" => 3, "data" => $variation);
        
                    $brand_ids = $this->create_and_assign_brand_with_hierarchy($variation['brand'], explode("|", $variation["brand_hierarchy"]));
                }        
            }

            if (!empty($gallery_images)) {
                $this->set_product_gallery($product, $gallery_images);
            }

            $product->save();
            $existing_product_id = $product->get_id();

            if(!empty($brand_ids)){
                wp_set_object_terms( $product->get_id(), $brand_ids, 'product_brand' );   
            }
        }else{
            $product = wc_get_product($existing_product_id);
        }
        //else{
            // Remove existing variations
           //$product = $this->remove_existing_variations($existing_product_id);

       // }
       
        $attributes_variations_data = $this->extract_attributes($product, $variable_data['variations']);
        //return array("att_var_data" => $attributes_variations_data);

        $this->product_save_attributes($existing_product_id, $attributes_variations_data['attributes']);
        return $this->product_save_variations($existing_product_id, $attributes_variations_data['variations']);
        //return $this->create_product_attributes_and_variations($existing_product_id, $attributes_variations_data['attributes'], $attributes_variations_data['variations']);
    }

    private function extract_attributes($product, $variation_products){
        $attributes = ["nuances" => array()];
        $variations = [];
        foreach ($variation_products as $variation_data) {
            $suffixe_part = str_replace($product->get_name(), '', $variation_data['name']);
           
            $extracted = $this->extract_attribute_from_common_name($suffixe_part);
            $variation_attributes = [];

            if($extracted !== false && !empty($extracted['attribute']) && !empty($extracted['common_name'])){
               $attr = $extracted['attribute']; 
               if(!array_key_exists($attr, $attributes)){
                 $attributes[$attr] = array($extracted['common_name']);
                 $variation_attributes[$attr] = $extracted['common_name'];
               }else{
                 if(!in_array($extracted['common_name'], $attributes[$attr])){
                    $attributes[$attr][] = $extracted['common_name'];
                    $variation_attributes[$attr] = $extracted['common_name'];
                 }
               }
            }else{
                if(!in_array($suffixe_part, $attributes['nuances'])){
                    $attributes['nuances'][] = $suffixe_part;
                    $variation_attributes['nuances'] = $suffixe_part;
                }
            }

            $variations[] = array(
            'attributes' => $variation_attributes,
            'data' => $variation_data
            );
           
        }

        $attr_results = [];
        $compteur = 0;
    

        foreach($attributes as $key => $values){
            $attr_results[] = array(
                'name' => $key,
                'label' => $key,
                'values' => implode('|', $values),
                'position' => $compteur,
                'visible' => 1,
                'variation' => 1
            );
            $compteur++;
        }

        return ["attributes" => $attr_results, "variations" => $variations];

    }

    private function extract_attribute_from_common_name($common_name) {
        $attribute_keywords = ['taille', 'couleur', 'format', 'poids', 'Talla']; // Extend as needed
        
        foreach ($attribute_keywords as $keyword) {
            if (stripos($common_name, $keyword) !== false) {
                $cleaned_name = wc_clean(trim(str_ireplace($keyword, '', $common_name)));
                return [
                    'common_name' => $cleaned_name,
                    'attribute' => ucfirst($keyword)
                ];
            }
        }
        
        return false;
    }

    private function find_existing_variable_product($sku_list) {
        global $wpdb;
        $like_query = implode("%' OR meta_value LIKE '%", $sku_list);
        return $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND (meta_value LIKE '%$like_query%') LIMIT 1");
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
       
        // Multi-language support
        if (!empty($data['translations_xml'])) {
            update_post_meta($product->get_id(), '_translations', $data['translations_xml']);
        }
        
        // Set minimum order quantity
        if (!empty($data['minimum_units_per_order'])) {
            update_post_meta($product->get_id(), '_min_units_per_order', (int)$data['minimum_units_per_order']);
        }
        
        $product->save();      
    }

    private function enrich_variation($variation, $product_data) {
        if(isset(($product_data['sku'])))
            $variation->set_sku($product_data['sku']);

        if(isset(($product_data['name'])))
            $variation->set_name($product_data['name']);

        if(isset(($product_data['html_description'])))
            $variation->set_description($product_data['html_description']);

        if(isset(($product_data['description'])))
            $variation->set_short_description($product_data['description']);

        if(isset(($product_data['recommended_sale_price'])))
            $variation->set_regular_price((string) $product_data['recommended_sale_price']);

            
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
        $variation_id = $variation->get_id();

        $product_data['available_stock'] = !empty($product_data['available_stock']) ? $product_data['available_stock'] : 0;
        update_post_meta($variation_id, '_stock', wc_clean($product_data['available_stock']));
        update_post_meta($variation_id, '_stock_status', (intval($product_data['available_stock']) > 0) ? 'instock' : 'outofstock');
        update_post_meta($variation_id, '_manage_stock', 'yes');
        
        // Assign purchase price
        update_post_meta($variation_id, '_purchase_price', $product_data['dealer_price']);
        
        // Assign brand
        if (!empty($product_data['brand'])) {
            $brand_ids = $this->create_and_assign_brand_with_hierarchy($product_data['brand'], explode("|", $product_data["brand_hierarchy"]));

            if(count($brand_ids)){
                wp_set_object_terms( $variation_id, $brand_ids, 'product_brand' );
            }            
        }
        
        // Assign EAN code
        if (!empty($product_data['ean'])) {
            update_post_meta($variation_id, '_ean_code', $product_data['ean']);
        }

        // Assign VAT percentage
        if (!empty($product_data['vat_percentage'])) {
            update_post_meta($variation_id, '_vat_percentage', $product_data['vat_percentage']);
        }

        // Assign shipping costs
        if (!empty($product_data['shipping_costs'])) {
            update_post_meta($variation_id, '_shipping_costs', $product_data['shipping_costs']);
        }
        
        
            // Assign shipping costs
        if (!empty($product_data['hs_intrastat_code'])) {
            update_post_meta($variation_id, '_hs_intrastat_code', $product_data['hs_intrastat_code']);
        }
        
        // Assign barcodes
        if (!empty($product_data['barcode_info_xml'])) {
            update_post_meta($variation_id, '_ean_code', $product_data['barcode_info_xml']);
        }

        // Set minimum order quantity
        if (!empty($product_data['minimum_units_per_order'])) {
            update_post_meta($variation_id, '_min_units_per_order', (int)$product_data['minimum_units_per_order']);
        }

        return $variation_id;
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

    private function product_save_attributes($post_id, $attributes_data) {
        // Get the product
        $product = wc_get_product($post_id);
        
        if (!$product || $product->get_type() !== 'variable') {
            wp_die('Invalid product or not a variable product: '.$post_id);
        }
       
        $attributes = array();
        foreach ($attributes_data as $attribute) {
            $attribute_name = sanitize_title($attribute['name']); // e.g. 'color'
            $attribute_label = wc_clean($attribute['label']); // e.g. 'Color'
            $attribute_position = isset($attribute['position']) ? absint($attribute['position']) : 0; // e.g
            $attribute_values = explode('|', $attribute['values']); // e.g. 'Red|Blue|Green'
            
            // Trim values
            $attribute_values = array_map('wc_clean', $attribute_values);
            $attribute_id = 0;
                
            // Vérification si l'attribut est taxonomique
            if (substr($attribute_name, 0, 3) === 'pa_') {
                $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);
            }
                
            // Création de l'objet attribut
            $attribute = new WC_Product_Attribute();
            $attribute->set_id($attribute_id);
            $attribute->set_name($attribute_name);
            $attribute->set_position($attribute_position);
            $attribute->set_visible(1);
            $attribute->set_variation(1);
                
            // Traitement des valeurs d'attribut
            if ($attribute_id) {
                // Pour les attributs taxonomiques
                $options = array();
                
                foreach ($attribute_values as $value) {
                    if ($term = get_term_by('name', $value, $attribute_name)) {
                        $options[] = $term->term_id;
                    } else {
                        $term = wp_insert_term($value, $attribute_name);
                        $options[] = $term['term_id'];
                    }
                }                
                $attribute->set_options($options);
            } else {
                // Pour les attributs personnalisés
                $attribute->set_options($attribute_values);
            }
            
            $attributes[] = $attribute;
            
        }
        
        $product->set_attributes($attributes);
        $product->save();
        //wp_die();
    } 
    
    /**
     * Save product variations via ajax.
     */
    public function product_save_variations($product_id, $variations_data) {        
        // Traitement des données de variation
        $product = wc_get_product($product_id);
        
        if (!$product || $product->get_type() !== 'variable') {
            wp_die('Invalid product or not a variable product: '.$product_id);
        }
        
        // Traitement des variations
        // Cette partie traiterait des tableaux comme variable_post_id, variable_sku, etc.
        
        // Préparation des variations pour enregistrement
        $variations = array();
         $product_already_variations = $product->get_children();
        foreach ($variations_data as $variation_data){
            $variation = null;
            $data = $variation_data['data'];
            foreach($product_already_variations as $product_variation){                
                if(!empty($data['sku']) && $data['sku'] == $product_variation->get_sku()){
                    $variation = $product_variation;
                    break;
                }
            }

            if(!$variation){
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product_id);
                $variation->set_status('publish');
            }
            
            $variation_attributes = array();
            $attributes = $product->get_attributes();
            $var_attributes = $variation_data['attributes'];
            
            foreach ($attributes as $attribute) {
                $attribute_key = sanitize_title($attribute->get_name());                
                foreach($var_attributes as $v_attr_key => $v_attr_value){
                    if($v_attr_key == $attribute_key){
                        $variation_attributes[$attribute_key] = $v_attr_value;
                    }
                }              
            }
            $variation->set_attributes($variation_attributes);
            $this->enrich_variation($variation, $data);
            $variations[] = $variation;
        }
        
        // Mise à jour du produit parent
        WC_Product_Variable::sync($product_id);
        
        return $variations;
    }
}