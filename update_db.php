<?php
// update_db.php
// Ejecuta este script una vez para actualizar la base de datos.
require 'db.php';

try {
    echo "Verificando columnas en la tabla 'resultados'...\n";

    // 1. Agregar columna 'intentos_tab_switch'
    try {
        $pdo->exec("ALTER TABLE resultados ADD COLUMN intentos_tab_switch INTEGER DEFAULT 0");
        echo "✅ Columna 'intentos_tab_switch' agregada.\n";
    } catch (PDOException $e) {
        // Ignorar si ya existe
        echo "ℹ️ La columna 'intentos_tab_switch' ya existe o no se pudo agregar.\n";
    }

    // 2. Agregar columna 'segundos_fuera'
    try {
        $pdo->exec("ALTER TABLE resultados ADD COLUMN segundos_fuera INTEGER DEFAULT 0");
        echo "✅ Columna 'segundos_fuera' agregada.\n";
    } catch (PDOException $e) {
        // Ignorar si ya existe
        echo "ℹ️ La columna 'segundos_fuera' ya existe o no se pudo agregar.\n";
    }

    echo "Actualización completada.";

} catch (PDOException $e) {
    die("Error general: " . $e->getMessage());
}
?>
