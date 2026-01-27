<?php
require_once 'db.php';

echo "=== DIAGNÓSTICO FINAL ===\n";

try {
    // 1. Verificar columnas
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'respuestas_usuarios'");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas en respuestas_usuarios: " . implode(', ', $cols) . "\n";
    
    $has_manual = in_array('es_correcta_manual', $cols);
    echo "Tiene es_correcta_manual? " . ($has_manual ? "SÍ" : "NO") . "\n";

    // 2. Buscar Quiz ID for "Preguntas Abiertas"
    $stmt = $pdo->prepare("SELECT id, titulo FROM quizzes WHERE titulo ILIKE '%Preguntas abiertas%' LIMIT 5");
    $stmt->execute();
    $quizzes = $stmt->fetchAll();
    
    foreach ($quizzes as $q) {
        $qid = $q['id'];
        echo "\nQuiz: {$q['titulo']} (ID: $qid)\n";
        
        // Contar respuestas
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM respuestas_usuarios ru JOIN preguntas p ON ru.pregunta_id = p.id WHERE p.quiz_id = ?");
        $stmt2->execute([$qid]);
        $count = $stmt2->fetchColumn();
        echo "Total respuestas encontradas: $count\n";
        
        if ($count > 0) {
            // Ver una muestra de respuestas
            $sql = "SELECT ru.id, ru.pregunta_id, ru.opcion_id, " . ($has_manual ? "ru.es_correcta_manual" : "NULL as es_correcta_manual") . " 
                    FROM respuestas_usuarios ru 
                    JOIN preguntas p ON ru.pregunta_id = p.id 
                    WHERE p.quiz_id = ? LIMIT 3";
            $stmt3 = $pdo->prepare($sql);
            $stmt3->execute([$qid]);
            print_r($stmt3->fetchAll());
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
