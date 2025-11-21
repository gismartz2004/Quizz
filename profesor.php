<?php
session_start();

// Redirigir si no está logueado
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'];

// Verificar que sea profesor
if (!isset($usuario['rol']) || $usuario['rol'] !== 'profesor') {
    die('<div style="padding:40px;text-align:center;color:#e74c3c;font-family:sans-serif;">
        <h2>🚫 Acceso denegado</h2>
        <p>Solo los profesores pueden acceder a este panel.</p>
        <a href="../index.php" style="color:#3498db;text-decoration:underline;">Volver al inicio</a>
    </div>');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel del Profesor</title>
    <link rel="stylesheet" href="css/profesor.css">
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <h2>🎓 Panel Profesor</h2>
            </div>
            <nav class="menu">
                <a href="resultados/dashboard.php" class="menu-item">
                    <span class="icon">📊</span>
                    <span class="text">Dashboard</span>
                </a>
                <a href="crear.php" class="menu-item">
                    <span class="icon">➕</span>
                    <span class="text">Crear Quiz</span>
                </a>
                <a href="logout.php" class="menu-item logout">
                    <span class="icon">🚪</span>
                    <span class="text">Cerrar sesión</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <h1>Bienvenido, <?= htmlspecialchars($usuario['nombre']) ?> 👋</h1>
            </header>

            <div class="welcome-card">
                <h2>👋 Hola, Profesor</h2>
                <p>Desde este panel puedes crear nuevos quizzes o ver el rendimiento de tus estudiantes en tiempo real.</p>
                <div class="quick-actions">
                    <a href="crear.php" class="btn-primary">➕ Crear nuevo quiz</a>
                    <a href="./resultados/dashboard.php" class="btn-secondary">📊 Ver dashboard</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>