<?php
declare(strict_types=1);

// Credenciales por defecto (XAMPP). Se sobrescriben con db_config.php si existe.
// Crea db_config.php a partir de db_config.example.php en cada entorno.
$dbHost = "127.0.0.1";
$dbUser = "root";
$dbPass = "";
$dbName = "web_personal";

$dbConfigPath = __DIR__ . "/db_config.php";
if (is_readable($dbConfigPath)) {
    $dbConfig = require $dbConfigPath;
    if (is_array($dbConfig)) {
        $dbHost = (string)($dbConfig["host"] ?? $dbHost);
        $dbUser = (string)($dbConfig["user"] ?? $dbUser);
        $dbPass = (string)($dbConfig["password"] ?? $dbPass);
        $dbName = (string)($dbConfig["database"] ?? $dbName);
    }
}

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    http_response_code(500);
    die("Error de conexión a la base de datos.");
}

$conn->set_charset("utf8mb4");

function getBootstrapAdminCredentials(): ?array
{
    $bootstrapPath = __DIR__ . "/admin_bootstrap.php";
    if (!is_readable($bootstrapPath)) {
        return null;
    }

    $bootstrap = require $bootstrapPath;
    if (!is_array($bootstrap)) {
        return null;
    }

    $email = trim((string)($bootstrap["email"] ?? ""));
    $password = (string)($bootstrap["password"] ?? "");
    if ($email === "" || $password === "") {
        return null;
    }

    return [
        "email" => $email,
        "password" => $password,
    ];
}

// Auto-inicialización mínima para evitar errores si no se importó setup.sql.
$conn->query("
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL
)");

$conn->query("
CREATE TABLE IF NOT EXISTS admin_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_password_resets_admin (admin_id),
  INDEX idx_admin_password_resets_expires (expires_at)
)");

