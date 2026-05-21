<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var list<array<string, mixed>> $services */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var int $agendasExpertId */
/** @var string $agendasExpertTab */

$agendasExpertId = (int)($agendasExpertId ?? 0);
$agendasExpertTab = (string)($agendasExpertTab ?? "schedule");

$svcList = $services ?? [];
$exSvc = $expertServiceIds ?? [];

// Mapa: servicio_id => meta del servicio.
$svcMeta = [];
foreach ($svcList as $svc) {
    $sid = (int)($svc["id"] ?? 0);
    if ($sid > 0) {
        $svcMeta[$sid] = $svc;
    }
}

// Construye grupos servicio_id => list<experts>. "0" = sin servicio.
$groups = [];
foreach ($experts as $ex) {
    $eid = (int)($ex["id"] ?? 0);
    if ($eid <= 0) {
        continue;
    }
    $linked = $exSvc[$eid] ?? [];
    $linkedSids = [];
    foreach ($linked as $sid => $on) {
        if ($on) {
            $linkedSids[] = (int)$sid;
        }
    }
    if (!$linkedSids) {
        $groups[0][] = $ex;
    } else {
        foreach ($linkedSids as $sid) {
            $groups[$sid][] = $ex;
        }
    }
}

// Lista ordenada de IDs de servicio (orden de $svcList + "sin servicio" al final).
$svcOrder = [];
foreach ($svcList as $svc) {
    $sid = (int)($svc["id"] ?? 0);
    if ($sid > 0 && isset($groups[$sid])) {
        $svcOrder[] = $sid;
    }
}
if (isset($groups[0])) {
    $svcOrder[] = 0;
}

// Servicio inicial: el primero que contenga al experto activo; si no, el primer grupo.
$initialSid = $svcOrder[0] ?? 0;
if ($agendasExpertId > 0) {
    foreach ($svcOrder as $sid) {
        foreach ($groups[$sid] as $ex) {
            if ((int)($ex["id"] ?? 0) === $agendasExpertId) {
                $initialSid = $sid;
                break 2;
            }
        }
    }
}

$svcLabel = static function (int $sid) use ($svcMeta): array {
    if ($sid === 0) {
        return ["title" => "Sin servicio", "icon" => "fa-solid fa-circle-question"];
    }
    $svc = $svcMeta[$sid] ?? [];
    $title = trim((string)($svc["title"] ?? ""));
    if ($title === "") {
        $title = "Servicio #" . $sid;
    }
    $icon = trim((string)($svc["icon_class"] ?? ""));
    if ($icon === "") {
        $icon = "fa-solid fa-briefcase";
    }
    return ["title" => $title, "icon" => $icon];
};
?>
<?php if (!empty($svcOrder)): ?>
<nav class="admin-agendas-expert-nav mb-3" aria-label="Seleccionar experto" data-admin-agendas-expert-nav>
  <div class="admin-agendas-expert-nav__head">
    <label class="admin-agendas-expert-nav__label" for="admin-agendas-expert-service-select">Servicio</label>
    <select
      id="admin-agendas-expert-service-select"
      class="form-select form-select-sm admin-agendas-expert-nav__select"
      data-admin-agendas-expert-service
    >
      <?php foreach ($svcOrder as $sid): ?>
        <?php $meta = $svcLabel($sid); ?>
        <option value="<?= (int)$sid ?>"<?= $sid === $initialSid ? " selected" : "" ?>>
          <?= h($meta["title"]) ?> (<?= count($groups[$sid]) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="admin-agendas-expert-groups">
    <?php foreach ($svcOrder as $sid): ?>
      <?php $meta = $svcLabel($sid); ?>
      <div
        class="admin-agendas-expert-group<?= $sid === 0 ? " admin-agendas-expert-group--no-svc" : "" ?>"
        data-service-id="<?= (int)$sid ?>"
        <?= $sid === $initialSid ? "" : "hidden" ?>
      >
        <div class="admin-agendas-expert-group__head" aria-hidden="true">
          <i class="<?= h($meta["icon"]) ?>" aria-hidden="true"></i>
          <span class="admin-agendas-expert-group__title"><?= h($meta["title"]) ?></span>
        </div>
        <ul class="admin-agendas-expert-chips" role="list">
          <?php foreach ($groups[$sid] as $ex): ?>
            <?php
              $eid = (int)($ex["id"] ?? 0);
              $name = trim((string)($ex["display_name"] ?? ""));
              $label = $name !== "" ? $name : "Experto #" . $eid;
              $isActive = $eid === $agendasExpertId;
              $isInactive = (int)($ex["is_active"] ?? 0) !== 1;
              $classes = "admin-agendas-expert-chip";
              if ($isActive) {
                  $classes .= " is-active";
              }
              if ($isInactive) {
                  $classes .= " is-inactive";
              }
            ?>
            <li class="admin-agendas-expert-chip-item">
              <a
                class="<?= h($classes) ?>"
                href="<?= h(admin_agenda_expert_url($eid, $agendasExpertTab)) ?>"
                <?= $isActive ? 'aria-current="page"' : "" ?>
                title="<?= h($label . ($isInactive ? " (inactivo)" : "")) ?>"
              >
                <span class="admin-agendas-expert-chip__name"><?= h($label) ?></span>
                <?php if ($isInactive): ?>
                  <span class="admin-agendas-expert-chip__badge" aria-label="Inactivo">off</span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</nav>
<?php endif; ?>

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

<script>
  (function () {
    "use strict";
    var nav = document.querySelector("[data-admin-agendas-expert-nav]");
    if (!nav) return;
    var select = nav.querySelector("[data-admin-agendas-expert-service]");
    if (!select) return;
    var groups = nav.querySelectorAll(".admin-agendas-expert-group[data-service-id]");
    function show(sid) {
      groups.forEach(function (g) {
        g.hidden = g.getAttribute("data-service-id") !== String(sid);
      });
    }
    select.addEventListener("change", function () {
      show(select.value);
    });
  })();
</script>
