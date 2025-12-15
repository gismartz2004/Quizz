<?php
session_start();
require '../db.php'; 

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: ../login.php');
    exit;
}

$mensaje = '';

// 2. LOGICA PARA ACTUALIZAR FECHA (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_fecha'])) {
    $resultado_id = $_POST['resultado_id'];
    $nueva_fecha = $_POST['nueva_fecha']; // Formato Y-m-d\TH:i

    try {
        $stmtUpdate = $pdo->prepare("UPDATE resultados SET fecha_realizacion = ? WHERE id = ?");
        $stmtUpdate->execute([$nueva_fecha, $resultado_id]);
        $mensaje = '<div class="alert alert-success">Fecha actualizada correctamente.</div>';
    } catch (PDOException $e) {
        $mensaje = '<div class="alert alert-danger">Error al actualizar: ' . $e->getMessage() . '</div>';
    }
}

// 3. OBTENER LISTA DE QUIZZES
try {
    $stmtQ = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY titulo ASC");
    $quizzes = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 4. OBTENER RESULTADOS FILTRADOS POR MATERIA
$resultados = [];
$quiz_seleccionado = isset($_GET['quiz_id']) ? $_GET['quiz_id'] : null;

if ($quiz_seleccionado) {
    try {
        $sql = "SELECT 
                    r.id,
                    r.fecha_realizacion,
                    r.puntos_obtenidos,
                    r.porcentaje,
                    u.nombre as usuario_nombre, 
                    u.email,
                    q.titulo as quiz_titulo 
                FROM resultados r
                JOIN usuarios u ON r.usuario_id = u.id
                JOIN quizzes q ON r.quiz_id = q.id
                WHERE r.quiz_id = ?
                ORDER BY u.nombre ASC";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quiz_seleccionado]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error al cargar datos: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Fechas de Examen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', sans-serif; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: none; }
        .table thead th { background: #f1f5f9; color: #64748b; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; }
        .btn-edit { background: #e0e7ff; color: #4361ee; border: none; padding: 5px 10px; border-radius: 6px; transition: 0.2s; }
        .btn-edit:hover { background: #4361ee; color: white; }
    </style>
</head>
<body>

<div class="container py-5">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark"><i class="far fa-calendar-alt text-primary"></i> Modificar Fechas de Examen</h3>
        <a href="../profesor.php" class="btn btn-outline-secondary btn-sm">Volver al Panel</a>
    </div>

    <?= $mensaje ?>

    <div class="card-custom p-4 mb-4">
        <form method="GET" class="row align-items-end">
            <div class="col-md-8">
                <label class="form-label fw-bold text-muted">Seleccione la Materia (Quiz)</label>
                <select name="quiz_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($quizzes as $q): ?>
                        <option value="<?= $q['id'] ?>" <?= $quiz_seleccionado == $q['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($q['titulo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <?php if ($quiz_seleccionado): ?>
                    <div class="text-muted small mt-2">
                        Mostrando <strong><?= count($resultados) ?></strong> estudiantes.
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($quiz_seleccionado): ?>
        <div class="card-custom p-0 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Estudiante</th>
                            <th>Nota Obtenida</th>
                            <th>Fecha de Realización</th>
                            <th class="text-end pe-4">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($resultados)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">Nadie ha realizado este examen aún.</td></tr>
                        <?php else: ?>
                            <?php foreach ($resultados as $row): 
                                // Formatear fecha para el input HTML (Y-m-d\TH:i)
                                $fecha_iso = date('Y-m-d\TH:i', strtotime($row['fecha_realizacion']));
                                // Formatear fecha para leer (d/m/Y H:i)
                                $fecha_nice = date('d/m/Y H:i', strtotime($row['fecha_realizacion']));
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['usuario_nombre']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['email']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $row['porcentaje'] >= 70 ? 'success' : 'danger' ?>">
                                            <?= $row['porcentaje'] ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <i class="far fa-clock text-muted me-1"></i> <?= $fecha_nice ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn-edit" 
                                                onclick="abrirModal(<?= $row['id'] ?>, '<?= $row['usuario_nombre'] ?>', '<?= $fecha_iso ?>')">
                                            <i class="fas fa-pencil-alt"></i> Editar Fecha
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif (!$mensaje): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-arrow-up fa-2x mb-3"></i>
            <p>Por favor seleccione una materia arriba para ver los estudiantes.</p>
        </div>
    <?php endif; ?>

</div>

<div class="modal fade" id="modalFecha" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Fecha de Examen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="actualizar_fecha" value="1">
                    <input type="hidden" name="resultado_id" id="modal_resultado_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Estudiante:</label>
                        <input type="text" class="form-control" id="modal_nombre_estudiante" readonly disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nueva Fecha y Hora:</label>
                        <input type="datetime-local" name="nueva_fecha" id="modal_nueva_fecha" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function abrirModal(id, nombre, fechaActual) {
        document.getElementById('modal_resultado_id').value = id;
        document.getElementById('modal_nombre_estudiante').value = nombre;
        document.getElementById('modal_nueva_fecha').value = fechaActual;
        
        var myModal = new bootstrap.Modal(document.getElementById('modalFecha'));
        myModal.show();
    }
</script>

</body>
</html>