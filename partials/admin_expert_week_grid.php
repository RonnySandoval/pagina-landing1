<?php
declare(strict_types=1);
/** @var int $eid */
/** @var array{week_start: string, week_end: string, week_label: string, days: list<array<string, mixed>>, rows: list<array<string, mixed>>} $expertWeekGrid */
if (!isset($expertWeekGrid) || !is_array($expertWeekGrid)) {
    return;
}
$weekStart = (string)($expertWeekGrid["week_start"] ?? "");
$weekLabel = (string)($expertWeekGrid["week_label"] ?? "");
$weekDays = $expertWeekGrid["days"] ?? [];
$weekRows = $expertWeekGrid["rows"] ?? [];
if ($weekStart === "") {
    return;
}
$tzNav = new DateTimeZone(date_default_timezone_get() ?: "UTC");
$monNav = DateTimeImmutable::createFromFormat("Y-m-d", $weekStart, $tzNav);
$prevWeek = $monNav !== false ? $monNav->modify("-7 days")->format("Y-m-d") : $weekStart;
$nextWeek = $monNav !== false ? $monNav->modify("+7 days")->format("Y-m-d") : $weekStart;
$todayWeek = agenda_normalize_week_start("");
?>
<div id="admin-expert-week-grid" class="admin-expert-week-section">
  <p class="small text-muted mb-3">
    Misma lógica que la reserva pública: filas por hora y columnas por día. <span class="admin-agenda-legend admin-agenda-legend--free">Libre</span>
    <span class="admin-agenda-legend admin-agenda-legend--booked">Reservado</span>
    <span class="admin-agenda-legend admin-agenda-legend--past">Pasado</span>
    <span class="admin-agenda-legend admin-agenda-legend--closed">Cerrado</span>
  </p>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3 admin-expert-week-toolbar">
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_expert_page_url($eid, "schedule", $prevWeek, "week")) ?>"><i class="fa-solid fa-chevron-left"></i> Semana anterior</a>
    <span class="small fw-semibold px-2"><?= h($weekLabel) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_expert_page_url($eid, "schedule", $nextWeek, "week")) ?>">Semana siguiente <i class="fa-solid fa-chevron-right"></i></a>
    <?php if ($weekStart !== $todayWeek): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_expert_page_url($eid, "schedule", $todayWeek, "week")) ?>">Semana actual</a>
    <?php endif; ?>
  </div>
  <?php if (count($weekRows) === 0): ?>
    <p class="small text-light-emphasis mb-0">Sin franjas en plantilla para esta semana. Configura la plantilla semanal o excepciones por fecha.</p>
  <?php else: ?>
    <div class="admin-expert-week-table-wrap">
      <table class="admin-expert-week-table agenda-slot-table" role="grid">
        <caption class="agenda-slot-caption">Semana del <?= h($weekLabel) ?></caption>
        <thead>
          <tr>
            <th scope="col" class="agenda-slot-table-time-col">Hora</th>
            <?php foreach ($weekDays as $wdCol): ?>
              <?php
                $wdFull = (string)($wdCol["label"] ?? "");
                $wdNum = (int)($wdCol["weekday"] ?? -1);
                $wdShortLabels = agenda_weekday_labels_es();
                $wdShort = ($wdNum >= 0 && isset($wdShortLabels[$wdNum])) ? (string)$wdShortLabels[$wdNum] : mb_substr($wdFull, 0, 3, "UTF-8");
              ?>
              <th scope="col" class="admin-expert-week-day-col" title="<?= h($wdFull . " " . (string)($wdCol["day_num"] ?? "")) ?>">
                <span class="admin-expert-week-day-name"><?= h($wdFull) ?></span>
                <abbr class="admin-expert-week-day-short"><?= h($wdShort) ?></abbr>
                <span class="admin-expert-week-day-date"><?= h((string)($wdCol["day_num"] ?? "")) ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
            $prevRowTime = null;
            $rowIdx = 0;
            $totalRows = count($weekRows);
          ?>
          <?php foreach ($weekRows as $wRow): ?>
            <?php
              $rowTime = (string)($wRow["time"] ?? "");
              $sepClasses = agenda_slot_row_separator_classes($rowTime, $prevRowTime, $rowIdx, $totalRows);
              $trClass = $sepClasses !== [] ? implode(" ", $sepClasses) : "";
              $rowCells = is_array($wRow["cells"] ?? null) ? $wRow["cells"] : [];
            ?>
            <tr<?= $trClass !== "" ? ' class="' . h($trClass) . '"' : "" ?>>
              <th scope="row" class="agenda-slot-table-time-col"><?= h($rowTime) ?></th>
              <?php foreach ($weekDays as $wdCol): ?>
                <?php
                  $dateKey = (string)($wdCol["date"] ?? "");
                  $cell = $rowCells[$dateKey] ?? ["state" => "off"];
                  $state = (string)($cell["state"] ?? "off");
                ?>
                <td class="admin-expert-week-slot-col">
                  <?php if ($state === "booked"): ?>
                    <div class="admin-agenda-cell admin-agenda-cell--booked" title="<?= h((string)($cell["guest_name"] ?? "") . " · " . (string)($cell["service_title"] ?? "")) ?>">
                      <i class="fa-solid fa-user-check admin-agenda-cell-glyph" aria-hidden="true"></i>
                      <span class="admin-agenda-cell-text">Reservado</span>
                      <span class="admin-agenda-cell-meta"><?= h((string)($cell["service_title"] ?? "")) ?></span>
                      <span class="admin-agenda-cell-meta"><?= h((string)($cell["guest_name"] ?? "")) ?></span>
                    </div>
                  <?php elseif ($state === "free"): ?>
                    <span class="admin-agenda-cell admin-agenda-cell--free" title="Libre">
                      <i class="fa-solid fa-circle admin-agenda-cell-glyph" aria-hidden="true"></i>
                      <span class="admin-agenda-cell-text">Libre</span>
                    </span>
                  <?php elseif ($state === "past"): ?>
                    <span class="admin-agenda-cell admin-agenda-cell--past">—</span>
                  <?php elseif ($state === "closed"): ?>
                    <span class="admin-agenda-cell admin-agenda-cell--closed" title="Cerrado">
                      <i class="fa-solid fa-ban admin-agenda-cell-glyph" aria-hidden="true"></i>
                      <span class="admin-agenda-cell-text">Cerrado</span>
                    </span>
                  <?php else: ?>
                    <span class="admin-agenda-cell admin-agenda-cell--off" aria-hidden="true">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
            <?php
              $prevRowTime = $rowTime;
              $rowIdx++;
            ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
