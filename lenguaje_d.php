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
    $stmt = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY titulo");
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 3. RECIBIR PARÁMETROS
$quiz_id      = isset($_GET['quiz_id']) && is_numeric($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : null;
$fecha_desde  = $_GET['fecha_desde'] ?? '';
$fecha_hasta  = $_GET['fecha_hasta'] ?? '';
$genero       = $_GET['genero'] ?? '';
$edad         = isset($_GET['edad']) && is_numeric($_GET['edad']) ? (int)$_GET['edad'] : '';
$paralelo     = $_GET['paralelo'] ?? '';
$integridad   = $_GET['integridad'] ?? '';
// Nuevo filtro: mostrar solo exámenes marcados como muestra
$filtro_muestra = isset($_GET['muestra']) ? $_GET['muestra'] : '';

// 4. CONSULTA SQL
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
if ($edad) {
    $sql .= " AND r.edad = :edad";
    $params['edad'] = $edad;
}
if ($paralelo) {
    $sql .= " AND r.paralelo = :paralelo";
    $params['paralelo'] = $paralelo;
}
// Aplicar filtro de muestra si corresponde: 'si' -> TRUE, 'no' -> FALSE
if ($filtro_muestra === 'si') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = TRUE";
} elseif ($filtro_muestra === 'no') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = FALSE";
}

$sql .= " ORDER BY r.fecha_realizacion DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error cargando resultados: " . $e->getMessage());
}

// 5. PROCESAMIENTO (Cálculo sobre 100 y Filtros)
$resultados = [];
$stats = ['total' => 0, 'aprobados' => 0, 'incidentes' => 0, 'suma_notas' => 0];
$TOTAL_PUNTOS_POSIBLES = 250; // 25 preguntas x 10 pts

foreach ($resultados_raw as $row) {
    // Integridad
    $swaps = (int)($row['intentos_tab_switch'] ?? 0);
    $time  = (int)($row['segundos_fuera'] ?? 0);
    
    if ($swaps == 0 && $time == 0) $nivel = 'limpio';
    elseif ($swaps <= 2 && $time < 15) $nivel = 'leve';
    else $nivel = 'riesgo';

    if ($integridad && $integridad !== $nivel) continue;

    // Cálculo Nota / 100
    $puntos_obtenidos = (float)$row['puntos_obtenidos'];
    $tot_posibles_row = isset($row['puntos_totales_quiz']) ? (float)$row['puntos_totales_quiz'] : $TOTAL_PUNTOS_POSIBLES;
    $nota_calculada = $tot_posibles_row > 0 ? ($puntos_obtenidos / $tot_posibles_row) * 100 : 0;
    $nota_final_100 = round($nota_calculada, 2);
    if ($nota_final_100 > 100) $nota_final_100 = 100;

    $row['nota_sobre_100'] = $nota_final_100;
    $row['nivel_integridad'] = $nivel;
    
    $resultados[] = $row;

    // Métricas
    $stats['total']++;
    $stats['suma_notas'] += $nota_final_100;
    if ($nota_final_100 >= 70) $stats['aprobados']++;
    if ($nivel !== 'limpio') $stats['incidentes']++;
}

$promedio = $stats['total'] > 0 ? round($stats['suma_notas'] / $stats['total'], 2) : 0;

// 7. DATOS PARA GRÁFICOS - Agrupado por Quiz/Materia
$stats_por_quiz = [];
foreach ($resultados as $row) {
    $quiz_titulo = $row['quiz_titulo'];
    if (!isset($stats_por_quiz[$quiz_titulo])) {
        $stats_por_quiz[$quiz_titulo] = [
            'total' => 0,
            'suma_notas' => 0,
            'aprobados' => 0
        ];
    }
    $stats_por_quiz[$quiz_titulo]['total']++;
    $stats_por_quiz[$quiz_titulo]['suma_notas'] += $row['nota_sobre_100'];
    if ($row['nota_sobre_100'] >= 70) {
        $stats_por_quiz[$quiz_titulo]['aprobados']++;
    }
}

// Calcular promedios
foreach ($stats_por_quiz as $titulo => &$data) {
    $data['promedio'] = round($data['suma_notas'] / $data['total'], 2);
    $data['tasa_aprobacion'] = round(($data['aprobados'] / $data['total']) * 100, 1);
}
unset($data);

