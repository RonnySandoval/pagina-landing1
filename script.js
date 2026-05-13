const menuToggle = document.getElementById("menuToggle");
const mainNav = document.getElementById("mainNav");
const year = document.getElementById("year");
const themeDropdown = document.querySelector("[data-theme-dropdown]");
const themeDropdownToggle = document.querySelector("[data-theme-dropdown-toggle]");
const themeDropdownPanel = document.querySelector("[data-theme-dropdown-panel]");
const themeModeBtns = Array.from(document.querySelectorAll("[data-theme-mode]"));
const paletteButtons = Array.from(document.querySelectorAll(".palette-swatch-btn"));

const allowedModes = new Set(["dark", "light"]);
const allowedPalettes = new Set([
  "blue",
  "cyan",
  "emerald",
  "lime",
  "amber",
  "sunset",
  "rose",
  "magenta",
  "violet",
  "indigo",
]);

function setThemeDropdownOpen(open) {
  if (!themeDropdownToggle || !themeDropdownPanel) return;
  themeDropdownToggle.setAttribute("aria-expanded", open ? "true" : "false");
  if (open) {
    themeDropdownPanel.removeAttribute("hidden");
  } else {
    themeDropdownPanel.setAttribute("hidden", "");
  }
}

function syncModeButtons(activeMode) {
  themeModeBtns.forEach((btn) => {
    const m = btn.getAttribute("data-theme-mode") || "";
    const isOn = m === activeMode;
    btn.classList.toggle("is-active", isOn);
    btn.setAttribute("aria-pressed", isOn ? "true" : "false");
  });
}

function syncPaletteButtons(activePalette) {
  paletteButtons.forEach((btn) => {
    const p = btn.getAttribute("data-palette") || "";
    const isOn = p === activePalette;
    btn.classList.toggle("is-active", isOn);
    btn.setAttribute("aria-pressed", isOn ? "true" : "false");
  });
}

function applyTheme(mode, palette) {
  const safeMode = allowedModes.has(mode) ? mode : "dark";
  const safePalette = allowedPalettes.has(palette) ? palette : "blue";
  document.documentElement.setAttribute("data-theme", safeMode);
  document.documentElement.setAttribute("data-palette", safePalette);
  syncModeButtons(safeMode);
  syncPaletteButtons(safePalette);
}

if (year) {
  year.textContent = new Date().getFullYear();
}

if (menuToggle && mainNav) {
  menuToggle.addEventListener("click", () => {
    const isOpen = mainNav.classList.toggle("open");
    menuToggle.setAttribute("aria-expanded", String(isOpen));
  });
}

const initialMode = localStorage.getItem("ui-mode") || document.documentElement.getAttribute("data-theme") || "dark";
const initialPalette = localStorage.getItem("ui-palette") || document.documentElement.getAttribute("data-palette") || "blue";
applyTheme(initialMode, initialPalette);

if (themeDropdownToggle && themeDropdownPanel) {
  themeDropdownToggle.addEventListener("click", (e) => {
    e.stopPropagation();
    const open = themeDropdownToggle.getAttribute("aria-expanded") === "true";
    setThemeDropdownOpen(!open);
  });
}

if (themeDropdown) {
  themeDropdown.addEventListener("click", (e) => {
    e.stopPropagation();
  });
}

document.addEventListener("click", (e) => {
  if (!themeDropdown || !themeDropdownPanel) return;
  if (themeDropdown.contains(e.target)) return;
  setThemeDropdownOpen(false);
});

document.addEventListener("keydown", (e) => {
  if (e.key !== "Escape") return;
  setThemeDropdownOpen(false);
});

themeModeBtns.forEach((btn) => {
  btn.addEventListener("click", (e) => {
    e.stopPropagation();
    const nextMode = btn.getAttribute("data-theme-mode") || "dark";
    const palette = document.documentElement.getAttribute("data-palette") || "blue";
    applyTheme(nextMode, palette);
    localStorage.setItem("ui-mode", nextMode);
  });
});

paletteButtons.forEach((btn) => {
  btn.addEventListener("click", (e) => {
    e.stopPropagation();
    const nextPalette = btn.getAttribute("data-palette") || "blue";
    const mode = document.documentElement.getAttribute("data-theme") || "dark";
    applyTheme(mode, nextPalette);
    localStorage.setItem("ui-palette", nextPalette);
  });
});

document.querySelectorAll(".service-toggle-btn").forEach((btn) => {
  btn.addEventListener("click", () => {
    const targetId = btn.getAttribute("data-toggle-target");
    if (!targetId) return;
    const detail = document.getElementById(targetId);
    if (!detail) return;
    const isHidden = detail.hasAttribute("hidden");
    if (isHidden) {
      detail.removeAttribute("hidden");
      btn.textContent = "Mostrar menos";
    } else {
      detail.setAttribute("hidden", "");
      btn.textContent = "Mostrar más";
    }
  });
});

