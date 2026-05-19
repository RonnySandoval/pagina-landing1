<?php
declare(strict_types=1);
/**
 * @var list<array<string, mixed>> $appointments
 * @var bool $showExpertColumn
 * @var int|null $cancelExpertId Si se indica, el formulario de cancelar usa este expert_id fijo
 * @var string $expertWeekHidden
 * @var string $emptyMessage
 * @var string $appointmentReturnView edit|schedule|list
 */
$showExpertColumn = $showExpertColumn ?? false;
$cancelExpertId = isset($cancelExpertId) ? (int)$cancelExpertId : null;
$expertWeekHidden = trim((string)($expertWeekHidden ?? ""));
$emptyMessage = trim((string)($emptyMessage ?? "No hay citas confirmadas próximas."));
$appointmentReturnView = trim((string)($appointmentReturnView ?? "schedule"));
if (!in_array($appointmentReturnView, ["edit", "schedule", "list"], true)) {
    $appointmentReturnView = "schedule";
}
$nAppts = count($appointments);
$colCount = $showExpertColumn ? 5 : 4;
$tableId = "admin-appts-" . ($showExpertColumn ? "all" : "expert");

$apptFormatShortDatetime = static function (string $startsAt): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{1,2}:\d{2})/', $startsAt, $m)) {
        return $m[3] . "/" . $m[2] . " · " . $m[4];
    }
    return $startsAt;
};

