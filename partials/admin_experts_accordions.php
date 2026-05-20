<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var list<array<string, mixed>> $services */
/** @var string $expertView */
/** @var array<string, mixed>|null $expertEdit */
/** @var list<array<string, mixed>> $allExpertsAppointmentsUpcoming */
$expertAccAddOpen = count($experts) === 0;
$expertAccAppointmentsOpen = count($allExpertsAppointmentsUpcoming ?? []) > 0;
$nAllAppointments = count($allExpertsAppointmentsUpcoming ?? []);
$expertAccEditOpen = $expertView === "edit" && is_array($expertEdit) && isset($expertEdit["id"]);
$expertEditDisplayName = is_array($expertEdit) ? (string)($expertEdit["display_name"] ?? "") : "";
?>
<div class="accordion admin-experts-inner-accordion mt-3" id="adminExpertsInnerAccordion">
  <div class="accordion-item">
    <h3 class="accordion-header m-0">
      <button
        class="accordion-button <?= $expertAccAddOpen ? "" : "collapsed" ?>"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#expert_acc_add"
        aria-expanded="<?= $expertAccAddOpen ? "true" : "false" ?>"
        aria-controls="expert_acc_add"
      >
        <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Agregar experto
      </button>
    </h3>
    <div
      id="expert_acc_add"
      class="accordion-collapse collapse <?= $expertAccAddOpen ? "show" : "" ?>"
    >
      <div class="accordion-body">
        <form method="post" class="row g-3 js-admin-ajax-form" id="form-add-expert" data-ajax-scope="expert-add">
          <input type="hidden" name="action" value="add_expert">
          <div class="col-md-8">
            <label class="form-label" for="new_expert_display_name">Nombre visible</label>
            <input id="new_expert_display_name" class="form-control" type="text" name="display_name" maxlength="180" placeholder="Ej. María López" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="new_expert_sort">Orden</label>
            <input id="new_expert_sort" class="form-control" type="number" name="sort_order" value="999" min="0" max="999999">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="new_expert_email">Correo (opcional)</label>
            <input id="new_expert_email" class="form-control" type="email" name="email" maxlength="180" placeholder="contacto@ejemplo.com" autocomplete="off">
            <div class="form-text text-light-emphasis">Útil para contacto o para enlazar luego la cuenta de experto.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="new_expert_phone">Teléfono (opcional)</label>
            <input id="new_expert_phone" class="form-control" type="text" name="phone" maxlength="48" placeholder="+34 …" autocomplete="off">
          </div>
          <div class="col-12">
            <label class="form-label" for="new_expert_notes">Notas / información adicional</label>
            <textarea id="new_expert_notes" class="form-control" name="notes" rows="4" maxlength="12000" placeholder="Especialidad, disponibilidad general, comentarios internos…"></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Servicios que puede ofrecer</label>
            <div class="expert-service-checks border rounded px-3 py-2 border-secondary">
              <?php if (count($services) === 0): ?>
                <span class="text-light-emphasis">No hay servicios en el catálogo.</span>
              <?php else: ?>
                <?php foreach ($services as $svc): ?>
                  <?php $sid = (int)$svc["id"]; ?>
                  <div class="form-check">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      id="ex_add_svc_<?= $sid ?>"
                      name="expert_services[]"
                      value="<?= $sid ?>"
                    >
                    <label class="form-check-label" for="ex_add_svc_<?= $sid ?>">
                      <i class="<?= h((string)($svc["icon_class"] ?: "fa-solid fa-star")) ?> me-1"></i><?= h((string)($svc["title"] ?? "")) ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="new_expert_active" name="is_active" value="1" checked>
              <label class="form-check-label" for="new_expert_active">Activo</label>
            </div>
          </div>
          <div class="col-12 admin-actions d-flex flex-wrap gap-2 mb-0">
            <button class="btn btn-primary" type="submit">
              <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Crear experto
            </button>
            <button type="button" class="btn btn-outline-secondary" id="cancel_new_expert_btn" aria-controls="expert_acc_add">
              <i class="fa-solid fa-xmark me-2" aria-hidden="true"></i>Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($nAllAppointments > 0): ?>
    <div class="accordion-item" id="expert_acc_appointments_item">
      <h3 class="accordion-header m-0">
        <button
          class="accordion-button<?= $expertAccAppointmentsOpen && !$expertAccEditOpen ? "" : " collapsed" ?>"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_acc_appointments"
          aria-expanded="<?= $expertAccAppointmentsOpen && !$expertAccEditOpen ? "true" : "false" ?>"
          aria-controls="expert_acc_appointments"
        >
          <i class="fa-solid fa-calendar-check me-2" aria-hidden="true"></i>Citas
          <span class="badge rounded-pill text-bg-primary ms-2"><?= (int)$nAllAppointments ?></span>
        </button>
      </h3>
      <div
        id="expert_acc_appointments"
        class="accordion-collapse collapse<?= $expertAccAppointmentsOpen && !$expertAccEditOpen ? " show" : "" ?>"
      >
        <div class="accordion-body">
          <p class="small text-muted mb-3">
            Próximas citas y las de los últimos 30 días, con estado (confirmada, pospuesta, terminada, cancelada). Horarios en <strong>Agendas</strong>.
          </p>
          <?php
            $appointments = $allExpertsAppointmentsUpcoming;
            $showExpertColumn = true;
            $cancelExpertId = null;
            $expertWeekHidden = "";
            $appointmentReturnView = "list";
            $emptyMessage = "No hay citas en el listado.";
            require __DIR__ . "/admin_expert_appointments_table.php";
          ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($expertAccEditOpen): ?>
    <div class="accordion-item">
      <h3 class="accordion-header m-0">
        <button
          class="accordion-button"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_acc_edit"
          aria-expanded="true"
          aria-controls="expert_acc_edit"
        >
          <i class="fa-solid fa-pen-to-square me-2" aria-hidden="true"></i>Editar: <?= h($expertEditDisplayName) ?>
        </button>
      </h3>
      <div id="expert_acc_edit" class="accordion-collapse collapse show">
        <div class="accordion-body p-0">
          <?php require __DIR__ . "/admin_expert_edit_panel.php"; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
