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
- [Agenda y expertos](#agenda-y-expertos)
- [Cliente público, portal y mensajes](#cliente-público-portal-y-mensajes)
- [API REST (v1)](#api-rest-v1)
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
- Módulo **Agenda / expertos** (`features.expert_agenda`): catálogo de expertos, franjas de disponibilidad, reservas públicas en `agenda.php` (y sección en `index.php`). Ver [Agenda y expertos](#agenda-y-expertos) y [Panel admin](#panel-admin-resumen).

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
| `features.expert_agenda` | Expertos, disponibilidad, citas: admin (**Expertos**), landing (`#agenda` / `agenda.php`) y POST `agenda_book.php`. Tablas `experts`, `expert_services`, `expert_availability`, `expert_availability_date`, `expert_appointments`. |
| `features.agenda_notifications` | Correos al **confirmar** o **cancelar** una cita: visitante, `site_settings.contact_email` y correo del experto (si está en la ficha). Usa `mail_config.php` como el formulario de contacto. La reserva/cancelación en BD no depende del envío. |

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
| **Expertos** | Si `expert_agenda`: **tabla de expertos** siempre visible; botones **Horario** (plantilla semanal, tabla tipo cliente, excepciones por fecha, citas) y **Editar** (solo datos y servicios). Acordeones internos: agregar experto, jornada L–V masiva, agenda pública (mostrar nombres en `agenda.php`). |
| **Mensajes** | Si `admin_inbox`: hilos agrupados por `client_id` o por correo; respuestas; envío SMTP al visitante según `mail_config` y validaciones. |
| **Clics WhatsApp** | Si `admin_whatsapp_clicks`: registro de clics/intenciones. |
| **Configuración / Credenciales** | Textos del sitio, logo, admin de panel, etc. |

Vista amplia de bandeja: query `?inbox=1` en el admin (documentado en la propia UI).

URLs de ficha experto (query en `admin.php`): `expert_id`, `expert_view=edit|schedule`, opcional `expert_week` para la tabla semanal.

## Agenda y expertos

Requiere `features.expert_agenda` en `app_config.php` (por defecto desactivado en `app_config.example.php`; actívalo en tu `app_config.php` local).

### Visitante (landing)

| Ruta / archivo | Uso |
| --- | --- |
| `index.php#agenda` | Sección **Solicitar cita** embebida (filtro servicio + fecha, tabla de huecos). |
| `agenda.php` | Página dedicada con la misma UI y formulario de reserva. |
| `agenda_book.php` | POST: valida huecos contiguos (mismo experto), crea fila en `expert_appointments`, redirige con flash. |

Comportamiento público:

- Por defecto la tabla es **anónima** (solo horarios); en admin puedes activar **Mostrar nombre del experto** (`site_settings.agenda_show_expert_names`) para columnas por profesional.
- Bloques de **30 minutos**; el visitante puede marcar **varios huecos seguidos** del mismo experto en una sola reserva.
- Tras elegir huecos, el panel **Detalle de la cita** muestra servicio, día, franja y nombre del experto.

### Admin

- **Plantilla semanal** por experto (franjas L–V u horario personalizado por día).
- **Excepciones por fecha** (día cerrado o franjas que sustituyen la plantilla ese día).
- **Agenda semanal** (vista tipo cliente: filas = hora, columnas = días).
- **Próximas citas** con opción de cancelar desde la ficha de horario.

### Notificaciones de citas (panel + correo)

Con `features.agenda_notifications` activo (por defecto si no se define la clave), cada reserva o cancelación genera registros en `agenda_notification_deliveries`:

| Canal | Quién lo ve | Reserva / cancelación |
| --- | --- | --- |
| **Panel admin** (`in_app_admin`) | Acordeón «Avisos de agenda» en Expertos | Siempre (aunque el visitante no tenga cuenta) |
| **Área cliente** (`in_app_client`) | Bloque «Avisos de citas» si hay `client_id` o cuenta con el mismo correo | Solo si la cita quedó vinculada a un cliente |
| **Correo** | Visitante, `contact_email` del sitio, experto (si tiene email) | Según validez del correo y preferencias del cliente |

Cada intento queda con **estado**: `delivered` (en panel o correo enviado), `skipped` (sin correo válido, sin cuenta, experto sin email, etc.) o `failed` (SMTP). En el admin, bajo cada cita aparece el desplegable **Registro de notificaciones**.

La reserva exige **correo válido o teléfono** (mín. 6 caracteres) para no perder contacto. Si el visitante usa un correo ya registrado, la cita se enlaza al `client_id` y verá avisos al iniciar sesión.

Lógica en `agenda_notifications_lib.php` (`agenda_service_create_booking()`, `experts_admin_cancel_appointment()`). Trazas: `contact_send_trace.log` (`agenda_notify:`).

Lógica compartida en `agenda_lib.php`; UI pública en `partials/agenda_public_section.php` (incluida desde `index.php` y `agenda.php`).

## Cliente público, portal y mensajes

Cada landing puede tener **cuentas de cliente** (tabla `clients`). El visitante se **registra
e inicia sesión en la propia landing**, sección **Área de clientes** (`#area-cliente` en
`index.php`): misma página pública, con bloques extra cuando hay sesión (datos pre-rellenados en contacto, **Mis mensajes** si `client_inbox`, **seguimientos** con `in_reply_to` en `contact_messages`, respuestas del admin). Cookie y prefijo de sesión propios (`client_session_*` en `client_portal_lib.php`).

| Archivo / ruta | Uso |
| --- | --- |
| `index.php` + `#area-cliente` | Registro, login, mensajes (nueva consulta y seguimientos) y vista «modo cliente». |
| `client_inbox_helpers.php` | Incluido desde `index.php`: helpers de hilo / bandeja cliente (no endpoint directo). |
| `send.php` | Adaptador HTTP (POST → redirect); delega en `contact_service.php`. |
| `contact_lib.php` / `contact_service.php` | Dominio y caso de uso del formulario de contacto. |
| `client_service.php` | Auth y bandeja del portal (API + reutilizable desde la landing). |
| `api/v1/contact/messages.php` | Mismo envío que `send.php`, respuesta JSON (`app_contact_messages_api_url()`). |
| `api/v1/auth/*`, `api/v1/client/*` | Sesión, login, registro y mensajes del cliente. |
| `client_login.php` / `client_dashboard.php` | Redirigen a la landing (compatibilidad con enlaces antiguos). |
| `client_logout.php` | Cierra sesión de cliente y vuelve a la landing. |
| `client_portal_lib.php` | Sesión, registro, login, validaciones de clave. |

En el admin (**Mensajes**), las entradas se **agrupan por cliente** (`client_id`) o, si el mensaje no llevaba sesión de portal, **por correo** del visitante; dentro de cada grupo, envíos en orden temporal. Respuestas en `contact_message_replies`.

El administrador **no** crea usuarios a mano: modera en **Portal de clientes**. La URL del portal se obtiene con `app_client_portal_url()` (`app_urls.php`) y también aparece en **Rutas**.

Política de clave al registrarse: al menos **10 caracteres**, **mayúscula**, **minúscula** y **número**
(igual que la recuperación de clave del admin). Los clientes **no** tienen «olvidé mi clave» en esta versión.

Esquema `setup.sql` y demás: `db.php` en la primera carga; referencia SQL en `setup.sql` (núcleo de contenido y tablas principales; algunas tablas solo las garantiza `db.php`, p. ej. `admin_password_resets`).

## API REST (v1)

Capa API JSON compartida (`api/bootstrap.php`). Cada módulo tiene `*_service.php` y endpoints bajo `api/v1/`.

| Recurso | Método | URL (relativa a la carpeta de la landing) |
| --- | --- | --- |
| Mensajes de contacto | `POST` | `api/v1/contact/messages.php` |
| Huecos de agenda | `GET` | `api/v1/agenda/slots.php` |
| Reservas de agenda | `POST` | `api/v1/agenda/bookings.php` |
| Sesión cliente | `GET` | `api/v1/auth/session.php` |
| Login cliente | `POST` | `api/v1/auth/login.php` |
| Logout cliente | `POST` | `api/v1/auth/logout.php` |
| Registro cliente | `POST` | `api/v1/auth/register.php` |
| Confirmar registro (token email) | `POST` | `api/v1/auth/register-confirm.php` |
| Registro sin correo | `POST` | `api/v1/auth/register-finalize.php` |
| Bandeja mensajes | `GET` | `api/v1/client/messages.php` |
| Poll bandeja | `GET` | `api/v1/client/inbox-poll.php` |
| Sesión admin | `GET` | `api/v1/admin/auth/session.php` |
| Login admin | `POST` | `api/v1/admin/auth/login.php` |
| Logout admin | `POST` | `api/v1/admin/auth/logout.php` |
| Recuperar clave admin | `POST` | `api/v1/admin/auth/password-reset-request.php` |
| Nueva clave admin (token) | `POST` | `api/v1/admin/auth/password-reset.php` |

URLs absolutas: helpers `app_*_api_url()` en `app_urls.php` (contacto, agenda, portal, admin).

**SPA web y app móvil:** hoy la API usa la **misma cookie de sesión** que `admin.php` / `index.php` (`credentials: "same-origin"` en un front React/Vue servido desde la misma carpeta o dominio). Una app nativa en otro origen suele necesitar después **tokens** (Bearer); el diseño por servicios deja ese paso sin reescribir reglas de negocio.

Requiere `features.expert_agenda` para agenda (403 `feature_disabled`). La bandeja exige `features.client_inbox` y cookie de sesión (`credentials: "same-origin"`).

### Contacto

URL: `app_contact_messages_api_url()`.

**Cuerpo:** `application/json` o `application/x-www-form-urlencoded` (mismos campos que el formulario).

| Campo | Obligatorio | Notas |
| --- | --- | --- |
| `nombre` | Sí | |
| `email` | Sí | En seguimiento (`in_reply_to`) se toma de la sesión. |
| `servicio` | Sí | En seguimiento se hereda del hilo. |
| `mensaje` | Sí | |
| `asunto` | Sí* | *No si `in_reply_to` > 0 (asunto del hilo). |
| `in_reply_to` | No | Seguimiento; requiere cookie de sesión de cliente. |
| `return_anchor` | No | `area-cliente` exige `features.client_inbox` (igual que `send.php`). |

**Respuesta exitosa (201):**

```json
{
  "ok": true,
  "data": {
    "message_id": 42,
    "outcome": "ok",
    "mail_sent": true
  }
}
```

`outcome`: `ok` si el correo al admin se envió; `saved` si el mensaje quedó en BD pero el mail falló (mismo criterio que el redirect `status=saved`).

**Errores:** `{ "ok": false, "error": "nombre", "fields": ["nombre", "..."] }` con códigos HTTP 400 (validación), 401 (`sesion_seguimiento`), 403 (`client_inbox_disabled`), 500 (`db_insert`).

**Sesión:** las peticiones con `credentials: "same-origin"` reutilizan la cookie del portal para seguimientos y para asociar `client_id` cuando el email coincide con la sesión.

**Ejemplo `curl` (local, carpeta `pag-template`):**

```bash
curl -X POST "http://localhost/pag-template/api/v1/contact/messages.php" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"nombre\":\"Ana\",\"email\":\"ana@ejemplo.com\",\"servicio\":\"Consulta\",\"asunto\":\"Desde API\",\"mensaje\":\"Hola\"}"
```

Archivos: `contact_lib.php`, `contact_service.php`.

### Agenda

**GET huecos** — query: `service_id` (o `agenda_service`), `date` (o `agenda_date`, `Y-m-d`).

Respuesta (`200`): `data` con `bookable_services`, `slots` (cada uno incluye `slot_token` = `expertoId@YYYY-MM-DD HH:MM:SS`), `table` (misma estructura que la UI), `min_date`, `max_date`, `slot_minutes`, `max_slot_units`.

**Ejemplo de respuesta GET (recortado):**

```json
{
  "ok": true,
  "data": {
    "service_id": 1,
    "service_title": "Asesoría",
    "date": "2026-05-18",
    "min_date": "2026-05-15",
    "max_date": "2026-08-13",
    "show_expert_names": true,
    "slot_minutes": 30,
    "max_slot_units": 16,
    "bookable_services": [
      { "id": 1, "title": "Asesoría", "icon_class": "fa-solid fa-comments" }
    ],
    "slots": [
      {
        "expert_id": 2,
        "display_name": "María López",
        "starts": "2026-05-18 09:00:00",
        "ends": "2026-05-18 09:30:00",
        "label": "09:00–09:30",
        "slot_token": "2@2026-05-18 09:00:00"
      }
    ],
    "table": {
      "layout": "by_expert",
      "experts": { "2": "María López" },
      "expert_order": [2],
      "rows": [],
      "show_expert_names": true
    }
  }
}
```

El objeto `table` replica la grilla de la landing (`by_expert` o `by_time`); en el ejemplo, `rows` está vacío por brevedad — en producción trae las filas horarias y celdas como en `partials/agenda_public_section.php`.

**POST reserva** — JSON o form; requiere `features.expert_agenda`.

| Campo | Obligatorio | Notas |
| --- | --- | --- |
| `service_id` | Sí | También `agenda_service_id` en formularios HTML. |
| `slot_token` | Sí* | Formato `expertId@starts_at`; también `agenda_slot`. |
| `expert_id` + `starts_at` | Sí* | Alternativa a `slot_token`. |
| `guest_name`, `guest_email` | Sí | |
| `guest_phone`, `notes` | No | |
| `slot_units` | No | Por defecto 1; máx. `AGENDA_MAX_SLOT_UNITS`. |

Respuesta exitosa (**201**):

```json
{
  "ok": true,
  "data": {
    "appointment_id": 15,
    "service_id": 1,
    "expert_id": 2,
    "starts_at": "2026-05-18 09:00:00",
    "ends_at": "2026-05-18 09:30:00",
    "slot_units": 1
  }
}
```

Errores: `message` en español (texto de `agenda_lib`); `error` suele ser `booking_rejected`. HTTP **409** si el hueco ya no está libre.

**Ejemplos `curl` (local):**

```bash
# 1) Listar huecos de un servicio y día
curl -s "http://localhost/pag-template/api/v1/agenda/slots.php?service_id=1&date=2026-05-18" \
  -H "Accept: application/json"

# 2) Reservar usando slot_token devuelto en slots[]
curl -X POST "http://localhost/pag-template/api/v1/agenda/bookings.php" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"service_id\":1,\"slot_token\":\"2@2026-05-18 09:00:00\",\"guest_name\":\"Ana\",\"guest_email\":\"ana@ejemplo.com\",\"guest_phone\":\"\",\"notes\":\"\",\"slot_units\":1}"
```

Con sesión de cliente (cookie del portal), la reserva puede asociar `client_id` automáticamente si el correo coincide. En `fetch` del navegador usa `credentials: "same-origin"`.

`agenda_book.php` delega en `agenda_service_create_booking()` (mismo criterio que la API).

Archivos: `agenda_service.php`, `agenda_lib.php`, `agenda_notifications_lib.php`, `agenda_public_bootstrap.php`.

### Portal de clientes (auth + bandeja)

Misma cookie de sesión que `index.php#area-cliente` (`client_session_*`). En `fetch` usa siempre `credentials: "same-origin"` para enviar la cookie.

**GET sesión** (`app_client_auth_session_api_url()`):

```json
{
  "ok": true,
  "data": {
    "authenticated": true,
    "user": { "id": 3, "email": "cliente@ejemplo.com", "display_name": "Ana" }
  }
}
```

Si no hay sesión: `"authenticated": false`. Si hay registro pendiente de verificación: `"registration_pending": true`, `"pending_email"`, `"verification_sent"`.

| Endpoint | Campos principales |
| --- | --- |
| `POST auth/login.php` | `email`, `password` → `data.user` |
| `POST auth/logout.php` | — |
| `POST auth/register.php` | `email`, `password`, `password_confirm`, `display_name` → **202** `awaiting_verification` (correo con enlace 48 h) |
| `POST auth/register-confirm.php` | `token` (del enlace) → sesión abierta |
| `POST auth/register-finalize.php` | Sin cuerpo; requiere `client_reg_pending` en sesión tras fallo SMTP → **201** |
| `GET client/messages.php` | Sesión + `client_inbox`; query `limit` (default 40) |
| `GET client/inbox-poll.php` | Sesión; `site_unseen_total`, `max_reply_id`, `threads_site_unseen` |

**Ejemplo `curl` con cookie jar:**

```bash
# Login (guarda cookie)
curl -c cookies.txt -X POST "http://localhost/pag-template/api/v1/auth/login.php" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"TU_CLIENTE@ejemplo.com\",\"password\":\"TuClave2026!\"}"

# Sesión
curl -b cookies.txt "http://localhost/pag-template/api/v1/auth/session.php" -H "Accept: application/json"

# Bandeja
curl -b cookies.txt "http://localhost/pag-template/api/v1/client/messages.php" -H "Accept: application/json"

# Poll (sustituye index.php?client_inbox_poll=1)
curl -b cookies.txt "http://localhost/pag-template/api/v1/client/inbox-poll.php" -H "Accept: application/json"
```

Errores habituales: **401** `no_session`, **403** `feature_disabled` (bandeja), **401** `invalid_credentials` (login).

Archivos: `client_service.php`, `client_portal_lib.php`, `client_inbox_helpers.php`.

Pruebas locales: `php tools/test_contact_api.php`, `php tools/test_agenda_api.php`, `php tools/test_client_api.php`, `php tools/test_admin_api.php`, `php tools/test_admin_messages_api.php`, `php tools/test_admin_settings_api.php`, `php tools/test_admin_services_api.php`, `php tools/test_admin_experts_api.php`, `php tools/test_admin_portal_api.php`.

### Admin (fase 4 — en curso)

**4.1 Auth** (implementado): `admin_portal_lib.php`, `admin_service.php`, endpoints bajo `api/v1/admin/auth/`. El panel `admin.php` delega login, logout y recuperación de clave.

```bash
curl -c admin-cookies.txt -X POST "http://localhost/pag-template/api/v1/admin/auth/login.php" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"TU_ADMIN\",\"password\":\"TU_CLAVE\"}"

curl -b admin-cookies.txt "http://localhost/pag-template/api/v1/admin/auth/session.php"
```

Prueba: `php tools/test_admin_api.php tu@admin.com TuClave`.

**4.2 Mensajes** (implementado): `admin_inbox_lib.php`, `admin_messages_service.php`, endpoints bajo `api/v1/admin/messages*`. El panel `admin.php` carga la bandeja con `admin_inbox_load()` y delega marcar leído, borrar y responder.

Misma cookie de sesión que `admin.php` (`admin_session_*`). En `fetch` usa `credentials: "same-origin"`.

| Endpoint | Método | Campos / notas |
| --- | --- | --- |
| `admin/messages.php` | GET | `limit` (default 100) → `counts`, `messages`, `replies`, `groups` |
| `admin/messages.php?id=` | GET | `message`, `replies` |
| `admin/messages/read.php` | POST | `message_id`, `read` (default true) |
| `admin/messages/read-all.php` | POST | `read` (default true) |
| `admin/messages/delete.php` | POST | `message_id` |
| `admin/messages/reply.php` | POST | `message_id`, `body` (o `reply_body`) → **201** |

Helpers en `app_urls.php`: `app_admin_messages_api_url()`, `app_admin_messages_read_api_url()`, etc.

```bash
curl -c admin-cookies.txt -X POST "http://localhost/pag-template/api/v1/admin/auth/login.php" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"TU_ADMIN\",\"password\":\"TU_CLAVE\"}"

curl -b admin-cookies.txt "http://localhost/pag-template/api/v1/admin/messages.php" -H "Accept: application/json"

curl -b admin-cookies.txt -X POST "http://localhost/pag-template/api/v1/admin/messages/read.php" \
  -H "Content-Type: application/json" \
  -d "{\"message_id\":1,\"read\":true}"
```

Prueba: `php tools/test_admin_messages_api.php tu@admin.com TuClave`.

**4.3 Ajustes del sitio** (implementado): `site_settings_lib.php`, `upload_image_lib.php`, `admin_settings_service.php`, endpoints bajo `api/v1/admin/settings*`. El panel `admin.php` delega guardado general, logo y opción de agenda pública.

| Endpoint | Método | Notas |
| --- | --- | --- |
| `admin/settings.php` | GET | Textos, correo, WhatsApp, logo, `agenda_show_expert_names` |
| `admin/settings.php` | PUT / PATCH | Mismos campos en JSON (`contact_whatsapp_country` + `contact_whatsapp_local` o columnas directas) |
| `admin/settings/logo.php` | POST | multipart `logo_image_file`; `remove_logo=true` |
| `admin/settings/agenda-display.php` | POST | `agenda_show_expert_names` (bool) |

Helpers: `app_admin_settings_api_url()`, `app_admin_settings_logo_api_url()`, `app_admin_settings_agenda_display_api_url()`.

```bash
curl -b admin-cookies.txt "http://localhost/pag-template/api/v1/admin/settings.php" -H "Accept: application/json"

curl -b admin-cookies.txt -X PUT "http://localhost/pag-template/api/v1/admin/settings.php" \
  -H "Content-Type: application/json" \
  -d @settings-payload.json
```

Prueba: `php tools/test_admin_settings_api.php tu@admin.com TuClave`.

**4.4 Servicios y galería** (implementado): `services_lib.php`, `admin_services_service.php`, endpoints bajo `api/v1/admin/services*`. El panel `admin.php` delega alta, guardado masivo y borrado.

| Endpoint | Método | Notas |
| --- | --- | --- |
| `admin/services.php` | GET | Lista con `gallery` por servicio |
| `admin/services.php?id=` | GET / PUT / DELETE | Detalle, actualizar JSON, borrar |
| `admin/services.php` | POST | Crear servicio (JSON; imagen en `services/image.php`) |
| `admin/services/image.php` | POST | multipart `service_id`, `image_file` |
| `admin/services/gallery.php` | POST | multipart añadir imagen a galería |
| `admin/services/gallery.php?id=` | PUT / DELETE | Metadatos o borrar imagen |
| `admin/services/gallery/reorder.php` | POST | `service_id`, `ordered_ids` |

Helpers: `app_admin_services_api_url()`, `app_admin_services_image_api_url()`, `app_admin_services_gallery_api_url()`, `app_admin_services_gallery_reorder_api_url()`.

Prueba: `php tools/test_admin_services_api.php tu@admin.com TuClave`.

**4.5 Expertos y agenda** (implementado): `experts_admin_lib.php`, `admin_experts_service.php`, endpoints bajo `api/v1/admin/experts*`. Requiere `features.expert_agenda` en `app_config.php`. El panel `admin.php` delega CRUD, disponibilidad, excepciones por fecha y cancelación de citas.

| Endpoint | Método | Notas |
| --- | --- | --- |
| `admin/experts.php` | GET | Lista de expertos con `service_ids` |
| `admin/experts.php?id=` | GET | Detalle; `?include=schedule&week=` incluye grilla semanal |
| `admin/experts.php` | POST | Crear (`service_ids`, jornada L–V por defecto) |
| `admin/experts.php?id=` | PUT / DELETE | Actualizar / borrar |
| `admin/experts/week-grid.php` | GET | `expert_id`, `week_start` |
| `admin/experts/availability.php` | POST / DELETE | Franja semanal |
| `admin/experts/availability/mon-fri.php` | POST | Jornada L–V de un experto |
| `admin/experts/availability/bulk-mon-fri.php` | POST | L–V para todos |
| `admin/experts/availability-date.php` | POST / DELETE | Excepción por fecha (`mode`: `closed` \| `window`) |
| `admin/experts/appointments/cancel.php` | POST | `expert_id`, `appointment_id` |

Helpers: `app_admin_experts_api_url()`, `app_admin_experts_week_grid_api_url()`, etc.

Prueba: `php tools/test_admin_experts_api.php tu@admin.com TuClave` (con `expert_agenda` activo).

**4.6 Portal clientes y WhatsApp** (implementado): `admin_clients_lib.php`, `admin_whatsapp_lib.php`, `admin_portal_service.php`, endpoints bajo `api/v1/admin/clients*` y `whatsapp-clicks*`.

| Endpoint | Método | Notas |
| --- | --- | --- |
| `admin/clients.php` | GET | Lista de cuentas del portal |
| `admin/clients.php?id=` | GET / DELETE | Detalle / eliminar |
| `admin/clients/toggle-active.php` | POST | `client_id` |
| `admin/clients/toggle-email-notify.php` | POST | Alternar envío SMTP al cliente |
| `admin/whatsapp-clicks.php` | GET | `counts` + `clicks` (requiere `admin_whatsapp_clicks`) |
| `admin/whatsapp-clicks.php?id=` | GET / DELETE | Detalle / borrar |
| `admin/whatsapp-clicks/read.php` | POST | `click_id`, `read` |
| `admin/whatsapp-clicks/read-all.php` | POST | Marcar todos leídos/no leídos |

Helpers: `app_admin_clients_api_url()`, `app_admin_whatsapp_clicks_api_url()`, etc.

Prueba: `php tools/test_admin_portal_api.php tu@admin.com TuClave`.

**Fase 4 admin API:** completa. Siguiente paso natural: front SPA consumiendo estos endpoints con `credentials: "same-origin"`.

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
├── admin.php                  Panel único (HTML); auth e inbox vía libs/servicios.
├── admin_portal_lib.php       Sesión, login y recuperación de clave admin.
├── admin_service.php          Casos de uso admin (auth).
├── admin_inbox_lib.php        Bandeja de contacto admin (carga, leer, responder).
├── admin_messages_service.php Casos de uso bandeja admin (API).
├── site_settings_lib.php      Configuración global del sitio (lectura/escritura).
├── admin_settings_service.php Casos de uso ajustes admin (API).
├── services_lib.php           Servicios y galería (CRUD, batch admin).
├── admin_services_service.php Casos de uso servicios admin (API).
├── experts_admin_lib.php      Expertos, disponibilidad y citas (admin).
├── admin_experts_service.php  Casos de uso expertos admin (API).
├── admin_clients_lib.php      Cuentas del portal de clientes (admin).
├── admin_whatsapp_lib.php     Clics WhatsApp en admin.
├── admin_portal_service.php   Casos de uso clientes + WhatsApp (API).
├── upload_image_lib.php       Subida de imágenes a uploads/ (logo, servicios).
├── index.php                  Landing pública + #area-cliente (portal), contacto, carruseles, #agenda (flag).
├── agenda.php                 Reserva de citas (página dedicada; flag expert_agenda).
├── agenda_book.php            Adaptador POST reserva → redirect (usa agenda_service).
├── agenda_service.php         Casos de uso agenda (huecos, reserva).
├── agenda_lib.php             Disponibilidad, huecos, tabla pública, grilla semanal admin.
├── agenda_notifications_lib.php  Correos reserva/cancelación de citas (SMTP como contacto).
├── agenda_public_bootstrap.php  Datos de agenda para index/agenda (require).
├── send.php                   Adaptador POST contacto → redirect (usa contact_service).
├── contact_lib.php            Dominio contacto (BD, mail, validación).
├── contact_service.php        Caso de uso contact_service_submit().
├── api/
│   ├── bootstrap.php          Respuestas JSON y lectura de cuerpo.
│   └── v1/
│       ├── contact/messages.php   POST API mensajes de contacto.
│       ├── agenda/
│       │   ├── slots.php          GET API huecos.
│       │   └── bookings.php       POST API reservas.
│       ├── auth/
│       │   ├── session.php        GET sesión cliente.
│       │   ├── login.php          POST login.
│       │   ├── logout.php         POST logout.
│       │   ├── register.php       POST registro (correo verificación).
│       │   ├── register-confirm.php POST token del email.
│       │   └── register-finalize.php POST cuenta sin correo.
│       ├── client/
│       │   ├── messages.php       GET bandeja.
│       │   └── inbox-poll.php     GET poll no leídos.
│       └── admin/
│           ├── auth/
│           │   ├── session.php        GET sesión admin.
│           │   ├── login.php          POST login.
│           │   ├── logout.php         POST logout.
│           │   ├── password-reset-request.php
│           │   └── password-reset.php
│           ├── messages.php           GET lista o detalle (?id=).
│           ├── messages/
│           │   ├── read.php           POST marcar leído/no leído.
│           │   ├── read-all.php       POST marcar todos.
│           │   ├── delete.php         POST borrar mensaje.
│           │   └── reply.php          POST responder.
│           ├── settings.php           GET / PUT configuración general.
│           ├── settings/
│           │   ├── logo.php           POST logo (multipart).
│           │   └── agenda-display.php POST mostrar nombre experto en agenda.
│           ├── services.php           GET / POST / PUT / DELETE servicios.
│           └── services/
│               ├── image.php          POST imagen principal del servicio.
│               ├── gallery.php        POST / PUT / DELETE galería.
│               └── gallery/reorder.php POST orden de imágenes.
│           ├── experts.php            GET / POST / PUT / DELETE expertos.
│           └── experts/
│               ├── week-grid.php      GET grilla semanal.
│               ├── availability.php   POST / DELETE franja semanal.
│               ├── availability-date.php POST / DELETE excepción por fecha.
│               ├── availability/mon-fri.php POST L–V un experto.
│               ├── availability/bulk-mon-fri.php POST L–V todos.
│               └── appointments/cancel.php POST cancelar cita.
│           ├── clients.php            GET / DELETE clientes portal.
│           ├── clients/toggle-active.php POST activar/desactivar cuenta.
│           ├── clients/toggle-email-notify.php POST preferencia SMTP.
│           ├── whatsapp-clicks.php    GET / DELETE clics WhatsApp.
│           └── whatsapp-clicks/
│               ├── read.php           POST marcar leído.
│               └── read-all.php       POST marcar todos.
├── contact_click_log.php      Registro auxiliar de clics (según flujo).
├── app_urls.php               Resolución de URLs (respeta app_config opcional).
├── app_config.example.php     Plantilla de app_config (features, public_base_url).
├── db.php                     Conexión MySQL, migraciones ligeras y creación de tablas.
├── setup.sql                  Esquema de referencia + seed; import opcional (phpMyAdmin).
├── smtp_mail.php              Envío SMTP sin dependencias externas.
├── client_portal_lib.php      Sesión y registro del portal de clientes.
├── client_service.php         Casos de uso auth + bandeja (API).
├── client_inbox_helpers.php   Helpers de bandeja/hilo cliente (require desde index).
├── client_login.php           Redirección a #area-cliente (compatibilidad).
├── client_dashboard.php       Redirección a #area-cliente (compatibilidad).
├── client_logout.php          Cierre de sesión cliente.
├── palette_picker.php         Selector de paleta (include desde admin).
├── partials/
│   ├── service_carousel.php       Carrusel de servicio (si aplica).
│   ├── agenda_public_section.php  Tabla de huecos + formulario de reserva (cliente).
│   ├── admin_experts_table.php    Listado de expertos en admin.
│   ├── admin_experts_accordions.php  Acordeones: alta, L–V masivo, agenda pública.
│   ├── admin_expert_edit_panel.php   Solo datos del experto.
│   ├── admin_expert_schedule_panel.php  Horario, plantilla, citas.
│   └── admin_expert_week_grid.php    Tabla semanal admin (tipo cliente).
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
| `site_settings` | Textos y datos globales del sitio (fila id=1); incluye `agenda_show_expert_names` (mostrar experto en agenda pública). |
| `services` | Servicios ofertados (título, descripción, icono, imagen, orden, activo). |
| `service_gallery` | Imágenes del carrusel por servicio. |
| `contact_messages` | Mensajes y seguimientos; `client_id`, `in_reply_to`, flags de lectura y «respuesta no vista» cliente. |
| `contact_message_replies` | Cuerpo de respuestas del admin vinculadas a un mensaje. |
| `contact_whatsapp_clicks` | Registro de interacciones tipo WhatsApp (según módulo). |
| `experts` | Ficha de experto (nombre, contacto, notas, orden, activo). |
| `expert_services` | N:M expertos ↔ servicios. |
| `expert_availability` | Plantilla semanal (día de la semana + franja horaria). |
| `expert_availability_date` | Excepción por fecha concreta (cerrado o franjas). |
| `expert_appointments` | Citas reservadas (experto, servicio, invitado, estado). |

## Decisiones de arquitectura

**Single-tenant por instalación.** Cada landing es una carpeta + una BD
independiente. Pros: aislamiento total, mismo código simple, escalable
manualmente. Contras: no hay registro self-service de usuarios; cada
landing es provisionada por el dueño del proyecto.

**Portal de clientes:** varias filas en `clients` por landing; el alta la hace el visitante
en la landing y el admin solo modera. Sesión y rutas propias (`client_session_*`), sin mezclar
con el panel admin; sigue siendo single-tenant (un sitio por instalación).

**Capa API:** contacto, agenda pública, portal cliente y **panel admin** (auth, bandeja, ajustes, servicios, expertos, clientes, WhatsApp) exponen JSON v1. Listo para un front SPA o app móvil con la misma cookie de sesión admin (`credentials: "same-origin"`).

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
