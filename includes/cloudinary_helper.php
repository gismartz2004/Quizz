<?php
// includes/cloudinary_helper.php

/**
 * Uploads a file to Cloudinary using their REST API via cURL.
 * 
 * @param string $fileTmpPath The temporary path of the uploaded file.
 * @return string|false The secure URL of the uploaded image or false on failure.
 */
function uploadToCloudinary($fileTmpPath) {
    if (!defined('CLOUDINARY_CLOUD_NAME') || !defined('CLOUDINARY_API_KEY') || !defined('CLOUDINARY_API_SECRET')) {
        return false;
    }

    $timestamp = time();
    $params = [
        'timestamp' => $timestamp,
        'folder'    => 'quizzes'
    ];

    // Sort parameters alphabetically (Cloudinary requirement for signing)
    ksort($params);

    // Build signature string
    $signStr = "";
    foreach ($params as $key => $value) {
        $signStr .= "$key=$value&";
    }
    $signStr = rtrim($signStr, '&') . CLOUDINARY_API_SECRET;
    $signature = sha1($signStr);

    $url = "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/image/upload";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $postFields = array_merge($params, [
        'api_key'   => CLOUDINARY_API_KEY,
        'signature' => $signature,
        'file'      => new CURLFile($fileTmpPath)
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    $result = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($info['http_code'] == 200) {
        $json = json_decode($result, true);
        return $json['secure_url'] ?? false;
    }

    // Log error if needed: error_log("Cloudinary Upload Error: " . $result);
    return false;
}
