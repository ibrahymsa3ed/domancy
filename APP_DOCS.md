# Rovana (دومانسي) — Application Documentation

> **Last updated:** 2026-03-31
>
> This document describes the full architecture, features, and codebase of the Rovana distribution management system. Keep it updated with every change.

---

## 1. Overview

Rovana is a **distribution management system** originally built for ice cream factory delivery operations. It manages customers, drivers, daily orders, route optimization, and reporting — all through a native PHP web application with an Arabic (RTL) interface.

**Stack:** PHP 8+ · MySQL 8 · Bootstrap 5.3 · Google Maps API · No framework (plain PHP)

**Authentication:** None. The app is open-access; no login system exists.

**Language:** The UI is fully in Arabic (hardcoded strings). A translation system (`language.php` + `lang/ar.php`, `lang/en.php`) exists but is **not wired** into any page.

---

## 2. File Structure

```
rovana/
├── index.php              # Map page — overview of factory, customers, today's routes
├── customers.php          # Customer CRUD with map picker, client-side table
├── drivers.php            # Driver CRUD with edit modal, color picker, toggle active
├── orders.php             # Daily order management, driver assignment, auto-assign algorithm
├── reports.php            # Read-only reports with tabs (daily/period/customer/driver)
├── factory.php            # Factory location management (single row upsert)
├── check.php              # Diagnostics page (PHP version, DB, config checks)
│
├── db.php                 # PDO singleton + auto-migration for customer_number
├── config.php             # Runtime config (gitignored — must be created from example)
├── config.example.php     # Template for config.php
├── database.sql           # MySQL schema (4 tables)
├── header.php             # Shared HTML head, navbar, CSS/JS includes
├── footer.php             # Global confirm modal + confirmSubmit() + Bootstrap JS
│
├── language.php           # i18n switcher (unused by pages)
├── lang/
│   ├── ar.php             # Arabic translations (unused)
│   └── en.php             # English translations (unused)
│
├── assets/
│   ├── css/
│   │   └── style.css      # All custom styles (IBM Plex Sans Arabic, theming, RTL fixes)
│   └── js/
│       └── routes.js      # Shared per-driver Directions polylines (chunking + fixed stop order)
│
├── .htaccess              # UTF-8, -Indexes
├── .gitignore             # Ignores config.php, IDE/OS files
├── README.md              # Basic install/feature overview
└── APP_DOCS.md            # This file
```

---

## 3. Configuration

Copy `config.example.php` → `config.php` and fill in:

| Constant | Purpose | Default |
|----------|---------|---------|
| `DB_HOST` | MySQL host | `localhost` |
| `DB_PORT` | MySQL port (optional) | — |
| `DB_NAME` | Database name | `ice_cream_factory` |
| `DB_USER` | DB username | `root` |
| `DB_PASS` | DB password | (empty) |
| `DB_CHARSET` | Connection charset | `utf8mb4` |
| `GOOGLE_MAPS_API_KEY` | Google Maps API key | `YOUR_API_KEY_HERE` |
| `APP_NAME` | App title in navbar | `نظام توزيع الآيس كريم` |
| `TIMEZONE` | PHP timezone | `Africa/Cairo` |

**Required Google APIs:** Maps JavaScript API, Geocoding API, Directions API, Places API.

---

## 4. Database Schema

Database: **`ice_cream_factory`** (MySQL 8, utf8mb4)

### `factory` — Single row for the factory/warehouse location
| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK AUTO_INCREMENT | |
| `name` | VARCHAR(255) NOT NULL | |
| `address` | TEXT NOT NULL | |
| `latitude` | DECIMAL(10,8) NOT NULL | |
| `longitude` | DECIMAL(11,8) NOT NULL | |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP ON UPDATE | |

