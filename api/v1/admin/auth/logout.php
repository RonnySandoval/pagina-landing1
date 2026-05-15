<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/auth/logout.php
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
api_bootstrap_admin();
admin_session_start();
admin_service_logout();

api_json_ok(["logged_out" => true]);
