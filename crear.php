<?php
// Forzar cabecera UTF-8 para el navegador
header('Content-Type: text/html; charset=utf-8');

require 'vendor/autoload.php';

// ==========================================
// 1. CONFIGURACIÓN SEGURA
// ==========================================
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die('<div style="color:red; padding:20px; text-align:center;">Error: Falta config.php</div>');
}

if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
    die('<div style="color:red; padding:20px; text-align:center;">Error: API Key no configurada.</div>');
}

$geminiApiKey = GEMINI_API_KEY;

// ==========================================
// 2. FUNCIONES DE PROCESAMIENTO (MEJORADAS PARA UTF-8)
// ==========================================

function procesarTextoCuestionario($text) {
    // 1. Asegurar UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
    }

    $preguntas = [];
    // Normalizar saltos de línea
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);
    
    $currentPregunta = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // REGEX MEJORADO CON SOPORTE UNICODE (/u)
        // Detecta: "¿Pregunta?", "1. Pregunta", "1) Pregunta", "- Pregunta", y tildes
        $esPregunta = preg_match('/^(\d+[\.\)]|\-|¿).+|.*\?$/u', $line);

        if ($esPregunta) {
            if ($currentPregunta && !empty($currentPregunta['respuestas'])) {
                $preguntas[] = $currentPregunta;
            }

            // Limpieza del texto (usando mb_ para no romper acentos)
            $textoLimpio = preg_replace('/^(\d+[\.\)]|\-)\s*/u', '', $line);
            $textoLimpio = str_replace(['**'], '', $textoLimpio);

            $currentPregunta = [
                'texto' => $textoLimpio,
                'valor' => 10,
                'respuestas' => []
            ];
        } elseif ($currentPregunta) {
            // Detectar respuesta correcta (*)
            // Usamos mb_substr para leer el primer caracter de forma segura
            $primerCaracter = mb_substr($line, 0, 1, 'UTF-8');
            $esCorrecta = ($primerCaracter === '*');
            
            // Limpiar asterisco
            if ($esCorrecta) {
                $textoRespuesta = mb_substr($line, 1, null, 'UTF-8');
            } else {
                $textoRespuesta = $line;
            }
            
            $textoRespuesta = trim($textoRespuesta);

            if (mb_strlen($textoRespuesta, 'UTF-8') > 1) {
                $currentPregunta['respuestas'][] = [
                    'texto' => $textoRespuesta,
                    'correcta' => $esCorrecta
                ];
            }
        }
    }
    
    if ($currentPregunta && !empty($currentPregunta['respuestas'])) {
        $preguntas[] = $currentPregunta;
    }
    
    return $preguntas;
}

function generarPreguntasIA($tema, $apiKey) {
    $modelo = "models/gemini-2.5-flash"; 
    $url = "https://generativelanguage.googleapis.com/v1beta/" . $modelo . ":generateContent?key=" . $apiKey;
    
    $prompt = "Genera 5 preguntas de opción múltiple sobre: '$tema'.
    REGLAS ESTRICTAS DE FORMATO:
    1. Escribe la pregunta directamente (ej: '1. ¿Cuál es el color...?').
    2. Usa acentos y ñ correctamente.
    3. LA RESPUESTA CORRECTA DEBE EMPEZAR CON UN ASTERISCO (*).
    4. NO uses letras A) B) C).
    5. NO uses negritas markdown (**).";

    $data = ["contents" => [["parts" => [["text" => $prompt]]]]];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    // Descomentar si hay problemas de SSL en local
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) return "Error cURL: " . curl_error($ch);
    curl_close($ch);
    
    $json = json_decode($response, true);
    
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }
    
    return "";
}

// ==========================================
// 3. PROCESAMIENTO
// ==========================================

$titulo = '';
$descripcion = '';
$preguntasGeneradas = [];
$mensaje = '';
$tipoMensaje = ''; 

// A) Procesar PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === 0) {
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($_FILES['pdf_file']['tmp_name']);
        
        // Obtener texto y FORZAR UTF-8
        $text = $pdf->getText();
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        $preguntasGeneradas = procesarTextoCuestionario($text);
        
        if (count($preguntasGeneradas) > 0) {
            $mensaje = "✅ PDF Procesado. Se extrajeron " . count($preguntasGeneradas) . " preguntas.";
            $tipoMensaje = 'success';
            $titulo = "Quiz importado de PDF";
        } else {
            $mensaje = "⚠️ El PDF se leyó, pero no se detectaron preguntas válidas.";
            $tipoMensaje = 'warning';
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipoMensaje = 'error';
    }
}

