<?php
declare(strict_types=1);
/** Interruptor claro/oscuro + paleta en panel compacto (hover / clic). */
?>
<div class="theme-toolbar" data-theme-toolbar>
  <label class="theme-mode-switch" title="Modo oscuro / claro">
    <input
      type="checkbox"
      class="theme-mode-switch-input"
      data-theme-mode-switch
      aria-label="Activar modo claro"
    />
    <span class="theme-mode-switch-track" aria-hidden="true">
      <span class="theme-mode-switch-thumb"></span>
      <i class="fa-solid fa-moon theme-mode-switch-icon theme-mode-switch-icon--dark" aria-hidden="true"></i>
      <i class="fa-solid fa-sun theme-mode-switch-icon theme-mode-switch-icon--light" aria-hidden="true"></i>
    </span>
  </label>

  <div class="theme-palette-anchor" data-palette-anchor>
    <button
      type="button"
      class="theme-palette-trigger"
      data-palette-trigger
      aria-expanded="false"
      aria-controls="themePaletteFlyout"
      title="Color del tema (pasa el cursor o pulsa)"
    >
      <i class="fa-solid fa-palette" aria-hidden="true"></i>
      <span class="theme-palette-trigger-dot" data-palette-active-dot aria-hidden="true"></span>
      <span class="theme-sr-only">Paleta de color</span>
    </button>
    <div
      id="themePaletteFlyout"
      class="theme-palette-flyout"
      data-palette-flyout
      role="region"
      aria-label="Paleta de color"
    >
      <div class="palette-picker palette-picker--flyout" role="radiogroup" aria-label="Elegir paleta">
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
