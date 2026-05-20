<?php
declare(strict_types=1);
/** @var bool $publicExpertAgenda */
/** @var list<array<string, mixed>> $agendaBookableServices */
/** @var list<array<string, mixed>> $agendaSlots */
/** @var array{experts: array<int, string>, expert_order: list<int>, rows: list<array<string, mixed>>, show_expert_names?: bool} $agendaSlotTable */
/** @var int $agendaSelectedServiceId */
/** @var string $agendaSelectedDate */
/** @var string $agendaMinDate */
/** @var string $agendaMaxDate */
/** @var array<int, string> $agendaWeekdayLabels */
/** @var array{type: string, msg: string}|null $agendaFlash */
/** @var array{id: int, email: string, display_name: string}|null $clientUser */
/** @var bool $agendaShowExpertNames */
/** @var string $agendaSelectedServiceTitle */
if (!$publicExpertAgenda) {
    return;
}
$agendaWdNum = agenda_weekday_from_ymd($agendaSelectedDate);
$agendaWdLabel = ($agendaWdNum >= 0 && isset($agendaWeekdayLabels[$agendaWdNum]))
    ? (string)$agendaWeekdayLabels[$agendaWdNum]
    : "";
$agendaWdLabelLong = $agendaWdNum >= 0 ? agenda_weekday_label_long($agendaWdNum) : "";
$agGuestName = $clientUser !== null ? trim((string)($clientUser["display_name"] ?? "")) : "";
$agGuestEmail = $clientUser !== null ? trim((string)($clientUser["email"] ?? "")) : "";
$agCompact = !empty($agendaSectionCompact);
$agShowNames = !empty($agendaShowExpertNames);
$agServiceTitle = trim((string)($agendaSelectedServiceTitle ?? ""));
$agendaDateHuman = $agendaSelectedDate;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $agendaSelectedDate)) {
    $agDt = DateTimeImmutable::createFromFormat("Y-m-d", $agendaSelectedDate);
    if ($agDt !== false) {
        $agendaDateHuman = $agDt->format("d/m/Y");
    }
}
?>
<section id="agenda-cita" class="section section-alt reveal section-agenda<?= $agCompact ? " section-agenda--compact" : "" ?>">
  <div class="container">
    <h2><i class="fa-solid fa-calendar-check"></i> Solicitar cita</h2>
    <?php if ($agCompact): ?>
      <p class="section-lead mb-2">Reserva en la <a href="agenda.php">vista de agenda</a> (tabla de horarios por servicio).</p>
    <?php else: ?>
      <p class="section-lead">Elige el <strong>servicio</strong> y el <strong>día</strong>. Pulsa uno o varios huecos <strong>seguidos</strong> para reservar un bloque (p. ej. 12:00 y 12:30 → 12:00 a 13:00)<?= $agShowNames ? " con el profesional indicado" : " (asignación anónima)" ?>.</p>
    <?php endif; ?>
    <?php if ($agendaFlash !== null): ?>
      <?php
        $agFt = (string)$agendaFlash["type"];
        $agFlashClass = ($agFt === "success") ? "form-ok" : (($agFt === "warning") ? "form-warn" : "form-error");
      ?>
      <p class="<?= htmlspecialchars($agFlashClass) ?> agenda-flash" role="alert"><?= htmlspecialchars((string)$agendaFlash["msg"]) ?></p>
    <?php endif; ?>
    <?php if (count($agendaBookableServices) === 0): ?>
      <p class="client-muted">No hay citas en línea (expertos activos con servicios).</p>
    <?php elseif ($agCompact): ?>
      <p class="mb-0"><a class="btn btn-primary" href="agenda.php"><i class="fa-solid fa-table"></i> Abrir agenda y reservar</a></p>
    <?php else: ?>
      <form class="agenda-filter-form contact-form" method="get" action="agenda.php" id="agendaFilterForm">
        <div class="agenda-filter-row">
          <div>
            <label for="agenda_service">Servicio</label>
            <select id="agenda_service" name="agenda_service" required>
              <?php foreach ($agendaBookableServices as $bs): ?>
                <?php $bsid = (int)$bs["id"]; ?>
                <option value="<?= $bsid ?>"<?= $bsid === $agendaSelectedServiceId ? " selected" : "" ?>><?= htmlspecialchars((string)($bs["title"] ?? "")) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="agenda-filter-date">
            <label for="agenda_date">Día</label>
            <div class="agenda-date-input-wrap">
              <input
                id="agenda_date"
                class="agenda-date-input"
                name="agenda_date"
                type="date"
                required
                value="<?= htmlspecialchars($agendaSelectedDate) ?>"
                min="<?= htmlspecialchars($agendaMinDate) ?>"
                max="<?= htmlspecialchars($agendaMaxDate) ?>"
              >
            </div>
          </div>
        </div>
      </form>

      <?php if (count($agendaSlots) === 0): ?>
        <p class="mt-4 mb-0 client-muted">No hay huecos libres ese día. Prueba otra fecha o servicio.</p>
      <?php else: ?>
        <?php
          $agendaLayout = (string)($agendaSlotTable["layout"] ?? ($agShowNames ? "by_expert" : "by_time"));
          $expertOrder = $agendaSlotTable["expert_order"];
          $expertNames = $agendaSlotTable["experts"];
          $gridRows = $agendaSlotTable["rows"];
          $colCount = count($expertOrder);
          $agendaByTime = $agendaLayout === "by_time";
        ?>
        <form class="agenda-book-form contact-form mt-3" method="post" action="agenda_book.php" id="agendaBookForm">
          <input type="hidden" name="return_page" value="<?= !empty($agendaSectionCompact) ? "index.php" : "agenda.php" ?>">
          <input type="hidden" name="agenda_service_id" value="<?= (int)$agendaSelectedServiceId ?>">
          <input type="hidden" name="agenda_date_return" value="<?= htmlspecialchars($agendaSelectedDate) ?>">
          <input type="hidden" name="agenda_slot" id="agendaSlotToken" value="">
          <input type="hidden" name="agenda_slot_units" id="agendaSlotUnits" value="1">

          <div class="agenda-book-layout">
          <div class="agenda-book-layout-main">
          <div class="agenda-slot-table-wrap<?= $agendaByTime ? " agenda-slot-table-wrap--by-time" : "" ?>">
            <table class="agenda-slot-table<?= $agendaByTime ? " agenda-slot-table--by-time" : "" ?>" role="grid" aria-describedby="agenda-slot-hint">
              <caption id="agenda-slot-caption" class="agenda-slot-caption">
                Disponibilidad<?= $agShowNames ? "" : " (reserva anónima por servicio)" ?>
              </caption>
              <thead>
                <tr>
                  <th scope="col" class="agenda-slot-table-time-col">Hora</th>
                  <?php if ($agendaByTime): ?>
                    <th scope="col" class="agenda-slot-table-avail-col">
                      <span class="agenda-col-head-text">Disponibilidad</span>
                      <i class="fa-solid fa-clock agenda-col-head-icon admin-icon-clock" aria-hidden="true" title="Disponibilidad"></i>
                    </th>
                  <?php elseif ($colCount === 0): ?>
                    <th scope="col">—</th>
                  <?php else: ?>
                    <?php foreach ($expertOrder as $colEid): ?>
                      <?php
                        $expertColName = (string)($expertNames[$colEid] ?? "");
                        $expertColIni = agenda_expert_initials($expertColName);
                      ?>
                      <th
                        scope="col"
                        class="agenda-slot-table-slot-col agenda-slot-table-slot-col--named"
                        title="<?= htmlspecialchars($expertColName) ?>"
                      >
                        <span class="agenda-expert-col-name-full"><?= htmlspecialchars($expertColName) ?></span>
                        <abbr class="agenda-expert-col-initials" title="<?= htmlspecialchars($expertColName) ?>"><?= htmlspecialchars($expertColIni) ?></abbr>
                      </th>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                  $agendaGridRowTotal = count($gridRows);
                  $agendaPrevRowTime = null;
                  $agendaRowIdx = 0;
                ?>
                <?php foreach ($gridRows as $gRow): ?>
                  <?php
                    $rowStarts = (string)$gRow["starts"];
                    $rowTime = substr($rowStarts, 11, 5);
                    $rowSepClasses = agenda_slot_row_separator_classes(
                        $rowTime,
                        $agendaPrevRowTime,
                        $agendaRowIdx,
                        $agendaGridRowTotal
                    );
                    $rowTrClass = $rowSepClasses !== [] ? implode(" ", $rowSepClasses) : "";
                  ?>
                  <tr<?= $rowTrClass !== "" ? ' class="' . htmlspecialchars($rowTrClass) . '"' : "" ?>>
                    <th scope="row" class="agenda-slot-table-time-col"><?= htmlspecialchars($rowTime) ?></th>
                    <?php if ($agendaByTime): ?>
                      <?php $timeSlots = is_array($gRow["slots"] ?? null) ? $gRow["slots"] : []; ?>
                      <td class="agenda-slot-table-avail-col">
                        <?php if (count($timeSlots) === 0): ?>
                          <span class="agenda-slot-cell-unavailable" aria-hidden="true">—</span>
                        <?php else: ?>
                          <div class="agenda-slot-cell-stack">
                            <?php foreach ($timeSlots as $cell): ?>
                              <?php if (!is_array($cell)) { continue; } ?>
                              <?php
                                $cellEid = (int)$cell["expert_id"];
                                $cellStarts = (string)$cell["starts"];
                                $cellEnds = (string)$cell["ends"];
                                $slotVal = $cellEid . "@" . $cellStarts;
                                $cellExpertName = trim((string)($cell["display_name"] ?? ""));
                                if ($cellExpertName === "") {
                                    $cellExpertName = "Profesional";
                                }
                                $cellPickTitle = "Disponible " . $rowTime . " · " . $cellExpertName;
                              ?>
                              <label
                                class="agenda-slot-cell-btn agenda-slot-cell-btn--compact"
                                title="<?= htmlspecialchars($cellPickTitle) ?>"
                              >
                                <input
                                  type="checkbox"
                                  class="agenda-slot-pick agenda-slot-cell-input theme-sr-only"
                                  value="<?= htmlspecialchars($slotVal, ENT_QUOTES, "UTF-8") ?>"
                                  data-expert-id="<?= $cellEid ?>"
                                  data-expert-name="<?= htmlspecialchars($cellExpertName, ENT_QUOTES, "UTF-8") ?>"
                                  data-starts="<?= htmlspecialchars($cellStarts, ENT_QUOTES, "UTF-8") ?>"
                                  data-ends="<?= htmlspecialchars($cellEnds, ENT_QUOTES, "UTF-8") ?>"
                                  data-time="<?= htmlspecialchars($rowTime) ?>"
                                  data-label="<?= htmlspecialchars((string)$cell["label"]) ?>"
                                  aria-label="<?= htmlspecialchars($cellPickTitle) ?>"
                                >
                                <span class="agenda-slot-cell-btn-face" aria-hidden="true">
                                  <i class="fa-solid fa-calendar-plus agenda-slot-cell-icon-pick" aria-hidden="true"></i>
                                  <i class="fa-solid fa-check agenda-slot-cell-icon"></i>
                                  <span class="agenda-slot-cell-text">Disponible</span>
                                </span>
                              </label>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </td>
                    <?php else: ?>
                      <?php foreach ($expertOrder as $colEid): ?>
                        <?php $cell = $gRow["cells"][$colEid] ?? null; ?>
                        <td class="agenda-slot-table-slot-col">
                          <?php if ($cell !== null && is_array($cell)): ?>
                            <?php
                              $cellEid = (int)$cell["expert_id"];
                              $cellStarts = (string)$cell["starts"];
                              $cellEnds = (string)$cell["ends"];
                              $slotVal = $cellEid . "@" . $cellStarts;
                              $cellExpertName = trim((string)($expertNames[$cellEid] ?? $cell["display_name"] ?? ""));
                              if ($cellExpertName === "") {
                                  $cellExpertName = "Profesional";
                              }
                              $cellPickTitle = "Reservar " . $rowTime . " · " . $cellExpertName;
                            ?>
                            <label
                              class="agenda-slot-cell-btn agenda-slot-cell-btn--compact"
                              title="<?= htmlspecialchars($cellPickTitle) ?>"
                            >
                              <input
                                type="checkbox"
                                class="agenda-slot-pick agenda-slot-cell-input theme-sr-only"
                                value="<?= htmlspecialchars($slotVal, ENT_QUOTES, "UTF-8") ?>"
                                data-expert-id="<?= $cellEid ?>"
                                data-expert-name="<?= htmlspecialchars($cellExpertName, ENT_QUOTES, "UTF-8") ?>"
                                data-starts="<?= htmlspecialchars($cellStarts, ENT_QUOTES, "UTF-8") ?>"
                                data-ends="<?= htmlspecialchars($cellEnds, ENT_QUOTES, "UTF-8") ?>"
                                data-time="<?= htmlspecialchars($rowTime) ?>"
                                data-label="<?= htmlspecialchars((string)$cell["label"]) ?>"
                                aria-label="<?= htmlspecialchars($cellPickTitle) ?>"
                              >
                              <span class="agenda-slot-cell-btn-face" aria-hidden="true">
                                <i class="fa-solid fa-calendar-plus agenda-slot-cell-icon-pick" aria-hidden="true"></i>
                                <i class="fa-solid fa-check agenda-slot-cell-icon"></i>
                                <span class="agenda-slot-cell-text">Reservar</span>
                              </span>
                            </label>
                          <?php else: ?>
                            <span class="agenda-slot-cell-unavailable" aria-hidden="true">—</span>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tr>
                  <?php
                    $agendaPrevRowTime = $rowTime;
                    $agendaRowIdx++;
                  ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <p id="agenda-slot-hint" class="client-muted small mb-0">Marca uno o varios huecos <strong>seguidos</strong> (misma columna en modo experto) para reservar el bloque completo.</p>
          </div>

          <div class="agenda-book-layout-side">
          <div id="agendaBookingSummary" class="agenda-booking-summary" role="status" aria-live="polite">
            <p class="agenda-booking-summary-title"><i class="fa-solid fa-clipboard-list"></i> Detalle de la cita</p>
            <dl class="agenda-booking-summary-grid">
              <div class="agenda-booking-summary-item">
                <dt>Servicio</dt>
                <dd id="agendaSummaryService"><?= $agServiceTitle !== "" ? htmlspecialchars($agServiceTitle) : "—" ?></dd>
              </div>
              <div class="agenda-booking-summary-item">
                <dt>Día</dt>
                <dd id="agendaSummaryWeekday"><?= $agendaWdLabelLong !== "" ? htmlspecialchars($agendaWdLabelLong) : "—" ?></dd>
              </div>
              <div class="agenda-booking-summary-item">
                <dt>Fecha</dt>
                <dd id="agendaSummaryDate"><?= htmlspecialchars($agendaDateHuman) ?></dd>
              </div>
              <div class="agenda-booking-summary-item">
                <dt>Experto</dt>
                <dd id="agendaSummaryExpert">—</dd>
              </div>
              <div class="agenda-booking-summary-item agenda-booking-summary-item--slot">
                <dt>Franja horaria</dt>
                <dd id="agendaSummarySlot" class="agenda-booking-summary-slot">Selecciona huecos en la tabla</dd>
              </div>
            </dl>
          </div>

          <div class="agenda-book-fields">
            <label for="agenda_guest_name">Tu nombre</label>
            <input id="agenda_guest_name" name="guest_name" type="text" required maxlength="180" autocomplete="name" value="<?= htmlspecialchars($agGuestName) ?>">

            <label for="agenda_guest_email">Correo <span class="text-muted fw-normal">(correo o teléfono obligatorio)</span></label>
            <input id="agenda_guest_email" name="guest_email" type="email" maxlength="180" autocomplete="email" value="<?= htmlspecialchars($agGuestEmail) ?>">

            <label for="agenda_guest_phone">Teléfono <span class="text-muted fw-normal">(si no indicas correo, mín. 6 caracteres)</span></label>
            <input id="agenda_guest_phone" name="guest_phone" type="text" maxlength="48" autocomplete="tel" value="">

            <label for="agenda_notes">Notas <span class="contact-required-hint">(opcional)</span></label>
            <textarea id="agenda_notes" name="agenda_notes" rows="2" maxlength="2000" placeholder="Motivo de la consulta…"></textarea>
          </div>

          <div class="agenda-book-actions">
            <button type="submit" class="btn btn-primary" id="agendaSubmitBtn" disabled><i class="fa-solid fa-check"></i> Confirmar reserva</button>
          </div>
          </div>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php if (!$agCompact): ?>
