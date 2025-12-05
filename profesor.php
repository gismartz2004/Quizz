<?php
session_start();
require 'db.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}
$usuario = $_SESSION['usuario'];

// 2. ACCIONES (Toggle Estado y Eliminar)
if (isset($_GET['toggle_id'])) {
    $id = $_GET['toggle_id'];
    $stmt = $pdo->prepare("SELECT activo FROM quizzes WHERE id = ?");
    $stmt->execute([$id]);
    $actual = $stmt->fetchColumn();
    $nuevo = $actual ? 0 : 1;
    $update = $pdo->prepare("UPDATE quizzes SET activo = ? WHERE id = ?");
    $update->execute([$nuevo, $id]);
    header("Location: profesor.php");
    exit;
}

// LÓGICA PARA ELIMINAR QUIZ
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    try {
        // Eliminar quiz (la BD se encarga de borrar preguntas y respuestas si está configurada con CASCADE, 
        // si no, borraría manualmente todo lo relacionado, pero asumimos integridad referencial básica)
        $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: profesor.php?msg=deleted");
        exit;
    } catch (PDOException $e) {
        $error_msg = "Error al eliminar: " . $e->getMessage();
    }
}

// 3. OBTENER QUIZZES
$mis_quizzes = [];
try {
    $sql = "SELECT q.*, (SELECT COUNT(*) FROM preguntas WHERE quiz_id = q.id) as total_preguntas 
            FROM quizzes q 
            ORDER BY q.id DESC";
    $mis_quizzes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_db = "Error: " . $e->getMessage();
}

