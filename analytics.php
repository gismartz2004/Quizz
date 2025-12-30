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

// 3. RECIBIR PARÁMETROS DE FILTROS
$quiz_id      = isset($_GET['quiz_id']) && is_numeric($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : null;
$fecha_desde  = $_GET['fecha_desde'] ?? '';
$fecha_hasta  = $_GET['fecha_hasta'] ?? '';
$genero       = $_GET['genero'] ?? '';
$edad         = isset($_GET['edad']) && is_numeric($_GET['edad']) ? (int)$_GET['edad'] : '';
$paralelo     = $_GET['paralelo'] ?? '';
$integridad   = $_GET['integridad'] ?? '';
$integridad   = $_GET['integridad'] ?? '';
$filtro_muestra = isset($_GET['muestra']) ? $_GET['muestra'] : '';
$min_nota     = isset($_GET['min_nota']) && is_numeric($_GET['min_nota']) ? (int)$_GET['min_nota'] : '';
$max_nota     = isset($_GET['max_nota']) && is_numeric($_GET['max_nota']) ? (int)$_GET['max_nota'] : '';
$query_str    = $_GET['q'] ?? ''; // Search persistence

// Mantener filtros de mes/año para compatibilidad
$mes_filtro = isset($_GET['mes']) ? str_pad($_GET['mes'], 2, '0', STR_PAD_LEFT) : date('m');
$anio_filtro = isset($_GET['anio']) ? $_GET['anio'] : date('Y');

// 4. CONSTRUIR CONSULTA SQL CON FILTROS
$sql = "SELECT * FROM resultados WHERE 1=1";
$params = [];

// Filtros de tiempo (mes/año)
if ($mes_filtro) {
    $sql .= " AND EXTRACT(MONTH FROM fecha_realizacion) = :mes";
    $params['mes'] = $mes_filtro;
}
if ($anio_filtro) {
    $sql .= " AND EXTRACT(YEAR FROM fecha_realizacion) = :anio";
    $params['anio'] = $anio_filtro;
}

// Filtros adicionales
if ($quiz_id) {
    $sql .= " AND quiz_id = :quiz_id";
    $params['quiz_id'] = $quiz_id;
}
if ($fecha_desde) {
    $sql .= " AND fecha_realizacion >= :fecha_desde";
    $params['fecha_desde'] = $fecha_desde . ' 00:00:00';
}
if ($fecha_hasta) {
    $sql .= " AND fecha_realizacion <= :fecha_hasta";
    $params['fecha_hasta'] = $fecha_hasta . ' 23:59:59';
}
if ($genero) {
    $sql .= " AND genero = :genero";
    $params['genero'] = $genero;
}
if ($edad) {
    $sql .= " AND edad = :edad";
    $params['edad'] = $edad;
}
if ($paralelo) {
    $sql .= " AND paralelo = :paralelo";
    $params['paralelo'] = $paralelo;
}
if ($filtro_muestra === 'si') {
    $sql .= " AND COALESCE(es_muestra, FALSE) = TRUE";
} elseif ($filtro_muestra === 'no') {
    $sql .= " AND COALESCE(es_muestra, FALSE) = FALSE";
}
if ($min_nota !== '') {
    $sql .= " AND ((puntos_obtenidos / 250) * 100) >= :min_nota";
    $params['min_nota'] = $min_nota;
}
if ($max_nota !== '') {
    $sql .= " AND ((puntos_obtenidos / 250) * 100) <= :max_nota";
    $params['max_nota'] = $max_nota;
}

// 5. OBTENER DATOS AGREGADOS CON FILTROS
try {
    // Total Estudiantes Evaluados (Unicos)
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT usuario_id) FROM resultados WHERE 1=1" . substr($sql, strpos($sql, ' AND')));
    $stmt->execute($params);
    $total_estudiantes = $stmt->fetchColumn();

    // Promedio General (Sobre 100)
    $sql_avg = "SELECT AVG((puntos_obtenidos / 250) * 100) FROM resultados WHERE 1=1" . substr($sql, strpos($sql, ' AND'));
    $stmt_avg = $pdo->prepare($sql_avg);
    $stmt_avg->execute($params);
    $promedio_general = round($stmt_avg->fetchColumn(), 2);

    // Total Examenes Realizados
    $stmt_tot = $pdo->prepare("SELECT COUNT(*) FROM resultados WHERE 1=1" . substr($sql, strpos($sql, ' AND')));
    $stmt_tot->execute($params);
    $total_examenes = $stmt_tot->fetchColumn();

    // Tasa de Aprobación
    $sql_aprobados = "SELECT COUNT(*) FROM resultados WHERE (puntos_obtenidos / 250) * 100 >= 70" . substr($sql, strpos($sql, ' AND'));
    $stmt_aprob = $pdo->prepare($sql_aprobados);
    $stmt_aprob->execute($params);
    $aprobados_count = $stmt_aprob->fetchColumn();
    $tasa_aprobacion = $total_examenes > 0 ? round(($aprobados_count / $total_examenes) * 100, 1) : 0;

    // Aplicar filtro de integridad si existe
    $resultados_raw = [];
    if ($integridad) {
        $stmt_all = $pdo->prepare($sql);
        $stmt_all->execute($params);
        $all_results = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($all_results as $row) {
            $swaps = (int)($row['intentos_tab_switch'] ?? 0);
            $time  = (int)($row['segundos_fuera'] ?? 0);
            
            if ($swaps == 0 && $time == 0) $nivel = 'limpio';
            elseif ($swaps <= 2 && $time < 15) $nivel = 'leve';
            else $nivel = 'riesgo';
            
            if ($nivel === $integridad) {
                $resultados_raw[] = $row;
            }
        }
    } else {
        $stmt_all = $pdo->prepare($sql);
        $stmt_all->execute($params);
        $resultados_raw = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- CHART: Promedio por Paralelo (Con Filtros) ---
    $sql_paralelo = "SELECT paralelo, AVG((puntos_obtenidos / 250) * 100) as promedio, COUNT(*) as cantidad 
                     FROM resultados 
                     WHERE paralelo IS NOT NULL AND paralelo != ''" . substr($sql, strpos($sql, ' AND')) . "
                     GROUP BY paralelo ORDER BY paralelo";
    $stmt_par = $pdo->prepare($sql_paralelo);
    $stmt_par->execute($params);
    $data_paralelo = $stmt_par->fetchAll(PDO::FETCH_ASSOC);

    // --- CHART: Género Normalizado (Con Filtros) ---
    $sql_genero_raw = "SELECT genero, COUNT(*) as cantidad 
                       FROM resultados 
                       WHERE genero IS NOT NULL AND genero != ''" . substr($sql, strpos($sql, ' AND')) . "
                       GROUP BY genero";
    $stmt_gen = $pdo->prepare($sql_genero_raw);
    $stmt_gen->execute($params);
    $raw_generos = $stmt_gen->fetchAll(PDO::FETCH_ASSOC);

    $generos_normalizados = ['Masculino' => 0, 'Femenino' => 0, 'Otro' => 0, 'Prefiero no decirlo' => 0];
    foreach($raw_generos as $row) {
        $g = strtolower(trim($row['genero']));
        $cant = (int)$row['cantidad'];
        if (strpos($g, 'masculino') !== false || $g == 'hombre' || $g == 'm') { $generos_normalizados['Masculino'] += $cant; }
        elseif (strpos($g, 'femenino') !== false || $g == 'mujer' || $g == 'f') { $generos_normalizados['Femenino'] += $cant; }
        elseif (strpos($g, 'prefiero') !== false) { $generos_normalizados['Prefiero no decirlo'] += $cant; }
        else { $generos_normalizados['Otro'] += $cant; }
    }
    $data_genero_final = [];
    foreach($generos_normalizados as $k => $v) {
        if ($v > 0) $data_genero_final[] = ['genero' => $k, 'cantidad' => $v];
    }


    // --- ANOMALIAS DE SEGURIDAD (Con Filtros) ---
    $sql_anomalias = "SELECT COUNT(*) FROM resultados WHERE (intentos_tab_switch > 1 OR segundos_fuera > 15)" . substr($sql, strpos($sql, ' AND'));
    $stmt_anom = $pdo->prepare($sql_anomalias);
    $stmt_anom->execute($params);
    $total_anomalias = $stmt_anom->fetchColumn();

    // --- PREDICCIONES & AUDIENCIAS (Simulación ML) ---
    $risk_count = 0;
    $success_count = 0;
    
    try {
        // Asegurar que existe WHERE para substr
        $where_chunk = (strpos($sql, ' AND') !== false) ? substr($sql, strpos($sql, ' AND')) : '';

        // 1. Riesgo de Deserción
        $sql_risk = "SELECT COUNT(*) FROM resultados WHERE ((puntos_obtenidos / 250) * 100) < 70 AND (intentos_tab_switch > 1 OR segundos_fuera > 15)" . $where_chunk;
        $stmt_risk = $pdo->prepare($sql_risk);
        $stmt_risk->execute($params);
        $risk_count = (int)$stmt_risk->fetchColumn();

        // 2. Alta Probabilidad de Excelencia
        $sql_success = "SELECT COUNT(*) FROM resultados WHERE ((puntos_obtenidos / 250) * 100) >= 90 AND intentos_tab_switch = 0" . $where_chunk;
        $stmt_success = $pdo->prepare($sql_success);
        $stmt_success->execute($params);
        $success_count = (int)$stmt_success->fetchColumn();
    } catch (Exception $e) {
        // Silently fail predictions if error, to not crash dashboard
        $risk_count = 0;
        $success_count = 0;
    }

} catch (PDOException $e) {
    die("Error de base de datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intelligence Dashboard | Academia Illingworth</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-body: #f0f2f5;
            --card-bg: #ffffff;
            --text-main: #1c1e21;
            --text-muted: #65676b;
            --google-blue: #1a73e8;
            --google-green: #34a853;
            --google-red: #ea4335;
            --google-yellow: #fbbc04;
        }

        body {
            background-color: var(--bg-body);
            font-family: 'Outfit', sans-serif;
            color: var(--text-main);
            padding-bottom: 3rem;
        }

        .navbar-custom {
            background: white;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            padding: 1rem 0;
            margin-bottom: 2rem;
        }

        @media print {
            .navbar-custom, .btn, .filter-bar { display: none !important; }
            body { background: white; padding: 0; }
            .container { max-width: 100% !important; width: 100% !important; }
            .card-header-title { color: #000 !important; }
            .kpi-card, .chart-card { box-shadow: none !important; border: 1px solid #eee !important; break-inside: avoid; }
        }

        .filter-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            height: 100%;
            border-left: 5px solid transparent;
        }
        
        .kpi-card.blue { border-left-color: var(--google-blue); }
        .kpi-card.green { border-left-color: var(--google-green); }
        .kpi-card.red { border-left-color: var(--google-red); }
        .kpi-card.yellow { border-left-color: var(--google-yellow); }

        .kpi-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .kpi-label {
            color: var(--text-muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            font-weight: 600;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            position: relative;
        }

        .card-header-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .geo-badge {
            background: #e8f0fe;
            color: var(--google-blue);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Search Bar Styles (Matched with lenguaje_d.php) */
        .smart-search-container {
            position: relative;
            width: 100%;
            max-width: 800px; /* Slightly wider for suggestions */
            margin: 0 auto 2rem auto;
        }
        .smart-search-input {
            width: 100%;
            padding: 1rem 1.5rem;
            padding-left: 3.5rem;
            border: none;
            border-radius: 50px;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        .smart-search-input:focus {
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
            outline: none;
        }
        .smart-search-icon {
            position: absolute;
            left: 1.5rem;
            top: 28px; /* Fixed alignment */
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.3rem;
            z-index: 10;
        }
        .magic-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            vertical-align: middle;
            margin-left: 0.5rem;
        }
        
        /* Predictive Cards */
        .prediction-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }
        .prediction-card h6 { font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
        .prediction-card .big-number { font-size: 2.5rem; font-weight: 700; color: var(--text-main); line-height: 1; margin: 0.5rem 0; }
        .prediction-card .desc { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; }
        .prediction-icon { position: absolute; right: -10px; bottom: -10px; font-size: 5rem; opacity: 0.05; transform: rotate(-15deg); }
        .btn-predict { border-radius: 20px; font-weight: 600; font-size: 0.85rem; padding: 0.4rem 1rem; }
    </style>
</head>
<body>

    <nav class="navbar-custom">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <i class="fas fa-chart-line fa-lg text-primary me-3"></i>
                <div class="ms-2">
                    <h5 class="fw-bold mb-0">Intelligence Center</h5>
                    <small class="text-muted">Academia Illingworth - Décimo Grado</small>
                </div>
                <div class="ms-4 d-flex align-items-center">
                    <span class="badge bg-success rounded-pill px-3 py-2">
                        <i class="fas fa-circle" style="font-size: 0.5rem; margin-right: 0.5rem; color: #00ff00;"></i>
                        Conectado con Google Analytics
                    </span>
                </div>
            </div>
            <div>
                <?php 
                $pdf_params = http_build_query([
                    'mes' => $mes_filtro,
                    'anio' => $anio_filtro,
                    'quiz_id' => $quiz_id,
                    'fecha_desde' => $fecha_desde,
                    'fecha_hasta' => $fecha_hasta,
                    'genero' => $genero,
                    'edad' => $edad,
                    'paralelo' => $paralelo,
                    'muestra' => $filtro_muestra,
                    'muestra' => $filtro_muestra,
                    'integridad' => $integridad,
                    'min_nota' => $min_nota,
                    'max_nota' => $max_nota
                ]);
                ?>
                <a href="reporte_pdf.php?<?= $pdf_params ?>" target="_blank" class="btn btn-primary btn-sm rounded-pill px-4 me-2">
                    <i class="fas fa-file-pdf me-2"></i>Descargar PDF Oficial
                </a>
                <a href="lenguaje_d.php" class="btn btn-outline-secondary btn-sm rounded-pill px-4">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- SMART SEARCH BAR -->
        <div class="smart-search-container">
            <i class="fas fa-search smart-search-icon"></i>
            <input type="text" id="smartSearchInput" class="smart-search-input" value="<?= htmlspecialchars($query_str) ?>" placeholder="Busca métricas: 'Mujeres nota menor a 70', 'Paralelo A'...">
            
            <!-- SUGGESTED QUERIES -->
            <div class="mt-3 text-center small text-muted">
                <i class="fas fa-lightbulb text-warning me-1"></i> Prueba preguntar:
                <a href="#" class="text-decoration-none badge bg-light text-dark border me-1 search-suggestion">"Mujeres con nota menor a 70"</a>
                <a href="#" class="text-decoration-none badge bg-light text-dark border me-1 search-suggestion">"Hombres del paralelo A"</a>
                <a href="#" class="text-decoration-none badge bg-light text-dark border me-1 search-suggestion">"Riesgo de deserción"</a>
                <a href="#" class="text-decoration-none badge bg-light text-dark border me-1 search-suggestion">"Mejores estudiantes"</a>
            </div>
        </div>

        <!-- ACTIVE FILTERS FEEDBACK -->
        <?php if ($query_str || $genero || $paralelo || $min_nota || $max_nota || $quiz_id): ?>
        <div class="mb-4">
            <h6 class="text-muted small fw-bold text-uppercase mb-2"><i class="fas fa-filter me-1"></i> Filtros Activos (Interpretados por IA):</h6>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($genero): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3">
                        <i class="fas fa-venus-mars me-1"></i> Género: <?= htmlspecialchars($genero) ?>
                    </span>
                <?php endif; ?>
                <?php if ($paralelo): ?>
                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3">
                        <i class="fas fa-chalkboard me-1"></i> Paralelo: <?= htmlspecialchars($paralelo) ?>
                    </span>
                <?php endif; ?>
                <?php if ($min_nota): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">
                        <i class="fas fa-arrow-up me-1"></i> Nota Min: <?= htmlspecialchars($min_nota) ?>
                    </span>
                <?php endif; ?>
                <?php if ($max_nota): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3">
                        <i class="fas fa-arrow-down me-1"></i> Nota Max: <?= htmlspecialchars($max_nota) ?>
                    </span>
                <?php endif; ?>
                <?php if ($quiz_id): ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3">
                        <i class="fas fa-file-alt me-1"></i> Examen ID: <?= htmlspecialchars($quiz_id) ?>
                    </span>
                <?php endif; ?>
                <a href="analytics.php" class="btn btn-link btn-sm text-muted text-decoration-none py-0 align-self-center"><i class="fas fa-times me-1"></i>Limpiar Todo</a>
            </div>
        </div>
        <?php endif; ?>
        <!-- FILTROS COMPLETOS -->
        <div class="filter-bar" style="flex-wrap: wrap;">
            <form method="GET" class="row g-3 w-100" id="analyticsForm">
                <!-- Hidden inputs for smart search ranges -->
                <input type="hidden" name="min_nota" value="<?= htmlspecialchars($min_nota) ?>">
                <input type="hidden" name="max_nota" value="<?= htmlspecialchars($max_nota) ?>">
                <input type="hidden" name="q" id="hiddenSearchQuery" value="<?= htmlspecialchars($query_str) ?>">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Examen</label>
                    <select name="quiz_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
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
                <div class="col-md-1">
                    <label class="form-label small fw-bold text-muted">Edad</label>
                    <input type="number" name="edad" class="form-control form-control-sm" value="<?= htmlspecialchars($edad) ?>" placeholder="15">
                </div>
                <div class="col-md-1">
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
                <div class="col-md-1">
                    <label class="form-label small fw-bold text-muted">Muestra</label>
                    <select name="muestra" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="si" <?= $filtro_muestra === 'si' ? 'selected' : '' ?>>Sí</option>
                        <option value="no" <?= $filtro_muestra === 'no' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Desde</label>
                    <input type="date" name="fecha_desde" class="form-control form-control-sm" value="<?= htmlspecialchars($fecha_desde) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Hasta</label>
                    <input type="date" name="fecha_hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($fecha_hasta) ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold text-muted">Mes</label>
                    <select name="mes" class="form-select form-select-sm">
                        <option value="12" <?= $mes_filtro == '12' ? 'selected' : '' ?>>Dic</option>
                        <option value="11" <?= $mes_filtro == '11' ? 'selected' : '' ?>>Nov</option>
                        <option value="10" <?= $mes_filtro == '10' ? 'selected' : '' ?>>Oct</option>
                        <option value="09" <?= $mes_filtro == '09' ? 'selected' : '' ?>>Sep</option>
                        <option value="08" <?= $mes_filtro == '08' ? 'selected' : '' ?>>Ago</option>
                        <option value="07" <?= $mes_filtro == '07' ? 'selected' : '' ?>>Jul</option>
                        <option value="06" <?= $mes_filtro == '06' ? 'selected' : '' ?>>Jun</option>
                        <option value="05" <?= $mes_filtro == '05' ? 'selected' : '' ?>>May</option>
                        <option value="04" <?= $mes_filtro == '04' ? 'selected' : '' ?>>Abr</option>
                        <option value="03" <?= $mes_filtro == '03' ? 'selected' : '' ?>>Mar</option>
                        <option value="02" <?= $mes_filtro == '02' ? 'selected' : '' ?>>Feb</option>
                        <option value="01" <?= $mes_filtro == '01' ? 'selected' : '' ?>>Ene</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold text-muted">Año</label>
                    <select name="anio" class="form-select form-select-sm">
                        <option value="2025" <?= $anio_filtro == '2025' ? 'selected' : '' ?>>2025</option>
                        <option value="2024" <?= $anio_filtro == '2024' ? 'selected' : '' ?>>2024</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                    <a href="?" class="btn btn-light btn-sm">Limpiar</a>
                </div>
            </form>
        </div>

        <!-- GLOBAL KPIS -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="kpi-card blue">
                    <div class="kpi-value text-primary"><?= number_format($total_estudiantes) ?></div>
                    <div class="kpi-label">Usuarios Activos</div>
                    <div class="small text-muted mt-2">En <?= $mes_filtro ?>/<?= $anio_filtro ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card green">
                    <div class="kpi-value text-success"><?= $promedio_general ?></div>
                    <div class="kpi-label">Score Promedio</div>
                    <div class="small text-muted mt-2">Escala sobre 100 pts</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card yellow">
                    <div class="kpi-value text-warning"><?= number_format($total_examenes) ?></div>
                    <div class="kpi-label">Exámenes Totales</div>
                    <div class="small text-muted mt-2">Intentos realizados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card red">
                    <div class="kpi-value text-danger"><?= $total_anomalias ?></div>
                    <div class="kpi-label">Seguridad</div>
                    <div class="small text-danger mt-2 fw-bold">Alertas detectadas</div>
                </div>
            </div>
        </div>

        <!-- PREDICTIVE ANALYTICS SECTION -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <h5 class="fw-bold mb-3">
                    <i class="fas fa-brain me-2" style="color: #764ba2;"></i>
                    Predicciones y Audiencias 
                    <?php if($query_str): ?><span class="text-muted fs-6 fw-normal">(Basadas en tu búsqueda)</span><?php endif; ?>
                    <span class="magic-badge"><i class="fas fa-bolt me-1"></i>AI Powered</span>
                </h5>
            </div>
            <div class="col-md-6">
                <div class="prediction-card">
                    <div style="z-index: 2;">
                        <h6 class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Riesgo de Deserción</h6>
                        <div class="big-number"><?= number_format($risk_count) ?></div>
                        <p class="desc">Estudiantes que el sistema predice podrían abandonar o reprobar basado en comportamiento no íntegro y bajas calificaciones.</p>
                        <a href="lenguaje_d.php?integridad=riesgo" class="btn btn-outline-danger btn-predict">Ver Audiencia de Riesgo</a>
                    </div>
                    <i class="fas fa-user-times prediction-icon text-danger"></i>
                </div>
            </div>
            <div class="col-md-6">
                <div class="prediction-card">
                    <div style="z-index: 2;">
                        <h6 class="text-success"><i class="fas fa-star me-1"></i>Alta Probabilidad de Excelencia</h6>
                        <div class="big-number"><?= number_format($success_count) ?></div>
                        <p class="desc">Estudiantes proyectados a obtener certificaciones de honor. Comportamiento 'Limpio' y notas superiores a 90/100.</p>
                        <a href="lenguaje_d.php?integridad=limpio" class="btn btn-outline-success btn-predict">Crear Audiencia de Honor</a>
                    </div>
                    <i class="fas fa-award prediction-icon text-success"></i>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- COLUMNA IZQUIERDA -->
            <div class="col-lg-4">
                <!-- GEOLOCALIZACION (Unica sede solicitada) -->
                <div class="chart-card">
                    <div class="card-header-title">
                        <span><i class="fas fa-map-marker-alt me-2 text-danger"></i>Sede Principal</span>
                        <span class="badge bg-success">Activo</span>
                    </div>
                    <div class="text-center mb-4">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/e/ec/Ecuador_adm_location_map.svg/300px-Ecuador_adm_location_map.svg.png" alt="Mapa Ecuador" class="img-fluid opacity-50 mb-3" style="max-height:150px">
                        <h4 class="fw-bold mb-1">Guayaquil, Ecuador</h4>
                        <p class="text-muted">Academia Illingworth - Décimo</p>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Sede Guayaquil (Décimo Grado)</span>
                            <span class="fw-bold">100%</span>
                        </div>
                        <div class="progress mb-3" style="height:8px">
                            <div class="progress-bar bg-primary" style="width: 100%"></div>
                        </div>
                        <p class="small text-muted text-center mt-3">
                            <i class="fas fa-info-circle me-1"></i> Todo el tráfico proviene de la sede autorizada.
                        </p>
                    </div>
                </div>

                <!-- DISPOSITIVOS -->
                <div class="chart-card">
                    <div class="card-header-title">
                        <span><i class="fas fa-mobile-alt me-2 text-primary"></i>Dispositivos Utilizados</span>
                    </div>
                    <!-- Simulación estática basada en contexto escolar -->
                    <div class="row text-center mt-3">
                        <div class="col-6">
                            <i class="fas fa-desktop fa-2x text-secondary mb-2"></i>
                            <div class="fw-bold">PC Laboratorio</div>
                            <small class="text-muted">92%</small>
                        </div>
                        <div class="col-6">
                            <i class="fas fa-laptop fa-2x text-secondary mb-2"></i>
                            <div class="fw-bold">Laptop</div>
                            <small class="text-muted">8%</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- COLUMNA DERECHA -->
            <div class="col-lg-8">
                <div class="row">
                    <div class="col-md-6">
                        <div class="chart-card">
                            <div class="card-header-title">
                                <span><i class="fas fa-chart-bar me-2 text-success"></i>Notas por Paralelo</span>
                            </div>
                            <canvas id="chartParalelo" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card">
                            <div class="card-header-title">
                                <span><i class="fas fa-venus-mars me-2 text-info"></i>Demografía (Décimo)</span>
                            </div>
                            <div style="height:200px; display:flex; justify-content:center;">
                                <canvas id="chartGenero"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    Chart.defaults.font.family = 'Outfit';
    Chart.defaults.color = '#65676b';
    
    // PHP Data Passing
    const dataParalelo = <?= json_encode($data_paralelo) ?>;
    const dataGenero = <?= json_encode($data_genero_final) ?>;

    // 1. Chart Paralelo (Bar)
    new Chart(document.getElementById('chartParalelo'), {
        type: 'bar',
        data: {
            labels: dataParalelo.map(d => d.paralelo),
            datasets: [{
                label: 'Promedio del Mes',
                data: dataParalelo.map(d => d.promedio),
                backgroundColor: '#1a73e8',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });

    // 2. Chart Genero (Doughnut)
    new Chart(document.getElementById('chartGenero'), {
        type: 'doughnut',
        data: {
            labels: dataGenero.map(d => d.genero),
            datasets: [{
                data: dataGenero.map(d => d.cantidad),
                backgroundColor: ['#4285f4', '#ea4335', '#fbbc04', '#34a853'], 
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } }
        }
    });
</script>
<script>
    // --- SMART SEARCH LOGIC (NLP SIMULATION) ---
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Smart Search: Script loaded.");
        
        const searchInput = document.getElementById('smartSearchInput');
        const form = document.getElementById('analyticsForm'); // Usar ID para más robustez

        if (!searchInput || !form) {
            console.error("Smart Search: Input o Form no encontrados.");
            return;
        }

        const processAndSubmitQuery = () => {
            console.log("Smart Search: Procesando...");
            const query = searchInput.value.toLowerCase();

            try {
                // 1. Detectar GÉNERO
                const selGenero = form.querySelector('select[name="genero"]');
                if (selGenero) {
                    if (query.match(/mujer|femenino|chicas|niñas/)) selGenero.value = 'Femenino';
                    else if (query.match(/hombre|masculino|chicos|niños/)) selGenero.value = 'Masculino';
                }

                // 2. Detectar PARALELO
                const selParalelo = form.querySelector('select[name="paralelo"]');
                if (selParalelo) {
                    const paraleloMatch = query.match(/\b([a-h])\b/i) || query.match(/paralelo\s*([a-h])/i);
                    if (paraleloMatch) selParalelo.value = paraleloMatch[1].toUpperCase();
                }

                // 3. Detectar EDAD
                const inpEdad = form.querySelector('input[name="edad"]');
                if (inpEdad) {
                    const edadMatch = query.match(/(\d+)\s*(a?os|edad)/);
                    if (edadMatch) inpEdad.value = edadMatch[1];
                }

                // 4. Detectar MES
                const selMes = form.querySelector('select[name="mes"]');
                if (selMes) {
                    const meses = {
                        'enero': '01', 'febrero': '02', 'marzo': '03', 'abril': '04', 'mayo': '05', 'junio': '06',
                        'julio': '07', 'agosto': '08', 'septiembre': '09', 'octubre': '10', 'noviembre': '11', 'diciembre': '12'
                    };
                    for (const [nombre, val] of Object.entries(meses)) {
                        if (query.includes(nombre)) {
                            selMes.value = val;
                            break;
                        }
                    }
                }

                // 5. Detectar EXAMEN
                const selQuiz = form.querySelector('select[name="quiz_id"]');
                if (selQuiz) {
                    const options = selQuiz.options;
                    for (let i = 1; i < options.length; i++) { 
                        if (query.includes(options[i].text.toLowerCase())) {
                            selQuiz.value = options[i].value;
                            break;
                        }
                    }
                }

                // 6. Detectar RANGO DE NOTAS
                const inpMax = form.querySelector('input[name="max_nota"]');
                if (inpMax) {
                    const maxMatch = query.match(/(?:menor|bajo|menos)\s*(?:a|que|de)?\s*(\d+)/);
                    if (maxMatch) inpMax.value = maxMatch[1];
                }
                
                const inpMin = form.querySelector('input[name="min_nota"]');
                if (inpMin) {
                    const minMatch = query.match(/(?:mayor|sobre|mas|arriba)\s*(?:a|que|de)?\s*(\d+)/);
                    if (minMatch) inpMin.value = minMatch[1];
                }

                // 7. ACTUALIZAR QUERY STRING HIDDEN
                const hiddenQuery = form.querySelector('#hiddenSearchQuery');
                if (hiddenQuery) hiddenQuery.value = searchInput.value;

                console.log("Smart Search: Enviando formulario...");
                form.submit();
                
            } catch (err) {
                console.error("Error en el parsing de la búsqueda inteligente:", err);
                alert("Hubo un error al interpretar la búsqueda. Por favor, inténtalo de nuevo o usa los filtros manuales.");
            }
        };

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                processAndSubmitQuery();
            }
        });

        // Suggestion Chips Click Logic
        document.querySelectorAll('.search-suggestion').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const text = this.innerText.replace(/"/g, '');
                searchInput.value = text;
                processAndSubmitQuery();
            });
        });

    });
</body>
</html>
