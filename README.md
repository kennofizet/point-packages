# Point packages

- **packages/backend** — Laravel Workpoint Record package (`kennofizet/workpoint-backend`). Requires **kennofizet/packages-core-backend**. Zone/server scoped; exposes `GET .../workpoint/top?period=day|week|month|year`.
- **packages/frontend** — Vue 3 components: top users ranking (day/week/month/year), point-earned notification. Package only; no npm build here — test from host app.

Root `composer.json` references `packages/backend` via path repository. Frontend is consumed by the host app (npm link or copy).


// Install workpoint module (top ranking, point notification)
installWorkpointModule(app, {
  apiConfig: {
    baseUrl: 'http://127.0.0.1:8000/KNF_CORE_API_PREFIX/WORKPOINT_API_PREFIX',
    token: 'test-token'
  }
})
