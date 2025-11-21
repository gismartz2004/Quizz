<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'];

// === 1. Si se solicita PDF (vía GET), generarlo desde el archivo de resultados ===
if (isset($_GET['descargar_pdf']) && $_GET['descargar_pdf'] === '1' && isset($_GET['quiz'])) {
    $quizFileName = basename($_GET['quiz']);
    $resultadosFile = './resultados/resultados_alumnos.json';

    if (!file_exists($resultadosFile)) {
        die('No hay resultados guardados. Por favor, completa el quiz primero.');
    }

    $todosResultados = json_decode(file_get_contents($resultadosFile), true);
    $resultadoEncontrado = null;

    // Buscar el último resultado del usuario actual para este quiz
    foreach (array_reverse($todosResultados) as $resultado) {
        if ($resultado['usuario_id'] == $usuario['id'] && $resultado['quiz_id'] === basename($quizFileName, '.json')) {
            $resultadoEncontrado = $resultado;
            break;
        }
    }

    if (!$resultadoEncontrado) {
        die('No se encontró tu resultado para este quiz. Por favor, resuélvelo primero.');
    }

    // Extraer datos
    $quizData = [
        'titulo' => $resultadoEncontrado['quiz_titulo'],
        'valor_total' => $resultadoEncontrado['puntos_totales']
    ];
    $resultados = $resultadoEncontrado['detalle_resultados'];
    $puntosObtenidos = $resultadoEncontrado['puntos_obtenidos'];
    $porcentaje = $resultadoEncontrado['porcentaje'];

    // Generar PDF
    require_once('tcpdf/tcpdf.php');
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);

    $pdf->SetFont('', 'B', 16);
    $pdf->Cell(0, 10, 'Resultados del Quiz', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('', '', 12);
    $pdf->Cell(0, 8, 'Usuario: ' . $usuario['nombre'], 0, 1);
    $pdf->Cell(0, 8, 'Quiz: ' . $quizData['titulo'], 0, 1);
    $pdf->Cell(0, 8, 'Fecha: ' . $resultadoEncontrado['fecha'], 0, 1);
    $pdf->Cell(0, 8, 'Puntuación: ' . $puntosObtenidos . ' / ' . $quizData['valor_total'] . ' (' . $porcentaje . '%)', 0, 1);
    $pdf->Ln(10);

    foreach ($resultados as $i => $r) {
        $pdf->SetFont('', 'B', 12);
        $pdf->Cell(0, 7, 'Pregunta ' . ($i+1) . ' (' . $r['valor_pregunta'] . ' pts):', 0, 1);
        $pdf->SetFont('', '', 11);
        $pdf->MultiCell(0, 6, $r['pregunta'], 0, 1);
        $pdf->Cell(0, 6, 'Tu respuesta: ' . $r['tu_respuesta'], 0, 1);
        $pdf->Cell(0, 6, 'Correcta: ' . $r['respuesta_correcta'], 0, 1);
        $pdf->Cell(0, 6, 'Resultado: ' . ($r['es_correcta'] ? 'Correcto' : 'Incorrecto'), 0, 1);
        $pdf->Ln(5);
    }

    $nombre = 'Resultados_' . preg_replace('/[^a-zA-Z0-9]/', '_', $quizData['titulo']) . '_' . $usuario['nombre'] . '.pdf';
    $pdf->Output($nombre, 'D');
    exit;
}

// === 2. Flujo normal: procesar respuestas del quiz (POST) ===
$quizFile = isset($_GET['quiz']) ? 'quizzes/' . $_GET['quiz'] : null;
if (!$quizFile || !file_exists($quizFile)) {
    die('Quiz no encontrado. <a href="index.php">Volver a la lista de quizzes</a>');
}

$quizData = json_decode(file_get_contents($quizFile), true);

if (!isset($_POST['respuesta']) || empty($_POST['respuesta'])) {
    die('No se recibieron respuestas. Por favor, completa el quiz.');
}

function getRespuestaTexto($respuestas, $idRespuesta) {
    foreach ($respuestas as $respuesta) {
        if ($respuesta['id'] == $idRespuesta) {
            return $respuesta['texto'];
        }
    }
    return '';
}

// Procesar respuestas
$respuestasUsuario = $_POST['respuesta'];
$puntosObtenidos = 0;
$resultados = [];

