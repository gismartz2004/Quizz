<?php
// db.php
$host = 'localhost'; 
$db   = 'sistema_quizzes';
$user = 'root'; // Cambia esto si tu hosting te dio otro usuario
$pass = '';     // Cambia esto si tu hosting te dio contraseña
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>