<?php
declare(strict_types=1);

/**
 * Prueba API admin auth (fase 4.1).
 * Uso: php tools/test_admin_api.php [admin_email] [password]
 */

$root = dirname(__DIR__);
chdir($root);

ob_start();
require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/admin_portal_lib.php";
require_once $root . "/admin_service.php";
ob_end_clean();

admin_session_start();

$failures = 0;
$passed = 0;

function test_assert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        echo "  OK  {$label}\n";
        $passed++;
        return;
    }
    echo "  FAIL {$label}\n";
    $failures++;
}

$base = getenv("ADMIN_API_TEST_BASE");
if (!is_string($base) || $base === "") {
    $base = "http://localhost/pag-template";
}
$base = rtrim($base, "/");
$cookieJar = $root . "/tools/_admin_api_cookies.txt";

echo "=== admin_service (CLI) ===\n\n";

$_SESSION = [];
$st = admin_service_session_status($conn);
test_assert(($st["authenticated"] ?? true) === false, "sin sesión");

$bad = admin_service_login($conn, "nobody@test.local", "wrong");
test_assert($bad["ok"] === false, "login inválido rechazado");

$adminEmail = $argv[1] ?? getenv("ADMIN_TEST_EMAIL") ?: "";
$adminPass = $argv[2] ?? getenv("ADMIN_TEST_PASSWORD") ?: "";

if ($adminEmail !== "" && $adminPass !== "") {
    $ok = admin_service_login($conn, $adminEmail, $adminPass);
    test_assert($ok["ok"] === true, "login CLI OK");
    $st2 = admin_service_session_status($conn);
    test_assert(($st2["authenticated"] ?? false) === true, "sesión activa tras login");
    admin_service_logout();
} else {
    echo "  SKIP login CLI (argv o ADMIN_TEST_EMAIL / ADMIN_TEST_PASSWORD)\n";
}

$reset = admin_service_request_password_reset($conn, "fake@test.local");
test_assert(($reset["ok"] ?? false) === true && ($reset["message"] ?? "") !== "", "reset request mensaje genérico");

echo "\n=== HTTP API ===\n\n";

if (function_exists("curl_init")) {
    @unlink($cookieJar);

    $ch = curl_init($base . "/api/v1/admin/auth/session.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ["Accept: application/json"]]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 200, "GET admin session 200");
        $dec = json_decode((string)$body, true);
        test_assert(($dec["data"]["authenticated"] ?? null) === false, "admin no autenticado");
    }

    $ch = curl_init($base . "/api/v1/admin/auth/login.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(["email" => "x@y.com", "password" => "bad"], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 401, "POST login malo → 401");
    }

    if ($adminEmail !== "" && $adminPass !== "") {
        $ch = curl_init($base . "/api/v1/admin/auth/login.php");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_POSTFIELDS => json_encode(["email" => $adminEmail, "password" => $adminPass], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false) {
            test_assert($code === 200, "POST admin login 200");
        }

        $ch = curl_init($base . "/api/v1/admin/auth/session.php");
        curl_setopt_array($ch, [
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ["Accept: application/json"],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false) {
            $dec = json_decode((string)$body, true);
            test_assert($code === 200 && ($dec["data"]["authenticated"] ?? false) === true, "GET session con cookie");
        }

        $ch = curl_init($base . "/api/v1/admin/auth/logout.php");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } else {
        echo "  SKIP HTTP login (credenciales admin)\n";
    }

    @unlink($cookieJar);
} else {
    echo "  SKIP HTTP (sin ext-curl)\n";
}

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";

exit($failures > 0 ? 1 : 0);
