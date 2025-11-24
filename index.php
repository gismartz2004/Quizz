<?php
session_start();

// Configurar zona horaria (Importante para que las fechas coincidan)
date_default_timezone_set('America/Guayaquil'); 

if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}

$usuario = $_SESSION['usuario'];

// Función para obtener todos los quizzes
function obtenerQuizzes() {
    $quizzes = [];
    $archivos = glob('quizzes/*.json');
    
    // Ordenar: Primero los abiertos, luego futuros, al final cerrados
    usort($archivos, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    foreach ($archivos as $archivo) {
        $contenido = file_get_contents($archivo);
        $quizData = json_decode($contenido, true);
        if ($quizData) {
            $quizzes[] = [
                'archivo' => basename($archivo),
                'titulo' => $quizData['titulo'] ?? 'Sin título',
                'descripcion' => $quizData['descripcion'] ?? '',
                'color_primario' => $quizData['color_primario'] ?? '#4f46e5',
                'color_secundario' => $quizData['color_secundario'] ?? '#4338ca',
                'cantidad_preguntas' => count($quizData['preguntas'] ?? []),
                'valor_total' => $quizData['valor_total'] ?? 0,
                'fecha_inicio' => $quizData['fecha_inicio'] ?? null,
                'fecha_fin' => $quizData['fecha_fin'] ?? null,
                'duracion_minutos' => $quizData['duracion_minutos'] ?? 60
            ];
        }
    }
    return $quizzes;
}

// ==========================================================
// VISTA: RESOLVER QUIZ (MODO EXAMEN)
// ==========================================================
if (isset($_GET['quiz'])) {
    $quizFile = 'quizzes/' . $_GET['quiz'];
    
    if (!file_exists($quizFile)) {
        die('<div class="container" style="text-align:center; padding:50px;"><h2>Quiz no encontrado</h2><a href="index.php" class="btn btn-primary">Volver</a></div>');
    }

    $quizData = json_decode(file_get_contents($quizFile), true);
    
    // VALIDACIÓN DE FECHAS
    $ahora = time();
    $inicio = isset($quizData['fecha_inicio']) ? strtotime($quizData['fecha_inicio']) : 0;
    $fin = isset($quizData['fecha_fin']) ? strtotime($quizData['fecha_fin']) : $ahora + 86400;

    // Estilos de error
    $errorStyle = 'max-width:600px; margin:50px auto; padding:30px; text-align:center; background:white; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.1); font-family:sans-serif;';

    if ($ahora < $inicio) {
        die('<div style="'.$errorStyle.'"><h2 style="color:#e67e22;">⏳ Aún no disponible</h2><p>Este quiz abre el: <strong>'.date('d/m/Y H:i', $inicio).'</strong></p><a href="index.php" style="display:inline-block; margin-top:15px; text-decoration:none; background:#3498db; color:white; padding:10px 20px; border-radius:6px;">Volver al inicio</a></div>');
    }
    if ($ahora > $fin) {
        die('<div style="'.$errorStyle.'"><h2 style="color:#e74c3c;">🔒 Quiz Finalizado</h2><p>La fecha límite fue el: <strong>'.date('d/m/Y H:i', $fin).'</strong></p><a href="index.php" style="display:inline-block; margin-top:15px; text-decoration:none; background:#3498db; color:white; padding:10px 20px; border-radius:6px;">Volver al inicio</a></div>');
    }

    // LÓGICA DEL TEMPORIZADOR
    $sessionKey = 'quiz_start_' . md5($_GET['quiz']);
    $duracionSegundos = ($quizData['duracion_minutos'] ?? 60) * 60;

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = time();
    }

    $tiempoTranscurrido = time() - $_SESSION[$sessionKey];
    $tiempoRestante = $duracionSegundos - $tiempoTranscurrido;

    if ($tiempoRestante <= 0) $tiempoRestante = 0; 

    // Mezclar preguntas solo una vez
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
        <title>Resolviendo: <?= htmlspecialchars($quizData['titulo']) ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            :root { --primary: <?= htmlspecialchars($quizData['color_primario']) ?>; --bg: #f8fafc; --text: #334155; }
            body { font-family: 'Inter', sans-serif; background-color: var(--bg); color: var(--text); margin: 0; padding-top: 80px; }
            
            /* Barra de Tiempo Flotante */
            .timer-bar {
                position: fixed; top: 0; left: 0; width: 100%; height: 70px;
                background: white; border-bottom: 1px solid #e2e8f0;
                display: flex; justify-content: center; align-items: center;
                z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            }
            .timer-content {
                width: 100%; max-width: 1000px; padding: 0 20px;
                display: flex; justify-content: space-between; align-items: center;
            }
            .quiz-info-mini { font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 10px; }
            .timer-clock {
                font-family: 'Courier New', monospace; font-weight: 700; font-size: 1.2rem;
                background: #fee2e2; color: #b91c1c; padding: 8px 16px; border-radius: 8px;
                display: flex; align-items: center; gap: 8px;
            }
            .timer-clock.safe { background: #dcfce7; color: #15803d; }

            .container { max-width: 800px; margin: 0 auto; padding: 20px; }
            
            /* Preguntas */
            .pregunta-card {
                background: white; border-radius: 16px; padding: 30px; margin-bottom: 30px;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
                position: relative; overflow: hidden;
            }
            .pregunta-card::before {
                content: ''; position: absolute; top: 0; left: 0; width: 6px; height: 100%;
                background: var(--primary);
            }
            .q-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
            .q-title { font-size: 1.1rem; font-weight: 600; color: #0f172a; line-height: 1.5; }
            .q-points { font-size: 0.85rem; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 20px; height: fit-content; }
            
            .q-image { max-width: 100%; border-radius: 8px; margin-bottom: 20px; max-height: 300px; object-fit: contain; border: 1px solid #e2e8f0; }

            /* Opciones */
            .option-group { display: flex; flex-direction: column; gap: 12px; }
            .option-label {
                display: flex; align-items: center; padding: 15px; border: 2px solid #e2e8f0;
                border-radius: 10px; cursor: pointer; transition: 0.2s; position: relative;
            }
            .option-label:hover { border-color: var(--primary); background: #f8fafc; }
            .option-input { position: absolute; opacity: 0; }
            .option-input:checked + .option-content { color: var(--primary); font-weight: 600; }
            .option-input:checked + .option-content::before { border-color: var(--primary); background: var(--primary); box-shadow: inset 0 0 0 4px white; }
            
            .option-content { display: flex; align-items: center; gap: 15px; width: 100%; }
            .option-content::before {
                content: ''; width: 20px; height: 20px; border-radius: 50%;
                border: 2px solid #cbd5e1; flex-shrink: 0; transition: 0.2s;
            }
            
            .option-img { max-width: 120px; border-radius: 6px; margin-left: auto; }

            /* Botón Enviar */
            .actions { text-align: center; margin-top: 40px; margin-bottom: 60px; }
            .btn-finish {
                background: var(--primary); color: white; font-size: 1.1rem; font-weight: 600;
                padding: 15px 40px; border-radius: 50px; border: none; cursor: pointer;
                box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3); transition: 0.2s;
            }
            .btn-finish:hover { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.4); }

            /* Estilo seleccionado para el contenedor entero */
            .option-label:has(input:checked) { border-color: var(--primary); background: #eff6ff; }
        </style>
    </head>
    <body>
        <div class="timer-bar">
            <div class="timer-content">
                <div class="quiz-info-mini">
                    <i class="fas fa-book-open" style="color:var(--primary)"></i>
                    <?= htmlspecialchars($quizData['titulo']) ?>
                </div>
                <div id="timerDisplay" class="timer-clock safe">
                    <i class="far fa-clock"></i> --:--
                </div>
            </div>
        </div>

        <div class="container">
            <form id="quizForm" action="resultados.php?quiz=<?= urlencode($_GET['quiz']) ?>" method="post">
                <?php foreach ($preguntasMostrar as $index => $pregunta): ?>
                    <div class="pregunta-card">
                        <div class="q-header">
                            <div class="q-title">
                                <span style="color:var(--primary); margin-right:8px;">#<?= $index + 1 ?></span>
                                <?= htmlspecialchars($pregunta['texto']) ?>
                            </div>
                            <span class="q-points"><?= $pregunta['valor'] ?> pts</span>
                        </div>

                        <?php if (!empty($pregunta['imagen'])): ?>
                            <img src="assets/images/<?= htmlspecialchars($pregunta['imagen']) ?>" class="q-image" alt="Imagen Pregunta">
                        <?php endif; ?>

                        <div class="option-group">
                            <?php foreach ($pregunta['respuestas'] as $respuesta): ?>
                                <label class="option-label">
                                    <input type="radio" class="option-input" name="respuesta[<?= $pregunta['id'] ?>]" value="<?= $respuesta['id'] ?>" required>
                                    <div class="option-content">
                                        <span><?= htmlspecialchars($respuesta['texto']) ?></span>
                                        <?php if (!empty($respuesta['imagen'])): ?>
                                            <img src="assets/images/<?= htmlspecialchars($respuesta['imagen']) ?>" class="option-img">
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="actions">
                    <button type="submit" class="btn-finish">
                        <i class="fas fa-paper-plane"></i> Enviar Examen
                    </button>
                </div>
            </form>
        </div>

        <script>
            let timeLeft = <?= $tiempoRestante ?>;
            const timerDisplay = document.getElementById('timerDisplay');
            const quizForm = document.getElementById('quizForm');

            function updateTimer() {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    timerDisplay.innerHTML = '<i class="fas fa-exclamation-circle"></i> Tiempo!';
                    timerDisplay.style.background = "#991b1b";
                    timerDisplay.style.color = "white";
                    alert("¡Tiempo agotado! Enviando respuestas...");
                    quizForm.submit();
                    return;
                }

                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                
                timerDisplay.innerHTML = `<i class="far fa-clock"></i> ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                // Cambiar color cuando queda poco tiempo
                if(timeLeft < 60) {
                    timerDisplay.classList.remove('safe');
                    timerDisplay.style.animation = "pulse 1s infinite";
                }

                timeLeft--;
            }

            const timerInterval = setInterval(updateTimer, 1000);
            updateTimer(); 
        </script>
        <style>@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }</style>
    </body>
    </html>
<?php

// ==========================================================
// VISTA: DASHBOARD ESTUDIANTE (LISTA DE QUIZZES)
// ==========================================================
} else {
    $quizzes = obtenerQuizzes();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Portal del Estudiante</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        
        <style>
            :root {
                --primary: #4f46e5;
                --bg-body: #f1f5f9;
                --text-main: #1e293b;
                --text-light: #64748b;
            }
            
            * { margin: 0; padding: 0; box-sizing: border-box; }
            
            body {
                font-family: 'Inter', sans-serif;
                background-color: var(--bg-body);
                color: var(--text-main);
            }

            /* Navbar */
            .navbar {
                background: white; padding: 15px 30px;
                display: flex; justify-content: space-between; align-items: center;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                position: sticky; top: 0; z-index: 100;
            }
            .nav-brand { font-weight: 700; font-size: 1.2rem; color: var(--primary); display: flex; align-items: center; gap: 10px; }
            
            .user-info { display: flex; align-items: center; gap: 15px; }
            .user-name { font-weight: 600; font-size: 0.9rem; text-align: right; }
            .user-role { font-size: 0.75rem; color: var(--text-light); display: block; }
            .avatar {
                width: 40px; height: 40px; background: #e0e7ff; color: var(--primary);
                border-radius: 50%; display: flex; align-items: center; justify-content: center;
                font-weight: 700;
            }
            .btn-logout { color: #ef4444; text-decoration: none; font-size: 1.2rem; margin-left: 10px; transition:0.2s; }
            .btn-logout:hover { transform: scale(1.1); }

            /* Contenedor Principal */
            .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
            
            .page-header { margin-bottom: 30px; }
            .page-header h2 { font-size: 1.8rem; font-weight: 800; margin-bottom: 5px; color: #0f172a; }
            .page-header p { color: var(--text-light); }

            /* Grid */
            .quiz-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px;
            }

            /* Tarjeta Quiz */
            .quiz-card {
                background: white; border-radius: 16px; overflow: hidden;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
                transition: transform 0.2s, box-shadow 0.2s;
                display: flex; flex-direction: column; height: 100%;
            }
            .quiz-card:hover { transform: translateY(-5px); box-shadow: 0 12px 20px -5px rgba(0,0,0,0.1); }

            .card-top { height: 6px; width: 100%; }
            
            .card-content { padding: 24px; flex-grow: 1; display: flex; flex-direction: column; }
            
            .status-badge {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 6px 12px; border-radius: 30px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; width: fit-content; margin-bottom: 15px;
            }
            .status-open { background: #dcfce7; color: #166534; }
            .status-closed { background: #f1f5f9; color: #64748b; }
            .status-future { background: #ffedd5; color: #9a3412; }

            .quiz-title { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 8px; line-height: 1.3; }
            .quiz-desc { color: var(--text-light); font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px; flex-grow: 1; }

            /* NUEVO: SECCIÓN DE FECHAS */
            .date-info {
                background: #f8fafc; padding: 12px; border-radius: 8px; font-size: 0.85rem; color: #475569;
                margin-bottom: 15px; border: 1px solid #e2e8f0;
            }
            .date-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
            .date-row:last-child { margin-bottom: 0; }
            .date-label { font-weight: 600; color: #64748b; font-size: 0.75rem; text-transform: uppercase; }

            .meta-info {
                display: flex; justify-content: space-between; padding: 15px 0;
                border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; margin-bottom: 20px; font-size: 0.85rem; color: #475569;
            }
            .meta-item { display: flex; align-items: center; gap: 6px; }

            /* Botón Card */
            .btn-card {
                display: flex; justify-content: center; align-items: center; gap: 8px;
                padding: 12px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: 0.2s;
            }
            .btn-primary { background: var(--primary); color: white; }
            .btn-primary:hover { background: #4338ca; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
            
            .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; pointer-events: none; }

            /* Empty State */
            .empty-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
            .empty-icon { font-size: 3rem; margin-bottom: 15px; opacity: 0.5; }
        </style>
    </head>
    <body>

        <nav class="navbar">
            <div class="nav-brand">
                <i class="fas fa-graduation-cap fa-lg"></i> AulaVirtual
            </div>
            <div class="user-info">
                <div>
                    <div class="user-name"><?= htmlspecialchars($usuario['nombre']) ?></div>
                    <span class="user-role">Estudiante</span>
                </div>
                <div class="avatar">
                    <?= strtoupper(substr($usuario['nombre'], 0, 1)) ?>
                </div>
                <a href="logout.php" class="btn-logout" title="Cerrar Sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>

        <div class="container">
            <div class="page-header">
                <h2>Evaluaciones Disponibles</h2>
                <p>Selecciona un examen para comenzar. Asegúrate de tener una conexión estable.</p>
            </div>

            <?php if (empty($quizzes)): ?>
                <div class="empty-state">
                    <i class="far fa-folder-open empty-icon"></i>
                    <h3>No hay evaluaciones asignadas</h3>
                    <p>Tus profesores aún no han publicado ningún quiz.</p>
                </div>
            <?php else: ?>
                <div class="quiz-grid">
                    <?php foreach ($quizzes as $quiz): 
                        $ahora = time();
                        $inicio = isset($quiz['fecha_inicio']) ? strtotime($quiz['fecha_inicio']) : 0;
                        $fin = isset($quiz['fecha_fin']) ? strtotime($quiz['fecha_fin']) : $ahora + 86400;
                        
                        $estado = 'open';
                        $mensajeBtn = 'Comenzar Examen';
                        $iconoBtn = 'fa-play';
                        
                        if ($ahora < $inicio) {
                            $estado = 'future';
                            $mensajeBtn = 'Próximamente';
                            $iconoBtn = 'fa-clock';
                        } elseif ($ahora > $fin) {
                            $estado = 'closed';
                            $mensajeBtn = 'Examen Cerrado';
                            $iconoBtn = 'fa-lock';
                        }
                    ?>
                    <div class="quiz-card">
                        <div class="card-top" style="background: <?= htmlspecialchars($quiz['color_primario']) ?>;"></div>
                        <div class="card-content">
                            <div>
                                <?php if($estado == 'open'): ?>
                                    <span class="status-badge status-open"><i class="fas fa-circle" style="font-size:8px"></i> Disponible</span>
                                <?php elseif($estado == 'closed'): ?>
                                    <span class="status-badge status-closed"><i class="fas fa-lock"></i> Finalizado</span>
                                <?php else: ?>
                                    <span class="status-badge status-future"><i class="fas fa-hourglass-half"></i> Programado</span>
                                <?php endif; ?>
                            </div>

                            <h3 class="quiz-title"><?= htmlspecialchars($quiz['titulo']) ?></h3>
                            <p class="quiz-desc">
                                <?= htmlspecialchars(substr($quiz['descripcion'], 0, 80)) . (strlen($quiz['descripcion'])>80 ? '...' : '') ?>
                            </p>

                            <div class="date-info">
                                <div class="date-row">
                                    <span class="date-label">Apertura:</span>
                                    <span><?= $quiz['fecha_inicio'] ? date('d/m/y H:i', strtotime($quiz['fecha_inicio'])) : 'Inmediata' ?></span>
                                </div>
                                <div class="date-row">
                                    <span class="date-label">Cierre:</span>
                                    <span style="color: <?= $estado == 'closed' ? '#ef4444' : 'inherit' ?>">
                                        <?= $quiz['fecha_fin'] ? date('d/m/y H:i', strtotime($quiz['fecha_fin'])) : 'Sin límite' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="meta-info">
                                <div class="meta-item" title="Preguntas">
                                    <i class="fas fa-list-ol" style="color:var(--primary)"></i> 
                                    <?= $quiz['cantidad_preguntas'] ?>
                                </div>
                                <div class="meta-item" title="Duración">
                                    <i class="far fa-clock" style="color:var(--primary)"></i> 
                                    <?= $quiz['duracion_minutos'] ?>m
                                </div>
                                <div class="meta-item" title="Puntos Totales">
                                    <i class="fas fa-star" style="color:#eab308"></i> 
                                    <?= $quiz['valor_total'] ?> pts
                                </div>
                            </div>

                            <a href="index.php?quiz=<?= urlencode($quiz['archivo']) ?>" 
                               class="btn-card <?= ($estado == 'open') ? 'btn-primary' : 'btn-disabled' ?>">
                                <i class="fas <?= $iconoBtn ?>"></i> <?= $mensajeBtn ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </body>
    </html>
<?php
}
?>