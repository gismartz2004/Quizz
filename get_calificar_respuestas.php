<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'profesor') {
    http_response_code(403);
    echo '<div class="alert alert-danger">No autorizado.</div>';
    exit;
}

if (!isset($_GET['resultado_id']) || !is_numeric($_GET['resultado_id'])) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Parámetro inválido: resultado_id.</div>';
    exit;
}

$rid = (int)$_GET['resultado_id'];

// Traer encabezado del resultado
try {
    $stmtR = $pdo->prepare("SELECT r.id, r.quiz_id, r.usuario_id, r.puntos_obtenidos, r.puntos_totales_quiz, r.porcentaje, r.observacion_docente, q.titulo
                              FROM resultados r JOIN quizzes q ON r.quiz_id = q.id WHERE r.id = ?");
    $stmtR->execute([$rid]);
    $res = $stmtR->fetch(PDO::FETCH_ASSOC);
    if (!$res) { throw new Exception('Resultado no encontrado'); }
} catch (Exception $e) {
    http_response_code(404);
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// Traer respuestas del usuario para este resultado
$sql = "
    SELECT 
        ru.id AS ru_id,
        p.id AS pregunta_id,
        p.texto AS pregunta,
        p.valor AS puntos_pregunta,
        o.texto AS respuesta_elegida,
        o.es_correcta AS es_correcta_auto,
        ru.es_correcta_manual,
        ru.observacion_docente
    FROM respuestas_usuarios ru
    JOIN preguntas p ON ru.pregunta_id = p.id
    LEFT JOIN opciones o ON ru.opcion_id = o.id
    WHERE ru.resultado_id = ?
    ORDER BY p.id ASC
";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$rid]);
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  // Fallback: si las columnas manuales aún no existen (no se corrió update_db.php)
  // usamos nulls para que la UI funcione en modo solo-lectura
  $sql_fallback = "
    SELECT 
      ru.id AS ru_id,
      p.id AS pregunta_id,
      p.texto AS pregunta,
      p.valor AS puntos_pregunta,
      o.texto AS respuesta_elegida,
      o.es_correcta AS es_correcta_auto,
      NULL::boolean AS es_correcta_manual,
      NULL::text AS observacion_docente
    FROM respuestas_usuarios ru
    JOIN preguntas p ON ru.pregunta_id = p.id
    LEFT JOIN opciones o ON ru.opcion_id = o.id
    WHERE ru.resultado_id = ?
    ORDER BY p.id ASC
  ";
  try {
    $stmt = $pdo->prepare($sql_fallback);
    $stmt->execute([$rid]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e2) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Error cargando respuestas: ' . htmlspecialchars($e2->getMessage()) . '</div>';
    echo '<div class="alert alert-warning mt-2">Si estás actualizando a la nueva calificación manual, ejecuta <code>php update_db.php</code> una vez para agregar columnas.</div>';
    exit;
  }
}

if (!$items) {
    echo '<div class="alert alert-warning">No hay respuestas registradas para calificar.</div>';
    exit;
}

?>
<div class="mb-3">
  <h6 class="mb-1">Calificar Examen: <?= htmlspecialchars($res['titulo']) ?></h6>
  <div class="small text-muted">Resultado #<?= (int)$res['id'] ?> · Puntaje actual: <?= (int)$res['puntos_obtenidos'] ?>/<?= (int)$res['puntos_totales_quiz'] ?></div>
</div>

<form id="formCalificacion">
  <input type="hidden" name="resultado_id" value="<?= (int)$rid ?>">
  <div class="list-group mb-3">
  <?php foreach ($items as $i => $it): 
        $pre = isset($it['es_correcta_manual']) && $it['es_correcta_manual'] !== null
              ? (bool)$it['es_correcta_manual']
              : ($it['es_correcta_auto'] === true || $it['es_correcta_auto'] === 'true' || $it['es_correcta_auto'] === 1 || $it['es_correcta_auto'] === '1');
  ?>
    <div class="list-group-item">
      <div class="d-flex justify-content-between align-items-start">
        <div class="flex-grow-1 pe-3">
          <div class="fw-bold mb-1"><?= ($i+1) ?>. <?= htmlspecialchars($it['pregunta']) ?>
            <span class="badge bg-light text-secondary border ms-2"><?= (int)$it['puntos_pregunta'] ?> pts</span>
          </div>
          <div class="small mb-2">Respuesta: <strong><?= htmlspecialchars($it['respuesta_elegida'] ?? 'Sin responder') ?></strong></div>
          <div class="d-flex align-items-center gap-3 mb-2">
            <label class="form-check form-check-inline">
              <input class="form-check-input item-correcta" type="radio" name="items[<?= (int)$it['ru_id'] ?>][estado]" value="1" <?= $pre ? 'checked' : '' ?>>
              <span class="form-check-label">Correcta (+<?= (int)$it['puntos_pregunta'] ?>)</span>
            </label>
            <label class="form-check form-check-inline">
              <input class="form-check-input item-correcta" type="radio" name="items[<?= (int)$it['ru_id'] ?>][estado]" value="0" <?= !$pre ? 'checked' : '' ?>>
              <span class="form-check-label">Incorrecta (+0)</span>
            </label>
          </div>
          <div>
            <input type="text" class="form-control form-control-sm" name="items[<?= (int)$it['ru_id'] ?>][obs]" placeholder="Observación (opcional)" value="<?= htmlspecialchars($it['observacion_docente'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="mb-3">
    <label class="form-label small fw-bold text-muted">Observación general</label>
    <textarea name="observacion_general" class="form-control" rows="2" placeholder="Comentarios del docente sobre el examen (opcional)"><?= htmlspecialchars($res['observacion_docente'] ?? '') ?></textarea>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <div>
      <span class="small text-muted">Puntaje total manual: </span>
      <span class="fw-bold" id="puntajeTotal">0</span>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="guardarCalificacion(<?= (int)$rid ?>)">
      <i class="fas fa-save me-1"></i> Guardar Calificación
    </button>
  </div>
</form>

<script>
(function(){
  function recalc() {
    let total = 0;
    document.querySelectorAll('#formCalificacion .list-group-item').forEach(item => {
      const ptsBadge = item.querySelector('.badge');
      const pts = ptsBadge ? parseInt(ptsBadge.textContent) || 0 : 0;
      const ok = item.querySelector('input.item-correcta[value="1"]');
      if (ok && ok.checked) total += pts;
    });
    const out = document.getElementById('puntajeTotal');
    if (out) out.textContent = total;
  }
  document.querySelectorAll('#formCalificacion input.item-correcta').forEach(el => el.addEventListener('change', recalc));
  recalc();
})();
</script>
