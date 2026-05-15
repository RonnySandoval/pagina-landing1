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
?>
<?php if (count($appointments) === 0): ?>
  <p class="small text-muted mb-0"><?= h($emptyMessage) ?></p>
<?php else: ?>
  <div class="table-responsive expert-appointments-table-wrap">
    <table class="table table-sm table-borderless align-middle mb-0 expert-appointments-table">
      <thead>
        <tr class="small text-secondary">
          <th scope="col">Fecha y hora</th>
          <?php if ($showExpertColumn): ?>
            <th scope="col">Experto</th>
          <?php endif; ?>
          <th scope="col">Servicio</th>
          <th scope="col">Cliente</th>
          <th scope="col" class="text-end">Acción</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($appointments as $ap): ?>
          <?php
            $apid = (int)($ap["id"] ?? 0);
            $apExpertId = (int)($ap["expert_id"] ?? 0);
            $formExpertId = $cancelExpertId !== null && $cancelExpertId > 0 ? $cancelExpertId : $apExpertId;
            $startsDisp = (string)($ap["starts_at"] ?? "");
          ?>
          <tr>
            <td><code class="small expert-appt-datetime"><?= h($startsDisp) ?></code></td>
            <?php if ($showExpertColumn): ?>
              <td class="small"><?= h((string)($ap["expert_name"] ?? "")) ?></td>
            <?php endif; ?>
            <td class="small"><?= h((string)($ap["service_title"] ?? "")) ?></td>
            <td>
              <span class="small"><?= h((string)($ap["guest_name"] ?? "")) ?></span>
              <?php if (trim((string)($ap["guest_email"] ?? "")) !== ""): ?>
                <br><span class="small text-secondary"><?= h((string)($ap["guest_email"] ?? "")) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end">
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
                    <i class="fa-solid fa-ban" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Cancelar</span>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
