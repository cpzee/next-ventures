<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Project snapshot

-   Framework: Laravel 12 (PHP ^8.2). See `composer.json` for dependencies (notably `laravel/horizon`, `predis/predis`).
-   Purpose: small e-commerce/order processing skeleton with queued Jobs, Redis-based KPIs, and Horizon for queue supervision.

## Quick start commands (developer)

-   Install & bootstrap (creates .env and runs migrations):

    composer run-script setup

-   Start the full local dev stack (server, queue worker, logs, vite):

    composer run-script dev

-   Run tests (note: tests use in-memory SQLite and `QUEUE_CONNECTION=sync` via `phpunit.xml`):

    composer test

-   Horizon (monitor queues):

    php artisan horizon

Notes: the `dev` composer script runs `php artisan serve`, `php artisan queue:listen --tries=1`, `php artisan pail` and Vite concurrently. Use it to reproduce local developer setup.

## High-level architecture & dataflow

-   Inbound orders are created/imported (see `app/Console/Commands/ImportOrders.php`).
-   Order processing is implemented as a chain of queued jobs:
    -   `App\Jobs\ProcessOrderJob` — creates/upserts Order using `order_id` (idempotent `updateOrCreate`) and dispatches a chain.
    -   `App\Jobs\ReserveStockJob` — reserves inventory (decrements `Product::stock`), marks order `reserved` and records a `JobLog`.
    -   `App\Jobs\SimulatePaymentJob` — simulates payment, marks order `completed`, and updates Redis KPIs.

Key files: `app/Jobs/ProcessOrderJob.php`, `app/Jobs/ReserveStockJob.php`, `app/Jobs/SimulatePaymentJob.php`, `app/Models/JobLog.php`.

Conventions observed:

-   Money is stored as integer cents in `total_cents`.
-   Order idempotency is implemented with `Order::updateOrCreate(['order_id'=>...], ...)`.
-   Order item data may be stored as JSON (jobs access `$order->items`) and `metadata` is cast to array on `Order` (`app/Models/Order.php`). When changing this surface, be careful to update both code paths.
-   Job logs use the `JobLog` model and include `status`, `started_at`, `completed_at`.

## Integration points & environment variables

-   Queue system: default connection is `database` (see `config/queue.php`), but tests set `QUEUE_CONNECTION=sync`.
-   Horizon: configured in `config/horizon.php`. Gate is defined in `app/Providers/HorizonServiceProvider.php` (empty allowlist by default).
-   Redis is used for KPIs and leaderboards (`kpi:*`, `leaderboard:customers`). The app uses `predis/predis` but the `KpiService` accepts either Predis or PhpRedis connections.

Important env variables to be aware of when running or testing:

-   `QUEUE_CONNECTION` (e.g. `database`, `redis`, `sync`)
-   `DB_CONNECTION`, `DB_DATABASE`
-   `REDIS_QUEUE_CONNECTION` / `REDIS_HOST`
-   `HORIZON_PATH` / `HORIZON_DOMAIN`

## Code patterns & examples for the agent

-   Idempotent order creation (from `app/Jobs/ProcessOrderJob.php`):

    Order::updateOrCreate(
    ['order_id' => $data['order_id']],
    [ 'customer_id' => ..., 'total_cents' => ..., 'metadata' => $data ]
    );

-   Job chaining example (from `ProcessOrderJob`):

    ReserveStockJob::withChain([
    new SimulatePaymentJob($order->id),
    ])->dispatch($order->id)->onQueue('reservations');

-   KPIs: either updated directly in `SimulatePaymentJob` via `Redis::incrby`/`zincrby` or via `App\Services\KpiService` which writes date-scoped keys `kpi:revenue:YYYY-MM-DD` and `leaderboard:customers`.

## Testing and developer gotchas

-   Tests run with `DB_DATABASE=:memory:` and `QUEUE_CONNECTION=sync` (see `phpunit.xml`). When writing tests, prefer the sync queue and in-memory DB fixtures unless you intentionally want integration tests.
-   Many jobs call `$this->fail($e)` or rethrow; job `tries` and `timeout` are configured in `config/horizon.php` defaults (tries=1 in this project). Be careful when changing retry behavior.
-   The Horizon gate is empty (no users allowed) by default; add emails in `HorizonServiceProvider::gate` if you expect to view Horizon in non-local environments.

## Where to look for changes & examples

-   CSV import & CLI: `app/Console/Commands/ImportOrders.php`
-   Queue & workers: `config/queue.php`, `config/horizon.php`, `app/Providers/HorizonServiceProvider.php`
-   Jobs: `app/Jobs/*.php` (core processing lives here)
-   KPIs & redis usage: `app/Jobs/SimulatePaymentJob.php`, `app/Services/KpiService.php`
-   Models: `app/Models/Order.php`, `OrderItem.php`, `Product.php`, `JobLog.php`

If any of these sections are unclear or you want the instructions to emphasize other parts (API routes, front-end build, deployment), tell me which area to expand and I will iterate.
