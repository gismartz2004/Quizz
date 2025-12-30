<?php
// Aumentar límite de memoria para generación de PDF
ini_set('memory_limit', '512M');

require 'vendor/autoload.php';
require 'db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. OBTENER DATOS CON FILTROS
$quiz_id      = isset($_GET['quiz_id']) && is_numeric($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : null;
$fecha_desde  = $_GET['fecha_desde'] ?? '';
$fecha_hasta  = $_GET['fecha_hasta'] ?? '';
$genero       = $_GET['genero'] ?? '';
$edad         = isset($_GET['edad']) && is_numeric($_GET['edad']) ? (int)$_GET['edad'] : '';
$paralelo     = $_GET['paralelo'] ?? '';
$filtro_muestra = isset($_GET['muestra']) ? $_GET['muestra'] : '';
$integridad   = $_GET['integridad'] ?? '';
$mes = isset($_GET['mes']) ? $_GET['mes'] : date('m');
$anio = isset($_GET['anio']) ? $_GET['anio'] : date('Y');

// Construir consulta SQL con filtros
$sql = "SELECT u.nombre, u.email, r.puntos_obtenidos, r.fecha_realizacion, r.paralelo, r.intentos_tab_switch, r.genero, r.edad
        FROM resultados r
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE 1=1";
$params = [];

// Aplicar filtros
if ($mes) {
    $sql .= " AND EXTRACT(MONTH FROM fecha_realizacion) = :mes";
    $params['mes'] = $mes;
}
if ($anio) {
    $sql .= " AND EXTRACT(YEAR FROM fecha_realizacion) = :anio";
    $params['anio'] = $anio;
}
if ($quiz_id) {
    $sql .= " AND r.quiz_id = :quiz_id";
    $params['quiz_id'] = $quiz_id;
}
if ($fecha_desde) {
    $sql .= " AND r.fecha_realizacion >= :fecha_desde";
    $params['fecha_desde'] = $fecha_desde . ' 00:00:00';
}
if ($fecha_hasta) {
    $sql .= " AND r.fecha_realizacion <= :fecha_hasta";
    $params['fecha_hasta'] = $fecha_hasta . ' 23:59:59';
}
if ($genero) {
    $sql .= " AND r.genero = :genero";
    $params['genero'] = $genero;
}
if ($edad) {
    $sql .= " AND r.edad = :edad";
    $params['edad'] = $edad;
}
if ($paralelo) {
    $sql .= " AND r.paralelo = :paralelo";
    $params['paralelo'] = $paralelo;
}
if ($filtro_muestra === 'si') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = TRUE";
} elseif ($filtro_muestra === 'no') {
    $sql .= " AND COALESCE(r.es_muestra, FALSE) = FALSE";
}

$sql .= " ORDER BY r.fecha_realizacion DESC";


// Consultas
try {
    // Totales
    $stmt_count = $pdo->prepare("SELECT COUNT(DISTINCT r.usuario_id) FROM resultados r WHERE 1=1" . substr($sql, strpos($sql, ' AND'), strpos($sql, 'ORDER BY') - strpos($sql, ' AND')));
    $stmt_count->execute($params);
    $total_estudiantes = $stmt_count->fetchColumn();

    $stmt_avg = $pdo->prepare("SELECT AVG((r.puntos_obtenidos / 250) * 100) FROM resultados r WHERE 1=1" . substr($sql, strpos($sql, ' AND'), strpos($sql, 'ORDER BY') - strpos($sql, ' AND')));
    $stmt_avg->execute($params);
    $promedio = round($stmt_avg->fetchColumn(), 2);

    // Lista Detallada (limitada a 500 resultados para evitar problemas de memoria)
    $sql_limited = $sql . " LIMIT 500";
    $stmt = $pdo->prepare($sql_limited);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_resultados = count($resultados);

} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 2. CONSTRUIR HTML DEL REPORTE
$mesNombre = date('F', mktime(0, 0, 0, $mes, 10)); // Simple mes en ingles o array
$meses = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$nombreMes = $meses[str_pad($mes, 2, '0', STR_PAD_LEFT)] ?? $mes;

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .logo { font-size: 24px; font-weight: bold; color: #667eea; }
        .sub { font-size: 14px; color: #777; }
        .stats-box { border: 1px solid #ddd; padding: 15px; background: #f9f9f9; margin-bottom: 20px; }
        .stat { display: inline-block; width: 30%; text-align: center; }
        .stat-val { font-size: 20px; font-weight: bold; display: block; }
        .stat-label { font-size: 12px; color: #555; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #667eea; color: white; padding: 8px; text-align: left; }
        td { border-bottom: 1px solid #eee; padding: 8px; }
        .badge-danger { color: red; font-weight: bold; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #aaa; padding: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">Academia Illingworth</div>
        <div class="sub">Reporte Oficial de Resultados - Décimo Grado</div>
        <div class="sub">Periodo: <?= $nombreMes ?> <?= $anio ?></div>
        <?php if ($total_resultados >= 500): ?>
        <div class="sub" style="color: #dc2626; font-weight: bold; margin-top: 10px;">
            ⚠ Mostrando los primeros 500 resultados de <?= $total_estudiantes ?> estudiantes
        </div>
        <?php endif; ?>
    </div>

    <div class="stats-box">
        <div class="stat">
            <span class="stat-val"><?= $total_estudiantes ?></span>
            <span class="stat-label">Alumnos Evaluados</span>
        </div>
        <div class="stat">
            <span class="stat-val"><?= $promedio ?>/100</span>
            <span class="stat-label">Nota Promedio</span>
        </div>
        <div class="stat">
            <span class="stat-val">Guayaquil</span>
            <span class="stat-label">Sede</span>
        </div>
    </div>

    <h3>Detalle de Estudiantes</h3>
    <table>
        <thead>
            <tr>
                <th>Estudiante</th>
                <th>Paralelo</th>
                <th>Fecha</th>
                <th>Puntaje</th>
                <th>Nota / 100</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resultados as $row): ?>
            <?php 
                $nota100 = round(($row['puntos_obtenidos'] / 250) * 100, 2);
                $alerta = $row['intentos_tab_switch'] > 1 ? '<span class="badge-danger">Alerta</span>' : 'Ok';
            ?>
            <tr>
                <td><?= htmlspecialchars($row['nombre']) ?><br><small style="color:#777"><?= $row['email'] ?></small></td>
                <td><?= htmlspecialchars($row['paralelo']) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($row['fecha_realizacion'])) ?></td>
                <td><?= $row['puntos_obtenidos'] ?> / 250</td>
                <td><strong><?= $nota100 ?></strong></td>
                <td><?= $alerta ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        Generado automáticamente por Intelligence Center | Google Analytics Powered Reporting (Simulated) | <?= date('Y-m-d H:i:s') ?>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

// 3. GENERAR PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Forzar descarga
$dompdf->stream("Reporte_Academia_" . date('Ymd_His') . ".pdf", ["Attachment" => true]);
?>
