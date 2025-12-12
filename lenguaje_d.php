<?php
session_start();
require 'db.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

// 2. CARGAR LISTA DE QUIZZES
$quizzes = [];
try {
    $stmt = $pdo->query("SELECT id, titulo FROM quizzes where id <> 23 ORDER BY titulo");
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 3. RECIBIR PARÁMETROS
// Corrección aquí: eliminé el doble signo $$ que tenías antes
$quiz_id      = isset($_GET['quiz_id']) && is_numeric($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : null;
$fecha_desde  = $_GET['fecha_desde'] ?? '';
$fecha_hasta  = $_GET['fecha_hasta'] ?? '';
$genero       = $_GET['genero'] ?? '';
$edad         = isset($_GET['edad']) && is_numeric($_GET['edad']) ? (int)$_GET['edad'] : '';
$integridad   = $_GET['integridad'] ?? '';

// ==========================================================
// CONSULTA 1: ESTUDIANTES QUE YA REALIZARON EL EXAMEN
// ==========================================================
$sql = "SELECT 
            r.*, 
            u.nombre as usuario_nombre, 
            u.email as usuario_email,
            q.titulo as quiz_titulo 
        FROM resultados r
        JOIN usuarios u ON r.usuario_id = u.id
        JOIN quizzes q ON r.quiz_id = q.id
        WHERE 1=1";

$params = [];

if ($quiz_id) {
    $sql .= " AND r.quiz_id = :quiz_id";
    $params['quiz_id'] = $quiz_id;
}
if ($fecha_desde) {
    $sql .= " AND r.fecha_realizacion >= :fecha_desde";
    $params['fecha_desde'] = $fecha_desde . ' 00:00:00';
}
if ($fecha_hasta) {
    $sql .= " AND r.fecha_realizacion <= :fecha_hasta";
    $params['fecha_hasta'] = $fecha_hasta . ' 23:59:59';
}
// Nuevos filtros (estos campos existen en la tabla 'resultados')
if ($genero) {
    $sql .= " AND r.genero = :genero";
    $params['genero'] = $genero;
}
if ($edad) {
    $sql .= " AND r.edad = :edad";
    $params['edad'] = $edad;
}

$sql .= " ORDER BY r.fecha_realizacion DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error cargando resultados: " . $e->getMessage());
}

// PROCESAMIENTO DE DATOS (Métricas y Filtro Integridad en PHP)
$resultados = [];
$stats = ['total' => 0, 'aprobados' => 0, 'incidentes' => 0, 'suma_notas' => 0];

foreach ($resultados_raw as $row) {
    // Lógica para determinar el nivel de integridad
    $swaps = (int)($row['intentos_tab_switch'] ?? 0);
    $time  = (int)($row['segundos_fuera'] ?? 0);
    
    if ($swaps == 0 && $time == 0) $nivel = 'limpio';
    elseif ($swaps <= 2 && $time < 15) $nivel = 'leve';
    else $nivel = 'riesgo';

    // Aplicar filtro de integridad si fue seleccionado
    if ($integridad && $integridad !== $nivel) continue;

    // Agregar datos calculados al row para usar en HTML
    $row['nivel_integridad'] = $nivel;
    $resultados[] = $row;

    // Métricas
    $stats['total']++;
    $stats['suma_notas'] += (float)$row['porcentaje'];
    if ((float)$row['porcentaje'] >= 70) $stats['aprobados']++;
    if ($nivel !== 'limpio') $stats['incidentes']++;
}

$promedio = $stats['total'] > 0 ? round($stats['suma_notas'] / $stats['total'], 1) : 0;

// ==========================================================
// CONSULTA 2: ESTUDIANTES PENDIENTES (SOLO SI SE SELECCIONA UN QUIZ)
// ==========================================================
$pendientes = [];
if ($quiz_id) {
    // Seleccionar usuarios tipo 'estudiante' cuyo ID NO esté en la tabla resultados para este quiz
    $sql_pendientes = "SELECT id, nombre, email, fecha_registro 
                       FROM usuarios 
                       WHERE rol = 'estudiante' 
                       AND id NOT IN (
                           SELECT usuario_id FROM resultados WHERE quiz_id = :quiz_id
                       )
                       ORDER BY nombre ASC";
    try {
        $stmt_p = $pdo->prepare($sql_pendientes);
        $stmt_p->execute(['quiz_id' => $quiz_id]);
        $pendientes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error cargando pendientes: " . $e->getMessage());
    }
}

