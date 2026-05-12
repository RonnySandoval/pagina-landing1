# Página personal multi-landing

Esqueleto en PHP + MySQL para crear y administrar varias landing pages
single-tenant con el mismo código base. Cada landing tiene su propia
carpeta, su propia BD y su propio admin, completamente aisladas entre sí.

## Índice

- [Qué es y qué no es](#qué-es-y-qué-no-es)
- [Requisitos](#requisitos)
- [Instalación local (primera vez)](#instalación-local-primera-vez)
- [Crear una landing nueva con `provision.ps1`](#crear-una-landing-nueva-con-provisionps1)
- [Borrar una landing local con `deprovision.ps1`](#borrar-una-landing-local-con-deprovisionps1)
- [Despliegue a producción](#despliegue-a-producción)
- [Recuperación de clave del admin](#recuperación-de-clave-del-admin)
- [Estructura de archivos](#estructura-de-archivos)
- [Decisiones de arquitectura](#decisiones-de-arquitectura)
- [Rol dual de este repo (template + instancia)](#rol-dual-de-este-repo-template--instancia)

---

## Qué es y qué no es

**Es:**

- Una landing personalizable desde un panel de admin web (textos, servicios,
  galería, mensajes recibidos del formulario de contacto).
- Un esqueleto que puedes clonar para crear varias landings independientes,
  cada una con su BD y su admin propios.
- Single-tenant por instalación: una carpeta + una BD = una landing.

**No es:**

- Un SaaS multi-tenant donde varios usuarios firman desde un mismo panel
  (eso requeriría refactorizar todas las queries con `site_id`; ver
  [Decisiones](#decisiones-de-arquitectura)).
- Un CMS general como WordPress: el esquema es fijo y específico para
  portafolios personales.

## Requisitos

- **Local:** XAMPP (PHP 8.1+, MySQL/MariaDB, Apache). Probado en Windows.
- **Producción:** cualquier hosting compartido con PHP 8.1+, MySQL y
  acceso a SMTP (App Password de Gmail funciona). Probado en InfinityFree.
- Un Gmail (u otro proveedor SMTP) con **contraseña de aplicación** para el
  remitente del formulario de contacto y de los enlaces de recuperación.

## Instalación local (primera vez)

1. Clonar el **template** dentro de `htdocs` con el nombre `pagina1` (así coinciden las rutas de este README y de `provision.ps1`, que usa `pagina1` como carpeta plantilla por defecto):

   ```powershell
   cd C:\xampp\htdocs
   git clone <URL-de-tu-repo-o-fork> pagina1
   ```

   Sustituye `<URL-de-tu-repo-o-fork>` por la URL HTTPS o SSH de **tu** copia del proyecto (fork propio, repo de organización, etc.). Si clonas con otro nombre de carpeta, tendrás que usar `-Template "ese-nombre"` al provisionar landings adicionales.

2. Crear los tres archivos de configuración a partir de los `*.example.php`:

   ```powershell
   cd pagina1
   Copy-Item db_config.example.php   db_config.php
   Copy-Item mail_config.example.php mail_config.php
   Copy-Item admin_bootstrap.example.php admin_bootstrap.php
   ```

3. Editar:
   - `db_config.php` → host/usuario/clave/nombre de la BD local.
   - `mail_config.php` → credenciales SMTP (Gmail con App Password u otro).
   - `admin_bootstrap.php` → correo real y clave inicial del admin.
   - Opcional: `app_config.example.php` → `app_config.php` y `public_base_url`
     si en el servidor la URL debe ser fija (proxy, dominio canónico). Si no
     existe, la base se infiere en cada petición. Tras iniciar sesión, el panel
     muestra las rutas de landing y admin en el acordeón **Rutas (landing y admin)**.

4. Crear la BD vacía en MySQL (con XAMPP/phpMyAdmin) con el nombre que
   pusiste en `db_config.php`. Por ejemplo:

   ```sql
   CREATE DATABASE web_personal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. Visitar `http://localhost/pagina1/admin.php` una vez. `db.php`
   creará todas las tablas y sembrará el admin desde `admin_bootstrap.php`.

6. Iniciar sesión con las credenciales del bootstrap y **borrar
   `admin_bootstrap.php`** (ya cumplió su rol). A partir de ahora la clave
   solo vive hasheada con bcrypt en MySQL.

   ```powershell
   Remove-Item admin_bootstrap.php
   ```

> Los archivos `db_config.php`, `mail_config.php`, `admin_bootstrap.php` y el
> opcional `app_config.php` están en `.gitignore`. Nunca subas secretos al repo;
> cada entorno los crea aparte.

> **Nota:** La primera instalación suele ser esta carpeta `pagina1` a mano.
> Las **siguientes** landings en el mismo PC se crean con `provision.ps1` en
> `C:\xampp\htdocs\pagina-<slug>\` (esa carpeta **no** está dentro del git de
> `pagina1` salvo que tú inicialices allí otro repo; ver [Rol dual](#rol-dual-de-este-repo-template--instancia)).

## Crear una landing nueva con `provision.ps1`

`provision.ps1` automatiza la copia del template, la BD vacía y la generación
de configs para una **landing nueva** (`pagina-juan`, `pagina-maria`, etc.).
Las **tablas** y el **admin** en BD se crean al visitar `admin.php` (como en la
instalación manual), salvo que uses `-SkipAutoSeed` y lo hagas tú después.

### Dónde vive

En `tools/provision.ps1` y `tools/provision.cmd`. Se versionan con el template
pero están **excluidos del deploy FTP** a producción.

### Quién lo usa

Solo tú (el dueño / desarrollador) en tu máquina local. Ningún cliente,
ningún usuario final, ni el servidor de producción lo ejecutan.

### Cuándo lo usas

- Cliente nuevo que necesita su propia landing (`pagina-juan`).
- Demo temporal para mostrarle algo a un prospecto.
- Sandbox para probar cambios sin tocar la landing principal.

### Cómo se invoca

Desde PowerShell (recomendado si ya tienes la ventana abierta):

```powershell
C:\xampp\htdocs\pagina1\tools\provision.ps1 `
  -Slug "juan" `
  -AdminEmail "juan@correo.com" `
  -AdminPassword "JuanIngles2026!"
```

Si haces **doble clic** o la consola se cierra sola al terminar, usa el
lanzador que deja el resultado visible y pide **Enter** al final (o `pause`
tras el script):

```text
C:\xampp\htdocs\pagina1\tools\provision.cmd -Slug "juan" -AdminEmail "juan@correo.com" -AdminPassword "JuanIngles2026!"
```

Para **CI o scripts** donde no debe haber pausa al final, añade **`-NoWait`**
al `.ps1`.

Esto crea de un solo golpe:

- Carpeta `C:\xampp\htdocs\pagina-juan\` con el código copiado desde el template
  (sin `.git`, `.github`, `uploads`, configs sensibles del template, etc.).
- BD MySQL `pagina_juan` **vacía** (utf8mb4); el esquema lo crea `db.php` al
  abrir `admin.php`.
- `db_config.php`, `mail_config.php`, `admin_bootstrap.php` generados dentro de
  la nueva carpeta.
- Por defecto intenta abrir `http://localhost/pagina-juan/admin.php` para
  sembrar tablas y admin; con **`-SkipAutoSeed`** ese paso lo haces tú en el
  navegador.

Luego abres `http://localhost/pagina-juan/admin.php` (si no lo hizo el script),
inicias sesión y borras `admin_bootstrap.php` igual que en la instalación
normal.

Si la copia falla, el script muestra el detalle de **robocopy** en consola.

### Flags útiles

| Flag                      | Para qué                                              |
| ------------------------- | ----------------------------------------------------- |
| `-NoSmtp`                 | No pregunta SMTP, deja `mail_config.php` con `use_smtp=false`. |
| `-SkipAutoSeed`           | No llama a `admin.php` automáticamente.               |
| `-Force`                  | Si la carpeta destino existe, la machaca y recrea.    |
| `-NoWait`                 | No pide Enter al final (automatización; el `.cmd` sigue haciendo `pause`). |
| `-Template "pagina-juan"` | Clona desde otra landing, no desde `pagina1`.         |
| `-DbHost / -DbUser / -DbPass` | Override de credenciales MySQL (default XAMPP).   |

### Lo que el script NO hace

- No despliega en producción (eso es manual).
- No edita una landing existente (solo crea nuevas).
- No hace backup automático antes de `-Force`.

## Borrar una landing local con `deprovision.ps1`

Operación inversa de `provision.ps1`. Borra la carpeta `pagina-<slug>\` y
la BD `pagina_<slug>` de tu MySQL local. Útil para limpiar landings de
prueba sin dejar bases huérfanas.

```powershell
C:\xampp\htdocs\pagina1\tools\deprovision.ps1 -Slug "demo"
```

Por seguridad pide confirmación interactiva: tienes que volver a escribir
el slug exacto. Para automatizarlo (CI, scripts envolventes), pasa
`-Force`.

### Flags útiles

| Flag             | Para qué                                                   |
| ---------------- | ---------------------------------------------------------- |
| `-Force`         | Salta la confirmación interactiva. Borrar es irreversible. |
| `-KeepDatabase`  | Solo borra la carpeta. La BD se preserva.                  |
| `-KeepFolder`    | Solo borra la BD. La carpeta se preserva.                  |
| `-DbHost / -DbUser / -DbPass` | Override de credenciales MySQL.               |

### Salvaguardas

- Rechaza slugs con caracteres raros (`../`, espacios, etc.).
- Rechaza nombres reservados (`pagina1` no se puede borrar con este script).
- Verifica que la ruta resuelta esté dentro de `htdocs\` antes de tocar
  el filesystem.

## Despliegue a producción

`provision.ps1` no funciona en hosting compartido (sin shell, sin permisos
de `CREATE DATABASE` desde PHP). El **primer despliegue** de cada landing es
manual en el panel y por FTP; **GitHub Actions solo sube archivos** cuando
configuras el workflow: **no crea** cuentas, **no crea** la BD ni el
subdominio.

### Antes del primer `push` con CI (secrets)

Tienes que tener **ya preparado** en el proveedor (p. ej. InfinityFree):

1. **Espacio web** accesible por FTP (usuario, contraseña, host).
2. **Carpeta remota** donde debe vivir esa instalación (`htdocs`, `public_html`,
   o una subcarpeta si usas `tudominio.com/juan/` o un subdominio que apunte a
   `htdocs/juan/`, etc.). Esa ruta es lo que suele ir en el secret
   **`FTP_SERVER_DIR`**.
3. **Base de datos MySQL** creada en el panel (host SQL, nombre, usuario y
   clave del proveedor).

Los **secrets** del repo (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
`FTP_SERVER_DIR`) son las credenciales de **ese** sitio concreto. Con **la misma
cuenta de hosting** y varias landings en **subcarpetas o subdominios**, a
menudo repites host/usuario/contraseña FTP y solo cambia **`FTP_SERVER_DIR`**
por repo; si cada landing es **otra cuenta** de hosting, suelen cambiar **los
cuatro** valores.

### Checklist una vez por landing

1. **Panel:** BD nueva (p. ej. nombre tipo `epiz_xxx_juan` en InfinityFree).
   Subdominio o ruta que apunte a una **carpeta nueva** si quieres varias
   landings en un solo hosting.
2. **FTP o CI:** Subir el código (desde `pagina-juan/` o desde la raíz del repo
   que versiones). El `deploy.yml` del template excluye `tools/`, secretos
   locales, `uploads/`, etc.; en el servidor deben existir los configs.
3. **En el servidor**, crear/editar `db_config.php`, `mail_config.php` y
   `admin_bootstrap.php` con datos reales del hosting (no van en git si están
   en `.gitignore`). Opcional: `app_config.php` con `public_base_url` si la URL
   canónica no se detecta bien.
4. Visitar `https://juan.tu-dominio.com/admin.php` una vez → creación de tablas
   y siembra del admin.
5. Iniciar sesión y borrar `admin_bootstrap.php` del servidor.

Si el repo tiene auto-deploy por FTP (`.github/workflows/deploy.yml`), cada
`push` a `main` **actualiza solo** el destino definido por esos secrets. Para
**varias landings** con multirepo, cada repo tiene **sus** secrets (a menudo
solo distinto `FTP_SERVER_DIR` si compartes el mismo FTP). Para un monorepo,
haría falta un workflow con matrix u otros jobs (no incluido por defecto).

## Recuperación de clave del admin

Cada landing tiene su propio flujo de recuperación, independiente:

1. En la pantalla de login del admin, hay un formulario "Recuperar clave
   por correo" debajo del login.
2. El correo introducido **debe coincidir exactamente** con el que está
   registrado en la tabla `admins` de esa landing (no es búsqueda fuzzy;
   es por seguridad).
3. Si coincide, se genera un token (sha256 hash en BD, válido 30 minutos)
   y se envía un enlace al correo del admin vía SMTP.
4. El enlace abre `admin.php?reset_token=...` donde el admin pone clave
   nueva.

Si el correo no coincide, el flujo aborta silenciosamente y el usuario
solo ve un mensaje genérico ("Si el correo existe, enviamos un enlace…")
para no revelar qué correos son admins.

> **Antes de poner una landing en producción real**, considera quitar la
> traza `admin_ajax_trace("password_reset link=" . $resetUrl)` en
> `admin.php`. Esa línea escribe el token completo en
> `contact_send_trace.log`, lo cual es útil en local pero peligroso en
> producción.

## Estructura de archivos

```
pagina1/
├── admin.php                  Panel de admin completo (login, settings, servicios, mensajes, reset).
├── index.php                  Landing pública: hero + sobre + servicios + contacto.
├── app_urls.php               URLs públicas (landing y admin); respeta app_config opcional.
├── send.php                   Endpoint del formulario de contacto.
├── db.php                     Conexión + auto-init del esquema + bootstrap del admin.
├── smtp_mail.php              Cliente SMTP minimalista (sin dependencias).
├── setup.sql                  Esquema + datos iniciales (placeholders).
├── styles.css, script.js      Frontend.
├── uploads/                   Imágenes subidas desde el admin (no versionado).
│
├── tools/
│   ├── provision.ps1          Script para crear nuevas landings en local
│   ├── provision.cmd          Lanzador: PowerShell + pause (doble clic / ventana que no desaparece)
│   └── deprovision.ps1        Script para borrar landings de prueba
│                              (versionado pero excluido del FTP deploy).
│
├── .github/
│   └── workflows/
│       └── deploy.yml         CI: auto-deploy por FTP de la instancia activa.
│
├── *.example.php              Plantillas públicas de configuración.
├── db_config.php              [GITIGNORED] Credenciales MySQL del entorno.
├── mail_config.php            [GITIGNORED] Credenciales SMTP del entorno.
├── admin_bootstrap.php        [GITIGNORED] Credenciales iniciales del primer admin.
├── app_config.php             [GITIGNORED] Opcional: public_base_url para producción/proxy.
└── README.md                  Este archivo.
```

Tablas en MySQL:

| Tabla                    | Para qué                                    |
| ------------------------ | ------------------------------------------- |
| `admins`                 | Cuentas del panel admin (1 por landing).    |
| `admin_password_resets`  | Tokens de recuperación de clave (sha256).   |
| `site_settings`          | Textos del sitio (1 fila, id=1).            |
| `services`               | Cards de servicios mostrados en la landing. |
| `service_gallery`        | Imágenes adicionales por servicio.          |
| `contact_messages`       | Mensajes recibidos por el formulario.       |

## Decisiones de arquitectura

**Single-tenant por instalación.** Cada landing es una carpeta + una BD
independiente. Pros: aislamiento total, mismo código simple, escalable
manualmente. Contras: no hay registro self-service de usuarios; cada
landing es provisionada por el dueño del proyecto.

**Por qué no multi-tenant compartido (todos en una BD con `site_id`):**
implicaba reescribir todas las queries de `admin.php` e `index.php` para
filtrar por `site_id`, y elevaba el riesgo de fugas de datos entre
clientes. Para hosting compartido y un volumen bajo de landings (10-20),
single-tenant es más simple y más seguro.

**Por qué `admin_bootstrap.php` y no un wizard web:** el wizard requeriría
distinguir "primer arranque" del flujo normal y abrir un endpoint extra de
mutación sin auth. Un archivo en disco que sólo se lee si `admins` está
vacía, y que el admin borra después, es más simple y más auditable.

**Por qué `password_hash` con compatibilidad para texto plano:** las
versiones viejas del proyecto guardaban claves en plano. Para no romper
admins ya existentes en producción al actualizar el código, el handler
de login acepta ambas y rehashea automáticamente al primer login válido.

**Por qué el token de reset se hashea en BD:** si la BD se filtra, los
tokens activos no son utilizables (se necesita el original). El usuario
recibe el original solo por correo (canal lateral).

## Rol dual de este repo (template + instancia)

> Este repo cumple **dos roles a la vez** ahora mismo. Es importante
> entenderlo antes de añadir más landings.

1. **Template reutilizable**: el código fuente sin datos personales.
   Cualquiera puede clonarlo para arrancar una landing nueva con
   `provision.ps1`.
2. **Instancia activa de la landing principal**: este mismo repo tiene
   un workflow de CI (`.github/workflows/deploy.yml`) que despliega por
   FTP a un servidor concreto. Los valores reales de FTP_SERVER,
   FTP_PASSWORD, etc. viven en *GitHub Secrets* del repo, no en el código.
   Pero el resultado neto es que cada `git push` de este repo afecta a
   una landing real específica.

El **contenido del repo** (lo que clonas) es genérico y sin secretos. Lo
que está atado a una instancia concreta es la **configuración de GitHub
Actions** (los secrets) y, cosméticamente, el nombre del repo y el del
workflow.

### Modelos para escalar a varias landings

| Modelo | Cómo se hace | Cuándo usarlo |
| ------ | ------------ | ------------- |
| **C — Provision local + FTP manual** | `provision.ps1` crea `pagina-juan/` local; subes a hosting por FTP a mano. La carpeta no es repo. | Empezando, 1-2 landings. Nada de CI por landing. |
| **A — Un repo por landing** | Cada `pagina-juan/` tiene su propio repo en GitHub con su propio `.github/workflows/deploy.yml` y sus propios secrets apuntando al servidor de Juan. | Cuando una landing necesita auto-deploy regular o varios developers la editan. |
| **B — Monorepo con varias landings** | Un solo repo con `pagina1/`, `pagina-juan/`, etc. Workflow con matrix que despliega cada carpeta a su FTP_SERVER_DIR. | Cuando tienes muchas landings y quieres una sola consola de CI. |

Hoy estamos en **Modelo C** (más una instancia "modelo A residual" para
la landing principal: el repo actual). Cuando aparezca el primer cliente
que necesite CI, lo más simple es:

1. Provisionar local con `provision.ps1`.
2. Dentro de `pagina-juan/`: `git init`, crear repo nuevo en GitHub,
   `git remote add origin <nuevo-repo>`, `git push`.
3. En el repo nuevo, configurar sus propios GitHub Secrets de FTP.
4. (Opcional) borrar `.github/workflows/deploy.yml` del clon si no
   quieres CI para esa landing.
