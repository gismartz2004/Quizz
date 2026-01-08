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

// Corregir filtro de edad para que esté vacío por defecto
$edad = '';
if (isset($_GET['edad']) && $_GET['edad'] !== '' && is_numeric($_GET['edad'])) {
    $edad = (int)$_GET['edad'];
}

$paralelo     = $_GET['paralelo'] ?? '';
$integridad   = $_GET['integridad'] ?? '';
// Nuevo filtro: mostrar solo exámenes marcados como muestra
// Nuevo filtro: mostrar solo exámenes marcados como muestra
$filtro_muestra = isset($_GET['muestra']) ? $_GET['muestra'] : '';
$min_nota       = isset($_GET['min_nota']) && is_numeric($_GET['min_nota']) ? (int)$_GET['min_nota'] : '';
$max_nota       = isset($_GET['max_nota']) && is_numeric($_GET['max_nota']) ? (int)$_GET['max_nota'] : '';

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

// Special handling for "Lengua y Literatura" - merge with "Preguntas Abiertas"
$merged_quiz_ids = [];
if ($quiz_id) {
    try {
        // Check if selected quiz is Lengua y Literatura
        $stmt_check = $pdo->prepare("SELECT titulo FROM quizzes WHERE id = :id");
        $stmt_check->execute(['id' => $quiz_id]);
        $titulo = $stmt_check->fetchColumn();
        
        if ($titulo && stripos($titulo, 'Lengua y Literatura') !== false) {
            // Find all related Lengua y Literatura quizzes
            $stmt_related = $pdo->prepare("SELECT id FROM quizzes WHERE titulo LIKE '%Lengua y Literatura%'");
            $stmt_related->execute();
            $merged_quiz_ids = $stmt_related->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($merged_quiz_ids) > 0) {
                // Build named placeholders
                $placeholders = [];
                foreach ($merged_quiz_ids as $idx => $qid) {
                    $key = "merged_quiz_$idx";
                    $placeholders[] = ":$key";
                    $params[$key] = $qid;
                }
                $sql .= " AND r.quiz_id IN (" . implode(',', $placeholders) . ")";
            }
        } else {
            // Normal single quiz filter
            $sql .= " AND r.quiz_id = :quiz_id";
            $params['quiz_id'] = $quiz_id;
        }
    } catch (PDOException $e) {
        // Fallback to normal behavior if check fails
        $sql .= " AND r.quiz_id = :quiz_id";
        $params['quiz_id'] = $quiz_id;
    }
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
// Filtros de notas (requiere cálculo en DB o filtro posterior, idealmente DB)
// Dado que la nota es calculada, usaremos la fórmula en el WHERE
if ($min_nota !== '') {
    $sql .= " AND ((r.puntos_obtenidos / 250.0) * 100) >= :min_nota";
    $params['min_nota'] = $min_nota;
}
if ($max_nota !== '') {
    $sql .= " AND ((r.puntos_obtenidos / 250.0) * 100) <= :max_nota";
    $params['max_nota'] = $max_nota;
}

// FIX: Capturar los filtros (WHERE) antes de agregar el ORDER BY, para no romper las queries de COUNT/AVG
$where_chunk = (strpos($sql, ' AND') !== false) ? substr($sql, strpos($sql, ' AND')) : '';

// ORDER BY: Si hay quizzes combinados, ordenar por estudiante primero
if (count($merged_quiz_ids) > 1) {
    $sql .= " ORDER BY u.nombre ASC, r.fecha_realizacion DESC";
} else {
    $sql .= " ORDER BY r.fecha_realizacion DESC";
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error cargando resultados: " . $e->getMessage());
}

