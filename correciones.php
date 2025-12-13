<?php
session_start();
require 'db.php';

// 1. SEGURIDAD: Solo profesores
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

// 2. OBTENER TODAS LAS MATERIAS (QUIZZES)
try {
    $stmtQ = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY titulo ASC");
    $all_quizzes = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al cargar quizzes: " . $e->getMessage());
}

// 3. LÓGICA DE FILTRADO
$filtro_falta_quiz_id = isset($_GET['falta_quiz']) ? (int)$_GET['falta_quiz'] : null;

// Construimos la consulta de estudiantes
$sql_estudiantes = "SELECT id, nombre, email FROM usuarios WHERE rol = 'estudiante'";

// Si hay un filtro activo (Ej: "Mostrar los que les falta Matemáticas")
if ($filtro_falta_quiz_id) {
    $sql_estudiantes .= " AND id NOT IN (
        SELECT usuario_id FROM resultados WHERE quiz_id = :quiz_id
    )";
}

$sql_estudiantes .= " ORDER BY nombre ASC";

try {
    $stmtU = $pdo->prepare($sql_estudiantes);
    if ($filtro_falta_quiz_id) {
        $stmtU->execute(['quiz_id' => $filtro_falta_quiz_id]);
    } else {
        $stmtU->execute();
    }
    $estudiantes = $stmtU->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al cargar estudiantes: " . $e->getMessage());
}

// 4. CARGAR PROGRESO (SOLO DE LOS ESTUDIANTES VISIBLES)
// Creamos un mapa: $progreso[usuario_id][quiz_id] = nota
$progreso = [];
if (!empty($estudiantes)) {
    // Obtenemos los IDs de los estudiantes listados para optimizar la consulta
    $ids_estudiantes = array_column($estudiantes, 'id');
    $in_query = implode(',', array_fill(0, count($ids_estudiantes), '?'));

    $sql_res = "SELECT usuario_id, quiz_id, porcentaje 
                FROM resultados 
                WHERE usuario_id IN ($in_query)";
    
    $stmtR = $pdo->prepare($sql_res);
    $stmtR->execute($ids_estudiantes);
    $raw_resultados = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    // Organizar en array asociativo para fácil acceso en el HTML
    foreach ($raw_resultados as $r) {
        $progreso[$r['usuario_id']][$r['quiz_id']] = $r['porcentaje'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Matriz de Cumplimiento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .table-responsive { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .status-cell { text-align: center; vertical-align: middle; }
        .badge-done { background-color: #dcfce7; color: #166534; padding: 5px 10px; border-radius: 4px; font-weight: 600; font-size: 0.85rem; }
        .badge-missing { background-color: #fee2e2; color: #991b1b; padding: 5px 10px; border-radius: 4px; font-weight: 600; font-size: 0.85rem; }
        .user-info { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 35px; height: 35px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #64748b; }
        th.rotate { height: 140px; white-space: nowrap; }
        th.rotate > div { transform: translate(10px, 50px) rotate(-45deg); width: 30px; }
        th.rotate > div > span { border-bottom: 1px solid #ccc; padding: 5px 10px; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark"><i class="fas fa-tasks text-primary"></i> Control de Cumplimiento</h2>
        <a href="profesor.php" class="btn btn-outline-secondary">Volver al Panel</a>
    </div>

    <div class="card p-4 mb-4 border-0 shadow-sm">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label fw-bold text-muted">¿Qué estudiantes FALTAN por rendir...?</label>
                <select name="falta_quiz" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Ver todos los estudiantes --</option>
                    <?php foreach ($all_quizzes as $q): ?>
                        <option value="<?= $q['id'] ?>" <?= $filtro_falta_quiz_id == $q['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($q['titulo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <?php if($filtro_falta_quiz_id): ?>
                    <div class="alert alert-warning mb-0 py-2">
                        <i class="fas fa-filter"></i> Mostrando solo estudiantes que <strong>NO</strong> han realizado el examen seleccionado.
                        <a href="ver.php" class="fw-bold text-dark ms-2">Limpiar filtro</a>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-2 small">Selecciona una materia para filtrar a los estudiantes pendientes.</p>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive p-3">
        <table class="table table-hover table-bordered mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 250px; vertical-align: bottom;">Estudiante</th>
                    <?php foreach ($all_quizzes as $q): ?>
                        <th class="text-center" style="vertical-align: bottom;">
                            <span class="d-block small text-muted text-uppercase mb-1" style="font-size:0.7rem">Materia</span>
                            <?= htmlspecialchars($q['titulo']) ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="text-center" style="vertical-align: bottom;">Progreso</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($estudiantes)): ?>
                    <tr><td colspan="<?= count($all_quizzes) + 2 ?>" class="text-center py-5 text-muted">No se encontraron estudiantes.</td></tr>
                <?php else: ?>
                    <?php foreach ($estudiantes as $est): 
                        $quizzes_completados = 0;
                        $total_quizzes = count($all_quizzes);
                    ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?= strtoupper(substr($est['nombre'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($est['nombre']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($est['email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <?php foreach ($all_quizzes as $q): 
                                // Verificamos si existe registro en nuestro array $progreso
                                $realizado = isset($progreso[$est['id']][$q['id']]);
                                $nota = $realizado ? $progreso[$est['id']][$q['id']] : 0;
                                if ($realizado) $quizzes_completados++;
                            ?>
                                <td class="status-cell <?= $realizado ? 'bg-light' : '' ?>">
                                    <?php if ($realizado): ?>
                                        <span class="badge-done" title="Nota: <?= $nota ?>%">
                                            <i class="fas fa-check"></i> Hecho
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-missing">
                                            <i class="fas fa-times"></i> Falta
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <td style="vertical-align: middle; width: 150px;">
                                <?php 
                                    $porcentaje_avance = ($total_quizzes > 0) ? round(($quizzes_completados / $total_quizzes) * 100) : 0;
                                    $color_barra = $porcentaje_avance == 100 ? 'bg-success' : ($porcentaje_avance > 50 ? 'bg-primary' : 'bg-warning');
                                ?>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar <?= $color_barra ?>" style="width: <?= $porcentaje_avance ?>%"></div>
                                    </div>
                                    <span class="small fw-bold"><?= $quizzes_completados ?>/<?= $total_quizzes ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>