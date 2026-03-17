<template>
  <div
    class="workpoint-top-users"
    :class="{ 'workpoint-top-users--dark': effectiveDarkMode }"
  >
    <!-- Header: zone row (when multi-zone) + Rule btn right, or period tabs + Rule btn right (when 1 zone) -->
    <div class="workpoint-top-users__header">
      <template v-if="zones.length > 1">
        <div class="workpoint-top-users__zone-row">
          <label class="workpoint-top-users__zone-label">{{ t.zone }}</label>
          <select
            v-model="selectedZoneId"
            class="workpoint-top-users__zone-select"
            @change="onZoneChange"
          >
            <option v-for="z in zones" :key="z.id" :value="z.id">
              {{ z.name || t.zoneName(z.id) }}
            </option>
          </select>
        </div>
        <button type="button" class="workpoint-top-users__rule-btn" @click="viewMode = viewMode === 'rules' ? 'ranking' : 'rules'">
          {{ viewMode === 'rules' ? t.rankingBtn : t.ruleBtn }}
        </button>
      </template>
      <template v-else>
        <div class="workpoint-top-users__tabs" v-if="viewMode === 'ranking'">
          <button
            v-for="p in periods"
            :key="p.value"
            type="button"
            class="workpoint-top-users__tab"
            :class="{ 'workpoint-top-users__tab--active': period === p.value }"
            @click="selectPeriod(p.value)"
          >
            {{ t.period(p.value) }}
          </button>
        </div>
        <button type="button" class="workpoint-top-users__rule-btn" @click="viewMode = viewMode === 'rules' ? 'ranking' : 'rules'">
          {{ viewMode === 'rules' ? t.rankingBtn : t.ruleBtn }}
        </button>
      </template>
    </div>

    <!-- Period tabs row (only when multi-zone, we already have zone row + rule btn above) -->
    <div v-if="zones.length > 1 && viewMode === 'ranking'" class="workpoint-top-users__tabs">
      <button
        v-for="p in periods"
        :key="p.value"
        type="button"
        class="workpoint-top-users__tab"
        :class="{ 'workpoint-top-users__tab--active': period === p.value }"
        @click="selectPeriod(p.value)"
      >
        {{ t.period(p.value) }}
      </button>
    </div>

    <!-- Rule page (game-style) -->
    <div v-if="viewMode === 'rules'" class="workpoint-top-users__rule-page">
      <div class="workpoint-top-users__rule-header">
        <h2 class="workpoint-top-users__rule-title">{{ t.ruleTitle }}</h2>
        <template v-if="isManager">
          <button type="button" class="workpoint-top-users__rule-btn workpoint-top-users__rule-btn--setting" @click="openSettingPopup">
            {{ t.settingBtn }}
          </button>
          <button type="button" class="workpoint-top-users__rule-btn workpoint-top-users__rule-btn--reset" @click="resetDefaultConfig">
            {{ t.resetDefaultBtn }}
          </button>
        </template>
      </div>
      <div v-if="rulesLoading" class="workpoint-top-users__loading">{{ t.loading }}</div>
      <ul v-else class="workpoint-top-users__rule-list">
        <li
          v-for="(rule, index) in rulesList"
          :key="rule.key"
          class="workpoint-top-users__rule-card"
        >
          <span class="workpoint-top-users__rule-index">#{{ index + 1 }}</span>
          <div class="workpoint-top-users__rule-body">
            <p class="workpoint-top-users__rule-desc">{{ rule.description }}</p>
            <div class="workpoint-top-users__rule-meta">
              <span class="workpoint-top-users__rule-points">{{ rule.points }} {{ t.pts }}</span>
              <span v-if="rule.period" class="workpoint-top-users__rule-period">{{ t.periodLabel }}: {{ t.period(rule.period) }}</span>
              <span v-if="rule.cap != null" class="workpoint-top-users__rule-cap">{{ t.capLabel }}: {{ rule.cap }}</span>
            </div>
          </div>
        </li>
      </ul>
      <p v-if="!rulesLoading && !rulesList.length" class="workpoint-top-users__empty">{{ t.noRules }}</p>

      <!-- Setting popup (manager): step 1 case key → step 2 check/cap/period → step 3 points + descriptions -->
      <div v-if="settingPopupOpen" class="workpoint-top-users__popup-overlay" @click.self="closeSettingPopup">
        <div class="workpoint-top-users__popup">
          <div class="workpoint-top-users__popup-header">
            <h3 class="workpoint-top-users__popup-title">{{ t.settingPopupTitle }}</h3>
            <button type="button" class="workpoint-top-users__popup-close" @click="closeSettingPopup" aria-label="Close">&times;</button>
          </div>
          <div class="workpoint-top-users__popup-body">
            <!-- Step 1: select case key -->
            <div v-if="settingStep === 1" class="workpoint-top-users__popup-step">
              <label class="workpoint-top-users__popup-label">{{ t.settingCaseKey }}</label>
              <select v-model="settingForm.case_key" class="workpoint-top-users__popup-select">
                <option value="">— {{ t.selectCaseKey }} —</option>
                <option v-for="r in rulesList" :key="r.key" :value="r.key">{{ r.description || r.key }}</option>
              </select>
            </div>
            <!-- Step 2: check, period, cap -->
            <div v-if="settingStep === 2" class="workpoint-top-users__popup-step">
              <label class="workpoint-top-users__popup-label">{{ t.settingCheck }}</label>
              <select v-model="settingForm.check" class="workpoint-top-users__popup-select">
                <option v-for="opt in CHECK_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
              <label class="workpoint-top-users__popup-label">{{ t.settingPeriod }}</label>
              <select v-model="settingForm.period" class="workpoint-top-users__popup-select">
                <option value="">—</option>
                <option value="day">{{ t.period('day') }}</option>
                <option value="week">{{ t.period('week') }}</option>
                <option value="month">{{ t.period('month') }}</option>
                <option value="year">{{ t.period('year') }}</option>
              </select>
              <label class="workpoint-top-users__popup-label">{{ t.capLabel }}</label>
              <input v-model.number="settingForm.cap" type="number" min="0" class="workpoint-top-users__popup-input" placeholder="—" />
            </div>
            <!-- Step 3: points + descriptions (vi / en) -->
            <div v-if="settingStep === 3" class="workpoint-top-users__popup-step">
              <label class="workpoint-top-users__popup-label">{{ t.settingPoints }}</label>
              <input v-model.number="settingForm.points" type="number" min="0" class="workpoint-top-users__popup-input" />
              <div class="workpoint-top-users__popup-lang-tabs">
                <button type="button" class="workpoint-top-users__popup-lang-tab" :class="{ 'workpoint-top-users__popup-lang-tab--active': settingDescLang === 'vi' }" @click="settingDescLang = 'vi'">VI</button>
                <button type="button" class="workpoint-top-users__popup-lang-tab" :class="{ 'workpoint-top-users__popup-lang-tab--active': settingDescLang === 'en' }" @click="settingDescLang = 'en'">EN</button>
              </div>
              <label class="workpoint-top-users__popup-label">{{ t.settingDescription }} ({{ settingDescLang.toUpperCase() }})</label>
              <textarea v-model="settingForm.descriptions[settingDescLang]" class="workpoint-top-users__popup-textarea" rows="3" :placeholder="t.settingDescriptionPlaceholder"></textarea>
            </div>
          </div>
          <div class="workpoint-top-users__popup-footer">
            <template v-if="settingStep > 1">
              <button type="button" class="workpoint-top-users__rule-btn" @click="settingStep--">{{ t.prev }}</button>
            </template>
            <template v-if="settingStep < 3">
              <button type="button" class="workpoint-top-users__rule-btn workpoint-top-users__rule-btn--primary" @click="settingStep++" :disabled="!canAdvanceSettingStep">{{ t.next }}</button>
            </template>
            <template v-else>
              <button type="button" class="workpoint-top-users__rule-btn workpoint-top-users__rule-btn--primary" @click="submitSetting" :disabled="savingRule">{{ t.save }}</button>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Ranking content (when viewMode === 'ranking') -->
    <template v-else>
    <div v-if="zonesLoading" class="workpoint-top-users__loading">{{ t.loadingZones }}</div>
    <template v-else-if="!hasZoneContext">
      <p class="workpoint-top-users__empty">{{ t.noZone }}</p>
    </template>
    <template v-else>
      <div v-if="loading" class="workpoint-top-users__loading">{{ t.loading }}</div>
      <template v-else-if="items.length">
        <!-- Top 3: floating glassmorphism cards (game style when dark) -->
        <div v-if="effectiveDarkMode && items.length >= 3" class="workpoint-top-users__podium">
          <div
            v-for="(item, index) in items.slice(0, 3)"
            :key="`podium-${item.subject_type}-${item.subject_id}`"
            class="workpoint-top-users__podium-card"
            :class="[
              index === 0 && 'workpoint-top-users__podium-card--gold',
              index === 1 && 'workpoint-top-users__podium-card--silver',
              index === 2 && 'workpoint-top-users__podium-card--bronze',
            ]"
          >
            <span class="workpoint-top-users__podium-rank" :class="{ 'workpoint-top-users__podium-rank--first': index === 0 }">#{{ index + 1 }}</span>
            <span class="workpoint-top-users__podium-subject">
              <slot name="subject" :item="item" :rank="index + 1">
                {{ t.userLabel(item.subject_name || item.subject_id) }}
              </slot>
            </span>
            <span class="workpoint-top-users__podium-xp">{{ item.total_points }} {{ t.pts }}</span>
            <span class="workpoint-top-users__podium-level">Lv.{{ levelFromPoints(item.total_points) }}</span>
          </div>
        </div>
        <!-- Battle-pass style list (all items in game style; or top 4+ when podium shown) -->
        <ul class="workpoint-top-users__list">
          <li
            v-for="(item, index) in listItems"
            :key="`${item.subject_type}-${item.subject_id}`"
            class="workpoint-top-users__item"
          >
            <span class="workpoint-top-users__rank">#{{ listItemRank(index) }}</span>
            <span class="workpoint-top-users__subject">
              <slot name="subject" :item="item" :rank="index + 1">
                {{ t.userLabel(item.subject_name || item.subject_id) }}
              </slot>
            </span>
            <template v-if="effectiveDarkMode">
              <span class="workpoint-top-users__level">Lv.{{ levelFromPoints(item.total_points) }}</span>
              <div class="workpoint-top-users__progress-wrap">
                <div class="workpoint-top-users__progress-bar" :style="{ width: progressPercent(item.total_points) + '%' }"></div>
              </div>
            </template>
            <span class="workpoint-top-users__points">{{ item.total_points }} <span class="workpoint-top-users__xp-label">{{ t.pts }}</span></span>
          </li>
        </ul>
      </template>
      <p v-else class="workpoint-top-users__empty">{{ t.noData }}</p>
    </template>
    </template>
  </div>
