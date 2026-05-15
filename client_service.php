<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/client_inbox_helpers.php";

/**
 * @return array{id: int, email: string, display_name: string}|null
 */
function client_service_current_user(mysqli $conn): ?array
{
    if (!client_portal_resume_session($conn)) {
        return null;
    }

    return [
        "id" => (int)($_SESSION["client_id"] ?? 0),
        "email" => (string)($_SESSION["client_email"] ?? ""),
        "display_name" => trim((string)($_SESSION["client_display_name"] ?? "")),
    ];
}

/**
 * @return array{ok: true, user: array{id: int, email: string, display_name: string}} | array{ok: false, error: string, message: string}
 */
function client_service_login(mysqli $conn, string $email, string $password): array
{
    $err = client_try_login($conn, $email, $password);
    if ($err !== null) {
        return ["ok" => false, "error" => "invalid_credentials", "message" => $err];
    }
    $user = client_service_current_user($conn);
    if ($user === null) {
        return ["ok" => false, "error" => "session_failed", "message" => "No se pudo iniciar sesión."];
    }

    return ["ok" => true, "user" => $user];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function client_service_register(mysqli $conn, array $input): array
{
    $email = trim((string)($input["email"] ?? $input["reg_email"] ?? ""));
    $password = (string)($input["password"] ?? $input["reg_password"] ?? "");
    $passwordConfirm = (string)($input["password_confirm"] ?? $input["reg_password_confirm"] ?? "");
    $displayName = (string)($input["display_name"] ?? $input["reg_display_name"] ?? "");

    $reg = client_try_register($conn, $email, $password, $passwordConfirm, $displayName);
    if (!empty($reg["ok"])) {
        return [
            "ok" => true,
            "awaiting_verification" => true,
            "email" => (string)($reg["email"] ?? $email),
        ];
    }
    if (!empty($reg["need_email_choice"])) {
        return [
            "ok" => false,
            "error" => "verification_email_failed",
            "need_email_choice" => true,
            "message" => "No se pudo enviar el correo de confirmación. Puedes activar la cuenta solo en la web o probar otro correo.",
        ];
    }

    return [
        "ok" => false,
        "error" => "registration_rejected",
        "message" => (string)($reg["error"] ?? "No se pudo registrar."),
    ];
}

/**
 * @return array{ok: true, user: array{id: int, email: string, display_name: string}} | array{ok: false, error: string, message: string}
 */
function client_service_confirm_registration(mysqli $conn, string $token): array
{
    $err = client_try_register_confirm_token($conn, trim($token));
    if ($err !== null) {
        return ["ok" => false, "error" => "invalid_token", "message" => $err];
    }
    $user = client_service_current_user($conn);
    if ($user === null) {
        return ["ok" => false, "error" => "session_failed", "message" => "Cuenta creada pero no se pudo abrir sesión."];
    }

    return ["ok" => true, "user" => $user];
}

/**
 * @return array{ok: true, user: array{id: int, email: string, display_name: string}} | array{ok: false, error: string, message: string}
 */
function client_service_register_finalize_no_mail(mysqli $conn): array
{
    $err = client_try_register_finalize_no_mail($conn);
    if ($err !== null) {
        return ["ok" => false, "error" => "finalize_failed", "message" => $err];
    }
    $user = client_service_current_user($conn);
    if ($user === null) {
        return ["ok" => false, "error" => "session_failed", "message" => "Cuenta creada pero no se pudo abrir sesión."];
    }

    return ["ok" => true, "user" => $user];
}

function client_service_register_retry_clear(mysqli $conn): void
{
    client_register_retry_clear($conn);
}

function client_service_logout(): void
{
    client_session_destroy();
}

/**
 * @return array{ok: true, authenticated: bool, user?: array{id: int, email: string, display_name: string}, registration_pending?: bool}
 */
function client_service_session_status(mysqli $conn): array
{
    $user = client_service_current_user($conn);
    if ($user !== null) {
        return ["ok" => true, "authenticated" => true, "user" => $user];
    }

    $pending = client_register_pending_get();
    if ($pending !== null) {
        return [
            "ok" => true,
            "authenticated" => false,
            "registration_pending" => true,
            "pending_email" => (string)($pending["email"] ?? ""),
            "verification_sent" => !empty($pending["verification_sent"]),
        ];
    }

    return ["ok" => true, "authenticated" => false];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function client_service_get_inbox(mysqli $conn, int $clientId, string $emailNorm, int $limit = 40): array
{
    if (!app_feature_enabled("client_inbox")) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    $inbox = client_inbox_load_full($conn, $clientId, $emailNorm, $limit);

    return [
        "ok" => true,
        "data" => [
            "messages" => $inbox["messages"],
            "replies" => $inbox["replies_by_message_id"],
            "threads" => $inbox["threads"],
            "site_unseen_total" => $inbox["site_unseen_total"],
            "max_reply_id" => $inbox["max_reply_id"],
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function client_service_poll_inbox(mysqli $conn, int $clientId, string $emailNorm): array
{
    if (!app_feature_enabled("client_inbox")) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    return ["ok" => true, "data" => client_inbox_poll_snapshot($conn, $clientId, $emailNorm)];
}
