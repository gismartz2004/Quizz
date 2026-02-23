<?php
// includes/analytics_data.php

function getJornada($paralelo) {
    if (in_array(strtoupper($paralelo), ['A', 'B', 'C', 'D'])) return 'Matutina';
    if (in_array(strtoupper($paralelo), ['E', 'F', 'G', 'H'])) return 'Vespertina';
    return 'Desconocida';
}

function calculateSectionStats($results) {
    $stats = [
        'Matutina' => [
            'total' => 0, 'aprobados' => 0, 'sum_notas' => 0, 
            'unique_users' => [], // Set of user IDs
            'genero' => ['Masculino' => 0, 'Femenino' => 0], // Unique counts
            'genero_users' => ['Masculino' => [], 'Femenino' => []], // Sets
            'paralelos' => []
        ],
        'Vespertina' => [
            'total' => 0, 'aprobados' => 0, 'sum_notas' => 0, 
            'unique_users' => [],
            'genero' => ['Masculino' => 0, 'Femenino' => 0],
            'genero_users' => ['Masculino' => [], 'Femenino' => []],
            'paralelos' => []
        ]
    ];

    foreach ($results as $row) {
        $paralelo = strtoupper($row['paralelo'] ?? '');
        $jornada = getJornada($paralelo);
        $nota = (float)$row['puntos_obtenidos'];
        $max = (stripos($row['quiz_titulo'], 'Preguntas Abiertas') !== false) ? 20 : 250;
        $score = ($max > 0) ? ($nota / $max) * 100 : 0;
        $genero = ucfirst($row['genero'] ?? 'Otro');
        $uid = $row['usuario_id'] ?? uniqid(); // Fallback if no ID

        if (isset($stats[$jornada])) {
            $stats[$jornada]['total']++; // Total exams
            $stats[$jornada]['sum_notas'] += $score;
            if ($score >= 70) $stats[$jornada]['aprobados']++; // Approved exams
            
            // Unique Global Students
            $stats[$jornada]['unique_users'][$uid] = true;

            // Unique Gender Global
            if (isset($stats[$jornada]['genero_users'][$genero])) {
                $stats[$jornada]['genero_users'][$genero][$uid] = true;
            }

            // Parallel Stats
            if (!isset($stats[$jornada]['paralelos'][$paralelo])) {
                $stats[$jornada]['paralelos'][$paralelo] = [
                    'total_exams' => 0, 
                    'sum_score' => 0, 
                    'unique_total' => [], // Set
                    
                    'exams_hombres' => 0, 
                    'sum_hombres' => 0,
                    'unique_hombres' => [], // Set

                    'exams_mujeres' => 0, 
                    'sum_mujeres' => 0,
                    'unique_mujeres' => [] // Set
                ];
            }
            
            $pStats = &$stats[$jornada]['paralelos'][$paralelo];
            $pStats['total_exams']++;
            $pStats['sum_score'] += $score;
            $pStats['unique_total'][$uid] = true;

            if ($genero === 'Masculino') {
                $pStats['exams_hombres']++;
                $pStats['sum_hombres'] += $score;
                $pStats['unique_hombres'][$uid] = true;
            }
            if ($genero === 'Femenino') {
                $pStats['exams_mujeres']++;
                $pStats['sum_mujeres'] += $score;
                $pStats['unique_mujeres'][$uid] = true;
            }
        }
    }
    
    // Post-process counts (convert arrays to integers)
    foreach (['Matutina', 'Vespertina'] as $j) {
        $stats[$j]['count_unique'] = count($stats[$j]['unique_users']);
        $stats[$j]['genero']['Masculino'] = count($stats[$j]['genero_users']['Masculino'] ?? []);
        $stats[$j]['genero']['Femenino'] = count($stats[$j]['genero_users']['Femenino'] ?? []);
        
        foreach ($stats[$j]['paralelos'] as $p => &$d) {
            $d['count_unique'] = count($d['unique_total']);
            $d['count_hombres'] = count($d['unique_hombres']);
            $d['count_mujeres'] = count($d['unique_mujeres']);
            // Clean up memory
            unset($d['unique_total'], $d['unique_hombres'], $d['unique_mujeres']);
        }
        unset($stats[$j]['unique_users'], $stats[$j]['genero_users']);
    }

    return $stats;
}