// 5. OBTENER DATOS AGREGADOS Y PREDICCIONES (MÁS EFICIENTE)
try {
    // Re-usar la cláusula WHERE de la consulta principal (ya capturada en $where_chunk antes del ORDER BY)

    // Total Examenes
    $stmt_tot = $pdo->prepare("SELECT COUNT(*) FROM resultados r WHERE 1=1" . $where_chunk);
    $stmt_tot->execute($params);
    $total_examenes = $stmt_tot->fetchColumn();

    // Promedio General
    $sql_avg = "SELECT AVG((r.puntos_obtenidos / 250.0) * 100) FROM resultados r WHERE 1=1" . $where_chunk;
    $stmt_avg = $pdo->prepare($sql_avg);
    $stmt_avg->execute($params);
    $promedio_general = round($stmt_avg->fetchColumn(), 2);

    // Tasa de Aprobación
    $sql_aprobados = "SELECT COUNT(*) FROM resultados r WHERE ((r.puntos_obtenidos / 250.0) * 100) >= 70" . $where_chunk;
    $stmt_aprob = $pdo->prepare($sql_aprobados);
    $stmt_aprob->execute($params);
    $aprobados_count = $stmt_aprob->fetchColumn();
    $tasa_aprobacion = $total_examenes > 0 ? round(($aprobados_count / $total_examenes) * 100, 1) : 0;

    // Incidentes de Seguridad
    $sql_anomalias = "SELECT COUNT(*) FROM resultados r WHERE (r.intentos_tab_switch > 1 OR r.segundos_fuera > 15)" . $where_chunk;
    $stmt_anom = $pdo->prepare($sql_anomalias);
    $stmt_anom->execute($params);
    $total_anomalias = $stmt_anom->fetchColumn();

    // --- PREDICCIONES ---
    // Riesgo de Deserción
    $sql_risk = "SELECT COUNT(*) FROM resultados r WHERE ((r.puntos_obtenidos / 250.0) * 100) < 70 AND (r.intentos_tab_switch > 1 OR r.segundos_fuera > 15)" . $where_chunk;
    $stmt_risk = $pdo->prepare($sql_risk);
    $stmt_risk->execute($params);
    $risk_count = (int)$stmt_risk->fetchColumn();

    // Alta Probabilidad de Excelencia
    $sql_success = "SELECT COUNT(*) FROM resultados r WHERE ((r.puntos_obtenidos / 250.0) * 100) >= 90 AND r.intentos_tab_switch = 0" . $where_chunk;
    $stmt_success = $pdo->prepare($sql_success);
    $stmt_success->execute($params);
    $success_count = (int)$stmt_success->fetchColumn();

    // --- DATA FOR NEW CHARTS ---

    // 1. Timeline (Exams per Day)
    // Note: Adjusting date format for SQL group by based on typical DB (assuming PostgreSQL/MySQL, using generic approach if possible but here tailored to what seems like Postgres 'EXTRACT' or MySQL 'DATE')
    // Since previous queries use 'EXTRACT(MONTH FROM ...)', it likely supports standard SQL or Postgres.
    // Let's use string formatting for safety in PHP or simple date(fecha_realizacion)
    $stmt_timeline = $pdo->prepare("SELECT DATE(fecha_realizacion) as fecha, COUNT(*) as count FROM resultados r WHERE 1=1 " . $where_chunk . " GROUP BY DATE(fecha_realizacion) ORDER BY fecha ASC");
    $stmt_timeline->execute($params);
    $timeline_data = $stmt_timeline->fetchAll(PDO::FETCH_ASSOC);

    // 2. Score Distribution (Histogram buckets: <60, 60-70, 70-80, 80-90, 90-100)
    // We will calculate this from the full results fetch to save a complex DB query
    
    // 3. Demographics (Gender, Parallel) - also calculated from full results

    // Finalmente, obtener la lista de resultados para la tabla
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error en agregación de datos: " . $e->getMessage());
}

// 6. PROCESAMIENTO POST-CONSULTA (Cálculos por fila para la tabla + Agregaciones ChartJS)
$resultados = [];

// Init aggregation containers
$dist_notas = ['<60' => 0, '60-70' => 0, '70-80' => 0, '80-90' => 0, '90-100' => 0];
$dist_genero = ['Masculino' => 0, 'Femenino' => 0, 'Otro' => 0];
$dist_paralelo = [];
$dist_edad = []; // New: Age distribution with scores

foreach ($resultados_raw as $row) {
    // ... logic for table row ...
    $swaps = (int)($row['intentos_tab_switch'] ?? 0);
    $time  = (int)($row['segundos_fuera'] ?? 0);
    if ($swaps == 0 && $time == 0) $nivel = 'limpio';
    elseif ($swaps <= 2 && $time < 15) $nivel = 'leve';
    else $nivel = 'riesgo';
    if ($integridad && $integridad !== $nivel) continue;

    $puntos_obtenidos = (float)$row['puntos_obtenidos'];
    $nota_calculada = (250 > 0) ? ($puntos_obtenidos / 250) * 100 : 0;
    $nota_final = round($nota_calculada, 2);
    
    $row['nota_sobre_100'] = $nota_final;
    $row['nivel_integridad'] = $nivel;
    $resultados[] = $row;

    // Aggregations for Charts
    // Score Dist
    if ($nota_final < 60) $dist_notas['<60']++;
    elseif ($nota_final < 70) $dist_notas['60-70']++;
    elseif ($nota_final < 80) $dist_notas['70-80']++;
    elseif ($nota_final < 90) $dist_notas['80-90']++;
    else $dist_notas['90-100']++;

    // Gender Dist
    $g = ucfirst(strtolower($row['genero'] ?? 'Otro'));
    if (!isset($dist_genero[$g])) $dist_genero[$g] = 0;
    $dist_genero[$g]++;

    // Parallel Dist
    $p = strtoupper($row['paralelo'] ?? 'N/A');
    if (!isset($dist_paralelo[$p])) $dist_paralelo[$p] = 0;
    $dist_paralelo[$p]++;

    // Age Dist (with average scores)
    $edad = $row['edad'] ?? 'N/A';
    if ($edad !== 'N/A') {
        if (!isset($dist_edad[$edad])) {
            $dist_edad[$edad] = ['count' => 0, 'sum_notas' => 0];
        }
        $dist_edad[$edad]['count']++;
        $dist_edad[$edad]['sum_notas'] += $nota_final;
    }
}
ksort($dist_paralelo); // Sort parallels A-Z
ksort($dist_edad); // Sort ages numerically

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
    
    <!-- Google Analytics (Pendiente de ID de medición G-XXXXXXXXXX)
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-XXXXXXXXXX');
    </script>
    -->
    
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
<body class="bg-light">

