<?php
require 'db.php';

try {
    echo "<h3>Verificando estructura de tablas:</h3>";
    
    // Verificar tabla quizzes
    echo "<h4>Tabla 'quizzes':</h4>";
    $stmt = $pdo->query("DESCRIBE quizzes");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "<br>";
    }
    
    echo "<h4>Tabla 'preguntas':</h4>";
    $stmt = $pdo->query("DESCRIBE preguntas");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "<br>";
    }
    
    echo "<h4>Tabla 'opciones':</h4>";
    $stmt = $pdo->query("DESCRIBE opciones");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "<br>";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>