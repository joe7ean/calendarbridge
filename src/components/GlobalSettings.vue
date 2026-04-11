<template>
	<div class="calbridge-global-settings">
		<h3>{{ t('calendarbridge', 'Global settings') }}</h3>

		<NcTextField
			:label="t('calendarbridge', 'Default service user (calendar owner)')"
			:placeholder="t('calendarbridge', 'e.g. calbridge-service')"
			v-model="form.default_service_user"
		/>

		<div class="calbridge-form__row calbridge-form__row--align">
			<label>{{ t('calendarbridge', 'Default sync interval') }}</label>
			<NcSelect
				v-model="form.default_sync_interval"
				:options="intervalOptions"
				:reduce="o => o.value"
				:get-option-label="o => o.label"
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
import { settingsApi } from '../services/api.js'

export default {
	name: 'GlobalSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextField },
	props: {
		settings: { type: Object, required: true },
	},
	emits: ['saved', 'close'],

	data() {
		return {
			form: { ...this.settings },
			saving: false,
			saveError: '',
		}
	},

	computed: {
		intervalOptions() {
			return [
				{ value: 900,   label: t('calendarbridge', '15 minutes') },
				{ value: 1800,  label: t('calendarbridge', '30 minutes') },
				{ value: 3600,  label: t('calendarbridge', '1 hour') },
				{ value: 7200,  label: t('calendarbridge', '2 hours') },
				{ value: 21600, label: t('calendarbridge', '6 hours') },
				{ value: 43200, label: t('calendarbridge', '12 hours') },
				{ value: 86400, label: t('calendarbridge', '24 hours') },
			]
		},
	},

	methods: {
		t,
		async save() {
			this.saving = true
			this.saveError = ''
			try {
				await settingsApi.save(this.form)
				this.$emit('saved', { ...this.form })
			} catch (e) {
				this.saveError = e.response?.data?.error ?? t('calendarbridge', 'Could not save settings')
			} finally {
				this.saving = false
			}
		},
	},
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
