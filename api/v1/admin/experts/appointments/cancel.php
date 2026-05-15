<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/experts/appointments/cancel.php
 * expert_id, appointment_id.
 */

require_once __DIR__ . "/../../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_experts();
api_require_admin_session($conn);

$deny = admin_experts_require_agenda();
if ($deny !== null) {
    api_json_error("feature_disabled", 403);
}

$input = api_read_input();
$expertId = (int)($input["expert_id"] ?? 0);
$appointmentId = (int)($input["appointment_id"] ?? 0);
$result = experts_admin_cancel_appointment($conn, $expertId, $appointmentId);
if (!$result["ok"]) {
    api_json_error((string)($result["error"] ?? "cancel_failed"), 400);
}

api_json_ok([
    "expert_id" => $expertId,
    "appointment_id" => $appointmentId,
    "cancelled" => (bool)($result["cancelled"] ?? false),
]);