### `customers` — All delivery customers
| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK AUTO_INCREMENT | |
| `customer_number` | VARCHAR(30) NOT NULL UNIQUE | Editable; auto-generated if empty |
| `name` | VARCHAR(255) NOT NULL | |
| `phone` | VARCHAR(20) | |
| `address` | TEXT NOT NULL | |
| `town` | VARCHAR(255) | Used for auto-assign clustering |
| `governorate` | VARCHAR(255) | In schema; not set by customer forms |
| `latitude` | DECIMAL(10,8) NOT NULL | |
| `longitude` | DECIMAL(11,8) NOT NULL | |
| `notes` | TEXT | |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP ON UPDATE | |

### `drivers` — Delivery drivers
| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK AUTO_INCREMENT | |
| `name` | VARCHAR(255) NOT NULL | |
| `phone` | VARCHAR(20) | |
| `car_number` | VARCHAR(50) | |
| `car_type` | VARCHAR(100) | |
| `color` | VARCHAR(20) DEFAULT `#e6194b` | Route color on map |
| `governorate` | VARCHAR(255) | |
| `capacity` | INT DEFAULT 10 | Max orders per day |
| `is_active` | BOOLEAN DEFAULT TRUE | |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP ON UPDATE | |

### `daily_orders` — Links customers to drivers per date
| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK AUTO_INCREMENT | |
| `order_date` | DATE NOT NULL | |
| `customer_id` | INT NOT NULL | FK → customers(id) ON DELETE CASCADE |
| `driver_id` | INT | FK → drivers(id) ON DELETE SET NULL |
| `status` | ENUM('pending','assigned','delivered') | Only `pending` and `assigned` are used in code |
| `notes` | TEXT | |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP ON UPDATE | |

**Auto-migration:** `db.php` automatically adds `customer_number` column to `customers` if missing, backfilling from `id`.

---

## 5. Shared Components

### `header.php`
- Sets `lang="ar"` and `dir="rtl"` globally
- Loads Bootstrap 5.3 CSS, Bootstrap Icons 1.11, `assets/css/style.css`
- Conditionally loads Google Maps JS when `$googleMapsScript` is set by the page
- Renders the top navbar with links: الخريطة (Map), العملاء (Customers), السائقين (Drivers), الطلبات اليومية (Daily Orders), التقارير (Reports), موقع المصنع (Factory Location)
- Active nav link is determined by `basename($_SERVER['PHP_SELF'])`
- Optional logo from `assets/images/logo.png` or `.jpg`

### `footer.php`
- Global Bootstrap confirm modal (`#globalConfirmModal`)
- `window.confirmSubmit(form, opts)` — reusable confirmation dialog used by all delete/destructive actions across the app
- Loads Bootstrap 5.3 JS bundle

### `assets/css/style.css`
- Google Fonts import (IBM Plex Sans Arabic)
- CSS custom properties: rose/slate color palette
- RTL-aware navbar, cards, tables, badges, alerts, form styling
- Print styles (`@media print`)
- Responsive breakpoints
- RTL fix for `.form-select` dropdown arrow positioning
- Card header overrides scoped to `.card-header` only (not badges)

### `assets/js/routes.js`
- Loaded by [index.php](index.php) and [orders.php](orders.php) (after Google Maps API).
- Exposes `window.RovanaRoutes`: `sortStopsByOrderId`, `buildOrderedLocations`, `buildRouteSegments`, `renderDriverRoute`, `clearRenderers`.
- **Stop order:** Stops are sorted by `daily_orders.id` (ascending) so the drawn path matches a stable database order (not Google’s waypoint optimizer).
- **Chunking:** Google Directions allows at most **23** intermediate waypoints per request. Routes with more stops are split into consecutive segments (factory → … → last stop of segment; next segment continues from that stop). Each segment gets its own `DirectionsRenderer`; all use the same driver color.
- **`optimizeWaypoints`:** Always `false` so the sequence stays `id`-ordered.

---

## 6. Distribution routes (خط السير) — workflow and behavior

### Operational checklist (Phase A)

