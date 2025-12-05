<?php
session_start();
require 'db.php'; // Conexión a la base de datos SQL

// Verificar si el usuario ya está logueado
if (isset($_SESSION['usuario'])) {
    $rol = $_SESSION['usuario']['rol'] ?? 'estudiante';
    header('Location: ' . ($rol === 'profesor' ? 'profesor.php' : 'index.php'));
    exit();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Por favor completa todos los campos.";
    } else {
        // CONSULTA SQL SEGURA (Prepared Statement)
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        // Verificar contraseña
        // Nota: Si en el futuro usas encriptación, cambia esto por: password_verify($password, $usuario['password'])
        if ($usuario && $usuario['password'] === $password) {
            
            // Guardar datos en sesión
            $_SESSION['usuario'] = $usuario;

            // Redirección según rol
            if (isset($usuario['rol']) && $usuario['rol'] === 'profesor') {
                header('Location: profesor.php');
            } else {
                header('Location: index.php');
            }
            exit();
        } else {
            $error = "Credenciales incorrectas. Inténtalo de nuevo.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | Plataforma Quiz</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
            --bg-color: #f3f4f6;
            --text-color: #1f2937;
            --card-bg: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: var(--text-color);
        }

        .login-container {
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 400px;
            text-align: center;
            animation: fadeIn 0.5s ease-out;
        }

        .logo-container {
            width: 100px;
            height: 100px;
            margin: 0 auto 1.5rem auto;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background-color: #e0e7ff;
            overflow: hidden;
        }

        .logo-container img {
            width: 80%;
            height: auto;
            object-fit: contain;
        }

        h2 { margin-bottom: 0.5rem; font-weight: 600; color: #111827; }
        
        .subtitle { color: #6b7280; font-size: 0.9rem; margin-bottom: 2rem; }

        .form-group { margin-bottom: 1.5rem; text-align: left; }

        .form-group label {
            display: block; margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 500; color: #374151;
        }

        .input-wrapper { position: relative; }

        .input-wrapper i {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #9ca3af;
        }

        .form-group input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        button:hover { background-color: var(--primary-hover); }

        .error-msg {
            background-color: #fee2e2;
            color: #991b1b;
            padding: 10px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            border: 1px solid #fecaca;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="logo-container">
            <?php if(file_exists('./assets/images/images__1_-removebg-preview (1).png')): ?>
                <img src="./assets/images/images__1_-removebg-preview (1).png" alt="Logo Escuela">
            <?php else: ?>
                <i class="fas fa-graduation-cap fa-3x" style="color: var(--primary-color);"></i>
            <?php endif; ?>
        </div>

        <h2>Bienvenido</h2>
        <p class="subtitle">Ingresa tus credenciales para continuar</p>
        
        <?php if ($error): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="email">Correo Electrónico</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="ejemplo@correo.com" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
            </div>
            
            <button type="submit">Ingresar <i class="fas fa-arrow-right" style="margin-left: 5px;"></i></button>
        </form>
    </div>

</body>
</html>