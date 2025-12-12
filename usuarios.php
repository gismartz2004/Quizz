<?php
// Aumentar tiempo de ejecución para cargas masivas
set_time_limit(300);
ini_set('memory_limit', '256M');

session_start();
require 'db.php'; 

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'];
$mensaje = '';
$tipo_mensaje = '';

// 2. LÓGICA: ELIMINAR USUARIO
if (isset($_GET['delete_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'estudiante'");
        $stmt->execute([$_GET['delete_id']]);
        $mensaje = "🗑️ Estudiante eliminado."; $tipo_mensaje = "success";
    } catch (Exception $e) { $mensaje = "Error: ".$e->getMessage(); $tipo_mensaje="error"; }
}

// 3. LÓGICA: ELIMINAR TODOS
if (isset($_POST['delete_all_students'])) {
    try {
        $pdo->query("DELETE FROM usuarios WHERE rol = 'estudiante'");
        $mensaje = "🗑️ Lista vaciada."; $tipo_mensaje = "success";
    } catch (Exception $e) { $mensaje = "Error: ".$e->getMessage(); $tipo_mensaje="error"; }
}

// 4. LÓGICA: CREAR ESTUDIANTE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_estudiante'])) {
    $codigo = trim($_POST['codigo_estudiante']);
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE password = ?");
    $stmt->execute([$codigo]);
    if($stmt->fetch()){ $mensaje = "⚠️ Código repetido."; $tipo_mensaje="error"; }
    else {
        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, 'estudiante')");
        if($stmt->execute([trim($_POST['nombre_estudiante']), $codigo."@quizzapp.com", $codigo])) {
            $mensaje = "✅ Guardado."; $tipo_mensaje="success";
        }
    }
}

// 5. LÓGICA: IMPORTAR CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_csv'])) {
    $archivo = $_FILES['archivo_csv'];
    if ($archivo['error'] === 0) {
        $ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'csv') { $mensaje = "❌ Solo CSV."; $tipo_mensaje = "error"; }
        else {
            $handle = fopen($archivo['tmp_name'], "r");
            $nuevos_usuarios = []; $codigos_csv = [];
            
            // Saltar header si existe
            $header = fgetcsv($handle, 1000, ",");
            if (is_numeric($header[1] ?? '')) rewind($handle); 

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) >= 2) {
                    $nombre = trim($data[0]); $codigo = trim($data[1]);
                    if (!empty($codigo)) {
                        $nuevos_usuarios[] = ['nombre' => $nombre, 'email' => $codigo . "@quizzapp.com", 'password' => $codigo];
                    }
                }
            }
            fclose($handle);

            if (count($nuevos_usuarios) > 0) {
                try {
                    $existentes = $pdo->query("SELECT password FROM usuarios WHERE rol = 'estudiante'")->fetchAll(PDO::FETCH_COLUMN);
                    $a_insertar = []; $duplicados = 0;
                    
                    foreach ($nuevos_usuarios as $u) {
                        if (in_array($u['password'], $existentes)) $duplicados++;
                        else { $a_insertar[] = $u; $existentes[] = $u['password']; }
                    }

                    $lotes = array_chunk($a_insertar, 50);
                    $creados = 0;
                    $pdo->beginTransaction();

                    foreach ($lotes as $lote) {
                        $valores_sql = []; $datos_sql = [];
                        foreach ($lote as $u) {
                            $valores_sql[] = "(?, ?, ?, 'estudiante')";
                            array_push($datos_sql, $u['nombre'], $u['email'], $u['password']);
                        }
                        $stmtInsert = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES " . implode(", ", $valores_sql));
                        $stmtInsert->execute($datos_sql);
                        $creados += count($lote);
                    }
                    $pdo->commit();
                    $mensaje = "✅ Importado: $creados. Duplicados: $duplicados."; $tipo_mensaje = ($creados > 0) ? "success" : "warning";
                } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $mensaje = "Error: " . $e->getMessage(); $tipo_mensaje = "error"; }
            }
        }
    }
}

// 6. BÚSQUEDA Y PAGINACIÓN
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
$pagina = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limite = 20;
$offset = ($pagina - 1) * $limite;

$sql_base = "FROM usuarios WHERE rol = 'estudiante'";
$params = [];

