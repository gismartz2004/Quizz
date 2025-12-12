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
// ACCIONES (Toggle / Delete)
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

// Obtener Lista de Quizzes
$mis_quizzes = [];
try {
    $sql = "SELECT q.*, (SELECT COUNT(*) FROM preguntas WHERE quiz_id = q.id) as total_preguntas 
            FROM quizzes q 
            ORDER BY q.id DESC";
    $mis_quizzes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_db = "Error: " . $e->getMessage();
}

$pageTitle = 'Panel Profesor';
include 'includes/header.php';
?>

<?php $page = 'quizzes'; include 'includes/sidebar_profesor.php'; ?>

<div class="main-wrapper">
    <div class="header-mobile">
        <button class="toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
        <h2 style="margin:0;">AulaVirtual</h2>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
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
    function toggleSidebar() { 
        document.getElementById('sidebar').classList.toggle('open'); 
    }

    function cargarVistaPrevia(id, titulo) {
        const modal = document.getElementById('modalPreview');
        const title = document.getElementById('modalTitle');
        const body = document.getElementById('modalBody');
        
        modal.style.display = 'flex';
        title.innerText = titulo;
        body.innerHTML = '<div class="modal-loader"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Cargando contenido...</div>';
        
        // Use the new API endpoint
        fetch(`api/profesor_api.php?action=preview&id=${id}`)
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

<?php include 'includes/footer.php'; ?>