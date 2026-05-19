<?php
declare(strict_types=1);
/**
 * Campana de notificaciones (barra superior).
 *
 * @var string $notifyBellId
 * @var list<array<string, mixed>> $notifyBellItems
 * @var int $notifyBellUnread
 * @var string $notifyBellMarkAction acción POST global «marcar todos» (opcional)
 * @var string $notifyBellViewAllHref
 * @var string $notifyBellLabel
 * @var string $notifyBellEmptyText
 * @var int $notifyBellAgendaUnread solo cliente: contador citas para polling
 */
if (!function_exists("h")) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
    }
}

$notifyBellId = (string)($notifyBellId ?? "notify-bell");
$notifyBellItems = $notifyBellItems ?? [];
$notifyBellUnread = (int)($notifyBellUnread ?? 0);
$notifyBellMarkAction = (string)($notifyBellMarkAction ?? "");
$notifyBellViewAllHref = (string)($notifyBellViewAllHref ?? "#");
$notifyBellLabel = (string)($notifyBellLabel ?? "Notificaciones");
$notifyBellEmptyText = (string)($notifyBellEmptyText ?? "No hay notificaciones nuevas.");
$notifyBellDropdownLimit = (int)($notifyBellDropdownLimit ?? 10);
$notifyBellAgendaUnread = (int)($notifyBellAgendaUnread ?? 0);
$notifyBellItems = array_slice($notifyBellItems, 0, max(1, $notifyBellDropdownLimit));
$panelId = $notifyBellId . "-panel";
$badgeLabel = $notifyBellUnread === 1
    ? "1 notificación sin leer"
    : $notifyBellUnread . " notificaciones sin leer";

$notifyBellItemIsNormalized = static function (array $row): bool {
    return isset($row["kind"]);
};
?>
<div
  class="notify-bell"
  data-notify-bell
  id="<?= h($notifyBellId) ?>"
  data-notify-bell-root="1"
  data-agenda-unread="<?= (int)$notifyBellAgendaUnread ?>"
  data-inbox-unread="<?= max(0, $notifyBellUnread - (int)$notifyBellAgendaUnread) ?>"
