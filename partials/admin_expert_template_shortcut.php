<?php
declare(strict_types=1);
/**
 * Atajo para aplicar una o dos franjas a días seleccionados de la plantilla.
 *
 * @var string $templateShortcutAction expert_set_mon_fri_window|bulk_mon_fri_all_experts
 * @var int $templateShortcutExpertId 0 = todos los expertos
 * @var bool $templateShortcutCompact diseño compacto (horario masivo)
 * @var array{slot1_start: string, slot1_end: string, slot2_start: string, slot2_end: string}|null $templateShortcutPrefill
 */
$templateShortcutAction = (string)($templateShortcutAction ?? "expert_set_mon_fri_window");
$templateShortcutExpertId = (int)($templateShortcutExpertId ?? 0);
$templateShortcutCompact = (bool)($templateShortcutCompact ?? false);
$templatePrefill = $templateShortcutPrefill ?? [
    "slot1_start" => AGENDA_DEFAULT_MON_FRI_START,
    "slot1_end" => AGENDA_DEFAULT_MON_FRI_END,
    "slot2_start" => "",
    "slot2_end" => "",
];
$templateAjax = $templateShortcutExpertId > 0 && $templateShortcutAction === "expert_set_mon_fri_window";
$wdShort = ["D", "L", "M", "X", "J", "V", "S"];
$defaultDays = [1, 2, 3, 4, 5];
$confirmMsg = $templateShortcutExpertId > 0
    ? "¿Sustituir la plantilla de los días marcados para este experto?"
    : "¿Sustituir la plantilla de los días marcados para TODOS los expertos?";
?>
<form
  method="post"
  lang="es"
  class="expert-template-shortcut<?= $templateShortcutCompact ? " expert-template-shortcut--compact" : "" ?><?= $templateAjax ? " js-admin-ajax-form" : "" ?>"
  <?php if ($templateAjax): ?>
    data-ajax-scope="expert-template"
  <?php endif; ?>
  onsubmit="return confirm(<?= json_encode($confirmMsg, JSON_UNESCAPED_UNICODE) ?>);"
>
  <input type="hidden" name="action" value="<?= h($templateShortcutAction) ?>">
  <?php if ($templateShortcutExpertId > 0): ?>
    <input type="hidden" name="expert_id" value="<?= $templateShortcutExpertId ?>">
  <?php endif; ?>

  <fieldset class="expert-template-shortcut__days mb-3">
    <legend class="form-label small fw-semibold mb-2">Días de la plantilla</legend>
    <div class="d-flex flex-wrap gap-1 expert-template-shortcut__day-picks" role="group" aria-label="Días de la semana">
      <?php for ($wd = 0; $wd <= 6; $wd++): ?>
        <label class="expert-template-shortcut__day">
          <input
            type="checkbox"
            class="btn-check"
            name="weekdays[]"
            value="<?= $wd ?>"
            autocomplete="off"
            <?= in_array($wd, $defaultDays, true) ? "checked" : "" ?>
          >
          <span class="btn btn-sm btn-outline-secondary"><?= h($wdShort[$wd]) ?></span>
        </label>
      <?php endfor; ?>
    </div>
    <p class="form-text small mb-0 mt-2">
      Sustituye las franjas solo de los días marcados. Los demás días no cambian.
    </p>
  </fieldset>

  <div class="row g-2 align-items-end">
    <div class="col-12<?= $templateShortcutCompact ? "" : " col-lg-6" ?>">
      <label class="form-label small mb-1">Franja principal</label>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <input type="time" name="template_slot1_start" class="form-control form-control-sm expert-lvf-time" value="<?= h((string)$templatePrefill["slot1_start"]) ?>" required aria-label="Inicio franja 1">
        <span class="small text-secondary">a</span>
        <input type="time" name="template_slot1_end" class="form-control form-control-sm expert-lvf-time" value="<?= h((string)$templatePrefill["slot1_end"]) ?>" required aria-label="Fin franja 1">
      </div>
    </div>
    <div class="col-12<?= $templateShortcutCompact ? "" : " col-lg-6" ?>">
      <label class="form-label small mb-1">Segunda franja <span class="text-secondary fw-normal">(opcional)</span></label>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <input type="time" name="template_slot2_start" class="form-control form-control-sm expert-lvf-time" value="<?= h((string)$templatePrefill["slot2_start"]) ?>" aria-label="Inicio franja 2">
        <span class="small text-secondary">a</span>
        <input type="time" name="template_slot2_end" class="form-control form-control-sm expert-lvf-time" value="<?= h((string)$templatePrefill["slot2_end"]) ?>" aria-label="Fin franja 2">
      </div>
      <p class="form-text small mb-0">Debe empezar después de la franja principal (ej. tarde 15:00–19:00).</p>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2 mt-3">
    <button type="submit" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Aplicar plantilla
    </button>
  </div>
</form>

<?php
  $stdHidden = [
    ["name" => "action", "value" => $templateShortcutAction],
    ["name" => "use_defaults", "value" => "1"],
  ];
  foreach ([1, 2, 3, 4, 5] as $wd) {
      $stdHidden[] = ["name" => "weekdays[]", "value" => (string)$wd];
  }
  if ($templateShortcutExpertId > 0) {
      $stdHidden[] = ["name" => "expert_id", "value" => (string)$templateShortcutExpertId];
  }
  $stdConfirm = $templateShortcutExpertId > 0
      ? "¿Aplicar 9:00–18:00 de lunes a viernes a este experto?"
      : "¿Aplicar 9:00–18:00 de lunes a viernes a TODOS los expertos?";
?>
<form
  method="post"
  class="d-inline mt-2<?= $templateAjax ? " js-admin-ajax-form" : "" ?>"
  <?php if ($templateAjax): ?>
    data-ajax-scope="expert-template"
  <?php endif; ?>
  onsubmit="return confirm(<?= json_encode($stdConfirm, JSON_UNESCAPED_UNICODE) ?>);"
>
  <?php foreach ($stdHidden as $fld): ?>
    <input type="hidden" name="<?= h($fld["name"]) ?>" value="<?= h($fld["value"]) ?>">
  <?php endforeach; ?>
  <button type="submit" class="btn btn-outline-secondary btn-sm">Estándar 9–18 (lun–vie)</button>
</form>