</template>

<script setup>
import { ref, watch, inject, computed, onMounted, isRef } from 'vue'

const props = defineProps({
  /** UI language: 'vi' | 'en'. Can be a ref so host can change it and component updates. */
  language: { type: [String, Object], default: 'vi' },
  /** Dark mode. Can be a ref (e.g. isDark) so host can toggle and component updates. */
  darkMode: { type: [Boolean, Object], default: false },
  /** Request limit */
  limit: { type: Number, default: 10 },
  /** Initial period */
  initialPeriod: { type: String, default: 'week' },
})

const emit = defineEmits(['zone-change', 'period-change'])

/** Use injected ref if provided (reactive); else unwrap ref prop; else use plain prop. */
const effectiveLanguage = computed(() => {
  return isRef(props.language) ? props.language.value : props.language
})
const effectiveDarkMode = computed(() => {
  return isRef(props.darkMode) ? props.darkMode.value : props.darkMode
})

function isInteger(value) {
  return typeof value === 'number' && Number.isInteger(value)
}

const PERIOD_KEYS = ['day', 'week', 'month', 'year']
const periods = PERIOD_KEYS.map(value => ({ value }))

const TRANSLATIONS = {
  vi: {
    zone: 'Khu vực',
    zoneName: (id) => `Khu vực ${id}`,
    day: 'Ngày',
    week: 'Tuần',
    month: 'Tháng',
    year: 'Năm',
    loadingZones: 'Đang tải khu vực...',
    loading: 'Đang tải...',
    noZone: 'Chưa có khu vực. Đăng nhập và tham gia khu vực để xem bảng xếp hạng.',
    noData: 'Chưa có dữ liệu cho khoảng thời gian này.',
    userLabel: (id) => isInteger(id) ? `Người dùng #${id}` : id,
    pts: 'điểm',
    ruleBtn: 'Quy định',
    rankingBtn: 'Xếp hạng',
    ruleTitle: 'Quy định nhận điểm',
    periodLabel: 'Chu kỳ',
    capLabel: 'Giới hạn',
    noRules: 'Chưa có quy định.',
    settingBtn: 'Cài đặt',
    resetDefaultBtn: 'Đặt lại mặc định',
    settingPopupTitle: 'Cài đặt quy định',
    settingCaseKey: 'Loại quy định',
    selectCaseKey: 'Chọn loại',
    settingCheck: 'Kiểm tra',
    settingPeriod: 'Chu kỳ',
    settingPoints: 'Điểm',
    settingDescription: 'Mô tả',
    settingDescriptionPlaceholder: 'Mô tả bằng tiếng Việt / English',
    prev: 'Trước',
    next: 'Tiếp',
    save: 'Lưu',
  },
  en: {
    zone: 'Zone',
    zoneName: (id) => `Zone ${id}`,
    day: 'Day',
    week: 'Week',
    month: 'Month',
    year: 'Year',
    loadingZones: 'Loading zones...',
    loading: 'Loading...',
    noZone: 'No zone available. Log in and join a zone to see ranking.',
    noData: 'No data for this period.',
    userLabel: (id) => isInteger(id) ? `User #${id}` : id,
    pts: 'pts',
    ruleBtn: 'Rules',
    rankingBtn: 'Ranking',
    ruleTitle: 'Points rules',
    periodLabel: 'Period',
    capLabel: 'Cap',
    noRules: 'No rules.',
    settingBtn: 'Setting',
    resetDefaultBtn: 'Reset default',
    settingPopupTitle: 'Rule setting',
    settingCaseKey: 'Rule type',
    selectCaseKey: 'Select type',
    settingCheck: 'Check',
    settingPeriod: 'Period',
    settingPoints: 'Points',
    settingDescription: 'Description',
    settingDescriptionPlaceholder: 'Description (VI / EN)',
    prev: 'Previous',
    next: 'Next',
    save: 'Save',
  },
}

