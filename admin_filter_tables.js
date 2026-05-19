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

  function getColFilters(root) {
    var filters = [];
    root.querySelectorAll("[data-filter-col]").forEach(function (el) {
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

  function initTable(root) {
    if (root.getAttribute("data-filter-inited") === "1") {
      applyFilters(root);
      return;
    }
    root.setAttribute("data-filter-inited", "1");

    initColFilters(root);
    initSort(root);
    applyFilters(root);
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
      document.querySelectorAll("[data-admin-filter-table]").forEach(function (root) {
        applyFilters(root);
        if (typeof window.fitAllExpertServiceRows === "function") {
          window.fitAllExpertServiceRows();
        }
      });
    });
  });

  window.initAdminFilterableTables = initAdminFilterableTables;
})();
