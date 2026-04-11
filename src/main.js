import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'

const el = document.getElementById('calendarbridge-root')
if (el) {
	const app = createApp(App)
	app.use(createPinia())
	app.mount(el)
}
