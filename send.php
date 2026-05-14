<?php
declare(strict_types=1);

// POST del formulario de contacto → send.php (misma carpeta que index.php). Patrones de URL: ver app_urls.php.

/**
 * Traza opcional en `contact_send_trace.log` (errores y envíos).
 */
function contact_send_trace(string $message): void
{
    $path = __DIR__ . "/contact_send_trace.log";
    $line = date("c") . " " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/** Una sola línea JSON con el formulario (sin saltos de línea en el archivo de log). */
function contact_clamp_field(string $value, int $maxLen): string
{
    $value = trim($value);
    if ($maxLen <= 0) {
        return "";
    }
    if (function_exists("mb_strlen") && mb_strlen($value, "UTF-8") > $maxLen) {
        return mb_substr($value, 0, $maxLen, "UTF-8");
    }
    if (strlen($value) > $maxLen) {
        return substr($value, 0, $maxLen);
    }

    return $value;
}

function contact_form_log_json(
    string $sentTo,
    string $nombre,
    string $email,
    string $servicio,
    string $asunto,
    string $mensaje
): string {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined("JSON_INVALID_UTF8_SUBSTITUTE")) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $data = [
        "event" => "formulario",
        "destino" => $sentTo,
        "nombre" => $nombre,
        "email" => $email,
        "servicio" => $servicio,
        "asunto" => $asunto,
        "mensaje" => $mensaje,
    ];
    $json = json_encode($data, $flags);

    return $json !== false ? $json : '{"event":"formulario","error":"json_encode_failed"}';
}

function contact_log_form_submission(
    array $mailConfig,
    string $nombre,
    string $email,
    string $servicio,
    string $asunto,
    string $mensaje,
    string $sentTo
): void {
    $line = contact_form_log_json($sentTo, $nombre, $email, $servicio, $asunto, $mensaje);
    contact_send_trace($line);
    if (!empty($mailConfig["debug"]) && !empty($mailConfig["debug_log"])) {
        smtp_debug_log($mailConfig, "Formulario " . $line);
    }
}

/** Tras POST en send.php: `client_contact` en área cliente o `status` en #contacto. */
function contact_send_redirect_to_landing(string $returnAnchor, string $outcome, string $reason = ""): void
{
    $fragment = $returnAnchor === "area-cliente" ? "area-cliente" : "contacto";
    if ($returnAnchor === "area-cliente") {
        if ($outcome === "ok") {
            header("Location: index.php?client_contact=ok#" . $fragment);
        } elseif ($outcome === "saved") {
            header("Location: index.php?client_contact=saved#" . $fragment);
        } else {
            $q = $reason !== "" ? ("client_contact=error&reason=" . urlencode($reason)) : "client_contact=error";
            header("Location: index.php?" . $q . "#" . $fragment);
        }
    } elseif ($outcome === "ok") {
        header("Location: index.php?status=ok#contacto");
    } elseif ($outcome === "saved") {
        header("Location: index.php?status=saved#contacto");
    } else {
        $q = $reason !== "" ? ("status=error&reason=" . urlencode($reason)) : "status=error";
        header("Location: index.php?" . $q . "#contacto");
    }
    exit;
}

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)($e["type"] ?? 0), $fatal, true)) {
        return;
    }
    contact_send_trace("FATAL: " . ($e["message"] ?? "") . " en " . ($e["file"] ?? "") . ":" . (string)($e["line"] ?? 0));
});

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {
    contact_send_trace("POST recibido en send.php");
}

require __DIR__ . "/db.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/smtp_mail.php";
require_once __DIR__ . "/app_urls.php";

client_session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    contact_send_redirect_to_landing("contacto", "error");
}

$returnAnchor = trim((string)($_POST["return_anchor"] ?? ""));
if ($returnAnchor !== "area-cliente") {
    $returnAnchor = "contacto";
}
if ($returnAnchor === "area-cliente" && !app_feature_enabled("client_inbox")) {
    contact_send_trace("send.php rechazado: client_inbox desactivado (app_config features)");
    contact_send_redirect_to_landing($returnAnchor, "error", "client_inbox_disabled");
}
$inReplyToPost = (int)($_POST["in_reply_to"] ?? 0);

$nombre = trim($_POST["nombre"] ?? "");
$email = trim($_POST["email"] ?? "");
$servicio = trim($_POST["servicio"] ?? "");
$mensaje = trim($_POST["mensaje"] ?? "");

$followThreadSubject = "";

$sessionClientId = 0;
$sessionEmailNorm = "";
if (client_portal_resume_session($conn)) {
    $sessionClientId = (int)($_SESSION["client_id"] ?? 0);
    $sessionEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
}

