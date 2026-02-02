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
        $esPregunta = preg_match('/^(\d+[\.\)]|\-|¿).+|.*\?$/u', $line);

        if ($esPregunta) {
            if ($currentPregunta && !empty($currentPregunta['respuestas'])) {
                $preguntas[] = $currentPregunta;
            }

            // Limpieza del texto
            $textoLimpio = preg_replace('/^(\d+[\.\)]|\-)\s*/u', '', $line);
            $textoLimpio = str_replace(['**'], '', $textoLimpio);

            $currentPregunta = [
                'id' => uniqid('p_'),
                'texto' => $textoLimpio,
                'valor' => 10,
                'respuestas' => []
            ];
        } elseif ($currentPregunta) {
            $primerCaracter = mb_substr($line, 0, 1, 'UTF-8');
            $esCorrecta = ($primerCaracter === '*');
            
            if ($esCorrecta) {
                $textoRespuesta = mb_substr($line, 1, null, 'UTF-8');
            } else {
                $textoRespuesta = $line;
            }
            
            $textoRespuesta = trim($textoRespuesta);

            if (mb_strlen($textoRespuesta, 'UTF-8') > 1) {
                $currentPregunta['respuestas'][] = [
                    'id' => uniqid('r_'),
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

        $creado_por = $_SESSION['usuario']['id'] ?? 1;
        $es_nne = isset($_POST['es_nne']) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO quizzes (titulo, descripcion, color_primario, color_secundario, valor_total, fecha_inicio, fecha_fin, duracion_minutos, creado_por, es_nne) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['titulo'],
            $_POST['descripcion'],
            $_POST['color_primario'],
            $_POST['color_secundario'],
            $_POST['valor_total'],
            $_POST['fecha_inicio'],
            $_POST['fecha_fin'],
            $_POST['duracion_minutos'],
            $creado_por,
            $es_nne
        ]);
        
        $quiz_id = $pdo->lastInsertId();

        if (isset($_POST['preguntas']) && is_array($_POST['preguntas'])) {
            foreach ($_POST['preguntas'] as $qKey => $qData) {
                if (empty(trim($qData['texto']))) continue;

                $req_justified = isset($qData['justificada']) ? 1 : 0;
                $stmtP = $pdo->prepare("INSERT INTO preguntas (quiz_id, texto, valor, requiere_justificacion) VALUES (?, ?, ?, ?)");
                $stmtP->execute([$quiz_id, $qData['texto'], $qData['valor'], $req_justified]);
                $pregunta_id = $pdo->lastInsertId();

                // IMAGEN PREGUNTA
                if (isset($_FILES['preguntas']['name'][$qKey]['imagen']) && $_FILES['preguntas']['name'][$qKey]['imagen']) {
                    if (!is_dir('assets/images')) mkdir('assets/images', 0777, true);
                    $ext = pathinfo($_FILES['preguntas']['name'][$qKey]['imagen'], PATHINFO_EXTENSION);
                    $nombreImagen = 'p_' . $pregunta_id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['preguntas']['tmp_name'][$qKey]['imagen'], 'assets/images/' . $nombreImagen)) {
                        $pdo->prepare("UPDATE preguntas SET imagen = ? WHERE id = ?")->execute([$nombreImagen, $pregunta_id]);
                    }
                }

                if (isset($qData['respuestas']) && is_array($qData['respuestas'])) {
                    $stmtR = $pdo->prepare("INSERT INTO opciones (pregunta_id, texto, es_correcta) VALUES (?, ?, ?)");
                    foreach ($qData['respuestas'] as $rKey => $rData) {
                        if (empty(trim($rData['texto']))) continue;
                        $esCorrecta = isset($rData['correcta']) ? 1 : 0;
                        $stmtR->execute([$pregunta_id, $rData['texto'], $esCorrecta]);
                    }
                }
            }
        }

        $pdo->commit();
        echo "<script>alert('¡Quiz guardado correctamente!'); window.location.href = 'profesor.php';</script>";
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
        .tools-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .tool-card { background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .tool-title { font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .file-upload-label { display: block; padding: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; text-align: center; border-radius: 6px; cursor: pointer; color: #64748b; margin-bottom: 10px; }
        .main-card { background: white; padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; }
        input[type="text"], input[type="number"], input[type="datetime-local"], textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
        .pregunta-card { background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--primary); position: relative; }
        .respuesta-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--primary); color: white; width: 100%; justify-content: center; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-danger { background: #fee2e2; color: #ef4444; padding: 6px 12px; font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>Nuevo Quiz</h1>
        <a href="profesor.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver</a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipoMensaje; ?>"><?php echo $mensaje; ?></div>
    <?php endif; ?>

    <div class="tools-grid">
        <div class="tool-card">
            <div class="tool-title" style="color: #e11d48;"><i class="fas fa-file-pdf"></i> Importar PDF</div>
            <form action="" method="post" enctype="multipart/form-data">
                <input type="file" name="pdf_file" id="pdf_file" accept=".pdf" style="display:none;" required onchange="document.getElementById('pdf-label').innerHTML = this.files[0].name">
                <label for="pdf_file" id="pdf-label" class="file-upload-label">Clic para subir PDF</label>
                <button type="submit" class="btn btn-primary" style="background: #e11d48;">Procesar</button>
            </form>
        </div>
        <div class="tool-card">
            <div class="tool-title" style="color: #8b5cf6;"><i class="fas fa-magic"></i> Generar con IA</div>
            <form action="" method="post">
                <input type="hidden" name="generar_ia" value="1">
                <input type="text" name="tema_ia" placeholder="Ej: Historia..." required style="width:100%; padding:10px; margin-bottom:10px; border-radius:6px; border:1px solid #ccc;">
                <button type="submit" class="btn btn-primary" style="background: #8b5cf6;">Generar</button>
            </form>
        </div>
    </div>

    <form action="" method="post" enctype="multipart/form-data" class="main-card">
        <input type="hidden" name="guardar_quiz" value="1">
        <div class="grid-2">
            <div class="form-group"><label>Título</label><input type="text" name="titulo" value="<?php echo htmlspecialchars($titulo); ?>" required></div>
            <div class="form-group"><label>Puntos Totales</label><input type="number" name="valor_total" value="100" required></div>
        </div>
        <div class="form-group"><label>Descripción</label><textarea name="descripcion" rows="2"><?php echo htmlspecialchars($descripcion); ?></textarea></div>
        
        <div class="form-group" style="background:#fff7ed; padding:10px; border-radius:8px; border:1px solid #fed7aa;">
            <label style="display:flex; align-items:center; gap:10px; margin:0; cursor:pointer;">
                <input type="checkbox" name="es_nne" value="1"> <strong>Examen Privado (Solo NNE)</strong>
            </label>
        </div>

        <div class="grid-2">
            <div class="form-group"><label>Apertura</label><input type="datetime-local" name="fecha_inicio" required value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
            <div class="form-group"><label>Cierre</label><input type="datetime-local" name="fecha_fin" required value="<?php echo date('Y-m-d\TH:i', strtotime('+3 days')); ?>"></div>
        </div>

        <div class="grid-2">
            <div class="form-group"><label>Límite (Minutos)</label><input type="number" name="duracion_minutos" value="60"></div>
            <div class="form-group"><label>Color Principal</label><input type="color" name="color_primario" value="#4f46e5" style="width:100%;"></div>
            <input type="hidden" name="color_secundario" value="#10b981">
        </div>

        <div id="preguntas-container">
            <?php 
            $initialData = !empty($preguntasGeneradas) ? $preguntasGeneradas : [['id'=>uniqid('p_'),'texto'=>'','valor'=>10,'respuestas'=>[['id'=>uniqid('r_'),'texto'=>'','correcta'=>false]]]];
            foreach ($initialData as $pIdx => $p): $qId = $p['id'] ?? uniqid('p_'); ?>
                <div class="pregunta-card" id="card_<?php echo $qId; ?>">
                    <div class="pregunta-header">
                        <span class="pregunta-num">Pregunta</span>
                        <button type="button" class="btn btn-danger remove-pregunta">Eliminar</button>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>Pregunta</label><input type="text" name="preguntas[<?php echo $qId; ?>][texto]" value="<?php echo htmlspecialchars($p['texto']); ?>" required></div>
                        <div class="form-group"><label>Puntos</label><input type="number" name="preguntas[<?php echo $qId; ?>][valor]" value="<?php echo $p['valor']; ?>"></div>
                    </div>
                    <div class="form-group"><label>Imagen</label><input type="file" name="preguntas[<?php echo $qId; ?>][imagen]"></div>
                    <div style="background:#f0f4ff; padding:8px; border-radius:6px; margin-bottom:10px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0;">
                            <input type="checkbox" name="preguntas[<?php echo $qId; ?>][justificada]" value="1"> 
                            <strong>Solicitar Justificación</strong>
                        </label>
                    </div>
                    <div class="respuestas-wrapper">
                        <?php foreach($p['respuestas'] as $r): $rId = $r['id'] ?? uniqid('r_'); ?>
                            <div class="respuesta-row">
                                <input type="checkbox" name="preguntas[<?php echo $qId; ?>][respuestas][<?php echo $rId; ?>][correcta]" value="1" <?php echo ($r['correcta']??false)?'checked':''; ?>>
                                <input type="text" name="preguntas[<?php echo $qId; ?>][respuestas][<?php echo $rId; ?>][texto]" value="<?php echo htmlspecialchars($r['texto']); ?>" style="flex-grow:1;" required>
                                <button type="button" class="btn btn-danger remove-respuesta">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary add-respuesta" data-qid="<?php echo $qId; ?>">+ Opción</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-pregunta-btn" class="btn btn-secondary">+ Agregar Pregunta</button>
        <button type="submit" class="btn btn-primary" style="margin-top:20px;">Guardar Quiz</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    function generateId() { return 'new_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5); }

    $('#add-pregunta-btn').click(function() {
        const id = generateId();
        const rid = generateId();
        const tpl = `<div class="pregunta-card" id="card_${id}">
            <div class="pregunta-header"><span class="pregunta-num">Nueva Pregunta</span><button type="button" class="btn btn-danger remove-pregunta">Eliminar</button></div>
            <div class="grid-2">
                <div class="form-group"><label>Pregunta</label><input type="text" name="preguntas[${id}][texto]" required></div>
                <div class="form-group"><label>Puntos</label><input type="number" name="preguntas[${id}][valor]" value="10"></div>
            </div>
            <div class="form-group"><label>Imagen</label><input type="file" name="preguntas[${id}][imagen]"></div>
            <div style="background:#f0f4ff; padding:8px; border-radius:6px; margin-bottom:10px;"><label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0;"><input type="checkbox" name="preguntas[${id}][justificada]" value="1"> <strong>Solicitar Justificación</strong></label></div>
            <div class="respuestas-wrapper">
                <div class="respuesta-row">
                    <input type="checkbox" name="preguntas[${id}][respuestas][${rid}][correcta]" value="1">
                    <input type="text" name="preguntas[${id}][respuestas][${rid}][texto]" placeholder="Opción 1" style="flex-grow:1;" required>
                    <button type="button" class="btn btn-danger remove-respuesta">&times;</button>
                </div>
            </div>
            <button type="button" class="btn btn-secondary add-respuesta" data-qid="${id}">+ Opción</button>
        </div>`;
        $('#preguntas-container').append(tpl);
    });

    $(document).on('click', '.add-respuesta', function() {
        const qid = $(this).data('qid');
        const rid = generateId();
        const tpl = `<div class="respuesta-row">
            <input type="checkbox" name="preguntas[${qid}][respuestas][${rid}][correcta]" value="1">
            <input type="text" name="preguntas[${qid}][respuestas][${rid}][texto]" placeholder="Opción" style="flex-grow:1;" required>
            <button type="button" class="btn btn-danger remove-respuesta">&times;</button>
        </div>`;
        $(this).siblings('.respuestas-wrapper').append(tpl);
    });

    $(document).on('click', '.remove-pregunta', function() { if(confirm('¿Eliminar?')) $(this).closest('.pregunta-card').remove(); });
    $(document).on('click', '.remove-respuesta', function() { $(this).closest('.respuesta-row').remove(); });
});
</script>
</body>
</html>