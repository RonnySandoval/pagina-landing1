<?php
declare(strict_types=1);

/**
 * POST /api/v1/contact/messages.php
 * Crea un mensaje de contacto (misma lógica que send.php).
 *
 * Cuerpo: application/json o application/x-www-form-urlencoded.
 * Campos: nombre, email, servicio, mensaje, asunto (opcional si in_reply_to),
 *         in_reply_to (opcional, requiere sesión de cliente),
 *         return_anchor (opcional: contacto | area-cliente)
 */

require_once __DIR__ . "/../../bootstrap.php";
require_once __DIR__ . "/../../../client_portal_lib.php";

api_require_method("POST");
$conn = api_bootstrap_core();
client_session_start();

$input = api_read_input();

$sessionClientId = 0;
$sessionEmailNorm = "";
if (client_portal_resume_session($conn)) {
    $sessionClientId = (int)($_SESSION["client_id"] ?? 0);
    $sessionEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
}

$result = contact_service_submit($conn, $input, [
    "session_client_id" => $sessionClientId,
    "session_email_norm" => $sessionEmailNorm,
    "require_client_inbox_for_area" => true,
]);

if (!$result["ok"]) {
    $status = 400;
    if (($result["error"] ?? "") === "client_inbox_disabled") {
        $status = 403;
    } elseif (($result["error"] ?? "") === "sesion_seguimiento") {
        $status = 401;
    } elseif (($result["error"] ?? "") === "db_insert") {
        $status = 500;
    }
    $extra = [];
    if (!empty($result["fields"]) && is_array($result["fields"])) {
        $extra["fields"] = $result["fields"];
    }
    api_json_error((string)$result["error"], $status, $extra);
}

api_json_ok([
    "message_id" => (int)$result["message_id"],
    "outcome" => (string)$result["outcome"],
    "mail_sent" => (bool)$result["mail_sent"],
], 201);
