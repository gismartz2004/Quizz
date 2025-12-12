<?php
// Opcional: iniciamos sesión por si usas alguna variable global, pero no validamos acceso.
session_start(); 
require 'db.php';

// --- SECCIÓN DE SEGURIDAD ELIMINADA ---
// Ya no verificamos si $_SESSION['usuario_id'] existe.
// ---------------------------------------

// 1. Obtener lista de Quizzes para el filtro
$stmtQuizzes = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY id DESC");
$quizzes = $stmtQuizzes->fetchAll(PDO::FETCH_ASSOC);

// 2. Obtener resultados filtrados
$resultados = [];
$quiz_seleccionado = isset($_GET['quiz_id']) ? $_GET['quiz_id'] : null;

if ($quiz_seleccionado) {
    // Consulta principal: Resultados + Datos de Usuario + Datos del Quiz
    $sql = "
        SELECT 
            r.id AS resultado_id,
            u.nombre, u.apellido,
            r.puntos_obtenidos, r.puntos_totales_quiz, r.porcentaje,
            r.grado, r.paralelo, r.fecha_realizacion,
            r.intentos_tab_switch, r.segundos_fuera
        FROM resultados r
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.quiz_id = ?
        ORDER BY r.grado, r.paralelo, u.apellido
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$quiz_seleccionado]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Calificaciones (Acceso Libre)</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f3f4f6; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        
        /* Estilos Tabla */
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #4f46e5; color: white; }
        tr:hover { background-color: #f9fafb; }
        
        /* Badges y Alertas */
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: bold; }
        .bg-green { background: #dcfce7; color: #166534; }
        .bg-red { background: #fee2e2; color: #991b1b; }
        .alert-cheat { color: #dc2626; font-weight: bold; font-size: 0.9em; }
        
        /* Formulario Filtro */
        .filter-box { background: #eef2ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        select, button { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; }
        button { background: #4f46e5; color: white; border: none; cursor: pointer; }
        
        /* Modal Detalles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; width: 80%; max-width: 800px; margin: 50px auto; padding: 20px; border-radius: 8px; max-height: 80vh; overflow-y: auto; }
        .close { float: right; font-size: 28px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <h1>📊 Reporte de Notas por Materia</h1>

    <div class="filter-box">
        <form method="GET">
            <label>Seleccionar Examen:</label>
            <select name="quiz_id" required>
                <option value="">-- Seleccione --</option>
                <?php foreach ($quizzes as $q): ?>
                    <option value="<?= $q['id'] ?>" <?= $quiz_seleccionado == $q['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($q['titulo']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Ver Resultados</button>
        </form>
    </div>

    <?php if ($quiz_seleccionado && empty($resultados)): ?>
        <p>No hay resultados registrados para este examen.</p>
    <?php elseif (!empty($resultados)): ?>
        
        <table>
            <thead>
                <tr>
                    <th>Estudiante</th>
                    <th>Grado/Paralelo</th>
                    <th>Nota</th>
                    <th>Porcentaje</th>
                    <th>Alertas</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultados as $row): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['apellido'] . ' ' . $row['nombre']) ?></strong>
                        </td>
                        <td><?= htmlspecialchars($row['grado'] . ' - ' . $row['paralelo']) ?></td>
                        <td>
                            <?= $row['puntos_obtenidos'] ?> / <?= $row['puntos_totales_quiz'] ?>
                        </td>
                        <td>
                            <span class="badge <?= $row['porcentaje'] >= 70 ? 'bg-green' : 'bg-red' ?>">
                                <?= $row['porcentaje'] ?>%
                            </span>
                        </td>
                        <td>
                            <?php if ($row['intentos_tab_switch'] > 0): ?>
                                <span class="alert-cheat">⚠️ <?= $row['intentos_tab_switch'] ?> cambios de pestaña</span>
                                <br><small>(<?= $row['segundos_fuera'] ?> seg fuera)</small>
                            <?php else: ?>
                                <span style="color:#aaa;">Limpio</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($row['fecha_realizacion'])) ?></td>
                        <td>
                            <button onclick="verDetalles(<?= $row['resultado_id'] ?>)" style="background:#2563eb; color:white; padding:5px 10px; border-radius:4px; border:none; cursor:pointer;">
                                Ver Respuestas
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<div id="modalRespuestas" class="modal">
    <div class="modal-content">
        <span class="close" onclick="cerrarModal()">&times;</span>
        <h2>Detalle de Respuestas</h2>
        <div id="contenidoDetalle">Cargando...</div>
    </div>
</div>

<script>
function verDetalles(resultadoId) {
    document.getElementById('modalRespuestas').style.display = 'block';
    document.getElementById('contenidoDetalle').innerHTML = '<p>Cargando datos...</p>';

    fetch(`get_respuestas_ajax.php?resultado_id=${resultadoId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('contenidoDetalle').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('contenidoDetalle').innerHTML = 'Error al cargar.';
        });
}

function cerrarModal() {
    document.getElementById('modalRespuestas').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('modalRespuestas')) {
        cerrarModal();
    }
}
</script>

</body>
</html>