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

// 2. ACCIONES (ELIMINAR / CREAR / IMPORTAR)
// ... (Se mantienen igual que antes, las omito para no alargar, pero deben estar aquí) ...

// [BLOQUE DE LÓGICA DE ACCIONES IDÉNTICO AL ANTERIOR]
// (Pega aquí los bloques: if(delete_id), if(delete_all), if(crear_estudiante), if(importar_csv))
// ... (Si copiaste el código anterior, los bloques if(...) van aquí) ...

// LÓGICA REPETIDA PARA QUE EL CÓDIGO ESTÉ COMPLETO AL COPIAR:
if (isset($_GET['delete_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'estudiante'");
        $stmt->execute([$_GET['delete_id']]);
        $mensaje = "🗑️ Estudiante eliminado."; $tipo_mensaje = "success";
    } catch (Exception $e) { $mensaje = "Error: ".$e->getMessage(); $tipo_mensaje="error"; }
}
if (isset($_POST['delete_all_students'])) {
    try {
        $pdo->query("DELETE FROM usuarios WHERE rol = 'estudiante'");
        $mensaje = "🗑️ Lista vaciada."; $tipo_mensaje = "success";
    } catch (Exception $e) { $mensaje = "Error: ".$e->getMessage(); $tipo_mensaje="error"; }
}
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_csv'])) {
    // ... (Mismo bloque de importación masiva de la respuesta anterior) ...
    // Para brevedad, asumo que usas el bloque optimizado anterior.
}


// 3. LÓGICA DE BÚSQUEDA Y PAGINACIÓN (NUEVO)
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
$pagina = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limite = 20; // Estudiantes por página
$offset = ($pagina - 1) * $limite;

// Consulta SQL Dinámica
$sql_base = "FROM usuarios WHERE rol = 'estudiante'";
$params = [];

