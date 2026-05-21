<?php
declare(strict_types=1);
/**
 * @var list<array<string, mixed>> $portalClients
 */
$nClients = count($portalClients);
?>
<?php if ($nClients === 0): ?>
  <p class="small text-light-emphasis mb-0">Aún no hay cuentas. Comparte la URL del acordeón «Rutas» o el enlace «Clientes» del menú de la web.</p>
<?php else: ?>
  <div
    id="admin-portal-clients-list"
    class="admin-filter-table admin-filter-table--grid admin-portal-clients-filter-table scroll-margin-admin"
    data-admin-filter-table
  >
    <div class="admin-filter-table__meta">
      <span class="admin-filter-table__count small text-secondary" data-filter-count aria-live="polite"></span>
      <button type="button" class="btn btn-link btn-sm py-0 admin-filter-table__clear" data-filter-clear>Limpiar filtros</button>
    </div>

    <div class="admin-filter-table__scroll table-responsive">
      <table class="table table-sm table-hover table-borderless align-middle mb-0 admin-filter-table__table admin-portal-clients-table">
        <thead>
          <tr class="admin-filter-table__head-row">
            <th scope="col" class="portal-col-email" data-sort-key="email">
              <span class="adm-th-full">Correo</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
            </th>
            <th scope="col" class="portal-col-name" data-sort-key="name">
              <span class="adm-th-full">Nombre</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
            </th>
            <th scope="col" class="text-center portal-col-account" data-sort-key="account" title="Estado de la cuenta">
              <span class="adm-th-full">Cuenta</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-user-check"></i></span>
              <span class="visually-hidden">Cuenta</span>
            </th>
            <th scope="col" class="text-center portal-col-smtp" data-sort-key="smtp" title="Envío por correo SMTP">
              <span class="adm-th-full">Correo SMTP</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-paper-plane"></i></span>
              <span class="visually-hidden">Correo SMTP</span>
            </th>
            <th scope="col" class="text-end portal-col-actions">
              <span class="adm-th-full">Acciones</span>
              <span class="adm-th-short" aria-hidden="true"><i class="fa-solid fa-ellipsis"></i></span>
              <span class="visually-hidden">Acciones</span>
            </th>
          </tr>
          <tr class="admin-filter-table__filter-row">
            <th scope="col" class="portal-col-email">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Correo…"
                data-filter-col="email"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por correo"
              />
            </th>
            <th scope="col" class="portal-col-name">
              <input
                type="search"
                class="form-control form-control-sm admin-filter-table__col-input"
                placeholder="Nombre…"
                data-filter-col="name"
                data-filter-type="text"
                autocomplete="off"
                aria-label="Filtrar por nombre"
              />
            </th>
            <th scope="col" class="portal-col-account">
              <select class="form-select form-select-sm admin-filter-table__col-input" data-filter-col="account" data-filter-type="select" aria-label="Filtrar por cuenta">
                <option value="all">Todos</option>
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
              </select>
            </th>
            <th scope="col" class="portal-col-smtp">
              <select class="form-select form-select-sm admin-filter-table__col-input" data-filter-col="smtp" data-filter-type="select" aria-label="Filtrar por envío">
                <option value="all">Todos</option>
                <option value="mail">Correo</option>
                <option value="web">Solo web</option>
              </select>
            </th>
            <th scope="col" class="portal-col-actions" aria-hidden="true"></th>
          </tr>
        </thead>
        <tbody>
          <?php $pcRowIdx = 0; ?>
          <?php foreach ($portalClients as $pc): ?>
            <?php
              $pcRowIdx++;
              $rowStripe = ($pcRowIdx % 2) === 0 ? " is-alt" : "";
              $pid = (int)($pc["id"] ?? 0);
              $active = (int)($pc["is_active"] ?? 0) === 1;
              $notifyOut = (int)($pc["email_notify_outbound"] ?? 1) === 1;
              $email = trim((string)($pc["email"] ?? ""));
              $name = trim((string)($pc["display_name"] ?? ""));
              $createdAt = trim((string)($pc["created_at"] ?? ""));
              $filterEmail = strtolower($email);
              $filterName = strtolower($name);
              $hasDetail = $name !== "" || $createdAt !== "";
            ?>
            <tr
              class="admin-filter-table__row<?= $rowStripe ?>"
              data-filter-row
              data-filter-id="<?= $pid ?>"
              data-filter-email="<?= h($filterEmail) ?>"
              data-filter-name="<?= h($filterName) ?>"
              data-filter-account="<?= $active ? "active" : "inactive" ?>"
              data-filter-smtp="<?= $notifyOut ? "mail" : "web" ?>"
              data-sort-email="<?= h($email) ?>"
              data-sort-name="<?= h($name) ?>"
              data-sort-account="<?= $active ? "1" : "0" ?>"
              data-sort-smtp="<?= $notifyOut ? "1" : "0" ?>"
            >
              <td class="portal-col-email font-monospace small" data-cell-label="Correo">
                <div class="d-flex align-items-center gap-1 portal-email-cell">
                  <span class="portal-client-email-line admin-filter-table__text-2l flex-grow-1"><?= h($email) ?></span>
                  <?php if ($hasDetail): ?>
                    <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm py-0 px-1 portal-client-expand-btn"
                      data-bs-toggle="collapse"
                      data-bs-target="#portal-client-mobile-<?= $pid ?>"
                      aria-expanded="false"
                      aria-controls="portal-client-mobile-<?= $pid ?>"
                      title="Ver detalle del cliente"
                    >
                      <i class="fa-solid fa-plus portal-client-expand-icon" aria-hidden="true"></i>
                      <span class="visually-hidden">Ver detalle del cliente</span>
                    </button>
                  <?php endif; ?>
                </div>
                <?php if ($name !== ""): ?>
                  <div class="portal-client-name-mobile small text-secondary admin-u-collapse-on-wide"><?= h($name) ?></div>
                <?php endif; ?>
              </td>
              <td class="portal-col-name small" data-cell-label="Nombre">
                <span class="admin-filter-table__text-2l" title="<?= h($name) ?>"><?= h($name) ?></span>
              </td>
              <td class="text-center portal-col-account" data-cell-label="Cuenta">
                <form method="post" class="d-inline m-0 js-admin-ajax-form js-portal-client-toggle" data-ajax-scope="client-toggle" onclick="event.stopPropagation();">
                  <input type="hidden" name="action" value="client_toggle_active">
                  <input type="hidden" name="client_id" value="<?= $pid ?>">
                  <button
                    type="submit"
                    class="btn btn-sm rounded-pill portal-client-pill portal-client-toggle-btn border-0 d-inline-flex align-items-center text-nowrap <?= $active ? "text-bg-success" : "text-bg-secondary" ?>"
                    title="<?= $active ? "Cuenta activa: puede iniciar sesión. Pulsa para desactivar (no podrá entrar)." : "Cuenta inactiva. Pulsa para reactivar el acceso." ?>"
                    aria-label="<?= $active ? "Cuenta activa, pulsar para desactivar" : "Cuenta inactiva, pulsar para activar" ?>"
                    onclick="event.stopPropagation();"
                  >
                    <?php if ($active): ?>
                      <i class="fa-solid fa-user-check" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Activo</span>
                    <?php else: ?>
                      <i class="fa-solid fa-user-slash" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Inactivo</span>
                    <?php endif; ?>
                  </button>
                </form>
              </td>
              <td class="text-center portal-col-smtp" data-cell-label="Correo SMTP">
                <form method="post" class="d-inline m-0 js-admin-ajax-form js-portal-client-toggle" data-ajax-scope="client-toggle" onclick="event.stopPropagation();">
                  <input type="hidden" name="action" value="client_toggle_email_notify">
                  <input type="hidden" name="client_id" value="<?= $pid ?>">
                  <button
                    type="submit"
                    class="btn btn-sm rounded-pill portal-client-pill portal-client-toggle-btn border-0 d-inline-flex align-items-center text-nowrap <?= $notifyOut ? "text-bg-info" : "text-bg-secondary border border-secondary" ?>"
                    title="<?= $notifyOut ? "Envío por correo activo (SMTP al responder). Pulsa para solo bandeja web." : "Solo bandeja web. Pulsa para intentar envío SMTP al responder desde Mensajes." ?>"
                    aria-label="<?= $notifyOut ? "Correo SMTP activo, pulsar para desactivar" : "Solo web, pulsar para activar envío SMTP" ?>"
                    onclick="event.stopPropagation();"
                  >
                    <?php if ($notifyOut): ?>
                      <i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Correo</span>
                    <?php else: ?>
                      <i class="fa-solid fa-display" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Solo web</span>
                    <?php endif; ?>
                  </button>
                </form>
              </td>
              <td class="text-end portal-col-actions" data-cell-label="Acciones">
                <div class="d-inline-flex flex-nowrap align-items-center justify-content-end admin-portal-client-actions">
                  <form method="post" class="m-0 js-admin-ajax-form js-portal-client-delete" data-ajax-scope="client-delete" onclick="event.stopPropagation();">
                    <input type="hidden" name="action" value="client_delete">
                    <input type="hidden" name="client_id" value="<?= $pid ?>">
                    <button
                      type="submit"
                      class="btn btn-outline-danger btn-sm"
                      title="Eliminar cliente de forma permanente"
                      aria-label="Eliminar cliente"
                      onclick="event.stopPropagation();"
                    >
                      <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden"> Eliminar</span>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php if ($hasDetail): ?>
              <tr class="collapse portal-client-detail-row admin-filter-table__detail-row" id="portal-client-mobile-<?= $pid ?>" data-filter-detail-for="<?= $pid ?>">
                <td colspan="5" class="pt-0 pb-2 px-2">
                  <div class="portal-client-detail-panel small border border-secondary rounded p-2 mt-1">
                    <ul class="list-unstyled mb-0 portal-client-detail-list">
                      <?php if ($name !== ""): ?>
                        <li><i class="fa-solid fa-user me-1 text-secondary" aria-hidden="true"></i><?= h($name) ?></li>
                      <?php endif; ?>
                      <?php if ($createdAt !== ""): ?>
                        <li><i class="fa-solid fa-calendar-plus me-1 text-secondary" aria-hidden="true"></i>Registro: <span class="font-monospace"><?= h($createdAt) ?></span></li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          <tr class="admin-filter-table__empty" data-filter-empty hidden>
            <td colspan="5" class="text-center text-muted small py-4">
              <i class="fa-solid fa-filter-circle-xmark me-1" aria-hidden="true"></i>Ningún cliente coincide con el filtro.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
