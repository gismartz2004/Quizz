<?php
require 'vendor/autoload.php'; // Asegúrate de que smalot/pdfparser esté instalado

// ==========================================
// CONFIGURACIÓN
// ==========================================
$geminiApiKey = 'AIzaSyBByPvjVmXms9PRF39cMGeiz1-3gFaIdes'; // <--- PON TU API KEY DE GEMINI AQUÍ

// ==========================================
// FUNCIONES AUXILIARES
// ==========================================

// Función para conectar con Gemini
// Función para conectar con Gemini (CORREGIDA - Modelo Estable)
function generarPreguntasIA($tema, $apiKey) {
    // Usamos el modelo estable y rápido que apareció en tu lista
    $modelo = "models/gemini-2.5-flash"; 

    // URL completa con la versión v1beta
    $url = "https://generativelanguage.googleapis.com/v1beta/" . $modelo . ":generateContent?key=" . $apiKey;
    
    $prompt = "Genera 5 preguntas de opción múltiple sobre el tema: '$tema'. 
    IMPORTANTE: Usa un formato estricto para que mi software lo lea:
    1. La pregunta en una línea (debe terminar en ? o comenzar con número).
    2. Las opciones debajo.
    3. Marca la opción correcta iniciando con un asterisco (*).
    4. No añadas numeración A) B) C) a las respuestas.
    5. No pongas texto introductorio, ni negritas markdown (**), solo las preguntas y respuestas planas.";

    $data = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    // IMPORTANTE: Como estás en XAMPP (vi la ruta en tu error), 
    // es muy probable que necesites descomentar la siguiente línea si te da error de conexión:
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

    $response = curl_exec($ch);
    
    // Debug de errores de conexión (cURL)
    if (curl_errno($ch)) {
        echo "<script>alert('Error de conexión cURL: " . curl_error($ch) . "');</script>";
        curl_close($ch);
        return "";
    }
    
    curl_close($ch);
    
    $json = json_decode($response, true);
    
    // Debug de errores de Google API
    if (isset($json['error'])) {
        $mensajeError = $json['error']['message'] ?? 'Error desconocido';
        echo "<script>alert('Error de Gemini API: " . $mensajeError . "');</script>";
        return "";
    }
    
    // Extraer texto
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }
    
    return "";
}
// Función unificada para procesar texto (sirve para PDF y para IA)
function procesarTextoCuestionario($text) {
    $preguntas = [];
    $lines = explode("\n", $text);
    $currentPregunta = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Regex mejorado: Detecta líneas que terminan en ? o empiezan por número seguido de punto o paréntesis
        // Elimina asteriscos decorativos al inicio de la pregunta si la IA los pone
        if (preg_match('/(\?)$|^\d+[\.\)]/', $line)) {
            if ($currentPregunta) {
                $preguntas[] = $currentPregunta;
            }
            // Limpiar la pregunta de numeración tipo "1. " para guardarla limpia
            $textoLimpio = preg_replace('/^\d+[\.\)]\s*/', '', $line);
            $textoLimpio = str_replace('**', '', $textoLimpio); // Quitar negritas de markdown

            $currentPregunta = [
                'texto' => $textoLimpio,
                'valor' => 10,
                'respuestas' => []
            ];
        } elseif ($currentPregunta) {
            // Es una respuesta
            // Detectar asterisco de correcta
            $esCorrecta = (substr($line, 0, 1) === '*' || substr($line, 0, 2) === '**'); // Soporte markdown
            
            // Limpiar el texto de la respuesta (quitar * y espacios)
            $textoRespuesta = preg_replace('/^[\*]+\s*/', '', $line);
            
            $currentPregunta['respuestas'][] = [
                'texto' => trim($textoRespuesta),
                'correcta' => $esCorrecta
            ];
        }
    }
    if ($currentPregunta) {
        $preguntas[] = $currentPregunta;
    }
    return $preguntas;
}

// ==========================================
// LÓGICA DEL SERVIDOR
// ==========================================

$titulo = '';
$descripcion = '';
$preguntasGeneradas = [];
$mensaje = '';
$origenDatos = ''; // Para saber si vino de PDF o IA

// CASO 1: Procesar PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === 0) {
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($_FILES['pdf_file']['tmp_name']);
        $text = $pdf->getText();
        $preguntasGeneradas = procesarTextoCuestionario($text);
        $mensaje = "PDF Procesado. Se encontraron " . count($preguntasGeneradas) . " preguntas.";
        $origenDatos = 'PDF';
    } catch (Exception $e) {
        $mensaje = "Error al leer PDF: " . $e->getMessage();
    }
}

