<?php
/**
 * Script de Sincronización con Google Analytics
 * Envía todos los resultados de exámenes a GA4
 */

require 'db.php';
require 'ga_api.php';

// Inicializar API
$ga = new GoogleAnalyticsAPI();

// Obtener todos los resultados
try {
    $sql = "SELECT r.*, u.nombre as usuario_nombre, u.email, q.titulo as quiz_titulo
            FROM resultados r
            JOIN usuarios u ON r.usuario_id = u.id
            JOIN quizzes q ON r.quiz_id = q.id
            ORDER BY r.fecha_realizacion DESC";
    
    $stmt = $pdo->query($sql);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($resultados);
    $enviados = 0;
    $errores = 0;

    echo "=== Sincronización con Google Analytics ===\n";
    echo "Total de registros a enviar: $total\n\n";

    foreach ($resultados as $row) {
        $success = $ga->enviarExamenCompletado(
            $row['usuario_id'],
            $row['usuario_nombre'],
            $row['puntos_obtenidos'],
            $row['paralelo'] ?? 'N/A',
            $row['quiz_titulo']
        );

        if ($success) {
            $enviados++;
            echo "✓ Enviado: {$row['usuario_nombre']} - {$row['quiz_titulo']}\n";
        } else {
            $errores++;
            echo "✗ Error: {$row['usuario_nombre']} - {$row['quiz_titulo']}\n";
        }

        // Pequeña pausa para no saturar la API
        usleep(100000); // 0.1 segundos
    }

    echo "\n=== Resumen ===\n";
    echo "Total: $total\n";
    echo "Enviados exitosamente: $enviados\n";
    echo "Errores: $errores\n";

    if ($errores > 0) {
        echo "\nNOTA: Si todos fallaron, verifica que hayas configurado MEASUREMENT_ID y API_SECRET en ga_api.php\n";
    }

} catch (PDOException $e) {
    die("Error de base de datos: " . $e->getMessage());
}
?>
