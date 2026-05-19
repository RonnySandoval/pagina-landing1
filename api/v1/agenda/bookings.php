<?php
declare(strict_types=1);

/**
 * POST /api/v1/agenda/bookings.php
 * Crea una reserva (misma lógica que agenda_book.php).
 *
 * JSON o form: service_id, slot_token | (expert_id + starts_at),
 * guest_name, guest_email, guest_phone, notes, slot_units.
 */

require_once __DIR__ . "/../../bootstrap.php";
require_once __DIR__ . "/../../../client_portal_lib.php";

api_require_method("POST");
$conn = api_bootstrap_agenda();
client_session_start();

$input = api_read_input();

$clientId = null;
if (client_portal_resume_session($conn)) {
    $cid = (int)($_SESSION["client_id"] ?? 0);
    if ($cid > 0) {
        $clientId = $cid;
    }
}

$result = agenda_service_create_booking($conn, $input, ["client_id" => $clientId]);

if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    $message = (string)($result["message"] ?? "");
    if ($err === "feature_disabled") {
        api_json_error($err, 403, ["message" => $message]);
    }
    $status = $message !== "" ? agenda_service_booking_http_status($message) : 400;
    api_json_error($err, $status, ["message" => $message]);
}

api_json_ok([
    "appointment_id" => (int)$result["appointment_id"],
    "service_id" => (int)$result["service_id"],
    "expert_id" => (int)$result["expert_id"],
    "starts_at" => (string)$result["starts_at"],
    "ends_at" => (string)$result["ends_at"],
    "slot_units" => (int)$result["slot_units"],
    "notifications" => $result["notifications"] ?? [],
], 201);