function analyzeSkillsDiff($pdo, $quiz_id = null, $threshold = 1.0, $filters = []) {
    if (!$quiz_id) return [];

    $threshold = (float)$threshold;
    $params = ['qid' => $quiz_id, 'threshold' => $threshold];
    
    // Construir filtros dinámicos
    $filter_sql = "";
    if (!empty($filters['genero'])) {
        $filter_sql .= " AND r.genero = :genero";
        $params['genero'] = $filters['genero'];
    }
    if (!empty($filters['paralelo'])) {
        $filter_sql .= " AND r.paralelo = :paralelo";
        $params['paralelo'] = $filters['paralelo'];
    }
    if (!empty($filters['jornada'])) {
        if ($filters['jornada'] === 'Matutina') {
            $filter_sql .= " AND r.paralelo IN ('A', 'B', 'C', 'D')";
        } elseif ($filters['jornada'] === 'Vespertina') {
            $filter_sql .= " AND r.paralelo IN ('E', 'F', 'G', 'H')";
        }
    }
    if (!empty($filters['muestra'])) {
        if ($filters['muestra'] === 'si') {
            $filter_sql .= " AND COALESCE(r.es_muestra, FALSE) = TRUE";
        } elseif ($filters['muestra'] === 'no') {
            $filter_sql .= " AND COALESCE(r.es_muestra, FALSE) = FALSE";
        }
    }

    try {
        // Obtenemos preguntas y estadisticas de respuestas
        // IMPORTANTE: Postgres requiere casting explícito y JOIN con opciones para autocalificación
        $sql = "
            SELECT 
                p.id, 
                p.texto, 
                COUNT(ru.id) as total_intentos,
                SUM(CASE 
                    WHEN ru.es_correcta_manual IS TRUE THEN 1
                    WHEN ru.es_correcta_manual IS FALSE THEN 0
                    WHEN o.es_correcta IS TRUE THEN 1
                    ELSE 0 
                END) as correctas,
                (COUNT(ru.id) - SUM(CASE 
                    WHEN ru.es_correcta_manual IS TRUE THEN 1
                    WHEN ru.es_correcta_manual IS FALSE THEN 0
                    WHEN o.es_correcta IS TRUE THEN 1
                    ELSE 0 
                END)) as incorrectas,
                (SUM(CASE 
                    WHEN ru.es_correcta_manual IS TRUE THEN 1
                    WHEN ru.es_correcta_manual IS FALSE THEN 0
                    WHEN o.es_correcta IS TRUE THEN 1
                    ELSE 0 
                END)::float / NULLIF(COUNT(ru.id), 0)) as success_rate,
                STRING_AGG(CASE 
                    WHEN (ru.es_correcta_manual IS TRUE OR (ru.es_correcta_manual IS NULL AND o.es_correcta IS TRUE)) THEN NULL 
                    ELSE u.nombre 
                END, ', ') as lista_errores
            FROM preguntas p
            LEFT JOIN respuestas_usuarios ru ON p.id = ru.pregunta_id
            LEFT JOIN opciones o ON ru.opcion_id = o.id
            LEFT JOIN resultados r ON ru.resultado_id = r.id
            LEFT JOIN usuarios u ON r.usuario_id = u.id
            WHERE p.quiz_id = :qid $filter_sql
            GROUP BY p.id, p.texto
            HAVING COUNT(ru.id) > 0 AND (SUM(CASE 
                    WHEN ru.es_correcta_manual IS TRUE THEN 1
                    WHEN ru.es_correcta_manual IS FALSE THEN 0
                    WHEN o.es_correcta IS TRUE THEN 1
                    ELSE 0 
                END)::float / NULLIF(COUNT(ru.id), 0)) <= :threshold
            ORDER BY success_rate ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        // Fallback: Lógica básica sin corrección manual (por si falla la detección de columnas)
        try {
            $sql_basic = "
                SELECT 
                    p.id, p.texto, 
                    COUNT(ru.id) as total_intentos,
                    SUM(CASE WHEN o.es_correcta IS TRUE THEN 1 ELSE 0 END) as correctas,
                    (COUNT(ru.id) - SUM(CASE WHEN o.es_correcta IS TRUE THEN 1 ELSE 0 END)) as incorrectas,
                    (SUM(CASE WHEN o.es_correcta IS TRUE THEN 1 ELSE 0 END)::float / NULLIF(COUNT(ru.id), 0)) as success_rate,
                    '' as lista_errores
                FROM preguntas p
                LEFT JOIN respuestas_usuarios ru ON p.id = ru.pregunta_id
                LEFT JOIN opciones o ON ru.opcion_id = o.id
                LEFT JOIN resultados r ON ru.resultado_id = r.id
                WHERE p.quiz_id = :qid $filter_sql
                GROUP BY p.id, p.texto
                HAVING COUNT(ru.id) > 0 AND (SUM(CASE WHEN o.es_correcta IS TRUE THEN 1 ELSE 0 END)::float / NULLIF(COUNT(ru.id), 0)) <= :threshold
                ORDER BY success_rate ASC
            ";
            $stmt = $pdo->prepare($sql_basic);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            return [];
        }
    }
}

