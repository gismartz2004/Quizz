<?php
session_start();
require 'vendor/autoload.php'; // Dompdf
require 'db.php';              // Conexión SQL

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. SEGURIDAD
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}
$usuario = $_SESSION['usuario'];

// ==========================================================
// LÓGICA: GENERAR PDF (VÍA GET)
// ==========================================================
if (isset($_GET['descargar_pdf']) && isset($_GET['resultado_id'])) {
    $resultado_id = $_GET['resultado_id'];

    $stmt = $pdo->prepare("
        SELECT r.*, q.titulo as quiz_titulo, u.nombre as alumno_nombre 
        FROM resultados r
        JOIN quizzes q ON r.quiz_id = q.id
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.id = ? AND r.usuario_id = ?
    ");
    $stmt->execute([$resultado_id, $usuario['id']]);
    $data = $stmt->fetch();

    if (!$data) die("Resultado no encontrado.");

    $html = '
    <html>
    <head>
        <style>
            body { font-family: sans-serif; color: #333; }
            .header { text-align: center; border-bottom: 2px solid #4f46e5; padding-bottom: 10px; margin-bottom: 20px; }
            .score-card { background: #f3f4f6; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
            .score-big { font-size: 30px; font-weight: bold; color: #4f46e5; }
            table { width: 100%; border-collapse: collapse; margin-top:20px; }
            td { padding: 10px; border-bottom: 1px solid #eee; }
            .label { font-weight:bold; color:#555; width: 150px;}
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Reporte de Resultados</h1>
            <p>Plataforma GeniosBot</p>
        </div>
        
        <div class="score-card">
            <div>Calificación Final</div>
            <div class="score-big">' . $data['puntos_obtenidos'] . ' / ' . $data['puntos_totales_quiz'] . '</div>
            <div>' . $data['porcentaje'] . '%</div>
        </div>

        <table>
            <tr><td class="label">Estudiante:</td><td>' . htmlspecialchars($data['alumno_nombre']) . '</td></tr>
            <tr><td class="label">Examen:</td><td>' . htmlspecialchars($data['quiz_titulo']) . '</td></tr>
            <tr><td class="label">Fecha:</td><td>' . date('d/m/Y H:i', strtotime($data['fecha_realizacion'])) . '</td></tr>
        </table>
        
        <p style="text-align:center; margin-top:50px; font-size:12px; color:#999;">Documento generado automáticamente.</p>
    </body>
    </html>';

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("Resultado_" . $data['quiz_titulo'] . ".pdf", ["Attachment" => true]);
    exit;
}

// ==========================================================
// LÓGICA: PROCESAR RESPUESTAS (VÍA POST)
// ==========================================================
$quizId = isset($_GET['quiz']) ? $_GET['quiz'] : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$quizId) {
    header('Location: index.php'); exit;
}

// 1. Obtener Quiz de BD
$stmtQ = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmtQ->execute([$quizId]);
$quizData = $stmtQ->fetch(PDO::FETCH_ASSOC);

if (!$quizData) die('Error: Quiz no encontrado.');

// 2. Obtener Preguntas y Respuestas Correctas de BD
$stmtP = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ?");
$stmtP->execute([$quizId]);
$preguntasDB = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$respuestasUsuario = $_POST['respuesta'] ?? [];
$puntosObtenidos = 0;
$detallesPantalla = [];

// 3. Calcular Nota
foreach ($preguntasDB as $pregunta) {
    $idPregunta = $pregunta['id'];
    $respuestaUserId = $respuestasUsuario[$idPregunta] ?? null;
    
    // Buscar opciones de esta pregunta
    $stmtO = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
    $stmtO->execute([$idPregunta]);
    $opciones = $stmtO->fetchAll(PDO::FETCH_ASSOC);
    
    $esCorrecta = false;
    $textoRespuestaUser = "Sin responder";
    $textoCorrecta = "";

    foreach ($opciones as $op) {
        if ($op['es_correcta']) {
            $textoCorrecta = $op['texto'];
            if ($op['id'] == $respuestaUserId) $esCorrecta = true;
        }
        if ($op['id'] == $respuestaUserId) {
            $textoRespuestaUser = $op['texto'];
        }
    }

    if ($esCorrecta) $puntosObtenidos += $pregunta['valor'];

    $detallesPantalla[] = [
        'pregunta' => $pregunta['texto'],
        'pts' => $pregunta['valor'],
        'tu_respuesta' => $textoRespuestaUser,
        'correcta_respuesta' => $textoCorrecta,
        'es_correcta' => $esCorrecta
    ];
}

$porcentaje = ($quizData['valor_total'] > 0) ? round(($puntosObtenidos / $quizData['valor_total']) * 100) : 0;

// 4. GUARDAR EN BASE DE DATOS CON DEMOGRÁFICOS
try {
    // Recuperar datos demográficos de sesión
    $sessionDemoKey = 'demo_data_' . $quizId . '_' . $usuario['id'];
    $demograficos = $_SESSION[$sessionDemoKey] ?? [
        'edad' => null, 'genero' => null, 'residencia' => null, 'discapacidad' => null
    ];

    $stmtR = $pdo->prepare("
        INSERT INTO resultados 
        (usuario_id, quiz_id, puntos_obtenidos, puntos_totales_quiz, porcentaje, edad, genero, residencia, discapacidad) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmtR->execute([
        $usuario['id'],
        $quizId,
        $puntosObtenidos,
        $quizData['valor_total'],
        $porcentaje,
        $demograficos['edad'],
        $demograficos['genero'],
        $demograficos['residencia'],
        $demograficos['discapacidad']
    ]);
    
    $resultado_id_sql = $pdo->lastInsertId();
    
    // Limpiar sesión demográfica ya usada
    unset($_SESSION[$sessionDemoKey]);
    unset($_SESSION['quiz_start_' . $quizId . '_' . $usuario['id']]); // Limpiar timer
    unset($_SESSION['quiz_questions_' . $quizId]); // Limpiar orden preguntas

} catch (Exception $e) {
    // echo "Error guardando: " . $e->getMessage(); 
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados: <?= htmlspecialchars($quizData['titulo']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #4f46e5; --success: #16a34a; --error: #dc2626; --bg: #f8fafc; --card: #ffffff; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; padding: 40px 20px; color: #334155; }
        .container { max-width: 800px; margin: 0 auto; }
        
        /* Tarjeta Resumen */
        .score-card { background: var(--card); border-radius: 16px; padding: 40px; text-align: center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); margin-bottom: 40px; position: relative; overflow: hidden; }
        .score-card::before { content:''; height: 6px; background: var(--primary); width: 100%; position: absolute; top:0; left:0; }
        
        .score-circle { width: 120px; height: 120px; border-radius: 50%; background: conic-gradient(var(--primary) <?= $porcentaje ?>%, #e2e8f0 0); margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; }
        .score-inner { width: 100px; height: 100px; background: white; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .percentage { font-size: 1.8rem; font-weight: 800; color: #1e293b; line-height: 1; }
        
        .score-details { font-size: 1.2rem; color: #334155; margin-bottom: 5px; }
        .score-message { font-weight: 600; color: var(--primary); font-size: 1.1rem; }

        /* Detalles */
        .q-card { background: var(--card); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #e2e8f0; border-left: 5px solid #e2e8f0; }
        .q-card.correct { border-left-color: var(--success); }
        .q-card.incorrect { border-left-color: var(--error); }
        .q-header { display: flex; justify-content: space-between; margin-bottom: 15px; }
        .q-text { font-weight: 600; font-size: 1rem; }
        .q-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-correct { background: #dcfce7; color: var(--success); }
        .badge-incorrect { background: #fee2e2; color: var(--error); }

        .answer-box { background: #f8fafc; padding: 12px; border-radius: 8px; font-size: 0.9rem; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .ans-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px; }
        .ans-val { font-weight: 500; }
        .ans-val.user-incorrect { color: var(--error); text-decoration: line-through; }
        .ans-val.user-correct { color: var(--success); }

        .actions { display: flex; justify-content: center; gap: 15px; margin-top: 40px; }
        .btn { text-decoration: none; padding: 12px 25px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 6px rgba(79, 70, 229, 0.2); }
        .btn-primary:hover { background: #4338ca; transform: translateY(-2px); }
        .btn-outline { border: 1px solid #cbd5e1; color: #334155; background: white; }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        
        @media (max-width: 600px) { .answer-box { grid-template-columns: 1fr; gap: 10px; } }
    </style>
</head>
<body>

<div class="container">
    <div class="score-card">
        <div class="score-circle">
            <div class="score-inner">
                <span class="percentage"><?= $porcentaje ?>%</span>
            </div>
        </div>
        <div class="score-details">
            Obtuviste <strong><?= $puntosObtenidos ?></strong> de <?= $quizData['valor_total'] ?> puntos
        </div>
        <div class="score-message">
            <?php 
                if($porcentaje == 100) echo "¡Excelente! 🌟";
                elseif($porcentaje >= 70) echo "¡Buen trabajo! 👍";
                else echo "Sigue practicando 💪";
            ?>
        </div>
    </div>

    <h3 style="margin-bottom: 20px; color: #334155;">Desglose de Respuestas</h3>

    <?php foreach ($detallesPantalla as $idx => $d): ?>
    <div class="q-card <?= $d['es_correcta'] ? 'correct' : 'incorrect' ?>">
        <div class="q-header">
            <div class="q-text">
                <span style="color: #94a3b8; margin-right: 5px;">#<?= $idx+1 ?></span> 
                <?= htmlspecialchars($d['pregunta']) ?>
            </div>
            <div>
                <?php if($d['es_correcta']): ?>
                    <span class="q-badge badge-correct"><i class="fas fa-check"></i> Correcto</span>
                <?php else: ?>
                    <span class="q-badge badge-incorrect"><i class="fas fa-times"></i> Error</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="answer-box">
            <div>
                <span class="ans-label">Tu Respuesta</span>
                <span class="ans-val <?= $d['es_correcta'] ? 'user-correct' : 'user-incorrect' ?>">
                    <?= htmlspecialchars($d['tu_respuesta']) ?>
                </span>
            </div>
            <?php if(!$d['es_correcta']): ?>
            <div>
                <span class="ans-label">Respuesta Correcta</span>
                <span class="ans-val" style="color: var(--success);">
                    <?= htmlspecialchars($d['correcta_respuesta']) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="actions">
        <a href="index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Volver al Inicio
        </a>
        <?php if(isset($resultado_id_sql)): ?>
            <a href="resultados.php?descargar_pdf=1&resultado_id=<?= $resultado_id_sql ?>" target="_blank" class="btn btn-primary">
                <i class="fas fa-file-pdf"></i> Descargar PDF
            </a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>