<?php
// debug_quizzes_schema_v2.php
require_once 'db.php';

try {
    $stmt = $pdo->prepare("
        SELECT column_name, is_nullable, data_type 
        FROM information_schema.columns 
        WHERE table_name = 'quizzes'
    ");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "COLUMNAS DE QUIZZES:\n";
    foreach ($cols as $c) {
        // Output one line per column to avoid truncation in logs
        echo " > " . $c['column_name'] . " (" . $c['data_type'] . ") Nullable? " . $c['is_nullable'] . "\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