// 8. PENDIENTES
$pendientes = [];
if ($quiz_id) {
    $sql_pendientes = "SELECT id, nombre, email, fecha_registro 
                       FROM usuarios 
                       WHERE rol = 'estudiante' 
                       AND id NOT IN (SELECT usuario_id FROM resultados WHERE quiz_id = :quiz_id)
                       ORDER BY nombre ASC";
    try {
        $stmt_p = $pdo->prepare($sql_pendientes);
        $stmt_p->execute(['quiz_id' => $quiz_id]);
        $pendientes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error cargando pendientes: " . $e->getMessage());
    }
}

// Helpers
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
    <title>Reporte Académico | Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-success: linear-gradient(135deg, #0cebeb 0%, #20e3b2 100%);
            --gradient-danger: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-info: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --bg-main: #f0f4f8;
            --text-primary: #1a202c;
            --text-secondary: #718096;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 20px 60px rgba(0, 0, 0, 0.12);
        }
        
        * {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background: var(--bg-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text-primary);
        }
        
        /* Header Styles */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem 0;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4);
        }
        
        .page-header h4 {
            color: white;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .page-header .btn {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 600;
            padding: 0.5rem 1.5rem;
            border-radius: 12px;
        }
        
        .page-header .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }
        
        /* Card Styles */
        .card-custom {
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            background: white;
            overflow: hidden;
        }
        
        .card-custom:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-5px);
        }
        
        /* Stat Cards */
        .stat-card {
            position: relative;
            padding: 1.5rem;
            border-radius: 20px;
            overflow: hidden;
            color: white;
            box-shadow: var(--card-shadow);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0.9;
            z-index: 1;
        }
        
        .stat-card.primary::before { background: var(--gradient-primary); }
        .stat-card.success::before { background: var(--gradient-success); }
        .stat-card.danger::before { background: var(--gradient-danger); }
        .stat-card.info::before { background: var(--gradient-info); }
        
        .stat-card > * { position: relative; z-index: 2; }
        
        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.95;
            margin-bottom: 0.5rem;
        }
        
        .stat-val {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 3rem;
            opacity: 0.2;
        }
        
        /* Filter Form */
        .filter-form {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
        }
        
        .form-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .form-select, .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.65rem 1rem;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 0.65rem 1.5rem;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        
        .btn-light {
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            color: var(--text-secondary);
            font-weight: 600;
            padding: 0.65rem 1.5rem;
        }
        
        .btn-light:hover {
            background: #edf2f7;
            border-color: #cbd5e0;
        }
        
        /* Tabs */
        .nav-tabs {
            border: none;
            background: white;
            padding: 0.5rem;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--text-secondary);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            margin: 0 0.25rem;
        }
        
        .nav-tabs .nav-link:hover {
            background: #f7fafc;
            color: var(--text-primary);
        }
        
        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .nav-tabs .badge {
            background: rgba(255, 255, 255, 0.9);
            color: #667eea;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 8px;
        }
        
        .nav-tabs .nav-link.active .badge {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        /* Table */
        .table {
            color: var(--text-primary);
        }
        
        .table thead {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
        }
        
        .table thead th {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            border: none;
            padding: 1rem;
        }
        
        .table tbody tr {
            border-bottom: 1px solid #f0f4f8;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.03) 0%, rgba(118, 75, 162, 0.03) 100%);
        }
        
        .table tbody td {
            padding: 1.25rem 1rem;
            vertical-align: middle;
            border: none;
        }
        
        /* Badges */
        .bg-success-soft {
            background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);
            color: #065f46;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(150, 230, 161, 0.4);
        }
        
        .bg-danger-soft {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #991b1b;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(252, 182, 159, 0.4);
        }
        
        .bg-info-soft {
            background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);
            color: #1e40af;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(161, 196, 253, 0.4);
        }
        
        /* Avatar */
        .avatar-initial {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: white;
            font-size: 1.1rem;
            margin-right: 1rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        /* Buttons */
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            border-radius: 12px;
            font-weight: 600;
            padding: 0.5rem 1.25rem;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        /* Modal */
        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 1.5rem;
        }
        
        .modal-title {
            font-weight: 700;
        }
        
        .modal-footer {
            border-top: 1px solid #f0f4f8;
            padding: 1.25rem;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            border: none;
            color: var(--text-secondary);
            font-weight: 600;
            border-radius: 12px;
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
            color: var(--text-primary);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card-custom, .stat-card, .filter-form {
            animation: fadeInUp 0.5s ease-out;
        }
        
        /* Spinner */
        .spinner-border {
            width: 3rem;
            height: 3rem;
            border-width: 0.3rem;
        }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="page-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h4><i class="fas fa-chart-line me-3"></i>Reporte Académico</h4>
                <a href="profesor.php" class="btn">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </a>
            </div>
        </div>
    </div>

    <div class="filter-form mb-4">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted">Examen</label>
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
                <label class="form-label small fw-bold text-muted">Edad</label>
                <input type="number" name="edad" class="form-control form-control-sm" value="<?= htmlspecialchars($edad) ?>" placeholder="Ej: 15">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted">Paralelo</label>
                <select name="paralelo" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="A" <?= $paralelo == 'A' ? 'selected' : '' ?>>A</option>
                    <option value="B" <?= $paralelo == 'B' ? 'selected' : '' ?>>B</option>
                    <option value="C" <?= $paralelo == 'C' ? 'selected' : '' ?>>C</option>
                    <option value="D" <?= $paralelo == 'D' ? 'selected' : '' ?>>D</option>
                    <option value="E" <?= $paralelo == 'E' ? 'selected' : '' ?>>E</option>
                    <option value="F" <?= $paralelo == 'F' ? 'selected' : '' ?>>F</option>
                    <option value="G" <?= $paralelo == 'G' ? 'selected' : '' ?>>G</option>
                    <option value="H" <?= $paralelo == 'H' ? 'selected' : '' ?>>H</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted">Muestra</label>
                <select name="muestra" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="si" <?= $filtro_muestra === 'si' ? 'selected' : '' ?>>Solo muestra</option>
                    <option value="no" <?= $filtro_muestra === 'no' ? 'selected' : '' ?>>No muestra</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                <a href="?" class="btn btn-light btn-sm">Limpiar</a>
            </div>
        </form>
    </div>

    <ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="results-tab" data-bs-toggle="tab" data-bs-target="#results">Resultados <span class="badge bg-secondary ms-1"><?= $stats['total'] ?></span></button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending">Pendientes</button>
        </li>
    </ul>

    <div class="tab-content" id="reportTabsContent">
        <div class="tab-pane fade show active" id="results">
            
            <div class="row mb-4 g-4">
                <div class="col-md-6">
                    <div class="stat-card primary">
                        <div class="stat-label">Promedio General</div>
                        <div class="stat-val"><?= $promedio ?><small style="font-size:1.5rem;opacity:0.8">/100</small></div>
                        <i class="fas fa-chart-line stat-icon"></i>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card success">
                        <div class="stat-label">Estudiantes Aprobados</div>
                        <div class="stat-val"><?= $stats['aprobados'] ?><small style="font-size:1.5rem;opacity:0.8"> / <?= $stats['total'] ?></small></div>
                        <i class="fas fa-user-check stat-icon"></i>
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
                                <th>Puntos Reales</th>
                                <th class="text-center">Muestra</th>
                                <th class="text-center">Nota / 100</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resultados)): ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted">No hay resultados.</td></tr>
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
                                        <div class="small"><i class="fas fa-venus-mars text-muted me-1"></i> <?= htmlspecialchars($row['genero'] ?? 'N/A') ?></div>
                                        <div class="small"><i class="fas fa-birthday-cake text-muted me-1"></i> <?= htmlspecialchars($row['edad'] ?? 'N/A') ?> años</div>
                                        <div class="small"><i class="fas fa-chalkboard text-muted me-1"></i> Paralelo: <?= htmlspecialchars($row['paralelo'] ?? 'N/A') ?></div>
                                        <div class="small"><i class="fas fa-map-marker-alt text-muted me-1"></i> <?= htmlspecialchars($row['residencia'] ?? 'N/A') ?></div>
                                        <?php if (!empty($row['discapacidad']) && $row['discapacidad'] !== 'Ninguna'): ?>
                                            <div class="small"><i class="fas fa-wheelchair text-muted me-1"></i> <?= htmlspecialchars($row['discapacidad']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-secondary small"><?= htmlspecialchars($row['quiz_titulo']) ?></div>
                                        <div class="small text-muted"><?= date('d/m/Y', strtotime($row['fecha_realizacion'])) ?></div>
                                        <?php if (!empty($row['es_muestra'])): ?>
                                            <span class="badge rounded-pill bg-secondary mt-1 badge-muestra">Muestra</span>
                                        <?php endif; ?>
                                        <?php if ($row['nivel_integridad'] !== 'limpio'): ?>
                                            <div class="text-danger small mt-1"><i class="fas fa-exclamation-triangle"></i> <?= $row['intentos_tab_switch'] ?> salidas</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted" id="pts-<?= (int)$row['id'] ?>"><?= $row['puntos_obtenidos'] ?> / <?= isset($row['puntos_totales_quiz']) ? (int)$row['puntos_totales_quiz'] : 250 ?></td>
                                    <td class="text-center">
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input chk-muestra" type="checkbox" data-resultado-id="<?= (int)$row['id'] ?>" <?= !empty($row['es_muestra']) ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?= getScoreBadge($row['nota_sobre_100']) ?> fs-6"><?= $row['nota_sobre_100'] ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-outline-primary" onclick="verJustificaciones(<?= $row['id'] ?>)">
                                            <i class="fas fa-comment-alt me-1"></i> Justificaciones
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary ms-2" onclick="verCalificacion(<?= $row['id'] ?>)">
                                            <i class="fas fa-check-double me-1"></i> Calificar
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

        <div class="tab-pane fade" id="pending">
            <?php if (!$quiz_id): ?>
                <div class="text-center py-5"><h5>Selecciona un examen primero</h5></div>
            <?php else: ?>
                <div class="card-custom p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Estudiante</th>
                                    <th>Email</th>
                                    <th class="text-end pe-4">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendientes)): ?>
                                    <tr><td colspan="3" class="text-center py-5">Todos completaron el examen.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendientes as $p): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= htmlspecialchars($p['nombre']) ?></td>
                                        <td><?= htmlspecialchars($p['email']) ?></td>
                                        <td class="text-end pe-4">
                                            <a href="mailto:<?= htmlspecialchars($p['email']) ?>" class="btn btn-sm btn-outline-primary">Recordar</a>
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

    <!-- Analytics Section -->
    <?php if (!empty($stats_por_quiz)): ?>
    <div class="mt-5">
        <h5 class="fw-bold mb-4">
            <i class="fas fa-chart-bar me-2" style="color: #667eea;"></i>
            Análisis de Rendimiento por Materia
        </h5>
        
        <div class="row g-4">
            <div class="col-md-8">
                <div class="card-custom p-4">
                    <h6 class="fw-bold mb-4 text-secondary">
                        <i class="fas fa-signal me-2"></i>Promedio de Notas por Examen
                    </h6>
                    <canvas id="chartPromedios" height="80"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-custom p-4">
                    <h6 class="fw-bold mb-4 text-secondary">
                        <i class="fas fa-percentage me-2"></i>Tasa de Aprobación
                    </h6>
                    <canvas id="chartAprobacion" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="justificacionesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-comment-dots me-2 text-primary"></i>Justificaciones</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="justificacionesBody">
                <div class="text-center py-4 text-muted">Cargando...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

        <div class="modal fade" id="calificarModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-check-double me-2 text-primary"></i>Calificar Examen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="calificarBody">
                        <div class="text-center py-4 text-muted">Cargando...</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
            </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function verJustificaciones(resultadoId) {
    const modalEl = document.getElementById('justificacionesModal');
    const bodyEl = document.getElementById('justificacionesBody');
    bodyEl.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border text-primary"></div><p>Cargando justificaciones...</p></div>';
    
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    // Llamada AJAX al archivo auxiliar
    fetch('detalles_justificaciones.php?resultado_id=' + resultadoId)
        .then(res => res.text())
        .then(html => {
            bodyEl.innerHTML = html;
        })
        .catch(() => {
            bodyEl.innerHTML = '<div class="alert alert-danger">Error al cargar datos.</div>';
        });
}

