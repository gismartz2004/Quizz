<?php
require 'db.php';
require 'config.php';
require 'vendor/autoload.php';

echo "--- DIAGNÓSTICO DE EXTRACCIÓN CON GEMINI ---\n";

function debugGemini($text, $apiKey) {
    $modelo = "models/gemini-2.0-flash";
    $url = "https://generativelanguage.googleapis.com/v1beta/$modelo:generateContent?key=$apiKey";

    $data = [
        "contents" => [["parts" => [["text" => "Extrae preguntas de este texto en formato JSON: $text"]]]],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "response_schema" => [
                "type" => "object",
                "properties" => [
                    "preguntas" => [
                        "type" => "array",
                        "items" => [
                            "type" => "object",
                            "properties" => [
                                "texto" => ["type" => "string"],
                                "valor" => ["type" => "integer"],
                                "respuestas" => [
                                    "type" => "array",
                                    "items" => [
                                        "type" => "object",
                                        "properties" => [
                                            "texto" => ["type" => "string"],
                                            "correcta" => ["type" => "boolean"]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    echo "HTTP Status: " . $info['http_code'] . "\n";
    if ($info['http_code'] !== 200) {
        echo "Error Response: " . $response . "\n";
        return;
    }

    $json = json_decode($response, true);
    print_r($json);
}

$sampleText = "1. ¿Cuál es el color del cielo? a) Rojo b) *Azul c) Verde";
debugGemini($sampleText, GEMINI_API_KEY);
