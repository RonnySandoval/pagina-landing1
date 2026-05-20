/**
 * Tablas admin filtrables por columna y ordenables (expertos, citas).
 */
(function () {
  "use strict";

  function norm(s) {
    return (s || "")
      .toString()
      .toLowerCase()
      .trim()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
  }

  function parseNum(v) {
    var n = parseFloat(String(v || ""));
    return isNaN(n) ? 0 : n;
  }

  function queryTokens(q) {
    return norm(q)
      .split(/\s+/)
      .filter(function (t) {
        return t.length > 0;
      });
  }

  function getMainRows(root) {
    return Array.from(root.querySelectorAll("tbody tr[data-filter-row]"));
  }

  function syncDetailRows(root) {
    root.querySelectorAll("tbody tr[data-filter-detail-for]").forEach(function (det) {
      var pid = det.getAttribute("data-filter-detail-for");
      if (!pid) {
        det.hidden = true;
        return;
      }
      var parent = root.querySelector(
        'tbody tr[data-filter-row][data-filter-id="' + pid.replace(/"/g, '\\"') + '"]'
      );
      det.hidden = !parent || parent.hidden;
    });
  }

  function updateCount(root, visible, total) {
    var el = root.querySelector("[data-filter-count]");
    if (!el) return;
    if (total === 0) {
      el.textContent = "";
      return;
    }
    if (visible === total) {
      el.textContent = total === 1 ? "1 fila" : total + " filas";
    } else {
      el.textContent = visible + " de " + total;
    }
  }

  function toggleEmptyState(root, visible, total) {
    var empty = root.querySelector("[data-filter-empty]");
    if (!empty) return;
    empty.hidden = total === 0 || visible > 0;
  }

  function colTextMatches(tr, colKey, rawQuery) {
    var tokens = queryTokens(rawQuery);
    if (tokens.length === 0) return true;
    var val = norm(tr.getAttribute("data-filter-" + colKey) || "");
    return tokens.every(function (tok) {
      return val.indexOf(tok) !== -1;
    });
  }

  function colSelectMatches(tr, colKey, value) {
    if (!value || value === "all") return true;
    return (tr.getAttribute("data-filter-" + colKey) || "") === value;
  }

  function isFilterControlVisible(el) {
    if (!el || el.closest("[hidden]")) {
      return false;
    }
    return el.getClientRects().length > 0;
  }

  function getColFilters(root) {
    var filters = [];
    root.querySelectorAll("[data-filter-col]").forEach(function (el) {
      if (!isFilterControlVisible(el)) {
        return;
      }
      var key = el.getAttribute("data-filter-col");
      if (!key) return;
      var type = el.getAttribute("data-filter-type") || "text";
      var value =
        type === "select" ? (el.value || "all") : (el.value || "").toString();
      if (type !== "select" && norm(value) === "") return;
      if (type === "select" && value === "all") return;
      filters.push({ key: key, type: type, value: value });
    });
    return filters;
  }

  function applyFilters(root) {
    var filters = getColFilters(root);
    var rows = getMainRows(root);
    var visible = 0;

    rows.forEach(function (tr) {
      var show = filters.every(function (f) {
        if (f.type === "select") {
          return colSelectMatches(tr, f.key, f.value);
        }
        return colTextMatches(tr, f.key, f.value);
      });
      tr.hidden = !show;
      if (show) visible += 1;
    });

    syncDetailRows(root);
    updateCount(root, visible, rows.length);
    toggleEmptyState(root, visible, rows.length);
    refreshRowOverflow(root);
  }

  function clearColFilters(root) {
    root.querySelectorAll("[data-filter-col]").forEach(function (el) {
      if (el.tagName === "SELECT") {
        el.value = "all";
      } else {
        el.value = "";
      }
    });
    applyFilters(root);
  }

  function getSortValue(tr, key) {
    var attr = "data-sort-" + key;
    if (tr.hasAttribute(attr)) {
      return tr.getAttribute(attr) || "";
    }
    return "";
  }

  function compareRows(a, b, key, dir) {
    var va = getSortValue(a, key);
    var vb = getSortValue(b, key);
    var na = parseNum(va);
    var nb = parseNum(vb);
    var cmp;
    if (va !== "" && vb !== "" && String(va) === String(na) && String(vb) === String(nb)) {
      cmp = na - nb;
    } else {
      cmp = va.localeCompare(vb, undefined, { numeric: true, sensitivity: "base" });
    }
    return dir === "desc" ? -cmp : cmp;
  }

  function moveRowGroup(tbody, tr) {
    tbody.appendChild(tr);
    var id = tr.getAttribute("data-filter-id");
    if (!id) return;
    tbody.querySelectorAll('tr[data-filter-detail-for="' + id + '"]').forEach(function (det) {
      tbody.appendChild(det);
    });
  }

  function applySort(root, key, dir) {
    var tbody = root.querySelector("tbody");
    if (!tbody || !key) return;
    var rows = getMainRows(root);
    rows.sort(function (a, b) {
      return compareRows(a, b, key, dir);
    });
    rows.forEach(function (tr) {
      moveRowGroup(tbody, tr);
    });
    syncDetailRows(root);
  }

  function initSort(root) {
    var sortState = { key: "", dir: "asc" };
    root.querySelectorAll(".admin-filter-table__head-row th[data-sort-key]").forEach(function (th) {
      th.classList.add("admin-filter-table__th-sortable");
      th.setAttribute("role", "button");
      th.setAttribute("tabindex", "0");
      th.setAttribute("title", "Ordenar columna");

      function toggleSort() {
        var key = th.getAttribute("data-sort-key");
        if (!key) return;
        if (sortState.key === key) {
          sortState.dir = sortState.dir === "asc" ? "desc" : "asc";
        } else {
          sortState.key = key;
          sortState.dir = "asc";
        }
        root.querySelectorAll(".admin-filter-table__head-row th[data-sort-key]").forEach(function (h) {
          h.classList.remove("is-sorted-asc", "is-sorted-desc");
          h.removeAttribute("aria-sort");
        });
        th.classList.add(sortState.dir === "asc" ? "is-sorted-asc" : "is-sorted-desc");
        th.setAttribute("aria-sort", sortState.dir === "asc" ? "ascending" : "descending");
        applySort(root, sortState.key, sortState.dir);
      }

      th.addEventListener("click", toggleSort);
      th.addEventListener("keydown", function (ev) {
        if (ev.key === "Enter" || ev.key === " ") {
          ev.preventDefault();
          toggleSort();
        }
      });
    });
  }

  function initColFilters(root) {
    root.querySelectorAll("[data-filter-col]").forEach(function (el) {
      el.addEventListener("click", function (ev) {
        ev.stopPropagation();
      });
      el.addEventListener("input", function () {
        applyFilters(root);
      });
      el.addEventListener("change", function () {
        applyFilters(root);
      });
    });
    var clearBtn = root.querySelector("[data-filter-clear]");
    if (clearBtn) {
      clearBtn.addEventListener("click", function () {
        clearColFilters(root);
      });
    }
  }

  function rowExpandInteractiveTarget(ev) {
    return ev.target.closest(
      "button, a, input, select, textarea, label, form, .admin-filter-table__col-resize"
    );
  }

  function markRowOverflow(tr) {
    if (!tr || tr.hidden) {
      return;
    }
    var overflow = false;
    tr.querySelectorAll(".admin-filter-table__text-2l").forEach(function (el) {
      if (el.scrollHeight > el.clientHeight + 2) {
        overflow = true;
      }
    });
    tr.classList.toggle("has-row-overflow", overflow);
    if (overflow) {
      tr.setAttribute("title", "Pulsar fila para ver texto completo");
      tr.setAttribute("tabindex", "0");
    } else {
      tr.removeAttribute("title");
      tr.removeAttribute("tabindex");
      tr.classList.remove("is-row-expanded");
    }
  }

  function refreshRowOverflow(root) {
    getMainRows(root).forEach(markRowOverflow);
  }

  function initRowExpand(root) {
    var mqDesktop = window.matchMedia("(min-width: 992px)");

    function rowExpandAllowsDesktop(rootForMode) {
      if (isExpertApptTable(rootForMode)) {
        return apptTableIsWideLayout(rootForMode);
      }
      return mqDesktop.matches;
    }

    function onRowActivate(tr, ev) {
      if (!rowExpandAllowsDesktop(root)) {
        return;
      }
      if (rowExpandInteractiveTarget(ev)) {
        return;
      }
      if (!tr.classList.contains("has-row-overflow")) {
        return;
      }
      tr.classList.toggle("is-row-expanded");
    }

    getMainRows(root).forEach(function (tr) {
      if (tr.getAttribute("data-row-expand-bound") === "1") {
        return;
      }
      tr.setAttribute("data-row-expand-bound", "1");
      tr.addEventListener("click", function (ev) {
        onRowActivate(tr, ev);
      });
      tr.addEventListener("keydown", function (ev) {
        if (ev.key === "Enter" || ev.key === " ") {
          ev.preventDefault();
          onRowActivate(tr, ev);
        }
      });
    });

    function onMqChange() {
      if (!rowExpandAllowsDesktop(root)) {
        getMainRows(root).forEach(function (tr) {
          tr.classList.remove("is-row-expanded");
        });
      }
      refreshRowOverflow(root);
    }

    if (typeof mqDesktop.addEventListener === "function") {
      mqDesktop.addEventListener("change", onMqChange);
    } else if (typeof mqDesktop.addListener === "function") {
      mqDesktop.addListener(onMqChange);
    }

    refreshRowOverflow(root);
  }

  var APPT_COMPACT_ACTIONS_PX = 112;
  var APPT_WIDE_MIN_PX_DEFAULT = 1100;

  function isExpertApptTable(root) {
    return root.classList.contains("expert-appointments-filter-table");
  }

  function getApptWideMinPx(root) {
    var parsed = parseFloat(
      getComputedStyle(root).getPropertyValue("--appt-wide-min")
    );
    return Number.isFinite(parsed) && parsed > 0 ? parsed : APPT_WIDE_MIN_PX_DEFAULT;
  }

  function apptTableContainerWidth(root) {
    return root.getBoundingClientRect().width;
  }

  function apptTableIsWideLayout(root) {
    if (!isExpertApptTable(root)) {
      return false;
    }
    var w = apptTableContainerWidth(root);
    return w >= getApptWideMinPx(root);
  }

  function apptTableShouldBeCompact(root) {
    if (!isExpertApptTable(root)) {
      return false;
    }
    var w = apptTableContainerWidth(root);
    return w > 0 && w < getApptWideMinPx(root);
  }

  function apptTableStorageKey(root) {
    var table = root.querySelector(".expert-appointments-table");
    return (
      "adminFilterColWidths:" +
      (root.id ||
        (table && table.className.replace(/\s+/g, "_").slice(0, 80)) ||
        "admin-filter-table")
    );
  }

  function loadApptSavedColWidths(root) {
    try {
      var raw = sessionStorage.getItem(apptTableStorageKey(root));
      if (!raw) {
        return null;
      }
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : null;
    } catch (_e) {
      return null;
    }
  }

  function ensureApptColgroup(table) {
    var colgroup = table.querySelector("colgroup");
    if (!colgroup) {
      colgroup = document.createElement("colgroup");
      table.insertBefore(colgroup, table.firstChild);
    }
    colgroup.removeAttribute("hidden");
    return colgroup;
  }

  function buildCompactApptColgroup(root) {
    var table = root.querySelector(".expert-appointments-table");
    var headRow = root.querySelector(".admin-filter-table__head-row");
    if (!table || !headRow) {
      return;
    }
    var colgroup = ensureApptColgroup(table);
    colgroup.setAttribute("data-appt-compact-cols", "1");
    colgroup.innerHTML = "";

    var actionsW = APPT_COMPACT_ACTIONS_PX;

    Array.from(headRow.querySelectorAll("th")).forEach(function (th) {
      var col = document.createElement("col");
      if (th.classList.contains("appt-col-actions")) {
        col.className = "appt-compact-col-actions";
        col.style.width = actionsW + "px";
      } else if (th.classList.contains("appt-col-datetime")) {
        col.className = "appt-compact-col-main";
        /* Sin width: en table-layout:fixed absorbe el espacio sobrante */
      } else {
        col.className = "appt-compact-col-hidden";
        col.style.width = "0";
      }
      colgroup.appendChild(col);
    });
    refineCompactApptColgroup(root);
  }

  function refineCompactApptColgroup(root) {
    var table = root.querySelector(".expert-appointments-table");
    var colgroup = table && table.querySelector('colgroup[data-appt-compact-cols="1"]');
    if (!colgroup) {
      return;
    }
    var tableW = table.getBoundingClientRect().width;
    if (tableW < 1) {
      return;
    }
    var mainCol = colgroup.querySelector("col.appt-compact-col-main");
    if (mainCol) {
      mainCol.style.width = Math.max(96, tableW - APPT_COMPACT_ACTIONS_PX) + "px";
    }
  }

  function restoreApptColgroup(root) {
    var table = root.querySelector(".expert-appointments-table");
    if (!table) {
      return;
    }
    var colgroup = table.querySelector("colgroup");
    if (!colgroup || colgroup.getAttribute("data-appt-compact-cols") !== "1") {
      return;
    }
    colgroup.removeAttribute("data-appt-compact-cols");
    colgroup.innerHTML = "";

    var headRow = root.querySelector(".admin-filter-table__head-row");
    if (!headRow) {
      return;
    }
    var saved = loadApptSavedColWidths(root);
    Array.from(headRow.querySelectorAll("th")).forEach(function (_th, index) {
      var col = document.createElement("col");
      if (saved && saved[index]) {
        col.style.width = saved[index];
      }
      colgroup.appendChild(col);
    });
  }

  function syncApptColgroup(root, compact) {
    if (compact) {
      buildCompactApptColgroup(root);
    } else {
      restoreApptColgroup(root);
    }
  }

  function updateApptCompactLayout(root) {
    if (!isExpertApptTable(root)) {
      return;
    }
    var compact = apptTableShouldBeCompact(root);
    var wasCompact = root.classList.contains("is-appt-compact");
    root.classList.toggle("is-appt-compact", compact);
    root.classList.toggle("is-appt-wide", !compact);
    syncApptColgroup(root, compact);
    if (compact) {
      requestAnimationFrame(function () {
        if (root.classList.contains("is-appt-compact")) {
          buildCompactApptColgroup(root);
          refineCompactApptColgroup(root);
        }
      });
    } else if (wasCompact && !compact && root.getAttribute("data-col-resize-inited") !== "1") {
      initColResize(root);
    }
    refreshRowOverflow(root);
  }

  function initApptCompactLayout(root) {
    if (!isExpertApptTable(root)) {
      return;
    }
    if (root.getAttribute("data-appt-compact-inited") === "1") {
      updateApptCompactLayout(root);
      return;
    }
    root.setAttribute("data-appt-compact-inited", "1");

    function run() {
      updateApptCompactLayout(root);
    }

    run();
    if (typeof ResizeObserver !== "undefined") {
      var ro = new ResizeObserver(run);
      ro.observe(root);
    }
    window.addEventListener("resize", run);
  }

  function initColResize(root) {
    var mqDesktop = window.matchMedia("(min-width: 992px)");
    if (isExpertApptTable(root)) {
      if (!apptTableIsWideLayout(root)) {
        return;
      }
    } else if (!mqDesktop.matches) {
      return;
    }
    if (root.getAttribute("data-col-resize-inited") === "1") {
      return;
    }
    var table = root.querySelector(".admin-filter-table__table");
    var headRow = root.querySelector(".admin-filter-table__head-row");
    if (!table || !headRow) {
      return;
    }

    var tableKey =
      root.id ||
      table.className.replace(/\s+/g, "_").slice(0, 80) ||
      "admin-filter-table";
    var storageKey = "adminFilterColWidths:" + tableKey;

    var colgroup = table.querySelector("colgroup");
    if (!colgroup) {
      colgroup = document.createElement("colgroup");
      table.insertBefore(colgroup, table.firstChild);
    }

    var ths = Array.from(headRow.querySelectorAll("th"));
    var cols = [];

    function loadWidths() {
      try {
        var raw = sessionStorage.getItem(storageKey);
        if (!raw) {
          return null;
        }
        var parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : null;
      } catch (_e) {
        return null;
      }
    }

    function saveWidths() {
      try {
        var widths = cols.map(function (col) {
          return col.style.width || "";
        });
        sessionStorage.setItem(storageKey, JSON.stringify(widths));
      } catch (_e) {}
    }

    function applyWidth(index, px) {
      if (!cols[index]) {
        return;
      }
      var w = Math.max(48, Math.round(px));
      cols[index].style.width = w + "px";
    }

    colgroup.innerHTML = "";
    cols = [];
    var saved = loadWidths();

    ths.forEach(function (th, index) {
      var col = document.createElement("col");
      if (saved && saved[index]) {
        col.style.width = saved[index];
      }
      colgroup.appendChild(col);
      cols.push(col);

      if (th.classList.contains("expert-col-actions") || th.classList.contains("appt-col-actions")) {
        return;
      }
      if (th.querySelector(".admin-filter-table__col-resize")) {
        return;
      }

      th.classList.add("admin-filter-table__th-resizable");
      var handle = document.createElement("span");
      handle.className = "admin-filter-table__col-resize";
      handle.setAttribute("aria-hidden", "true");
      handle.title = "Arrastrar para cambiar ancho";
      th.appendChild(handle);

      handle.addEventListener("mousedown", function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var startX = ev.clientX;
        var startW = th.getBoundingClientRect().width;
        root.classList.add("is-col-resizing");

        function onMove(moveEv) {
          applyWidth(index, startW + (moveEv.clientX - startX));
        }

        function onUp() {
          document.removeEventListener("mousemove", onMove);
          document.removeEventListener("mouseup", onUp);
          root.classList.remove("is-col-resizing");
          saveWidths();
        }

        document.addEventListener("mousemove", onMove);
        document.addEventListener("mouseup", onUp);
      });

      handle.addEventListener("click", function (ev) {
        ev.stopPropagation();
      });
    });

    table.style.tableLayout = "fixed";
    root.setAttribute("data-col-resize-inited", "1");
  }

  function initTable(root) {
    if (root.getAttribute("data-filter-inited") === "1") {
      updateApptCompactLayout(root);
      applyFilters(root);
      refreshRowOverflow(root);
      return;
    }
    root.setAttribute("data-filter-inited", "1");

    initColFilters(root);
    initSort(root);
    initApptCompactLayout(root);
    initRowExpand(root);
    initColResize(root);
    applyFilters(root);
    refreshRowOverflow(root);
  }

  function initAdminFilterableTables() {
    document.querySelectorAll("[data-admin-filter-table]").forEach(initTable);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAdminFilterableTables);
  } else {
    initAdminFilterableTables();
  }

  document.querySelectorAll(".accordion").forEach(function (acc) {
    acc.addEventListener("shown.bs.collapse", function () {
      requestAnimationFrame(function () {
        document.querySelectorAll("[data-admin-filter-table]").forEach(function (root) {
          updateApptCompactLayout(root);
          applyFilters(root);
          if (typeof window.fitAllExpertServiceRows === "function") {
            window.fitAllExpertServiceRows();
          }
        });
      });
    });
  });

  window.initAdminFilterableTables = initAdminFilterableTables;
  window.updateApptCompactLayout = updateApptCompactLayout;
  window.refreshAdminFilterTableRows = function (root) {
    if (root) {
      refreshRowOverflow(root);
      return;
    }
    document.querySelectorAll("[data-admin-filter-table]").forEach(refreshRowOverflow);
  };
})();
