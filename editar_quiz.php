<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['id'])) {
    header('Location: profesor.php');
    exit;
}
$id = (int)$_GET['id'];
$mensaje = '';

// Mensajes de operaciones
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'saved':
            $mensaje = '✅ Cambios guardados correctamente.';
            break;
        case 'q_deleted':
            $mensaje = '✅ Pregunta eliminada correctamente.';
            break;
        case 'opt_deleted':
            $mensaje = '✅ Opción eliminada correctamente.';
            break;
        case 'opt_error':
            $mensaje = '❌ Error: Debe haber al menos 2 opciones por pregunta.';
            break;
        case 'opt_is_correct':
            $mensaje = '❌ No puedes eliminar la respuesta correcta. Primero marca otra opción como correcta.';
            break;
    }
}


// --- Eliminar pregunta
if (isset($_GET['delete_question'])) {
    $q_id = (int)$_GET['delete_question'];
    $stmt = $pdo->prepare("DELETE FROM preguntas WHERE id = ? AND quiz_id = ?");
    $stmt->execute([$q_id, $id]);
    header("Location: editar_quiz.php?id=$id&msg=q_deleted");
    exit;
}

// --- Eliminar opción
if (isset($_GET['delete_option'])) {
    $opt_id = (int)$_GET['delete_option'];
    
    // Verificar que la opción no es la correcta
    $stmt = $pdo->prepare("SELECT es_correcta FROM opciones WHERE id = ?");
    $stmt->execute([$opt_id]);
    $opt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($opt && ($opt['es_correcta'] === true || $opt['es_correcta'] === 't' || $opt['es_correcta'] == 1)) {
        header("Location: editar_quiz.php?id=$id&msg=opt_is_correct");
        exit;
    }
    
    // Verificar que la opción pertenece a este quiz y que quedarán al menos 2 opciones
    $stmt = $pdo->prepare("
        SELECT p.quiz_id, COUNT(*) as total_opciones
        FROM opciones o
        JOIN preguntas p ON o.pregunta_id = p.id
        WHERE o.pregunta_id = (SELECT pregunta_id FROM opciones WHERE id = ?)
        GROUP BY p.quiz_id
    ");
    $stmt->execute([$opt_id]);
    $check = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($check && $check['quiz_id'] == $id && $check['total_opciones'] > 2) {
        $stmt = $pdo->prepare("DELETE FROM opciones WHERE id = ?");
        $stmt->execute([$opt_id]);
        header("Location: editar_quiz.php?id=$id&msg=opt_deleted");
    } else {
        header("Location: editar_quiz.php?id=$id&msg=opt_error");
    }
    exit;
}

// --- Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Formatear fechas
        $f_inicio = !empty($_POST['fecha_inicio']) ? ($_POST['fecha_inicio'] . ':00') : null;
        $f_fin    = !empty($_POST['fecha_fin'])    ? ($_POST['fecha_fin']    . ':00') : null;

        // Actualizar quiz
        $stmt = $pdo->prepare("UPDATE quizzes SET 
            titulo = ?, descripcion = ?, color_primario = ?, color_secundario = ?, 
            valor_total = ?, duracion_minutos = ?, fecha_inicio = ?, fecha_fin = ?,
            es_nne = ?
            WHERE id = ?");
        
        $es_nne = isset($_POST['es_nne']) ? 'true' : 'false';

        $stmt->execute([
            $_POST['titulo'],
            $_POST['descripcion'],
            $_POST['color_primario'],
            $_POST['color_secundario'],
            (int)$_POST['valor_total'],
            (int)$_POST['duracion_minutos'],
            $f_inicio,
            $f_fin,
            $es_nne,
            $id
        ]);

        // Actualizar preguntas y respuestas
        if (!empty($_POST['preguntas']) && is_array($_POST['preguntas'])) {
            foreach ($_POST['preguntas'] as $p_id_raw => $data) {
                $p_id = (int)$p_id_raw;
                if (!$p_id) continue;

                // 🔥 CORRECCIÓN DEFINITIVA: forzar 1 o 0 (entero seguro para BOOLEAN en PostgreSQL)
                $requiere_just = (int)(isset($data['requiere_justificacion']) && $data['requiere_justificacion'] !== '');
                $texto_just = $requiere_just ? trim($data['texto_justificacion'] ?? '') : '';

                $stmtP = $pdo->prepare("UPDATE preguntas SET 
                    texto = ?, valor = ?, requiere_justificacion = ?, texto_justificacion = ? 
                    WHERE id = ?");
                $stmtP->execute([
                    trim($data['texto'] ?? ''),
                    (int)($data['valor'] ?? 0),
                    $requiere_just ? 'true' : 'false', // ✅ Fix: Send 'true'/'false' for PostgreSQL BOOLEAN
                    $texto_just,
                    $p_id
                ]);

                // Actualizar opciones
                if (!empty($data['respuestas']) && is_array($data['respuestas'])) {
                    // Paso 1: Verificar qué opciones existen realmente
                    $validOptions = [];
                    foreach ($data['respuestas'] as $r_id_raw => $r_data) {
                        $r_id = (int)$r_id_raw;
                        if (!$r_id) continue;

                        // Verificar que la opción existe y pertenece a esta pregunta
                        $checkOpt = $pdo->prepare("SELECT id FROM opciones WHERE id = ? AND pregunta_id = ?");
                        $checkOpt->execute([$r_id, $p_id]);
                        if ($checkOpt->fetch()) {
                            $validOptions[$r_id] = $r_data;
                        }
                    }

                    // Paso 2: Verificar que la respuesta correcta seleccionada existe
                    $correcta_id = (int)($data['correcta'] ?? 0);
                    if (!isset($validOptions[$correcta_id]) && !empty($validOptions)) {
                        // La correcta fue eliminada, usar la primera opción válida
                        $correcta_id = (int)array_key_first($validOptions);
                    }

                    // Paso 3: Actualizar solo opciones válidas
                    foreach ($validOptions as $r_id => $r_data) {
                        $es_correcta = ($r_id === $correcta_id) ? 1 : 0;

                        $stmtR = $pdo->prepare("UPDATE opciones SET texto = ?, es_correcta = ? WHERE id = ?");
                        $stmtR->execute([
                            trim($r_data['texto'] ?? ''),
                            $es_correcta ? 'true' : 'false',
                            $r_id
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
        header("Location: editar_quiz.php?id=$id&msg=saved");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . htmlspecialchars($e->getMessage());
    }
}

// --- Cargar datos del quiz
$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->execute([$id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    die("Quiz no encontrado.");
}

// Cargar preguntas y sus opciones (justificación al final)
$stmtP = $pdo->prepare("
    SELECT * FROM preguntas 
    WHERE quiz_id = ? 
    ORDER BY 
        CASE WHEN requiere_justificacion = TRUE THEN 1 ELSE 0 END,
        id ASC
");
$stmtP->execute([$id]);
$preguntas = $stmtP->fetchAll(PDO::FETCH_ASSOC);

foreach ($preguntas as &$p) {
    $stmtO = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
    $stmtO->execute([$p['id']]);
    $p['opciones'] = $stmtO->fetchAll(PDO::FETCH_ASSOC);
}
unset($p); // ⚠️ CRÍTICO: Romper la referencia para evitar corrupción en el siguiente bucle foreach
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
        .card { background: white; border-radius: 12px; padding: 30px; border: 1px solid #e2e8f0; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; }
        input[type="text"], input[type="number"], input[type="datetime-local"], textarea {
            width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .q-item { background: #f1f5f9; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #4f46e5; }
        .q-header { display: flex; justify-content: space-between; margin-bottom: 10px; align-items: flex-start; gap: 10px; }
        .q-title-input { font-weight: 600; width: 100%; min-height: 60px; resize: vertical; }
        .q-val-input { width: 80px; }
        .justificacion-section { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #cbd5e1; }
        .opt-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
        .opt-radio { width: 20px; height: 20px; cursor: pointer; margin-top: 10px; }
        .opt-text { flex-grow: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-height: 42px; resize: vertical; font-family: inherit; }
        .btn { padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-save { background: #4f46e5; color: white; font-size: 1rem; width: 100%; justify-content: center; }
        .btn-save:hover { background: #4338ca; }
        .btn-back { background: transparent; color: #64748b; margin-bottom: 20px; }
        .btn-del-q { background: #fee2e2; color: #991b1b; padding: 5px 10px; font-size: 0.8rem; border-radius: 4px; }
        .btn-del-opt { background: #fef3c7; color: #92400e; padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; transition: all 0.2s; }
        .btn-del-opt:hover { background: #fde68a; }
        .alert, .error { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #b91c1c; }
        .toggle-just { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 0.9rem; color: #475569; }
    </style>
</head>
<body>

<div class="container">
    <a href="profesor.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Volver al Panel</a>

    <?php if ($mensaje): ?>
        <div class="<?= strpos($mensaje, '❌') !== false ? 'error' : 'alert' ?>"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST">
        <!-- Configuración general -->
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

            <div class="form-group" style="background: #fff7ed; padding: 15px; border-radius: 10px; border: 1px solid #fed7aa; margin-bottom: 25px;">
                <label style="cursor:pointer; display:flex; align-items:center; gap:10px; margin:0;">
                    <input type="checkbox" name="es_nne" value="1" <?= !empty($quiz['es_nne']) ? 'checked' : '' ?> style="width:20px; height:20px;">
                    <div>
                        <span style="font-weight:700; color:#9a3412;">Examen Privado (Solo NNE)</span>
                        <p style="margin:0; font-size:0.8rem; color:#c2410c;">Este examen no aparecerá en el portal general. Los estudiantes solo podrán entrar con el enlace directo.</p>
                    </div>
                </label>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Apertura</label>
                    <input type="datetime-local" name="fecha_inicio" value="<?= $quiz['fecha_inicio'] ? date('Y-m-d\TH:i', strtotime($quiz['fecha_inicio'])) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Cierre</label>
                    <input type="datetime-local" name="fecha_fin" value="<?= $quiz['fecha_fin'] ? date('Y-m-d\TH:i', strtotime($quiz['fecha_fin'])) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Minutos</label>
                    <input type="number" name="duracion_minutos" value="<?= (int)$quiz['duracion_minutos'] ?>">
                </div>
                <div class="form-group">
                    <label>Puntos Totales</label>
                    <input type="number" name="valor_total" value="<?= (int)$quiz['valor_total'] ?>">
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Color 1</label>
                    <input type="color" name="color_primario" value="<?= htmlspecialchars($quiz['color_primario']) ?>" style="width:100%; height:40px">
                </div>
                <div class="form-group">
                    <label>Color 2</label>
                    <input type="color" name="color_secundario" value="<?= htmlspecialchars($quiz['color_secundario']) ?>" style="width:100%; height:40px">
                </div>
            </div>
        </div>

        <!-- Preguntas -->
        <div class="card">
            <h2 style="margin-top:0; margin-bottom:20px;">📝 Editar Preguntas</h2>
            <?php foreach($preguntas as $i => $p): ?>
                <div class="q-item">
                    <div class="q-header">
                        <span style="font-weight:700; color:#4f46e5; white-space:nowrap;">#<?= $i+1 ?></span>
                        <!-- Changed to textarea -->
                        <textarea name="preguntas[<?= $p['id'] ?>][texto]" class="q-title-input" required><?= htmlspecialchars($p['texto']) ?></textarea>
                        
                        <input type="number" name="preguntas[<?= $p['id'] ?>][valor]" class="q-val-input" value="<?= (int)$p['valor'] ?>" min="0" required title="Puntos">
                        
                        <a href="?id=<?= $id ?>&delete_question=<?= $p['id'] ?>" class="btn-del-q" onclick="return confirm('¿Borrar esta pregunta?')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>

                    <!-- Opciones -->
                    <div class="q-options">
                        <label style="font-size:0.8rem; color:#64748b;">RESPUESTAS (Marca la correcta)</label>
                        <?php foreach($p['opciones'] as $op): ?>
                            <div class="opt-row">
                                <input type="radio" name="preguntas[<?= $p['id'] ?>][correcta]" value="<?= $op['id'] ?>" class="opt-radio" <?= $op['es_correcta'] ? 'checked' : '' ?> required>
                                <!-- Changed to textarea -->
                                <textarea name="preguntas[<?= $p['id'] ?>][respuestas][<?= $op['id'] ?>][texto]" class="opt-text" required><?= htmlspecialchars($op['texto']) ?></textarea>
                                
                                <?php if (count($p['opciones']) > 2): ?>
                                    <button type="button" class="btn-del-opt" onclick="deleteOption(<?= $op['id'] ?>, <?= $id ?>)" title="Eliminar opción">
                                        <i class="fas fa-times"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Justificación -->
                    <div class="justificacion-section">
                        <?php 
                        $rj = $p['requiere_justificacion'] ?? false;
                        $isRj = ($rj === true || $rj === 'true' || $rj === 't' || $rj == 1);
                        ?>
                        <label class="toggle-just">
                            <input type="checkbox" name="preguntas[<?= $p['id'] ?>][requiere_justificacion]" 
                                <?= $isRj ? 'checked' : '' ?>>
                            Requiere que el estudiante justifique su respuesta
                        </label>
                        <div id="just-text-<?= $p['id'] ?>" style="display:<?= $isRj ? 'block' : 'none' ?>;">
                            <label style="font-size:0.85rem; color:#475569; margin-top:6px;">Texto de instrucción (opcional)</label>
                            <textarea name="preguntas[<?= $p['id'] ?>][texto_justificacion]" class="justificacion-field" rows="2"><?= htmlspecialchars($p['texto_justificacion'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="position:sticky; bottom:20px; z-index:100;">
            <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Guardar Todos los Cambios</button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Fix: Use more specific selector and regex to avoid mismatches
            const checkboxes = document.querySelectorAll('input[name^="preguntas"][name$="[requiere_justificacion]"]');
            
            checkboxes.forEach(checkbox => {
                // Extract ID strictly from "preguntas[ID][requiere_justificacion]"
                const match = checkbox.name.match(/preguntas\[(\d+)\]\[requiere_justificacion\]/);
                if (!match) return;
                
                const qId = match[1];
                const textDiv = document.getElementById('just-text-' + qId);
                
                if (textDiv) {
                    checkbox.addEventListener('change', () => {
                        textDiv.style.display = checkbox.checked ? 'block' : 'none';
                    });
                }
            });
        });

        // Función para eliminar opción
        function deleteOption(optId, quizId) {
            if (confirm('¿Eliminar esta opción de respuesta?\n\nNOTA: Debe haber al menos 2 opciones por pregunta.')) {
                window.location.href = `editar_quiz.php?id=${quizId}&delete_option=${optId}`;
            }
        }
    </script>
</div>

</body>
</html>