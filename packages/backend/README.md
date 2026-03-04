# Workpoint Backend

Laravel package: record workpoint events with check rules; zone/server scoped via **packages-core**. Same format as feedback-packages/rewardplay-packages (backend under `packages/backend`).

## Structure

- `packages/backend` — Laravel package (Company\Workpoint), requires **kennofizet/packages-core-backend**.
- `packages/frontend` — Vue 3 components (top users ranking, point-earned notification); consumed by host app (no npm build inside this repo).

## Backend

- **Config**: `workpoint.php` (table, event_class, **after_record_listeners**, rules, api_prefix), `workpoint_cases.php` (action_key => points, check, period, cap). Set `after_record_listeners` to an array of class names implementing `AfterWorkpointRecordedListener` (each has `handle(WorkpointRecord $record): void`); they run after the event is dispatched (e.g. update coin, notify user).
- **Zone scoping**: Queries use `BaseModelActions::currentUserZoneId()`; `workpoint_records` has `zone_id`; routes use `ValidateRewardPlayToken` (packages-core).
- **API**: `GET {api_prefix}/workpoint/top?period=day|week|month|year&limit=10` — top users by total points in period (scoped by current zone).
- **Trait HasWorkpointRecords**: `recordWorkpoint()`, `workpointRecords()`, **`getWorkpointRecordsByPeriod(string $period)`** — returns this subject’s records in the given period (day|week|month|year), ordered by `created_at` desc.

## Scaling "top by period" (large data)

By default, top is computed with `SUM(points_delta)` and `groupBy` on `workpoint_records`, which can be slow on large tables. For scale:

1. Set **`workpoint.use_period_totals_table`** to `true` (or `WORKPOINT_USE_PERIOD_TOTALS_TABLE=true`).
2. Run the migration that creates **`workpoint_period_totals`** (one row per subject per period type per period key; synced on each record).
3. `getTopInPeriod()` then reads from this table (indexed by zone, period_type, period_key, total_points) instead of aggregating the main table.

If you enable the option after you already have data, backfill `workpoint_period_totals` once (e.g. loop over `WorkpointRecord` and call `app(PeriodTotalsSync::class)->syncRecord($record)` for each, or add an artisan command).

## Frontend (package only)

- **WorkpointTopUsersRanking**: Tabs Day/Week/Month/Year, fetches top from API. Pass `apiBaseUrl` (or set `window.__WORKPOINT_API_BASE__`). Slot `#subject` to customize row (e.g. user name).
- **WorkpointPointEarnedNotification**: Shows when user earns points; props `points`, `message`, `show`, `autoHideMs`. Use after recording a workpoint or when listening to `WorkpointRecorded` event.

Host app: install backend via Composer, frontend via npm (or copy); configure API base and auth; run migrations. No `npm run dev` inside this package — you test from the host app.
