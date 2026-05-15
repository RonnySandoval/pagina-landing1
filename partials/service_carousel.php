<?php
declare(strict_types=1);
/**
 * Carrusel de imágenes de galería de servicio (vista pública).
 * Variables previas: $carouselSlides, $carouselServiceTitle, $carouselVariant, $carouselId
 *
 * @var list<array{image_path:string, title:string, description:string}> $carouselSlides
 * @var string $carouselServiceTitle
 * @var string $carouselVariant 'focus' | 'inline'
 * @var string $carouselId id único del contenedor
 */
$carouselSlides = $carouselSlides ?? [];
$carouselServiceTitle = (string)($carouselServiceTitle ?? "");
$carouselVariant = (string)($carouselVariant ?? "inline");
$carouselId = (string)($carouselId ?? "service_carousel");
$n = count($carouselSlides);
if ($n === 0) {
    return;
}
$many = $n > 1;
$wrapClass = "service-carousel" . ($carouselVariant === "focus" ? " service-carousel--focus" : " service-carousel--inline");
?>
<div class="<?= htmlspecialchars($wrapClass, ENT_QUOTES, "UTF-8") ?>" id="<?= htmlspecialchars($carouselId, ENT_QUOTES, "UTF-8") ?>"<?= $many ? ' data-carousel' : '' ?>>
  <?php if ($many): ?>
    <button type="button" class="carousel-btn" data-carousel-prev aria-label="Imagen anterior">
      <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
    </button>
  <?php else: ?>
    <span class="service-carousel-spacer" aria-hidden="true"></span>
  <?php endif; ?>
  <div class="carousel-track">
    <?php foreach ($carouselSlides as $ci => $cs): ?>
      <?php
        $sumT = $cs["title"] !== "" ? $cs["title"] : "Imagen";
        $ctaDetail = $cs["title"];
        if ($cs["description"] !== "") {
            $lim = $carouselVariant === "focus" ? 120 : 100;
            $ctaDetail .= " — " . mb_substr($cs["description"], 0, $lim, "UTF-8") . (mb_strlen($cs["description"], "UTF-8") > $lim ? "…" : "");
        }
      ?>
      <div class="carousel-slide<?= $ci === 0 ? " is-active" : "" ?>">
        <img
          class="carousel-image"
          src="<?= htmlspecialchars($cs["image_path"], ENT_QUOTES, "UTF-8") ?>"
          alt="<?= htmlspecialchars($sumT, ENT_QUOTES, "UTF-8") ?>"
          loading="<?= $ci === 0 ? "eager" : "lazy" ?>"
          decoding="async"
        >
        <div class="service-carousel-meta">
          <div class="service-carousel-meta-text">
            <strong class="service-carousel-title"><?= htmlspecialchars($sumT, ENT_QUOTES, "UTF-8") ?></strong>
            <?php if ($cs["description"] !== ""): ?>
              <p class="service-carousel-desc"><?= nl2br(htmlspecialchars($cs["description"], ENT_QUOTES, "UTF-8")) ?></p>
            <?php endif; ?>
          </div>
          <div class="service-carousel-meta-cta">
            <button
              type="button"
              class="btn btn-primary service-carousel-cta-btn js-service-cta"
              data-service="<?= htmlspecialchars($carouselServiceTitle, ENT_QUOTES, "UTF-8") ?>"
              data-detail="<?= htmlspecialchars($ctaDetail, ENT_QUOTES, "UTF-8") ?>"
            >
              <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
              <span>Solicitar información</span>
            </button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($many): ?>
    <button type="button" class="carousel-btn" data-carousel-next aria-label="Imagen siguiente">
      <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    </button>
  <?php else: ?>
    <span class="service-carousel-spacer" aria-hidden="true"></span>
  <?php endif; ?>
</div>
