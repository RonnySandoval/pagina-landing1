<?php
declare(strict_types=1);
/** @var array<string, mixed> $expertEdit */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var list<array<string, mixed>> $services */
/** @var list<array<string, mixed>> $expertAppointmentsUpcoming */
if (!is_array($expertEdit) || !isset($expertEdit["id"])) {
    return;
}
$eid = (int)$expertEdit["id"];
?>
<div id="admin-expert-edit" class="admin-expert-subpanel scroll-margin-admin p-3">
  <nav class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <a href="admin.php#admin-experts-list" class="link-light"><i class="fa-solid fa-arrow-left me-1"></i>Listado</a>
    <span class="text-secondary" aria-hidden="true">·</span>
    <a href="<?= h(admin_agenda_expert_url($eid, "schedule")) ?>" class="link-light"><i class="fa-solid fa-calendar-week me-1"></i>Horario en Agendas</a>
  </nav>
  <?php
    $expertEditFormReturnTo = "";
    require __DIR__ . "/admin_expert_edit_form.php";
  ?>

  <section class="expert-schedule-block mt-4 pt-3 border-top border-secondary" aria-labelledby="expert-edit-appt-heading">
    <h4 id="expert-edit-appt-heading" class="h6 expert-schedule-block-title">
      <i class="fa-solid fa-calendar-check me-2" aria-hidden="true"></i>Citas programadas
    </h4>
    <?php
      $appointments = $expertAppointmentsUpcoming ?? [];
      $showExpertColumn = false;
      $cancelExpertId = $eid;
      $expertWeekHidden = "";
      $appointmentReturnView = "edit";
      $emptyMessage = "Este experto no tiene citas confirmadas próximas.";
      require __DIR__ . "/admin_expert_appointments_table.php";
    ?>
  </section>

  <form method="post" class="mt-3 pt-3 border-top border-secondary" onsubmit="return confirm('¿Eliminar este experto? Se quitarán sus vínculos con servicios.');">
    <input type="hidden" name="action" value="delete_expert">
    <input type="hidden" name="expert_id" value="<?= $eid ?>">
    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash me-2"></i>Eliminar experto</button>
  </form>
</div>
