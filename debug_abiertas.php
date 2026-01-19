<?php
// debug_abiertas.php
require_once 'db.php';

try {
    echo "=== DEBUGGEANDO PREGUNTAS ABIERTAS ===\n";
    
    // 1. Find Quiz IDs
    echo "\n1. Buscando Quizzes con 'Preguntas Abiertas'...\n";
    $stmt = $pdo->prepare("SELECT id, titulo FROM quizzes WHERE titulo ILIKE '%Preguntas Abiertas%'");
    $stmt->execute();
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($quizzes)) {
        echo "ALERTA: No se encontraron quizzes con ese nombre.\n";
    }

    foreach ($quizzes as $q) {
        echo "------------------------------------------------\n";
        echo "ID: " . $q['id'] . " | TITULO: " . $q['titulo'] . "\n";
        
        // 2. Count Questions associated to this Quiz
        $stmtP = $pdo->prepare("SELECT id, texto FROM preguntas WHERE quiz_id = ?");
        $stmtP->execute([$q['id']]);
        $preguntas = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        echo "  > Total Preguntas en BD: " . count($preguntas) . "\n";

        if (count($preguntas) > 0) {
            echo "    (Muestreo de IDs: " . $preguntas[0]['id'] . ", " . ($preguntas[1]['id'] ?? '') . ")\n";
            
            // 3. Check Respuestas Usuarios for these questions
            $p_ids = array_column($preguntas, 'id');
            $placeholders = implode(',', array_fill(0, count($p_ids), '?'));
            
            $stmtR = $pdo->prepare("SELECT COUNT(*) FROM respuestas_usuarios WHERE pregunta_id IN ($placeholders)");
            $stmtR->execute($p_ids);
            $totalR = $stmtR->fetchColumn();
            
            echo "  > Total Respuestas en BD (respuestas_usuarios): $totalR\n";
            
            if ($totalR == 0) {
                 echo "  [!!!] ALERTA CRITICA: Hay preguntas pero 0 respuestas registradas.\n";
            } else {
                 // Check if manual grading is affecting anything (though my query assumes simple count)
                 echo "  > Datos encontrados. El filtro HAVING COUNT > 0 deberia funcionar.\n";
            }
        }
    }

} catch (PDOException $e) {
    echo "Error SQL: " . $e->getMessage();
}
?>
