<?php
// migration_fix_image.php
require_once 'db.php';

try {
    echo "Expanding 'imagen' column...\n";
    // Postgres specific syntax
    $pdo->exec("ALTER TABLE preguntas ALTER COLUMN imagen TYPE TEXT");
    echo "Success! Column is now TEXT.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
