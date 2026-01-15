<?php
require_once 'db.php';
session_start();

// Validar sesión profesor
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    die("Acceso denegado. Rol detectado: " . print_r($_SESSION, true));
}

// 1. OBTENER FILTROS (Misma lógica que lenguaje_d.php)
$quiz_id        = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT);
$fecha_desde    = filter_input(INPUT_GET, 'fecha_desde');
$fecha_hasta    = filter_input(INPUT_GET, 'fecha_hasta');
$genero         = filter_input(INPUT_GET, 'genero');
$edad           = filter_input(INPUT_GET, 'edad', FILTER_VALIDATE_INT);
$paralelo       = filter_input(INPUT_GET, 'paralelo');
$min_nota       = filter_input(INPUT_GET, 'min_nota');
$max_nota       = filter_input(INPUT_GET, 'max_nota');
$filtro_muestra = filter_input(INPUT_GET, 'muestra');

// 2. CONSTRUIR CONSULTA SQL
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
        $stmt_check = $pdo->prepare("SELECT titulo FROM quizzes WHERE id = :id");
        $stmt_check->execute(['id' => $quiz_id]);
        $titulo = $stmt_check->fetchColumn();
        
        if ($titulo && stripos($titulo, 'Lengua y Literatura') !== false) {
            $stmt_related = $pdo->prepare("SELECT id FROM quizzes WHERE titulo LIKE '%Lengua y Literatura%'");
            $stmt_related->execute();
            $merged_quiz_ids = $stmt_related->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($merged_quiz_ids) > 0) {
                $placeholders = [];
                foreach ($merged_quiz_ids as $idx => $qid) {
                    $key = "merged_quiz_$idx";
                    $placeholders[] = ":$key";
                    $params[$key] = $qid;
                }
                $sql .= " AND r.quiz_id IN (" . implode(',', $placeholders) . ")";
            }
        } else {
            $sql .= " AND r.quiz_id = :quiz_id";
            $params['quiz_id'] = $quiz_id;
        }
    } catch (PDOException $e) {
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
if ($filtro_muestra === 'si') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = TRUE";
} elseif ($filtro_muestra === 'no') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = FALSE";
}

// Filtro de Notas
$max_score_expr = "(CASE WHEN q.titulo ILIKE '%Preguntas Abiertas%' THEN 20.0 ELSE 250.0 END)";
if ($min_nota !== '' && $min_nota !== null) {
    $sql .= " AND ((r.puntos_obtenidos / $max_score_expr) * 100) >= :min_nota";
    $params['min_nota'] = $min_nota;
}
if ($max_nota !== '' && $max_nota !== null) {
    $sql .= " AND ((r.puntos_obtenidos / $max_score_expr) * 100) <= :max_nota";
    $params['max_nota'] = $max_nota;
}

// Orden
$sql .= " ORDER BY r.fecha_realizacion DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. GENERAR CSV
    header('Content-Type: text/csv; charset=utf-8');
    $prefix = ($filtro_muestra === 'si') ? 'muestra_' : 'reporte_';
    header('Content-Disposition: attachment; filename=' . $prefix . 'resultados_' . date('Y-m-d_H-i') . '.csv');

    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fputs($output, "\xEF\xBB\xBF");

    // Headers
    fputcsv($output, [
        'ID Resultado', 
        'Estudiante', 
        'Email', 
        'Examen', 
        'Fecha', 
        'Paralelo', 
        'Genero', 
        'Edad', 
        'Puntos Obtenidos', 
        'Nota Maxima', 
        'Calificacion (/100)', 
        'Integridad',
        'Intentos Tab Switch',
        'Segundos Fuera'
    ]);

    foreach ($resultados as $row) {
        // Calcular campos derivados
        $puntos = (float)$row['puntos_obtenidos'];
        $max_puntos = (stripos($row['quiz_titulo'], 'Preguntas Abiertas') !== false) ? 20 : 250;
        $nota_final = ($max_puntos > 0) ? round(($puntos / $max_puntos) * 100, 2) : 0;
        
        // Integridad
        $swaps = (int)($row['intentos_tab_switch'] ?? 0);
        $time  = (int)($row['segundos_fuera'] ?? 0);
        if ($swaps == 0 && $time == 0) $nivel = 'Limpio';
        elseif ($swaps <= 2 && $time < 15) $nivel = 'Leve';
        else $nivel = 'Riesgo';

        fputcsv($output, [
            $row['id'],
            $row['usuario_nombre'],
            $row['usuario_email'],
            $row['quiz_titulo'],
            $row['fecha_realizacion'],
            strtoupper($row['paralelo'] ?? 'N/A'),
            ucfirst($row['genero'] ?? 'N/A'),
            $row['edad'] ?? 'N/A',
            $puntos,
            $max_puntos,
            $nota_final,
            $nivel,
            $swaps,
            $time
        ]);
    }
    
    fclose($output);

} catch (PDOException $e) {
    die("Error generando reporte: " . $e->getMessage());
}
?>
