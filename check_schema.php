<?php
require 'db.php';
header('Content-Type: text/plain');

try {
    echo "--- Preguntas Table Structure ---\n";
    $stmt = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'preguntas'");
    while ($row = $stmt->fetch()) {
        echo "{$row['column_name']} ({$row['data_type']}) - Nullable: {$row['is_nullable']}\n";
    }

    echo "\n--- Sample Data (including long texts) ---\n";
    $stmt = $pdo->query("SELECT id, LENGTH(texto) as len, requiere_justificacion FROM preguntas ORDER BY len DESC LIMIT 10");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stmtO = $pdo->prepare("SELECT COUNT(*) FROM opciones WHERE pregunta_id = ?");
        $stmtO->execute([$row['id']]);
        $optCount = $stmtO->fetchColumn();
        echo "ID: {$row['id']} | Length: {$row['len']} | Flag: ";
        var_dump($row['requiere_justificacion']);
        echo " | Options: $optCount\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
