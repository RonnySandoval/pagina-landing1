<?php
declare(strict_types=1);
/** @var array<string, mixed> $expertEdit */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var list<array<string, mixed>> $services */
/** @var string $expertEditFormReturnTo */
/** @var string $expertEditFormTab */
if (!is_array($expertEdit) || !isset($expertEdit["id"])) {
    return;
}
$eid = (int)$expertEdit["id"];
$svcSet = $expertServiceIds[$eid] ?? [];
$expertEditFormReturnTo = (string)($expertEditFormReturnTo ?? "");
$expertEditFormTab = (string)($expertEditFormTab ?? "datos");
$formIdPrefix = $expertEditFormReturnTo === "agendas" ? "ag_" : "ex_";
?>
<form method="post" class="row g-3 admin-expert-edit-form js-admin-ajax-form" data-ajax-scope="expert-save">
  <input type="hidden" name="action" value="save_expert">
  <input type="hidden" name="expert_id" value="<?= $eid ?>">
  <?php if ($expertEditFormReturnTo !== ""): ?>
    <input type="hidden" name="return_to" value="<?= h($expertEditFormReturnTo) ?>">
    <input type="hidden" name="expert_tab" value="<?= h($expertEditFormTab) ?>">
  <?php endif; ?>
  <div class="col-md-8">
    <label class="form-label" for="<?= h($formIdPrefix) ?>display_name">Nombre visible</label>
    <input id="<?= h($formIdPrefix) ?>display_name" class="form-control" type="text" name="display_name" value="<?= h((string)($expertEdit["display_name"] ?? "")) ?>" maxlength="180" required>
  </div>
  <div class="col-md-4">
    <label class="form-label" for="<?= h($formIdPrefix) ?>sort_order">Orden</label>
    <input id="<?= h($formIdPrefix) ?>sort_order" class="form-control" type="number" name="sort_order" value="<?= (int)($expertEdit["sort_order"] ?? 999) ?>">
  </div>
  <div class="col-md-6">
    <label class="form-label" for="<?= h($formIdPrefix) ?>email">Correo (opcional)</label>
    <input id="<?= h($formIdPrefix) ?>email" class="form-control" type="email" name="email" value="<?= h((string)($expertEdit["email"] ?? "")) ?>" maxlength="180" placeholder="contacto@ejemplo.com" autocomplete="off">
    <div class="form-text text-light-emphasis">Útil para contacto o avisos por correo.</div>
  </div>
  <div class="col-md-6">
    <label class="form-label" for="<?= h($formIdPrefix) ?>phone">Teléfono (opcional)</label>
    <input id="<?= h($formIdPrefix) ?>phone" class="form-control" type="text" name="phone" value="<?= h((string)($expertEdit["phone"] ?? "")) ?>" maxlength="48" placeholder="+34 …" autocomplete="off">
  </div>
  <div class="col-12">
    <label class="form-label" for="<?= h($formIdPrefix) ?>notes">Notas / información adicional</label>
    <textarea id="<?= h($formIdPrefix) ?>notes" class="form-control" name="notes" rows="4" maxlength="12000" placeholder="Especialidad, disponibilidad general, comentarios internos…"><?= h((string)($expertEdit["notes"] ?? "")) ?></textarea>
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
              id="<?= h($formIdPrefix) ?>svc_<?= $sid ?>"
              name="expert_services[]"
              value="<?= $sid ?>"
              <?= isset($svcSet[$sid]) ? "checked" : "" ?>
            >
            <label class="form-check-label" for="<?= h($formIdPrefix) ?>svc_<?= $sid ?>">
              <i class="<?= h((string)($svc["icon_class"] ?: "fa-solid fa-star")) ?> me-1"></i><?= h((string)($svc["title"] ?? "")) ?>
            </label>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="<?= h($formIdPrefix) ?>active" name="is_active" <?= ((int)($expertEdit["is_active"] ?? 0) === 1) ? "checked" : "" ?>>
      <label class="form-check-label" for="<?= h($formIdPrefix) ?>active">Activo</label>
    </div>
  </div>
  <div class="col-12 admin-actions mb-0">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar datos</button>
  </div>
</form>