1. Set **factory** location on [factory.php](factory.php) (routes start here).
2. Ensure **customers** have valid lat/lng ([customers.php](customers.php)).
3. On [orders.php](orders.php): choose **date**, select customers, **save** to create/sync `daily_orders`.
4. Select **drivers** (picker; optional `localStorage` per date).
5. **Assign** manually per row or use **توزيع تلقائي** (requires saved customers + at least one selected driver).
6. View polylines on the **orders** map (toggles per driver) or on **index.php** map for **today** only.

Unassigned orders appear gray; missing factory or coordinates will break or skew routes.

### Technical summary

| Topic | Behavior |
|--------|----------|
| Grouping | PHP groups rows by `driver_id`; each group is one driver’s stops for the map. |
| Order of stops | JavaScript sorts by `daily_orders.id` ascending before calling Directions. |
| Long routes | [routes.js](assets/js/routes.js) splits into multiple API requests when a driver has more than 24 stops in a leg (23 waypoints + destination). |
| Colors | Each driver’s polylines use `drivers.color` (fallback palette if missing). |

---

## 7. Page-by-Page Feature Reference

### `index.php` — الخريطة (Map Overview)
**Purpose:** Main dashboard showing the factory, all customers, and today's delivery routes on a Google Map.

**Data loaded:**
- Factory location
- All customers
- Active drivers
- Today's `daily_orders` with driver assignments (route query includes `o.id` for stop ordering)

**Features:**
- Google Map with factory marker, customer markers (color-coded by assigned driver)
- Filter: show all customers vs. only today's orders
- Per-driver route toggle (show/hide route polylines); each driver may have multiple polylines when chunked (see [routes.js](assets/js/routes.js))
- POST `assign_driver` — quick assign a driver to an order from the map

**Google Maps:** Uses Directions API via `RovanaRoutes.renderDriverRoute` — fixed order by `daily_orders.id`, chunked past 23 waypoints.

---

### `customers.php` — العملاء (Customer Management)
**Purpose:** Full CRUD for customers with map-based location picker.

**POST actions:**
- `add` — Create customer (auto-generates `customer_number` if empty; uniqueness enforced)
- `edit` — Update customer (uniqueness check on `customer_number`)
- `delete` — Remove customer
- `seed_customers` — Generate 10 demo customers near factory location

**Client-side features (JavaScript):**
- **Live search** — Filter-as-you-type by customer number, name, phone, or address
- **Client-side sorting** — Click table headers to sort (default: customer_number ASC)
- **Client-side pagination** — Top and bottom pagination bars; configurable per-page (20/50/100)
- **Google Places autocomplete** for address input
- **Map picker** — Draggable marker, click-to-place, coordinate search
- **QR code** — Generated via `api.qrserver.com` linking to Google Maps
- **Plus Code** — Computed server-side from lat/lng

**Data flow:** All customers loaded into JS array `allCustData` via `json_encode`. Table rendered entirely client-side.

---

### `drivers.php` — السائقين (Driver Management)
**Purpose:** Full CRUD for drivers.

**POST actions:**
- `add` — Create driver
- `edit` — Update all driver fields via modal
- `delete` — Remove driver (with confirm modal)
- `toggle_active` — Toggle `is_active` status
- `update_color` — Update route color (inline color picker per row)

**UI:**
- Left sidebar: Add driver form with color preset dropdown
- Right side: Driver table with columns: Name, Phone, Car Number, Car Type, Capacity, Governorate, Color, Status (badge), Actions
- **Edit modal** — Scrollable Bootstrap modal pre-filled with driver data; includes all fields + color presets
- **Status badges** — Green (`bg-success`) for active, gray (`bg-secondary`) for inactive
- Edit (pencil), toggle (play/pause), and delete (trash) buttons per row

---

### `orders.php` — الطلبات اليومية (Daily Orders)
**Purpose:** Manage which customers get deliveries on a given date, assign drivers, and optimize routes.

