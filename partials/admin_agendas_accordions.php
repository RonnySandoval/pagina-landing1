<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var int $agendasExpertId */
/** @var array<string, mixed>|null $agendasExpert */
/** @var bool $agendasExpertNotFound */
/** @var string $agendasExpertTab */
/** @var bool $agendaShowExpertNamesAdmin */
/** @var list<array<string, mixed>> $agendaAdminNotifications */
/** @var int $agendaAdminNotifyUnread */
/** @var list<array<string, mixed>> $agendasAvailabilityRows */
/** @var list<array<string, mixed>> $agendasAvailabilityDateRows */
/** @var list<array<string, mixed>> $agendasAppointmentsUpcoming */
/** @var array<string, mixed> $agendasWeekGrid */
/** @var string $expertScheduleSection */
$agendaAccNotifyOpen = (int)($agendaAdminNotifyUnread ?? 0) > 0;
$nExperts = count($experts);
?>
<div class="admin-agendas-layout">
  <div class="admin-agendas-schedule-block mb-3" id="admin-agendas-expert-workspace">
    <?php if ($nExperts === 0): ?>
      <p class="text-light-emphasis mb-0">
        No hay expertos. Créalos en <a href="admin.php#admin-tools-experts" class="link-light">Expertos</a> para gestionar horarios aquí.
      </p>
    <?php else: ?>
      <?php require __DIR__ . "/admin_agendas_expert_nav.php"; ?>

      <?php if ($agendasExpertNotFound): ?>
        <div class="alert alert-warning mb-0">No hay ningún experto con ese identificador.</div>
      <?php elseif ($agendasExpert !== null): ?>
        <?php if ($agendasExpertTab === "datos"): ?>
          <div class="admin-agendas-expert-datos p-3 border border-secondary rounded">
            <?php
              $expertEdit = $agendasExpert;
              $expertEditFormReturnTo = "agendas";
              $expertEditFormTab = "datos";
              require __DIR__ . "/admin_expert_edit_form.php";
            ?>
          </div>
        <?php else: ?>
          <?php
            $expertEdit = $agendasExpert;
            $expertAvailabilityRows = $agendasAvailabilityRows;
            $expertAvailabilityDateRows = $agendasAvailabilityDateRows;
            $expertAppointmentsUpcoming = $agendasAppointmentsUpcoming;
            $expertWeekGrid = $agendasWeekGrid;
            $agendaScheduleContext = true;
            require __DIR__ . "/admin_expert_schedule_panel.php";
          ?>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php require __DIR__ . "/admin_agendas_quick_settings.php"; ?>
</div>
