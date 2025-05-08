<?php

function get_image_url_from_drive_links_csv($csvFilePath, $outputCsvPath, $wordpressMediaPath, $wordpressMediaUrlBase)
{
    if (!file_exists($csvFilePath)) {
        die("❌ Fichier CSV introuvable.");
    }

    $inputHandle = fopen($csvFilePath, "r");
    $outputHandle = fopen($outputCsvPath, "w");

    $header = fgetcsv($inputHandle);
    $imageColIndex = array_search("images", $header);

    if ($imageColIndex === false) {
        die("❌ Colonne 'images' non trouvée dans le fichier CSV.");
    }

    // Écrire l'en-tête dans le nouveau CSV
    fputcsv($outputHandle, $header);

    while (($data = fgetcsv($inputHandle)) !== false) {
        $driveLinks = explode(",", $data[$imageColIndex]);
        $mediaUrls = [];

        foreach ($driveLinks as $link) {
            $link = trim($link);
            if (!$link) continue;

            $fileName = get_real_filename_from_drive($link);
            if (!$fileName) continue;

            $mediaPath = rtrim($wordpressMediaPath, '/') . '/' . $fileName;
            $mediaUrl = rtrim($wordpressMediaUrlBase, '/') . '/' . $fileName;

            if (file_exists($mediaPath)) {
                $mediaUrls[] = $mediaUrl;
            } else {
                error_log("⚠️ Fichier non trouvé sur le serveur : $fileName");
            }
        }

        // Remplacer la colonne 'images' par les URLs
        $data[$imageColIndex] = implode(",", $mediaUrls);
        fputcsv($outputHandle, $data);
    }

    fclose($inputHandle);
    fclose($outputHandle);
    echo "✅ Nouveau fichier CSV généré : $outputCsvPath\n";
}

function get_real_filename_from_drive($driveLink)
{
    // Extraire l'ID du fichier Google Drive
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $driveLink, $matches)) {
        $fileId = $matches[1];
    } elseif (preg_match('/id=([a-zA-Z0-9_-]+)/', $driveLink, $matches)) {
        $fileId = $matches[1];
    } else {
        error_log("❌ Lien Drive invalide : $driveLink");
        return null;
    }

    $downloadUrl = "https://drive.google.com/uc?export=download&id=$fileId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    curl_close($ch);

    // Extraire le nom du fichier depuis les headers
    if (preg_match('/Content-Disposition:.*filename="([^"]+)"/i', $headers, $matches)) {
        return $matches[1];
    }

    error_log("⚠️ Impossible d'extraire le nom du fichier depuis : $driveLink");
    return null;
}

// === Exemple d'utilisation ===

$csvInput = __DIR__ . "/produits.csv";
$csvOutput = __DIR__ . "/produits_convertis.csv";
$wordpressMediaDir = "/home/bridge/cco-237.shop/wp-content/uploads/2025/05/";
$wordpressMediaUrl = 'https://cco-237.shop/wp-content/uploads/2025/05/';

get_image_url_from_drive_links_csv($csvInput, $csvOutput, $wordpressMediaDir, $wordpressMediaUrl);
