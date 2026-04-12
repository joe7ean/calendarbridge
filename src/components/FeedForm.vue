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
			:input-label="t('calendarbridge', 'Service user (calendar owner)')"
			:options="users"
			label="label"
			v-model="form.serviceUser"
			:loading="loadingUsers"
			@search="searchUsers"
		/>

		<!-- Target groups -->
		<NcSelect
			:input-label="t('calendarbridge', 'Share read-only with groups')"
			:options="groups"
			label="displayName"
			v-model="form.targetGroups"
			multiple
			:loading="loadingGroups"
			@search="searchGroups"
		/>

		<NcNoteCard type="info" class="calbridge-share-note">
			{{ t('calendarbridge', 'Calendar Bridge manages group sharing automatically. Changes made via the Calendar app (e.g. removing the share) will be restored on the next sync. Group members can hide this calendar from their personal view using "Remove from my calendars" — this does not affect other members.') }}
		</NcNoteCard>

		<!-- Sync interval -->
		<div class="calbridge-form__row calbridge-form__row--align">
			<label>{{ t('calendarbridge', 'Sync interval') }}</label>
			<NcSelect
				v-model="form.syncInterval"
				:options="intervalOptions"
				label="label"
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
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import IconEye from 'vue-material-design-icons/Eye.vue'
import { translate as t } from '@nextcloud/l10n'
import { feedsApi, groupsApi, usersApi } from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

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
	name: 'FeedForm',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField, IconEye },
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
			return INTERVAL_OPTIONS
		},
	},

	async mounted() {
		await Promise.all([this.loadGroups(), this.loadUsers()])
	},

	methods: {
		t,

		buildForm() {
			const f = this.feed
			// For edit mode: find matching objects from loaded lists; fall back to plain values
			// until lists are loaded (watcher in mounted will re-resolve)
			return {
				feedUrl:        f?.feedUrl ?? '',
				feedName:       f?.feedName ?? '',
				calendarColor:  f?.calendarColor ?? '#0082c9',
				// NcSelect v9: store full objects; resolved against loaded lists in mounted()
				serviceUser:    null,
				targetGroups:   [],
			syncInterval:   INTERVAL_OPTIONS.find(o => o.value === (f?.syncInterval ?? this.settings?.default_sync_interval ?? 3600))
			                ?? INTERVAL_OPTIONS[2], // default: 1 hour
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
				// In edit mode: resolve stored group IDs to objects
				if (this.feed?.targetGroups?.length) {
					this.form.targetGroups = data.filter(g => this.feed.targetGroups.includes(g.id))
				}
			} finally {
				this.loadingGroups = false
			}
		},

		async loadUsers(search = '') {
			this.loadingUsers = true
			try {
				const { data } = await usersApi.list(search)
				// Add composite label for display
				this.users = data.map(u => ({ ...u, label: `${u.displayName} (${u.uid})` }))
				// In edit mode: resolve stored uid to object; for new: use default
				const defaultUid = this.feed?.serviceUser ?? this.settings?.default_service_user ?? ''
				if (defaultUid) {
					this.form.serviceUser = this.users.find(u => u.uid === defaultUid) ?? null
				}
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
					// Extract scalar values from NcSelect objects
					serviceUser:   this.form.serviceUser?.uid ?? this.form.serviceUser ?? '',
					targetGroups:  this.form.targetGroups.map(g => g.id ?? g),
					syncInterval:  this.form.syncInterval?.value ?? this.form.syncInterval ?? 3600,
					httpAuthPass:  this.form.httpAuthPass || undefined,
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
.calbridge-share-note {
	margin-top: 4px;
	margin-bottom: 4px;
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
