<?php
// trigger_skills.php (Enhanced)
require_once 'db.php';
require_once 'includes/analytics_data.php';

try {
    // Search for "Preguntas abiertas de Lengua y Literatura"
    $stmt = $pdo->prepare("SELECT id, titulo FROM quizzes WHERE titulo ILIKE '%Preguntas abiertas%' LIMIT 1");
    $stmt->execute();
    $quiz = $stmt->fetch();

    if (!$quiz) {
        die("Quiz not found\n");
    }

    $id = $quiz['id'];
    echo "Testing Quiz: " . $quiz['titulo'] . " (ID: $id)\n";
    
    // Check questions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM preguntas WHERE quiz_id = ?");
    $stmt->execute([$id]);
    echo "Total Preguntas: " . $stmt->fetchColumn() . "\n";

    // Check attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM respuestas_usuarios ru JOIN preguntas p ON p.id = ru.pregunta_id WHERE p.quiz_id = ?");
    $stmt->execute([$id]);
    echo "Total Intentos (Join): " . $stmt->fetchColumn() . "\n";

    // Run actual analysis function
    $results = analyzeSkillsDiff($pdo, $id);
    echo "AnalyzeSkillsDiff Results: " . count($results) . "\n";
    print_r($results);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
