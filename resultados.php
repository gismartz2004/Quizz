<?php
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();
require 'db.php';              // Conexión SQL
ini_set('max_execution_time', 300); // 5 minutos máximo para procesar

// . SEGURIDAD
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
    $pdo->beginTransaction();

    // Recuperar datos demográficos de sesión
    $sessionDemoKey = 'demo_data_' . $quizId . '_' . $usuario['id'];
    $demograficos = $_SESSION[$sessionDemoKey] ?? [
        'edad' => null, 'genero' => null, 'residencia' => null, 'grado' => null, 
        'paralelo' => null, 'jornada' => null, 'discapacidad' => null
    ];

    // Recuperar datos de integridad (tab switch y tiempo fuera)
    $intentos_tab_switch = isset($_POST['intentos_copia']) ? (int)$_POST['intentos_copia'] : 0;
    $segundos_fuera = isset($_POST['tiempo_fuera_segundos']) ? (int)$_POST['tiempo_fuera_segundos'] : 0;

    $stmtR = $pdo->prepare("
        INSERT INTO resultados 
        (usuario_id, quiz_id, puntos_obtenidos, puntos_totales_quiz, porcentaje, edad, genero, residencia, grado, paralelo, jornada, discapacidad, intentos_tab_switch, segundos_fuera) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        $demograficos['grado'],
        $demograficos['paralelo'],
        $demograficos['jornada'],
        $demograficos['discapacidad'],
        $intentos_tab_switch,
        $segundos_fuera
    ]);
    
    // Limpiar sesión demográfica ya usada
    unset($_SESSION[$sessionDemoKey]);
    unset($_SESSION['quiz_start_' . $quizId . '_' . $usuario['id']]); // Limpiar timer
    unset($_SESSION['quiz_questions_' . $quizId]); // Limpiar orden preguntas

    // ----------------------------------------------------------
    // 5. GUARDAR DETALLE DE RESPUESTAS (Justificaciones)
    // ----------------------------------------------------------
    $resultadoId = $pdo->lastInsertId();

    // Crear tabla si no existe (Idealmente esto debería ir en un script de migración)
    // $pdo->exec("CREATE TABLE IF NOT EXISTS respuestas_usuarios (
    //     id SERIAL PRIMARY KEY,
    //     resultado_id INTEGER NOT NULL,
    //     pregunta_id INTEGER NOT NULL,
    //     opcion_id INTEGER,
    //     justificacion TEXT,
    //     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //     FOREIGN KEY (resultado_id) REFERENCES resultados(id) ON DELETE CASCADE
    // )");

    $stmtDetalle = $pdo->prepare("INSERT INTO respuestas_usuarios (resultado_id, pregunta_id, opcion_id, justificacion) VALUES (?, ?, ?, ?)");

    // Guardar cada respuesta
    // $respuestasUsuario tiene [pregunta_id => opcion_id]
    $justificaciones = $_POST['justificacion'] ?? [];

    foreach ($respuestasUsuario as $pregId => $opId) {
        $justTexto = isset($justificaciones[$pregId]) ? trim($justificaciones[$pregId]) : null;
        $stmtDetalle->execute([
            $resultadoId,
            $pregId,
            $opId,
            $justTexto
        ]);
    }

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die('<div style="color:red; font-family:sans-serif; padding:20px; text-align:center;">
            <h1>Error al guardar respuestas</h1>
            <p>Por favor toma una captura de esta pantalla y envíala al profesor.</p>
            <p style="background:#fce7f3; padding:15px; border-radius:10px;">' . htmlspecialchars($e->getMessage()) . '</p>
            <a href="index.php" style="color:blue;">Intentar volver</a>
         </div>');
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