// CASO 2: Generar con IA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_ia'])) {
    if (empty($geminiApiKey) || $geminiApiKey === 'TU_API_KEY_AQUI') {
        $mensaje = "Error: Debes configurar tu API Key de Gemini en el código PHP.";
    } else {
        $tema = $_POST['tema_ia'];
        $textoIA = generarPreguntasIA($tema, $geminiApiKey);
        if ($textoIA) {
            $preguntasGeneradas = procesarTextoCuestionario($textoIA);
            $mensaje = "IA Generó contenido sobre '$tema'. Se encontraron " . count($preguntasGeneradas) . " preguntas.";
            $origenDatos = 'IA';
            // Pre-llenar título y descripción
            $titulo = "Quiz sobre " . ucfirst($tema);
            $descripcion = "Cuestionario generado automáticamente con Inteligencia Artificial sobre $tema.";
        } else {
            $mensaje = "La IA no devolvió resultados. Intenta de nuevo.";
        }
    }
}

// CASO 3: Guardar el Quiz Final
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_quiz'])) {
    $quizData = [
        'titulo' => $_POST['titulo'],
        'descripcion' => $_POST['descripcion'],
        'color_primario' => $_POST['color_primario'],
        'color_secundario' => $_POST['color_secundario'],
        'valor_total' => $_POST['valor_total'],
        
        // NUEVOS CAMPOS DE FECHA Y TIEMPO
        'fecha_inicio' => $_POST['fecha_inicio'],
        'fecha_fin' => $_POST['fecha_fin'],
        'duracion_minutos' => $_POST['duracion_minutos'],
        
        'preguntas' => []
    ];

    if (isset($_POST['pregunta_texto'])) {
        foreach ($_POST['pregunta_texto'] as $index => $textoPregunta) {
            $pregunta = [
                'id' => $index + 1,
                'texto' => $textoPregunta,
                'valor' => $_POST['pregunta_valor'][$index],
                'imagen' => '',
                'respuestas' => []
            ];

            if (isset($_FILES['pregunta_imagen']['name'][$index]) && $_FILES['pregunta_imagen']['name'][$index]) {
                if (!is_dir('assets/images')) mkdir('assets/images', 0777, true);
                $ext = pathinfo($_FILES['pregunta_imagen']['name'][$index], PATHINFO_EXTENSION);
                $nombreImagen = 'p_' . ($index + 1) . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['pregunta_imagen']['tmp_name'][$index], 'assets/images/' . $nombreImagen);
                $pregunta['imagen'] = $nombreImagen;
            }

            if (isset($_POST['respuesta_texto'][$index])) {
                foreach ($_POST['respuesta_texto'][$index] as $respIndex => $textoRespuesta) {
                    $respuesta = [
                        'id' => $respIndex + 1,
                        'texto' => $textoRespuesta,
                        'imagen' => '',
                        'correcta' => isset($_POST['respuesta_correcta'][$index][$respIndex])
                    ];
                    $pregunta['respuestas'][] = $respuesta;
                }
            }
            $quizData['preguntas'][] = $pregunta;
        }
    }

    if (!is_dir('quizzes')) mkdir('quizzes', 0777, true);
    $nombreArchivo = 'quizzes/quiz_' . time() . '.json';
    file_put_contents($nombreArchivo, json_encode($quizData, JSON_PRETTY_PRINT));

    echo "<script>alert('¡Quiz guardado exitosamente con configuración de tiempo!'); window.location.href = '?success=1';</script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Quizzes Inteligente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4338ca;
            --secondary: #10b981;
            --ai-color: #8b5cf6; /* Color púrpura para IA */
            --bg: #f3f4f6;
            --card: #ffffff;
            --text: #1f2937;
            --border: #e5e7eb;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }

        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { font-weight: 800; color: var(--primary-dark); margin: 0; }
        
        /* Paneles de Importación */
        .tools-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .tool-card {
            padding: 20px;
            border-radius: 12px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .pdf-tool { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
        .ai-tool { background: linear-gradient(135deg, var(--ai-color), #6d28d9); position: relative; overflow: hidden; }
        
        /* Efecto de brillo para IA */
        .ai-tool::before {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 60%);
            animation: rotate 10s linear infinite; pointer-events: none;
        }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        .tool-title { font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        
        /* Inputs dentro de herramientas */
        .tool-input {
            width: 100%; padding: 8px; border-radius: 6px; border: none; margin-bottom: 10px;
            font-size: 0.9rem; color: #333;
        }
        
        /* Formulario Principal */
        .main-card { background: var(--card); border-radius: 12px; padding: 30px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }

        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; }
        
        input[type="text"], input[type="number"], input[type="datetime-local"], textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px;
            font-family: inherit; font-size: 0.95rem;
        }
        
        .time-section {
            background-color: #fff7ed; border: 1px solid #ffedd5;
            padding: 20px; border-radius: 8px; margin-bottom: 20px;
        }
        .time-section h3 { margin-top: 0; color: #c2410c; font-size: 1rem; display: flex; gap: 8px; align-items: center; }

        /* Estilos Preguntas (Heredados y simplificados) */
        .pregunta-card { background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--primary); }
        .respuesta-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; background: white; padding: 10px; border-radius: 6px; border: 1px solid var(--border); }
        
        /* Botones */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; font-size: 0.9rem; }
        .btn-white { background: white; color: var(--text); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: #fee2e2; color: var(--danger); padding: 5px 10px; font-size: 0.8rem; }
        .btn-ghost { background: transparent; border: 1px dashed var(--primary); color: var(--primary); }
        
        .alert { padding: 15px; background: #d1fae5; color: #065f46; border-radius: 6px; margin-bottom: 20px; }
        .file-custom { display: none; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fa-solid fa-brain"></i> Creador de Quizzes IA</h1>
        <p>Genera con IA, importa PDF o crea manualmente</p>
    </div>
     <a href="profesor.php" class="menu-item active">
                <i class="fas fa-home"></i> <span class="text">Inicio</span>
            </a>
    <?php if ($mensaje): ?>
        <div class="alert"><i class="fa-solid fa-circle-info"></i> <?php echo $mensaje; ?></div>
    <?php endif; ?>

    <div class="tools-grid">
        <div class="tool-card pdf-tool">
            <div class="tool-title"><i class="fa-solid fa-file-pdf"></i> Importar PDF</div>
            <form action="" method="post" enctype="multipart/form-data">
                <p style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 10px;">Formato: Pregunta? [enter] Opciones (*Correcta)</p>
                <input type="file" name="pdf_file" id="pdf_file" accept=".pdf" class="file-custom" required>
                <label for="pdf_file" class="btn btn-white" style="width:100%; justify-content:center; margin-bottom:10px;">
                    <i class="fa-solid fa-upload"></i> Elegir Archivo
                </label>
                <button type="submit" class="btn btn-white" style="width:100%; justify-content:center; color: var(--primary);">Procesar PDF</button>
            </form>
        </div>

        <div class="tool-card ai-tool">
            <div class="tool-title"><i class="fa-solid fa-robot"></i> Generar con Gemini AI</div>
            <form action="" method="post">
                <input type="hidden" name="generar_ia" value="1">
                <p style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 10px;">Escribe un tema y la IA creará el examen.</p>
                <input type="text" name="tema_ia" class="tool-input" placeholder="Ej: Historia de Roma, Fotosíntesis..." required>
                <button type="submit" class="btn btn-white" style="width:100%; justify-content:center; color: var(--ai-color);">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Generar Preguntas
                </button>
            </form>
        </div>
    </div>

    <form action="" method="post" enctype="multipart/form-data" class="main-card">
        <input type="hidden" name="guardar_quiz" value="1">
        
        <div class="grid-2">
            <div class="form-group">
                <label>Título del Quiz</label>
                <input type="text" name="titulo" value="<?php echo htmlspecialchars($titulo); ?>" required>
            </div>
            <div class="form-group">
                <label>Puntos Totales</label>
                <input type="number" name="valor_total" value="100">
            </div>
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" rows="2"><?php echo htmlspecialchars($descripcion); ?></textarea>
        </div>

        <div class="time-section">
            <h3><i class="fa-regular fa-clock"></i> Configuración de Disponibilidad y Tiempo</h3>
            <div class="grid-3">
                <div class="form-group">
                    <label>Fecha Apertura</label>
                    <input type="datetime-local" name="fecha_inicio" required>
                    <small style="color: #666; font-size: 0.75rem;">Cuándo pueden empezar</small>
                </div>
                <div class="form-group">
                    <label>Fecha Cierre</label>
                    <input type="datetime-local" name="fecha_fin" required>
                    <small style="color: #666; font-size: 0.75rem;">Cuándo se cierra el quiz</small>
                </div>
                <div class="form-group">
                    <label>Tiempo Límite (Minutos)</label>
                    <input type="number" name="duracion_minutos" value="60" min="1">
                    <small style="color: #666; font-size: 0.75rem;">Tiempo una vez iniciado</small>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group"><label>Color Primario</label><input type="color" name="color_primario" value="#6366f1" style="width:100%; height:40px;"></div>
            <div class="form-group"><label>Color Secundario</label><input type="color" name="color_secundario" value="#10b981" style="width:100%; height:40px;"></div>
        </div>

        <div style="margin-top: 30px;">
            <h2 style="border-bottom: 2px solid var(--border); padding-bottom: 10px;">Preguntas</h2>
            <div id="preguntas-container">
                <?php 
                $preguntasMostrar = !empty($preguntasGeneradas) ? $preguntasGeneradas : [['texto' => '', 'valor' => 10, 'respuestas' => [['texto'=>'', 'correcta'=>false]]]];
                foreach ($preguntasMostrar as $idx => $preg): $pIndex = $idx + 1; ?>
                    <div class="pregunta-card">
                        <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                            <span class="pregunta-num">Pregunta <?php echo $pIndex; ?></span>
                            <button type="button" class="btn btn-danger remove-pregunta"><i class="fa-solid fa-trash"></i></button>
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <input type="text" name="pregunta_texto[]" value="<?php echo htmlspecialchars($preg['texto']); ?>" placeholder="Escribe la pregunta..." required>
                            </div>
                            <div class="form-group">
                                <input type="number" name="pregunta_valor[]" value="<?php echo $preg['valor']; ?>" placeholder="Puntos" style="width: 100px;">
                            </div>
                        </div>
                        <div class="form-group"><input type="file" name="pregunta_imagen[]"></div>
                        
                        <div class="respuestas-wrapper">
                            <?php foreach ($preg['respuestas'] as $rIdx => $resp): ?>
                                <div class="respuesta-row">
                                    <input type="checkbox" name="respuesta_correcta[<?php echo $idx; ?>][]" value="1" <?php echo $resp['correcta'] ? 'checked' : ''; ?>>
                                    <input type="text" name="respuesta_texto[<?php echo $idx; ?>][]" value="<?php echo htmlspecialchars($resp['texto']); ?>" style="flex-grow:1;" required>
                                    <button type="button" class="btn btn-danger remove-respuesta">X</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-ghost add-respuesta" data-index="<?php echo $idx; ?>"><i class="fa-solid fa-plus"></i> Respuesta</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="margin-top: 30px; display: flex; gap: 10px;">
            <button type="button" id="add-pregunta-btn" class="btn btn-white" style="border:1px solid #ccc;">+ Nueva Pregunta</button>
            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Guardar Quiz</button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Script para UI de archivo
    $('#pdf_file').change(function() {
        if(this.files.length > 0) $(this).next('label').html('<i class="fa-solid fa-check"></i> ' + this.files[0].name);
    });

    // Añadir Pregunta (Lógica simplificada para el ejemplo)
    $('#add-pregunta-btn').click(function() {
        const count = $('.pregunta-card').length; 
        const template = `
            <div class="pregunta-card">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                    <span class="pregunta-num">Nueva Pregunta</span>
                    <button type="button" class="btn btn-danger remove-pregunta"><i class="fa-solid fa-trash"></i></button>
                </div>
                <div class="grid-2">
                    <div class="form-group"><input type="text" name="pregunta_texto[]" placeholder="Pregunta..." required></div>
                    <div class="form-group"><input type="number" name="pregunta_valor[]" value="10" style="width: 100px;"></div>
                </div>
                <div class="respuestas-wrapper">
                    <div class="respuesta-row">
                        <input type="checkbox" name="respuesta_correcta[${count}][]" value="1">
                        <input type="text" name="respuesta_texto[${count}][]" placeholder="Respuesta..." style="flex-grow:1;" required>
                        <button type="button" class="btn btn-danger remove-respuesta">X</button>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost add-respuesta" data-index="${count}"><i class="fa-solid fa-plus"></i> Respuesta</button>
            </div>`;
        $('#preguntas-container').append(template);
    });

    // Añadir Respuesta
    $(document).on('click', '.add-respuesta', function() {
        // Nota: para que funcione dinámicamente bien en un form complejo, lo ideal es recalcular índices, 
        // pero usamos el índice relativo de la tarjeta padre para este ejemplo rápido.
        const parent = $(this).closest('.pregunta-card');
        const index = $('.pregunta-card').index(parent);
        
        const tpl = `
            <div class="respuesta-row">
                <input type="checkbox" name="respuesta_correcta[${index}][]" value="1">
                <input type="text" name="respuesta_texto[${index}][]" placeholder="Respuesta..." style="flex-grow:1;" required>
                <button type="button" class="btn btn-danger remove-respuesta">X</button>
            </div>`;
        parent.find('.respuestas-wrapper').append(tpl);
    });

    $(document).on('click', '.remove-pregunta', function() { $(this).closest('.pregunta-card').remove(); });
    $(document).on('click', '.remove-respuesta', function() { $(this).closest('.respuesta-row').remove(); });
});
</script>
</body>
</html>