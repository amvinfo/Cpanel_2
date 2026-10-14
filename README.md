# Mesa de Ayuda / Helpdesk — Gobierno de Chile

Sistema de tickets con Portal Ciudadano, Dashboard interno e **instalador automático** (`install.php`).

## Instalación rápida en cPanel (3 pasos)

1. **Base de datos:** En cPanel → *Bases de datos MySQL*, cree la BD y un usuario, y asígnele *Todos los privilegios*.
2. **Archivos:** Suba todo el contenido a `public_html` (Administrador de Archivos o FTP) y abra en el navegador:
   `https://midominio.cl/install.php` → complete el formulario y pulse **🚀 Instalar / Ejecutar**.
   El instalador genera `config.php`, crea las tablas (usa `schema.sql` si está presente, o su esquema interno autónomo), inserta datos semilla/demo, crea `uploads/` y escribe `instalado.lock`.
3. **Seguridad:** Elimine `install.php` (o use el botón *🔒 Bloquear install.php (.htaccess)* de la pantalla de éxito) y entre al panel.

> Alternativa manual: importar `schema.sql` en phpMyAdmin y editar `config.php` a mano.

## Credenciales por defecto
| Rol        | Usuario      | Clave     |
|------------|--------------|-----------|
| Admin      | `admin`      | `admin123`|
| Supervisor | `supervisor` | `admin123`|
| Técnico    | `tecnico`    | `admin123`|

*(El instalador permite fijar una clave única distinta para los tres durante la instalación.)*

## Estructura
```
index.php            Portal ciudadano (SPA, flujos inline)
login.php / dashboard.php   Panel de funcionarios
api/public.php       Endpoints sin sesión (crear/consultar ticket, categorías)
api/panel.php        Endpoints con sesión (login, estadísticas, gestión)
assets/css/gobcl.css Kit Digital GC (azul #002B49/#0039A6, rojo #D52B1E)
assets/js/*.js       portal.js · dashboard.js (Fetch API)
install.php          Instalador web completo (requisitos + BD + seeders)
schema.sql           Esquema MySQL + datos semilla (phpMyAdmin)
config.php           Configuración BD (la genera install.php)
uploads/             Adjuntos de tickets (creada por el instalador, 0755)
```

## Requisitos
PHP ≥ 7.4 con `pdo_mysql` (mbstring recomendado). Requiere escritura sobre `config.php` y `uploads/`.