**POST actions:**
- `create_orders` — Sync: deletes removed customers' orders for the date, inserts new ones as `pending`
- `assign_driver` — Assign a driver to a specific order
- `unassign_driver` — Remove driver assignment
- `bulk_unassign_all` — Unassign all drivers for the date
- `remove_order` — Delete a single order
- `bulk_remove_driver_orders` — Remove all orders for a specific driver
- `bulk_remove_all_orders` — Remove all orders for the date
- `auto_assign` — Algorithm: geographic clusters + load-balanced driver choice + capacity + nearest-neighbor within cluster

**Auto-assign algorithm:**
1. **Clusters** orders with `clusterKeyForOrder()`: DB `governorate` (if set), else `town`, else parsed governorate from address (`extractGovernorate`), else parsed town from address — so one city (e.g. Alexandria) is not split across drivers when data is consistent.
2. **Town-to-driver** choice uses distance to cluster centroid **plus** a strong penalty for drivers who already have many assignments (`loadRatio = existing_assigned / capacity`) and for drivers already given other clusters in this run — reduces one driver taking every nearby town.
3. Within each cluster, orders are **nearest-neighbor** ordered from the driver’s current position.
4. `drivingDistanceKm()` may call Google Directions API (falls back to haversine)

**Client-side features:**
- **Customer picker** — Table with search, pagination, select/remove buttons
- **Driver picker** — Table with search, pagination, select/remove buttons
- **`localStorage`** — Persists selected drivers per date key
- **Route map** — Shows assigned routes per driver with color-coded polylines via [routes.js](assets/js/routes.js) (`daily_orders.id` order, chunked when needed)
- Confirm modals for all destructive actions

---

### `reports.php` — التقارير (Reports)
**Purpose:** Read-only reporting with multiple views and print support.

**Tabs:**
1. **يومي (Daily)** — Default: today's report. Date picker to change day.
2. **فترة (Period)** — Date range report (from/to).
3. **حسب العميل (By Customer)** — Filter by customer with modes: all / specific day / period. Searchable customer dropdown (name/phone/number).
4. **حسب السائق (By Driver)** — Filter by driver with same mode options.

**Features:**
- SortableJS for drag-reorder of report rows (visual/print order only, not saved)
- Print button (per driver section or full report)
- QR codes per order row
- Plus codes displayed

**No write operations.** All data is GET-based filtering.

---

### `factory.php` — موقع المصنع (Factory Location)
**Purpose:** Set or update the factory/warehouse location (single row in `factory` table).

**POST action:** Upsert — UPDATE if row exists, INSERT otherwise.

**Features:**
- Google Map with draggable marker
- Google Places autocomplete for address
- Click map to set location
- Geocoding support

---

### `check.php` — System Diagnostics
**Purpose:** Verify system requirements and configuration.

**Checks:**
- PHP version
- `config.php` exists
- Database connection
- Table existence and row counts
- Google Maps API key (placeholder check)
- Core file existence

---

## 8. JavaScript Patterns

- **Shared route module** — [assets/js/routes.js](assets/js/routes.js) for per-driver Directions polylines on map pages; other behavior remains inline in PHP pages
- **Client-side tables** — `customers.php` and parts of `orders.php` load all data into JS arrays and render tables client-side for instant search/sort/pagination
- **`window.confirmSubmit(form, opts)`** — Global function from `footer.php` for delete confirmations via Bootstrap modal
- **Google Maps integration** — Marker placement, geocoding, autocomplete, directions/polylines
- **`localStorage`** — Used in `orders.php` to persist driver selections per date
- **`json_encode`** — PHP arrays passed to JS via inline `<script>` blocks

---

## 9. External Dependencies (CDN)

| Library | Version | Used In |
|---------|---------|---------|
| Bootstrap CSS | 5.3.0 | All pages (header.php) |
| Bootstrap JS Bundle | 5.3.0 | All pages (footer.php) |
| Bootstrap Icons | 1.11.0 | All pages (header.php) |
| Google Maps JS API | Latest | index, customers, orders, factory |
| SortableJS | 1.15.2 | reports.php only |
| IBM Plex Sans Arabic | Latest | style.css (@import) |
| QR Server API | v1 | customers.php, reports.php |