function verCalificacion(resultadoId) {
    const modalEl = document.getElementById('calificarModal');
    const bodyEl = document.getElementById('calificarBody');
    bodyEl.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border text-primary"></div><p>Cargando preguntas...</p></div>';
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    fetch('get_calificar_respuestas.php?resultado_id=' + resultadoId)
        .then(res => res.text())
        .then(html => { bodyEl.innerHTML = html; })
        .catch(() => { bodyEl.innerHTML = '<div class="alert alert-danger">Error al cargar los datos de calificación.</div>'; });
}

function guardarCalificacion(resultadoId) {
    const bodyEl = document.getElementById('calificarBody');
    const form = bodyEl.querySelector('#formCalificacion');
    if (!form) return;
    // UI: deshabilitar botón y mostrar spinner
    const btn = bodyEl.querySelector('button.btn.btn-primary.btn-sm');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando...';
    }
    const fd = new FormData(form);
    fetch('guardar_calificacion.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) throw new Error(j.error || 'Error al guardar');
            // Actualizar puntaje en la tabla si existe el elemento
            const ptsEl = document.getElementById('pts-' + resultadoId);
            if (ptsEl && j.puntos_obtenidos !== undefined && j.puntos_totales !== undefined) {
                ptsEl.textContent = j.puntos_obtenidos + ' / ' + j.puntos_totales;
            }
            // Notificación visible (toast)
            if (window.showToast) {
                window.showToast('Calificación guardada exitosamente', 'success');
            } else {
                const ok = document.createElement('div');
                ok.className = 'alert alert-success';
                ok.textContent = 'Calificación guardada exitosamente';
                bodyEl.prepend(ok);
                setTimeout(()=>{ ok.remove(); }, 3000);
            }
        })
        .catch(err => {
            if (window.showToast) {
                window.showToast('No se pudo guardar la calificación: ' + err.message, 'danger');
            } else {
                const er = document.createElement('div');
                er.className = 'alert alert-danger';
                er.textContent = 'No se pudo guardar la calificación: ' + err.message;
                bodyEl.prepend(er);
                setTimeout(()=>{ er.remove(); }, 5000);
            }
        })
        .finally(() => {
            // Restaurar botón
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });
}

