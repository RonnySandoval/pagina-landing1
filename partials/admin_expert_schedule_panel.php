<?php
declare(strict_types=1);
/** @var array<string, mixed> $expertEdit */
/** @var list<array<string, mixed>> $expertAvailabilityRows */
/** @var list<array<string, mixed>> $expertAvailabilityDateRows */
/** @var list<array<string, mixed>> $expertAppointmentsUpcoming */
/** @var array{week_start: string, week_end: string, week_label: string, days: list<array<string, mixed>>, rows: list<array<string, mixed>>} $expertWeekGrid */
if (!is_array($expertEdit) || !isset($expertEdit["id"])) {
    return;
}
$eid = (int)$expertEdit["id"];
$expertWeekHidden = trim((string)($_GET["expert_week"] ?? ""));
?>
<div id="admin-expert-schedule" class="admin-expert-subpanel scroll-margin-admin p-3">
  <nav class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <a href="admin.php#admin-experts-list" class="link-light"><i class="fa-solid fa-arrow-left me-1"></i>Listado</a>
    <span class="text-secondary" aria-hidden="true">·</span>
    <a href="<?= h(admin_expert_page_url($eid, "edit")) ?>" class="link-light"><i class="fa-solid fa-pen-to-square me-1"></i>Editar datos</a>
  </nav>

  <h4 class="h6"><i class="fa-solid fa-table-columns me-2"></i>Plantilla semanal (vista en columnas)</h4>
  <p class="small text-light-emphasis mb-3">
    Cada columna es un día de la semana. Usa los botones para aplicar la <strong>misma franja a todo L–V</strong> de una vez, o añade franjas por día. Los huecos públicos se generan en bloques de 30 minutos.
  </p>
  <?php
    $wdLabelsExpert = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
    $availByWd = [[], [], [], [], [], [], []];
    foreach ($expertAvailabilityRows as $arow) {
        $awi = (int)($arow["weekday"] ?? -1);
        if ($awi >= 0 && $awi <= 6) {
            $availByWd[$awi][] = $arow;
        }
    }
    $weekColOrder = [1, 2, 3, 4, 5, 6, 0];
  ?>
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3 expert-agenda-toolbar">
    <form method="post" class="d-inline" onsubmit="return confirm('¿Sustituir lunes a viernes por 09:00–18:00 solo para este experto? (No cambia sábado ni domingo.)');">
      <input type="hidden" name="action" value="expert_set_mon_fri_window">
      <input type="hidden" name="expert_id" value="<?= $eid ?>">
      <input type="hidden" name="use_defaults" value="1">
      <button type="submit" class="btn btn-sm btn-outline-light">Este experto: L–V 9–18</button>
    </form>
    <form method="post" class="d-flex flex-wrap gap-1 align-items-center" onsubmit="return confirm('¿Sustituir L–V por esta franja solo para este experto?');">
      <input type="hidden" name="action" value="expert_set_mon_fri_window">
      <input type="hidden" name="expert_id" value="<?= $eid ?>">
      <input type="time" name="mon_fri_start" class="form-control form-control-sm" style="width:7rem" value="09:00" required>
      <span class="small text-secondary">a</span>
      <input type="time" name="mon_fri_end" class="form-control form-control-sm" style="width:7rem" value="18:00" required>
      <button type="submit" class="btn btn-sm btn-primary">Este experto: mismo L–V</button>
    </form>
  </div>
  <div class="expert-week-grid admin-week-grid">
    <?php foreach ($weekColOrder as $wd): ?>
      <div class="expert-week-col border border-secondary rounded p-2">
        <div class="expert-week-col-head small fw-semibold mb-2"><?= h(mb_substr($wdLabelsExpert[$wd], 0, 3, "UTF-8")) ?></div>
        <?php foreach ($availByWd[$wd] as $arow): ?>
          <?php $avid = (int)($arow["id"] ?? 0); ?>
          <div class="d-flex justify-content-between align-items-center small mb-1 expert-week-slot-line">
            <span><code><?= h(substr((string)($arow["start_time"] ?? ""), 0, 5)) ?>–<?= h(substr((string)($arow["end_time"] ?? ""), 0, 5)) ?></code></span>
            <form method="post" class="d-inline" onsubmit="return confirm('¿Quitar esta franja?');">
              <input type="hidden" name="action" value="expert_delete_availability">
              <input type="hidden" name="expert_id" value="<?= $eid ?>">
              <input type="hidden" name="availability_id" value="<?= $avid ?>">
              <button type="submit" class="btn btn-link btn-sm text-danger p-0 m-0" title="Quitar">×</button>
            </form>
          </div>
        <?php endforeach; ?>
        <form method="post" class="mt-2 pt-2 border-top border-secondary">
          <input type="hidden" name="action" value="expert_add_availability">
          <input type="hidden" name="expert_id" value="<?= $eid ?>">
          <input type="hidden" name="weekday" value="<?= $wd ?>">
          <input type="time" name="start_time" class="form-control form-control-sm mb-1" required value="<?= ($wd >= 1 && $wd <= 5) ? "09:00" : "10:00" ?>">
          <input type="time" name="end_time" class="form-control form-control-sm mb-1" required value="<?= ($wd >= 1 && $wd <= 5) ? "18:00" : "14:00" ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Añadir</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small text-light-emphasis mt-2 mb-2">Los expertos nuevos reciben automáticamente L–V 9:00–18:00. Desde el listado puedes aplicar la misma jornada a <strong>todos</strong> a la vez.</p>

  <?php require __DIR__ . "/admin_expert_week_grid.php"; ?>

  <?php
    $tzExForm = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $exAvDateMin = (new DateTimeImmutable("now", $tzExForm))->format("Y-m-d");
    $exAvDateMax = (new DateTimeImmutable("now", $tzExForm))
        ->setTime(0, 0, 0)
        ->modify("+" . AGENDA_DATE_EXCEPTION_MAX_DAYS . " days")
        ->format("Y-m-d");
  ?>
  <h4 class="h6 mt-4"><i class="fa-solid fa-calendar-day me-2"></i>Cambios por fecha concreta</h4>
  <p class="small text-light-emphasis mb-3">
    Para un <strong>día calendario</strong> concreto puedes cerrar la agenda o definir <strong>franjas distintas</strong> a la plantilla semanal (sustituyen ese día; puedes añadir varias franjas el mismo día enviando el formulario varias veces).
  </p>
  <?php if (count($expertAvailabilityDateRows) > 0): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm table-borderless align-middle text-light-emphasis expert-av-date-table">
        <thead>
          <tr class="small text-secondary">
            <th>Fecha</th>
            <th>Tipo</th>
            <th class="text-end">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expertAvailabilityDateRows as $drow): ?>
            <?php
              $did = (int)($drow["id"] ?? 0);
              $dclosed = (int)($drow["is_closed"] ?? 0) === 1;
              $dcal = (string)($drow["calendar_date"] ?? "");
            ?>
            <tr>
              <td><code class="small"><?= h($dcal) ?></code></td>
              <td class="small">
                <?php if ($dclosed): ?>
                  <span class="badge text-bg-secondary">Cerrado</span>
                <?php else: ?>
                  <span class="badge text-bg-info text-dark"><?= h(substr((string)($drow["start_time"] ?? ""), 0, 5)) ?>–<?= h(substr((string)($drow["end_time"] ?? ""), 0, 5)) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('¿Quitar este cambio por fecha?');">
                  <input type="hidden" name="action" value="expert_delete_availability_date">
                  <input type="hidden" name="expert_id" value="<?= $eid ?>">
                  <input type="hidden" name="av_date_id" value="<?= $did ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm py-0">Quitar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="small text-light-emphasis mb-3">No hay excepciones por fecha en el rango mostrado (últimas 2 semanas y futuro).</p>
  <?php endif; ?>
  <form method="post" class="row g-2 align-items-end mb-2 expert-av-date-form">
    <input type="hidden" name="action" value="expert_add_availability_date">
    <input type="hidden" name="expert_id" value="<?= $eid ?>">
    <div class="col-md-3">
      <label class="form-label small mb-0">Fecha</label>
      <input class="form-control form-control-sm" type="date" name="calendar_date" required min="<?= h($exAvDateMin) ?>" max="<?= h($exAvDateMax) ?>" value="<?= h($exAvDateMin) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Tipo</label>
      <select class="form-select form-select-sm" name="date_av_mode" required>
        <option value="window">Franjas horarias (sustituyen ese día)</option>
        <option value="closed">Día cerrado (sin citas)</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Desde</label>
      <input class="form-control form-control-sm" type="time" name="date_start_time" value="10:00">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Hasta</label>
      <input class="form-control form-control-sm" type="time" name="date_end_time" value="14:00">
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-outline-primary btn-sm w-100">Aplicar</button>
    </div>
  </form>
  <p class="small text-light-emphasis mb-4">Si eliges «Día cerrado», las horas no se usan. Si hay franjas por fecha, ese día <strong>no</strong> se mezclan con la plantilla semanal.</p>

  <h4 class="h6 mt-2"><i class="fa-solid fa-clock me-2"></i>Próximas citas reservadas</h4>
  <?php if (count($expertAppointmentsUpcoming) === 0): ?>
    <p class="small text-light-emphasis mb-0">No hay citas confirmadas próximas.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-borderless align-middle text-light-emphasis">
        <thead>
          <tr class="small text-secondary">
            <th>Fecha y hora</th>
            <th>Servicio</th>
            <th>Cliente</th>
            <th class="text-end">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expertAppointmentsUpcoming as $ap): ?>
            <?php
              $apid = (int)($ap["id"] ?? 0);
              $startsDisp = (string)($ap["starts_at"] ?? "");
            ?>
            <tr>
              <td><code class="small"><?= h($startsDisp) ?></code></td>
              <td><?= h((string)($ap["service_title"] ?? "")) ?></td>
              <td>
                <span class="small"><?= h((string)($ap["guest_name"] ?? "")) ?></span><br>
                <span class="small text-secondary"><?= h((string)($ap["guest_email"] ?? "")) ?></span>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('¿Cancelar esta cita?');">
                  <input type="hidden" name="action" value="expert_cancel_appointment">
                  <input type="hidden" name="expert_id" value="<?= $eid ?>">
                  <input type="hidden" name="appointment_id" value="<?= $apid ?>">
                  <?php if ($expertWeekHidden !== ""): ?>
                    <input type="hidden" name="expert_week" value="<?= h($expertWeekHidden) ?>">
                  <?php endif; ?>
                  <button type="submit" class="btn btn-outline-warning btn-sm py-0">Cancelar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
