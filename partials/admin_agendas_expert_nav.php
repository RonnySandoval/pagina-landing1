<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var int $agendasExpertId */
/** @var string $agendasExpertTab */
$agendasExpertId = (int)($agendasExpertId ?? 0);
$agendasExpertTab = (string)($agendasExpertTab ?? "schedule");
?>
<nav class="admin-agendas-expert-nav mb-3" aria-label="Seleccionar experto">
  <p class="small text-secondary mb-2 mb-md-1">Experto</p>
  <ul class="nav nav-pills flex-wrap gap-1 admin-agendas-expert-pills" role="tablist">
    <?php foreach ($experts as $ex): ?>
      <?php
        $eid = (int)($ex["id"] ?? 0);
        if ($eid <= 0) {
            continue;
        }
        $name = trim((string)($ex["display_name"] ?? ""));
        $isActive = $eid === $agendasExpertId;
        $isInactive = (int)($ex["is_active"] ?? 0) !== 1;
      ?>
      <li class="nav-item" role="presentation">
        <a
          class="nav-link<?= $isActive ? " active" : "" ?><?= $isInactive ? " text-secondary" : "" ?>"
          href="<?= h(admin_agenda_expert_url($eid, $agendasExpertTab)) ?>"
          <?= $isActive ? 'aria-current="page"' : "" ?>
        >
          <?= h($name !== "" ? $name : "Experto #" . $eid) ?>
          <?php if ($isInactive): ?>
            <span class="badge text-bg-secondary ms-1">off</span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>

<?php if ($agendasExpertId > 0): ?>
  <ul class="nav nav-tabs admin-agendas-expert-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
      <a
        class="nav-link<?= $agendasExpertTab === "schedule" ? " active" : "" ?>"
        href="<?= h(admin_agenda_expert_url($agendasExpertId, "schedule")) ?>"
        <?= $agendasExpertTab === "schedule" ? 'aria-current="page"' : "" ?>
      >
        <i class="fa-solid fa-calendar-week me-1" aria-hidden="true"></i>Horario y citas
      </a>
    </li>
    <li class="nav-item" role="presentation">
      <a
        class="nav-link<?= $agendasExpertTab === "datos" ? " active" : "" ?>"
        href="<?= h(admin_agenda_expert_url($agendasExpertId, "datos")) ?>"
        <?= $agendasExpertTab === "datos" ? 'aria-current="page"' : "" ?>
      >
        <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Datos del experto
      </a>
    </li>
  </ul>
<?php endif; ?>