$submittingClientId = null;
if ($sessionClientId > 0 && $sessionEmailNorm !== "" && strtolower(trim($email)) === $sessionEmailNorm) {
    $submittingClientId = $sessionClientId;
}

$inReplyToId = 0;
if ($inReplyToPost > 0) {
    if ($sessionClientId <= 0 || $sessionEmailNorm === "") {
        contact_send_trace("seguimiento rechazado: sin sesión de cliente");
        contact_send_redirect_to_landing($returnAnchor, "error", "sesion_seguimiento");
    }
    $pst = $conn->prepare("SELECT id, client_id, email, servicio, subject FROM contact_messages WHERE id = ? LIMIT 1");
    if ($pst === false) {
        contact_send_redirect_to_landing($returnAnchor, "error", "seguimiento_invalido");
    }
    $pst->bind_param("i", $inReplyToPost);
    $pst->execute();
    $pres = $pst->get_result();
    $pst->close();
    if (!$pres || $pres->num_rows !== 1) {
        contact_send_redirect_to_landing($returnAnchor, "error", "seguimiento_invalido");
    }
    $parentRow = $pres->fetch_assoc();
    $pClientId = (int)($parentRow["client_id"] ?? 0);
    $pEmailNorm = strtolower(trim((string)($parentRow["email"] ?? "")));
    $ownsParent = ($pClientId > 0 && $pClientId === $sessionClientId)
        || ($pClientId === 0 && $pEmailNorm !== "" && $pEmailNorm === $sessionEmailNorm);
    if (!$ownsParent) {
        contact_send_trace("seguimiento rechazado: mensaje padre no asociado al cliente");
        contact_send_redirect_to_landing($returnAnchor, "error", "seguimiento_invalido");
    }
    $parentServicio = trim((string)($parentRow["servicio"] ?? ""));
    if ($parentServicio === "") {
        contact_send_redirect_to_landing($returnAnchor, "error", "seguimiento_invalido");
    }
    $inReplyToId = $inReplyToPost;
    $email = trim((string)($_SESSION["client_email"] ?? ""));
    $servicio = $parentServicio;
    $submittingClientId = $sessionClientId;
    $parentSubject = trim((string)($parentRow["subject"] ?? ""));
    $followThreadSubject = $parentSubject !== ""
        ? $parentSubject
        : ($parentServicio !== "" ? ("Consulta: " . $parentServicio) : "Seguimiento");
    $followThreadSubject = contact_clamp_field($followThreadSubject, 200);
}

$storedSubject = contact_clamp_field((string)($_POST["asunto"] ?? ""), 200);
if ($inReplyToId > 0) {
    $storedSubject = $followThreadSubject;
}

$failedFields = [];
if ($nombre === "") {
    $failedFields[] = "nombre";
}
if ($email === "") {
    $failedFields[] = "email_vacio";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $failedFields[] = "email_invalido";
}
if ($servicio === "") {
    $failedFields[] = "servicio";
}
if ($inReplyToId === 0 && $storedSubject === "") {
    $failedFields[] = "asunto";
}
if ($mensaje === "") {
    $failedFields[] = "mensaje";
}

if (count($failedFields) > 0) {
    $postedKeys = array_keys($_POST);
    contact_send_trace(
        "validación del formulario rechazada [campos_fallidos=" . implode(",", $failedFields) . "]"
        . " [keys_post=" . implode(",", $postedKeys) . "]"
    );
    $reason = $failedFields[0];
    contact_send_redirect_to_landing($returnAnchor, "error", $reason);
}

$to = "admin@admin.com";
$settingsResult = $conn->query("SELECT contact_email, person_name FROM site_settings WHERE id = 1 LIMIT 1");
$personName = "";
if ($settingsResult && $settingsResult->num_rows === 1) {
    $row = $settingsResult->fetch_assoc();
    if (!empty($row["contact_email"])) {
        $to = $row["contact_email"];
    }
    if (!empty($row["person_name"])) {
        $personName = trim((string)$row["person_name"]);
    }
}

$mailConfigPath = __DIR__ . "/mail_config.php";
$mailConfig = is_readable($mailConfigPath) ? require $mailConfigPath : [];
$mailConfig = is_array($mailConfig) ? $mailConfig : [];

contact_log_form_submission($mailConfig, $nombre, $email, $servicio, $storedSubject, $mensaje, $to);

