<?php
// -----------------------------------------
// 1. PROCESAMIENTO DE DATOS (BACKEND LOCAL)
// -----------------------------------------
$archivo_json = 'resultados_alumnos.json';
$resultados = [];

if (file_exists($archivo_json)) {
    $json_data = file_get_contents($archivo_json);
    $resultados = json_decode($json_data, true) ?? [];
}

// Inicializar contadores
$total_alumnos = count($resultados);
$suma_puntos = 0;
$suma_porcentaje = 0;
$aprobados = 0;
$reprobados = 0;
$nota_minima_aprobacion = 70; // Puedes cambiar esto a 60 u 80

$nombre_quiz = $total_alumnos > 0 ? ($resultados[0]['quiz_titulo'] ?? 'Examen General') : 'Sin Datos';

foreach($resultados as $alumno) {
    $suma_puntos += $alumno['puntos_obtenidos'];
    $suma_porcentaje += $alumno['porcentaje'];
    
    // Contar aprobados/reprobados para las gráficas
    if ($alumno['porcentaje'] >= $nota_minima_aprobacion) {
        $aprobados++;
    } else {
        $reprobados++;
    }
}

$promedio_puntos = $total_alumnos > 0 ? round($suma_puntos/$total_alumnos, 1) : 0;
$promedio_global = $total_alumnos > 0 ? round($suma_porcentaje/$total_alumnos, 1) : 0;

// Función auxiliar para estilos
function getBadge($porcentaje) {
    if ($porcentaje >= 90) return ['bg-success-soft text-success', 'Excelente'];
    if ($porcentaje >= 70) return ['bg-info-soft text-info', 'Aprobado'];
    if ($porcentaje >= 50) return ['bg-warning-soft text-warning', 'Regular'];
    return ['bg-danger-soft text-danger', 'Reprobado'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Interno | <?php echo htmlspecialchars($nombre_quiz); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #a0aec0;
            --success: #2ec4b6;
            --danger: #e63946;
            --warning: #ff9f1c;
            --dark: #1e293b;
            --bg: #f8fafc;
            --card-bg: #ffffff;
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg); color: var(--dark); }

        /* Tarjetas KPI Estilo "Analytics" */
        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            height: 100%;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; margin-bottom: 15px;
        }
        .stat-value { font-size: 1.8rem; font-weight: 700; margin-bottom: 5px; }
        .stat-label { color: var(--secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Gráficas */
        .chart-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            height: 350px; /* Altura fija para consistencia */
        }

        /* Tablas */
        .table-card {
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            border: 1px solid #e2e8f0;
        }
        .table thead th {
            background: #f1f5f9;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--secondary);
            font-weight: 700;
            border: none;
            padding: 15px;
        }
        .table tbody td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
        
        /* Badges */
        .bg-success-soft { background: #d1fae5; color: #065f46; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-info-soft { background: #dbeafe; color: #1e40af; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-warning-soft { background: #ffedd5; color: #9a3412; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-danger-soft { background: #fee2e2; color: #991b1b; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }

        /* Utilidades */
        .text-trend-up { color: var(--success); font-size: 0.8rem; font-weight: 600; }
        .text-trend-down { color: var(--danger); font-size: 0.8rem; font-weight: 600; }
    </style>
</head>
<body>

<div class="container py-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1">Reporte de Resultados</h4>
            <span class="text-muted small">Quiz: <?php echo htmlspecialchars($nombre_quiz); ?></span>
        </div>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm">
            <i class="fas fa-print"></i> Imprimir Reporte
        </button>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e0e7ff; color: var(--primary);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $total_alumnos; ?></div>
                <div class="stat-label">Total Alumnos</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #d1fae5; color: var(--success);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value"><?php echo $promedio_global; ?>%</div>
                <div class="stat-label">Promedio Global</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #dbeafe; color: #2563eb;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $aprobados; ?></div>
                <div class="stat-label">Aprobados (Min <?php echo $nota_minima_aprobacion; ?>%)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #fee2e2; color: var(--danger);">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $reprobados; ?></div>
                <div class="stat-label">Reprobados</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="chart-container">
                <h6 class="fw-bold mb-3">Rendimiento Individual</h6>
                <canvas id="barChart"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-container">
                <h6 class="fw-bold mb-3">Tasa de Aprobación</h6>
                <div style="height: 250px; position: relative;">
                    <canvas id="pieChart"></canvas>
                </div>
                <div class="text-center mt-3 small text-muted">
                    Total evaluados: <?php echo $total_alumnos; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-lg-8 mb-4">
            <div class="table-card">
                <div class="p-3 border-bottom bg-light">
                    <h6 class="fw-bold m-0"><i class="fas fa-list"></i> Listado Detallado</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th class="text-center">Puntos</th>
                                <th class="text-center">Calificación</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($resultados as $a): 
                                $estilo = getBadge($a['porcentaje']);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($a['usuario_nombre']); ?></div>
                                    <small class="text-muted"><?php echo date('d/m H:i', strtotime($a['fecha'])); ?></small>
                                </td>
                                <td class="text-center"><?php echo $a['puntos_obtenidos']; ?>/<?php echo $a['puntos_totales']; ?></td>
                                <td class="text-center fw-bold"><?php echo $a['porcentaje']; ?>%</td>
                                <td class="text-center">
                                    <span class="<?php echo $estilo[0]; ?>"><?php echo $estilo[1]; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($resultados)): ?>
                                <tr><td colspan="4" class="text-center py-4">No hay datos registrados aún.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="table-card">
                <div class="p-3 border-bottom bg-light">
                    <h6 class="fw-bold m-0 text-warning"><i class="fas fa-trophy"></i> Top Estudiantes</h6>
                </div>
                <ul class="list-group list-group-flush">
                    <?php 
                    // Ordenar y obtener top 5
                    $top = $resultados;
                    usort($top, function($a, $b) { return $b['porcentaje'] - $a['porcentaje']; });
                    $top = array_slice($top, 0, 5);
                    
                    foreach($top as $i => $t): 
                        $medal = ($i===0) ? '🥇' : (($i===1) ? '🥈' : (($i===2) ? '🥉' : ($i+1).'.'));
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <span class="me-2 fs-5"><?php echo $medal; ?></span>
                            <span class="fw-bold"><?php echo htmlspecialchars($t['usuario_nombre']); ?></span>
                        </div>
                        <span class="fw-bold text-primary"><?php echo $t['porcentaje']; ?>%</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Datos procesados en PHP pasados a JS
    const nombres = <?php echo json_encode(array_column($resultados, 'usuario_nombre')); ?>;
    const porcentajes = <?php echo json_encode(array_column($resultados, 'porcentaje')); ?>;
    const totalAprobados = <?php echo $aprobados; ?>;
    const totalReprobados = <?php echo $reprobados; ?>;

    // 1. Gráfico de Barras (Rendimiento Individual)
    const ctxBar = document.getElementById('barChart').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: nombres,
            datasets: [{
                label: 'Calificación (%)',
                data: porcentajes,
                backgroundColor: '#4361ee',
                borderRadius: 4,
                barThickness: 20,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100, grid: { borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Gráfico de Pastel (Aprobados vs Reprobados)
    const ctxPie = document.getElementById('pieChart').getContext('2d');
    new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: ['Aprobados', 'Reprobados'],
            datasets: [{
                data: [totalAprobados, totalReprobados],
                backgroundColor: ['#2ec4b6', '#e63946'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%', // Hace el agujero de la dona más grande
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
            }
        }
    });
</script>

</body>
</html>