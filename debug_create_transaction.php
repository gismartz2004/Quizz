<?php
// debug_create_transaction.php
require 'db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<h1>Debug Creation Process</h1>";

try {
    // 1. Insert Quiz
    echo "Attempting to insert quiz...<br>";
    $stmt = $pdo->prepare(
        "INSERT INTO quizzes (titulo, descripcion, color_primario, color_secundario, valor_total, fecha_inicio, fecha_fin, duracion_minutos, creado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        'Test Quiz ' . time(),
        'Debug Description',
        '#000000',
        '#ffffff',
        100,
        '2026-01-01',
        '2026-12-31',
        60,
        1 // Default User ID
    ]);
    $quiz_id = $pdo->lastInsertId();
    echo "Quiz inserted with ID: $quiz_id<br>";

    // 2. Insert Question
    echo "Attempting to insert question...<br>";
    $stmtPregunta = $pdo->prepare("INSERT INTO preguntas (quiz_id, texto, valor) VALUES (?, ?, ?)");
    $stmtPregunta->execute([$quiz_id, 'Test Question', 10]);
    $pregunta_id = $pdo->lastInsertId();
    echo "Question inserted with ID: $pregunta_id<br>";
    
    // 3. Update Image
    echo "Attempting to update image...<br>";
    $longUrl = "https://res.cloudinary.com/demo/image/upload/v1/samples/cld-sample-long-url-testing-character-limit-to-ensure-database-column-is-large-enough-for-real-world-scenarios-and-transformations.jpg";
    $pdo->prepare("UPDATE preguntas SET imagen = ? WHERE id = ?")->execute([$longUrl, $pregunta_id]);
    echo "Image updated.<br>";

    // 4. Insert Option
    echo "Attempting to insert option...<br>";
    $stmtRespuesta = $pdo->prepare("INSERT INTO opciones (pregunta_id, texto, es_correcta) VALUES (?, ?, ?)");
    $stmtRespuesta->execute([$pregunta_id, 'Option A', 1]);
    echo "Option inserted.<br>";

    echo "<h2>SUCCESS: All steps completed without error.</h2>";

} catch (PDOException $e) {
    echo "<h2 style='color:red'>FAILURE: " . $e->getMessage() . "</h2>";
}
?>