$expertsInList = [];
if ($showExpertColumn) {
    foreach ($appointments as $ap) {
        $eid = (int)($ap["expert_id"] ?? 0);
        $ename = trim((string)($ap["expert_name"] ?? ""));
        if ($eid > 0 && $ename !== "") {
            $expertsInList[$eid] = $ename;
        }
    }
    asort($expertsInList, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<?php if ($nAppts === 0): ?>
  <p class="small text-muted mb-0"><?= h($emptyMessage) ?></p>
<?php else: ?>
  <div
    class="admin-filter-table expert-appointments-filter-table"
    id="<?= h($tableId) ?>"
    data-admin-filter-table
  >
    <div class="admin-filter-table__meta">
      <span class="admin-filter-table__count small text-secondary" data-filter-count aria-live="polite"></span>
      <button type="button" class="btn btn-link btn-sm py-0 admin-filter-table__clear" data-filter-clear>Limpiar filtros</button>
    </div>

    <div class="admin-filter-table__scroll table-responsive expert-appointments-table-wrap">
      <table class="table table-sm table-hover align-middle mb-0 admin-filter-table__table expert-appointments-table">
        <thead>
          <tr class="admin-filter-table__head-row">
            <th scope="col" class="appt-col-datetime" data-sort-key="datetime">
              <span class="adm-th-full">Fecha y hora</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
            </th>
            <?php if ($showExpertColumn): ?>
              <th scope="col" class="appt-col-expert adm-desktop-only" data-sort-key="expert">
                <span class="adm-th-full">Experto</span>
                <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-user-tie"></i></span>
              </th>
            <?php endif; ?>
            <th scope="col" class="appt-col-service adm-desktop-only" data-sort-key="service">
              <span class="adm-th-full">Servicio</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-briefcase"></i></span>
            </th>
            <th scope="col" class="appt-col-guest adm-desktop-only" data-sort-key="guest">
              <span class="adm-th-full">Cliente</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
            </th>
            <th scope="col" class="text-end appt-col-actions">
              <span class="adm-th-full">Acción</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-ellipsis"></i></span>
              <span class="visually-hidden">Acción</span>
            </th>
          </tr>
          <tr class="admin-filter-table__filter-row appt-filter-row">
            <th scope="col" class="appt-col-datetime">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Fecha…"
                data-filter-col="datetime"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por fecha"
              />
            </th>
            <?php if ($showExpertColumn): ?>
              <th scope="col" class="appt-col-expert adm-desktop-only">
                <?php if (count($expertsInList) > 0): ?>
                  <select class="form-select form-select-sm admin-filter-table__col-input" data-filter-col="expert" data-filter-type="select" aria-label="Filtrar por experto">
                    <option value="all">Todos</option>
                    <?php foreach ($expertsInList as $eid => $ename): ?>
                      <option value="<?= (int)$eid ?>"><?= h($ename) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input
                    type="search"
                    class="form-control form-control-sm admin-filter-table__col-input"
                    placeholder="Experto…"
                    data-filter-col="expertname"
                    data-filter-type="text"
                    autocomplete="off"
                    aria-label="Filtrar por experto"
                  />
                <?php endif; ?>
              </th>
            <?php endif; ?>
            <th scope="col" class="appt-col-service adm-desktop-only">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Servicio…"
                data-filter-col="service"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por servicio"
              />
            </th>
            <th scope="col" class="appt-col-guest adm-desktop-only">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Cliente…"
                data-filter-col="guest"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por cliente"
              />
            </th>
            <th scope="col" class="appt-col-actions" aria-hidden="true"></th>
          </tr>
        </thead>
        <tbody>
          <?php $apptRowIdx = 0; ?>
          <?php foreach ($appointments as $ap): ?>
            <?php
              $apptRowIdx++;
              $rowStripe = ($apptRowIdx % 2) === 0 ? " is-alt" : "";
              $apid = (int)($ap["id"] ?? 0);
              $apExpertId = (int)($ap["expert_id"] ?? 0);
              $formExpertId = $cancelExpertId !== null && $cancelExpertId > 0 ? $cancelExpertId : $apExpertId;
              $startsDisp = (string)($ap["starts_at"] ?? "");
              $expertName = (string)($ap["expert_name"] ?? "");
              $serviceTitle = (string)($ap["service_title"] ?? "");
              $serviceIcon = trim((string)($ap["service_icon_class"] ?? ""));
              if ($serviceIcon === "") {
                  $serviceIcon = "fa-solid fa-briefcase";
              }
              $guestName = (string)($ap["guest_name"] ?? "");
              $guestEmail = trim((string)($ap["guest_email"] ?? ""));
              $startsShort = $apptFormatShortDatetime($startsDisp);
              $hasMobileExtra = $serviceTitle !== "" || $guestName !== "" || $guestEmail !== ""
                  || ($showExpertColumn && $expertName !== "");
            ?>
            <tr
              class="admin-filter-table__row<?= $rowStripe ?>"
              data-filter-row
              data-filter-id="<?= $apid ?>"
              data-filter-datetime="<?= h(strtolower($startsDisp)) ?>"
              data-filter-expert="<?= $apExpertId > 0 ? (string)$apExpertId : "" ?>"
              data-filter-expertname="<?= h(strtolower($expertName)) ?>"
              data-filter-service="<?= h(strtolower($serviceTitle)) ?>"
              data-filter-guest="<?= h(strtolower(implode(" ", array_filter([$guestName, $guestEmail])))) ?>"
              data-sort-datetime="<?= h($startsDisp) ?>"
              data-sort-expert="<?= h($expertName) ?>"
              data-sort-service="<?= h($serviceTitle) ?>"
              data-sort-guest="<?= h($guestName) ?>"
            >
              <td class="appt-col-datetime">
                <div class="appt-datetime-cell">
                  <?php if ($serviceTitle !== ""): ?>
                    <span
                      class="appt-mobile-svc-icon appt-svc-icon expert-svc-icon d-inline-flex align-items-center justify-content-center rounded-2 border border-secondary expert-svc-icon-chip adm-mobile-only flex-shrink-0"
                      title="<?= h($serviceTitle) ?>"
                    >
                      <i class="<?= h($serviceIcon) ?>" aria-hidden="true"></i>
                      <span class="visually-hidden"><?= h($serviceTitle) ?></span>
                    </span>
                  <?php endif; ?>
                  <div class="appt-mobile-stack">
                    <span class="appt-mobile-when fw-semibold admin-filter-table__text-2l"><?= h($startsShort) ?></span>
                    <?php if ($guestName !== ""): ?>
                      <span class="appt-mobile-guest text-secondary admin-filter-table__text-2l"><?= h($guestName) ?></span>
                    <?php endif; ?>
                  </div>
                  <code class="small expert-appt-datetime appt-datetime-full admin-filter-table__text-2l"><?= h($startsDisp) ?></code>
                  <?php if ($hasMobileExtra && $apid > 0): ?>
                    <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm py-0 px-1 appt-expand-btn adm-mobile-only"
                      data-bs-toggle="collapse"
                      data-bs-target="#appt-mobile-<?= $apid ?>"
                      aria-expanded="false"
                      aria-controls="appt-mobile-<?= $apid ?>"
                      title="Ver servicio y contacto"
                    >
                      <i class="fa-solid fa-plus appt-expand-icon" aria-hidden="true"></i>
                      <span class="visually-hidden">Ver detalle de la cita</span>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
              <?php if ($showExpertColumn): ?>
                <td class="small appt-col-expert adm-desktop-only">
                  <span class="admin-filter-table__text-2l" title="<?= h($expertName) ?>"><?= h($expertName) ?></span>
                </td>
              <?php endif; ?>
              <td class="appt-col-service adm-desktop-only">
                <?php if ($serviceTitle !== ""): ?>
                  <span
                    class="appt-svc-icon expert-svc-icon d-inline-flex align-items-center justify-content-center rounded-2 border border-secondary expert-svc-icon-chip"
                    title="<?= h($serviceTitle) ?>"
                  >
                    <i class="<?= h($serviceIcon) ?>" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= h($serviceTitle) ?></span>
                  </span>
                  <span class="appt-svc-label small admin-filter-table__text-2l d-none d-lg-inline" title="<?= h($serviceTitle) ?>"><?= h($serviceTitle) ?></span>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td class="appt-col-guest adm-desktop-only">
                <span class="small appt-guest-name admin-filter-table__text-2l d-block" title="<?= h($guestName) ?>"><?= h($guestName) ?></span>
                <?php if ($guestEmail !== ""): ?>
                  <span class="small text-secondary appt-guest-email admin-filter-table__text-2l d-block" title="<?= h($guestEmail) ?>"><?= h($guestEmail) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end appt-col-actions">
                <?php if ($apid > 0 && $formExpertId > 0): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('¿Cancelar esta cita?');">
                    <input type="hidden" name="action" value="expert_cancel_appointment">
                    <input type="hidden" name="expert_id" value="<?= $formExpertId ?>">
                    <input type="hidden" name="appointment_id" value="<?= $apid ?>">
                    <input type="hidden" name="expert_return_view" value="<?= h($appointmentReturnView) ?>">
                    <?php if ($expertWeekHidden !== ""): ?>
                      <input type="hidden" name="expert_week" value="<?= h($expertWeekHidden) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-outline-warning btn-sm py-0" title="Cancelar cita" aria-label="Cancelar cita">
                      <i class="fa-solid fa-ban" aria-hidden="true"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($hasMobileExtra && $apid > 0): ?>
              <tr class="appt-mobile-detail-row admin-filter-table__detail-row adm-mobile-only" data-filter-detail-for="<?= $apid ?>">
                <td colspan="<?= $colCount ?>" class="pt-0 pb-0 px-1">
                  <div class="collapse" id="appt-mobile-<?= $apid ?>">
                  <div class="appt-mobile-detail-panel small border border-secondary rounded p-2 mb-1">
                    <ul class="list-unstyled mb-0 appt-mobile-detail-list">
                      <?php if ($showExpertColumn && $expertName !== ""): ?>
                        <li><i class="fa-solid fa-user-tie me-1 text-secondary" aria-hidden="true"></i><?= h($expertName) ?></li>
                      <?php endif; ?>
                      <?php if ($guestName !== ""): ?>
                        <li><i class="fa-solid fa-user me-1 text-secondary" aria-hidden="true"></i><?= h($guestName) ?></li>
                      <?php endif; ?>
                      <?php if ($guestEmail !== ""): ?>
                        <li><i class="fa-solid fa-envelope me-1 text-secondary" aria-hidden="true"></i><?= h($guestEmail) ?></li>
                      <?php endif; ?>
                      <li class="text-muted"><i class="fa-solid fa-clock me-1" aria-hidden="true"></i><?= h($startsDisp) ?></li>
                    </ul>
                  </div>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          <tr class="admin-filter-table__empty" data-filter-empty hidden>
            <td colspan="<?= $colCount ?>" class="text-center text-muted small py-4">
              <i class="fa-solid fa-filter-circle-xmark me-1" aria-hidden="true"></i>Ninguna cita coincide con el filtro.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
