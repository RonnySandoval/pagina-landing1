<?php
declare(strict_types=1);

// Punto de entrada de la landing. Patrones de URL local/servidor: ver app_urls.php (mapa al inicio del archivo).
require __DIR__ . "/db.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/client_inbox_helpers.php";

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
        if ($wantRead) {
            $stmt = $conn->prepare(
                "UPDATE contact_messages SET is_read = 1, client_has_unseen_reply = 0 WHERE id = ?"
            );
        } else {
            $stmt = $conn->prepare("UPDATE contact_messages SET is_read = 0 WHERE id = ?");
        }
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
        $stmt->bind_param("i", $messageId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($isAjax) {
            $minimal = index_client_inbox_messages_minimal($conn, $sessClientId, $sessEmailNorm);
            $siteUnseen = index_client_site_unseen_total_from_rows($minimal);
            $maxRep = index_client_max_reply_id_for_messages($conn, $minimal);
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode([
                "ok" => (bool)$ok,
                "id" => $messageId,
                "site_unseen_total" => $siteUnseen,
                "max_reply_id" => $maxRep,
            ]);
            exit;
        }
        client_set_flash("success", $wantRead ? "Marcado como leído." : "Marcado como no leído.");
        header("Location: " . app_public_base_url() . "/index.php#area-cliente");
        exit;
    }
    if ($postAction === "client_mark_thread_read") {
        $isAjax = isset($_POST["ajax"]) && (string)($_POST["ajax"] ?? "") === "1";
        $threadRootId = (int)($_POST["thread_root_id"] ?? 0);
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
            client_set_flash("danger", "Inicia sesión para continuar.");
            header("Location: " . app_public_base_url() . "/index.php?client_tab=login#area-cliente");
            exit;
        }
        $sessClientId = (int)($_SESSION["client_id"] ?? 0);
        $sessEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
        if ($threadRootId <= 0) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "bad_thread"]);
                exit;
            }
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        $minimalTr = index_client_inbox_messages_minimal($conn, $sessClientId, $sessEmailNorm);
        $idsTr = index_client_message_ids_in_thread($minimalTr, $threadRootId);
        $safeIds = array_values(array_filter(array_map(static function ($x): int {
            return (int)$x;
        }, $idsTr), static function (int $x): bool {
            return $x > 0;
        }));
        if (count($safeIds) === 0) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "not_found"]);
                exit;
            }
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        $inListTr = implode(",", $safeIds);
        $sqlTr = "UPDATE contact_messages SET is_read = 1, client_has_unseen_reply = 0
            WHERE id IN ($inListTr) AND (client_id = ? OR (client_id IS NULL AND LOWER(TRIM(email)) = ?))";
        $stmtTr = $conn->prepare($sqlTr);
        $okTr = false;
        if ($stmtTr !== false) {
            $stmtTr->bind_param("is", $sessClientId, $sessEmailNorm);
            $okTr = $stmtTr->execute();
            $stmtTr->close();
        }
        if ($isAjax) {
            $minimalTr2 = index_client_inbox_messages_minimal($conn, $sessClientId, $sessEmailNorm);
            $siteUnseenTr = index_client_site_unseen_total_from_rows($minimalTr2);
            $maxRepTr = index_client_max_reply_id_for_messages($conn, $minimalTr2);
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode([
                "ok" => (bool)$okTr,
                "thread_root_id" => $threadRootId,
                "site_unseen_total" => $siteUnseenTr,
                "max_reply_id" => $maxRepTr,
            ]);
            exit;
        }
        header("Location: " . app_public_base_url() . "/index.php#area-cliente");
        exit;
    }
    if ($postAction === "client_ack_thread_unseen") {
        $isAjax = isset($_POST["ajax"]) && (string)($_POST["ajax"] ?? "") === "1";
        $threadRootId = (int)($_POST["thread_root_id"] ?? 0);
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
            client_set_flash("danger", "Inicia sesión para continuar.");
            header("Location: " . app_public_base_url() . "/index.php?client_tab=login#area-cliente");
            exit;
        }
        $sessClientId = (int)($_SESSION["client_id"] ?? 0);
        $sessEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
        if ($threadRootId <= 0) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "bad_thread"]);
                exit;
            }
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        $minimal = index_client_inbox_messages_minimal($conn, $sessClientId, $sessEmailNorm);
        $ids = index_client_message_ids_in_thread($minimal, $threadRootId);
        if (count($ids) === 0) {
            if ($isAjax) {
                header("Content-Type: application/json; charset=UTF-8");
                echo json_encode(["ok" => false, "err" => "not_found"]);
                exit;
            }
            header("Location: " . app_public_base_url() . "/index.php#area-cliente");
            exit;
        }
        $ok = true;
        foreach ($ids as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) {
                continue;
            }
            $st = $conn->prepare(
                "UPDATE contact_messages SET client_has_unseen_reply = 0 WHERE id = ? AND (client_id = ? OR (client_id IS NULL AND LOWER(TRIM(email)) = ?))"
            );
            if ($st === false) {
                $ok = false;
                break;
            }
            $st->bind_param("iis", $tid, $sessClientId, $sessEmailNorm);
            if (!$st->execute()) {
                $ok = false;
            }
            $st->close();
        }
        if ($isAjax) {
            $minimal2 = index_client_inbox_messages_minimal($conn, $sessClientId, $sessEmailNorm);
            $siteUnseen = index_client_site_unseen_total_from_rows($minimal2);
            $maxRep = index_client_max_reply_id_for_messages($conn, $minimal2);
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode([
                "ok" => $ok,
                "thread_root_id" => $threadRootId,
                "site_unseen_total" => $siteUnseen,
                "max_reply_id" => $maxRep,
            ]);
            exit;
        }
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

