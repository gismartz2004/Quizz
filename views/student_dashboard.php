<?php
$pageTitle = 'Portal del Estudiante';
include 'includes/header.php';
?>

<nav class="navbar">
    <div style="font-weight:700; font-size:1.2rem; color:var(--primary);"><i class="fas fa-graduation-cap"></i> AulaVirtual</div>
    <div><?= htmlspecialchars($usuario['nombre']) ?> | <a href="logout.php" style="color:#ef4444; text-decoration:none;">Salir</a></div>
</nav>

<div class="container" style="max-width: 1100px; margin: 40px auto; padding: 20px;">
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

<?php include 'includes/footer.php'; ?>
