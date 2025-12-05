<?php
session_start();
require 'db.php'; // Conexión a BD

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'];
$mensaje = '';
$tipo_mensaje = '';

// 2. LÓGICA: CREAR ESTUDIANTE INDIVIDUAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_estudiante'])) {
    $nombre = trim($_POST['nombre_estudiante']);
    $email = trim($_POST['email_estudiante']);
    $password = trim($_POST['password_estudiante']);

    // Verificar duplicado
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        $mensaje = "⚠️ El correo $email ya está registrado.";
        $tipo_mensaje = "error";
    } else {
        $stmtIns = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, 'estudiante')");
        if ($stmtIns->execute([$nombre, $email, $password])) {
            $mensaje = "✅ Estudiante creado correctamente.";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "❌ Error al guardar.";
            $tipo_mensaje = "error";
        }
    }
}

// 3. LÓGICA: IMPORTAR CSV MASIVO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_csv'])) {
    $archivo = $_FILES['archivo_csv'];
    
    if ($archivo['error'] === 0) {
        $ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'csv') {
            $mensaje = "❌ Solo se permiten archivos CSV.";
            $tipo_mensaje = "error";
        } else {
            $handle = fopen($archivo['tmp_name'], "r");
            $creados = 0;
            $errores = 0;
            
            // Saltar encabezado si existe (opcional, aquí asumimos que la primera fila son datos o headers)
            // fgetcsv($handle); 

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Formato esperado: Nombre, Email, Password
                if (count($data) >= 3) {
                    $nombre = trim($data[0]);
                    $email = trim($data[1]);
                    $password = trim($data[2]);

                    // Validar email
                    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                    $stmt->execute([$email]);
                    
                    if (!$stmt->fetch()) {
                        $stmtIns = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, 'estudiante')");
                        if ($stmtIns->execute([$nombre, $email, $password])) {
                            $creados++;
                        }
                    } else {
                        $errores++; // Email repetido
                    }
                }
            }
            fclose($handle);
            
            $mensaje = "✅ Proceso finalizado. Creados: $creados. Omitidos (duplicados): $errores.";
            $tipo_mensaje = ($creados > 0) ? "success" : "warning";
        }
    }
}

