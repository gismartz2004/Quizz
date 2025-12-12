<?php
session_start();
require '../db.php'; // Adjust path since we are in /api/

// 1. SEGURIDAD
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    http_response_code(403);
    echo "Acceso Denegado";
    exit;
}

// ==========================================================
// ACTION: PREVIEW UIZ
// ==========================================================
if (isset($_GET['action']) && $_GET['action'] === 'preview' && isset($_GET['id'])) {
    $quiz_id = $_GET['id'];
    
    // Traer preguntas
    $stmt = $pdo->prepare("SELECT * FROM preguntas WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Traer opciones para cada pregunta
    foreach($preguntas as &$p) {
        $stmtR = $pdo->prepare("SELECT * FROM opciones WHERE pregunta_id = ?");
        $stmtR->execute([$p['id']]);
        $p['respuestas'] = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Generar HTML de respuesta para el modal
    if(empty($preguntas)) {
        echo '<p class="text-center text-muted">Este quiz no tiene preguntas guardadas.</p>';
    } else {
        foreach($preguntas as $i => $p) {
            echo '<div class="p-row">';
            echo '<strong>' . ($i+1) . '. ' . htmlspecialchars($p['texto']) . '</strong>';
            
            // Check for image in question
            if(!empty($p['imagen'])) {
                echo '<br><img src="assets/images/'.htmlspecialchars($p['imagen']).'" style="max-height:100px; margin-top:5px;">';
            }

            foreach($p['respuestas'] as $r) {
                $class = $r['es_correcta'] ? 'correct' : '';
                $icon = $r['es_correcta'] ? 'fas fa-check-circle' : 'far fa-circle';
                echo '<div class="p-opt ' . $class . '">';
                echo '<i class="' . $icon . '"></i> ' . htmlspecialchars($r['texto']);
                echo '</div>';
            }
            echo '</div>';
        }
    }
    exit;
}
?>
