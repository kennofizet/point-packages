# Workpoint Backend

Laravel package to record workpoints (points) for subjects (e.g. users) with configurable rules, zone-scoped via **packages-core**. Use the **HasWorkpointRecords** trait on any model to record points and query history.

---

## Requirements

- PHP 8.2+
- Laravel 12.x
- **kennofizet/packages-core-backend** (for zone scoping and API auth)

---

## Install

### 1. Composer

Add the package to your Laravel app:

```bash
composer require kennofizet/workpoint-backend
```

If you use a local or private repo, add the repository in your root `composer.json` and run the same command.

### 2. Publish config

Publish the config files so you can edit them in your app:

```bash
php artisan vendor:publish --tag=workpoint-config
```

This creates (or overwrites):

- `config/workpoint.php`
- `config/workpoint_cases.php`

### 3. Configure .env

Add to your `.env` (optional; defaults are shown):

```env
# Tables (default names)
WORKPOINT_TABLE=workpoint_records
WORKPOINT_PERIOD_TOTALS_TABLE=workpoint_period_totals

# API path: your API prefix + this segment (e.g. api/knf/workpoint)
WORKPOINT_API_PREFIX=workpoint

# Set to true for large data: "top by period" reads from workpoint_period_totals instead of aggregating records
WORKPOINT_USE_PERIOD_TOTALS_TABLE=true
```

### 4. Publish and run migrations

Publish migrations into your app:

```bash
php artisan vendor:publish --tag=workpoint-migrations
```

Then run them:

```bash
php artisan migrate
```

This creates:

- `workpoint_records` — one row per workpoint event (subject, action, points, etc.)
- `workpoint_period_totals` — optional summary table used when `WORKPOINT_USE_PERIOD_TOTALS_TABLE=true`

---

## Update

After pulling a new version of the package:

```bash
composer update kennofizet/workpoint-backend
```

If the package adds new config keys or migrations:

1. **Config** — Optionally re-publish and merge changes:
   ```bash
   php artisan vendor:publish --tag=workpoint-config --force
   ```
   Review `config/workpoint.php` and `config/workpoint_cases.php` so you don’t lose your custom values.

2. **Migrations** — Re-publish migrations if new ones were added, then run:
   ```bash
   php artisan vendor:publish --tag=workpoint-migrations --force
   php artisan migrate
   ```

---

## Config overview

### `config/workpoint.php`

- **table** / **period_totals_table** — Table names (from .env).
- **api_prefix** — URL segment for workpoint API (e.g. `workpoint` → `KNF_CORE_API_PREFIX/workpoint/top`).
- **use_period_totals_table** — `true` to use the summary table for “top by period” (recommended for large data).
- **event_class** — Event class fired when a workpoint is recorded (for your listeners).
- **after_record_listeners** — Array of class names that run after each record (e.g. update coins, send notification). Each must implement `Kennofizet\Workpoint\Contracts\AfterWorkpointRecordedListener` and have `handle(WorkpointRecord $record): void`.
- **rules** — Map of rule names to classes (none, first_time, first_time_per_target, first_time_per_period, count_cap_per_period).

### `config/workpoint_cases.php`

Defines **action keys** and how many points they give, plus which rule to use. Example:

```php
return [
    'task_completed_on_time' => [
        'points' => 2,
        'check' => 'first_time_per_target',
    ],
    'app_first_visit_day' => [
        'points' => 1,
        'check' => 'first_time_per_period',
        'period' => 'day',
    ],
];
```

You can add or change keys here; the package uses them when you call `recordWorkpoint('task_completed_on_time', $task)`.

---

## Using the trait

Add **HasWorkpointRecords** to any model that can earn workpoints (e.g. `User`).

### 1. Use the trait

```php
use Kennofizet\Workpoint\Traits\HasWorkpointRecords;

class User extends Authenticatable
{
    use HasWorkpointRecords;

    // ...
}
```

### 2. Record workpoints

When something happens (e.g. user completes a task), record a workpoint by **action key** (defined in `workpoint_cases.php`):

```php
// No target
$user->recordWorkpoint('app_first_visit_day');

// With target (e.g. task) — used by rules like first_time_per_target
$user->recordWorkpoint('task_completed_on_time', $task);

// Optional payload
$user->recordWorkpoint('task_accepted_sla', $task, ['sla_hours' => 24]);
```

Returns the created `WorkpointRecord` or `null` if the rule did not allow it (e.g. already earned for that target/period).

### 3. Query records

```php
// All workpoint records for this user
$user->workpointRecords;

// Records in a period (day|week|month|year), newest first
$user->getWorkpointRecordsByPeriod('week');
$user->getWorkpointRecordsByPeriod('month');
```

---

## API

With **packages-core** and the default prefix, the endpoint is:

- **GET** `KNF_CORE_API_PREFIX/WORKPOINT_API_PREFIX/top?period=day|week|month|year&limit=10`

Returns top subjects (e.g. users) by total points in the given period, scoped by the current zone. Use it to drive leaderboards (Day / Week / Month / Year).

---

## Optional: scale “top by period”

If you have a lot of records, aggregating `workpoint_records` for “top by period” can be slow. You can switch to the summary table:

1. Set in `.env`:
   ```env
   WORKPOINT_USE_PERIOD_TOTALS_TABLE=true
   ```
2. Ensure the migration for `workpoint_period_totals` has been run (it is published with `workpoint-migrations`).

The package will then maintain `workpoint_period_totals` on each record and `getTopInPeriod()` will read from that table. If you enable this after you already have data, run a one-time backfill (e.g. loop over existing `WorkpointRecord` and call `app(PeriodTotalsSync::class)->syncRecord($record)` for each).

---

## Summary

| Step            | Command / action |
|-----------------|------------------|
| Install         | `composer require kennofizet/workpoint-backend` |
| Publish config | `php artisan vendor:publish --tag=workpoint-config` |
| .env            | Set `WORKPOINT_*` vars if you need non-defaults |
| Migrations      | `php artisan vendor:publish --tag=workpoint-migrations` then `php artisan migrate` |
| Use in model    | `use HasWorkpointRecords;` then `$model->recordWorkpoint('action_key', $target)` |

For more (events, listeners, rules), see the in-file comments in `config/workpoint.php` and `config/workpoint_cases.php`.
