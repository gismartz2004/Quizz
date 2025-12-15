<?php
// admin.php
require 'db.php';

$mensaje = '';

// --- CREAR PROFESOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_profesor'])) {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($nombre) || empty($email) || empty($password)) {
        $mensaje = '<div class="alert alert-warning">Todos los campos son obligatorios.</div>';
    } else {
        try {
            // ⚠️ ADVERTENCIA: Guardamos la contraseña en TEXTO PLANO para poder verla.
            // NO USAR ESTO EN PRODUCCIÓN O INTERNET REAL.
            $sql = "INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, 'profesor')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre, $email, $password]);

            $mensaje = '<div class="alert alert-success">¡Profesor creado correctamente!</div>';
        } catch (PDOException $e) {
            if ($e->getCode() == 23505) {
                $mensaje = '<div class="alert alert-danger">El email ya existe.</div>';
            } else {
                $mensaje = '<div class="alert alert-danger">Error DB: ' . $e->getMessage() . '</div>';
            }
        }
    }
}

// --- ELIMINAR ---
if (isset($_GET['eliminar'])) {
    $id = (int)$_GET['eliminar'];
    $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'profesor'")->execute([$id]);
    header('Location: admin.php');
    exit;
}

// --- LISTAR ---
$profesores = $pdo->query("SELECT * FROM usuarios WHERE rol = 'profesor' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Credenciales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card-form { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        /* ESTILOS PARA IMPRESIÓN */
        @media print {
            .no-print { display: none !important; }
            .card-form { display: none !important; }
            .container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            table { font-size: 12pt; width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #000 !important; padding: 10px !important; color: #000 !important; }
            .badge { border: 1px solid #000; color: #000; background: none !important; }
            body { background: white; }
            a { text-decoration: none; color: black; }
            /* Ocultar columna de acciones al imprimir */
            th:last-child, td:last-child { display: none; }
        }
    </style>
</head>
<body>

<div class="container py-5">
    
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <h2 class="fw-bold text-dark"><i class="fas fa-users-cog text-primary"></i> Gestión de Profesores</h2>
        <button onclick="window.print()" class="btn btn-dark">
            <i class="fas fa-print"></i> Imprimir Credenciales
        </button>
    </div>

    <div class="row">
        <div class="col-md-4 no-print">
            <div class="card-form mb-4">
                <h5 class="mb-3">Crear Nuevo Usuario</h5>
                <?= $mensaje ?>
                <form method="POST">
                    <input type="hidden" name="crear_profesor" value="1">
                    <div class="mb-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre" class="form-control" required placeholder="Nombre Apellido">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email (Usuario)</label>
                        <input type="email" name="email" class="form-control" required placeholder="correo@ejemplo.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contraseña</label>
                        <div class="input-group">
                            <input type="text" name="password" class="form-control" required placeholder="Contraseña123">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                        </div>
                        <div class="form-text">Se guardará visible para imprimir.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Guardar</button>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="m-0 fw-bold">Credenciales de Acceso</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th>Usuario / Email</th>
                                <th class="text-danger">Contraseña</th>
                                <th class="no-print text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($profesores)): ?>
                                <tr><td colspan="4" class="text-center py-4">No hay registros.</td></tr>
                            <?php else: ?>
                                <?php foreach ($profesores as $p): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($p['nombre']) ?></td>
                                        <td><?= htmlspecialchars($p['email']) ?></td>
                                        <td class="font-monospace text-primary fw-bold" style="font-size: 1.1em;">
                                            <?= htmlspecialchars($p['password']) ?>
                                        </td>
                                        <td class="no-print text-end">
                                            <a href="admin.php?eliminar=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Borrar?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="d-none d-print-block mt-4 text-center text-muted">
                <p>Lista de Credenciales - Sistema Local - Uso exclusivo administrativo.</p>
            </div>
        </div>
    </div>
</div>

</body>
</html>