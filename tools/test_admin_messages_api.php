<?php
declare(strict_types=1);

/**
 * Prueba API admin mensajes (fase 4.2).
 * Uso: php tools/test_admin_messages_api.php [admin_email] [password]
 */

$root = dirname(__DIR__);
chdir($root);

ob_start();
require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/admin_portal_lib.php";
require_once $root . "/admin_messages_service.php";
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
$cookieJar = $root . "/tools/_admin_messages_api_cookies.txt";

echo "=== admin_messages_service (CLI) ===\n\n";

$list = admin_messages_service_list($conn, 5);
test_assert(($list["ok"] ?? false) === true || ($list["error"] ?? "") === "feature_disabled", "list sin sesión (feature o ok)");

$badGet = admin_messages_service_get($conn, 0);
test_assert(($badGet["ok"] ?? true) === false, "get id 0 rechazado");

echo "\n=== HTTP API (sin sesión) ===\n\n";

if (!function_exists("curl_init")) {
    echo "  SKIP HTTP (sin ext-curl)\n";
    echo "\n=== Resumen ===\n";
    echo "Pasaron: {$passed}\n";
    echo "Fallaron: {$failures}\n";
    exit($failures > 0 ? 1 : 0);
}

@unlink($cookieJar);

$ch = curl_init($base . "/api/v1/admin/messages.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ["Accept: application/json"],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body !== false) {
    test_assert($code === 401, "GET messages sin sesión → 401");
}

$ch = curl_init($base . "/api/v1/admin/messages/read.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(["message_id" => 1], JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body !== false) {
    test_assert($code === 401, "POST read sin sesión → 401");
}

$adminEmail = $argv[1] ?? getenv("ADMIN_TEST_EMAIL") ?: "";
$adminPass = $argv[2] ?? getenv("ADMIN_TEST_PASSWORD") ?: "";

if ($adminEmail === "" || $adminPass === "") {
    echo "  SKIP HTTP autenticado (argv o ADMIN_TEST_EMAIL / ADMIN_TEST_PASSWORD)\n";
    @unlink($cookieJar);
    echo "\n=== Resumen ===\n";
    echo "Pasaron: {$passed}\n";
    echo "Fallaron: {$failures}\n";
    exit($failures > 0 ? 1 : 0);
}

echo "\n=== HTTP API (con sesión admin) ===\n\n";

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
if ($body === false || $code !== 200) {
    echo "  FAIL login admin (code {$code})\n";
    $failures++;
    @unlink($cookieJar);
    echo "\n=== Resumen ===\n";
    echo "Pasaron: {$passed}\n";
    echo "Fallaron: {$failures}\n";
    exit(1);
}
test_assert(true, "POST admin login 200");

$ch = curl_init($base . "/api/v1/admin/messages.php?limit=10");
curl_setopt_array($ch, [
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ["Accept: application/json"],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$firstId = 0;
if ($body !== false) {
    test_assert($code === 200 || $code === 403, "GET messages lista 200/403");
    $dec = json_decode((string)$body, true);
    if ($code === 200 && is_array($dec["data"]["messages"] ?? null)) {
        test_assert(isset($dec["data"]["counts"]["unread"]), "lista incluye counts");
        $msgs = $dec["data"]["messages"];
        if (count($msgs) > 0) {
            $firstId = (int)($msgs[0]["id"] ?? 0);
        }
    }
}

if ($firstId > 0) {
    $ch = curl_init($base . "/api/v1/admin/messages.php?id=" . $firstId);
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
        test_assert($code === 200, "GET message por id");
        $dec = json_decode((string)$body, true);
        test_assert((int)($dec["data"]["message"]["id"] ?? 0) === $firstId, "detalle id coincide");
    }

    $ch = curl_init($base . "/api/v1/admin/messages/read.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_POSTFIELDS => json_encode(["message_id" => $firstId, "read" => true], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 200, "POST mark read");
    }
} else {
    echo "  SKIP detalle/mark read (bandeja vacía)\n";
}

$ch = curl_init($base . "/api/v1/admin/messages.php?id=999999999");
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
    test_assert($code === 404, "GET id inexistente → 404");
}

@unlink($cookieJar);

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";

exit($failures > 0 ? 1 : 0);
