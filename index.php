<?php
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
// VISTA: RESOLVER QUIZ (MODO EXAMEN)
// ==========================================================
if (isset($_GET['quiz'])) {
    $quizId = $_GET['quiz'];
    $limitQuestions = 25; // <--- CONFIGURACIÓN: Cantidad de preguntas a mostrar
    
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

    // 3. FORMULARIO DEMOGRÁFICO PREVIO
    $sessionDemoKey = 'demo_data_' . $quizId . '_' . $usuario['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_demograficos'])) {
        $_SESSION[$sessionDemoKey] = [
            'edad' => $_POST['edad'],
            'genero' => $_POST['genero'],
            'residencia' => $_POST['residencia'],
            'discapacidad' => $_POST['discapacidad']
        ];
        header("Location: index.php?quiz=" . $quizId);
        exit;
    }

    if (!isset($_SESSION[$sessionDemoKey])) {
        // Renderizar Formulario Demográfico (Diseño Mejorado)
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Datos Previos</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
                .form-card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 450px; }
                h2 { color: #1e293b; margin-top: 0; font-size: 1.4rem; }
                label { display: block; margin-bottom: 6px; font-weight: 600; color: #334155; font-size: 0.9rem; }
                input, select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 15px; font-family: inherit; box-sizing: border-box; }
                .btn { width: 100%; padding: 14px; background: #4f46e5; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; }
                .btn:hover { background: #4338ca; }
            </style>
        </head>
        <body>
            <div class="form-card">
                <div style="margin-bottom:20px; color:#4f46e5; font-weight:bold;">📋 Paso 1 de 2</div>
                <h2>Datos del Estudiante</h2>
                <p style="color:#64748b; font-size:0.9rem; margin-bottom:20px;">Completa esta información para iniciar la prueba <strong><?= htmlspecialchars($quizData['titulo']) ?></strong>.</p>
                <form method="POST">
                    <input type="hidden" name="guardar_demograficos" value="1">
                    <label>Edad</label><input type="number" name="edad" required min="5" max="99">
                    <label>Género</label>
                    <select name="genero" required>
                        <option value="">Selecciona...</option>
                        <option value="Masculino">Masculino</option>
                        <option value="Femenino">Femenino</option>
                        <option value="Otro">Otro</option>
                    </select>
                    <label>Residencia (Ciudad)</label><input type="text" name="residencia" required>
                    <label>Discapacidad</label>
                    <select name="discapacidad" required>
                        <option value="Ninguna">Ninguna</option>
                        <option value="Visual">Visual</option>
                        <option value="Auditiva">Auditiva</option>
                        <option value="Motriz">Motriz</option>
                        <option value="Otra">Otra</option>
                    </select>
                    <button type="submit" class="btn">Guardar y Comenzar</button>
                </form>
            </div>
        </body>
        </html>
        <?php exit;
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

    // --- CARGA INTELIGENTE DE PREGUNTAS (SOLO 25 ALEATORIAS) ---
    if (!isset($_SESSION['quiz_questions_' . $quizId])) {
        // 1. Traer TODAS las preguntas del quiz
        $stmtP = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ?");
        $stmtP->execute([$quizId]);
        $todasLasPreguntas = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Mezclar
        shuffle($todasLasPreguntas);
        
        // 3. Cortar solo las necesarias (ej: 25)
        // Si hay menos de 25, las toma todas.
        $preguntasSeleccionadas = array_slice($todasLasPreguntas, 0, $limitQuestions);
        
        // 4. Cargar opciones SOLO para esas 25 preguntas (Optimización)
        foreach ($preguntasSeleccionadas as &$p) {
            $stmtO = $pdo->prepare("SELECT id, texto, imagen FROM opciones WHERE pregunta_id = ?");
            $stmtO->execute([$p['id']]);
            $p['respuestas'] = $stmtO->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 5. Guardar en sesión para que no cambien al recargar
        $_SESSION['quiz_questions_' . $quizId] = $preguntasSeleccionadas;
    }
    
    $preguntasMostrar = $_SESSION['quiz_questions_' . $quizId];
    ?>

    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Examen: <?= htmlspecialchars($quizData['titulo']) ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            :root { --primary: <?= htmlspecialchars($quizData['color_primario']) ?>; --bg: #f8fafc; --text: #334155; }
            body { font-family: 'Inter', sans-serif; background-color: var(--bg); color: var(--text); margin: 0; padding-top: 80px; padding-bottom: 100px; }
            
            /* Header Flotante */
            .timer-bar {
                position: fixed; top: 0; left: 0; width: 100%; height: 65px;
                background: white; border-bottom: 1px solid #e2e8f0;
                display: flex; justify-content: center; align-items: center;
                z-index: 1000; box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            }
            .timer-content { width: 100%; max-width: 900px; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
            .quiz-title-mini { font-weight: 700; color: #1e293b; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 60%; }
            
            .timer-clock {
                font-family: 'Courier New', monospace; font-weight: 700; font-size: 1.1rem;
                background: #f1f5f9; color: #334155; padding: 6px 12px; border-radius: 6px;
                display: flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0;
            }
            .timer-clock.danger { background: #fee2e2; color: #ef4444; border-color: #fecaca; animation: pulse 1s infinite; }

            .container { max-width: 800px; margin: 0 auto; padding: 20px; }

            /* Barra de Progreso */
            .progress-container { margin-bottom: 30px; }
            .progress-info { display: flex; justify-content: space-between; font-size: 0.85rem; color: #64748b; margin-bottom: 5px; }
            .progress-bar-bg { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
            .progress-bar-fill { height: 100%; background: var(--primary); width: 0%; transition: width 0.3s ease; }

            /* Tarjetas de Pregunta */
            .pregunta-card {
                background: white; border-radius: 12px; padding: 25px; margin-bottom: 25px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.02); border: 1px solid #e2e8f0;
            }
            .q-header { display: flex; gap: 15px; margin-bottom: 20px; }
            .q-num { font-weight: 700; color: var(--primary); font-size: 1.1rem; min-width: 30px; }
            .q-text { font-weight: 600; font-size: 1.05rem; line-height: 1.5; color: #0f172a; }
            
            .q-image { display: block; max-width: 100%; max-height: 300px; border-radius: 8px; margin: 10px 0 20px 45px; object-fit: contain; border: 1px solid #f1f5f9; }

            /* Opciones Estilizadas */
            .option-group { display: flex; flex-direction: column; gap: 10px; margin-left: 45px; }
            .option-label {
                display: flex; align-items: center; padding: 12px 16px; border: 2px solid #e2e8f0;
                border-radius: 8px; cursor: pointer; transition: all 0.2s; position: relative; background: white;
            }
            .option-label:hover { background: #f8fafc; border-color: #cbd5e1; }
            
            .option-input { position: absolute; opacity: 0; cursor: pointer; }
            
            /* Diseño cuando está seleccionado */
            .option-input:checked + .option-content { font-weight: 600; color: var(--primary); }
            .option-label:has(.option-input:checked) { border-color: var(--primary); background: #eff6ff; box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1); }
            
            .option-circle {
                width: 20px; height: 20px; border-radius: 50%; border: 2px solid #cbd5e1; margin-right: 15px; flex-shrink: 0;
                display: flex; align-items: center; justify-content: center; transition: 0.2s;
            }
            .option-input:checked + .option-content .option-circle { border-color: var(--primary); background: var(--primary); }
            .option-circle::after { content: ''; width: 8px; height: 8px; background: white; border-radius: 50%; display: none; }
            .option-input:checked + .option-content .option-circle::after { display: block; }

            .option-content { display: flex; align-items: center; width: 100%; }
            .option-img { max-width: 100px; border-radius: 4px; margin-left: auto; }

            /* Footer Fijo con Botón */
            .bottom-bar {
                position: fixed; bottom: 0; left: 0; width: 100%; background: white; padding: 15px;
                border-top: 1px solid #e2e8f0; display: flex; justify-content: center; z-index: 900;
            }
            .btn-finish {
                background: var(--primary); color: white; font-size: 1rem; font-weight: 600;
                padding: 12px 50px; border-radius: 30px; border: none; cursor: pointer;
                box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); transition: 0.2s;
            }
            .btn-finish:hover { transform: translateY(-2px); filter: brightness(1.1); }

            @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
            @media (max-width: 600px) { .option-group, .q-image { margin-left: 0; } .q-header { flex-direction: column; gap: 5px; } }
        </style>
    </head>
    <body>
        <div class="timer-bar">
            <div class="timer-content">
                <div class="quiz-title-mini">
                    <i class="fas fa-file-alt" style="color:var(--primary); margin-right:8px;"></i>
                    <?= htmlspecialchars($quizData['titulo']) ?>
                </div>
                <div id="timerDisplay" class="timer-clock">
                    <i class="far fa-clock"></i> --:--
                </div>
            </div>
        </div>

        <div class="container">
            
            <div class="progress-container">
                <div class="progress-info">
                    <span>Preguntas contestadas</span>
                    <span id="progressText">0 / <?= count($preguntasMostrar) ?></span>
                </div>
                <div class="progress-bar-bg">
                    <div id="progressBar" class="progress-bar-fill"></div>
                </div>
            </div>

            <form id="quizForm" action="resultados.php?quiz=<?= $quizId ?>" method="post">
                <?php foreach ($preguntasMostrar as $index => $pregunta): ?>
                    <div class="pregunta-card">
                        <div class="q-header">
                            <div class="q-num">#<?= $index + 1 ?></div>
                            <div class="q-text"><?= htmlspecialchars($pregunta['texto']) ?></div>
                        </div>

                        <?php if (!empty($pregunta['imagen'])): ?>
                            <img src="assets/images/<?= htmlspecialchars($pregunta['imagen']) ?>" class="q-image" alt="Imagen Referencia">
                        <?php endif; ?>

                        <div class="option-group">
                            <?php foreach ($pregunta['respuestas'] as $respuesta): ?>
                                <label class="option-label">
                                    <input type="radio" class="option-input js-option" 
                                           name="respuesta[<?= $pregunta['id'] ?>]" 
                                           value="<?= $respuesta['id'] ?>" 
                                           onchange="updateProgress()">
                                    
                                    <div class="option-content">
                                        <div class="option-circle"></div>
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

                <div style="height: 60px;"></div>

                <div class="bottom-bar">
                    <button type="submit" class="btn-finish" onclick="return confirm('¿Estás seguro de enviar tus respuestas?')">
                        <i class="fas fa-paper-plane"></i> Enviar Evaluación
                    </button>
                </div>
            </form>
        </div>

        <script>
            // --- TEMPORIZADOR ---
            let timeLeft = <?= $tiempoRestante ?>;
            const timerDisplay = document.getElementById('timerDisplay');
            const quizForm = document.getElementById('quizForm');

            function updateTimer() {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    alert("¡El tiempo se ha terminado! Se enviarán tus respuestas automáticamente.");
                    quizForm.submit();
                    return;
                }

                const h = Math.floor(timeLeft / 3600);
                const m = Math.floor((timeLeft % 3600) / 60);
                const s = timeLeft % 60;
                
                // Formato HH:MM:SS o MM:SS
                let timeString = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                if(h > 0) timeString = `${h}:${timeString}`;
                
                timerDisplay.innerHTML = `<i class="far fa-clock"></i> ${timeString}`;
                
                // Alerta visual últimos 5 minutos
                if(timeLeft < 300) {
                    timerDisplay.classList.add('danger');
                }
                timeLeft--;
            }
            const timerInterval = setInterval(updateTimer, 1000);
            updateTimer();

            // --- BARRA DE PROGRESO ---
            const totalQuestions = <?= count($preguntasMostrar) ?>;
            
            function updateProgress() {
                // Contar cuántos grupos de radio buttons tienen al menos uno marcado
                const answered = document.querySelectorAll('.pregunta-card:has(input:checked)').length;
                const percent = (answered / totalQuestions) * 100;
                
                document.getElementById('progressBar').style.width = percent + '%';
                document.getElementById('progressText').innerText = `${answered} / ${totalQuestions}`;
            }
        </script>
    </body>
    </html>

<?php
// ==========================================================
// VISTA: DASHBOARD ESTUDIANTE (LISTADO)
// ==========================================================
} else {
    // Obtener quizzes desde BD
    try {
        $stmt = $pdo->query("SELECT *, (SELECT COUNT(*) FROM preguntas WHERE quiz_id = quizzes.id) as cantidad_preguntas FROM quizzes ORDER BY id DESC");
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $quizzes = []; }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Portal del Estudiante</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            /* Estilos del Dashboard (Resumido) */
            :root { --primary: #4f46e5; --bg: #f8fafc; }
            body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; }
            .navbar { background: white; padding: 15px 30px; display: flex; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .container { max-width: 1100px; margin: 40px auto; padding: 20px; }
            .quiz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
            .quiz-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; transition: 0.2s; display: flex; flex-direction: column; }
            .quiz-card:hover { transform: translateY(-5px); }
            .status-badge { padding: 4px 8px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; display:inline-block; margin-bottom:10px;}
            .status-open { background: #dcfce7; color: #166534; }
            .status-closed { background: #f1f5f9; color: #64748b; }
            .status-disabled { background: #fee2e2; color: #991b1b; }
            .btn-card { display: block; text-align: center; background: var(--primary); color: white; padding: 10px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: auto; }
            .btn-disabled { background: #e2e8f0; color: #94a3b8; pointer-events: none; }
            .quiz-meta { font-size:0.85rem; color:#64748b; margin: 15px 0; border-top:1px solid #f1f5f9; padding-top:10px; }
            .quiz-meta div { margin-bottom: 4px; }
        </style>
    </head>
    <body>
        <nav class="navbar">
            <div style="font-weight:700; font-size:1.2rem; color:var(--primary);"><i class="fas fa-graduation-cap"></i> AulaVirtual</div>
            <div><?= htmlspecialchars($usuario['nombre']) ?> | <a href="logout.php" style="color:#ef4444; text-decoration:none;">Salir</a></div>
        </nav>
        
        <div class="container">
            <h2 style="margin-bottom:20px; color:#1e293b;">Evaluaciones Asignadas</h2>
            <?php if(empty($quizzes)): ?>
                <p style="color:#64748b; text-align:center; padding:40px;">No hay evaluaciones disponibles.</p>
            <?php else: ?>
                <div class="quiz-grid">
                    <?php foreach($quizzes as $quiz): 
                        $ahora = time();
                        $inicio = strtotime($quiz['fecha_inicio']);
                        $fin = strtotime($quiz['fecha_fin']);
                        $estado = 'open'; $btnTxt = 'Comenzar';
                        
                        if($quiz['activo'] == 0) { $estado = 'disabled'; $btnTxt = 'No disponible'; }
                        elseif($ahora < $inicio) { $estado = 'future'; $btnTxt = 'Abre pronto'; }
                        elseif($ahora > $fin) { $estado = 'closed'; $btnTxt = 'Cerrado'; }
                    ?>
                    <div class="quiz-card">
                        <div style="height:6px; background:<?= $quiz['color_primario']?>"></div>
                        <div style="padding:20px; flex-grow:1; display:flex; flex-direction:column;">
                            <div>
                                <span class="status-badge status-<?= $estado == 'disabled' ? 'disabled' : ($estado == 'future' ? 'closed' : $estado) ?>">
                                    <?= $estado == 'open' ? 'Disponible' : ($estado == 'disabled' ? 'Deshabilitado' : ucfirst($estado)) ?>
                                </span>
                                <h3 style="margin:0 0 10px 0; font-size:1.1rem; color:#1e293b;"><?= htmlspecialchars($quiz['titulo']) ?></h3>
                                <p style="font-size:0.9rem; color:#64748b; margin:0;"><?= htmlspecialchars(substr($quiz['descripcion'] ?? '', 0, 80)) ?>...</p>
                            </div>

                            <div class="quiz-meta">
                                <div><i class="fas fa-list"></i> <?= $quiz['cantidad_preguntas'] ?> Preguntas</div>
                                <div><i class="far fa-clock"></i> <?= $quiz['duracion_minutos'] ?> Minutos</div>
                                <div style="margin-top:8px; font-size:0.8rem;">
                                    📅 Inicio: <?= date('d/m H:i', $inicio) ?><br>
                                    🏁 Fin: <?= date('d/m H:i', $fin) ?>
                                </div>
                            </div>
                            
                            <a href="?quiz=<?= $quiz['id'] ?>" class="btn-card <?= $estado != 'open' ? 'btn-disabled' : '' ?>">
                                <?= $btnTxt ?>
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