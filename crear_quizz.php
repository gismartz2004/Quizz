<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

// Habilitar reporte de errores al máximo
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

$mensaje = '';
$tipoMensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_quiz'])) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // --- ⚠️ DEBUGGING: COMENTAMOS LA TRANSACCIÓN PARA VER EL ERROR REAL ⚠️ ---
        // $pdo->beginTransaction(); 

        echo "\n";

        // 1. INSERTAR QUIZ
        if (empty(trim($_POST['titulo']))) throw new Exception("El título es obligatorio.");

        $creado_por = $_SESSION['usuario_id'] ?? null;
        
        // Verifica si la sesión está vacía (posible causa de error)
        if ($creado_por === null) {
             // Si tu base de datos permite NULL en creado_por, esto está bien.
             // Si NO permite NULL, pon un número temporal (ej: 1) para probar.
             // $creado_por = 1; 
             echo "\n";
        }

        $sqlQuiz = "INSERT INTO quizzes (
            titulo, descripcion, color_primario, color_secundario, valor_total,
            fecha_inicio, fecha_fin, duracion_minutos, creado_por, activo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, true) RETURNING id";

        echo "\n";

        $stmt = $pdo->prepare($sqlQuiz);
        $stmt->execute([
            trim($_POST['titulo']),
            trim($_POST['descripcion'] ?? ''),
            $_POST['color_primario'] ?? '#4f46e5',
            $_POST['color_secundario'] ?? '#4338ca',
            (int)($_POST['valor_total'] ?? 100),
            date('Y-m-d H:i:s', strtotime($_POST['fecha_inicio'])),
            date('Y-m-d H:i:s', strtotime($_POST['fecha_fin'])),
            (int)($_POST['duracion_minutos'] ?? 60),
            $creado_por
        ]);
        
        $quiz_id = $stmt->fetchColumn();
        echo "\n";

        if (!$quiz_id) throw new Exception("Error: No se generó el ID del Quiz.");

        // 2. PROCESAR PREGUNTAS
        $textos = $_POST['pregunta_texto'] ?? [];
        $valores = $_POST['pregunta_valor'] ?? [];
        $imagenes = $_FILES['pregunta_imagen'] ?? [];

        foreach ($textos as $i => $textoPregunta) {
            if (trim($textoPregunta) === '') continue;

            echo "\n";

            // Corregido: 'false' como string para el booleano
            $sqlPregunta = "INSERT INTO preguntas (
                quiz_id, texto, valor, requiere_justificacion
            ) VALUES (?, ?, ?, 'false') RETURNING id";
            
            $stmtP = $pdo->prepare($sqlPregunta);
            $stmtP->execute([
                $quiz_id,
                trim($textoPregunta),
                (int)($valores[$i] ?? 10)
            ]);
            
            $pregunta_id = $stmtP->fetchColumn();
            echo "\n";

            // Manejo de Imagen
            $nombreImagenFinal = null;

            // CASO A: Subida de imagen nueva
            if (isset($imagenes['name'][$i]) && $imagenes['error'][$i] === UPLOAD_ERR_OK) {
                $nombreArchivo = $imagenes['name'][$i];
                $tmpArchivo = $imagenes['tmp_name'][$i];
                $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
                
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $dir = 'assets/images';
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    
                    // 1. GENERAMOS EL NOMBRE UNA SOLA VEZ
                    // Usamos este formato: quizID_preguntaID_timestamp.ext
                    $nombreGenerado = "q{$quiz_id}_p{$pregunta_id}_" . time() . ".$ext";
                    
                    // 2. MOVEMOS EL ARCHIVO CON ESE NOMBRE
                    if (move_uploaded_file($tmpArchivo, "$dir/$nombreGenerado")) {
                        // 3. SI SE MOVIÓ BIEN, ESE ES EL NOMBRE FINAL
                        $nombreImagenFinal = $nombreGenerado;
                    } else {
                        throw new Exception("Error al mover la imagen a la carpeta assets/images.");
                    }
                }
            } 
            // CASO B: Imagen existente (reutilización)
            elseif (!empty($_POST['pregunta_imagen_existente'][$i])) {
                $nombreImagenFinal = $_POST['pregunta_imagen_existente'][$i];
            }

            // 4. GUARDAMOS EN LA BASE DE DATOS EXACTAMENTE ESE NOMBRE
            if ($nombreImagenFinal) {
                $stmtImg = $pdo->prepare("UPDATE preguntas SET imagen = ? WHERE id = ?");
                $stmtImg->execute([$nombreImagenFinal, $pregunta_id]);
            }
            // OPCIONES
            $opcionesTexto = $_POST['respuesta_texto'][$i] ?? [];
            $opcionesCorrectas = $_POST['respuesta_correcta'][$i] ?? [];

            foreach ($opcionesTexto as $j => $textoOpcion) {
                if (trim($textoOpcion) === '') continue;

                // Aseguramos que sea string 'true' o 'false'
                $esCorrecta = isset($opcionesCorrectas[$j]) ? 'true' : 'false';

                echo "\n";

                // Quitamos el casteo ?::boolean y pasamos el valor directo para probar compatibilidad
                $stmtOp = $pdo->prepare("INSERT INTO opciones (
                    pregunta_id, texto, es_correcta, imagen
                ) VALUES (?, ?, ?, NULL)");
                
                $stmtOp->execute([
                    $pregunta_id,
                    trim($textoOpcion),
                    $esCorrecta // PHP enviará esto como string "true"/"false"
                ]);
            }
        }

        // --- ⚠️ DEBUGGING: COMENTADO EL COMMIT ⚠️ ---
        // $pdo->commit();
        
        $mensaje = "¡Proceso completado (Modo Debug)!";
        $tipoMensaje = "success";

    } catch (PDOException $e) {
        // Al no haber transacción, el rollback daría error, así que lo quitamos también
        // if ($pdo->inTransaction()) $pdo->rollBack();
        
        $mensaje = "🔥 ERROR REAL DETECTADO 🔥: " . $e->getMessage();
        $tipoMensaje = "error";
        
        // Imprimir el error en pantalla grande
        die("<div style='background:red; color:white; padding:20px; font-size:20px;'>ERROR SQL EXACTO: " . $e->getMessage() . "</div>");
        
    } catch (Exception $e) {
        $mensaje = "Error General: " . $e->getMessage();
        $tipoMensaje = "error";
        die($mensaje);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Quiz - Profesor</title>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type="text"], input[type="number"], textarea, input[type="datetime-local"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        
        .pregunta-card { background: #f9fafb; border: 1px solid #e5e7eb; padding: 15px; border-radius: 8px; margin-bottom: 20px; position: relative; }
        .btn-del-preg { position: absolute; top: 10px; right: 10px; background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        
        .opcion-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .btn-add { background: #4f46e5; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        .btn-secondary { background: #fff; border: 1px solid #4f46e5; color: #4f46e5; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        
        .msg { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .msg.error { background: #fee2e2; color: #991b1b; }
        .msg.success { background: #dcfce7; color: #166534; }
    </style>
</head>
<body>

<div class="container">
    <h1>Crear Nuevo Quiz</h1>
    
    <?php if ($mensaje): ?>
        <div class="msg <?= $tipoMensaje ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="quizForm">
        <input type="hidden" name="guardar_quiz" value="1">
        
        <div class="form-group">
            <label>Título del Quiz</label>
            <input type="text" name="titulo" required>
        </div>
        
        <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" rows="2"></textarea>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>Fecha Inicio</label>
                <input type="datetime-local" name="fecha_inicio" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="form-group">
                <label>Fecha Fin</label>
                <input type="datetime-local" name="fecha_fin" value="<?= date('Y-m-d\TH:i', strtotime('+1 week')) ?>" required>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="form-group">
                <label>Puntos Totales</label>
                <input type="number" name="valor_total" value="100">
            </div>
            <div class="form-group">
                <label>Duración (min)</label>
                <input type="number" name="duracion_minutos" value="60">
            </div>
        </div>

        <hr>
        <h2>Preguntas</h2>
        <div id="preguntas-container"></div>
        
        <button type="button" class="btn-secondary" onclick="agregarPregunta()">+ Agregar Pregunta</button>
        <br><br>
        <button type="submit" class="btn-add" style="width: 100%;">GUARDAR QUIZ</button>
    </form>
</div>

<script>
    let pIndex = 0;

    function agregarPregunta() {
        const container = document.getElementById('preguntas-container');
        const html = `
            <div class="pregunta-card" id="p-${pIndex}">
                <button type="button" class="btn-del-preg" onclick="eliminarPregunta(${pIndex})">Eliminar</button>
                
                <div class="form-group">
                    <label>Pregunta ${pIndex + 1}</label>
                    <input type="text" name="pregunta_texto[${pIndex}]" required placeholder="Escribe la pregunta...">
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>Puntos</label>
                        <input type="number" name="pregunta_valor[${pIndex}]" value="10">
                    </div>
                    <div class="form-group">
                        <label>Imagen (Opcional)</label>
                        <input type="file" name="pregunta_imagen[${pIndex}]" accept="image/*">
                    </div>
                </div>

                <div class="form-group">
                    <label>Opciones (Marca la correcta)</label>
                    <div id="opciones-${pIndex}">
                        ${generarOpcionHTML(pIndex, 0)}
                        ${generarOpcionHTML(pIndex, 1)}
                    </div>
                    <button type="button" class="btn-secondary" style="font-size:0.8em; margin-top:5px" onclick="agregarOpcion(${pIndex})">+ Opción</button>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
        pIndex++;
    }

    function generarOpcionHTML(pIdx, oIdx) {
        return `
            <div class="opcion-row" id="opt-${pIdx}-${oIdx}">
                <input type="checkbox" name="respuesta_correcta[${pIdx}][${oIdx}]">
                <input type="text" name="respuesta_texto[${pIdx}][]" placeholder="Opción" required>
                <button type="button" onclick="this.parentElement.remove()" style="color:red; border:none; background:none; cursor:pointer;">&times;</button>
            </div>
        `;
    }

    function agregarOpcion(pIdx) {
        const container = document.getElementById(`opciones-${pIdx}`);
        // Usamos timestamp para índice único visual, aunque PHP usará el orden del array
        const oIdx = Date.now(); 
        container.insertAdjacentHTML('beforeend', generarOpcionHTML(pIdx, oIdx));
    }

    function eliminarPregunta(idx) {
        const el = document.getElementById(`p-${idx}`);
        if(el) el.remove();
    }

    // Inicializar con una pregunta
    window.onload = agregarPregunta;
</script>

</body>
</html>