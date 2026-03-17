# Workpoint Backend

Laravel package to **record workpoints** (points) for subjects (e.g. users) with configurable rules. Zone-scoped via **packages-core**. Use the `HasWorkpointRecords` trait to record points and expose top ranking + rules APIs.

---

## Requirements

- PHP 8.2+, Laravel 12.x
- **kennofizet/packages-core-backend** (zone scoping + API auth)

---

## Install

```bash
composer require kennofizet/workpoint-backend
php artisan vendor:publish --tag=workpoint-config
php artisan vendor:publish --tag=workpoint-migrations
php artisan migrate
```

**Optional .env** (defaults are fine for most):

```env
WORKPOINT_TABLE=workpoint_records
WORKPOINT_PERIOD_TOTALS_TABLE=workpoint_period_totals
WORKPOINT_ZONE_CASES_TABLE=workpoint_zone_cases
WORKPOINT_API_PREFIX=workpoint
WORKPOINT_USE_PERIOD_TOTALS_TABLE=true
```

---

## Config

- **config/workpoint.php** — Tables, API prefix, period totals, event/listeners, rules map.
- **config/workpoint_cases.php** — Action keys (e.g. `task_completed_on_time`) with `points`, `check`, `period`, `cap`, `descriptions` (vi/en). Per-zone overrides stored in DB; defaults from this file.

---

## Usage in code

**1. Add trait to the model that earns points (e.g. User):**

```php
use Kennofizet\Workpoint\Traits\HasWorkpointRecords;

class User extends Authenticatable
{
    use HasWorkpointRecords;
}
```

**2. Record workpoints** (action key must exist in `workpoint_cases.php`):

```php
$user->recordWorkpoint('app_first_visit_day');                    // no target
$user->recordWorkpoint('task_completed_on_time', $task);          // with target
$user->recordWorkpoint('task_accepted_sla', $task, ['sla' => 24]); // with payload
```

Returns `WorkpointRecord` or `null` if the rule disallows (e.g. already earned for that target/period).

**3. Query records:**

```php
$user->workpointRecords;
$user->getWorkpointRecordsByPeriod('week');
```

---

## API

All under `{packages-core.api_prefix}/{workpoint.api_prefix}/` (e.g. `api/knf/workpoint`). Requires `X-Knf-Token`; zone from `X-Knf-Zone-Id`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `top?period=day\|week\|month\|year&limit=10` | Top subjects by points in period (zone-scoped). |
| GET | `rules?language=vi\|en` | Merged rules (default + zone overrides). Returns `rules`, `language`, `isManager`. |
| POST | `rules/save` | Save one zone case override (manager). Body: `zone_id`, `case_key`, `points`, `check`, `period?`, `cap?`, `descriptions?`. |
| POST | `rules/reset` | Reset zone rules to default: delete overrides and clone from config (manager). Body: `zone_id`. |

---

## Summary

| Step | Action |
|------|--------|
| Install | `composer require kennofizet/workpoint-backend` |
| Config | `php artisan vendor:publish --tag=workpoint-config` |
| Migrations | `php artisan vendor:publish --tag=workpoint-migrations` then `php artisan migrate` |
| Model | `use HasWorkpointRecords;` → `$model->recordWorkpoint('action_key', $target)` |

More in config file comments (events, listeners, rules).
