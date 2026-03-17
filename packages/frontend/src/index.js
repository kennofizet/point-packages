/**
 * @kennofizet/workpoint-frontend
 *
 * Workpoint UI: top users ranking (day/week/month/year) and point-earned notification.
 * Zones from core (same as rewardplay); top ranking from workpoint-backend. Host app provides
 * coreUrl, backendUrl and token.
 */
import { createWorkpointApi } from './api'
import TopUsersRanking from './components/TopUsersRanking.vue'
import PointEarnedNotification from './components/PointEarnedNotification.vue'

/**
 * @param {Object} app - Vue app
 * @param {Object} options - { coreUrl, backendUrl, token }
 */
export function installWorkpointModule(app, options = {}) {
  if (options.coreUrl != null && options.backendUrl != null && options.token) {
    const workpointApi = createWorkpointApi(options.coreUrl, options.backendUrl, options.token)
    app.provide('workpointApi', workpointApi)
    app.config.globalProperties.$workpointApi = workpointApi
  }
  app.component('WorkpointTopUsersRanking', TopUsersRanking)
  app.component('WorkpointPointEarnedNotification', PointEarnedNotification)
}

export {
  createWorkpointApi,
  TopUsersRanking,
  PointEarnedNotification,
}

export default {
  install: installWorkpointModule,
}
