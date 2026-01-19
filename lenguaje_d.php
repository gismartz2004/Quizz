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
// --- LOGICA DE BÚSQUEDA QA (Antes de filtros normales) ---
$qa_msg = null;
$qa_type = $_GET['q_type'] ?? '';
$qa_val  = $_GET['q_val'] ?? 0;

if ($qa_type === 'count_approved' && is_numeric($qa_val)) {
    // Calcular cuántos estudiantes aprobaron N materias (Unificando Lengua)
    try {
        // Obtenemos TODAS las notas sin filtros para cálculo global
        $sql_all = "SELECT r.usuario_id, q.titulo, r.puntos_obtenidos, 
                       (CASE WHEN q.titulo ILIKE '%Preguntas Abiertas%' THEN 20.0 ELSE 250.0 END) as max_puntos
                    FROM resultados r 
                    JOIN quizzes q ON r.quiz_id = q.id";
        $stmt_all = $pdo->query($sql_all);
        $all_rows = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

        $students = [];
        foreach ($all_rows as $rw) {
            $uid = $rw['usuario_id'];
            $tit = $rw['titulo'];
            $pts = (float)$rw['puntos_obtenidos'];
            $max = (float)$rw['max_puntos'];
            $score = ($max > 0) ? ($pts / $max) * 100 : 0;

            if (!isset($students[$uid])) $students[$uid] = [];
            
            // Normalizar nombres de materias para agrupación
            if (stripos($tit, 'Lengua y Literatura') !== false) {
                // Separar componente
                if (stripos($tit, 'Preguntas Abiertas') !== false) {
                    $students[$uid]['Lengua']['abierta'] = $score;
                } else {
                    $students[$uid]['Lengua']['teoria'] = $score;
                }
            } else {
                $students[$uid][$tit] = $score;
            }
        }

        $count_approved_N = 0;
        foreach ($students as $uid => $materias) {
            $materias_aprobadas = 0;
            foreach ($materias as $key => $val) {
                $final_score = 0;
                if ($key === 'Lengua') {
                    // Calculo unificado 80/20
                    $t = $val['teoria'] ?? 0;
                    $a = $val['abierta'] ?? 0;
                    // Si falta alguna parte, asumimos 0 en esa parte (o podríamos promediar solo lo que hay, pero 80/20 es estricto)
                    $final_score = ($t * 0.8) + ($a * 0.2);
                } else {
                    $final_score = $val; // Es un valor directo
                }

                if ($final_score >= 70) {
                    $materias_aprobadas++;
                }
            }
            if ($materias_aprobadas >= $qa_val) {
                $count_approved_N++;
            }
        }
        $qa_msg = "Estudiantes que aprobaron $qa_val o más materias: <strong>$count_approved_N</strong>";

    } catch (PDOException $e) {
        $qa_msg = "Error calculando datos QA: " . $e->getMessage();
    }
}

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
// Dado que la nota es calculada, usaremos la fórmula en el WHERE con el CASE dinámico
$max_score_expr = "(CASE WHEN q.titulo ILIKE '%Preguntas Abiertas%' THEN 20.0 ELSE 250.0 END)";

if ($min_nota !== '') {
    $sql .= " AND ((r.puntos_obtenidos / $max_score_expr) * 100) >= :min_nota";
    $params['min_nota'] = $min_nota;
}
if ($max_nota !== '') {
    $sql .= " AND ((r.puntos_obtenidos / $max_score_expr) * 100) <= :max_nota";
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
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC); // Standardize to $resultados
} catch (PDOException $e) {
    die("Error cargando resultados: " . $e->getMessage());
}

// --- 5. LOGICA DE ANALITICAS (Refactorizada + Metricas Nuevas) ---
require_once 'includes/analytics_data.php';

// Calcular Estadísticas Avanzadas
$sectionStats = calculateSectionStats($resultados);
// Solo analizar destrezas si hay un quiz específico seleccionado y no es un grupo merged 
// --- 1. SKILLS ANALYSIS (DESTREZAS) ---
// Default: Analyze selected quiz
$target_quiz_id = $quiz_id;

// Logic Swap for Merged Exams (Lengua y Literatura)
// User Request: "Instead of showing Lengua, show Open Questions inside the standard Skills table"
if ($quiz_id && count($merged_quiz_ids) > 0) {
    // Find the 'Preguntas Abiertas' ID within the merged set
    foreach ($merged_quiz_ids as $mqid) {
        $stmt_t = $pdo->prepare("SELECT titulo FROM quizzes WHERE id = ?");
        $stmt_t->execute([$mqid]);
        $tit = $stmt_t->fetchColumn();
        
        if (stripos($tit, 'Preguntas Abiertas') !== false) {
            $target_quiz_id = $mqid;
            break; // Use this one
        }
    }
}

// Calculate Stats using the Target ID (either the single quiz, or the Open Questions one)
// This populates the STANDARD table regardless of merged status
$skillsStats = ($target_quiz_id) ? analyzeSkillsDiff($pdo, $target_quiz_id) : [];

// We no longer need separate merged stats tables since we swapped the main one
$mergedSkillsStats = [];