// HELPERS VISUALES
function getScoreBadge($nota) {
    if ($nota >= 90) return 'bg-success-soft text-success';
    if ($nota >= 70) return 'bg-info-soft text-info';
    return 'bg-danger-soft text-danger';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Académico Avanzado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --bg: #f8fafc; }
        body { background-color: var(--bg); font-family: system-ui, -apple-system, sans-serif; }
        .card-custom { border:none; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.03); background:white; }
        .stat-val { font-size: 1.5rem; font-weight: 700; }
        .bg-success-soft { background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size:0.8rem; }
        .bg-danger-soft { background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size:0.8rem; }
        .bg-info-soft { background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size:0.8rem; }
        .nav-tabs .nav-link { color: #64748b; border: none; border-bottom: 2px solid transparent; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); font-weight: 600; }
        .avatar-initial { width:32px; height:32px; background:#e2e8f0; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; color:#475569; font-size:0.8rem; margin-right:8px; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold m-0 text-dark"><i class="fas fa-chart-pie me-2 text-primary"></i>Reporte Académico</h4>
        <a href="profesor.php" class="btn btn-outline-secondary btn-sm">Volver</a>
    </div>

    <div class="card-custom p-4 mb-4">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted">Examen (Requerido para pendientes)</label>
                <select name="quiz_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">-- Todos los exámenes --</option>
                    <?php foreach ($quizzes as $q): ?>
                        <option value="<?= $q['id'] ?>" <?= $quiz_id == $q['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($q['titulo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted">Género</label>
                <select name="genero" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="Masculino" <?= $genero == 'Masculino' ? 'selected' : '' ?>>Masculino</option>
                    <option value="Femenino" <?= $genero == 'Femenino' ? 'selected' : '' ?>>Femenino</option>
                    <option value="Otro" <?= $genero == 'Otro' ? 'selected' : '' ?>>Otro</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted">Edad Exacta</label>
                <input type="number" name="edad" class="form-control form-control-sm" value="<?= htmlspecialchars($edad) ?>" placeholder="Ej: 15">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted">Integridad</label>
                <select name="integridad" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="limpio" <?= $integridad == 'limpio' ? 'selected' : '' ?>>Limpio</option>
                    <option value="riesgo" <?= $integridad == 'riesgo' ? 'selected' : '' ?>>Con Sospecha</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="fas fa-filter me-1"></i> Filtrar</button>
                <a href="?" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Limpiar</a>
            </div>
        </form>
    </div>

    <ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="results-tab" data-bs-toggle="tab" data-bs-target="#results" type="button" role="tab">
                Resultados Completados <span class="badge bg-secondary rounded-pill ms-1"><?= $stats['total'] ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                Estudiantes Pendientes 
                <?php if ($quiz_id): ?>
                    <span class="badge bg-danger rounded-pill ms-1"><?= count($pendientes) ?></span>
                <?php else: ?>
                    <small class="text-muted fst-italic ms-1">(Selecciona examen)</small>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="reportTabsContent">
        
        <div class="tab-pane fade show active" id="results" role="tabpanel">
            
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card-custom p-3 border-start border-4 border-primary">
                        <small class="text-muted text-uppercase fw-bold">Promedio</small>
                        <div class="stat-val text-primary"><?= $promedio ?>%</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-custom p-3 border-start border-4 border-success">
                        <small class="text-muted text-uppercase fw-bold">Aprobados</small>
                        <div class="stat-val text-success"><?= $stats['aprobados'] ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-custom p-3 border-start border-4 border-danger">
                        <small class="text-muted text-uppercase fw-bold">Alertas Integridad</small>
                        <div class="stat-val text-danger"><?= $stats['incidentes'] ?></div>
                    </div>
                </div>
            </div>

            <div class="card-custom p-0 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Estudiante</th>
                                <th>Demografía</th>
                                <th>Examen</th>
                                <th>Integridad</th>
                                <th class="text-center">Nota</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resultados)): ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted">No hay resultados con estos filtros.</td></tr>
                            <?php else: ?>
                                <?php foreach ($resultados as $row): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-initial"><?= strtoupper(substr($row['usuario_nombre'], 0, 1)) ?></div>
                                            <div>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['usuario_nombre']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($row['usuario_email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <i class="fas fa-venus-mars text-muted me-1"></i> <?= htmlspecialchars($row['genero'] ?? 'N/A') ?>
                                        </div>
                                        <div class="small">
                                            <i class="fas fa-birthday-cake text-muted me-1"></i> <?= htmlspecialchars($row['edad'] ?? 'N/A') ?> años
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-secondary small"><?= htmlspecialchars($row['quiz_titulo']) ?></div>
                                        <div class="small text-muted"><?= date('d/m/Y H:i', strtotime($row['fecha_realizacion'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($row['nivel_integridad'] === 'limpio'): ?>
                                            <span class="badge bg-light text-secondary border"><i class="fas fa-check me-1"></i> Limpio</span>
                                        <?php else: ?>
                                            <div class="text-danger small fw-bold"><i class="fas fa-exclamation-triangle"></i> <?= ucfirst($row['nivel_integridad']) ?></div>
                                            <div class="small text-muted" style="font-size:0.75rem">
                                                <?= $row['intentos_tab_switch'] ?> cambios · <?= $row['segundos_fuera'] ?>s fuera
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?= getScoreBadge($row['porcentaje']) ?>">
                                            <?= round($row['porcentaje'], 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick="verJustificaciones(<?= (int)$row['id'] ?>)">
                                            <i class="fas fa-align-left"></i> Justificaciones
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pending" role="tabpanel">
            <?php if (!$quiz_id): ?>
                <div class="text-center py-5">
                    <div class="text-muted mb-3"><i class="fas fa-arrow-up fa-2x"></i></div>
                    <h5>Selecciona un examen primero</h5>
                    <p class="text-muted">Para ver quién falta, necesitamos saber qué examen estás revisando.</p>
                </div>
            <?php else: ?>
                <div class="alert alert-warning border-0 d-flex align-items-center mb-3">
                    <i class="fas fa-info-circle fs-4 me-3"></i>
                    <div>
                        <strong>Nota:</strong> Esta lista muestra estudiantes registrados en el sistema que aún no han completado el examen seleccionado.
                        <br>No se pueden filtrar por edad/género porque esos datos se capturan <em>durante</em> el examen.
                    </div>
                </div>

                <div class="card-custom p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Estudiante</th>
                                    <th>Email</th>
                                    <th>Fecha Registro</th>
                                    <th class="text-end pe-4">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendientes)): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-success"><i class="fas fa-check-circle fa-2x mb-2"></i><br>¡Todos los estudiantes han completado este examen!</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendientes as $p): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-initial bg-warning-subtle text-warning-emphasis">
                                                    <?= strtoupper(substr($p['nombre'], 0, 1)) ?>
                                                </div>
                                                <?= htmlspecialchars($p['nombre']) ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($p['email']) ?></td>
                                        <td class="text-muted small"><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></td>
                                        <td class="text-end pe-4">
                                            <a href="mailto:<?= htmlspecialchars($p['email']) ?>?subject=Recordatorio Examen Pendiente: Examen Pendiente" class="btn btn-sm btn-outline-primary">
                                                <i class="far fa-envelope"></i> Recordar
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Modal para ver justificaciones -->
<div class="modal fade" id="justificacionesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-comment-dots me-2 text-primary"></i>Justificaciones del estudiante</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="justificacionesBody">
                <div class="text-center py-4 text-muted">Cargando justificaciones...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
    </div>

<script>
function verJustificaciones(resultadoId) {
    const modalEl = document.getElementById('justificacionesModal');
    const bodyEl = document.getElementById('justificacionesBody');
    bodyEl.innerHTML = '<div class="text-center py-4 text-muted">Cargando justificaciones...</div>';
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    fetch('detalles_justificaciones.php?resultado_id=' + encodeURIComponent(resultadoId))
        .then(res => res.text())
        .then(html => {
            bodyEl.innerHTML = html;
        })
        .catch(() => {
            bodyEl.innerHTML = '<div class="alert alert-danger">No se pudieron cargar las justificaciones.</div>';
        });
}
</script>
</body>
</html>