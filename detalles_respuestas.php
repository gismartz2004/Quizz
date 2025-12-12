<?php
require 'db.php';

// Validamos solo que llegue el ID necesario para la consulta, sin importar quién lo pida
if (!isset($_GET['resultado_id'])) {
    die("Error: ID de resultado no proporcionado.");
}

$id = $_GET['resultado_id'];

// Consulta SQL
$sql = "
    SELECT 
        p.texto AS pregunta,
        p.valor AS puntos_pregunta,
        o.texto AS respuesta_elegida,
        o.es_correcta,
        ru.justificacion
    FROM respuestas_usuarios ru
    JOIN preguntas p ON ru.pregunta_id = p.id
    LEFT JOIN opciones o ON ru.opcion_id = o.id
    WHERE ru.resultado_id = ?
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $respuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error SQL: " . $e->getMessage());
}

if (count($respuestas) === 0) {
    echo "<p>No se encontraron respuestas detalladas para este examen.</p>";
    exit;
}
?>

<div style="font-family: sans-serif;">
    <?php foreach ($respuestas as $i => $resp): ?>
        <div style="border-bottom: 1px solid #eee; padding: 15px 0;">
            <p style="margin:0 0 5px; font-weight:bold; color:#333;">
                <?= ($i + 1) ?>. <?= htmlspecialchars($resp['pregunta']) ?>
                <span style="font-size:0.8em; color:#666;">(<?= $resp['puntos_pregunta'] ?> pts)</span>
            </p>
            
            <p style="margin:5px 0;">
                Respuesta: 
                <?php if ($resp['es_correcta']): ?>
                    <strong style="color: green;">✅ <?= htmlspecialchars($resp['respuesta_elegida']) ?></strong>
                <?php else: ?>
                    <strong style="color: red;">❌ <?= htmlspecialchars($resp['respuesta_elegida'] ?? 'Sin responder') ?></strong>
                <?php endif; ?>
            </p>

            <?php if (!empty($resp['justificacion'])): ?>
                <div style="background:#fffbeb; padding:8px; border-radius:4px; font-size:0.9em; margin-top:5px;">
                    <strong>Justificación:</strong> <?= htmlspecialchars($resp['justificacion']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>