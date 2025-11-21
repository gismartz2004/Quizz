<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login.php');
    exit;
}

$usuario = $_SESSION['usuario'];

if (!isset($usuario['rol']) || $usuario['rol'] !== 'profesor') {
    die('<div style="padding:40px;text-align:center;color:#e74c3c;font-family:sans-serif;">
        <h2>🚫 Acceso denegado</h2>
        <p>Solo los profesores pueden acceder al dashboard.</p>
        <a href="../index.php" style="color:#3498db;text-decoration:underline;">Volver</a>
    </div>');
}

// Cargar quizzes
$quizzes = [];
if (file_exists('../quizzes')) {
    foreach (glob('../quizzes/*.json') as $archivo) {
        $data = json_decode(file_get_contents($archivo), true);
        if ($data) {
            $quizzes[] = $data;
        }
    }
}

// Cargar resultados
$resultados = [];
if (file_exists('../resultados/resultados_alumnos.json')) {
    $resultados = json_decode(file_get_contents('../resultados/resultados_alumnos.json'), true) ?: [];
}

// Estadísticas
$totalQuizzes = count($quizzes);
$totalResultados = count($resultados);
$promedioGeneral = $totalResultados > 0 
    ? round(array_sum(array_column($resultados, 'porcentaje')) / $totalResultados, 1)
    : 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Profesor</title>
    <link rel="stylesheet" href="../css/profesor.css">
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="logo">
                <h2>🎓 Panel Profesor</h2>
            </div>
            <nav class="menu">
                <a href="dashboard.php" class="menu-item active">
                    <span class="icon">📊</span>
                    <span class="text">Dashboard</span>
                </a>
                <a href="../crear.php" class="menu-item">
                    <span class="icon">➕</span>
                    <span class="text">Crear Quiz</span>
                </a>
                <a href="../logout.php" class="menu-item logout">
                    <span class="icon">🚪</span>
                    <span class="text">Cerrar sesión</span>
                </a>
            </nav>
        </aside>

        <main class="main-content">
            <header class="top-bar">
                <h1>📊 Dashboard de Rendimiento</h1>
            </header>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Quizzes Creados</h3>
                    <p class="stat-number"><?= $totalQuizzes ?></p>
                </div>
                <div class="stat-card">
                    <h3>Estudiantes Evaluados</h3>
                    <p class="stat-number"><?= $totalResultados ?></p>
                </div>
                <div class="stat-card">
                    <h3>Promedio General</h3>
                    <p class="stat-number"><?= $promedioGeneral ?>%</p>
                </div>
            </div>

            <div class="section">
                <h2>📋 Últimos Quizzes</h2>
                <?php if ($quizzes): ?>
                    <div class="quizzes-list">
                        <?php foreach (array_slice($quizzes, -5) as $quiz): ?>
                            <div class="quiz-item">
                                <h4><?= htmlspecialchars($quiz['titulo']) ?></h4>
                                <p><?= count($quiz['preguntas'] ?? []) ?> preguntas • <?= $quiz['valor_total'] ?> pts</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No has creado quizzes aún. <a href="../crear.php">Crear uno ahora</a>.</p>
                <?php endif; ?>
            </div>

            <div class="section">
                <h2>📈 Últimos Resultados</h2>
                <?php if ($resultados): ?>
                    <div class="resultados-list">
                        <?php foreach (array_slice(array_reverse($resultados), 0, 5) as $res): ?>
                            <div class="resultado-item">
                                <div>
                                    <strong><?= htmlspecialchars($res['usuario_nombre']) ?></strong>
                                    <span class="quiz-title"><?= htmlspecialchars($res['quiz_titulo']) ?></span>
                                </div>
                                <span class="porcentaje <?= $res['porcentaje'] >= 70 ? 'alta' : 'baja' ?>">
                                    <?= $res['porcentaje'] ?>%
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No hay resultados aún.</p>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>