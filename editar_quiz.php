<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php'); exit;
}

if (!isset($_GET['id'])) { header('Location: profesor.php'); exit; }
$id = $_GET['id'];
$mensaje = '';

// --- 1. PROCESAR ELIMINACIÓN DE PREGUNTA ---
if (isset($_GET['delete_question'])) {
    $q_id = $_GET['delete_question'];
    $stmt = $pdo->prepare("DELETE FROM preguntas WHERE id = ? AND quiz_id = ?");
    $stmt->execute([$q_id, $id]);
    header("Location: editar_quiz.php?id=$id&msg=q_deleted"); exit;
}

// --- 2. PROCESAR ACTUALIZACIÓN DEL QUIZ COMPLETO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // A) Actualizar datos generales
        $sql = "UPDATE quizzes SET titulo=?, descripcion=?, color_primario=?, color_secundario=?, valor_total=?, duracion_minutos=?, fecha_inicio=?, fecha_fin=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['titulo'], $_POST['descripcion'], $_POST['color_primario'], $_POST['color_secundario'], 
            $_POST['valor_total'], $_POST['duracion_minutos'], $_POST['fecha_inicio'], $_POST['fecha_fin'], $id
        ]);

        // B) Actualizar Preguntas y Respuestas
        if (isset($_POST['preguntas'])) {
            foreach ($_POST['preguntas'] as $p_id => $data) {
                // Actualizar texto pregunta
                $stmtP = $pdo->prepare("UPDATE preguntas SET texto = ?, valor = ? WHERE id = ?");
                $stmtP->execute([$data['texto'], $data['valor'], $p_id]);

                // Actualizar respuestas
                if (isset($data['respuestas'])) {
                    foreach ($data['respuestas'] as $r_id => $r_data) {
                        // Verificar si esta es la correcta (radio button envía value = r_id)
                        $es_correcta = (isset($data['correcta']) && $data['correcta'] == $r_id) ? 1 : 0;
                        
                        $stmtR = $pdo->prepare("UPDATE opciones SET texto = ?, es_correcta = ? WHERE id = ?");
                        $stmtR->execute([$r_data['texto'], $es_correcta, $r_id]);
                    }
                }
            }
        }

        $pdo->commit();
        $mensaje = "✅ Cambios guardados correctamente.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

// --- 3. CARGAR DATOS ---
$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->execute([$id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

// Cargar preguntas y sus respuestas
$stmtP = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ?");
$stmtP->execute([$id]);
$preguntas = $stmtP->fetchAll(PDO::FETCH_ASSOC);

foreach ($preguntas as &$p) {
    $stmtO = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
    $stmtO->execute([$p['id']]);
    $p['opciones'] = $stmtO->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Quiz</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding: 30px; color: #334155; }
        .container { max-width: 900px; margin: 0 auto; }
        
        /* Card Styles */
        .card { background: white; border-radius: 12px; padding: 30px; border: 1px solid #e2e8f0; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        
        /* Forms */
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; }
        input[type="text"], input[type="number"], input[type="datetime-local"], textarea {
            width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        /* Questions Editor */
        .q-item { background: #f1f5f9; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #4f46e5; }
        .q-header { display: flex; justify-content: space-between; margin-bottom: 15px; align-items: center; }
        .q-title-input { font-weight: 600; width: 70%; }
        .q-val-input { width: 80px; }
        
        .opt-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .opt-radio { width: 20px; height: 20px; cursor: pointer; }
        .opt-text { flex-grow: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }

        /* Buttons */
        .btn { padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-save { background: #4f46e5; color: white; font-size: 1rem; width: 100%; justify-content: center; }
        .btn-save:hover { background: #4338ca; }
        .btn-back { background: transparent; color: #64748b; margin-bottom: 20px; }
        .btn-del-q { background: #fee2e2; color: #991b1b; padding: 5px 10px; font-size: 0.8rem; border-radius: 4px; }
        
        .alert { padding: 15px; background: #dcfce7; color: #166534; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="container">
    <a href="profesor.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Volver al Panel</a>

    <?php if ($mensaje): ?>
        <div class="alert"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="card">
            <h2 style="margin-top:0; margin-bottom:20px;">⚙️ Configuración General</h2>
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="titulo" value="<?= htmlspecialchars($quiz['titulo']) ?>" required>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion" rows="2"><?= htmlspecialchars($quiz['descripcion']) ?></textarea>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>Apertura</label><input type="datetime-local" name="fecha_inicio" value="<?= date('Y-m-d\TH:i', strtotime($quiz['fecha_inicio'])) ?>"></div>
                <div class="form-group"><label>Cierre</label><input type="datetime-local" name="fecha_fin" value="<?= date('Y-m-d\TH:i', strtotime($quiz['fecha_fin'])) ?>"></div>
                <div class="form-group"><label>Minutos</label><input type="number" name="duracion_minutos" value="<?= $quiz['duracion_minutos'] ?>"></div>
                <div class="form-group"><label>Puntos Totales</label><input type="number" name="valor_total" value="<?= $quiz['valor_total'] ?>"></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>Color 1</label><input type="color" name="color_primario" value="<?= $quiz['color_primario'] ?>" style="width:100%; height:40px"></div>
                <div class="form-group"><label>Color 2</label><input type="color" name="color_secundario" value="<?= $quiz['color_secundario'] ?>" style="width:100%; height:40px"></div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0; margin-bottom:20px;">📝 Editar Preguntas</h2>
            
            <?php foreach($preguntas as $i => $p): ?>
                <div class="q-item">
                    <div class="q-header">
                        <span style="font-weight:700; color:#4f46e5;">#<?= $i+1 ?></span>
                        <input type="text" name="preguntas[<?= $p['id'] ?>][texto]" class="q-title-input" value="<?= htmlspecialchars($p['texto']) ?>">
                        <input type="number" name="preguntas[<?= $p['id'] ?>][valor]" class="q-val-input" value="<?= $p['valor'] ?>" title="Puntos">
                        <a href="?id=<?= $id ?>&delete_question=<?= $p['id'] ?>" class="btn-del-q" onclick="return confirm('¿Borrar esta pregunta?')"><i class="fas fa-trash"></i></a>
                    </div>

                    <div class="q-options">
                        <label style="font-size:0.8rem; color:#64748b;">RESPUESTAS (Marca la correcta)</label>
                        <?php foreach($p['opciones'] as $op): ?>
                            <div class="opt-row">
                                <input type="radio" name="preguntas[<?= $p['id'] ?>][correcta]" value="<?= $op['id'] ?>" class="opt-radio" <?= $op['es_correcta'] ? 'checked' : '' ?>>
                                <input type="text" name="preguntas[<?= $p['id'] ?>][respuestas][<?= $op['id'] ?>][texto]" class="opt-text" value="<?= htmlspecialchars($op['texto']) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="position:sticky; bottom:20px; z-index:100;">
            <button type="submit" class="btn btn-save shadow"><i class="fas fa-save"></i> Guardar Todos los Cambios</button>
        </div>
    </form>
</div>

</body>
</html>