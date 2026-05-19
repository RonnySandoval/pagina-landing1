<?php
declare(strict_types=1);
/** @var array<string, mixed> $expertEdit */
/** @var list<array<string, mixed>> $expertAvailabilityRows */
/** @var list<array<string, mixed>> $expertAvailabilityDateRows */
/** @var list<array<string, mixed>> $expertAppointmentsUpcoming */
/** @var array{week_start: string, week_end: string, week_label: string, days: list<array<string, mixed>>, rows: list<array<string, mixed>>} $expertWeekGrid */
/** @var string $expertScheduleSection appts|week|template|daily|dates */
if (!is_array($expertEdit) || !isset($expertEdit["id"])) {
    return;
}
$eid = (int)$expertEdit["id"];
$expertName = trim((string)($expertEdit["display_name"] ?? "Experto"));
$expertWeekHidden = trim((string)($_GET["expert_week"] ?? ""));
$expertScheduleSection = trim((string)($expertScheduleSection ?? ""));
$schSections = ["appts", "week", "template", "daily", "dates"];
if (!in_array($expertScheduleSection, $schSections, true)) {
    $expertScheduleSection = "";
}
if ($expertScheduleSection === "daily") {
    $expertScheduleSection = "template";
}

$wdLabelsExpert = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
$availByWd = [[], [], [], [], [], [], []];
foreach ($expertAvailabilityRows as $arow) {
    $awi = (int)($arow["weekday"] ?? -1);
    if ($awi >= 0 && $awi <= 6) {
        $availByWd[$awi][] = $arow;
    }
}

$monFriSummary = null;
$monFriSlots = [];
for ($wd = 1; $wd <= 5; $wd++) {
    if (count($availByWd[$wd]) !== 1) {
        $monFriSlots = [];
        break;
    }
    $monFriSlots[] = substr((string)($availByWd[$wd][0]["start_time"] ?? ""), 0, 5)
        . "–" . substr((string)($availByWd[$wd][0]["end_time"] ?? ""), 0, 5);
}
if (count($monFriSlots) === 5 && count(array_unique($monFriSlots)) === 1) {
    $monFriSummary = $monFriSlots[0];
}

$weekColWeekdays = [1, 2, 3, 4, 5];
$weekColWeekend = [6, 0];

$tzExForm = new DateTimeZone(date_default_timezone_get() ?: "UTC");
$exAvDateMin = (new DateTimeImmutable("now", $tzExForm))->format("Y-m-d");
$exAvDateMax = (new DateTimeImmutable("now", $tzExForm))
    ->setTime(0, 0, 0)
    ->modify("+" . AGENDA_DATE_EXCEPTION_MAX_DAYS . " days")
    ->format("Y-m-d");

$nAppts = count($expertAppointmentsUpcoming);
$nDateEx = count($expertAvailabilityDateRows);

