<?php
declare(strict_types=1);

// Panel admin: script admin.php. Rutas y ejemplos local/producción: ver app_urls.php (bloque «MAPA DE RUTAS»).
// Aislar sesiones por landing.
// Si varias landings comparten host (p. ej. localhost/pag-laura, localhost/pag-juan),
// la cookie PHPSESSID por defecto (path "/") se comparte entre ellas y los datos
// de sesión se "cuelan": admin_email de una landing aparece logueado en otra y la
// pantalla "Credenciales Admin" muestra/usa el correo equivocado.
// Solución: nombre de cookie único por instalación + cookie path = directorio del script (admin_portal_lib.php).
require_once __DIR__ . "/admin_portal_lib.php";
require_once __DIR__ . "/admin_inbox_lib.php";
require_once __DIR__ . "/admin_settings_service.php";
require_once __DIR__ . "/services_lib.php";
require_once __DIR__ . "/experts_admin_lib.php";
require_once __DIR__ . "/admin_clients_lib.php";
require_once __DIR__ . "/admin_whatsapp_lib.php";
require_once __DIR__ . "/upload_image_lib.php";
admin_session_start();

require __DIR__ . "/db.php";
require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/smtp_mail.php";

$adminInboxUi = app_feature_enabled("admin_inbox");
$adminWhatsappClicksUi = app_feature_enabled("admin_whatsapp_clicks");
$adminExpertAgendaUi = app_feature_enabled("expert_agenda");
if ($adminExpertAgendaUi) {
    require_once __DIR__ . "/agenda_lib.php";
    require_once __DIR__ . "/agenda_notifications_lib.php";
}

$adminAssetStylesVer = is_file(__DIR__ . "/styles.css") ? (string) filemtime(__DIR__ . "/styles.css") : "1";
$adminAssetScriptVer = is_file(__DIR__ . "/script.js") ? (string) filemtime(__DIR__ . "/script.js") : "1";
$adminFilterTablesVer = is_file(__DIR__ . "/admin_filter_tables.js")
    ? (string) filemtime(__DIR__ . "/admin_filter_tables.js")
    : "1";
$adminFilterTablesCssVer = is_file(__DIR__ . "/admin_filter_tables.css")
    ? (string) filemtime(__DIR__ . "/admin_filter_tables.css")
    : "1";
$adminPanelCssVer = is_file(__DIR__ . "/admin.css")
    ? (string) filemtime(__DIR__ . "/admin.css")
    : "1";
$adminAjaxVer = is_file(__DIR__ . "/admin_ajax.js")
    ? (string) filemtime(__DIR__ . "/admin_ajax.js")
    : "1";

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

/** Clases Font Awesome completas (fa-solid / fa-brands + nombre). */
function admin_nav_icon_class(string $icon): string
{
    $icon = trim($icon);
    if ($icon === "") {
        return "fa-solid fa-circle";
    }
    if (preg_match('/^fa-(solid|regular|brands)\s+/', $icon)) {
        return $icon;
    }
    if (str_starts_with($icon, "fa-brands ")) {
        return $icon;
    }
    if (str_starts_with($icon, "fa-")) {
        return "fa-solid " . $icon;
    }

    return "fa-solid fa-" . $icon;
}

function admin_sync_layout_wide_session(): void
{
    if (!isset($_GET["wide"])) {
        return;
    }
    if ((string)$_GET["wide"] === "1") {
        $_SESSION["admin_layout_wide"] = 1;
        return;
    }
    unset($_SESSION["admin_layout_wide"]);
}

function admin_layout_wide_from_request(): bool
{
    if (isset($_GET["wide"])) {
        return (string)$_GET["wide"] === "1";
    }

    return !empty($_SESSION["admin_layout_wide"]);
}

/** URL de ficha experto en Expertos (solo datos y citas del listado). */
function admin_expert_page_url(int $expertId, string $view = "edit", string $weekStart = "", string $section = ""): string
{
    $view = $view === "schedule" ? "schedule" : "edit";
    if ($view === "schedule") {
        return admin_agenda_expert_url($expertId, "schedule", $weekStart, $section);
    }
    $q = "expert_id=" . $expertId . "&expert_view=edit";

    return "admin.php?" . $q . "#admin-expert-edit";
}

/** URL en la sección Agendas: horario o datos del experto. */
function admin_agenda_expert_url(int $expertId, string $tab = "schedule", string $weekStart = "", string $section = ""): string
{
    $tab = $tab === "datos" ? "datos" : "schedule";
    $q = "expert_id=" . $expertId . "&expert_view=schedule&expert_tab=" . rawurlencode($tab);
    if ($weekStart !== "") {
        $q .= "&expert_week=" . rawurlencode($weekStart);
    }
    $scheduleSections = ["appts", "week", "template", "daily", "dates"];
    if ($tab === "schedule" && $section !== "" && in_array($section, $scheduleSections, true)) {
        $q .= "&expert_section=" . rawurlencode($section);
    }

    $url = "admin.php?" . $q . "&workspace=agendas";
    if (admin_layout_wide_from_request()) {
        $url .= "&wide=1";
    }

    $hash = "#admin-tools-agendas";
    if ($tab === "schedule" && $section !== "") {
        $sectionHashes = [
            "appts" => "#expert_sch_acc_appts",
            "week" => "#expert_sch_acc_week",
            "template" => "#expert_sch_acc_template",
            "daily" => "#expert_sch_acc_template",
            "dates" => "#expert_sch_acc_dates",
        ];
        if (isset($sectionHashes[$section])) {
            $hash = $sectionHashes[$section];
        }
    }

    return $url . $hash;
}

/** Mensaje de error legible al aplicar plantilla semanal. */
function admin_expert_template_error_message(string $code, bool $bulk = false): string
{
    if ($code === "not_found" || $code === "invalid_expert") {
        return $bulk ? "No hay expertos válidos." : "Ese experto no existe.";
    }
    if ($code === "invalid_time_range") {
        return "La hora de fin debe ser posterior a la de inicio.";
    }
    if ($code === "invalid_weekdays") {
        return "Marca al menos un día de la semana.";
    }
    if ($code === "invalid_time") {
        return "Indica hora inicio y fin en formato HH:MM.";
    }

    return $bulk
        ? "No se pudo aplicar la plantilla a todos los expertos."
        : "No se pudo actualizar la plantilla semanal.";
}

/** @return array{unread:int,total:int} */
function admin_whatsapp_inbox_counts(mysqli $conn): array
{
    return whatsapp_admin_counts($conn);
}

function admin_set_flash(string $type, string $msg): void
{
    $_SESSION["admin_flash"] = ["type" => $type, "msg" => $msg];
}

function admin_consume_flash(): array
{
    if (isset($_SESSION["admin_flash"]) && is_array($_SESSION["admin_flash"])) {
        $flash = $_SESSION["admin_flash"];
        unset($_SESSION["admin_flash"]);
        return [
            "type" => (string)($flash["type"] ?? ""),
            "msg" => (string)($flash["msg"] ?? "")
        ];
    }
    return ["type" => "", "msg" => ""];
}

/** manage | inbox | agendas */
function admin_resolve_workspace(bool $logged, bool $inboxUi, bool $agendaUi): string
{
    if (!$logged) {
        return "manage";
    }
    $requested = "";
    if (isset($_GET["workspace"])) {
        $requested = (string)$_GET["workspace"];
    } elseif (isset($_GET["inbox"]) && (string)$_GET["inbox"] === "1") {
        $requested = "inbox";
    } elseif (
        $agendaUi
        && isset($_GET["expert_view"])
        && (string)$_GET["expert_view"] === "schedule"
    ) {
        return "agendas";
    }
    if ($requested === "inbox" && $inboxUi) {
        return "inbox";
    }
    if ($requested === "agendas" && $agendaUi) {
        return "agendas";
    }

    return "manage";
}

function admin_workspace_query_param(string $workspace): string
{
    if ($workspace === "manage") {
        return "";
    }

    return "workspace=" . rawurlencode($workspace);
}

/** URL del panel con área de trabajo opcional (?workspace=…&wide=1). */
function admin_workspace_url(string $workspace, string $query = "", string $hash = "", ?bool $wide = null): string
{
    $parts = [];
    if ($query !== "") {
        $parts[] = ltrim($query, "?&");
    }
    $wq = admin_workspace_query_param($workspace);
    if ($wq !== "") {
        $parts[] = $wq;
    }
    if ($wide === true) {
        $parts[] = "wide=1";
    } elseif ($wide === false) {
        $parts[] = "wide=0";
    } elseif ($wide === null && admin_layout_wide_from_request()) {
        $parts[] = "wide=1";
    }
    $url = "admin.php";
    if ($parts !== []) {
        $url .= "?" . implode("&", $parts);
    }
    if ($hash !== "") {
        $url .= str_starts_with($hash, "#") ? $hash : "#" . $hash;
    }

    return $url;
}

function admin_wants_json_response(): bool
{
    if (isset($_POST["ajax"]) && (string)$_POST["ajax"] === "1") {
        return true;
    }

    return isset($_SERVER["HTTP_X_REQUESTED_WITH"])
        && strtolower((string)$_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest";
}

/** @param array<string, mixed> $payload */
function admin_json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header("Content-Type: application/json; charset=UTF-8");
        header("Cache-Control: no-store");
    }
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined("JSON_INVALID_UTF8_SUBSTITUTE")) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

function admin_redirect_after_action(?string $hash = null): void
{
    $ws = trim((string)($_POST["admin_workspace"] ?? $_GET["workspace"] ?? ""));
    $wide = isset($_POST["admin_layout_wide"])
        || admin_layout_wide_from_request();
    $url = "admin.php";
    $q = [];
    if (in_array($ws, ["inbox", "agendas"], true)) {
        $q[] = "workspace=" . rawurlencode($ws);
    }
    if ($wide) {
        $q[] = "wide=1";
    }
    if ($q !== []) {
        $url .= "?" . implode("&", $q);
    }
    if ($hash !== null && $hash !== "") {
        $url .= str_starts_with($hash, "#") ? $hash : "#" . $hash;
    }
    header("Location: " . $url);
    exit;
}

function storeServiceImage(array $file): array
{
    return upload_store_service_image($file, __DIR__);
}

function storeLogoImage(array $file): array
{
    return upload_store_logo_image($file, __DIR__);
}

$message = "";
$error = "";
$messageAlertClass = "alert-success";
$resetTokenFromUrl = trim((string)($_GET["reset_token"] ?? ""));
// Cuando el usuario envía el form de "Olvidaste tu clave?" (POST de reset request),
// preservamos esa vista al re-renderizar para mostrarle el mensaje genérico.
$showResetView = (isset($_POST["action"]) && $_POST["action"] === "request_admin_password_reset");

$adminFlash = admin_consume_flash();
if ($adminFlash["msg"] !== "") {
    if ($adminFlash["type"] === "error") {
        $error = $adminFlash["msg"];
    } elseif ($adminFlash["type"] === "warning") {
        $message = $adminFlash["msg"];
        $messageAlertClass = "alert-warning";
    } else {
        $message = $adminFlash["msg"];
        $messageAlertClass = "alert-success";
    }
}
$iconOptions = [
    "fa-solid fa-star" => "Estrella",
    "fa-solid fa-code" => "Codigo",
    "fa-solid fa-book-open-reader" => "Asesoria / Estudio",
    "fa-solid fa-guitar" => "Guitarra",
    "fa-solid fa-laptop-code" => "Desarrollo web",
    "fa-solid fa-graduation-cap" => "Educacion",
    "fa-solid fa-chalkboard-user" => "Tutor personalizado",
    "fa-solid fa-lightbulb" => "Ideas / Creatividad",
    "fa-solid fa-brain" => "Pensamiento critico",
    "fa-solid fa-calculator" => "Matematicas",
    "fa-solid fa-language" => "Idiomas",
    "fa-solid fa-music" => "Musica",
    "fa-solid fa-microphone-lines" => "Voz / Oratoria",
    "fa-solid fa-pen-ruler" => "Diseno",
    "fa-solid fa-briefcase" => "Asesoria profesional",
    "fa-solid fa-chart-line" => "Crecimiento",
    "fa-solid fa-rocket" => "Impulso de proyectos",
    "fa-solid fa-gear" => "Soporte tecnico",
    "fa-solid fa-headset" => "Atencion / Soporte",
    "fa-solid fa-handshake" => "Acompanamiento",
    "fa-solid fa-bullseye" => "Objetivos",
    "fa-solid fa-wand-magic-sparkles" => "Soluciones creativas"
];

if (isset($_POST["action"]) && $_POST["action"] === "login") {
    $loginErr = admin_try_login($conn, trim((string)($_POST["email"] ?? "")), (string)($_POST["password"] ?? ""));
    if ($loginErr !== null) {
        $error = $loginErr;
    } else {
        header("Location: admin.php");
        exit;
    }
}

if (isset($_POST["action"]) && $_POST["action"] === "request_admin_password_reset") {
    $resetReq = admin_request_password_reset($conn, (string)($_POST["reset_email"] ?? ""));
    $message = (string)($resetReq["message"] ?? "Si el correo existe, enviamos un enlace de recuperacion.");
}

if (isset($_POST["action"]) && $_POST["action"] === "reset_admin_password") {
    $resetErr = admin_reset_password_with_token(
        $conn,
        (string)($_POST["reset_token"] ?? ""),
        (string)($_POST["new_admin_password"] ?? ""),
        (string)($_POST["confirm_admin_password"] ?? "")
    );
    if ($resetErr !== null) {
        $error = $resetErr;
    } else {
        $message = "Clave restablecida. Ya puedes iniciar sesion.";
        $resetTokenFromUrl = "";
    }
}

if (isset($_GET["logout"])) {
    admin_session_destroy();
    header("Location: admin.php");
    exit;
}

$isLogged = admin_resume_session($conn);
if ($isLogged) {
    admin_sync_layout_wide_session();
}

