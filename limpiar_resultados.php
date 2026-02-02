<?php
session_start();
require 'db.php';

// Validar sesión (Seguridad)
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    // Si no hay sesión, al menos pedir una clave simple o redirigir
    // Para emergencias, podemos dejarlo abierto pero con advertencia, 
    // pero idealmente debe ser seguro. Usaremos la misma lógica de editar_quiz.php
    header('Location: login.php');
    exit;
}

$mensaje = '';
$preview_data = [];
$count_to_delete = 0;

// Cargar Quizzes para el select
$stmt = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY id DESC");
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lógica de Preview y Borrado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quiz_id = (int)$_POST['quiz_id'];
    $fecha_corte = $_POST['fecha_corte']; // Formato YYYY-MM-DDTHH:MM
    $accion = $_POST['accion'];

    if ($quiz_id && $fecha_corte) {
        // Añadir :00 si falta segundos para el formato SQL
        $fecha_sql = date('Y-m-d H:i:s', strtotime($fecha_corte));

        if ($accion === 'preview') {
            $stmt = $pdo->prepare("
                SELECT r.id, u.nombre, r.fecha_realizacion, r.puntos_obtenidos 
                FROM resultados r
                JOIN usuarios u ON r.usuario_id = u.id
                WHERE r.quiz_id = ? AND r.fecha_realizacion < ?
                ORDER BY r.fecha_realizacion DESC
            ");
            $stmt->execute([$quiz_id, $fecha_sql]);
            $preview_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $count_to_delete = count($preview_data);
            
            if ($count_to_delete === 0) {
                $mensaje = "ℹ️ No se encontraron exámenes anteriores a esa fecha/hora.";
            } else {
                $mensaje = "⚠️ Se encontraron $count_to_delete registros para eliminar. Revisa la lista abajo.";
            }

        } elseif ($accion === 'delete') {
            try {
                // 1. Obtener IDs a borrar
                $stmt = $pdo->prepare("SELECT id FROM resultados WHERE quiz_id = ? AND fecha_realizacion < ?");
                $stmt->execute([$quiz_id, $fecha_sql]);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($ids)) {
                    $inQuery = implode(',', array_fill(0, count($ids), '?'));
                    
                    // 2. Borrar respuestas (CASCADE manual)
                    $stmtDelR = $pdo->prepare("DELETE FROM respuestas_usuarios WHERE resultado_id IN ($inQuery)");
                    $stmtDelR->execute($ids);

                    // 3. Borrar resultados
                    $stmtDel = $pdo->prepare("DELETE FROM resultados WHERE id IN ($inQuery)");
                    $stmtDel->execute($ids);

                    $mensaje = "✅ ¡Éxito! Se eliminaron " . count($ids) . " exámenes correctamente.";
                } else {
                    $mensaje = "⚠️ No había nada que borrar.";
                }
            } catch (Exception $e) {
                $mensaje = "❌ Error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Limpiar Resultados por Hora</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        h1 { color: #111827; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; }
        select, input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; margin-bottom: 10px; font-size: 1rem; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-preview { background: #4f46e5; color: white; width: 100%; }
        .btn-preview:hover { background: #4338ca; }
        .btn-danger { background: #ef4444; color: white; width: 100%; margin-top: 20px; }
        .btn-danger:hover { background: #dc2626; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .alert-info { background: #eff6ff; color: #1e40af; }
        .alert-warn { background: #fef3c7; color: #92400e; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #b91c1c; }

        .preview-list { margin-top: 30px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        .preview-item { padding: 12px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; font-size: 0.9rem; }
        .preview-item:last-child { border-bottom: none; }
        .preview-item:nth-child(even) { background: #f9fafb; }
        .time-badge { background: #e5e7eb; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
    </style>
</head>
<body>

<div class="container">
    <a href="profesor.php" style="display:inline-block; margin-bottom:20px; color:#6b7280; text-decoration:none;">&larr; Volver al Panel</a>
    
    <h1>🧹 Limpieza de Exámenes por Horario</h1>
    <p style="color:#6b7280; margin-bottom:30px;">
        Esta herramienta permite eliminar exámenes realizados <strong>antes</strong> de una hora específica. 
        Útil para limpiar intentos de jornadas anteriores (ej. borrar todo lo de la mañana antes de las 13:00).
    </p>

    <?php if ($mensaje): ?>
        <div class="alert <?= strpos($mensaje, '✅')!==false ? 'alert-success' : (strpos($mensaje, '❌')!==false ? 'alert-error' : 'alert-warn') ?>">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>1. Selecciona el Examen:</label>
            <select name="quiz_id" required>
                <option value="">-- Elige un Quiz --</option>
                <?php foreach ($quizzes as $q): ?>
                    <option value="<?= $q['id'] ?>" <?= (isset($_POST['quiz_id']) && $_POST['quiz_id'] == $q['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($q['titulo']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>2. Fecha y Hora de Corte (Se borrará TODO LO ANTERIOR a esto):</label>
            <input type="datetime-local" name="fecha_corte" value="<?= $_POST['fecha_corte'] ?? '' ?>" required>
            <small style="color:#6b7280;">Ejemplo: Si pones 09:00 AM, se borrarán todos los exámenes hechos a las 8:59, 8:00, ayer, etc.</small>
        </div>

        <?php if (empty($preview_data)): ?>
            <button type="submit" name="accion" value="preview" class="btn btn-preview">🔍 Buscar Exámenes para Eliminar</button>
        <?php endif; ?>

        <?php if (!empty($preview_data)): ?>
            <div class="preview-list">
                <div style="padding:15px; background:#f3f4f6; font-weight:bold; border-bottom:1px solid #e5e7eb;">
                    Resumen: Se eliminarán <?= count($preview_data) ?> exámenes
                </div>
                <?php foreach ($preview_data as $row): ?>
                    <div class="preview-item">
                        <div>
                            <strong><?= htmlspecialchars($row['nombre']) ?></strong>
                            <span style="color:#6b7280; margin-left:10px;">Puntaje: <?= $row['puntos_obtenidos'] ?></span>
                        </div>
                        <span class="time-badge"><?= date('d/m H:i', strtotime($row['fecha_realizacion'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" name="accion" value="delete" class="btn btn-danger" onclick="return confirm('⚠️ ¿ESTÁS 100% SEGURO?\n\nSe eliminarán TODOS los exámenes listados arriba de forma PERMANENTE.\n\nEsta acción NO se puede deshacer.')">
                🗑️ CONFIRMAR ELIMINACIÓN MASIVA
            </button>
            
            <a href="limpiar_resultados.php" style="display:block; text-align:center; margin-top:15px; color:#6b7280;">Cancelar y volver a empezar</a>
        <?php endif; ?>
    </form>
</div>

</body>
</html>
