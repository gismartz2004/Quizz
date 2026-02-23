<?php
require_once 'includes/session.php';
require 'db.php';
require_once 'includes/analytics_data.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

// 2. CARGAR LISTA DE QUIZZES
$quizzes = [];
try {
    $stmt = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY titulo");
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 3. RECIBIR PARÁMETROS
$quiz_id        = isset($_GET['quiz_id']) && is_numeric($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : null;
$fecha_desde    = $_GET['fecha_desde'] ?? '';
$fecha_hasta    = $_GET['fecha_hasta'] ?? '';
$genero         = $_GET['genero'] ?? '';
$paralelo       = $_GET['paralelo'] ?? '';
$jornada_filtro = $_GET['jornada'] ?? ''; // 'Matutina' o 'Vespertina'
$filtro_muestra = $_GET['muestra'] ?? 'si'; // SI por defecto
$threshold      = isset($_GET['threshold']) ? (float)$_GET['threshold'] / 100 : 1.0;

// 4. CONSULTA SQL BASE
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
if ($genero) {
    $sql .= " AND r.genero = :genero";
    $params['genero'] = $genero;
}
if ($paralelo) {
    $sql .= " AND r.paralelo = :paralelo";
    $params['paralelo'] = $paralelo;
}
if ($filtro_muestra === 'si') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = TRUE";
} elseif ($filtro_muestra === 'no') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = FALSE";
}

// Filtro de Jornada (A-D Matutina, E-H Vespertina)
if ($jornada_filtro === 'Matutina') {
    $sql .= " AND r.paralelo IN ('A', 'B', 'C', 'D')";
} elseif ($jornada_filtro === 'Vespertina') {
    $sql .= " AND r.paralelo IN ('E', 'F', 'G', 'H')";
}

$sql .= " ORDER BY r.fecha_realizacion DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error cargando resultados: " . $e->getMessage());
}

// 5. CÁLCULOS DE SECCIÓN Y DESTREZAS
$sectionStats = calculateSectionStats($resultados);
$filters_analysis = [
    'genero' => $genero,
    'paralelo' => $paralelo,
    'jornada' => $jornada_filtro,
    'muestra' => $filtro_muestra
];
$skillsStats = ($quiz_id) ? analyzeSkillsDiff($pdo, $quiz_id, $threshold, $filters_analysis) : [];

// 6. LÓGICA DE APROBACIÓN POR MULTI-MATERIA (Similar a lenguaje_d)
// Necesitamos jalar todos los resultados de este sub-grupo para ver aprobados
$sql_global = "SELECT r.usuario_id, q.titulo, r.puntos_obtenidos, r.paralelo
               FROM resultados r
               JOIN quizzes q ON r.quiz_id = q.id";
$stmt_g = $pdo->query($sql_global);
$all_r = $stmt_g->fetchAll(PDO::FETCH_ASSOC);

$student_materias = [];
$matrix_data = []; // [Materia][Paralelo] = sum/count

foreach ($all_r as $r) {
    $uid = $r['usuario_id'];
    $tit = $r['titulo'];
    $par = strtoupper($r['paralelo']);
    $score = ($r['puntos_obtenidos'] / 250) * 100;

    // Agrupar por estudiante
    if (!isset($student_materias[$uid])) $student_materias[$uid] = ['aprobadas' => 0, 'paralelo' => $par];
    if ($score >= 70) $student_materias[$uid]['aprobadas']++;

    // Agrupar para matriz Paralelo vs Materia
    if (!isset($matrix_data[$tit][$par])) $matrix_data[$tit][$par] = ['sum' => 0, 'count' => 0];
    $matrix_data[$tit][$par]['sum'] += $score;
    $matrix_data[$tit][$par]['count']++;
}

$aprobados_2 = 0; $aprobados_3 = 0;
foreach ($student_materias as $s) {
    if ($s['aprobadas'] >= 2) $aprobados_2++;
    if ($s['aprobadas'] >= 3) $aprobados_3++;
}

// 7. OBTENER PREGUNTAS PARA LA TABLA EN BRUTO
$preguntas_quiz = [];
if ($quiz_id) {
    $stmt_p = $pdo->prepare("SELECT id, texto FROM preguntas WHERE quiz_id = ? ORDER BY id ASC");
    $stmt_p->execute([$quiz_id]);
    $preguntas_quiz = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
}