$adminWorkspace = admin_resolve_workspace($isLogged, $adminInboxUi, $adminExpertAgendaUi);
$adminLayoutWide = $isLogged && admin_layout_wide_from_request();
$adminInboxFocus = $isLogged && $adminWorkspace === "inbox";

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "save_settings") {
    $settingsErrMsg = "";
    $textResult = site_settings_update($conn, $_POST);
    if (!$textResult["ok"]) {
        $code = (string)($textResult["error"] ?? "");
        if ($code === "invalid_contact_email") {
            $settingsErrMsg = "Ingresa un correo de contacto válido.";
        } elseif (str_starts_with($code, "missing_")) {
            $settingsErrMsg = "Completa todos los campos obligatorios de configuración general.";
        } else {
            $settingsErrMsg = "No se pudo guardar la configuración general.";
        }
    } else {
        $currentLogoPath = trim((string)($_POST["current_logo_image_path"] ?? ""));
        $removeLogo = !empty($_POST["remove_logo_image"]);
        $logoFile = $_FILES["logo_image_file"] ?? [];
        $hasLogoFile = is_array($logoFile)
            && isset($logoFile["error"])
            && (int)$logoFile["error"] !== UPLOAD_ERR_NO_FILE;
        if ($hasLogoFile || $removeLogo) {
            $logoResult = site_settings_update_logo($conn, is_array($logoFile) ? $logoFile : [], $currentLogoPath, $removeLogo, __DIR__);
            if (!$logoResult["ok"]) {
                $settingsErrMsg = (string)($logoResult["message"] ?? "No se pudo actualizar el logo.");
            }
        }
    }
    if (admin_wants_json_response()) {
        if ($settingsErrMsg !== "") {
            admin_json_response(["ok" => false, "message" => $settingsErrMsg], 400);
        }
        $fresh = site_settings_get($conn) ?? site_settings_defaults();
        admin_json_response([
            "ok" => true,
            "message" => "Configuración general actualizada.",
            "title" => "Configuración guardada",
            "settings" => [
                "logo_image_path" => (string)($fresh["logo_image_path"] ?? ""),
            ],
        ]);
    }
    if ($settingsErrMsg !== "") {
        $error = $settingsErrMsg;
    } else {
        $message = "Configuración general actualizada.";
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "change_admin_credentials") {
    $credErr = "";
    $credOk = "";
    $currentSessionEmail = (string)($_SESSION["admin_email"] ?? "");
    $newEmail = trim((string)($_POST["new_admin_email"] ?? ""));
    $currentPassword = (string)($_POST["current_admin_password"] ?? "");
    $newPassword = (string)($_POST["new_admin_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_admin_password"] ?? "");

    if ($currentSessionEmail === "") {
        $credErr = "Sesion invalida. Vuelve a iniciar sesion.";
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $credErr = "Ingresa un correo valido para el admin.";
    } elseif ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
        $credErr = "Completa todos los campos para cambiar credenciales.";
    } elseif ($newPassword !== $confirmPassword) {
        $credErr = "La nueva clave y su confirmacion no coinciden.";
    } elseif (strlen($newPassword) < 10) {
        $credErr = "La nueva clave debe tener al menos 10 caracteres.";
    } elseif (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        $credErr = "La nueva clave debe incluir mayuscula, minuscula y numero.";
    } else {
        $stmt = $conn->prepare("SELECT id, password FROM admins WHERE email = ? LIMIT 1");
        if ($stmt === false) {
            $credErr = "No se pudo validar el usuario admin.";
        } else {
            $stmt->bind_param("s", $currentSessionEmail);
            $stmt->execute();
            $adminResult = $stmt->get_result();
            $stmt->close();

            if (!$adminResult || $adminResult->num_rows !== 1) {
                $credErr = "No se encontro la cuenta admin actual.";
            } else {
                $adminRow = $adminResult->fetch_assoc();
                $adminId = (int)($adminRow["id"] ?? 0);
                $storedPassword = (string)($adminRow["password"] ?? "");
                $validCurrentPassword = false;

                if ($storedPassword !== "") {
                    $validCurrentPassword = password_verify($currentPassword, $storedPassword) || hash_equals($storedPassword, $currentPassword);
                }

                if (!$validCurrentPassword) {
                    $credErr = "La clave actual no es correcta.";
                } else {
                    $emailCheckStmt = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1");
                    if ($emailCheckStmt === false) {
                        $credErr = "No se pudo validar el nuevo correo.";
                    } else {
                        $emailCheckStmt->bind_param("si", $newEmail, $adminId);
                        $emailCheckStmt->execute();
                        $emailExistsResult = $emailCheckStmt->get_result();
                        $emailCheckStmt->close();

                        if ($emailExistsResult && $emailExistsResult->num_rows > 0) {
                            $credErr = "Ese correo ya esta en uso por otro admin.";
                        } else {
                            $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                            if ($newHashedPassword === false) {
                                $credErr = "No se pudo asegurar la nueva clave.";
                            } else {
                                $updateStmt = $conn->prepare("UPDATE admins SET email = ?, password = ? WHERE id = ?");
                                if ($updateStmt === false) {
                                    $credErr = "No se pudieron actualizar las credenciales.";
                                } else {
                                    $updateStmt->bind_param("ssi", $newEmail, $newHashedPassword, $adminId);
                                    $updated = $updateStmt->execute();
                                    $updateStmt->close();
                                    if ($updated) {
                                        $_SESSION["admin_email"] = $newEmail;
                                        $credOk = "Credenciales de admin actualizadas correctamente.";
                                    } else {
                                        $credErr = "No se pudieron guardar las nuevas credenciales.";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    if (admin_wants_json_response()) {
        if ($credErr !== "") {
            admin_json_response(["ok" => false, "message" => $credErr], 400);
        }
        if ($credOk !== "") {
            admin_json_response([
                "ok" => true,
                "message" => $credOk,
                "title" => "Credenciales actualizadas",
                "admin_email" => $newEmail,
            ]);
        }
        admin_json_response(["ok" => false, "message" => "No se procesó el formulario."], 400);
    }
    if ($credErr !== "") {
        $error = $credErr;
    } elseif ($credOk !== "") {
        $message = $credOk;
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "client_delete") {
    $cid = (int)($_POST["client_id"] ?? 0);
    $deleted = clients_admin_delete($conn, $cid);
    if (!$deleted["ok"]) {
        $errMsg = $cid <= 0 ? "Cliente no válido." : "No se pudo eliminar el cliente.";
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        admin_set_flash("error", $errMsg);
    } else {
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => "Cliente eliminado.",
                "title" => "Cliente eliminado",
                "client_id" => $cid,
            ]);
        }
        admin_set_flash("success", "Cliente eliminado.");
    }
    header("Location: admin.php#admin-tool-clients");
    exit;
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "client_toggle_active") {
    $cid = (int)($_POST["client_id"] ?? 0);
    $toggled = clients_admin_toggle_active($conn, $cid);
    if (!$toggled["ok"]) {
        $errMsg = $cid <= 0 ? "Cliente no válido." : "No se pudo actualizar la cuenta.";
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        admin_set_flash("error", $errMsg);
    } else {
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => "Estado de la cuenta actualizado.",
                "title" => "Cuenta actualizada",
                "toggle" => "active",
                "client" => $toggled["client"] ?? null,
            ]);
        }
        admin_set_flash("success", "Estado de la cuenta actualizado.");
    }
    header("Location: admin.php#admin-tool-clients");
    exit;
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "add_service") {
    $created = services_create($conn, [
        "title" => $_POST["title"] ?? "",
        "description" => $_POST["description"] ?? "",
        "icon_class" => $_POST["icon_class"] ?? "fa-solid fa-star",
        "sort_order" => $_POST["sort_order"] ?? 999,
        "is_active" => isset($_POST["is_active"]),
    ], $_FILES["image_file"] ?? [], __DIR__);
    if (!$created["ok"]) {
        $code = (string)($created["error"] ?? "");
        if ($code === "missing_fields") {
            $errMsg = "Título y descripción son obligatorios.";
        } else {
            $errMsg = (string)($created["message"] ?? "No se pudo agregar el servicio.");
        }
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "error" => $code, "message" => $errMsg], 400);
        }
        $error = $errMsg;
    } else {
        $newId = (int)($created["service_id"] ?? 0);
        if (admin_wants_json_response()) {
            $svcPayload = null;
            if ($newId > 0) {
                $got = services_get_with_gallery($conn, $newId);
                if ($got["ok"]) {
                    $svcPayload = services_format_for_api($got["service"], $got["gallery"]);
                }
            }
            admin_json_response([
                "ok" => true,
                "message" => "Servicio agregado.",
                "title" => "Servicio creado",
                "service_id" => $newId,
                "service" => $svcPayload,
            ]);
        }
        $message = "Servicio agregado.";
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "save_services") {
    $servicesRedirect = admin_workspace_url("manage", "", "admin-tool-service-edit");
    if (!isset($_POST["services"]) || !is_array($_POST["services"])) {
        $errMsg = "No se recibieron los datos del formulario. Si hay muchas imágenes en la galería, revisa max_input_vars en PHP.";
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        admin_set_flash("error", $errMsg);
        header("Location: " . $servicesRedirect);
        exit;
    }
    $onlyServiceId = isset($_POST["save_service_id"]) ? (int)$_POST["save_service_id"] : null;
    if ($onlyServiceId !== null && $onlyServiceId <= 0) {
        $onlyServiceId = null;
    }
    $batch = services_save_batch_from_post($conn, $_POST, $_FILES, __DIR__, $onlyServiceId);
    if (!$batch["ok"]) {
        $errMsg = trim((string)($batch["message"] ?? ""));
        if ($errMsg === "") {
            $code = (string)($batch["error"] ?? "");
            $errMsg = match ($code) {
                "reorder_failed" => "No se pudo actualizar el orden del carrusel. El resto del servicio puede haberse guardado; recarga y revisa.",
                "gallery_failed", "image_upload_failed" => "No se pudo subir una imagen del carrusel. El resto del servicio puede haberse guardado; recarga y revisa.",
                "missing_services" => "No se recibieron los datos del formulario. Si hay muchas imágenes en la galería, revisa max_input_vars en PHP.",
                default => "No se pudieron actualizar los servicios.",
            };
        }
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => false,
                "error" => (string)($batch["error"] ?? "update_failed"),
                "message" => $errMsg,
            ], 400);
        }
        admin_set_flash("error", $errMsg);
    } else {
        $okMsg = (string)($batch["message"] ?? "Servicios actualizados.");
        if (admin_wants_json_response()) {
            $payload = [
                "ok" => true,
                "message" => $okMsg,
                "title" => $onlyServiceId !== null ? "Servicio actualizado" : "Servicios actualizados",
                "service_id" => $onlyServiceId,
            ];
            if (!empty($batch["service"]) && is_array($batch["service"])) {
                $payload["service"] = $batch["service"];
            }
            admin_json_response($payload);
        }
        admin_set_flash("success", $okMsg);
    }
    header("Location: " . $servicesRedirect);
    exit;
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "delete_service") {
    $serviceId = (int)($_POST["service_id"] ?? 0);
    $deleted = services_delete($conn, $serviceId);
    if ($deleted["ok"]) {
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => "Servicio eliminado.",
                "title" => "Servicio eliminado",
                "service_id" => $serviceId,
            ]);
        }
        admin_set_flash("success", "Servicio eliminado.");
    } else {
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => false,
                "message" => "No se pudo eliminar el servicio.",
                "error" => (string)($deleted["error"] ?? "delete_failed"),
            ], 400);
        }
        admin_set_flash("error", "No se pudo eliminar el servicio.");
    }
    header("Location: " . admin_workspace_url("manage", "", "admin-tool-service-edit"));
    exit;
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "add_expert") {
    $svcList = $_POST["expert_services"] ?? [];
    if (!is_array($svcList)) {
        $svcList = [];
    }
    $created = experts_admin_create($conn, $_POST, $svcList);
    if (!$created["ok"]) {
        $code = (string)($created["error"] ?? "");
        if ($code === "missing_display_name") {
            $errMsg = "Indica el nombre visible del experto.";
        } elseif ($code === "invalid_email") {
            $errMsg = "El correo del experto no es válido.";
        } elseif ($code === "services_link_failed") {
            $errMsg = "No se pudo vincular servicios al experto.";
        } else {
            $errMsg = "No se pudo crear el experto.";
        }
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        $error = $errMsg;
    } else {
        $newExpertId = (int)$created["expert_id"];
        $okMsg = "Experto creado con jornada L–V por defecto (9:00–18:00). Ajusta en su ficha si hace falta.";
        $redirectUrl = admin_agenda_expert_url($newExpertId, "datos");
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => $okMsg,
                "title" => "Experto creado",
                "expert_id" => $newExpertId,
                "redirect" => $redirectUrl,
            ]);
        }
        admin_set_flash("success", $okMsg);
        header("Location: " . $redirectUrl);
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "save_expert") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $svcList = $_POST["expert_services"] ?? [];
    if (!is_array($svcList)) {
        $svcList = [];
    }
    $updated = experts_admin_update($conn, $eid, $_POST, $svcList);
    if (!$updated["ok"]) {
        $code = (string)($updated["error"] ?? "");
        if ($code === "not_found" || $code === "invalid_expert") {
            $errMsg = $eid <= 0 ? "Experto no válido." : "Ese experto no existe.";
        } elseif ($code === "invalid_email") {
            $errMsg = "El correo del experto no es válido.";
        } elseif ($code === "services_link_failed") {
            $errMsg = "No se pudo actualizar los servicios del experto.";
        } else {
            $errMsg = "No se pudo guardar el experto.";
        }
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        $error = $errMsg;
    } else {
        $expertPayload = null;
        $got = experts_admin_get($conn, $eid);
        if ($got["ok"]) {
            $svcByExpert = experts_admin_service_ids_by_expert($conn, [$eid]);
            $expertPayload = experts_admin_format_expert($got["expert"], $svcByExpert[$eid] ?? []);
        }
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => "Experto actualizado.",
                "title" => "Experto guardado",
                "expert_id" => $eid,
                "expert" => $expertPayload,
            ]);
        }
        admin_set_flash("success", "Experto actualizado.");
        $returnTo = trim((string)($_POST["return_to"] ?? ""));
        if ($returnTo === "agendas") {
            $tab = trim((string)($_POST["expert_tab"] ?? "datos"));
            header("Location: " . admin_agenda_expert_url($eid, $tab === "schedule" ? "schedule" : "datos"));
        } else {
            header("Location: " . admin_expert_page_url($eid, "edit"));
        }
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "delete_expert") {
    $expertId = (int)($_POST["expert_id"] ?? 0);
    $deleted = experts_admin_delete($conn, $expertId);
    if ($deleted["ok"]) {
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => "Experto eliminado.",
                "title" => "Experto eliminado",
                "expert_id" => $expertId,
            ]);
        }
        admin_set_flash("success", "Experto eliminado.");
        header("Location: admin.php#admin-experts-list");
        exit;
    }
    if (admin_wants_json_response()) {
        admin_json_response(["ok" => false, "message" => "No se pudo eliminar el experto."], 400);
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_add_availability") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $added = experts_admin_add_weekly_availability(
        $conn,
        $eid,
        (int)($_POST["weekday"] ?? -1),
        trim((string)($_POST["start_time"] ?? "")),
        trim((string)($_POST["end_time"] ?? ""))
    );
    if (!$added["ok"]) {
        $code = (string)($added["error"] ?? "");
        if ($code === "not_found" || $code === "invalid_expert") {
            $error = $eid <= 0 ? "Experto no válido." : "Ese experto no existe.";
        } elseif ($code === "invalid_weekday") {
            $error = "El día de la semana no es válido.";
        } elseif ($code === "invalid_time_range") {
            $error = "La hora de fin debe ser posterior a la de inicio.";
        } elseif ($code === "invalid_time") {
            $error = "Indica hora de inicio y fin con formato HH:MM.";
        } else {
            $error = "No se pudo guardar la franja.";
        }
    } else {
        admin_set_flash("success", "Franja de disponibilidad añadida.");
        header("Location: " . admin_expert_page_url($eid, "schedule", "", "template"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_add_availability_date") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $mode = trim((string)($_POST["date_av_mode"] ?? ""));
    $saved = experts_admin_add_date_exception($conn, $eid, $_POST);
    if (!$saved["ok"]) {
        $code = (string)($saved["error"] ?? "");
        if ($code === "not_found" || $code === "invalid_expert") {
            $error = $eid <= 0 ? "Experto no válido." : "Ese experto no existe.";
        } elseif ($code === "invalid_date") {
            $error = "Fecha no válida.";
        } elseif ($code === "date_out_of_range") {
            $error = "La fecha debe estar entre hoy y " . (string)AGENDA_DATE_EXCEPTION_MAX_DAYS . " días a futuro.";
        } elseif ($code === "invalid_mode") {
            $error = "Indica si el día queda cerrado o con franjas horarias concretas.";
        } elseif ($code === "invalid_time_range") {
            $error = "La hora de fin debe ser posterior a la de inicio.";
        } elseif ($code === "invalid_time") {
            $error = "Indica hora de inicio y fin (HH:MM).";
        } else {
            $error = "No se pudo actualizar la agenda por fecha.";
        }
    } else {
        admin_set_flash("success", $mode === "closed" ? "Día marcado como cerrado en la agenda." : "Franja por fecha añadida.");
        header("Location: " . admin_expert_page_url($eid, "schedule", "", "dates"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_delete_availability_date") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $did = (int)($_POST["av_date_id"] ?? 0);
    if ($eid > 0 && $did > 0) {
        experts_admin_delete_date_exception($conn, $eid, $did);
        admin_set_flash("success", "Cambio por fecha eliminado.");
        header("Location: " . admin_expert_page_url($eid, "schedule", "", "dates"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_set_mon_fri_window") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $set = experts_admin_set_mon_fri_window($conn, $eid, $_POST);
    if (!$set["ok"]) {
        $code = (string)($set["error"] ?? "");
        $errMsg = $eid <= 0 && ($code === "not_found" || $code === "invalid_expert")
            ? "Experto no válido."
            : admin_expert_template_error_message($code, false);
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        $error = $errMsg;
    } else {
        $successMsg = "Plantilla semanal actualizada para los días seleccionados.";
        if (admin_wants_json_response()) {
            $weeklyRows = experts_admin_list_weekly_availability($conn, $eid);
            admin_json_response([
                "ok" => true,
                "message" => $successMsg,
                "title" => "Plantilla guardada",
                "weekly" => experts_admin_weekly_schedule_client_payload($weeklyRows),
                "expert_id" => $eid,
            ]);
        }
        admin_set_flash("success", $successMsg);
        header("Location: " . admin_expert_page_url($eid, "schedule", "", "template"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "save_agenda_display") {
    $showNames = isset($_POST["agenda_show_expert_names"]);
    $agResult = site_settings_set_agenda_show_expert_names($conn, $showNames);
    if (!$agResult["ok"]) {
        $error = "No se pudo guardar la opción de agenda pública.";
    } else {
        admin_set_flash(
            "success",
            $showNames
                ? "La agenda pública mostrará el nombre de cada experto."
                : "La agenda pública será anónima (solo servicio y horario)."
        );
        header("Location: " . admin_workspace_url("agendas", "", "agenda_acc_public"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "bulk_mon_fri_all_experts") {
    $bulk = experts_admin_bulk_mon_fri_all($conn, $_POST);
    if (!$bulk["ok"]) {
        $code = (string)($bulk["error"] ?? "");
        $errMsg = admin_expert_template_error_message($code, true);
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        $error = $errMsg;
    } else {
        $n = (int)($bulk["experts_updated"] ?? 0);
        admin_set_flash("success", "Plantilla aplicada a " . $n . " experto(s).");
        header("Location: " . admin_workspace_url("agendas", "", "agenda_acc_bulk"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_delete_availability") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $avid = (int)($_POST["availability_id"] ?? 0);
    if ($eid > 0 && $avid > 0) {
        experts_admin_delete_weekly_availability($conn, $eid, $avid);
        admin_set_flash("success", "Franja eliminada.");
        header("Location: " . admin_expert_page_url($eid, "schedule", "", "template"));
        exit;
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "agenda_mark_notifications_read") {
    $deliveryId = (int)($_POST["delivery_id"] ?? 0);
    agenda_notifications_mark_admin_read($conn, $deliveryId > 0 ? $deliveryId : null);
    admin_set_flash("success", "Avisos de agenda marcados como leídos.");
    $notifyReturn = trim((string)($_POST["notify_return"] ?? ""));
    header(
        "Location: " . ($notifyReturn === "top" ? "admin.php" : "admin.php#agenda_acc_notify")
    );
    exit;
}

/** Redirección tras cambiar estado de una cita. */
function admin_expert_appointment_redirect(int $expertId, string $returnView, string $weekBack = ""): void
{
    if ($returnView === "edit") {
        header("Location: " . admin_expert_page_url($expertId, "edit"));
        exit;
    }
    if ($returnView === "list") {
        header("Location: admin.php#expert_acc_appointments");
        exit;
    }
    $schSec = $weekBack !== "" ? "week" : "appts";
    header("Location: " . admin_expert_page_url($expertId, "schedule", $weekBack, $schSec));
    exit;
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_cancel_appointment") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $apid = (int)($_POST["appointment_id"] ?? 0);
    if ($eid > 0 && $apid > 0) {
        experts_admin_cancel_appointment($conn, $eid, $apid);
        admin_set_flash("success", "Cita cancelada.");
        admin_expert_appointment_redirect(
            $eid,
            trim((string)($_POST["expert_return_view"] ?? "schedule")),
            trim((string)($_POST["expert_week"] ?? ""))
        );
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_complete_appointment") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $apid = (int)($_POST["appointment_id"] ?? 0);
    if ($eid > 0 && $apid > 0) {
        $done = experts_admin_complete_appointment($conn, $eid, $apid);
        if (!$done["ok"]) {
            admin_set_flash("error", "No se pudo marcar la cita como terminada.");
        } elseif (!($done["updated"] ?? false)) {
            admin_set_flash("warning", "La cita ya no admite ese cambio de estado.");
        } else {
            admin_set_flash("success", "Cita marcada como terminada.");
        }
        admin_expert_appointment_redirect(
            $eid,
            trim((string)($_POST["expert_return_view"] ?? "schedule")),
            trim((string)($_POST["expert_week"] ?? ""))
        );
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "expert_postpone_appointment") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $apid = (int)($_POST["appointment_id"] ?? 0);
    $newStarts = trim((string)($_POST["new_starts_at"] ?? ""));
    if ($eid > 0 && $apid > 0) {
        $postpone = experts_admin_postpone_appointment($conn, $eid, $apid, $newStarts);
        if (!$postpone["ok"]) {
            $code = (string)($postpone["error"] ?? "");
            if ($code === "invalid_datetime") {
                admin_set_flash("error", "Indica fecha y hora válidas para la nueva cita.");
            } elseif ($code === "slot_taken") {
                admin_set_flash("error", "Ese hueco ya está ocupado. Elige otra fecha u hora.");
            } elseif ($code === "invalid_status") {
                admin_set_flash("warning", "La cita ya no se puede posponer.");
            } else {
                admin_set_flash("error", "No se pudo posponer la cita.");
            }
        } else {
            admin_set_flash("success", "Cita pospuesta al nuevo horario.");
        }
        admin_expert_appointment_redirect(
            $eid,
            trim((string)($_POST["expert_return_view"] ?? "schedule")),
            trim((string)($_POST["expert_week"] ?? ""))
        );
    }
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_message_read") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $mark = admin_inbox_mark_read($conn, $messageId, true);
    if ($isAjax) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$mark["ok"],
            "id" => $messageId,
            "affected" => (int)($mark["affected"] ?? 0),
            "err" => (string)($mark["error"] ?? ""),
            "logged" => true,
            "unread_total" => (int)($mark["counts"]["unread"] ?? 0),
            "messages_total" => (int)($mark["counts"]["total"] ?? 0),
        ]);
        exit;
    }
    admin_set_flash("success", "Mensaje marcado como leído.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_messages_read") {
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $mark = admin_inbox_mark_all($conn, true);
    if ($isAjax) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$mark["ok"],
            "unread_total" => (int)($mark["counts"]["unread"] ?? 0),
            "messages_total" => (int)($mark["counts"]["total"] ?? 0),
        ]);
        exit;
    }
    admin_set_flash("success", "Todos los mensajes marcados como leídos.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_message_unread") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $mark = admin_inbox_mark_read($conn, $messageId, false);
    if ($isAjax) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$mark["ok"],
            "id" => $messageId,
            "unread_total" => (int)($mark["counts"]["unread"] ?? 0),
            "messages_total" => (int)($mark["counts"]["total"] ?? 0),
        ]);
        exit;
    }
    admin_set_flash("success", "Mensaje marcado como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_messages_unread") {
    admin_inbox_mark_all($conn, false);
    admin_set_flash("success", "Todos los mensajes marcados como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "delete_message") {
    admin_inbox_delete_message($conn, (int)($_POST["message_id"] ?? 0));
    admin_set_flash("success", "Mensaje eliminado.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "delete_whatsapp_click") {
    $whatsappClickId = (int)($_POST["whatsapp_click_id"] ?? 0);
    if ($whatsappClickId > 0) {
        whatsapp_admin_delete($conn, $whatsappClickId);
    }
    admin_set_flash("success", "Entrada eliminada.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_whatsapp_read") {
    $whatsappClickId = (int)($_POST["whatsapp_click_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $result = $whatsappClickId > 0 ? whatsapp_admin_set_read($conn, $whatsappClickId, true) : ["ok" => false];
    if ($isAjax) {
        $counts = $result["counts"] ?? whatsapp_admin_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)($result["ok"] ?? false),
            "id" => $whatsappClickId,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Marcado como leído.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_whatsapp_unread") {
    $whatsappClickId = (int)($_POST["whatsapp_click_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $result = $whatsappClickId > 0 ? whatsapp_admin_set_read($conn, $whatsappClickId, false) : ["ok" => false];
    if ($isAjax) {
        $counts = $result["counts"] ?? whatsapp_admin_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)($result["ok"] ?? false),
            "id" => $whatsappClickId,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Marcado como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_whatsapp_read") {
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $result = whatsapp_admin_set_all_read($conn, true);
    if ($isAjax) {
        $counts = $result["counts"] ?? whatsapp_admin_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)($result["ok"] ?? false),
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Todos los clics de WhatsApp marcados como leídos.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_whatsapp_unread") {
    whatsapp_admin_set_all_read($conn, false);
    admin_set_flash("success", "Todos los clics de WhatsApp marcados como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "client_toggle_email_notify") {
    $cid = (int)($_POST["client_id"] ?? 0);
    $toggled = clients_admin_toggle_email_notify($conn, $cid);
    if (!$toggled["ok"]) {
        $errMsg = $cid <= 0 ? "Cliente no válido." : "No se pudo actualizar la preferencia de correo.";
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        admin_set_flash("error", $errMsg);
    } else {
        if (admin_wants_json_response()) {
            admin_json_response([
                "ok" => true,
                "message" => "Preferencia de envío por SMTP actualizada.",
                "title" => "Correo SMTP",
                "toggle" => "email_notify",
                "client" => $toggled["client"] ?? null,
            ]);
        }
        admin_set_flash("success", "Preferencia de envío por SMTP al cliente actualizada.");
    }
    header("Location: admin.php#admin-tool-clients");
    exit;
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "reply_contact_message") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    $replyBodyRaw = (string)($_POST["reply_body"] ?? "");
    $reply = admin_inbox_reply_message($conn, $messageId, $replyBodyRaw);
    if (!$reply["ok"]) {
        $errMsg = (string)($reply["message"] ?? "No se pudo enviar la respuesta.");
        if (admin_wants_json_response()) {
            admin_json_response(["ok" => false, "message" => $errMsg], 400);
        }
        admin_set_flash("error", $errMsg);
        admin_redirect_after_action();
    }
    $notice = (string)($reply["notice"] ?? "Respuesta guardada.");
    $emailSent = !empty($reply["email_sent"]);
    if (admin_wants_json_response()) {
        $replyLabel = date("d/m/Y H:i");
        admin_json_response([
            "ok" => true,
            "message" => $notice,
            "title" => $emailSent ? "Respuesta enviada" : "Respuesta guardada",
            "toast_type" => $emailSent ? "success" : "warning",
            "message_id" => $messageId,
            "reply" => [
                "id" => (int)($reply["reply_id"] ?? 0),
                "body" => $replyBodyRaw,
                "created_label" => $replyLabel,
            ],
        ]);
    }
    $flashType = $emailSent ? "success" : "warning";
    admin_set_flash($flashType, $notice);
    admin_redirect_after_action();
}

$settings = site_settings_get($conn) ?? site_settings_defaults();

$servicesCatalog = services_load_admin_catalog($conn);
$services = $servicesCatalog["services"];
$galleryByService = $servicesCatalog["gallery_by_service"];

$experts = [];
$expertServiceIds = [];
$expertEditId = 0;
$expertEdit = null;
$expertEditNotFound = false;
$expertsPanelOpen = false;
$expertAvailabilityRows = [];
$expertAvailabilityDateRows = [];
$expertAppointmentsUpcoming = [];
$allExpertsAppointmentsUpcoming = [];
$expertWeekGrid = [
    "week_start" => "",
    "week_end" => "",
    "week_label" => "",
    "days" => [],
    "rows" => [],
];
$expertView = "";
$expertScheduleSection = "";
$agendasExpertId = 0;
$agendasExpert = null;
$agendasExpertNotFound = false;
$agendasExpertTab = "schedule";
$agendasAvailabilityRows = [];
$agendasAvailabilityDateRows = [];
$agendasAppointmentsUpcoming = [];
$agendasWeekGrid = [
    "week_start" => "",
    "week_end" => "",
    "week_label" => "",
    "days" => [],
    "rows" => [],
];
if ($isLogged && $adminExpertAgendaUi) {
    $expertEditId = (int)($_GET["expert_id"] ?? 0);
    $expertView = trim((string)($_GET["expert_view"] ?? ""));
    if (!in_array($expertView, ["edit", "schedule"], true)) {
        $expertView = "";
    }
    if ($expertView === "schedule") {
        $secRaw = trim((string)($_GET["expert_section"] ?? ""));
        if (in_array($secRaw, ["appts", "week", "template", "daily", "dates"], true)) {
            $expertScheduleSection = $secRaw;
        }
    }
    $expertsCatalog = experts_admin_load_admin_catalog($conn);
    $experts = $expertsCatalog["raw_experts"];
    foreach ($expertsCatalog["expert_service_ids"] as $eid => $sidList) {
        $expertServiceIds[(int)$eid] = [];
        foreach ($sidList as $sid) {
            $expertServiceIds[(int)$eid][(int)$sid] = true;
        }
    }
    if ($expertEditId > 0) {
        foreach ($experts as $er) {
            if ((int)($er["id"] ?? 0) === $expertEditId) {
                $expertEdit = $er;
                break;
            }
        }
        if ($expertEdit === null) {
            $got = experts_admin_get($conn, $expertEditId);
            if ($got["ok"]) {
                $expertEdit = $got["expert"];
            } else {
                $expertEditNotFound = true;
            }
        }
    }
    if (count($experts) > 0) {
        $allExpertsAppointmentsUpcoming = experts_admin_fetch_all_upcoming_appointments($conn);
    }
    if ($expertEditId > 0 && $expertEdit !== null && $expertView === "schedule") {
        $expertWeekParam = trim((string)($_GET["expert_week"] ?? ""));
        $sched = experts_admin_load_schedule($conn, $expertEditId, $expertWeekParam);
        if ($sched["ok"]) {
            $expertAvailabilityRows = $sched["schedule"]["weekly_availability"];
            $expertAvailabilityDateRows = $sched["schedule"]["date_exceptions"];
            $expertAppointmentsUpcoming = $sched["schedule"]["upcoming_appointments"];
            $expertWeekGrid = $sched["schedule"]["week_grid"];
        }
    } elseif ($expertEditId > 0 && $expertEdit !== null && $expertView === "edit") {
        $expertAppointmentsUpcoming = experts_admin_fetch_upcoming_appointments_for_expert($conn, $expertEditId);
    }
    $expertsPanelOpen = $expertView === "edit" && $expertEditId > 0;

    $agendasExpertId = $expertEditId > 0 ? $expertEditId : 0;
    if ($agendasExpertId <= 0 && count($experts) > 0) {
        $agendasExpertId = (int)($experts[0]["id"] ?? 0);
    }
    $agendasExpertTab = trim((string)($_GET["expert_tab"] ?? "schedule"));
    if (!in_array($agendasExpertTab, ["schedule", "datos"], true)) {
        $agendasExpertTab = "schedule";
    }
    if ($agendasExpertId > 0) {
        foreach ($experts as $er) {
            if ((int)($er["id"] ?? 0) === $agendasExpertId) {
                $agendasExpert = $er;
                break;
            }
        }
        if ($agendasExpert === null) {
            $gotAg = experts_admin_get($conn, $agendasExpertId);
            if ($gotAg["ok"]) {
                $agendasExpert = $gotAg["expert"];
            } else {
                $agendasExpertNotFound = true;
            }
        }
    }
    if ($agendasExpert !== null && $agendasExpertTab === "schedule") {
        $agendasWeekParam = trim((string)($_GET["expert_week"] ?? ""));
        $agSched = experts_admin_load_schedule($conn, $agendasExpertId, $agendasWeekParam);
        if ($agSched["ok"]) {
            $agendasAvailabilityRows = $agSched["schedule"]["weekly_availability"];
            $agendasAvailabilityDateRows = $agSched["schedule"]["date_exceptions"];
            $agendasAppointmentsUpcoming = $agSched["schedule"]["upcoming_appointments"];
            $agendasWeekGrid = $agSched["schedule"]["week_grid"];
        }
        $secRawAg = trim((string)($_GET["expert_section"] ?? ""));
        if (in_array($secRawAg, ["appts", "week", "template", "daily", "dates"], true)) {
            $expertScheduleSection = $secRawAg;
        }
    }
}

$agendaAdminNotifications = [];
$agendaAdminNotifyUnread = 0;
$adminAgendaHistoryUi = false;
$agendaAppointmentHistory = [];
if ($isLogged && $adminExpertAgendaUi) {
    $adminAgendaHistoryUi = true;
    $agendaAppointmentHistory = agenda_appointment_history_timeline($conn, 50);
}
if ($isLogged && $adminExpertAgendaUi && agenda_notifications_enabled()) {
    $agendaAdminNotifications = agenda_notifications_list_admin($conn, 60);
    $agendaAdminNotifyUnread = agenda_notifications_count_admin_unread($conn);
}

$adminWorkspaceNavItems = [];
$adminSidebarToolItems = [];
$adminSidebarAgendaItems = [];
$adminSidebarInboxItems = [];
if ($isLogged) {
    if ($adminExpertAgendaUi) {
        $adminWorkspaceNavItems[] = [
            "id" => "agendas",
            "label" => "Agendas",
            "icon" => "fa-calendar-days",
            "href" => admin_workspace_url("agendas", "", "admin-tools-agendas"),
        ];
        $adminSidebarAgendaItems[] = [
            "hash" => "admin-agendas-expert-workspace",
            "collapse" => "",
            "label" => "Horarios y citas",
            "icon" => "fa-calendar-week",
        ];
        if (agenda_notifications_enabled()) {
            $adminSidebarAgendaItems[] = [
                "hash" => "agenda_acc_notify",
                "collapse" => "agenda_acc_notify",
                "label" => "Avisos de agenda",
                "icon" => "fa-bell",
            ];
        }
        $adminSidebarAgendaItems[] = [
            "hash" => "agenda_acc_public",
            "collapse" => "agenda_acc_public",
            "label" => "Agenda pública",
            "icon" => "fa-globe",
        ];
        if (count($experts) > 0) {
            $adminSidebarAgendaItems[] = [
                "hash" => "agenda_acc_bulk",
                "collapse" => "agenda_acc_bulk",
                "label" => "Horario masivo",
                "icon" => "fa-clock",
            ];
        }
    }
    $adminWorkspaceNavItems[] = [
        "id" => "manage",
        "label" => "Gestión",
        "icon" => "fa-screwdriver-wrench",
        "href" => admin_workspace_url("manage"),
    ];
    if ($adminInboxUi) {
        $adminWorkspaceNavItems[] = [
            "id" => "inbox",
            "label" => "Bandeja",
            "icon" => "fa-inbox",
            "href" => admin_workspace_url("inbox"),
        ];
    }
    $adminSidebarToolItems = [
        ["hash" => "admin-tool-config", "panel" => "tools_config_panel", "label" => "Configuración", "icon" => "fa-gear"],
        ["hash" => "admin-tool-credentials", "panel" => "tools_credentials_panel", "label" => "Credenciales", "icon" => "fa-key"],
        ["hash" => "admin-tool-routes", "panel" => "tools_routes_panel", "label" => "Rutas", "icon" => "fa-link"],
        ["hash" => "admin-tool-service-edit", "panel" => "tools_edit_panel", "label" => "Servicios", "icon" => "fa-pen-to-square"],
    ];
    if ($adminExpertAgendaUi) {
        $adminSidebarToolItems[] = [
            "hash" => "admin-tools-experts",
            "panel" => "tools_experts_panel",
            "label" => "Expertos",
            "icon" => "fa-user-tie",
        ];
    } else {
        $adminSidebarToolItems[] = [
            "hash" => "admin-tool-experts-off",
            "panel" => "tools_expert_agenda_off_panel",
            "label" => "Expertos",
            "icon" => "fa-user-tie",
        ];
    }
    $adminSidebarToolItems[] = [
        "hash" => "admin-tool-clients",
        "panel" => "tools_clients_panel",
        "label" => "Clientes",
        "icon" => "fa-users",
    ];
    if ($adminInboxUi) {
        $adminSidebarInboxItems[] = [
            "hash" => "side-inbox-messages",
            "collapse" => "collapseSideMessages",
            "scroll" => "headingSideMessages",
            "label" => "Mensajes",
            "icon" => "fa-inbox",
        ];
    }
    if ($adminWhatsappClicksUi) {
        $adminSidebarInboxItems[] = [
            "hash" => "side-inbox-whatsapp",
            "collapse" => "collapseSideWhatsapp",
            "scroll" => "headingSideWhatsapp",
            "label" => "WhatsApp",
            "icon" => "fa-brands fa-whatsapp",
        ];
    }
    if ($adminAgendaHistoryUi) {
        $adminSidebarInboxItems[] = [
            "hash" => "side-inbox-agenda-history",
            "collapse" => "collapseSideAgendaHistory",
            "scroll" => "headingSideAgendaHistory",
            "label" => "Historial de citas",
            "icon" => "fa-calendar-check",
        ];
    }
}

$portalClients = $isLogged ? clients_admin_list($conn) : [];

$contactMessages = [];
$contactRepliesByMessageId = [];
$contactMessageGroups = [];
$contactMessagesUnread = 0;
if ($isLogged && $adminInboxUi) {
    $inboxData = admin_inbox_load($conn, 100, $portalClients);
    $contactMessages = $inboxData["messages"];
    $contactRepliesByMessageId = $inboxData["replies_by_message_id"];
    $contactMessageGroups = $inboxData["groups"];
    $contactMessagesUnread = (int)($inboxData["counts"]["unread"] ?? 0);
}

$whatsappClicks = [];
$whatsappClicksUnread = 0;
if ($isLogged && $adminWhatsappClicksUi) {
    $whatsappClicks = whatsapp_admin_list($conn, 100);
    $waCounts = whatsapp_admin_counts($conn);
    $whatsappClicksUnread = (int)($waCounts["unread"] ?? 0);
}

$waSideTotal = count($whatsappClicks);
$waSideUnread = $whatsappClicksUnread;
$waSideCounterClass = $waSideUnread > 0 ? "text-bg-warning" : "text-bg-secondary";
$waSideCounterTitle = $waSideUnread > 0
    ? sprintf("%d sin leer de %d", $waSideUnread, $waSideTotal)
    : sprintf("%d en total", $waSideTotal);

?>
<!doctype html>
<html lang="es" data-theme-context="admin">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    (function () {
      try {
        var mode = localStorage.getItem("admin-ui-mode") || "dark";
        var palette = localStorage.getItem("admin-ui-palette") || "blue";
        document.documentElement.setAttribute("data-theme", mode);
        document.documentElement.setAttribute("data-palette", palette);
      } catch (e) {}
    })();
  </script>
  <title>Panel de Administración</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="styles.css?v=<?= h($adminAssetStylesVer) ?>">
  <link rel="stylesheet" href="admin_filter_tables.css?v=<?= h($adminFilterTablesCssVer) ?>">
  <link rel="stylesheet" href="admin.css?v=<?= h($adminPanelCssVer) ?>">
</head>
<body>
  <?php if (!$isLogged): ?>
    <div class="login-shell">
      <div class="card login-card p-4 p-md-4">
        <div class="text-center mb-4">
          <div class="mb-2"><i class="fa-solid fa-shield-halved fa-2x text-light"></i></div>
          <h1 class="login-title mb-1">Panel de Administración</h1>
          <p class="login-subtitle mb-0">Ingresa para gestionar tu web personal.</p>
        </div>

        <?php if ($message !== ""): ?><p class="admin-msg"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ""): ?><p class="admin-err"><?= h($error) ?></p><?php endif; ?>

        <?php if ($resetTokenFromUrl !== ""): ?>
          <form method="post" class="d-grid gap-3">
            <input type="hidden" name="action" value="reset_admin_password">
            <input type="hidden" name="reset_token" value="<?= h($resetTokenFromUrl) ?>">
            <div>
              <label for="new_admin_password" class="form-label text-light fw-semibold">Nueva clave</label>
              <div class="password-wrap">
                <input id="new_admin_password" type="password" name="new_admin_password" class="form-control login-input" required minlength="10" autocomplete="new-password" placeholder="••••••••">
                <button type="button" class="password-toggle js-password-toggle" data-target="new_admin_password" aria-label="Mostrar clave" aria-pressed="false">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
            <div>
              <label for="confirm_admin_password" class="form-label text-light fw-semibold">Confirmar nueva clave</label>
              <div class="password-wrap">
                <input id="confirm_admin_password" type="password" name="confirm_admin_password" class="form-control login-input" required minlength="10" autocomplete="new-password" placeholder="••••••••">
                <button type="button" class="password-toggle js-password-toggle" data-target="confirm_admin_password" aria-label="Mostrar clave" aria-pressed="false">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              <div class="form-text text-light-emphasis">Mínimo 10 caracteres con mayúscula, minúscula y número.</div>
            </div>
            <button class="btn btn-primary btn-lg fw-semibold" type="submit">
              <i class="fa-solid fa-key me-2"></i>Restablecer clave
            </button>
            <div class="login-help">
              <a href="admin.php" class="login-link">Volver al inicio de sesión</a>
            </div>
          </form>
        <?php else: ?>
          <form method="post" class="d-grid gap-3 login-view" data-view="login" <?= $showResetView ? "hidden" : "" ?>>
            <input type="hidden" name="action" value="login">
            <div>
              <label for="email" class="form-label text-light fw-semibold">Correo</label>
              <input id="email" type="email" name="email" class="form-control login-input" required placeholder="tu@correo.com" autocomplete="username">
            </div>
            <div>
              <label for="password" class="form-label text-light fw-semibold">Clave</label>
              <div class="password-wrap">
                <input id="password" type="password" name="password" class="form-control login-input" required placeholder="••••••••" autocomplete="current-password">
                <button type="button" class="password-toggle js-password-toggle" data-target="password" aria-label="Mostrar clave" aria-pressed="false">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
            <button class="btn btn-primary btn-lg fw-semibold" type="submit">
              <i class="fa-solid fa-right-to-bracket me-2"></i>Iniciar sesión
            </button>
            <div class="login-help">
              <a href="#" class="login-link js-show-view" data-target-view="reset">¿Olvidaste tu clave?</a>
            </div>
          </form>

          <form method="post" class="d-grid gap-3 login-view" data-view="reset" <?= $showResetView ? "" : "hidden" ?>>
            <input type="hidden" name="action" value="request_admin_password_reset">
            <p class="login-subtitle small mb-0">Te enviaremos un enlace al correo del administrador para restablecer tu clave.</p>
            <div>
              <label for="reset_email" class="form-label text-light fw-semibold">Correo del administrador</label>
              <input id="reset_email" type="email" name="reset_email" class="form-control login-input" required placeholder="tu@correo.com" autocomplete="username">
            </div>
            <button class="btn btn-primary btn-lg fw-semibold" type="submit">
              <i class="fa-solid fa-envelope me-2"></i>Enviar enlace
            </button>
            <div class="login-help">
              <a href="#" class="login-link js-show-view" data-target-view="login">Volver al inicio de sesión</a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
  <div class="admin-app admin-wrap<?= $adminLayoutWide ? " admin-app--layout-wide" : "" ?>">
    <header class="admin-app-bar">
      <div class="admin-app-bar__inner">
        <div class="admin-app-bar__brand">
          <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i>
          <span class="admin-app-bar__title">Admin</span>
          <span class="admin-app-bar__session" title="<?= h($_SESSION["admin_email"] ?? "") ?>"><?= h($_SESSION["admin_email"] ?? "") ?></span>
        </div>
        <?php if (count($adminWorkspaceNavItems) > 1): ?>
          <nav class="admin-app-bar__workspaces" aria-label="Área de trabajo">
            <?php foreach ($adminWorkspaceNavItems as $wsItem): ?>
              <a
                href="<?= h($wsItem["href"]) ?>"
                class="admin-app-bar__ws-link<?= $adminWorkspace === $wsItem["id"] ? " is-active" : "" ?>"
                <?= $adminWorkspace === $wsItem["id"] ? ' aria-current="page"' : "" ?>
              >
                <i class="<?= h(admin_nav_icon_class($wsItem["icon"])) ?>" aria-hidden="true"></i>
                <span><?= h($wsItem["label"]) ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>
        <div class="admin-app-bar__actions">
          <?php if ($isLogged): ?>
            <?php if ($adminLayoutWide): ?>
              <a
                href="<?= h(admin_workspace_url($adminWorkspace, "", "", false)) ?>"
                class="btn btn-outline-secondary btn-sm admin-app-bar__layout-toggle"
                title="Volver al ancho habitual del panel"
              >
                <i class="fa-solid fa-compress" aria-hidden="true"></i>
                <span>Vista normal</span>
              </a>
            <?php else: ?>
              <a
                href="<?= h(admin_workspace_url($adminWorkspace, "", "", true)) ?>"
                class="btn btn-outline-secondary btn-sm admin-app-bar__layout-toggle"
                title="Usar todo el ancho disponible"
              >
                <i class="fa-solid fa-expand" aria-hidden="true"></i>
                <span>Vista completa</span>
              </a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (agenda_notifications_enabled()): ?>
            <?php
              $notifyBellId = "admin-agenda-notify";
              $notifyBellItems = $agendaAdminNotifications ?? [];
              $notifyBellUnread = (int)($agendaAdminNotifyUnread ?? 0);
              $notifyBellMarkAction = "agenda_mark_notifications_read";
              $notifyBellViewAllHref = admin_workspace_url("agendas", "", "agenda_acc_notify");
              $notifyBellLabel = "Avisos de agenda";
              require __DIR__ . "/partials/notify_bell.php";
            ?>
          <?php endif; ?>
          <?php require __DIR__ . "/palette_picker.php"; ?>
          <a href="admin.php?logout=1" class="btn btn-outline-secondary btn-sm admin-app-bar__logout" title="Cerrar sesión">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span class="ms-1">Salir</span>
          </a>
        </div>
      </div>
      <?php if ($message !== "" || $error !== ""): ?>
        <div class="admin-app-bar__alerts">
          <?php if ($message !== ""): ?><div class="alert <?= h($messageAlertClass) ?> mb-0"><?= h($message) ?></div><?php endif; ?>
          <?php if ($error !== ""): ?><div class="alert alert-danger mb-0"><?= h($error) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </header>

    <div class="admin-app-body">
      <?php require __DIR__ . "/partials/admin_app_sidebar.php"; ?>
      <main class="admin-app-main">
        <?php if ($adminWorkspace === "inbox"): ?>
        <div class="alert alert-info py-2 px-3 mb-3">
          <strong>Bandeja</strong>: mensajes, WhatsApp e historial de citas.
          <a href="<?= h(admin_workspace_url("manage")) ?>" class="alert-link ms-2">Ir a gestión</a>
        </div>
        <?php elseif ($adminWorkspace === "agendas"): ?>
        <div class="alert alert-info py-2 px-3 mb-3">
          <strong>Agendas</strong>: horarios y citas.
          <a href="<?= h(admin_workspace_url("manage")) ?>" class="alert-link ms-2">Ir a gestión</a>
        </div>
        <?php endif; ?>

    <div class="admin-layout admin-layout--workspace-<?= h($adminWorkspace) ?>">
      <div class="admin-main">
        <div class="accordion admin-tools-accordion admin-tools-ordered mb-3" id="adminToolsAccordion">

          <div class="accordion-item" id="admin-tool-routes">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_routes_panel"
                aria-expanded="false" aria-controls="tools_routes_panel">
                <i class="fa-solid fa-link me-2"></i>Rutas (landing y admin)
              </button>
            </h2>
            <div id="tools_routes_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-3"><?= h(app_public_url_source_description()) ?></p>
                <div class="mb-3">
                  <label class="form-label mb-1">Landing pública</label>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace small" readonly value="<?= h(app_landing_url()) ?>">
                    <a class="btn btn-outline-secondary" href="<?= h(app_landing_url()) ?>" target="_blank" rel="noopener">Abrir</a>
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label mb-1">Panel de administración</label>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace small" readonly value="<?= h(app_admin_url()) ?>">
                    <a class="btn btn-outline-secondary" href="<?= h(app_admin_url()) ?>" target="_blank" rel="noopener">Abrir</a>
                  </div>
                </div>
                <div class="mb-0">
                  <label class="form-label mb-1">Área de clientes en la landing</label>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace small" readonly value="<?= h(app_client_portal_url()) ?>">
                    <a class="btn btn-outline-secondary" href="<?= h(app_client_portal_url()) ?>" target="_blank" rel="noopener">Abrir</a>
                  </div>
                  <div class="form-text text-light-emphasis mt-1">Misma página pública: sección «Clientes».</div>
                </div>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-clients">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_clients_panel"
                aria-expanded="false" aria-controls="tools_clients_panel">
                <i class="fa-solid fa-user-group me-2"></i>Portal de clientes
              </button>
            </h2>
            <div id="tools_clients_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body admin-portal-clients-body">
                <p class="small text-light-emphasis mb-3">
                  Los visitantes se registran solos en la landing (sección <strong>Área de clientes</strong>).
                  Desde aquí solo moderas: desactivar o borrar cuentas. La sesión de cliente es independiente del admin.
                  En <strong>Cuenta</strong> y <strong>Correo SMTP</strong>, las pastillas muestran el estado actual: <strong>pulsa</strong> una para alternar (icono + texto).
                  <strong>Correo SMTP:</strong> si está en «Correo», al responder desde Mensajes se intentará enviar por SMTP; en «Solo web» la respuesta queda solo en la bandeja del área de cliente.
                </p>
                <?php include __DIR__ . "/partials/admin_portal_clients_table.php"; ?>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-config">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_config_panel"
                aria-expanded="false" aria-controls="tools_config_panel">
                <i class="fa-solid fa-sliders me-2"></i>Configuración general
              </button>
            </h2>
            <div id="tools_config_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <form
                  method="post"
                  enctype="multipart/form-data"
                  id="form-save-settings"
                  class="js-admin-ajax-form"
                  data-ajax-scope="settings"
                >
                  <input type="hidden" name="action" value="save_settings">
        <div class="row g-3">
          <div>
            <label class="form-label">Nombre persona</label>
            <input class="form-control" type="text" name="person_name" value="<?= h($settings["person_name"] ?? "") ?>" required>
          </div>
          <div>
            <label class="form-label">Marca / nombre corto</label>
            <input class="form-control" type="text" name="brand_name" value="<?= h($settings["brand_name"] ?? "") ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Correo receptor del formulario</label>
            <input class="form-control" type="email" name="contact_email" value="<?= h($settings["contact_email"] ?? "") ?>" required>
            <div class="form-text text-light-emphasis">Los mensajes del formulario se intentarán enviar aquí (al correo receptor).</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">WhatsApp de contacto (opcional)</label>
            <?php
            $waCcDigits = preg_replace('/\D+/', '', (string)($settings["contact_whatsapp_country_code"] ?? "")) ?? "";
            $waCcDigits = substr($waCcDigits, 0, 3);
            $waLocalDigits = preg_replace('/\D+/', '', (string)($settings["contact_whatsapp"] ?? "")) ?? "";
            ?>
            <div class="input-group wa-phone-input-group">
              <span class="input-group-text" title="Prefijo internacional">+</span>
              <input class="form-control wa-cc-field" type="text" name="contact_whatsapp_country" value="<?= h($waCcDigits) ?>" placeholder="57" maxlength="3" inputmode="numeric" pattern="[0-9]{0,3}" autocomplete="tel-country-code" aria-label="Indicativo (máx. 3 dígitos)">
              <input class="form-control wa-local-field" type="tel" name="contact_whatsapp_local" value="<?= h($waLocalDigits) ?>" placeholder="300 123 4567" inputmode="numeric" autocomplete="tel-national" aria-label="Número de teléfono (sin indicativo)">
            </div>
            <div class="form-text text-light-emphasis">Vacío: sin botón WhatsApp en la web.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Texto footer</label>
            <input class="form-control" type="text" name="footer_text" value="<?= h($settings["footer_text"] ?? "") ?>" required>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto principal hero</label>
          <textarea class="form-control" name="hero_title" rows="2" required><?= h($settings["hero_title"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto secundario hero</label>
          <textarea class="form-control" name="hero_intro" rows="2" required><?= h($settings["hero_intro"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto sobre mí</label>
          <textarea class="form-control" name="about_text" rows="3" required><?= h($settings["about_text"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto contacto</label>
          <textarea class="form-control" name="contact_intro" rows="2" required><?= h($settings["contact_intro"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Logo del sitio (opcional)</label>
          <input type="hidden" name="current_logo_image_path" value="<?= h((string)($settings["logo_image_path"] ?? "")) ?>">
          <?php if (!empty($settings["logo_image_path"])): ?>
            <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
              <img src="<?= h((string)$settings["logo_image_path"]) ?>" alt="Logo actual" class="logo-preview-admin">
              <label class="form-check m-0 d-inline-flex align-items-center gap-2">
                <input class="form-check-input" type="checkbox" name="remove_logo_image" value="1">
                <span class="form-check-label">Quitar logo personalizado</span>
              </label>
            </div>
          <?php endif; ?>
          <input class="form-control" type="file" name="logo_image_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
          <div class="form-text text-light-emphasis">
            Si no subes nada, la web muestra un logo automático con la(s) primera(s)
            letra(s) de la marca. Formatos: PNG, JPG, WEBP, GIF o SVG.
          </div>
        </div>
                  <button class="btn btn-primary mt-3" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar configuración</button>
                </form>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-credentials">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_credentials_panel"
                aria-expanded="false" aria-controls="tools_credentials_panel">
                <i class="fa-solid fa-key me-2"></i>Credenciales Admin
              </button>
            </h2>
            <div id="tools_credentials_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <form
                  method="post"
                  class="row g-3 js-admin-ajax-form"
                  data-ajax-scope="credentials"
                  id="form-admin-credentials"
                >
                  <input type="hidden" name="action" value="change_admin_credentials">
                  <div class="col-md-6">
                    <label class="form-label">Correo admin nuevo</label>
                    <input
                      class="form-control"
                      type="email"
                      name="new_admin_email"
                      value="<?= h((string)($_SESSION["admin_email"] ?? "")) ?>"
                      required
                    >
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Clave actual</label>
                    <input class="form-control" type="password" name="current_admin_password" autocomplete="current-password" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Clave nueva</label>
                    <input class="form-control" type="password" name="new_admin_password" autocomplete="new-password" minlength="10" required>
                    <div class="form-text text-light-emphasis">Minimo 10 caracteres con mayuscula, minuscula y numero.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Confirmar clave nueva</label>
                    <input class="form-control" type="password" name="confirm_admin_password" autocomplete="new-password" minlength="10" required>
                  </div>
                  <div class="col-12">
                    <button class="btn btn-primary" type="submit">
                      <i class="fa-solid fa-shield-halved me-2"></i>Actualizar credenciales
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-service-edit">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_edit_panel"
                aria-expanded="false" aria-controls="tools_edit_panel">
                <i class="fa-solid fa-briefcase me-2"></i>Servicios
              </button>
            </h2>
            <div id="tools_edit_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-3">
                  Pulsa <strong><i class="fa-solid fa-circle-plus me-1" aria-hidden="true"></i>Agregar servicio</strong> para desplegar el mismo esquema que al editar (vacío). Abajo, cada servicio guardado tiene su bloque con carrusel y opción de borrar.
                </p>
                <div class="mb-3">
                  <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-toggle="collapse"
                    data-bs-target="#collapse_new_service_panel"
                    aria-expanded="false"
                    aria-controls="collapse_new_service_panel"
                    title="Agregar servicio"
                  >
                    <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Agregar servicio
                  </button>
                </div>
                <div id="collapse_new_service_panel" class="collapse mb-4 service-add-panel-collapse">
                  <div class="border border-secondary rounded-3 p-3 bg-body-tertiary bg-opacity-25">
                    <form
                      method="post"
                      enctype="multipart/form-data"
                      id="form-add-service"
                      class="js-admin-ajax-form"
                      data-ajax-action="add_service"
                      data-ajax-reload-on-success="1"
                    >
                      <input type="hidden" name="action" value="add_service">
                      <div class="row g-3">
                        <div class="col-md-6">
                          <label class="form-label" for="new_service_title">Título</label>
                          <input id="new_service_title" class="form-control" type="text" name="title" placeholder="Título del servicio" required>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Icono</label>
                          <div id="new_service_icon_picker" class="icon-grid icon-picker" data-target-input="new_service_icon_input">
                            <?php foreach ($iconOptions as $iconClass => $iconLabel): ?>
                              <button type="button" class="icon-option <?= ($iconClass === "fa-solid fa-star") ? "is-active" : "" ?>" data-icon="<?= h($iconClass) ?>">
                                <i class="<?= h($iconClass) ?>"></i>
                              </button>
                            <?php endforeach; ?>
                          </div>
                          <input id="new_service_icon_input" type="hidden" name="icon_class" value="fa-solid fa-star">
                        </div>
                        <div class="col-md-2">
                          <label class="form-label" for="new_service_sort">Orden</label>
                          <input id="new_service_sort" class="form-control" type="number" name="sort_order" value="999" min="0" max="999999">
                        </div>
                        <div class="col-md-10">
                          <label class="form-label" for="new_service_description">Descripción</label>
                          <textarea id="new_service_description" class="form-control" name="description" rows="2" placeholder="Descripción visible en la web" required></textarea>
                        </div>
                        <div class="col-md-12">
                          <label class="form-label" for="new_service_image">Imagen del servicio (opcional)</label>
                          <input id="new_service_image" class="form-control" type="file" name="image_file" accept="image/png,image/jpeg,image/webp,image/gif">
                        </div>
                        <div class="col-md-12">
                          <label class="form-label">Carrusel / galería</label>
                          <p class="form-text text-light-emphasis mb-0">Cuando exista el servicio en el listado inferior, podrás subir imágenes, títulos y orden del carrusel desde su bloque.</p>
                        </div>
                        <div class="col-md-12">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_service_is_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_service_is_active">Activo</label>
                          </div>
                        </div>
                      </div>
                      <div class="admin-actions mt-3 mb-0 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">
                          <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Crear servicio
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="cancel_new_service_btn" aria-controls="collapse_new_service_panel">
                          <i class="fa-solid fa-xmark me-2" aria-hidden="true"></i>Cancelar
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
                <form
                  method="post"
                  enctype="multipart/form-data"
                  action="<?= h(admin_workspace_url("manage")) ?>"
                  class="js-admin-ajax-form"
                  data-ajax-action="save_services"
                  data-ajax-scope="service"
                  novalidate
                >
                  <input type="hidden" name="action" value="save_services">
        <div class="accordion admin-services-accordion" id="adminServicesAccordion">
        <?php foreach ($services as $service): ?>
          <?php
            $svcId = (int)$service["id"];
            $collapseId = "collapse_svc_" . $svcId;
          ?>
          <div class="accordion-item service-row">
            <h3 class="accordion-header m-0">
              <button
                class="accordion-button collapsed d-flex align-items-center gap-2"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#<?= h($collapseId) ?>"
                aria-expanded="false"
                aria-controls="<?= h($collapseId) ?>"
              >
                <i class="<?= h($service["icon_class"] ?: "fa-solid fa-star") ?>"></i>
                <span class="accordion-header-service-title"><?= h($service["title"]) ?></span>
                <?php if ((int)$service["is_active"] !== 1): ?>
                  <span class="badge text-bg-secondary ms-1">Inactivo</span>
                <?php endif; ?>
              </button>
            </h3>
            <div id="<?= h($collapseId) ?>" class="accordion-collapse collapse" data-bs-parent="#adminServicesAccordion">
              <div class="accordion-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Título</label>
                <input class="form-control" type="text" name="services[<?= (int)$service["id"] ?>][title]" value="<?= h($service["title"]) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Icono</label>
                <div class="icon-grid icon-picker" data-target-input="icon_input_<?= (int)$service["id"] ?>">
                  <?php foreach ($iconOptions as $iconClass => $iconLabel): ?>
                    <button type="button" class="icon-option <?= ($iconClass === $service["icon_class"]) ? "is-active" : "" ?>" data-icon="<?= h($iconClass) ?>">
                      <i class="<?= h($iconClass) ?>"></i>
                    </button>
                  <?php endforeach; ?>
                </div>
                <input
                  id="icon_input_<?= (int)$service["id"] ?>"
                  type="hidden"
                  name="services[<?= (int)$service["id"] ?>][icon_class]"
                  value="<?= h($service["icon_class"]) ?>"
                >
              </div>
              <div class="col-md-2">
                <label class="form-label">Orden</label>
                <input class="form-control" type="number" name="services[<?= (int)$service["id"] ?>][sort_order]" value="<?= (int)$service["sort_order"] ?>">
              </div>
              <div class="col-md-10">
                <label class="form-label">Descripción</label>
                <textarea class="form-control" name="services[<?= (int)$service["id"] ?>][description]" rows="2" required><?= h($service["description"]) ?></textarea>
              </div>
              <div class="col-md-12">
                <label class="form-label">Imagen del servicio</label>
                <?php if (!empty($service["image_path"])): ?>
                  <div class="mb-2">
                    <img src="<?= h($service["image_path"]) ?>" alt="Imagen servicio" class="admin-u-img-service-thumb">
                  </div>
                <?php endif; ?>
                <input class="form-control" type="file" name="service_images[<?= (int)$service["id"] ?>]" accept="image/png,image/jpeg,image/webp,image/gif">
                <input type="hidden" name="services[<?= (int)$service["id"] ?>][current_image_path]" value="<?= h((string)($service["image_path"] ?? "")) ?>">
              </div>
              <div class="col-md-12">
                <label class="form-label">Carrusel / galería (título y descripción por imagen)</label>
                <div class="gallery-tools">
                  <button
                    type="button"
                    class="btn btn-outline-light btn-sm js-gallery-pick-btn"
                    data-input-id="gallery_input_<?= (int)$service["id"] ?>"
                  >
                    <i class="fa-solid fa-images me-2"></i>Elegir más
                  </button>
                  <button
                    type="button"
                    class="btn btn-outline-danger btn-sm js-gallery-remove-btn"
                    data-service-id="<?= (int)$service["id"] ?>"
                  >
                    <i class="fa-solid fa-trash me-2"></i>Eliminar seleccionadas
                  </button>
                </div>
                <input
                  id="gallery_input_<?= (int)$service["id"] ?>"
                  class="gallery-input-hidden"
                  type="file"
                  name="gallery_images[<?= (int)$service["id"] ?>][]"
                  accept="image/png,image/jpeg,image/webp,image/gif"
                  data-gallery-field-name="gallery_images[<?= (int)$service["id"] ?>][]"
                  data-preview-target="gallery_preview_<?= (int)$service["id"] ?>"
                  multiple
                >
                <div id="gallery_preview_<?= (int)$service["id"] ?>" class="gallery-thumbs"></div>
                <?php $serviceGallery = $galleryByService[(int)$service["id"]] ?? []; ?>
                <?php if (count($serviceGallery) > 0): ?>
                  <div class="gallery-thumbs js-gallery-sortable" data-service-id="<?= (int)$service["id"] ?>">
                    <?php foreach ($serviceGallery as $galleryItem): ?>
                      <div class="gallery-thumb-wrap is-draggable" title="Arrastra para ordenar" draggable="true" data-gallery-id="<?= (int)$galleryItem["id"] ?>">
                        <label class="gallery-thumb-item">
                          <img src="<?= h($galleryItem["image_path"]) ?>" alt="Imagen carrusel">
                          <input class="gallery-thumb-check js-gallery-check-<?= (int)$service["id"] ?>" type="checkbox" name="remove_gallery_ids[]" value="<?= (int)$galleryItem["id"] ?>">
                          <span class="gallery-mark-overlay"></span>
                        </label>
                        <div class="gallery-meta-stack">
                          <input
                            class="form-control form-control-sm mb-1"
                            type="text"
                            name="gallery_image_titles[<?= (int)$galleryItem["id"] ?>]"
                            value="<?= h(trim((string)($galleryItem["image_title"] ?? "")) !== "" ? (string)$galleryItem["image_title"] : (string)($galleryItem["caption"] ?? "")) ?>"
                            placeholder="Título de la imagen"
                            maxlength="220">
                          <textarea
                            class="form-control form-control-sm gallery-desc-input"
                            name="gallery_image_descriptions[<?= (int)$galleryItem["id"] ?>]"
                            rows="2"
                            placeholder="Descripción (opcional)"><?= h((string)($galleryItem["image_description"] ?? "")) ?></textarea>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <input
                  class="js-gallery-order-input"
                  type="hidden"
                  name="services[<?= (int)$service["id"] ?>][gallery_order]"
                  value="<?= h(implode(",", array_map(static fn($row): string => (string)((int)$row["id"]), $serviceGallery))) ?>"
                >
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="is_active_<?= (int)$service["id"] ?>" name="services[<?= (int)$service["id"] ?>][is_active]" <?= ((int)$service["is_active"] === 1) ? "checked" : "" ?>>
                  <label class="form-check-label" for="is_active_<?= (int)$service["id"] ?>">Activo</label>
                </div>
              </div>
            </div>
            <div class="admin-actions mt-3">
              <button class="btn btn-outline-light" type="submit" name="save_service_id" value="<?= (int)$service["id"] ?>"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar cambios</button>
              <button
                class="btn btn-outline-danger js-admin-ajax-delete-service"
                type="submit"
                name="action"
                value="delete_service"
                data-service-id="<?= (int)$service["id"] ?>"
              >
                <i class="fa-solid fa-trash me-2"></i>Eliminar
              </button>
            </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
                  <input type="hidden" name="service_id" value="">
                </form>
              </div>
            </div>
          </div>

          <?php if ($adminExpertAgendaUi): ?>
          <div class="accordion-item" id="admin-tools-experts">
            <h2 class="accordion-header m-0">
              <button
                class="accordion-button <?= $expertsPanelOpen ? "" : "collapsed" ?>"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#tools_experts_panel"
                aria-expanded="<?= $expertsPanelOpen ? "true" : "false" ?>"
                aria-controls="tools_experts_panel"
              >
                <i class="fa-solid fa-users me-2"></i>Expertos
              </button>
            </h2>
            <div id="tools_experts_panel" class="accordion-collapse collapse <?= $expertsPanelOpen ? "show" : "" ?>" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <?php if ($expertEditNotFound): ?>
                  <div class="alert alert-warning mb-3">No hay ningún experto con ese identificador.</div>
                  <a href="admin.php#admin-experts-list" class="btn btn-outline-light btn-sm mb-3">Volver al listado</a>
                <?php endif; ?>

                <?php if (count($experts) > 0): ?>
                  <?php require __DIR__ . "/partials/admin_experts_table.php"; ?>
                <?php else: ?>
                  <p class="text-light-emphasis mb-4" id="admin-experts-list">Aún no hay expertos. Usa <strong>Agregar experto</strong> para crear el primero.</p>
                <?php endif; ?>

                <?php require __DIR__ . "/partials/admin_experts_accordions.php"; ?>
              </div>
            </div>
          </div>

          <?php else: ?>
          <div class="accordion-item" id="admin-tool-experts-off">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_expert_agenda_off_panel"
                aria-expanded="false" aria-controls="tools_expert_agenda_off_panel">
                <i class="fa-solid fa-users me-2"></i>Expertos
              </button>
            </h2>
            <div id="tools_expert_agenda_off_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-0">
                  Módulo desactivado (<code class="small">features.expert_agenda</code> en <code class="small">app_config.php</code>).
                  Las tablas <code class="small">experts</code> y <code class="small">expert_services</code> existen; actívalo en <code class="small">true</code> para gestionarlos aquí.
                </p>
              </div>
            </div>
          </div>
          <?php endif; ?>

        </div>

      </div>

      <?php if ($adminExpertAgendaUi): ?>
        <?php
          $agendaShowExpertNamesAdmin = (int)($settings["agenda_show_expert_names"] ?? 0) === 1;
          require __DIR__ . "/partials/admin_agendas_section.php";
        ?>
      <?php endif; ?>

      <aside class="admin-side">
        <?php if (!$adminInboxUi && !$adminWhatsappClicksUi && !$adminAgendaHistoryUi): ?>
        <p class="small text-light-emphasis mb-0 px-1">Módulos de bandeja del panel desactivados en <code class="small">app_config.php</code> (<code>features.admin_inbox</code>, <code>features.admin_whatsapp_clicks</code>, agenda con notificaciones). Los datos en la base de datos no se borran.</p>
        <?php else: ?>
        <div class="card admin-side-inbox-card overflow-hidden">
          <div class="accordion accordion-flush admin-side-inbox-accordion" id="adminSideInboxAccordion">
            <?php if ($adminInboxUi): ?>
            <?php
            $sideInboxTotal = count($contactMessages);
            $sideInboxUnread = (int)$contactMessagesUnread;
            $sideInboxCounterClass = $sideInboxUnread > 0 ? "text-bg-warning" : "text-bg-secondary";
            $sideInboxCounterTitle = $sideInboxUnread > 0
                ? sprintf("%d sin leer de %d", $sideInboxUnread, $sideInboxTotal)
                : sprintf("%d en total", $sideInboxTotal);
            ?>
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header m-0" id="headingSideMessages">
                <button
                  class="accordion-button<?= $adminInboxFocus ? "" : " collapsed" ?> py-3 px-3"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseSideMessages"
                  aria-expanded="<?= $adminInboxFocus ? "true" : "false" ?>"
                  aria-controls="collapseSideMessages"
                >
                  <span class="d-flex flex-wrap align-items-center gap-2 me-auto">
                    <i class="fa-solid fa-inbox"></i>
                    <span>Mensajes</span>
                    <span
                      class="badge admin-messages-counter <?= h($sideInboxCounterClass) ?>"
                      title="<?= h($sideInboxCounterTitle) ?>"
                    ><?= $sideInboxUnread ?>/<?= $sideInboxTotal ?></span>
                  </span>
                </button>
              </h2>
              <div
                id="collapseSideMessages"
                class="accordion-collapse collapse<?= $adminInboxFocus ? " show" : "" ?>"
                aria-labelledby="headingSideMessages"
              >
                <div class="accordion-body pt-0 px-3 pb-3">
                  <?php if ($sideInboxTotal > 0): ?>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom border-secondary">
                      <span class="small text-light-emphasis">Agrupado por cuenta de cliente o por correo del visitante.</span>
                    </div>
                  <?php endif; ?>
                  <?php if ($sideInboxUnread > 0 || $sideInboxTotal > 0): ?>
                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
                      <?php if ($sideInboxUnread > 0): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_messages_read">
                          <button class="btn btn-outline-light btn-sm" type="submit" title="Marcar todos como leídos">
                            <i class="fa-solid fa-check-double me-2"></i>Marcar todos
                          </button>
                        </form>
                      <?php elseif ($sideInboxTotal > 0): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_messages_unread">
                          <button class="btn btn-outline-warning btn-sm" type="submit" title="Marcar todos como sin leer">
                            <i class="fa-solid fa-rotate-left me-2"></i>Marcar todos como sin leer
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($sideInboxTotal === 0): ?>
                    <p class="text-light-emphasis mb-0">Aún no hay mensajes desde el formulario de contacto.</p>
                  <?php else: ?>
                    <div id="adminInboxMessagesRoot">
                    <div class="accordion admin-conv-groups-accordion" id="adminConvGroupsAccordion">
                      <?php foreach ($contactMessageGroups as $grp): ?>
                        <?php
                          $gSlug = (string)($grp["slug"] ?? "");
                          $gCollapse = "collapse_conv_" . $gSlug;
                          $gUnread = (int)($grp["unread"] ?? 0);
                          $gHasUnread = $gUnread > 0;
                        ?>
                        <div class="accordion-item conv-group-row<?= $gHasUnread ? " is-unread" : "" ?>">
                          <h3 class="accordion-header m-0 conv-group-header">
                            <button
                              class="accordion-button collapsed d-flex flex-wrap align-items-center gap-2 py-2 px-2"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#<?= h($gCollapse) ?>"
                              aria-expanded="false"
                              aria-controls="<?= h($gCollapse) ?>"
                            >
                              <span class="badge text-bg-primary"><?= h((string)($grp["head_badge"] ?? "")) ?></span>
                              <?php if ($gHasUnread): ?>
                                <span class="badge text-bg-warning js-group-unread-badge" title="Mensajes sin leer en este grupo"><?= (int)($grp["unread"]) ?></span>
                              <?php endif; ?>
                              <span class="text-truncate flex-grow-1 admin-u-min-w-0"><strong><?= h((string)($grp["head_title"] ?? "")) ?></strong></span>
                              <?php if (($grp["head_sub"] ?? "") !== ""): ?>
                                <span class="text-light-emphasis small text-truncate d-none d-md-inline admin-u-truncate-md"><?= h((string)$grp["head_sub"]) ?></span>
                              <?php endif; ?>
                              <span class="text-light-emphasis small text-nowrap ms-auto" title="Conversaciones / mensajes totales en este grupo"><?= (int)($grp["conv_count"] ?? 0) ?> · <?= (int)($grp["msg_count"] ?? 0) ?></span>
                              <?php if (($grp["latest_label"] ?? "") !== ""): ?>
                                <span class="text-secondary small text-nowrap d-none d-lg-inline"><?= h((string)$grp["latest_label"]) ?></span>
                              <?php endif; ?>
                            </button>
                          </h3>
                          <div
                            id="<?= h($gCollapse) ?>"
                            class="accordion-collapse collapse"
                            data-bs-parent="#adminConvGroupsAccordion"
                          >
                            <div class="accordion-body p-2 pt-1">
                              <p class="admin-inbox-grp-meta mb-2">
                                <?php if ((string)($grp["head_email"] ?? "") !== ""): ?>
                                  <a href="mailto:<?= h((string)$grp["head_email"]) ?>"><?= h((string)$grp["head_email"]) ?></a>
                                  <span class="text-muted"> · </span>
                                <?php endif; ?>
                                <span title="Hilos ordenados por fecha; responde al final de cada hilo"><?= (int)($grp["conv_count"] ?? 0) ?> hilos</span>
                              </p>
                              <div class="admin-inbox-threads mt-1">
                                <?php foreach (($grp["threads"] ?? []) as $ti => $thread): ?>
                                  <?php
                                    $tMsgs = $thread["messages"] ?? [];
                                    $rootRow = $tMsgs[0] ?? [];
                                    $rootServ = trim((string)($rootRow["servicio"] ?? ""));
                                    $rootSubject = trim((string)($rootRow["subject"] ?? ""));
                                    $threadAsunto = $rootSubject !== "" ? $rootSubject : "Sin asunto";
                                    $rootCreated = (string)($rootRow["created_at"] ?? "");
                                    $rootCreatedLabel = $rootCreated;
                                    try {
                                        $rootCreatedLabel = (new DateTime($rootCreated))->format("d/m/Y H:i");
                                    } catch (Exception $e) {
                                    }
                                    $tCount = count($tMsgs);
                                    $tLatest = (int)($thread["latest_ts"] ?? 0);
                                    $tLatestLabel = $tLatest > 0 ? date("d/m/Y H:i", $tLatest) : "";
                                    $tUnread = 0;
                                    foreach ($tMsgs as $tm) {
                                        if ((int)($tm["is_read"] ?? 0) === 0) {
                                            $tUnread++;
                                        }
                                    }
                                    $rootBody = trim(preg_replace('/\s+/u', ' ', (string)($rootRow["mensaje"] ?? "")));
                                    $snippet = $rootBody;
                                    if (strlen($snippet) > 96) {
                                        $snippet = substr($snippet, 0, 96) . "…";
                                    }
                                    $threadConvIdAdmin = (int)($thread["root_id"] ?? 0);
                                  ?>
                                  <details class="admin-msg-thread admin-msg-conv-root" data-thread-id="<?= $threadConvIdAdmin ?>">
                                    <summary class="admin-msg-thread-summary" title="Referencia interna del hilo (mensaje raíz ID <?= (int)$threadConvIdAdmin ?>)<?= $rootServ !== "" ? " · " . htmlspecialchars($rootServ, ENT_QUOTES, "UTF-8") : "" ?>">
                                      <div class="admin-msg-thread-asunto-block">
                                        <span class="admin-msg-thread-asunto-label">Asunto</span>
                                        <span class="admin-msg-thread-asunto-text<?= $rootSubject === "" ? " text-secondary" : "" ?>"><?= h($threadAsunto) ?></span>
                                      </div>
                                      <span class="admin-msg-thread-summary-main">
                                        <span class="message-meta-date"><?= h($rootCreatedLabel) ?></span>
                                        <span class="message-meta-service"><i class="fa-solid fa-briefcase me-1"></i><?= h($rootServ !== "" ? $rootServ : "—") ?></span>
                                        <?php if ($tCount > 1): ?>
                                          <span class="admin-msg-conv-meta"><?= (int)$tCount ?> pasos</span>
                                        <?php endif; ?>
                                        <?php if ($tUnread > 0): ?>
                                          <span class="badge text-bg-warning js-thread-unread-badge" title="Pasos sin leer en este hilo"><?= (int)$tUnread ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($thread["has_admin_reply"])): ?>
                                          <span class="badge text-bg-success">Resp.</span>
                                        <?php endif; ?>
                                      </span>
                                      <?php if ($snippet !== ""): ?>
                                        <span class="admin-msg-thread-snippet"><?= h($snippet) ?></span>
                                      <?php endif; ?>
                                    </summary>
                                    <div class="admin-msg-thread-body">
                                      <div class="admin-msg-chat-stream">
                                      <?php foreach ($tMsgs as $tmi => $contactMsg): ?>
                                        <?php
                                          $msgId = (int)$contactMsg["id"];
                                          $isUnread = (int)($contactMsg["is_read"] ?? 0) === 0;
                                          $fromClientId = (int)($contactMsg["client_id"] ?? 0);
                                          $msgInReplyTo = (int)($contactMsg["in_reply_to"] ?? 0);
                                          $turnSubject = trim((string)($contactMsg["subject"] ?? ""));
                                          $createdAt = (string)($contactMsg["created_at"] ?? "");
                                          $createdLabel = $createdAt;
                                          try {
                                              $dt = new DateTime($createdAt);
                                              $createdLabel = $dt->format("d/m/Y H:i");
                                          } catch (Exception $e) {
                                          }
                                          $repliesForMsg = $contactRepliesByMessageId[$msgId] ?? [];
                                          $isContinuation = $tmi > 0;
                                          $prevRow = $isContinuation ? ($tMsgs[$tmi - 1] ?? []) : [];
                                          $prevEmail = strtolower(trim((string)($prevRow["email"] ?? "")));
                                          $curEmail = strtolower(trim((string)($contactMsg["email"] ?? "")));
                                          $hideContactFooter = $isContinuation && $prevEmail !== "" && $prevEmail === $curEmail;
                                          $rootServStr = trim((string)$rootServ);
                                          $turnServStr = trim((string)($contactMsg["servicio"] ?? ""));
                                          $hideTurnService = $isContinuation && $rootServStr !== "" && $turnServStr === $rootServStr;
                                        ?>
                                        <div class="message-row admin-msg-turn<?= $isUnread ? " is-unread" : "" ?><?= $isContinuation ? " admin-msg-turn--continuation" : "" ?>" data-message-id="<?= $msgId ?>">
                                          <div class="admin-msg-turn-toolbar">
                                            <div class="admin-msg-turn-head">
                                              <?php if ($msgId > 0): ?>
                                                <?php
                                                  $turnStepLabel = ($tmi + 1) . "/" . $tCount;
                                                  $turnChipTitle = "Referencia interna: mensaje ID " . $msgId . " · " . $createdLabel;
                                                ?>
                                                <span class="admin-msg-id-chip" title="<?= h($turnChipTitle) ?>"><?= h($turnStepLabel) ?></span>
                                              <?php endif; ?>
                                              <span class="badge text-bg-warning js-msg-new-badge">Nuevo</span>
                                              <?php if ($fromClientId > 0 && !$isContinuation): ?>
                                                <span class="badge text-bg-info">Cliente</span>
                                              <?php endif; ?>
                                              <?php if ($msgInReplyTo > 0 && !$isContinuation): ?>
                                                <span class="badge text-bg-secondary" title="Seguimiento">Seg.</span>
                                              <?php endif; ?>
                                              <span class="message-meta-date"><?= h($createdLabel) ?></span>
                                              <?php if (!$hideTurnService): ?>
                                              <span class="message-meta-service"><i class="fa-solid fa-tag me-1"></i><?= h((string)$contactMsg["servicio"]) ?></span>
                                              <?php endif; ?>
                                            </div>
                                            <form method="post" class="message-delete-form" onsubmit="return confirm('¿Eliminar este mensaje del historial?');">
                                              <input type="hidden" name="action" value="delete_message">
                                              <input type="hidden" name="message_id" value="<?= $msgId ?>">
                                              <button class="btn-message-delete" type="submit" title="Eliminar mensaje" aria-label="Eliminar mensaje">
                                                <i class="fa-solid fa-trash"></i>
                                              </button>
                                            </form>
                                          </div>
                                          <div class="admin-msg-turn-main">
                                            <div class="admin-msg-turn-inner">
                                            <?php if ($tmi > 0 || ($turnSubject !== "" && $turnSubject !== $rootSubject)): ?>
                                              <p class="admin-msg-asunto-line mb-2"><span class="text-secondary small text-uppercase fw-semibold">Asunto</span><br><?= h($turnSubject) ?></p>
                                            <?php endif; ?>
                                            <?php if ($msgInReplyTo > 0 && !$isContinuation): ?>
                                              <p class="small text-light-emphasis mb-2" title="Referencia interna del mensaje enlazado: ID <?= (int)$msgInReplyTo ?>">
                                                <i class="fa-solid fa-link me-1"></i>Seguimiento enlazado a otra entrada de este mismo hilo.
                                              </p>
                                            <?php endif; ?>
                                            <div class="admin-msg-bubble admin-msg-bubble--visitor">
                                              <div class="admin-msg-bubble-label text-secondary"><i class="fa-solid fa-user me-1"></i>Mensaje del visitante</div>
                                            <div class="message-body-text mb-2 mb-md-3"><?= nl2br(h((string)$contactMsg["mensaje"])) ?></div>
                                            <?php if (!$hideContactFooter): ?>
                                            <div class="d-flex flex-wrap gap-2 align-items-center text-light-emphasis small mb-0">
                                              <span><strong><?= h((string)$contactMsg["nombre"]) ?></strong></span>
                                              <span><i class="fa-solid fa-envelope me-1"></i><a href="mailto:<?= h((string)$contactMsg["email"]) ?>"><?= h((string)$contactMsg["email"]) ?></a></span>
                                            </div>
                                            <?php endif; ?>
                                            </div>
                                            <?php if (count($repliesForMsg) > 0): ?>
                                            <div class="admin-msg-bubble admin-msg-bubble--admin">
                                              <div class="admin-msg-bubble-label text-secondary"><i class="fa-solid fa-reply me-1"></i>Tus respuestas por correo (desde el panel)</div>
                                              <div class="message-replies-sent border-0 ps-0 pt-0 mt-0 mb-0">
                                                <?php foreach ($repliesForMsg as $repRow): ?>
                                                  <?php
                                                    $repCreated = (string)($repRow["created_at"] ?? "");
                                                    $repLabel = $repCreated;
                                                    try {
                                                        $repDt = new DateTime($repCreated);
                                                        $repLabel = $repDt->format("d/m/Y H:i");
                                                    } catch (Exception $e) {
                                                    }
                                                  ?>
                                                  <div class="message-reply-item small">
                                                    <span class="text-muted"><?= h($repLabel) ?></span>
                                                    <div class="mt-1 message-body-text mb-0 admin-u-msg-body-scroll"><?= nl2br(h((string)($repRow["body"] ?? ""))) ?></div>
                                                  </div>
                                                <?php endforeach; ?>
                                              </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-2 message-mark-actions">
                                              <form method="post" class="m-0 js-mark-read-form">
                                                <input type="hidden" name="action" value="mark_message_read">
                                                <input type="hidden" name="message_id" value="<?= $msgId ?>">
                                                <button class="btn btn-outline-light btn-msg-read-state" type="submit" title="Marcar como leído" aria-label="Marcar como leído">
                                                  <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                              </form>
                                              <form method="post" class="m-0 js-mark-unread-form">
                                                <input type="hidden" name="action" value="mark_message_unread">
                                                <input type="hidden" name="message_id" value="<?= $msgId ?>">
                                                <button class="btn btn-outline-warning btn-msg-read-state" type="submit" title="Marcar como no leído" aria-label="Marcar como no leído">
                                                  <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                </button>
                                              </form>
                                            </div>
                                            </div>
                                          </div>
                                        </div>
                                      <?php endforeach; ?>
                                      </div>
                                      <?php
                                        $threadRootIdAdmin = (int)($thread["root_id"] ?? 0);
                                        $nAdminTm = count($tMsgs);
                                        $lastAdminTurn = $nAdminTm > 0 ? $tMsgs[$nAdminTm - 1] : [];
                                        $lastAdminMsgId = (int)($lastAdminTurn["id"] ?? 0);
                                        $lastVisitorName = trim((string)($lastAdminTurn["nombre"] ?? ""));
                                      ?>
                                      <?php if ($lastAdminMsgId > 0): ?>
                                        <form
                                          method="post"
                                          class="message-reply-form mb-0 mt-3 admin-msg-reply-thread-end js-admin-ajax-form"
                                          data-ajax-scope="inbox-reply"
                                        >
                                          <input type="hidden" name="action" value="reply_contact_message">
                                          <input type="hidden" name="message_id" value="<?= $lastAdminMsgId ?>">
                                          <p class="small text-light-emphasis mb-2" title="Referencia interna del mensaje destino: ID <?= (int)$lastAdminMsgId ?>">Un solo envío al final del hilo: la respuesta queda asociada al <strong>último mensaje</strong> del hilo (el más reciente en esta conversación).</p>
                                          <label class="form-label small mb-1" for="reply_body_root_<?= $threadRootIdAdmin ?>">Responder por correo</label>
                                          <textarea id="reply_body_root_<?= $threadRootIdAdmin ?>" class="form-control form-control-sm" name="reply_body" rows="4" placeholder="Texto que recibirá <?= h($lastVisitorName !== "" ? $lastVisitorName : "el visitante") ?> en su correo…" required></textarea>
                                          <button class="btn btn-primary btn-sm mt-2" type="submit">
                                            <i class="fa-solid fa-paper-plane me-2"></i>Enviar respuesta
                                          </button>
                                        </form>
                                      <?php endif; ?>
                                    </div>
                                  </details>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($adminWhatsappClicksUi || $adminAgendaHistoryUi): ?>
            <div class="admin-side-secondary">
            <?php endif; ?>
            <?php if ($adminWhatsappClicksUi): ?>
            <div class="accordion-item border-0<?= $adminAgendaHistoryUi ? " border-bottom" : "" ?>">
              <h2 class="accordion-header m-0" id="headingSideWhatsapp">
                <button
                  class="accordion-button collapsed py-3 px-3"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseSideWhatsapp"
                  aria-expanded="false"
                  aria-controls="collapseSideWhatsapp"
                >
                  <span class="d-flex flex-wrap align-items-center gap-2 me-auto">
                    <i class="fa-brands fa-whatsapp text-success"></i>
                    <span>Clics WhatsApp</span>
                    <span
                      class="badge admin-whatsapp-counter <?= h($waSideCounterClass) ?>"
                      title="<?= h($waSideCounterTitle) ?>"
                    ><?= $waSideUnread ?>/<?= $waSideTotal ?></span>
                  </span>
                </button>
              </h2>
              <div
                id="collapseSideWhatsapp"
                class="accordion-collapse collapse"
                aria-labelledby="headingSideWhatsapp"
              >
                <div class="accordion-body pt-0 px-3 pb-3">
                  <p class="small text-light-emphasis mb-3">Cada fila es alguien que pulsó «Escribir por WhatsApp» en la web.</p>
                  <?php if ($waSideTotal > 0): ?>
                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
                      <?php if ($waSideUnread > 0): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_whatsapp_read">
                          <button class="btn btn-outline-light btn-sm" type="submit" title="Marcar todos como leídos">
                            <i class="fa-solid fa-check-double me-2"></i>Marcar todos
                          </button>
                        </form>
                      <?php else: ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_whatsapp_unread">
                          <button class="btn btn-outline-warning btn-sm" type="submit" title="Marcar todos como sin leer">
                            <i class="fa-solid fa-rotate-left me-2"></i>Marcar todos como sin leer
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if (count($whatsappClicks) === 0): ?>
                    <p class="text-light-emphasis mb-0">Sin clics todavía.</p>
                  <?php else: ?>
                    <div class="accordion admin-messages-accordion" id="adminWhatsappAccordion">
                      <?php foreach ($whatsappClicks as $waClick): ?>
                        <?php
                          $waId = (int)$waClick["id"];
                          $waCollapseId = "collapse_wa_" . $waId;
                          $waCreated = (string)($waClick["created_at"] ?? "");
                          $waLabel = $waCreated;
                          try {
                              $waDt = new DateTime($waCreated);
                              $waLabel = $waDt->format("Y-m-d H:i");
                          } catch (Exception $e) {
                          }
                          $waNombre = trim((string)($waClick["nombre"] ?? ""));
                          $waEmail = trim((string)($waClick["email"] ?? ""));
                          $waServicio = trim((string)($waClick["servicio"] ?? ""));
                          $waUnreadRow = (int)($waClick["is_read"] ?? 0) === 0;
                        ?>
                        <div class="accordion-item message-row<?= $waUnreadRow ? " is-unread" : "" ?>" data-whatsapp-click-id="<?= $waId ?>">
                          <h3 class="accordion-header m-0 message-header-row">
                            <button
                              class="accordion-button collapsed d-flex flex-wrap align-items-center gap-2"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#<?= h($waCollapseId) ?>"
                              aria-expanded="false"
                              aria-controls="<?= h($waCollapseId) ?>"
                            >
                              <span class="badge text-bg-warning js-msg-new-badge">Nuevo</span>
                              <span class="message-meta-date"><?= h($waLabel) ?></span>
                              <span class="message-meta-name"><strong><?= $waNombre !== "" ? h($waNombre) : "—" ?></strong></span>
                              <span class="message-meta-service"><i class="fa-solid fa-tag me-1"></i><?= $waServicio !== "" ? h($waServicio) : "—" ?></span>
                              <?php if ($waEmail !== ""): ?>
                                <span class="message-meta-email text-light-emphasis"><?= h($waEmail) ?></span>
                              <?php endif; ?>
                            </button>
                            <form method="post" class="message-delete-form" onsubmit="return confirm('¿Eliminar esta entrada?');">
                              <input type="hidden" name="action" value="delete_whatsapp_click">
                              <input type="hidden" name="whatsapp_click_id" value="<?= $waId ?>">
                              <button class="btn-message-delete" type="submit" title="Eliminar" aria-label="Eliminar">
                                <i class="fa-solid fa-trash"></i>
                              </button>
                            </form>
                          </h3>
                          <div id="<?= h($waCollapseId) ?>" class="accordion-collapse collapse" data-bs-parent="#adminWhatsappAccordion">
                            <div class="accordion-body">
                              <div class="message-body-text mb-3"><?= nl2br(h((string)($waClick["composed_text"] ?? ""))) ?></div>
                              <div class="d-flex flex-wrap gap-2 message-mark-actions">
                                <form method="post" class="m-0 js-wa-mark-read-form">
                                  <input type="hidden" name="action" value="mark_whatsapp_read">
                                  <input type="hidden" name="whatsapp_click_id" value="<?= $waId ?>">
                                  <button class="btn btn-outline-light btn-sm" type="submit">
                                    <i class="fa-solid fa-check me-2"></i>Marcar como leído
                                  </button>
                                </form>
                                <form method="post" class="m-0 js-wa-mark-unread-form">
                                  <input type="hidden" name="action" value="mark_whatsapp_unread">
                                  <input type="hidden" name="whatsapp_click_id" value="<?= $waId ?>">
                                  <button class="btn btn-outline-warning btn-sm" type="submit">
                                    <i class="fa-solid fa-rotate-left me-2"></i>Marcar como sin leer
                                  </button>
                                </form>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($adminAgendaHistoryUi): ?>
              <?php require __DIR__ . "/partials/admin_side_appointment_history_accordion.php"; ?>
            <?php endif; ?>
            <?php if ($adminWhatsappClicksUi || $adminAgendaHistoryUi): ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </aside>
    </div>
      </main>
    </div>
  </div>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Controles de la pantalla de login: toggle de mostrar/ocultar clave y
    // alternancia entre la vista "Iniciar sesión" y "Recuperar clave".
    // Se ejecuta tanto si estás logueado como si no (los selectores quedan
    // vacíos cuando no aplica, así que no hay efectos colaterales).
    (function () {
      document.querySelectorAll(".js-password-toggle").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const id = btn.getAttribute("data-target");
          if (!id) return;
          const input = document.getElementById(id);
          if (!input) return;
          const wasPwd = input.type === "password";
          input.type = wasPwd ? "text" : "password";
          btn.setAttribute("aria-pressed", wasPwd ? "true" : "false");
          btn.setAttribute("aria-label", wasPwd ? "Ocultar clave" : "Mostrar clave");
          const icon = btn.querySelector("i");
          if (icon) {
            icon.classList.toggle("fa-eye", !wasPwd);
            icon.classList.toggle("fa-eye-slash", wasPwd);
          }
        });
      });

      document.querySelectorAll(".js-show-view").forEach(function (link) {
        link.addEventListener("click", function (ev) {
          ev.preventDefault();
          const target = link.getAttribute("data-target-view");
          if (!target) return;
          let focusTarget = null;
          document.querySelectorAll(".login-view").forEach(function (view) {
            const isTarget = view.getAttribute("data-view") === target;
            if (isTarget) {
              view.removeAttribute("hidden");
              focusTarget = view.querySelector("input:not([type='hidden'])");
            } else {
              view.setAttribute("hidden", "");
            }
          });
          if (focusTarget) {
            setTimeout(function () {
              try { focusTarget.focus({ preventScroll: true }); } catch (e) { focusTarget.focus(); }
            }, 60);
          }
        });
      });

      function adminCollapseShowIfNeeded(el) {
        if (!el || !window.bootstrap || !bootstrap.Collapse) {
          return;
        }
        if (el.classList.contains("show")) {
          return;
        }
        try {
          bootstrap.Collapse.getOrCreateInstance(el).show();
        } catch (e) {}
      }

      function adminScrollTo(el) {
        if (!el) return;
        setTimeout(function () {
          el.scrollIntoView({ block: "nearest", behavior: "smooth" });
        }, 160);
      }

      var adminNavSchLegacySection = null;

      function adminApplyLocationHash() {
        var hash = (window.location.hash || "").trim();
        var legacySchMatch = /^#expert_sch_acc_(appts|week|template|daily|dates)$/.exec(hash);
        if (legacySchMatch) {
          adminNavSchLegacySection = legacySchMatch[1] === "daily" ? "template" : legacySchMatch[1];
          try {
            history.replaceState(
              null,
              "",
              window.location.pathname + window.location.search + "#admin-tools-agendas"
            );
          } catch (e) {}
          hash = "#admin-tools-agendas";
        }

        var urlParams = null;
        try {
          urlParams = new URLSearchParams(window.location.search);
        } catch (e) {}

        var agendaHashAliases = {
          "#expert_acc_bulk": "agenda_acc_bulk",
          "#expert_acc_agenda_notify": "agenda_acc_notify",
          "#expert_acc_public": "agenda_acc_public",
          "#expert_acc_schedule": "agenda_acc_schedule"
        };
        if (agendaHashAliases[hash]) {
          hash = "#" + agendaHashAliases[hash];
        }

        var inboxHashTargets = {
          "#side-inbox-messages": { collapse: "collapseSideMessages", scroll: "headingSideMessages" },
          "#side-inbox-whatsapp": { collapse: "collapseSideWhatsapp", scroll: "headingSideWhatsapp" },
          "#side-inbox-agenda-history": { collapse: "collapseSideAgendaHistory", scroll: "headingSideAgendaHistory" }
        };
        if (inboxHashTargets[hash]) {
          adminCollapseShowIfNeeded(document.getElementById(inboxHashTargets[hash].collapse));
          adminScrollTo(document.getElementById(inboxHashTargets[hash].scroll));
          return;
        }

        var isExpertSchedule =
          hash === "#admin-expert-schedule" ||
          hash === "#admin-agendas-expert-workspace" ||
          (urlParams &&
            urlParams.get("expert_view") === "schedule" &&
            urlParams.get("expert_id"));

        var isAgendasArea =
          hash === "#admin-tools-agendas" ||
          hash === "#admin-agendas-intro" ||
          hash === "#admin-agendas-expert-workspace" ||
          hash.indexOf("#agenda_acc_") === 0 ||
          isExpertSchedule;

        if (isAgendasArea) {
          if (hash === "#agenda_acc_bulk" || hash === "#agenda_acc_notify" || hash === "#agenda_acc_public") {
            var quickId = hash.slice(1);
            var quickDetails =
              document.getElementById(quickId + "_wrap") ||
              document.getElementById(quickId + "_item");
            if (quickDetails && quickDetails.tagName === "DETAILS") {
              quickDetails.open = true;
            }
          }
          if (isExpertSchedule) {
            var schSec = adminNavSchLegacySection;
            if (!schSec && urlParams) {
              schSec = urlParams.get("expert_section");
            }
            if (schSec === "daily") {
              schSec = "template";
            }
            if (schSec) {
              adminCollapseShowIfNeeded(document.getElementById("expert_sch_acc_" + schSec));
            }
          }
          var scrollAgendas = document.getElementById("admin-tools-agendas");
          if (hash === "#admin-tools-agendas" || hash === "#admin-agendas-expert-workspace" || isExpertSchedule) {
            scrollAgendas =
              document.getElementById("admin-agendas-expert-workspace") ||
              document.getElementById("admin-expert-schedule") ||
              scrollAgendas;
          } else if (hash.indexOf("#agenda_acc_") === 0) {
            var innerAg = document.getElementById(hash.slice(1));
            scrollAgendas = innerAg || scrollAgendas;
          } else if (hash === "#admin-agendas-intro" || hash === "#admin-agendas-heading") {
            scrollAgendas = document.getElementById(hash.slice(1)) || scrollAgendas;
          } else if (hash && hash !== "#admin-tools-agendas") {
            scrollAgendas = document.querySelector(hash) || scrollAgendas;
          }
          adminScrollTo(scrollAgendas);
          return;
        }

        if (hash === "#expert_acc_appointments" || hash === "#admin-experts-list" || hash === "#admin-expert-edit") {
          adminCollapseShowIfNeeded(document.getElementById("tools_experts_panel"));
          if (hash === "#expert_acc_appointments") {
            adminCollapseShowIfNeeded(document.getElementById("expert_acc_appointments"));
          }
          var scrollExperts = null;
          if (hash === "#admin-experts-list") {
            scrollExperts = document.getElementById("admin-experts-list");
          } else if (hash === "#admin-expert-edit") {
            scrollExperts = document.getElementById("admin-expert-edit");
          } else if (hash === "#expert_acc_appointments") {
            scrollExperts = document.getElementById("expert_acc_appointments");
          }
          adminScrollTo(scrollExperts);
          return;
        }

        var adminToolHashPanels = {
          "#admin-tool-config": "tools_config_panel",
          "#admin-tool-credentials": "tools_credentials_panel",
          "#admin-tool-routes": "tools_routes_panel",
          "#admin-tool-service-edit": "tools_edit_panel",
          "#admin-tools-experts": "tools_experts_panel",
          "#admin-tool-experts-off": "tools_expert_agenda_off_panel",
          "#admin-tool-clients": "tools_clients_panel"
        };
        if (adminToolHashPanels[hash]) {
          adminCollapseShowIfNeeded(document.getElementById(adminToolHashPanels[hash]));
          adminScrollTo(document.getElementById(hash.slice(1)));
        }
      }

      function adminSidebarNavigate(ev, link) {
        var href = link.getAttribute("href") || "";
        var url;
        try {
          url = new URL(href, window.location.href);
        } catch (e) {
          return;
        }
        var samePage =
          url.pathname === window.location.pathname &&
          url.search === window.location.search;
        if (!samePage || !url.hash) {
          return;
        }
        ev.preventDefault();
        var target = url.pathname + url.search + url.hash;
        if (window.location.pathname + window.location.search + window.location.hash !== target) {
          try {
            history.pushState(null, "", target);
          } catch (e2) {
            window.location.hash = url.hash;
          }
        }
        adminApplyLocationHash();
      }

      document.addEventListener("DOMContentLoaded", function () {
        adminApplyLocationHash();
        window.addEventListener("hashchange", adminApplyLocationHash);

        document.querySelectorAll(".js-expert-sch-goto-dates").forEach(function (link) {
          link.addEventListener("click", function (ev) {
            var target = document.getElementById("expert_sch_acc_dates");
            if (!target) {
              return;
            }
            ev.preventDefault();
            adminCollapseShowIfNeeded(target);
            adminScrollTo(target);
            try {
              history.replaceState(null, "", "#expert_sch_acc_dates");
            } catch (e) {}
          });
        });

        document.querySelectorAll(".js-admin-sidebar-tool").forEach(function (link) {
          link.addEventListener("click", function (ev) {
            adminSidebarNavigate(ev, link);
            var panelId = link.getAttribute("data-admin-panel");
            if (!panelId) return;
            adminCollapseShowIfNeeded(document.getElementById(panelId));
          });
        });

        document.querySelectorAll(".js-admin-sidebar-agenda").forEach(function (link) {
          link.addEventListener("click", function (ev) {
            adminSidebarNavigate(ev, link);
            var collapseId = link.getAttribute("data-admin-collapse");
            if (collapseId) {
              adminCollapseShowIfNeeded(document.getElementById(collapseId));
            }
          });
        });

        document.querySelectorAll(".js-admin-sidebar-inbox").forEach(function (link) {
          link.addEventListener("click", function (ev) {
            adminSidebarNavigate(ev, link);
            var collapseId = link.getAttribute("data-admin-collapse");
            if (collapseId) {
              adminCollapseShowIfNeeded(document.getElementById(collapseId));
            }
            var scrollId = link.getAttribute("data-admin-scroll");
            if (scrollId) {
              adminScrollTo(document.getElementById(scrollId));
            }
          });
        });
      });
    })();
  </script>
  <script>
    (function () {
      function updateGalleryOrderForRow(serviceRow) {
        if (!serviceRow) return;
        const orderInput = serviceRow.querySelector(".js-gallery-order-input");
        if (!orderInput) return;
        const ids = Array.from(serviceRow.querySelectorAll(".gallery-thumb-wrap[data-gallery-id]"))
          .map(function (el) { return el.getAttribute("data-gallery-id") || ""; })
          .filter(function (id) { return id !== ""; });
        orderInput.value = ids.join(",");
      }

      function syncActiveButton(containerEl, value) {
        const current = (value || "").trim();
        containerEl.querySelectorAll(".icon-option").forEach(function (btn) {
          const isMatch = btn.getAttribute("data-icon") === current;
          btn.classList.toggle("is-active", isMatch);
        });
      }

      document.querySelectorAll(".icon-picker").forEach(function (containerEl) {
        const inputId = containerEl.getAttribute("data-target-input");
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;

        containerEl.querySelectorAll(".icon-option").forEach(function (btn) {
          btn.addEventListener("click", function () {
            inputEl.value = btn.getAttribute("data-icon") || "";
            syncActiveButton(containerEl, inputEl.value);
          });
        });

        syncActiveButton(containerEl, inputEl.value);
      });

      (function () {
        var cancelBtn = document.getElementById("cancel_new_service_btn");
        var collapseEl = document.getElementById("collapse_new_service_panel");
        var formEl = document.getElementById("form-add-service");
        var pickerEl = document.getElementById("new_service_icon_picker");
        var iconInput = document.getElementById("new_service_icon_input");
        if (!cancelBtn || !collapseEl || !formEl) return;

        function syncNewServiceIconPicker() {
          if (!pickerEl || !iconInput) return;
          var v = (iconInput.value || "").trim();
          pickerEl.querySelectorAll(".icon-option").forEach(function (btn) {
            btn.classList.toggle("is-active", btn.getAttribute("data-icon") === v);
          });
        }

        cancelBtn.addEventListener("click", function () {
          formEl.reset();
          syncNewServiceIconPicker();
          if (window.bootstrap && bootstrap.Collapse) {
            var inst = bootstrap.Collapse.getOrCreateInstance(collapseEl);
            inst.hide();
          }
        });
      })();

      (function () {
        var cancelBtn = document.getElementById("cancel_new_expert_btn");
        var collapseEl = document.getElementById("expert_acc_add");
        var formEl = document.getElementById("form-add-expert");
        if (!cancelBtn || !collapseEl || !formEl) return;

        cancelBtn.addEventListener("click", function () {
          formEl.reset();
          if (window.bootstrap && bootstrap.Collapse) {
            bootstrap.Collapse.getOrCreateInstance(collapseEl).hide();
          }
        });
      })();

      (function () {
        if (typeof ResizeObserver === "undefined") {
          return;
        }
        function fitExpertServiceRow(container) {
          var slot = container.querySelector(".expert-svc-icons-slot");
          var inner = container.querySelector(".expert-svc-icons-inner");
          var plus = container.querySelector(".expert-svc-overflow-plus");
          if (!slot || !inner || !plus) {
            return;
          }
          var icons = Array.prototype.slice.call(inner.querySelectorAll(".expert-svc-icon"));
          icons.forEach(function (el) {
            el.style.display = "";
          });
          plus.classList.remove("is-visible");
          plus.removeAttribute("title");
          plus.removeAttribute("aria-label");
          plus.setAttribute("aria-hidden", "true");
          if (icons.length === 0) {
            return;
          }
          if (slot.clientWidth < 40) {
            return;
          }
          var maxIter = icons.length + 10;
          var iter = 0;
          while (inner.scrollWidth > slot.clientWidth + 1 && iter < maxIter) {
            var visible = icons.filter(function (i) {
              return i.style.display !== "none";
            });
            if (visible.length === 0) {
              break;
            }
            visible[visible.length - 1].style.display = "none";
            iter += 1;
          }
          var hidden = icons.filter(function (i) {
            return i.style.display === "none";
          });
          if (hidden.length === 0) {
            return;
          }
          plus.classList.add("is-visible");
          iter = 0;
          while (inner.scrollWidth > slot.clientWidth + 1 && iter < maxIter) {
            var visible2 = icons.filter(function (i) {
              return i.style.display !== "none";
            });
            if (visible2.length === 0) {
              break;
            }
            visible2[visible2.length - 1].style.display = "none";
            iter += 1;
          }
          var hidden2 = icons.filter(function (i) {
            return i.style.display === "none";
          });
          var titles = hidden2.map(function (i) {
            return i.getAttribute("data-service-title") || "";
          }).filter(Boolean);
          var t = titles.join(", ");
          if (t) {
            plus.setAttribute("title", t);
            plus.setAttribute("aria-label", "Servicios no mostrados en la fila: " + t);
            plus.removeAttribute("aria-hidden");
          } else {
            plus.setAttribute("aria-label", "Hay más servicios vinculados");
            plus.removeAttribute("aria-hidden");
          }
        }
        function fitAllExpertServiceRows() {
          document.querySelectorAll(".expert-row-services.js-expert-svc-fit").forEach(fitExpertServiceRow);
        }
        window.fitAllExpertServiceRows = fitAllExpertServiceRows;
        var ro = new ResizeObserver(function () {
          window.requestAnimationFrame(fitAllExpertServiceRows);
        });
        document.querySelectorAll(".expert-row-services.js-expert-svc-fit").forEach(function (el) {
          ro.observe(el);
        });
        window.addEventListener("load", fitAllExpertServiceRows);
        var expertsPanel = document.getElementById("tools_experts_panel");
        if (expertsPanel) {
          expertsPanel.addEventListener("shown.bs.collapse", fitAllExpertServiceRows);
        }
      })();

      document.querySelectorAll(".js-gallery-remove-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const serviceId = btn.getAttribute("data-service-id");
          if (!serviceId) return;
          const serviceRow = btn.closest(".service-row");
          if (!serviceRow) return;
          const checks = serviceRow.querySelectorAll(".js-gallery-check-" + serviceId);
          let marked = 0;
          checks.forEach(function (checkEl) {
            if (checkEl.checked) marked += 1;
          });
          if (marked === 0) {
            alert("Selecciona al menos una miniatura para eliminar.");
            return;
          }
          if (!confirm("Se eliminarán " + marked + " imagen(es) al guardar los cambios. ¿Continuar?")) {
            return;
          }

          // Oculta visualmente la miniatura y la saca del orden, pero
          // CONSERVA en el DOM el checkbox marcado para que name="remove_gallery_ids[]"
          // llegue al servidor al guardar y PHP pueda borrar el registro.
          checks.forEach(function (checkEl) {
            if (!checkEl.checked) return;
            const wrap = checkEl.closest(".gallery-thumb-wrap");
            if (!wrap) return;
            wrap.removeAttribute("data-gallery-id");
            wrap.setAttribute("draggable", "false");
            wrap.classList.add("is-removed");
            wrap.style.display = "none";
          });
          updateGalleryOrderForRow(serviceRow);
        });
      });

      function initAdminGallerySortable(containerEl) {
        if (!containerEl) return;
        let draggedEl = null;

        containerEl.querySelectorAll(".gallery-thumb-wrap.is-draggable").forEach(function (item) {
          item.addEventListener("dragstart", function () {
            draggedEl = item;
            item.classList.add("is-dragging");
          });

          item.addEventListener("dragend", function () {
            item.classList.remove("is-dragging");
            draggedEl = null;
            updateGalleryOrderForRow(containerEl.closest(".service-row"));
          });

          item.addEventListener("dragover", function (event) {
            event.preventDefault();
          });

          item.addEventListener("drop", function (event) {
            event.preventDefault();
            if (!draggedEl || draggedEl === item) return;
            const items = Array.from(containerEl.querySelectorAll(".gallery-thumb-wrap"));
            const draggedIndex = items.indexOf(draggedEl);
            const targetIndex = items.indexOf(item);
            if (draggedIndex < 0 || targetIndex < 0) return;
            if (draggedIndex < targetIndex) {
              item.insertAdjacentElement("afterend", draggedEl);
            } else {
              item.insertAdjacentElement("beforebegin", draggedEl);
            }
            updateGalleryOrderForRow(containerEl.closest(".service-row"));
          });
        });
      }
      window.initAdminGallerySortable = initAdminGallerySortable;
      document.querySelectorAll(".js-gallery-sortable").forEach(initAdminGallerySortable);

      document.querySelectorAll(".js-gallery-pick-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const inputId = btn.getAttribute("data-input-id");
          if (!inputId) return;
          const inputEl = document.getElementById(inputId);
          if (inputEl) inputEl.click();
        });
      });

      document.addEventListener("change", function (event) {
        const inputEl = event.target;
        if (!(inputEl instanceof HTMLInputElement)) return;
        if (!inputEl.classList.contains("gallery-input-hidden")) return;

        const targetId = inputEl.getAttribute("data-preview-target");
        if (!targetId) return;
        const previewWrap = document.getElementById(targetId);
        if (!previewWrap) return;

        const fieldName = inputEl.getAttribute("data-gallery-field-name") || inputEl.getAttribute("name") || "";
        if (fieldName !== "" && !inputEl.getAttribute("name")) {
          inputEl.setAttribute("name", fieldName);
        }

        const files = inputEl.files ? Array.from(inputEl.files) : [];
        if (files.length === 0) return;

        files.forEach(function (file) {
          if (!file.type.startsWith("image/")) return;
          const objectUrl = URL.createObjectURL(file);
          const item = document.createElement("div");
          item.className = "gallery-thumb-item";
          item.innerHTML = '<img alt="Previsualización">';
          const img = item.querySelector("img");
          if (img) img.src = objectUrl;
          previewWrap.appendChild(item);
        });

        // Acumula selecciones de varios clics: el input con archivos conserva name;
        // el clon vacío no se envía (evita UPLOAD_ERR_NO_FILE y falsos errores al guardar).
        const nextInput = inputEl.cloneNode();
        nextInput.value = "";
        nextInput.removeAttribute("name");
        nextInput.removeAttribute("id");
        inputEl.removeAttribute("id");
        inputEl.insertAdjacentElement("afterend", nextInput);
      });
    })();

    // Marcar como leído automáticamente al desplegar un mensaje sin leer.
    (function () {
      const inboxRoot = document.getElementById("adminInboxMessagesRoot");

      function setInboxCounterFromServer(unread, total) {
        var list = document.querySelectorAll(".admin-messages-counter");
        if (!list.length) return;
        var u = Math.max(0, parseInt(String(unread), 10) || 0);
        var t = Math.max(0, parseInt(String(total), 10) || 0);
        var title = u > 0 ? u + " sin leer de " + t : t + " en total";
        var warn = u > 0;
        list.forEach(function (counter) {
          counter.textContent = u + "/" + t;
          counter.title = title;
          counter.classList.toggle("text-bg-warning", warn);
          counter.classList.toggle("text-bg-secondary", !warn);
        });
      }

      function syncThreadUnreadBadgeFromDom(row) {
        var det = row.closest("details.admin-msg-thread");
        if (!det) return;
        var n = det.querySelectorAll(".message-row.is-unread").length;
        var summaryMain = det.querySelector(".admin-msg-thread-summary-main");
        var badge = det.querySelector(".js-thread-unread-badge");
        if (n <= 0) {
          if (badge && badge.parentElement) badge.parentElement.removeChild(badge);
          return;
        }
        if (!badge && summaryMain) {
          badge = document.createElement("span");
          badge.className = "badge text-bg-warning js-thread-unread-badge";
          badge.title = "Pasos sin leer en este hilo";
          summaryMain.appendChild(badge);
        }
        if (badge) badge.textContent = String(n);
      }

      function syncGroupUnreadFromDom(row) {
        var item = row.closest(".accordion-item.conv-group-row");
        if (!item) return;
        var n = item.querySelectorAll(".admin-inbox-threads .message-row.is-unread").length;
        var badge = item.querySelector(".js-group-unread-badge");
        if (n <= 0) {
          item.classList.remove("is-unread");
          if (badge && badge.parentElement) badge.parentElement.removeChild(badge);
          return;
        }
        item.classList.add("is-unread");
        if (badge) {
          badge.textContent = String(n);
          return;
        }
        var btn = item.querySelector(".conv-group-header .accordion-button");
        if (!btn) return;
        badge = document.createElement("span");
        badge.className = "badge text-bg-warning js-group-unread-badge";
        badge.title = "Mensajes sin leer en este grupo";
        badge.textContent = String(n);
        var primary = btn.querySelector(".badge.text-bg-primary");
        if (primary) {
          primary.insertAdjacentElement("afterend", badge);
        } else {
          btn.insertBefore(badge, btn.firstChild);
        }
      }

      function applyReadStateToRow(row) {
        if (!row || !row.classList.contains("is-unread")) return;
        row.classList.remove("is-unread");
        syncThreadUnreadBadgeFromDom(row);
        syncGroupUnreadFromDom(row);
      }

      function applyUnreadStateToRow(row) {
        if (!row || row.classList.contains("is-unread")) return;
        row.classList.add("is-unread");
        syncThreadUnreadBadgeFromDom(row);
        syncGroupUnreadFromDom(row);
      }

      function postReadToggle(row, action) {
        if (!row) return Promise.resolve(false);
        const id = row.getAttribute("data-message-id");
        if (!id) return Promise.resolve(false);
        const fd = new FormData();
        fd.append("action", action);
        fd.append("message_id", id);
        fd.append("ajax", "1");
        return fetch(window.location.pathname, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          const ct = (r.headers.get("Content-Type") || "").toLowerCase();
          if (ct.indexOf("application/json") === -1) {
            return r.text().then(function (txt) {
              throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
            });
          }
          return r.json();
        }).then(function (data) {
          if (data && typeof data.unread_total === "number" && typeof data.messages_total === "number") {
            setInboxCounterFromServer(data.unread_total, data.messages_total);
          }
          return !!(data && data.ok);
        }).catch(function (err) {
          console.error("Error en " + action + ":", err);
          return false;
        });
      }

      function markRowAsRead(row) {
        postReadToggle(row, "mark_message_read").then(function (ok) {
          if (ok) {
            applyReadStateToRow(row);
            if (typeof window.showAdminToast === "function") {
              window.showAdminToast("success", "Mensaje marcado como leído.", { title: "Bandeja" });
            }
          }
        });
      }

      function markRowAsUnread(row) {
        postReadToggle(row, "mark_message_unread").then(function (ok) {
          if (ok) {
            applyUnreadStateToRow(row);
            if (typeof window.showAdminToast === "function") {
              window.showAdminToast("success", "Mensaje marcado como sin leer.", { title: "Bandeja" });
            }
          }
        });
      }

      if (!inboxRoot) {
        // no hay mensajes en esta vista
      } else {
      inboxRoot.querySelectorAll("details.admin-msg-thread").forEach(function (det) {
        det.addEventListener("toggle", function () {
          if (!det.open) return;
          det.querySelectorAll(".message-row.is-unread").forEach(function (row) {
            markRowAsRead(row);
          });
        });
      });

      // Forms explícitos: en lugar de submit normal con recarga, hacemos AJAX
      // y actualizamos la fila para que el toggle inverso quede disponible.
      inboxRoot.querySelectorAll(".js-mark-read-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markRowAsRead(row);
        });
      });
      inboxRoot.querySelectorAll(".js-mark-unread-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markRowAsUnread(row);
        });
      });

      const markAllForm = document.querySelector("form input[name='action'][value='mark_all_messages_read']");
      if (markAllForm && markAllForm.form) {
        markAllForm.form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const fd = new FormData(markAllForm.form);
          fd.append("ajax", "1");
          fetch(window.location.pathname, {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
          }).then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            const ct = (r.headers.get("Content-Type") || "").toLowerCase();
            if (ct.indexOf("application/json") === -1) {
              return r.text().then(function (txt) {
                throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
              });
            }
            return r.json();
          }).then(function (data) {
            if (!data || !data.ok) {
              console.error("mark_all_messages_read no actualizó la BD", data);
              return;
            }
            if (typeof data.unread_total === "number" && typeof data.messages_total === "number") {
              setInboxCounterFromServer(data.unread_total, data.messages_total);
            }
            inboxRoot.querySelectorAll(".message-row.is-unread").forEach(applyReadStateToRow);
            if (markAllForm.form.parentElement) markAllForm.form.parentElement.removeChild(markAllForm.form);
            if (typeof window.showAdminToast === "function") {
              window.showAdminToast("success", "Todos los mensajes marcados como leídos.", { title: "Bandeja" });
            }
          }).catch(function (err) {
            console.error("Error marcando todos como leídos:", err);
          });
        });
      }
      }
    })();

    (function () {
      const waAccordion = document.getElementById("adminWhatsappAccordion");
      if (!waAccordion) return;

      function setWaCounterFromServer(unread, total) {
        var list = document.querySelectorAll(".admin-whatsapp-counter");
        if (!list.length) return;
        var u = Math.max(0, parseInt(String(unread), 10) || 0);
        var t = Math.max(0, parseInt(String(total), 10) || 0);
        var title = u > 0 ? u + " sin leer de " + t : t + " en total";
        var warn = u > 0;
        list.forEach(function (counter) {
          counter.textContent = u + "/" + t;
          counter.title = title;
          counter.classList.toggle("text-bg-warning", warn);
          counter.classList.toggle("text-bg-secondary", !warn);
        });
      }

      function applyWaReadStateToRow(row) {
        if (!row || !row.classList.contains("is-unread")) return;
        row.classList.remove("is-unread");
      }

      function applyWaUnreadStateToRow(row) {
        if (!row || row.classList.contains("is-unread")) return;
        row.classList.add("is-unread");
      }

      function postWaReadToggle(row, action) {
        if (!row) return Promise.resolve(false);
        const id = row.getAttribute("data-whatsapp-click-id");
        if (!id) return Promise.resolve(false);
        const fd = new FormData();
        fd.append("action", action);
        fd.append("whatsapp_click_id", id);
        fd.append("ajax", "1");
        return fetch(window.location.pathname, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          const ct = (r.headers.get("Content-Type") || "").toLowerCase();
          if (ct.indexOf("application/json") === -1) {
            return r.text().then(function (txt) {
              throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
            });
          }
          return r.json();
        }).then(function (data) {
          if (data && typeof data.unread_total === "number" && typeof data.messages_total === "number") {
            setWaCounterFromServer(data.unread_total, data.messages_total);
          }
          return !!(data && data.ok);
        }).catch(function (err) {
          console.error("Error en " + action + ":", err);
          return false;
        });
      }

      function markWaRowAsRead(row) {
        postWaReadToggle(row, "mark_whatsapp_read").then(function (ok) {
          if (ok) applyWaReadStateToRow(row);
        });
      }

      function markWaRowAsUnread(row) {
        postWaReadToggle(row, "mark_whatsapp_unread").then(function (ok) {
          if (ok) applyWaUnreadStateToRow(row);
        });
      }

      waAccordion.querySelectorAll(".accordion-collapse").forEach(function (coll) {
        coll.addEventListener("shown.bs.collapse", function () {
          const row = coll.closest(".message-row");
          if (row && row.getAttribute("data-whatsapp-click-id") && row.classList.contains("is-unread")) {
            markWaRowAsRead(row);
          }
        });
      });

      waAccordion.querySelectorAll(".js-wa-mark-read-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markWaRowAsRead(row);
        });
      });
      waAccordion.querySelectorAll(".js-wa-mark-unread-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markWaRowAsUnread(row);
        });
      });

      const markAllWaForm = document.querySelector("form input[name='action'][value='mark_all_whatsapp_read']");
      if (markAllWaForm && markAllWaForm.form) {
        markAllWaForm.form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const fd = new FormData(markAllWaForm.form);
          fd.append("ajax", "1");
          fetch(window.location.pathname, {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
          }).then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            const ct = (r.headers.get("Content-Type") || "").toLowerCase();
            if (ct.indexOf("application/json") === -1) {
              return r.text().then(function (txt) {
                throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
              });
            }
            return r.json();
          }).then(function (data) {
            if (!data || !data.ok) return;
            if (typeof data.unread_total === "number" && typeof data.messages_total === "number") {
              setWaCounterFromServer(data.unread_total, data.messages_total);
            }
            waAccordion.querySelectorAll(".message-row.is-unread").forEach(function (row) {
              if (row.getAttribute("data-whatsapp-click-id")) {
                applyWaReadStateToRow(row);
              }
            });
            if (markAllWaForm.form.parentElement) markAllWaForm.form.parentElement.removeChild(markAllWaForm.form);
          }).catch(function (err) {
            console.error("Error marcando WhatsApp como leídos:", err);
          });
        });
      }
    })();
  </script>
  <script src="script.js?v=<?= h($adminAssetScriptVer) ?>"></script>
  <script src="admin_filter_tables.js?v=<?= h($adminFilterTablesVer) ?>"></script>
  <script src="admin_ajax.js?v=<?= h($adminAjaxVer) ?>"></script>
</body>
</html>
