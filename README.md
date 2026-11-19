# Gather — Multi-tenant Kiosk POS

A runnable Laravel application based on the approved ivory, charcoal, and orange UI concept. The source includes a custom Livewire business dashboard, cashier POS, customer QR menu, kitchen display, and a native Filament platform administration panel.

## Quick start (macOS / Laravel Herd)

Use PHP 8.4 or newer, Composer 2, and Node.js 22.12+ (or Node.js 24). Required PHP extensions include intl, mbstring, bcmath, PDO, pdo_sqlite (or pdo_mysql), fileinfo, dom, xml, curl, and zip. SQLite is the default for a quick local demo.

Unzip this folder, open it in VS Code, and run from this folder:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Open `.env` and set `DEMO_PASSWORD` to a password with at least 12 characters. The same password will be assigned to the local demo accounts. Do not use a real account password. Keep `APP_ENV=local` for this demo.

```bash
php artisan migrate --seed
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000/login and use one of these accounts with the `DEMO_PASSWORD` you chose:

| Account | Role / access |
| --- | --- |
| owner@gather.test | Owner of Gather, both kiosks |
| manager@gather.test | Manager, assigned to both Gather kiosks |
| cashier@gather.test | Cashier, Main branch only |
| kitchen@gather.test | Kitchen staff, Main branch only |
| platform@gather.test | Super Admin, Filament platform panel |
| other@gather.test | Owner of Juniper Cafe, an isolated second tenant |

Demo seed runs only in local/testing and refuses to run over existing businesses. Do not run `migrate:fresh` against any database containing work you need to keep.

For frontend development, run `npm run dev` in another terminal while `php artisan serve` is running. Production CSS/JS are already included in `public/build` and can be rebuilt from source.

## Try the connected workflow

1. Sign in as the owner and open Gather Restaurant.
2. Open **Kiosks & QR**, then **Open menu** under a table. For an actual phone scan, serve the app at a URL the phone can reach. Set `APP_URL` to that URL before printing QR codes. A phone cannot reach your laptop through `127.0.0.1`.
3. Add a meal, open the basket, and place the order. QR orders are unpaid until a cashier records payment. Stock is reserved at submission.
4. Open **Kitchen display** for that kiosk. Incoming orders appear through 5-second polling. Advance Received → Preparing → Ready → Served.
5. In the POS, open **Incoming orders** and record collected cash for the customer's existing order. Do not create a second sale for that QR order.
6. Refresh the customer tracking page to see progress. A completed order keeps its unpaid flag until payment is recorded; serving and payment are separate states.
7. Add a kiosk in the business dashboard, then use **Inventory** to set stock for that kiosk. New kiosks and new products start at zero stock. Eight table QR codes are created for a new kiosk.
8. Under **Team & access**, add members, choose a role, assign kiosks, and optionally grant additional permissions. Owners and administrators can access all business kiosks; other roles are restricted to their kiosk assignments. Removing staff access preserves their global account and memberships in other businesses.
9. As Platform Admin, open **Platform administration** → **Businesses** to provision a new business and owner. The owner then adds its kiosks, products, and staff.

## What is implemented

- Single database, isolated business memberships, multiple kiosks per business, and assignment checks on operational queries and writes.
- Owner/admin/manager/cashier/kitchen roles, explicit action permissions, and additional permission grants scoped to a business.
- Platform Super Admin outside business memberships; access is enforced by the Filament panel and page.
- Shared menu per business, kiosk inventory, product availability, menu editing, kiosk editing, and printable SVG table QR codes.
- Cashier cart, quantity controls, category filters, search, table selection, checkout, payment recording, and order receipts.
- QR ordering with an unguessable table token, server-priced totals, service charges, optional customer name/notes, and tokenized order tracking.
- Transactional order creation with kiosk locking, stock validation, server-side prices, integer cent amounts, and idempotent request keys to avoid duplicate submissions.
- Responsive screens, locally bundled food images and Inter font, customer PWA manifest/icons, and a service worker with an offline fallback. Customer menus, orders, authenticated pages, and Livewire responses are not cached. Online access is required to submit orders.
- Live dashboard statistics and 7-day paid-sales chart; reports currently show this dashboard summary rather than an export/report builder.
- Login throttling, session regeneration, CSRF protection, protected Livewire context IDs, and authorization checks on every sensitive action.

## Scope of this first version

This is a working MVP for local review, not a completed production rollout. Card and GCash are manually confirmed external payments; there is no gateway charge or payment verification API. Cash checkout records payment after the cashier confirms collection. Do not treat selecting a payment method as evidence that a gateway has processed money.

Taxes/VAT configuration, discounts/refunds/voids, cash drawer shifts, receipt printer drivers, stock adjustment audit history, item modifiers, subscription billing, invitation emails, password reset, custom branding uploads, real-time push/WebSockets, and offline order queues are not implemented. The browser can print the order receipt, but no fiscal receipt format or printer integration is provided. Menu photographs are illustrative sample assets; replace them with your own product photos before use.

Customer order status is refreshed manually. Kitchen status uses polling. Kiosk and table links are capability URLs: anyone with a table QR can place an order for that table, and anyone with an order link can see its receipt/status. Keep order links private; customer names and notes are not shown on the public receipt.

Roles are predefined in `config/kiosk.php`. The UI can assign roles and grant extra actions, but it does not include a custom-role editor or deny overrides.

## Architecture

- `app/Livewire/Workspace.php`: business dashboard and management sections.
- `app/Livewire/Terminal.php`: cashier POS and incoming payment queue.
- `app/Livewire/CustomerMenu.php`: public table menu/basket.
- `app/Livewire/Kitchen.php`: assigned kiosk kitchen queue.
- `app/Services/Access.php`: tenant permissions and kiosk assignments.
- `app/Services/PlaceOrder.php`: transactional prices, stock, and order creation.
- `app/Filament/Pages/Businesses.php`: Super Admin tenant provisioning.
- `resources/css/app.css`: approved design direction and responsive layouts.
- `tests/Feature/TenantOrderTest.php`: business isolation, assignment enforcement, revocation, stock, payment, QR, PWA, and provisioning tests.

Filament powers the platform panel; the business workspace uses custom Blade/Livewire views to match the mockup more closely. Livewire provides Alpine.js, so there is no separately loaded Alpine package.

Exact dependency versions are pinned in `composer.lock` and `package-lock.json`. This build resolved Laravel 13.43.0, Filament 5.11.4, and Livewire 4.4.8, and Tailwind CSS 4.3.5. Use `composer install` and `npm ci` to reproduce the tested versions.

## Tests

```bash
php artisan test --compact
```

Tests run against an in-memory SQLite database, not your demo database. They cover tenant/assignment boundaries, permission revocation, invalid carts, stock rejection, idempotency, arithmetic, checkout, QR receipts, native platform permissions, business provisioning, staff assignments/removal, kiosk/product creation, and the customer manifest.

## New production installation

Use a separate database and environment, not the seeded demo database. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to your HTTPS origin, the correct `APP_TIMEZONE`, secure session cookies, and your MySQL connection details. Set your web server document root to `public/` and allow writes to `storage/` and `bootstrap/cache/`. Do not expose `.env` or the source directory as the web root.

Install production dependencies and run migrations; do not run the demo seeder. Create the first Super Admin interactively:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan gather:platform-admin
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Keep an existing app key on upgrades. After creating the Super Admin, sign in at `/login` and open the platform panel. Build assets with `npm ci && npm run build` if changing the UI. HTTPS is required for PWA installation outside localhost. Verify concurrent checkout behavior against the selected production database and finish payment, printer, tax, and operational integrations before live use.

## Sample asset credits

Sample photos are downloaded locally from Unsplash image IDs: photo-1547592180-85f173990554, photo-1512621776951-a57141f2eefd, photo-1461023058943-07fcbe16d735, photo-1573080496219-bb080dd4f877, photo-1606755962773-d324e0a13086, and photo-1568901346375-23c9450c58cd. Inter font is supplied by Filament. Framework and package licenses remain with their respective authors.