foreach ($quizData['preguntas'] as $pregunta) {
    $idPregunta = $pregunta['id'];
    if (isset($respuestasUsuario[$idPregunta])) {
        $respuestaUsuario = $respuestasUsuario[$idPregunta];
        $respuestaCorrectaId = null;

        foreach ($pregunta['respuestas'] as $r) {
            if (!empty($r['correcta'])) {
                $respuestaCorrectaId = $r['id'];
                break;
            }
        }

        $esCorrecta = ($respuestaUsuario == $respuestaCorrectaId);
        if ($esCorrecta) {
            $puntosObtenidos += $pregunta['valor'];
        }

        $resultados[] = [
            'pregunta' => $pregunta['texto'],
            'valor_pregunta' => $pregunta['valor'],
            'tu_respuesta' => getRespuestaTexto($pregunta['respuestas'], $respuestaUsuario),
            'respuesta_correcta' => getRespuestaTexto($pregunta['respuestas'], $respuestaCorrectaId),
            'es_correcta' => $esCorrecta
        ];
    }
}

$porcentaje = round(($puntosObtenidos / $quizData['valor_total']) * 100);

// Guardar resultados
$resultadosFile = './resultados/resultados_alumnos.json';
if (!file_exists('resultados')) {
    mkdir('resultados', 0777, true);
}

$resultadosAlumnos = file_exists($resultadosFile) ? json_decode(file_get_contents($resultadosFile), true) : [];
$resultadosAlumnos[] = [
    'usuario_id' => $usuario['id'],
    'usuario_nombre' => $usuario['nombre'],
    'quiz_id' => basename($_GET['quiz'], '.json'),
    'quiz_titulo' => $quizData['titulo'],
    'fecha' => date('Y-m-d H:i:s'),
    'puntos_obtenidos' => $puntosObtenidos,
    'puntos_totales' => $quizData['valor_total'],
    'porcentaje' => $porcentaje,
    'detalle_resultados' => $resultados
];
file_put_contents($resultadosFile, json_encode($resultadosAlumnos, JSON_PRETTY_PRINT));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados: <?= htmlspecialchars($quizData['titulo']) ?></title>
    <link rel="stylesheet" href="css/resultados.css">
</head>
<body>
    <div class="container">
        <div class="user-bar">
            <h2>👤 <?= htmlspecialchars($usuario['nombre'] ?? 'Estudiante') ?></h2>
            <a href="logout.php" class="logout-btn">Cerrar sesión</a>
        </div>

        <div class="resultados-header">
            <h1>✅ Resultados del Quiz</h1>
            <p><?= htmlspecialchars($quizData['titulo']) ?></p>
        </div>

        <div class="resumen-card">
            <div class="resumen-info">
                <div class="puntuacion">
                    <span class="puntos"><?= $puntosObtenidos ?></span>
                    <span class="total">/ <?= $quizData['valor_total'] ?></span>
                </div>
                <div class="porcentaje"><?= $porcentaje ?>%</div>
            </div>
            <div class="fecha">📅 <?= date('d/m/Y H:i:s') ?></div>
        </div>

        <h2 class="seccion-titulo">Detalle de respuestas</h2>

        <div class="detalles">
            <?php foreach ($resultados as $index => $r): ?>
                <div class="pregunta-card <?= $r['es_correcta'] ? 'correcta' : 'incorrecta' ?>">
                    <div class="pregunta-header">
                        <h3>Pregunta <?= $index + 1 ?> <span>(<?= $r['valor_pregunta'] ?> pts)</span></h3>
                        <span class="estado"><?= $r['es_correcta'] ? '✅ Correcto' : '❌ Incorrecto' ?></span>
                    </div>
                    <p class="texto-pregunta"><?= htmlspecialchars($r['pregunta']) ?></p>
                    <div class="respuesta-comparacion">
                        <div class="respuesta-tuya">
                            <strong>Tu respuesta:</strong>
                            <div class="texto"><?= htmlspecialchars($r['tu_respuesta']) ?></div>
                        </div>
                        <div class="respuesta-correcta">
                            <strong>Respuesta correcta:</strong>
                            <div class="texto"><?= htmlspecialchars($r['respuesta_correcta']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="acciones">
            <!-- Botón de PDF: ahora usa GET y lee desde el archivo JSON -->
            <a href="?quiz=<?= urlencode($_GET['quiz']) ?>&descargar_pdf=1" class="btn btn-pdf">
                📄 Descargar resultados en PDF
            </a>
            <a href="index.php" class="btn btn-volver">← Volver a los quizzes</a>
        </div>
    </div>
</body>
</html>