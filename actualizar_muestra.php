<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$rid = isset($_POST['resultado_id']) ? (int)$_POST['resultado_id'] : 0;
// Normalizar valor a 'true'/'false' para PostgreSQL boolean
$raw = isset($_POST['es_muestra']) ? trim((string)$_POST['es_muestra']) : null;
$es_str = null;
if ($raw !== null) {
    if ($raw === '1' || strtolower($raw) === 'true' || strtolower($raw) === 'on') {
        $es_str = 'true';
    } elseif ($raw === '0' || strtolower($raw) === 'false' || strtolower($raw) === 'off') {
        $es_str = 'false';
    }
}

if ($rid <= 0 || $es_str === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos (resultado_id/es_muestra)']);
    exit;
}

try {
    // Enviar como texto 'true'/'false' que Postgres convierte a boolean
    $stmt = $pdo->prepare("UPDATE resultados SET es_muestra = :es::boolean WHERE id = :id");
    $stmt->execute(['es' => $es_str, 'id' => $rid]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
