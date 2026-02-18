<?php
require 'db.php';
function printColumns($pdo, $table) {
    echo "<h3>Columns for $table:</h3>";
    try {
        $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '$table' ORDER BY ordinal_position");
        echo "<pre>";
        print_r($stmt->fetchAll());
        echo "</pre>";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}

printColumns($pdo, 'respuestas_usuarios');
printColumns($pdo, 'opciones');
printColumns($pdo, 'preguntas');
?>
