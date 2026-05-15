<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/auth/session.php
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_admin();
admin_session_start();

api_json_ok(admin_service_session_status($conn));
