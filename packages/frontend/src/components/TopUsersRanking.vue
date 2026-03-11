<template>
  <div class="workpoint-top-users">
    <div class="workpoint-top-users__tabs">
      <button
        v-for="p in periods"
        :key="p.value"
        type="button"
        class="workpoint-top-users__tab"
        :class="{ 'workpoint-top-users__tab--active': period === p.value }"
        @click="selectPeriod(p.value)"
      >
        {{ p.label }}
      </button>
    </div>
    <div v-if="loading" class="workpoint-top-users__loading">Loading...</div>
    <ul v-else-if="items.length" class="workpoint-top-users__list">
      <li
        v-for="(item, index) in items"
        :key="`${item.subject_type}-${item.subject_id}`"
        class="workpoint-top-users__item"
      >
        <span class="workpoint-top-users__rank">#{{ index + 1 }}</span>
        <span class="workpoint-top-users__subject">
          <slot name="subject" :item="item" :rank="index + 1">
            User #{{ item.subject_id }}
          </slot>
        </span>
        <span class="workpoint-top-users__points">{{ item.total_points }} pts</span>
      </li>
    </ul>
    <p v-else class="workpoint-top-users__empty">No data for this period.</p>
  </div>
</template>

<script>
import { ref, watch, inject } from 'vue'

const PERIODS = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
  { value: 'year', label: 'Year' },
]

export default {
  name: 'TopUsersRanking',
  props: {
    /** API base URL (e.g. from host app) */
    apiBaseUrl: { type: String, default: '' },
    /** X-Knf-Token for auth (or from apiConfig) */
    token: { type: String, default: '' },
    /** Request limit */
    limit: { type: Number, default: 10 },
    /** Initial period */
    initialPeriod: { type: String, default: 'week' },
  },
  setup(props, { emit }) {
    const workpointApi = inject('workpointApi', null)
    const effectiveToken = () => props.token || workpointApi?.token || ''
    const period = ref(
      PERIODS.some(p => p.value === props.initialPeriod) ? props.initialPeriod : 'week'
    )
    const items = ref([])
    const loading = ref(false)

    const periods = PERIODS

    async function fetchTop() {
      const base = props.apiBaseUrl || workpointApi?.baseUrl || (typeof window !== 'undefined' && window.__WORKPOINT_API_BASE__) || ''
      if (!base) {
        items.value = []
        return
      }
      loading.value = true
      try {
        const url = `${base.replace(/\/$/, '')}/top?period=${period.value}&limit=${props.limit}`
        const headers = { Accept: 'application/json' }
        const token = effectiveToken()
        if (token) {
          headers['X-Knf-Token'] = token
        }
        const res = await fetch(url, { credentials: 'include', headers })
        const data = await res.json()
        const payload = data.datas || data.data || data
        items.value = (payload && payload.items) ? payload.items : []
      } catch (_) {
        items.value = []
      } finally {
        loading.value = false
      }
    }

    function selectPeriod(p) {
      period.value = p
      emit('period-change', p)
    }

    watch([period, () => props.limit], fetchTop, { immediate: true })

    return { period, periods, items, loading, selectPeriod }
  },
}
</script>

<style scoped>
.workpoint-top-users {
  font-size: 14px;
}
.workpoint-top-users__tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 12px;
}
.workpoint-top-users__tab {
  padding: 6px 12px;
  border: 1px solid #ddd;
  background: #fff;
  border-radius: 6px;
  cursor: pointer;
}
.workpoint-top-users__tab--active {
  background: #1a1a2e;
  color: #fff;
  border-color: #1a1a2e;
}
.workpoint-top-users__list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.workpoint-top-users__item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid #eee;
}
.workpoint-top-users__rank {
  font-weight: 600;
  min-width: 28px;
}
.workpoint-top-users__subject {
  flex: 1;
}
.workpoint-top-users__points {
  font-weight: 600;
  color: #0a0;
}
.workpoint-top-users__loading,
.workpoint-top-users__empty {
  color: #666;
  margin: 12px 0;
}
</style>
