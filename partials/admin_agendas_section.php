<?php
declare(strict_types=1);
/** @var bool $agendaShowExpertNamesAdmin */
/** @var bool $expertEditNotFound */
/** @var string $expertView */
if (!($adminExpertAgendaUi ?? false)) {
    return;
}
?>
<section class="admin-agendas-section scroll-margin-admin" id="admin-tools-agendas" aria-labelledby="admin-agendas-heading">
  <header class="admin-agendas-section__header d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom border-secondary">
    <div>
      <h2 class="h5 mb-1" id="admin-agendas-heading">
        <i class="fa-solid fa-calendar-days me-2 text-info" aria-hidden="true"></i>Agendas
      </h2>
      <p class="small text-light-emphasis mb-0" id="admin-agendas-intro">
        Elige un experto para horario y datos. Abajo, en <strong>Ajustes de agenda</strong>, opciones globales puntuales.
      </p>
    </div>
    <a href="admin.php#admin-tools-experts" class="btn btn-outline-secondary btn-sm flex-shrink-0">
      <i class="fa-solid fa-users me-1" aria-hidden="true"></i>Listado de expertos
    </a>
  </header>

  <?php require __DIR__ . "/admin_agendas_accordions.php"; ?>
</section>
