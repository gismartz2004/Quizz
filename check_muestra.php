<?php
require 'db.php';
try {
    $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'resultados' AND column_name LIKE '%muestra%'");
    $columns = $stmt->fetchAll();
    if (empty($columns)) {
        echo "No columns found with 'muestra' in name for table 'resultados'. Checking all columns:";
        $stmt2 = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'resultados' LIMIT 50");
        print_r($stmt2->fetchAll(PDO::FETCH_COLUMN));
    } else {
        print_r($columns);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
