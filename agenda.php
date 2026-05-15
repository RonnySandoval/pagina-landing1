<?php
declare(strict_types=1);

require __DIR__ . "/db.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/agenda_public_bootstrap.php";

client_session_start();

if (!app_feature_enabled("expert_agenda")) {
    header("Location: " . app_landing_url());
    exit;
}

$agendaFlash = null;
if (isset($_SESSION["agenda_flash"]) && is_array($_SESSION["agenda_flash"])) {
    $rawAgendaFlash = $_SESSION["agenda_flash"];
    unset($_SESSION["agenda_flash"]);
    $agm = trim((string)($rawAgendaFlash["msg"] ?? ""));
    if ($agm !== "") {
        $agt = (string)($rawAgendaFlash["type"] ?? "info");
        if (!in_array($agt, ["success", "danger", "warning", "info"], true)) {
            $agt = "info";
        }
        $agendaFlash = ["type" => $agt, "msg" => $agm];
    }
}

$clientUser = null;
if (client_portal_resume_session($conn)) {
    $clientUser = [
        "id" => (int)($_SESSION["client_id"] ?? 0),
        "email" => (string)($_SESSION["client_email"] ?? ""),
        "display_name" => trim((string)($_SESSION["client_display_name"] ?? "")),
    ];
}

$defaultSettings = [
    "person_name" => "",
    "brand_name" => "Sitio",
    "logo_image_path" => "",
];
$settings = $defaultSettings;
$settingsQuery = $conn->query("SELECT person_name, brand_name, logo_image_path FROM site_settings WHERE id = 1 LIMIT 1");
if ($settingsQuery && $settingsQuery->num_rows === 1) {
    $settings = array_merge($settings, $settingsQuery->fetch_assoc());
}

$agendaState = agenda_public_load_state($conn, null, true);
$publicExpertAgenda = $agendaState["publicExpertAgenda"];
$agendaBookableServices = $agendaState["agendaBookableServices"];
$agendaSlots = $agendaState["agendaSlots"];
$agendaSlotTable = $agendaState["agendaSlotTable"];
$agendaSelectedServiceId = $agendaState["agendaSelectedServiceId"];
$agendaSelectedDate = $agendaState["agendaSelectedDate"];
$agendaMinDate = $agendaState["agendaMinDate"];
$agendaMaxDate = $agendaState["agendaMaxDate"];
$agendaWeekdayLabels = $agendaState["agendaWeekdayLabels"];
$agendaShowExpertNames = $agendaState["agendaShowExpertNames"];
$agendaSelectedServiceTitle = $agendaState["agendaSelectedServiceTitle"];

$stylesVersion = (string)(@filemtime(__DIR__ . "/styles.css") ?: time());
$scriptVersion = (string)(@filemtime(__DIR__ . "/script.js") ?: time());

if (!function_exists("brand_monogram")) {
    function brand_monogram(string $brand): string
    {
        $brand = trim($brand);
        if ($brand === "") {
            return "?";
        }
        $parts = preg_split('/\s+/u', $brand) ?: [];
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1, "UTF-8") . mb_substr($parts[1], 0, 1, "UTF-8"), "UTF-8");
        }
        return mb_strtoupper(mb_substr($brand, 0, 2, "UTF-8"), "UTF-8");
    }
}
?>
<!doctype html>
<html lang="es" data-theme-context="site">
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
  <title>Agenda y citas | <?= htmlspecialchars((string)$settings["brand_name"]) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="styles.css?v=<?= htmlspecialchars($stylesVersion) ?>">
</head>
<body>
  <header class="site-header">
    <div class="container nav">
      <a href="<?= htmlspecialchars(app_landing_url()) ?>" class="brand">
        <?php $logoPath = (string)($settings["logo_image_path"] ?? ""); ?>
        <?php if ($logoPath !== ""): ?>
          <img class="brand-logo brand-logo-img" src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars((string)$settings["brand_name"]) ?>">
        <?php else: ?>
          <span class="brand-logo brand-logo-monogram" aria-hidden="true"><?= htmlspecialchars(brand_monogram((string)$settings["brand_name"])) ?></span>
        <?php endif; ?>
        <span class="brand-name"><?= htmlspecialchars((string)$settings["brand_name"]) ?></span>
      </a>
      <button class="menu-toggle" id="menuToggle" aria-expanded="false" aria-label="Abrir menú">
        <span></span><span></span><span></span>
      </button>
      <nav class="main-nav" id="mainNav">
        <a href="<?= htmlspecialchars(app_landing_url()) ?>"><i class="fa-solid fa-house"></i> Inicio</a>
        <a href="agenda.php" aria-current="page"><i class="fa-solid fa-calendar-check"></i> Citas</a>
        <a href="<?= htmlspecialchars(app_landing_url()) ?>#contacto"><i class="fa-solid fa-envelope"></i> Contacto</a>
      </nav>
      <div class="theme-controls">
        <?php require __DIR__ . "/palette_picker.php"; ?>
      </div>
    </div>
  </header>

  <main>
    <?php $agendaSectionCompact = false; require __DIR__ . "/partials/agenda_public_section.php"; ?>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p class="mb-0"><a href="<?= htmlspecialchars(app_landing_url()) ?>">Volver a la web</a></p>
    </div>
  </footer>

  <script src="script.js?v=<?= htmlspecialchars($scriptVersion) ?>"></script>
</body>
</html>
