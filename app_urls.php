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
 *   API contacto     → api/v1/contact/messages.php (POST JSON o form; misma lógica que send.php)
 *   Agenda / citas   → agenda.php (tabla de huecos; requiere features.expert_agenda)
 *   Reserva agenda   → agenda_book.php (POST desde agenda.php o landing)
 *   API agenda       → api/v1/agenda/slots.php (GET), api/v1/agenda/bookings.php (POST)
 *   API portal       → api/v1/auth/* (sesión, login, registro), api/v1/client/* (mensajes)
 *   API admin        → api/v1/admin/auth/* (sesión, login, recuperación de clave)
 *   Login clientes   → index.php#area-cliente (misma página tras sesión)
 *
 * Módulos opcionales: `app_feature_enabled()` lee `features` en app_config.php
 * (p. ej. client_inbox, admin_inbox, expert_agenda).
 * (ver app_config.example.php). Sirve para desactivar WhatsApp o la bandeja
 * del área cliente en una instalación concreta.
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
 *             app_feature_enabled() — módulos opcionales (ver app_config.example.php).
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

/**
 * Activa o desactiva módulos por instalación (`app_config.php`, clave `features`).
 * Si no existe `features` o falta el nombre, el módulo queda activo (comportamiento por defecto).
 */
function app_feature_enabled(string $name): bool
{
    $cfg = app_load_url_config();
    $features = $cfg["features"] ?? null;
    if (!is_array($features)) {
        return true;
    }
    if (!array_key_exists($name, $features)) {
        return true;
    }

    $v = $features[$name];
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return ((float)$v) !== 0.0;
    }
    if (is_string($v)) {
        $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($b !== null) {
            return $b;
        }
        $t = strtolower(trim($v));

        return !in_array($t, ["0", "false", "no", "off", ""], true);
    }

    return (bool)$v;
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

/** POST mensajes de contacto (API v1). */
function app_contact_messages_api_url(): string
{
    return app_public_base_url() . "/api/v1/contact/messages.php";
}

/** GET huecos de agenda (API v1). */
function app_agenda_slots_api_url(): string
{
    return app_public_base_url() . "/api/v1/agenda/slots.php";
}

/** POST reservas de agenda (API v1). */
function app_agenda_bookings_api_url(): string
{
    return app_public_base_url() . "/api/v1/agenda/bookings.php";
}

function app_client_auth_session_api_url(): string
{
    return app_public_base_url() . "/api/v1/auth/session.php";
}

function app_client_auth_login_api_url(): string
{
    return app_public_base_url() . "/api/v1/auth/login.php";
}

function app_client_messages_api_url(): string
{
    return app_public_base_url() . "/api/v1/client/messages.php";
}

function app_client_inbox_poll_api_url(): string
{
    return app_public_base_url() . "/api/v1/client/inbox-poll.php";
}

function app_admin_auth_session_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/auth/session.php";
}

function app_admin_auth_login_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/auth/login.php";
}

function app_admin_messages_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/messages.php";
}

function app_admin_messages_read_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/messages/read.php";
}

function app_admin_messages_read_all_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/messages/read-all.php";
}

function app_admin_messages_delete_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/messages/delete.php";
}

function app_admin_messages_reply_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/messages/reply.php";
}

function app_admin_settings_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/settings.php";
}

function app_admin_settings_logo_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/settings/logo.php";
}

function app_admin_settings_agenda_display_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/settings/agenda-display.php";
}

function app_admin_services_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/services.php";
}

function app_admin_services_image_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/services/image.php";
}

function app_admin_services_gallery_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/services/gallery.php";
}

function app_admin_services_gallery_reorder_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/services/gallery/reorder.php";
}

function app_admin_experts_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts.php";
}

function app_admin_experts_week_grid_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts/week-grid.php";
}

function app_admin_experts_availability_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts/availability.php";
}

function app_admin_experts_availability_mon_fri_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts/availability/mon-fri.php";
}

function app_admin_experts_availability_bulk_mon_fri_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts/availability/bulk-mon-fri.php";
}

function app_admin_experts_availability_date_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts/availability-date.php";
}

function app_admin_experts_appointments_cancel_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/experts/appointments/cancel.php";
}

function app_admin_clients_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/clients.php";
}

function app_admin_clients_toggle_active_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/clients/toggle-active.php";
}

function app_admin_clients_toggle_email_notify_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/clients/toggle-email-notify.php";
}

function app_admin_whatsapp_clicks_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/whatsapp-clicks.php";
}

function app_admin_whatsapp_clicks_read_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/whatsapp-clicks/read.php";
}

function app_admin_whatsapp_clicks_read_all_api_url(): string
{
    return app_public_base_url() . "/api/v1/admin/whatsapp-clicks/read-all.php";
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