$schAccOpen = static function (string $id) use ($expertScheduleSection): string {
    return $expertScheduleSection === $id ? " show" : "";
};
$schAccBtnCollapsed = static function (string $id) use ($expertScheduleSection): string {
    return $expertScheduleSection === $id ? "" : " collapsed";
};
$schAccExpanded = static function (string $id) use ($expertScheduleSection): string {
    return $expertScheduleSection === $id ? "true" : "false";
};
?>
<div id="admin-expert-schedule" class="admin-expert-subpanel scroll-margin-admin p-3 border border-secondary rounded">
  <?php if (empty($agendaScheduleContext)): ?>
    <nav class="mb-3 d-flex flex-wrap gap-2 align-items-center expert-schedule-nav">
      <a href="admin.php#admin-experts-list" class="link-light"><i class="fa-solid fa-arrow-left me-1"></i>Listado</a>
      <span class="text-secondary" aria-hidden="true">·</span>
      <a href="<?= h(admin_agenda_expert_url($eid, "datos")) ?>" class="link-light"><i class="fa-solid fa-pen-to-square me-1"></i>Datos en Agendas</a>
    </nav>
  <?php endif; ?>

  <header class="mb-3">
    <h3 class="h5 mb-1">Horario y disponibilidad · <?= h($expertName) ?></h3>
    <p class="small text-muted mb-0">
      Franjas de la plantilla semanal, vista de agenda, citas y excepciones por fecha. Los huecos públicos son de 30 minutos.
    </p>
  </header>

  <div class="accordion admin-expert-schedule-accordion admin-experts-inner-accordion" id="expertScheduleSectionsAccordion">
    <div class="accordion-item">
      <h4 class="accordion-header m-0">
        <button
          class="accordion-button<?= $schAccBtnCollapsed("appts") ?>"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_sch_acc_appts"
          aria-expanded="<?= $schAccExpanded("appts") ?>"
          aria-controls="expert_sch_acc_appts"
        >
          <i class="fa-solid fa-clock me-2" aria-hidden="true"></i>Próximas citas
          <?php if ($nAppts > 0): ?>
            <span class="badge rounded-pill text-bg-primary ms-2"><?= (int)$nAppts ?></span>
          <?php endif; ?>
        </button>
      </h4>
      <div id="expert_sch_acc_appts" class="accordion-collapse collapse<?= $schAccOpen("appts") ?>">
        <div class="accordion-body">
          <?php
            $appointments = $expertAppointmentsUpcoming;
            $showExpertColumn = false;
            $cancelExpertId = $eid;
            $appointmentReturnView = "schedule";
            $emptyMessage = "No hay citas confirmadas próximas.";
            require __DIR__ . "/admin_expert_appointments_table.php";
          ?>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h4 class="accordion-header m-0">
        <button
          class="accordion-button<?= $schAccBtnCollapsed("week") ?>"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_sch_acc_week"
          aria-expanded="<?= $schAccExpanded("week") ?>"
          aria-controls="expert_sch_acc_week"
        >
          <i class="fa-solid fa-table me-2" aria-hidden="true"></i>Agenda semanal
        </button>
      </h4>
      <div id="expert_sch_acc_week" class="accordion-collapse collapse<?= $schAccOpen("week") ?>">
        <div class="accordion-body p-2 p-md-3">
          <?php require __DIR__ . "/admin_expert_week_grid.php"; ?>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h4 class="accordion-header m-0">
        <button
          class="accordion-button<?= $schAccBtnCollapsed("template") ?>"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_sch_acc_template"
          aria-expanded="<?= $schAccExpanded("template") ?>"
          aria-controls="expert_sch_acc_template"
        >
          <i class="fa-solid fa-calendar-week me-2" aria-hidden="true"></i>Plantilla semanal
        </button>
      </h4>
      <div id="expert_sch_acc_template" class="accordion-collapse collapse<?= $schAccOpen("template") ?>">
        <div class="accordion-body">
          <?php if ($monFriSummary !== null): ?>
            <div class="alert alert-secondary py-2 px-3 small mb-3 expert-schedule-summary" role="status">
              <i class="fa-solid fa-circle-check me-1 text-success" aria-hidden="true"></i>
              <strong>Lunes a viernes:</strong> <?= h($monFriSummary) ?> (misma franja cada día laborable).
              <?php if (count($availByWd[6]) === 0 && count($availByWd[0]) === 0): ?>
                Fin de semana sin horario.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <section class="expert-template-block mb-4" aria-labelledby="expert-template-shortcut-heading">
            <h5 class="h6 mb-2" id="expert-template-shortcut-heading">
              <i class="fa-solid fa-bolt me-1 text-warning" aria-hidden="true"></i>Atajo rápido
            </h5>
            <p class="small text-muted mb-2">
              Marca los días y una o dos franjas; se sustituye la plantilla de esos días. Para festivos o un solo día concreto, usa
              <a href="#expert_sch_acc_dates" class="link-light js-expert-sch-goto-dates">excepciones por fecha</a>.
            </p>
            <?php
              $templateShortcutAction = "expert_set_mon_fri_window";
              $templateShortcutExpertId = $eid;
              $templateShortcutCompact = false;
              require __DIR__ . "/admin_expert_template_shortcut.php";
            ?>
            <p class="small text-muted mb-0 mt-2">
              <i class="fa-solid fa-users me-1" aria-hidden="true"></i>
              Mismo atajo para <strong>todos</strong> los expertos:
              <a href="admin.php?workspace=agendas#agenda_acc_bulk" class="link-light">Horario para todos</a> (abajo en Ajustes).
            </p>
          </section>

          <section class="expert-template-block" aria-labelledby="expert-template-days-heading">
            <h5 class="h6 mb-2" id="expert-template-days-heading">
              <i class="fa-solid fa-calendar-day me-1" aria-hidden="true"></i>Por día de la semana
            </h5>
            <p class="small text-muted mb-2">
              Ajusta un día distinto o añade varias franjas el mismo día con «Añadir franja».
            </p>
            <div class="expert-days-section">
              <p class="small text-uppercase text-secondary fw-semibold mb-2 expert-days-section__label">Lunes a viernes</p>
              <div class="expert-week-grid expert-week-grid--weekdays">
                <?php foreach ($weekColWeekdays as $wd): ?>
                  <?php
                    $dayRows = $availByWd[$wd];
                    $isWeekend = false;
                    $defaultStart = "09:00";
                    $defaultEnd = "18:00";
                    include __DIR__ . "/admin_expert_day_card.php";
                  ?>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="expert-days-section mt-3">
              <p class="small text-uppercase text-secondary fw-semibold mb-2 expert-days-section__label">Fin de semana <span class="fw-normal">(opcional)</span></p>
              <div class="expert-week-grid expert-week-grid--weekend">
                <?php foreach ($weekColWeekend as $wd): ?>
                  <?php
                    $dayRows = $availByWd[$wd];
                    $isWeekend = true;
                    $defaultStart = "10:00";
                    $defaultEnd = "14:00";
                    include __DIR__ . "/admin_expert_day_card.php";
                  ?>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h4 class="accordion-header m-0">
        <button
          class="accordion-button<?= $schAccBtnCollapsed("dates") ?>"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_sch_acc_dates"
          aria-expanded="<?= $schAccExpanded("dates") ?>"
          aria-controls="expert_sch_acc_dates"
        >
          <i class="fa-solid fa-calendar-xmark me-2" aria-hidden="true"></i>Excepciones por fecha
          <?php if ($nDateEx > 0): ?>
            <span class="badge rounded-pill text-bg-secondary ms-2"><?= (int)$nDateEx ?></span>
          <?php endif; ?>
        </button>
      </h4>
      <div id="expert_sch_acc_dates" class="accordion-collapse collapse<?= $schAccOpen("dates") ?>">
        <div class="accordion-body">
          <p class="small text-muted mb-3">
            <strong>Festivos y días puntuales:</strong> cierra la agenda o define franjas distintas a la plantilla (sustituyen ese día; no se mezclan con la semana).
          </p>
          <?php if ($nDateEx > 0): ?>
            <div class="table-responsive mb-3">
              <table class="table table-sm table-borderless align-middle mb-0 expert-av-date-table">
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
            <p class="small text-muted mb-3">No hay excepciones por fecha en el rango mostrado.</p>
          <?php endif; ?>
          <form method="post" class="row g-2 align-items-end expert-av-date-form">
            <input type="hidden" name="action" value="expert_add_availability_date">
            <input type="hidden" name="expert_id" value="<?= $eid ?>">
            <div class="col-12 col-sm-6 col-md-3">
              <label class="form-label small mb-0">Fecha</label>
              <input class="form-control form-control-sm" type="date" name="calendar_date" required min="<?= h($exAvDateMin) ?>" max="<?= h($exAvDateMax) ?>" value="<?= h($exAvDateMin) ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
              <label class="form-label small mb-0">Tipo</label>
              <select class="form-select form-select-sm" name="date_av_mode" required>
                <option value="window">Franjas distintas (sustituye ese día)</option>
                <option value="closed">Día cerrado</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small mb-0">Desde</label>
              <input class="form-control form-control-sm" type="time" name="date_start_time" value="10:00">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small mb-0">Hasta</label>
              <input class="form-control form-control-sm" type="time" name="date_end_time" value="14:00">
            </div>
            <div class="col-12 col-md-2">
              <button type="submit" class="btn btn-outline-primary btn-sm w-100">Aplicar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