function parseZonesFromResponse(resp) {
  if (!resp || !resp.data) return []
  const d = resp.data
  if (d.datas && Array.isArray(d.datas.zones)) return d.datas.zones
  if (Array.isArray(d.zones)) return d.zones
  if (Array.isArray(d)) return d
  if (d.datas && Array.isArray(d.datas)) return d.datas
  return []
}

const workpointApi = inject('workpointApi', null)

const zones = ref([])
const zonesLoading = ref(true)
const selectedZoneId = ref(null)
const period = ref(
  PERIOD_KEYS.includes(props.initialPeriod) ? props.initialPeriod : 'week'
)
const items = ref([])
const loading = ref(false)

const viewMode = ref('ranking')
const rulesList = ref([])
const rulesLoading = ref(false)
const isManager = ref(false)

const settingPopupOpen = ref(false)
const settingStep = ref(1)
const settingForm = ref({
  case_key: '',
  check: 'none',
  period: '',
  cap: null,
  points: 0,
  descriptions: { vi: '', en: '' },
})
const settingDescLang = ref('vi')
const savingRule = ref(false)

const t = computed(() => {
  const lang = TRANSLATIONS[effectiveLanguage.value] || TRANSLATIONS.vi
  return {
    zone: lang.zone,
    zoneName: typeof lang.zoneName === 'function' ? lang.zoneName : (id) => `Zone ${id}`,
    period: (key) => lang[key] ?? key,
    loadingZones: lang.loadingZones,
    loading: lang.loading,
    noZone: lang.noZone,
    noData: lang.noData,
    userLabel: typeof lang.userLabel === 'function' ? lang.userLabel : (id) => isInteger(id) ? `User #${id}` : id,
    pts: lang.pts,
    ruleBtn: lang.ruleBtn,
    rankingBtn: lang.rankingBtn,
    ruleTitle: lang.ruleTitle,
    periodLabel: lang.periodLabel,
    capLabel: lang.capLabel,
    noRules: lang.noRules,
    settingBtn: lang.settingBtn,
    resetDefaultBtn: lang.resetDefaultBtn,
    settingPopupTitle: lang.settingPopupTitle,
    settingCaseKey: lang.settingCaseKey,
    selectCaseKey: lang.selectCaseKey,
    settingCheck: lang.settingCheck,
    settingPeriod: lang.settingPeriod,
    settingPoints: lang.settingPoints,
    settingDescription: lang.settingDescription,
    settingDescriptionPlaceholder: lang.settingDescriptionPlaceholder,
    prev: lang.prev,
    next: lang.next,
    save: lang.save,
  }
})

