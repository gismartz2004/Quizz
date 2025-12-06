<?php
session_start();
require 'db.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}
$usuario = $_SESSION['usuario'];

// ==========================================================
// BLOQUE AJAX: ESTO SE EJECUTA SOLO AL DAR CLIC EN "VER"
// ==========================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'preview' && isset($_GET['id'])) {
    $quiz_id = $_GET['id'];
    
    // Traer preguntas
    $stmt = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Traer opciones para cada pregunta
    foreach($preguntas as &$p) {
        $stmtR = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
        $stmtR->execute([$p['id']]);
        $p['respuestas'] = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Generar HTML de respuesta para el modal
    if(empty($preguntas)) {
        echo '<p class="text-center text-muted">Este quiz no tiene preguntas guardadas.</p>';
    } else {
        foreach($preguntas as $i => $p) {
            echo '<div class="p-row">';
            echo '<strong>' . ($i+1) . '. ' . htmlspecialchars($p['texto']) . '</strong>';
            foreach($p['respuestas'] as $r) {
                $class = $r['es_correcta'] ? 'correct' : '';
                $icon = $r['es_correcta'] ? 'fas fa-check-circle' : 'far fa-circle';
                echo '<div class="p-opt ' . $class . '">';
                echo '<i class="' . $icon . '"></i> ' . htmlspecialchars($r['texto']);
                echo '</div>';
            }
            echo '</div>';
        }
    }
    exit; // Detenemos el script aquí para no cargar el resto de la página
}

// ==========================================================
// BLOQUE NORMAL: ACCIONES Y VISTA PRINCIPAL
// ==========================================================

// Acción: Toggle Estado
if (isset($_GET['toggle_id'])) {
    $id = $_GET['toggle_id'];
    $stmt = $pdo->prepare("SELECT activo FROM quizzes WHERE id = ?");
    $stmt->execute([$id]);
    $actual = $stmt->fetchColumn();
    $nuevo = $actual ? 0 : 1;
    $update = $pdo->prepare("UPDATE quizzes SET activo = ? WHERE id = ?");
    $update->execute([$nuevo, $id]);
    header("Location: profesor.php"); exit;
}

// Acción: Eliminar
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: profesor.php?msg=deleted"); exit;
    } catch (PDOException $e) {
        $error_msg = "Error al eliminar: " . $e->getMessage();
    }
}

