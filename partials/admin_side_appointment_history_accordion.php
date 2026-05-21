<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $agendaAppointmentHistory */
$agendaAppointmentHistory = $agendaAppointmentHistory ?? [];
$historyEventCount = count($agendaAppointmentHistory);
$historyCounterTitle = $historyEventCount > 0
    ? sprintf("%d eventos (reservas y cancelaciones)", $historyEventCount)
    : "Sin movimientos de citas";
?>
<div class="accordion-item border-0<?= !($adminWhatsappClicksUi ?? false) && !($adminInboxUi ?? false) ? "" : " border-top" ?>">
  <h2 class="accordion-header m-0" id="headingSideAgendaHistory">
    <button
      class="accordion-button collapsed py-3 px-3"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#collapseSideAgendaHistory"
      aria-expanded="false"
      aria-controls="collapseSideAgendaHistory"
    >
      <span class="d-flex flex-wrap align-items-center gap-2 me-auto">
        <i class="fa-solid fa-calendar-check text-info" aria-hidden="true"></i>
        <span>Historial de citas</span>
        <span
          class="badge text-bg-secondary"
          title="<?= h($historyCounterTitle) ?>"
        ><?= (int)$historyEventCount ?></span>
      </span>
    </button>
  </h2>
  <div
    id="collapseSideAgendaHistory"
    class="accordion-collapse collapse"
    aria-labelledby="headingSideAgendaHistory"
  >
    <div class="accordion-body pt-0 px-3 pb-3">
      <p class="small text-light-emphasis mb-3">
        Reservas y cancelaciones recientes. Pulsa <strong>+</strong> en una fila para ver si se envió correo (SMTP), avisos en panel o si faltaba email del cliente o experto.
      </p>
      <?php if ($historyEventCount === 0): ?>
        <p class="text-light-emphasis mb-0">Aún no hay citas registradas.</p>
      <?php else: ?>
        <ul class="list-unstyled mb-0 admin-side-appt-history-list">
          <?php foreach ($agendaAppointmentHistory as $ev): ?>
            <?php
              $eventKey = (string)($ev["event_key"] ?? "");
              $aid = (int)($ev["appointment_id"] ?? 0);
              $eventType = (string)($ev["event_type"] ?? "");
              $isCancel = $eventType === AGENDA_NOTIFY_EVENT_CANCELLED;
              $startsAt = (string)($ev["starts_at"] ?? "");
              $startsLabel = $startsAt;
              try {
                  $startsLabel = (new DateTime($startsAt))->format("d/m/Y H:i");
              } catch (Exception $e) {
              }
              $eventAt = (string)($ev["event_at"] ?? "");
              $eventAtLabel = "";
              if ($eventAt !== "") {
                  try {
                      $eventAtLabel = (new DateTime($eventAt))->format("d/m/Y H:i");
                  } catch (Exception $e) {
                      $eventAtLabel = $eventAt;
                  }
              }
              $guestName = trim((string)($ev["guest_name"] ?? ""));
              $serviceTitle = trim((string)($ev["service_title"] ?? ""));
              $serviceIcon = trim((string)($ev["service_icon_class"] ?? ""));
              if ($serviceIcon === "") {
                  $serviceIcon = "fa-solid fa-briefcase";
              }
              $expertName = trim((string)($ev["expert_name"] ?? ""));
              $deliveries = $ev["deliveries"] ?? [];
              $nDel = count($deliveries);
              $collapseId = "side-appt-history-del-" . preg_replace('/[^a-z0-9_]/i', "_", $eventKey);
            ?>
            <li class="admin-side-appt-history-item border border-secondary rounded mb-2 p-2">
              <div class="admin-side-appt-history-row d-flex align-items-start gap-2">
                <?php if ($serviceTitle !== ""): ?>
                  <span
                    class="expert-svc-icon d-inline-flex align-items-center justify-content-center rounded-2 border border-secondary expert-svc-icon-chip flex-shrink-0 admin-u-icon-box-sm"
                    title="<?= h($serviceTitle) ?>"
                  >
                    <i class="<?= h($serviceIcon) ?>" aria-hidden="true"></i>
                  </span>
                <?php endif; ?>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                    <?php if ($isCancel): ?>
                      <span class="badge text-bg-secondary">Cancelada</span>
                    <?php else: ?>
                      <span class="badge text-bg-primary">Reservada</span>
                    <?php endif; ?>
                    <strong class="small"><?= h($startsLabel) ?></strong>
                    <?php if ($guestName !== ""): ?>
                      <span class="small text-secondary">· <?= h($guestName) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($expertName !== "" || $serviceTitle !== ""): ?>
                    <p class="small text-secondary mb-0">
                      <?php if ($expertName !== ""): ?>
                        <i class="fa-solid fa-user-tie me-1" aria-hidden="true"></i><?= h($expertName) ?>
                      <?php endif; ?>
                      <?php if ($serviceTitle !== ""): ?>
                        <?php if ($expertName !== ""): ?><span class="text-muted"> · </span><?php endif; ?>
                        <?= h($serviceTitle) ?>
                      <?php endif; ?>
                    </p>
                  <?php endif; ?>
                  <?php if ($eventAtLabel !== ""): ?>
                    <p class="small text-muted mb-0 mt-1">
                      <i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i>
                      <?= $isCancel ? "Cancelación" : "Reserva" ?> registrada <?= h($eventAtLabel) ?>
                    </p>
                  <?php elseif ($isCancel): ?>
                    <p class="small text-muted mb-0 mt-1">Cancelación (sin hora de registro)</p>
                  <?php endif; ?>
                </div>
                <?php if ($nDel > 0): ?>
                  <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm py-0 px-1 appt-expand-btn flex-shrink-0"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= h($collapseId) ?>"
                    aria-expanded="false"
                    aria-controls="<?= h($collapseId) ?>"
                    title="Ver envíos de correo y avisos (<?= (int)$nDel ?>)"
                  >
                    <i class="fa-solid fa-plus appt-expand-icon" aria-hidden="true"></i>
                    <span class="visually-hidden">Ver detalle de envíos</span>
                  </button>
                <?php endif; ?>
              </div>
              <?php if ($nDel > 0): ?>
                <div class="collapse mt-2" id="<?= h($collapseId) ?>">
                  <div class="small border-top border-secondary pt-2">
                    <p class="text-muted mb-2">Canales al <?= $isCancel ? "cancelar" : "reservar" ?>:</p>
                    <ul class="list-unstyled mb-0 admin-appt-notify-log-list">
                      <?php require __DIR__ . "/admin_delivery_log_items.php"; ?>
                    </ul>
                  </div>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