---

## 10. Data Flow

```
config.php ──► db.php (PDO singleton) ──► All pages
                                            │
header.php ◄──── $pageTitle, $googleMapsScript
                                            │
                 ┌──────────────────────────┤
                 ▼                          ▼
           Page PHP logic            footer.php
         (POST handlers,         (confirm modal,
          DB queries,              Bootstrap JS)
          HTML output)
```

**Request lifecycle:**
1. Browser requests `*.php`
2. Page does `require_once 'db.php'` → loads `config.php` → PDO connection
3. POST handlers process form submissions (CRUD operations)
4. Page queries DB for display data
5. Sets `$pageTitle` / `$googleMapsScript`, includes `header.php`
6. Renders HTML body with data (some pages embed data as JSON for client-side JS)
7. Includes `footer.php`

---

## 11. Key Design Decisions

- **No framework** — Plain PHP for simplicity and direct control
- **Server-rendered with client-side enhancements** — Forms POST to same page; JS handles search/sort/pagination for speed
- **No AJAX/API endpoints** — All CRUD via full-page form POST/redirect
- **RTL-first** — Arabic UI with `dir="rtl"` on `<html>`; CSS fixes for Bootstrap RTL edge cases
- **Single DB connection** — PDO singleton pattern in `db.php`
- **Google Maps integration** — Central to the app's purpose (delivery route planning)
- **No auth** — Designed for internal/trusted network use

---

## 12. Common Patterns for New Developers

### Adding a new page:
```php
<?php
require_once 'db.php';
// POST handlers here
$pageTitle = APP_NAME . ' - Page Name';
// $googleMapsScript = 'places,geometry'; // if maps needed
require_once 'header.php';
?>
<!-- HTML content -->
<?php require_once 'footer.php'; ?>
```

### Using the confirm modal for delete:
```javascript
form.addEventListener('submit', (e) => {
    e.preventDefault();
    confirmSubmit(form, {
        title: 'Delete Item',
        message: 'Are you sure?',
        btnText: 'Yes, delete',
        btnClass: 'btn-danger'  // optional, defaults to btn-danger
    });
});
```

### Database queries:
```php
// Select
$rows = getDB()->query("SELECT * FROM table")->fetchAll();

// Prepared statement
$stmt = getDB()->prepare("SELECT * FROM table WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

// Insert/Update
$stmt = getDB()->prepare("INSERT INTO table (col) VALUES (?)");
$stmt->execute([$value]);
```

---

## 13. Change Log

| Date | Description |
|------|-------------|
| 2026-03-31 | Auto-assign fairness: [orders.php](orders.php) `clusterKeyForOrder()` (governorate → town → address), stronger load-based score; fallback driver uses same score |
| 2026-03-31 | Per-driver routes: shared [assets/js/routes.js](assets/js/routes.js) — stop order by `daily_orders.id`, waypoint chunking (max 23 per request), `optimizeWaypoints: false`; [index.php](index.php) route query includes `o.id`; APP_DOCS section 6 (distribution workflow) |
| 2026-02-09 | Added driver edit functionality (modal with all fields) |
| 2026-02-09 | Added top pagination bar to customers table |
| 2026-02-09 | Fixed driver status badge colors (scoped CSS overrides to card headers only) |
| 2026-02-09 | Fixed RTL dropdown arrow overlap on `.form-select` globally |
| 2026-02-09 | Added customizable per-page display (20/50/100) to customers table |
| 2026-02-09 | Converted customers table to full client-side rendering (search/sort/pagination) |
| 2026-02-09 | Redesigned reports page with tabbed interface (daily/period/customer/driver) |
| 2026-02-09 | Added searchable customer picker to reports page |
| 2026-02-09 | Redesigned orders page customer/driver selection with table pickers |
| 2026-02-09 | Added editable unique customer number |
| 2026-02-09 | Implemented global delete confirmation modal (replaced browser confirm) |
| 2026-02-09 | Created APP_DOCS.md — comprehensive application documentation |
