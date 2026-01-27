<?php
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();
require 'db.php';

// Configurar zona horaria
date_default_timezone_set('America/Guayaquil'); 

if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}
$usuario = $_SESSION['usuario'];

// ==========================================================
// MODO EXAMEN
// ==========================================================
if (isset($_GET['quiz'])) {
    $quizId = $_GET['quiz'];
    $limitQuestions = 25; 
    
    // 1. Obtener datos del Quiz
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
    $stmt->execute([$quizId]);
    $quizData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quizData) {
        die('<div class="container" style="text-align:center; padding:50px;"><h2>Examen no encontrado</h2><a href="index.php" class="btn">Volver</a></div>');
    }

    // 2. VALIDACIONES
    if ($quizData['activo'] == 0) die('<div style="text-align:center; padding:50px;"><h2>⛔ Acceso Restringido</h2><p>El profesor ha desactivado este examen.</p><a href="index.php">Volver</a></div>');

    $ahora = time();
    $inicio = strtotime($quizData['fecha_inicio']);
    $fin = strtotime($quizData['fecha_fin']);

    if ($ahora < $inicio) die('<div style="text-align:center; padding:50px;"><h2>⏳ Aún no disponible</h2><p>Abre: '.date('d/m H:i', $inicio).'</p><a href="index.php">Volver</a></div>');
    if ($ahora > $fin) die('<div style="text-align:center; padding:50px;"><h2>🔒 Finalizado</h2><p>Cerró: '.date('d/m H:i', $fin).'</p><a href="index.php">Volver</a></div>');

    // 2.1 VALIDAR SI YA LO REALIZÓ
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM resultados WHERE usuario_id = ? AND quiz_id = ?");
    $stmtCheck->execute([$usuario['id'], $quizId]);
    if ($stmtCheck->fetchColumn() > 0) {
        die('<div style="text-align:center; padding:50px;"><h2>⚠️ Ya realizado</h2><p>Ya has completado este examen anteriormente. O el examen está en proceso de calificación.</p><a href="index.php">Volver</a></div>');
    }

    // 3. FORMULARIO DEMOGRÁFICO PREVIO
    $sessionDemoKey = 'demo_data_' . $quizId . '_' . $usuario['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_demograficos'])) {
        $_SESSION[$sessionDemoKey] = [
            'edad' => $_POST['edad'],
            'genero' => $_POST['genero'],
            'residencia' => $_POST['residencia'],
            'grado' => $_POST['grado'],
            'paralelo' => $_POST['paralelo'],
            'jornada' => $_POST['jornada'],
            'discapacidad' => $_POST['discapacidad']
        ];
        header("Location: index.php?quiz=" . $quizId);
        exit;
    }

    if (!isset($_SESSION[$sessionDemoKey])) {
        // Renderizar Vista Formulario
        include 'views/quiz_demographics.php';
        exit;
    }

    // 4. LÓGICA DEL EXAMEN (TIMER Y PREGUNTAS)
    $sessionKey = 'quiz_start_' . $quizId . '_' . $usuario['id']; 
    $duracionSegundos = ($quizData['duracion_minutos'] ?? 60) * 60;

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = time();
    }

    $tiempoTranscurrido = time() - $_SESSION[$sessionKey];
    $tiempoRestante = $duracionSegundos - $tiempoTranscurrido;
    if ($tiempoRestante <= 0) $tiempoRestante = 0; 

    // --- CARGA INTELIGENTE SEGMENTADA (23 de un grupo + 2 del otro) ---
    if (!isset($_SESSION['quiz_questions_' . $quizId])) {
        
        // 1. Traer TODAS las preguntas
        $stmtP = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ? ORDER BY id ASC");
        $stmtP->execute([$quizId]);
        $todasLasPreguntas = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        // 2. LÓGICA DE SELECCIÓN
        $totalDisponibles = count($todasLasPreguntas);

        if ($totalDisponibles <= 10) {
            // CASO: Pocas preguntas (ej. prueba) -> Seleccionar solo 2 al azar
            shuffle($todasLasPreguntas);
            $preguntasFinales = array_slice($todasLasPreguntas, 0, 2);
        } else {
            // CASO NORMAL: Segmentación 50/50 y selección 23+2
            
            // 2.1 SEPARAR LOS GRUPOS (Slicing)
            $grupoA = array_slice($todasLasPreguntas, 0, 50); 
            $grupoB = array_slice($todasLasPreguntas, 50); 
    
            // 2.2 SELECCIONAR ALEATORIAS
            shuffle($grupoA); 
            $seleccionA = array_slice($grupoA, 0, 23);
    
            shuffle($grupoB); 
            $seleccionB = array_slice($grupoB, 0, 2);
    
            // 2.3 UNIFICAR
            // IMPORTANTE: Mezclar Solo el Grupo A para que el Grupo B quede siempre al final
            shuffle($seleccionA);
            // (Opcional) Mezclar el Grupo B entre sí
            shuffle($seleccionB);
    
            $preguntasFinales = array_merge($seleccionA, $seleccionB);
        }
        // shuffle($preguntasFinales); <--- SE QUITA EL SHUFFLE FINAL

        // 6. CARGAR OPCIONES
        foreach ($preguntasFinales as &$p) {
            $stmtO = $pdo->prepare("SELECT id, texto, imagen FROM opciones WHERE pregunta_id = ?");
            $stmtO->execute([$p['id']]);
            $p['respuestas'] = $stmtO->fetchAll(PDO::FETCH_ASSOC);
        }

        // 7. GUARDAR EN SESIÓN
        $_SESSION['quiz_questions_' . $quizId] = $preguntasFinales;
    }

    $preguntasMostrar = $_SESSION['quiz_questions_' . $quizId];
    
    // Renderizar Vista Examen
    include 'views/quiz_taking.php';

// ==========================================================
// MODO DASHBOARD
// ==========================================================
} else {
    // Obtener quizzes desde BD (No-NNE)
    try {
        $stmt = $pdo->query("SELECT *, (SELECT COUNT(*) FROM preguntas WHERE quiz_id = quizzes.id) as cantidad_preguntas 
                             FROM quizzes 
                             WHERE COALESCE(es_nne, false) = false
                             ORDER BY id DESC");
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $quizzes = []; }
    
    // Renderizar Vista Dashboard
    include 'views/student_dashboard.php';
}
?>