<?php
require_once 'includes/session.php';
require 'db.php';
require_once 'includes/analytics_data.php';

// Seguridad básica
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    http_response_code(403);
    exit('Acceso denegado');
}

$quiz_id   = isset($_GET['quiz_id']) && is_numeric($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : null;
$threshold = isset($_GET['threshold']) ? (float)$_GET['threshold'] / 100 : 1.0;

$filters = [
    'genero' => $_GET['genero'] ?? '',
    'paralelo' => $_GET['paralelo'] ?? '',
    'jornada' => $_GET['jornada'] ?? '',
    'muestra' => $_GET['muestra'] ?? ''
];

if (!$quiz_id) {
    echo '<div class="text-center py-4 text-muted">Seleccione un examen para analizar destrezas.</div>';
    exit;
}

$skillsStats = analyzeSkillsDiff($pdo, $quiz_id, $threshold, $filters);

if (empty($skillsStats)) {
    echo '<div class="text-center py-4 text-muted">No hay preguntas que coincidan con el nivel de dificultad seleccionado (<= ' . ($threshold * 100) . '%).</div>';
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th>Pregunta</th>
                <th class="text-center">Acierto (%)</th>
                <th class="text-center">Correctas</th>
                <th class="text-center">Incorrectas</th>
                <th>Estudiantes con dificultad</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($skillsStats as $s): ?>
                <tr>
                    <td class="small w-50"><?= htmlspecialchars($s['texto']) ?></td>
                    <td class="text-center fw-bold text-<?= $s['success_rate'] < 0.5 ? 'danger' : 'warning' ?>">
                        <?= round($s['success_rate'] * 100, 1) ?>%
                    </td>
                    <td class="text-center text-success"><?= $s['correctas'] ?> <i class="fas fa-check-circle ms-1"></i></td>
                    <td class="text-center text-danger"><?= $s['incorrectas'] ?> <i class="fas fa-times-circle ms-1"></i></td>
                    <td class="small text-muted fst-italic">
                        <?= $s['lista_errores'] ? htmlspecialchars(substr($s['lista_errores'], 0, 150)) . '...' : 'Ninguno' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
