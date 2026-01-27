<?php
session_start();
require 'db.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

$page = 'nne';

// 2. CARGAR QUIZZES NNE
try {
    $stmt = $pdo->query("SELECT *, (SELECT COUNT(*) FROM preguntas WHERE quiz_id = quizzes.id) as cantidad_preguntas 
                         FROM quizzes 
                         WHERE COALESCE(es_nne, false) = true
                         ORDER BY id DESC");
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 3. OBTENER URL BASE AUTOMÁTICAMENTE
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$base_url = "$protocol://$host";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exámenes Privados (NNE) | Panel Profesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/profesor.css">
</head>
<body class="bg-light">

    <?php include 'includes/sidebar_profesor.php'; ?>

    <main class="main-content">
        <header class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 fw-bold mb-0">Exámenes Privados (NNE)</h1>
                <p class="text-muted">Gestiona los enlaces directos para estudiantes con necesidades especiales.</p>
            </div>
            <a href="crear.php" class="btn btn-primary rounded-pill px-4">
                <i class="fas fa-plus me-2"></i>Crear Nuevo
            </a>
        </header>

        <?php if(empty($quizzes)): ?>
            <div class="card border-0 shadow-sm p-5 text-center">
                <div class="mb-3 text-muted"><i class="fas fa-lock fa-3x opacity-25"></i></div>
                <h5 class="text-muted">No tienes exámenes privados configurados aún.</h5>
                <p class="small text-muted">Crea un examen nuevo o edita uno existente y marca la casilla "Examen Privado (Solo NNE)".</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach($quizzes as $quiz): 
                    $public_url = "$base_url/index.php?quiz=" . $quiz['id'];
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0 shadow-sm h-100" style="border-top: 4px solid <?= $quiz['color_primario'] ?> !important;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h5 class="card-title fw-bold mb-0"><?= htmlspecialchars($quiz['titulo']) ?></h5>
                                <span class="badge bg-warning text-dark">NNE</span>
                            </div>
                            <p class="text-muted small mb-4"><?= htmlspecialchars(substr($quiz['descripcion'] ?? '', 0, 100)) ?>...</p>
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Enlace de Acceso Directo</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control bg-light" value="<?= $public_url ?>" readonly id="url-<?= $quiz['id'] ?>">
                                    <button class="btn btn-outline-primary" type="button" onclick="copyLink(<?= $quiz['id'] ?>)">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <a href="editar_quiz.php?id=<?= $quiz['id'] ?>" class="btn btn-light btn-sm flex-grow-1">
                                    <i class="fas fa-edit me-1"></i>Editar
                                </a>
                                <a href="<?= $public_url ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyLink(id) {
            const copyText = document.getElementById("url-" + id);
            copyText.select();
            copyText.setSelectionRange(0, 99999); 
            navigator.clipboard.writeText(copyText.value);
            
            // Efecto visual simple
            const btn = event.currentTarget;
            const icon = btn.querySelector('i');
            icon.className = 'fas fa-check text-success';
            setTimeout(() => { icon.className = 'fas fa-copy'; }, 2000);
        }
    </script>
</body>
</html>
