<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var list<array<string, mixed>> $services */
/** @var string $expertView */
/** @var array<string, mixed>|null $expertEdit */
/** @var bool $agendaShowExpertNamesAdmin */
$expertAccAddOpen = count($experts) === 0;
$expertAccEditOpen = $expertView === "edit" && is_array($expertEdit) && isset($expertEdit["id"]);
$expertAccScheduleOpen = $expertView === "schedule" && is_array($expertEdit) && isset($expertEdit["id"]);
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
        <form method="post" class="row g-3" id="form-add-expert">
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

  <?php if (count($experts) > 0): ?>
    <div class="accordion-item">
      <h3 class="accordion-header m-0">
        <button
          class="accordion-button collapsed"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_acc_bulk"
          aria-expanded="false"
          aria-controls="expert_acc_bulk"
        >
          <i class="fa-solid fa-users-gear me-2" aria-hidden="true"></i>Jornada L–V para todos
        </button>
      </h3>
      <div id="expert_acc_bulk" class="accordion-collapse collapse">
        <div class="accordion-body">
          <p class="small text-light-emphasis mb-3">Sustituye la plantilla de lunes a viernes de <strong>cada experto</strong> por la misma franja (no cambia sábado ni domingo).</p>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="post" class="d-inline" onsubmit="return confirm('¿Aplicar 9:00–18:00 a todos los expertos?');">
              <input type="hidden" name="action" value="bulk_mon_fri_all_experts">
              <input type="hidden" name="use_defaults" value="1">
              <button type="submit" class="btn btn-sm btn-outline-light">Todos: 9:00–18:00</button>
            </form>
            <form method="post" class="d-flex flex-wrap gap-1 align-items-center" onsubmit="return confirm('¿Aplicar esta franja L–V a todos los expertos?');">
              <input type="hidden" name="action" value="bulk_mon_fri_all_experts">
              <input type="time" name="mon_fri_start" class="form-control form-control-sm" style="width:7rem" value="09:00" required>
              <span class="small text-secondary">a</span>
              <input type="time" name="mon_fri_end" class="form-control form-control-sm" style="width:7rem" value="18:00" required>
              <button type="submit" class="btn btn-sm btn-primary">Todos: mismo horario</button>
            </form>
          </div>
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

  <?php if ($expertAccScheduleOpen): ?>
    <div class="accordion-item">
      <h3 class="accordion-header m-0">
        <button
          class="accordion-button"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#expert_acc_schedule"
          aria-expanded="true"
          aria-controls="expert_acc_schedule"
        >
          <i class="fa-solid fa-calendar-week me-2" aria-hidden="true"></i>Horario: <?= h($expertEditDisplayName) ?>
        </button>
      </h3>
      <div id="expert_acc_schedule" class="accordion-collapse collapse show">
        <div class="accordion-body p-0">
          <?php require __DIR__ . "/admin_expert_schedule_panel.php"; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="accordion-item">
    <h3 class="accordion-header m-0">
      <button
        class="accordion-button collapsed"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#expert_acc_public"
        aria-expanded="false"
        aria-controls="expert_acc_public"
      >
        <i class="fa-solid fa-eye me-2" aria-hidden="true"></i>Agenda pública (visitantes)
      </button>
    </h3>
    <div id="expert_acc_public" class="accordion-collapse collapse">
      <div class="accordion-body">
        <p class="small text-light-emphasis mb-3">
          Por defecto la reserva es <strong>por servicio y anónima</strong>. Activa el interruptor para mostrar el nombre del experto en <a href="agenda.php" class="link-light" target="_blank" rel="noopener">agenda.php</a>.
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
              <?= $agendaShowExpertNamesAdmin ? "checked" : "" ?>
            >
            <label class="form-check-label" for="agenda_show_expert_names">Mostrar nombre del experto en la tabla</label>
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
        </form>
      </div>
    </div>
  </div>
</div>
