<?php
$pageTitle = 'Examen: ' . htmlspecialchars($quizData['titulo']);

// Extra styles for this page
$extraStyles = "
<style>
    :root { --primary: " . htmlspecialchars($quizData['color_primario']) . "; }
    body { padding-top: 80px; padding-bottom: 100px; }
    .q-text { 
        word-wrap: break-word; 
        overflow-wrap: break-word; 
        word-break: break-word; 
    }
    .pregunta-card {
        overflow: visible;
        height: auto;
    }
</style>
";

include 'includes/header.php';
?>

<!-- TOP TIMER BAR -->
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

<!-- MAIN CONTAINER -->
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 20px;">
    
    <!-- PROGRESS BAR -->
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

        <input type="hidden" name="intentos_copia" id="intentosCopia" value="0">
        <input type="hidden" name="tiempo_fuera_segundos" id="tiempoFuera" value="0">

        <?php foreach ($preguntasMostrar as $index => $pregunta): ?>
            <div class="pregunta-card">

                <div class="q-header">
                    <div class="q-num">#<?= $index + 1 ?></div>
                    <div class="q-text"><?= htmlspecialchars($pregunta['texto']) ?></div>
                </div>

                <?php if (!empty($pregunta['imagen'])): 
                    $imgSrc = (strpos($pregunta['imagen'], 'http') === 0) ? $pregunta['imagen'] : "assets/images/" . $pregunta['imagen'];
                ?>
                    <img src="<?= htmlspecialchars($imgSrc) ?>" class="q-image" alt="Imagen Referencia">
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

                                <?php if (!empty($respuesta['imagen'])): 
                                    $optImgSrc = (strpos($respuesta['imagen'], 'http') === 0) ? $respuesta['imagen'] : "assets/images/" . $respuesta['imagen'];
                                ?>
                                    <img src="<?= htmlspecialchars($optImgSrc) ?>" class="option-img">
                                <?php endif; ?>

                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php 
                // Robust boolean check to handle different DB driver outputs (true, 'true', 't', 1, '1')
                $rj = $pregunta['requiere_justificacion'] ?? false;
                $showJustification = ($rj === true || $rj === 'true' || $rj === 't' || $rj == 1 || $rj === '1' || $rj === 'on');
                
                if ($showJustification): 
                ?>
                    <div class="justificacion-container" style="margin-top: 15px; width: 100%; clear: both;">
                        <label style="display:block; font-weight:600; margin-bottom:5px; color:#475569;">
                            <?= !empty($pregunta['texto_justificacion']) ? htmlspecialchars($pregunta['texto_justificacion']) : '¿Por qué elegiste esta respuesta? Justifica:' ?>
                        </label>
                        <textarea 
                            name="justificacion[<?= $pregunta['id'] ?>]" 
                            class="form-control" 
                            rows="2" 
                            placeholder="Escribe tu justificación aquí..."
                            style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"
                        ></textarea>
                    </div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>

        <div style="height: 60px;"></div>

        <!-- SUBMIT BUTTON -->
        <div class="bottom-bar">
            <button type="button" id="submitQuizBtn" class="btn-finish">
                <i class="fas fa-paper-plane"></i> Enviar Evaluación
            </button>
        </div>

    </form>
</div>

<!-- RETRY OVERLAY (HIDDEN BY DEFAULT) -->
<div id="retry-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:100000; flex-direction:column; justify-content:center; align-items:center; text-align:center; color:white; padding:20px;">
    <div style="font-size:4rem; color:#fbbc04; margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i></div>
    <h2 style="margin:0 0 10px 0;">El servidor está muy ocupado</h2>
    <p style="font-size:1.1rem; max-width:500px; line-height:1.5; color:#cbd5e1;">No te preocupes, tus respuestas están seguras en este dispositivo. Estamos intentando enviarlas de nuevo automáticamente...</p>
    <div id="retry-timer" style="font-weight:bold; font-size:1.2rem; margin-top:20px; background:rgba(255,255,255,0.1); padding:10px 20px; border-radius:50px;">Reintentando en 5s...</div>
    <button onclick="sendQuizData()" style="margin-top:30px; background:#4f46e5; color:white; border:none; padding:12px 30px; border-radius:8px; cursor:pointer; font-weight:bold;">Intentar ahora mismo</button>
</div>

