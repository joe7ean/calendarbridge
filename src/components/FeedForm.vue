<template>
	<div class="calbridge-form">
		<h3>{{ isEdit ? t('calendarbridge', 'Edit feed') : t('calendarbridge', 'Add feed') }}</h3>

		<!-- Feed URL + Test -->
		<div class="calbridge-form__row">
			<NcTextField
				:label="t('calendarbridge', 'Feed URL')"
				:placeholder="'https://example.org/remote.php/dav/public-calendars/TOKEN/'"
				v-model="form.feedUrl"
				:error="!!urlError"
				:helper-text="urlError || previewInfo"
			/>
			<NcButton
				type="secondary"
				:disabled="!form.feedUrl || previewing"
				@click="testUrl"
			>
				<template #icon>
					<NcLoadingIcon v-if="previewing" :size="20" />
					<IconEye v-else :size="20" />
				</template>
				{{ t('calendarbridge', 'Test') }}
			</NcButton>
		</div>

		<!-- Calendar name -->
		<NcTextField
			:label="t('calendarbridge', 'Calendar name')"
			:placeholder="t('calendarbridge', 'Shown to group members')"
			v-model="form.feedName"
		/>

		<!-- Color -->
		<div class="calbridge-form__row calbridge-form__row--align">
			<label>{{ t('calendarbridge', 'Calendar color') }}</label>
			<input type="color" v-model="form.calendarColor" class="calbridge-color-input" />
		</div>

		<!-- Service user -->
		<NcSelect
			:label="t('calendarbridge', 'Service user (calendar owner)')"
			:options="users"
			:get-option-label="u => `${u.displayName} (${u.uid})`"
			:reduce="u => u.uid"
			v-model="form.serviceUser"
			:loading="loadingUsers"
			@search="searchUsers"
		/>

		<!-- Target groups -->
		<NcSelect
			:label="t('calendarbridge', 'Share read-only with groups')"
			:options="groups"
			:get-option-label="g => g.displayName"
			:reduce="g => g.id"
			v-model="form.targetGroups"
			multiple
			:loading="loadingGroups"
			@search="searchGroups"
		/>

		<!-- Sync interval -->
		<div class="calbridge-form__row calbridge-form__row--align">
			<label>{{ t('calendarbridge', 'Sync interval') }}</label>
			<NcSelect
				v-model="form.syncInterval"
				:options="intervalOptions"
				:reduce="o => o.value"
				:get-option-label="o => o.label"
				style="min-width: 180px"
			/>
		</div>

		<!-- Optional: HTTP Basic Auth -->
		<details class="calbridge-form__details">
			<summary>{{ t('calendarbridge', 'HTTP authentication (optional)') }}</summary>
			<div class="calbridge-form__auth">
				<NcTextField
					:label="t('calendarbridge', 'Username')"
					v-model="form.httpAuthUser"
					autocomplete="off"
				/>
				<NcPasswordField
					:label="t('calendarbridge', 'Password')"
					v-model="form.httpAuthPass"
					autocomplete="new-password"
					:placeholder="isEdit && feed.hasHttpAuth ? '••••••' : ''"
				/>
				<NcCheckboxRadioSwitch
					v-if="isEdit && feed.hasHttpAuth"
					v-model="form.clearHttpAuth"
					type="checkbox"
				>
					{{ t('calendarbridge', 'Remove stored credentials') }}
				</NcCheckboxRadioSwitch>
			</div>
		</details>

		<!-- Privacy & SSL -->
		<div class="calbridge-form__toggles">
			<NcCheckboxRadioSwitch v-model="form.stripAttendees" type="checkbox">
				{{ t('calendarbridge', 'Remove attendee/organizer data (recommended)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.verifySsl" type="checkbox">
				{{ t('calendarbridge', 'Verify SSL certificate') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.active" type="checkbox">
				{{ t('calendarbridge', 'Active') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div v-if="saveError" class="calbridge-form__error">{{ saveError }}</div>

		<div class="calbridge-form__buttons">
			<NcButton type="primary" :disabled="saving || !isValid" @click="save">
				<template #icon><NcLoadingIcon v-if="saving" :size="20" /></template>
				{{ t('calendarbridge', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('cancel')">{{ t('calendarbridge', 'Cancel') }}</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import IconEye from 'vue-material-design-icons/Eye.vue'
import { translate as t } from '@nextcloud/l10n'
import { feedsApi, groupsApi, usersApi } from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'FeedForm',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcPasswordField, NcSelect, NcTextField, IconEye },
	props: {
		feed: { type: Object, default: null },
		settings: { type: Object, default: () => ({}) },
	},
	emits: ['saved', 'cancel'],

	data() {
		return {
			form: this.buildForm(),
			groups: [],
			users: [],
			loadingGroups: false,
			loadingUsers: false,
			previewing: false,
			previewInfo: '',
			urlError: '',
			saving: false,
			saveError: '',
		}
	},

	computed: {
		isEdit() { return !!this.feed },
		isValid() {
			return this.form.feedUrl && this.form.feedName && this.form.serviceUser
		},
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

	async mounted() {
		await Promise.all([this.loadGroups(), this.loadUsers()])
	},

	methods: {
		t,

		buildForm() {
			const f = this.feed
			return {
				feedUrl:        f?.feedUrl ?? '',
				feedName:       f?.feedName ?? '',
				calendarColor:  f?.calendarColor ?? '#0082c9',
				serviceUser:    f?.serviceUser ?? (this.settings?.default_service_user ?? ''),
				targetGroups:   f?.targetGroups ?? [],
				syncInterval:   f?.syncInterval ?? (this.settings?.default_sync_interval ?? 3600),
				stripAttendees: f?.stripAttendees ?? (this.settings?.strip_attendees_default ?? true),
				verifySsl:      f?.verifySsl ?? true,
				active:         f?.active ?? true,
				httpAuthUser:   f?.httpAuthUser ?? '',
				httpAuthPass:   '',
				clearHttpAuth:  false,
			}
		},

		async loadGroups(search = '') {
			this.loadingGroups = true
			try {
				const { data } = await groupsApi.list(search)
				this.groups = data
			} finally {
				this.loadingGroups = false
			}
		},

		async loadUsers(search = '') {
			this.loadingUsers = true
			try {
				const { data } = await usersApi.list(search)
				this.users = data
			} finally {
				this.loadingUsers = false
			}
		},

		searchGroups: debounce(function(search) { this.loadGroups(search) }, 300),
		searchUsers:  debounce(function(search) { this.loadUsers(search) }, 300),

		async testUrl() {
			this.previewing = true
			this.urlError = ''
			this.previewInfo = ''
			try {
				const { data } = await feedsApi.preview({
					url: this.form.feedUrl,
					httpAuthUser: this.form.httpAuthUser || null,
					httpAuthPass: this.form.httpAuthPass || null,
					verifySsl: this.form.verifySsl,
				})
				const { eventCount, calendarName, minDate, maxDate } = data
				const name = calendarName ? `"${calendarName}" · ` : ''
				const range = minDate
					? ` · ${new Date(minDate).toLocaleDateString()} – ${new Date(maxDate).toLocaleDateString()}`
					: ''
				this.previewInfo = `✓ ${name}${eventCount} events${range}`
				if (calendarName && !this.form.feedName) {
					this.form.feedName = calendarName
				}
			} catch (e) {
				this.urlError = e.response?.data?.error ?? t('calendarbridge', 'Could not connect to feed URL')
			} finally {
				this.previewing = false
			}
		},

		async save() {
			this.saving = true
			this.saveError = ''
			try {
				const payload = {
					...this.form,
					httpAuthPass: this.form.httpAuthPass || undefined,
					clearHttpAuth: this.form.clearHttpAuth || undefined,
				}
				if (this.isEdit) {
					await feedsApi.update(this.feed.id, payload)
				} else {
					await feedsApi.create(payload)
				}
				this.$emit('saved')
			} catch (e) {
				this.saveError = e.response?.data?.error ?? t('calendarbridge', 'Could not save feed')
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
.calbridge-form {
	min-width: 340px;
	max-width: 500px;
}
.calbridge-form__row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}
.calbridge-form__row--align {
	align-items: center;
	gap: 12px;
}
.calbridge-color-input {
	width: 44px;
	height: 36px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	padding: 2px;
}
.calbridge-form__details {
	margin-top: 8px;
}
.calbridge-form__details summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 8px;
}
.calbridge-form__auth {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}
.calbridge-form__toggles {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
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