// 5. OBTENER DATOS AGREGADOS Y PREDICCIONES (MÁS EFICIENTE)
try {
    // Re-usar la cláusula WHERE de la consulta principal (ya capturada en $where_chunk antes del ORDER BY)

// SQL Helper for Max Score
    $max_score_case = "(CASE WHEN q.titulo ILIKE '%Preguntas Abiertas%' THEN 20.0 ELSE 250.0 END)";

    // Promedio General
    $sql_avg = "SELECT AVG((r.puntos_obtenidos / $max_score_case) * 100) FROM resultados r 
                JOIN quizzes q ON r.quiz_id = q.id 
                WHERE 1=1" . $where_chunk; 
    // Note: $where_chunk uses alias 'r' and 'u', but q needs to be joined if not already. 
    // The original main query already joins quizzes q. 
    // The previous $where_chunk extraction was from a query that JOINED q.
    // However, the $sql_avg constructed here did NOT join q in the original code (it was just FROM resultados r).
    // Accessing q.titulo requires JOINing quizzes q.
    // Let's verify context: Line 48: JOIN quizzes q ON r.quiz_id = q.id.
    // The $where_chunk starts with " AND..." so it safely appends conditions on r, u, q.
    // BUT checking existing code: $sql_avg = "... FROM resultados r WHERE 1=1" . $where_chunk;
    // Original code Line 154 did NOT join quizzes q. If $where_chunk contained 'q.titulo', it would fail.
    // But filters are mostly on r (quiz_id, fecha, genero, edad, paralelo). 
    // IF we use q.titulo in the select expression, we MUST join q.
    
    // REDEFINING QUERIES TO INCLUDE JOIN
    $join_q = " JOIN quizzes q ON r.quiz_id = q.id ";

    // Total Examenes (No change needed, but good to be safe)
    $stmt_tot = $pdo->prepare("SELECT COUNT(*) FROM resultados r $join_q WHERE 1=1" . $where_chunk);
    $stmt_tot->execute($params);
    $total_examenes = $stmt_tot->fetchColumn();

    // Promedio General
    $sql_avg = "SELECT AVG((r.puntos_obtenidos / $max_score_case) * 100) FROM resultados r $join_q WHERE 1=1" . $where_chunk;
    $stmt_avg = $pdo->prepare($sql_avg);
    $stmt_avg->execute($params);
    $promedio_general = round($stmt_avg->fetchColumn(), 2);

    // Tasa de Aprobación
    $sql_aprobados = "SELECT COUNT(*) FROM resultados r $join_q WHERE ((r.puntos_obtenidos / $max_score_case) * 100) >= 70" . $where_chunk;
    $stmt_aprob = $pdo->prepare($sql_aprobados);
    $stmt_aprob->execute($params);
    $aprobados_count = $stmt_aprob->fetchColumn();
    $tasa_aprobacion = $total_examenes > 0 ? round(($aprobados_count / $total_examenes) * 100, 1) : 0;

    // Incidentes de Seguridad
    $sql_anomalias = "SELECT COUNT(*) FROM resultados r $join_q WHERE (r.intentos_tab_switch > 1 OR r.segundos_fuera > 15)" . $where_chunk;
    $stmt_anom = $pdo->prepare($sql_anomalias);
    $stmt_anom->execute($params);
    $total_anomalias = $stmt_anom->fetchColumn();

    // --- PREDICCIONES ---
    // Riesgo de Deserción
    $sql_risk = "SELECT COUNT(*) FROM resultados r $join_q WHERE ((r.puntos_obtenidos / $max_score_case) * 100) < 70 AND (r.intentos_tab_switch > 1 OR r.segundos_fuera > 15)" . $where_chunk;
    $stmt_risk = $pdo->prepare($sql_risk);
    $stmt_risk->execute($params);
    $risk_count = (int)$stmt_risk->fetchColumn();

    // Alta Probabilidad de Excelencia
    $sql_success = "SELECT COUNT(*) FROM resultados r $join_q WHERE ((r.puntos_obtenidos / $max_score_case) * 100) >= 90 AND r.intentos_tab_switch = 0" . $where_chunk;
    $stmt_success = $pdo->prepare($sql_success);
    $stmt_success->execute($params);
    $success_count = (int)$stmt_success->fetchColumn();

    // --- DATA FOR NEW CHARTS ---

    // 1. Timeline (Exams per Day)
    // Note: Adjusting date format for SQL group by based on typical DB (assuming PostgreSQL/MySQL)
    $stmt_timeline = $pdo->prepare("SELECT DATE(fecha_realizacion) as fecha, COUNT(*) as count FROM resultados r $join_q WHERE 1=1 " . $where_chunk . " GROUP BY DATE(fecha_realizacion) ORDER BY fecha ASC");
    $stmt_timeline->execute($params);
    $timeline_data = $stmt_timeline->fetchAll(PDO::FETCH_ASSOC);

    // 2. Score Distribution (Histogram buckets: <60, 60-70, 70-80, 80-90, 90-100)
    // We will calculate this from the full results fetch to save a complex DB query
    
    // 3. Demographics (Gender, Parallel) - also calculated from full results

    // Finalmente, obtener la lista de resultados para la tabla
    // Finalmente, obtener la lista de resultados para la tabla
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- GENERATE CONCLUSIONS (Refactored) ---
    $conclusions = getConclusions($promedio_general, $tasa_aprobacion, $total_anomalias, $total_examenes);
    $rendimientoMsg = $conclusions['rendimiento'];
    $aprobacionMsg = $conclusions['aprobacion'];
    $seguridadMsg = $conclusions['seguridad'];

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
    // Ajuste dinámico de nota máxima según el tipo de examen
    $max_puntos = (stripos($row['quiz_titulo'], 'Preguntas Abiertas') !== false) ? 20 : 250;
    
    $nota_calculada = ($max_puntos > 0) ? ($puntos_obtenidos / $max_puntos) * 100 : 0;
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
    $row_edad = $row['edad'] ?? 'N/A';
    if ($row_edad !== 'N/A') {
        if (!isset($dist_edad[$row_edad])) {
            $dist_edad[$row_edad] = ['count' => 0, 'sum_notas' => 0];
        }
        $dist_edad[$row_edad]['count']++;
        $dist_edad[$row_edad]['sum_notas'] += $nota_final;
    }
}
ksort($dist_paralelo); // Sort parallels A-Z
ksort($dist_edad); // Sort ages numerically

// 7. DATOS UNIFICADOS Y CONCLUSIONES (Lógica Servidor)

// A. Obtener TODOS los resultados relevantes para cálculo global (respetando filtros generales de fecha/genero/edad si aplican, 
// pero IGNORANDO filtros de quiz/paralelo para los gráficos comparativos globales)
// Sin embargo, para las "Conclusiones" y "Gráficos Globales", idealmente queremos el panorama completo del curso.
// Asumiremos que los filtros de FECHA/EDAD/GENERO refinan la "Población de Estudio", pero quiz/paralelo no deben limitar la comparación.

$sql_raw_global = "SELECT 
                r.usuario_id, 
                r.paralelo,
                r.genero,
                q.titulo as quiz_titulo,
                r.puntos_obtenidos,
                (CASE WHEN q.titulo ILIKE '%Preguntas Abiertas%' THEN 20.0 ELSE 250.0 END) as max_puntos
            FROM resultados r
            JOIN quizzes q ON r.quiz_id = q.id
            WHERE 1=1";

$params_raw = [];
if ($fecha_desde) { $sql_raw_global .= " AND r.fecha_realizacion >= :rd_desde"; $params_raw['rd_desde'] = $fecha_desde . ' 00:00:00'; }
if ($fecha_hasta) { $sql_raw_global .= " AND r.fecha_realizacion <= :rd_hasta"; $params_raw['rd_hasta'] = $fecha_hasta . ' 23:59:59'; }
if ($genero) { $sql_raw_global .= " AND r.genero = :rd_genero"; $params_raw['rd_genero'] = $genero; }
if ($edad) { $sql_raw_global .= " AND r.edad = :rd_edad"; $params_raw['rd_edad'] = $edad; }

// APLICAR FILTRO DE QUIZ TAMBIEN AQUI (Solicitud Usuario)
if ($quiz_id) {
    // Reutilizar lógica de merged_quiz_ids si ya fue calculada arriba, o simple id
    if (!empty($merged_quiz_ids)) {
        $placeholders_g = [];
        foreach ($merged_quiz_ids as $idx => $qid) {
            $key = "rd_mq_$idx";
            $placeholders_g[] = ":$key";
            $params_raw[$key] = $qid;
        }
        $sql_raw_global .= " AND r.quiz_id IN (" . implode(',', $placeholders_g) . ")";
    } else {
        $sql_raw_global .= " AND r.quiz_id = :rd_quiz_id";
        $params_raw['rd_quiz_id'] = $quiz_id;
    }
}

try {
    $stmt_rg = $pdo->prepare($sql_raw_global);
    $stmt_rg->execute($params_raw);
    $all_results_raw = $stmt_rg->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_results_raw = [];
}

// B. PROCESAMIENTO UNIFICADO (Por Estudiante)
$estudiantes_calcs = [];

foreach ($all_results_raw as $row) {
    $uid = $row['usuario_id'];
    $tit = $row['quiz_titulo'];
    $par = strtoupper($row['paralelo'] ?? 'SIN PARALELO');
    $gen = $row['genero'];
    
    // Calcular nota sobre 100
    $pts = (float)$row['puntos_obtenidos'];
    $max = (float)$row['max_puntos'];
    $score = ($max > 0) ? ($pts / $max) * 100 : 0;

    if (!isset($estudiantes_calcs[$uid])) {
        $estudiantes_calcs[$uid] = [
            'materias' => [],
            'meta' => ['paralelo' => $par, 'genero' => $gen]
        ];
    }

    // Lógica 80/20 para Lengua
    if (stripos($tit, 'Lengua y Literatura') !== false) {
        if (stripos($tit, 'Preguntas Abiertas') !== false) {
            $estudiantes_calcs[$uid]['materias']['Lengua y Literatura']['abierta'] = $score;
        } else {
            $estudiantes_calcs[$uid]['materias']['Lengua y Literatura']['teoria'] = $score;
        }
    } else {
        $estudiantes_calcs[$uid]['materias'][$tit]['nota_unica'] = $score;
    }
}

// C. CALCULAR NOTAS FINALES Y METRICAS GLOBALES
$stats_materias_global = []; // Promedios por materia
$stats_paralelos = []; // Notas por paralelo (para identificar mejor paralelo)
$stats_genero = []; // Notas por genero
$stats_jornada = ['Matutina' => ['sum'=>0, 'count'=>0], 'Vespertina' => ['sum'=>0, 'count'=>0]]; // A-E vs F-H

