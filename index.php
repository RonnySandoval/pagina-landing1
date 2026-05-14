<?php
declare(strict_types=1);

// Punto de entrada de la landing. Patrones de URL local/servidor: ver app_urls.php (mapa al inicio del archivo).
require __DIR__ . "/db.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/app_urls.php";

client_session_start();

if (isset($_GET["client_verify"])) {
    $vTok = trim((string)($_GET["client_verify"] ?? ""));
    if ($vTok !== "") {
        $vErr = client_try_register_confirm_token($conn, $vTok);
        if ($vErr !== null) {
            client_set_flash("danger", $vErr);
            header("Location: " . app_public_base_url() . "/index.php?client_tab=register#area-cliente");
        } else {
            client_set_flash("success", "Correo verificado. Tu cuenta ya está activa.");
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
        }
        exit;
    }
}

if (isset($_GET["client_logout"])) {
    client_session_destroy();
    header("Location: " . app_public_base_url() . "/index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postAction = (string)($_POST["action"] ?? "");
    if ($postAction === "client_login") {
        $err = client_try_login($conn, (string)($_POST["email"] ?? ""), (string)($_POST["password"] ?? ""));
        if ($err !== null) {
            client_set_flash("danger", $err);
            header("Location: " . app_public_base_url() . "/index.php?client_tab=login#area-cliente");
        } else {
            client_set_flash("success", "Sesión iniciada. Ya puedes usar las ventajas de tu cuenta en esta misma página.");
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
        }
        exit;
    }
    if ($postAction === "client_register") {
        $reg = client_try_register(
            $conn,
            (string)($_POST["reg_email"] ?? ""),
            (string)($_POST["reg_password"] ?? ""),
            (string)($_POST["reg_password_confirm"] ?? ""),
            (string)($_POST["reg_display_name"] ?? "")
        );
        if (!empty($reg["ok"])) {
            $em = htmlspecialchars((string)($reg["email"] ?? ""));
            client_set_flash(
                "warning",
                "Mensaje enviado a {$em}. Abre el enlace del correo (48 h) o, si no llega, usa las opciones debajo."
            );
            header("Location: " . app_public_base_url() . "/index.php?client_tab=register#area-cliente");
        } elseif (!empty($reg["need_email_choice"])) {
            client_set_flash(
                "warning",
                "No se pudo enviar el correo de confirmación. Puedes crear la cuenta solo en la web o probar otro correo (debajo)."
            );
            header("Location: " . app_public_base_url() . "/index.php?client_tab=register#area-cliente");
        } else {
            client_set_flash("danger", (string)($reg["error"] ?? "No se pudo registrar."));
            header("Location: " . app_public_base_url() . "/index.php?client_tab=register#area-cliente");
        }
        exit;
    }
    if ($postAction === "client_register_no_mail") {
        $err = client_try_register_finalize_no_mail($conn);
        if ($err !== null) {
            client_set_flash("danger", $err);
            header("Location: " . app_public_base_url() . "/index.php?client_tab=register#area-cliente");
        } else {
            client_set_flash(
                "success",
                "Cuenta activa. Sin avisos por correo desde el sitio; el historial queda aquí."
            );
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
        }
        exit;
    }
    if ($postAction === "client_register_retry_email") {
        client_register_retry_clear($conn);
        client_set_flash("info", "Vuelve a rellenar el registro con otro correo.");
        header("Location: " . app_public_base_url() . "/index.php?client_tab=register#area-cliente");
        exit;
    }
    if (
        $postAction === "client_mark_message_read"
        || $postAction === "client_mark_message_unread"
    ) {
        $isAjax = isset($_POST["ajax"]) && (string)($_POST["ajax"] ?? "") === "1";
        $messageId = (int)($_POST["message_id"] ?? 0);
        if (!app_feature_enabled("client_inbox")) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "feature_off"]);
                exit;
            }
            client_set_flash("danger", "Esta función no está activada en esta web.");
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        if (!client_portal_resume_session($conn)) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "no_session"]);
                exit;
            }
            client_set_flash("danger", "Inicia sesión para gestionar el estado de los mensajes.");
            header("Location: " . app_public_base_url() . "/index.php?client_tab=login#area-cliente");
            exit;
        }
        $sessClientId = (int)($_SESSION["client_id"] ?? 0);
        $sessEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
        if ($messageId <= 0 || !client_contact_message_owned_by($conn, $sessClientId, $sessEmailNorm, $messageId)) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "not_found"]);
                exit;
            }
            client_set_flash("danger", "Ese mensaje no está en tu cuenta.");
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        $wantRead = $postAction === "client_mark_message_read";
        $readVal = $wantRead ? 1 : 0;
        $stmt = $conn->prepare("UPDATE contact_messages SET is_read = ? WHERE id = ?");
        if ($stmt === false) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "prepare"]);
                exit;
            }
            client_set_flash("danger", "No se pudo actualizar el mensaje.");
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        $stmt->bind_param("ii", $readVal, $messageId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($isAjax) {
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode(["ok" => (bool)$ok, "id" => $messageId]);
            exit;
        }
        client_set_flash("success", $wantRead ? "Marcado como leído." : "Marcado como no leído.");
        header("Location: " . app_public_base_url() . "/index.php#area-cliente");
        exit;
    }
}

$clientFlash = client_take_flash();
$clientContactStatus = (string)($_GET["client_contact"] ?? "");
$clientContactReason = (string)($_GET["reason"] ?? "");
$clientUser = null;
if (client_portal_resume_session($conn)) {
    $clientUser = [
        "id" => (int)($_SESSION["client_id"] ?? 0),
        "email" => (string)($_SESSION["client_email"] ?? ""),
        "display_name" => trim((string)($_SESSION["client_display_name"] ?? "")),
    ];
}

$clientTab = (string)($_GET["client_tab"] ?? "");
if ($clientTab !== "login" && $clientTab !== "register") {
    $clientTab = "";
}