$mailNotifySubject = "Nuevo contacto desde tu web";
$body = "Nombre: $nombre\nCorreo: $email\nServicio: $servicio\nAsunto: $storedSubject\n\nMensaje:\n$mensaje\n";
if ($inReplyToId > 0) {
    $mailNotifySubject = "Seguimiento de cliente (ref. mensaje n.º " . $inReplyToId . ")";
    if ($storedSubject !== "") {
        $mailNotifySubject = "Seguimiento: " . $storedSubject . " (ref. n.º " . $inReplyToId . ")";
    }
    $body = "Seguimiento en la conversación iniciada con el mensaje n.º " . $inReplyToId . ".\n\n"
        . "Nombre: $nombre\nCorreo: $email\nServicio: $servicio\nAsunto (hilo): $storedSubject\n\nMensaje:\n$mensaje\n";
} elseif ($storedSubject !== "") {
    $mailNotifySubject = "Nuevo contacto: " . $storedSubject;
}
$body .= "\n" . app_mail_plain_text_links_footer("admin_notify");

$useSmtp = !empty($mailConfig["use_smtp"]);
$smtpFromResolved = mail_config_resolve_smtp_from($mailConfig);
$smtpReady = $useSmtp
    && !empty($mailConfig["host"])
    && !empty($mailConfig["username"])
    && !empty($mailConfig["password"])
    && $smtpFromResolved !== "";

$fromEmailFallback = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $to;
$fromForPhpMail = $smtpFromResolved !== "" ? $smtpFromResolved : $fromEmailFallback;

// Nombre visible del remitente: from_name en mail_config → "Nombre persona" del admin.
// No usamos "Marca" (brand_name): suele ser un nombre corto tipo carpeta del proyecto (Pagina1).
$fromDisplayName = trim((string)($mailConfig["from_name"] ?? ""));
if ($fromDisplayName === "") {
    $fromDisplayName = $personName;
}
if ($fromDisplayName === "") {
    $localPart = "";
    if (str_contains($fromForPhpMail, "@")) {
        $localPart = trim(explode("@", $fromForPhpMail, 2)[0] ?? "");
    }
    $fromDisplayName = $localPart !== "" ? $localPart : "Formulario web";
}
$fromHeaderLine = smtp_format_from_header($fromDisplayName, $fromForPhpMail);

if (!empty($mailConfig["debug"]) && !empty($mailConfig["debug_log"])) {
    smtp_debug_log($mailConfig, "From enviado por PHP: " . $fromHeaderLine . " (fromDisplayName=" . $fromDisplayName . ")");
}

$headers = "From: " . $fromHeaderLine . "\r\n";
$headers .= "Reply-To: " . $email . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "MIME-Version: 1.0\r\n";

// is_read = 0. Sesión de cliente + correo coherente → client_id; seguimiento → in_reply_to.
if ($inReplyToId > 0) {
    $stmt = $conn->prepare(
        "INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read, client_id, in_reply_to) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)"
    );
    if ($stmt === false) {
        contact_send_trace("error prepare INSERT seguimiento: " . $conn->error);
        contact_send_redirect_to_landing($returnAnchor, "error");
    }
    $stmt->bind_param("ssssssii", $nombre, $email, $servicio, $storedSubject, $mensaje, $to, $submittingClientId, $inReplyToId);
} elseif ($submittingClientId === null) {
    $stmt = $conn->prepare("INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)");
    if ($stmt === false) {
        contact_send_trace("error prepare INSERT: " . $conn->error);
        contact_send_redirect_to_landing($returnAnchor, "error");
    }
    $stmt->bind_param("ssssss", $nombre, $email, $servicio, $storedSubject, $mensaje, $to);
} else {
    $stmt = $conn->prepare("INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read, client_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
    if ($stmt === false) {
        contact_send_trace("error prepare INSERT: " . $conn->error);
        contact_send_redirect_to_landing($returnAnchor, "error");
    }
    $stmt->bind_param("ssssssi", $nombre, $email, $servicio, $storedSubject, $mensaje, $to, $submittingClientId);
}
if (!$stmt->execute()) {
    contact_send_trace("error execute INSERT: " . $stmt->error);
    contact_send_redirect_to_landing($returnAnchor, "error");
}

$mailSent = false;

if ($smtpReady) {
    $smtpCfg = $mailConfig;
    $smtpCfg["from_email"] = $smtpFromResolved;
    $smtpCfg["from_name"] = $fromDisplayName;
    $mailSent = send_mail_smtp($smtpCfg, $to, $mailNotifySubject, $body, $email);
    if (!$mailSent) {
        smtp_trace_public("send.php: SMTP devolvió false (To=" . $to . " Reply-To=" . $email . ")");
    }
}

if (!$mailSent) {
    $mailSent = @mail($to, $mailNotifySubject, $body, $headers);
}

contact_send_trace(
    "resultado envío correo to=" . $to
    . " smtpReady=" . ($smtpReady ? "1" : "0")
    . " " . ($mailSent ? "ok (SMTP o mail())" : "falló (mensaje ya guardado en BD)")
);

if ($mailSent) {
    contact_send_redirect_to_landing($returnAnchor, "ok");
}

contact_send_redirect_to_landing($returnAnchor, "saved");
