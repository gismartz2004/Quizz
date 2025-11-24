<?php
session_start();

// 1. SEGURIDAD
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'];

if (!isset($usuario['rol']) || $usuario['rol'] !== 'profesor') {
    die('<div style="padding:40px;text-align:center;color:#e74c3c;font-family:sans-serif;">
        <h2>🚫 Acceso denegado</h2>
        <p>Solo los profesores pueden acceder a este panel.</p>
        <a href="index.php" style="color:#3498db;text-decoration:underline;">Volver al inicio</a>
    </div>');
}

// 2. LÓGICA: CREAR ESTUDIANTE
$mensaje_estudiante = '';
$tipo_mensaje = ''; // success o error

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_estudiante'])) {
    $archivo_usuarios = 'usuarios.json';
    $usuarios_actuales = [];
    
    if (file_exists($archivo_usuarios)) {
        $usuarios_actuales = json_decode(file_get_contents($archivo_usuarios), true) ?? [];
    }

    // Validar si el email ya existe
    $email_existente = false;
    foreach ($usuarios_actuales as $u) {
        if ($u['email'] === $_POST['email_estudiante']) {
            $email_existente = true;
            break;
        }
    }

    if ($email_existente) {
        $mensaje_estudiante = "⚠️ El correo ya está registrado.";
        $tipo_mensaje = "error";
    } else {
        // Calcular nuevo ID
        $ultimo_id = 0;
        foreach ($usuarios_actuales as $u) if ($u['id'] > $ultimo_id) $ultimo_id = $u['id'];
        
        $nuevo_estudiante = [
            "id" => $ultimo_id + 1,
            "email" => $_POST['email_estudiante'],
            "password" => $_POST['password_estudiante'],
            "rol" => "estudiante",
            "nombre" => $_POST['nombre_estudiante']
        ];

        $usuarios_actuales[] = $nuevo_estudiante;
        
        if (file_put_contents($archivo_usuarios, json_encode($usuarios_actuales, JSON_PRETTY_PRINT))) {
            $mensaje_estudiante = "✅ Estudiante creado correctamente.";
            $tipo_mensaje = "success";
        } else {
            $mensaje_estudiante = "❌ Error al guardar.";
            $tipo_mensaje = "error";
        }
    }
}

// 3. LÓGICA: OBTENER DATOS
function obtenerQuizzesProfesor() {
    $quizzes = [];
    $archivos = glob('quizzes/*.json');
    usort($archivos, function($a, $b) { return filemtime($b) - filemtime($a); });

    foreach ($archivos as $archivo) {
        $contenido = file_get_contents($archivo);
        $data = json_decode($contenido, true);
        if ($data) {
            $data['id_archivo'] = basename($archivo);
            $quizzes[] = $data;
        }
    }
    return $quizzes;
}

$mis_quizzes = obtenerQuizzesProfesor();

