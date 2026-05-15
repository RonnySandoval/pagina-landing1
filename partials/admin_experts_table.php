<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var list<array<string, mixed>> $services */
/** @var int $expertEditId */
/** @var string $expertView */
?>
<div id="admin-experts-list" class="table-responsive mb-4 scroll-margin-admin">
  <table class="table table-sm table-hover table-borderless align-middle mb-0 admin-experts-table">
    <thead>
      <tr class="text-secondary small">
        <th scope="col">Nombre</th>
        <th scope="col" class="text-center">Orden</th>
        <th scope="col" class="text-center">Estado</th>
        <th scope="col" class="text-start expert-services-col">Servicios</th>
        <th scope="col" class="text-end">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($experts as $ex): ?>
        <?php
          $rowId = (int)($ex["id"] ?? 0);
          $svcSet = $expertServiceIds[$rowId] ?? [];
          $nSvc = count($svcSet);
          $linkedTitlesStr = "";
          if ($nSvc > 0) {
              $t = [];
              foreach ($services as $svc) {
                  $sid = (int)($svc["id"] ?? 0);
                  if ($sid > 0 && !empty($svcSet[$sid])) {
                      $t[] = (string)($svc["title"] ?? "");
                  }
              }
              $linkedTitlesStr = implode(", ", $t);
          }
          $em = trim((string)($ex["email"] ?? ""));
          $ph = trim((string)($ex["phone"] ?? ""));
          $hasExtra = ($em !== "" || $ph !== "" || trim((string)($ex["notes"] ?? "")) !== "");
          $exActive = (int)($ex["is_active"] ?? 0) === 1;
          $rowSelected = $rowId > 0 && $rowId === $expertEditId && $expertView !== "";
          $rowClass = $rowSelected ? "table-active" : "";
        ?>
        <tr<?= $rowClass !== "" ? ' class="' . h($rowClass) . '"' : "" ?>>
          <td>
            <strong><?= h((string)($ex["display_name"] ?? "")) ?></strong>
            <?php if ($hasExtra): ?>
              <span class="badge rounded-pill text-bg-info expert-pill d-inline-flex align-items-center ms-1" title="Correo, teléfono o notas en la ficha">
                <i class="fa-solid fa-id-card" aria-hidden="true"></i><span class="visually-hidden"> </span>Info
              </span>
            <?php endif; ?>
          </td>
          <td class="text-center font-monospace small"><?= (int)($ex["sort_order"] ?? 999) ?></td>
          <td class="text-center">
            <?php if ($exActive): ?>
              <span class="badge rounded-pill text-bg-success expert-pill d-inline-flex align-items-center" title="Visible en la web según configuración">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Activo
              </span>
            <?php else: ?>
              <span class="badge rounded-pill text-bg-secondary expert-pill d-inline-flex align-items-center" title="No se muestra en la agenda pública">
                <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Inactivo
              </span>
            <?php endif; ?>
          </td>
          <td class="text-start expert-services-col">
            <?php if ($nSvc === 0): ?>
              <span class="text-light-emphasis small font-monospace" title="Sin servicios vinculados">0</span>
            <?php else: ?>
              <div class="d-flex align-items-center gap-1 expert-row-services js-expert-svc-fit">
                <div class="expert-svc-icons-slot">
                  <div class="expert-svc-icons-inner">
                    <?php foreach ($services as $svc): ?>
                      <?php
                        $sid = (int)($svc["id"] ?? 0);
                        if ($sid <= 0 || empty($svcSet[$sid])) {
                            continue;
                        }
                        $svcTitle = (string)($svc["title"] ?? "");
                        $svcIcon = (string)($svc["icon_class"] ?: "fa-solid fa-star");
                      ?>
                      <span
                        class="expert-svc-icon d-inline-flex align-items-center justify-content-center rounded-2 border border-secondary bg-body-tertiary text-body"
                        title="<?= h($svcTitle) ?>"
                        data-service-title="<?= h($svcTitle) ?>"
                      >
                        <i class="<?= h($svcIcon) ?>" aria-hidden="true"></i>
                        <span class="visually-hidden"><?= h($svcTitle) ?></span>
                      </span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <span
                  class="expert-svc-icon expert-svc-overflow-plus align-items-center justify-content-center rounded-2 border border-secondary bg-body-tertiary text-body"
                  role="img"
                  aria-hidden="true"
                >
                  <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </span>
                <span
                  class="badge rounded-pill text-bg-secondary border expert-pill d-inline-flex align-items-center expert-svc-count-badge"
                  title="<?= h($linkedTitlesStr !== "" ? $linkedTitlesStr : "Servicios vinculados") ?>"
                >
                  <?= (int)$nSvc ?>
                </span>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <div class="d-inline-flex flex-wrap align-items-center justify-content-end admin-expert-row-actions gap-1">
              <a
                class="btn btn-outline-info btn-sm<?= ($rowSelected && $expertView === "schedule") ? " active" : "" ?>"
                href="<?= h(admin_expert_page_url($rowId, "schedule")) ?>"
                title="Horario y disponibilidad"
                aria-label="Horario de <?= h((string)($ex["display_name"] ?? "experto")) ?>"
              >
                <i class="fa-solid fa-calendar-week" aria-hidden="true"></i><span class="visually-hidden"> Horario</span>
              </a>
              <a
                class="btn btn-outline-light btn-sm<?= ($rowSelected && $expertView === "edit") ? " active" : "" ?>"
                href="<?= h(admin_expert_page_url($rowId, "edit")) ?>"
                title="Editar datos del experto"
                aria-label="Editar <?= h((string)($ex["display_name"] ?? "experto")) ?>"
              >
                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden"> Editar</span>
              </a>
              <form method="post" onsubmit="return confirm('¿Eliminar este experto?');">
                <input type="hidden" name="action" value="delete_expert">
                <input type="hidden" name="expert_id" value="<?= $rowId ?>">
                <button
                  type="submit"
                  class="btn btn-outline-danger btn-sm"
                  title="Eliminar experto"
                  aria-label="Eliminar <?= h((string)($ex["display_name"] ?? "experto")) ?>"
                >
                  <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden"> Eliminar</span>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
