# Workpoint Backend

Laravel package to **record workpoints** (points) for users with configurable rules. Zone-scoped via **packages-core**. Use the `HasWorkpointRecords` trait to record points and expose top ranking + rules APIs.

---

## Requirements

- PHP 8.2+, Laravel 12.x
- **kennofizet/packages-core-backend** (zone scoping + API auth)

---

## Install

```bash
composer require kennofizet/workpoint-backend
php artisan vendor:publish --tag=workpoint-config
php artisan vendor:publish --tag=workpoint-cases-config
php artisan vendor:publish --tag=workpoint-migrations
php artisan migrate
```

> Note: `workpoint-config` publishes `config/workpoint.php` (and `packages-core.php`) only.
> `config/workpoint_cases.php` is on a separate tag (`workpoint-cases-config`) so your existing rules file is not overwritten when you run `--tag=workpoint-config --force`.

**Optional .env** (defaults are fine for most):

```env
WORKPOINT_TABLE=workpoint_records
WORKPOINT_PERIOD_TOTALS_TABLE=workpoint_period_totals
WORKPOINT_ZONE_CASES_TABLE=workpoint_zone_cases
WORKPOINT_API_PREFIX=workpoint
WORKPOINT_USE_PERIOD_TOTALS_TABLE=true
WORKPOINT_HISTORY_PER_PAGE=30
WORKPOINT_ADMIN_MEMBERS_PER_PAGE=30
```

User display column comes from `packages-core` config:
- `packages-core.user_col_name` (default: `name`)
- `packages-core.table_user` (user table used by core)

---

## Config

- **config/workpoint.php** — Tables, API prefix, period totals, event/listeners, rules map.
- **config/workpoint_cases.php** — Action keys (e.g. `task_completed_on_time`) with `points`, `check`, `period`, `cap`, optional **`limit_period`** (day|week|month|year) and **`limit_period_time`** (max recordings per user per zone in that window, enforced after the rule check; omit or `0` for no extra limit), and `descriptions` (vi/en). Per-zone overrides stored in DB; defaults from this file.

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

// Optional zone list (multi-zone record). Invalid/non-member zones are skipped silently.
$user->recordWorkpoint('task_completed_on_time', $task, [], [1, 2, 3]);

// Null zone list (or [null]) means: record across all zones that belong to this user.
$user->recordWorkpoint('app_first_visit_day', null, [], null);
```

Returns `WorkpointRecord` or `null` if skipped/disallowed.

`workpoint_records` and `workpoint_period_totals` now store `user_id` and all checks/queries use `user_id` as the primary key.
`subject_type`/`subject_id` stay for polymorphic relation metadata only.

When request context has `currentUserId`, backend always uses that user first. If `currentUserZoneId` exists, it records only in that zone; otherwise it uses all zones of that current user. Only when `currentUserId` is null will backend use passed `userId` + `zoneIds`.

**3. Check if a record already exists** (by `user_id`; same `action_key` / target shape as when recording — does **not** re-run rule logic, only looks for a row):

```php
use App\Constants\ProjectConstant;

if ($user->hasWorkpointRecord(ProjectConstant::$workpointCase['project_confirm_assign'], $project)) {
    // already has a workpoint row for this case + project in this zone
}

if ($user->hasWorkpointRecord('app_first_visit_day')) {
    // no-target case: matches rows with null target
}

// Optional: explicit zoneIds and/or userId
$user->hasWorkpointRecord('task_completed_on_time', $task, [1, 2], 123);
```

**4. Get zone IDs that can record this action now** (after membership filter + rule check):

```php
// Returns only zone IDs where this action is currently allowed for this user.
$allowedZoneIds = $user->canWorkpointRecordZoneIds('task_completed_on_time', $task);

// Optional: limit candidate zones and/or force user id.
$allowedZoneIds = $user->canWorkpointRecordZoneIds(
    'task_completed_on_time',
    $task,
    [1, 2, 3], // optional candidate zones
    123        // optional user id override
);
```

`canWorkpointRecordZoneIds()` returns `array<int, int>`:
- First filters zones to zones the user is a member of.
- Then checks each zone with the configured rule (`check`) for that action key.
- Returns only zones that pass the rule at call time.

---

## API

All under `{packages-core.api_prefix}/{workpoint.api_prefix}/` (e.g. `api/knf/workpoint`). Requires `X-Knf-Token`; zone from `X-Knf-Zone-Id`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `top?period=day\|week\|month\|year&limit=10` | Top users by points in period (zone-scoped). |
| GET | `rules?language=vi\|en` | Merged rules (default + zone overrides). Returns `rules`, `language`, `isManager`. |
| POST | `rules/save` | Save one zone case override (manager). Zone from `X-Knf-Zone-Id`. Body: `case_key`, `points`, `check`, `period?`, `cap?`, `descriptions?`. |
| POST | `rules/reset` | Reset zone rules to default for the current zone (manager). Zone from `X-Knf-Zone-Id`; no body required. |
| GET | `history/me?period=day\|week\|month\|year&cursor=&language=vi\|en` | Current user: point log in period (cursor = last record `id` for “load more”), totals, ranks, `today_by_rule`, `isManager`. |
| GET | `history/user/{userId}?period=&cursor=&language=` | Same payload for one user (self always; others only if manager for zone / server). |
| GET | `admin/members?cursor=` | **Manager only.** Cursor-paginated users who have workpoints in the zone (`next_cursor` is base64 JSON). |

History summary responses include **`today_by_rule`**: per rule, `earned` is points earned today; **`max_points`** is the max total points for that rule when the check is capped — for `count_cap_per_period` it is **`points × cap`** (cap = max awards per period); for `first_time` / `first_time_per_period` / `first_time_per_target` it is **`points`** (one award). If **`limit_period_time`** is set, an additional ceiling **`points × limit_period_time`** applies; when both a count rule and a limit apply, the smaller ceiling is shown. **`max_points`** is `null` when unlimited (`none` or no cap on count rules and no limit period cap).

---

## Summary

| Step | Action |
|------|--------|
| Install | `composer require kennofizet/workpoint-backend` |
| Config | `php artisan vendor:publish --tag=workpoint-config` |
| Cases config (optional first publish) | `php artisan vendor:publish --tag=workpoint-cases-config` |
| Migrations | `php artisan vendor:publish --tag=workpoint-migrations` then `php artisan migrate` |
| Model | `use HasWorkpointRecords;` → `$model->recordWorkpoint(...)`, `$model->hasWorkpointRecord($key, $target?)`, `$model->canWorkpointRecordZoneIds($key, $target?)` |

More in config file comments (events, listeners, rules).
