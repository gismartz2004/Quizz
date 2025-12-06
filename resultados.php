<?php
session_start();
require 'db.php';              // Conexión SQL

// 1. SEGURIDAD
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}
$usuario = $_SESSION['usuario'];

// ==========================================================
// LÓGICA: GENERAR PDF (VÍA GET)
// ==========================================================
    // (PDF generation logic removed by request)

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
// $detallesPantalla ya no es necesario para la vista, pero calculamos puntos para guardar

// 3. Calcular Nota
foreach ($preguntasDB as $pregunta) {
    $idPregunta = $pregunta['id'];
    $respuestaUserId = $respuestasUsuario[$idPregunta] ?? null;
    
    // Buscar opciones de esta pregunta
    $stmtO = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
    $stmtO->execute([$idPregunta]);
    $opciones = $stmtO->fetchAll(PDO::FETCH_ASSOC);
    
    $esCorrecta = false;
    foreach ($opciones as $op) {
        if ($op['es_correcta'] && $op['id'] == $respuestaUserId) {
            $esCorrecta = true;
            break;
        }
    }

    if ($esCorrecta) $puntosObtenidos += $pregunta['valor'];
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
    <title>Examen Finalizado</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #4f46e5; --bg: #f8fafc; --card: #ffffff; --text: #334155; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg); 
            margin: 0; 
            padding: 20px; 
            color: var(--text); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
        }
        .container { 
            max-width: 500px; 
            width: 100%; 
            text-align: center; 
        }
        .card { 
            background: var(--card); 
            border-radius: 20px; 
            padding: 50px 30px; 
            box-shadow: 0 20px 40px -5px rgba(0,0,0,0.1); 
            border: 1px solid #e2e8f0;
        }
        h1 { 
            color: #1e293b; 
            margin: 0 0 15px 0; 
            font-size: 1.8rem; 
            font-weight: 700;
        }
        p { 
            font-size: 1.05rem; 
            line-height: 1.6; 
            color: #64748b; 
            margin: 0 0 35px 0; 
        }
        .btn { 
            text-decoration: none; 
            padding: 14px 40px; 
            border-radius: 30px; 
            font-weight: 600; 
            background: var(--primary); 
            color: white; 
            transition: all 0.2s; 
            display: inline-block; 
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
        .btn:hover { 
            background: #4338ca; 
            transform: translateY(-2px); 
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.4); 
        }
        .icon-success { 
            font-size: 4.5rem; 
            color: #10b981; 
            margin-bottom: 25px; 
            animation: popUp 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes popUp {
            0% { transform: scale(0.5); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="icon-success"><i class="fas fa-check-circle"></i></div>
            <h1>¡Examen Finalizado!</h1>
            <p>
                Terminaste tu examen exitosamente.<br>
                <strong>Felicitaciones, te enviaremos las respuestas después.</strong>
            </p>
            <a href="index.php" class="btn"><i class="fas fa-home"></i> Volver al Inicio</a>
        </div>
    </div>
</body>
</html>