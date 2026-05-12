<?php
declare(strict_types=1);

// Punto de entrada de la landing. Patrones de URL local/servidor: ver app_urls.php (mapa al inicio del archivo).
require __DIR__ . "/db.php";

$defaultSettings = [
    "person_name" => "Tu Nombre",
    "brand_name" => "Tu Marca",
    "hero_title" => "Describe aqui tu propuesta principal de valor.",
    "hero_intro" => "Agrega una breve introduccion para tu portada.",
    "about_text" => "Escribe una descripcion corta sobre ti y tus servicios.",
    "contact_intro" => "Invita a tus visitantes a contactarte para mas informacion.",
    "contact_email" => "contacto@tu-dominio.com",
    "contact_whatsapp" => "",
    "footer_text" => "Todos los derechos reservados.",
    "logo_image_path" => null
];

/**
 * Devuelve hasta 2 caracteres en mayúscula para el monograma del logo por
 * defecto. Para "Ronny Sandoval" -> "RS". Para "Acme" -> "A".
 */
function brand_monogram(string $brandName): string {
    $clean = trim($brandName);
    if ($clean === "") return "?";
    $parts = preg_split('/\s+/u', $clean) ?: [$clean];
    $letters = "";
    foreach ($parts as $part) {
        $firstChar = mb_substr($part, 0, 1, "UTF-8");
        if ($firstChar !== "") {
            $letters .= mb_strtoupper($firstChar, "UTF-8");
        }
        if (mb_strlen($letters, "UTF-8") >= 2) break;
    }
    return $letters !== "" ? $letters : "?";
}

$settings = $defaultSettings;
$settingsQuery = $conn->query("SELECT * FROM site_settings WHERE id = 1 LIMIT 1");
if ($settingsQuery && $settingsQuery->num_rows === 1) {
    $settings = array_merge($settings, $settingsQuery->fetch_assoc());
}

