<?php
session_start();
// Ajustar la ruta para salir de la carpeta 'resultados' y buscar 'db.php'
require '../db.php'; 

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: ../login.php');
    exit;
}

// 2. OBTENER DATOS DE LA BASE DE DATOS
$resultados = [];
$total_alumnos = 0;
$suma_porcentaje = 0;
$aprobados = 0;
$reprobados = 0;
$nota_minima_aprobacion = 70;

try {
    // Consulta SQL uniendo tablas para traer nombres y títulos
    $sql = "SELECT 
                r.*, 
                u.nombre as usuario_nombre, 
                q.titulo as quiz_titulo 
            FROM resultados r
            JOIN usuarios u ON r.usuario_id = u.id
            JOIN quizzes q ON r.quiz_id = q.id
            ORDER BY r.fecha_realizacion DESC";
            
    $stmt = $pdo->query($sql);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_alumnos = count($resultados);

    if ($total_alumnos > 0) {
        foreach($resultados as $alumno) {
            $suma_porcentaje += $alumno['porcentaje'];
            
            if ($alumno['porcentaje'] >= $nota_minima_aprobacion) {
                $aprobados++;
            } else {
                $reprobados++;
            }
        }
    }

} catch (PDOException $e) {
    die("Error al cargar datos: " . $e->getMessage());
}

// Cálculos finales
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
    <title>Dashboard de Resultados</title>
    
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

        /* KPI Cards */
        .stat-card {
            background: var(--card-bg); border-radius: 12px; padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03); height: 100%; border: 1px solid #e2e8f0;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; margin-bottom: 15px;
        }
        .stat-value { font-size: 1.8rem; font-weight: 700; margin-bottom: 5px; }
        .stat-label { color: var(--secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }

        /* Charts & Tables */
        .chart-container {
            background: var(--card-bg); border-radius: 12px; padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;
            margin-bottom: 24px; height: 350px;
        }
        .table-card {
            background: var(--card-bg); border-radius: 12px; overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;
        }
        .table thead th {
            background: #f1f5f9; font-size: 0.75rem; text-transform: uppercase;
            color: var(--secondary); font-weight: 700; border: none; padding: 15px;
        }
        .table tbody td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        
        /* Badges & Utilities */
        .bg-success-soft { background: #d1fae5; color: #065f46; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-info-soft { background: #dbeafe; color: #1e40af; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-warning-soft { background: #ffedd5; color: #9a3412; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-danger-soft { background: #fee2e2; color: #991b1b; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }

        .menu-link { text-decoration: none; color: var(--dark); font-weight: 600; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 10px; transition: color 0.2s; }
        .menu-link:hover { color: var(--primary); }

        .demo-tag { font-size: 0.75rem; color: #64748b; display: block; margin-bottom: 2px; }
        .demo-tag i { width: 15px; text-align: center; margin-right: 4px; color: #94a3b8; }
    </style>
</head>
<body>

<div class="container py-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div> 
            <a href="../profesor.php" class="menu-link">
                <i class="fas fa-arrow-left"></i> Volver al Inicio
            </a>
            <h4 class="fw-bold mb-1">Reporte de Resultados</h4>
            <span class="text-muted small">Datos demográficos y calificaciones</span>
        </div>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm">
            <i class="fas fa-print"></i> Imprimir Reporte
        </button>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e0e7ff; color: var(--primary);"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo $total_alumnos; ?></div>
                <div class="stat-label">Evaluaciones</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #d1fae5; color: var(--success);"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value"><?php echo $promedio_global; ?>%</div>
                <div class="stat-label">Promedio Global</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #dbeafe; color: #2563eb;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?php echo $aprobados; ?></div>
                <div class="stat-label">Aprobados</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #fee2e2; color: var(--danger);"><i class="fas fa-times-circle"></i></div>
                <div class="stat-value"><?php echo $reprobados; ?></div>
                <div class="stat-label">Reprobados</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="chart-container">
                <h6 class="fw-bold mb-3">Rendimiento por Estudiante</h6>
                <canvas id="barChart"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-container">
                <h6 class="fw-bold mb-3">Tasa de Aprobación</h6>
                <div style="height: 250px; position: relative;">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-12 mb-4">
            <div class="table-card">
                <div class="p-3 border-bottom bg-light">
                    <h6 class="fw-bold m-0"><i class="fas fa-list"></i> Detalle Completo</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Examen</th>
                                <th>Datos Demográficos</th>
                                <th class="text-center">Puntos</th>
                                <th class="text-center">Nota</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($resultados)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No hay resultados registrados aún.</td></tr>
                            <?php else: ?>
                                <?php foreach($resultados as $a): 
                                    $estilo = getBadge($a['porcentaje']);
                                    $edad = $a['edad'] ? $a['edad'] . ' años' : 'N/A';
                                    $genero = $a['genero'] ?? 'N/A';
                                    $residencia = $a['residencia'] ?? 'N/A';
                                    $discapacidad = $a['discapacidad'] ?? 'Ninguna';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($a['usuario_nombre']); ?></div>
                                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($a['fecha_realizacion'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($a['quiz_titulo']); ?>">
                                            <?php echo htmlspecialchars($a['quiz_titulo']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="row">
                                            <div class="col-6">
                                                <span class="demo-tag"><i class="fas fa-birthday-cake"></i> <?php echo $edad; ?></span>
                                                <span class="demo-tag"><i class="fas fa-venus-mars"></i> <?php echo $genero; ?></span>
                                            </div>
                                            <div class="col-6">
                                                <span class="demo-tag"><i class="fas fa-map-marker-alt"></i> <?php echo $residencia; ?></span>
                                                <span class="demo-tag"><i class="fas fa-wheelchair"></i> <?php echo $discapacidad; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center"><?php echo $a['puntos_obtenidos']; ?>/<?php echo $a['puntos_totales_quiz']; ?></td>
                                    <td class="text-center fw-bold"><?php echo $a['porcentaje']; ?>%</td>
                                    <td class="text-center">
                                        <span class="<?php echo $estilo[0]; ?>"><?php echo $estilo[1]; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const nombres = <?php echo json_encode(array_column($resultados, 'usuario_nombre')); ?>;
    const porcentajes = <?php echo json_encode(array_column($resultados, 'porcentaje')); ?>;
    const totalAprobados = <?php echo $aprobados; ?>;
    const totalReprobados = <?php echo $reprobados; ?>;

    if (nombres.length > 0) {
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
                cutout: '70%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
                }
            }
        });
    } else {
        document.getElementById('barChart').parentElement.innerHTML = '<div class="text-center text-muted py-5">No hay datos para graficar</div>';
        document.getElementById('pieChart').parentElement.innerHTML = '<div class="text-center text-muted py-5">No hay datos</div>';
    }
</script>

</body>
</html>