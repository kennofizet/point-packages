# Point packages

Same format as feedback-packages / rewardplay-packages: **backend** and **frontend** under `packages/`.

- **packages/backend** — Laravel Workpoint Record package (`company/workpoint-backend`). Requires **kennofizet/packages-core-backend**. Zone/server scoped; exposes `GET .../workpoint/top?period=day|week|month|year`.
- **packages/frontend** — Vue 3 components: top users ranking (day/week/month/year), point-earned notification. Package only; no npm build here — test from host app.

Root `composer.json` references `packages/backend` via path repository. Frontend is consumed by the host app (npm link or copy).