$services = [];
$servicesQuery = $conn->query("SELECT id, title, description, icon_class, image_path FROM services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
if ($servicesQuery) {
    while ($row = $servicesQuery->fetch_assoc()) {
        $services[] = $row;
    }
}

$serviceGallery = [];
$galleryQuery = $conn->query("
  SELECT sg.id, sg.service_id, sg.image_path, sg.caption
  FROM service_gallery sg
  INNER JOIN services s ON s.id = sg.service_id
  WHERE sg.is_active = 1 AND s.is_active = 1
  ORDER BY sg.sort_order ASC, sg.id ASC
");
if ($galleryQuery) {
    while ($galleryRow = $galleryQuery->fetch_assoc()) {
        $serviceId = (int)$galleryRow["service_id"];
        if (!isset($serviceGallery[$serviceId])) {
            $serviceGallery[$serviceId] = [];
        }
        $serviceGallery[$serviceId][] = $galleryRow;
    }
}

$stylesVersion = (string)(@filemtime(__DIR__ . "/styles.css") ?: time());
$scriptVersion = (string)(@filemtime(__DIR__ . "/script.js") ?: time());
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    (function () {
      try {
        var mode = localStorage.getItem("ui-mode") || "dark";
        var palette = localStorage.getItem("ui-palette") || "blue";
        document.documentElement.setAttribute("data-theme", mode);
        document.documentElement.setAttribute("data-palette", palette);
      } catch (e) {}
    })();
  </script>
  <title><?= htmlspecialchars($settings["brand_name"]) ?> | Web Personal</title>
  <meta name="description" content="Web personal de <?= htmlspecialchars($settings["person_name"]) ?>.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="styles.css?v=<?= htmlspecialchars($stylesVersion) ?>">
</head>
<body>
  <header class="site-header">
    <div class="container nav">
      <a href="#inicio" class="brand">
        <?php $logoPath = (string)($settings["logo_image_path"] ?? ""); ?>
        <?php if ($logoPath !== ""): ?>
          <img class="brand-logo brand-logo-img" src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars($settings["brand_name"]) ?>">
        <?php else: ?>
          <span class="brand-logo brand-logo-monogram" aria-hidden="true"><?= htmlspecialchars(brand_monogram((string)$settings["brand_name"])) ?></span>
        <?php endif; ?>
        <span class="brand-name"><?= htmlspecialchars($settings["brand_name"]) ?></span>
      </a>
      <button class="menu-toggle" id="menuToggle" aria-expanded="false" aria-label="Abrir menú">
        <span></span><span></span><span></span>
      </button>
      <nav id="mainNav" class="main-nav">
        <a href="#inicio"><i class="fa-solid fa-house"></i> Inicio</a>
        <a href="#sobre-mi"><i class="fa-solid fa-user"></i> Sobre mí</a>
        <a href="#servicios"><i class="fa-solid fa-briefcase"></i> Servicios</a>
        <a href="#contacto"><i class="fa-solid fa-envelope"></i> Contacto</a>
      </nav>
      <div class="theme-controls">
        <button id="themeModeBtn" class="theme-btn" type="button" aria-label="Cambiar modo de color">
          <i class="fa-solid fa-moon"></i>
        </button>
        <select id="paletteSelect" class="palette-select" aria-label="Seleccionar paleta de color">
          <option value="blue">Azul</option>
          <option value="violet">Violeta</option>
          <option value="emerald">Esmeralda</option>
          <option value="sunset">Sunset</option>
        </select>
      </div>
    </div>
  </header>

  <main>
    <section id="inicio" class="hero reveal">
      <div class="container hero-grid">
        <div>
          <p class="eyebrow">Hola, soy</p>
          <h1><?= htmlspecialchars($settings["person_name"]) ?></h1>
          <p class="lead">
            <?= htmlspecialchars($settings["hero_title"]) ?>
            <?= htmlspecialchars($settings["hero_intro"]) ?>
          </p>
          <div class="hero-cta">
            <a href="#contacto" class="btn btn-primary">Quiero contactarte</a>
            <a href="#servicios" class="btn btn-ghost">Ver servicios</a>
          </div>
        </div>
        <aside class="hero-card reveal">
          <h2><i class="fa-solid fa-clock"></i> Disponibilidad</h2>
          <ul>
            <?php foreach ($services as $service): ?>
              <li><i class="<?= htmlspecialchars($service["icon_class"] ?: "fa-solid fa-star") ?>"></i> <?= htmlspecialchars($service["title"]) ?></li>
            <?php endforeach; ?>
          </ul>
        </aside>
      </div>
    </section>

    <section id="sobre-mi" class="section reveal">
      <div class="container">
        <h2><i class="fa-solid fa-address-card"></i> Sobre mí</h2>
        <p><?= htmlspecialchars($settings["about_text"]) ?></p>
      </div>
    </section>

    <section id="servicios" class="section section-alt reveal">
      <div class="container">
        <h2><i class="fa-solid fa-star"></i> Servicios</h2>
        <div class="cards">
          <?php foreach ($services as $service): ?>
            <?php
              $serviceId = (int)$service["id"];
              $galleryItems = $serviceGallery[$serviceId] ?? [];
              $slides = [];
              foreach ($galleryItems as $galleryItem) {
                  $slides[] = [
                    "image_path" => (string)$galleryItem["image_path"],
                    "caption" => (string)($galleryItem["caption"] ?? "")
                  ];
              }
              $coverImage = !empty($service["image_path"]) ? (string)$service["image_path"] : "";
              $hasAdditionalContent = count($slides) > 0;
            ?>
            <article class="card reveal">
              <?php if ($coverImage !== ""): ?>
                <img
                  class="service-image js-service-cta"
                  src="<?= htmlspecialchars($coverImage) ?>"
                  alt="<?= htmlspecialchars($service["title"]) ?>"
                  role="button"
                  tabindex="0"
                  title="Solicitar este servicio"
                  data-service="<?= htmlspecialchars($service["title"]) ?>"
                  data-detail="">
              <?php else: ?>
                <div
                  class="service-image service-image-placeholder js-service-cta"
                  role="button"
                  tabindex="0"
                  title="Solicitar este servicio"
                  data-service="<?= htmlspecialchars($service["title"]) ?>"
                  data-detail="">
                  <i class="<?= htmlspecialchars($service["icon_class"] ?: "fa-solid fa-star") ?>"></i>
                </div>
              <?php endif; ?>
              <h3><i class="<?= htmlspecialchars($service["icon_class"]) ?>"></i> <?= htmlspecialchars($service["title"]) ?></h3>
              <p><?= htmlspecialchars($service["description"]) ?></p>
              <?php if ($hasAdditionalContent): ?>
                <button type="button" class="btn btn-ghost service-toggle-btn" data-toggle-target="service_more_<?= $serviceId ?>">Mostrar más</button>
                <div id="service_more_<?= $serviceId ?>" class="service-more" hidden>
                  <div class="service-carousel" data-carousel>
                    <button type="button" class="carousel-btn" data-carousel-prev aria-label="Imagen anterior"><i class="fa-solid fa-chevron-left"></i></button>
                    <div class="carousel-track">
                      <?php foreach ($slides as $index => $slide): ?>
                        <div class="carousel-slide <?= $index === 0 ? "is-active" : "" ?>">
                          <img
                            class="carousel-image js-service-cta"
                            src="<?= htmlspecialchars($slide["image_path"]) ?>"
                            alt="<?= htmlspecialchars($service["title"]) ?>"
                            role="button"
                            tabindex="0"
                            title="Solicitar este servicio"
                            data-service="<?= htmlspecialchars($service["title"]) ?>"
                            data-detail="<?= htmlspecialchars($slide["caption"]) ?>">
                          <small><?= htmlspecialchars($slide["caption"] !== "" ? $slide["caption"] : $service["title"]) ?></small>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <button type="button" class="carousel-btn" data-carousel-next aria-label="Imagen siguiente"><i class="fa-solid fa-chevron-right"></i></button>
                  </div>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section id="contacto" class="section reveal">
      <div class="container contact-grid">
        <div>
          <h2><i class="fa-solid fa-paper-plane"></i> Contacto</h2>
          <p><?= htmlspecialchars($settings["contact_intro"]) ?></p>
          <?php
            $errorMessages = [
                "nombre"          => "Por favor escribe tu nombre.",
                "email_vacio"     => "Por favor escribe tu correo.",
                "email_invalido"  => "El correo no es válido. Asegúrate de incluir “@” y un dominio (por ejemplo: tunombre@email.com).",
                "servicio"        => "Selecciona un servicio en el desplegable.",
                "mensaje"         => "Escribe el mensaje que quieres enviar.",
            ];
            $status = $_GET["status"] ?? "";
            $reason = $_GET["reason"] ?? "";
          ?>
          <?php if ($status === "ok"): ?>
            <p class="form-ok">Mensaje enviado correctamente.</p>
          <?php elseif ($status === "saved"): ?>
            <p class="form-ok">Mensaje guardado. Revisa la configuración de correo del servidor.</p>
          <?php elseif ($status === "error"): ?>
            <p class="form-error">
              <?= htmlspecialchars($errorMessages[$reason] ?? "No se pudo procesar el mensaje. Revisa los datos del formulario.") ?>
            </p>
          <?php endif; ?>
        </div>
        <?php
          // wa.me solo acepta dígitos. Aunque el admin valida, sanitizamos aquí también
          // para no inyectar HTML/JS en el data-attribute y evitar prefijos como "+57".
          $whatsappRaw = (string)($settings["contact_whatsapp"] ?? "");
          $whatsappDigits = preg_replace('/\D+/', '', $whatsappRaw) ?? "";
        ?>
        <form id="contactForm" class="contact-form reveal" method="post" action="send.php" data-whatsapp="<?= htmlspecialchars($whatsappDigits) ?>">
          <label for="nombre">Nombre</label>
          <input id="nombre" name="nombre" type="text" placeholder="Tu nombre" required>

          <label for="email">Correo</label>
          <input id="email" name="email" type="email" placeholder="tunombre@email.com" required>

          <label for="servicio">Servicio de interés</label>
          <select id="servicio" name="servicio" required>
            <option value="">Selecciona una opción</option>
            <?php foreach ($services as $service): ?>
              <option value="<?= htmlspecialchars($service["title"]) ?>"><?= htmlspecialchars($service["title"]) ?></option>
            <?php endforeach; ?>
          </select>

          <label for="mensaje">Mensaje</label>
          <textarea id="mensaje" name="mensaje" rows="4" placeholder="Cuéntame cómo te puedo ayudar" required></textarea>

          <div class="contact-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-envelope-open-text"></i> Enviar por correo</button>
            <button
              type="button"
              id="contactWhatsappBtn"
              class="btn btn-whatsapp<?= $whatsappDigits === "" ? " btn-whatsapp-disabled" : "" ?>"
              <?= $whatsappDigits === "" ? 'disabled aria-disabled="true" title="Configura el número en Administración → Configuración general → WhatsApp de contacto"' : "" ?>
            ><i class="fa-brands fa-whatsapp"></i> Escribir por WhatsApp</button>
          </div>
          <?php if ($whatsappDigits === ""): ?>
            <p class="contact-whatsapp-hint">Activa el botón de WhatsApp guardando tu número (solo dígitos, con código de país) en el panel de administración.</p>
          <?php endif; ?>
          <p id="formMessage" class="form-message" role="status" aria-live="polite"></p>
        </form>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p>© <span id="year"></span> <?= htmlspecialchars($settings["person_name"]) ?>. <?= htmlspecialchars($settings["footer_text"]) ?></p>
    </div>
  </footer>

  <script src="script.js?v=<?= htmlspecialchars($scriptVersion) ?>"></script>
</body>
</html>
