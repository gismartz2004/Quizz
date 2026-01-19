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
    // 3. GENERAR CSV
    $mode = filter_input(INPUT_GET, 'mode'); // 'full' for detailed data
    
    // Disable limits for massive exports
    ini_set('memory_limit', '-1');
    set_time_limit(0);
    ini_set('zlib.output_compression', 'Off'); // Disable compression for streaming

    // Clean any existing output buffer to prevent corrupted files
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    $prefix = ($filtro_muestra === 'si') ? 'muestra_' : (($mode === 'full') ? 'bd_completa_' : 'reporte_');
    header('Content-Disposition: attachment; filename=' . $prefix . 'resultados_' . date('Y-m-d_H-i') . '.csv');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM

    // ... Headers code ...
    // (We will regenerate headers part to be safe, but mostly the fix is in processBatch and buffering)
    
    // Basic Headers
    $headers = [
        'ID Resultado', 'Estudiante', 'Email', 'Examen', 'Fecha', 
        'Paralelo', 'Genero', 'Edad', 'Puntos Obtenidos', 'Nota Maxima', 
        'Calificacion (/100)', 'Integridad', 'Intentos Tab Switch', 'Segundos Fuera'
    ];

    $questionMap = [];
    
    // BUILD DYNAMIC HEADERS (Full Mode)
    if ($mode === 'full') {
        try {
            // Get ALL questions to ensure column consistency
            $stmtQ = $pdo->query("SELECT id, texto FROM preguntas ORDER BY id ASC");
            while ($q = $stmtQ->fetch(PDO::FETCH_ASSOC)) {
                $questionMap[$q['id']] = $q['texto'];
                $headers[] = "P" . $q['id'] . ": " . substr(strip_tags($q['texto']), 0, 50);
            }
        } catch (Exception $e) {
            // If checking questions fails, we proceed without dynamic columns
        }
    }

    fputcsv($output, $headers);
    flush(); // Send headers immediately

    // EXECUTE MAIN QUERY
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // BATCH PROCESSING
    $batchSize = 200; 
    $batchRows = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batchRows[] = $row;

        if (count($batchRows) >= $batchSize) {
            processBatch($pdo, $output, $batchRows, $mode, $questionMap);
            $batchRows = []; 
            if (ob_get_level() > 0) ob_flush();
            flush(); 
        }
    }

    if (count($batchRows) > 0) {
        processBatch($pdo, $output, $batchRows, $mode, $questionMap);
        flush();
    }
    
    fclose($output);

} catch (PDOException $e) {
    die("Error generando reporte: " . $e->getMessage());
}

// Helper function to process a batch of results
function processBatch($pdo, $output, $rows, $mode, $questionMap) {
    // 1. If Full Mode, fetch ALL answers for this batch in ONE query
    $batchAnswers = [];
    if ($mode === 'full' && !empty($rows)) {
        $ids = array_column($rows, 'id');
        $inQuery = implode(',', array_map('intval', $ids));
        
        // FIXED COLUMN NAME: p.valor instead of p.puntos
        $sqlAns = "SELECT ru.resultado_id, ru.pregunta_id, o.texto as respuesta_texto,
                          ru.es_correcta_manual, o.es_correcta, p.valor as puntos_pregunta
                   FROM respuestas_usuarios ru 
                   LEFT JOIN opciones o ON ru.opcion_id = o.id
                   LEFT JOIN preguntas p ON ru.pregunta_id = p.id
                   WHERE ru.resultado_id IN ($inQuery)";
        
        try {
            $stmtAns = $pdo->query($sqlAns);
            while ($ans = $stmtAns->fetch(PDO::FETCH_ASSOC)) {
                
                $is_c_manual = $ans['es_correcta_manual'];
                $is_c_auto   = $ans['es_correcta'];
                
                // Postgres/MySQL boolean standardization
                $is_c_auto_bool = ($is_c_auto === true || $is_c_auto === 't' || $is_c_auto == 1);
                
                if ($is_c_manual !== null) {
                    $final_correct = ($is_c_manual === true || $is_c_manual === 't' || $is_c_manual == 1);
                } else {
                    $final_correct = $is_c_auto_bool;
                }

                $points = $final_correct ? floatval($ans['puntos_pregunta']) : 0;
                $text = trim($ans['respuesta_texto'] ?? '');
                
                // Format: "Answer [Pts: 2.5]"
                $batchAnswers[$ans['resultado_id']][$ans['pregunta_id']] = $text . " [Pts: $points]";
            }
        } catch (Exception $e) {
            // Only capture errors if strictly necessary, otherwise let empty strings fill gaps
            // error_log("Error in batch export: " . $e->getMessage());
        }
    }

    // 2. Iterate and write CSV lines
    foreach ($rows as $row) {
        $puntos = (float)$row['puntos_obtenidos'];
        $max_puntos = (stripos($row['quiz_titulo'], 'Preguntas Abiertas') !== false) ? 20 : 250;
        $nota_final = ($max_puntos > 0) ? round(($puntos / $max_puntos) * 100, 2) : 0;
        
        // Integridad
        $swaps = (int)($row['intentos_tab_switch'] ?? 0);
        $time  = (int)($row['segundos_fuera'] ?? 0);
        if ($swaps == 0 && $time == 0) $nivel = 'Limpio';
        elseif ($swaps <= 2 && $time < 15) $nivel = 'Leve';
        else $nivel = 'Riesgo';

        $csvRow = [
            $row['id'], $row['usuario_nombre'], $row['usuario_email'], $row['quiz_titulo'],
            $row['fecha_realizacion'], strtoupper($row['paralelo'] ?? 'N/A'),
            ucfirst($row['genero'] ?? 'N/A'), $row['edad'] ?? 'N/A',
            $puntos, $max_puntos, $nota_final, $nivel, $swaps, $time
        ];

        // Append Answers
        if ($mode === 'full') {
            $rId = $row['id'];
            foreach ($questionMap as $qId => $qText) {
                $ansText = $batchAnswers[$rId][$qId] ?? 'N/R'; 
                $csvRow[] = $ansText;
            }
        }

        fputcsv($output, $csvRow);
    }
}
?>
