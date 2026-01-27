<?php
// gestion_resultados.php
// Servicio para visualizar y eliminar resultados de exámenes (Sin Login)
require 'db.php';

// --- LÓGICA DE ELIMINACIÓN (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'ID inválido']);
        exit;
    }

    try {
        // Eliminar respuestas asociadas primero (por si la FK no tiene cascade)
        $pdo->prepare("DELETE FROM respuestas_usuarios WHERE resultado_id = ?")->execute([$id]);
        
        // Eliminar el resultado
        $stmt = $pdo->prepare("DELETE FROM resultados WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Registro no encontrado']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- LÓGICA DE LISTADO ---
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
$resultados = [];

try {
    $sql = "SELECT 
                r.id, 
                r.puntos_obtenidos, 
                r.puntos_totales_quiz, 
                r.porcentaje, 
                r.fecha_realizacion,
                q.titulo as examen_titulo,
                u.nombre as estudiante_nombre,
                u.email as estudiante_email
            FROM resultados r
            JOIN quizzes q ON r.quiz_id = q.id
            JOIN usuarios u ON r.usuario_id = u.id
            WHERE 1=1";
    
    $params = [];
    if ($busqueda) {
        $sql .= " AND (u.nombre ILIKE ? OR q.titulo ILIKE ?)";
        $params[] = "%$busqueda%";
        $params[] = "%$busqueda%";
    }
    
    $sql .= " ORDER BY r.fecha_realizacion DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Resultados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-rgb: 79, 70, 229;
            --secondary: #ec4899;
            --bg-body: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.9);
        }
        
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #eef2ff 0%, #fce7f3 100%);
            min-height: 100vh;
            color: #1e293b;
            padding-bottom: 50px;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 20px;
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .header-gradient {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
        }

        .search-input {
            border: 2px solid #e2e8f0;
            border-radius: 50px;
            padding: 12px 25px;
            padding-left: 50px;
            transition: all 0.3s;
            font-size: 1rem;
        }
        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1);
            outline: none;
        }
        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .table-custom th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            padding: 15px;
        }
        .table-custom td {
            vertical-align: middle;
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        .table-custom tr:last-child td { border-bottom: none; }
        
        .avatar-circle {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .score-badge {
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 10px;
        }
        .score-good { background: #d1fae5; color: #059669; }
        .score-bad { background: #fee2e2; color: #dc2626; }

        .btn-delete {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: #fff;
            color: #ef4444;
            transition: all 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .btn-delete:hover {
            background: #ef4444;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(239, 68, 68, 0.3);
        }

        /* Floating Animation */
        .floating-shape {
            position: fixed;
            z-index: -1;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.6;
        }
        .shape-1 { background: #c7d2fe; width: 400px; height: 400px; top: -100px; left: -100px; }
        .shape-2 { background: #fbcfe8; width: 300px; height: 300px; bottom: -50px; right: -50px; }
    </style>
</head>
<body>

    <div class="floating-shape shape-1"></div>
    <div class="floating-shape shape-2"></div>

    <div class="container py-5">
        
        <!-- HEADER -->
        <div class="text-center mb-5">
            <h1 class="display-4 mb-2 header-gradient">Resultados de Exámenes</h1>
            <p class="text-muted lead">Panel de gestión simplificado (Sin Login)</p>
        </div>

        <!-- SEARCH -->
        <div class="row justify-content-center mb-5">
            <div class="col-md-8 col-lg-6">
                <form method="GET" class="position-relative">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="q" class="search-input w-100 shadow-sm" 
                           placeholder="Buscar por estudiante o examen..." 
                           value="<?= htmlspecialchars($busqueda) ?>">
                </form>
            </div>
        </div>

        <!-- ALERTAS -->
        <div id="alertContainer"></div>

        <!-- TABLA -->
        <div class="glass-card">
            <?php if (empty($resultados)): ?>
                <div class="text-center py-5">
                    <div class="mb-3 text-muted opacity-25">
                        <i class="fas fa-clipboard-list fa-4x"></i>
                    </div>
                    <h5 class="text-muted">No se encontraron resultados</h5>
                    <?php if ($busqueda): ?>
                        <p class="small">Intenta con otros términos de búsqueda.</p>
                        <a href="gestion_resultados.php" class="btn btn-sm btn-outline-secondary rounded-pill px-4">Limpiar filtro</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Examen</th>
                                <th>Fecha</th>
                                <th class="text-center">Puntaje</th>
                                <th class="text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $row): 
                                $isPass = $row['porcentaje'] >= 70;
                                $initial = strtoupper(substr($row['estudiante_nombre'], 0, 1));
                            ?>
                            <tr id="row-<?= $row['id'] ?>">
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3 shadow-sm"><?= $initial ?></div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['estudiante_nombre']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($row['estudiante_email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-primary"><?= htmlspecialchars($row['examen_titulo']) ?></div>
                                    <div class="small text-muted">ID: <?= $row['id'] ?></div>
                                </td>
                                <td>
                                    <div class="small fw-medium text-secondary">
                                        <i class="far fa-calendar-alt me-1"></i>
                                        <?= date('d M, Y', strtotime($row['fecha_realizacion'])) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= date('H:i', strtotime($row['fecha_realizacion'])) ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="score-badge <?= $isPass ? 'score-good' : 'score-bad' ?>">
                                        <?= $row['porcentaje'] ?>%
                                    </span>
                                    <div class="small text-muted mt-1"><?= $row['puntos_obtenidos'] ?> / <?= $row['puntos_totales_quiz'] ?> pts</div>
                                </td>
                                <td class="text-end">
                                    <button onclick="eliminarResultado(<?= $row['id'] ?>)" class="btn-delete ms-auto" title="Eliminar este resultado">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Script de eliminación -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function eliminarResultado(id) {
            Swal.fire({
                title: '¿Estás seguro?',
                text: "No podrás revertir esta acción. El resultado se borrará permanentemente.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                borderRadius: '15px'
            }).then((result) => {
                if (result.isConfirmed) {
                    
                    // AJAX Request
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('id', id);

                    fetch('gestion_resultados.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.ok) {
                            // Animate Removal
                            const row = document.getElementById('row-' + id);
                            row.style.transition = 'all 0.5s';
                            row.style.transform = 'translateX(100px)';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 500);

                            Swal.fire(
                                '¡Eliminado!',
                                'El resultado ha sido eliminado exitosamente.',
                                'success'
                            );
                        } else {
                            Swal.fire('Error', data.error || 'No se pudo eliminar', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Error de conexión con el servidor', 'error');
                    });
                }
            })
        }
    </script>
</body>
</html>
