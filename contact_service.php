<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/smtp_mail.php";
require_once __DIR__ . "/contact_lib.php";

/**
 * Caso de uso: enviar mensaje de contacto (formulario público, área cliente o API).
 *
 * @param array{
 *   nombre?: string,
 *   email?: string,
 *   servicio?: string,
 *   mensaje?: string,
 *   asunto?: string,
 *   in_reply_to?: int|string,
 *   return_anchor?: string
 * } $input
 * @param array{
 *   session_client_id?: int,
 *   session_email_norm?: string,
 *   require_client_inbox_for_area?: bool
 * } $context
 * @return array{
 *   ok: true,
 *   outcome: 'ok'|'saved',
 *   message_id: int,
 *   mail_sent: bool
 * } | array{
 *   ok: false,
 *   error: string,
 *   fields?: list<string>
 * }
 */
function contact_service_submit(mysqli $conn, array $input, array $context = []): array
{
    $returnAnchor = trim((string)($input["return_anchor"] ?? ""));
    if ($returnAnchor !== "area-cliente") {
        $returnAnchor = "contacto";
    }

    $requireClientInbox = (bool)($context["require_client_inbox_for_area"] ?? true);
    if ($returnAnchor === "area-cliente" && $requireClientInbox && !app_feature_enabled("client_inbox")) {
        contact_send_trace("contact_service rechazado: client_inbox desactivado");
        return ["ok" => false, "error" => "client_inbox_disabled"];
    }

    $sessionClientId = (int)($context["session_client_id"] ?? 0);
    $sessionEmailNorm = strtolower(trim((string)($context["session_email_norm"] ?? "")));

    $inReplyToPost = (int)($input["in_reply_to"] ?? 0);
    $nombre = trim((string)($input["nombre"] ?? ""));
    $email = trim((string)($input["email"] ?? ""));
    $servicio = trim((string)($input["servicio"] ?? ""));
    $mensaje = trim((string)($input["mensaje"] ?? ""));

    $submittingClientId = null;
    if ($sessionClientId > 0 && $sessionEmailNorm !== "" && strtolower(trim($email)) === $sessionEmailNorm) {
        $submittingClientId = $sessionClientId;
    }

    $inReplyToId = 0;
    if ($inReplyToPost > 0) {
        $follow = contact_resolve_follow_up($conn, $inReplyToPost, $sessionClientId, $sessionEmailNorm);
        if (!$follow["ok"]) {
            return ["ok" => false, "error" => $follow["error"]];
        }
        $inReplyToId = $follow["in_reply_to_id"];
        $email = $follow["email"];
        $servicio = $follow["servicio"];
        $submittingClientId = $follow["submitting_client_id"];
        $storedSubject = $follow["stored_subject"];
    } else {
        $storedSubject = contact_clamp_field((string)($input["asunto"] ?? ""), 200);
    }

    $validation = contact_validate_submission(
        [
            "nombre" => $nombre,
            "email" => $email,
            "servicio" => $servicio,
            "mensaje" => $mensaje,
        ],
        $inReplyToId,
        $storedSubject
    );
    if (!$validation["ok"]) {
        $failedFields = $validation["fields"];
        contact_send_trace(
            "validación del formulario rechazada [campos_fallidos=" . implode(",", $failedFields) . "]"
        );
        return [
            "ok" => false,
            "error" => $validation["error"],
            "fields" => $failedFields,
        ];
    }

    $nombre = (string)$validation["fields"]["nombre"];
    $email = (string)$validation["fields"]["email"];
    $servicio = (string)$validation["fields"]["servicio"];
    $mensaje = (string)$validation["fields"]["mensaje"];
    $storedSubject = (string)$validation["fields"]["stored_subject"];

    $recipient = contact_site_recipient($conn);
    $to = $recipient["to"];
    $personName = $recipient["person_name"];

    $mailConfig = contact_load_mail_config();
    contact_log_form_submission($mailConfig, $nombre, $email, $servicio, $storedSubject, $mensaje, $to);

    $insert = contact_insert_message(
        $conn,
        $nombre,
        $email,
        $servicio,
        $storedSubject,
        $mensaje,
        $to,
        $submittingClientId,
        $inReplyToId
    );
    if (!$insert["ok"]) {
        return ["ok" => false, "error" => $insert["error"]];
    }

    $messageId = $insert["message_id"];

    $notify = contact_send_admin_notification(
        $mailConfig,
        $personName,
        $to,
        $nombre,
        $email,
        $servicio,
        $storedSubject,
        $mensaje,
        $inReplyToId
    );
    if (!$notify["ok"]) {
        return ["ok" => false, "error" => $notify["error"]];
    }

    $outcome = !empty($notify["mail_sent"]) ? "ok" : "saved";

    return [
        "ok" => true,
        "outcome" => $outcome,
        "message_id" => $messageId,
        "mail_sent" => (bool)$notify["mail_sent"],
    ];
}
