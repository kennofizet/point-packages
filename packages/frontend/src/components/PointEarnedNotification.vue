<template>
  <div v-if="visible" class="workpoint-point-earned" :class="`workpoint-point-earned--${variant}`">
    <span class="workpoint-point-earned__icon">{{ icon }}</span>
    <span class="workpoint-point-earned__text">
      <slot>
        {{ message || `+${points} workpoint${points !== 1 ? 's' : ''}` }}
      </slot>
    </span>
  </div>
</template>

<script>
import { ref, watch } from 'vue'

export default {
  name: 'PointEarnedNotification',
  props: {
    /** Points earned (controls visibility when using v-model:show) */
    points: { type: Number, default: 0 },
    /** Optional action key for custom message */
    actionKey: { type: String, default: '' },
    /** Custom message (overrides default "+N workpoints") */
    message: { type: String, default: '' },
    /** Show/hide (use with v-model:show or single-shot display) */
    show: { type: Boolean, default: false },
    /** Visual variant: success | info */
    variant: { type: String, default: 'success' },
    /** Auto-hide after ms (0 = no auto-hide) */
    autoHideMs: { type: Number, default: 3000 },
  },
  emits: ['update:show'],
  setup(props, { emit }) {
    const visible = ref(props.show)
    const icon = props.variant === 'success' ? '★' : 'ℹ'

    watch(() => props.show, v => { visible.value = v })
    watch(visible, v => emit('update:show', v))

    watch(() => props.points, (val) => {
      if (val > 0) {
        visible.value = true
        if (props.autoHideMs > 0) {
          setTimeout(() => {
            visible.value = false
            emit('update:show', false)
          }, props.autoHideMs)
        }
      }
    }, { immediate: true })

    return { visible, icon }
  },
}
</script>

<style scoped>
.workpoint-point-earned {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
}
.workpoint-point-earned--success {
  background: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #a5d6a7;
}
.workpoint-point-earned--info {
  background: #e3f2fd;
  color: #1565c0;
  border: 1px solid #90caf9;
}
.workpoint-point-earned__icon {
  font-size: 16px;
}
</style>
