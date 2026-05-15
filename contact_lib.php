<?php
declare(strict_types=1);

/**
 * Dominio del formulario de contacto: validación, persistencia y notificación por correo.
 * Sin redirecciones ni cabeceras HTTP (eso va en send.php o api/).
 */

function contact_send_trace(string $message): void
{
    $path = __DIR__ . "/contact_send_trace.log";
    $line = date("c") . " " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

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

/**
 * @return array{to: string, person_name: string}
 */
function contact_site_recipient(mysqli $conn): array
{
    $to = "admin@admin.com";
    $personName = "";
    $settingsResult = $conn->query("SELECT contact_email, person_name FROM site_settings WHERE id = 1 LIMIT 1");
    if ($settingsResult && $settingsResult->num_rows === 1) {
        $row = $settingsResult->fetch_assoc();
        if (!empty($row["contact_email"])) {
            $to = (string)$row["contact_email"];
        }
        if (!empty($row["person_name"])) {
            $personName = trim((string)$row["person_name"]);
        }
    }

    return ["to" => $to, "person_name" => $personName];
}

/** @return array<string, mixed> */
function contact_load_mail_config(): array
{
    $mailConfigPath = __DIR__ . "/mail_config.php";
    $mailConfig = is_readable($mailConfigPath) ? require $mailConfigPath : [];

    return is_array($mailConfig) ? $mailConfig : [];
}

/**
 * @param array{nombre?: string, email?: string, servicio?: string, mensaje?: string, asunto?: string, in_reply_to?: int|string} $input
 * @return array{ok: true, fields: array<string, string|int|null>} | array{ok: false, error: string, fields: list<string>}
 */
function contact_validate_submission(array $input, int $inReplyToId, string $storedSubject): array
{
    $nombre = trim((string)($input["nombre"] ?? ""));
    $email = trim((string)($input["email"] ?? ""));
    $servicio = trim((string)($input["servicio"] ?? ""));
    $mensaje = trim((string)($input["mensaje"] ?? ""));

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
        return [
            "ok" => false,
            "error" => $failedFields[0],
            "fields" => $failedFields,
        ];
    }

    return [
        "ok" => true,
        "fields" => [
            "nombre" => $nombre,
            "email" => $email,
            "servicio" => $servicio,
            "mensaje" => $mensaje,
            "stored_subject" => $storedSubject,
            "in_reply_to_id" => $inReplyToId > 0 ? $inReplyToId : null,
        ],
    ];
}

/**
 * Resuelve seguimiento en hilo (in_reply_to) para cliente autenticado.
 *
 * @return array{ok: true, in_reply_to_id: int, email: string, servicio: string, stored_subject: string, submitting_client_id: int}
 *     | array{ok: false, error: string}
 */
function contact_resolve_follow_up(
    mysqli $conn,
    int $inReplyToPost,
    int $sessionClientId,
    string $sessionEmailNorm
): array {
    if ($inReplyToPost <= 0) {
        return ["ok" => false, "error" => "seguimiento_invalido"];
    }
    if ($sessionClientId <= 0 || $sessionEmailNorm === "") {
        contact_send_trace("seguimiento rechazado: sin sesión de cliente");
        return ["ok" => false, "error" => "sesion_seguimiento"];
    }

    $pst = $conn->prepare("SELECT id, client_id, email, servicio, subject FROM contact_messages WHERE id = ? LIMIT 1");
    if ($pst === false) {
        return ["ok" => false, "error" => "seguimiento_invalido"];
    }
    $pst->bind_param("i", $inReplyToPost);
    $pst->execute();
    $pres = $pst->get_result();
    $pst->close();
    if (!$pres || $pres->num_rows !== 1) {
        return ["ok" => false, "error" => "seguimiento_invalido"];
    }

    $parentRow = $pres->fetch_assoc();
    $pClientId = (int)($parentRow["client_id"] ?? 0);
    $pEmailNorm = strtolower(trim((string)($parentRow["email"] ?? "")));
    $ownsParent = ($pClientId > 0 && $pClientId === $sessionClientId)
        || ($pClientId === 0 && $pEmailNorm !== "" && $pEmailNorm === $sessionEmailNorm);
    if (!$ownsParent) {
        contact_send_trace("seguimiento rechazado: mensaje padre no asociado al cliente");
        return ["ok" => false, "error" => "seguimiento_invalido"];
    }

    $parentServicio = trim((string)($parentRow["servicio"] ?? ""));
    if ($parentServicio === "") {
        return ["ok" => false, "error" => "seguimiento_invalido"];
    }

    $parentSubject = trim((string)($parentRow["subject"] ?? ""));
    $followThreadSubject = $parentSubject !== ""
        ? $parentSubject
        : ($parentServicio !== "" ? ("Consulta: " . $parentServicio) : "Seguimiento");
    $followThreadSubject = contact_clamp_field($followThreadSubject, 200);

    return [
        "ok" => true,
        "in_reply_to_id" => $inReplyToPost,
        "email" => trim((string)($_SESSION["client_email"] ?? "")),
        "servicio" => $parentServicio,
        "stored_subject" => $followThreadSubject,
        "submitting_client_id" => $sessionClientId,
    ];
}

