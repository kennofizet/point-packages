/**
 * @company/workpoint-frontend
 *
 * Workpoint UI: top users ranking (day/week/month/year) and point-earned notification.
 * Consumes workpoint-backend API (top endpoint). Host app provides API base URL and auth.
 */
import TopUsersRanking from './components/TopUsersRanking.vue'
import PointEarnedNotification from './components/PointEarnedNotification.vue'

export function installWorkpointModule(app, options = {}) {
  if (options.apiConfig) {
    app.provide('workpointApi', options.apiConfig)
    app.config.globalProperties.$workpointApi = options.apiConfig
  }
  app.component('WorkpointTopUsersRanking', TopUsersRanking)
  app.component('WorkpointPointEarnedNotification', PointEarnedNotification)
}

export {
  TopUsersRanking,
  PointEarnedNotification,
}

export default {
  install: installWorkpointModule,
}
