<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $deliveries */
$deliveries = $deliveries ?? [];
foreach ($deliveries as $d):
    $status = (string)($d["status"] ?? "");
    $badgeClass = $status === "delivered" ? "success" : ($status === "failed" ? "danger" : "secondary");
    ?>
  <li class="mb-1">
    <span class="badge text-bg-<?= h($badgeClass) ?>">
      <?= h(agenda_notifications_status_label($status, isset($d["status_detail"]) ? (string)$d["status_detail"] : null)) ?>
    </span>
    <?= h(agenda_notifications_channel_label((string)($d["channel"] ?? ""), (string)($d["recipient_role"] ?? ""))) ?>
    <?php if (!empty($d["recipient_email"])): ?>
      <span class="text-secondary">→ <?= h((string)$d["recipient_email"]) ?></span>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
