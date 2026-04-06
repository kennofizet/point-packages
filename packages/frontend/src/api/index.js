import axios from 'axios'

/**
 * Create workpoint API client (zones from core, top ranking from workpoint backend).
 * Same pattern as rewardplay-packages frontend api: coreUrl for zones, backend URL for workpoint.
 *
 * @param {string} coreUrl - Base URL for core API (e.g. packages-core; used for /player/zones)
 * @param {string} workpointUrl - Base URL for workpoint backend API (used for /top)
 * @param {string} token - Auth token (X-Knf-Token)
 * @returns {Object} API client with getZones, getTop; requests send X-Knf-Token and X-Knf-Zone-Id
 */
export function createWorkpointApi(coreUrl, workpointUrl, token) {
  if (!token) {
    throw new Error('Workpoint API: token is required')
  }

  const defaultHeaders = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Knf-Token': token,
  }

  // Axios instance for core (zones) – no baseURL so we pass full URL per call
  const coreApi = axios.create({
    headers: { ...defaultHeaders },
  })

  // Axios instance for workpoint backend – add X-Knf-Zone-Id from selected zone
  const workpointApi = axios.create({
    baseURL: workpointUrl ? workpointUrl.replace(/\/$/, '') : '',
    headers: { ...defaultHeaders },
  })

  workpointApi.interceptors.request.use((config) => {
    try {
      const selectedZone = localStorage.getItem('selected_zone')
      if (selectedZone) {
        const zone = JSON.parse(selectedZone)
        if (zone && zone.id) {
          config.headers = config.headers || {}
          config.headers['X-Knf-Zone-Id'] = zone.id.toString()
        }
      }
    } catch (e) {
      console.warn('Workpoint API: failed to get zone from localStorage', e)
    }
    return config
  })

  return {
    /** Zones the current user can view (from core, same as rewardplay). */
    getZones: () => coreApi.get(coreUrl ? (coreUrl.replace(/\/$/, '') + '/player/zones') : ''),
    /** Top users in period (workpoint backend; zone from X-Knf-Zone-Id). */
    getTop: (period = 'week', limit = 10) =>
      workpointApi.get('/top', { params: { period, limit } }),
    /** Rules (workpoint cases) for rule page. Zone from X-Knf-Zone-Id. Returns { rules, language, isManager }. */
    getRules: (language = 'vi') =>
      workpointApi.get('/rules', { params: { language } }),
    /** Save one zone case override (manager). Zone from X-Knf-Zone-Id. Body: case_key, points, check, period?, cap?, limit_period?, limit_period_time?, descriptions? */
    saveRule: (payload) => workpointApi.post('/rules/save', payload),
    /** Reset zone rules to default (manager). Zone from X-Knf-Zone-Id. */
    resetZoneRules: () => workpointApi.post('/rules/reset', {}),
    /** Current user summary: totals + ranks + today_by_rule + isManager */
    getHistoryMeSummary: (language = 'vi') =>
      workpointApi.get('/history/me/summary', { params: { language } }),
    /** Current user logs only (cursor pagination). Params: period, cursor (optional record id), language */
    getHistoryMeLogs: (period = 'week', cursor = null, language = 'vi') =>
      workpointApi.get('/history/me/logs', {
        params: { period, ...(cursor != null && cursor !== '' ? { cursor } : {}), language },
      }),
    /** One user summary (manager or self). */
    getHistoryUserSummary: (userId, language = 'vi') =>
      workpointApi.get(`/history/user/${userId}/summary`, { params: { language } }),
    /** One user logs only (cursor pagination). */
    getHistoryUserLogs: (userId, period = 'week', cursor = null, language = 'vi') =>
      workpointApi.get(`/history/user/${userId}/logs`, {
        params: { period, ...(cursor != null && cursor !== '' ? { cursor } : {}), language },
      }),
    /** Cursor-paginated members in zone (manager only). */
    getAdminMembers: (cursor = null) =>
      workpointApi.get('/admin/members', {
        params: cursor != null && cursor !== '' ? { cursor } : {},
      }),
  }
}