// 8. CÁLCULO DE PROMEDIO GLOBAL Y DISTRIBUCIONES PARA GRÁFICOS
$sum_pts = array_sum(array_column($resultados, 'puntos_obtenidos'));
$total_examenes_filtrados = count($resultados);
$promedio = ($total_examenes_filtrados > 0) ? round(($sum_pts / ($total_examenes_filtrados * 250)) * 100, 1) : 0;

// Distribución de Notas (Buckets)
$dist_notas = ['<60' => 0, '60-70' => 0, '70-80' => 0, '80-90' => 0, '90-100' => 0];
foreach ($resultados as $r) {
    $nota = ($r['puntos_obtenidos'] / 250) * 100;
    if ($nota < 60) $dist_notas['<60']++;
    elseif ($nota < 70) $dist_notas['60-70']++;
    elseif ($nota < 80) $dist_notas['70-80']++;
    elseif ($nota < 90) $dist_notas['80-90']++;
    else $dist_notas['90-100']++;
}

// Promedio por Paralelo (Data para gráfico)
$paralelos_chart_labels = ['A','B','C','D','E','F','G','H'];
$paralelos_chart_data = [];
foreach ($paralelos_chart_labels as $p) {
    $p_total = 0; $p_count = 0;
    foreach ($resultados as $r) {
        if (strtoupper($r['paralelo']) === $p) {
            $p_total += ($r['puntos_obtenidos'] / 250) * 100;
            $p_count++;
        }
    }
    $paralelos_chart_data[] = ($p_count > 0) ? round($p_total / $p_count, 1) : 0;
}

