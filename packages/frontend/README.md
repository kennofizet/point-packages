# Workpoint Frontend

Vue 3 UI for **workpoint**: top users ranking (day/week/month/year), rules page, **point history** (filters by zone + period; managers get a user list with cursor pagination + per-user detail), and point-earned notification. Uses **packages-core** for zones and **workpoint-backend** for top & rules APIs. Host app provides `coreUrl`, `backendUrl`, and `token`.

---

## Requirements

- Vue 3.2+
- **axios** (for API)
- Backend: workpoint-backend + packages-core (for zones/auth)

---

## Install

```bash
npm install @kennofizet/workpoint-frontend
# or
yarn add @kennofizet/workpoint-frontend
```

---

## Setup

Provide core URL, workpoint backend URL, and auth token so the module can call zones (core) and top/rules (workpoint). Zone is sent as `X-Knf-Zone-Id` (e.g. from a zone selector).

**Option A — Plugin (recommended):**

```js
import { createApp } from 'vue'
import { installWorkpointModule } from '@kennofizet/workpoint-frontend'
import App from './App.vue'

const app = createApp(App)
installWorkpointModule(app, {
  coreUrl: 'https://your-api/api/knf',      // zones: coreUrl + '/player/zones'
  backendUrl: 'https://your-api/api/knf/workpoint',
  token: 'your-knf-token',
})
app.mount('#app')
```

This registers **WorkpointTopUsersRanking** and **WorkpointPointEarnedNotification** and provides `workpointApi` (inject or `$workpointApi`).

**Option B — Manual:**

```js
import { createWorkpointApi, TopUsersRanking, PointEarnedNotification } from '@kennofizet/workpoint-frontend'

const workpointApi = createWorkpointApi(coreUrl, backendUrl, token)
app.provide('workpointApi', workpointApi)
app.component('WorkpointTopUsersRanking', TopUsersRanking)
app.component('WorkpointPointEarnedNotification', PointEarnedNotification)
```

---

## Components

### WorkpointTopUsersRanking

Leaderboard + rules view. Fetches zones from core; top and rules from workpoint backend (zone from `X-Knf-Zone-Id`). Supports light/dark mode and language (vi/en).

**Props**

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `language` | `string` or ref | `'vi'` | Display language: `vi` \| `en`. |
| `darkMode` | `boolean` or ref | `false` | Use dark (game-style) theme. |
| `limit` | `number` | `10` | Max items in top list. |
| `initialPeriod` | `string` | `'week'` | Initial period tab: `day` \| `week` \| `month` \| `year`. |

**Events:** `zone-change`, `period-change`

**Slots:** `subject` — custom content for each row (receives `item`, `rank`).

**Behaviour:** Zone selector (if multiple zones); period tabs; **“History”** opens point log + totals + ranks + today-by-rule; **“Rules”** toggles to rules view. Managers see a split layout: user list (load more) → detail for selected user. Needs `workpointApi` injected (or provided by plugin).

---

### WorkpointPointEarnedNotification

Inline notification when user earns points (e.g. “+2 workpoints”). Optional auto-hide.

**Props**

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `points` | `number` | `0` | Points earned; when > 0 can trigger show. |
| `show` | `boolean` | `false` | Visibility (use with `v-model:show`). |
| `message` | `string` | — | Override default “+N workpoints”. |
| `variant` | `string` | `'success'` | `success` \| `info`. |
| `autoHideMs` | `number` | `3000` | Auto-hide after ms; `0` = no auto-hide. |

**Events:** `update:show`

---

## API client

`createWorkpointApi(coreUrl, workpointUrl, token)` returns:

- `getZones()` — GET zones (core).
- `getTop(period, limit)` — GET top (workpoint).
- `getRules(language)` — GET rules (workpoint; zone from header).
- `saveRule(payload)` — POST save zone rule (manager).
- `resetZoneRules(zoneId)` — POST reset zone rules (manager).

Requests send `X-Knf-Token`; workpoint requests send `X-Knf-Zone-Id` from `localStorage.selected_zone` when set.

---

## Summary

| Step | Action |
|------|--------|
| Install | `npm install @kennofizet/workpoint-frontend` |
| Setup | `installWorkpointModule(app, { coreUrl, backendUrl, token })` |
| Use | `<WorkpointTopUsersRanking />`, `<WorkpointPointEarnedNotification />` |

Pass `language` and `darkMode` (or refs) to TopUsersRanking for i18n and theme.