/**
 * @return array{ok: true, message_id: int} | array{ok: false, error: string}
 */
function contact_insert_message(
    mysqli $conn,
    string $nombre,
    string $email,
    string $servicio,
    string $storedSubject,
    string $mensaje,
    string $sentTo,
    ?int $submittingClientId,
    int $inReplyToId
): array {
    if ($inReplyToId > 0) {
        $clientId = $submittingClientId ?? 0;
        $stmt = $conn->prepare(
            "INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read, client_id, in_reply_to) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)"
        );
        if ($stmt === false) {
            contact_send_trace("error prepare INSERT seguimiento: " . $conn->error);
            return ["ok" => false, "error" => "db_insert"];
        }
        $stmt->bind_param("ssssssii", $nombre, $email, $servicio, $storedSubject, $mensaje, $sentTo, $clientId, $inReplyToId);
    } elseif ($submittingClientId === null) {
        $stmt = $conn->prepare(
            "INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)"
        );
        if ($stmt === false) {
            contact_send_trace("error prepare INSERT: " . $conn->error);
            return ["ok" => false, "error" => "db_insert"];
        }
        $stmt->bind_param("ssssss", $nombre, $email, $servicio, $storedSubject, $mensaje, $sentTo);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read, client_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
        );
        if ($stmt === false) {
            contact_send_trace("error prepare INSERT: " . $conn->error);
            return ["ok" => false, "error" => "db_insert"];
        }
        $stmt->bind_param("ssssssi", $nombre, $email, $servicio, $storedSubject, $mensaje, $sentTo, $submittingClientId);
    }

    if (!$stmt->execute()) {
        contact_send_trace("error execute INSERT: " . $stmt->error);
        $stmt->close();
        return ["ok" => false, "error" => "db_insert"];
    }

    $messageId = (int)$conn->insert_id;
    $stmt->close();

    return ["ok" => true, "message_id" => $messageId];
}

/**
 * @return array{ok: true, mail_sent: bool} | array{ok: false, error: string}
 */
function contact_send_admin_notification(
    array $mailConfig,
    string $personName,
    string $to,
    string $nombre,
    string $email,
    string $servicio,
    string $storedSubject,
    string $mensaje,
    int $inReplyToId
): array {
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

    $mailSent = false;
    if ($smtpReady) {
        $smtpCfg = $mailConfig;
        $smtpCfg["from_email"] = $smtpFromResolved;
        $smtpCfg["from_name"] = $fromDisplayName;
        $mailSent = send_mail_smtp($smtpCfg, $to, $mailNotifySubject, $body, $email);
        if (!$mailSent) {
            smtp_trace_public("contact_lib: SMTP devolvió false (To=" . $to . " Reply-To=" . $email . ")");
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

    return ["ok" => true, "mail_sent" => $mailSent];
}
