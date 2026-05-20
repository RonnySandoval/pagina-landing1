<?php
declare(strict_types=1);
/**
 * Columna de un día en la plantilla semanal del experto.
 *
 * @var int $wd
 * @var list<array<string, mixed>> $dayRows
 * @var int $eid
 * @var list<string> $wdLabelsExpert
 * @var string $defaultStart
 * @var string $defaultEnd
 * @var bool $isWeekend
 */
if (!isset($wd, $dayRows, $eid, $wdLabelsExpert, $defaultStart, $defaultEnd, $isWeekend)) {
    return;
}
$dayLabel = $wdLabelsExpert[$wd] ?? "Día";
$hasSlots = count($dayRows) > 0;
?>
<article
  class="expert-day-card border border-secondary rounded<?= $isWeekend ? " expert-day-card--weekend" : "" ?>"
  role="listitem"
  data-weekday="<?= (int)$wd ?>"
  data-day-label="<?= h($dayLabel) ?>"
>
  <header class="expert-day-card__head">
    <span class="expert-day-card__name"><?= h($dayLabel) ?></span>
    <?php if (!$hasSlots): ?>
      <span class="badge rounded-pill text-bg-secondary expert-day-card__badge">Sin horario</span>
    <?php endif; ?>
  </header>

  <?php if ($hasSlots): ?>
    <ul class="expert-day-card__slots list-unstyled mb-0">
      <?php foreach ($dayRows as $arow): ?>
        <?php $avid = (int)($arow["id"] ?? 0); ?>
        <li class="expert-day-card__slot">
          <span class="expert-day-card__time">
            <code><?= h(agenda_format_time_range_24((string)($arow["start_time"] ?? ""), (string)($arow["end_time"] ?? ""))) ?></code>
          </span>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Quitar esta franja de <?= h($dayLabel) ?>?');">
            <input type="hidden" name="action" value="expert_delete_availability">
            <input type="hidden" name="expert_id" value="<?= $eid ?>">
            <input type="hidden" name="availability_id" value="<?= $avid ?>">
            <button type="submit" class="btn btn-link btn-sm text-danger p-0 expert-day-card__remove" title="Quitar franja" aria-label="Quitar franja">
              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="expert-day-card__empty small text-secondary mb-0">No atiende este día.</p>
  <?php endif; ?>

  <details class="expert-day-card__add">
    <summary class="expert-day-card__add-toggle small">Añadir franja</summary>
    <form method="post" class="expert-day-card__add-form mt-2" lang="es">
      <input type="hidden" name="action" value="expert_add_availability">
      <input type="hidden" name="expert_id" value="<?= $eid ?>">
      <input type="hidden" name="weekday" value="<?= $wd ?>">
      <div class="d-flex align-items-center gap-1">
        <label class="visually-hidden" for="expert-wd-<?= $wd ?>-start">Desde</label>
        <input id="expert-wd-<?= $wd ?>-start" type="time" name="start_time" class="form-control form-control-sm flex-grow-1" required value="<?= h($defaultStart) ?>">
        <span class="small text-secondary" aria-hidden="true">–</span>
        <label class="visually-hidden" for="expert-wd-<?= $wd ?>-end">Hasta</label>
        <input id="expert-wd-<?= $wd ?>-end" type="time" name="end_time" class="form-control form-control-sm flex-grow-1" required value="<?= h($defaultEnd) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-outline-primary w-100 mt-2">Guardar franja</button>
    </form>
  </details>
</article>
