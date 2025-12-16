<?php
// update_db.php
// Ejecuta este script una vez para actualizar la base de datos.
require 'db.php';

try {
    echo "Verificando columnas en la tabla 'resultados'...\n";
    echo "----------------------------------------\n";
    // // 1. Agregar columna 'intentos_tab_switch'
    // try {
    //     $pdo->exec("ALTER TABLE resultados ADD COLUMN intentos_tab_switch INTEGER DEFAULT 0");
    //     echo "✅ Columna 'intentos_tab_switch' agregada.\n";
    // } catch (PDOException $e) {
    //     // Ignorar si ya existe
    //     echo "ℹ️ La columna 'intentos_tab_switch' ya existe o no se pudo agregar.\n";
    // }

    // // 2. Agregar columna 'segundos_fuera'
    // try {
    //     $pdo->exec("ALTER TABLE resultados ADD COLUMN segundos_fuera INTEGER DEFAULT 0");
    //     echo "✅ Columna 'segundos_fuera' agregada.\n";
    // } catch (PDOException $e) {
    //     // Ignorar si ya existe
    //     echo "ℹ️ La columna 'segundos_fuera' ya existe o no se pudo agregar.\n";
    // }

    // 3. Agregar columna 'es_muestra' (resultado marcado como muestra)
    try {
        $pdo->exec("ALTER TABLE resultados ADD COLUMN es_muestra BOOLEAN DEFAULT false");
        echo "✅ Columna 'es_muestra' agregada.\n";
    } catch (PDOException $e) {
        echo "ℹ️ La columna 'es_muestra' ya existe o no se pudo agregar.\n";
    }

    // 4. Agregar columna 'revisado_manual' (resultado revisado por docente)
    try {
        $pdo->exec("ALTER TABLE resultados ADD COLUMN revisado_manual BOOLEAN DEFAULT false");
        echo "✅ Columna 'revisado_manual' agregada.\n";
    } catch (PDOException $e) {
        echo "ℹ️ La columna 'revisado_manual' ya existe o no se pudo agregar.\n";
    }

    // 5. Agregar columna 'observacion_docente' en resultados
    try {
        $pdo->exec("ALTER TABLE resultados ADD COLUMN observacion_docente TEXT");
        echo "✅ Columna 'observacion_docente' (resultados) agregada.\n";
    } catch (PDOException $e) {
        echo "ℹ️ La columna 'observacion_docente' (resultados) ya existe o no se pudo agregar.\n";
    }

    echo "Verificando columnas en la tabla 'respuestas_usuarios'...\n";

    // 6. Agregar columna 'es_correcta_manual' (override docente por pregunta)
    try {
        $pdo->exec("ALTER TABLE respuestas_usuarios ADD COLUMN es_correcta_manual BOOLEAN");
        echo "✅ Columna 'es_correcta_manual' agregada en respuestas_usuarios.\n";
    } catch (PDOException $e) {
        echo "ℹ️ La columna 'es_correcta_manual' ya existe o no se pudo agregar.\n";
    }

    // 7. Agregar columna 'observacion_docente' por pregunta
    try {
        $pdo->exec("ALTER TABLE respuestas_usuarios ADD COLUMN observacion_docente TEXT");
        echo "✅ Columna 'observacion_docente' agregada en respuestas_usuarios.\n";
    } catch (PDOException $e) {
        echo "ℹ️ La columna 'observacion_docente' (respuestas_usuarios) ya existe o no se pudo agregar.\n";
    }

    echo "Actualización completada.";

} catch (PDOException $e) {
    die("Error general: " . $e->getMessage());
}
?>
