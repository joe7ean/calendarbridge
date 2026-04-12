<template>
	<div class="calbridge-global-settings">
		<h3>{{ t('calendarbridge', 'Global settings') }}</h3>

		<NcSelect
			:input-label="t('calendarbridge', 'Default service user (calendar owner)')"
			:options="users"
			label="label"
			v-model="form.default_service_user"
			:loading="loadingUsers"
			@search="searchUsers"
		/>

		<div class="calbridge-form__row calbridge-form__row--align">
			<label>{{ t('calendarbridge', 'Default sync interval') }}</label>
			<NcSelect
				v-model="form.default_sync_interval"
				:options="intervalOptions"
				label="label"
				style="min-width: 180px"
			/>
		</div>

		<NcTextField
			:label="t('calendarbridge', 'Maximum feed size (MB)')"
			v-model.number="form.max_feed_size_mb"
			type="number"
			:min="1"
			:max="100"
		/>

		<NcTextField
			:label="t('calendarbridge', 'HTTP request timeout (seconds)')"
			v-model.number="form.http_timeout"
			type="number"
			:min="5"
			:max="120"
		/>

		<div class="calbridge-form__toggles">
			<NcCheckboxRadioSwitch v-model="form.strip_attendees_default" type="checkbox">
				{{ t('calendarbridge', 'Remove attendee/organizer data by default (recommended)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.allow_http" type="checkbox">
				<span class="calbridge-warn-label">
					{{ t('calendarbridge', 'Allow HTTP feeds (not recommended — disables TLS protection)') }}
				</span>
			</NcCheckboxRadioSwitch>
		</div>

		<div v-if="saveError" class="calbridge-form__error">{{ saveError }}</div>

		<div class="calbridge-form__buttons">
			<NcButton type="primary" :disabled="saving" @click="save">
				<template #icon><NcLoadingIcon v-if="saving" :size="20" /></template>
				{{ t('calendarbridge', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('close')">{{ t('calendarbridge', 'Close') }}</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { settingsApi, usersApi } from '../services/api.js'

const INTERVAL_OPTIONS = [
	{ value: 900,   label: t('calendarbridge', '15 minutes') },
	{ value: 1800,  label: t('calendarbridge', '30 minutes') },
	{ value: 3600,  label: t('calendarbridge', '1 hour') },
	{ value: 7200,  label: t('calendarbridge', '2 hours') },
	{ value: 21600, label: t('calendarbridge', '6 hours') },
	{ value: 43200, label: t('calendarbridge', '12 hours') },
	{ value: 86400, label: t('calendarbridge', '24 hours') },
]

export default {
	name: 'GlobalSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextField },
	props: {
		settings: { type: Object, required: true },
	},
	emits: ['saved', 'close'],

	data() {
		return {
			form: {
				...this.settings,
				// Will be resolved to objects in mounted() after API loads
				default_service_user: null,
				default_sync_interval: INTERVAL_OPTIONS.find(o => o.value === (this.settings.default_sync_interval ?? 3600))
				                       ?? INTERVAL_OPTIONS[2],
			},
			users: [],
			loadingUsers: false,
			saving: false,
			saveError: '',
		}
	},

	computed: {
		intervalOptions() {
			return INTERVAL_OPTIONS
		},
	},

	async mounted() {
		await this.loadUsers()
	},

	methods: {
		t,

		async loadUsers(search = '') {
			this.loadingUsers = true
			try {
				const { data } = await usersApi.list(search)
				this.users = data.map(u => ({ ...u, label: `${u.displayName} (${u.uid})` }))
				// Resolve stored UID string to full object
				const stored = this.settings.default_service_user
				if (stored) {
					this.form.default_service_user = this.users.find(u => u.uid === stored) ?? null
				}
			} finally {
				this.loadingUsers = false
			}
		},

		searchUsers: debounce(function(search) { this.loadUsers(search) }, 300),

		async save() {
			this.saving = true
			this.saveError = ''
			try {
				const payload = {
					...this.form,
					default_service_user:   this.form.default_service_user?.uid ?? '',
					default_sync_interval:  this.form.default_sync_interval?.value ?? 3600,
				}
				await settingsApi.save(payload)
				this.$emit('saved', payload)
			} catch (e) {
				this.saveError = e.response?.data?.error ?? t('calendarbridge', 'Could not save settings')
			} finally {
				this.saving = false
			}
		},
	},
}

function debounce(fn, ms) {
	let timer
	return function(...args) {
		clearTimeout(timer)
		timer = setTimeout(() => fn.apply(this, args), ms)
	}
}
</script>

<style scoped>
.calbridge-global-settings {
	min-width: 320px;
	max-width: 460px;
}
.calbridge-warn-label {
	color: var(--color-warning-text);
}
.calbridge-form__row--align {
	display: flex;
	align-items: center;
	gap: 12px;
}
.calbridge-form__toggles {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 12px;
}
.calbridge-form__error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: 8px;
}
.calbridge-form__buttons {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
