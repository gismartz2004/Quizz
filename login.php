<?php
require_once 'includes/session.php';
require 'db.php';

// Si ya está logueado, redirigir
if (isset($_SESSION['usuario'])) {
    $rol = $_SESSION['usuario']['rol'] ?? 'estudiante';
    header('Location: ' . ($rol === 'profesor' ? 'profesor.php' : 'index.php'));
    exit();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- LÓGICA PARA ESTUDIANTES (SOLO CÓDIGO) ---
    if (isset($_POST['login_type']) && $_POST['login_type'] === 'student') {
        $codigo = trim($_POST['student_code'] ?? '');
        
        if (empty($codigo)) {
            $error = "Por favor ingresa tu código.";
        } else {
            // Buscamos al estudiante donde la contraseña sea igual al código
            // (Ya que en la importación definimos Password = Código)
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE password = ? AND rol = 'estudiante' LIMIT 1");
            $stmt->execute([$codigo]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario) {
                $_SESSION['usuario'] = $usuario;
                header('Location: index.php');
                exit();
            } else {
                $error = "Código no encontrado. Verifica tus datos.";
            }
        }
    }
    
    // --- LÓGICA PARA PROFESORES (EMAIL Y PASSWORD) ---
    elseif (isset($_POST['login_type']) && $_POST['login_type'] === 'teacher') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = "Completa todos los campos.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND rol = 'profesor' LIMIT 1");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario && $usuario['password'] === $password) {
                $_SESSION['usuario'] = $usuario;
                header('Location: profesor.php');
                exit();
            } else {
                $error = "Credenciales incorrectas.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso | AulaVirtual</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4f46e5;
            --bg-body: #f3f4f6;
            --text-main: #1f2937;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; color: var(--text-main);
        }

        .login-card {
            background: white; padding: 40px; border-radius: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            width: 100%; max-width: 420px;
            animation: slideUp 0.4s ease-out;
        }

        .logo-area { text-align: center; margin-bottom: 30px; }
        .logo-icon { font-size: 3rem; color: var(--primary); margin-bottom: 10px; }
        .app-name { font-size: 1.5rem; font-weight: 800; color: #111827; }

        /* Pestañas (Tabs) */
        .tabs {
            display: flex; background: #f1f5f9; padding: 4px; border-radius: 12px; margin-bottom: 25px;
        }
        .tab-btn {
            flex: 1; padding: 10px; border: none; background: transparent;
            border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem;
            color: #64748b; transition: 0.2s;
        }
        .tab-btn.active {
            background: white; color: var(--primary);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        /* Formularios */
        .form-group { margin-bottom: 20px; text-align: left; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #374151; }
        
        .input-wrapper { position: relative; }
        .input-wrapper i {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: #9ca3af; font-size: 1.1rem;
        }
        
        input {
            width: 100%; padding: 14px 14px 14px 45px;
            border: 2px solid #e5e7eb; border-radius: 12px;
            font-size: 1rem; transition: 0.2s; outline: none; font-family: inherit;
        }
        input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }

        .btn-submit {
            width: 100%; padding: 14px; background: var(--primary);
            color: white; border: none; border-radius: 12px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: 0.2s; margin-top: 10px;
        }
        .btn-submit:hover { background: #4338ca; transform: translateY(-2px); }

        .error-msg {
            background: #fee2e2; color: #991b1b; padding: 12px;
            border-radius: 8px; font-size: 0.9rem; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }

        .hidden { display: none; }

        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo-area">
            <?php if(file_exists('assets/logo.png')): ?>
                <img src="assets/logo.png" alt="Logo" style="width: 80px; margin-bottom: 10px;">
            <?php else: ?>
                <i class="fas fa-graduation-cap logo-icon"></i>
            <?php endif; ?>
            <div class="app-name">AulaVirtual</div>
        </div>

        <?php if ($error): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('student')">Soy Estudiante</button>
            <button class="tab-btn" onclick="switchTab('teacher')">Soy Profesor</button>
        </div>

        <form method="post" id="form-student">
            <input type="hidden" name="login_type" value="student">
            <div class="form-group">
                <label>Código de Estudiante</label>
                <div class="input-wrapper">
                    <i class="fas fa-id-card"></i>
                    <input type="text" name="student_code" placeholder="Ej: 155585" required autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn-submit">Ingresar <i class="fas fa-arrow-right"></i></button>
        </form>

        <form method="post" id="form-teacher" class="hidden">
            <input type="hidden" name="login_type" value="teacher">
            <div class="form-group">
                <label>Correo Institucional</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="profe@ejemplo.com">
                </div>
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="••••••••">
                </div>
            </div>
            <button type="submit" class="btn-submit">Acceder <i class="fas fa-key"></i></button>
        </form>
    </div>

    <script>
        function switchTab(type) {
            const studentForm = document.getElementById('form-student');
            const teacherForm = document.getElementById('form-teacher');
            const tabs = document.querySelectorAll('.tab-btn');

            if (type === 'student') {
                studentForm.classList.remove('hidden');
                teacherForm.classList.add('hidden');
                tabs[0].classList.add('active');
                tabs[1].classList.remove('active');
                // Quitar required del otro form para evitar errores
                teacherForm.querySelector('input[type="email"]').required = false;
                teacherForm.querySelector('input[type="password"]').required = false;
                studentForm.querySelector('input').required = true;
            } else {
                studentForm.classList.add('hidden');
                teacherForm.classList.remove('hidden');
                tabs[0].classList.remove('active');
                tabs[1].classList.add('active');
                // Ajustar required
                studentForm.querySelector('input').required = false;
                teacherForm.querySelector('input[type="email"]').required = true;
                teacherForm.querySelector('input[type="password"]').required = true;
            }
        }
    </script>

</body>
</html>