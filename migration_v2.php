<?php
/**
 * migration_v2.php
 * Script para migrar esquema y datos de la base de datos antigua a la nueva.
 */

set_time_limit(0); // Sin límite de tiempo

// 1. Credenciales de la base de datos ANTIGUA
$old_host = 'ep-mute-base-aduqpged-pooler.c-2.us-east-1.aws.neon.tech';
$old_db   = 'neondb';
$old_user = 'neondb_owner';
$old_pass = 'npg_ykdPhQnR50gZ';
$old_port = '5432';

// 2. Conectar a la base de datos NUEVA (usando db.php ya actualizado)
require 'db.php';
$new_pdo = $pdo; 

try {
    echo "Conectando a base de datos antigua...\n";
    $old_dsn = "pgsql:host=$old_host;port=$old_port;dbname=$old_db;sslmode=require";
    $old_pdo = new PDO($old_dsn, $old_user, $old_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✅ Conexión antigua exitosa.\n\n";

    // 3. Tablas a migrar (en orden de dependencia)
    $tables = ['usuarios', 'quizzes', 'preguntas', 'opciones', 'resultados', 'respuestas_usuarios'];

    foreach ($tables as $table) {
        echo "--- Migrando tabla: $table ---\n";

        // Obtener esquema básico (esto es una simplificación, idealmente se usa pg_dump)
        // Pero como no tenemos acceso a la shell de postgres, recreamos lo esencial
        
        echo "Recreando esquema...\n";
        
        // Deshabilitar FK temporalmente en la nueva conexión si es necesario, 
        // o simplemente limpiar si existe
        $new_pdo->exec("DROP TABLE IF EXISTS $table CASCADE");

        if ($table === 'usuarios') {
            $sql = "CREATE TABLE usuarios (
                id SERIAL PRIMARY KEY,
                nombre TEXT,
                email TEXT,
                password TEXT,
                rol TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } elseif ($table === 'quizzes') {
            $sql = "CREATE TABLE quizzes (
                id SERIAL PRIMARY KEY,
                titulo TEXT,
                descripcion TEXT,
                valor_total INTEGER,
                tiempo_limite INTEGER,
                estado TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } elseif ($table === 'preguntas') {
            $sql = "CREATE TABLE preguntas (
                id SERIAL PRIMARY KEY,
                quiz_id INTEGER REFERENCES quizzes(id) ON DELETE CASCADE,
                pregunta TEXT,
                tipo TEXT,
                valor INTEGER,
                imagen TEXT,
                orden INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } elseif ($table === 'opciones') {
            $sql = "CREATE TABLE opciones (
                id SERIAL PRIMARY KEY,
                pregunta_id INTEGER REFERENCES preguntas(id) ON DELETE CASCADE,
                opcion TEXT,
                es_correcta BOOLEAN,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } elseif ($table === 'resultados') {
            $sql = "CREATE TABLE resultados (
                id SERIAL PRIMARY KEY,
                usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
                quiz_id INTEGER REFERENCES quizzes(id) ON DELETE CASCADE,
                puntos_obtenidos INTEGER,
                puntos_totales_quiz INTEGER,
                porcentaje INTEGER,
                edad INTEGER,
                genero TEXT,
                residencia TEXT,
                grado TEXT,
                paralelo TEXT,
                jornada TEXT,
                discapacidad TEXT,
                intentos_tab_switch INTEGER DEFAULT 0,
                segundos_fuera INTEGER DEFAULT 0,
                es_muestra BOOLEAN DEFAULT false,
                revisado_manual BOOLEAN DEFAULT false,
                observacion_docente TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } elseif ($table === 'respuestas_usuarios') {
            $sql = "CREATE TABLE respuestas_usuarios (
                id SERIAL PRIMARY KEY,
                resultado_id INTEGER REFERENCES resultados(id) ON DELETE CASCADE,
                pregunta_id INTEGER,
                opcion_id INTEGER,
                justificacion TEXT,
                es_correcta_manual BOOLEAN,
                observacion_docente TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        }

        $new_pdo->exec($sql);
        echo "✅ Esquema creado.\n";

        // 4. Copiar Datos
        echo "Copiando datos...\n";
        $stmt = $old_pdo->query("SELECT * FROM $table");
        $rows = $stmt->fetchAll();

        if (count($rows) > 0) {
            $columns = array_keys($rows[0]);
            $colList = implode(',', $columns);
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            
            $insertSql = "INSERT INTO $table ($colList) VALUES ($placeholders)";
            $insertStmt = $new_pdo->prepare($insertSql);

            foreach ($rows as $row) {
                $insertStmt->execute(array_values($row));
            }
            echo "✅ " . count($rows) . " filas copiadas.\n";
        } else {
            echo "ℹ️ Tabla vacía.\n";
        }
        echo "\n";
    }

    echo "🎉 Migración completada con éxito.\n";

} catch (PDOException $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
}
?>
