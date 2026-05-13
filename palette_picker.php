<?php
declare(strict_types=1);
/** Tema: icono abre panel; nombres de paleta vía title (hover) + .theme-sr-only. */
?>
<div class="theme-dropdown" data-theme-dropdown>
  <button
    type="button"
    class="theme-btn theme-dropdown-toggle"
    id="themeDropdownToggle"
    data-theme-dropdown-toggle
    aria-expanded="false"
    aria-haspopup="true"
    aria-controls="themeDropdownPanel"
    title="Tema"
  >
    <i class="fa-solid fa-palette" aria-hidden="true"></i>
    <span class="theme-sr-only">Tema</span>
  </button>
  <div
    class="theme-dropdown-panel"
    id="themeDropdownPanel"
    data-theme-dropdown-panel
    hidden
    role="region"
    aria-label="Opciones de tema"
  >
    <div class="theme-dropdown-section">
      <div class="theme-dropdown-section-title">Aspecto</div>
      <div class="theme-mode-toggle" role="group" aria-label="Aspecto claro u oscuro">
        <button
          type="button"
          class="theme-mode-btn"
          data-theme-mode="dark"
          aria-pressed="true"
          title="Modo oscuro"
          aria-label="Modo oscuro"
        >
          <i class="fa-solid fa-moon" aria-hidden="true"></i>
          <span class="theme-sr-only">Oscuro</span>
        </button>
        <button
          type="button"
          class="theme-mode-btn"
          data-theme-mode="light"
          aria-pressed="false"
          title="Modo claro"
          aria-label="Modo claro"
        >
          <i class="fa-solid fa-sun" aria-hidden="true"></i>
          <span class="theme-sr-only">Claro</span>
        </button>
      </div>
    </div>
    <div class="theme-dropdown-section">
      <div class="theme-dropdown-section-title">Paleta</div>
      <div class="palette-picker palette-picker--compact" role="radiogroup" aria-label="Paleta de color">
        <button type="button" class="palette-swatch-btn" data-palette="blue" aria-pressed="false" title="Azul">
          <span class="palette-swatch" style="background:#3b82f6"></span><span class="theme-sr-only">Azul</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="cyan" aria-pressed="false" title="Cian">
          <span class="palette-swatch" style="background:#06b6d4"></span><span class="theme-sr-only">Cian</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="emerald" aria-pressed="false" title="Esmeralda">
          <span class="palette-swatch" style="background:#10b981"></span><span class="theme-sr-only">Esmeralda</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="lime" aria-pressed="false" title="Lima">
          <span class="palette-swatch" style="background:#84cc16"></span><span class="theme-sr-only">Lima</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="amber" aria-pressed="false" title="Ámbar">
          <span class="palette-swatch" style="background:#f59e0b"></span><span class="theme-sr-only">Ámbar</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="sunset" aria-pressed="false" title="Atardecer">
          <span class="palette-swatch" style="background:#f97316"></span><span class="theme-sr-only">Atardecer</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="rose" aria-pressed="false" title="Rosa">
          <span class="palette-swatch" style="background:#f43f5e"></span><span class="theme-sr-only">Rosa</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="magenta" aria-pressed="false" title="Magenta">
          <span class="palette-swatch" style="background:#d946ef"></span><span class="theme-sr-only">Magenta</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="violet" aria-pressed="false" title="Violeta">
          <span class="palette-swatch" style="background:#8b5cf6"></span><span class="theme-sr-only">Violeta</span>
        </button>
        <button type="button" class="palette-swatch-btn" data-palette="indigo" aria-pressed="false" title="Índigo">
          <span class="palette-swatch" style="background:#6366f1"></span><span class="theme-sr-only">Índigo</span>
        </button>
      </div>
    </div>
  </div>
</div>
