<?php
session_start();

// Configurar zona horaria (ajusta según tu país, ej: America/Guayaquil, America/Mexico_City)
date_default_timezone_set('America/Guayaquil'); 

if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}

$usuario = $_SESSION['usuario'];

// Función para obtener todos los quizzes con los nuevos datos
function obtenerQuizzes() {
    $quizzes = [];
    $archivos = glob('quizzes/*.json');
    foreach ($archivos as $archivo) {
        $contenido = file_get_contents($archivo);
        $quizData = json_decode($contenido, true);
        if ($quizData) {
            $quizzes[] = [
                'archivo' => basename($archivo),
                'titulo' => $quizData['titulo'] ?? 'Sin título',
                'descripcion' => $quizData['descripcion'] ?? '',
                'color_primario' => $quizData['color_primario'] ?? '#3498db',
                'color_secundario' => $quizData['color_secundario'] ?? '#2980b9',
                'cantidad_preguntas' => count($quizData['preguntas'] ?? []),
                'valor_total' => $quizData['valor_total'] ?? 0,
                // Nuevos campos de fecha y tiempo
                'fecha_inicio' => $quizData['fecha_inicio'] ?? null,
                'fecha_fin' => $quizData['fecha_fin'] ?? null,
                'duracion_minutos' => $quizData['duracion_minutos'] ?? 60
            ];
        }
    }
    return $quizzes;
}

