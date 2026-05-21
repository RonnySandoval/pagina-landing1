<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $agendaAdminNotifications */
/** @var int $agendaAdminNotifyUnread */
$agendaAdminNotifications = $agendaAdminNotifications ?? [];
$agendaAdminNotifyUnread = (int)($agendaAdminNotifyUnread ?? 0);
?>
<div class="admin-agenda-notify-panel">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="small text-muted mb-0">
      Avisos de reservas y cancelaciones en el panel. El historial de movimientos está en la barra lateral (<strong>Historial de citas</strong>).
    </p>
    <?php if ($agendaAdminNotifyUnread > 0): ?>
      <form method="post" class="m-0">
        <input type="hidden" name="action" value="agenda_mark_notifications_read">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Marcar todos leídos</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (count($agendaAdminNotifications) === 0): ?>
    <p class="small text-muted mb-0">No hay avisos de agenda todavía.</p>
  <?php else: ?>
    <div class="list-group list-group-flush admin-agenda-notify-list">
      <?php foreach ($agendaAdminNotifications as $nrow): ?>
        <?php
          $nid = (int)($nrow["id"] ?? 0);
          $isUnread = (int)($nrow["is_read"] ?? 0) === 0;
          $evt = (string)($nrow["event_type"] ?? "");
          $isCancel = $evt === "appointment_cancelled";
        ?>
        <div class="list-group-item admin-agenda-notify-item<?= $isUnread ? " admin-agenda-notify-item--unread" : "" ?>">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold small mb-1">
                <?php if ($isCancel): ?>
                  <span class="badge text-bg-secondary me-1">Cancelada</span>
                <?php else: ?>
                  <span class="badge text-bg-primary me-1">Nueva cita</span>
                <?php endif; ?>
                <?= h((string)($nrow["title"] ?? "")) ?>
              </div>
              <p class="small mb-1 text-light-emphasis admin-u-pre-wrap"><?= h((string)($nrow["body"] ?? "")) ?></p>
              <p class="small text-secondary mb-0">
                <i class="fa-solid fa-clock me-1 admin-icon-clock" aria-hidden="true"></i><?= h(agenda_format_datetime_24((string)($nrow["created_at"] ?? ""))) ?>
                · Cita #<?= (int)($nrow["appointment_id"] ?? 0) ?>
              </p>
            </div>
            <?php if ($isUnread && $nid > 0): ?>
              <form method="post" class="m-0 flex-shrink-0">
                <input type="hidden" name="action" value="agenda_mark_notifications_read">
                <input type="hidden" name="delivery_id" value="<?= $nid ?>">
                <button type="submit" class="btn btn-outline-light btn-sm py-0">Leído</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
