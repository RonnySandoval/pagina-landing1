<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var list<array<string, mixed>> $services */
/** @var int $expertEditId */
/** @var string $expertView */
?>
<div id="admin-experts-list" class="table-responsive mb-4 scroll-margin-admin admin-experts-table-wrap">
  <table class="table table-sm table-hover table-borderless align-middle mb-0 admin-experts-table">
    <thead>
      <tr class="text-secondary small">
        <th scope="col" class="expert-col-name">Nombre</th>
        <th scope="col" class="text-center expert-col-order" title="Orden de visualización">
          <span class="expert-th-full">Orden</span>
          <span class="expert-th-short" aria-hidden="true"><i class="fa-solid fa-arrow-down-1-9"></i></span>
          <span class="visually-hidden">Orden</span>
        </th>
        <th scope="col" class="text-center expert-col-status" title="Estado">
          <span class="expert-th-full">Estado</span>
          <span class="expert-th-short" aria-hidden="true"><i class="fa-solid fa-toggle-on"></i></span>
          <span class="visually-hidden">Estado</span>
        </th>
        <th scope="col" class="text-start expert-col-services">Servicios</th>
        <th scope="col" class="text-end expert-col-actions">
          <span class="expert-th-full">Acciones</span>
          <span class="expert-th-short" aria-hidden="true"><i class="fa-solid fa-ellipsis"></i></span>
          <span class="visually-hidden">Acciones</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($experts as $ex): ?>
        <?php
          $rowId = (int)($ex["id"] ?? 0);
          $svcSet = $expertServiceIds[$rowId] ?? [];
          $nSvc = count($svcSet);
          $linkedTitles = [];
          $linkedSvcItems = [];
          foreach ($services as $svc) {
              $sid = (int)($svc["id"] ?? 0);
              if ($sid > 0 && !empty($svcSet[$sid])) {
                  $linkedTitles[] = (string)($svc["title"] ?? "");
                  $linkedSvcItems[] = $svc;
              }
          }
          $linkedTitlesStr = implode(", ", $linkedTitles);
          $em = trim((string)($ex["email"] ?? ""));
          $ph = trim((string)($ex["phone"] ?? ""));
          $notes = trim((string)($ex["notes"] ?? ""));
          $hasContact = ($em !== "" || $ph !== "" || $notes !== "");
          $showInfoPanel = $hasContact || $nSvc > 0;
          $exActive = (int)($ex["is_active"] ?? 0) === 1;
          $rowSelected = $rowId > 0 && $rowId === $expertEditId && $expertView !== "";
          $rowClass = $rowSelected ? "table-active" : "";
          $displayName = (string)($ex["display_name"] ?? "");
        ?>
        <tr<?= $rowClass !== "" ? ' class="' . h($rowClass) . '"' : "" ?>>
          <td class="expert-col-name">
            <div class="d-flex align-items-center flex-wrap gap-1">
              <strong class="expert-row-name"><?= h($displayName) ?></strong>
              <?php if ($showInfoPanel): ?>
                <button
                  type="button"
                  class="btn btn-outline-info btn-sm py-0 px-2 expert-info-btn expert-info-btn--mobile"
                  data-bs-toggle="collapse"
                  data-bs-target="#expert-info-<?= $rowId ?>"
                  aria-expanded="false"
                  aria-controls="expert-info-<?= $rowId ?>"
                  title="Ver contacto y servicios"
                >
                  Info
                </button>
              <?php endif; ?>
              <?php if ($hasContact): ?>
                <span class="badge rounded-pill text-bg-info expert-pill expert-info-badge expert-info-badge--desktop d-inline-flex align-items-center" title="Correo, teléfono o notas en la ficha">
                  <i class="fa-solid fa-id-card" aria-hidden="true"></i><span class="expert-pill-label ms-1">Info</span>
                </span>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-center font-monospace small expert-col-order"><?= (int)($ex["sort_order"] ?? 999) ?></td>
          <td class="text-center expert-col-status">
            <?php if ($exActive): ?>
              <span class="badge rounded-pill text-bg-success expert-pill d-inline-flex align-items-center" title="Visible en la web según configuración">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i><span class="expert-pill-label">Activo</span>
              </span>
            <?php else: ?>
              <span class="badge rounded-pill text-bg-secondary expert-pill d-inline-flex align-items-center" title="No se muestra en la agenda pública">
                <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><span class="expert-pill-label">Inactivo</span>
              </span>
            <?php endif; ?>
          </td>
          <td class="text-start expert-col-services">
            <?php if ($nSvc === 0): ?>
              <span class="text-muted small font-monospace" title="Sin servicios vinculados">0</span>
            <?php else: ?>
              <div class="d-flex align-items-center gap-1 expert-row-services js-expert-svc-fit">
                <div class="expert-svc-icons-slot">
                  <div class="expert-svc-icons-inner">
                    <?php foreach ($linkedSvcItems as $svc): ?>
                      <?php
                        $svcTitle = (string)($svc["title"] ?? "");
                        $svcIcon = (string)($svc["icon_class"] ?: "fa-solid fa-star");
                      ?>
                      <span
                        class="expert-svc-icon d-inline-flex align-items-center justify-content-center rounded-2 border border-secondary expert-svc-icon-chip"
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
                  class="expert-svc-icon expert-svc-overflow-plus align-items-center justify-content-center rounded-2 border border-secondary expert-svc-icon-chip"
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
          <td class="text-end expert-col-actions">
            <div class="d-inline-flex flex-nowrap align-items-center justify-content-end admin-expert-row-actions">
              <a
                class="btn btn-outline-info btn-sm<?= ($rowSelected && $expertView === "schedule") ? " active" : "" ?>"
                href="<?= h(admin_expert_page_url($rowId, "schedule", "", "week")) ?>"
                title="Horario y disponibilidad"
                aria-label="Horario de <?= h($displayName) ?>"
              >
                <i class="fa-solid fa-calendar-week" aria-hidden="true"></i><span class="visually-hidden"> Horario</span>
              </a>
              <a
                class="btn btn-outline-secondary btn-sm<?= ($rowSelected && $expertView === "edit") ? " active" : "" ?>"
                href="<?= h(admin_expert_page_url($rowId, "edit")) ?>"
                title="Editar datos del experto"
                aria-label="Editar <?= h($displayName) ?>"
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
                  aria-label="Eliminar <?= h($displayName) ?>"
                >
                  <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden"> Eliminar</span>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php if ($showInfoPanel): ?>
          <tr class="collapse expert-detail-row" id="expert-info-<?= $rowId ?>">
            <td colspan="5" class="pt-0 pb-2">
              <div class="expert-detail-panel small border border-secondary rounded p-2 mt-1">
                <?php if ($hasContact): ?>
                  <p class="fw-semibold mb-1 text-secondary">Contacto y notas</p>
                  <ul class="list-unstyled mb-2 expert-detail-list">
                    <?php if ($em !== ""): ?>
                      <li><i class="fa-solid fa-envelope me-1 text-secondary" aria-hidden="true"></i><?= h($em) ?></li>
                    <?php endif; ?>
                    <?php if ($ph !== ""): ?>
                      <li><i class="fa-solid fa-phone me-1 text-secondary" aria-hidden="true"></i><?= h($ph) ?></li>
                    <?php endif; ?>
                    <?php if ($notes !== ""): ?>
                      <li class="mt-1 text-muted"><?= nl2br(h($notes)) ?></li>
                    <?php endif; ?>
                  </ul>
                <?php endif; ?>
                <?php if ($nSvc > 0): ?>
                  <p class="fw-semibold mb-1 text-secondary">Servicios vinculados</p>
                  <ul class="list-unstyled mb-0 d-flex flex-wrap gap-2 expert-detail-svc-list">
                    <?php foreach ($linkedSvcItems as $svc): ?>
                      <li class="expert-detail-svc-item">
                        <i class="<?= h((string)($svc["icon_class"] ?: "fa-solid fa-star")) ?> me-1" aria-hidden="true"></i><?= h((string)($svc["title"] ?? "")) ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