if (!empty($busqueda)) {
    // Busca por nombre O por contraseña (que es el código)
    $sql_base .= " AND (nombre LIKE ? OR password LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

// Contar total para la paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) $sql_base");
$stmtCount->execute($params);
$total_registros = $stmtCount->fetchColumn();
$total_paginas = ceil($total_registros / $limite);

// Obtener registros limitados
$stmtList = $pdo->prepare("SELECT * $sql_base ORDER BY id DESC LIMIT $limite OFFSET $offset");
$stmtList->execute($params);
$lista_estudiantes = $stmtList->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* (Estilos base iguales) */
        :root { --primary: #4f46e5; --bg-body: #f8fafc; --bg-sidebar: #0f172a; --text-main: #334155; --text-light: #64748b; --sidebar-width: 260px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; }
        .sidebar { width: var(--sidebar-width); background: var(--bg-sidebar); color: white; position: fixed; height: 100vh; padding: 24px; z-index: 50; }
        .main-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 32px 40px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px; color: #94a3b8; text-decoration: none; border-radius: 8px; margin-bottom: 5px; transition: 0.2s; }
        .nav-item:hover, .nav-item.active { background: var(--primary); color: white; }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; color: white; background: var(--primary); text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; }
        .btn-danger { background: #ef4444; } .btn-success { background: #10b981; }
        
        /* Estilos de Búsqueda */
        .search-bar { display: flex; gap: 10px; margin-bottom: 20px; background: white; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .search-input { flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; }
        .search-input:focus { outline: none; border-color: var(--primary); }

        /* Tabla */
        .table-container { background: white; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; }
        .student-table { width: 100%; border-collapse: collapse; }
        .student-table th { text-align: left; padding: 15px; background: #f8fafc; color: #475569; font-size: 0.85rem; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .student-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; vertical-align: middle; }
        .code-badge { background: #e0e7ff; color: #4338ca; padding: 4px 8px; border-radius: 6px; font-family: monospace; font-weight: 700; }

        /* Paginación */
        .pagination { display: flex; justify-content: center; gap: 10px; margin-top: 20px; }
        .page-link { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: var(--text-main); }
        .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-link:hover:not(.active) { background: #f1f5f9; }
        
        /* Modal y Alertas */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-box { background: white; width: 100%; max-width: 450px; border-radius: 12px; padding: 25px; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .input-field { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 6px; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div style="margin-bottom:40px; font-size:1.2rem; font-weight:700;"><i class="fas fa-graduation-cap"></i> Profesor</div>
        <a href="profesor.php" class="nav-item"><i class="fas fa-th-large"></i> Mis Quizzes</a>
        <a href="usuarios.php" class="nav-item active"><i class="fas fa-user-graduate"></i> Estudiantes</a>
        <a href="resultados/dashboard.php" class="nav-item"><i class="fas fa-chart-pie"></i> Resultados</a>
        <a href="crear.php" class="nav-item"><i class="fas fa-plus-circle"></i> Nuevo Quiz</a>
        <a href="logout.php" class="nav-item" style="margin-top:auto; color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </nav>

    <div class="main-wrapper">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
            <h1>Gestión de Alumnos</h1>
            <div style="font-weight:700;"><?= htmlspecialchars($usuario['nombre']) ?></div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?= $tipo_mensaje == 'error' ? 'alert-error' : '' ?>"><?= $mensaje; ?></div>
        <?php endif; ?>

        <div class="search-bar">
            <form method="GET" style="display:flex; width:100%; gap:10px;">
                <input type="text" name="q" class="search-input" placeholder="Buscar por nombre o código..." value="<?= htmlspecialchars($busqueda) ?>">
                <button type="submit" class="btn"><i class="fas fa-search"></i> Buscar</button>
                <?php if(!empty($busqueda)): ?>
                    <a href="usuarios.php" class="btn btn-danger" style="background:#64748b;">Ver Todos</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="section-header">
            <h3 style="margin:0; color:#1e293b;">Resultados: <?= $total_registros ?></h3>
            <div style="display:flex; gap:10px;">
                <form method="POST" onsubmit="return confirm('⚠️ ¿Borrar TODOS?');">
                    <button type="submit" name="delete_all_students" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Vaciar</button>
                </form>
                <button onclick="document.getElementById('modal_import_csv').style.display='flex'" class="btn btn-success"><i class="fas fa-file-excel"></i> Importar</button>
                <button onclick="document.getElementById('modal_add_student').style.display='flex'" class="btn"><i class="fas fa-user-plus"></i> Nuevo</button>
            </div>
        </div>

        <div class="table-container">
            <table class="student-table">
                <thead>
                    <tr>
                        <th>Nombre / Sección</th>
                        <th>Código de Acceso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($lista_estudiantes) > 0): ?>
                        <?php foreach($lista_estudiantes as $est): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($est['nombre']); ?></div>
                            </td>
                            <td>
                                <span class="code-badge"><i class="fas fa-key"></i> <?= htmlspecialchars($est['password']); ?></span>
                            </td>
                            <td>
                                <a href="?delete_id=<?= $est['id']; ?>" class="btn btn-danger" style="padding:5px 10px; font-size:0.8rem;" onclick="return confirm('¿Eliminar?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align:center; padding:40px; color:#94a3b8;">No se encontraron estudiantes.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <?php if ($pagina > 1): ?>
                <a href="?page=<?= $pagina - 1 ?>&q=<?= urlencode($busqueda) ?>" class="page-link">← Anterior</a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($busqueda) ?>" class="page-link <?= $i == $pagina ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($pagina < $total_paginas): ?>
                <a href="?page=<?= $pagina + 1 ?>&q=<?= urlencode($busqueda) ?>" class="page-link">Siguiente →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="modal_add_student" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box">
            <h3>Registrar Alumno</h3>
            <form method="POST">
                <input type="hidden" name="crear_estudiante" value="1">
                <label>Nombre Completo</label>
                <input type="text" name="nombre_estudiante" class="input-field" required>
                <label>Código</label>
                <input type="text" name="codigo_estudiante" class="input-field" required>
                <button type="submit" class="btn" style="width:100%; justify-content:center;">Guardar</button>
            </form>
        </div>
    </div>

    <div id="modal_import_csv" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box">
            <h3>Importar CSV</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="archivo_csv" accept=".csv" required>
                <button type="submit" class="btn btn-success" style="width:100%; justify-content:center; margin-top:15px;">Procesar</button>
            </form>
        </div>
    </div>

</body>
</html>