// B) Generar con IA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_ia'])) {
    $tema = $_POST['tema_ia'];
    $textoIA = generarPreguntasIA($tema, $geminiApiKey);
    
    if (strpos($textoIA, 'Error') === 0) {
        $mensaje = "❌ " . $textoIA;
        $tipoMensaje = 'error';
    } else {
        $preguntasGeneradas = procesarTextoCuestionario($textoIA);
        if (count($preguntasGeneradas) > 0) {
            $mensaje = "✨ IA Generó " . count($preguntasGeneradas) . " preguntas.";
            $tipoMensaje = 'success';
            $titulo = "Quiz sobre " . ucfirst($tema);
            $descripcion = "Generado por IA. Tema: " . $tema;
        } else {
            $mensaje = "⚠️ La IA respondió con un formato desconocido.";
            $tipoMensaje = 'warning';
        }
    }
}

// C) Guardar en BD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_quiz'])) {
    require 'db.php';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO quizzes (titulo, descripcion, color_primario, color_secundario, valor_total, fecha_inicio, fecha_fin, duracion_minutos, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $creado_por = $_SESSION['usuario']['id'] ?? 1; 

        $stmt->execute([
            $_POST['titulo'],
            $_POST['descripcion'],
            $_POST['color_primario'],
            $_POST['color_secundario'],
            $_POST['valor_total'],
            $_POST['fecha_inicio'],
            $_POST['fecha_fin'],
            $_POST['duracion_minutos'],
            $creado_por
        ]);
        
        $quiz_id = $pdo->lastInsertId();

        if (isset($_POST['pregunta_texto'])) {
            foreach ($_POST['pregunta_texto'] as $index => $textoPregunta) {
                $stmtP = $pdo->prepare("INSERT INTO preguntas (quiz_id, texto, valor) VALUES (?, ?, ?)");
                $stmtP->execute([$quiz_id, $textoPregunta, $_POST['pregunta_valor'][$index]]);
                $pregunta_id = $pdo->lastInsertId();

                if (isset($_FILES['pregunta_imagen']['name'][$index]) && $_FILES['pregunta_imagen']['name'][$index]) {
                    if (!is_dir('assets/images')) mkdir('assets/images', 0777, true);
                    $ext = pathinfo($_FILES['pregunta_imagen']['name'][$index], PATHINFO_EXTENSION);
                    $nombreImagen = 'p_' . $pregunta_id . '_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['pregunta_imagen']['tmp_name'][$index], 'assets/images/' . $nombreImagen);
                    
                    $stmtUpdate = $pdo->prepare("UPDATE preguntas SET imagen = ? WHERE id = ?");
                    $stmtUpdate->execute([$nombreImagen, $pregunta_id]);
                }

                if (isset($_POST['respuesta_texto'][$index])) {
                    $stmtR = $pdo->prepare("INSERT INTO opciones (pregunta_id, texto, es_correcta) VALUES (?, ?, ?)");
                    foreach ($_POST['respuesta_texto'][$index] as $respIndex => $textoRespuesta) {
                        $esCorrecta = isset($_POST['respuesta_correcta'][$index][$respIndex]) ? 1 : 0;
                        $stmtR->execute([$pregunta_id, $textoRespuesta, $esCorrecta]);
                    }
                }
            }
        }

        $pdo->commit();
        echo "<script>alert('¡Quiz guardado correctamente!'); window.location.href = 'profesor.php';</script>";
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "Error BD: " . $e->getMessage();
        $tipoMensaje = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creador de Quizzes | Profesor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #4f46e5; --bg-body: #f1f5f9; --border-color: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: #1e293b; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .page-title h1 { font-size: 1.8rem; font-weight: 700; color: #0f172a; }
        .btn-back { text-decoration: none; color: #64748b; font-weight: 500; display: flex; align-items: center; gap: 5px; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }

        .tools-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .tool-card { background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .tool-title { font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .tool-input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; margin-bottom: 10px; }
        .file-upload-label { display: block; padding: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; text-align: center; border-radius: 6px; cursor: pointer; color: #64748b; margin-bottom: 10px; }
        .file-upload-label:hover { background: #f1f5f9; border-color: var(--primary); color: var(--primary); }

        .main-card { background: white; padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; }
        input[type="text"], input[type="number"], input[type="datetime-local"], textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
        input:focus { outline: none; border-color: var(--primary); }
        .config-section { background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid var(--border-color); }

        .pregunta-card { background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--primary); position: relative; }
        .pregunta-header { display: flex; justify-content: space-between; margin-bottom: 15px; }
        .pregunta-num { font-weight: 700; color: var(--primary); text-transform: uppercase; font-size: 0.85rem; }
        .respuestas-wrapper { margin-top: 15px; padding-left: 10px; border-left: 2px solid #f1f5f9; }
        .respuesta-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; }
        .btn-primary { background: var(--primary); color: white; width: 100%; justify-content: center; }
        .btn-primary:hover { background: #4338ca; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-tool { background: var(--primary); color: white; width: 100%; }
        .btn-ghost { background: transparent; color: var(--primary); padding: 5px; }
        .btn-danger { background: #fee2e2; color: #ef4444; padding: 6px 12px; font-size: 0.8rem; }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <div>
            <h1>Nuevo Quiz</h1>
            <p style="color: #64748b;">Configura los detalles y añade preguntas.</p>
        </div>
        <a href="profesor.php" class="btn-back"><i class="fas fa-arrow-left"></i> Cancelar</a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipoMensaje; ?>"><?php echo $mensaje; ?></div>
    <?php endif; ?>

    <div class="tools-grid">
        <div class="tool-card">
            <div class="tool-title" style="color: #e11d48;"><i class="fas fa-file-pdf"></i> Importar PDF</div>
            <form action="" method="post" enctype="multipart/form-data">
                <input type="file" name="pdf_file" id="pdf_file" accept=".pdf" style="display:none;" required onchange="document.getElementById('pdf-label').innerHTML = this.files[0].name">
                <label for="pdf_file" id="pdf-label" class="file-upload-label"><i class="fas fa-cloud-upload-alt"></i> Clic para subir PDF</label>
                <div style="font-size:0.8rem; color:#64748b; margin-bottom:10px;">Formato: Preguntas con número, respuestas abajo. Marca la correcta con (*).</div>
                <button type="submit" class="btn btn-tool" style="background: #e11d48;">Procesar Archivo</button>
            </form>
        </div>

        <div class="tool-card">
            <div class="tool-title" style="color: #8b5cf6;"><i class="fas fa-magic"></i> Generar con IA</div>
            <form action="" method="post">
                <input type="hidden" name="generar_ia" value="1">
                <input type="text" name="tema_ia" class="tool-input" placeholder="Ej: Historia de Roma..." required>
                <button type="submit" class="btn btn-tool" style="background: #8b5cf6;">Generar Preguntas</button>
            </form>
        </div>
    </div>

    <form action="" method="post" enctype="multipart/form-data" class="main-card">
        <input type="hidden" name="guardar_quiz" value="1">
        
        <div class="grid-2">
            <div class="form-group">
                <label>Título del Quiz</label>
                <input type="text" name="titulo" value="<?php echo htmlspecialchars($titulo); ?>" required placeholder="Ej: Examen Parcial 1">
            </div>
            <div class="form-group">
                <label>Puntos Totales</label>
                <input type="number" name="valor_total" value="100" required>
            </div>
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" rows="3" placeholder="Instrucciones..."><?php echo htmlspecialchars($descripcion); ?></textarea>
        </div>

        <div class="config-section">
            <h3><i class="far fa-clock"></i> Disponibilidad</h3>
            <div class="grid-3" style="margin-top:15px;">
                <div class="form-group"><label>Apertura</label><input type="datetime-local" name="fecha_inicio" required value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
                <div class="form-group"><label>Cierre</label><input type="datetime-local" name="fecha_fin" required value="<?php echo date('Y-m-d\TH:i', strtotime('+3 days')); ?>"></div>
                <div class="form-group"><label>Límite (Min)</label><input type="number" name="duracion_minutos" value="60" min="1"></div>
            </div>
        </div>

        <div class="grid-2" style="margin-bottom:30px;">
            <div class="form-group"><label>Color Principal</label><input type="color" name="color_primario" value="#4f46e5" style="width:100%;"></div>
            <div class="form-group"><label>Color Secundario</label><input type="color" name="color_secundario" value="#10b981" style="width:100%;"></div>
        </div>

        <div style="margin-top: 30px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:2px solid var(--border-color); padding-bottom:10px;">
                <h2 style="font-size:1.2rem;">Preguntas</h2>
                <span style="background:#f1f5f9; padding:5px 10px; border-radius:20px; font-size:0.8rem; font-weight:600;"><?php echo count($preguntasGeneradas); ?> Cargadas</span>
            </div>

            <div id="preguntas-container">
                <?php 
                $preguntasMostrar = !empty($preguntasGeneradas) ? $preguntasGeneradas : [['texto' => '', 'valor' => 10, 'respuestas' => [['texto'=>'', 'correcta'=>false]]]];
                foreach ($preguntasMostrar as $idx => $preg): $pIndex = $idx + 1; ?>
                    <div class="pregunta-card">
                        <div class="pregunta-header">
                            <span class="pregunta-num">Pregunta <?php echo $pIndex; ?></span>
                            <button type="button" class="btn btn-danger remove-pregunta"><i class="fas fa-trash"></i></button>
                        </div>
                        <div class="grid-2">
                            <div class="form-group"><label>Enunciado</label><input type="text" name="pregunta_texto[]" value="<?php echo htmlspecialchars($preg['texto']); ?>" required></div>
                            <div class="form-group"><label>Puntos</label><input type="number" name="pregunta_valor[]" value="<?php echo $preg['valor']; ?>" style="width: 100px;"></div>
                        </div>
                        <div class="form-group"><label>Imagen (Opcional)</label><input type="file" name="pregunta_imagen[]"></div>
                        
                        <label style="font-size:0.8rem; font-weight:600; color:#64748b;">RESPUESTAS</label>
                        <div class="respuestas-wrapper">
                            <?php foreach ($preg['respuestas'] as $rIdx => $resp): ?>
                                <div class="respuesta-row">
                                    <div style="display:flex; flex-direction:column; align-items:center; margin-right:10px;">
                                        <input type="checkbox" name="respuesta_correcta[<?php echo $idx; ?>][]" value="1" <?php echo $resp['correcta'] ? 'checked' : ''; ?>>
                                    </div>
                                    <input type="text" name="respuesta_texto[<?php echo $idx; ?>][]" value="<?php echo htmlspecialchars($resp['texto']); ?>" style="flex-grow:1;" required>
                                    <button type="button" class="btn btn-ghost remove-respuesta" style="color:#ef4444;"><i class="fas fa-times"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-ghost add-respuesta" data-index="<?php echo $idx; ?>" style="margin-top:10px;"><i class="fas fa-plus"></i> Añadir Opción</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="margin-top: 40px; display: flex; gap: 15px; padding-top: 20px; border-top: 1px solid var(--border-color);">
            <button type="button" id="add-pregunta-btn" class="btn btn-secondary"><i class="fas fa-plus-circle"></i> Agregar Pregunta</button>
            <button type="submit" class="btn btn-primary" style="font-size:1.1rem; padding:12px;"><i class="fas fa-save"></i> Guardar Examen</button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('#add-pregunta-btn').click(function() {
        const count = $('.pregunta-card').length;
        const newIndex = count;
        const template = `
            <div class="pregunta-card">
                <div class="pregunta-header">
                    <span class="pregunta-num">Nueva Pregunta</span>
                    <button type="button" class="btn btn-danger remove-pregunta"><i class="fas fa-trash"></i></button>
                </div>
                <div class="grid-2">
                    <div class="form-group"><label>Enunciado</label><input type="text" name="pregunta_texto[]" placeholder="Escribe la pregunta..." required></div>
                    <div class="form-group"><label>Puntos</label><input type="number" name="pregunta_valor[]" value="10" style="width: 100px;"></div>
                </div>
                <div class="form-group"><label>Imagen (Opcional)</label><input type="file" name="pregunta_imagen[]"></div>
                <label style="font-size:0.8rem; font-weight:600; color:#64748b;">RESPUESTAS</label>
                <div class="respuestas-wrapper">
                    <div class="respuesta-row">
                        <div style="display:flex; flex-direction:column; align-items:center; margin-right:10px;">
                            <input type="checkbox" name="respuesta_correcta[${newIndex}][]" value="1">
                        </div>
                        <input type="text" name="respuesta_texto[${newIndex}][]" placeholder="Opción 1" style="flex-grow:1;" required>
                        <button type="button" class="btn btn-ghost remove-respuesta" style="color:#ef4444;"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost add-respuesta" data-index="${newIndex}" style="margin-top:10px;"><i class="fas fa-plus"></i> Añadir Opción</button>
            </div>`;
        $('#preguntas-container').append(template);
    });

    $(document).on('click', '.add-respuesta', function() {
        const card = $(this).closest('.pregunta-card');
        const index = $('.pregunta-card').index(card);
        const tpl = `
            <div class="respuesta-row">
                <div style="display:flex; flex-direction:column; align-items:center; margin-right:10px;">
                    <input type="checkbox" name="respuesta_correcta[${index}][]" value="1">
                </div>
                <input type="text" name="respuesta_texto[${index}][]" placeholder="Nueva Opción" style="flex-grow:1;" required>
                <button type="button" class="btn btn-ghost remove-respuesta" style="color:#ef4444;"><i class="fas fa-times"></i></button>
            </div>`;
        card.find('.respuestas-wrapper').append(tpl);
    });

    $(document).on('click', '.remove-pregunta', function() {
        if(confirm('¿Eliminar esta pregunta?')) $(this).closest('.pregunta-card').remove();
    });

    $(document).on('click', '.remove-respuesta', function() {
        $(this).closest('.respuesta-row').remove();
    });
});
</script>
</body>
</html>