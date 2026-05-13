-- Referencia del esquema y datos iniciales (placeholders en español).
-- En una instalación normal no hace falta importar este archivo: `db.php` crea
-- las tablas y el seed al cargar la landing o `admin.php`. Útil para phpMyAdmin
-- o entornos donde quieras aplicar el SQL a mano. Debe coincidir con la lógica
-- de migración en `db.php` (columnas nuevas se añaden allí si faltan).

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(180) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  display_name VARCHAR(180) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_notify_outbound TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clients_active (is_active)
);

CREATE TABLE IF NOT EXISTS client_registration_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(180) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(180) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_crt_token (token_hash),
  INDEX idx_crt_email (email),
  INDEX idx_crt_expires (expires_at)
);

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
  contact_whatsapp_country_code VARCHAR(8) DEFAULT NULL,
  footer_text VARCHAR(180) NOT NULL,
  logo_image_path VARCHAR(255) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  icon_class VARCHAR(120) NOT NULL DEFAULT 'fa-solid fa-star',
  image_path VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 999,
  is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  email VARCHAR(180) NOT NULL,
  servicio VARCHAR(180) NOT NULL,
  subject VARCHAR(200) NOT NULL DEFAULT '',
  mensaje TEXT NOT NULL,
  sent_to VARCHAR(180) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  client_id INT NULL DEFAULT NULL,
  in_reply_to INT NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_messages_client (client_id),
  INDEX idx_contact_messages_in_reply (in_reply_to)
);

CREATE TABLE IF NOT EXISTS contact_message_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_message_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_message_replies_msg (contact_message_id)
);

CREATE TABLE IF NOT EXISTS contact_whatsapp_clicks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL DEFAULT '',
  email VARCHAR(180) NOT NULL DEFAULT '',
  servicio VARCHAR(180) NOT NULL DEFAULT '',
  mensaje TEXT NOT NULL,
  composed_text TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_whatsapp_clicks_created (created_at)
);

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
);

INSERT INTO site_settings (id, person_name, brand_name, hero_title, hero_intro, about_text, contact_intro, contact_email, footer_text)
SELECT
  1,
  'Tu Nombre',
  'Tu Marca',
  'Describe aquí tu propuesta principal de valor.',
  'Agrega una breve introducción para tu portada.',
  'Escribe una descripción corta sobre ti y tus servicios.',
  'Invita a tus visitantes a contactarte para más información.',
  'contacto@tu-dominio.com',
  'Todos los derechos reservados.'
WHERE NOT EXISTS (
  SELECT 1 FROM site_settings WHERE id = 1
);

INSERT INTO services (title, description, icon_class, image_path, sort_order, is_active)
SELECT * FROM (
  SELECT 'Servicio 1', 'Describe aquí el primer servicio que ofreces.', 'fa-solid fa-book-open-reader', NULL, 1, 1
  UNION ALL
  SELECT 'Servicio 2', 'Describe aquí el segundo servicio con su beneficio principal.', 'fa-solid fa-code', NULL, 2, 1
  UNION ALL
  SELECT 'Servicio 3', 'Describe aquí el tercer servicio de forma breve y clara.', 'fa-solid fa-guitar', NULL, 3, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM services);
