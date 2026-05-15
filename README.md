# Página personal multi-landing

Esqueleto en PHP + MySQL para crear y administrar varias landing pages
single-tenant con el mismo código base. Cada landing tiene su propia
carpeta, su propia BD y su propio admin, completamente aisladas entre sí.

## Índice

- [Qué es y qué no es](#qué-es-y-qué-no-es)
- [Requisitos](#requisitos)
- [Instalación local (primera vez)](#instalación-local-primera-vez)
- [Configuración `app_config.php` y módulos](#configuración-app_configphp-y-módulos)
- [Crear una landing nueva con `provision.ps1`](#crear-una-landing-nueva-con-provisionps1)
- [Borrar una landing local con `deprovision.ps1`](#borrar-una-landing-local-con-deprovisionps1)
- [Despliegue a producción](#despliegue-a-producción)
- [Panel admin (resumen)](#panel-admin-resumen)
- [Cliente público, portal y mensajes](#cliente-público-portal-y-mensajes)
- [Recuperación de clave del admin](#recuperación-de-clave-del-admin)
- [Estructura de archivos](#estructura-de-archivos)
- [Base de datos (tablas)](#base-de-datos-tablas)
- [Decisiones de arquitectura](#decisiones-de-arquitectura)
- [Rol dual de este repo (template + instancia)](#rol-dual-de-este-repo-template--instancia)

---

## Qué es y qué no es

**Es:**

- Una landing personalizable desde un panel de admin web (textos, servicios,
  galería, mensajes recibidos del formulario de contacto).
- Un esqueleto que puedes clonar para crear varias landings independientes,
  cada una con su BD y su admin propios.
- Un **portal de clientes** opcional: registro e inicio de sesión en la misma
  landing (`index.php#area-cliente`), sesión aislada del admin (ver [Cliente público, portal y mensajes](#cliente-público-portal-y-mensajes)).
- Módulo **Expertos** (agenda en evolución): catálogo de expertos vinculado a servicios; se muestra u oculta con `features.expert_agenda` en `app_config.php` (ver [Configuración `app_config.php` y módulos](#configuración-app_configphp-y-módulos)).

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

1. Clonar el **template** dentro de `htdocs` con el nombre `pag-template` (así coinciden las rutas de este README y de `provision.ps1`, que usa `pag-template` como carpeta plantilla por defecto):

   ```powershell
   cd C:\xampp\htdocs
   git clone <URL-de-tu-repo-o-fork> pag-template
   ```

   Sustituye `<URL-de-tu-repo-o-fork>` por la URL HTTPS o SSH de **tu** copia del proyecto (fork propio, repo de organización, etc.). Si clonas con otro nombre de carpeta, tendrás que usar `-Template "ese-nombre"` al provisionar landings adicionales.

2. Crear los archivos de configuración a partir de los `*.example.php`:

   ```powershell
   cd pag-template
   Copy-Item db_config.example.php        db_config.php
   Copy-Item mail_config.example.php     mail_config.php
   Copy-Item admin_bootstrap.example.php admin_bootstrap.php
   # Opcional (URLs fijas o depuración de rutas): descomenta la siguiente línea.
   # Copy-Item app_config.example.php app_config.php
   ```

3. Editar:
   - `db_config.php` → host, usuario, clave y nombre de la BD local (en hosting
     compartido el host **no** suele ser `127.0.0.1`; cópialo del panel del proveedor).
   - `mail_config.php` → credenciales SMTP (Gmail con contraseña de aplicación u otro).
   - `admin_bootstrap.php` → correo real y clave inicial del admin.
   - Opcional: copia `app_config.example.php` → `app_config.php` para fijar `public_base_url`
     y los flags `features` (WhatsApp, bandejas, expertos, etc.). Si no existe el archivo, la base
     se infiere en cada petición y los módulos omitidos cuentan como activos. Tras iniciar sesión, el panel muestra las rutas en el acordeón **Rutas (landing y admin)**.

4. Crear la BD vacía en MySQL (con XAMPP/phpMyAdmin) con el nombre que
   pusiste en `db_config.php`. Por ejemplo:

   ```sql
   CREATE DATABASE web_personal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. Visitar `http://localhost/pag-template/admin.php` una vez. `db.php`
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

> **Nota:** La primera instalación suele ser esta carpeta `pag-template` a mano.
> Las **siguientes** landings en el mismo PC se crean con `provision.ps1` en
> `C:\xampp\htdocs\pagina-<slug>\` (esa carpeta **no** está dentro del git de
> `pag-template` salvo que tú inicialices allí otro repo; ver [Rol dual](#rol-dual-de-este-repo-template--instancia)).

## Configuración `app_config.php` y módulos

Opcional: copia `app_config.example.php` → `app_config.php` y ajusta. Si **no** existe `app_config.php`, la URL pública se **calcula en cada petición** y los módulos listados en `features` se consideran **activos** (comportamiento por defecto).

| Clave | Uso |
| --- | --- |
| `public_base_url` | URL base fija (sin `/` final). Útil en producción con proxy, subcarpeta o SSL terminado delante. Vacío = autodetección. |
| `log_public_base_url` | Si es `true`, escribe trazas de depuración de la URL resuelta en el log de PHP. Solo para diagnosticar; desactivar en producción. |
| `features.contact_whatsapp` | Muestra el botón «Escribir por WhatsApp» en el formulario de contacto y el flujo asociado. |
| `features.client_inbox` | Bandeja **Mis mensajes** en el área cliente y envíos enlazados (`send.php` con `return_anchor=area-cliente`). |
| `features.admin_inbox` | Acordeón **Mensajes** en el admin (agrupación por cliente o por correo, respuestas, SMTP al visitante según configuración). |
| `features.admin_whatsapp_clicks` | Acordeón **Clics WhatsApp** en el admin (registro de intenciones de contacto por WhatsApp). |
| `features.expert_agenda` | Acordeón **Expertos** en el admin (tablas `experts` / `expert_services`; alta/edición y listado). |

Los valores por defecto están en `app_config.example.php`. Cada landing provisionada **no** recibe tu `app_config.php` local (el script no lo copia); si la nueva instalación lo necesita, cópialo a mano desde el ejemplo.

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
C:\xampp\htdocs\pag-template\tools\provision.ps1 `
  -Slug "juan" `
  -AdminEmail "juan@correo.com" `
  -AdminPassword "CambiaEstaClave2026!"
```

Si haces **doble clic** o la consola se cierra sola al terminar, usa el
lanzador que deja el resultado visible y pide **Enter** al final (o `pause`
tras el script):

```text
C:\xampp\htdocs\pag-template\tools\provision.cmd -Slug "juan" -AdminEmail "juan@correo.com" -AdminPassword "CambiaEstaClave2026!"
```

Para **CI o scripts** donde no debe haber pausa al final, añade **`-NoWait`**
al `.ps1`.

Esto crea de un solo golpe:

- Carpeta `C:\xampp\htdocs\pagina-juan\` con **la misma aplicación** que el template: todo el código PHP, `partials/`, `tools/` (incl. provisioning y scripts como `seed_demo_conversations.php`), `styles.css`, `script.js`, `.github/workflows/`, `setup.sql`, `*.example.php`, etc.
- **No** se copian (se regeneran o son locales): `db_config.php`, `mail_config.php`, `admin_bootstrap.php`, `app_config.php` del template, carpetas `uploads/`, `.git/`, `var/` (logs locales), ni basura `*.log`, `*.bak`, `*.tmp`.
- Tras la copia se eliminan del destino los `*.sql` sueltos que hubiera en el template, **dejando solo** `setup.sql` (esquema de referencia).
- BD MySQL `pagina_juan` **vacía** (utf8mb4): el nombre de la BD usa el mismo
  *slug* con guion bajo (`pagina_<slug>`); la carpeta usa guion (`pagina-<slug>`).
  El esquema lo crea `db.php` al abrir `admin.php`.
- `db_config.php`, `mail_config.php`, `admin_bootstrap.php` generados dentro de
  la nueva carpeta (SMTP interactivo salvo `-NoSmtp`).
- Por defecto intenta abrir `http://localhost/pagina-juan/admin.php` para
  sembrar tablas y admin; con **`-SkipAutoSeed`** ese paso lo haces tú en el
  navegador.

Si la nueva landing necesita `app_config.php` (URL fija o flags de módulos), cópialo desde `app_config.example.php` en la carpeta creada y edítalo allí.

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
| `-Template "pagina-juan"` | Clona desde otra landing, no desde `pag-template`.         |
| `-DbHost / -DbUser / -DbPass` | Override de credenciales MySQL (default XAMPP).   |

### Lo que el script NO hace

- No sube archivos al hosting (el deploy lo hace GitHub Actions o FTP manual).
- No edita una landing existente (solo crea nuevas).
- No hace backup automático antes de `-Force`.

## Borrar una landing local con `deprovision.ps1`

Operación inversa de `provision.ps1`. Borra la carpeta `pagina-<slug>\` y
la BD `pagina_<slug>` de tu MySQL local. Útil para limpiar landings de
prueba sin dejar bases huérfanas.

```powershell
C:\xampp\htdocs\pag-template\tools\deprovision.ps1 -Slug "demo"
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
- Rechaza nombres reservados (`pag-template` no se puede borrar con este script).
- Verifica que la ruta resuelta esté dentro de `htdocs\` antes de tocar
  el filesystem.

## Despliegue a producción

`provision.ps1` no funciona en hosting compartido (sin shell, sin permisos
de `CREATE DATABASE` desde PHP). El **primer despliegue** de cada landing es
manual en el panel y por FTP; **GitHub Actions solo sube archivos** cuando
configuras el workflow: **no crea** cuentas, **no crea** la BD ni el
subdominio.

### Workflow FTP (`deploy.yml`)

En este repo el archivo **`.github/workflows/deploy.yml`** está versionado y
dispara el despliegue por FTP en cada `push` a `main` (y también con
**Run workflow** manual). Si tu copia **no** trae ese archivo (fork antiguo,
repo creado solo con archivos sueltos, o borraste `.github/` por error),
créalo tú:

1. En la raíz del proyecto, crea la ruta `.github/workflows/`.
2. Dentro, crea `deploy.yml` y **pega** el siguiente contenido (debe coincidir
   con el del repositorio; tras un `git pull` puedes copiarlo desde
   `.github/workflows/deploy.yml` en lugar de aquí).

```yaml
name: Deploy to InfinityFree

on:
  push:
    branches:
      - main
  workflow_dispatch:

jobs:
  ftp-deploy:
    name: FTP deploy
    runs-on: ubuntu-latest
    steps:
      - name: Detect FTP configuration
        id: ftp_check
        env:
          FTP_SERVER: ${{ secrets.FTP_SERVER }}
        run: |
          if [ -n "$FTP_SERVER" ]; then
            echo "configured=true" >> "$GITHUB_OUTPUT"
            echo "FTP_SERVER configurado; este repo desplegará al servidor."
          else
            echo "configured=false" >> "$GITHUB_OUTPUT"
            echo "FTP_SERVER no está configurado; modo plantilla, se omite el deploy."
          fi

      - name: Checkout
        if: steps.ftp_check.outputs.configured == 'true'
        uses: actions/checkout@v4

      - name: Sync via FTP
        if: steps.ftp_check.outputs.configured == 'true'
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.FTP_SERVER }}
          username: ${{ secrets.FTP_USERNAME }}
          password: ${{ secrets.FTP_PASSWORD }}
          # El valor del secret debe terminar en / (p. ej. htdocs/).
          server-dir: ${{ secrets.FTP_SERVER_DIR }}
          local-dir: ./
          dangerous-clean-slate: false
          exclude: |
            **/.git*
            **/.git*/**
            **/.github/**
            **/node_modules/**
            tools/**
            README.md
            mail_config.php
            db_config.php
            admin_bootstrap.php
            app_config.php
            *.log
            uploads/**
            *.sql
```

Luego configura en GitHub **Settings → Secrets and variables → Actions** los
secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` y `FTP_SERVER_DIR` como
se indica más abajo.

**`FTP_SERVER_DIR`:** debe ser una carpeta remota y **terminar en barra `/`**
(p. ej. `htdocs/` o `htdocs/mi-landing/`). Si falta la barra final, el action
`FTP-Deploy-Action` falla con *server-dir should be a folder (must end with /)*.

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
3. **En el servidor** (FTP o administrador de archivos del panel), crear o
   subir `db_config.php`, `mail_config.php` y `admin_bootstrap.php` con datos
   reales: el workflow **no** los sube desde el repo (están en `.gitignore` y en
   la lista `exclude` del deploy). Sin `db_config.php` en el servidor, la app
   intenta conectarse con los valores por defecto de XAMPP y suele responder
   **500**. Opcional: `app_config.php` con `public_base_url` si la URL canónica
   no se detecta bien.
4. Visitar `https://juan.tu-dominio.com/admin.php` una vez → creación de tablas
   y siembra del admin.
5. Iniciar sesión y borrar `admin_bootstrap.php` del servidor.

Si el repo tiene auto-deploy por FTP (`.github/workflows/deploy.yml`), cada
`push` a `main` **actualiza solo** el destino definido por esos secrets. Para
**varias landings** con multirepo, cada repo tiene **sus** secrets (a menudo
solo distinto `FTP_SERVER_DIR` si compartes el mismo FTP). Para un monorepo,
haría falta un workflow con matrix u otros jobs (no incluido por defecto).

## Panel admin (resumen)

Todo en **`admin.php`**. Los acordeones de **Herramientas** siguen un orden por responsabilidad (CSS `order`): **Configuración general** → **Credenciales** → **Rutas** → **Servicios** → **Expertos** (si `expert_agenda`) → **Portal de clientes**.

| Bloque | Contenido principal |
| --- | --- |
| **Rutas** | URLs públicas (landing, admin, área cliente), enlaces de prueba. |
| **Portal de clientes** | Tabla de cuentas: pastilla **Cuenta** (activo/inactivo) y pastilla **Correo SMTP** / **Solo web** son **botones**: muestran el estado y al pulsar alternan (POST con **flash + redirección** a `admin.php#admin-tool-clients` para que el acordeón se vuelva a abrir). **Eliminar** sigue aparte. Columna `email_notify_outbound`: si está desactivada, las respuestas del admin no intentan enviar copia SMTP al cliente (solo bandeja web). |
| **Servicios** | Catálogo, alta en panel desplegable (misma UI que edición vacía), carrusel/galería por servicio, iconos Font Awesome. |
| **Expertos** | Si el módulo está activo: listado, ficha, alta con formulario alineado a la ficha, vínculo `expert_services` ↔ `services`. |
| **Mensajes** | Si `admin_inbox`: hilos agrupados por `client_id` o por correo; respuestas; envío SMTP al visitante según `mail_config` y validaciones. |
| **Clics WhatsApp** | Si `admin_whatsapp_clicks`: registro de clics/intenciones. |
| **Configuración / Credenciales** | Textos del sitio, logo, admin de panel, etc. |

Vista amplia de bandeja: query `?inbox=1` en el admin (documentado en la propia UI).

## Cliente público, portal y mensajes

Cada landing puede tener **cuentas de cliente** (tabla `clients`). El visitante se **registra
e inicia sesión en la propia landing**, sección **Área de clientes** (`#area-cliente` en
`index.php`): misma página pública, con bloques extra cuando hay sesión (datos pre-rellenados en contacto, **Mis mensajes** si `client_inbox`, **seguimientos** con `in_reply_to` en `contact_messages`, respuestas del admin). Cookie y prefijo de sesión propios (`client_session_*` en `client_portal_lib.php`).

| Archivo / ruta | Uso |
| --- | --- |
| `index.php` + `#area-cliente` | Registro, login, mensajes (nueva consulta y seguimientos) y vista «modo cliente». |
| `client_inbox_helpers.php` | Incluido desde `index.php`: helpers de hilo / bandeja cliente (no endpoint directo). |
| `send.php` | Envío del formulario de contacto y seguimientos; puede volver con `return_anchor=area-cliente`. |
| `client_login.php` / `client_dashboard.php` | Redirigen a la landing (compatibilidad con enlaces antiguos). |
| `client_logout.php` | Cierra sesión de cliente y vuelve a la landing. |
| `client_portal_lib.php` | Sesión, registro, login, validaciones de clave. |

En el admin (**Mensajes**), las entradas se **agrupan por cliente** (`client_id`) o, si el mensaje no llevaba sesión de portal, **por correo** del visitante; dentro de cada grupo, envíos en orden temporal. Respuestas en `contact_message_replies`.

El administrador **no** crea usuarios a mano: modera en **Portal de clientes**. La URL del portal se obtiene con `app_client_portal_url()` (`app_urls.php`) y también aparece en **Rutas**.

Política de clave al registrarse: al menos **10 caracteres**, **mayúscula**, **minúscula** y **número**
(igual que la recuperación de clave del admin). Los clientes **no** tienen «olvidé mi clave» en esta versión.

Esquema `setup.sql` y demás: `db.php` en la primera carga; referencia SQL en `setup.sql` (núcleo de contenido y tablas principales; algunas tablas solo las garantiza `db.php`, p. ej. `admin_password_resets`).

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
pag-template/
├── admin.php                  Panel único: config, rutas, servicios, galerías, mensajes, portal clientes, expertos (flag), WhatsApp (flag), reset.
├── index.php                  Landing pública + #area-cliente (portal), contacto, carruseles.
├── send.php                   POST del formulario de contacto / seguimientos.
├── contact_click_log.php      Registro auxiliar de clics (según flujo).
├── app_urls.php               Resolución de URLs (respeta app_config opcional).
├── app_config.example.php     Plantilla de app_config (features, public_base_url).
├── db.php                     Conexión MySQL, migraciones ligeras y creación de tablas.
├── setup.sql                  Esquema de referencia + seed; import opcional (phpMyAdmin).
├── smtp_mail.php              Envío SMTP sin dependencias externas.
├── client_portal_lib.php      Sesión y registro del portal de clientes.
├── client_inbox_helpers.php   Helpers de bandeja/hilo cliente (require desde index).
├── client_login.php           Redirección a #area-cliente (compatibilidad).
├── client_dashboard.php       Redirección a #area-cliente (compatibilidad).
├── client_logout.php          Cierre de sesión cliente.
├── palette_picker.php         Selector de paleta (include desde admin).
├── partials/
│   └── service_carousel.php   Marca parcial carrusel de servicio (si aplica).
├── styles.css, script.js      Estilos y JS de la landing (y trozos usados por admin según página).
├── index.html                 Estático opcional en raíz (si lo usas en tu hosting).
├── tools/
│   ├── provision.ps1          Clona la misma app a pagina-<slug> + BD + configs.
│   ├── provision.cmd          Lanzador con pause.
│   ├── deprovision.ps1        Borra carpeta + BD local.
│   └── seed_demo_conversations.php  Utilidad local para datos demo (no producción).
├── .github/workflows/deploy.yml   CI FTP (excluye tools/, secretos, etc.).
├── *.example.php              Plantillas públicas de configuración.
├── db_config.php              [GITIGNORED] MySQL.
├── mail_config.php            [GITIGNORED] SMTP.
├── admin_bootstrap.php        [GITIGNORED] Primer admin (borrar tras login).
├── app_config.php             [GITIGNORED] Opcional: URL y features.
├── uploads/                   [GITIGNORED] Medios subidos desde admin.
├── var/                       [GITIGNORED] Logs locales (no se copia al provisionar).
└── README.md                  Este archivo.
```

## Base de datos (tablas)

Creadas o alineadas por **`db.php`** al cargar la app; `setup.sql` refleja el modelo para import manual. Relaciones importantes: `service_gallery.service_id` → `services.id`; `expert_services` → `experts` y `services`; respuestas → `contact_messages`.

| Tabla | Para qué |
| --- | --- |
| `admins` | Cuentas del panel (login admin). |
| `admin_password_resets` | Tokens hash de recuperación de clave admin. |
| `clients` | Cuentas del portal (`email`, hash, `display_name`, `is_active`, `email_notify_outbound`, …). |
| `client_registration_tokens` | Tokens de verificación en flujos de registro. |
| `site_settings` | Textos y datos globales del sitio (fila id=1). |
| `services` | Servicios ofertados (título, descripción, icono, imagen, orden, activo). |
| `service_gallery` | Imágenes del carrusel por servicio. |
| `contact_messages` | Mensajes y seguimientos; `client_id`, `in_reply_to`, flags de lectura y «respuesta no vista» cliente. |
| `contact_message_replies` | Cuerpo de respuestas del admin vinculadas a un mensaje. |
| `contact_whatsapp_clicks` | Registro de interacciones tipo WhatsApp (según módulo). |
| `experts` | Ficha de experto (nombre, contacto, notas, orden, activo). |
| `expert_services` | N:M expertos ↔ servicios. |

## Decisiones de arquitectura

**Single-tenant por instalación.** Cada landing es una carpeta + una BD
independiente. Pros: aislamiento total, mismo código simple, escalable
manualmente. Contras: no hay registro self-service de usuarios; cada
landing es provisionada por el dueño del proyecto.

**Portal de clientes:** varias filas en `clients` por landing; el alta la hace el visitante
en la landing y el admin solo modera. Sesión y rutas propias (`client_session_*`), sin mezclar
con el panel admin; sigue siendo single-tenant (un sitio por instalación).

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

## Plantilla y repos hijos

Este repositorio puede usarse en uno de dos roles, indistinguibles a nivel
de contenido pero distintos a nivel de configuración en GitHub:

1. **Repo plantilla** (canónico): la fuente de código común para todas las
   landings. **No tiene** secrets de FTP, por lo que `deploy.yml` se ejecuta
   pero detecta "FTP_SERVER no está configurado" y termina sin desplegar
   (modo plantilla, run verde sin acciones reales). Nunca apunta a un
   servidor concreto.
2. **Repo hijo** (instancia): copia del plantilla con sus propios secrets
   de FTP en *Settings > Secrets and variables > Actions*. Cada `push` a
   `main` despliega a **su** servidor. Cada landing real (Juan, Laura,
   Maria, …) tiene su propio repo hijo.

El **contenido versionado** es idéntico en ambos casos; lo único que cambia
es que el hijo añade los secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`
y `FTP_SERVER_DIR`, y que el workflow detecta esa diferencia automáticamente.

### Flujo de trabajo: template + hijos

Cada repo hijo añade el plantilla como remote `upstream`. Eso te permite:

- **Trabajo normal en una landing:** `git add … && git commit && git push`
  (a `origin`, que despliega a su servidor). El plantilla no se entera.
- **Propagar una mejora a varias landings:** la haces en el plantilla, push.
  Luego en cada hijo, **cuando tú decidas**:

  ```powershell
  git fetch upstream
  git log HEAD..upstream/main --oneline      # preview de qué traería
  git merge upstream/main
  git push                                   # despliega solo a este hijo
  ```

- **Llevar un parche puntual de una landing a otra** (cherry-pick entre
  hijos, p. ej. un fix que hiciste en Laura hace 20 días y ahora quieres en
  Juan):

  ```powershell
  cd pagina-juan
  git remote add laura https://github.com/<usuario>/<repo-de-laura>.git
  git fetch laura
  git cherry-pick <hash-del-commit>
  git push
  ```

| Operación                                       | Comando clave                                                          |
| ----------------------------------------------- | ---------------------------------------------------------------------- |
| Preview de cambios de la plantilla              | `git fetch upstream && git log HEAD..upstream/main --oneline`          |
| Aplicar todos los cambios de la plantilla       | `git merge upstream/main`                                              |
| Aplicar **un solo** commit de la plantilla      | `git fetch upstream && git cherry-pick <hash>`                         |
| Llevar un parche de otra landing                | `git remote add <nombre> <url> && git fetch <nombre> && git cherry-pick <hash>` |

Archivos que **nunca viajan** en estos merges (siguen en `.gitignore`):
`db_config.php`, `mail_config.php`, `admin_bootstrap.php`, `app_config.php`,
`uploads/`. La BD MySQL de cada hijo tampoco se toca: el contenido editable
desde el admin sigue siendo exclusivo de esa landing.

### Modelos para escalar a varias landings

| Modelo | Cómo se hace | Cuándo usarlo |
| ------ | ------------ | ------------- |
| **A — Plantilla + repo por landing** *(recomendado)* | Un repo plantilla canónico. Cada landing (`paginajuan`, `paginalaura`, …) tiene su propio repo hijo con secrets de FTP propios; se sincronizan con la plantilla vía `upstream`. | Caso por defecto. Mejoras propagables, cada landing despliega sola, parches cruzables con `cherry-pick`. |
| **B — Monorepo con matriz** | Un solo repo con `landings/juan/`, `landings/maria/`, etc. Workflow con `strategy: matrix` que despliega cada carpeta a su FTP_SERVER_DIR. | Si tienes muchas landings y prefieres una sola consola de CI. Requiere refactor del layout. |
| **C — Provision local + FTP manual** | `provision.ps1` crea `pagina-juan/` local; subes a hosting por FTP a mano. La carpeta no es repo. | Pruebas, demos rápidas, sandbox. No tiene CI ni historial. |

Cuando aparezca un cliente que necesite CI:

1. **En GitHub web**: crea un repo nuevo (`paginajuan`, vacío, sin README).
2. **En local**: provision con `provision.ps1`, luego desde `pagina-juan/`:

   ```powershell
   git init
   git remote add origin https://github.com/<usuario>/paginajuan.git
   git remote add upstream https://github.com/<usuario>/<repo-plantilla>.git
   git add -A
   git commit -m "Initial commit (clonado de plantilla)"
   git push -u origin main
   ```

3. **En el repo nuevo** (`paginajuan` en GitHub): añade los Secrets
   `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR` apuntando
   al hosting de Juan. El workflow detectará que `FTP_SERVER` ya está y
   desplegará en el siguiente push.
