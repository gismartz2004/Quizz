<?php
// debug_quizzes_schema.php
require_once 'db.php';

try {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, character_maximum_length 
        FROM information_schema.columns 
        WHERE table_name = 'quizzes'
    ");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "COLUMNAS QUIZZES:\n";
    foreach ($cols as $c) {
        echo $c['column_name'] . " | " . $c['data_type'] . " | NULL: " . $c['is_nullable'] . "\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
