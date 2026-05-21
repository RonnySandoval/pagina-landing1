<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $clientAgendaNotifications */
/** @var int $clientAgendaNotifyUnread */
$clientAgendaNotifications = $clientAgendaNotifications ?? [];
$clientAgendaNotifyUnread = (int)($clientAgendaNotifyUnread ?? 0);
if (!function_exists("h")) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
    }
}
?>
<div class="client-agenda-notify-panel mb-4" id="client-agenda-notifications">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <h3 class="h6 mb-0">
      <i class="fa-solid fa-bell me-2" aria-hidden="true"></i>Avisos de citas
      <?php if ($clientAgendaNotifyUnread > 0): ?>
        <span class="badge rounded-pill text-bg-primary ms-1"><?= (int)$clientAgendaNotifyUnread ?></span>
      <?php endif; ?>
    </h3>
    <?php if ($clientAgendaNotifyUnread > 0): ?>
      <form method="post" class="m-0">
        <input type="hidden" name="action" value="client_agenda_mark_notifications_read">
        <button type="submit" class="btn btn-ghost btn-sm py-0">Marcar leídos</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (count($clientAgendaNotifications) === 0): ?>
    <p class="client-muted small mb-0">Cuando reserves una cita con este correo, los avisos aparecerán aquí.</p>
  <?php else: ?>
    <ul class="list-unstyled client-agenda-notify-list mb-0">
      <?php foreach ($clientAgendaNotifications as $nrow): ?>
        <?php
          $nid = (int)($nrow["id"] ?? 0);
          $isUnread = (int)($nrow["is_read"] ?? 0) === 0;
          $evt = (string)($nrow["event_type"] ?? "");
          $isCancel = $evt === "appointment_cancelled";
        ?>
        <li class="client-agenda-notify-item<?= $isUnread ? " client-agenda-notify-item--unread" : "" ?>">
          <p class="small fw-semibold mb-1">
            <?php if ($isCancel): ?>
              <span class="text-secondary">Cancelada:</span>
            <?php else: ?>
              <span class="text-primary">Cita:</span>
            <?php endif; ?>
            <?= h((string)($nrow["title"] ?? "")) ?>
          </p>
          <p class="small mb-1 admin-u-pre-wrap"><?= h((string)($nrow["body"] ?? "")) ?></p>
          <p class="client-muted small mb-2">
            <?= h((string)($nrow["created_at"] ?? "")) ?>
            <?php if ($isUnread && $nid > 0): ?>
              <form method="post" class="d-inline ms-2">
                <input type="hidden" name="action" value="client_agenda_mark_notifications_read">
                <input type="hidden" name="delivery_id" value="<?= $nid ?>">
                <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">Marcar leído</button>
              </form>
            <?php endif; ?>
          </p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
