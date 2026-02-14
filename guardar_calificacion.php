<?php
require_once 'includes/session.php';
require 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$rid = isset($_POST['resultado_id']) ? (int)$_POST['resultado_id'] : 0;
$items = $_POST['items'] ?? [];
$obs_general = isset($_POST['observacion_general']) ? trim($_POST['observacion_general']) : null;

if ($rid <= 0 || !is_array($items)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

try {
    // Asegurar columnas requeridas (idempotente)
    try {
        $pdo->exec("ALTER TABLE respuestas_usuarios ADD COLUMN IF NOT EXISTS es_correcta_manual BOOLEAN");
        $pdo->exec("ALTER TABLE respuestas_usuarios ADD COLUMN IF NOT EXISTS observacion_docente TEXT");
        $pdo->exec("ALTER TABLE resultados ADD COLUMN IF NOT EXISTS revisado_manual BOOLEAN DEFAULT false");
        $pdo->exec("ALTER TABLE resultados ADD COLUMN IF NOT EXISTS observacion_docente TEXT");
    } catch (Exception $e) {
        // No interrumpir si el motor no soporta IF NOT EXISTS; se manejará en la transacción
    }

    $pdo->beginTransaction();

    // Actualizar cada respuesta del usuario
    $stmtU = $pdo->prepare("UPDATE respuestas_usuarios SET es_correcta_manual = :ok::boolean, observacion_docente = :obs WHERE id = :ru_id AND resultado_id = :rid");

    foreach ($items as $ru_id => $data) {
        $ru_id = (int)$ru_id;
        if ($ru_id <= 0) continue;
        $ok = isset($data['estado']) ? (int)$data['estado'] === 1 : false;
        $ok_str = $ok ? 'true' : 'false';
        $obs = isset($data['obs']) ? trim($data['obs']) : null;
        $stmtU->execute([
            'ok' => $ok_str,
            'obs' => $obs,
            'ru_id' => $ru_id,
            'rid' => $rid,
        ]);
    }

    // Recalcular puntaje total manual (suma de valores de preguntas marcadas correctas)
    $stmtPts = $pdo->prepare("SELECT COALESCE(SUM(p.valor),0) AS total
                               FROM respuestas_usuarios ru
                               JOIN preguntas p ON ru.pregunta_id = p.id
                               WHERE ru.resultado_id = :rid AND ru.es_correcta_manual = true");
    $stmtPts->execute(['rid' => $rid]);
    $total_manual = (int)($stmtPts->fetchColumn() ?? 0);

    // Obtener puntos totales del quiz para calcular porcentaje
    $stmtQT = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM preguntas WHERE quiz_id = (SELECT quiz_id FROM resultados WHERE id = :rid)");
    $stmtQT->execute(['rid' => $rid]);
    $puntos_totales = (int)($stmtQT->fetchColumn() ?? 0);

    $porcentaje = $puntos_totales > 0 ? round(($total_manual / $puntos_totales) * 100) : 0;

    // Actualizar resultado
    $stmtR = $pdo->prepare("UPDATE resultados SET puntos_obtenidos = :po, puntos_totales_quiz = :pt, porcentaje = :por, revisado_manual = true, observacion_docente = :obs WHERE id = :rid");
    $stmtR->execute([
        'po' => $total_manual,
        'pt' => $puntos_totales,
        'por' => $porcentaje,
        'obs' => $obs_general,
        'rid' => $rid,
    ]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'puntos_obtenidos' => $total_manual, 'puntos_totales' => $puntos_totales, 'porcentaje' => $porcentaje]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
