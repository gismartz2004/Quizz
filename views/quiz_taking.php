<?php
$pageTitle = 'Examen: ' . htmlspecialchars($quizData['titulo']);

// Extra styles dedicated to quiz execution
$extraStyles = "<style>
    :root { --primary: " . htmlspecialchars($quizData['color_primario']) . "; }
    body { padding-top: 80px; padding-bottom: 100px; }
</style>";

include 'includes/header.php';
?>

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

<div class="container" style="max-width: 800px; margin: 0 auto; padding: 20px;">
    
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

<?php
// PHP logic that needs to be passed to JS
$jsTimerLeft = $tiempoRestante;
$jsTotalQuestions = count($preguntasMostrar);
$jsQuizTitle = htmlspecialchars($quizData['titulo']);

// Creating the script content
ob_start();
?>
<script>
    let tabSwitchCount = 0;
    let timeAwayStart = 0;
    let totalTimeAway = 0;
    
    const inputIntentos = document.getElementById('intentosCopia');
    const inputTiempo = document.getElementById('tiempoFuera');

    // Detectar cambio de pestaña o minimizado
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
            overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; display:flex; justify-content:center; align-items:center; flex-direction:column; text-align:center; color:white; font-family:"Inter",sans-serif;';
            overlay.innerHTML = `
                <div style="background:#fff; color:#333; padding:30px; border-radius:12px; max-width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                    <div style="font-size:3rem; margin-bottom:10px;">🚨</div>
                    <h2 style="color:#ef4444; margin:0 0 10px 0;">¡Movimiento Detectado!</h2>
                    <p>Has salido de la pantalla del examen. Esta acción ha sido registrada.</p>
                    <p style="font-size:0.9rem; background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin:15px 0;">
                        Faltas acumuladas: <strong id="warnCount">0</strong>
                    </p>
                    <button onclick="document.getElementById('warnOverlay').style.display='none'" style="background:#4f46e5; color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-weight:bold;">Entendido, continuar</button>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        document.getElementById('warnCount').innerText = count;
        document.getElementById('warnOverlay').style.display = 'flex';
    }

    // --- TEMPORIZADOR ---
    let timeLeft = <?= $jsTimerLeft ?>;
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
        
        let timeString = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        if(h > 0) timeString = `${h}:${timeString}`;
        
        timerDisplay.innerHTML = `<i class="far fa-clock"></i> ${timeString}`;
        
        if(timeLeft < 300) {
            timerDisplay.classList.add('danger');
        }
        timeLeft--;
    }
    const timerInterval = setInterval(updateTimer, 1000);
    updateTimer();

    // --- BARRA DE PROGRESO ---
    const totalQuestions = <?= $jsTotalQuestions ?>;
    
    function updateProgress() {
        const answered = document.querySelectorAll('.pregunta-card:has(input:checked)').length;
        const percent = (answered / totalQuestions) * 100;
        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressText').innerText = `${answered} / ${totalQuestions}`;
    }
</script>
<?php
$extraScripts = ob_get_clean();
include 'includes/footer.php';
?>
