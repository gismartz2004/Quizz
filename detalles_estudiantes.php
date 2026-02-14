<?php
require_once 'includes/session.php';
require 'db.php';



$estudiantes_encontrados = [];
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

// 2. LÓGICA DE BÚSQUEDA
if ($busqueda !== '') {
    // Buscamos por Contraseña (exacta), Nombre (parcial) o Email (parcial)
    // NOTA: Si usas password_hash(), la búsqueda por contraseña NO funcionará directamente en SQL.
    // Este código asume que quieres buscar texto plano o coincidencias directas.
    $sql = "SELECT * FROM usuarios 
            WHERE rol = 'estudiante' 
            AND (password = :b1 OR nombre ILIKE :b2 OR email ILIKE :b3)";
            // ILIKE es case-insensitive en Postgres. Si usas MySQL usa LIKE.
    
    try {
        $stmt = $pdo->prepare($sql);
        $term = "%$busqueda%";
        $stmt->execute([
            'b1' => $busqueda, // Contraseña exacta
            'b2' => $term,     // Nombre parcial
            'b3' => $term      // Email parcial
        ]);
        $estudiantes_encontrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error en búsqueda: " . $e->getMessage();
    }
}

// 3. FUNCIÓN PARA OBTENER DETALLES DEMOGRÁFICOS Y RESPUESTAS
function getDetallesExamenes($pdo, $usuario_id) {
    $sql = "SELECT 
                r.id as resultado_id,
                q.titulo as examen,
                r.puntos_obtenidos,
                r.fecha_realizacion,
                -- Datos Demográficos guardados en 'resultados'
                r.edad,
                r.genero,
                r.residencia,
                r.discapacidad,
                r.paralelo,
                r.grado,
                r.jornada
            FROM resultados r
            JOIN quizzes q ON r.quiz_id = q.id
            WHERE r.usuario_id = ?
            ORDER BY r.fecha_realizacion DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalles del Estudiante</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', sans-serif; }
        .search-box { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .student-card { background: white; border-radius: 12px; overflow: hidden; margin-bottom: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .student-header { background: #4f46e5; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; padding: 15px; background: #f8fafc; border-radius: 8px; margin: 10px 0; font-size: 0.85rem; }
        .info-item label { display: block; color: #64748b; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
        .info-item span { color: #1e293b; font-weight: 500; }
        
        /* Modal */
        .modal-content { border-radius: 10px; border: none; }
        .modal-header { background: #4f46e5; color: white; }
        .btn-close { filter: invert(1); }
    </style>
</head>
<body>

<div class="container py-5">
    
    <div class="row justify-content-center mb-4">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="fw-bold text-dark"><i class="fas fa-id-card text-primary"></i> Búsqueda de Estudiante</h2>
                <a href="profesor.php" class="btn btn-outline-secondary btn-sm">Volver al Panel</a>
            </div>

            <div class="search-box">
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="busqueda" class="form-control form-control-lg" 
                           placeholder="Buscar por Contraseña, Nombre o Email..." 
                           value="<?= htmlspecialchars($busqueda) ?>" required>
                    <button type="submit" class="btn btn-primary btn-lg px-4"><i class="fas fa-search"></i></button>
                </form>
                <div class="form-text mt-2 text-muted">
                    <i class="fas fa-info-circle"></i> Si buscas por contraseña, debe ser exacta. Por nombre o email puede ser parcial.
                </div>
            </div>
        </div>
    </div>

    <?php if ($busqueda && empty($estudiantes_encontrados)): ?>
        <div class="alert alert-warning text-center">
            No se encontraron estudiantes con esa información.
        </div>
    <?php endif; ?>

    <?php foreach ($estudiantes_encontrados as $est): 
        $examenes = getDetallesExamenes($pdo, $est['id']);
    ?>
        <div class="student-card animate__animated animate__fadeIn">
            <div class="student-header">
                <div>
                    <h4 class="m-0 fw-bold"><i class="fas fa-user-graduate me-2"></i> <?= htmlspecialchars($est['nombre']) ?></h4>
                    <small class="opacity-75"><?= htmlspecialchars($est['email']) ?></small>
                </div>
                <div class="text-end">
                    <span class="badge bg-white text-primary">ID: <?= $est['id'] ?></span>
                    <div class="small mt-1 opacity-75">Reg: <?= date('d/m/Y', strtotime($est['fecha_registro'])) ?></div>
                </div>
            </div>

            <div class="p-4">
                <h5 class="text-secondary border-bottom pb-2 mb-3"><i class="fas fa-history"></i> Historial de Evaluaciones y Datos Demográficos</h5>
                
                <?php if (empty($examenes)): ?>
                    <p class="text-muted fst-italic">Este estudiante aún no ha realizado ninguna evaluación.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Examen / Fecha</th>
                                    <th>Datos Personales (En ese examen)</th>
                                    <th>Residencia / Discap.</th>
                                    <th class="text-center">Nota</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($examenes as $ex): ?>
                                    <tr>
                                        <td width="20%">
                                            <div class="fw-bold text-primary"><?= htmlspecialchars($ex['examen']) ?></div>
                                            <small class="text-muted"><i class="far fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($ex['fecha_realizacion'])) ?></small>
                                        </td>
                                        <td width="30%">
                                            <div class="info-grid m-0 p-2 bg-light border">
                                                <div class="info-item"><label>Edad</label><span><?= $ex['edad'] ?></span></div>
                                                <div class="info-item"><label>Género</label><span><?= $ex['genero'] ?></span></div>
                                                <div class="info-item"><label>Grado</label><span><?= $ex['grado'] ?></span></div>
                                                <div class="info-item"><label>Paralelo</label><span><?= $ex['paralelo'] ?></span></div>
                                                <div class="info-item"><label>Jornada</label><span><?= $ex['jornada'] ?></span></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div><i class="fas fa-map-marker-alt text-muted"></i> <?= htmlspecialchars($ex['residencia']) ?></div>
                                            <?php if ($ex['discapacidad'] && $ex['discapacidad'] !== 'Ninguna'): ?>
                                                <div class="text-danger mt-1 small"><i class="fas fa-wheelchair"></i> <?= htmlspecialchars($ex['discapacidad']) ?></div>
                                            <?php else: ?>
                                                <div class="text-muted mt-1 small">Sin discapacidad</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge rounded-pill bg-<?= $ex['puntos_obtenidos'] >= 70 ? 'success' : 'danger' ?> fs-6">
                                                <?= $ex['puntos_obtenidos'] ?> pts
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" onclick="verRespuestas(<?= $ex['resultado_id'] ?>)">
                                                <i class="fas fa-eye"></i> Ver Respuestas
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<div class="modal fade" id="modalRespuestas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i> Detalle de Respuestas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="contenidoRespuestas">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Cargando respuestas...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function verRespuestas(resultadoId) {
    const modal = new bootstrap.Modal(document.getElementById('modalRespuestas'));
    const contenedor = document.getElementById('contenidoRespuestas');
    
    // Mostrar modal con spinner
    contenedor.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Cargando...</p></div>';
    modal.show();

    // Petición AJAX (Usa el mismo archivo auxiliar que creamos antes)
    fetch(`get_respuestas_ajax.php?resultado_id=${resultadoId}`)
        .then(response => response.text())
        .then(html => {
            contenedor.innerHTML = html;
        })
        .catch(err => {
            contenedor.innerHTML = '<div class="alert alert-danger">Error al cargar las respuestas. Asegúrate de que get_respuestas_ajax.php existe.</div>';
        });
}
</script>

</body>
</html>