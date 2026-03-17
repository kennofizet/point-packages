# Point packages

Monorepo for the **Workpoint** (points/ranking) system: Laravel backend + Vue 3 frontend. Zone-scoped via **packages-core**.

| Package | Description |
|--------|--------------|
| [packages/backend](packages/backend/README.md) | Laravel API: record points, top ranking, rules. Requires `kennofizet/packages-core-backend`. |
| [packages/frontend](packages/frontend/README.md) | Vue 3: ranking widget, rules view, point-earned notification. Uses workpoint backend + core for zones. |

**Quick links**

- Backend: `composer require kennofizet/workpoint-backend` → publish config & migrations → use `HasWorkpointRecords` and call APIs.
- Frontend: install `@kennofizet/workpoint-frontend`, call `installWorkpointModule(app, { coreUrl, backendUrl, token })`, use `<WorkpointTopUsersRanking />` and `<WorkpointPointEarnedNotification />`.

See each package README for details.