// Toggle inline "Muestra" switch
document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('chk-muestra')) return;
        const cb = e.target;
        const rid = cb.getAttribute('data-resultado-id');
        if (!rid) return;
        const desired = cb.checked;
        const fd = new FormData();
        fd.append('resultado_id', rid);
        fd.append('es_muestra', desired ? '1' : '0');
        fetch('actualizar_muestra.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!j.ok) throw new Error(j.error || 'Error al actualizar');
                // Update badge in the Examen cell
                const row = cb.closest('tr');
                if (!row) return;
                // Examen está en la 3ra celda (1: Estudiante, 2: Demografía, 3: Examen)
                const examCell = row.querySelector('td:nth-child(3)');
                if (!examCell) return;
                let badge = examCell.querySelector('.badge-muestra');
                if (desired) {
                        if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'badge rounded-pill bg-secondary mt-1 badge-muestra';
                                badge.textContent = 'Muestra';
                                examCell.appendChild(badge);
                        }
                } else if (badge) {
                        badge.remove();
                }
            })
            .catch(err => {
                // revert state and notify
                cb.checked = !desired;
                alert('No se pudo actualizar la marca de muestra: ' + err.message);
            });
});

// ============================================
// GRÁFICOS CON CHART.JS
// ============================================
<?php if (!empty($stats_por_quiz)): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Datos desde PHP
    const statsData = <?= json_encode($stats_por_quiz) ?>;
    
    // Preparar arrays para los gráficos
    const materias = Object.keys(statsData);
    const promedios = materias.map(m => statsData[m].promedio);
    const totales = materias.map(m => statsData[m].total);
    const aprobados = materias.map(m => statsData[m].aprobados);
    
    // Paleta de colores moderna
    const gradientColors = [
        'rgba(102, 126, 234, 0.8)',
        'rgba(118, 75, 162, 0.8)',
        'rgba(12, 235, 235, 0.8)',
        'rgba(32, 227, 178, 0.8)',
        'rgba(240, 147, 251, 0.8)',
        'rgba(245, 87, 108, 0.8)',
        'rgba(79, 172, 254, 0.8)',
        'rgba(0, 242, 254, 0.8)'
    ];
    
    // ============================================
    // GRÁFICO 1: Promedios por Examen (Barras)
    // ============================================
    const ctxPromedios = document.getElementById('chartPromedios');
    if (ctxPromedios) {
        new Chart(ctxPromedios, {
            type: 'bar',
            data: {
                labels: materias,
                datasets: [{
                    label: 'Promedio (/100)',
                    data: promedios,
                    backgroundColor: gradientColors,
                    borderColor: gradientColors.map(c => c.replace('0.8', '1')),
                    borderWidth: 2,
                    borderRadius: 10,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                const materia = context.label;
                                const promedio = context.parsed.y;
                                const total = totales[context.dataIndex];
                                return [
                                    `Promedio: ${promedio}/100`,
                                    `Estudiantes: ${total}`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '/100';
                            },
                            font: { weight: 600 }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: { weight: 600, size: 11 },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
    
    // ============================================
    // GRÁFICO 2: Tasa de Aprobación Global (Dona)
    // ============================================
    const totalAprobados = aprobados.reduce((a, b) => a + b, 0);
    const totalEstudiantes = totales.reduce((a, b) => a + b, 0);
    const totalReprobados = totalEstudiantes - totalAprobados;
    const tasaAprobacionGlobal = ((totalAprobados / totalEstudiantes) * 100).toFixed(1);
    
    const ctxAprobacion = document.getElementById('chartAprobacion');
    if (ctxAprobacion) {
        new Chart(ctxAprobacion, {
            type: 'doughnut',
            data: {
                labels: ['Aprobados', 'Reprobados'],
                datasets: [{
                    data: [totalAprobados, totalReprobados],
                    backgroundColor: [
                        'rgba(32, 227, 178, 0.9)',
                        'rgba(245, 87, 108, 0.9)'
                    ],
                    borderColor: ['#fff', '#fff'],
                    borderWidth: 3,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12, weight: 600 },
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const label = context.label;
                                const value = context.parsed;
                                const percentage = ((value / totalEstudiantes) * 100).toFixed(1);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '70%'
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function(chart) {
                    const ctx = chart.ctx;
                    ctx.save();
                    const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                    const centerY = (chart.chartArea.top + chart.chartArea.bottom) / 2;
                    
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    
                    ctx.font = 'bold 28px Inter';
                    ctx.fillStyle = '#1a202c';
                    ctx.fillText(tasaAprobacionGlobal + '%', centerX, centerY - 10);
                    
                    ctx.font = '600 12px Inter';
                    ctx.fillStyle = '#718096';
                    ctx.fillText('Aprobación', centerX, centerY + 15);
                    ctx.restore();
                }
            }]
        });
    }
});
<?php endif; ?>
</script>
<style>
/* Toast container positioning */
#globalToastContainer { position: fixed; top: 16px; right: 16px; z-index: 1080; }
</style>
<div id="globalToastContainer" aria-live="polite" aria-atomic="true"></div>
<script>
// Utilidad: mostrar toast Bootstrap (o fallback) en la esquina superior derecha
window.showToast = function(message, type = 'success') {
        const container = document.getElementById('globalToastContainer');
        if (!container) return alert(message);
        const color = type === 'danger' ? 'bg-danger text-white' : type === 'warning' ? 'bg-warning text-dark' : 'bg-success text-white';
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="toast align-items-center ${color}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>`;
        const toastEl = wrapper.firstElementChild;
        container.appendChild(toastEl);
        try {
                const toast = new bootstrap.Toast(toastEl);
                toast.show();
                toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        } catch (e) {
                // Fallback si Bootstrap.Toast no está disponible
                const alt = document.createElement('div');
                alt.className = 'alert ' + (type === 'danger' ? 'alert-danger' : type === 'warning' ? 'alert-warning' : 'alert-success');
                alt.textContent = message;
                container.appendChild(alt);
                setTimeout(() => alt.remove(), 3000);
                toastEl.remove();
        }
};
</script>

</body>
</html>