const CHECK_OPTIONS = [
  { value: 'none', label: 'None' },
  { value: 'first_time_per_target', label: 'First time per target' },
  { value: 'first_time_per_period', label: 'First time per period' },
  { value: 'count_cap_per_period', label: 'Count cap per period' },
]

const hasZoneContext = computed(() => {
  if (zones.value.length === 0) return false
  if (zones.value.length === 1) return true
  return selectedZoneId.value != null
})

/** Game style: list is items 4+ when podium shown; otherwise all items. */
const listItems = computed(() => {
  if (effectiveDarkMode.value && items.value.length >= 3) return items.value.slice(3)
  return items.value
})

function listItemRank(index) {
  if (effectiveDarkMode.value && items.value.length >= 3) return index + 4
  return index + 1
}

const maxPoints = computed(() => {
  const list = items.value
  if (!list.length) return 1
  return Math.max(1, list[0].total_points || 1)
})

function levelFromPoints(pts) {
  return Math.min(99, Math.floor((pts || 0) / 50) + 1)
}

function progressPercent(pts) {
  const max = maxPoints.value
  return Math.min(100, Math.round(((pts || 0) / max) * 100))
}

async function fetchZones() {
  zonesLoading.value = true
  try {
    if (workpointApi && typeof workpointApi.getZones === 'function') {
      const resp = await workpointApi.getZones()
      const list = parseZonesFromResponse(resp)
      zones.value = list || []

      try {
        const stored = localStorage.getItem('selected_zone')
        if (stored) {
          const z = JSON.parse(stored)
          if (z && z.id && zones.value.some(zone => zone.id === z.id)) {
            selectedZoneId.value = z.id
            zonesLoading.value = false
            return
          }
        }
      } catch (_) {}

      if (zones.value.length === 1) {
        selectedZoneId.value = zones.value[0].id
        try {
          localStorage.setItem('selected_zone', JSON.stringify(zones.value[0]))
        } catch (_) {}
      } else if (zones.value.length > 1 && selectedZoneId.value == null) {
        const first = zones.value[0]
        selectedZoneId.value = first.id
        try {
          localStorage.setItem('selected_zone', JSON.stringify(first))
        } catch (_) {}
      }
    } else {
      zones.value = []
    }
  } catch (e) {
    console.warn('TopUsersRanking: failed to fetch zones', e)
    zones.value = []
  } finally {
    zonesLoading.value = false
  }
}

function onZoneChange() {
  const z = zones.value.find(zone => zone.id === selectedZoneId.value)
  if (z) {
    try {
      localStorage.setItem('selected_zone', JSON.stringify(z))
    } catch (_) {}
    emit('zone-change', z)
  }
  if (viewMode.value === 'rules') {
    fetchRules()
  } else {
    fetchTop()
  }
}

