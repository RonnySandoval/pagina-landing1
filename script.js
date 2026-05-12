const menuToggle = document.getElementById("menuToggle");
const mainNav = document.getElementById("mainNav");
const year = document.getElementById("year");
const themeModeBtn = document.getElementById("themeModeBtn");
const paletteSelect = document.getElementById("paletteSelect");

if (year) {
  year.textContent = new Date().getFullYear();
}

if (menuToggle && mainNav) {
  menuToggle.addEventListener("click", () => {
    const isOpen = mainNav.classList.toggle("open");
    menuToggle.setAttribute("aria-expanded", String(isOpen));
  });
}

const allowedModes = new Set(["dark", "light"]);
const allowedPalettes = new Set(["blue", "violet", "emerald", "sunset"]);

function applyTheme(mode, palette) {
  const safeMode = allowedModes.has(mode) ? mode : "dark";
  const safePalette = allowedPalettes.has(palette) ? palette : "blue";
  document.documentElement.setAttribute("data-theme", safeMode);
  document.documentElement.setAttribute("data-palette", safePalette);

  if (themeModeBtn) {
    const icon = themeModeBtn.querySelector("i");
    if (icon) {
      icon.className = safeMode === "dark" ? "fa-solid fa-moon" : "fa-solid fa-sun";
    }
  }
  if (paletteSelect) {
    paletteSelect.value = safePalette;
  }
}

const initialMode = localStorage.getItem("ui-mode") || document.documentElement.getAttribute("data-theme") || "dark";
const initialPalette = localStorage.getItem("ui-palette") || document.documentElement.getAttribute("data-palette") || "blue";
applyTheme(initialMode, initialPalette);

if (themeModeBtn) {
  themeModeBtn.addEventListener("click", () => {
    const current = document.documentElement.getAttribute("data-theme") || "dark";
    const nextMode = current === "dark" ? "light" : "dark";
    const palette = document.documentElement.getAttribute("data-palette") || "blue";
    applyTheme(nextMode, palette);
    localStorage.setItem("ui-mode", nextMode);
  });
}

if (paletteSelect) {
  paletteSelect.addEventListener("change", () => {
    const mode = document.documentElement.getAttribute("data-theme") || "dark";
    const nextPalette = paletteSelect.value;
    applyTheme(mode, nextPalette);
    localStorage.setItem("ui-palette", nextPalette);
  });
}

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
  contactForm.addEventListener("submit", function () {
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

