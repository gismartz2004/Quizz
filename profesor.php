<?php
session_start();
require 'db.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}
$usuario = $_SESSION['usuario'];

// 2. ACCIONES (Toggle / Delete)
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

// 3. Obtener Lista de Quizzes
$mis_quizzes = [];
try {
    $sql = "SELECT q.*, 
                   (SELECT COUNT(*) FROM preguntas WHERE quiz_id = q.id) as total_preguntas,
                   (SELECT COUNT(*) FROM resultados WHERE quiz_id = q.id) as total_respuestas
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Profesor | AulaVirtual</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-success: linear-gradient(135deg, #0cebeb 0%, #20e3b2 100%);
            --gradient-warning: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --bg-main: #f0f4f8;
            --text-primary: #1a202c;
            --text-secondary: #718096;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 20px 60px rgba(0, 0, 0, 0.12);
        }
        
        * {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background: var(--bg-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-primary);
            min-height: 100vh;
        }
        
        /* Header */
        .page-header {
            background: var(--gradient-primary);
            padding: 2rem 0;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4);
        }
        
        .page-header h4 {
            color: white;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .page-header .user-info {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-weight: 600;
        }
        
        /* Navigation Cards */
        .nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .nav-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            text-decoration: none;
            color: var(--text-primary);
            position: relative;
            overflow: hidden;
        }
        
        .nav-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-5px);
        }
        
        .nav-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .nav-card.primary::before { background: var(--gradient-primary); }
        .nav-card.success::before { background: var(--gradient-success); }
        .nav-card.warning::before { background: var(--gradient-warning); }
        
        .nav-card .icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
            color: white;
        }
        
        .nav-card.primary .icon { background: var(--gradient-primary); }
        .nav-card.success .icon { background: var(--gradient-success); }
        .nav-card.warning .icon { background: var(--gradient-warning); }
        
        .nav-card h5 {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        
        .nav-card p {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin: 0;
        }
        
        /* Quiz Cards */
        .quiz-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        
        .quiz-card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            position: relative;
        }
        
        .quiz-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-5px);
        }
        
        .quiz-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
        }
        
        .quiz-card.active::before {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }
        
        .quiz-card.inactive::before {
            background: linear-gradient(90deg, #94a3b8 0%, #64748b 100%);
        }
        
        .quiz-card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #f0f4f8;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.4rem 1rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
            text-decoration: none;
        }
        
        .status-badge.active {
            background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);
            color: #065f46;
            box-shadow: 0 4px 15px rgba(150, 230, 161, 0.4);
        }
        
        .status-badge.inactive {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #475569;
        }
        
        .quiz-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--text-primary);
        }
        
        .quiz-meta {
            display: flex;
            gap: 1.5rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .quiz-meta span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quiz-actions {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        
        .btn-action {
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-view {
            background: #667eea;
            color: white;
        }
        
        .btn-view:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-edit {
            background: #10b981;
            color: white;
        }
        
        .btn-edit:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-delete {
            background: #ef4444;
            color: white;
        }
        
        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .btn-primary-grad {
            background: var(--gradient-primary);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary-grad:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
            color: white;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        
        .modal-box {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header span:first-child {
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        .close-btn {
            cursor: pointer;
            font-size: 2rem;
            line-height: 1;
            opacity: 0.8;
        }
        
        .close-btn:hover {
            opacity: 1;
        }
        
        .modal-content {
            padding: 2rem;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .modal-loader {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }
        
        /* Alert */
        .alert-success {
            background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);
            color: #065f46;
            padding: 1rem 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(150, 230, 161, 0.3);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .quiz-card, .nav-card {
            animation: fadeInUp 0.5s ease-out;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
        
        .empty-state h5 {
            color: var(--text-secondary);
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="page-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h4><i class="fas fa-graduation-cap me-3"></i>Panel de Profesor</h4>
                <div class="user-info">
                    <i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($usuario['nombre']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="nav-grid">
        <a href="crear.php" class="nav-card primary">
            <div class="icon"><i class="fas fa-plus"></i></div>
            <h5>Crear Examen</h5>
            <p>Crea un nuevo quiz con preguntas</p>
        </a>
        <a href="analytics.php" class="nav-card success">
            <div class="icon"><i class="fas fa-chart-line"></i></div>
            <h5>Google Analytics</h5>
            <p>Dashboard avanzado de métricas</p>
        </a>
        <a href="lenguaje_d.php" class="nav-card success">
            <div class="icon"><i class="fas fa-chart-bar"></i></div>
            <h5>Reportes</h5>
            <p>Analiza resultados y métricas</p>
        </a>
        <a href="logout.php" class="nav-card warning">
            <div class="icon"><i class="fas fa-sign-out-alt"></i></div>
            <h5>Cerrar Sesión</h5>
            <p>Salir del sistema</p>
        </a>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted'): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle me-2"></i>Quiz eliminado correctamente
        </div>
    <?php endif; ?>

    <!-- Section Title -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold m-0">
            <i class="fas fa-list-alt me-2" style="color: #667eea;"></i>
            Mis Exámenes (<?= count($mis_quizzes) ?>)
        </h5>
    </div>

    <!-- Quiz Grid -->
    <?php if (empty($mis_quizzes)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h5>No hay exámenes creados</h5>
            <p class="text-secondary">Comienza creando tu primer quiz</p>
            <a href="crear.php" class="btn-primary-grad mt-3">
                <i class="fas fa-plus"></i> Crear Ahora
            </a>
        </div>
    <?php else: ?>
        <div class="quiz-grid">
            <?php foreach($mis_quizzes as $quiz): ?>
            <div class="quiz-card <?= $quiz['activo'] ? 'active' : 'inactive' ?>">
                <div class="quiz-card-header">
                    <a href="?toggle_id=<?= $quiz['id'] ?>" class="status-badge <?= $quiz['activo'] ? 'active' : 'inactive' ?>">
                        <?= $quiz['activo'] ? '✓ Activo' : '⏸ Pausado' ?>
                    </a>
                    <div class="quiz-title"><?= htmlspecialchars($quiz['titulo']) ?></div>
                    <div class="quiz-meta">
                        <span>
                            <i class="fas fa-question-circle"></i>
                            <?= $quiz['total_preguntas'] ?> preguntas
                        </span>
                        <span>
                            <i class="fas fa-clock"></i>
                            <?= $quiz['duracion_minutos'] ?> min
                        </span>
                        <span>
                            <i class="fas fa-users"></i>
                            <?= $quiz['total_respuestas'] ?? 0 ?> respuestas
                        </span>
                    </div>
                </div>
                <div class="quiz-actions">
                    <button onclick="cargarVistaPrevia(<?= $quiz['id'] ?>, '<?= htmlspecialchars($quiz['titulo']) ?>')" class="btn-action btn-view">
                        <i class="far fa-eye"></i> Ver
                    </button>
                    <a href="editar_quiz.php?id=<?= $quiz['id'] ?>" class="btn-action btn-edit">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                    <a href="?delete_id=<?= $quiz['id'] ?>" class="btn-action btn-delete" onclick="return confirm('¿Eliminar este quiz? Se borrará todo.')">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Preview -->
<div id="modalPreview" class="modal-overlay" onclick="if(event.target===this) cerrarModal()">
    <div class="modal-box">
        <div class="modal-header">
            <span id="modalTitle">Cargando...</span>
            <span class="close-btn" onclick="cerrarModal()">&times;</span>
        </div>
        <div class="modal-content" id="modalBody">
            <div class="modal-loader">
                <i class="fas fa-spinner fa-spin fa-2x"></i><br>
                Cargando preguntas...
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function cargarVistaPrevia(id, titulo) {
        const modal = document.getElementById('modalPreview');
        const title = document.getElementById('modalTitle');
        const body = document.getElementById('modalBody');
        
        modal.style.display = 'flex';
        title.innerText = titulo;
        body.innerHTML = '<div class="modal-loader"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Cargando contenido...</div>';
        
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

</body>
</html>