<script>
(function () {
  var filterForm = document.getElementById("agendaFilterForm");
  if (!filterForm) return;
  var serviceSel = document.getElementById("agenda_service");
  var dateInp = document.getElementById("agenda_date");
  function submitFilter() {
    if (typeof filterForm.requestSubmit === "function") {
      filterForm.requestSubmit();
    } else {
      filterForm.submit();
    }
  }
  if (serviceSel) {
    serviceSel.addEventListener("change", submitFilter);
  }
  if (dateInp) {
    dateInp.addEventListener("change", submitFilter);
    dateInp.addEventListener("click", function () {
      if (typeof dateInp.showPicker === "function") {
        try {
          dateInp.showPicker();
        } catch (e) {
          dateInp.focus();
        }
      }
    });
  }
})();
</script>
<?php endif; ?>
<?php if (!$agCompact && count($agendaSlots) > 0): ?>
<script>
(function () {
  var form = document.getElementById("agendaBookForm");
  if (!form) return;
  var summarySlot = document.getElementById("agendaSummarySlot");
  var summaryExpert = document.getElementById("agendaSummaryExpert");
  var summaryPanel = document.getElementById("agendaBookingSummary");
  var submitBtn = document.getElementById("agendaSubmitBtn");
  var tokenInp = document.getElementById("agendaSlotToken");
  var unitsInp = document.getElementById("agendaSlotUnits");
  var slotMinutes = <?= (int)AGENDA_SLOT_MINUTES ?>;
  var maxUnits = <?= (int)AGENDA_MAX_SLOT_UNITS ?>;

  function allPicks() {
    return Array.prototype.slice.call(form.querySelectorAll(".agenda-slot-pick"));
  }
  function picksForExpert(expertId) {
    return allPicks().filter(function (el) {
      return el.getAttribute("data-expert-id") === String(expertId);
    });
  }
  function addMinutesIso(iso, mins) {
    var d = new Date(iso.replace(" ", "T"));
    if (isNaN(d.getTime())) return iso;
    d.setMinutes(d.getMinutes() + mins);
    var pad = function (n) { return n < 10 ? "0" + n : String(n); };
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + " " +
      pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":00";
  }
  function fillContiguous(expertId, minStarts, maxStarts) {
    var group = picksForExpert(expertId);
    var cur = minStarts;
    var ok = true;
    while (cur <= maxStarts) {
      var el = group.find(function (e) { return e.getAttribute("data-starts") === cur; });
      if (!el) { ok = false; break; }
      cur = addMinutesIso(cur, slotMinutes);
    }
    group.forEach(function (el) {
      var t = el.getAttribute("data-starts");
      el.checked = ok && t >= minStarts && t <= maxStarts;
    });
    return ok;
  }
  function reconcileFrom(trigger) {
    if (trigger && trigger.checked) {
      allPicks().forEach(function (el) {
        if (el !== trigger && el.getAttribute("data-expert-id") !== trigger.getAttribute("data-expert-id")) {
          el.checked = false;
        }
      });
      var expertId = trigger.getAttribute("data-expert-id");
      var times = picksForExpert(expertId)
        .filter(function (el) { return el.checked; })
        .map(function (el) { return el.getAttribute("data-starts"); })
        .sort();
      var minT = times[0];
      var maxT = times[times.length - 1];
      if (!fillContiguous(expertId, minT, maxT)) {
        allPicks().forEach(function (el) { el.checked = el === trigger; });
      }
    }
    updateHidden();
  }
  function updateHidden() {
    var checked = allPicks().filter(function (el) { return el.checked; });
    if (checked.length === 0) {
      if (tokenInp) tokenInp.value = "";
      if (unitsInp) unitsInp.value = "1";
      if (summarySlot) summarySlot.textContent = "Selecciona huecos en la tabla";
      if (summaryExpert) summaryExpert.textContent = "—";
      if (summaryPanel) summaryPanel.classList.remove("is-ready");
      if (submitBtn) submitBtn.disabled = true;
      return;
    }
    var expertId = checked[0].getAttribute("data-expert-id");
    var same = checked.every(function (el) { return el.getAttribute("data-expert-id") === expertId; });
    if (!same) {
      if (tokenInp) tokenInp.value = "";
      if (summarySlot) summarySlot.textContent = "Selecciona huecos en la tabla";
      if (summaryExpert) summaryExpert.textContent = "—";
      if (summaryPanel) summaryPanel.classList.remove("is-ready");
      if (submitBtn) submitBtn.disabled = true;
      return;
    }
    var ordered = checked.slice().sort(function (a, b) {
      return a.getAttribute("data-starts").localeCompare(b.getAttribute("data-starts"));
    });
    var first = ordered[0];
    var last = ordered[ordered.length - 1];
    var units = ordered.length;
    if (units > maxUnits) {
      ordered.slice(maxUnits).forEach(function (el) { el.checked = false; });
      ordered = ordered.slice(0, maxUnits);
      last = ordered[ordered.length - 1];
      units = maxUnits;
    }
    if (tokenInp) tokenInp.value = first.value;
    if (unitsInp) unitsInp.value = String(units);
    if (submitBtn) submitBtn.disabled = false;
    if (summaryExpert) {
      summaryExpert.textContent = first.getAttribute("data-expert-name") || "—";
    }
    if (summarySlot) {
      var startLbl = first.getAttribute("data-time") || "";
      var endLbl = last.getAttribute("data-ends") || "";
      if (endLbl.length >= 16) endLbl = endLbl.substr(11, 5);
      var blockLbl = units > 1 ? (startLbl + " – " + endLbl) : (first.getAttribute("data-label") || startLbl);
      summarySlot.textContent = blockLbl;
    }
    if (summaryPanel) summaryPanel.classList.add("is-ready");
  }
  allPicks().forEach(function (inp) {
    inp.addEventListener("change", function () { reconcileFrom(inp); });
  });
  form.addEventListener("submit", function (e) {
    if (!tokenInp || tokenInp.value === "") {
      e.preventDefault();
      if (summarySlot) summarySlot.textContent = "Selecciona al menos un hueco en la tabla";
    }
  });
})();
</script>
<?php endif; ?>