// --- 4. CONCLUSIONS GENERATOR ---
function getConclusions($avg, $approval, $anomalies, $total) {
    // Rendimiento
    if ($avg >= 80) $rendMsg = "Excelente rendimiento general. El promedio es alto ($avg), indicando un buen dominio de los temas.";
    elseif ($avg >= 70) $rendMsg = "Rendimiento satisfactorio ($avg). La mayoría está aprobando, pero hay margen de mejora.";
    else $rendMsg = "Rendimiento bajo ($avg). Se requieren acciones de refuerzo urgentes.";

    // Aprobación
    if ($approval >= 80) $aprobMsg = "Alta tasa de aprobación ($approval%). El grupo está cumpliendo los objetivos.";
    elseif ($approval >= 60) $aprobMsg = "Aprobación moderada ($approval%). Identificar estudiantes en riesgo.";
    else $aprobMsg = "Aprobación crítica ($approval%). Revisar metodología o dificultad.";

    // Integridad
    $anomRate = ($total > 0) ? ($anomalies / $total) : 0;
    if ($anomRate > 0.2) $secMsg = "Atención: Se han detectado $anomalies casos de comportamiento no íntegro (altas tasas de cambio de pestaña).";
    elseif ($anomRate > 0) $secMsg = "Se detectaron algunas anomalías ($anomalies), pero dentro de rangos normales.";
    else $secMsg = "Comportamiento íntegro ejemplar. No se detectaron anomalías significativas.";

    return [
        'rendimiento' => $rendMsg,
        'aprobacion' => $aprobMsg,
        'seguridad' => $secMsg
    ];
}

/**
 * Fetches detailed answers for a batch of results for the raw data table.
 */
function getDetailedBatchAnswers($pdo, $resultIds) {
    if (empty($resultIds)) return [];
    
    $inQuery = implode(',', array_map('intval', $resultIds));
    $batchAnswers = [];
    
    $sql = "SELECT ru.resultado_id, ru.pregunta_id, o.texto as respuesta_texto,
                   ru.es_correcta_manual, o.es_correcta, p.valor as puntos_pregunta
            FROM respuestas_usuarios ru 
            LEFT JOIN opciones o ON ru.opcion_id = o.id
            LEFT JOIN preguntas p ON ru.pregunta_id = p.id
            WHERE ru.resultado_id IN ($inQuery)";
    
    try {
        $stmt = $pdo->query($sql);
        while ($ans = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $is_c_manual = $ans['es_correcta_manual'] ?? null;
            $is_c_auto   = ($ans['es_correcta'] === true || $ans['es_correcta'] === 't' || $ans['es_correcta'] == 1);
            
            if ($is_c_manual !== null) {
                $final_correct = ($is_c_manual === true || $is_c_manual === 't' || $is_c_manual == 1);
            } else {
                $final_correct = $is_c_auto;
            }

            $points = $final_correct ? floatval($ans['puntos_pregunta']) : 0;
            $text = trim($ans['respuesta_texto'] ?? 'N/R');
            
            $batchAnswers[$ans['resultado_id']][$ans['pregunta_id']] = [
                'texto' => $text,
                'puntos' => $points,
                'es_correcta' => $final_correct
            ];
        }
    } catch (Exception $e) {
        // Fallback: Si falló la consulta (probablemente por es_correcta_manual)
        try {
            $sql_fallback = "SELECT ru.resultado_id, ru.pregunta_id, o.texto as respuesta_texto,
                           o.es_correcta, p.valor as puntos_pregunta
                    FROM respuestas_usuarios ru 
                    LEFT JOIN opciones o ON ru.opcion_id = o.id
                    LEFT JOIN preguntas p ON ru.pregunta_id = p.id
                    WHERE ru.resultado_id IN ($inQuery)";
            $stmt = $pdo->query($sql_fallback);
            while ($ans = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $final_correct = ($ans['es_correcta'] === true || $ans['es_correcta'] === 't' || $ans['es_correcta'] == 1);
                $points = $final_correct ? floatval($ans['puntos_pregunta']) : 0;
                $text = trim($ans['respuesta_texto'] ?? 'N/R');
                $batchAnswers[$ans['resultado_id']][$ans['pregunta_id']] = [
                    'texto' => $text,
                    'puntos' => $points,
                    'es_correcta' => $final_correct
                ];
            }
        } catch (Exception $e2) {
             // Real silent error
        }
    }
    
    return $batchAnswers;
}
?>