// Obtener Lista de Quizzes (SOLO LA LISTA, SIN DETALLES)
$mis_quizzes = [];
try {
    $sql = "SELECT q.*, (SELECT COUNT(*) FROM preguntas WHERE quiz_id = q.id) as total_preguntas 
            FROM quizzes q 
            ORDER BY q.id DESC";
    $mis_quizzes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_db = "Error: " . $e->getMessage();
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
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px; color: #94a3b8; text-decoration: none; border-radius: 8px; margin-bottom: 5px; transition: 0.2s; }
        .nav-item:hover, .nav-item.active { background: var(--primary); color: white; }
        
        /* MAIN CONTENT */
        .main-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 30px; }
        
        /* GRID */
        .quiz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; margin-top: 30px; }
        .quiz-card { background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s; }
        .quiz-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .quiz-card.inactive { opacity: 0.85; border-left: 4px solid #fee2e2 !important; }

        .quiz-body { padding: 20px; flex-grow: 1; }
        .quiz-title { font-weight: 700; font-size: 1.1rem; margin-bottom: 5px; color: #1e293b; }
        .quiz-actions { padding: 15px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; }

        /* BADGES & BUTTONS */
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; cursor: pointer; text-decoration: none; float:right; }
        .status-on { background: #dcfce7; color: #166534; }
        .status-off { background: #fee2e2; color: #991b1b; }

        .btn-action { flex: 1; text-align: center; padding: 8px; border-radius: 6px; font-size: 0.85rem; text-decoration: none; font-weight: 600; transition: 0.2s; cursor: pointer; border:none; }
        .btn-view { background: white; border: 1px solid #cbd5e1; color: #334155; }
        .btn-view:hover { border-color: var(--primary); color: var(--primary); }
        .btn-edit { background: var(--primary); color: white; }
        .btn-delete { background: #fee2e2; color: #991b1b; width: 35px; flex:none; display:flex; align-items:center; justify-content:center; }
        .btn-delete:hover { background: #fecaca; }

        /* MODAL (Único y dinámico) */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal-box { background: white; width: 90%; max-width: 600px; max-height: 85vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; font-weight: 700; background: #fff; }
        .modal-content { padding: 20px; overflow-y: auto; min-height: 100px; }
        .modal-loader { text-align: center; padding: 40px; color: #94a3b8; }

        /* Estilos inyectados por AJAX */
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
        <a href="crear.php" class="nav-item"><i class="fas fa-plus-circle"></i> Nuevo Quiz</a>
        <a href="usuarios.php" class="nav-item"><i class="fas fa-user-graduate"></i> Estudiantes</a>
        <a href="resultados/dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i>Resultados</a>
        <a href="logout.php" class="nav-item" style="margin-top:auto; color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </nav>

    <div class="main-wrapper">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h1>Panel de Control</h1>
            <a href="crear.php" class="btn-edit btn-action" style="flex:none; padding: 10px 20px;"><i class="fas fa-plus"></i> Crear Nuevo</a>
        </div>
        
        <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted'): ?>
            <div style="padding:15px; background:#dcfce7; color:#166534; border-radius:8px; margin-top:20px;">✅ Quiz eliminado correctamente.</div>
        <?php endif; ?>

        <div class="quiz-grid">
            <?php foreach($mis_quizzes as $quiz): ?>
            <div class="quiz-card <?= $quiz['activo'] ? '' : 'inactive' ?>" style="border-top: 4px solid <?= $quiz['color_primario'] ?>;">
                <div class="quiz-body">
                    <a href="?toggle_id=<?= $quiz['id'] ?>" class="status-badge <?= $quiz['activo'] ? 'status-on' : 'status-off' ?>">
                        <?= $quiz['activo'] ? 'Activo' : 'Pausado' ?>
                    </a>
                    <div class="quiz-title"><?= htmlspecialchars($quiz['titulo']) ?></div>
                    <div style="font-size:0.85rem; color:#64748b; margin-bottom:15px;">
                        <?= $quiz['total_preguntas'] ?> Preguntas • <?= $quiz['duracion_minutos'] ?> min
                    </div>
                </div>
                <div class="quiz-actions">
                    <button onclick="cargarVistaPrevia(<?= $quiz['id'] ?>, '<?= htmlspecialchars($quiz['titulo']) ?>')" class="btn-action btn-view">
                        <i class="far fa-eye"></i> Ver
                    </button>
                    <a href="editar_quiz.php?id=<?= $quiz['id'] ?>" class="btn-action btn-edit">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                    <a href="?delete_id=<?= $quiz['id'] ?>" class="btn-action btn-delete" onclick="return confirm('¿Estás seguro? Se borrará todo.')">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="modalPreview" class="modal-overlay" onclick="if(event.target===this) cerrarModal()">
        <div class="modal-box">
            <div class="modal-header">
                <span id="modalTitle">Cargando...</span>
                <span class="close-btn" onclick="cerrarModal()">&times;</span>
            </div>
            <div class="modal-content" id="modalBody">
                <div class="modal-loader"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Cargando preguntas...</div>
            </div>
        </div>
    </div>

    <script>
        // Función AJAX para cargar preguntas solo cuando se necesitan
        function cargarVistaPrevia(id, titulo) {
            const modal = document.getElementById('modalPreview');
            const title = document.getElementById('modalTitle');
            const body = document.getElementById('modalBody');
            
            // 1. Mostrar modal con loader
            modal.style.display = 'flex';
            title.innerText = titulo;
            body.innerHTML = '<div class="modal-loader"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Cargando contenido...</div>';
            
            // 2. Petición al mismo archivo (Bloque AJAX de arriba)
            fetch(`profesor.php?ajax_action=preview&id=${id}`)
                .then(response => response.text())
                .then(html => {
                    body.innerHTML = html;
                })
                .catch(err => {
                    body.innerHTML = '<p class="text-center text-danger">Error al cargar contenido.</p>';
                });
        }

        function cerrarModal() {
            document.getElementById('modalPreview').style.display = 'none';
        }
    </script>
</body>
</html>