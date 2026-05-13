<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------------
 * MAPA DE RUTAS (léelo aquí; es el único sitio “de verdad” en el código)
 * ---------------------------------------------------------------------------
 *
 * ARCHIVOS (raíz del proyecto = carpeta de la landing en disco):
 *   Landing pública  → index.php
 *   Panel admin      → admin.php
 *   Formulario       → send.php (POST desde la landing)
 *   Login clientes   → index.php#area-cliente (misma página tras sesión)
 *
 * URLS (dependen de dónde cuelgue la carpeta en el servidor web):
 *   Local típico (carpeta htdocs/pag-nombre):
 *     Landing  http://localhost/pag-nombre/
 *     Admin    http://localhost/pag-nombre/admin.php
 *   Producción (ejemplo):
 *     Landing  https://tudominio.com/
 *     Admin    https://tudominio.com/admin.php
 *     Clientes https://tudominio.com/index.php#area-cliente
 *   Si la instalación está en subcarpeta, el mismo patrón lleva el segmento
 *   extra: .../subcarpeta/ y .../subcarpeta/admin.php
 *
 * CÓMO SE CALCULA LA BASE (local y servidor):
 *   1) Si existe app_config.php con "public_base_url" (idealmente sin "/" final;
 *      si la lleva en la ruta, se normaliza) → esa es la base (útil en hosting con
 *      proxy o dominio fijo). Plantilla: app_config.example.php
 *   2) Si no → app_public_base_url() usa HTTP_HOST + HTTPS / X-Forwarded-Proto
 *      + dirname(SCRIPT_NAME) en cada petición.
 *
 * FUNCIONES: app_public_base_url(), app_landing_url(), app_admin_url(), app_client_portal_url()
 *             app_mail_plain_text_links_footer() — una URL en pie (admin o área cliente).
 * UI para copiar URLs: panel admin → acordeón «Rutas (landing y admin)».
 *
 * Depuración (servidor, no UI): en app_config.php pon log_public_base_url
 * a true; cada petición escribe una línea en el log de PHP (error_log) con
 * la base calculada y HTTP_HOST, SCRIPT_NAME, etc. Quitar en producción estable.
 * ---------------------------------------------------------------------------
 */

function app_load_url_config(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = __DIR__ . "/app_config.php";
    if (!is_readable($path)) {
        $cached = [];
        return $cached;
    }
    $cfg = require $path;
    $cached = is_array($cfg) ? $cfg : [];
    return $cached;
}

function app_log_public_base_url_debug(array $cfg, string $base, string $source): void
{
    if (empty($cfg["log_public_base_url"])) {
        return;
    }
    $https = (string)($_SERVER["HTTPS"] ?? "");
    $xff = (string)($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "");
    $sn = (string)($_SERVER["SCRIPT_NAME"] ?? "");
    $ru = (string)($_SERVER["REQUEST_URI"] ?? "");
    $h = (string)($_SERVER["HTTP_HOST"] ?? "");
    error_log(
        "[app_urls] public_base_url={$base} | source={$source} | HTTP_HOST={$h} | SCRIPT_NAME={$sn} | REQUEST_URI={$ru} | HTTPS={$https} | X-Forwarded-Proto={$xff}"
    );
}

function app_public_base_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $cfg = app_load_url_config();
    $forced = trim((string)($cfg["public_base_url"] ?? ""));
    if ($forced !== "") {
        $parsed = parse_url($forced);
        if (is_array($parsed) && !empty($parsed["scheme"]) && !empty($parsed["host"])) {
            $scheme = (string)$parsed["scheme"];
            $host = (string)$parsed["host"];
            $port = isset($parsed["port"]) ? ":" . (int)$parsed["port"] : "";
            $path = isset($parsed["path"]) ? (string)$parsed["path"] : "";
            $path = $path !== "" ? rtrim($path, "/") : "";
            $cached = $scheme . "://" . $host . $port . $path;
            app_log_public_base_url_debug($cfg, $cached, "public_base_url");
            return $cached;
        }
    }

    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (isset($_SERVER["HTTP_X_FORWARDED_PROTO"])
            && strtolower((string)$_SERVER["HTTP_X_FORWARDED_PROTO"]) === "https");
    $scheme = $https ? "https" : "http";
    $host = (string)($_SERVER["HTTP_HOST"] ?? "localhost");
    $script = (string)($_SERVER["SCRIPT_NAME"] ?? "");
    if ($script === "") {
        $script = "/index.php";
    }
    $scriptDir = str_replace("\\", "/", dirname($script));
    $scriptDir = rtrim($scriptDir, "/");
    if ($scriptDir === "" || $scriptDir === "." || $scriptDir === "/") {
        $pathPrefix = "";
    } else {
        $pathPrefix = $scriptDir;
    }
    $cached = $scheme . "://" . $host . $pathPrefix;
    app_log_public_base_url_debug($cfg, $cached, "auto");
    return $cached;
}

function app_landing_url(): string
{
    return app_public_base_url() . "/";
}

function app_admin_url(): string
{
    return app_public_base_url() . "/admin.php";
}

function app_client_login_url(): string
{
    return app_public_base_url() . "/index.php#area-cliente";
}

function app_client_dashboard_url(): string
{
    return app_public_base_url() . "/index.php#area-cliente";
}

/** Registro, login y vista de cliente en la misma landing. */
function app_client_portal_url(): string
{
    return app_public_base_url() . "/index.php#area-cliente";
}

/**
 * Una sola URL en pie de correo (texto plano).
 *
 * @param "admin_notify"|"visitor_reply"
 */
function app_mail_plain_text_links_footer(string $kind): string
{
    $kind = strtolower(trim($kind));
    if ($kind === "admin_notify") {
        return "---\nPanel de administración: " . app_admin_url() . "\n";
    }
    if ($kind === "visitor_reply") {
        return "---\nTu área de clientes y mensajes: " . app_client_portal_url() . "\n";
    }

    return "";
}

function app_public_url_source_description(): string
{
    $cfg = app_load_url_config();
    $forced = trim((string)($cfg["public_base_url"] ?? ""));
    if ($forced !== "") {
        return "Base fija: public_base_url en app_config.php.";
    }
    return "Base calculada desde esta petición (HTTP_HOST, HTTPS / X-Forwarded-Proto y la carpeta del script). En producción, si no coincide con la URL real, define public_base_url en app_config.php (ver app_config.example.php).";
}
