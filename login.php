<?php
session_start();

// Cargar usuarios desde el archivo JSON
$usuarios = json_decode(file_get_contents('usuarios.json'), true);

// Procesar el formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    foreach ($usuarios as $usuario) {
        if ($usuario['email'] === $email && $usuario['password'] === $password) {
            $_SESSION['usuario'] = $usuario;
            
            // Redirección según rol
            if (isset($usuario['rol']) && $usuario['rol'] === 'profesor') {
                header('Location: profesor.php');
            } else {
                header('Location: index.php');
            }
            exit();
        }
    }
    
    $error = "Email o contraseña incorrectos";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Quiz</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="login-form">
        <h2>Iniciar sesión</h2>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>