>
  <button
    type="button"
    class="notify-bell__trigger"
    data-notify-bell-trigger
    aria-expanded="false"
    aria-haspopup="true"
    aria-controls="<?= h($panelId) ?>"
    title="<?= h($notifyBellLabel) ?>"
  >
    <i class="fa-solid fa-bell" aria-hidden="true"></i>
    <?php if ($notifyBellUnread > 0): ?>
      <span class="notify-bell__badge js-notify-bell-badge" aria-hidden="true"><?= $notifyBellUnread > 99 ? "99+" : (string)$notifyBellUnread ?></span>
      <span class="theme-sr-only js-notify-bell-badge-sr"><?= h($badgeLabel) ?></span>
    <?php else: ?>
      <span class="notify-bell__badge js-notify-bell-badge" aria-hidden="true" hidden></span>
      <span class="theme-sr-only js-notify-bell-badge-sr"></span>
    <?php endif; ?>
  </button>
  <div
    id="<?= h($panelId) ?>"
    class="notify-bell__panel"
    data-notify-bell-panel
    role="region"
    aria-label="<?= h($notifyBellLabel) ?>"
    hidden
  >
    <div class="notify-bell__head">
      <span class="notify-bell__head-title"><?= h($notifyBellLabel) ?></span>
      <?php if ($notifyBellUnread > 0 && $notifyBellMarkAction !== ""): ?>
        <form method="post" class="m-0">
          <input type="hidden" name="action" value="<?= h($notifyBellMarkAction) ?>">
          <input type="hidden" name="notify_return" value="top">
          <button type="submit" class="notify-bell__mark-all btn btn-link btn-sm p-0">Marcar leídos</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="notify-bell__list">
      <?php if (count($notifyBellItems) === 0): ?>
        <p class="notify-bell__empty small mb-0"><?= h($notifyBellEmptyText) ?></p>
      <?php else: ?>
        <?php foreach ($notifyBellItems as $nrow): ?>
          <?php
            if ($notifyBellItemIsNormalized($nrow)) {
                $isUnread = !empty($nrow["is_unread"]);
                $tag = (string)($nrow["tag"] ?? "");
                $tagMuted = !empty($nrow["tag_muted"]);
                $title = trim((string)($nrow["title"] ?? ""));
                $body = trim((string)($nrow["body"] ?? ""));
                $createdAt = (string)($nrow["created_at"] ?? "");
                $metaExtra = trim((string)($nrow["meta_extra"] ?? ""));
                $href = trim((string)($nrow["href"] ?? ""));
                $mark = is_array($nrow["mark"] ?? null) ? $nrow["mark"] : [];
                $markAction = (string)($mark["action"] ?? "");
            } else {
                $nid = (int)($nrow["id"] ?? 0);
                $isUnread = (int)($nrow["is_read"] ?? 0) === 0;
                $evt = (string)($nrow["event_type"] ?? "");
                $isCancel = $evt === "appointment_cancelled";
                $tag = $isCancel ? "Cancelada" : "Cita";
                $tagMuted = $isCancel;
                $title = trim((string)($nrow["title"] ?? ""));
                $body = trim((string)($nrow["body"] ?? ""));
                $createdAt = (string)($nrow["created_at"] ?? "");
                $metaExtra = (int)($nrow["appointment_id"] ?? 0) > 0
                    ? "#" . (int)($nrow["appointment_id"] ?? 0)
                    : "";
                $href = "";
                $markAction = $notifyBellMarkAction;
                $mark = $isUnread && $nid > 0 && $markAction !== ""
                    ? ["action" => $markAction, "delivery_id" => $nid]
                    : [];
            }
            if (strlen($body) > 140) {
                $body = substr($body, 0, 137) . "…";
            }
            unset($mark["action"]);
          ?>
          <article class="notify-bell__item<?= $isUnread ? " notify-bell__item--unread" : "" ?>">
            <?php if ($href !== ""): ?>
              <a href="<?= h($href) ?>" class="notify-bell__item-link">
            <?php endif; ?>
            <div class="notify-bell__item-top">
              <span class="notify-bell__tag<?= $tagMuted ? " notify-bell__tag--muted" : "" ?>"><?= h($tag) ?></span>
              <?php if ($isUnread && $markAction !== "" && count($mark) > 0): ?>
                <form method="post" class="m-0" onclick="event.stopPropagation();">
                  <input type="hidden" name="action" value="<?= h($markAction) ?>">
                  <input type="hidden" name="notify_return" value="top">
                  <?php foreach ($mark as $mk => $mv): ?>
                    <input type="hidden" name="<?= h((string)$mk) ?>" value="<?= h((string)$mv) ?>">
                  <?php endforeach; ?>
                  <button type="submit" class="notify-bell__read-one btn btn-link btn-sm p-0">Leído</button>
                </form>
              <?php endif; ?>
            </div>
            <?php if ($title !== ""): ?>
              <p class="notify-bell__item-title mb-0"><?= h($title) ?></p>
            <?php endif; ?>
            <?php if ($body !== ""): ?>
              <p class="notify-bell__item-body small mb-0"><?= h($body) ?></p>
            <?php endif; ?>
            <p class="notify-bell__item-meta small mb-0">
              <?= h($createdAt) ?>
              <?php if ($metaExtra !== ""): ?>
                · <?= h($metaExtra) ?>
              <?php endif; ?>
            </p>
            <?php if ($href !== ""): ?>
              </a>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php if ($notifyBellViewAllHref !== ""): ?>
      <div class="notify-bell__foot">
        <a href="<?= h($notifyBellViewAllHref) ?>" class="notify-bell__view-all small">Ver todo en mi cuenta</a>
      </div>
    <?php endif; ?>
  </div>
</div>
