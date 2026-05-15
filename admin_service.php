<?php
declare(strict_types=1);

require_once __DIR__ . "/admin_portal_lib.php";

/**
 * @return array{ok: true, user: array{id: int, email: string}} | array{ok: false, error: string, message: string}
 */
function admin_service_login(mysqli $conn, string $email, string $password): array
{
    $err = admin_try_login($conn, $email, $password);
    if ($err !== null) {
        return ["ok" => false, "error" => "invalid_credentials", "message" => $err];
    }
    $user = admin_current_user($conn);
    if ($user === null) {
        return ["ok" => false, "error" => "session_failed", "message" => "No se pudo iniciar sesión."];
    }

    return ["ok" => true, "user" => $user];
}

function admin_service_logout(): void
{
    admin_session_destroy();
}

/**
 * @return array{ok: true, authenticated: bool, user?: array{id: int, email: string}}
 */
function admin_service_session_status(mysqli $conn): array
{
    $user = admin_current_user($conn);
    if ($user !== null) {
        return ["ok" => true, "authenticated" => true, "user" => $user];
    }

    return ["ok" => true, "authenticated" => false];
}

/**
 * @return array{ok: true, message: string}
 */
function admin_service_request_password_reset(mysqli $conn, string $email): array
{
    $result = admin_request_password_reset($conn, $email);

    return ["ok" => true, "message" => (string)($result["message"] ?? "")];
}

/**
 * @return array{ok: true} | array{ok: false, error: string, message: string}
 */
function admin_service_reset_password(
    mysqli $conn,
    string $token,
    string $newPassword,
    string $confirmPassword
): array {
    $err = admin_reset_password_with_token($conn, $token, $newPassword, $confirmPassword);
    if ($err !== null) {
        return ["ok" => false, "error" => "reset_failed", "message" => $err];
    }

    return ["ok" => true];
}
