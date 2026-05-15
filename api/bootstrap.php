<?php
declare(strict_types=1);

/**
 * Utilidades HTTP compartidas por api/v1/*.
 */

function api_json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header("Content-Type: application/json; charset=UTF-8");
        header("Cache-Control: no-store");
    }
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined("JSON_INVALID_UTF8_SUBSTITUTE")) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

function api_json_ok(array $data = [], int $status = 200): void
{
    api_json_response(["ok" => true, "data" => $data], $status);
}

function api_json_error(string $error, int $status = 400, array $extra = []): void
{
    $payload = array_merge(["ok" => false, "error" => $error], $extra);
    api_json_response($payload, $status);
}

/** Cuerpo JSON o campos POST clásicos del formulario. */
function api_read_input(): array
{
    $method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if ($method === "GET") {
        return $_GET;
    }

    $contentType = strtolower(trim((string)($_SERVER["CONTENT_TYPE"] ?? "")));
    if (str_contains($contentType, "application/json")) {
        $raw = file_get_contents("php://input");
        if ($raw === false || $raw === "") {
            return [];
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function api_require_method(string $method): void
{
    $current = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if ($current !== strtoupper($method)) {
        api_json_error("method_not_allowed", 405);
    }
}

function api_bootstrap_db(): mysqli
{
    require __DIR__ . "/../db.php";
    require_once __DIR__ . "/../app_urls.php";

    return $conn;
}

function api_bootstrap_core(): mysqli
{
    $conn = api_bootstrap_db();
    require_once __DIR__ . "/../smtp_mail.php";
    require_once __DIR__ . "/../contact_service.php";

    return $conn;
}

function api_bootstrap_agenda(): mysqli
{
    $conn = api_bootstrap_db();
    require_once __DIR__ . "/../agenda_service.php";

    return $conn;
}

function api_bootstrap_client(): mysqli
{
    $conn = api_bootstrap_db();
    require_once __DIR__ . "/../client_service.php";

    return $conn;
}

/**
 * @return array{id: int, email: string, display_name: string}
 */
function api_require_client_session(mysqli $conn): array
{
    client_session_start();
    $user = client_service_current_user($conn);
    if ($user === null) {
        api_json_error("no_session", 401);
    }

    return $user;
}

function api_bootstrap_admin(): mysqli
{
    $conn = api_bootstrap_db();
    require_once __DIR__ . "/../smtp_mail.php";
    require_once __DIR__ . "/../admin_service.php";

    return $conn;
}

function api_bootstrap_admin_messages(): mysqli
{
    $conn = api_bootstrap_admin();
    require_once __DIR__ . "/../admin_messages_service.php";

    return $conn;
}

function api_bootstrap_admin_settings(): mysqli
{
    $conn = api_bootstrap_admin();
    require_once __DIR__ . "/../admin_settings_service.php";

    return $conn;
}

function api_bootstrap_admin_services(): mysqli
{
    $conn = api_bootstrap_admin();
    require_once __DIR__ . "/../admin_services_service.php";

    return $conn;
}

function api_bootstrap_admin_experts(): mysqli
{
    $conn = api_bootstrap_admin();
    require_once __DIR__ . "/../admin_experts_service.php";

    return $conn;
}

function api_bootstrap_admin_portal(): mysqli
{
    $conn = api_bootstrap_admin();
    require_once __DIR__ . "/../admin_portal_service.php";

    return $conn;
}

/**
 * @return array{id: int, email: string}
 */
function api_require_admin_session(mysqli $conn): array
{
    admin_session_start();
    $user = admin_current_user($conn);
    if ($user === null) {
        api_json_error("no_session", 401);
    }

    return $user;
}
