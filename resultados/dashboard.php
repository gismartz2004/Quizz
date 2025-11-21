<?php
// Leer datos desde el archivo JSON
$json_data = file_get_contents('resultados_alumnos.json');
$resultados_alumnos = json_decode($json_data, true);

// Verificar si se cargaron correctamente los datos
if ($resultados_alumnos === null) {
    die("Error al cargar los datos del archivo JSON.");
}

// Función para determinar el color según el porcentaje
function getColorPorcentaje($porcentaje) {
    if ($porcentaje >= 80) return 'success';
    if ($porcentaje >= 60) return 'info';
    if ($porcentaje >= 40) return 'warning';
    return 'danger';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Resultados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-header {
            font-weight: bold;
        }
        .progress {
            height: 25px;
        }
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1 class="text-center mb-4">
            <i class="fas fa-chart-line"></i> Dashboard de Resultados
        </h1>
        
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-info-circle"></i> Resumen General
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <h5>Total Alumnos</h5>
                                <h3 class="text-primary"><?php echo count($resultados_alumnos); ?></h3>
                            </div>
                            <div class="col-md-3 text-center">
                                <h5>Promedio Puntos</h5>
                                <h3 class="text-info">
                                    <?php 
                                        $total = 0;
                                        foreach($resultados_alumnos as $alumno) {
                                            $total += $alumno['puntos_obtenidos'];
                                        }
                                        echo round($total/count($resultados_alumnos), 1);
                                    ?>
                                </h3>
                            </div>
                            <div class="col-md-3 text-center">
                                <h5>Promedio %</h5>
                                <h3 class="text-success">
                                    <?php 
                                        $total = 0;
                                        foreach($resultados_alumnos as $alumno) {
                                            $total += $alumno['porcentaje'];
                                        }
                                        echo round($total/count($resultados_alumnos), 1) . '%';
                                    ?>
                                </h3>
                            </div>
                            <div class="col-md-3 text-center">
                                <h5>Examen</h5>
                                <h3 class="text-secondary"><?php echo $resultados_alumnos[0]['quiz_titulo']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-users"></i> Resultados por Alumno
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Examen</th>
                                <th>Fecha</th>
                                <th>Puntos</th>
                                <th>Porcentaje</th>
                                <th>Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($resultados_alumnos as $alumno): ?>
                            <tr>
                                <td><?php echo $alumno['usuario_id']; ?></td>
                                <td><?php echo $alumno['usuario_nombre']; ?></td>
                                <td><?php echo $alumno['quiz_titulo']; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($alumno['fecha'])); ?></td>
                                <td>
                                    <?php echo $alumno['puntos_obtenidos']; ?> / <?php echo $alumno['puntos_totales']; ?>
                                </td>
                                <td><?php echo $alumno['porcentaje']; ?>%</td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar bg-<?php echo getColorPorcentaje($alumno['porcentaje']); ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo $alumno['porcentaje']; ?>%" 
                                             aria-valuenow="<?php echo $alumno['porcentaje']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <i class="fas fa-trophy"></i> Mejores Resultados
                    </div>
                    <div class="card-body">
                        <ol class="list-group">
                            <?php 
                            // Ordenar por porcentaje descendente
                            usort($resultados_alumnos, function($a, $b) {
                                return $b['porcentaje'] - $a['porcentaje'];
                            });
                            
                            // Mostrar top 3
                            for($i = 0; $i < min(3, count($resultados_alumnos)); $i++): 
                                $alumno = $resultados_alumnos[$i];
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo $alumno['usuario_nombre']; ?>
                                <span class="badge bg-primary rounded-pill">
                                    <?php echo $alumno['porcentaje']; ?>%
                                </span>
                            </li>
                            <?php endfor; ?>
                        </ol>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <i class="fas fa-chart-pie"></i> Distribución de Puntajes
                    </div>
                    <div class="card-body">
                        <canvas id="graficoPorcentajes"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Configuración del gráfico
        const ctx = document.getElementById('graficoPorcentajes').getContext('2d');
        const porcentajes = <?php echo json_encode(array_column($resultados_alumnos, 'porcentaje')); ?>;
        const nombres = <?php echo json_encode(array_column($resultados_alumnos, 'usuario_nombre')); ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: nombres,
                datasets: [{
                    label: 'Porcentaje obtenido',
                    data: porcentajes,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 206, 86, 0.7)',
                        'rgba(75, 192, 192, 0.7)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    </script>
</body>
</html>