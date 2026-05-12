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
 *
 * URLS (dependen de dónde cuelgue la carpeta en el servidor web):
 *   Local típico (carpeta htdocs/pagina1):
 *     Landing  http://localhost/pagina1/
 *     Admin    http://localhost/pagina1/admin.php
 *   Producción (ejemplo):
 *     Landing  https://tudominio.com/
 *     Admin    https://tudominio.com/admin.php
 *   Si la instalación está en subcarpeta, el mismo patrón lleva el segmento
 *   extra: .../subcarpeta/ y .../subcarpeta/admin.php
 *
 * CÓMO SE CALCULA LA BASE (local y servidor):
 *   1) Si existe app_config.php con "public_base_url" → esa es la base (útil
 *      en hosting con proxy o dominio fijo). Plantilla: app_config.example.php
 *   2) Si no → app_public_base_url() usa HTTP_HOST + HTTPS / X-Forwarded-Proto
 *      + dirname(SCRIPT_NAME) en cada petición.
 *
 * FUNCIONES: app_public_base_url(), app_landing_url(), app_admin_url()
 * UI para copiar URLs: panel admin → acordeón «Rutas (landing y admin)».
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

function app_public_base_url(): string
{
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
            return $scheme . "://" . $host . $port . $path;
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
    return $scheme . "://" . $host . $pathPrefix;
}

function app_landing_url(): string
{
    return app_public_base_url() . "/";
}

function app_admin_url(): string
{
    return app_public_base_url() . "/admin.php";
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