<!-- SUBMISSION OVERLAY -->
<div id="submission-overlay"
     style="display:none; position:fixed; top:0; left:0; width:100%;
            height:100%; background:rgba(255,255,255,0.95); z-index:99999;
            flex-direction:column; justify-content:center; align-items:center;
            text-align:center;">

    <div style="width: 50px; height: 50px; border: 5px solid #e2e8f0;
                border-top: 5px solid var(--primary); border-radius: 50%;
                animation: spin 1s linear infinite; margin-bottom: 20px;">
    </div>

    <h2 style="color: #1e293b; margin: 0;">Enviando respuestas...</h2>
    <p style="color: #64748b;">Por favor espera, no cierres la página.</p>
</div>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php
// Values for JS
$jsTimerLeft = $tiempoRestante;
$jsTotalQuestions = count($preguntasMostrar);
$jsQuizTitle = htmlspecialchars($quizData['titulo']);
$jsUserId = $usuario['id']; // Inject User ID

ob_start();
?>

<script>
    let tabSwitchCount = 0;
    let timeAwayStart = 0;
    let totalTimeAway = 0;

    const inputIntentos = document.getElementById('intentosCopia');
    const inputTiempo = document.getElementById('tiempoFuera');
    const quizForm = document.getElementById('quizForm');

    // Detect tab changes
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            tabSwitchCount++;
            timeAwayStart = new Date().getTime();
            inputIntentos.value = tabSwitchCount;
            document.title = "⚠️ ¡REGRESA AL EXAMEN!";
        } else {
            let timeAwayEnd = new Date().getTime();
            let duration = (timeAwayEnd - timeAwayStart) / 1000;
            totalTimeAway += duration;
            inputTiempo.value = Math.floor(totalTimeAway);
            document.title = "Examen: <?= $jsQuizTitle ?>";
            mostrarAdvertencia(tabSwitchCount);
        }
    });

    function mostrarAdvertencia(count) {
        if (!document.getElementById('warnOverlay')) {
            const overlay = document.createElement('div');
            overlay.id = 'warnOverlay';
            overlay.style.cssText = `
                position:fixed; top:0; left:0; width:100%; height:100%;
                background:rgba(0,0,0,0.85); z-index:9999; display:flex;
                justify-content:center; align-items:center;
                flex-direction:column; text-align:center;
                color:white; font-family:"Inter",sans-serif;
            `;

            overlay.innerHTML = `
                <div style="background:#fff; color:#333; padding:30px;
                            border-radius:12px; max-width:400px;
                            box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                    <div style="font-size:3rem; margin-bottom:10px;">🚨</div>
                    <h2 style="color:#ef4444; margin:0 0 10px 0;">¡Movimiento Detectado!</h2>
                    <p>Has salido de la pantalla del examen. Esta acción ha sido registrada.</p>
                    <p style="font-size:0.9rem; background:#fee2e2; color:#991b1b;
                              padding:10px; border-radius:6px; margin:15px 0;">
                        Faltas acumuladas: <strong id="warnCount">0</strong>
                    </p>
                    <button onclick="document.getElementById('warnOverlay').style.display='none'"
                            style="background:#4f46e5; color:white; border:none; padding:10px 20px;
                                   border-radius:6px; cursor:pointer; font-weight:bold;">
                        Entendido, continuar
                    </button>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        document.getElementById('warnCount').innerText = count;
        document.getElementById('warnOverlay').style.display = 'flex';
    }

    // TIMER
    let timeLeft = <?= $jsTimerLeft ?>;
    const timerDisplay = document.getElementById('timerDisplay');

    function updateTimer() {
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            alert("¡El tiempo se ha terminado! Se enviarán tus respuestas automáticamente.");

            document.getElementById('submission-overlay').style.display = 'flex';
            setTimeout(() => quizForm.submit(), 100);
            return;
        }

        const h = Math.floor(timeLeft / 3600);
        const m = Math.floor((timeLeft % 3600) / 60);
        const s = timeLeft % 60;

        let timeString = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        if (h > 0) timeString = `${h}:${timeString}`;

        timerDisplay.innerHTML = `<i class="far fa-clock"></i> ${timeString}`;

        if (timeLeft < 300) timerDisplay.classList.add('danger');

        timeLeft--;
    }

    const timerInterval = setInterval(updateTimer, 1000);
    updateTimer();

    // PROGRESS BAR
    const totalQuestions = <?= $jsTotalQuestions ?>;

    function updateProgress() {
        const answered = document.querySelectorAll('.pregunta-card:has(input:checked)').length;
        const percent = (answered / totalQuestions) * 100;
        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressText').innerText = `${answered} / ${totalQuestions}`;
    }

    // SUBMISSION LOGIC WITH RETRIES
    const submitBtn = document.getElementById('submitQuizBtn');
    const submissionOverlay = document.getElementById('submission-overlay');
    const retryOverlay = document.getElementById('retry-overlay');
    const retryTimerDisp = document.getElementById('retry-timer');

    let retrySeconds = 5;
    let retryInterval = null;

    submitBtn.addEventListener('click', function() {
        if (confirm('¿Estás seguro de enviar tus respuestas?')) {
            sendQuizData();
        }
    });

    async function sendQuizData() {
        submissionOverlay.style.display = 'flex';
        retryOverlay.style.display = 'none';
        if (retryInterval) clearInterval(retryInterval);

        const formData = new FormData(quizForm);

        try {
            const response = await fetch(quizForm.action, {
                method: 'POST',
                body: formData
            });

            const html = await response.text();

            if (response.ok) {
                // SUCCESS (or at least valid HTML response)
                localStorage.removeItem(STORAGE_KEY);
                document.open();
                document.write(html);
                document.close();
                window.scrollTo(0,0);
            } else {
                console.warn('Server Error:', response.status);
                // Si el servidor devolvió un error (500, etc) pero envió HTML, mostrarlo
                if (html.length > 50) {
                     localStorage.removeItem(STORAGE_KEY);
                     document.open();
                     document.write(html);
                     document.close();
                } else {
                     throw new Error('Server returned empty error');
                }
            }
        } catch (error) {
            console.error('Submission Error:', error);
            showRetryOverlay();
        }
    }

    function showRetryOverlay() {
        submissionOverlay.style.display = 'none';
        retryOverlay.style.display = 'flex';
        retrySeconds = 5;
        
        retryInterval = setInterval(() => {
            retrySeconds--;
            retryTimerDisp.innerText = `Reintentando en ${retrySeconds}s...`;
            if (retrySeconds <= 0) {
                sendQuizData();
            }
        }, 1000);
    }

    // KEEP ALIVE SESSION
    // Ping al servidor cada 5 minutos (300000 ms) para evitar que la sesión caduque
    setInterval(() => {
        fetch('keep_alive.php').then(r => console.log('Session refreshed'));
    }, 300000);

    // ======================================================
    // PERSISTENCIA LOCAL (LOCAL STORAGE)
    // ======================================================
    const USER_ID = <?= $jsUserId ?>;
    const QUIZ_ID = <?= $quizId ?>;
    
    // Nueva llave única por usuario
    const STORAGE_KEY = `quiz_answers_${QUIZ_ID}_${USER_ID}`;
    const OLD_STORAGE_KEY = `quiz_answers_${QUIZ_ID}`; // La llave antigua insegura

    // 1. Cargar respuestas guardadas
    function loadSavedAnswers() {
        // Migración/Limpieza: Si existe una llave antigua genérica y NO es mía (o por seguridad), la borramos
        // Para evitar borrar datos útiles, solo borramos la antigua si NO tenemos datos nuevos
        // O mejor: simplemente ignoramos la antigua y empezamos a usar la nueva.
        
        // Limpieza proactiva de la llave antigua para evitar confusión en el futuro
        if (localStorage.getItem(OLD_STORAGE_KEY)) {
            // Opcional: Podríamos intentar migrarla si creemos que es del usuario actual, 
            // pero es arriesgado. Mejor empezar limpio para garantizar privacidad.
            localStorage.removeItem(OLD_STORAGE_KEY);
        }

        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            const answers = JSON.parse(saved);
            for (const [questionId, optionId] of Object.entries(answers)) {
                // Selector para el radio button específico
                const radio = document.querySelector(`input[name="respuesta[${questionId}]"][value="${optionId}"]`);
                if (radio) {
                    radio.checked = true;
                }
            }
            updateProgress(); // Actualizar barra de progreso
        }
    }

    // 2. Guardar respuestas al cambiar
    document.querySelectorAll('.js-option').forEach(input => {
        input.addEventListener('change', function() {
            const questionId = this.name.match(/\[(\d+)\]/)[1];
            const optionId = this.value;
            
            // Leer actual
            let currentAnswers = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            currentAnswers[questionId] = optionId;
            
            // Guardar
            localStorage.setItem(STORAGE_KEY, JSON.stringify(currentAnswers));
        });
    });

    // Iniciar carga
    loadSavedAnswers();
</script>

<?php
$extraScripts = ob_get_clean();
include 'includes/footer.php';
?>