// Función auxiliar para vista previa
function obtenerPreguntasQuiz($pdo, $quiz_id) {
    $stmt = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($preguntas as &$p) {
        $stmtR = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
        $stmtR->execute([$p['id']]);
        $p['respuestas'] = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    }
    return $preguntas;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Profesor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5; --bg-body: #f8fafc; --bg-sidebar: #0f172a;
            --text-main: #334155; --card-bg: #ffffff; --border-color: #e2e8f0;
            --sidebar-width: 260px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; }
        
        /* SIDEBAR */
        .sidebar { width: var(--sidebar-width); background: var(--bg-sidebar); color: white; position: fixed; height: 100vh; padding: 24px; z-index: 50; }
        .logo-area { display: flex; align-items: center; gap: 10px; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px; color: #94a3b8; text-decoration: none; border-radius: 8px; margin-bottom: 5px; transition: 0.2s; }
        .nav-item:hover, .nav-item.active { background: var(--primary); color: white; }
        
        /* MAIN CONTENT */
        .main-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 30px; }
        
        /* CARDS */
        .quiz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; margin-top: 30px; }
        .quiz-card { background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s; position: relative; }
        .quiz-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        
        /* Estado Visual */
        .quiz-card.inactive { opacity: 0.85; border-left: 4px solid #fee2e2 !important; }
        
        .quiz-body { padding: 20px; flex-grow: 1; }
        .quiz-header-card { display: flex; justify-content: space-between; margin-bottom: 10px; align-items: center; }
        
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; cursor: pointer; text-decoration: none; }
        .status-on { background: #dcfce7; color: #166534; }
        .status-off { background: #fee2e2; color: #991b1b; }
        
        .quiz-title { font-weight: 700; font-size: 1.1rem; margin-bottom: 5px; color: #1e293b; }
        .quiz-desc { font-size: 0.85rem; color: #64748b; margin-bottom: 15px; }
        .quiz-stats { display: flex; gap: 15px; font-size: 0.8rem; color: #64748b; border-top: 1px solid #f1f5f9; padding-top: 10px; }
        
        .quiz-actions { padding: 15px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; }
        .btn-action { flex: 1; text-align: center; padding: 8px; border-radius: 6px; font-size: 0.85rem; text-decoration: none; font-weight: 600; transition: 0.2s; border:none; cursor: pointer; }
        .btn-view { background: white; border: 1px solid #cbd5e1; color: #334155; }
        .btn-view:hover { border-color: var(--primary); color: var(--primary); }
        .btn-edit { background: var(--primary); color: white; }
        .btn-delete { background: #fee2e2; color: #991b1b; flex: 0 0 40px; display: flex; align-items: center; justify-content: center; }
        .btn-delete:hover { background: #fecaca; }

        /* MODAL */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal-box { background: white; width: 90%; max-width: 600px; max-height: 85vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; font-weight: 700; }
        .modal-content { padding: 20px; overflow-y: auto; }
        .close-btn { cursor: pointer; font-size: 1.5rem; }
        
        .p-row { margin-bottom: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .p-opt { padding: 5px 10px; background: #f8fafc; margin-top: 5px; border-radius: 4px; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .p-opt.correct { background: #dcfce7; color: #15803d; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div style="margin-bottom:40px; font-size:1.2rem; font-weight:700;">
            <i class="fas fa-graduation-cap" style="color:#818cf8"></i> Profesor
        </div>
        <a href="profesor.php" class="nav-item active"><i class="fas fa-th-large"></i> Mis Quizzes</a>
        <a href="usuarios.php" class="nav-item"><i class="fas fa-user-graduate"></i> Estudiantes</a>
        <a href="crear.php" class="nav-item"><i class="fas fa-plus-circle"></i> Nuevo Quiz</a>
        <a href="resultados/dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i> Resultados</a>
        <a href="logout.php" class="nav-item" style="margin-top:auto; color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </nav>

    <div class="main-wrapper">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
            <h1 style="font-size:1.8rem; color:#1e293b;">Panel de Control</h1>
            <div style="text-align:right;">
                <div style="font-weight:700;"><?= htmlspecialchars($usuario['nombre']) ?></div>
                <div style="font-size:0.8rem; color:#64748b;">Administrador</div>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="color:#334155;">Mis Evaluaciones</h3>
            <a href="crear.php" class="btn-edit" style="text-decoration:none; padding:10px 20px; border-radius:8px;">
                <i class="fas fa-plus"></i> Crear Nuevo
            </a>
        </div>
        
        <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted'): ?>
            <div style="padding:15px; background:#dcfce7; color:#166534; border-radius:8px; margin-top:20px;">✅ Quiz eliminado correctamente.</div>
        <?php endif; ?>

        <?php if(empty($mis_quizzes)): ?>
            <div style="text-align:center; padding:50px; background:white; border-radius:12px; margin-top:20px; border:2px dashed #e2e8f0;">
                <h3 style="color:#94a3b8;">No hay quizzes creados</h3>
            </div>
        <?php else: ?>
            <div class="quiz-grid">
                <?php foreach($mis_quizzes as $quiz): 
                    $modalId = 'modal_' . $quiz['id'];
                    $preguntasPreview = obtenerPreguntasQuiz($pdo, $quiz['id']);
                    $isActive = $quiz['activo'] == 1;
                ?>
                <div class="quiz-card <?= $isActive ? '' : 'inactive' ?>" style="border-top: 4px solid <?= $quiz['color_primario'] ?>;">
                    <div class="quiz-body">
                        <div class="quiz-header-card">
                            <span style="font-size:0.75rem; color:#94a3b8;">ID: #<?= $quiz['id'] ?></span>
                            <a href="?toggle_id=<?= $quiz['id'] ?>" class="status-badge <?= $isActive ? 'status-on' : 'status-off' ?>">
                                <?= $isActive ? 'Activo' : 'Pausado' ?>
                            </a>
                        </div>
                        
                        <div class="quiz-title"><?= htmlspecialchars($quiz['titulo']) ?></div>
                        <div class="quiz-desc"><?= htmlspecialchars(substr($quiz['descripcion'] ?? '', 0, 80)) ?>...</div>
                        
                        <div class="quiz-stats">
                            <span><i class="fas fa-list"></i> <?= $quiz['total_preguntas'] ?> Pregs</span>
                            <span><i class="far fa-clock"></i> <?= $quiz['duracion_minutos'] ?> min</span>
                        </div>
                    </div>
                    <div class="quiz-actions">
                        <button onclick="document.getElementById('<?= $modalId ?>').style.display='flex'" class="btn-action btn-view">
                            <i class="far fa-eye"></i> Ver
                        </button>
                        <a href="editar_quiz.php?id=<?= $quiz['id'] ?>" class="btn-action btn-edit">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <a href="?delete_id=<?= $quiz['id'] ?>" class="btn-action btn-delete" onclick="return confirm('¿Estás seguro de eliminar este quiz permanentemente? Se perderán los resultados de los alumnos.')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                </div>

                <div id="<?= $modalId ?>" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
                    <div class="modal-box">
                        <div class="modal-header">
                            <span><?= htmlspecialchars($quiz['titulo']) ?></span>
                            <span class="close-btn" onclick="document.getElementById('<?= $modalId ?>').style.display='none'">&times;</span>
                        </div>
                        <div class="modal-content">
                            <div style="background:#f1f5f9; padding:10px; border-radius:6px; margin-bottom:20px; font-size:0.9rem;">
                                <?= htmlspecialchars($quiz['descripcion']) ?>
                            </div>
                            
                            <?php if(empty($preguntasPreview)): ?>
                                <p class="text-center text-muted">Este quiz no tiene preguntas guardadas.</p>
                            <?php else: ?>
                                <?php foreach($preguntasPreview as $i => $p): ?>
                                <div class="p-row">
                                    <strong><?= ($i+1).'. '.htmlspecialchars($p['texto']) ?></strong>
                                    <?php foreach($p['respuestas'] as $r): ?>
                                        <div class="p-opt <?= $r['es_correcta'] ? 'correct' : '' ?>">
                                            <i class="<?= $r['es_correcta'] ? 'fas fa-check-circle' : 'far fa-circle' ?>"></i>
                                            <?= htmlspecialchars($r['texto']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>