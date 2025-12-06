<?php
// db.php - Conexión a Neon.tech (PostgreSQL)

// Tus credenciales de Neon
$host = 'ep-mute-base-aduqpged-pooler.c-2.us-east-1.aws.neon.tech';
$db   = 'neondb';
$user = 'neondb_owner';
$pass = 'npg_ykdPhQnR50gZ';
$port = '5432';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false // Desactivado para evitar errores de transacción en Neon
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (\PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>