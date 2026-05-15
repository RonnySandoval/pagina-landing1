<?php
declare(strict_types=1);
/** @var array<string, mixed> $expertEdit */
/** @var array<int, array<int, bool>> $expertServiceIds */
/** @var list<array<string, mixed>> $services */
if (!is_array($expertEdit) || !isset($expertEdit["id"])) {
    return;
}
$eid = (int)$expertEdit["id"];
$svcSet = $expertServiceIds[$eid] ?? [];
?>
<div id="admin-expert-edit" class="admin-expert-subpanel scroll-margin-admin p-3">
  <nav class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <a href="admin.php#admin-experts-list" class="link-light"><i class="fa-solid fa-arrow-left me-1"></i>Listado</a>
    <span class="text-secondary" aria-hidden="true">·</span>
    <a href="<?= h(admin_expert_page_url($eid, "schedule")) ?>" class="link-light"><i class="fa-solid fa-calendar-week me-1"></i>Horario</a>
  </nav>
  <form method="post" class="row g-3">
    <input type="hidden" name="action" value="save_expert">
    <input type="hidden" name="expert_id" value="<?= $eid ?>">
    <div class="col-md-8">
      <label class="form-label">Nombre visible</label>
      <input class="form-control" type="text" name="display_name" value="<?= h((string)($expertEdit["display_name"] ?? "")) ?>" maxlength="180" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Orden</label>
      <input class="form-control" type="number" name="sort_order" value="<?= (int)($expertEdit["sort_order"] ?? 999) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Correo (opcional)</label>
      <input class="form-control" type="email" name="email" value="<?= h((string)($expertEdit["email"] ?? "")) ?>" maxlength="180" placeholder="contacto@ejemplo.com" autocomplete="off">
      <div class="form-text text-light-emphasis">Útil para contacto o para enlazar luego la cuenta de experto.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Teléfono (opcional)</label>
      <input class="form-control" type="text" name="phone" value="<?= h((string)($expertEdit["phone"] ?? "")) ?>" maxlength="48" placeholder="+34 …" autocomplete="off">
    </div>
    <div class="col-12">
      <label class="form-label">Notas / información adicional</label>
      <textarea class="form-control" name="notes" rows="4" maxlength="12000" placeholder="Especialidad, disponibilidad general, comentarios internos…"><?= h((string)($expertEdit["notes"] ?? "")) ?></textarea>
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
                id="ex_detail_svc_<?= $sid ?>"
                name="expert_services[]"
                value="<?= $sid ?>"
                <?= isset($svcSet[$sid]) ? "checked" : "" ?>
              >
              <label class="form-check-label" for="ex_detail_svc_<?= $sid ?>">
                <i class="<?= h((string)($svc["icon_class"] ?: "fa-solid fa-star")) ?> me-1"></i><?= h((string)($svc["title"] ?? "")) ?>
              </label>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="expert_detail_active" name="is_active" <?= ((int)($expertEdit["is_active"] ?? 0) === 1) ? "checked" : "" ?>>
        <label class="form-check-label" for="expert_detail_active">Activo</label>
      </div>
    </div>
    <div class="col-12 admin-actions">
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar datos</button>
    </div>
  </form>
  <form method="post" class="mt-3 pt-3 border-top border-secondary" onsubmit="return confirm('¿Eliminar este experto? Se quitarán sus vínculos con servicios.');">
    <input type="hidden" name="action" value="delete_expert">
    <input type="hidden" name="expert_id" value="<?= $eid ?>">
    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash me-2"></i>Eliminar experto</button>
  </form>
</div>