document.querySelectorAll("[data-carousel]").forEach((carousel) => {
  const slides = Array.from(carousel.querySelectorAll(".carousel-slide"));
  if (slides.length <= 1) return;

  let index = 0;
  const render = () => {
    slides.forEach((slide, slideIndex) => {
      slide.classList.toggle("is-active", slideIndex === index);
    });
  };

  const prevBtn = carousel.querySelector("[data-carousel-prev]");
  const nextBtn = carousel.querySelector("[data-carousel-next]");

  if (prevBtn) {
    prevBtn.addEventListener("click", () => {
      index = (index - 1 + slides.length) % slides.length;
      render();
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener("click", () => {
      index = (index + 1) % slides.length;
      render();
    });
  }
});

const ctaImages = document.querySelectorAll(".js-service-cta");
const serviceSelect = document.getElementById("servicio");
const messageField = document.getElementById("mensaje");
const nameField = document.getElementById("nombre");

function fillFormFromImage(img) {
  const service = (img.getAttribute("data-service") || "").trim();
  const detail = (img.getAttribute("data-detail") || "").trim();
  if (service === "" && detail === "") return;

  if (serviceSelect && service !== "") {
    for (const option of serviceSelect.options) {
      if (option.value === service) {
        serviceSelect.value = service;
        break;
      }
    }
  }

  if (messageField) {
    const detailPart = detail !== "" ? `, específicamente: "${detail}"` : "";
    messageField.value =
      `Hola, me interesa el servicio de "${service}"${detailPart}. ¿Cómo me puedes ayudar?`;
  }

  const target = document.getElementById("contacto");
  if (target) {
    target.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  if (nameField) {
    setTimeout(() => {
      try {
        nameField.focus({ preventScroll: true });
      } catch (e) {
        nameField.focus();
      }
    }, 600);
  }
}

ctaImages.forEach((img) => {
  img.addEventListener("click", () => fillFormFromImage(img));
  img.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      fillFormFromImage(img);
    }
  });
});

const contactForm = document.getElementById("contactForm");
if (contactForm) {
  contactForm.addEventListener("submit", function (ev) {
    const emailEl = document.getElementById("email");
    const emailVal = emailEl ? String(emailEl.value || "").trim() : "";
    if (emailVal === "") {
      ev.preventDefault();
      if (emailEl) {
        try {
          emailEl.focus();
        } catch (_e) {}
        emailEl.setCustomValidity("Indica un correo para enviar por esta vía.");
        emailEl.reportValidity();
        emailEl.setCustomValidity("");
      }
      return;
    }
    const submitBtn = contactForm.querySelector("button[type='submit']");
    if (!submitBtn || submitBtn.disabled) return;
    submitBtn.disabled = true;
    submitBtn.dataset.originalLabel = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Enviando...';
    setTimeout(function () {
      if (submitBtn.disabled) {
        submitBtn.disabled = false;
        if (submitBtn.dataset.originalLabel) {
          submitBtn.innerHTML = submitBtn.dataset.originalLabel;
        }
      }
    }, 8000);
  });
}

const whatsappBtn = document.getElementById("contactWhatsappBtn");
if (whatsappBtn && contactForm) {
  whatsappBtn.addEventListener("click", function () {
    if (whatsappBtn.disabled) return;
    const phone = (contactForm.getAttribute("data-whatsapp") || "").replace(/\D+/g, "");
    if (phone === "") return;

    const nombre = (document.getElementById("nombre")?.value || "").trim();
    const email = (document.getElementById("email")?.value || "").trim();
    const servicio = (document.getElementById("servicio")?.value || "").trim();
    const asunto = (document.getElementById("contact_asunto")?.value || "").trim();
    const mensaje = (document.getElementById("mensaje")?.value || "").trim();

    // Si ya hay mensaje en el formulario, es el cuerpo completo: no repetir servicio ni plantillas.
    let text = "";
    if (mensaje !== "") {
      text = mensaje;
    } else {
      const parts = ["Hola"];
      if (nombre !== "") parts[0] = "Hola, soy " + nombre;
      if (asunto !== "") parts.push('Asunto: "' + asunto + '".');
      if (servicio !== "") parts.push('Me interesa el servicio "' + servicio + '".');
      if (parts.length === 1) parts.push("Quisiera más información, por favor.");
      else parts.push("¿Me puedes orientar?");
      text = parts.join(" ");
    }

    const url = "https://wa.me/" + phone + "?text=" + encodeURIComponent(text);

    const logUrl = new URL("contact_click_log.php", window.location.href).href;
    const body = new URLSearchParams();
    body.set("nombre", nombre);
    body.set("email", email);
    body.set("servicio", servicio);
    body.set("mensaje", mensaje);
    body.set("composed_text", text);

    // Abrir pestaña en blanco en el mismo clic (no la bloquea el navegador); luego wa.me cuando termine el POST del registro.
    const tab = window.open("about:blank", "_blank");
    const navigateWa = function () {
      try {
        if (tab && !tab.closed) {
          tab.location.href = url;
        } else {
          const w = window.open(url, "_blank", "noopener");
          if (!w) window.location.href = url;
        }
      } catch (_e) {
        window.location.href = url;
      }
    };
    const maxWait = 8000;
    const timeoutId = window.setTimeout(navigateWa, maxWait);
    fetch(logUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
      body: body.toString(),
      keepalive: true,
    })
      .catch(function () {})
      .finally(function () {
        window.clearTimeout(timeoutId);
        navigateWa();
      });
  });
}

const revealItems = document.querySelectorAll(".reveal");

if (revealItems.length) {
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("show");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.15 }
  );

  revealItems.forEach((item, index) => {
    item.style.transitionDelay = `${Math.min(index * 80, 360)}ms`;
    observer.observe(item);
  });
}

