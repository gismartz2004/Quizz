<?php
$pageTitle = 'Datos Previos';
include 'includes/header.php';
?>
<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh;">
    <div class="form-card">
        <div style="margin-bottom:20px; color:#4f46e5; font-weight:bold;">📋 Paso 1 de 2</div>
        <h2>Datos del Estudiante</h2>
        <p style="color:#64748b; font-size:0.9rem; margin-bottom:20px;">Completa esta información para iniciar la prueba <strong><?= htmlspecialchars($quizData['titulo']) ?></strong>.</p>
        <form method="POST">
            <input type="hidden" name="guardar_demograficos" value="1">
            
            <label>Edad</label>
            <input type="number" name="edad" required min="5" max="99" class="form-control">
            
            <label>Género</label>
            <select name="genero" required>
                <option value="">Selecciona...</option>
                <option value="Masculino">Masculino</option>
                <option value="Femenino">Femenino</option>
                <option value="Otro">Prefiero no decirlo</option>
            </select>

            <label>Residencia</label>
            <select name="residencia" required>
                <option value="">Selecciona...</option>
                <option value="Urbano">Urbano</option>
                <option value="Rural">Rural</option>
            </select>

            <label>Grado</label>
            <select name="grado" required>
                <option value="Decimo" selected>Décimo</option>
            </select>

            <label>Paralelo</label>
            <select name="paralelo" required>
                <option value="">Selecciona...</option>
                <?php foreach(range('A','H') as $letra): ?>
                    <option value="<?= $letra ?>"><?= $letra ?></option>
                <?php endforeach; ?>
            </select>

            <label>Jornada</label>
            <select name="jornada" required>
                <option value="">Selecciona...</option>
                <option value="Matutina">Matutina</option>
                <option value="Vespertina">Vespertina</option>
            </select>

            <label>Discapacidad</label>
            <select name="discapacidad" required>
                <option value="Ninguna">Ninguna</option>
                <option value="Auditiva">Auditiva</option>
                <option value="Motriz">Motriz</option>
                <option value="Otra">Otra</option>
            </select>

            <button type="submit" class="btn-block">Guardar y Comenzar</button>
        </form>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