$adminsPasswordLength = 255;
$adminsPasswordColumnResult = $conn->query("
SELECT CHARACTER_MAXIMUM_LENGTH AS max_len
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'admins'
  AND COLUMN_NAME = 'password'
LIMIT 1
");
if ($adminsPasswordColumnResult && $adminsPasswordColumnResult->num_rows === 1) {
    $adminsPasswordColumn = $adminsPasswordColumnResult->fetch_assoc();
    $adminsPasswordLength = (int)($adminsPasswordColumn["max_len"] ?? 255);
}
if ($adminsPasswordLength < 255) {
    $conn->query("ALTER TABLE admins MODIFY password VARCHAR(255) NOT NULL");
}

$adminsCountResult = $conn->query("SELECT COUNT(*) AS total FROM admins");
$adminsTotal = 0;
if ($adminsCountResult) {
    $adminsRow = $adminsCountResult->fetch_assoc();
    $adminsTotal = (int)($adminsRow["total"] ?? 0);
}
if ($adminsTotal === 0) {
    $bootstrapAdmin = getBootstrapAdminCredentials();
    if (is_array($bootstrapAdmin)) {
        $hashedPassword = password_hash($bootstrapAdmin["password"], PASSWORD_DEFAULT);
        if ($hashedPassword !== false) {
            $stmt = $conn->prepare("INSERT INTO admins (email, password) VALUES (?, ?)");
            if ($stmt !== false) {
                $stmt->bind_param("ss", $bootstrapAdmin["email"], $hashedPassword);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

$conn->query("
CREATE TABLE IF NOT EXISTS site_settings (
  id INT PRIMARY KEY,
  person_name VARCHAR(180) NOT NULL,
  brand_name VARCHAR(120) NOT NULL,
  hero_title TEXT NOT NULL,
  hero_intro TEXT NOT NULL,
  about_text TEXT NOT NULL,
  contact_intro TEXT NOT NULL,
  contact_email VARCHAR(180) NOT NULL,
  contact_whatsapp VARCHAR(32) DEFAULT NULL,
  footer_text VARCHAR(180) NOT NULL,
  logo_image_path VARCHAR(255) DEFAULT NULL
)");

// Migración: si la BD ya existía con el schema viejo, agrega logo_image_path.
$siteSettingsHasLogo = false;
$siteSettingsLogoColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'site_settings'
  AND COLUMN_NAME = 'logo_image_path'
LIMIT 1
");
if ($siteSettingsLogoColumnResult && $siteSettingsLogoColumnResult->num_rows === 1) {
    $siteSettingsHasLogo = true;
}
if (!$siteSettingsHasLogo) {
    $conn->query("ALTER TABLE site_settings ADD COLUMN logo_image_path VARCHAR(255) DEFAULT NULL AFTER footer_text");
}

// Migración: agrega contact_whatsapp si la BD ya existía sin esa columna.
$siteSettingsHasWhatsapp = false;
$siteSettingsWhatsappColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'site_settings'
  AND COLUMN_NAME = 'contact_whatsapp'
LIMIT 1
");
if ($siteSettingsWhatsappColumnResult && $siteSettingsWhatsappColumnResult->num_rows === 1) {
    $siteSettingsHasWhatsapp = true;
}
if (!$siteSettingsHasWhatsapp) {
    $conn->query("ALTER TABLE site_settings ADD COLUMN contact_whatsapp VARCHAR(32) DEFAULT NULL AFTER contact_email");
}

$conn->query("
CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  icon_class VARCHAR(120) NOT NULL DEFAULT 'fa-solid fa-star',
  image_path VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 999,
  is_active TINYINT(1) NOT NULL DEFAULT 1
)");

$servicesHasImagePath = false;
$servicesImageColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'services'
  AND COLUMN_NAME = 'image_path'
LIMIT 1
");
if ($servicesImageColumnResult && $servicesImageColumnResult->num_rows === 1) {
    $servicesHasImagePath = true;
}
if (!$servicesHasImagePath) {
    $conn->query("ALTER TABLE services ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER icon_class");
}

$conn->query("
CREATE TABLE IF NOT EXISTS contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  email VARCHAR(180) NOT NULL,
  servicio VARCHAR(180) NOT NULL,
  mensaje TEXT NOT NULL,
  sent_to VARCHAR(180) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$messagesHasIsRead = false;
$messagesIsReadColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_messages'
  AND COLUMN_NAME = 'is_read'
LIMIT 1
");
if ($messagesIsReadColumnResult && $messagesIsReadColumnResult->num_rows === 1) {
    $messagesHasIsRead = true;
}
if (!$messagesHasIsRead) {
    $conn->query("ALTER TABLE contact_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER sent_to");
}

$conn->query("
CREATE TABLE IF NOT EXISTS service_gallery (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  caption VARCHAR(180) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 999,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_service_gallery_service (service_id),
  CONSTRAINT fk_service_gallery_service
    FOREIGN KEY (service_id) REFERENCES services(id)
    ON DELETE CASCADE
)");

$conn->query("
INSERT IGNORE INTO site_settings (
  id, person_name, brand_name, hero_title, hero_intro, about_text, contact_intro, contact_email, footer_text
) VALUES (
  1,
  'Tu Nombre',
  'Tu Marca',
  'Describe aqui tu propuesta principal de valor.',
  'Agrega una breve introduccion para tu portada.',
  'Escribe una descripcion corta sobre ti y tus servicios.',
  'Invita a tus visitantes a contactarte para mas informacion.',
  'contacto@tu-dominio.com',
  'Todos los derechos reservados.'
)
");

$servicesCountResult = $conn->query("SELECT COUNT(*) AS total FROM services");
$servicesTotal = 0;
if ($servicesCountResult) {
    $servicesRow = $servicesCountResult->fetch_assoc();
    $servicesTotal = (int)($servicesRow["total"] ?? 0);
}

if ($servicesTotal === 0) {
    $conn->query("
    INSERT INTO services (title, description, icon_class, image_path, sort_order, is_active)
    VALUES
      ('Servicio 1', 'Describe aqui el primer servicio que ofreces.', 'fa-solid fa-book-open-reader', NULL, 1, 1),
      ('Servicio 2', 'Describe aqui el segundo servicio con su beneficio principal.', 'fa-solid fa-code', NULL, 2, 1),
      ('Servicio 3', 'Describe aqui el tercer servicio de forma breve y clara.', 'fa-solid fa-guitar', NULL, 3, 1)
    ");
}