$conteo_aprobadas_2 = 0;
$conteo_aprobadas_3 = 0;

$data_chart_mat_par_unified = []; // Estructura: [Materia][Paralelo] = Promedio

foreach ($estudiantes_calcs as $uid => $data) {
    $par = strtoupper(!empty($data['meta']['paralelo']) ? $data['meta']['paralelo'] : 'SIN PARALELO');
    $gen = !empty($data['meta']['genero']) ? ucfirst(strtolower($data['meta']['genero'])) : 'No especificado';
    
    // Determinar jornada
    $jornada = (preg_match('/^[A-E]$/', $par)) ? 'Matutina' : 'Vespertina';

    $materias_aprobadas_user = 0;

    foreach ($data['materias'] as $materia_nombre => $components) {
        // Calcular Nota Final Unificada
        $final_score = 0;
        if ($materia_nombre === 'Lengua y Literatura') {
            $t = $components['teoria'] ?? 0;
            $a = $components['abierta'] ?? 0;
            $final_score = ($t * 0.8) + ($a * 0.2);
        } else {
            $final_score = $components['nota_unica'] ?? 0;
        }

        // 1. Métricas de Aprobación
        if ($final_score >= 70) $materias_aprobadas_user++;

        // 2. Acumular para Gráficos Globales (Materia vs Paralelo)
        if (!isset($data_chart_mat_par_unified[$materia_nombre][$par])) {
            $data_chart_mat_par_unified[$materia_nombre][$par] = ['sum'=>0, 'count'=>0];
        }
        $data_chart_mat_par_unified[$materia_nombre][$par]['sum'] += $final_score;
        $data_chart_mat_par_unified[$materia_nombre][$par]['count']++;

        // 3. Acumular para Promedios Generales por Materia
        if (!isset($stats_materias_global[$materia_nombre])) {
            $stats_materias_global[$materia_nombre] = ['sum'=>0, 'count'=>0, 'aprobados'=>0];
        }
        $stats_materias_global[$materia_nombre]['sum'] += $final_score;
        $stats_materias_global[$materia_nombre]['count']++;
        if ($final_score >= 70) $stats_materias_global[$materia_nombre]['aprobados']++;

        // 4. Métricas de Segmento (Para Conclusiones)
        // Por Paralelo
        if (!isset($stats_paralelos[$par])) $stats_paralelos[$par] = ['sum'=>0, 'count'=>0];
        $stats_paralelos[$par]['sum'] += $final_score;
        $stats_paralelos[$par]['count']++;

        // Por Genero
        if (!isset($stats_genero[$gen])) $stats_genero[$gen] = ['sum'=>0, 'count'=>0];
        $stats_genero[$gen]['sum'] += $final_score;
        $stats_genero[$gen]['count']++;

        // Por Jornada
        $stats_jornada[$jornada]['sum'] += $final_score;
        $stats_jornada[$jornada]['count']++;
    }

    if ($materias_aprobadas_user >= 2) $conteo_aprobadas_2++;
    if ($materias_aprobadas_user >= 3) $conteo_aprobadas_3++;
}

// D. PREPARAR DATOS FINALES PARA GRAFICOS JS
// Grafico 1: Promedios por Materia (Unificado)
$stats_por_quiz_unified = [];
foreach ($stats_materias_global as $mat => $d) {
    // Renombrar si es necesario, pero ya unificamos en el loop
    $stats_por_quiz_unified[$mat] = [
        'promedio' => round($d['sum'] / $d['count'], 2),
        'total' => $d['count'], // Esto es total de *exámenes* (o estudiantes únicos por materia)
        'aprobados' => $d['aprobados']
    ];
}

// Grafico 2: Materia vs Paralelo (Grid)
// Obtener lista completa de paralelos del dataset
$all_pars = array_keys($stats_paralelos);
sort($all_pars);
$paralelos_lista = $all_pars; // Usar globalmente

$data_chart_mat_par_final = [];
foreach ($data_chart_mat_par_unified as $mat => $par_data) {
    $row_data = [];
    foreach ($paralelos_lista as $p) {
        if (isset($par_data[$p])) {
            $row_data[] = round($par_data[$p]['sum'] / $par_data[$p]['count'], 2);
        } else {
            $row_data[] = 0;
        }
    }
    $data_chart_mat_par_final[$mat] = $row_data;
}

// E. CONCLUSIONES (Top Stats)
// Mejor Paralelo
$best_par_name = 'N/A';
$best_par_avg = -1;
foreach ($stats_paralelos as $p => $d) {
    // Filtrar Solo Paralelos Validos (A-H)
    if (!preg_match('/^[A-H]$/', $p)) continue;
    
    $avg = $d['count'] > 0 ? $d['sum'] / $d['count'] : 0;
    if ($avg > $best_par_avg) { $best_par_avg = $avg; $best_par_name = $p; }
}

// Mejor Genero
$best_gen_name = 'N/A';
$best_gen_avg = -1;
foreach ($stats_genero as $g => $d) {
    // Filtrar Solo Generos Validos (Masculino, Femenino)
    if (!in_array($g, ['Masculino', 'Femenino'])) continue;

    $avg = $d['count'] > 0 ? $d['sum'] / $d['count'] : 0;
    if ($avg > $best_gen_avg) { $best_gen_avg = $avg; $best_gen_name = $g; }
}

// Mejor Jornada
$best_shift_name = 'N/A';
$best_shift_avg = -1;
foreach ($stats_jornada as $j => $d) {
    $avg = ($d['count'] ?? 0) > 0 ? $d['sum'] / $d['count'] : 0;
    if ($avg > $best_shift_avg) { $best_shift_avg = $avg; $best_shift_name = $j; }
}

// Mejor Materia (Area)
$best_area_name = 'N/A';
$best_area_avg = -1;
foreach ($stats_materias_global as $m => $d) {
    $avg = ($d['count'] ?? 0) > 0 ? $d['sum'] / $d['count'] : 0;
    if ($avg > $best_area_avg) { $best_area_avg = $avg; $best_area_name = $m; }
}

// $best_par_name, $best_par_avg
// $best_gen_name, $best_gen_avg
// $best_shift_name, $best_shift_avg
// $stats_por_quiz_unified (JSON)
// $data_chart_mat_par_final (JSON)

// --- NUEVO: CÁLCULOS DE TASA DE APROBACIÓN POR ESTUDIANTE (FILTRO A-H) ---
$total_valid_students = 0;
$students_approved_global = 0;
$total_valid_students = 0;
$students_approved_global = 0;
// SOLO MOSTRAR DESDE 1 EN ADELANTE (Pedido usuario: sacar el 0)
$distribucion_aprobadas = ['1'=>0, '2'=>0, '3'=>0, '4+'=>0];

foreach ($estudiantes_calcs as $uid => $data) {
    $par = strtoupper(!empty($data['meta']['paralelo']) ? $data['meta']['paralelo'] : 'SIN PARALELO');
    // Filtro estricto: Solo A-H para métricas globales de estudiantes
    if (!preg_match('/^[A-H]$/', $par)) continue;

    $total_valid_students++;

    // 1. Calcular Promedio Global del Estudiante
    $sum_scores = 0;
    $count_subs = 0;
    $approved_subs_count = 0;

    foreach ($data['materias'] as $m => $comps) {
        $sc = 0;
        if ($m === 'Lengua y Literatura') {
            $sc = ($comps['teoria']??0)*0.8 + ($comps['abierta']??0)*0.2;
        } else {
            $sc = $comps['nota_unica']??0;
        }
        $sum_scores += $sc;
        $count_subs++;
        if ($sc >= 70) $approved_subs_count++;
    }

    $global_avg_student = ($count_subs > 0) ? $sum_scores / $count_subs : 0;
    if ($global_avg_student >= 70) {
        $students_approved_global++;
    }

    // 2. Distribución de Materias Aprobadas
    if ($approved_subs_count >= 4) {
        $distribucion_aprobadas['4+']++;
    } elseif ($approved_subs_count > 0) {
        // Solo registrar si aprobó al menos 1
        $distribucion_aprobadas[(string)$approved_subs_count]++;
    }
}