<style>
    /* Copied Search Styles for Consistency */
    .smart-search-container { position: relative; width: 100%; max-width: 600px; margin: 0 auto; }
    .smart-search-input { width: 100%; padding: 0.8rem 1.5rem; padding-left: 2.8rem; border: none; border-radius: 50px; background: rgba(255,255,255,0.9); box-shadow: 0 4px 15px rgba(0,0,0,0.05); font-size: 1rem; transition: all 0.3s ease; }
    .smart-search-input:focus { box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2); outline: none; background: white; }
    .smart-search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #667eea; }
</style>

<div class="container py-4">
    <div class="page-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-4">
                    <h4 class="mb-0"><i class="fas fa-chart-line me-3"></i>Reporte Académico</h4>
                    <span class="badge bg-success rounded-pill px-3 py-2 d-none d-lg-block">
                        <i class="fas fa-circle" style="font-size: 0.5rem; margin-right: 0.5rem; color: #00ff00;"></i>
                        Conectado con Google Analytics
                    </span>
                    
                    <!-- SEARCH BAR IN HEADER -->
                    <div class="smart-search-container d-none d-md-block">
                        <i class="fas fa-search smart-search-icon"></i>
                        <input type="text" id="smartSearchInput" class="smart-search-input" placeholder="Buscar: 'Mujeres del paralelo A', 'Juan en Matematicas'...">
                    </div>
                </div>
                <div>
                    <a href="profesor.php" class="btn">
                        <i class="fas fa-arrow-left me-2"></i>Volver
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-form mb-4">
        <form method="GET" class="row g-3" id="filterForm">
            <input type="hidden" name="q" id="hiddenSearchQuery" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <input type="hidden" name="min_nota" value="<?= htmlspecialchars($min_nota) ?>">
            <input type="hidden" name="max_nota" value="<?= htmlspecialchars($max_nota) ?>">
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
                <input type="number" name="edad" class="form-control form-control-sm" value="<?= htmlspecialchars($edad) ?>" placeholder="Ej: 15" autocomplete="off">
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

    <!-- Analytics Section MOVED TO TOP -->
    <?php if (!empty($stats_por_quiz)): ?>
    <div class="mb-5">
        <h5 class="fw-bold mb-4">
            <i class="fas fa-chart-pie me-2" style="color: #667eea;"></i>
            Análisis Profundo
        </h5>

        <!-- Row 1: Timeline & Demographics -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-chart-line me-2 text-primary"></i>Actividad en el Tiempo
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartTimeline', 'actividad_tiempo')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 350px;">
                        <canvas id="chartTimeline"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-users me-2 text-info"></i>Género
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartGenero', 'distribucion_genero')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="chartGenero"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Performance & Distribution -->
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-chart-bar me-2 text-warning"></i>Distribución de Notas
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartNotasDist', 'distribucion_notas')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="chartNotasDist"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-layer-group me-2 text-success"></i>Participación por Paralelo
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartParalelos', 'participacion_paralelo')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="chartParalelos"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-birthday-cake me-2 text-danger"></i>Métricas por Edad
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartEdad', 'metricas_edad')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="chartEdad"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 3: Ladder Chart & Conclusions Preview -->
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-stairs me-2 text-purple"></i>Escalera de Rendimiento
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartLadder', 'escalera_rendimiento')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="chartLadder"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <h6 class="fw-bold mb-3 text-dark">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Conclusiones Clave
                    </h6>
                    <div class="conclusions-preview">
                        <?php
                        $insights = [];
                        if ($promedio_general >= 80) {
                            $insights[] = ['icon' => 'fa-trophy', 'color' => 'success', 'text' => 'Rendimiento general excelente con promedio de ' . $promedio_general];
                        } elseif ($promedio_general >= 70) {
                            $insights[] = ['icon' => 'fa-check-circle', 'color' => 'info', 'text' => 'Rendimiento satisfactorio con promedio de ' . $promedio_general];
                        } else {
                            $insights[] = ['icon' => 'fa-exclamation-triangle', 'color' => 'warning', 'text' => 'Se requiere atención: promedio de ' . $promedio_general];
                        }
                        
                        if ($tasa_aprobacion >= 80) {
                            $insights[] = ['icon' => 'fa-users', 'color' => 'success', 'text' => 'Alta tasa de aprobación: ' . $tasa_aprobacion . '%'];
                        } elseif ($tasa_aprobacion < 60) {
                            $insights[] = ['icon' => 'fa-user-times', 'color' => 'danger', 'text' => 'Tasa de aprobación baja: ' . $tasa_aprobacion . '%'];
                        }
                        
                        if ($total_anomalias > ($total_examenes * 0.2)) {
                            $insights[] = ['icon' => 'fa-shield-alt', 'color' => 'danger', 'text' => 'Alertas de integridad elevadas: ' . $total_anomalias . ' casos'];
                        }
                        
                        foreach ($insights as $insight):
                        ?>
                        <div class="insight-item mb-2">
                            <i class="fas <?= $insight['icon'] ?> text-<?= $insight['color'] ?> me-2"></i>
                            <span class="small"><?= $insight['text'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 4: Existing Charts -->
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-signal me-2 text-primary"></i>Promedio de Notas por Examen
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartPromedios', 'promedios_examen')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 350px;">
                        <canvas id="chartPromedios"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-check-circle me-2 text-success"></i>Tasa de Aprobación Global
                        </h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('chartAprobacion', 'tasa_aprobacion')">
                            <i class="fas fa-download me-1"></i>PNG
                        </button>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="chartAprobacion"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="results-tab" data-bs-toggle="tab" data-bs-target="#results">Resultados <span class="badge bg-secondary ms-1"><?= $total_examenes ?></span></button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending">Pendientes</button>
        </li>
    </ul>

    <div class="tab-content" id="reportTabsContent">
        <div class="tab-pane fade show active" id="results">

            <div class="row mb-4 g-4">
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card blue">
                        <div class="kpi-value text-primary"><?= number_format($total_examenes) ?></div>
                        <div class="kpi-label">Exámenes Realizados</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card green">
                        <div class="kpi-value text-success"><?= $promedio_general ?></div>
                        <div class="kpi-label">Score Promedio</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card yellow">
                        <div class="kpi-value text-warning"><?= $tasa_aprobacion ?>%</div>
                        <div class="kpi-label">Tasa de Aprobación</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card red">
                        <div class="kpi-value text-danger"><?= $total_anomalias ?></div>
                        <div class="kpi-label">Alertas de Seguridad</div>
                    </div>
                </div>
            </div>

            <!-- PREDICTIVE ANALYTICS SECTION -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-brain me-2" style="color: #764ba2;"></i>
                        Predicciones y Audiencias
                        <span class="magic-badge"><i class="fas fa-bolt me-1"></i>AI Powered</span>
                    </h5>
                    <p class="text-muted mb-4" style="max-width: 800px;">
                        Analytics usa modelos de aprendizaje automático de Google para analizar tus datos y predecir las acciones que los usuarios pueden realizar en el futuro, como hacer una compra o abandonar el proceso de conversión. A partir de esa información, puedes crear audiencias que, según predice el sistema, realizarán esas acciones. Así conseguirás aumentar las conversiones o retener a más usuarios.
                    </p>
                </div>
                <div class="col-md-6">
                    <div class="prediction-card">
                        <div style="z-index: 2;">
                            <h6 class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Riesgo de Deserción</h6>
                            <div class="big-number"><?= number_format($risk_count) ?></div>
                            <p class="desc">Estudiantes con comportamiento no íntegro y bajas calificaciones.</p>
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
                            <p class="desc">Estudiantes con comportamiento 'Limpio' y notas superiores a 90/100.</p>
                            <a href="lenguaje_d.php?integridad=limpio&min_nota=90" class="btn btn-outline-success btn-predict">Crear Audiencia de Honor</a>
                        </div>
                        <i class="fas fa-chart-line stat-icon"></i>
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
                                <tr><td colspan="7" class="text-center py-5 text-muted">No hay resultados para los filtros seleccionados.</td></tr>
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
                                    <td class="text-muted" id="pts-<?= (int)$row['id'] ?>"><?= $row['puntos_obtenidos'] ?> / 250</td>
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

    <!-- Analytics Section REMOVED FROM HERE (Moved to top) -->
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
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script>
// ==========================================
// DOWNLOAD CHART AS PNG
// ==========================================
function downloadChart(chartId, filename) {
    const chart = Chart.getChart(chartId);
    if (!chart) {
        console.error('Chart not found:', chartId);
        return;
    }
    
    // Store original background color
    const originalBgColor = chart.options.plugins?.backgroundColor;
    
    // Set white background for export
    if (!chart.options.plugins) chart.options.plugins = {};
    chart.options.plugins.backgroundColor = '#ffffff';
    
    // Force render with animation disabled to ensure datalabels are included
    chart.options.animation = false;
    chart.update();
    
    // Small delay to ensure render is complete
    setTimeout(() => {
        // Get canvas and create new one with white background
        const canvas = chart.canvas;
        const ctx = canvas.getContext('2d');
        
        // Create temporary canvas
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = canvas.width;
        tempCanvas.height = canvas.height;
        const tempCtx = tempCanvas.getContext('2d');
        
        // Fill with white background
        tempCtx.fillStyle = '#ffffff';
        tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
        
        // Draw chart on top
        tempCtx.drawImage(canvas, 0, 0);
        
        // Get base64 image from temp canvas
        const url = tempCanvas.toDataURL('image/png', 1);
        
        // Create download link
        const link = document.createElement('a');
        link.download = filename + '.png';
        link.href = url;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Restore original settings
        chart.options.plugins.backgroundColor = originalBgColor;
        chart.options.animation = true;
        chart.update();
        
        // Show feedback
        if (window.showToast) {
            window.showToast('Gráfico descargado exitosamente 📊', 'success');
        }
    }, 100);
}

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
    const timelineData = <?= json_encode($timeline_data ?? []) ?>;
    const distNotas = <?= json_encode($dist_notas ?? []) ?>;
    const distGenero = <?= json_encode($dist_genero ?? []) ?>;
    const distParalelo = <?= json_encode($dist_paralelo ?? []) ?>;
    const distEdad = <?= json_encode($dist_edad ?? []) ?>;
    
    // Configuración Global Chart.js
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#718096';
    Chart.defaults.scale.grid.color = 'rgba(0, 0, 0, 0.05)';
    Chart.defaults.plugins.tooltip.boxPadding = 5;
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    
    // Disable datalabels by default (enable per chart)
    Chart.register(ChartDataLabels);
    Chart.defaults.set('plugins.datalabels', { display: false });
    
    // Paleta de colores moderna
    const colors = {
        primary: '#667eea',
        success: '#10b981',
        info: '#3b82f6',
        warning: '#f59e0b',
        danger: '#ef4444',
        purple: '#8b5cf6',
        teal: '#14b8a6',
        gradients: [
            'rgba(102, 126, 234, 0.8)', 'rgba(239, 68, 68, 0.8)', 'rgba(245, 158, 11, 0.8)', 
            'rgba(16, 185, 129, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(139, 92, 246, 0.8)'
        ]
    };

    // 1. TIMELINE - Actividad en el Tiempo
    if(document.getElementById('chartTimeline')) {
        new Chart(document.getElementById('chartTimeline'), {
            type: 'line',
            data: {
                labels: timelineData.map(d => d.fecha),
                datasets: [{
                    label: 'Exámenes Realizados',
                    data: timelineData.map(d => d.count),
                    borderColor: colors.primary,
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { mode: 'index', intersect: false },
                    datalabels: {
                        display: true,
                        align: 'top',
                        color: colors.primary,
                        font: { weight: 'bold', size: 11 }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, border: { display: false } }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    }

    // 2. GÉNERO - Distribución
    if(document.getElementById('chartGenero')) {
        new Chart(document.getElementById('chartGenero'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(distGenero),
                datasets: [{
                    data: Object.values(distGenero),
                    backgroundColor: [colors.info, colors.danger, colors.warning],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    datalabels: {
                        display: true,
                        formatter: (value, ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return percentage + '%';
                        },
                        color: '#fff',
                        font: { weight: 'bold', size: 14 }
                    }
                }
            }
        });
    }

    // 3. NOTAS - Distribución
    if(document.getElementById('chartNotasDist')) {
        new Chart(document.getElementById('chartNotasDist'), {
            type: 'bar',
            data: {
                labels: Object.keys(distNotas),
                datasets: [{
                    label: 'Estudiantes',
                    data: Object.values(distNotas),
                    backgroundColor: [
                        'rgba(239, 68, 68, 0.7)',  // <60
                        'rgba(245, 158, 11, 0.7)', // 60-70
                        'rgba(250, 204, 21, 0.7)', // 70-80
                        'rgba(16, 185, 129, 0.7)', // 80-90
                        'rgba(59, 130, 246, 0.7)'  // 90-100
                    ],
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    datalabels: {
                        display: true,
                        anchor: 'end',
                        align: 'top',
                        color: '#1a202c',
                        font: { weight: 'bold', size: 12 }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, border: { display: false } }
                }
            }
        });
    }

    // 4. PARALELOS - Participación
    if(document.getElementById('chartParalelos')) {
        new Chart(document.getElementById('chartParalelos'), {
            type: 'bar',
            data: {
                labels: Object.keys(distParalelo),
                datasets: [{
                    label: 'Participantes',
                    data: Object.values(distParalelo),
                    backgroundColor: colors.teal,
                    borderRadius: 4,
                }]
            },
            options: {
                indexAxis: 'y', // Horizontal bars
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    datalabels: {
                        display: true,
                        anchor: 'end',
                        align: 'right',
                        color: '#1a202c',
                        font: { weight: 'bold', size: 12 }
                    }
                },
                scales: {
                    x: { beginAtZero: true, border: { display: false }, grid: { borderDash: [2, 4] } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    // 5. EDAD - Métricas por Edad
    if(document.getElementById('chartEdad')) {
        const edadLabels = Object.keys(distEdad);
        
        // Check if we have data
        if (edadLabels.length === 0) {
            console.warn('No age data available for chart');
            const ctx = document.getElementById('chartEdad').getContext('2d');
            ctx.font = '14px Inter';
            ctx.fillStyle = '#718096';
            ctx.textAlign = 'center';
            ctx.fillText('No hay datos de edad disponibles', 150, 125);
        } else {
            const edadCounts = edadLabels.map(edad => distEdad[edad].count);
            const edadPromedios = edadLabels.map(edad => {
                const data = distEdad[edad];
                return data.count > 0 ? (data.sum_notas / data.count).toFixed(1) : 0;
            });

            new Chart(document.getElementById('chartEdad'), {
                type: 'bar',
                data: {
                    labels: edadLabels.map(e => e + ' años'),
                    datasets: [
                        {
                            label: 'Estudiantes',
                            data: edadCounts,
                            backgroundColor: 'rgba(239, 68, 68, 0.6)',
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Promedio',
                            data: edadPromedios,
                            type: 'line',
                            borderColor: colors.warning,
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            yAxisID: 'y1',
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            position: 'top',
                            labels: { boxWidth: 12, usePointStyle: true }
                        },
                        datalabels: {
                            display: function(context) {
                                // Show labels only for bars (dataset 0), not for line (dataset 1)
                                return context.datasetIndex === 0;
                            },
                            anchor: 'end',
                            align: 'top',
                            color: '#1a202c',
                            font: { weight: 'bold', size: 11 }
                        }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            title: { display: true, text: 'Estudiantes' }
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            max: 100,
                            title: { display: true, text: 'Promedio' },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        }
    }

    // Preparar arrays para los gráficos Existentes (Refined)
    const materias = Object.keys(statsData);
    const promedios = materias.map(m => statsData[m].promedio);
    const totales = materias.map(m => statsData[m].total);
    const aprobados = materias.map(m => statsData[m].aprobados);
    
    // 5. PROMEDIOS (Existente mejorado)
    if(document.getElementById('chartPromedios')) {
        new Chart(document.getElementById('chartPromedios'), {
            type: 'bar',
            data: {
                labels: materias,
                datasets: [{
                    label: 'Promedio',
                    data: promedios,
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderRadius: 8,
                    barPercentage: 0.6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    datalabels: {
                        display: true,
                        anchor: 'end',
                        align: 'top',
                        formatter: (value) => value + '/100',
                        color: '#1a202c',
                        font: { weight: 'bold', size: 11 }
                    }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '/100' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 6. APROBACIÓN (Existente mejorado)
    const totalAprobados = aprobados.reduce((a, b) => a + b, 0);
    const totalEstudiantes = totales.reduce((a, b) => a + b, 0);
    const totalReprobados = totalEstudiantes - totalAprobados;
    const tasaAprobacionGlobal = totalEstudiantes > 0 ? ((totalAprobados / totalEstudiantes) * 100).toFixed(1) : 0;
    
    if(document.getElementById('chartAprobacion')) {
        new Chart(document.getElementById('chartAprobacion'), {
            type: 'doughnut',
            data: {
                labels: ['Aprobados', 'Reprobados'],
                datasets: [{
                    data: [totalAprobados, totalReprobados],
                    backgroundColor: [colors.success, colors.danger],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    datalabels: {
                        display: true,
                        formatter: (value, ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return percentage + '%';
                        },
                        color: '#fff',
                        font: { weight: 'bold', size: 16 }
                    }
                }
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function(chart) {
                    const ctx = chart.ctx;
                    ctx.save();
                    const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                    const centerY = (chart.chartArea.top + chart.chartArea.bottom) / 2;
                    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.font = 'bold 24px Inter'; ctx.fillStyle = '#1a202c';
                    ctx.fillText(tasaAprobacionGlobal + '%', centerX, centerY - 10);
                    ctx.font = '600 11px Inter'; ctx.fillStyle = '#718096';
                    ctx.fillText('Aprobación', centerX, centerY + 15);
                    ctx.restore();
                }
            }]
        });
    }

    // 7. LADDER CHART - Escalera de Rendimiento
    if(document.getElementById('chartLadder')) {
        // Calculate ladder data
        const total = totalEstudiantes;
        const passed = totalAprobados; // ≥70
        const good = materias.reduce((sum, m) => {
            const avg = statsData[m].promedio;
            return sum + (avg >= 80 ? statsData[m].total : 0);
        }, 0);
        const excellent = materias.reduce((sum, m) => {
            const avg = statsData[m].promedio;
            return sum + (avg >= 90 ? statsData[m].total : 0);
        }, 0);

        new Chart(document.getElementById('chartLadder'), {
            type: 'bar',
            data: {
                labels: ['Total', 'Aprobados\n(≥70)', 'Buenos\n(≥80)', 'Excelentes\n(≥90)'],
                datasets: [{
                    label: 'Estudiantes',
                    data: [total, passed, good, excellent],
                    backgroundColor: [
                        'rgba(102, 126, 234, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(239, 68, 68, 0.8)'
                    ],
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        display: true,
                        anchor: 'end',
                        align: 'right',
                        formatter: (value) => value,
                        color: '#1a202c',
                        font: { weight: 'bold', size: 13 }
                    }
                },
                scales: {
                    x: { 
                        beginAtZero: true,
                        border: { display: false },
                        grid: { borderDash: [2, 4] }
                    },
                    y: { 
                        grid: { display: false },
                        ticks: { font: { size: 12, weight: 600 } }
                    }
                }
            }
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

    <!-- Bootstrap JS (Required for Toasts) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script type="text/javascript-disabled">
    // Utilidad: mostrar toast Bootstrap (o fallback) en la esquina superior derecha
    window.showToast = function(message, type = 'success') {
        const container = document.getElementById('globalToastContainer');
        if (!container) return alert(message);
        
        const color = type === 'danger' ? 'bg-danger text-white' : type === 'warning' ? 'bg-warning text-dark' : 'bg-success text-white';
        const closeBtnClass = type === 'warning' ? 'btn-close' : 'btn-close btn-close-white';

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="toast align-items-center ${color}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="${closeBtnClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
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

    document.addEventListener('DOMContentLoaded', function() {
        console.log("Smart Search: Script loaded.");
        
        const searchInput = document.getElementById('smartSearchInput');
        const form = document.getElementById('filterForm'); // CORRECTED: targets proper form in lenguaje_d.php

        if (!searchInput || !form) {
            console.error("Smart Search: Input o Form no encontrados.");
            return;
        }

        // ==========================================
        // CONFIGURACIÓN DE INTENCIONES DE BÚSQUEDA (JSON)
        // ==========================================
        const SEARCH_PATTERNS = [
            // --- 1. GÉNERO ---
            {
                id: 'genero_fem',
                triggers: [/mujer|femenino|chicas|niñas|generalas/i],
                action: (form) => setVal(form, 'select[name="genero"]', 'Femenino')
            },
            {
                id: 'genero_masc',
                triggers: [/hombre|masculino|chicos|niños|varones/i],
                action: (form) => setVal(form, 'select[name="genero"]', 'Masculino')
            },

            // --- 2. RENDIMIENTO ACADÉMICO (Preguntas Humanas) ---
            {
                id: 'reprobados',
                description: 'Estudiantes con nota menor a 70',
                triggers: [/reprob|perdieron|jalados|pierden|malas notas|bajo rendimiento|fracaso/i],
                action: (form) => setVal(form, 'input[name="max_nota"]', '69')
            },
            {
                id: 'aprobados',
                description: 'Estudiantes con nota mayor o igual a 70',
                triggers: [/pasaron|aprob|ganaron|buenos|regular/i],
                action: (form) => setVal(form, 'input[name="min_nota"]', '70')
            },
            {
                id: 'excelencia',
                description: 'Cuadro de honor, notas > 90',
                triggers: [/honor|excelencia|mejores|destacados|top|brillantes|cracks/i],
                action: (form) => setVal(form, 'input[name="min_nota"]', '90')
            },

            // --- 3. INTEGRIDAD / CONDUCTA ---
            {
                id: 'riesgo_copia',
                description: 'Estudiantes con alertas de integridad',
                triggers: [/copia|trampa|integridad|riesgo|sospechosos|alerta|conducta/i],
                // Nota: Asumiendo que existe un filtro de integridad o usaremos validación extra
                // Si no existe el campo directo, podríamos simularlo o filtrar por notas bajas + tiempo fuera
                action: (form) => {
                    // Si existiera un input hidden o select específico para integridad:
                    // setVal(form, 'select[name="integridad"]', 'riesgo'); 
                    // Como no lo veo explícito en el form HTML visible, lo dejamos como TODO o usamos un query param
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'integridad';
                    input.value = 'riesgo';
                    form.appendChild(input);
                }
            },

            // --- 4. PARALELO (Regex con captura) ---
            {
                id: 'paralelo',
                triggers: [/\bparalelo\s*([a-h])\b/i, /\bcurso\s*([a-h])\b/i, /\bgrupo\s*([a-h])\b/i],
                action: (form, match) => setVal(form, 'select[name="paralelo"]', match[1].toUpperCase())
            },

            // --- 5. EDAD (Regex con captura) ---
            {
                id: 'edad',
                triggers: [/(\d+)\s*(?:años|edad)/i],
                action: (form, match) => setVal(form, 'input[name="edad"]', match[1])
            },

            // --- 6. NOTA EXACTA (antes de rangos) ---
            {
                id: 'nota_exacta',
                description: 'Nota específica (ej: nota de 50, nota 70)',
                triggers: [/\bnota\s*(?:de|igual a|=)?\s*(\d+)\b/i],
                action: (form, match) => {
                    const nota = match[1];
                    // Aplicar como rango estrecho (±2 puntos)
                    const min = Math.max(0, parseInt(nota) - 2);
                    const max = Math.min(100, parseInt(nota) + 2);
                    setVal(form, 'input[name="min_nota"]', min.toString());
                    setVal(form, 'input[name="max_nota"]', max.toString());
                }
            },

            // --- 7. RANGO DE NOTAS (Desigualdades) ---
            {
                id: 'nota_max',
                triggers: [/(?:menor(?:es)?|bajo|menos|inferior(?:es)?|<)\s*(?:a|que|de)?\s*(\d+)/i],
                action: (form, match) => setVal(form, 'input[name="max_nota"]', match[1])
            },
            {
                id: 'nota_min',
                triggers: [/(?:mayor(?:es)?|sobre|m[áa]s|arriba|superior(?:es)?|>)\s*(?:a|que|de)?\s*(\d+)/i],
                action: (form, match) => setVal(form, 'input[name="min_nota"]', match[1])
            }
        ];

        // Helpers
        const setVal = (form, selector, value) => {
            const el = form.querySelector(selector);
            if (el) el.value = value;
        };

        const processAndSubmitQuery = () => {
            console.log("Smart Search: Procesando (Motor JSON)...");
            const query = searchInput.value.toLowerCase();
            let matched = false;

            try {
                // 1. Detección de patrones
                SEARCH_PATTERNS.forEach(pattern => {
                    pattern.triggers.forEach(regex => {
                        const match = query.match(regex);
                        if (match) {
                            console.log(`Patrón detectado: ${pattern.id}`);
                            pattern.action(form, match);
                            matched = true;
                        }
                    });
                });

                // 2. Detección Especial: MESES (Lógica iterativa compleja mejor mantenerla aparte o integrarla si se desea)
                const selMes = form.querySelector('select[name="mes"]');
                if (selMes) {
                    const meses = {
                        'enero':'01', 'febrero':'02', 'marzo':'03', 'abril':'04', 'mayo':'05', 'junio':'06',
                        'julio':'07', 'agosto':'08', 'septiembre':'09', 'octubre':'10', 'noviembre':'11', 'diciembre':'12'
                    };
                    for (const [nombre, val] of Object.entries(meses)) {
                        if (query.includes(nombre)) {
                            selMes.value = val;
                            matched = true;
                            break;
                        }
                    }
                }

                // 3. Detección Especial: EXAMEN
                const selQuiz = form.querySelector('select[name="quiz_id"]');
                if (selQuiz) {
                    const options = selQuiz.options;
                    for (let i = 1; i < options.length; i++) { 
                        if (query.includes(options[i].text.toLowerCase())) {
                            selQuiz.value = options[i].value;
                            matched = true;
                            break;
                        }
                    }
                }

                // 4. ACTUALIZAR QUERY STRING HIDDEN
                const hiddenQuery = form.querySelector('#hiddenSearchQuery');
                if (hiddenQuery) hiddenQuery.value = searchInput.value;

                console.log("Smart Search: Enviando formulario...");
                // UI Feedback antes de enviar
                if (matched && window.showToast) {
                    window.showToast("Búsqueda inteligente aplicada 🧠", "success");
                }
                
                setTimeout(() => form.submit(), 500); // Pequeño delay para ver el toast
                
            } catch (err) {
                console.error("Error en Smart Search:", err);
                if(window.showToast) window.showToast("No entendí esa consulta, intenta con palabras clave.", "warning");
            }
        };

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                processAndSubmitQuery();
            }
        });

        // Event listener for suggestion chips if they exist
        document.body.addEventListener('click', function(e) {
            if(e.target.classList.contains('search-suggestion')) {
                 e.preventDefault();
                 searchInput.value = e.target.innerText.replace(/"/g, '');
                 processAndSubmitQuery();
            }
        });

    });
</script>

</body>
</html>