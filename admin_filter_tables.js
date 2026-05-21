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
      "button, a, input, select, textarea, label, form"
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
    function onRowActivate(tr, ev) {
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

    refreshRowOverflow(root);
  }

  /** Limpia restos de sesiones antiguas (tiers, colgroup, resize). */
  function clearGridLegacyState(root) {
    root.classList.remove(
      "is-appt-compact",
      "is-appt-wide",
      "is-expert-compact",
      "is-expert-wide",
      "is-col-resizing"
    );
    root.removeAttribute("data-aft-size");
    root.removeAttribute("data-col-resize-inited");
    root.removeAttribute("data-appt-compact-inited");
    root.removeAttribute("data-expert-compact-inited");

    var table = root.querySelector(".admin-filter-table__table");
    if (!table) {
      return;
    }
    var colgroup = table.querySelector("colgroup");
    if (colgroup) {
      colgroup.remove();
    }
    table.style.tableLayout = "";
    table.querySelectorAll(".admin-filter-table__th-resizable").forEach(function (th) {
      th.classList.remove("admin-filter-table__th-resizable");
      var handle = th.querySelector(".admin-filter-table__col-resize");
      if (handle) {
        handle.remove();
      }
    });
  }

  function refreshTableLayout(root) {
    clearGridLegacyState(root);
    refreshRowOverflow(root);
    if (
      root.classList.contains("admin-experts-filter-table") &&
      typeof window.fitAllExpertServiceRows === "function"
    ) {
      window.fitAllExpertServiceRows();
    }
  }

  function syncScrollHints(scrollEl) {
    if (!scrollEl) return;
    var max = scrollEl.scrollWidth - scrollEl.clientWidth;
    if (max <= 1) {
      scrollEl.classList.remove(
        "is-scrollable-x",
        "is-scrollable-x-start",
        "is-scrollable-x-end"
      );
      return;
    }
    var left = scrollEl.scrollLeft;
    scrollEl.classList.add("is-scrollable-x");
    scrollEl.classList.toggle("is-scrollable-x-start", left > 2);
    scrollEl.classList.toggle("is-scrollable-x-end", left < max - 2);
  }

  function initScrollHints(root) {
    var scroll = root.querySelector(".admin-filter-table__scroll");
    if (!scroll) return;

    function update() {
      syncScrollHints(scroll);
    }

    if (scroll.getAttribute("data-scroll-hints-inited") !== "1") {
      scroll.setAttribute("data-scroll-hints-inited", "1");
      scroll.addEventListener("scroll", update, { passive: true });
      if (typeof ResizeObserver !== "undefined") {
        var ro = new ResizeObserver(update);
        ro.observe(scroll);
        var inner = scroll.firstElementChild;
        if (inner) ro.observe(inner);
      }
      window.addEventListener("resize", update);
    }
    update();
  }

  function initTable(root) {
    if (root.getAttribute("data-filter-inited") === "1") {
      applyFilters(root);
      refreshTableLayout(root);
      initScrollHints(root);
      return;
    }
    root.setAttribute("data-filter-inited", "1");

    clearGridLegacyState(root);
    initColFilters(root);
    initSort(root);
    initRowExpand(root);
    initScrollHints(root);
    applyFilters(root);
    refreshTableLayout(root);
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
          applyFilters(root);
          refreshTableLayout(root);
          initScrollHints(root);
        });
      });
    });
  });

  window.initAdminFilterableTables = initAdminFilterableTables;
  window.refreshAdminFilterTableLayout = refreshTableLayout;
  window.refreshAdminFilterTableRows = function (root) {
    if (root) {
      refreshRowOverflow(root);
      return;
    }
    document.querySelectorAll("[data-admin-filter-table]").forEach(refreshRowOverflow);
  };
})();
