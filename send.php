<?php
declare(strict_types=1);

// POST del formulario de contacto → send.php (misma carpeta que index.php). Patrones de URL: ver app_urls.php.

/**
 * Traza mínima en disco (no depende de mail_config["debug"]).
 * Si este archivo no cambia al enviar el formulario, el POST no está llegando a este send.php
 * (URL distinta, otro virtual host, error antes de ejecutar PHP, etc.).
 */
function contact_send_trace(string $message): void
{
    $path = __DIR__ . "/contact_send_trace.log";
    $line = date("c") . " " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/** Una sola línea JSON con el formulario (sin saltos de línea en el archivo de log). */
function contact_form_log_json(
    string $sentTo,
    string $nombre,
    string $email,
    string $servicio,
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
    string $mensaje,
    string $sentTo
): void {
    $line = contact_form_log_json($sentTo, $nombre, $email, $servicio, $mensaje);
    contact_send_trace($line);
    if (!empty($mailConfig["debug"]) && !empty($mailConfig["debug_log"])) {
        smtp_debug_log($mailConfig, "Formulario " . $line);
    }
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
require_once __DIR__ . "/smtp_mail.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php?status=error#contacto");
    exit;
}

$nombre = trim($_POST["nombre"] ?? "");
$email = trim($_POST["email"] ?? "");
$servicio = trim($_POST["servicio"] ?? "");
$mensaje = trim($_POST["mensaje"] ?? "");

$failedFields = [];
if ($nombre === "") $failedFields[] = "nombre";
if ($email === "") {
    $failedFields[] = "email_vacio";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $failedFields[] = "email_invalido";
}
if ($servicio === "") $failedFields[] = "servicio";
if ($mensaje === "") $failedFields[] = "mensaje";

if (count($failedFields) > 0) {
    $postedKeys = array_keys($_POST);
    contact_send_trace(
        "validación del formulario rechazada [campos_fallidos=" . implode(",", $failedFields) . "]"
        . " [keys_post=" . implode(",", $postedKeys) . "]"
        . " [longs n=" . strlen($nombre) . " e=" . strlen($email) . " s=" . strlen($servicio) . " m=" . strlen($mensaje) . "]"
    );
    $reason = $failedFields[0];
    header("Location: index.php?status=error&reason=" . urlencode($reason) . "#contacto");
    exit;
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

contact_log_form_submission($mailConfig, $nombre, $email, $servicio, $mensaje, $to);

$subject = "Nuevo contacto desde tu web";
$body = "Nombre: $nombre\nCorreo: $email\nServicio: $servicio\n\nMensaje:\n$mensaje\n";

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

// Importante: se fuerza is_read = 0 explícitamente para que el mensaje
// quede siempre como "sin leer" al insertarse, sin depender del DEFAULT
// de la columna en la BD (que podría no estar bien aplicado).
$stmt = $conn->prepare("INSERT INTO contact_messages (nombre, email, servicio, mensaje, sent_to, is_read) VALUES (?, ?, ?, ?, ?, 0)");
if ($stmt === false) {
    contact_send_trace("error prepare INSERT: " . $conn->error);
    header("Location: index.php?status=error#contacto");
    exit;
}
$stmt->bind_param("sssss", $nombre, $email, $servicio, $mensaje, $to);
if (!$stmt->execute()) {
    contact_send_trace("error execute INSERT: " . $stmt->error);
    header("Location: index.php?status=error#contacto");
    exit;
}

$mailSent = false;

if ($smtpReady) {
    $smtpCfg = $mailConfig;
    $smtpCfg["from_email"] = $smtpFromResolved;
    $smtpCfg["from_name"] = $fromDisplayName;
    $mailSent = send_mail_smtp($smtpCfg, $to, $subject, $body, $email);
}

if (!$mailSent) {
    $mailSent = @mail($to, $subject, $body, $headers);
}

contact_send_trace("resultado envío correo: " . ($mailSent ? "ok (SMTP o mail())" : "falló"));

if ($mailSent) {
    header("Location: index.php?status=ok#contacto");
    exit;
}

header("Location: index.php?status=saved#contacto");
exit;
