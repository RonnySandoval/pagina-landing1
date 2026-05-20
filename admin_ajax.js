/**
 * Formularios admin sin recarga: fetch + JSON (ajax=1).
 * Feedback: toast flotante + pulso en el panel afectado.
 */
(function () {
  "use strict";

  var TOAST_MS = 5200;

  function escapeHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function getToastStack() {
    var stack = document.getElementById("adminToastStack");
    if (!stack) {
      stack = document.createElement("div");
      stack.id = "adminToastStack";
      stack.className = "admin-toast-stack";
      stack.setAttribute("aria-live", "polite");
      stack.setAttribute("aria-relevant", "additions");
      document.body.appendChild(stack);
    }
    return stack;
  }

  /**
   * @param {"success"|"error"|"warning"} type
   * @param {string} message
   * @param {{ title?: string }} [opts]
   */
  function showAdminToast(type, message, opts) {
    opts = opts || {};
    var title =
      opts.title ||
      (type === "success"
        ? "Listo"
        : type === "error"
          ? "Error"
          : "Aviso");
    var icons = {
      success: "fa-circle-check",
      error: "fa-circle-xmark",
      warning: "fa-triangle-exclamation",
    };
    var stack = getToastStack();
    var card = document.createElement("div");
    card.className = "admin-toast-card admin-toast-card--" + type;
    card.setAttribute("role", "alert");
    card.innerHTML =
      '<div class="admin-toast-card__icon" aria-hidden="true"><i class="fa-solid ' +
      (icons[type] || icons.success) +
      '"></i></div>' +
      '<div class="admin-toast-card__body">' +
      '<p class="admin-toast-card__title">' +
      escapeHtml(title) +
      "</p>" +
      '<p class="admin-toast-card__msg">' +
      escapeHtml(message) +
      "</p>" +
      "</div>" +
      '<button type="button" class="admin-toast-card__close" aria-label="Cerrar">' +
      '<i class="fa-solid fa-xmark"></i></button>' +
      '<div class="admin-toast-card__progress"></div>';

    var progress = card.querySelector(".admin-toast-card__progress");
    if (progress) {
      progress.style.animation = "adminToastProgress " + TOAST_MS + "ms linear forwards";
    }

    function dismiss() {
      if (card.classList.contains("is-leaving")) {
        return;
      }
      card.classList.add("is-leaving");
      window.setTimeout(function () {
        card.remove();
      }, 280);
    }

    card.querySelector(".admin-toast-card__close").addEventListener("click", dismiss);
    stack.appendChild(card);
    window.setTimeout(dismiss, TOAST_MS);
  }

  function flashSavedPanel(el) {
    if (!el) {
      return;
    }
    el.classList.remove("admin-ajax-flash-saved");
    void el.offsetWidth;
    el.classList.add("admin-ajax-flash-saved");
    window.setTimeout(function () {
      el.classList.remove("admin-ajax-flash-saved");
    }, 1400);
  }

  function setFormBusy(form, busy) {
    if (!form) {
      return;
    }
    form.querySelectorAll("button[type='submit'], input[type='submit']").forEach(function (btn) {
      btn.disabled = !!busy;
    });
    form.classList.toggle("is-ajax-busy", !!busy);
  }

  function parseJsonResponse(response) {
    var ct = (response.headers.get("Content-Type") || "").toLowerCase();
    if (ct.indexOf("application/json") === -1) {
      return response.text().then(function (txt) {
        throw new Error("Respuesta no JSON: " + txt.slice(0, 160));
      });
    }
    return response.json();
  }

  function fetchAdminPost(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    }).then(function (response) {
      return parseJsonResponse(response).then(function (data) {
        if (!response.ok || !data || data.ok !== true) {
          var err = new Error((data && data.message) || "Error en la petición");
          err.payload = data;
          throw err;
        }
        return data;
      });
    });
  }

  function postFormAjax(form, submitter) {
    var fd = new FormData(form);
    fd.append("ajax", "1");
    if (submitter && submitter.name) {
      fd.set(submitter.name, submitter.value);
    }
    var actionUrl = form.getAttribute("action") || window.location.pathname;
    return fetchAdminPost(actionUrl, fd);
  }

  function notifySuccess(data, fallbackTitle) {
    var toastType =
      data && data.toast_type && data.toast_type !== ""
        ? data.toast_type
        : "success";
    showAdminToast(toastType, (data && data.message) || "Guardado.", {
      title: (data && data.title) || fallbackTitle,
    });
  }

  window.showAdminToast = showAdminToast;

  function notifyError(err, fallbackTitle) {
    var msg = err.message || "No se pudo completar la acción.";
    if (err.payload && err.payload.message) {
      msg = err.payload.message;
    }
    showAdminToast("error", msg, { title: fallbackTitle || "Error" });
  }

  function updateActivePill(btn, isActive) {
    if (!btn) {
      return;
    }
    btn.classList.remove("text-bg-success", "text-bg-secondary");
    btn.classList.add(isActive ? "text-bg-success" : "text-bg-secondary");
    btn.title = isActive
      ? "Cuenta activa: puede iniciar sesión. Pulsa para desactivar (no podrá entrar)."
      : "Cuenta inactiva. Pulsa para reactivar el acceso.";
    btn.setAttribute(
      "aria-label",
      isActive ? "Cuenta activa, pulsar para desactivar" : "Cuenta inactiva, pulsar para activar"
    );
    var icon = btn.querySelector("i");
    var label = btn.querySelector(".portal-pill-label");
    if (icon) {
      icon.className = isActive ? "fa-solid fa-user-check" : "fa-solid fa-user-slash";
      icon.setAttribute("aria-hidden", "true");
    }
    if (label) {
      label.textContent = isActive ? "Activo" : "Inactivo";
    }
    btn.classList.add("is-ajax-pulse");
    window.setTimeout(function () {
      btn.classList.remove("is-ajax-pulse");
    }, 550);
  }

  function updateSmtpPill(btn, notifyOut) {
    if (!btn) {
      return;
    }
    btn.classList.remove("text-bg-info", "text-bg-secondary", "border", "border-secondary");
    if (notifyOut) {
      btn.classList.add("text-bg-info");
    } else {
      btn.classList.add("text-bg-secondary", "border", "border-secondary");
    }
    btn.title = notifyOut
      ? "Envío por correo activo (SMTP al responder). Pulsa para solo bandeja web."
      : "Solo bandeja web. Pulsa para intentar envío SMTP al responder desde Mensajes.";
    btn.setAttribute(
      "aria-label",
      notifyOut
        ? "Correo SMTP activo, pulsar para desactivar"
        : "Solo web, pulsar para activar envío SMTP"
    );
    var icon = btn.querySelector("i");
    var label = btn.querySelector(".portal-pill-label");
    if (icon) {
      icon.className = notifyOut ? "fa-solid fa-paper-plane" : "fa-solid fa-display";
      icon.setAttribute("aria-hidden", "true");
    }
    if (label) {
      label.textContent = notifyOut ? "Correo" : "Solo web";
    }
    btn.classList.add("is-ajax-pulse");
    window.setTimeout(function () {
      btn.classList.remove("is-ajax-pulse");
    }, 550);
  }

  function applyClientToggle(form, data) {
    var client = data.client;
    if (!client) {
      return;
    }
    var tr = form.closest("tr");
    if (!tr) {
      return;
    }
    if (data.toggle === "active") {
      var activeInput = tr.querySelector(
        'input[name="action"][value="client_toggle_active"]'
      );
      if (activeInput) {
        var activeForm = activeInput.closest("form");
        if (activeForm) {
          updateActivePill(activeForm.querySelector("button[type='submit']"), !!client.is_active);
        }
      }
    } else if (data.toggle === "email_notify") {
      updateSmtpPill(form.querySelector("button[type='submit']"), !!client.email_notify_outbound);
    }
    flashSavedPanel(tr);
  }

  function applySettingsSave(form, data) {
    var panel =
      document.getElementById("tools_config_panel") ||
      form.closest(".accordion-body");
    flashSavedPanel(panel);
    if (data.settings && typeof data.settings.logo_image_path === "string") {
      var path = data.settings.logo_image_path;
      var hidden = form.querySelector('input[name="current_logo_image_path"]');
      if (hidden) {
        hidden.value = path;
      }
      var removeCb = form.querySelector('input[name="remove_logo_image"]');
      if (removeCb) {
        removeCb.checked = false;
      }
      var fileInput = form.querySelector('input[name="logo_image_file"]');
      if (fileInput) {
        fileInput.value = "";
      }
      var preview = form.querySelector(".logo-preview-admin");
      var previewWrap = preview ? preview.closest(".d-flex") : null;
      if (path === "") {
        if (previewWrap) {
          previewWrap.remove();
        }
      } else if (preview) {
        preview.src = path;
      } else {
        var logoBlock = fileInput ? fileInput.closest(".mt-3") : null;
        if (logoBlock) {
          var row = document.createElement("div");
          row.className = "d-flex flex-wrap gap-3 align-items-center mb-2";
          row.innerHTML =
            '<img src="' +
            escapeHtml(path) +
            '" alt="Logo actual" class="logo-preview-admin">';
          logoBlock.insertBefore(row, logoBlock.firstChild.nextSibling);
        }
      }
    }
  }

  function updateServiceRowHeader(row, service) {
    if (!row || !service) {
      return;
    }
    var titleEl = row.querySelector(".accordion-header-service-title");
    if (titleEl && service.title) {
      titleEl.textContent = service.title;
    }
    var iconEl = row.querySelector(".accordion-button > i");
    if (iconEl && service.icon_class) {
      iconEl.className = service.icon_class;
    }
    var btn = row.querySelector(".accordion-button");
    if (!btn) {
      return;
    }
    var badge = btn.querySelector(".badge");
    if (service.is_active) {
      if (badge) {
        badge.remove();
      }
    } else if (!badge) {
      var span = document.createElement("span");
      span.className = "badge text-bg-secondary ms-1";
      span.textContent = "Inactivo";
      btn.appendChild(span);
    }
  }

  function updateServiceMainImage(row, imagePath) {
    if (!row || !imagePath) {
      return;
    }
    var fileBlock = row.querySelector('input[name^="service_images"]');
    if (!fileBlock) {
      return;
    }
    var wrap = fileBlock.closest(".col-md-12");
    if (!wrap) {
      return;
    }
    var preview = wrap.querySelector("img[alt='Imagen servicio']");
    if (preview) {
      preview.src = imagePath;
      return;
    }
    var box = document.createElement("div");
    box.className = "mb-2";
    box.innerHTML =
      '<img src="' +
      escapeHtml(imagePath) +
      '" alt="Imagen servicio" style="width:120px;height:80px;object-fit:cover;border-radius:10px;border:1px solid var(--border);">';
    wrap.insertBefore(box, fileBlock);
    var hidden = row.querySelector('input[name$="[current_image_path]"]');
    if (hidden) {
      hidden.value = imagePath;
    }
  }

  function rebuildServiceGallery(row, service) {
    if (!row || !service || !Array.isArray(service.gallery)) {
      return;
    }
    var sid = String(service.id || "");
    var orderInput = row.querySelector(".js-gallery-order-input");
    if (orderInput) {
      orderInput.value = service.gallery
        .map(function (g) {
          return String(g.id);
        })
        .join(",");
    }
    var existing = row.querySelector(".js-gallery-sortable");
    var preview = row.querySelector('[id^="gallery_preview_"]');
    if (preview) {
      preview.innerHTML = "";
    }
    if (existing) {
      existing.remove();
    }
    if (service.gallery.length === 0) {
      return;
    }
    var container = document.createElement("div");
    container.className = "gallery-thumbs js-gallery-sortable";
    container.setAttribute("data-service-id", sid);
    service.gallery.forEach(function (item) {
      var gid = String(item.id);
      var imgPath = escapeHtml(item.image_path || "");
      var titleVal = escapeHtml(
        (item.image_title || item.caption || "").toString()
      );
      var descVal = escapeHtml((item.image_description || "").toString());
      var wrap = document.createElement("div");
      wrap.className = "gallery-thumb-wrap is-draggable";
      wrap.title = "Arrastra para ordenar";
      wrap.draggable = true;
      wrap.setAttribute("data-gallery-id", gid);
      wrap.innerHTML =
        '<label class="gallery-thumb-item">' +
        '<img src="' +
        imgPath +
        '" alt="Imagen carrusel">' +
        '<input class="gallery-thumb-check js-gallery-check-' +
        sid +
        '" type="checkbox" name="remove_gallery_ids[]" value="' +
        gid +
        '">' +
        '<span class="gallery-mark-overlay"></span>' +
        "</label>" +
        '<div class="gallery-meta-stack">' +
        '<input class="form-control form-control-sm mb-1" type="text" name="gallery_image_titles[' +
        gid +
        ']" value="' +
        titleVal +
        '" placeholder="Título de la imagen" maxlength="220">' +
        '<textarea class="form-control form-control-sm gallery-desc-input" name="gallery_image_descriptions[' +
        gid +
        ']" rows="2" placeholder="Descripción (opcional)">' +
        descVal +
        "</textarea>" +
        "</div>";
      container.appendChild(wrap);
    });
    if (orderInput && orderInput.parentElement) {
      orderInput.parentElement.insertBefore(container, orderInput);
    }
    if (typeof window.initAdminGallerySortable === "function") {
      window.initAdminGallerySortable(container);
    }
  }

  function applyServiceSave(row, data) {
    if (!row || !data.service) {
      return;
    }
    var service = data.service;
    updateServiceRowHeader(row, service);
    if (service.image_path) {
      updateServiceMainImage(row, service.image_path);
    }
    rebuildServiceGallery(row, service);
    row.querySelectorAll('input[type="file"]').forEach(function (input) {
      input.value = "";
    });
    flashSavedPanel(row.querySelector(".accordion-body") || row);
  }

  function handleServiceDelete(btn) {
    var serviceId = btn.getAttribute("data-service-id");
    if (!serviceId || !window.confirm("¿Eliminar este servicio?")) {
      return;
    }
    var form = btn.closest("form");
    if (!form) {
      return;
    }
    var fd = new FormData();
    fd.append("ajax", "1");
    fd.append("action", "delete_service");
    fd.append("service_id", serviceId);
    setFormBusy(form, true);
    fetchAdminPost(form.getAttribute("action") || window.location.pathname, fd)
      .then(function (data) {
        var row = btn.closest(".service-row");
        if (row) {
          row.style.transition = "opacity 0.3s ease";
          row.style.opacity = "0";
          window.setTimeout(function () {
            row.remove();
          }, 300);
        }
        notifySuccess(data, "Servicio eliminado");
      })
      .catch(function (err) {
        notifyError(err, "No se pudo eliminar");
      })
      .finally(function () {
        setFormBusy(form, false);
      });
  }

  function applyCredentialsSave(form, data) {
    var panel = document.getElementById("tools_credentials_panel");
    flashSavedPanel(panel ? panel.querySelector(".accordion-body") : form);
    form.querySelectorAll('input[type="password"]').forEach(function (input) {
      input.value = "";
    });
    if (data.admin_email) {
      var sessionEl = document.querySelector(".admin-app-bar__session");
      if (sessionEl) {
        sessionEl.textContent = data.admin_email;
        sessionEl.setAttribute("title", data.admin_email);
      }
      var emailInput = form.querySelector('input[name="new_admin_email"]');
      if (emailInput) {
        emailInput.value = data.admin_email;
      }
    }
  }

  function applyExpertSave(form, data) {
    var expert = data.expert;
    flashSavedPanel(form.closest(".admin-expert-subpanel") || form);
    if (!expert || !expert.id) {
      return;
    }
    var eid = String(expert.id);
    var nameEl = document.querySelector(
      'tr[data-filter-id="' + eid + '"] .expert-row-name'
    );
    if (nameEl && expert.display_name) {
      nameEl.textContent = expert.display_name;
    }
  }

  function removeExpertFromDom(expertId) {
    var id = String(expertId || "");
    if (id === "") {
      return;
    }
    document
      .querySelectorAll('tr[data-filter-id="' + id + '"], tr[data-filter-detail-for="' + id + '"]')
      .forEach(function (tr) {
        tr.style.transition = "opacity 0.28s ease, transform 0.28s ease";
        tr.style.opacity = "0";
        tr.style.transform = "translateX(6px)";
        window.setTimeout(function () {
          tr.remove();
        }, 280);
      });
    if (document.getElementById("admin-expert-edit")) {
      window.setTimeout(function () {
        window.location.hash = "admin-experts-list";
      }, 350);
    }
  }

  function applyInboxReply(form, data) {
    var textarea = form.querySelector('textarea[name="reply_body"]');
    if (textarea) {
      textarea.value = "";
    }
    var details = form.closest("details.admin-msg-thread");
    if (details) {
      details.querySelectorAll(".message-row.is-unread").forEach(function (row) {
        row.classList.remove("is-unread");
        var badge = row.querySelector(".js-msg-new-badge");
        if (badge) {
          badge.remove();
        }
      });
    }
    var reply = data.reply;
    if (!reply || !reply.body) {
      flashSavedPanel(form);
      return;
    }
    var list = details ? details.querySelector(".message-replies-sent") : null;
    if (!list && details) {
      var bubble = document.createElement("div");
      bubble.className = "admin-msg-bubble admin-msg-bubble--admin";
      var label = document.createElement("div");
      label.className = "admin-msg-bubble-label text-secondary";
      label.innerHTML =
        '<i class="fa-solid fa-reply me-1"></i>Tus respuestas por correo (desde el panel)';
      list = document.createElement("div");
      list.className = "message-replies-sent border-0 ps-0 pt-0 mt-0 mb-0";
      bubble.appendChild(label);
      bubble.appendChild(list);
      form.parentElement.insertBefore(bubble, form);
    }
    if (list) {
      var item = document.createElement("div");
      item.className = "message-reply-item small admin-ajax-reply-new";
      var when = document.createElement("span");
      when.className = "text-muted";
      when.textContent = reply.created_label || "";
      var body = document.createElement("div");
      body.className = "mt-1 message-body-text mb-0";
      body.style.maxHeight = "12rem";
      body.style.whiteSpace = "pre-wrap";
      body.textContent = reply.body;
      item.appendChild(when);
      item.appendChild(body);
      list.appendChild(item);
    }
    flashSavedPanel(details || form);
  }

  function updateExpertTemplateSummary(summary, weekendEmpty) {
    var el = document.getElementById("expert-template-summary");
    if (!summary) {
      if (el) {
        el.remove();
      }
      return;
    }
    var weekendNote =
      weekendEmpty === true
        ? " Fin de semana sin horario."
        : "";
    var html =
      '<i class="fa-solid fa-circle-check me-1 text-success" aria-hidden="true"></i>' +
      "<strong>Lunes a viernes:</strong> " +
      escapeHtml(summary) +
      " (misma franja cada día laborable)." +
      escapeHtml(weekendNote);
    if (!el) {
      el = document.createElement("div");
      el.id = "expert-template-summary";
      el.className =
        "alert alert-secondary py-2 px-3 small mb-3 expert-schedule-summary";
      el.setAttribute("role", "status");
      var body = document.getElementById("expert_sch_acc_template");
      var anchor = body ? body.querySelector(".expert-template-block") : null;
      if (body) {
        body.insertBefore(el, anchor || body.firstChild);
      }
    }
    el.innerHTML = html;
  }

  function buildExpertDaySlotLi(slot, expertId, dayLabel) {
    var li = document.createElement("li");
    li.className = "expert-day-card__slot";
    var timeSpan = document.createElement("span");
    timeSpan.className = "expert-day-card__time";
    var code = document.createElement("code");
    code.textContent = slot.start + "–" + slot.end;
    timeSpan.appendChild(code);
    var delForm = document.createElement("form");
    delForm.method = "post";
    delForm.className = "d-inline";
    delForm.setAttribute(
      "onsubmit",
      "return confirm('¿Quitar esta franja de " + dayLabel.replace(/'/g, "\\'") + "?');"
    );
    var fields = [
      ["action", "expert_delete_availability"],
      ["expert_id", String(expertId)],
      ["availability_id", String(slot.id)],
    ];
    fields.forEach(function (pair) {
      var inp = document.createElement("input");
      inp.type = "hidden";
      inp.name = pair[0];
      inp.value = pair[1];
      delForm.appendChild(inp);
    });
    var btn = document.createElement("button");
    btn.type = "submit";
    btn.className = "btn btn-link btn-sm text-danger p-0 expert-day-card__remove";
    btn.title = "Quitar franja";
    btn.setAttribute("aria-label", "Quitar franja");
    btn.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
    delForm.appendChild(btn);
    li.appendChild(timeSpan);
    li.appendChild(delForm);
    return li;
  }

  function updateExpertDayCard(card, slots, expertId) {
    if (!card) {
      return;
    }
    var dayLabel = card.getAttribute("data-day-label") || "este día";
    var badge = card.querySelector(".expert-day-card__badge");
    var slotsUl = card.querySelector(".expert-day-card__slots");
    var emptyP = card.querySelector(".expert-day-card__empty");
    var hasSlots = slots && slots.length > 0;

    if (badge) {
      badge.style.display = hasSlots ? "none" : "";
    }
    if (hasSlots) {
      if (emptyP) {
        emptyP.remove();
      }
      if (!slotsUl) {
        slotsUl = document.createElement("ul");
        slotsUl.className = "expert-day-card__slots list-unstyled mb-0";
        var addDetails = card.querySelector(".expert-day-card__add");
        if (addDetails) {
          card.insertBefore(slotsUl, addDetails);
        } else {
          card.appendChild(slotsUl);
        }
      }
      slotsUl.innerHTML = "";
      slots.forEach(function (slot) {
        slotsUl.appendChild(buildExpertDaySlotLi(slot, expertId, dayLabel));
      });
    } else {
      if (slotsUl) {
        slotsUl.remove();
      }
      if (!emptyP) {
        emptyP = document.createElement("p");
        emptyP.className = "expert-day-card__empty small text-secondary mb-0";
        emptyP.textContent = "No atiende este día.";
        var addDetails = card.querySelector(".expert-day-card__add");
        if (addDetails) {
          card.insertBefore(emptyP, addDetails);
        } else {
          card.appendChild(emptyP);
        }
      }
    }
    card.classList.add("admin-ajax-flash-saved");
    window.setTimeout(function () {
      card.classList.remove("admin-ajax-flash-saved");
    }, 1400);
  }

  function applyExpertTemplateSave(form, data) {
    var weekly = data.weekly;
    var expertId = data.expert_id || 0;
    if (weekly && weekly.prefill) {
      var p = weekly.prefill;
      var s1 = form.querySelector('input[name="template_slot1_start"]');
      var e1 = form.querySelector('input[name="template_slot1_end"]');
      var s2 = form.querySelector('input[name="template_slot2_start"]');
      var e2 = form.querySelector('input[name="template_slot2_end"]');
      if (s1) {
        s1.value = p.slot1_start || "";
      }
      if (e1) {
        e1.value = p.slot1_end || "";
      }
      if (s2) {
        s2.value = p.slot2_start || "";
      }
      if (e2) {
        e2.value = p.slot2_end || "";
      }
    }
    if (weekly && weekly.by_weekday) {
      var byWd = weekly.by_weekday;
      document.querySelectorAll(".expert-day-card[data-weekday]").forEach(function (card) {
        var wd = parseInt(card.getAttribute("data-weekday"), 10);
        var slots = byWd[wd] || byWd[String(wd)] || [];
        updateExpertDayCard(card, slots, expertId);
      });
      var wk6 = byWd[6] || byWd["6"] || [];
      var wk0 = byWd[0] || byWd["0"] || [];
      updateExpertTemplateSummary(
        weekly.mon_fri_summary || null,
        wk6.length === 0 && wk0.length === 0
      );
    }
    var panel =
      document.getElementById("expert_sch_acc_template") ||
      document.getElementById("expert-template-days-grid");
    flashSavedPanel(panel);
  }

  function handleAjaxSuccess(form, submitter, data) {
    var scope = form.getAttribute("data-ajax-scope") || "";

    if (scope === "expert-template") {
      applyExpertTemplateSave(form, data);
      notifySuccess(data, "Plantilla guardada");
      return;
    }

    if (scope === "credentials") {
      applyCredentialsSave(form, data);
      notifySuccess(data, "Credenciales actualizadas");
      return;
    }

    if (scope === "expert-save") {
      applyExpertSave(form, data);
      notifySuccess(data, "Experto guardado");
      return;
    }

    if (scope === "expert-add") {
      notifySuccess(data, "Experto creado");
      try {
        form.reset();
      } catch (_e) {}
      if (data.redirect) {
        window.setTimeout(function () {
          window.location.href = data.redirect;
        }, 900);
      }
      return;
    }

    if (scope === "expert-delete") {
      removeExpertFromDom(data.expert_id);
      notifySuccess(data, "Experto eliminado");
      return;
    }

    if (scope === "inbox-reply") {
      applyInboxReply(form, data);
      notifySuccess(data, data.title || "Respuesta enviada");
      return;
    }

    if (scope === "settings") {
      applySettingsSave(form, data);
      notifySuccess(data, "Configuración guardada");
      return;
    }

    if (scope === "client-toggle") {
      applyClientToggle(form, data);
      notifySuccess(data, data.title || "Cliente actualizado");
      return;
    }

    if (scope === "client-delete") {
      var tr = form.closest("tr");
      if (tr) {
        tr.style.transition = "opacity 0.28s ease, transform 0.28s ease";
        tr.style.opacity = "0";
        tr.style.transform = "translateX(8px)";
        window.setTimeout(function () {
          tr.remove();
          var tbody = document.querySelector(".admin-portal-clients-table tbody");
          if (tbody && !tbody.querySelector("tr")) {
            var wrap = document.querySelector(".admin-portal-clients-body");
            if (wrap) {
              var empty = document.createElement("p");
              empty.className = "small text-light-emphasis mb-0";
              empty.textContent =
                "Aún no hay cuentas. Comparte la URL del acordeón «Rutas» o el enlace «Clientes» del menú de la web.";
              var tableWrap = wrap.querySelector(".table-responsive");
              if (tableWrap) {
                tableWrap.remove();
              }
              wrap.appendChild(empty);
            }
          }
        }, 280);
      }
      notifySuccess(data, "Cliente eliminado");
      return;
    }

    if (scope === "service" && submitter) {
      applyServiceSave(submitter.closest(".service-row"), data);
      notifySuccess(data, "Servicio actualizado");
      return;
    }

    notifySuccess(data, "Guardado");

    if (form.getAttribute("data-ajax-reload-on-success") === "1") {
      window.setTimeout(function () {
        window.location.href =
          window.location.pathname + "?workspace=manage#admin-tool-service-edit";
      }, 600);
    } else if (form.id === "form-add-service") {
      try {
        form.reset();
      } catch (_e) {}
      var panel = document.getElementById("collapse_new_service_panel");
      if (panel && window.bootstrap && bootstrap.Collapse) {
        bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).hide();
      }
    }
  }

  document.addEventListener("submit", function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    if (!form.classList.contains("js-admin-ajax-form")) {
      return;
    }

    var submitter = ev.submitter || null;

    if (submitter && submitter.classList.contains("js-admin-ajax-delete-service")) {
      ev.preventDefault();
      handleServiceDelete(submitter);
      return;
    }

    if (form.classList.contains("js-portal-client-delete")) {
      if (!window.confirm("¿Eliminar este cliente? No se puede deshacer.")) {
        ev.preventDefault();
        return;
      }
    }

    if (form.classList.contains("js-expert-delete-form")) {
      var expertDelMsg = form.closest("#admin-expert-edit")
        ? "¿Eliminar este experto? Se quitarán sus vínculos con servicios."
        : "¿Eliminar este experto?";
      if (!window.confirm(expertDelMsg)) {
        ev.preventDefault();
        return;
      }
    }

    ev.preventDefault();
    setFormBusy(form, true);

    postFormAjax(form, submitter)
      .then(function (data) {
        handleAjaxSuccess(form, submitter, data);
      })
      .catch(function (err) {
        notifyError(err);
      })
      .finally(function () {
        setFormBusy(form, false);
      });
  });

  if (!document.getElementById("adminToastProgressStyle")) {
    var style = document.createElement("style");
    style.id = "adminToastProgressStyle";
    style.textContent =
      "@keyframes adminToastProgress { from { transform: scaleX(1); } to { transform: scaleX(0); } }";
    document.head.appendChild(style);
  }
})();