$clientRegisterPending = $clientUser === null ? client_register_pending_get() : null;

/** La sección usa .reveal (opacity 0 hasta JS); forzar .show si hay sesión o flujos de cliente para no dejar el inbox “invisible”. */
$areaClienteRevealShow = $clientUser !== null || $clientTab !== "" || $clientRegisterPending !== null;

$clientPrefillNombre = "";
$clientPrefillEmail = "";
$clientMyMessages = [];
$clientRepliesByMessageId = [];
if ($clientUser !== null) {
    $clientPrefillNombre = $clientUser["display_name"] !== ""
        ? $clientUser["display_name"]
        : (preg_match('/^([^@]+)/u', $clientUser["email"], $m) ? $m[1] : "");
    $clientPrefillEmail = $clientUser["email"];

    if (app_feature_enabled("client_inbox")) {
        $clientIdForQuery = (int)$clientUser["id"];
        $clientEmailNorm = strtolower(trim($clientUser["email"]));
        $stmt = $conn->prepare("
        SELECT id, nombre, servicio, subject, mensaje, created_at, in_reply_to, is_read,
               (SELECT COUNT(*) FROM contact_message_replies r WHERE r.contact_message_id = m.id) AS reply_count
        FROM contact_messages m
        WHERE m.client_id = ? OR (m.client_id IS NULL AND LOWER(TRIM(m.email)) = ?)
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 40
    ");
        if ($stmt !== false) {
            $stmt->bind_param("is", $clientIdForQuery, $clientEmailNorm);
            $stmt->execute();
            $cmRes = $stmt->get_result();
            if ($cmRes) {
                while ($cmRow = $cmRes->fetch_assoc()) {
                    $clientMyMessages[] = $cmRow;
                }
            }
            $stmt->close();
        }
        $msgIds = [];
        foreach ($clientMyMessages as $cm) {
            $mid = (int)($cm["id"] ?? 0);
            if ($mid > 0) {
                $msgIds[$mid] = true;
            }
        }
        if (count($msgIds) > 0) {
            $inList = implode(",", array_keys($msgIds));
            $repQ = $conn->query(
                "SELECT id, contact_message_id, body, created_at FROM contact_message_replies WHERE contact_message_id IN ($inList) ORDER BY created_at ASC, id ASC"
            );
            if ($repQ) {
                while ($rp = $repQ->fetch_assoc()) {
                    $mid = (int)$rp["contact_message_id"];
                    if (!isset($clientRepliesByMessageId[$mid])) {
                        $clientRepliesByMessageId[$mid] = [];
                    }
                    $clientRepliesByMessageId[$mid][] = $rp;
                }
            }
        }
    }
}

$clientMsgMetaById = [];
foreach ($clientMyMessages as $cmRow) {
    $mid = (int)($cmRow["id"] ?? 0);
    if ($mid > 0) {
        $clientMsgMetaById[$mid] = $cmRow;
    }
}

$clientThreads = [];
if ($clientUser !== null && count($clientMyMessages) > 0) {
    $clientThreads = index_client_group_messages_threads($clientMyMessages, $clientRepliesByMessageId);
}

$landingContactErrorLabels = [
    "nombre" => "Por favor escribe tu nombre.",
    "email_vacio" => "Por favor escribe tu correo.",
    "email_invalido" => "El correo no es válido. Asegúrate de incluir “@” y un dominio (por ejemplo: tunombre@email.com).",
    "servicio" => "Selecciona un servicio en el desplegable.",
    "asunto" => "Escribe un título o asunto en el campo de texto (no es el servicio: elige el servicio en su desplegable).",
    "mensaje" => "Escribe el mensaje que quieres enviar.",
    "sesion_seguimiento" => "Para enviar un seguimiento necesitas tener la sesión iniciada en esta página.",
    "seguimiento_invalido" => "Ese mensaje no está en tu historial o ya no está disponible. Actualiza la página e inténtalo de nuevo.",
    "client_inbox_disabled" => "En esta web el contacto por mensajes desde tu cuenta no está activo. Usa el formulario de contacto al pie de la página.",
];

$defaultSettings = [
    "person_name" => "Tu Nombre",
    "brand_name" => "Tu Marca",
    "hero_title" => "Describe aquí tu propuesta principal de valor.",
    "hero_intro" => "Agrega una breve introducción para tu portada.",
    "about_text" => "Escribe una descripción corta sobre ti y tus servicios.",
    "contact_intro" => "Invita a tus visitantes a contactarte para más información.",
    "contact_email" => "contacto@tu-dominio.com",
    "contact_whatsapp" => "",
    "contact_whatsapp_country_code" => null,
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

/** Fecha legible para el historial de mensajes del cliente. */
function index_format_datetime(string $sqlDatetime): string
{
    try {
        $dt = new DateTimeImmutable($sqlDatetime);
        return $dt->format("d/m/Y H:i");
    } catch (Throwable $e) {
        return $sqlDatetime;
    }
}

/**
 * Agrupa mensajes en hilos según in_reply_to (un bloque = una conversación con el sitio).
 *
 * @param array<int, list<array>> $repliesByMessageId
 * @return list<array{root_id:int, messages:list<array>, latest_ts:int, has_admin_reply:bool}>
 */
function index_client_group_messages_threads(array $messages, array $repliesByMessageId): array
{
    $byId = [];
    foreach ($messages as $m) {
        $id = (int)($m["id"] ?? 0);
        if ($id > 0) {
            $byId[$id] = $m;
        }
    }
    $buckets = [];
    foreach ($messages as $m) {
        $mid = (int)($m["id"] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        $root = $mid;
        $p = (int)($m["in_reply_to"] ?? 0);
        $guard = 0;
        while ($p > 0 && isset($byId[$p]) && $guard++ < 64) {
            $root = $p;
            $p = (int)($byId[$p]["in_reply_to"] ?? 0);
        }
        if (!isset($buckets[$root])) {
            $buckets[$root] = [];
        }
        $buckets[$root][] = $m;
    }
    $threads = [];
    foreach ($buckets as $rootId => $rows) {
        usort($rows, static function (array $a, array $b): int {
            $ta = strtotime((string)($a["created_at"] ?? "")) ?: 0;
            $tb = strtotime((string)($b["created_at"] ?? "")) ?: 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int)($a["id"] ?? 0)) <=> ((int)($b["id"] ?? 0));
        });
        $latestTs = 0;
        foreach ($rows as $r) {
            $t = strtotime((string)($r["created_at"] ?? "")) ?: 0;
            if ($t > $latestTs) {
                $latestTs = $t;
            }
        }
        $hasAdmin = false;
        foreach ($rows as $r) {
            $rid = (int)($r["id"] ?? 0);
            if ($rid > 0 && !empty($repliesByMessageId[$rid])) {
                $hasAdmin = true;
                break;
            }
        }
        $threads[] = [
            "root_id" => (int)$rootId,
            "messages" => $rows,
            "latest_ts" => $latestTs,
            "has_admin_reply" => $hasAdmin,
        ];
    }
    usort($threads, static function (array $a, array $b): int {
        return ($b["latest_ts"] ?? 0) <=> ($a["latest_ts"] ?? 0);
    });

    return $threads;
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
  SELECT sg.id, sg.service_id, sg.image_path, sg.caption, sg.image_title, sg.image_description
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
        <?php if ($clientUser !== null): ?>
          <a href="#area-cliente" class="nav-client-active"><i class="fa-solid fa-user-check"></i> Mi cuenta</a>
          <a href="index.php?client_logout=1"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
        <?php else: ?>
          <a href="#area-cliente"><i class="fa-solid fa-circle-user"></i> Clientes</a>
        <?php endif; ?>
      </nav>
      <div class="theme-controls">
        <?php require __DIR__ . "/palette_picker.php"; ?>
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

    <section id="area-cliente" class="section section-client reveal<?= $areaClienteRevealShow ? " show" : "" ?>">
      <div class="container">
        <h2><i class="fa-solid fa-circle-user"></i> Área de clientes</h2>
        <?php if ($clientFlash["msg"] !== ""): ?>
          <p class="<?php
            $ft = (string)$clientFlash["type"];
            echo ($ft === "success" || $ft === "info")
                ? "form-ok"
                : ($ft === "warning" ? "form-warn" : "form-error");
            ?> client-portal-flash client-portal-flash--compact" role="alert">
            <?= htmlspecialchars($clientFlash["msg"]) ?>
          </p>
        <?php endif; ?>

        <?php if ($clientUser !== null && $clientContactStatus !== ""): ?>
          <?php
            $showClientContactBanner = $clientContactStatus === "error"
                || ($clientContactStatus !== "error" && app_feature_enabled("client_inbox"));
          ?>
          <?php if ($showClientContactBanner): ?>
          <?php if ($clientContactStatus === "ok"): ?>
            <p class="form-ok client-portal-flash" role="status">Seguimiento enviado. Aparece en tu historial y en el panel del sitio.</p>
          <?php elseif ($clientContactStatus === "saved"): ?>
            <p class="form-ok client-portal-flash" role="status">Tu mensaje quedó guardado; el aviso por correo al sitio puede no haberse enviado (revisa la configuración SMTP).</p>
          <?php else: ?>
            <p class="form-error client-portal-flash" role="alert">
              <?= htmlspecialchars($landingContactErrorLabels[$clientContactReason] ?? "No se pudo enviar el seguimiento. Revisa los datos e inténtalo de nuevo.") ?>
            </p>
          <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($clientUser !== null && !app_feature_enabled("client_inbox")): ?>
          <p class="client-auth-guest-hint mb-4">Bandeja de mensajes desactivada en esta web. Para contactar, usa el <a href="#contacto">formulario al pie</a>.</p>
        <?php elseif ($clientUser !== null): ?>
          <p class="lead mb-4 client-zone-intro">
            Tu historial de contacto con esta web se guarda aquí. El correo sirve sobre todo para el primer aviso o notificaciones; lo que quede registrado en detalle es esta bandeja.
          </p>
          <div class="client-auth-box client-perks mb-4">
              <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Cómo funciona</h3>
              <ul class="mb-0">
                <li><strong>Nueva consulta:</strong> <strong>Servicio</strong> y <strong>asunto</strong> (obligatorio) en la misma fila, luego el mensaje; o el formulario al pie con el mismo criterio.</li>
                <li><strong>Conversaciones:</strong> cada bloque es un tema con su <strong>ID de conversación</strong> (Conv.); dentro, los envíos van en orden de tiempo y cada uno muestra su <strong>ID de mensaje</strong> (Msg.). Al final del hilo puedes enviar un seguimiento.</li>
              </ul>
          </div>

          <div class="client-messages-panel client-auth-box">
            <h3><i class="fa-solid fa-inbox"></i> Mis mensajes</h3>

            <div class="client-msg-new-inquiry">
              <p class="client-msg-label">Nueva consulta</p>
              <p class="client-muted small mb-2">Nueva conversación: <strong>servicio</strong> y <strong>asunto</strong> (obligatorio) en la misma fila, luego el mensaje.</p>
              <form method="post" action="send.php" class="client-msg-new-inquiry-form contact-form">
                <input type="hidden" name="return_anchor" value="area-cliente">
                <input type="hidden" name="nombre" value="<?= htmlspecialchars($clientPrefillNombre) ?>">
                <input type="hidden" name="email" value="<?= htmlspecialchars($clientPrefillEmail) ?>">
                <div class="form-servicio-asunto-row">
                  <div class="form-servicio-asunto-cell">
                    <label for="client_new_servicio">Servicio de interés</label>
                    <select id="client_new_servicio" name="servicio" required>
                      <option value="">Selecciona una opción</option>
                      <?php foreach ($services as $service): ?>
                        <option value="<?= htmlspecialchars($service["title"]) ?>"><?= htmlspecialchars($service["title"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-servicio-asunto-cell">
                    <label for="client_new_asunto">Asunto o título <span class="contact-required-hint">(obligatorio)</span></label>
                    <input id="client_new_asunto" name="asunto" type="text" maxlength="200" required minlength="1" placeholder="Título breve (texto)" autocomplete="off">
                  </div>
                </div>
                <p class="client-muted small mb-0 mt-1">Servicio: lista de la web. Asunto: frase propia para localizar el hilo (no sustituye al servicio).</p>
                <label for="client_new_mensaje" class="mt-3">Mensaje</label>
                <textarea id="client_new_mensaje" name="mensaje" rows="4" required placeholder="Describe tu consulta"></textarea>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Enviar consulta</button>
              </form>
            </div>

            <?php if (count($clientMyMessages) === 0): ?>
              <p class="client-muted small mb-0 mt-3">Aún no hay mensajes anteriores en tu historial.</p>
            <?php else: ?>
              <p class="client-muted small mb-3 mt-3">
                <?= count($clientThreads) ?> conversación(es), <?= count($clientMyMessages) ?> mensaje(s) en total. Abre un bloque para ver el hilo y responder. Puedes marcar cada envío como leído o no leído para organizarte.
              </p>
              <div id="clientInboxMessagesRoot" class="client-msg-list">
                <?php foreach ($clientThreads as $ti => $thread): ?>
                  <?php
                    $tMsgs = $thread["messages"] ?? [];
                    $rootRow = $tMsgs[0] ?? [];
                    $rootServ = (string)($rootRow["servicio"] ?? "");
                    $rootSubject = trim((string)($rootRow["subject"] ?? ""));
                    $rootSubjectDisp = $rootSubject !== "" ? $rootSubject : "Sin asunto";
                    $rootStart = (string)($rootRow["created_at"] ?? "");
                    $tCount = count($tMsgs);
                    $tLatest = (int)($thread["latest_ts"] ?? 0);
                    $tLatestLabel = $tLatest > 0 ? index_format_datetime(date("Y-m-d H:i:s", $tLatest)) : "";
                    $threadUnread = 0;
                    foreach ($tMsgs as $tmU) {
                        if ((int)($tmU["is_read"] ?? 0) === 0) {
                            $threadUnread++;
                        }
                    }
                    $threadConvId = (int)($thread["root_id"] ?? 0);
                  ?>
                  <details class="client-msg-thread client-msg-conv-root"<?= $ti === 0 ? " open" : "" ?> data-thread-id="<?= $threadConvId ?>">
                    <summary>
                      <span class="client-msg-summary-main">
                        <?php if ($threadConvId > 0): ?>
                          <span class="client-msg-thread-id" title="Identificador de esta conversación (asunto/hilo); servirá para buscar o filtrar">Conv. <?= $threadConvId ?></span>
                        <?php endif; ?>
                        <span class="client-msg-thread-subject<?= $rootSubject === "" ? " client-msg-thread-subject--empty" : "" ?>" title="Asunto (título); el servicio se muestra aparte"><?= htmlspecialchars($rootSubjectDisp) ?></span>
                        <span class="client-msg-date"><?= htmlspecialchars(index_format_datetime($rootStart)) ?></span>
                        <span class="client-msg-service"><i class="fa-solid fa-briefcase" aria-hidden="true"></i> <?= htmlspecialchars($rootServ !== "" ? $rootServ : "—") ?></span>
                        <?php if ($tCount > 1): ?>
                          <span class="client-msg-conv-meta"><?= (int)$tCount ?> envíos</span>
                        <?php endif; ?>
                        <?php if ($tLatestLabel !== "" && $tCount > 1): ?>
                          <span class="client-msg-conv-meta">Última act.: <?= htmlspecialchars($tLatestLabel) ?></span>
                        <?php endif; ?>
                      </span>
                      <span class="client-msg-summary-badges">
                        <?php if ($threadUnread > 0): ?>
                          <span class="client-msg-badge client-msg-badge-unread" title="Hay envíos sin leer en este hilo"><?= (int)$threadUnread ?> sin leer</span>
                        <?php endif; ?>
                        <?php if (!empty($thread["has_admin_reply"])): ?>
                          <span class="client-msg-badge" title="Hay respuesta del sitio en este hilo">Respuesta</span>
                        <?php endif; ?>
                      </span>
                    </summary>
                    <div class="client-msg-detail client-msg-conv-body">
                      <?php foreach ($tMsgs as $mi => $cm): ?>
                        <?php
                          $cmId = (int)($cm["id"] ?? 0);
                          $cmServicio = (string)($cm["servicio"] ?? "");
                          $cmSubject = trim((string)($cm["subject"] ?? ""));
                          $cmMensaje = (string)($cm["mensaje"] ?? "");
                          $cmCreated = (string)($cm["created_at"] ?? "");
                          $cmReplies = $clientRepliesByMessageId[$cmId] ?? [];
                          $cmReplyCount = (int)($cm["reply_count"] ?? 0);
                          $cmInReplyTo = isset($cm["in_reply_to"]) ? (int)$cm["in_reply_to"] : 0;
                          $isRootTurn = $cmInReplyTo <= 0 || !isset($clientMsgMetaById[$cmInReplyTo]);
                          $isCmUnread = (int)($cm["is_read"] ?? 0) === 0;
                        ?>
                        <div class="client-msg-turn client-msg-own-row<?= $isCmUnread ? " is-unread" : "" ?>" data-message-id="<?= $cmId ?>">
                          <div class="client-msg-turn-main">
                          <div class="client-msg-turn-head">
                            <?php if ($cmId > 0): ?>
                              <span class="client-msg-id-chip" title="ID del mensaje; servirá para buscar o filtrar">Msg. <?= $cmId ?></span>
                            <?php endif; ?>
                            <span class="client-msg-turn-date"><?= htmlspecialchars(index_format_datetime($cmCreated)) ?></span>
                            <?php if (!$isRootTurn): ?>
                              <span class="client-msg-badge client-msg-badge-muted">Seguimiento</span>
                            <?php endif; ?>
                            <?php if ($cmReplyCount > 0): ?>
                              <span class="client-msg-badge">Respuesta</span>
                            <?php endif; ?>
                            <?php if ($tCount > 1 && $cmServicio !== ""): ?>
                              <span class="client-msg-turn-svc"><?= htmlspecialchars($cmServicio) ?></span>
                            <?php endif; ?>
                          </div>
                          <div class="client-msg-turn-body">
                            <div class="client-msg-bubble client-msg-bubble--you">
                            <?php if ($cmSubject !== "" && $isRootTurn): ?>
                              <p class="client-msg-subject-line"><span class="client-msg-label">Asunto</span> <?= htmlspecialchars($cmSubject) ?></p>
                            <?php endif; ?>
                            <p class="client-msg-bubble-label"><i class="fa-solid fa-user" aria-hidden="true"></i> Tu mensaje</p>
                            <div class="client-msg-body"><?= nl2br(htmlspecialchars($cmMensaje)) ?></div>
                            </div>
                            <div class="client-msg-bubble client-msg-bubble--site">
                            <p class="client-msg-bubble-label"><i class="fa-solid fa-building" aria-hidden="true"></i> Respuesta del sitio</p>
                            <?php if (count($cmReplies) > 0): ?>
                              <div class="client-msg-replies">
                                <?php foreach ($cmReplies as $rep): ?>
                                  <div class="client-msg-reply">
                                    <span class="client-msg-reply-meta"><?= htmlspecialchars(index_format_datetime((string)($rep["created_at"] ?? ""))) ?></span>
                                    <div class="client-msg-reply-body"><?= nl2br(htmlspecialchars((string)($rep["body"] ?? ""))) ?></div>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php else: ?>
                              <p class="client-muted small mb-0">Aún no hay respuesta a este envío.</p>
                            <?php endif; ?>
                            </div>
                            <div class="client-msg-read-actions" role="group" aria-label="Estado de lectura de este envío">
                              <span class="client-msg-badge client-msg-badge-new js-client-msg-new-badge">Nuevo</span>
                              <form method="post" class="client-msg-mark-form js-client-mark-read-form">
                                <input type="hidden" name="action" value="client_mark_message_read">
                                <input type="hidden" name="message_id" value="<?= $cmId ?>">
                                <button type="submit" class="btn btn-ghost btn-msg-read-state" title="Marcar como leído" aria-label="Marcar como leído">
                                  <i class="fa-solid fa-check" aria-hidden="true"></i>
                                </button>
                              </form>
                              <form method="post" class="client-msg-mark-form js-client-mark-unread-form">
                                <input type="hidden" name="action" value="client_mark_message_unread">
                                <input type="hidden" name="message_id" value="<?= $cmId ?>">
                                <button type="submit" class="btn btn-ghost btn-msg-read-state" title="Marcar como no leído" aria-label="Marcar como no leído">
                                  <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                </button>
                              </form>
                            </div>
                          </div>
                        </div>
                        </div>
                      <?php endforeach; ?>
                      <?php
                        $threadRootId = (int)($thread["root_id"] ?? 0);
                        $nTm = count($tMsgs);
                        $lastCm = $nTm > 0 ? $tMsgs[$nTm - 1] : [];
                        $lastCmId = (int)($lastCm["id"] ?? 0);
                        $lastNombre = trim((string)($lastCm["nombre"] ?? "")) !== "" ? trim((string)$lastCm["nombre"]) : $clientPrefillNombre;
                        $lastServicio = trim((string)($lastCm["servicio"] ?? ""));
                        if ($lastServicio === "" && $nTm > 0) {
                            $lastServicio = trim((string)($tMsgs[0]["servicio"] ?? ""));
                        }
                      ?>
                      <?php if ($lastCmId > 0): ?>
                        <div class="client-msg-followup client-msg-followup-thread-end">
                          <p class="client-msg-bubble-label"><i class="fa-solid fa-reply" aria-hidden="true"></i> Siguiente mensaje en este hilo</p>
                          <p class="client-muted small mb-2">Un solo envío al final del hilo: queda enlazado al <strong>último mensaje</strong> (Msg. <?= (int)$lastCmId ?>).</p>
                          <form method="post" action="send.php" class="client-msg-followup-form">
                            <input type="hidden" name="return_anchor" value="area-cliente">
                            <input type="hidden" name="in_reply_to" value="<?= $lastCmId ?>">
                            <input type="hidden" name="nombre" value="<?= htmlspecialchars($lastNombre) ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($clientPrefillEmail) ?>">
                            <input type="hidden" name="servicio" value="<?= htmlspecialchars($lastServicio) ?>">
                            <label class="theme-sr-only" for="followup_thread_<?= $threadRootId ?>">Texto del seguimiento</label>
                            <textarea id="followup_thread_<?= $threadRootId ?>" name="mensaje" rows="3" required placeholder="Escribe aquí tu siguiente mensaje en esta conversación"></textarea>
                            <button type="submit" class="btn btn-ghost">Enviar seguimiento</button>
                          </form>
                        </div>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endforeach; ?>
              </div>
              <p class="client-muted small mt-3 mb-0">También puedes usar el <a href="#contacto">formulario de contacto</a> al pie de la página si lo prefieres.</p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="client-auth-guest-hint">
            <?php if (app_feature_enabled("client_inbox")): ?>
            <a href="#client-login-card">Entrar</a> o <a href="#client-register-card">registrarse</a> con el mismo correo que uses al contactar para ver el historial aquí. Registro: enlace de confirmación al correo (48 h).
            <?php else: ?>
            <a href="#client-login-card">Entrar</a> o <a href="#client-register-card">registrarse</a> con el mismo correo (confirmación por enlace). El contacto con el sitio es por el <a href="#contacto">formulario al pie</a>.
            <?php endif; ?>
          </p>
          <div class="client-auth-grid">
            <?php if ($clientRegisterPending !== null): ?>
              <?php
                $pendEmail = htmlspecialchars((string)($clientRegisterPending["email"] ?? ""));
                $pendName = htmlspecialchars((string)($clientRegisterPending["display_name"] ?? ""));
                $pendMailSent = !empty($clientRegisterPending["verification_sent"]);
              ?>
              <div class="client-auth-box client-card-highlight client-reg-pending-card" id="client-register-pending-card" style="grid-column: 1 / -1;">
                <?php if ($pendMailSent): ?>
                <h3><i class="fa-solid fa-envelope"></i> Confirma el correo</h3>
                <p class="client-reg-pending-meta">
                  <?= $pendEmail ?><?php if ($pendName !== ""): ?> · <?= $pendName ?><?php endif; ?>
                </p>
                <p class="client-reg-pending-lead">Revisa bandeja y spam. La cuenta se activa al abrir el enlace (48 h).</p>
                <p class="client-reg-pending-hint">¿No llega? Puedes activar solo en la web o probar otro correo.</p>
                <?php else: ?>
                <h3><i class="fa-solid fa-envelope-circle-check"></i> Correo de confirmación no enviado</h3>
                <p class="client-reg-pending-meta">
                  <?= $pendEmail ?><?php if ($pendName !== ""): ?> · <?= $pendName ?><?php endif; ?>
                </p>
                <p class="client-reg-pending-lead">Aún no hay cuenta. Crea una sin correos del sitio o cambia el correo.</p>
                <?php endif; ?>
                <div class="client-reg-pending-actions">
                  <form method="post" class="m-0">
                    <input type="hidden" name="action" value="client_register_no_mail">
                    <button type="submit" class="btn btn-primary">Activar sin correos del sitio</button>
                  </form>
                  <form method="post" class="m-0">
                    <input type="hidden" name="action" value="client_register_retry_email">
                    <button type="submit" class="btn btn-ghost">Cambiar correo</button>
                  </form>
                </div>
              </div>
            <?php else: ?>
            <div class="client-auth-box <?= $clientTab === "register" ? "client-card-highlight" : "" ?>" id="client-register-card">
              <h3><i class="fa-solid fa-user-plus"></i> Crear cuenta</h3>
              <form method="post" class="contact-form client-auth-form">
                <input type="hidden" name="action" value="client_register">
                <label for="reg_email">Correo</label>
                <input id="reg_email" name="reg_email" type="email" required autocomplete="email">

                <label for="reg_display_name">Nombre para mostrar (opcional)</label>
                <input id="reg_display_name" name="reg_display_name" type="text" maxlength="180" autocomplete="name" placeholder="Cómo te saludamos">

                <label for="reg_password">Clave</label>
                <input id="reg_password" name="reg_password" type="password" required minlength="10" autocomplete="new-password" placeholder="Mín. 10 caracteres, A, a, 0">

                <label for="reg_password_confirm">Repetir clave</label>
                <input id="reg_password_confirm" name="reg_password_confirm" type="password" required minlength="10" autocomplete="new-password">

                <button type="submit" class="btn btn-primary">Registrarme</button>
              </form>
              </div>
            <?php endif; ?>
            <div class="client-auth-box <?= $clientTab === "login" ? "client-card-highlight" : "" ?>" id="client-login-card">
              <h3><i class="fa-solid fa-right-to-bracket"></i> Iniciar sesión</h3>
              <form method="post" class="contact-form client-auth-form">
                <input type="hidden" name="action" value="client_login">
                <label for="login_email">Correo</label>
                <input id="login_email" name="email" type="email" required autocomplete="username">

                <label for="login_password">Clave</label>
                <input id="login_password" name="password" type="password" required autocomplete="current-password">

                <button type="submit" class="btn btn-ghost">Entrar</button>
              </form>
              </div>
          </div>
        <?php endif; ?>
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

        <div id="serviceFocusHost" class="service-focus-host" hidden>
          <?php foreach ($services as $service): ?>
            <?php
              $fid = (int)$service["id"];
              $galleryItemsF = $serviceGallery[$fid] ?? [];
              $slidesF = [];
              foreach ($galleryItemsF as $gi) {
                  $t = trim((string)($gi["image_title"] ?? ""));
                  if ($t === "") {
                      $t = trim((string)($gi["caption"] ?? ""));
                  }
                  $slidesF[] = [
                      "image_path" => (string)$gi["image_path"],
                      "title" => $t,
                      "description" => trim((string)($gi["image_description"] ?? "")),
                  ];
              }
              $coverF = !empty($service["image_path"]) ? (string)$service["image_path"] : "";
            ?>
            <article id="service_focus_<?= $fid ?>" class="service-focus-article" data-service-id="<?= $fid ?>" hidden>
              <button type="button" class="btn btn-ghost service-focus-back js-service-focus-close">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Todos los servicios
              </button>
              <div class="service-focus-hero">
                <?php if ($coverF !== ""): ?>
                  <img src="<?= htmlspecialchars($coverF) ?>" alt="" class="service-focus-cover">
                <?php else: ?>
                  <div class="service-focus-cover service-focus-cover--placeholder">
                    <i class="<?= htmlspecialchars($service["icon_class"] ?: "fa-solid fa-star") ?>" aria-hidden="true"></i>
                  </div>
                <?php endif; ?>
                <div class="service-focus-hero-text">
                  <h3 class="service-focus-title"><i class="<?= htmlspecialchars($service["icon_class"]) ?>"></i> <?= htmlspecialchars($service["title"]) ?></h3>
                  <p class="service-focus-desc"><?= htmlspecialchars($service["description"]) ?></p>
                </div>
              </div>
              <?php if (count($slidesF) > 0): ?>
                <div class="service-focus-gal-list">
                  <?php foreach ($slidesF as $sf): ?>
                    <?php
                      $sumT = $sf["title"] !== "" ? $sf["title"] : "Imagen";
                      $ctaDetail = $sf["title"];
                      if ($sf["description"] !== "") {
                          $ctaDetail .= " — " . mb_substr($sf["description"], 0, 120, "UTF-8") . (mb_strlen($sf["description"], "UTF-8") > 120 ? "…" : "");
                      }
                    ?>
                    <details class="service-gal-item">
                      <summary class="service-gal-sum">
                        <img class="service-gal-sum-thumb" src="<?= htmlspecialchars($sf["image_path"]) ?>" alt="">
                        <span class="service-gal-sum-title"><?= htmlspecialchars($sumT) ?></span>
                      </summary>
                      <div class="service-gal-body">
                        <?php if ($sf["description"] !== ""): ?>
                          <p class="service-gal-desc"><?= nl2br(htmlspecialchars($sf["description"])) ?></p>
                        <?php endif; ?>
                        <img class="service-gal-body-img" src="<?= htmlspecialchars($sf["image_path"]) ?>" alt="<?= htmlspecialchars($sumT) ?>">
                        <button type="button" class="btn btn-primary js-service-cta" data-service="<?= htmlspecialchars($service["title"]) ?>" data-detail="<?= htmlspecialchars($ctaDetail) ?>">
                          Solicitar información
                        </button>
                      </div>
                    </details>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="cards" id="serviceCardsGrid">
          <?php foreach ($services as $service): ?>
            <?php
              $serviceId = (int)$service["id"];
              $galleryItems = $serviceGallery[$serviceId] ?? [];
              $slides = [];
              foreach ($galleryItems as $galleryItem) {
                  $stt = trim((string)($galleryItem["image_title"] ?? ""));
                  if ($stt === "") {
                      $stt = trim((string)($galleryItem["caption"] ?? ""));
                  }
                  $slides[] = [
                      "image_path" => (string)$galleryItem["image_path"],
                      "title" => $stt,
                      "description" => trim((string)($galleryItem["image_description"] ?? "")),
                  ];
              }
              $coverImage = !empty($service["image_path"]) ? (string)$service["image_path"] : "";
              $hasGallery = count($slides) > 0;
            ?>
            <article class="card reveal" data-service-card-id="<?= $serviceId ?>">
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
              <div class="service-card-actions">
                <?php if ($hasGallery): ?>
                  <button type="button" class="btn btn-ghost service-gallery-chevron-btn js-service-gallery-toggle" aria-expanded="false" aria-controls="service_gallery_inline_<?= $serviceId ?>" id="service_gallery_btn_<?= $serviceId ?>" aria-label="Mostrar u ocultar imágenes del servicio" title="Imágenes del servicio">
                    <i class="fa-solid fa-chevron-down service-gallery-chevron" aria-hidden="true"></i>
                  </button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary js-service-focus-open" data-focus-target="service_focus_<?= $serviceId ?>">
                  Ver más
                </button>
              </div>
              <?php if ($hasGallery): ?>
                <div id="service_gallery_inline_<?= $serviceId ?>" class="service-gallery-inline is-collapsed">
                  <?php foreach ($slides as $slide): ?>
                    <?php
                      $sumT = $slide["title"] !== "" ? $slide["title"] : "Imagen";
                      $ctaDetail = $slide["title"];
                      if ($slide["description"] !== "") {
                          $ctaDetail .= " — " . mb_substr($slide["description"], 0, 100, "UTF-8") . (mb_strlen($slide["description"], "UTF-8") > 100 ? "…" : "");
                      }
                    ?>
                    <details class="service-gal-item">
                      <summary class="service-gal-sum">
                        <img class="service-gal-sum-thumb" src="<?= htmlspecialchars($slide["image_path"]) ?>" alt="">
                        <span class="service-gal-sum-title"><?= htmlspecialchars($sumT) ?></span>
                      </summary>
                      <div class="service-gal-body">
                        <?php if ($slide["description"] !== ""): ?>
                          <p class="service-gal-desc"><?= nl2br(htmlspecialchars($slide["description"])) ?></p>
                        <?php endif; ?>
                        <img class="service-gal-body-img" src="<?= htmlspecialchars($slide["image_path"]) ?>" alt="<?= htmlspecialchars($sumT) ?>">
                        <button type="button" class="btn btn-ghost js-service-cta" data-service="<?= htmlspecialchars($service["title"]) ?>" data-detail="<?= htmlspecialchars($ctaDetail) ?>">
                          Solicitar información
                        </button>
                      </div>
                    </details>
                  <?php endforeach; ?>
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
          <div class="contact-flow-hint">
            <p><strong>Primera consulta:</strong> este formulario envía un aviso al correo del sitio. Es la forma habitual de empezar.</p>
            <?php if ($clientUser === null): ?>
              <?php if (app_feature_enabled("client_inbox")): ?>
              <p><strong>Seguimiento:</strong> para que las respuestas y tus envíos queden reunidos en un solo historial (sin depender solo del correo), te conviene <a href="#area-cliente">crear cuenta o iniciar sesión</a> con el mismo correo y seguir escribiendo desde el área de clientes.</p>
              <?php else: ?>
              <p><strong>Seguimiento:</strong> el sitio responde por correo; el contacto desde la web es con este formulario.</p>
              <?php endif; ?>
            <?php else: ?>
              <?php if (app_feature_enabled("client_inbox")): ?>
              <p><strong>Seguimiento:</strong> en <a href="#area-cliente">Mis mensajes</a> puedes enviar una <strong>nueva consulta</strong> desde el mismo panel o un <strong>seguimiento</strong> dentro de un hilo abierto.</p>
              <?php else: ?>
              <p><strong>Seguimiento:</strong> usa de nuevo este formulario o el correo.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php
            $status = $_GET["status"] ?? "";
            $reason = $_GET["reason"] ?? "";
          ?>
          <?php if ($status === "ok"): ?>
            <p class="form-ok">Mensaje enviado correctamente.</p>
          <?php elseif ($status === "saved"): ?>
            <p class="form-ok">Mensaje guardado. Revisa la configuración de correo del servidor.</p>
          <?php elseif ($status === "error"): ?>
            <p class="form-error">
              <?= htmlspecialchars($landingContactErrorLabels[$reason] ?? "No se pudo procesar el mensaje. Revisa los datos del formulario.") ?>
            </p>
          <?php endif; ?>
        </div>
        <?php
          $waFeatureOn = app_feature_enabled("contact_whatsapp");
          // wa.me solo acepta dígitos. Aunque el admin valida, sanitizamos aquí también
          // para no inyectar HTML/JS en el data-attribute y evitar prefijos como "+57".
          $whatsappLocalDigits = preg_replace('/\D+/', '', (string)($settings["contact_whatsapp"] ?? "")) ?? "";
          $whatsappCcDigits = preg_replace('/\D+/', '', (string)($settings["contact_whatsapp_country_code"] ?? "")) ?? "";
          $whatsappDigits = $waFeatureOn
            ? ($whatsappCcDigits !== ""
              ? $whatsappCcDigits . $whatsappLocalDigits
              : $whatsappLocalDigits)
            : "";
        ?>
        <form id="contactForm" class="contact-form reveal" method="post" action="send.php" data-whatsapp="<?= htmlspecialchars($whatsappDigits) ?>">
          <label for="nombre">Nombre</label>
          <input id="nombre" name="nombre" type="text" placeholder="Tu nombre" required value="<?= htmlspecialchars($clientPrefillNombre) ?>">

          <label for="email">Correo <span class="contact-required-hint">(obligatorio si envías con «Enviar…»)</span></label>
          <input id="email" name="email" type="email" placeholder="tunombre@email.com" required autocomplete="email" inputmode="email" value="<?= htmlspecialchars($clientPrefillEmail) ?>">

          <div class="form-servicio-asunto-row">
            <div class="form-servicio-asunto-cell">
              <label for="servicio">Servicio de interés</label>
              <select id="servicio" name="servicio" required>
                <option value="">Selecciona una opción</option>
                <?php foreach ($services as $service): ?>
                  <option value="<?= htmlspecialchars($service["title"]) ?>"><?= htmlspecialchars($service["title"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-servicio-asunto-cell">
              <label for="contact_asunto">Asunto o título <span class="contact-required-hint">(obligatorio)</span></label>
              <input id="contact_asunto" name="asunto" type="text" maxlength="200" required minlength="1" placeholder="Título breve (texto)" autocomplete="off">
            </div>
          </div>
          <p class="client-muted small mb-0 mt-1">Servicio y asunto en la misma fila (en móvil se apilan). El asunto es obligatorio y no reemplaza al servicio.</p>

          <label for="mensaje" class="mt-3">Mensaje</label>
          <textarea id="mensaje" name="mensaje" rows="4" placeholder="Cuéntame cómo te puedo ayudar" required></textarea>

          <div class="contact-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-envelope-open-text"></i> <?= $clientUser !== null ? "Enviar consulta" : "Enviar (aviso por correo)" ?></button>
            <?php if ($waFeatureOn): ?>
            <button
              type="button"
              id="contactWhatsappBtn"
              class="btn btn-whatsapp<?= $whatsappDigits === "" ? " btn-whatsapp-disabled" : "" ?>"
              <?= $whatsappDigits === "" ? 'disabled aria-disabled="true" title="Configura el número en Administración → Configuración general → WhatsApp de contacto"' : "" ?>
            ><i class="fa-brands fa-whatsapp"></i> Escribir por WhatsApp</button>
            <?php endif; ?>
          </div>
          <?php if ($waFeatureOn && $whatsappDigits === ""): ?>
            <p class="contact-whatsapp-hint">Configúralo en Administración.</p>
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
  <?php if ($clientUser !== null && app_feature_enabled("client_inbox") && count($clientMyMessages) > 0): ?>
  <script>
    (function () {
      var root = document.getElementById("clientInboxMessagesRoot");
      if (!root) return;

      function applyRead(row) {
        if (!row || !row.classList.contains("is-unread")) return;
        row.classList.remove("is-unread");
      }
      function applyUnread(row) {
        if (!row || row.classList.contains("is-unread")) return;
        row.classList.add("is-unread");
      }

      function postToggle(row, action) {
        var id = row.getAttribute("data-message-id");
        if (!id) return Promise.resolve(false);
        var fd = new FormData();
        fd.append("action", action);
        fd.append("message_id", id);
        fd.append("ajax", "1");
        return fetch(window.location.pathname || "index.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          var ct = (r.headers.get("Content-Type") || "").toLowerCase();
          if (ct.indexOf("application/json") === -1) {
            return r.text().then(function (txt) {
              throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
            });
          }
          return r.json();
        }).then(function (data) {
          return !!(data && data.ok);
        }).catch(function (err) {
          console.error("Error en " + action + ":", err);
          return false;
        });
      }

      root.querySelectorAll(".js-client-mark-read-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          var row = form.closest(".client-msg-own-row");
          postToggle(row, "client_mark_message_read").then(function (ok) {
            if (ok) applyRead(row);
          });
        });
      });
      root.querySelectorAll(".js-client-mark-unread-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          var row = form.closest(".client-msg-own-row");
          postToggle(row, "client_mark_message_unread").then(function (ok) {
            if (ok) applyUnread(row);
          });
        });
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
