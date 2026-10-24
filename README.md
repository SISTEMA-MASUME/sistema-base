# Sistema base (ejemplo)

Sistema administrativo para un distribuidor de ferretería y refacciones industriales, hecho en Laravel. Es una **copia de
ejemplo**: todos los nombres de personas, clientes y proveedores, así como los correos, teléfonos y RFC, son inventados.
No trae datos reales ni historial.

## Qué incluye

- **Cotizaciones** con PDF, envío por correo o WhatsApp, revisión interna y bitácora de cambios (Rev. N).
- **Clientes y proveedores** (contactos, razones sociales, sucursales, detección de duplicados).
- **Pedidos** en un tablero tipo Trello: tarjeta con estados por partida, recogidas parciales, fechas de entrega,
  comentarios, actividad, expediente (OC del cliente, facturas, fotos de entrega).
- **Revisión de la OC del cliente con IA** (OpenAI): lee el PDF o la foto y la compara contra lo cotizado.
- **OC a proveedores** (PDF e imagen para WhatsApp) y "A quién le debo" (compras a crédito).
- **Catálogo de artículos** y listas de precios de proveedores.
- **Finanzas**: ventas y cobranza, nómina con comisiones, gastos, tablero (dashboard) y saldos de cuentas.
- **Vacaciones** del equipo.
- **Puente con SICAR** (punto de venta) por API. El programa de la PC que lee SICAR no viene en esta copia.
- **Conexión con IA (MCP)** para consultar y crear cotizaciones en borrador desde Claude o ChatGPT.
- Un módulo aparte para un socio externo ("Ned Tracking") como ejemplo de usuario con acceso limitado.

## Tecnología

Laravel 12, PHP 8.3+, Livewire 3 (Alpine), Tailwind 4 (Vite), mPDF. Funciona con SQLite (local) y MySQL/MariaDB.

## Arrancar en local

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite        # con DB_CONNECTION=sqlite en .env
php artisan migrate --seed            # crea un admin (ver database/seeders/DatabaseSeeder.php)
php artisan serve
```

Variables opcionales en `.env`: `OPENAI_API_KEY` (lectura de OC con IA), cuentas de correo de salida
(`CORREO_*`) y `SICAR_SYNC_TOKEN` (puente con SICAR).

## Pruebas

```bash
php artisan test
```

Las pruebas nunca llaman a servicios reales (la IA se simula con `Http::fake()`).

## Organización del código

- `app/Livewire/` — pantallas (una carpeta por módulo).
- `app/Support/` — reglas de negocio reutilizables (precios, folios, conciliación, nómina, etc.).
- `app/Models/` — modelos; las tablas y columnas están en español.
- `routes/modulos/` — un archivo de rutas por módulo.
- `tests/Feature/` — pruebas por módulo.
