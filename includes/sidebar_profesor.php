<nav class="sidebar" id="sidebar">
    <div class="logo-area">
        <i class="fas fa-graduation-cap fa-lg"></i>
        <span style="font-weight: 700; font-size: 1.2rem;">Profesor</span>
    </div>
    <div style="display: flex; flex-direction: column; gap: 5px;">
        <a href="<?php echo isset($base_path) ? $base_path : ''; ?>profesor.php" class="nav-item <?php echo (isset($page) && $page == 'quizzes') ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Mis Quizzes
        </a>
        <a href="<?php echo isset($base_path) ? $base_path : ''; ?>usuarios.php" class="nav-item <?php echo (isset($page) && $page == 'usuarios') ? 'active' : ''; ?>">
            <i class="fas fa-user-graduate"></i> Estudiantes
        </a>
        <a href="<?php echo isset($base_path) ? $base_path : ''; ?>crear.php" class="nav-item <?php echo (isset($page) && $page == 'crear') ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i> Nuevo Quiz
        </a>
        <a href="<?php echo isset($base_path) ? $base_path : ''; ?>lenguaje_d.php" class="nav-item <?php echo (isset($page) && $page == 'resultados') ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i> Resultados
        </a>
        <a href="<?php echo isset($base_path) ? $base_path : ''; ?>logout.php" class="nav-item logout" style="margin-top: 20px; color: #ef4444;">
            <i class="fas fa-sign-out-alt"></i> Salir
        </a>
    </div>
</nav>
