<?php
class WC_CSV_Product_Handler {
    private $batch_size = 5; // Number of products per batch

    public function initialize_import($file_url) {
        $csv_file = wp_tempnam($file_url);
        $response = wp_remote_get($file_url, array(
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

        $csv_data = array_map('str_getcsv', file($csv_file));
        $total_rows = count($csv_data) - 1; // Exclude header row
        $header = array_shift($csv_data);
        file_put_contents($csv_file, json_encode(['header' => $header, 'rows' => $csv_data]));

        return [
            'file_path' => $csv_file,
            'total_rows' => $total_rows,
        ];
    }

    public function process_import_batch($file_path, $offset) {
        $file_content = json_decode(file_get_contents($file_path), true);
        $header = $file_content['header'];
        $rows = $file_content['rows'];
        $batch = array_slice($rows, $offset, $this->batch_size);

        foreach ($batch as $row) {
            $product_data = array_combine($header, $row);
            $sku = $product_data['sku'];
            $last_modification = strtotime($product_data['date_of_last_modification']);
            $current_time = time();

            $product_id = wc_get_product_id_by_sku($sku);
            
            if ($product_id) {
                if (($current_time - $last_modification) <= TIME_TO_CHECK) {
                    $this->update_product($product_id, $product_data);
                }
            } else {
                $this->import_product($product_data);
            }
        }

        $progress = min($offset + $this->batch_size, count($rows));
        if ($progress >= count($rows)) {
            unlink($file_path); // Delete file when done
            return ['completed' => true];
        }

        return [
            'completed' => false,
            'next_offset' => $progress,
            'total_rows' => count($rows),
        ];
    }
}
?>