/** La sección usa .reveal; forzar .show si hay sesión o flujos de cliente para que el bloque sea visible al cargar. */
$areaClienteRevealShow = $clientUser !== null || $clientTab !== "" || $clientRegisterPending !== null;

$clientPrefillNombre = "";
$clientPrefillEmail = "";
$clientMyMessages = [];
$clientRepliesByMessageId = [];
$clientSiteUnseenTotal = 0;
$clientMaxReplyId = 0;
if ($clientUser !== null) {
    $clientPrefillNombre = $clientUser["display_name"] !== ""
        ? $clientUser["display_name"]
        : (preg_match('/^([^@]+)/u', $clientUser["email"], $m) ? $m[1] : "");
    $clientPrefillEmail = $clientUser["email"];

    if (app_feature_enabled("client_inbox")) {
        $clientIdForQuery = (int)$clientUser["id"];
        $clientEmailNorm = strtolower(trim($clientUser["email"]));
        $stmt = $conn->prepare("
        SELECT id, nombre, servicio, subject, mensaje, created_at, in_reply_to, is_read, client_has_unseen_reply,
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
            $mxQ = $conn->query(
                "SELECT COALESCE(MAX(id), 0) AS mx FROM contact_message_replies WHERE contact_message_id IN ($inList)"
            );
            if ($mxQ && $mxQ->num_rows === 1) {
                $mxRow = $mxQ->fetch_assoc();
                $clientMaxReplyId = (int)($mxRow["mx"] ?? 0);
            }
        }
        foreach ($clientMyMessages as $_cmUn) {
            if ((int)($_cmUn["client_has_unseen_reply"] ?? 0) === 1) {
                $clientSiteUnseenTotal++;
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

$clientThreads = [];
if ($clientUser !== null && count($clientMyMessages) > 0) {
    $clientThreads = index_client_group_messages_threads($clientMyMessages, $clientRepliesByMessageId);
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

if (isset($_GET["client_inbox_poll"]) && (string)$_GET["client_inbox_poll"] === "1") {
    header("Content-Type: application/json; charset=UTF-8");
    if (!app_feature_enabled("client_inbox")) {
        echo json_encode(["ok" => false, "err" => "feature_off"]);
        exit;
    }
    if (!client_portal_resume_session($conn)) {
        echo json_encode(["ok" => false, "err" => "no_session"]);
        exit;
    }
    $pollClientId = (int)($_SESSION["client_id"] ?? 0);
    $pollEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
    $minimalPoll = index_client_inbox_messages_minimal($conn, $pollClientId, $pollEmailNorm);
    $siteUnseenPoll = index_client_site_unseen_total_from_rows($minimalPoll);
    $maxReplyPoll = index_client_max_reply_id_for_messages($conn, $minimalPoll);
    $threadsPoll = index_client_group_messages_threads($minimalPoll, []);
    $threadsSiteUnseen = [];
    foreach ($threadsPoll as $tp) {
        $nSite = 0;
        foreach ($tp["messages"] as $tm) {
            if ((int)($tm["client_has_unseen_reply"] ?? 0) === 1) {
                $nSite++;
            }
        }
        if ($nSite > 0) {
            $threadsSiteUnseen[(string)((int)($tp["root_id"] ?? 0))] = $nSite;
        }
    }
    echo json_encode([
        "ok" => true,
        "site_unseen_total" => $siteUnseenPoll,
        "max_reply_id" => $maxReplyPoll,
        "threads_site_unseen" => $threadsSiteUnseen,
    ]);
    exit;
}

$stylesVersion = (string)(@filemtime(__DIR__ . "/styles.css") ?: time());
$scriptVersion = (string)(@filemtime(__DIR__ . "/script.js") ?: time());
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
          <p class="lead mb-3 client-zone-intro">
            Aquí ves lo que has escrito a esta web y las respuestas que hayan dejado. Es tu copia del historial: puedes volver cuando quieras, seguir un tema abierto o abrir uno nuevo.
          </p>
          <div class="client-auth-box client-perks mb-4">
              <h3><i class="fa-solid fa-circle-info"></i> En pocas palabras</h3>
              <ul class="mb-0">
                <li><strong>Nueva consulta:</strong> abajo puedes elegir <strong>servicio</strong>, poner un <strong>asunto</strong> (un título que te ayude a reconocer el tema) y escribir el mensaje. También puedes usar el formulario de contacto al final de la página con el mismo correo de tu cuenta.</li>
                <li><strong>Conversaciones:</strong> cada bloque agrupa un mismo tema. Ábrelo para leer el hilo, responder al final o marcar mensajes como leídos para ti.</li>
              </ul>
          </div>

          <div class="client-messages-panel client-auth-box" id="clientMessagesPanel">
            <div class="client-inbox-live-alert js-client-inbox-live-alert" hidden role="status" aria-live="polite">
              <span class="client-inbox-live-alert-inner">
                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                <span>Hay novedades en tu historial (respuestas del sitio). Despliega «Tus conversaciones anteriores» o actualiza la página para verlas.</span>
              </span>
              <button type="button" class="btn btn-primary btn-sm js-client-inbox-reload">Actualizar página</button>
            </div>

            <div class="client-msg-new-inquiry">
              <p class="client-msg-new-heading"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Nueva consulta</p>
              <p class="client-muted small mb-2">Completa servicio, asunto y mensaje. El asunto es obligatorio y sirve para titular el hilo en tu historial.</p>
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
                    <input id="client_new_asunto" name="asunto" type="text" maxlength="200" required minlength="1" placeholder="Ej.: dudas sobre presupuesto" autocomplete="off">
                  </div>
                </div>
                <label for="client_new_mensaje" class="mt-3">Mensaje</label>
                <textarea id="client_new_mensaje" name="mensaje" rows="4" required placeholder="Describe tu consulta"></textarea>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Enviar consulta</button>
              </form>
            </div>

            <h3 class="client-msg-history-heading"><i class="fa-solid fa-inbox"></i> Mis mensajes</h3>

            <?php if (count($clientMyMessages) === 0): ?>
              <p class="client-muted small mb-0 mt-3">Aún no hay mensajes anteriores en tu historial.</p>
            <?php else: ?>
              <?php
                $clientThreadCount = count($clientThreads);
                $clientMsgCount = count($clientMyMessages);
              ?>
              <details class="client-inbox-history<?= $clientSiteUnseenTotal > 0 ? " client-inbox-history--has-site-unseen" : "" ?>">
                <summary class="client-inbox-history-summary">
                  <span class="client-inbox-history-title">
                    <i class="fa-solid fa-comments" aria-hidden="true"></i>
                    Tus conversaciones anteriores
                    <i class="fa-solid fa-chevron-down client-inbox-history-chevron" aria-hidden="true"></i>
                  </span>
                  <span class="client-inbox-history-meta">
                    <span class="client-inbox-history-badge"><?= (int)$clientThreadCount ?> <?= $clientThreadCount === 1 ? "conversación" : "conversaciones" ?> · <?= (int)$clientMsgCount ?> <?= $clientMsgCount === 1 ? "mensaje" : "mensajes" ?></span>
                    <span class="client-inbox-history-site-badge js-client-inbox-history-site-badge<?= $clientSiteUnseenTotal <= 0 ? " is-empty" : "" ?>"<?= $clientSiteUnseenTotal > 0 ? ' title="Respuestas del sitio pendientes de revisar en el historial"' : "" ?>><?= $clientSiteUnseenTotal > 0 ? ((int)$clientSiteUnseenTotal . " novedad" . ($clientSiteUnseenTotal !== 1 ? "es" : "")) : "" ?></span>
                  </span>
                </summary>
                <div class="client-inbox-history-body">
                  <p class="client-muted small mb-3 mt-0">Abre un tema para ver el hilo completo. Si hay novedades sin leer, lo verás en el resumen. Puedes marcar cada envío como leído o no leído para organizarte.</p>
                  <div id="clientInboxMessagesRoot" class="client-msg-list" data-init-site-unseen="<?= (int)$clientSiteUnseenTotal ?>" data-init-max-reply="<?= (int)$clientMaxReplyId ?>">
                <?php foreach ($clientThreads as $thread): ?>
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
                    $threadSiteUnseen = 0;
                    foreach ($tMsgs as $tmSite) {
                        if ((int)($tmSite["client_has_unseen_reply"] ?? 0) === 1) {
                            $threadSiteUnseen++;
                        }
                    }
                  ?>
                  <details class="client-msg-thread client-msg-conv-root<?= $threadSiteUnseen > 0 ? " client-msg-thread--site-unseen" : "" ?>" data-thread-id="<?= $threadConvId ?>">
                    <summary>
                      <span class="client-msg-summary-main">
                        <?php if ($threadConvId > 0): ?>
                          <span class="client-msg-thread-id" title="Número de este tema en tu historial">Tema <?= $threadConvId ?></span>
                        <?php endif; ?>
                        <span class="client-msg-thread-subject<?= $rootSubject === "" ? " client-msg-thread-subject--empty" : "" ?>" title="Título que diste a esta consulta"><?= htmlspecialchars($rootSubjectDisp) ?></span>
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
                        <?php if ($threadSiteUnseen > 0): ?>
                          <span class="client-msg-badge client-msg-badge-site-unread js-client-thread-site-badge" data-thread-id="<?= (int)$threadConvId ?>"><?= (int)$threadSiteUnseen ?> respuesta nueva<?= $threadSiteUnseen !== 1 ? "s" : "" ?></span>
                        <?php else: ?>
                          <span class="client-msg-badge client-msg-badge-site-unread js-client-thread-site-badge is-empty" data-thread-id="<?= (int)$threadConvId ?>" hidden aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php if ($threadUnread > 0): ?>
                          <span class="client-msg-badge client-msg-badge-unread js-client-thread-unread-badge" title="Hay envíos sin leer en este hilo"><?= (int)$threadUnread ?> sin leer</span>
                        <?php else: ?>
                          <span class="client-msg-badge client-msg-badge-unread js-client-thread-unread-badge is-empty" title="Hay envíos sin leer en este hilo" hidden aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php if (!empty($thread["has_admin_reply"])): ?>
                          <span class="client-msg-badge" title="Hay respuesta del sitio en este hilo">Respuesta</span>
                        <?php endif; ?>
                      </span>
                    </summary>
                    <div class="client-msg-detail client-msg-conv-body">
                      <div class="client-msg-chat-stream">
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
                          $isClientContinuation = $mi > 0;
                          $rootServTrim = trim($rootServ);
                          $cmServTrim = trim($cmServicio);
                          $hideClientTurnSvc = $isClientContinuation && $rootServTrim !== "" && $cmServTrim === $rootServTrim;
                          $cmCreatedLabel = htmlspecialchars(index_format_datetime($cmCreated));
                          $clientStepLabel = ($mi + 1) . "/" . $tCount;
                          $clientChipTitle = "Paso " . ($mi + 1) . " de " . $tCount . " en este tema · " . $cmCreatedLabel;
                          $hasSiteUnseen = (int)($cm["client_has_unseen_reply"] ?? 0) === 1;
                        ?>
                        <div class="client-msg-turn client-msg-own-row<?= $isCmUnread ? " is-unread" : "" ?><?= $isClientContinuation ? " client-msg-turn--continuation" : "" ?><?= $hasSiteUnseen ? " client-msg-own-row--site-unseen" : "" ?>" data-message-id="<?= $cmId ?>">
                          <div class="client-msg-turn-main">
                          <div class="client-msg-turn-head">
                            <div class="client-msg-turn-head-main">
                            <?php if ($cmId > 0): ?>
                              <span class="client-msg-id-chip" title="<?= htmlspecialchars($clientChipTitle, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($clientStepLabel) ?></span>
                            <?php endif; ?>
                            <span class="client-msg-turn-date"><?= $cmCreatedLabel ?></span>
                            <?php if (!$isRootTurn && !$isClientContinuation): ?>
                              <span class="client-msg-badge client-msg-badge-muted">Seguimiento</span>
                            <?php endif; ?>
                            <?php if ($cmReplyCount > 0): ?>
                              <span class="client-msg-badge">Respuesta</span>
                            <?php endif; ?>
                            <?php if ($tCount > 1 && $cmServTrim !== "" && !$hideClientTurnSvc): ?>
                              <span class="client-msg-turn-svc"><?= htmlspecialchars($cmServicio) ?></span>
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
                          </div>
                        </div>
                        </div>
                      <?php endforeach; ?>
                      </div>
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
                          <p class="client-muted small mb-2">Tu respuesta se añade al final de este mismo tema, con el mismo asunto y servicio.</p>
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
                </div>
              </details>
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
            <article id="service_focus_<?= $fid ?>" class="service-focus-article<?= count($slidesF) > 0 ? " service-focus-article--has-gallery" : "" ?>" data-service-id="<?= $fid ?>" hidden>
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
                  <?php
                    $carouselSlides = $slidesF;
                    $carouselServiceTitle = (string)$service["title"];
                    $carouselVariant = "focus";
                    $carouselId = "service_carousel_focus_" . $fid;
                    require __DIR__ . "/partials/service_carousel.php";
                  ?>
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
                  <?php
                    $carouselSlides = $slides;
                    $carouselServiceTitle = (string)$service["title"];
                    $carouselVariant = "inline";
                    $carouselId = "service_carousel_inline_" . $serviceId;
                    require __DIR__ . "/partials/service_carousel.php";
                  ?>
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
      var panel = document.getElementById("clientMessagesPanel");
      var historyDetails = document.querySelector("details.client-inbox-history");
      var liveAlert = panel ? panel.querySelector(".js-client-inbox-live-alert") : null;
      if (!root) return;

      var pollUrl = (window.location.pathname || "index.php").split("?")[0] + "?client_inbox_poll=1";
      var lastSnap = {
        maxReply: parseInt(String(root.getAttribute("data-init-max-reply") || "0"), 10) || 0,
        siteUnseen: parseInt(String(root.getAttribute("data-init-site-unseen") || "0"), 10) || 0
      };

      function parseJsonSafe(r) {
        return r.text().then(function (txt) {
          try {
            return JSON.parse(txt);
          } catch (e) {
            return null;
          }
        });
      }

      function setHistorySiteClass(total) {
        if (!historyDetails) return;
        historyDetails.classList.toggle("client-inbox-history--has-site-unseen", (total || 0) > 0);
      }

      function setOuterSiteBadge(total) {
        var el = document.querySelector(".js-client-inbox-history-site-badge");
        if (!el) return;
        var n = Math.max(0, parseInt(String(total), 10) || 0);
        if (n <= 0) {
          el.textContent = "";
          el.classList.add("is-empty");
          el.removeAttribute("title");
        } else {
          el.classList.remove("is-empty");
          el.textContent = n + " novedad" + (n === 1 ? "" : "es");
          el.setAttribute("title", "Respuestas del sitio pendientes de revisar en el historial");
        }
      }

      function setThreadSiteBadge(threadId, n) {
        var el = root.querySelector('.js-client-thread-site-badge[data-thread-id="' + String(threadId) + '"]');
        if (!el) return;
        var c = Math.max(0, parseInt(String(n), 10) || 0);
        if (c <= 0) {
          el.textContent = "";
          el.classList.add("is-empty");
          el.setAttribute("hidden", "");
          el.setAttribute("aria-hidden", "true");
        } else {
          el.classList.remove("is-empty");
          el.removeAttribute("hidden");
          el.removeAttribute("aria-hidden");
          el.textContent = c + " respuesta nueva" + (c === 1 ? "" : "s");
        }
      }

      function setThreadSiteUnseenClass(det, on) {
        det.classList.toggle("client-msg-thread--site-unseen", !!on);
      }

      function syncThreadSiteClassFromDom(det) {
        var n = det.querySelectorAll(".client-msg-own-row--site-unseen").length;
        setThreadSiteUnseenClass(det, n > 0);
        setThreadSiteBadge(det.getAttribute("data-thread-id") || "0", n);
      }

      function syncGlobalFromTotal(total) {
        setOuterSiteBadge(total);
        setHistorySiteClass(total);
      }

      function syncThreadUnreadBadgeFromDom(det) {
        var badge = det.querySelector(".js-client-thread-unread-badge");
        if (!badge) return;
        var n = det.querySelectorAll(".client-msg-own-row.is-unread").length;
        if (n <= 0) {
          badge.textContent = "";
          badge.classList.add("is-empty");
          badge.setAttribute("hidden", "");
          badge.setAttribute("aria-hidden", "true");
        } else {
          badge.classList.remove("is-empty");
          badge.removeAttribute("hidden");
          badge.removeAttribute("aria-hidden");
          badge.textContent = n + " sin leer";
        }
      }

      function applyServerThreadMap(map) {
        if (!map || typeof map !== "object") return;
        root.querySelectorAll("details.client-msg-thread").forEach(function (det) {
          var tid = det.getAttribute("data-thread-id");
          if (!tid) return;
          var n = map[tid];
          if (typeof n === "number" && n > 0) {
            setThreadSiteBadge(tid, n);
            setThreadSiteUnseenClass(det, true);
          } else {
            syncThreadSiteClassFromDom(det);
          }
          syncThreadUnreadBadgeFromDom(det);
        });
      }

      function showRemoteAttention() {
        if (liveAlert) liveAlert.removeAttribute("hidden");
        if (panel) panel.classList.add("client-messages-panel--live-attention");
        if (historyDetails && !historyDetails.open) {
          setHistorySiteClass(true);
        }
      }

      function hideRemoteAttentionIfCaughtUp(total) {
        if ((total || 0) <= 0 && liveAlert && panel) {
          liveAlert.setAttribute("hidden", "");
          panel.classList.remove("client-messages-panel--live-attention");
        }
      }

      function postToggle(row, action) {
        var id = row.getAttribute("data-message-id");
        if (!id) return Promise.resolve(null);
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
          return data && data.ok ? data : null;
        }).catch(function (err) {
          console.error("Error en " + action + ":", err);
          return null;
        });
      }

      function applyRead(row) {
        if (!row || !row.classList.contains("is-unread")) return;
        row.classList.remove("is-unread");
      }
      function applyUnread(row) {
        if (!row || row.classList.contains("is-unread")) return;
        row.classList.add("is-unread");
      }

      root.querySelectorAll(".js-client-mark-read-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          var row = form.closest(".client-msg-own-row");
          postToggle(row, "client_mark_message_read").then(function (data) {
            if (!data) return;
            applyRead(row);
            row.classList.remove("client-msg-own-row--site-unseen");
            var det = row.closest("details.client-msg-thread");
            if (det) {
              syncThreadSiteClassFromDom(det);
              syncThreadUnreadBadgeFromDom(det);
            }
            if (typeof data.site_unseen_total === "number") {
              syncGlobalFromTotal(data.site_unseen_total);
              hideRemoteAttentionIfCaughtUp(data.site_unseen_total);
              lastSnap.siteUnseen = data.site_unseen_total;
            }
            if (typeof data.max_reply_id === "number") {
              lastSnap.maxReply = data.max_reply_id;
            }
          });
        });
      });
      root.querySelectorAll(".js-client-mark-unread-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          var row = form.closest(".client-msg-own-row");
          postToggle(row, "client_mark_message_unread").then(function (data) {
            if (!data) return;
            applyUnread(row);
            var det = row.closest("details.client-msg-thread");
            if (det) syncThreadUnreadBadgeFromDom(det);
          });
        });
      });

      function markThreadOpenedRead(det) {
        var tid = det.getAttribute("data-thread-id");
        if (!tid) return;
        var fd = new FormData();
        fd.append("action", "client_mark_thread_read");
        fd.append("thread_root_id", tid);
        fd.append("ajax", "1");
        fetch(window.location.pathname || "index.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
        })
          .then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
          })
          .then(function (data) {
            if (!data || !data.ok) return;
            det.querySelectorAll(".client-msg-own-row.is-unread").forEach(function (row) {
              applyRead(row);
            });
            det.querySelectorAll(".client-msg-own-row--site-unseen").forEach(function (row) {
              row.classList.remove("client-msg-own-row--site-unseen");
            });
            setThreadSiteUnseenClass(det, false);
            setThreadSiteBadge(tid, 0);
            syncThreadUnreadBadgeFromDom(det);
            if (typeof data.site_unseen_total === "number") {
              syncGlobalFromTotal(data.site_unseen_total);
              hideRemoteAttentionIfCaughtUp(data.site_unseen_total);
              lastSnap.siteUnseen = data.site_unseen_total;
            }
            if (typeof data.max_reply_id === "number") {
              lastSnap.maxReply = data.max_reply_id;
            }
          })
          .catch(function (e) {
            console.error("client_mark_thread_read", e);
          });
      }

      root.querySelectorAll("details.client-msg-thread").forEach(function (det) {
        det.addEventListener("toggle", function () {
          if (!det.open) return;
          markThreadOpenedRead(det);
        });
      });

      if (liveAlert) {
        var btnReload = liveAlert.querySelector(".js-client-inbox-reload");
        if (btnReload) {
          btnReload.addEventListener("click", function () {
            window.location.reload();
          });
        }
      }

      function pollOnce() {
        fetch(pollUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
          .then(function (r) {
            return r.ok ? r.json() : parseJsonSafe(r);
          })
          .then(function (data) {
            if (!data || !data.ok) return;
            var mr = parseInt(String(data.max_reply_id || 0), 10) || 0;
            var su = parseInt(String(data.site_unseen_total || 0), 10) || 0;
            var bump = mr > lastSnap.maxReply || su > lastSnap.siteUnseen;
            if (bump) {
              showRemoteAttention();
            }
            lastSnap.maxReply = mr;
            lastSnap.siteUnseen = su;
            syncGlobalFromTotal(su);
            applyServerThreadMap(data.threads_site_unseen || {});
            hideRemoteAttentionIfCaughtUp(su);
          })
          .catch(function () {});
      }

      window.setTimeout(pollOnce, 5000);
      window.setInterval(pollOnce, 28000);
    })();
  </script>
  <?php endif; ?>
</body>
</html>
