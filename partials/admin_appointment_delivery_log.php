<?php
declare(strict_types=1);
/** @var int $appointmentIdForNotifyLog */
/** @var bool $notifyLogCompact Lista sin <details> */
$appointmentIdForNotifyLog = (int)($appointmentIdForNotifyLog ?? 0);
$notifyLogCompact = !empty($notifyLogCompact);
if ($appointmentIdForNotifyLog <= 0 || !function_exists("agenda_notifications_list_for_appointment")) {
    return;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}
$deliveries = agenda_notifications_list_for_appointment($conn, $appointmentIdForNotifyLog);
if (count($deliveries) === 0) {
    return;
}
?>
<?php if ($notifyLogCompact): ?>
  <ul class="list-unstyled mb-0 mt-2 pt-2 border-top border-secondary admin-appt-notify-log-list admin-appt-notify-log-list--compact">
    <?php require __DIR__ . "/admin_delivery_log_items.php"; ?>
  </ul>
<?php else: ?>
  <details class="admin-appt-notify-log small mt-1 mb-2">
    <summary class="text-secondary">Registro de notificaciones (<?= count($deliveries) ?>)</summary>
    <ul class="list-unstyled mb-0 mt-1 admin-appt-notify-log-list">
      <?php require __DIR__ . "/admin_delivery_log_items.php"; ?>
    </ul>
  </details>
<?php endif; ?>
