<?php
declare(strict_types=1);
/** @var bool $agendaAccNotifyOpen */
/** @var int $agendaAdminNotifyUnread */
/** @var bool $agendaShowExpertNamesAdmin */
/** @var int $nExperts */
$agendaAccNotifyOpen = (bool)($agendaAccNotifyOpen ?? false);
$agendaAdminNotifyUnread = (int)($agendaAdminNotifyUnread ?? 0);
$nExperts = (int)($nExperts ?? 0);
$hasNotify = function_exists("agenda_notifications_enabled") && agenda_notifications_enabled();
?>
<aside class="admin-agendas-quick-settings" aria-labelledby="admin-agendas-quick-heading">
  <h3 class="h6 text-muted mb-2" id="admin-agendas-quick-heading">Ajustes de agenda</h3>
  <p class="small text-light-emphasis mb-2">
    Opciones globales y atajos. El horario de cada experto se edita arriba en <strong>Plantilla semanal</strong>.
  </p>

  <div class="admin-agendas-quick-settings__list">
    <?php if ($hasNotify): ?>
      <details class="admin-agendas-quick-item" id="agenda_acc_notify_item"<?= $agendaAccNotifyOpen ? " open" : "" ?>>
        <summary class="admin-agendas-quick-item__summary">
          <i class="fa-solid fa-bell" aria-hidden="true"></i>
          <span>Avisos de agenda</span>
          <?php if ($agendaAdminNotifyUnread > 0): ?>
            <span class="badge rounded-pill text-bg-warning text-dark ms-1"><?= $agendaAdminNotifyUnread ?></span>
          <?php endif; ?>
        </summary>
        <div class="admin-agendas-quick-item__body" id="agenda_acc_notify">
          <?php require __DIR__ . "/admin_agenda_notifications_panel.php"; ?>
        </div>
      </details>
    <?php endif; ?>

    <details class="admin-agendas-quick-item" id="agenda_acc_public_wrap">
      <summary class="admin-agendas-quick-item__summary">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        <span>Agenda pública</span>
      </summary>
      <div class="admin-agendas-quick-item__body" id="agenda_acc_public">
        <p class="small text-light-emphasis mb-2">
          Por defecto la reserva es <strong>por servicio y anónima</strong>. Activa el interruptor para mostrar el nombre del experto en
          <a href="agenda.php" class="link-light" target="_blank" rel="noopener">agenda.php</a>.
        </p>
        <form method="post" class="d-flex flex-wrap align-items-center gap-3 mb-0">
          <input type="hidden" name="action" value="save_agenda_display">
          <div class="form-check form-switch mb-0">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="agenda_show_expert_names"
              name="agenda_show_expert_names"
              value="1"
              <?= ($agendaShowExpertNamesAdmin ?? false) ? "checked" : "" ?>
            >
            <label class="form-check-label" for="agenda_show_expert_names">Mostrar nombre del experto</label>
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
        </form>
      </div>
    </details>

    <?php if ($nExperts > 0): ?>
      <details class="admin-agendas-quick-item" id="agenda_acc_bulk_wrap">
        <summary class="admin-agendas-quick-item__summary">
          <i class="fa-solid fa-users-gear" aria-hidden="true"></i>
          <span>Horario para todos</span>
        </summary>
        <div class="admin-agendas-quick-item__body" id="agenda_acc_bulk">
          <p class="small text-light-emphasis mb-2">
            Atajo para aplicar la misma plantilla a <strong>todos</strong> los expertos (solo sustituye los días que marques).
          </p>
          <?php
            $templateShortcutAction = "bulk_mon_fri_all_experts";
            $templateShortcutExpertId = 0;
            $templateShortcutCompact = true;
            require __DIR__ . "/admin_expert_template_shortcut.php";
          ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</aside>