async function fetchTop() {
  if (!workpointApi || typeof workpointApi.getTop !== 'function') {
    items.value = []
    return
  }
  if (!hasZoneContext.value) {
    items.value = []
    return
  }
  loading.value = true
  try {
    const res = await workpointApi.getTop(period.value, props.limit)
    const data = res?.data
    const payload = data?.datas || data?.data || data
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

async function fetchRules() {
  if (!workpointApi || typeof workpointApi.getRules !== 'function') {
    rulesList.value = []
    isManager.value = false
    return
  }
  rulesLoading.value = true
  try {
    const res = await workpointApi.getRules(effectiveLanguage.value)
    const data = res?.data
    const payload = data?.datas ?? data?.data ?? data
    const list = payload?.rules ?? payload ?? []
    rulesList.value = Array.isArray(list) ? list : []
    isManager.value = !!payload?.isManager
  } catch (_) {
    rulesList.value = []
    isManager.value = false
  } finally {
    rulesLoading.value = false
  }
}

function openSettingPopup() {
  const firstRule = rulesList.value[0]
  settingForm.value = {
    case_key: firstRule ? firstRule.key : '',
    check: firstRule?.check ?? 'none',
    period: firstRule?.period ?? '',
    cap: firstRule?.cap ?? null,
    points: firstRule?.points ?? 0,
    descriptions: { vi: '', en: '' },
  }
  settingStep.value = 1
  settingDescLang.value = effectiveLanguage.value === 'en' ? 'en' : 'vi'
  settingPopupOpen.value = true
}

function closeSettingPopup() {
  settingPopupOpen.value = false
}

const canAdvanceSettingStep = computed(() => {
  if (settingStep.value === 1) return !!settingForm.value.case_key
  if (settingStep.value === 2) return true
  return true
})

async function submitSetting() {
  const zoneId = selectedZoneId.value ?? zones.value[0]?.id
  if (zoneId == null || !workpointApi?.saveRule) return
  savingRule.value = true
  try {
    const payload = {
      zone_id: zoneId,
      case_key: settingForm.value.case_key,
      points: Number(settingForm.value.points) || 0,
      check: settingForm.value.check || 'none',
      period: settingForm.value.period || null,
      cap: settingForm.value.cap != null && settingForm.value.cap !== '' ? Number(settingForm.value.cap) : null,
      descriptions: settingForm.value.descriptions,
    }
    await workpointApi.saveRule(payload)
    closeSettingPopup()
    await fetchRules()
  } catch (e) {
    console.warn('Save rule failed', e)
  } finally {
    savingRule.value = false
  }
}

async function resetDefaultConfig() {
  const zoneId = selectedZoneId.value ?? zones.value[0]?.id
  if (zoneId == null || !workpointApi?.resetZoneRules) return
  if (!confirm(t.value.resetDefaultBtn + '?')) return
  try {
    await workpointApi.resetZoneRules(zoneId)
    await fetchRules()
  } catch (e) {
    console.warn('Reset zone rules failed', e)
  }
}

onMounted(() => {
  fetchZones()
})

watch([period, () => props.limit], fetchTop, { immediate: false })
watch(hasZoneContext, (ok) => {
  if (ok) fetchTop()
}, { immediate: true })

watch(viewMode, (mode) => {
  if (mode === 'rules') fetchRules()
})
watch(effectiveLanguage, () => {
  if (viewMode.value === 'rules') fetchRules()
})

watch(() => settingForm.value.case_key, (key) => {
  if (!key) return
  const rule = rulesList.value.find(r => r.key === key)
  if (rule) {
    settingForm.value.check = rule.check ?? 'none'
    settingForm.value.period = rule.period ?? ''
    settingForm.value.cap = rule.cap ?? null
    settingForm.value.points = rule.points ?? 0
  }
}, { immediate: false })
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700;800&display=swap');

/* ========== Light mode (default) ========== */
.workpoint-top-users {
  font-family: 'Orbitron', sans-serif;
  font-size: 14px;
  color: #1a1a1a;
  background: #ffffff;
  height: 100%;
  padding: 16px;
  border-radius: 12px;
  /* border: 1px solid #e5e7eb; */
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}

.workpoint-top-users__zone-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.workpoint-top-users__zone-label {
  font-weight: 600;
  font-size: 13px;
  color: #374151;
}

.workpoint-top-users__zone-select {
  font-family: 'Orbitron', sans-serif;
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #ffffff;
  color: #1a1a1a;
  min-width: 180px;
  font-size: 14px;
  cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
}

.workpoint-top-users__zone-select:hover {
  border-color: #9ca3af;
  background-color: #fafafa;
}

.workpoint-top-users__zone-select:focus {
  outline: none;
  border-color: #1a1a2e;
  box-shadow: 0 0 0 2px rgba(26, 26, 46, 0.15);
}

.workpoint-top-users__zone-select option {
  background-color: #ffffff;
  color: #1a1a1a;
  padding: 10px 12px;
  font-size: 14px;
  font-weight: 500;
}

.workpoint-top-users__zone-select option:hover {
  background-color: #f3f4f6;
  color: #1a1a1a;
}

.workpoint-top-users__zone-select option:checked {
  background-color: #e5e7eb;
  color: #1a1a1a;
  font-weight: 600;
}

.workpoint-top-users__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.workpoint-top-users__header .workpoint-top-users__zone-row {
  margin-bottom: 0;
}

.workpoint-top-users__rule-btn {
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  background: #ffffff;
  color: #374151;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;
}

.workpoint-top-users__rule-btn:hover {
  background: #f3f4f6;
  border-color: #9ca3af;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.workpoint-top-users__rule-page {
  margin-top: 8px;
}

.workpoint-top-users__rule-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.workpoint-top-users__rule-btn--setting,
.workpoint-top-users__rule-btn--reset {
  margin-left: auto;
}

.workpoint-top-users__rule-btn--primary {
  background: #1a1a2e;
  color: #fff;
  border-color: #1a1a2e;
}

.workpoint-top-users__rule-btn--primary:hover:not(:disabled) {
  background: #2d2d44;
  border-color: #2d2d44;
}

.workpoint-top-users__rule-btn--primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.workpoint-top-users__rule-back {
  padding: 6px 12px;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  background: #f9fafb;
  color: #374151;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
}

.workpoint-top-users__rule-back:hover {
  background: #f3f4f6;
}

.workpoint-top-users__rule-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #1a1a1a;
  flex: 1;
}

/* Setting popup */
.workpoint-top-users__popup-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.workpoint-top-users__popup {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
  max-width: 420px;
  width: 100%;
  max-height: 90vh;
  overflow: auto;
}

.workpoint-top-users__popup-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #e5e7eb;
}

.workpoint-top-users__popup-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #1a1a1a;
}

.workpoint-top-users__popup-close {
  background: none;
  border: none;
  font-size: 24px;
  line-height: 1;
  color: #6b7280;
  cursor: pointer;
  padding: 0 4px;
}

.workpoint-top-users__popup-close:hover {
  color: #1a1a1a;
}

.workpoint-top-users__popup-body {
  padding: 20px;
}

.workpoint-top-users__popup-step {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.workpoint-top-users__popup-label {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
}

.workpoint-top-users__popup-select,
.workpoint-top-users__popup-input {
  font-family: 'Orbitron', sans-serif;
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  color: #1a1a1a;
}

.workpoint-top-users__popup-textarea {
  font-family: inherit;
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  color: #1a1a1a;
  resize: vertical;
}

.workpoint-top-users__popup-lang-tabs {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}

.workpoint-top-users__popup-lang-tab {
  padding: 6px 12px;
  border: 1px solid #d1d5db;
  background: #f9fafb;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.workpoint-top-users__popup-lang-tab--active {
  background: #1a1a2e;
  color: #fff;
  border-color: #1a1a2e;
}

.workpoint-top-users__popup-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 20px;
  border-top: 1px solid #e5e7eb;
}

/* Popup light mode: ensure select/input background for options */
.workpoint-top-users__popup-select,
.workpoint-top-users__popup-input {
  background-color: #ffffff;
}

.workpoint-top-users__popup-select option {
  background-color: #ffffff;
  color: #1a1a1a;
}

.workpoint-top-users__popup-textarea {
  background-color: #ffffff;
}

/* Popup dark mode */
.workpoint-top-users--dark .workpoint-top-users__popup-overlay {
  background: rgba(0, 0, 0, 0.7);
}