// Obtener respuestas detalladas para la tabla (BATCH)
$ids_resultados = array_column($resultados, 'id');
$respuestas_batch = getDetailedBatchAnswers($pdo, $ids_resultados);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SÉPTIMO | Resultados Especializados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #4361ee; --secondary-color: #3f37c9; --bg-light: #f8f9fa; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-light); color: #333; }
        .dashboard-card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .dashboard-card:hover { transform: translateY(-3px); }
        .filter-header { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.03); }
        .table-raw { font-size: 0.85rem; }
        .badge-shift { font-size: 0.7rem; padding: 4px 8px; border-radius: 20px; }
        .matutina { background-color: #e0f2fe; color: #0369a1; }
        .vespertina { background-color: #fef3c7; color: #92400e; }
        .sticky-column { position: sticky; left: 0; background: white; z-index: 10; border-right: 2px solid #eee; }
        
        .bg-primary-soft { background-color: rgba(67, 97, 238, 0.1); }
        .bg-success-soft { background-color: rgba(76, 175, 80, 0.1); }
        .bg-info-soft { background-color: rgba(0, 184, 212, 0.1); }
        .bg-warning-soft { background-color: rgba(255, 193, 7, 0.1); }
        .bg-purple-soft { background-color: rgba(155, 89, 182, 0.1); }
        .text-purple { color: #8e44ad; }
        
        .table-raw td { vertical-align: middle; }
        .table-raw tr:hover { background-color: #f1f5f9; }
    </style>
</head>
<body>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1"><i class="fas fa-graduation-cap me-3 text-primary"></i>Resultados Séptimo EGB</h2>
            <p class="text-muted small mb-0">Análisis detallado por jornada, paralelo y destrezas.</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="exportToExcel()" class="btn btn-success">
                <i class="fas fa-file-excel me-2"></i>Exportar Todo a Excel
            </button>
            <a href="profesor.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Regresar
            </a>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="filter-header">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold small text-muted">Seleccionar Examen</label>
                <select name="quiz_id" class="form-select select-field" onchange="this.form.submit()">
                    <option value="">-- Todos --</option>
                    <?php foreach ($quizzes as $q): ?>
                        <option value="<?= $q['id'] ?>" <?= $quiz_id == $q['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($q['titulo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small text-muted">Jornada</label>
                <select name="jornada" class="form-select select-field">
                    <option value="">Todas</option>
                    <option value="Matutina" <?= $jornada_filtro == 'Matutina' ? 'selected' : '' ?>>Matutina (A-D)</option>
                    <option value="Vespertina" <?= $jornada_filtro == 'Vespertina' ? 'selected' : '' ?>>Vespertina (E-H)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small text-muted">Paralelo</label>
                <select name="paralelo" class="form-select select-field">
                    <option value="">Todos</option>
                    <?php foreach (['A','B','C','D','E','F','G','H'] as $p): ?>
                        <option value="<?= $p ?>" <?= $paralelo == $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
             <div class="col-md-2">
                <label class="form-label fw-bold small text-muted">Género</label>
                <select name="genero" class="form-select select-field">
                    <option value="">Ambos</option>
                    <option value="Masculino" <?= $genero == 'Masculino' ? 'selected' : '' ?>>Masculino</option>
                    <option value="Femenino" <?= $genero == 'Femenino' ? 'selected' : '' ?>>Femenino</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-bold small text-muted">Muestra</label>
                <select name="muestra" class="form-select select-field">
                    <option value="todos" <?= $filtro_muestra == 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="si" <?= $filtro_muestra == 'si' ? 'selected' : '' ?>>Sí</option>
                    <option value="no" <?= $filtro_muestra == 'no' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary w-100">Aplicar Filtros</button>
                <a href="resultados_septimo.php" class="btn btn-light text-muted" title="Limpiar"><i class="fas fa-undo"></i></a>
            </div>
        </form>
    </div>

    <!-- KPIs -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card dashboard-card p-3 border-start border-4 border-primary">
                <div class="d-flex align-items-center">
                    <div class="bg-primary-soft p-3 rounded-circle me-3"><i class="fas fa-users text-primary fa-lg"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= count($resultados) ?></h4>
                        <p class="text-muted small mb-0">Total Exámenes</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card p-3 border-start border-4 border-success">
                <div class="d-flex align-items-center">
                    <div class="bg-success-soft p-3 rounded-circle me-3"><i class="fas fa-check-double text-success fa-lg"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $aprobados_2 ?></h4>
                        <p class="text-muted small mb-0">Aprobados 2+ Materias</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card p-3 border-start border-4 border-info">
                <div class="d-flex align-items-center">
                    <div class="bg-info-soft p-3 rounded-circle me-3"><i class="fas fa-star text-info fa-lg"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $aprobados_3 ?></h4>
                        <p class="text-muted small mb-0">Aprobados 3+ Materias</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card p-3 border-start border-4 border-purple">
                <div class="d-flex align-items-center">
                    <div class="bg-purple-soft p-3 rounded-circle me-3"><i class="fas fa-percentage text-purple fa-lg"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $promedio ?>%</h4>
                        <p class="text-muted small mb-0">Promedio General</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GRÁFICOS ANALÍTICOS -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card dashboard-card p-3">
                <h6 class="fw-bold text-muted mb-3"><i class="fas fa-chart-line me-2"></i>Promedio por Paralelo (%)</h6>
                <canvas id="chartParalelos" style="max-height: 300px;"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card dashboard-card p-3">
                <h6 class="fw-bold text-muted mb-3"><i class="fas fa-chart-pie me-2"></i>Distribución de Notas</h6>
                <canvas id="chartNotas" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>

    <!-- MATRIZ DE RESULTADOS POR PARALELO Y MATERIA -->
    <div class="card dashboard-card mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-th me-2 text-info"></i>Rendimiento por Área y Paralelo</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">Asignatura / Área</th>
                            <?php foreach (['A','B','C','D','E','F','G','H'] as $p): ?>
                                <th>Par. <?= $p ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrix_data as $materia => $paralelos): ?>
                            <tr>
                                <td class="text-start fw-bold small"><?= htmlspecialchars($materia) ?></td>
                                <?php foreach (['A','B','C','D','E','F','G','H'] as $p): ?>
                                    <?php 
                                        $val = 0;
                                        if (isset($paralelos[$p])) {
                                            $val = round($paralelos[$p]['sum'] / $paralelos[$p]['count'], 1);
                                        }
                                        $color = ($val >= 70) ? 'text-success' : 'text-danger';
                                    ?>
                                    <td class="<?= $color ?> fw-bold"><?= $val ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DESTREZAS (DIFICULTAD) -->
    <div class="card dashboard-card mb-4 border-start border-4 border-warning">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-brain me-2 text-warning"></i>Detección de Destrezas Críticas <span id="skillsLoader" class="spinner-border spinner-border-sm text-warning ms-2" style="display:none;"></span></h5>
            <div class="d-flex align-items-center gap-3" style="width: 400px;">
                <label class="small text-muted text-nowrap">Dificultad Threshold: <span id="thresholdVal" class="fw-bold text-dark"><?= $threshold * 100 ?></span>%</label>
                <input type="range" class="form-range" id="thresholdRange" min="0" max="100" step="5" value="<?= $threshold * 100 ?>">
            </div>
        </div>
        <div class="card-body" id="skillsContainer">
            <?php if (!$quiz_id): ?>
                <div class="text-center py-4 text-muted">Seleccione un examen para analizar destrezas.</div>
            <?php elseif (empty($skillsStats)): ?>
                <div class="text-center py-4 text-muted">No hay preguntas que coincidan con el nivel de dificultad seleccionado (<= <?= $threshold * 100 ?>%).</div>
            <?php else: ?>
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
            <?php endif; ?>
        </div>
    </div>

    <!-- TABLA EN BRUTO -->
    <div class="card dashboard-card">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-database me-2 text-primary"></i>Base de Resultados Detallada</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 700px;">
                <table class="table table-sm table-hover table-bordered mb-0 table-raw">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th class="sticky-column">Estudiante</th>
                            <th>Jornada</th>
                            <th>Para.</th>
                            <th>Gén.</th>
                            <th>Edad</th>
                            <th>Nota/250</th>
                            <th>Nota%</th>
                            <?php foreach ($preguntas_quiz as $idx => $p): ?>
                                <th title="<?= htmlspecialchars(strip_tags($p['texto'])) ?>">P<?= $idx+1 ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $r): ?>
                            <?php 
                                $shift = in_array(strtoupper($r['paralelo']), ['A','B','C','D']) ? 'Matutina' : 'Vespertina';
                                $nota_p = round(($r['puntos_obtenidos'] / 250) * 100, 2);
                            ?>
                            <tr>
                                <td class="sticky-column fw-bold"><?= htmlspecialchars($r['usuario_nombre']) ?></td>
                                <td class="text-center">
                                    <span class="badge-shift <?= strtolower($shift) ?>"><?= $shift ?></span>
                                </td>
                                <td class="text-center"><?= $r['paralelo'] ?></td>
                                <td class="text-center"><?= substr($r['genero'], 0, 1) ?></td>
                                <td class="text-center"><?= $r['edad'] ?></td>
                                <td class="text-center"><?= $r['puntos_obtenidos'] ?></td>
                                <td class="text-center fw-bold"><?= $nota_p ?>%</td>
                                <?php foreach ($preguntas_quiz as $p): ?>
                                    <?php 
                                        $ans = $respuestas_batch[$r['id']][$p['id']] ?? null;
                                        $class = '';
                                        if ($ans) {
                                            $class = $ans['es_correcta'] ? 'table-success' : 'table-danger';
                                        }
                                    ?>
                                    <td class="<?= $class ?> text-center" style="min-width: 40px;" title="<?= $ans ? htmlspecialchars($ans['texto']) : 'No responde' ?>">
                                        <?= $ans ? ($ans['es_correcta'] ? '✔' : '✘') : '-' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function updateThresholdAjax(val) {
    const quizId = "<?= $quiz_id ?>";
    if (!quizId) return;

    // Obtener filtros actuales de la URL o del estado
    const params = new URLSearchParams(window.location.search);
    params.set('quiz_id', quizId);
    params.set('threshold', val);
    
    document.getElementById('skillsLoader').style.display = 'inline-block';
    
    fetch(`api_skills.php?${params.toString()}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('skillsContainer').innerHTML = html;
            document.getElementById('skillsLoader').style.display = 'none';
        })
        .catch(err => {
            console.error('Error cargando destrezas:', err);
            document.getElementById('skillsLoader').style.display = 'none';
        });
}

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

const rangeInput = document.getElementById('thresholdRange');
if (rangeInput) {
    const debouncedUpdate = debounce((val) => {
        updateThresholdAjax(val);
    }, 400);

    rangeInput.addEventListener('input', function() {
        document.getElementById('thresholdVal').innerText = this.value;
        debouncedUpdate(this.value);
    });
}

function exportToExcel() {
    const urlParams = new URLSearchParams(window.location.search);
    window.location.href = 'exportar_excel.php?mode=full&' + urlParams.toString();
}

// --- GRÁFICOS ---
document.addEventListener('DOMContentLoaded', function() {
    const ctxPar = document.getElementById('chartParalelos').getContext('2d');
    new Chart(ctxPar, {
        type: 'bar',
        data: {
            labels: <?= json_encode($paralelos_chart_labels) ?>,
            datasets: [{
                label: 'Promedio (%)',
                data: <?= json_encode($paralelos_chart_data) ?>,
                backgroundColor: 'rgba(67, 97, 238, 0.7)',
                borderColor: '#4361ee',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, max: 100 } },
            plugins: { legend: { display: false } }
        }
    });

    const ctxNot = document.getElementById('chartNotas').getContext('2d');
    new Chart(ctxNot, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($dist_notas)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($dist_notas)) ?>,
                backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6', '#06b6d4', '#10b981'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'right' }
            },
            cutout: '65%'
        }
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</body>
</html>
