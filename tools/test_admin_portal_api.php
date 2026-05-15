<?php
declare(strict_types=1);

/**
 * Prueba API admin portal clientes + WhatsApp (fase 4.6).
 * Uso: php tools/test_admin_portal_api.php [admin_email] [password]
 */

$root = dirname(__DIR__);
chdir($root);

ob_start();
require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/admin_portal_lib.php";
require_once $root . "/admin_portal_service.php";
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
$cookieJar = $root . "/tools/_admin_portal_api_cookies.txt";

echo "=== admin_portal_service (CLI) ===\n\n";

$clients = admin_clients_service_list($conn);
test_assert(($clients["ok"] ?? false) === true, "list clientes CLI");
if (($clients["ok"] ?? false) === true) {
    test_assert(isset($clients["data"]["clients"]) && is_array($clients["data"]["clients"]), "data.clients es array");
}

$wa = admin_whatsapp_service_list($conn);
$waOff = ($wa["error"] ?? "") === "feature_disabled";
test_assert(($wa["ok"] ?? false) === true || $waOff, "list WhatsApp (o feature off)");

echo "\n=== HTTP API (sin sesión) ===\n\n";

if (!function_exists("curl_init")) {
    echo "  SKIP HTTP (sin ext-curl)\n";
    echo "\n=== Resumen ===\n";
    echo "Pasaron: {$passed}\n";
    echo "Fallaron: {$failures}\n";
    exit($failures > 0 ? 1 : 0);
}

@unlink($cookieJar);

$ch = curl_init($base . "/api/v1/admin/clients.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ["Accept: application/json"],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body !== false) {
    test_assert($code === 401, "GET clients sin sesión → 401");
}

$ch = curl_init($base . "/api/v1/admin/whatsapp-clicks.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ["Accept: application/json"],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body !== false) {
    if ($waOff) {
        test_assert($code === 401 || $code === 403, "GET whatsapp sin sesión");
    } else {
        test_assert($code === 401, "GET whatsapp sin sesión → 401");
    }
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

$ch = curl_init($base . "/api/v1/admin/clients.php");
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
    test_assert($code === 200, "GET clients 200");
}

if (!$waOff) {
    $ch = curl_init($base . "/api/v1/admin/whatsapp-clicks.php");
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
        test_assert($code === 200, "GET whatsapp-clicks 200");
        $dec = json_decode((string)$body, true);
        test_assert(isset($dec["data"]["counts"]["unread"]), "whatsapp incluye counts");
    }
}

@unlink($cookieJar);

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";

exit($failures > 0 ? 1 : 0);
