<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $experts */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var list<array<string, mixed>> $services */
/** @var int $expertEditId */
/** @var string $expertView */
$nExperts = count($experts);
?>
<div
  id="admin-experts-list"
  class="admin-filter-table scroll-margin-admin mb-4<?= $nExperts === 0 ? " admin-filter-table--empty-static" : "" ?>"
  data-admin-filter-table
>
  <?php if ($nExperts > 0): ?>
    <div class="admin-filter-table__meta">
      <span class="admin-filter-table__count small text-secondary" data-filter-count aria-live="polite"></span>
      <button type="button" class="btn btn-link btn-sm py-0 admin-filter-table__clear" data-filter-clear>Limpiar filtros</button>
    </div>
  <?php endif; ?>

  <div class="admin-filter-table__scroll table-responsive admin-experts-table-wrap">
    <table class="table table-sm table-hover align-middle mb-0 admin-filter-table__table admin-experts-table">
      <thead>
        <tr class="admin-filter-table__head-row">
          <th scope="col" class="expert-col-name" data-sort-key="name">
            <span class="adm-th-full">Nombre</span>
            <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-user-tie"></i></span>
          </th>
          <th scope="col" class="text-center expert-col-order" data-sort-key="order" title="Orden de visualización">
            <span class="adm-th-full">Orden</span>
            <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-arrow-down-1-9"></i></span>
            <span class="visually-hidden">Orden</span>
          </th>
          <th scope="col" class="text-center expert-col-status" data-sort-key="status" title="Estado">
            <span class="adm-th-full">Estado</span>
            <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-toggle-on"></i></span>
            <span class="visually-hidden">Estado</span>
          </th>
          <th scope="col" class="text-start expert-col-services expert-services-col" data-sort-key="services">
            <span class="adm-th-full">Servicios</span>
            <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-briefcase"></i></span>
            <span class="visually-hidden">Servicios</span>
          </th>
          <th scope="col" class="text-end expert-col-actions">
            <span class="adm-th-full">Acciones</span>
            <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-ellipsis"></i></span>
            <span class="visually-hidden">Acciones</span>
          </th>
        </tr>
        <?php if ($nExperts > 0): ?>
          <tr class="admin-filter-table__filter-row">
            <th scope="col" class="expert-col-name">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Filtrar nombre…"
                data-filter-col="name"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por nombre"
              />
            </th>
            <th scope="col" class="expert-col-order">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Orden"
                data-filter-col="order"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por orden"
              />
            </th>
            <th scope="col" class="expert-col-status">
              <select class="form-select form-select-sm admin-filter-table__col-input" data-filter-col="status" data-filter-type="select" aria-label="Filtrar por estado">
                <option value="all">Todos</option>
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
              </select>
            </th>
            <th scope="col" class="expert-col-services expert-services-col">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Servicios…"
                data-filter-col="services"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por servicios"
              />
            </th>
            <th scope="col" class="expert-col-actions" aria-hidden="true"></th>
          </tr>
        <?php endif; ?>
      </thead>
      <tbody>
        <?php if ($nExperts === 0): ?>
          <tr>
            <td colspan="5" class="text-muted small py-3">Aún no hay expertos en el listado.</td>
          </tr>
        <?php endif; ?>
        <?php $expertRowIdx = 0; ?>
        <?php foreach ($experts as $ex): ?>
          <?php
            $expertRowIdx++;
            $rowStripe = ($expertRowIdx % 2) === 0 ? " is-alt" : "";
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
            $sortOrder = (int)($ex["sort_order"] ?? 999);
            $filterName = strtolower(implode(" ", array_filter([$displayName, $em, $ph, $notes])));
            $filterServices = strtolower(implode(" ", array_filter([$linkedTitlesStr, (string)$nSvc])));
          ?>
          <tr
            class="admin-filter-table__row<?= $rowStripe ?><?= $rowClass !== "" ? " " . h($rowClass) : "" ?>"
            data-filter-row
            data-filter-id="<?= $rowId ?>"
            data-filter-name="<?= h($filterName) ?>"
            data-filter-order="<?= h((string)$sortOrder) ?>"
            data-filter-status="<?= $exActive ? "active" : "inactive" ?>"
            data-filter-services="<?= h($filterServices) ?>"
            data-sort-name="<?= h($displayName) ?>"
            data-sort-order="<?= $sortOrder ?>"
            data-sort-status="<?= $exActive ? "1" : "0" ?>"
            data-sort-services="<?= (int)$nSvc ?>"
          >
            <td class="expert-col-name">
              <div class="d-flex align-items-center flex-wrap gap-1">
                <strong class="expert-row-name admin-filter-table__text-2l"><?= h($displayName) ?></strong>
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
            <td class="text-center font-monospace small expert-col-order"><?= $sortOrder ?></td>
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
                  title="Horario"
                  aria-label="Horario de <?= h($displayName) ?>"
                >
                  <i class="fa-solid fa-calendar-week" aria-hidden="true"></i>
                </a>
                <a
                  class="btn btn-outline-secondary btn-sm<?= ($rowSelected && $expertView === "edit") ? " active" : "" ?>"
                  href="<?= h(admin_expert_page_url($rowId, "edit")) ?>"
                  title="Editar"
                  aria-label="Editar <?= h($displayName) ?>"
                >
                  <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                </a>
                <form method="post" class="js-admin-ajax-form js-expert-delete-form" data-ajax-scope="expert-delete">
                  <input type="hidden" name="action" value="delete_expert">
                  <input type="hidden" name="expert_id" value="<?= $rowId ?>">
                  <button
                    type="submit"
                    class="btn btn-outline-danger btn-sm"
                    title="Eliminar"
                    aria-label="Eliminar <?= h($displayName) ?>"
                  >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php if ($showInfoPanel): ?>
            <tr class="collapse expert-detail-row admin-filter-table__detail-row" id="expert-info-<?= $rowId ?>" data-filter-detail-for="<?= $rowId ?>">
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
        <tr class="admin-filter-table__empty" data-filter-empty hidden>
          <td colspan="5" class="text-center text-muted small py-4">
            <i class="fa-solid fa-filter-circle-xmark me-1" aria-hidden="true"></i>Ningún experto coincide con el filtro.
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
