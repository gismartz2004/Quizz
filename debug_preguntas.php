<?php
// debug_preguntas.php
require_once 'db.php';

try {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, character_maximum_length 
        FROM information_schema.columns 
        WHERE table_name = 'preguntas' AND column_name = 'imagen'
    ");
    $stmt->execute();
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "COLUMNA IMAGEN:\n";
    print_r($col);

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