$lista_estudiantes = [];
if (file_exists('usuarios.json')) {
    $todos = json_decode(file_get_contents('usuarios.json'), true) ?? [];
    foreach ($todos as $u) {
        if (isset($u['rol']) && $u['rol'] === 'estudiante') $lista_estudiantes[] = $u;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Profesor | Gestión Escolar</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;       /* Indigo 600 */
            --primary-hover: #4338ca; /* Indigo 700 */
            --bg-body: #f8fafc;       /* Slate 50 */
            --bg-sidebar: #0f172a;    /* Slate 900 */
            --text-main: #334155;     /* Slate 700 */
            --text-light: #64748b;    /* Slate 500 */
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-sidebar);
            color: white;
            position: fixed;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 24px;
            z-index: 50;
        }

        .logo-area {
            display: flex; align-items: center; gap: 12px; margin-bottom: 40px;
            padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .logo-icon { font-size: 1.8rem; color: #818cf8; }
        .logo-text { font-weight: 700; font-size: 1.2rem; letter-spacing: -0.5px; }

        .nav-menu { display: flex; flex-direction: column; gap: 8px; }
        .nav-item {
            display: flex; align-items: center; gap: 12px; padding: 12px 16px;
            color: #94a3b8; text-decoration: none; border-radius: 8px;
            transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem;
            cursor: pointer;
        }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { background: var(--primary); color: white; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
        .nav-item.logout { margin-top: auto; color: #ef4444; }
        .nav-item.logout:hover { background: rgba(239, 68, 68, 0.1); }

        /* --- CONTENIDO PRINCIPAL --- */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 32px 40px;
        }

        /* Header Superior */
        .header-top {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;
        }
        .page-title h1 { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .page-title span { color: var(--text-light); font-size: 0.9rem; }

        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar {
            width: 40px; height: 40px; background: #e0e7ff; color: var(--primary);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 1.1rem;
        }

        /* Banner */
        .hero-banner {
            background: linear-gradient(120deg, #4f46e5 0%, #3b82f6 100%);
            border-radius: 16px; padding: 32px; color: white; margin-bottom: 40px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.4);
        }
        .hero-text h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 8px; }
        .hero-text p { opacity: 0.9; font-size: 1rem; max-width: 500px; }
        .hero-actions { display: flex; gap: 12px; }

        /* Botones */
        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            border-radius: 8px; font-weight: 600; text-decoration: none; font-size: 0.9rem;
            transition: 0.2s; border: none; cursor: pointer;
        }
        .btn-white { background: white; color: var(--primary); }
        .btn-white:hover { background: #f1f5f9; transform: translateY(-2px); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: var(--text-main); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }

        /* --- GRID DE QUIZZES --- */
        .section-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
        }
        .section-label { font-size: 1.2rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        
        .quiz-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px;
        }

        .quiz-card {
            background: white; border-radius: 12px; overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex; flex-direction: column;
        }
        .quiz-card:hover { transform: translateY(-4px); box-shadow: 0 12px 20px -5px rgba(0,0,0,0.08); }

        .quiz-body { padding: 20px; flex-grow: 1; }
        .quiz-top { display: flex; justify-content: space-between; margin-bottom: 12px; }
        .quiz-icon { 
            width: 36px; height: 36px; border-radius: 8px; 
            display: flex; align-items: center; justify-content: center;
        }
        .quiz-title { font-weight: 700; font-size: 1.05rem; color: #1e293b; margin-bottom: 6px; }
        .quiz-desc { font-size: 0.85rem; color: var(--text-light); line-height: 1.5; margin-bottom: 16px; }
        
        .quiz-tags { display: flex; gap: 10px; font-size: 0.75rem; color: #64748b; font-weight: 500; }
        .tag { display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; }

        .quiz-footer {
            padding: 12px 20px; background: #f8fafc; border-top: 1px solid #f1f5f9;
        }
        .btn-view { width: 100%; justify-content: center; font-size: 0.85rem; }

        /* --- TABLA DE ESTUDIANTES --- */
        .table-container {
            background: white; border-radius: 12px; border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); overflow: hidden;
        }
        .table-actions { padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: flex-end; }
        
        .student-table { width: 100%; border-collapse: collapse; }
        .student-table th {
            text-align: left; padding: 16px 24px; background: #f8fafc; 
            color: #475569; font-size: 0.8rem; text-transform: uppercase; font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }
        .student-table td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; }
        .student-table tr:last-child td { border-bottom: none; }
        .student-table tr:hover td { background: #f8fafc; }
        
        .badge-id { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.8rem; }
        .password-field { font-family: monospace; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; color: #475569; }

        /* --- MENSAJES FLOTANTES --- */
        .alert {
            padding: 16px; margin-bottom: 24px; border-radius: 8px; font-weight: 500;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* --- MODAL --- */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
            z-index: 1000; align-items: center; justify-content: center;
            animation: fadeIn 0.2s ease;
        }
        .modal-box {
            background: white; width: 100%; max-width: 550px; max-height: 85vh;
            border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            display: flex; flex-direction: column; overflow: hidden;
            transform: translateY(20px); animation: slideUp 0.3s ease forwards;
        }
        .modal-header {
            padding: 20px 24px; border-bottom: 1px solid var(--border-color);
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-title { font-size: 1.2rem; font-weight: 700; color: #1e293b; }
        .close-btn { font-size: 1.5rem; color: #94a3b8; cursor: pointer; transition: 0.2s; }
        .close-btn:hover { color: #ef4444; }
        
        .modal-content { padding: 24px; overflow-y: auto; }

        .input-group { margin-bottom: 16px; }
        .input-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #334155; }
        .input-field {
            width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 0.95rem; transition: 0.2s;
        }
        .input-field:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }

        /* Preview Styles */
        .p-q { margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
        .p-q-title { font-weight: 600; margin-bottom: 10px; }
        .p-opt {
            padding: 8px 12px; margin-bottom: 4px; background: #f8fafc; border-radius: 6px; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;
        }
        .p-opt.correct { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

        /* Animations */
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { to { transform: translateY(0); } }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { width: 70px; padding: 20px 10px; }
            .sidebar .logo-text, .sidebar .nav-item span { display: none; }
            .main-wrapper { margin-left: 70px; width: calc(100% - 70px); padding: 20px; }
            .hero-banner { flex-direction: column; text-align: center; gap: 20px; }
            .nav-item { justify-content: center; }
        }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="logo-area">
            <?php if(file_exists('assets/logo.png')): ?>
                <img src="assets/logo.png" alt="Logo" style="width: 35px; border-radius: 6px;">
            <?php else: ?>
                <i class="fas fa-graduation-cap logo-icon"></i>
            <?php endif; ?>
            <span class="logo-text">Profesor</span>
        </div>
        
        <div class="nav-menu">
            <div class="nav-item active" id="nav-dashboard" onclick="switchTab('dashboard')">
                <i class="fas fa-th-large"></i> <span>Mis Quizzes</span>
            </div>
            <div class="nav-item" id="nav-students" onclick="switchTab('students')">
                <i class="fas fa-user-graduate"></i> <span>Estudiantes</span>
            </div>
            <a href="resultados/dashboard.php" class="nav-item">
                <i class="fas fa-chart-pie"></i> <span>Resultados</span>
            </a>
            <a href="crear.php" class="nav-item">
                <i class="fas fa-plus-circle"></i> <span>Nuevo Quiz</span>
            </a>
            <a href="logout.php" class="nav-item logout">
                <i class="fas fa-sign-out-alt"></i> <span>Cerrar Sesión</span>
            </a>
        </div>
    </nav>

    <div class="main-wrapper">
        
        <header class="header-top">
            <div class="page-title">
                <h1 id="page-header-title">Panel de Control</h1>
                <span><?php echo date('l, d F Y'); ?></span>
            </div>
            <div class="user-profile">
                <div style="text-align: right;">
                    <div style="font-weight: 700; font-size: 0.95rem;"><?php echo htmlspecialchars($usuario['nombre']); ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-light);">Admin</div>
                </div>
                <div class="user-avatar">
                    <?php echo strtoupper(substr($usuario['nombre'], 0, 1)); ?>
                </div>
            </div>
        </header>

        <?php if ($mensaje_estudiante): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <i class="fas <?php echo $tipo_mensaje == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo $mensaje_estudiante; ?>
            </div>
        <?php endif; ?>

        <section id="view-dashboard">
            <div class="hero-banner">
                <div class="hero-text">
                    
                    <h2>Bienvenido de nuevo</h2>
                    <p>Aquí tienes un resumen de tus evaluaciones activas. Puedes crear nuevas o revisar las existentes.</p>
                </div>
                <div class="hero-actions">
                    <a href="crear.php" class="btn btn-white"><i class="fas fa-plus"></i> Crear</a>
                    <button onclick="switchTab('students')" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">
                        <i class="fas fa-users"></i> Alumnos
                    </button>
                </div>
            </div>

            <div class="section-header">
                <div class="section-label"><i class="fas fa-folder-open text-primary"></i> Biblioteca de Quizzes</div>
                <span style="font-size: 0.9rem; color: var(--text-light); font-weight: 500;"><?php echo count($mis_quizzes); ?> archivos</span>
            </div>

            <?php if(empty($mis_quizzes)): ?>
                <div style="text-align:center; padding: 60px; background: white; border-radius: 12px; border: 2px dashed #e2e8f0;">
                    <i class="fas fa-file-alt" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 20px;"></i>
                    <h3 style="color: #64748b; margin-bottom: 10px;">No hay quizzes todavía</h3>
                    <a href="crear.php" class="btn btn-primary">Crear mi primer quiz</a>
                </div>
            <?php else: ?>
                <div class="quiz-grid">
                    <?php foreach($mis_quizzes as $idx => $quiz): 
                        $modalId = 'modal_preview_' . $idx;
                    ?>
                    <div class="quiz-card" style="border-top: 4px solid <?php echo $quiz['color_primario']; ?>;">
                        <div class="quiz-body">
                            <div class="quiz-top">
                                <div class="quiz-icon" style="background: <?php echo $quiz['color_primario']; ?>15; color: <?php echo $quiz['color_primario']; ?>;">
                                    <i class="fas fa-book"></i>
                                </div>
                                <i class="fas fa-ellipsis-h" style="color: #cbd5e1;"></i>
                            </div>
                            <div class="quiz-title"><?php echo htmlspecialchars($quiz['titulo']); ?></div>
                            <div class="quiz-desc">
                                <?php echo htmlspecialchars(substr($quiz['descripcion'], 0, 60)) . '...'; ?>
                            </div>
                            <div class="quiz-tags">
                                <span class="tag"><i class="fas fa-list"></i> <?php echo count($quiz['preguntas']); ?></span>
                                <span class="tag"><i class="fas fa-clock"></i> <?php echo $quiz['duracion_minutos'] ?? 60; ?>m</span>
                            </div>
                        </div>
                        <div class="quiz-footer">
                            <button onclick="openModal('<?php echo $modalId; ?>')" class="btn btn-outline btn-view">
                                Vista Previa
                            </button>
                        </div>
                    </div>

                    <div id="<?php echo $modalId; ?>" class="modal-overlay" onclick="closeModal(event, '<?php echo $modalId; ?>')">
                        <div class="modal-box">
                            <div class="modal-header">
                                <div class="modal-title"><?php echo htmlspecialchars($quiz['titulo']); ?></div>
                                <div class="close-btn" onclick="forceClose('<?php echo $modalId; ?>')">&times;</div>
                            </div>
                            <div class="modal-content">
                                <div style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:20px; font-size:0.9rem;">
                                    <?php echo htmlspecialchars($quiz['descripcion']); ?>
                                </div>
                                <?php foreach($quiz['preguntas'] as $k => $p): ?>
                                    <div class="p-q">
                                        <div class="p-q-title"><?php echo ($k+1).'. '.htmlspecialchars($p['texto']); ?> <small style="color:#94a3b8">(<?php echo $p['valor']; ?>pts)</small></div>
                                        <?php foreach($p['respuestas'] as $r): ?>
                                            <div class="p-opt <?php echo ($r['correcta']??false) ? 'correct' : ''; ?>">
                                                <i class="<?php echo ($r['correcta']??false) ? 'fas fa-check-circle' : 'far fa-circle'; ?>"></i>
                                                <?php echo htmlspecialchars($r['texto']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="view-students" style="display: none;">
            <div class="section-header">
                <div class="section-label">
                    <i class="fas fa-users text-primary"></i> Gestión de Usuarios
                </div>
                <button onclick="openModal('modal_add_student')" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Nuevo Estudiante
                </button>
            </div>

            <div class="table-container">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Estudiante</th>
                            <th>Credenciales</th>
                            <th>Rol</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($lista_estudiantes as $est): ?>
                        <tr>
                            <td><span class="badge-id">#<?php echo $est['id']; ?></span></td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($est['nombre']); ?></div>
                                <div style="font-size: 0.85rem; color: #64748b;">Activo</div>
                            </td>
                            <td>
                                <div style="font-size: 0.9rem; margin-bottom: 4px;"><?php echo htmlspecialchars($est['email']); ?></div>
                                <span class="password-field"><i class="fas fa-key" style="font-size:0.7rem"></i> <?php echo htmlspecialchars($est['password']); ?></span>
                            </td>
                            <td>
                                <span style="background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Estudiante</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($lista_estudiantes)): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 40px; color: #94a3b8;">No hay estudiantes registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </div>

    <div id="modal_add_student" class="modal-overlay" onclick="closeModal(event, 'modal_add_student')">
        <div class="modal-box" style="max-width: 450px;">
            <div class="modal-header">
                <div class="modal-title">Registrar Alumno</div>
                <div class="close-btn" onclick="forceClose('modal_add_student')">&times;</div>
            </div>
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="crear_estudiante" value="1">
                    
                    <div class="input-group">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre_estudiante" class="input-field" required placeholder="Ej: Ana García">
                    </div>
                    
                    <div class="input-group">
                        <label>Correo Electrónico</label>
                        <input type="email" name="email_estudiante" class="input-field" required placeholder="ana@escuela.com">
                    </div>
                    
                    <div class="input-group">
                        <label>Contraseña Temporal</label>
                        <input type="text" name="password_estudiante" class="input-field" required value="123456">
                        <small style="color:#64748b; margin-top:5px; display:block;">El alumno podrá usar esta clave para entrar.</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width:100%; justify-content: center; margin-top: 10px;">
                        Guardar y Registrar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Lógica de Pestañas
        function switchTab(tabName) {
            // Ocultar todo
            document.getElementById('view-dashboard').style.display = 'none';
            document.getElementById('view-students').style.display = 'none';
            
            // Quitar clase active del menu
            document.getElementById('nav-dashboard').classList.remove('active');
            document.getElementById('nav-students').classList.remove('active');

            // Mostrar seleccionado
            document.getElementById('view-' + tabName).style.display = 'block';
            document.getElementById('nav-' + tabName).classList.add('active');

            // Cambiar título header
            const titles = { 'dashboard': 'Panel de Control', 'students': 'Listado de Alumnos' };
            document.getElementById('page-header-title').innerText = titles[tabName];
        }

        // Lógica Modales
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function forceClose(id) {
            document.getElementById(id).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function closeModal(event, id) {
            if (event.target.id === id) {
                forceClose(id);
            }
        }

        // Persistir pestaña si se recarga la página (opcional, básico)
        <?php if($mensaje_estudiante): ?>
            switchTab('students');
        <?php endif; ?>
    </script>

</body>
</html>