<?php
// db.php
$host = '195.35.61.56';
$db   = 'u578800031_Quizz';          // Verifica que sea exactamente este nombre
$user = 'u578800031_Sistema_quizz';  // Verifica que sea exactamente este usuario
$pass = 'Desarrollosoftware2025';    // La contraseña que acabas de restablecer
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Si llegas aquí, conectó bien.
} catch (\PDOException $e) {
    // Muestra el error exacto para depurar (solo mientras arreglas esto)
    die("Error de conexión: " . $e->getMessage());
}
?>