if (!empty($busqueda)) {
    $sql_base .= " AND (nombre LIKE ? OR password LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$total_registros = $pdo->prepare("SELECT COUNT(*) $sql_base");
$total_registros->execute($params);
$total_registros = $total_registros->fetchColumn();
$total_paginas = ceil($total_registros / $limite);

$stmtList = $pdo->prepare("SELECT * $sql_base ORDER BY id DESC LIMIT $limite OFFSET $offset");
$stmtList->execute($params);
$lista_estudiantes = $stmtList->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --bg-body: #f3f4f6;
            --bg-sidebar: #1e293b;
            --text-main: #1f2937;
            --text-light: #6b7280;
            --white: #ffffff;
            --danger: #ef4444;
            --success: #10b981;
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width); background: var(--bg-sidebar); color: white;
            position: fixed; height: 100vh; padding: 20px; z-index: 100;
            transition: transform 0.3s ease-in-out;
        }
        .logo-area { display: flex; align-items: center; gap: 10px; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: #94a3b8; text-decoration: none; border-radius: 8px; margin-bottom: 5px; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { background: var(--primary); color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        
        /* --- MAIN CONTENT --- */
        .main-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 30px; transition: margin 0.3s; }

        /* HEADER */
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: var(--white); padding: 15px 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .toggle-btn { display: none; font-size: 1.5rem; cursor: pointer; color: var(--text-main); background: none; border: none; }
        
        /* BÚSQUEDA Y ACCIONES */
        .controls-bar { display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between; align-items: center; margin-bottom: 20px; background: var(--white); padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .search-form { display: flex; flex: 1; gap: 10px; min-width: 300px; }
        .search-input { flex: 1; padding: 10px 15px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; outline: none; transition: border-color 0.2s; }
        .search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }

        .action-buttons { display: flex; gap: 10px; }

        /* BOTONES */
        .btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; color: white; background: var(--primary); transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-size: 0.9rem; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-danger { background: var(--danger); }
        .btn-success { background: var(--success); }
        .btn-secondary { background: var(--text-light); }

        /* TABLA */
        .table-card { background: var(--white); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
        .table-responsive { overflow-x: auto; }
        .student-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        .student-table th { text-align: left; padding: 15px 20px; background: #f9fafb; color: #6b7280; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; border-bottom: 1px solid #e5e7eb; }
        .student-table td { padding: 15px 20px; border-bottom: 1px solid #f3f4f6; color: #374151; vertical-align: middle; }
        .student-table tr:hover td { background: #f9fafb; }
        
        .code-badge { background: #e0e7ff; color: var(--primary-dark); padding: 4px 8px; border-radius: 6px; font-family: monospace; font-weight: 700; font-size: 0.9rem; }

        /* PAGINACIÓN */
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; flex-wrap: wrap; }
        .page-link { padding: 8px 12px; background: white; border: 1px solid #e5e7eb; border-radius: 6px; text-decoration: none; color: var(--text-main); transition: 0.2s; }
        .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-link:hover:not(.active) { background: #f3f4f6; }

        /* MODAL */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; backdrop-filter: blur(4px); animation: fadeIn 0.2s; }
        .modal-box { background: white; width: 90%; max-width: 450px; border-radius: 16px; padding: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: slideUp 0.3s; }
        .modal-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .close-modal { cursor: pointer; color: #9ca3af; transition: 0.2s; }
        .close-modal:hover { color: var(--danger); }
        
        .input-field { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; margin-bottom: 15px; outline: none; transition: 0.2s; }
        .input-field:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; width: 100%; padding: 15px; }
            .toggle-btn { display: block; }
            .controls-bar { flex-direction: column; align-items: stretch; }
            .action-buttons { justify-content: space-between; }
            .btn { justify-content: center; flex: 1; }
            .search-form { min-width: auto; }
            .header-top h1 { font-size: 1.2rem; }
        }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <?php $page = 'usuarios'; include 'includes/sidebar_profesor.php'; ?>

    <div class="main-wrapper">
        
        <div class="header-top">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button class="toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div>
                    <h1 style="margin:0;">Gestión de Alumnos</h1>
                    <span style="color:var(--text-light); font-size:0.85rem;">Total: <?= $total_registros ?> Estudiantes</span>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="text-align: right; display: none; @media(min-width:768px){display:block;}">
                    <div style="font-weight:700;"><?= htmlspecialchars($usuario['nombre']) ?></div>
                    <div style="font-size:0.75rem; color:var(--text-light);">Administrador</div>
                </div>
                <div style="width:40px; height:40px; background:#e0e7ff; color:var(--primary); border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700;">
                    <?= strtoupper(substr($usuario['nombre'], 0, 1)) ?>
                </div>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?= $tipo_mensaje == 'error' ? 'alert-error' : 'alert-success' ?>">
                <i class="fas <?= $tipo_mensaje == 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
                <?= $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="controls-bar">
            <form method="GET" class="search-form">
                <input type="text" name="q" class="search-input" placeholder="Buscar por nombre o código..." value="<?= htmlspecialchars($busqueda) ?>">
                <button type="submit" class="btn"><i class="fas fa-search"></i></button>
                <?php if(!empty($busqueda)): ?>
                    <a href="usuarios.php" class="btn btn-secondary" title="Limpiar filtro"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>

            <div class="action-buttons">
                <form method="POST" onsubmit="return confirm('⚠️ ¿ESTÁS SEGURO? Se borrarán TODOS los estudiantes.');" style="margin:0;">
                    <button type="submit" name="delete_all_students" class="btn btn-danger" title="Borrar todo"><i class="fas fa-trash-alt"></i></button>
                </form>
                <button onclick="openModal('modal_import_csv')" class="btn btn-success"><i class="fas fa-file-upload"></i> CSV</button>
                <button onclick="openModal('modal_add_student')" class="btn"><i class="fas fa-plus"></i> Nuevo</button>
            </div>
        </div>

        <div class="table-card">
            <div class="table-responsive">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Estudiante</th>
                            <th>Código de Acceso</th>
                            <th>Estado</th>
                            <th style="text-align:right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($lista_estudiantes) > 0): ?>
                            <?php foreach($lista_estudiantes as $est): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($est['nombre']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-light);">ID: #<?= $est['id']; ?></div>
                                </td>
                                <td>
                                    <span class="code-badge"><i class="fas fa-key" style="font-size:0.8em"></i> <?= htmlspecialchars($est['password']); ?></span>
                                </td>
                                <td>
                                    <span style="background:#dcfce7; color:#166534; padding:4px 8px; border-radius:20px; font-size:0.75rem; font-weight:700;">Activo</span>
                                </td>
                                <td style="text-align:right;">
                                    <a href="?delete_id=<?= $est['id']; ?>" class="btn btn-danger" style="padding:6px 10px;" onclick="return confirm('¿Eliminar a <?= htmlspecialchars($est['nombre']); ?>?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding:40px; color:var(--text-light);">No se encontraron resultados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <?php if ($pagina > 1): ?>
                <a href="?page=<?= $pagina - 1 ?>&q=<?= urlencode($busqueda) ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($busqueda) ?>" class="page-link <?= $i == $pagina ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($pagina < $total_paginas): ?>
                <a href="?page=<?= $pagina + 1 ?>&q=<?= urlencode($busqueda) ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <div id="modal_add_student" class="modal-overlay" onclick="if(event.target===this) closeModal('modal_add_student')">
        <div class="modal-box">
            <div class="modal-title">
                <span>Nuevo Estudiante</span>
                <i class="fas fa-times close-modal" onclick="closeModal('modal_add_student')"></i>
            </div>
            <form method="POST">
                <input type="hidden" name="crear_estudiante" value="1">
                <label style="font-weight:600; font-size:0.9rem; display:block; margin-bottom:5px;">Nombre Completo</label>
                <input type="text" name="nombre_estudiante" class="input-field" required placeholder="Ej: Juan Pérez">
                
                <label style="font-weight:600; font-size:0.9rem; display:block; margin-bottom:5px;">Código de Estudiante</label>
                <input type="text" name="codigo_estudiante" class="input-field" required placeholder="Ej: 1755822">
                
                <button type="submit" class="btn" style="width:100%; justify-content:center; margin-top:10px;">Registrar</button>
            </form>
        </div>
    </div>

    <div id="modal_import_csv" class="modal-overlay" onclick="if(event.target===this) closeModal('modal_import_csv')">
        <div class="modal-box">
            <div class="modal-title">
                <span>Importación Masiva</span>
                <i class="fas fa-times close-modal" onclick="closeModal('modal_import_csv')"></i>
            </div>
            <p style="font-size:0.9rem; color:var(--text-light); margin-bottom:20px;">
                Sube un archivo <b>.CSV</b> con 2 columnas: <br><code>Nombre, Código</code>
            </p>
            
            <form method="POST" enctype="multipart/form-data">
                <div style="border: 2px dashed #cbd5e1; padding: 30px; text-align: center; border-radius: 12px; margin-bottom: 20px; cursor: pointer; background: #f9fafb;" onclick="document.getElementById('file_csv').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--primary); margin-bottom: 10px;"></i>
                    <p style="margin:0; font-size:0.9rem;">Clic para seleccionar archivo</p>
                </div>
                <input type="file" name="archivo_csv" id="file_csv" accept=".csv" required style="display:none;" onchange="this.form.submit()">
                
                <a href="data:text/csv;charset=utf-8,Nombre,Codigo%0AEstudiante Ejemplo,123456" download="plantilla_simple.csv" style="display:block; text-align:center; color:var(--primary); text-decoration:none; font-size:0.9rem;">
                    <i class="fas fa-download"></i> Descargar Plantilla
                </a>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        function toggleSidebar() { 
            document.getElementById('sidebar').classList.toggle('open'); 
        }
    </script>
</body>
</html>