 
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
        $product->save();
    }
}