// SOBRESCRIBIR TASA DE APROBACIÓN GLOBAL (Para el gráfico existente)
$tasa_aprobacion = ($total_valid_students > 0) ? round(($students_approved_global / $total_valid_students) * 100, 1) : 0;
// Actualizar contador para el label
$aprobados_count = $students_approved_global;
$total_examenes = $total_valid_students; // Hack visual: el label dirá "X de Y" (donde Y es estudiantes)
// $stats_por_quiz_unified (JSON)
// $data_chart_mat_par_final (JSON)
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
    
    <link rel="stylesheet" href="css/custom_dashboard.css">
</head>
<body class="bg-light">

<!-- Smart Search Styles Moved to custom_dashboard.css -->

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
            <div class="col-md-3 d-flex align-items-end gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                <div class="btn-group flex-grow-1" role="group">
                    <button type="button" class="btn btn-success btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-file-csv me-1"></i>Exportar
                    </button>
                    <ul class="dropdown-menu">
                        <li class="dropdown-header">Exportar con filtros actuales</li>
                        <li>
                            <a class="dropdown-item small" href="exportar_excel.php?<?php 
                                $export_params = $_GET;
                                unset($export_params['mode']); // Remove mode param for simple export
                                echo http_build_query($export_params);
                            ?>" target="_blank">
                                <i class="fas fa-table me-2 text-success"></i>Datos Filtrados (Simple)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item small" href="exportar_excel.php?<?php 
                                $export_full = $_GET;
                                unset($export_full['muestra']); // Remove sample filter for full export
                                $export_full['mode'] = 'full'; // Add full mode
                                echo http_build_query($export_full);
                            ?>" target="_blank">
                                <i class="fas fa-file-csv me-2 text-primary"></i>Datos Filtrados (Con Preguntas)
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li class="dropdown-header">Exportar TODO (sin filtros)</li>
                        <li>
                            <a class="dropdown-item small fw-bold" href="exportar_excel.php" target="_blank">
                                <i class="fas fa-database me-2 text-danger"></i>TODO (Simple)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item small fw-bold" href="exportar_excel.php?mode=full" target="_blank">
                                <i class="fas fa-table me-2 text-danger"></i>TODO (Con Preguntas)
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item small" href="exportar_excel.php?<?= http_build_query(array_merge($_GET, ['muestra' => 'si'])) ?>" target="_blank">
                                <i class="fas fa-filter me-2 text-warning"></i>Solo Muestra
                            </a>
                        </li>
                    </ul>
                </div>
                <a href="?" class="btn btn-light btn-sm" title="Limpiar Filtros"><i class="fas fa-undo"></i></a>
            </div>
        </form>
    </div>

    <!-- Analytics Section MOVED TO TOP -->
    <?php if (!empty($stats_por_quiz_unified)): ?>
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

        <!-- Row 3: Ladder Chart -->
        <div class="row g-4 mb-4">
            <div class="col-12">
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

        <!-- Row 5: Parallel vs Subject (New) -->
        <div class="row mt-4 mb-4">
            <div class="col-12">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                         <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-layer-group me-2 text-primary"></i>Rendimiento por Asignatura y Paralelo (Unificado)
                        </h6>
                    </div>
                    <div style="height: 400px;">
                        <canvas id="chartMateriaParalelo"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 6: CONCLUSIONES DEL ANÁLISIS (New) -->
        <div class="row mt-4 mb-4">
            <div class="col-12">
                <div class="card-custom p-4 border-start border-4 border-info">
                    <h5 class="fw-bold mb-3 text-dark">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Conclusiones del Análisis
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card-custom p-3 border shadow-sm h-100">
                                <h6 class="text-center small fw-bold text-muted mb-2">Materias Aprobadas por Estudiante</h6>
                                <div style="height: 180px; position: relative;">
                                    <canvas id="chartPastelAprobadas"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                                    <span><i class="fas fa-venus-mars me-2 text-secondary"></i>Mejor Rendimiento (Género)</span>
                                    <span class="badge bg-purple text-dark fw-bold"><?= ($best_gen_name !== '' && $best_gen_name !== 'N/A') ? $best_gen_name : 'No Definido' ?> (Avg: <?= round($best_gen_avg, 2) ?>)</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                                    <span><i class="fas fa-users me-2 text-secondary"></i>Paralelo Destacado</span>
                                    <span class="badge bg-info text-dark fw-bold"><?= $best_par_name ?> (Avg: <?= round($best_par_avg, 2) ?>)</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                                    <span><i class="fas fa-sun me-2 text-secondary"></i>Jornada Destacada</span>
                                    <span class="badge bg-warning text-dark fw-bold"><?= $best_shift_name ?> (Avg: <?= round($best_shift_avg, 2) ?>)</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                                    <span><i class="fas fa-book me-2 text-secondary"></i>Mejor Asignatura</span>
                                    <span class="badge bg-success text-white fw-bold"><?= $best_area_name ?> (Avg: <?= round($best_area_avg, 2) ?>)</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 7: Shift Performance (Jornada) -->
        <!-- Row 7: Shift Performance (Jornada) & Gender Performance (New) -->
        <div class="row mt-4 mb-4">
            <div class="col-md-6">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                         <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-cloud-sun me-2 text-warning"></i>Rendimiento por Jornada
                        </h6>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="chartJornada"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                         <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-venus-mars me-2 text-purple"></i>Rendimiento por Género
                        </h6>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="chartRendimientoGenero"></canvas>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- NEW ANALYTICS SECTIONS (Jornada, Skills, Conclusions) -->
    <?php if ($total_examenes > 0): ?>
    
    <!-- Row 5: Section Analysis (New) -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card-custom p-4">
                <h6 class="fw-bold mb-4 text-dark"><i class="fas fa-building me-2 text-primary"></i>Análisis por Jornada</h6>
                
                <div class="row text-center mb-4">
                    <!-- Matutina Summary -->
                    <div class="col-md-6 border-end">
                        <h6 class="text-uppercase text-muted small fw-bold mb-3">Matutina (A-D)</h6>
                        <div class="row">
                            <div class="col-4">
                                <div class="display-6 fw-bold text-primary"><?= $sectionStats['Matutina']['total'] ?></div>
                                <div class="small text-muted">Estudiantes</div>
                            </div>
                            <div class="col-4">
                                <?php 
                                    $promM = $sectionStats['Matutina']['total'] > 0 
                                        ? number_format($sectionStats['Matutina']['sum_notas'] / $sectionStats['Matutina']['total'], 1) 
                                        : 0;
                                ?>
                                <div class="display-6 fw-bold text-dark"><?= $promM ?></div>
                                <div class="small text-muted">Promedio</div>
                            </div>
                            <div class="col-4">
                                <?php 
                                    $aprobM = $sectionStats['Matutina']['total'] > 0 
                                        ? number_format(($sectionStats['Matutina']['aprobados'] / $sectionStats['Matutina']['total']) * 100, 1) . '%'
                                        : '0%';
                                ?>
                                <div class="display-6 fw-bold text-success"><?= $aprobM ?></div>
                                <div class="small text-muted">Aprobación</div>
                            </div>
                        </div>
                        <div class="mt-3 small text-muted">
                            Hombres: <b><?= $sectionStats['Matutina']['genero']['Masculino'] ?></b> | 
                            Mujeres: <b><?= $sectionStats['Matutina']['genero']['Femenino'] ?></b>
                        </div>
                    </div>

                    <!-- Vespertina Summary -->
                    <div class="col-md-6">
                        <h6 class="text-uppercase text-muted small fw-bold mb-3">Vespertina (E-H)</h6>
                        <div class="row">
                            <div class="col-4">
                                <div class="display-6 fw-bold text-info"><?= $sectionStats['Vespertina']['total'] ?></div>
                                <div class="small text-muted">Estudiantes</div>
                            </div>
                            <div class="col-4">
                                <?php 
                                    $promV = $sectionStats['Vespertina']['total'] > 0 
                                        ? number_format($sectionStats['Vespertina']['sum_notas'] / $sectionStats['Vespertina']['total'], 1) 
                                        : 0;
                                ?>
                                <div class="display-6 fw-bold text-dark"><?= $promV ?></div>
                                <div class="small text-muted">Promedio</div>
                            </div>
                            <div class="col-4">
                                <?php 
                                    $aprobV = $sectionStats['Vespertina']['total'] > 0 
                                        ? number_format(($sectionStats['Vespertina']['aprobados'] / $sectionStats['Vespertina']['total']) * 100, 1) . '%'
                                        : '0%';
                                ?>
                                <div class="display-6 fw-bold text-success"><?= $aprobV ?></div>
                                <div class="small text-muted">Aprobación</div>
                            </div>
                        </div>
                        <div class="mt-3 small text-muted">
                            Hombres: <b><?= $sectionStats['Vespertina']['genero']['Masculino'] ?></b> | 
                            Mujeres: <b><?= $sectionStats['Vespertina']['genero']['Femenino'] ?></b>
                        </div>
                    </div>
                </div>

                <!-- Detail Tables -->
                <div class="row">
                    <!-- MATUTINA TABLE -->
                    <div class="col-md-6 border-end">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover small mb-0">
                                <thead class="table-light">
                                    <tr class="text-center">
                                        <th class="text-start">Paralelo</th>
                                        <th>Estud. (Únicos)</th>
                                        <th>Prom.</th>
                                        <th class="border-start text-primary"><i class="fas fa-mars"></i> Hombres</th>
                                        <th class="border-start text-danger"><i class="fas fa-venus"></i> Mujeres</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    ksort($sectionStats['Matutina']['paralelos']);
                                    foreach($sectionStats['Matutina']['paralelos'] as $par => $d): 
                                        // Averages based on EXAMS (scores / exams taken)
                                        $avgH = $d['exams_hombres'] > 0 ? number_format($d['sum_hombres']/$d['exams_hombres'], 1) : '-';
                                        $avgM = $d['exams_mujeres'] > 0 ? number_format($d['sum_mujeres']/$d['exams_mujeres'], 1) : '-';
                                        $avgT = $d['total_exams'] > 0 ? number_format($d['sum_score']/$d['total_exams'], 1) : '-';
                                    ?>
                                    <tr class="text-center align-middle">
                                        <td class="text-start fw-bold bg-light text-dark"><?= $par ?></td>
                                        <td class="fw-bold"><?= $d['count_unique'] ?></td>
                                        <td class="fw-bold text-dark"><?= $avgT ?></td>
                                        
                                        <td class="border-start bg-blue-50">
                                            <div class="d-flex justify-content-center gap-2">
                                                <span class="badge bg-primary-soft text-primary small" title="Estudiantes Únicos"><?= $d['count_hombres'] ?></span>
                                                <span class="small fw-bold text-muted" title="Promedio General"><?= $avgH ?></span>
                                            </div>
                                        </td>
                                        
                                        <td class="border-start bg-red-50">
                                            <div class="d-flex justify-content-center gap-2">
                                                <span class="badge bg-danger-soft text-danger small" title="Estudiantes Únicas"><?= $d['count_mujeres'] ?></span>
                                                <span class="small fw-bold text-muted" title="Promedio General"><?= $avgM ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- VESPERTINA TABLE -->
                    <div class="col-md-6">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover small mb-0">
                                <thead class="table-light">
                                    <tr class="text-center">
                                        <th class="text-start">Paralelo</th>
                                        <th>Estud. (Únicos)</th>
                                        <th>Prom.</th>
                                        <th class="border-start text-primary"><i class="fas fa-mars"></i> Hombres</th>
                                        <th class="border-start text-danger"><i class="fas fa-venus"></i> Mujeres</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    ksort($sectionStats['Vespertina']['paralelos']);
                                    foreach($sectionStats['Vespertina']['paralelos'] as $par => $d): 
                                        // Averages based on EXAMS (scores / exams taken)
                                        $avgH = $d['exams_hombres'] > 0 ? number_format($d['sum_hombres']/$d['exams_hombres'], 1) : '-';
                                        $avgM = $d['exams_mujeres'] > 0 ? number_format($d['sum_mujeres']/$d['exams_mujeres'], 1) : '-';
                                        $avgT = $d['total_exams'] > 0 ? number_format($d['sum_score']/$d['total_exams'], 1) : '-';
                                    ?>
                                    <tr class="text-center align-middle">
                                        <td class="text-start fw-bold bg-light text-dark"><?= $par ?></td>
                                        <td class="fw-bold"><?= $d['count_unique'] ?></td>
                                        <td class="fw-bold text-dark"><?= $avgT ?></td>
                                        
                                        <td class="border-start bg-blue-50">
                                            <div class="d-flex justify-content-center gap-2">
                                                <span class="badge bg-primary-soft text-primary small" title="Estudiantes Únicos"><?= $d['count_hombres'] ?></span>
                                                <span class="small fw-bold text-muted" title="Promedio General"><?= $avgH ?></span>
                                            </div>
                                        </td>
                                        
                                        <td class="border-start bg-red-50">
                                            <div class="d-flex justify-content-center gap-2">
                                                <span class="badge bg-danger-soft text-danger small" title="Estudiantes Únicas"><?= $d['count_mujeres'] ?></span>
                                                <span class="small fw-bold text-muted" title="Promedio General"><?= $avgM ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 6: Skills Analysis (Enhanced) -->
    <?php if (!empty($skillsStats)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card-custom p-4 bg-white border-danger border-start border-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="fw-bold mb-1 text-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>Destrezas con Mayor Dificultad
                        </h6>
                        <p class="small text-muted mb-0">Las <?= count($skillsStats) ?> preguntas con menor tasa de aciertos.</p>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="exportSkillsToCSV()">
                        <i class="fas fa-file-csv me-1"></i>Exportar CSV
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless mb-0" id="skillsTable">
                        <thead>
                            <tr class="text-secondary small text-uppercase">
                                <th width="60">#</th>
                                <th>Pregunta</th>
                                <th class="text-center" width="150">Tasa de Aciertos</th>
                                <th width="200">Estudiantes (Errores)</th>
                                <th class="text-end" width="100">Intentos</th>
                                <th class="text-end" width="100">Errores</th>
                                <th class="text-end" width="100">Correctas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($skillsStats as $index => $skill): 
                                $rate = $skill['total_intentos'] > 0 ? ($skill['correctas'] / $skill['total_intentos']) * 100 : 0;
                                $barColor = $rate < 30 ? 'bg-danger' : ($rate < 50 ? 'bg-warning' : 'bg-info');
                                $textColor = $rate < 30 ? 'text-danger' : ($rate < 50 ? 'text-warning' : 'text-info');
                            ?>
                            <tr>
                                <td class="align-middle text-center">
                                    <span class="badge bg-light text-dark fw-bold"><?= $index + 1 ?></span>
                                </td>
                                <td class="align-middle">
                                    <div class="fw-medium text-dark mb-2" style="line-height: 1.4;">
                                        <?= htmlspecialchars($skill['texto']) ?>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= $barColor ?>" role="progressbar" style="width: <?= $rate ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-center align-middle">
                                    <span class="fw-bold <?= $textColor ?> fs-5"><?= number_format($rate, 1) ?>%</span>
                                </td>
                                <td class="align-middle">
                                    <div style="max-height: 80px; overflow-y: auto; font-size: 0.75rem;" class="text-muted">
                                        <?= !empty($skill['lista_errores']) ? str_replace(',', ', ', htmlspecialchars($skill['lista_errores'])) : '<span class="text-success fst-italic">Ninguno</span>' ?>
                                    </div>
                                </td>
                                <td class="text-end align-middle">
                                    <span class="badge bg-light text-dark"><?= $skill['total_intentos'] ?></span>
                                </td>
                                <td class="text-end align-middle">
                                    <span class="badge bg-danger-soft text-danger"><?= $skill['incorrectas'] ?? 0 ?></span>
                                </td>
                                <td class="text-end align-middle">
                                    <span class="badge bg-success-soft text-success"><?= $skill['correctas'] ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Legend -->
                <div class="mt-3 d-flex gap-3 small text-muted justify-content-end">
                    <div><i class="fas fa-circle text-danger me-1"></i> < 30% Muy difícil</div>
                    <div><i class="fas fa-circle text-warning me-1"></i> 30-50% Difícil</div>
                    <div><i class="fas fa-circle text-info me-1"></i> > 50% Moderado</div>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($quiz_id && count($merged_quiz_ids) > 1): ?>
        
    <!-- MERGED QUIZZES SKILLS ANALYSIS (Lengua & Literatura Exception) -->
    <?php foreach ($mergedSkillsStats as $mItem): ?>
        <?php $mStats = $mItem['stats']; ?>
        <?php if (!empty($mStats)): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card-custom p-4 bg-white border-danger border-start border-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold mb-1 text-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>Destrezas con Mayor Dificultad
                            </h6>
                            <p class="small text-muted mb-0">Examen: <strong><?= htmlspecialchars($mItem['titulo']) ?></strong></p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-borderless mb-0">
                            <thead>
                                <tr class="text-secondary small text-uppercase">
                                    <th width="60">#</th>
                                    <th>Pregunta</th>
                                    <th class="text-center" width="150">Tasa de Aciertos</th>
                                    <th width="200">Estudiantes (Errores)</th>
                                    <th class="text-end" width="100">Intentos</th>
                                    <th class="text-end" width="100">Errores</th>
                                    <th class="text-end" width="100">Correctas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mStats as $index => $skill): 
                                    $rate = $skill['total_intentos'] > 0 ? ($skill['correctas'] / $skill['total_intentos']) * 100 : 0;
                                    $barColor = $rate < 30 ? 'bg-danger' : ($rate < 50 ? 'bg-warning' : 'bg-info');
                                    $textColor = $rate < 30 ? 'text-danger' : ($rate < 50 ? 'text-warning' : 'text-info');
                                ?>
                                <tr>
                                    <td class="align-middle text-center">
                                        <span class="badge bg-light text-dark fw-bold"><?= $index + 1 ?></span>
                                    </td>
                                    <td class="align-middle">
                                        <div class="fw-medium text-dark mb-2" style="line-height: 1.4;">
                                            <?= htmlspecialchars($skill['texto']) ?>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar <?= $barColor ?>" role="progressbar" style="width: <?= $rate ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="fw-bold <?= $textColor ?> fs-5"><?= number_format($rate, 1) ?>%</span>
                                    </td>
                                    <td class="align-middle">
                                        <div style="max-height: 80px; overflow-y: auto; font-size: 0.75rem;" class="text-muted">
                                            <?= !empty($skill['lista_errores']) ? str_replace(',', ', ', htmlspecialchars($skill['lista_errores'])) : '<span class="text-success fst-italic">Ninguno</span>' ?>
                                        </div>
                                    </td>
                                    <td class="text-end align-middle">
                                        <span class="badge bg-light text-dark"><?= $skill['total_intentos'] ?></span>
                                    </td>
                                    <td class="text-end align-middle">
                                        <span class="badge bg-danger-soft text-danger"><?= $skill['incorrectas'] ?? 0 ?></span>
                                    </td>
                                    <td class="text-end align-middle">
                                        <span class="badge bg-success-soft text-success"><?= $skill['correctas'] ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- EMPTY STATE FOR MERGED EXAM -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card-custom p-4 bg-light border-secondary border-start border-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="text-secondary" style="font-size: 2rem;">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1 text-secondary">
                                Sin Datos Suficientes
                            </h6>
                            <p class="small text-muted mb-0">Examen: <strong><?= htmlspecialchars($mItem['titulo']) ?></strong></p>
                            <p class="small text-muted mb-0">No se encontraron preguntas con intentos registrados para este examen.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php elseif (!$quiz_id): ?>
    <!-- Message when no quiz is selected -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card-custom p-4 bg-light border-warning border-start border-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="text-warning" style="font-size: 2rem;">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-dark">
                            <i class="fas fa-exclamation-triangle me-2 text-warning"></i>Destrezas con Mayor Dificultad
                        </h6>
                        <p class="small text-muted mb-2">
                            Para ver las preguntas más difíciles, por favor:
                        </p>
                        <ol class="small text-muted mb-0 ps-3">
                            <li>Selecciona un <strong>examen específico</strong> en el filtro "Seleccionar Examen"</li>
                            <li>Haz clic en el botón <strong>"Filtrar"</strong></li>
                            <li>Aquí aparecerán las 5 preguntas con menor tasa de aciertos</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Row 7: Conclusions (Moved/New) -->
    <div class="mb-4">
        <div class="card-custom p-4 bg-gradient-to-br from-white to-gray-50">
            <h5 class="fw-bold mb-4 text-dark">
                <i class="fas fa-lightbulb me-2 text-warning"></i>Conclusiones Clave
            </h5>
            <div class="row g-4">
                <!-- Insight Cards -->
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(16, 185, 129, 0.1);">
                        <div class="text-success mt-1"><i class="fas fa-chart-line fa-lg"></i></div>
                        <div>
                            <h6 class="fw-bold text-success mb-1">Rendimiento General</h6>
                            <p class="small text-muted mb-0 lh-sm"><?= $rendimientoMsg ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(59, 130, 246, 0.1);">
                        <div class="text-primary mt-1"><i class="fas fa-users fa-lg"></i></div>
                        <div>
                            <h6 class="fw-bold text-primary mb-1">Tasa de Aprobación</h6>
                            <p class="small text-muted mb-0 lh-sm"><?= $aprobacionMsg ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: <?= strpos($seguridadMsg, 'críticas') !== false ? 'rgba(239, 68, 68, 0.1)' : 'rgba(245, 158, 11, 0.1)' ?>;">
                        <div class="<?= strpos($seguridadMsg, 'críticas') !== false ? 'text-danger' : 'text-warning' ?> mt-1">
                            <i class="fas fa-shield-alt fa-lg"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold <?= strpos($seguridadMsg, 'críticas') !== false ? 'text-danger' : 'text-warning' ?> mb-1">Integridad</h6>
                            <p class="small text-muted mb-0 lh-sm"><?= $seguridadMsg ?></p>
                        </div>
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
<?php if (!empty($stats_por_quiz_unified)): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Datos desde PHP
    
    // Forzar el campo de edad a estar vacío en la carga inicial para evitar autocompletado agresivo
    window.addEventListener('load', function() {
        const edadInput = document.querySelector('input[name="edad"]');
        if (edadInput && edadInput.value === '14' && '<?= $edad ?>' === '') {
            edadInput.value = '';
        }
        // QA Toast
        <?php if ($qa_msg): ?>
        if(window.showToast) window.showToast('<?= addslashes($qa_msg) ?>', 'info');
        <?php endif; ?>
    });

    // --- DATA UNIFICATION LOGIC (MOVED TO PHP) ---
    // Now we just consume the server-side unified data directly
    const statsData = <?= json_encode($stats_por_quiz_unified) ?>;
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
    // 8. NUEVO CHART: Materia vs Paralelo
    if(document.getElementById('chartMateriaParalelo')) {
        const paralelosLabels = <?= json_encode($paralelos_lista) ?>;
        // Use PHP Unified Data directly
        const rawData = <?= json_encode($data_chart_mat_par_final) ?>;
        
        // Generar datasets dinámicos
        const datasets = [];
        const colorPalette = [
            'rgba(102, 126, 234, 0.7)',  // Azul
            'rgba(16, 185, 129, 0.7)',   // Verde
            'rgba(245, 158, 11, 0.7)',   // Naranja
            'rgba(239, 68, 68, 0.7)',    // Rojo
            'rgba(139, 92, 246, 0.7)',   // Violeta
            'rgba(236, 72, 153, 0.7)',   // Rosa
            'rgba(14, 165, 233, 0.7)',   // Celeste
            'rgba(20, 184, 166, 0.7)'    // Teal
        ];

        let colorIdx = 0;
        for (const [materia, data] of Object.entries(rawData)) {
            datasets.push({
                label: materia,
                data: data,
                backgroundColor: colorPalette[colorIdx % colorPalette.length],
                borderRadius: 4,
                borderSkipped: false
            });
            colorIdx++;
        }

        new Chart(document.getElementById('chartMateriaParalelo'), {
            type: 'bar',
            data: {
                labels: paralelosLabels, // Eje X: Paralelos (A, B, C...)
                datasets: datasets       // Barras agrupadas: Materias
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += context.parsed.y + '/100';
                                }
                                return label;
                            }
                        }
                    },
                    datalabels: {
                        display: true,
                        anchor: 'end',
                        align: 'top',
                        formatter: (value) => value > 0 ? Math.round(value) : '',
                        color: '#4a5568',
                        font: { weight: 'bold', size: 10 },
                        offset: -2
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: { display: true, text: 'Promedio' }
                    },
                    x: {
                        title: { display: true, text: 'Paralelos' },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // 9. NUEVO CHART: Jornada (Shift) - CORRECTLY RE-INSERTED
    if(document.getElementById('chartJornada')) {
        const statsJornada = <?= json_encode($stats_jornada) ?>;
        // statsJornada is {Matutina: {sum, count}, Vespertina: {sum, count}}
        const labels = Object.keys(statsJornada);
        const dataAvg = labels.map(l => {
            const d = statsJornada[l];
            return d.count > 0 ? (d.sum / d.count).toFixed(2) : 0;
        });

        new Chart(document.getElementById('chartJornada'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Promedio Global',
                    data: dataAvg,
                    backgroundColor: [colors.warning, colors.purple],
                    borderRadius: 8,
                    barPercentage: 0.5
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
                        formatter: (val) => val + '/100',
                        color: colors.primary,
                        font: { weight: 'bold', size: 12 }
                    }
                },
                scales: {
                    y: { beginAtZero: true, max: 100 },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 10. NUEVO CHART: Rendimiento por Genero
    if(document.getElementById('chartRendimientoGenero')) {
        const statsGen = <?= json_encode($stats_genero) ?>; 
        // statsGen structure: {Masculino: {sum, count}, ...}
        // Filter keys to only allow Masculino/Femenino
        const validKeys = Object.keys(statsGen).filter(k => k === 'Masculino' || k === 'Femenino');
        
        const dataAvgGen = validKeys.map(k => {
            const d = statsGen[k];
            return d.count > 0 ? (d.sum / d.count).toFixed(2) : 0;
        });

        new Chart(document.getElementById('chartRendimientoGenero'), {
            type: 'bar',
            data: {
                labels: validKeys,
                datasets: [{
                    label: 'Promedio',
                    data: dataAvgGen,
                    backgroundColor: [colors.info, colors.danger], // Blue/Pinkish
                    borderRadius: 8,
                    barPercentage: 0.5
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
                        formatter: (val) => val + '/100',
                        color: colors.primary,
                        font: { weight: 'bold', size: 12 }
                    }
                },
                scales: {
                    y: { beginAtZero: true, max: 100 },
                    x: { grid: { display: false } }
                }
            }
        });
    }



    // 11. NUEVO CHART: Pastel Materias Aprobadas
    if(document.getElementById('chartPastelAprobadas')) {
        const distData = <?= json_encode($distribucion_aprobadas) ?>;
        // keys: 0, 1, 2, 3, 4+
        new Chart(document.getElementById('chartPastelAprobadas'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(distData).map(k => k + ' Materias'),
                datasets: [{
                    data: Object.values(distData),
                    backgroundColor: [
                        // '#e2e8f0', // 0 (REMOVED)
                        '#f56565', // 1
                        '#ed8936', // 2
                        '#4299e1', // 3
                        '#48bb78'  // 4+
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } },
                    datalabels: {
                        color: '#fff',
                        font: { weight: 'bold' },
                        formatter: (val, ctx) => {
                            if(val === 0) return '';
                            let sum = 0;
                            let dataArr = ctx.chart.data.datasets[0].data;
                            dataArr.map(data => { sum += data; });
                            let percentage = (val*100 / sum).toFixed(0)+"%";
                            return percentage;
                        }
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

    <script>
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

    // --- EXPORT SKILLS TO CSV ---
    window.exportSkillsToCSV = function() {
        const table = document.getElementById('skillsTable');
        if (!table) {
            if (window.showToast) window.showToast('No hay datos para exportar', 'warning');
            return;
        }

        let csv = [];
        
        // Header row
        const headers = ['#', 'Pregunta', 'Tasa de Aciertos (%)', 'Intentos Totales', 'Respuestas Correctas'];
        csv.push(headers.join(','));
        
        // Data rows
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach((row, index) => {
            const cols = row.querySelectorAll('td');
            if (cols.length === 0) return;
            
            const rowNum = index + 1;
            const question = cols[1].querySelector('.fw-medium')?.textContent.trim().replace(/"/g, '""') || '';
            const rateText = cols[2].querySelector('.fw-bold')?.textContent.trim() || '0%';
            const rate = rateText.replace('%', '');
            const attempts = cols[3].querySelector('.badge')?.textContent.trim() || '0';
            const correct = cols[4].querySelector('.badge')?.textContent.trim() || '0';
            
            csv.push([
                rowNum,
                `"${question}"`,
                rate,
                attempts,
                correct
            ].join(','));
        });
        
        // Create download
        const csvContent = csv.join('\n');
        const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', `destrezas_dificiles_${new Date().toISOString().slice(0,10)}.csv`);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        if (window.showToast) {
            window.showToast('Datos exportados exitosamente', 'success');
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
            },
            
            // --- 8. QA: CONTAR APROBADOS (X MATERIAS) ---
            {
                id: 'qa_approved_count',
                triggers: [/(?:cuantos|numero de|cantidad de)\s*(?:estudiantes|alumnos).*(?:aprobaron|pasaron)\s*(\d+)\s*materias/i],
                action: (form, match) => {
                    const num = match[1];
                    // Inject hidden fields for QA mode
                    const iType = document.createElement('input'); iType.type='hidden'; iType.name='q_type'; iType.value='count_approved';
                    const iVal = document.createElement('input'); iVal.type='hidden'; iVal.name='q_val'; iVal.value=num;
                    form.appendChild(iType);
                    form.appendChild(iVal);
                    // Clear other filters to ensure global scan? 
                    // Actually, keeping filters might be cool ("How many females passed 2 subjects?"). 
                    // My PHP implementation uses a fresh "SELECT ALL" query for this QA, ignoring filters.
                    // If we want to respect filters, we strictly need to modify the PHP QA block.
                    // The PHP block currently does: $sql_all = "SELECT ..."; (No filters applied).
                    // So it is Global Count.
                }
            },

            // ==========================================
            // NUEVOS PATRONES: JORNADA, GÉNERO, PARALELO, MATERIAS
            // ==========================================

            // --- 9. DATOS POR JORNADA (MATUTINA/VESPERTINA) ---
            {
                id: 'jornada_matutina',
                description: 'Filtrar por jornada matutina (paralelos A-D)',
                triggers: [/\b(?:datos?\s*(?:por|de|en))?\s*matutina?\b/i, /\bjornada\s*matutina?\b/i],
                action: (form) => {
                    // Mostrar análisis por jornada (ya existe en el dashboard)
                    // No filtramos paralelo específico, solo indicamos jornada
                    // Podríamos agregar un scroll automático a la sección
                    console.log('Query: Datos Matutina - Ver sección "Análisis por Jornada"');
                }
            },
            {
                id: 'jornada_vespertina',
                description: 'Filtrar por jornada vespertina (paralelos E-H)',
                triggers: [/\b(?:datos?\s*(?:por|de|en))?\s*vespertina?\b/i, /\bjornada\s*vespertina?\b/i],
                action: (form) => {
                    console.log('Query: Datos Vespertina - Ver sección "Análisis por Jornada"');
                }
            },

            // --- 10. DATOS MATUTINA/VESPERTINA POR ÁREAS (MATERIAS) ---
            {
                id: 'jornada_por_areas',
                description: 'Ver distribución de materias por jornada',
                triggers: [/(?:datos|resultados)\s*(?:matutina?|vespertina?)\s*(?:por)?\s*(?:áreas?|materias?|asignaturas?)/i],
                action: (form) => {
                    // Los datos ya existen en stats_por_quiz_unified
                    console.log('Query: Ver gráfico de promedio por materia (general)');
                }
            },

            // --- 11. GÉNERO EN MATUTINA ---
            {
                id: 'genero_matutina_masculino',
                description: 'Ver resultados masculinos en jornada matutina',
                triggers: [/(?:datos|resultados)\s*(?:de)?\s*(?:hombres?|masculinos?)\s*(?:en|de)?\s*matutina?/i, /matutina?\s*(?:hombres?|masculinos?)/i],
                action: (form) => {
                    setVal(form, 'select[name="genero"]', 'Masculino');
                    // Opcionalmente filtrar paralelos A-D para matutina
                    console.log('Query: Masculino + Matutina');
                }
            },
            {
                id: 'genero_matutina_femenino',
                description: 'Ver resultados femeninos en jornada matutina',
                triggers: [/(?:datos|resultados)\s*(?:de)?\s*(?:mujeres?|femeninos?)\s*(?:en|de)?\s*matutina?/i, /matutina?\s*(?:mujeres?|femeninos?)/i],
                action: (form) => {
                    setVal(form, 'select[name="genero"]', 'Femenino');
                    console.log('Query: Femenino + Matutina');
                }
            },

            // --- 12. GÉNERO EN VESPERTINA ---
            {
                id: 'genero_vespertina_masculino',
                description: 'Ver resultados masculinos en jornada vespertina',
                triggers: [/(?:datos|resultados)\s*(?:de)?\s*(?:hombres?|masculinos?)\s*(?:en|de)?\s*vespertina?/i, /vespertina?\s*(?:hombres?|masculinos?)/i],
                action: (form) => {
                    setVal(form, 'select[name="genero"]', 'Masculino');
                    console.log('Query: Masculino + Vespertina');
                }
            },
            {
                id: 'genero_vespertina_femenino',
                description: 'Ver resultados femeninos en jornada vespertina',
                triggers: [/(?:datos|resultados)\s*(?:de)?\s*(?:mujeres?|femeninos?)\s*(?:en|de)?\s*vespertina?/i, /vespertina?\s*(?:mujeres?|femeninos?)/i],
                action: (form) => {
                    setVal(form, 'select[name="genero"]', 'Femenino');
                    console.log('Query: Femenino + Vespertina');
                }
            },

            // --- 13. DATOS POR PARALELO EN MATUTINA ---
            {
                id: 'paralelo_matutina',
                description: 'Ver datos de un paralelo específico en matutina',
                triggers: [/(?:datos|resultados)\s*(?:del)?\s*paralelo\s*([a-d])\s*(?:en|de)?\s*matutina?/i, /matutina?\s*paralelo\s*([a-d])/i],
                action: (form, match) => {
                    setVal(form, 'select[name="paralelo"]', match[1].toUpperCase());
                    console.log(`Query: Paralelo ${match[1].toUpperCase()} (Matutina)`);
                }
            },

            // --- 14. DATOS POR PARALELO EN VESPERTINA ---
            {
                id: 'paralelo_vespertina',
                description: 'Ver datos de un paralelo específico en vespertina',
                triggers: [/(?:datos|resultados)\s*(?:del)?\s*paralelo\s*([e-h])\s*(?:en|de)?\s*vespertina?/i, /vespertina?\s*paralelo\s*([e-h])/i],
                action: (form, match) => {
                    setVal(form, 'select[name="paralelo"]', match[1].toUpperCase());
                    console.log(`Query: Paralelo ${match[1].toUpperCase()} (Vespertina)`);
                }
            },

            // --- 15. DATOS POR PARALELO DE LAS 4 ASIGNATURAS ---
            {
                id: 'paralelo_asignaturas',
                description: 'Ver rendimiento de un paralelo en todas las materias',
                triggers: [/(?:datos|resultados)\s*(?:del)?\s*paralelo\s*([a-h])\s*(?:en|de)?\s*(?:las)?\s*(?:4\s*)?(?:asignaturas?|materias?)/i],
                action: (form, match) => {
                    setVal(form, 'select[name="paralelo"]', match[1].toUpperCase());
                    // Mostrar gráfico materia vs paralelo
                    console.log(`Query: Paralelo ${match[1].toUpperCase()} en todas las materias`);
                }
            },

            // --- 16. PARALELOS QUE APROBARON X MATERIAS ---
            {
                id: 'paralelos_aprobados',
                description: 'Ver cuántos estudiantes por paralelo aprobaron X materias',
                triggers: [/(?:datos|resultados)\s*(?:de)?\s*paralelos?\s*(?:que)?\s*aprobaron\s*(\d+)\s*materias?/i, /paralelos?\s*(?:con)?\s*(\d+)\s*materias?\s*aprobadas?/i],
                action: (form, match) => {
                    // Esta métrica requiere análisis especial
                    // Podríamos usar el gráfico de "Materias Aprobadas por Estudiante"
                    console.log(`Query: Paralelos con ${match[1]} materias aprobadas`);
                }
            },

            // --- 17. JORNADAS QUE APROBARON X MATERIAS ---
            {
                id: 'jornadas_aprobadas',
                description: 'Ver cuántos estudiantes por jornada aprobaron X materias',
                triggers: [/(?:datos|resultados)\s*(?:de)?\s*jornadas?\s*(?:que)?\s*aprobaron\s*(\d+)\s*materias?/i, /jornadas?\s*(?:con)?\s*(\d+)\s*materias?\s*aprobadas?/i],
                action: (form, match) => {
                    console.log(`Query: Jornadas con ${match[1]} materias aprobadas`);
                }
            },

            // --- 18. PARALELO EN MATUTINA - HOMBRES ---
            {
                id: 'paralelo_matutina_hombres',
                description: 'Ver resultados masculinos de un paralelo en matutina',
                triggers: [/(?:datos|resultados)\s*(?:del)?\s*paralelo\s*([a-d])\s*(?:en)?\s*matutina?\s*(?:hombres?|masculinos?)/i, /(?:hombres?|masculinos?)\s*(?:del)?\s*paralelo\s*([a-d])\s*(?:en)?\s*matutina?/i],
                action: (form, match) => {
                    setVal(form, 'select[name="paralelo"]', match[1].toUpperCase());
                    setVal(form, 'select[name="genero"]', 'Masculino');
                    console.log(`Query: Paralelo ${match[1].toUpperCase()} Matutina - Hombres`);
                }
            },

            // --- 19. PARALELO EN MATUTINA - MUJERES ---
            {
                id: 'paralelo_matutina_mujeres',
                description: 'Ver resultados femeninos de un paralelo en matutina',
                triggers: [/(?:datos|resultados)\s*(?:del)?\s*paralelo\s*([a-d])\s*(?:en)?\s*matutina?\s*(?:mujeres?|femeninos?)/i, /(?:mujeres?|femeninos?)\s*(?:del)?\s*paralelo\s*([a-d])\s*(?:en)?\s*matutina?/i],
                action: (form, match) => {
                    setVal(form, 'select[name="paralelo"]', match[1].toUpperCase());
                    setVal(form, 'select[name="genero"]', 'Femenino');
                    console.log(`Query: Paralelo ${match[1].toUpperCase()} Matutina - Mujeres`);
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

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

</body>
</html>