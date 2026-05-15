<?php
declare(strict_types=1);

/**
 * Prueba API portal cliente (CLI helpers + HTTP con cookie jar).
 * Uso: php tools/test_client_api.php [email] [password]
 * Sin argumentos: solo pruebas que no requieren credenciales.
 */

$root = dirname(__DIR__);
chdir($root);

ob_start();
require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/client_portal_lib.php";
require_once $root . "/client_service.php";
ob_end_clean();

$failures = 0;
$passed = 0;

client_session_start();

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

$base = getenv("CLIENT_API_TEST_BASE");
if (!is_string($base) || $base === "") {
    $base = "http://localhost/pag-template";
}
$base = rtrim($base, "/");
$cookieJar = $root . "/tools/_client_api_cookies.txt";

echo "=== client_service (CLI) ===\n\n";

$_SESSION = [];
$st = client_service_session_status($conn);
test_assert(($st["ok"] ?? false) === true && ($st["authenticated"] ?? true) === false, "session_status sin login");

$badLogin = client_service_login($conn, "no-valid@example.local", "wrong");
test_assert($badLogin["ok"] === false, "login rechazado con credenciales falsas");

$testEmail = $argv[1] ?? getenv("CLIENT_TEST_EMAIL") ?: "";
$testPass = $argv[2] ?? getenv("CLIENT_TEST_PASSWORD") ?: "";

if ($testEmail !== "" && $testPass !== "") {
    $good = client_service_login($conn, $testEmail, $testPass);
    test_assert($good["ok"] === true, "login CLI con credenciales proporcionadas");
    if ($good["ok"] && app_feature_enabled("client_inbox")) {
        $uid = (int)($good["user"]["id"] ?? 0);
        $em = strtolower(trim((string)($good["user"]["email"] ?? "")));
        $inbox = client_service_get_inbox($conn, $uid, $em, 5);
        test_assert($inbox["ok"] === true, "get_inbox tras login");
        test_assert(isset($inbox["data"]["threads"]), "inbox incluye threads");
        $poll = client_service_poll_inbox($conn, $uid, $em);
        test_assert($poll["ok"] === true && isset($poll["data"]["site_unseen_total"]), "poll inbox");
    }
    client_service_logout();
} else {
    echo "  SKIP login/inbox CLI (pasa email y password: php tools/test_client_api.php user@mail pass)\n";
}

echo "\n=== HTTP API ===\n\n";

if (!function_exists("curl_init")) {
    echo "  SKIP HTTP (sin ext-curl)\n";
} else {
    @unlink($cookieJar);

    $ch = curl_init($base . "/api/v1/auth/session.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 200, "GET session HTTP 200");
        $dec = json_decode((string)$body, true);
        test_assert(($dec["ok"] ?? false) === true, "GET session ok:true");
    }

    $ch = curl_init($base . "/api/v1/auth/login.php");
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

    $ch = curl_init($base . "/api/v1/client/messages.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 401, "GET messages sin cookie → 401");
    }

    if ($testEmail !== "" && $testPass !== "") {
        $ch = curl_init($base . "/api/v1/auth/login.php");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_POSTFIELDS => json_encode(["email" => $testEmail, "password" => $testPass], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false) {
            test_assert($code === 200, "POST login HTTP 200");
            $dec = json_decode((string)$body, true);
            test_assert(($dec["ok"] ?? false) === true && isset($dec["data"]["user"]["id"]), "POST login devuelve user");
        }

        if (app_feature_enabled("client_inbox")) {
            $ch = curl_init($base . "/api/v1/client/messages.php");
            curl_setopt_array($ch, [
                CURLOPT_COOKIEFILE => $cookieJar,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ["Accept: application/json"],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false) {
                test_assert($code === 200, "GET messages con cookie HTTP 200");
            }

            $ch = curl_init($base . "/api/v1/client/inbox-poll.php");
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
                test_assert($code === 200, "GET inbox-poll HTTP 200");
            }
        }

        $ch = curl_init($base . "/api/v1/auth/logout.php");
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
        echo "  SKIP HTTP login+bandeja (credenciales en argv o CLIENT_TEST_EMAIL/PASSWORD)\n";
    }

    @unlink($cookieJar);
}

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";

exit($failures > 0 ? 1 : 0);
