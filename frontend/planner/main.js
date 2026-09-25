import { createApp } from 'vue'
import Planner from './Planner.vue'

const el = document.getElementById('planner')
if (el) {
  createApp(Planner, { ...el.dataset }).mount(el)
}