// ==========================================================
// VISTA: RESOLVER QUIZ
// ==========================================================
if (isset($_GET['quiz'])) {
    $quizFile = 'quizzes/' . $_GET['quiz'];
    
    if (!file_exists($quizFile)) {
        die('<div class="container"><h2>Quiz no encontrado</h2><a href="index.php" class="btn btn-back">Volver</a></div>');
    }

    $quizData = json_decode(file_get_contents($quizFile), true);
    
    // 1. VALIDACIÓN DE FECHAS (Seguridad Backend)
    $ahora = time();
    $inicio = isset($quizData['fecha_inicio']) ? strtotime($quizData['fecha_inicio']) : 0;
    $fin = isset($quizData['fecha_fin']) ? strtotime($quizData['fecha_fin']) : $ahora + 86400;

    if ($ahora < $inicio) {
        die('<div class="container" style="text-align:center; margin-top:50px;"><h2>⏳ El quiz aún no está disponible.</h2><p>Abre el: '.date('d/m/Y H:i', $inicio).'</p><a href="index.php" class="btn btn-back">Volver</a></div>');
    }
    if ($ahora > $fin) {
        die('<div class="container" style="text-align:center; margin-top:50px;"><h2>🔒 El quiz ha finalizado.</h2><p>Cerró el: '.date('d/m/Y H:i', $fin).'</p><a href="index.php" class="btn btn-back">Volver</a></div>');
    }

    // 2. LÓGICA DEL TEMPORIZADOR (SESSION)
    // Usamos una clave única por quiz para guardar cuándo empezó este usuario específico
    $sessionKey = 'quiz_start_' . md5($_GET['quiz']);
    $duracionSegundos = ($quizData['duracion_minutos'] ?? 60) * 60;

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = time();
    }

    $tiempoTranscurrido = time() - $_SESSION[$sessionKey];
    $tiempoRestante = $duracionSegundos - $tiempoTranscurrido;

    // Si se acabó el tiempo, forzar envío o mostrar mensaje (aquí dejamos que JS haga el submit, 
    // pero si recarga la página con tiempo negativo, lo redirigimos o mostramos alerta)
    if ($tiempoRestante <= 0) {
        // Opcional: Redirigir a resultados directamente si ya pasó el tiempo
        // header("Location: resultados.php?quiz=" . urlencode($_GET['quiz']) . "&timeout=1");
        $tiempoRestante = 0; 
    }

    // Mezclar preguntas
    if (!isset($_SESSION['quiz_questions_' . md5($_GET['quiz'])])) {
        shuffle($quizData['preguntas']);
        $_SESSION['quiz_questions_' . md5($_GET['quiz'])] = $quizData['preguntas'];
    }
    $preguntasMostrar = array_slice($_SESSION['quiz_questions_' . md5($_GET['quiz'])], 0, min(40, count($quizData['preguntas'] ?? [])));
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($quizData['titulo'] ?? 'Quiz') ?></title>
        <link rel="stylesheet" href="css/index.css">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>
            /* Estilos específicos para el Timer Flotante */
            .timer-bar {
                position: fixed;
                top: 0; left: 0; width: 100%;
                background: #2c3e50;
                color: white;
                padding: 10px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                z-index: 1000;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            }
            .timer-clock {
                font-size: 1.2rem;
                font-weight: bold;
                font-family: monospace;
                background: #e74c3c;
                padding: 5px 15px;
                border-radius: 5px;
            }
            .quiz-content { margin-top: 60px; } /* Espacio para no tapar con el timer */
            .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-size: 1rem; }
            .btn-submit:hover { background: #2ecc71; }
            .pregunta img { max-width: 100%; height: auto; margin: 10px 0; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="timer-bar">
            <div><i class="fa-solid fa-book-open"></i> <?= htmlspecialchars($quizData['titulo']) ?></div>
            <div id="timerDisplay" class="timer-clock">
                <i class="fa-regular fa-clock"></i> Cargando...
            </div>
        </div>

        <div class="container quiz-content">
            <div class="quiz-header">
                <h1><?= htmlspecialchars($quizData['titulo']) ?></h1>
                <p><?= htmlspecialchars($quizData['descripcion']) ?></p>
            </div>

            <form id="quizForm" action="resultados.php?quiz=<?= urlencode($_GET['quiz']) ?>" method="post">
                <?php foreach ($preguntasMostrar as $index => $pregunta): ?>
                    <div class="pregunta" style="border-left: 5px solid <?= htmlspecialchars($quizData['color_primario']) ?>; background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <h3>
                            <span style="color:<?= htmlspecialchars($quizData['color_primario']) ?>">Q<?= $index + 1 ?>.</span> 
                            <?= htmlspecialchars($pregunta['texto']) ?>
                            <span style="float:right; font-size:0.8rem; color:#777;">(<?= $pregunta['valor'] ?> pts)</span>
                        </h3>
                        
                        <?php if (!empty($pregunta['imagen'])): ?>
                            <img src="assets/images/<?= htmlspecialchars($pregunta['imagen']) ?>" alt="Imagen de la pregunta">
                        <?php endif; ?>

                        <div class="opciones" style="margin-top: 15px;">
                            <?php foreach ($pregunta['respuestas'] as $respuesta): ?>
                                <div class="respuesta" style="margin-bottom: 8px;">
                                    <input type="radio" name="respuesta[<?= $pregunta['id'] ?>]" value="<?= $respuesta['id'] ?>" id="r_<?= $pregunta['id'] ?>_<?= $respuesta['id'] ?>" required>
                                    <label for="r_<?= $pregunta['id'] ?>_<?= $respuesta['id'] ?>" style="margin-left: 5px; cursor: pointer;">
                                        <?= htmlspecialchars($respuesta['texto']) ?>
                                    </label>
                                    <?php if (!empty($respuesta['imagen'])): ?>
                                        <br><img src="assets/images/<?= htmlspecialchars($respuesta['imagen']) ?>" style="max-width: 150px;" alt="Img respuesta">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="form-actions" style="text-align: center; margin-top: 30px;">
                    <button type="submit" class="btn btn-submit"><i class="fa-solid fa-paper-plane"></i> Enviar Respuestas</button>
                </div>
            </form>
        </div>

        <script>
            // Tiempo restante traído desde PHP (segundos)
            let timeLeft = <?= $tiempoRestante ?>;
            const timerDisplay = document.getElementById('timerDisplay');
            const quizForm = document.getElementById('quizForm');

            function updateTimer() {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    timerDisplay.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ¡Tiempo Agotado!';
                    timerDisplay.style.background = "#c0392b";
                    alert("El tiempo se ha terminado. Tus respuestas serán enviadas automáticamente.");
                    quizForm.submit();
                    return;
                }

                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                
                // Formato 00:00
                timerDisplay.innerHTML = `<i class="fa-regular fa-clock"></i> ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                // Alerta visual cuando queda poco tiempo (menos de 1 min)
                if(timeLeft < 60) {
                    timerDisplay.style.background = "#d35400";
                    timerDisplay.classList.add('blink'); // Podrías añadir animación CSS
                }

                timeLeft--;
            }

            const timerInterval = setInterval(updateTimer, 1000);
            updateTimer(); // Ejecutar una vez al inicio
        </script>
    </body>
    </html>
<?php

// ==========================================================
// VISTA: LISTA DE QUIZZES
// ==========================================================
} else {
    $quizzes = obtenerQuizzes();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Quizzes Disponibles</title>
        <link rel="stylesheet" href="css/index.css">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>
            /* Estilos inline para asegurar que se vean los cambios */
            body { background-color: #f5f7fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
            .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
            .user-bar { background: white; padding: 15px 20px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 30px; }
            .quizzes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
            
            .quiz-card { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; display: flex; flex-direction: column; }
            .quiz-card:hover { transform: translateY(-5px); }
            .card-header { padding: 15px 20px; color: white; }
            .card-header h3 { margin: 0; font-size: 1.2rem; }
            .card-body { padding: 20px; flex-grow: 1; display: flex; flex-direction: column; }
            .quiz-meta { margin: 15px 0; font-size: 0.9rem; color: #666; }
            .quiz-meta div { margin-bottom: 5px; }
            
            .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; margin-bottom: 10px; }
            .status-open { background: #d1fae5; color: #065f46; }
            .status-closed { background: #fee2e2; color: #991b1b; }
            .status-future { background: #ffedd5; color: #9a3412; }

            .btn { text-decoration: none; padding: 10px 15px; border-radius: 5px; text-align: center; display: inline-block; }
            .btn-primary { background: #3498db; color: white; }
            .btn-disabled { background: #ccc; color: #666; cursor: not-allowed; pointer-events: none; }
            .logout-btn { color: #e74c3c; text-decoration: none; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="user-bar">
                <div>
                    <h2 style="margin:0;">👤 <?= htmlspecialchars($usuario['nombre'] ?? 'Estudiante') ?></h2>
                    <small style="color:#777"><?= date('d/m/Y H:i') ?></small>
                </div>
                <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</a>
            </div>

            <div class="header" style="margin-bottom: 20px;">
                <h2>📚 Quizzes Disponibles</h2>
                <p>Selecciona un quiz habilitado para comenzar.</p>
            </div>

            <?php if (!empty($quizzes)): ?>
                <div class="quizzes-grid">
                    <?php foreach ($quizzes as $quiz): 
                        // Lógica de estado del quiz
                        $ahora = time();
                        $inicio = isset($quiz['fecha_inicio']) ? strtotime($quiz['fecha_inicio']) : 0;
                        $fin = isset($quiz['fecha_fin']) ? strtotime($quiz['fecha_fin']) : $ahora + 86400;
                        
                        $estado = 'open';
                        $mensajeBtn = 'Iniciar Quiz';
                        
                        if ($ahora < $inicio) {
                            $estado = 'future';
                            $mensajeBtn = 'Abre pronto';
                        } elseif ($ahora > $fin) {
                            $estado = 'closed';
                            $mensajeBtn = 'Cerrado';
                        }
                    ?>
                        <div class="quiz-card" style="border-top: 4px solid <?= htmlspecialchars($quiz['color_primario']) ?>;">
                            <div class="card-header" style="background-color: <?= htmlspecialchars($quiz['color_primario']) ?>;">
                                <h3><?= htmlspecialchars($quiz['titulo']) ?></h3>
                            </div>
                            <div class="card-body">
                                <div>
                                    <?php if($estado == 'open'): ?>
                                        <span class="status-badge status-open"><i class="fa-solid fa-check-circle"></i> Disponible</span>
                                    <?php elseif($estado == 'closed'): ?>
                                        <span class="status-badge status-closed"><i class="fa-solid fa-lock"></i> Finalizado</span>
                                    <?php else: ?>
                                        <span class="status-badge status-future"><i class="fa-solid fa-clock"></i> Programado</span>
                                    <?php endif; ?>
                                </div>

                                <p><?= htmlspecialchars($quiz['descripcion'] ?: 'Sin descripción') ?></p>
                                
                                <div class="quiz-meta">
                                    <div><i class="fa-solid fa-list-ol"></i> <strong><?= $quiz['cantidad_preguntas'] ?></strong> preguntas</div>
                                    <div><i class="fa-solid fa-star"></i> <strong><?= $quiz['valor_total'] ?></strong> pts</div>
                                    <div><i class="fa-regular fa-hourglass"></i> <strong><?= $quiz['duracion_minutos'] ?></strong> minutos</div>
                                    <hr style="border: 0; border-top: 1px solid #eee; margin: 10px 0;">
                                    <?php if($quiz['fecha_inicio']): ?>
                                        <div style="font-size: 0.85rem;">
                                            <div><i class="fa-regular fa-calendar-check"></i> Inicio: <?= date('d/m/y H:i', strtotime($quiz['fecha_inicio'])) ?></div>
                                            <div><i class="fa-regular fa-calendar-xmark"></i> Fin: <?= date('d/m/y H:i', strtotime($quiz['fecha_fin'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <a href="index.php?quiz=<?= urlencode($quiz['archivo']) ?>" 
                                   class="btn <?= ($estado == 'open') ? 'btn-primary' : 'btn-disabled' ?>">
                                   <?= $mensajeBtn ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-quizzes" style="text-align: center; padding: 50px; background: white; border-radius: 10px;">
                    <i class="fa-regular fa-folder-open" style="font-size: 3rem; color: #ddd;"></i>
                    <h3>No hay quizzes disponibles</h3>
                    <p>El profesor aún no ha creado ningún quiz.</p>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
<?php
}
?>