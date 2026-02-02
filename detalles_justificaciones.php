<?php
require 'db.php';

if (!isset($_GET['resultado_id']) || !is_numeric($_GET['resultado_id'])) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Parámetro inválido: resultado_id.</div>';
    exit;
}

$resultadoId = (int)$_GET['resultado_id'];

// Obtener flag 'es_muestra' del resultado
$esMuestra = false;
try {
  $stmtM = $pdo->prepare("SELECT es_muestra FROM resultados WHERE id = :id");
  $stmtM->execute(['id' => $resultadoId]);
  $rowM = $stmtM->fetch(PDO::FETCH_ASSOC);
  if ($rowM && isset($rowM['es_muestra'])) {
    $esMuestra = (bool)$rowM['es_muestra'];
  }
} catch (Exception $e) {
  // Silencio: si falla, solo no mostramos el check prechequeado
}

$sql = "
    SELECT 
        p.id AS pregunta_id,
        p.texto AS pregunta,
        p.requiere_justificacion,
        ru.justificacion,
        o.texto AS respuesta_elegida
    FROM respuestas_usuarios ru
    JOIN preguntas p ON ru.pregunta_id = p.id
    LEFT JOIN opciones o ON ru.opcion_id = o.id
    WHERE ru.resultado_id = :resultado_id
      AND (
            p.requiere_justificacion IS TRUE 
         OR p.requiere_justificacion::text IN ('true', 't', '1', 'on')
      )
      AND COALESCE(NULLIF(TRIM(ru.justificacion), ''), NULL) IS NOT NULL
    ORDER BY p.id ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['resultado_id' => $resultadoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Error SQL: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

if (!$rows) {
    // Incluso si no hay justificaciones, mostramos el control de muestra
    echo '<div class="d-flex justify-content-between align-items-center mb-3">'
        . '<h6 class="m-0">Justificaciones</h6>'
        . '<div class="form-check form-switch">'
        . '  <input class="form-check-input" type="checkbox" id="chkMuestra" ' . ($esMuestra ? 'checked' : '') . '>'
        . '  <label class="form-check-label" for="chkMuestra">Tomado como muestra</label>'
        . '</div>'
        . '</div>';
    echo '<div class="text-center py-4 text-muted">No hay preguntas con justificación para este examen.</div>';
    echo '<script>(function(){const c=document.getElementById("chkMuestra"); if(!c) return; c.addEventListener("change",()=>{const fd=new FormData(); fd.append("resultado_id","' . $resultadoId . '"); fd.append("es_muestra", c.checked?"1":"0"); fetch("actualizar_muestra.php",{method:"POST",body:fd}).then(r=>r.json()).then(j=>{if(!j.ok){alert("No se pudo actualizar: "+(j.error||""));}}).catch(()=>alert("Error de red al actualizar muestra"));});})();</script>';
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="m-0">Justificaciones</h6>
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" id="chkMuestra" <?= $esMuestra ? 'checked' : '' ?>>
    <label class="form-check-label" for="chkMuestra">Tomado como muestra</label>
  </div>
</div>

<div class="list-group">
<?php foreach ($rows as $i => $r): ?>
  <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <div class="fw-bold mb-1"><?= ($i+1) ?>. <?= htmlspecialchars($r['pregunta']) ?></div>
        <?php if (!empty($r['respuesta_elegida'])): ?>
          <div class="small text-muted mb-2">Respuesta: <?= htmlspecialchars($r['respuesta_elegida']) ?></div>
        <?php endif; ?>
        <div class="p-2 rounded" style="background:#fffbeb; border:1px solid #fde68a;">
          <span class="fw-bold">Justificación:</span>
          <span><?= $r['justificacion'] ? htmlspecialchars($r['justificacion']) : '<em class="text-muted">Sin justificación proporcionada</em>' ?></span>
        </div>
      </div>
      <span class="badge bg-light text-secondary border">Requiere justificación</span>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
(function(){
  const c = document.getElementById('chkMuestra');
  if (!c) return;
  c.addEventListener('change', () => {
    const fd = new FormData();
    fd.append('resultado_id', '<?= $resultadoId ?>');
    fd.append('es_muestra', c.checked ? '1' : '0');
    fetch('actualizar_muestra.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(j => { if(!j.ok){ alert('No se pudo actualizar: ' + (j.error||'')); } })
      .catch(() => alert('Error de red al actualizar muestra'));
  });
})();
</script>
