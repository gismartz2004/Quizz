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
            'genero' => ['Masculino' => 0, 'Femenino' => 0],
            'paralelos' => []
        ],
        'Vespertina' => [
            'total' => 0, 'aprobados' => 0, 'sum_notas' => 0, 
            'genero' => ['Masculino' => 0, 'Femenino' => 0],
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

        if (isset($stats[$jornada])) {
            $stats[$jornada]['total']++;
            $stats[$jornada]['sum_notas'] += $score;
            if ($score >= 70) $stats[$jornada]['aprobados']++;
            
            if (isset($stats[$jornada]['genero'][$genero])) {
                $stats[$jornada]['genero'][$genero]++;
            }

            if (!isset($stats[$jornada]['paralelos'][$paralelo])) {
                $stats[$jornada]['paralelos'][$paralelo] = [
                    'total' => 0, 'sum' => 0, 
                    'hombres' => 0, 'sum_hombres' => 0,
                    'mujeres' => 0, 'sum_mujeres' => 0
                ];
            }
            $stats[$jornada]['paralelos'][$paralelo]['total']++;
            $stats[$jornada]['paralelos'][$paralelo]['sum'] += $score;
            
            if ($genero === 'Masculino') {
                $stats[$jornada]['paralelos'][$paralelo]['hombres']++;
                $stats[$jornada]['paralelos'][$paralelo]['sum_hombres'] += $score;
            }
            if ($genero === 'Femenino') {
                $stats[$jornada]['paralelos'][$paralelo]['mujeres']++;
                $stats[$jornada]['paralelos'][$paralelo]['sum_mujeres'] += $score;
            }
        }
    }
    return $stats;
}

function analyzeSkillsDiff($pdo, $quiz_id = null) {
    // Si no hay quiz seleccionado, no podemos analizar preguntas específicas fácilmente
    // a menos que analicemos TODAS, pero sería una lista gigante.
    // Retornamos vacío si no hay quiz_id específico o si es múltiple.
    if (!$quiz_id) return [];

    try {
        // Obtenemos preguntas y estadisticas de respuestas
        $sql = "
            SELECT p.id, p.texto, 
                COUNT(ru.id) as total_intentos,
                SUM(CASE 
                    WHEN ru.es_correcta_manual = TRUE THEN 1
                    WHEN ru.es_correcta_manual IS NULL AND o.es_correcta = TRUE THEN 1
                    ELSE 0 
                END) as correctas
            FROM preguntas p
            LEFT JOIN respuestas_usuarios ru ON p.id = ru.pregunta_id
            LEFT JOIN opciones o ON ru.opcion_id = o.id
            WHERE p.quiz_id = :qid
            GROUP BY p.id, p.texto
            ORDER BY (SUM(CASE 
                    WHEN ru.es_correcta_manual = TRUE THEN 1
                    WHEN ru.es_correcta_manual IS NULL AND o.es_correcta = TRUE THEN 1
                    ELSE 0 
                END)::float / NULLIF(COUNT(ru.id), 0)) ASC
            LIMIT 5
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['qid' => $quiz_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
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
?>
