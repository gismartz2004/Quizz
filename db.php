<?php
// db.php - Conexión Optimizada para Latencia

$host = '195.35.61.56'; // IP de Hostinger
$db   = 'u578800031_Quizz';
$user = 'u578800031_Sistema_quizz';
$pass = 'Desarrollosoftware2025';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // ESTA LÍNEA ES LA MAGIA: Mantiene la conexión abierta
        PDO::ATTR_PERSISTENT => true 
    ]);
} catch (\PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>