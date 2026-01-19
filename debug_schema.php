<?php
// debug_schema.php (v2)
require_once 'db.php';

try {
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns 
        WHERE table_name = 'respuestas_usuarios'
    ");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "COLUMNS:\n";
    foreach ($cols as $c) {
        echo "- $c\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