// 4. LÓGICA: LISTAR ESTUDIANTES (SQL)
$lista_estudiantes = [];
$stmtList = $pdo->query("SELECT * FROM usuarios WHERE rol = 'estudiante' ORDER BY id DESC");
$lista_estudiantes = $stmtList->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios | Profesor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4f46e5;
            --bg-body: #f8fafc;
            --bg-sidebar: #0f172a;
            --text-main: #334155;
            --text-light: #64748b;
            --sidebar-width: 260px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background: var(--bg-sidebar); color: white; position: fixed; height: 100vh; display: flex; flex-direction: column; padding: 24px; z-index: 50; }
        .logo-area { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .logo-text { font-weight: 700; font-size: 1.2rem; }
        .nav-menu { display: flex; flex-direction: column; gap: 8px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: #94a3b8; text-decoration: none; border-radius: 8px; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { background: var(--primary); color: white; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
        .nav-item.logout { margin-top: auto; color: #ef4444; }

        .main-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 32px 40px; }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .page-title h1 { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .page-title span { color: var(--text-light); font-size: 0.9rem; }
        .user-avatar { width: 40px; height: 40px; background: #e0e7ff; color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; }

        /* Estilos específicos */
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; color: white; background: var(--primary); transition: 0.2s; text-decoration: none; font-size: 0.9rem; }
        .btn:hover { background: #4338ca; }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }

        .table-container { background: white; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); overflow: hidden; }
        .student-table { width: 100%; border-collapse: collapse; }
        .student-table th { text-align: left; padding: 16px 24px; background: #f8fafc; color: #475569; font-size: 0.8rem; text-transform: uppercase; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
        .student-table td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; }
        .student-table tr:hover td { background: #f8fafc; }
        .badge-id { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.8rem; }
        .password-field { font-family: monospace; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; color: #475569; }

        /* Alertas */
        .alert { padding: 16px; margin-bottom: 24px; border-radius: 8px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; animation: fadeIn 0.2s ease; }
        .modal-box { background: white; width: 100%; max-width: 450px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); display: flex; flex-direction: column; overflow: hidden; transform: translateY(20px); animation: slideUp 0.3s ease forwards; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 1.2rem; font-weight: 700; color: #1e293b; }
        .close-btn { font-size: 1.5rem; color: #94a3b8; cursor: pointer; }
        .modal-content { padding: 24px; }
        .input-group { margin-bottom: 16px; }
        .input-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #334155; }
        .input-field { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem; }
        
        .file-upload-box { border: 2px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 8px; cursor: pointer; background: #f8fafc; transition: 0.2s; }
        .file-upload-box:hover { border-color: var(--primary); background: #e0e7ff; color: var(--primary); }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { to { transform: translateY(0); } }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="logo-area">
            <?php if(file_exists('assets/logo.png')): ?>
                <img src="assets/logo.png" alt="Logo" style="width: 35px; border-radius: 6px;">
            <?php else: ?>
                <i class="fas fa-graduation-cap" style="font-size: 1.5rem; color:#818cf8"></i>
            <?php endif; ?>
            <span class="logo-text">Profesor</span>
        </div>
        
        <div class="nav-menu">
            <a href="profesor.php" class="nav-item">
                <i class="fas fa-th-large"></i> <span>Mis Quizzes</span>
            </a>
            <a href="usuarios.php" class="nav-item active">
                <i class="fas fa-user-graduate"></i> <span>Estudiantes</span>
            </a>
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
                <h1>Gestión de Alumnos</h1>
                <span><?php echo date('l, d F Y'); ?></span>
            </div>
            <div class="user-profile">
                <div style="text-align: right; margin-right: 10px;">
                    <div style="font-weight: 700; font-size: 0.95rem;"><?php echo htmlspecialchars($usuario['nombre']); ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-light);">Admin</div>
                </div>
                <div class="user-avatar">
                    <?php echo strtoupper(substr($usuario['nombre'], 0, 1)); ?>
                </div>
            </div>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <i class="fas <?php echo $tipo_mensaje == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <div style="font-size: 1.2rem; font-weight: 700; color: #1e293b;">
                <i class="fas fa-users text-primary"></i> Listado Oficial
            </div>
            <div style="display:flex; gap:10px;">
                <button onclick="openModal('modal_import_csv')" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Importar CSV
                </button>
                <button onclick="openModal('modal_add_student')" class="btn">
                    <i class="fas fa-user-plus"></i> Nuevo Estudiante
                </button>
            </div>
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
    </div>

    <div id="modal_add_student" class="modal-overlay" onclick="closeModal(event, 'modal_add_student')">
        <div class="modal-box">
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
                    </div>
                    <button type="submit" class="btn" style="width:100%; justify-content: center;">Guardar y Registrar</button>
                </form>
            </div>
        </div>
    </div>

    <div id="modal_import_csv" class="modal-overlay" onclick="closeModal(event, 'modal_import_csv')">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title" style="color:#10b981"><i class="fas fa-file-csv"></i> Importar Masivo</div>
                <div class="close-btn" onclick="forceClose('modal_import_csv')">&times;</div>
            </div>
            <div class="modal-content">
                <p style="font-size:0.9rem; color:#64748b; margin-bottom:15px;">
                    Sube un archivo .csv (Excel) con las columnas: <br><strong>Nombre, Email, Contraseña</strong>
                </p>
                
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="file" name="archivo_csv" id="csv_input" accept=".csv" style="display:none;" onchange="document.getElementById('csv-label').innerText = this.files[0].name" required>
                    <label for="csv_input" id="csv-label" class="file-upload-box">
                        <i class="fas fa-cloud-upload-alt" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                        Clic para seleccionar archivo CSV
                    </label>
                    
                    <button type="submit" class="btn btn-success" style="width:100%; justify-content: center; margin-top: 15px;">
                        Procesar Archivo
                    </button>
                </form>
                
                <div style="margin-top:15px; text-align:center;">
                    <a href="data:text/csv;charset=utf-8,Nombre,Email,Password%0AJuan Perez,juan@test.com,123456%0AMaria Lopez,maria@test.com,123456" download="plantilla_alumnos.csv" style="color:var(--primary); font-size:0.85rem; text-decoration:none;">
                        <i class="fas fa-download"></i> Descargar Plantilla de Ejemplo
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function forceClose(id) { document.getElementById(id).style.display = 'none'; }
        function closeModal(event, id) { if (event.target.id === id) forceClose(id); }
    </script>
</body>
</html>