.workpoint-top-users--dark .workpoint-top-users__popup {
  background: #1a1a2e;
  border: 1px solid rgba(255, 255, 255, 0.1);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), 0 0 20px rgba(0, 242, 255, 0.08);
}

.workpoint-top-users--dark .workpoint-top-users__popup-header {
  border-bottom-color: rgba(255, 255, 255, 0.1);
}

.workpoint-top-users--dark .workpoint-top-users__popup-title {
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-close {
  color: #94a3b8;
}

.workpoint-top-users--dark .workpoint-top-users__popup-close:hover {
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-label {
  color: #94a3b8;
}

.workpoint-top-users--dark .workpoint-top-users__popup-select,
.workpoint-top-users--dark .workpoint-top-users__popup-input {
  background-color: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-select:hover,
.workpoint-top-users--dark .workpoint-top-users__popup-input:hover {
  background-color: rgba(255, 255, 255, 0.09);
  border-color: rgba(255, 255, 255, 0.18);
}

.workpoint-top-users--dark .workpoint-top-users__popup-select:focus,
.workpoint-top-users--dark .workpoint-top-users__popup-input:focus {
  outline: none;
  border-color: rgba(0, 242, 255, 0.5);
  box-shadow: 0 0 0 2px rgba(0, 242, 255, 0.2);
}

.workpoint-top-users--dark .workpoint-top-users__popup-select option {
  background-color: #1a1a2e;
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-select option:hover,
.workpoint-top-users--dark .workpoint-top-users__popup-select option:focus {
  background-color: rgba(255, 255, 255, 0.1);
}

.workpoint-top-users--dark .workpoint-top-users__popup-select option:checked {
  background-color: rgba(0, 242, 255, 0.2);
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-textarea {
  background-color: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-textarea::placeholder {
  color: #94a3b8;
}

.workpoint-top-users--dark .workpoint-top-users__popup-lang-tab {
  background: rgba(255, 255, 255, 0.05);
  border-color: rgba(255, 255, 255, 0.12);
  color: #94a3b8;
}

.workpoint-top-users--dark .workpoint-top-users__popup-lang-tab:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #e6eef6;
  border-color: rgba(255, 255, 255, 0.18);
}

.workpoint-top-users--dark .workpoint-top-users__popup-lang-tab--active {
  background: linear-gradient(135deg, rgba(0, 242, 255, 0.25) 0%, rgba(138, 43, 226, 0.25) 100%);
  color: #e6eef6;
  border-color: rgba(0, 242, 255, 0.4);
  box-shadow: 0 0 10px rgba(0, 242, 255, 0.15);
}

.workpoint-top-users--dark .workpoint-top-users__popup-footer {
  border-top-color: rgba(255, 255, 255, 0.1);
}

.workpoint-top-users--dark .workpoint-top-users__popup-footer .workpoint-top-users__rule-btn {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__popup-footer .workpoint-top-users__rule-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.1);
  border-color: rgba(255, 255, 255, 0.2);
}

.workpoint-top-users--dark .workpoint-top-users__popup-footer .workpoint-top-users__rule-btn--primary {
  background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
  border-color: transparent;
  color: #fff;
}

.workpoint-top-users--dark .workpoint-top-users__popup-footer .workpoint-top-users__rule-btn--primary:hover:not(:disabled) {
  background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
  box-shadow: 0 0 16px rgba(59, 130, 246, 0.4);
}

.workpoint-top-users__rule-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.workpoint-top-users__rule-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  background: #ffffff;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.workpoint-top-users__rule-index {
  font-weight: 700;
  font-size: 14px;
  color: #6b7280;
  min-width: 24px;
}

.workpoint-top-users__rule-body {
  flex: 1;
}

.workpoint-top-users__rule-desc {
  margin: 0 0 8px 0;
  font-size: 14px;
  color: #1a1a1a;
  line-height: 1.45;
}

.workpoint-top-users__rule-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12px;
  color: #6b7280;
}

.workpoint-top-users__rule-points {
  font-weight: 600;
  color: #0d7a0d;
}

.workpoint-top-users__tabs {
  display: flex;
  gap: 6px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.workpoint-top-users__tab {
  padding: 8px 16px;
  border: 1px solid #d1d5db;
  background: #ffffff;
  color: #374151;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  transition: background 0.2s, border-color 0.2s, color 0.2s;
}

.workpoint-top-users__tab:hover {
  background: #f3f4f6;
  border-color: #9ca3af;
  color: #1a1a1a;
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
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.workpoint-top-users__item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  background: #ffffff;
  color: #1a1a1a;
  transition: background 0.15s, box-shadow 0.15s, border-color 0.15s;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.workpoint-top-users__item:hover {
  background: #f9fafb;
  border-color: #d1d5db;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
}

.workpoint-top-users__rank {
  font-weight: 700;
  min-width: 32px;
  font-size: 15px;
  color: #6b7280;
}

.workpoint-top-users__subject {
  flex: 1;
}

.workpoint-top-users__points {
  font-weight: 600;
  color: #0d7a0d;
  font-size: 14px;
}

.workpoint-top-users__loading {
  color: #6b7280;
  margin: 24px 0;
  text-align: center;
  font-size: 14px;
}

.workpoint-top-users__empty {
  color: #6b7280;
  margin: 32px 0;
  text-align: center;
  font-size: 15px;
  line-height: 1.5;
  padding: 24px 16px;
  background: #f9fafb;
  border-radius: 10px;
  border: 1px dashed #d1d5db;
}

/* Dark mode */
.workpoint-top-users--dark {
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__zone-label {
  color: #94a3b8;
  font-size: 13px;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: #e6eef6;
  border-radius: 8px;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select:hover {
  background: rgba(255, 255, 255, 0.09);
  border-color: rgba(255, 255, 255, 0.18);
}

.workpoint-top-users--dark .workpoint-top-users__zone-select:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
}

.workpoint-top-users--dark .workpoint-top-users__tab {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #94a3b8;
  border-radius: 8px;
}

.workpoint-top-users--dark .workpoint-top-users__tab:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #e6eef6;
  border-color: rgba(255, 255, 255, 0.15);
}

.workpoint-top-users--dark .workpoint-top-users__tab--active {
  background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
  color: #fff;
  border-color: transparent;
  box-shadow: 0 2px 8px rgba(59, 130, 246, 0.35);
}

.workpoint-top-users--dark .workpoint-top-users__rule-btn {
  background: rgba(138, 43, 226, 0.12);
  border: 1px solid rgba(138, 43, 226, 0.4);
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__rule-btn:hover {
  background: rgba(138, 43, 226, 0.2);
  border-color: rgba(138, 43, 226, 0.6);
  box-shadow: 0 0 12px rgba(138, 43, 226, 0.25);
}

.workpoint-top-users--dark .workpoint-top-users__item {
  border-bottom-color: rgba(255, 255, 255, 0.06);
  color: #e6eef6;
  padding: 14px 0;
}

.workpoint-top-users--dark .workpoint-top-users__item:hover {
  background: rgba(255, 255, 255, 0.03);
}

.workpoint-top-users--dark .workpoint-top-users__rank {
  color: #94a3b8;
  font-weight: 700;
}

.workpoint-top-users--dark .workpoint-top-users__points {
  color: #4ade80;
  font-weight: 600;
}

.workpoint-top-users--dark .workpoint-top-users__loading {
  color: #94a3b8;
  margin: 32px 0;
}

.workpoint-top-users--dark .workpoint-top-users__empty {
  color: #94a3b8;
  margin: 40px 0;
  padding: 32px 24px;
  background: rgba(255, 255, 255, 0.03);
  border: 1px dashed rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  font-size: 15px;
  line-height: 1.6;
}

/* ========== Cyberpunk eSports Game Style (when dark) ========== */
.workpoint-top-users--dark {
  background: linear-gradient(135deg, #0d0221 0%, #1a0b2e 50%, #0d0221 100%);
  color: #e6eef6;
  padding: 16px;
  border-radius: 12px;
  position: relative;
  overflow: hidden;
}

.workpoint-top-users--dark::before {
  content: '';
  position: absolute;
  inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0, 242, 255, 0.02) 2px,
    rgba(0, 242, 255, 0.02) 4px
  );
  pointer-events: none;
  border-radius: 12px;
}

.workpoint-top-users--dark .workpoint-top-users__zone-label {
  color: rgba(0, 242, 255, 0.9);
  font-weight: 600;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select {
  background: rgba(0, 242, 255, 0.05);
  border: 1px solid rgba(0, 242, 255, 0.25);
  color: #e6eef6;
  border-radius: 10px;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select:hover {
  border-color: rgba(0, 242, 255, 0.5);
  box-shadow: 0 0 12px rgba(0, 242, 255, 0.2);
}

.workpoint-top-users--dark .workpoint-top-users__zone-select:focus {
  border-color: rgba(0, 242, 255, 0.6);
  box-shadow: 0 0 15px rgba(0, 242, 255, 0.4);
}

.workpoint-top-users--dark .workpoint-top-users__zone-select {
  font-weight: 500;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select option {
  background-color: #1a0b2e;
  color: #e6eef6;
  padding: 12px 14px;
  font-size: 13px;
  font-weight: 500;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select option:hover {
  background-color: #252040;
  color: #fff;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select option:focus {
  background-color: rgba(0, 242, 255, 0.15);
  color: #fff;
}

.workpoint-top-users--dark .workpoint-top-users__zone-select option:checked {
  background-color: #2a1b4a;
  color: rgba(0, 242, 255, 0.95);
  font-weight: 600;
}

.workpoint-top-users--dark .workpoint-top-users__tab {
  background: rgba(138, 43, 226, 0.08);
  border: 1px solid rgba(138, 43, 226, 0.25);
  color: #94a3b8;
  border-radius: 10px;
}

.workpoint-top-users--dark .workpoint-top-users__tab:hover {
  background: rgba(138, 43, 226, 0.15);
  color: #e6eef6;
  border-color: rgba(138, 43, 226, 0.4);
  box-shadow: 0 0 12px rgba(138, 43, 226, 0.2);
}

.workpoint-top-users--dark .workpoint-top-users__tab--active {
  background: linear-gradient(135deg, rgba(0, 242, 255, 0.2) 0%, rgba(138, 43, 226, 0.25) 100%);
  border: 1px solid rgba(0, 242, 255, 0.4);
  color: #fff;
  box-shadow: 0 0 15px rgba(0, 242, 255, 0.3), inset 0 0 20px rgba(138, 43, 226, 0.1);
}

/* Top 3 podium cards */
.workpoint-top-users__podium {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

.workpoint-top-users__podium-card {
  position: relative;
  padding: 16px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.04);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.08);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-align: center;
}

.workpoint-top-users__podium-card--gold {
  border-color: rgba(255, 215, 0, 0.4);
  box-shadow: 0 0 20px rgba(255, 215, 0, 0.25), 0 0 40px rgba(255, 215, 0, 0.1);
  animation: workpoint-glow-pulse 2.5s ease-in-out infinite;
}

.workpoint-top-users__podium-card--silver {
  border-color: rgba(192, 192, 192, 0.4);
  box-shadow: 0 0 15px rgba(192, 192, 192, 0.25);
}

.workpoint-top-users__podium-card--bronze {
  border-color: rgba(205, 127, 50, 0.4);
  box-shadow: 0 0 15px rgba(205, 127, 50, 0.25);
}

@keyframes workpoint-glow-pulse {
  0%, 100% { box-shadow: 0 0 20px rgba(255, 215, 0, 0.25), 0 0 40px rgba(255, 215, 0, 0.1); }
  50% { box-shadow: 0 0 28px rgba(255, 215, 0, 0.4), 0 0 50px rgba(255, 215, 0, 0.15); }
}

.workpoint-top-users__podium-rank {
  font-weight: 800;
  font-size: 1.25rem;
  color: #94a3b8;
}

.workpoint-top-users__podium-rank--first {
  background: linear-gradient(to bottom, #fff 20%, #ffd700 80%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  filter: drop-shadow(0 0 6px rgba(255, 215, 0, 0.5));
  font-size: 1.5rem;
}

.workpoint-top-users__podium-subject {
  font-weight: 600;
  font-size: 0.85rem;
  color: #e6eef6;
}

.workpoint-top-users__podium-xp {
  font-weight: 700;
  color: rgba(0, 242, 255, 0.95);
  font-size: 0.9rem;
  text-shadow: 0 0 8px rgba(0, 242, 255, 0.4);
}

.workpoint-top-users__podium-level {
  font-size: 0.7rem;
  font-weight: 600;
  color: rgba(138, 43, 226, 0.9);
  padding: 2px 8px;
  border-radius: 8px;
  background: rgba(138, 43, 226, 0.2);
  border: 1px solid rgba(138, 43, 226, 0.3);
}

/* Battle-pass list (game style) */
.workpoint-top-users--dark .workpoint-top-users__item {
  display: grid;
  grid-template-columns: 36px 1fr 56px minmax(70px, 120px) auto;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  margin-bottom: 8px;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.06);
  background: rgba(255, 255, 255, 0.02);
  border-bottom: none;
}

.workpoint-top-users--dark .workpoint-top-users__item:hover {
  background: rgba(0, 242, 255, 0.04);
  border-color: rgba(0, 242, 255, 0.15);
  box-shadow: 0 0 12px rgba(0, 242, 255, 0.08);
}

.workpoint-top-users--dark .workpoint-top-users__rank {
  font-weight: 700;
  color: #94a3b8;
  font-size: 0.85rem;
}

.workpoint-top-users--dark .workpoint-top-users__level {
  font-size: 0.7rem;
  font-weight: 700;
  color: rgba(138, 43, 226, 0.95);
  padding: 3px 8px;
  border-radius: 8px;
  background: rgba(138, 43, 226, 0.2);
  border: 1px solid rgba(138, 43, 226, 0.35);
  text-align: center;
}

.workpoint-top-users__progress-wrap {
  height: 6px;
  background: rgba(0, 0, 0, 0.3);
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.06);
}

.workpoint-top-users__progress-bar {
  height: 100%;
  border-radius: 8px;
  background: linear-gradient(90deg, rgba(0, 242, 255, 0.6) 0%, rgba(138, 43, 226, 0.7) 100%);
  box-shadow: 0 0 10px rgba(0, 242, 255, 0.3);
  transition: width 0.4s ease;
}

.workpoint-top-users--dark .workpoint-top-users__points {
  color: rgba(0, 242, 255, 0.95);
  font-weight: 700;
  text-shadow: 0 0 6px rgba(0, 242, 255, 0.3);
}

.workpoint-top-users__xp-label {
  font-size: 0.75rem;
  opacity: 0.85;
}

.workpoint-top-users--dark .workpoint-top-users__empty {
  background: rgba(0, 242, 255, 0.03);
  border: 1px dashed rgba(0, 242, 255, 0.2);
  color: #94a3b8;
}

/* Rule page game style (dark) */
.workpoint-top-users--dark .workpoint-top-users__rule-title {
  color: rgba(0, 242, 255, 0.95);
  font-family: 'Orbitron', sans-serif;
  font-weight: 700;
  text-shadow: 0 0 10px rgba(0, 242, 255, 0.3);
}

.workpoint-top-users--dark .workpoint-top-users__rule-back {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(0, 242, 255, 0.25);
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__rule-back:hover {
  background: rgba(0, 242, 255, 0.1);
  border-color: rgba(0, 242, 255, 0.4);
  box-shadow: 0 0 10px rgba(0, 242, 255, 0.2);
}

.workpoint-top-users--dark .workpoint-top-users__rule-card {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(0, 242, 255, 0.2);
  border-radius: 10px;
  box-shadow: 0 0 15px rgba(0, 242, 255, 0.08);
}

.workpoint-top-users--dark .workpoint-top-users__rule-card:hover {
  border-color: rgba(0, 242, 255, 0.35);
  box-shadow: 0 0 20px rgba(0, 242, 255, 0.15);
}

.workpoint-top-users--dark .workpoint-top-users__rule-index {
  color: rgba(138, 43, 226, 0.9);
  font-weight: 800;
}

.workpoint-top-users--dark .workpoint-top-users__rule-desc {
  color: #e6eef6;
}

.workpoint-top-users--dark .workpoint-top-users__rule-meta {
  color: #94a3b8;
}

.workpoint-top-users--dark .workpoint-top-users__rule-points {
  color: rgba(0, 242, 255, 0.95);
  font-weight: 700;
  text-shadow: 0 0 6px rgba(0, 242, 255, 0.3);
}

.workpoint-top-users--dark .workpoint-top-users__rule-period,
.workpoint-top-users--dark .workpoint-top-users__rule-cap {
  color: rgba(138, 43, 226, 0.9);
}

@media (max-width: 640px) {
  .workpoint-top-users__podium {
    grid-template-columns: 1fr;
  }
  .workpoint-top-users--dark .workpoint-top-users__item {
    grid-template-columns: 28px 1fr 48px minmax(50px, 1fr) auto;
    gap: 8px;
    padding: 10px 12px;
  }
}
</style>
