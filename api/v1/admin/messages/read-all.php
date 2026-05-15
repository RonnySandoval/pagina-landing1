<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/messages/read-all.php
 * read: true = marcar todos leídos, false = todos sin leer.
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_messages();
api_require_admin_session($conn);

$input = api_read_input();
$read = !array_key_exists("read", $input) || filter_var($input["read"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

$result = admin_messages_service_set_all_read($conn, $read);
if (!$result["ok"]) {
    api_json_error((string)($result["error"] ?? "feature_disabled"), 403);
}

api_json_ok($result["data"]);
