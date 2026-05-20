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
            $errMsg = "No se pudieron actualizar los servicios.";
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
  <style>
    .admin-wrap {
      width: min(1280px, 96%);
      margin: 2rem auto;
    }
    .admin-app {
      --admin-bar-height: 3.15rem;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      /* Bootstrap 5.3 usa variables propias; las alineamos al tema de la plantilla para herencia correcta */
      --bs-body-color: var(--text);
      --bs-body-bg: var(--bg);
      --bs-secondary-color: var(--muted);
      --bs-tertiary-color: color-mix(in srgb, var(--muted) 78%, transparent);
      --bs-emphasis-color: var(--text);
      --bs-heading-color: inherit;
      --bs-border-color: var(--border);
      --bs-link-color: var(--accent);
      --bs-link-hover-color: var(--accent-strong);
      --bs-light-text-emphasis: var(--muted);
      --bs-dark-text-emphasis: var(--text);
      --bs-card-color: var(--text);
      --bs-card-bg: color-mix(in srgb, var(--surface) 96%, transparent);
      --bs-card-border-color: var(--border);
      --bs-accordion-color: var(--text);
      --bs-accordion-bg: var(--surface);
      --bs-accordion-btn-color: var(--text);
      --bs-accordion-btn-bg: var(--surface-2);
      --bs-accordion-active-color: var(--text);
      --bs-accordion-active-bg: color-mix(in srgb, var(--accent) 18%, var(--surface));
      --bs-accordion-border-color: var(--border);
      --bs-accordion-btn-icon-width: 1rem;
    }
    .admin-layout {
      display: grid;
      gap: 1rem;
      grid-template-columns: 1fr;
      isolation: isolate;
    }
    /* Columna lateral después en el DOM + sticky: sin capa clara, compite con el main.
       Bootstrap además pone z-index 2/3 en .accordion-button:hover:focus → parpadeo
       entre columnas; aislamos el main y anulamos esos z-index en todo el admin. */
    .admin-main {
      min-width: 0;
      position: relative;
      z-index: 8;
      isolation: isolate;
    }
    .admin-side { min-width: 0; }
    @media (min-width: 992px) {
      .admin-layout {
        grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
        grid-template-rows: auto auto;
        align-items: start;
      }
      .admin-main {
        grid-column: 1;
        grid-row: 1;
      }
      .admin-side {
        grid-column: 2;
        grid-row: 1;
        position: sticky;
        top: 1rem;
        align-self: start;
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        z-index: 0;
        isolation: isolate;
      }
      .admin-agendas-section {
        grid-column: 1 / -1;
        grid-row: 2;
      }
    }
    .admin-app .accordion-button:hover,
    .admin-app .accordion-button:focus,
    .admin-wrap .accordion-button:hover,
    .admin-wrap .accordion-button:focus {
      z-index: auto !important;
    }
    /* Flecha = mismo color que el título (currentColor / --text); la máscara evita SVG por tema roto */
    .admin-app .accordion .accordion-button,
    .admin-wrap .accordion .accordion-button {
      --bs-accordion-btn-color: var(--text);
      --bs-accordion-active-color: var(--text);
      color: var(--text) !important;
    }
    .admin-app .accordion .accordion-button::after,
    .admin-wrap .accordion .accordion-button::after {
      background: none !important;
      background-color: currentColor !important;
      -webkit-mask-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='%23000' fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e") !important;
      mask-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='%23000' fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e") !important;
      -webkit-mask-repeat: no-repeat !important;
      mask-repeat: no-repeat !important;
      -webkit-mask-size: 100% 100% !important;
      mask-size: 100% 100% !important;
      -webkit-mask-position: center !important;
      mask-position: center !important;
      width: var(--bs-accordion-btn-icon-width) !important;
      height: var(--bs-accordion-btn-icon-width) !important;
    }
    .admin-tools-accordion .accordion-button {
      background-color: var(--surface);
      color: var(--text);
      font-weight: 600;
    }
    .admin-tools-accordion .accordion-button:not(.collapsed) {
      background-color: color-mix(in srgb, var(--accent) 18%, var(--surface));
      box-shadow: none;
    }
    .admin-tools-accordion .accordion-body {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
    .wa-phone-input-group .input-group-text {
      font-weight: 700;
      padding-inline: 0.65rem;
    }
    .wa-phone-input-group .wa-cc-field {
      flex: 0 0 auto;
      width: 3.35rem;
      max-width: 3.35rem;
      text-align: center;
      padding-inline: 0.35rem;
    }
    .wa-phone-input-group .wa-local-field {
      min-width: 0;
    }
    .admin-grid { display: grid; gap: .8rem; }
    .admin-grid-2 { display: grid; gap: .8rem; grid-template-columns: 1fr; }
    .admin-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    .admin-msg { color: #86efac; font-weight: 700; }
    .admin-err { color: #fda4af; font-weight: 700; }
    .service-row { border: 1px solid var(--border); border-radius: 10px; padding: .8rem; margin-bottom: .8rem; }
    .login-shell {
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: radial-gradient(circle at top, color-mix(in srgb, var(--accent) 45%, transparent) 0%, var(--bg) 45%, var(--bg-strong) 100%);
      padding: 1rem;
      color: var(--text);
      --bs-body-color: var(--text);
      --bs-secondary-color: var(--muted);
      --bs-light-text-emphasis: var(--muted);
    }
    .login-card {
      width: min(460px, 96%);
      border: 1px solid var(--border);
      border-radius: 18px;
      background: color-mix(in srgb, var(--surface) 92%, transparent);
      box-shadow: 0 30px 60px var(--shadow-soft);
    }
    .login-title {
      font-size: 1.35rem;
      font-weight: 800;
      color: #eef2ff;
    }
    .login-subtitle { color: var(--muted); }
    .login-input {
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
    }
    .login-input::placeholder { color: var(--muted); }
    .login-input:focus {
      border-color: var(--ring);
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ring) 30%, transparent);
      background: var(--field-bg);
      color: var(--text);
    }
    .password-wrap { position: relative; }
    .password-wrap .login-input { padding-right: 2.8rem; }
    .password-toggle {
      position: absolute;
      right: .35rem;
      top: 50%;
      transform: translateY(-50%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.1rem;
      height: 2.1rem;
      background: transparent;
      border: 1px solid transparent;
      color: var(--muted);
      border-radius: 8px;
      cursor: pointer;
      transition: color .15s ease, background .15s ease, border-color .15s ease;
    }
    .password-toggle:hover { color: var(--text); background: var(--field-bg); border-color: var(--border); }
    .password-toggle:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
    .login-help { display: flex; justify-content: center; }
    .login-link {
      color: var(--muted);
      text-decoration: none;
      font-size: .9rem;
      padding: .3rem .55rem;
      border-radius: 6px;
      transition: color .15s ease, background .15s ease;
    }
    .login-link:hover,
    .login-link:focus-visible {
      color: var(--text);
      background: color-mix(in srgb, var(--surface-2) 60%, transparent);
      outline: none;
    }
    .login-view[hidden] { display: none !important; }
    .logo-preview-admin {
      width: 56px;
      height: 56px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--logo-bg);
      padding: 4px;
      object-fit: contain;
    }
    .credential-box {
      background: color-mix(in srgb, var(--surface-2) 88%, transparent);
      border: 1px dashed var(--border);
      color: var(--text);
      border-radius: 12px;
      font-size: .95rem;
    }
    .admin-app .card,
    .admin-wrap .card {
      background: var(--bs-card-bg);
      border: 1px solid var(--border);
      color: var(--bs-card-color);
      border-radius: 14px;
    }
    .admin-app-bar {
      position: sticky;
      top: 0;
      z-index: 420;
      width: 100%;
      border-bottom: 1px solid var(--border);
      backdrop-filter: blur(10px);
      background:
        linear-gradient(90deg, color-mix(in srgb, var(--accent) 10%, transparent), transparent 42%),
        color-mix(in srgb, var(--surface) 96%, transparent);
      box-shadow: 0 1px 0 color-mix(in srgb, var(--border) 80%, transparent);
    }
    .admin-app-bar__inner {
      display: flex;
      align-items: center;
      gap: 0.65rem 1rem;
      min-height: var(--admin-bar-height);
      padding: 0.35rem clamp(0.75rem, 2vw, 1.35rem);
    }
    .admin-app-bar__brand {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      min-width: 0;
      flex: 0 1 auto;
    }
    .admin-app-bar__brand > i {
      flex-shrink: 0;
      font-size: 1.05rem;
      color: var(--accent);
    }
    .admin-app-bar__title {
      font-size: 0.95rem;
      font-weight: 800;
      line-height: 1.1;
      white-space: nowrap;
    }
    .admin-app-bar__session {
      font-size: 0.78rem;
      color: var(--muted);
      max-width: 14rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      padding-left: 0.5rem;
      border-left: 1px solid var(--border);
    }
    .admin-app-bar__workspaces {
      display: none;
      align-items: center;
      justify-content: center;
      gap: 0.3rem;
      flex: 1 1 auto;
      min-width: 0;
    }
    .admin-app-bar__ws-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.32rem 0.7rem;
      border-radius: 999px;
      border: 1px solid transparent;
      color: var(--muted);
      font-size: 0.84rem;
      font-weight: 600;
      text-decoration: none;
      white-space: nowrap;
      transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    }
    .admin-app-bar__ws-link:hover,
    .admin-app-bar__ws-link:focus-visible {
      color: var(--text);
      background: color-mix(in srgb, var(--accent) 10%, var(--surface-2));
    }
    .admin-app-bar__ws-link.is-active {
      color: var(--text);
      border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
      background: color-mix(in srgb, var(--accent) 18%, var(--surface-2));
    }
    .admin-app-bar__actions {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      flex: 0 0 auto;
      margin-left: auto;
    }
    .admin-app-bar__logout {
      padding: 0.28rem 0.55rem;
      font-size: 0.82rem;
    }
    .admin-app-bar__alerts {
      padding: 0  clamp(0.75rem, 2vw, 1.35rem) 0.45rem;
    }
    .admin-app-bar__alerts .alert {
      font-size: 0.85rem;
      padding: 0.35rem 0.55rem;
      margin-bottom: 0.35rem;
    }
    .admin-app-bar__alerts .alert:last-child {
      margin-bottom: 0;
    }
    .admin-toast-stack {
      position: fixed;
      bottom: 1.25rem;
      right: 1.25rem;
      z-index: 1090;
      display: flex;
      flex-direction: column;
      gap: 0.65rem;
      max-width: min(22rem, calc(100vw - 1.5rem));
      pointer-events: none;
    }
    .admin-toast-card {
      pointer-events: auto;
      display: flex;
      align-items: flex-start;
      gap: 0.75rem;
      padding: 0.9rem 1rem 0.75rem;
      border-radius: 14px;
      background: color-mix(in srgb, var(--surface) 92%, #fff 8%);
      border: 1px solid var(--border);
      box-shadow:
        0 12px 40px rgba(0, 0, 0, 0.22),
        0 0 0 1px color-mix(in srgb, var(--border) 60%, transparent);
      animation: adminToastIn 0.4s cubic-bezier(0.22, 1, 0.36, 1);
      overflow: hidden;
      position: relative;
    }
    .admin-toast-card.is-leaving {
      animation: adminToastOut 0.28s ease forwards;
    }
    .admin-toast-card__icon {
      flex-shrink: 0;
      width: 2.25rem;
      height: 2.25rem;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.05rem;
    }
    .admin-toast-card--success .admin-toast-card__icon {
      background: color-mix(in srgb, #198754 22%, transparent);
      color: #75d99b;
    }
    .admin-toast-card--error .admin-toast-card__icon {
      background: color-mix(in srgb, #dc3545 22%, transparent);
      color: #f1a8ae;
    }
    .admin-toast-card--warning .admin-toast-card__icon {
      background: color-mix(in srgb, #ffc107 22%, transparent);
      color: #ffe083;
    }
    .admin-toast-card__body {
      flex: 1;
      min-width: 0;
      padding-top: 0.1rem;
    }
    .admin-toast-card__title {
      font-weight: 600;
      font-size: 0.92rem;
      line-height: 1.25;
      margin: 0 0 0.15rem;
      color: var(--text);
    }
    .admin-toast-card__msg {
      font-size: 0.82rem;
      line-height: 1.35;
      margin: 0;
      color: color-mix(in srgb, var(--text) 78%, transparent);
    }
    .admin-toast-card__close {
      flex-shrink: 0;
      border: 0;
      background: transparent;
      color: color-mix(in srgb, var(--text) 55%, transparent);
      padding: 0.15rem;
      line-height: 1;
      cursor: pointer;
      border-radius: 6px;
    }
    .admin-toast-card__close:hover {
      color: var(--text);
      background: color-mix(in srgb, var(--text) 8%, transparent);
    }
    .admin-toast-card__progress {
      position: absolute;
      left: 0;
      bottom: 0;
      height: 3px;
      width: 100%;
      transform-origin: left center;
      background: currentColor;
      opacity: 0.85;
    }
    .admin-toast-card--success .admin-toast-card__progress {
      color: #75d99b;
    }
    .admin-toast-card--error .admin-toast-card__progress {
      color: #f1a8ae;
    }
    .admin-toast-card--warning .admin-toast-card__progress {
      color: #ffe083;
    }
    .admin-ajax-flash-saved {
      animation: adminPanelSaved 1.35s ease;
    }
    @keyframes adminToastIn {
      from {
        opacity: 0;
        transform: translateX(1.25rem) scale(0.96);
      }
      to {
        opacity: 1;
        transform: translateX(0) scale(1);
      }
    }
    @keyframes adminToastOut {
      to {
        opacity: 0;
        transform: translateX(0.75rem) scale(0.96);
      }
    }
    @keyframes adminPanelSaved {
      0%, 100% {
        box-shadow: none;
        outline: 2px solid transparent;
      }
      25% {
        outline: 2px solid color-mix(in srgb, #75d99b 55%, transparent);
        box-shadow: 0 0 0 4px color-mix(in srgb, #75d99b 12%, transparent);
      }
    }
    .portal-client-pill.is-ajax-pulse {
      animation: adminPillPulse 0.55s ease;
    }
    @keyframes adminPillPulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.06); }
    }
    form.is-ajax-busy {
      opacity: 0.72;
      pointer-events: none;
      transition: opacity 0.2s ease;
    }
    .admin-ajax-reply-new {
      animation: adminReplyHighlight 1.4s ease;
      border-radius: 8px;
      padding: 0.35rem 0.5rem;
      margin-top: 0.35rem;
    }
    @keyframes adminReplyHighlight {
      0% {
        background: color-mix(in srgb, #75d99b 28%, transparent);
      }
      100% {
        background: transparent;
      }
    }
    .admin-app-body {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: stretch;
      width: 100%;
      min-height: calc(100vh - var(--admin-bar-height));
    }
    .admin-app-sidebar {
      display: flex;
      flex-direction: column;
      gap: 0.65rem;
      width: 3.35rem;
      padding: 0.55rem 0.35rem 0.85rem;
      border-right: 1px solid var(--border);
      background: color-mix(in srgb, var(--surface-2) 55%, var(--surface));
      position: sticky;
      top: var(--admin-bar-height);
      align-self: start;
      max-height: calc(100vh - var(--admin-bar-height));
      overflow-x: hidden;
      overflow-y: auto;
      z-index: 12;
    }
    .admin-app-sidebar__heading {
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
      padding: 0 0.45rem 0.25rem;
    }
    @media (max-width: 991.98px) {
      .admin-app-sidebar__heading {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
      }
    }
    .admin-app-sidebar__list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
    }
    .admin-app-sidebar__link {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 0.5rem;
      padding: 0.42rem 0.55rem;
      border-radius: 8px;
      color: var(--text);
      text-decoration: none;
      font-size: 0.88rem;
      font-weight: 600;
      line-height: 1.25;
      border: 1px solid transparent;
      transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    @media (max-width: 991.98px) {
      .admin-app-sidebar__link {
        position: relative;
        justify-content: center;
        width: 2.65rem;
        height: 2.65rem;
        padding: 0;
        margin-inline: auto;
      }
      .admin-app-sidebar__link > span {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
      }
      .admin-app-sidebar__link::after {
        content: attr(data-label);
        position: absolute;
        left: calc(100% + 0.45rem);
        top: 50%;
        transform: translateY(-50%);
        z-index: 500;
        padding: 0.3rem 0.55rem;
        border-radius: 6px;
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--text);
        font-size: 0.78rem;
        font-weight: 600;
        white-space: nowrap;
        box-shadow: 0 4px 14px color-mix(in srgb, var(--bg) 55%, transparent);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.12s ease, visibility 0.12s ease;
      }
      .admin-app-sidebar__link:hover::after,
      .admin-app-sidebar__link:focus-visible::after {
        opacity: 1;
        visibility: visible;
      }
      .admin-app-sidebar__hint {
        display: none;
      }
    }
    .admin-app-sidebar__link:hover,
    .admin-app-sidebar__link:focus-visible {
      background: color-mix(in srgb, var(--accent) 10%, var(--surface));
      color: var(--text);
    }
    .admin-app-sidebar__link.is-active {
      border-color: color-mix(in srgb, var(--accent) 40%, var(--border));
      background: color-mix(in srgb, var(--accent) 16%, var(--surface));
    }
    .admin-app-sidebar__link i {
      width: 1.15rem;
      text-align: center;
      flex-shrink: 0;
      color: var(--text);
      opacity: 0.92;
      line-height: 1;
    }
    .admin-wrap .admin-icon-clock,
    .admin-app .admin-icon-clock,
    .admin-wrap .accordion-button .admin-icon-clock,
    .admin-wrap .adm-th-short .admin-icon-clock,
    .expert-appointments-table .adm-th-short .admin-icon-clock {
      color: var(--text) !important;
      opacity: 0.95;
    }
    .admin-wrap .expert-appointments-table code {
      color: var(--text) !important;
    }
    .admin-app-sidebar__link i.fa-brands {
      font-family: var(--fa-style-family-brands, "Font Awesome 6 Brands");
      font-weight: 400;
    }
    .admin-app-sidebar__hint {
      padding: 0 0.45rem;
      line-height: 1.45;
    }
    .admin-app-main {
      min-width: 0;
      padding: 0.75rem clamp(0.55rem, 2vw, 1.35rem) 1.5rem;
      max-width: none;
    }
    @media (min-width: 768px) {
      .admin-app-bar__workspaces {
        display: flex;
      }
    }
    @media (min-width: 992px) {
      .admin-app-body {
        grid-template-columns: 13.25rem minmax(0, 1fr);
        min-height: calc(100vh - var(--admin-bar-height));
      }
      .admin-app-sidebar {
        width: auto;
        padding: 0.85rem 0.55rem 1.25rem;
      }
      .admin-app-sidebar__link {
        width: auto;
        height: auto;
        padding: 0.42rem 0.55rem;
        margin-inline: 0;
        justify-content: flex-start;
      }
      .admin-app-sidebar__link > span {
        position: static;
        width: auto;
        height: auto;
        margin: 0;
        overflow: visible;
        clip: auto;
        white-space: normal;
      }
      .admin-app-sidebar__link::after {
        content: none;
        display: none;
      }
      .admin-app-sidebar__list {
        gap: 0.1rem;
      }
      .admin-app-main {
        padding-top: 1rem;
      }
    }
    @media (min-width: 576px) {
      .admin-app-bar__session {
        max-width: 22rem;
      }
    }
    .admin-app .form-label,
    .admin-wrap .form-label {
      color: var(--text);
      font-weight: 600;
      margin-bottom: .35rem;
    }
    .admin-app .form-control,
    .admin-app .form-select,
    .admin-wrap .form-control,
    .admin-wrap .form-select {
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
    }
    .admin-app .form-control::placeholder,
    .admin-wrap .form-control::placeholder {
      color: var(--muted);
    }
    .admin-app .form-control:focus,
    .admin-app .form-select:focus,
    .admin-wrap .form-control:focus,
    .admin-wrap .form-select:focus {
      border-color: var(--ring);
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ring) 30%, transparent);
      background: var(--field-bg);
      color: var(--text);
    }
    .admin-app .form-check-input,
    .admin-wrap .form-check-input {
      border-color: var(--border);
      background-color: var(--field-bg);
    }
    .admin-app .form-check-input:checked,
    .admin-wrap .form-check-input:checked {
      background-color: var(--accent);
      border-color: var(--accent-strong);
    }
    .admin-app .btn-outline-light,
    .admin-wrap .btn-outline-light {
      border-color: var(--border);
      color: var(--text);
    }
    .admin-app .btn-outline-light:hover,
    .admin-wrap .btn-outline-light:hover {
      background: color-mix(in srgb, var(--accent) 22%, var(--surface-2));
      border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
      color: var(--text);
    }
    .admin-app .text-light-emphasis {
      color: var(--muted) !important;
    }
    .admin-app .link-light {
      color: var(--accent);
      text-decoration-color: color-mix(in srgb, var(--accent) 45%, transparent);
    }
    .admin-app .link-light:hover,
    .admin-app .link-light:focus-visible {
      color: var(--accent-strong);
    }
    .admin-app .border-secondary {
      border-color: var(--border) !important;
    }
    .admin-app .admin-panel-surface,
    .admin-app .admin-agendas-expert-datos,
    .admin-app .admin-expert-subpanel {
      background: var(--surface-2);
      border-color: var(--border);
      color: var(--text);
    }
    html[data-theme="light"] .admin-app .admin-panel-surface,
    html[data-theme="light"] .admin-app .admin-agendas-expert-datos,
    html[data-theme="light"] .admin-app .admin-expert-subpanel {
      background: color-mix(in srgb, var(--surface) 92%, var(--palette-soft));
    }
    .admin-app .btn-outline-secondary {
      --bs-btn-color: var(--text);
      --bs-btn-border-color: var(--border);
      --bs-btn-hover-color: var(--text);
      --bs-btn-hover-bg: color-mix(in srgb, var(--accent) 14%, var(--surface-2));
      --bs-btn-hover-border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
      --bs-btn-active-color: var(--text);
      --bs-btn-active-bg: color-mix(in srgb, var(--accent) 20%, var(--surface-2));
    }
    .admin-app .btn-outline-info {
      --bs-btn-color: var(--accent);
      --bs-btn-border-color: color-mix(in srgb, var(--accent) 50%, var(--border));
      --bs-btn-hover-color: var(--text);
      --bs-btn-hover-bg: color-mix(in srgb, var(--accent) 18%, var(--surface-2));
    }
    .admin-app .btn-outline-warning {
      --bs-btn-color: color-mix(in srgb, var(--accent-strong) 70%, #e8a317);
      --bs-btn-border-color: color-mix(in srgb, #e8a317 45%, var(--border));
      --bs-btn-hover-color: var(--text);
      --bs-btn-hover-bg: color-mix(in srgb, #e8a317 16%, var(--surface-2));
      --bs-btn-hover-border-color: color-mix(in srgb, #e8a317 55%, var(--border));
    }
    .admin-app .form-text,
    .admin-wrap .form-text {
      color: var(--muted);
    }
    .admin-app.admin-wrap.admin-app--layout-wide {
      width: 100%;
      max-width: none;
      margin: 0;
    }
    .admin-app-bar__layout-toggle {
      display: none;
      align-items: center;
      gap: 0.35rem;
      white-space: nowrap;
    }
    @media (min-width: 992px) {
      .admin-app-bar__layout-toggle {
        display: inline-flex;
      }
    }
    .admin-app--layout-wide .admin-app-main {
      max-width: none;
    }
    @media (min-width: 992px) {
      .admin-app--layout-wide .admin-side-inbox-card {
        max-width: none;
        margin-left: 0;
        margin-right: 0;
      }
      .admin-app--layout-wide .admin-layout--workspace-inbox .admin-side {
        width: 100%;
      }
      .admin-app--layout-wide .admin-agendas-section {
        width: 100%;
      }
    }
    .icon-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(46px, 1fr));
      gap: .5rem;
      margin-top: .5rem;
    }
    .icon-option {
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
      border-radius: 10px;
      width: 100%;
      aspect-ratio: 1 / 1;
      padding: .25rem;
      text-align: center;
      display: flex;
      justify-content: center;
      align-items: center;
      font-size: 1rem;
    }
    .icon-option i {
      color: var(--icon-soft);
    }
    .icon-option.is-active {
      border-color: var(--ring);
      background: color-mix(in srgb, var(--accent) 35%, var(--field-bg));
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ring) 28%, transparent);
    }
    #adminToolsAccordion.admin-tools-ordered {
      display: flex;
      flex-direction: column;
    }
    #adminToolsAccordion.admin-tools-ordered > .accordion-item {
      width: 100%;
      min-width: 0;
    }
    /* Orden por responsabilidad: sistema → contenido → equipo → portal */
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-config { order: 1; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-credentials { order: 2; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-routes { order: 3; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-service-edit { order: 4; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tools-experts,
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-experts-off { order: 5; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-clients { order: 6; }
    .admin-agendas-section {
      grid-column: 1 / -1;
      width: 100%;
      margin-top: 0.25rem;
      padding: 1.25rem 1.35rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--bs-border-radius-lg, 0.5rem);
      box-shadow: 0 1px 0 color-mix(in srgb, var(--text) 6%, transparent);
    }
    .admin-agendas-section .admin-agendas-inner-accordion .accordion-item {
      background: var(--surface-2);
      border-color: var(--border);
    }
    .admin-agendas-section .admin-expert-subpanel {
      max-width: none;
    }
    .admin-agendas-schedule-block {
      width: 100%;
    }
    .admin-agendas-expert-pills .nav-link {
      font-size: 0.9rem;
      padding: 0.35rem 0.75rem;
    }
    .admin-agendas-expert-tabs .nav-link {
      font-size: 0.9rem;
    }
    .admin-agendas-quick-settings {
      margin-top: 0.35rem;
      padding-top: 0.85rem;
    }
    .admin-agendas-quick-settings__list {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }
    .admin-agendas-quick-item {
      border: 1px solid var(--border);
      border-radius: var(--bs-border-radius, 0.375rem);
      background: color-mix(in srgb, var(--surface-2) 88%, transparent);
    }
    .admin-agendas-quick-item__summary {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.45rem 0.65rem;
      font-size: 0.88rem;
      font-weight: 600;
      cursor: pointer;
      list-style: none;
      color: var(--text);
    }
    .admin-agendas-quick-item__summary::-webkit-details-marker {
      display: none;
    }
    .admin-agendas-quick-item__summary::before {
      content: "";
      width: 0.45rem;
      height: 0.45rem;
      border-right: 2px solid var(--text);
      border-bottom: 2px solid var(--text);
      opacity: 0.88;
      transform: rotate(-45deg);
      transition: transform 0.15s ease, opacity 0.15s ease;
      flex-shrink: 0;
    }
    .admin-agendas-quick-item[open] > .admin-agendas-quick-item__summary::before {
      transform: rotate(45deg);
      opacity: 1;
    }
    .admin-agendas-quick-item__body {
      padding: 0.55rem 0.65rem 0.75rem;
      border-top: 1px solid var(--border);
    }
    .expert-template-shortcut__day-picks .btn-check:checked + .btn {
      background: color-mix(in srgb, var(--accent) 22%, var(--surface));
      border-color: color-mix(in srgb, var(--accent) 50%, var(--border));
      color: var(--text);
    }
    .expert-template-block + .expert-template-block {
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px dashed var(--border);
    }
    .admin-agendas-expert-nav .admin-agendas-expert-pills {
      flex-wrap: nowrap;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      padding-bottom: 0.15rem;
      margin-bottom: 0;
    }
    .admin-agendas-expert-tabs {
      flex-wrap: nowrap;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .admin-agendas-expert-tabs .nav-item {
      flex: 0 0 auto;
    }
    .admin-agendas-section__header .btn {
      width: 100%;
    }
    @media (min-width: 576px) {
      .admin-agendas-section__header .btn {
        width: auto;
      }
    }
    @media (max-width: 991.98px) {
      .admin-agendas-section {
        padding: 0.85rem 0.65rem;
      }
      .admin-agendas-section .admin-expert-subpanel {
        padding: 0.65rem !important;
      }
      .admin-expert-week-toolbar {
        gap: 0.35rem;
      }
      .admin-expert-week-toolbar .btn {
        font-size: 0.78rem;
        padding: 0.25rem 0.45rem;
      }
      .admin-expert-week-toolbar .fw-semibold {
        flex: 1 1 100%;
        text-align: center;
        font-size: 0.82rem;
      }
      .admin-agendas-expert-datos {
        padding: 0.65rem !important;
      }
    }
    .admin-portal-clients-body {
      padding-inline: 0.5rem;
      max-width: 100%;
    }
    .admin-portal-clients-body .table-responsive {
      margin-inline: -0.25rem;
      width: calc(100% + 0.5rem);
      max-width: none;
    }
    .admin-portal-clients-body .table td,
    .admin-portal-clients-body .table th {
      vertical-align: middle;
    }
    .admin-portal-clients-body .table td.font-monospace {
      word-break: break-word;
      max-width: 14rem;
    }
    .admin-portal-client-actions {
      gap: 0.35rem !important;
    }
    .admin-portal-client-actions form {
      margin: 0;
    }
    .admin-portal-client-actions .btn {
      min-width: 2.35rem;
      padding: 0.28rem 0.45rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .admin-portal-client-actions .btn i {
      font-size: 0.95rem;
      line-height: 1;
    }
    .admin-portal-clients-body .badge.portal-client-pill {
      font-weight: 600;
      font-size: 0.78rem;
      padding: 0.35em 0.65em;
      gap: 0.35em;
    }
    .admin-portal-clients-table .portal-client-toggle-btn {
      cursor: pointer;
      font-weight: 600;
      font-size: 0.78rem;
      padding: 0.35em 0.65em;
      gap: 0.35em;
      line-height: 1.2;
      text-decoration: none;
      transition: filter 0.12s ease, box-shadow 0.12s ease;
    }
    .admin-portal-clients-table .portal-client-toggle-btn:hover {
      filter: brightness(1.08);
    }
    .admin-portal-clients-table .portal-client-toggle-btn:focus-visible {
      outline: 2px solid color-mix(in srgb, var(--accent, #0d6efd) 85%, #fff);
      outline-offset: 2px;
    }
    .admin-portal-clients-table {
      table-layout: fixed;
      width: 100%;
    }
    .admin-portal-clients-table .portal-col-email {
      width: 34%;
      min-width: 0;
    }
    .admin-portal-clients-table .portal-col-name {
      width: 26%;
      min-width: 0;
    }
    .admin-portal-clients-table .portal-col-account,
    .admin-portal-clients-table .portal-col-smtp {
      width: 14%;
      text-align: center;
    }
    .admin-portal-clients-table .portal-col-actions {
      width: 12%;
    }
    .admin-portal-clients-table .portal-client-email-line {
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.35;
    }
    .admin-portal-clients-table .portal-th-short {
      display: none;
    }
    .admin-portal-clients-table .portal-client-name-mobile {
      display: none;
      margin-top: 0.15rem;
      line-height: 1.3;
      overflow-wrap: anywhere;
    }
    @media (max-width: 575.98px) {
      .admin-portal-clients-table {
        table-layout: auto;
      }
      .admin-portal-clients-table thead .portal-th-full {
        display: none;
      }
      .admin-portal-clients-table thead .portal-th-short {
        display: inline;
      }
      .admin-portal-clients-table .portal-col-email {
        width: auto;
        min-width: 0;
        max-width: none;
      }
      .admin-portal-clients-table .portal-col-name {
        display: none !important;
      }
      .admin-portal-clients-table .portal-client-name-mobile {
        display: block;
      }
      .admin-portal-clients-table .portal-col-account,
      .admin-portal-clients-table .portal-col-smtp {
        width: 2.35rem;
        padding-left: 0.2rem;
        padding-right: 0.2rem;
      }
      .admin-portal-clients-table .portal-col-actions {
        width: 2.35rem;
        padding-left: 0.15rem;
        white-space: nowrap;
      }
      .admin-portal-clients-table .portal-pill-label {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
      }
      .admin-portal-clients-table .portal-client-toggle-btn {
        padding: 0.32em 0.42em;
        min-width: 2rem;
        justify-content: center;
      }
      .admin-portal-clients-table .portal-client-toggle-btn .ms-1 {
        margin-left: 0 !important;
      }
      .admin-portal-clients-body .table td.font-monospace {
        max-width: none;
      }
      .admin-portal-client-actions .btn {
        min-width: 2rem;
        padding: 0.22rem 0.35rem;
      }
    }
    /* Tablas filtrables (expertos, citas) */
    .admin-filter-table {
      --aft-border: color-mix(in srgb, var(--border) 88%, transparent);
      --aft-bg: color-mix(in srgb, var(--surface) 96%, var(--surface-2));
      --aft-bg-alt: color-mix(in srgb, var(--surface-2) 72%, var(--surface));
      --aft-head-bg: color-mix(in srgb, var(--surface-2) 96%, var(--accent) 4%);
      border: 1px solid var(--aft-border);
      border-radius: 12px;
      background: var(--aft-bg);
      overflow: hidden;
    }
    .admin-filter-table__meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.35rem 0.65rem;
      padding: 0.4rem 0.65rem;
      border-bottom: 1px solid var(--aft-border);
      background: var(--aft-head-bg);
    }
    .admin-filter-table__clear {
      font-size: 0.78rem;
      text-decoration: none;
      color: var(--accent);
    }
    .admin-filter-table__clear:hover {
      text-decoration: underline;
    }
    .admin-filter-table__count {
      white-space: nowrap;
    }
    .admin-filter-table__scroll {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      background: var(--aft-bg);
    }
    .admin-filter-table__table {
      margin: 0;
      width: 100%;
      min-width: 100%;
      color: var(--text);
      background-color: var(--aft-bg);
      border-collapse: separate;
      border-spacing: 0;
    }
    .admin-filter-table__head-row th {
      position: sticky;
      top: 0;
      z-index: 3;
      background-color: var(--aft-head-bg);
      border-bottom: 1px solid var(--aft-border);
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
      white-space: nowrap;
      vertical-align: middle;
      padding: 0.5rem 0.45rem;
    }
    .admin-filter-table__filter-row th {
      position: sticky;
      top: 2.05rem;
      z-index: 2;
      background-color: var(--aft-head-bg);
      border-bottom: 1px solid var(--aft-border);
      padding: 0.28rem 0.35rem;
      vertical-align: middle;
      font-weight: 400;
      text-transform: none;
      letter-spacing: normal;
    }
    .admin-filter-table__col-input {
      width: 100%;
      min-width: 3.5rem;
      border-radius: 6px;
      background: var(--surface);
      border-color: var(--border);
      color: var(--text);
      font-size: 0.78rem;
      font-weight: 400;
      text-transform: none;
    }
    .admin-filter-table__col-input::placeholder {
      color: var(--muted);
      opacity: 0.85;
    }
    .admin-filter-table__table tbody td {
      border-bottom: 1px solid color-mix(in srgb, var(--border) 55%, transparent);
      padding: 0.45rem 0.45rem;
      vertical-align: middle;
      background-color: var(--aft-bg);
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .admin-filter-table__text-2l {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
      line-clamp: 2;
      overflow: hidden;
      white-space: normal;
      line-height: 1.32;
      max-width: 100%;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .admin-filter-table__table code.admin-filter-table__text-2l {
      display: -webkit-box;
      white-space: normal;
    }
    .admin-filter-table__table tbody tr[data-filter-row].is-alt td {
      background-color: var(--aft-bg-alt);
    }
    .admin-filter-table__table tbody tr[data-filter-row]:hover td {
      background-color: color-mix(in srgb, var(--accent) 12%, var(--aft-bg));
    }
    .admin-filter-table__table tbody tr[data-filter-row].is-alt:hover td {
      background-color: color-mix(in srgb, var(--accent) 12%, var(--aft-bg-alt));
    }
    .admin-filter-table__table tbody tr.admin-filter-table__detail-row td {
      background-color: color-mix(in srgb, var(--aft-bg-alt) 85%, var(--surface-2));
    }
    .admin-filter-table__table tbody tr:last-child td,
    .admin-filter-table__table tbody tr.admin-filter-table__empty td {
      border-bottom: none;
    }
    .admin-filter-table__th-sortable {
      cursor: pointer;
      user-select: none;
    }
    .admin-filter-table__th-sortable:hover {
      color: var(--text);
    }
    .admin-filter-table__th-sortable.is-sorted-asc::after,
    .admin-filter-table__th-sortable.is-sorted-desc::after {
      font-family: "Font Awesome 6 Free";
      font-weight: 900;
      font-size: 0.62rem;
      margin-left: 0.25rem;
      opacity: 0.85;
    }
    .admin-filter-table__th-sortable.is-sorted-asc::after {
      content: "\f0de";
    }
    .admin-filter-table__th-sortable.is-sorted-desc::after {
      content: "\f0dd";
    }
    .admin-filter-table .adm-th-short {
      display: none;
    }
    .admin-filter-table .adm-action-label {
      display: none !important;
    }
    .admin-experts-table.table-hover tbody tr,
    .admin-filter-table__table.table-hover tbody tr {
      transition: background-color 0.12s ease;
    }
    .admin-expert-row-actions {
      gap: 0.35rem !important;
    }
    .admin-expert-row-actions form {
      margin: 0;
      display: inline-flex;
    }
    .admin-expert-row-actions .btn {
      min-width: 2.35rem;
      padding: 0.28rem 0.45rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .admin-expert-row-actions .btn i {
      font-size: 0.95rem;
      line-height: 1;
    }
    .admin-experts-table .badge.expert-pill {
      font-weight: 600;
      font-size: 0.78rem;
      padding: 0.35em 0.65em;
      gap: 0.35em;
    }
    .admin-experts-table {
      table-layout: fixed;
      width: 100%;
    }
    .admin-experts-table .expert-col-name {
      overflow: hidden;
    }
    .admin-experts-table .expert-row-name {
      max-width: 100%;
    }
    .admin-experts-table th.expert-services-col,
    .admin-experts-table td.expert-services-col {
      max-width: 14rem;
      width: 22%;
      min-width: 0;
      vertical-align: middle;
    }
    .admin-experts-table .expert-row-services {
      width: 100%;
      min-width: 0;
      justify-content: flex-start;
    }
    .admin-experts-table .expert-svc-icons-slot {
      flex: 1 1 auto;
      min-width: 0;
      overflow: hidden;
    }
    .admin-experts-table .expert-svc-icons-inner {
      display: inline-flex;
      flex-wrap: nowrap;
      align-items: center;
      gap: 0.25rem;
      vertical-align: middle;
    }
    .admin-experts-table .expert-svc-icon {
      width: 1.75rem;
      height: 1.75rem;
      font-size: 0.82rem;
      line-height: 1;
      cursor: help;
      flex-shrink: 0;
    }
    .admin-experts-table .expert-svc-overflow-plus {
      display: none;
      cursor: help;
    }
    .admin-experts-table .expert-svc-overflow-plus.is-visible {
      display: inline-flex;
    }
    .admin-experts-table .expert-svc-count-badge {
      flex-shrink: 0;
    }
    .admin-experts-table .expert-svc-icon i {
      line-height: 1;
    }
    .admin-experts-table .expert-th-short,
    .admin-experts-table .adm-th-short {
      display: none;
    }
    .admin-experts-table .expert-info-btn--mobile {
      display: none;
    }
    .admin-experts-table .expert-info-badge--desktop {
      display: inline-flex;
    }
    .admin-experts-table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .expert-appointments-table {
      table-layout: fixed;
      width: 100%;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-datetime {
      width: 18%;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-expert {
      width: 16%;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-service {
      width: 20%;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-guest {
      width: 28%;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-status {
      width: 9%;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-actions {
      width: 11%;
      min-width: 4.5rem;
      white-space: nowrap;
    }
    .expert-appointments-filter-table.is-appt-compact .expert-appointments-table .appt-col-datetime {
      width: auto !important;
    }
    .expert-appointments-filter-table.is-appt-compact .expert-appointments-table .appt-col-expert,
    .expert-appointments-filter-table.is-appt-compact .expert-appointments-table .appt-col-service,
    .expert-appointments-filter-table.is-appt-compact .expert-appointments-table .appt-col-guest,
    .expert-appointments-filter-table.is-appt-compact .expert-appointments-table .appt-col-status {
      width: 0 !important;
      min-width: 0 !important;
      max-width: 0 !important;
    }
    .expert-appointments-filter-table.is-appt-compact .expert-appointments-table .appt-col-actions {
      width: 7rem !important;
      min-width: 7rem !important;
      max-width: 7rem !important;
    }
    .expert-appointments-table .appt-guest-email {
      overflow-wrap: anywhere;
    }
    .expert-appointments-table .adm-mobile-only,
    .expert-appointments-table .adm-compact-only {
      display: none !important;
    }
    .expert-appointments-table tr.appt-mobile-detail-row.adm-compact-only,
    .expert-appointments-table tr.appt-mobile-detail-row.adm-mobile-only {
      display: none !important;
    }
    .expert-appointments-table .appt-datetime-cell {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      flex-wrap: nowrap;
      min-width: 0;
    }
    .expert-appointments-table .appt-mobile-svc-icon {
      width: 1.85rem;
      height: 1.85rem;
      font-size: 0.88rem;
      flex-shrink: 0;
      cursor: help;
    }
    .expert-appointments-table .appt-datetime-full {
      flex: 1 1 auto;
      min-width: 0;
    }
    .expert-appointments-table .appt-col-service {
      max-width: 8rem;
    }
    .expert-appointments-table .appt-svc-icon {
      width: 1.75rem;
      height: 1.75rem;
      font-size: 0.82rem;
      flex-shrink: 0;
      cursor: help;
    }
    .expert-appointments-table .appt-svc-label {
      max-width: 100%;
      vertical-align: middle;
      margin-left: 0.35rem;
    }
    .expert-appointments-table .appt-col-guest {
      max-width: 12rem;
    }
    .expert-appointments-table .appt-col-expert {
      max-width: 10rem;
    }
    .expert-appointments-table .appt-expand-btn {
      flex-shrink: 0;
      min-width: 1.75rem;
      line-height: 1.1;
    }
    .expert-appointments-table .appt-expand-btn[aria-expanded="true"] .appt-expand-icon::before {
      content: "\f068";
    }
    .expert-appointments-table .appt-mobile-detail-panel {
      background: color-mix(in srgb, var(--surface-2) 90%, var(--surface));
    }
    .expert-appointments-table .appt-mobile-detail-list li + li {
      margin-top: 0.35rem;
    }
    .expert-appointments-table .admin-appt-notify-log {
      max-width: 100%;
    }
    @media (max-width: 575.98px) {
      .admin-experts-table {
        table-layout: auto;
      }
      .admin-filter-table__filter-row th {
        top: 1.85rem;
      }
      .admin-filter-table__meta {
        padding: 0.35rem 0.5rem;
      }
      .admin-filter-table .adm-th-full,
      .admin-experts-table thead .expert-th-full,
      .admin-experts-table thead .adm-th-full {
        display: none;
      }
      .admin-filter-table .adm-th-short,
      .admin-experts-table thead .expert-th-short,
      .admin-experts-table thead .adm-th-short {
        display: inline;
      }
      .admin-experts-table .expert-col-services,
      .admin-experts-table .expert-services-col {
        display: none !important;
      }
      .admin-experts-table .expert-pill-label {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
      }
      .admin-experts-table .expert-pill {
        padding: 0.35em 0.45em;
      }
      .admin-experts-table .expert-col-order {
        width: 2.25rem;
        padding-left: 0.15rem;
        padding-right: 0.15rem;
      }
      .admin-experts-table .expert-col-status {
        width: 2.5rem;
        padding-left: 0.15rem;
        padding-right: 0.15rem;
      }
      .admin-experts-table .expert-col-actions {
        width: auto;
        white-space: nowrap;
        padding-left: 0.15rem;
      }
      .admin-experts-table .expert-col-name {
        min-width: 0;
        max-width: 42vw;
      }
      .admin-experts-table .expert-row-name {
        font-size: 0.88rem;
      }
      .admin-experts-table .expert-info-btn--mobile {
        display: inline-flex;
      }
      .admin-experts-table .expert-info-badge--desktop {
        display: none !important;
      }
      .admin-experts-table .admin-expert-row-actions {
        gap: 0.2rem !important;
      }
      .admin-experts-table .admin-expert-row-actions .btn {
        min-width: 2rem;
        padding: 0.22rem 0.35rem;
      }
      .admin-experts-table .expert-detail-row td {
        border-top: none;
      }
    }
    .admin-services-accordion .accordion-item {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: .65rem;
    }
    .admin-services-accordion .accordion-button {
      background: var(--surface-2);
      color: var(--text);
      font-weight: 700;
      box-shadow: none;
    }
    .admin-services-accordion .accordion-button:not(.collapsed) {
      background: color-mix(in srgb, var(--accent) 22%, var(--surface-2));
      color: var(--text);
    }
    .admin-services-accordion .accordion-body {
      background: color-mix(in srgb, var(--surface) 94%, transparent);
    }
    .admin-experts-inner-accordion .accordion-item {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 0.65rem;
    }
    .admin-experts-inner-accordion .accordion-button {
      background: var(--surface-2);
      color: var(--text);
      font-weight: 700;
      font-size: 0.92rem;
      box-shadow: none;
    }
    .admin-experts-inner-accordion .accordion-button:not(.collapsed) {
      background: color-mix(in srgb, var(--accent) 22%, var(--surface-2));
      color: var(--text);
    }
    .admin-experts-inner-accordion .accordion-body {
      background: color-mix(in srgb, var(--surface) 94%, transparent);
    }
    .admin-experts-inner-accordion .accordion-body.p-0 .admin-expert-subpanel {
      border: none;
      margin: 0;
    }
    .admin-agenda-notify-item--unread {
      border-left: 3px solid var(--accent, #0d6efd);
      background: color-mix(in srgb, var(--accent, #0d6efd) 8%, transparent);
    }
    .admin-appt-notify-log summary {
      cursor: pointer;
    }
    .admin-side-appt-history-list {
      max-height: min(70vh, 28rem);
      overflow-y: auto;
    }
    .admin-side-appt-history-row .appt-expand-btn {
      align-self: flex-start;
    }
    .admin-services-accordion .accordion-header-service-title {
      flex: 1;
      min-width: 0;
      text-align: left;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .gallery-tools {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: .6rem;
    }
    .gallery-input-hidden {
      display: none;
    }
    .gallery-thumbs {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }
    .gallery-thumb-wrap {
      display: flex;
      flex-direction: column;
      gap: 4px;
      width: 78px;
    }
    .gallery-thumb-wrap.is-draggable {
      cursor: grab;
    }
    .gallery-thumb-wrap.is-dragging {
      opacity: .45;
      cursor: grabbing;
    }
    .gallery-thumb-item {
      position: relative;
      width: 78px;
      height: 78px;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
    }
    .gallery-caption-input {
      width: 100%;
      font-size: 11px;
      padding: 2px 6px;
      height: auto;
      min-height: 24px;
    }
    .gallery-thumb-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .gallery-thumb-check {
      position: absolute;
      top: 4px;
      right: 4px;
      width: 16px;
      height: 16px;
      margin: 0;
      accent-color: var(--accent);
    }
    .gallery-thumb-check:checked + .gallery-mark-overlay {
      opacity: 1;
    }
    .gallery-mark-overlay {
      position: absolute;
      inset: 0;
      border: 2px solid #ef4444;
      background: rgba(239, 68, 68, 0.16);
      opacity: 0;
      pointer-events: none;
    }
    @media (min-width: 768px) { .admin-grid-2 { grid-template-columns: 1fr 1fr; } }

    .admin-messages-accordion .accordion-item {
      background-color: var(--surface);
      border: 1px solid var(--border);
    }
    .admin-messages-accordion .message-row { position: relative; }
    .admin-messages-accordion .message-header-row {
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      background-color: var(--surface);
    }
    .admin-messages-accordion .message-header-row .accordion-button {
      min-width: 0;
    }
    .admin-messages-accordion .accordion-button {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-messages-accordion .accordion-button:not(.collapsed) {
      box-shadow: none;
    }
    .admin-messages-accordion .message-row.is-unread {
      border-left: 4px solid #ffc107;
      box-shadow: 0 0 0 1px color-mix(in srgb, #ffc107 35%, transparent);
    }
    .admin-inbox-threads .message-row.is-unread {
      border-left: 4px solid #ffc107;
      box-shadow: 0 0 0 1px color-mix(in srgb, #ffc107 35%, transparent);
    }
    .admin-messages-accordion .message-row.is-unread .accordion-button,
    .admin-messages-accordion .message-row.is-unread .message-header-row {
      background-color: color-mix(in srgb, #ffc107 14%, var(--surface));
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-turn-toolbar {
      background-color: color-mix(in srgb, #ffc107 14%, var(--surface));
    }
    .admin-messages-accordion .message-row.is-unread .accordion-button {
      font-weight: 700;
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-turn-head {
      font-weight: 700;
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-bubble--visitor {
      box-shadow: inset 3px 0 0 #ffc107;
      border-color: color-mix(in srgb, #ffc107 40%, var(--border));
    }
    .admin-messages-accordion .message-row.is-unread .accordion-button::before {
      content: "";
      width: .6rem;
      height: .6rem;
      border-radius: 50%;
      background-color: #ffc107;
      box-shadow: 0 0 0 4px color-mix(in srgb, #ffc107 35%, transparent);
      margin-right: .6rem;
      flex-shrink: 0;
      animation: msgUnreadPulse 1.6s ease-in-out infinite;
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-turn-head::before {
      content: "";
      width: .55rem;
      height: .55rem;
      border-radius: 50%;
      background-color: #ffc107;
      box-shadow: 0 0 0 4px color-mix(in srgb, #ffc107 35%, transparent);
      margin-right: .5rem;
      flex-shrink: 0;
      animation: msgUnreadPulse 1.6s ease-in-out infinite;
    }
    /* Mostrar el botón correcto según el estado del mensaje. Renderizamos
       siempre ambos forms (y el badge "Nuevo") para que tras un toggle por
       AJAX el opuesto quede disponible sin necesidad de refrescar la página. */
    .admin-messages-accordion .message-row.is-unread .js-mark-unread-form,
    .admin-inbox-threads .message-row.is-unread .js-mark-unread-form { display: none; }
    .admin-messages-accordion .message-row:not(.is-unread) .js-mark-read-form,
    .admin-inbox-threads .message-row:not(.is-unread) .js-mark-read-form { display: none; }
    .admin-messages-accordion .message-row.is-unread .js-wa-mark-unread-form { display: none; }
    .admin-messages-accordion .message-row:not(.is-unread) .js-wa-mark-read-form { display: none; }
    .admin-messages-accordion .message-row:not(.is-unread) .js-msg-new-badge,
    .admin-inbox-threads .message-row:not(.is-unread) .js-msg-new-badge { display: none; }
    @keyframes msgUnreadPulse {
      0%, 100% { box-shadow: 0 0 0 4px color-mix(in srgb, #ffc107 35%, transparent); }
      50%      { box-shadow: 0 0 0 7px color-mix(in srgb, #ffc107 10%, transparent); }
    }

    .admin-conv-groups-accordion .conv-group-row {
      border: 1px solid var(--border);
      border-radius: 10px;
      margin-bottom: 0.5rem;
      overflow: hidden;
      background-color: color-mix(in srgb, var(--surface) 92%, transparent);
    }
    .admin-conv-groups-accordion .conv-group-row.is-unread {
      border-left: 4px solid #0d6efd;
      box-shadow: 0 0 0 1px color-mix(in srgb, #0d6efd 25%, transparent);
    }
    .admin-conv-groups-accordion .conv-group-header .accordion-button {
      background-color: color-mix(in srgb, var(--surface) 88%, transparent);
      font-size: 0.9rem;
    }

    .admin-inbox-threads {
      display: flex;
      flex-direction: column;
      gap: 0.65rem;
    }
    .admin-msg-thread {
      border: 1px solid var(--border);
      border-radius: 10px;
      background: color-mix(in srgb, var(--surface) 96%, var(--muted));
      overflow: hidden;
    }
    .admin-msg-thread-summary {
      list-style: none;
      cursor: pointer;
      padding: 0.55rem 0.65rem;
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      background: color-mix(in srgb, var(--surface) 90%, transparent);
    }
    .admin-msg-thread-asunto-block {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 0.35rem 0.5rem;
      line-height: 1.35;
    }
    .admin-msg-thread-asunto-label {
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
      flex-shrink: 0;
    }
    .admin-msg-thread-asunto-text {
      font-weight: 600;
      font-size: 0.95rem;
      color: var(--text);
      min-width: 0;
      flex: 1;
    }
    .admin-msg-thread-summary::-webkit-details-marker { display: none; }
    .admin-msg-thread-summary::marker { content: ""; }
    .admin-msg-thread > summary {
      position: relative;
      padding-right: 1.25rem;
    }
    .admin-msg-thread > summary::after {
      content: "\f078";
      font-family: "Font Awesome 6 Free";
      font-weight: 900;
      position: absolute;
      right: 0.55rem;
      top: 50%;
      transform: translateY(-50%) rotate(0deg);
      font-size: 0.7rem;
      color: var(--muted);
      transition: transform 0.15s ease;
    }
    .admin-msg-thread[open] > summary::after {
      transform: translateY(-50%) rotate(180deg);
    }
    .admin-msg-thread-summary-main {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.35rem 0.55rem;
    }
    .admin-msg-conv-meta {
      font-size: 0.82rem;
      color: var(--muted);
    }
    .admin-msg-thread-snippet {
      display: block;
      font-size: 0.82rem;
      line-height: 1.35;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    .admin-msg-thread-body {
      padding: 0.65rem 0.7rem 0.85rem;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      border-top: 1px solid var(--border);
      background: var(--surface);
    }
    .admin-msg-chat-stream {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }
    .admin-msg-chat-stream .admin-msg-turn + .admin-msg-turn {
      margin-top: 0.35rem;
      padding-top: 0.35rem;
      border-top: none;
    }
    .admin-msg-chat-stream .admin-msg-turn--continuation .admin-msg-turn-toolbar {
      margin-bottom: 0.15rem;
    }
    .admin-msg-chat-stream .admin-msg-turn--continuation .admin-msg-bubble--visitor .admin-msg-bubble-label,
    .admin-msg-chat-stream .admin-msg-turn--continuation .admin-msg-bubble--admin .admin-msg-bubble-label {
      display: none;
    }
    .admin-msg-chat-stream .admin-msg-turn--continuation .message-mark-actions {
      margin-top: 0.05rem;
    }
    .admin-msg-turn {
      border-left: none;
      margin-left: 0;
      padding-left: 0;
      border-radius: 0;
    }
    /* Línea guía en todos los pasos (incl. el 1); mayor especificidad que .admin-msg-turn */
    .admin-msg-chat-stream > .admin-msg-turn {
      padding-left: 0.45rem;
      border-left: 2px solid color-mix(in srgb, var(--accent) 35%, var(--border));
      margin-left: 0.1rem;
    }
    .admin-msg-thread-id {
      display: inline-block;
      margin-top: 0.35rem;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      font-variant-numeric: tabular-nums;
      padding: 0.18rem 0.42rem;
      border-radius: 5px;
      background: color-mix(in srgb, var(--muted) 18%, var(--surface));
      border: 1px solid var(--border);
      color: var(--muted);
    }
    .admin-msg-id-chip {
      display: inline-block;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      font-variant-numeric: tabular-nums;
      padding: 0.18rem 0.42rem;
      border-radius: 5px;
      background: color-mix(in srgb, var(--accent) 16%, var(--surface));
      border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--border));
      color: var(--text);
    }
    .admin-msg-turn-main {
      min-width: 0;
    }
    .admin-msg-turn-inner {
      display: flex;
      flex-direction: column;
      gap: 0.55rem;
      align-items: stretch;
    }
    .admin-side-inbox-card .admin-msg-turn-inner {
      gap: 0.45rem;
      min-width: 0;
    }
    /* Chat: visitante a la izquierda, respuestas admin a la derecha; ancho según panel */
    .admin-msg-bubble {
      border-radius: 10px;
      padding: 0.65rem 0.75rem;
      margin-bottom: 0;
      border: 1px solid var(--border);
      box-sizing: border-box;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .admin-msg-bubble--visitor {
      align-self: flex-start;
      max-width: min(100%, 32rem);
      width: fit-content;
      min-width: 0;
      margin-right: auto;
      background: color-mix(in srgb, var(--accent) 16%, var(--field-bg));
      border-color: color-mix(in srgb, var(--accent) 30%, var(--border));
      border-left-width: 3px;
      border-left-style: solid;
      border-left-color: color-mix(in srgb, var(--accent) 65%, var(--border));
    }
    .admin-msg-bubble--admin {
      align-self: flex-end;
      max-width: min(100%, 32rem);
      width: fit-content;
      min-width: 0;
      margin-left: auto;
      margin-right: 0;
      text-align: start;
      background: color-mix(in srgb, var(--muted) 12%, var(--field-bg));
      border-color: color-mix(in srgb, var(--muted) 28%, var(--border));
      border-right-width: 3px;
      border-right-style: solid;
      border-right-color: color-mix(in srgb, var(--accent) 45%, var(--muted));
    }
    .admin-side-inbox-card .admin-msg-turn-toolbar {
      margin-bottom: 0.25rem;
    }
    .admin-side-inbox-card .admin-msg-id-chip {
      font-size: 0.65rem;
      padding: 0.12rem 0.32rem;
    }
    .admin-side-inbox-card .admin-msg-thread {
      border-radius: 8px;
    }
    .admin-side-inbox-card .admin-msg-thread-summary {
      padding: 0.4rem 0.5rem;
      gap: 0.2rem;
    }
    .admin-side-inbox-card .admin-msg-thread-asunto-label {
      display: none;
    }
    .admin-side-inbox-card .admin-msg-thread-asunto-text {
      font-size: 0.88rem;
      line-height: 1.25;
    }
    .admin-side-inbox-card .admin-msg-thread-id {
      display: none;
    }
    .admin-side-inbox-card .admin-msg-thread-summary-main {
      gap: 0.25rem 0.4rem;
      font-size: 0.78rem;
    }
    .admin-side-inbox-card .admin-msg-thread-summary-main .message-meta-service {
      max-width: 9rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .admin-side-inbox-card .admin-msg-thread-snippet {
      font-size: 0.76rem;
    }
    .admin-side-inbox-card .admin-msg-thread-body {
      padding: 0.45rem 0.5rem 0.6rem;
      gap: 0.55rem;
    }
    .admin-side-inbox-card .admin-conv-groups-accordion .conv-group-header .accordion-button {
      font-size: 0.82rem;
      line-height: 1.25;
      padding-block: 0.45rem;
    }
    .admin-side-inbox-card .admin-inbox-grp-meta {
      font-size: 0.72rem;
      color: var(--muted);
      margin-bottom: 0.35rem;
    }
    .admin-side-inbox-card .message-mark-actions {
      justify-content: flex-end;
      margin-top: 0.15rem;
    }
    .admin-msg-bubble-label {
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      margin-bottom: 0.4rem;
      opacity: 0.95;
    }
    .admin-msg-reply-below {
      margin-top: 0.45rem;
    }
    .admin-msg-reply-thread-end {
      margin-top: 0.75rem;
      padding-top: 0.85rem;
      border-top: 1px solid color-mix(in srgb, var(--border) 85%, transparent);
    }
    .admin-msg-turn-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.35rem;
      margin-bottom: 0.35rem;
    }
    .admin-msg-turn-head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.35rem 0.5rem;
      min-width: 0;
      flex: 1;
    }

    .admin-layout--workspace-manage .admin-agendas-section,
    .admin-layout--workspace-manage .admin-side {
      display: none !important;
    }
    .admin-layout--workspace-manage .admin-main {
      grid-column: 1 / -1;
    }
    @media (min-width: 992px) {
      .admin-layout--workspace-manage {
        grid-template-columns: 1fr;
      }
    }

    .admin-layout--workspace-inbox {
      display: flex !important;
      flex-direction: column;
      gap: 1rem;
    }
    .admin-layout--workspace-inbox .admin-main,
    .admin-layout--workspace-inbox .admin-agendas-section {
      display: none !important;
    }
    .admin-layout--workspace-inbox .admin-side {
      order: 1;
      position: static;
      max-height: none;
      overflow: visible;
      width: 100%;
    }
    .admin-layout--workspace-inbox .admin-side-inbox-card {
      max-width: min(1100px, 100%);
      margin-left: auto;
      margin-right: auto;
    }
    .admin-layout--workspace-agendas .admin-main,
    .admin-layout--workspace-agendas .admin-side {
      display: none !important;
    }
    .admin-layout--workspace-agendas .admin-agendas-section {
      grid-column: 1 / -1;
      grid-row: 1;
      margin-top: 0;
    }
    @media (min-width: 992px) {
      .admin-layout--workspace-agendas {
        grid-template-columns: 1fr;
        grid-template-rows: auto;
      }
    }

    .admin-messages-accordion .message-delete-form,
    .admin-inbox-threads .message-delete-form {
      display: flex;
      align-items: center;
      flex: 0 0 auto;
      flex-shrink: 0;
      margin: 0;
      padding: 0 .55rem;
      background-color: transparent;
    }
    .admin-messages-accordion .btn-message-delete,
    .admin-inbox-threads .btn-message-delete {
      width: 2rem;
      height: 2rem;
      border-radius: 50%;
      border: 1px solid var(--border);
      padding: 0;
      background-color: var(--field-bg);
      color: var(--muted);
      display: grid;
      place-items: center;
      font-size: .8rem;
      line-height: 1;
      cursor: pointer;
      transition: background-color .15s ease, color .15s ease, border-color .15s ease;
    }
    .admin-messages-accordion .btn-message-delete:hover,
    .admin-messages-accordion .btn-message-delete:focus-visible,
    .admin-inbox-threads .btn-message-delete:hover,
    .admin-inbox-threads .btn-message-delete:focus-visible {
      background-color: #dc3545;
      border-color: #dc3545;
      color: #fff;
      outline: none;
    }
    .admin-messages-accordion .btn-message-delete i,
    .admin-inbox-threads .btn-message-delete i {
      display: block;
      line-height: 1;
      width: 1em;
      height: 1em;
      text-align: center;
      font-size: inherit;
    }
    .admin-messages-counter {
      font-variant-numeric: tabular-nums;
      letter-spacing: .02em;
    }
    .admin-side-inbox-accordion .accordion-item {
      background: transparent;
      border-color: var(--border);
    }
    .admin-side-inbox-accordion .accordion-button {
      background-color: var(--surface);
      color: var(--text);
      font-weight: 600;
      font-size: 1.05rem;
      box-shadow: none;
    }
    .admin-side-inbox-accordion .accordion-button:not(.collapsed) {
      background-color: color-mix(in srgb, var(--accent) 14%, var(--surface));
      color: var(--text);
    }
    .admin-side-inbox-accordion .accordion-button:focus {
      box-shadow: none;
      border-color: var(--border);
    }
    .admin-side-inbox-accordion .accordion-body {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-messages-accordion .message-meta-date,
    .admin-inbox-threads .message-meta-date {
      font-variant-numeric: tabular-nums;
      font-size: .9rem;
    }
    .admin-messages-accordion .message-meta-service,
    .admin-inbox-threads .message-meta-service {
      font-size: .9rem;
    }
    .admin-messages-accordion .message-meta-email {
      font-size: .85rem;
    }
    .admin-messages-accordion .accordion-body {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-messages-accordion .message-body-text,
    .admin-inbox-threads .message-body-text {
      white-space: pre-wrap;
      background-color: var(--field-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: .8rem;
      max-height: 320px;
      overflow: auto;
    }
    .admin-messages-accordion .message-replies-sent,
    .admin-inbox-threads .message-replies-sent {
      border-left: 3px solid color-mix(in srgb, var(--accent) 55%, var(--border));
      padding-left: .75rem;
      margin-bottom: 1rem;
    }
    .admin-messages-accordion .message-reply-item,
    .admin-inbox-threads .message-reply-item {
      margin-bottom: .75rem;
    }
    .admin-messages-accordion .message-reply-item:last-child,
    .admin-inbox-threads .message-reply-item:last-child {
      margin-bottom: 0;
    }
    .admin-messages-accordion .message-reply-form textarea,
    .admin-inbox-threads .message-reply-form textarea {
      min-height: 5rem;
    }
    .gallery-meta-stack {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      flex: 1;
      min-width: 0;
    }
    .gallery-desc-input {
      min-height: 4.5rem;
      resize: vertical;
    }
    .expert-service-checks {
      display: grid;
      gap: 0.35rem;
      max-height: 240px;
      overflow: auto;
    }
  </style>
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
                <?php if (count($portalClients) === 0): ?>
                  <p class="small text-light-emphasis mb-0">Aún no hay cuentas. Comparte la URL del acordeón «Rutas» o el enlace «Clientes» del menú de la web.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-hover table-borderless align-middle mb-0 admin-portal-clients-table">
                      <thead>
                        <tr class="text-secondary small">
                          <th scope="col" class="portal-col-email">
                            <span class="portal-th-full">Correo</span>
                            <span class="portal-th-short" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
                            <span class="visually-hidden">Correo y nombre</span>
                          </th>
                          <th scope="col" class="portal-col-name">
                            <span class="portal-th-full">Nombre</span>
                          </th>
                          <th scope="col" class="portal-col-account" title="Estado de la cuenta">
                            <span class="portal-th-full">Cuenta</span>
                            <span class="portal-th-short" aria-hidden="true"><i class="fa-solid fa-user-check"></i></span>
                            <span class="visually-hidden">Cuenta</span>
                          </th>
                          <th scope="col" class="portal-col-smtp" title="Envío por correo SMTP">
                            <span class="portal-th-full">Correo SMTP</span>
                            <span class="portal-th-short" aria-hidden="true"><i class="fa-solid fa-paper-plane"></i></span>
                            <span class="visually-hidden">Correo SMTP</span>
                          </th>
                          <th scope="col" class="text-end portal-col-actions">
                            <span class="portal-th-full">Acciones</span>
                            <span class="portal-th-short" aria-hidden="true"><i class="fa-solid fa-ellipsis"></i></span>
                            <span class="visually-hidden">Acciones</span>
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($portalClients as $pc): ?>
                          <?php
                            $pid = (int)($pc["id"] ?? 0);
                            $active = (int)($pc["is_active"] ?? 0) === 1;
                            $notifyOut = (int)($pc["email_notify_outbound"] ?? 1) === 1;
                          ?>
                          <tr>
                            <td class="portal-col-email font-monospace small">
                              <div class="portal-client-email-line"><?= h((string)($pc["email"] ?? "")) ?></div>
                              <?php if (trim((string)($pc["display_name"] ?? "")) !== ""): ?>
                                <div class="portal-client-name-mobile small text-secondary"><?= h((string)($pc["display_name"] ?? "")) ?></div>
                              <?php endif; ?>
                            </td>
                            <td class="portal-col-name small"><?= h((string)($pc["display_name"] ?? "")) ?></td>
                            <td class="portal-col-account">
                              <form method="post" class="d-inline m-0 js-admin-ajax-form js-portal-client-toggle" data-ajax-scope="client-toggle" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="client_toggle_active">
                                <input type="hidden" name="client_id" value="<?= $pid ?>">
                                <button
                                  type="submit"
                                  class="btn btn-sm rounded-pill portal-client-pill portal-client-toggle-btn border-0 d-inline-flex align-items-center text-nowrap <?= $active ? "text-bg-success" : "text-bg-secondary" ?>"
                                  title="<?= $active ? "Cuenta activa: puede iniciar sesión. Pulsa para desactivar (no podrá entrar)." : "Cuenta inactiva. Pulsa para reactivar el acceso." ?>"
                                  aria-label="<?= $active ? "Cuenta activa, pulsar para desactivar" : "Cuenta inactiva, pulsar para activar" ?>"
                                  onclick="event.stopPropagation();"
                                >
                                  <?php if ($active): ?>
                                    <i class="fa-solid fa-user-check" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Activo</span>
                                  <?php else: ?>
                                    <i class="fa-solid fa-user-slash" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Inactivo</span>
                                  <?php endif; ?>
                                </button>
                              </form>
                            </td>
                            <td class="portal-col-smtp">
                              <form method="post" class="d-inline m-0 js-admin-ajax-form js-portal-client-toggle" data-ajax-scope="client-toggle" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="client_toggle_email_notify">
                                <input type="hidden" name="client_id" value="<?= $pid ?>">
                                <button
                                  type="submit"
                                  class="btn btn-sm rounded-pill portal-client-pill portal-client-toggle-btn border-0 d-inline-flex align-items-center text-nowrap <?= $notifyOut ? "text-bg-info" : "text-bg-secondary border border-secondary" ?>"
                                  title="<?= $notifyOut ? "Envío por correo activo (SMTP al responder). Pulsa para solo bandeja web." : "Solo bandeja web. Pulsa para intentar envío SMTP al responder desde Mensajes." ?>"
                                  aria-label="<?= $notifyOut ? "Correo SMTP activo, pulsar para desactivar" : "Solo web, pulsar para activar envío SMTP" ?>"
                                  onclick="event.stopPropagation();"
                                >
                                  <?php if ($notifyOut): ?>
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Correo</span>
                                  <?php else: ?>
                                    <i class="fa-solid fa-display" aria-hidden="true"></i><span class="ms-1 portal-pill-label">Solo web</span>
                                  <?php endif; ?>
                                </button>
                              </form>
                            </td>
                            <td class="text-end portal-col-actions">
                              <div class="d-inline-flex flex-nowrap align-items-center justify-content-end admin-portal-client-actions">
                                <form method="post" class="m-0 js-admin-ajax-form js-portal-client-delete" data-ajax-scope="client-delete" onclick="event.stopPropagation();">
                                  <input type="hidden" name="action" value="client_delete">
                                  <input type="hidden" name="client_id" value="<?= $pid ?>">
                                  <button
                                    type="submit"
                                    class="btn btn-outline-danger btn-sm"
                                    title="Eliminar cliente de forma permanente"
                                    aria-label="Eliminar cliente"
                                    onclick="event.stopPropagation();"
                                  >
                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden"> Eliminar</span>
                                  </button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
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
                    <img src="<?= h($service["image_path"]) ?>" alt="Imagen servicio" style="width:120px;height:80px;object-fit:cover;border-radius:10px;border:1px solid var(--border);">
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
                              <span class="text-truncate flex-grow-1" style="min-width: 0;"><strong><?= h((string)($grp["head_title"] ?? "")) ?></strong></span>
                              <?php if (($grp["head_sub"] ?? "") !== ""): ?>
                                <span class="text-light-emphasis small text-truncate d-none d-md-inline" style="max-width: 8rem;"><?= h((string)$grp["head_sub"]) ?></span>
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
                                                    <div class="mt-1 message-body-text mb-0" style="max-height: 12rem;"><?= nl2br(h((string)($repRow["body"] ?? ""))) ?></div>
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

        // Acumula selecciones de varios clics creando un nuevo input vacío.
        const nextInput = inputEl.cloneNode();
        nextInput.value = "";
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
