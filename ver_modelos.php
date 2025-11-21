<?php
// ==========================================
// SCRIPT PARA LISTAR MODELOS DISPONIBLES
// ==========================================
$apiKey = 'AIzaSyBByPvjVmXms9PRF39cMGeiz1-3gFaIdes'; // Tu API Key actual

// Endpoint para obtener la lista de modelos
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Descomenta la siguiente línea si estás en XAMPP y te da error de SSL
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    die('<h3>Error de conexión:</h3> ' . curl_error($ch));
}
curl_close($ch);

$json = json_decode($response, true);

echo "<div style='font-family: sans-serif; padding: 20px;'>";
echo "<h1>Modelos Disponibles para tu API Key</h1>";

if (isset($json['error'])) {
    echo "<h3 style='color: red;'>Error de la API:</h3>";
    echo $json['error']['message'];
} elseif (isset($json['models'])) {
    echo "<p>Estos son los modelos que puedes usar para generar texto. Copia uno de los nombres en negrita:</p>";
    echo "<ul>";
    $encontrado = false;
    foreach ($json['models'] as $model) {
        // Filtramos solo los modelos que sirven para generar contenido
        if (isset($model['supportedGenerationMethods']) && in_array('generateContent', $model['supportedGenerationMethods'])) {
            echo "<li style='margin-bottom: 10px; font-size: 1.1em;'>";
            echo "Nombre a usar: <strong>" . $model['name'] . "</strong><br>";
            echo "<small style='color: #666;'>" . $model['description'] . "</small>";
            echo "</li>";
            $encontrado = true;
        }
    }
    echo "</ul>";
    if (!$encontrado) {
        echo "<p>No se encontraron modelos que soporten 'generateContent'.</p>";
    }
} else {
    echo "<pre>" . print_r($json, true) . "</pre>";
}
echo "</div>";
?>