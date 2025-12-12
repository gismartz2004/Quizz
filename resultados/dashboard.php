<?php
session_start();
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
$total_incidentes = 0; // Contador de trampas
$nota_minima_aprobacion = 70;

try {
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
            
            if ($alumno['porcentaje'] >= $nota_minima_aprobacion) $aprobados++;
            else $reprobados++;

            // Contar incidentes si hubo más de 1 salida o más de 10s fuera
            if (($alumno['intentos_tab_switch'] ?? 0) > 1 || ($alumno['segundos_fuera'] ?? 0) > 10) {
                $total_incidentes++;
            }
        }
    }

} catch (PDOException $e) {
    die("Error al cargar datos: " . $e->getMessage());
}

$promedio_global = $total_alumnos > 0 ? round($suma_porcentaje/$total_alumnos, 1) : 0;

// Helpers
function getBadge($porcentaje) {
    if ($porcentaje >= 90) return ['bg-success-soft text-success', 'Excelente'];
    if ($porcentaje >= 70) return ['bg-info-soft text-info', 'Aprobado'];
    if ($porcentaje >= 50) return ['bg-warning-soft text-warning', 'Regular'];
    return ['bg-danger-soft text-danger', 'Reprobado'];
}

function getIntegrityBadge($intentos, $segundos) {
    if ($intentos == 0 && $segundos == 0) return ['bg-success-soft text-success', 'Limpio', 'fa-check-circle'];
    if ($intentos <= 2 && $segundos < 15) return ['bg-warning-soft text-warning', 'Leve', 'fa-exclamation-circle'];
    return ['bg-danger-soft text-danger', 'Riesgo', 'fa-radiation'];
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

        /* Cards */
        .stat-card { background: var(--card-bg); border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); height: 100%; border: 1px solid #e2e8f0; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 15px; }
        .stat-value { font-size: 1.8rem; font-weight: 700; margin-bottom: 5px; }
        .stat-label { color: var(--secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }

        /* Tables */
        .table-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; }
        .table thead th { background: #f1f5f9; font-size: 0.75rem; text-transform: uppercase; color: var(--secondary); font-weight: 700; border: none; padding: 15px; }
        .table tbody td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        
        /* Badges */
        .bg-success-soft { background: #d1fae5; color: #065f46; padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .bg-info-soft { background: #dbeafe; color: #1e40af; padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .bg-warning-soft { background: #ffedd5; color: #9a3412; padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .bg-danger-soft { background: #fee2e2; color: #991b1b; padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }

        .menu-link { text-decoration: none; color: var(--dark); font-weight: 600; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 10px; transition: color 0.2s; }
        .menu-link:hover { color: var(--primary); }
        
        /* Integrity metrics */
        .integrity-box { display: flex; gap: 15px; font-size: 0.8rem; color: #64748b; }
        .integrity-item { display: flex; align-items: center; gap: 4px; }
        .integrity-item i { color: #94a3b8; }
        .integrity-bad i { color: var(--danger); }
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
            <span class="text-muted small">Monitoreo académico y de integridad</span>
        </div>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm">
            <i class="fas fa-print"></i> Imprimir
        </button>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e0e7ff; color: var(--primary);"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo $total_alumnos; ?></div>
                <div class="stat-label">Total Evaluaciones</div>
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
                <div class="stat-icon" style="background: #fee2e2; color: var(--danger);"><i class="fas fa-shield-alt"></i></div>
                <div class="stat-value"><?php echo $total_incidentes; ?></div>
                <div class="stat-label">Alertas de Integridad</div>
            </div>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-12 mb-4">
            <div class="table-card">
                <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold m-0"><i class="fas fa-list"></i> Detalle Completo</h6>
                    <small class="text-muted">Ordenado por fecha reciente</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Examen</th>
                                <th>Monitoreo de Integridad</th>
                                <th class="text-center">Nota</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($resultados)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No hay resultados registrados aún.</td></tr>
                            <?php else: ?>
                                <?php foreach($resultados as $a): 
                                    $estilo = getBadge($a['porcentaje']);
                                    
                                    // Datos de integridad
                                    $swaps = $a['intentos_tab_switch'] ?? 0;
                                    $time_away = $a['segundos_fuera'] ?? 0;
                                    $integrity = getIntegrityBadge($swaps, $time_away);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($a['usuario_nombre']); ?></div>
                                        <small class="text-muted"><?php echo date('d/m H:i', strtotime($a['fecha_realizacion'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="d-inline-block text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($a['quiz_titulo']); ?>">
                                            <?php echo htmlspecialchars($a['quiz_titulo']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="integrity-box">
                                            <div class="integrity-item <?= $swaps > 0 ? 'integrity-bad' : '' ?>">
                                                <i class="far fa-window-restore"></i> <?= $swaps ?> Salidas
                                            </div>
                                            <div class="integrity-item <?= $time_away > 5 ? 'integrity-bad' : '' ?>">
                                                <i class="far fa-clock"></i> <?= $time_away ?>s Fuera
                                            </div>
                                            <span class="<?= $integrity[0] ?>">
                                                <i class="fas <?= $integrity[2] ?>"></i> <?= $integrity[1] ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold"><?php echo $a['porcentaje']; ?>%</td>
                                    <td class="text-center">
                                        <span class="<?= $estilo[0] ?>"><?= $estilo[1] ?></span>
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
</body>
</html>