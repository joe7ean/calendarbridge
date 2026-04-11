<template>
	<NcAppContent>
		<div class="calbridge-header">
			<h2>{{ t('calendarbridge', 'Calendar Bridge') }}</h2>
			<p class="calbridge-subtitle">
				{{ t('calendarbridge', 'Subscribe to external iCal feeds and share them read-only with groups.') }}
			</p>
		</div>

		<div class="calbridge-layout">
			<FeedList
				:feeds="feedsStore.feeds"
				:syncing="feedsStore.syncing"
				:loading="feedsStore.loading"
				@add="showForm(null)"
				@edit="showForm"
				@delete="confirmDelete"
				@sync="feedsStore.manualSync"
			/>

			<FeedForm
				v-if="formVisible"
				:feed="editingFeed"
				:settings="feedsStore.settings"
				@saved="onSaved"
				@cancel="formVisible = false"
			/>

			<GlobalSettings
				v-if="settingsVisible"
				:settings="feedsStore.settings"
				@saved="onSettingsSaved"
				@close="settingsVisible = false"
			/>
		</div>

		<div class="calbridge-footer-actions">
			<NcButton @click="settingsVisible = true" type="secondary">
				<template #icon><IconSettings :size="20" /></template>
				{{ t('calendarbridge', 'Global settings') }}
			</NcButton>
		</div>

		<NcDialog
			v-if="deleteTarget"
			:name="t('calendarbridge', 'Delete feed?')"
			:open="true"
			@closing="deleteTarget = null"
		>
			<p>
				{{ t('calendarbridge', 'This will delete the relay calendar and all its events. This cannot be undone.') }}
			</p>
			<template #actions>
				<NcButton type="error" @click="doDelete">
					{{ t('calendarbridge', 'Delete') }}
				</NcButton>
				<NcButton @click="deleteTarget = null">
					{{ t('calendarbridge', 'Cancel') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcDialog } from '@nextcloud/vue'
import IconSettings from 'vue-material-design-icons/Cog.vue'
import { translate as t } from '@nextcloud/l10n'
import { useFeedsStore } from './stores/feeds.js'
import FeedList from './components/FeedList.vue'
import FeedForm from './components/FeedForm.vue'
import GlobalSettings from './components/GlobalSettings.vue'

export default {
	name: 'App',
	components: { NcAppContent, NcButton, NcDialog, IconSettings, FeedList, FeedForm, GlobalSettings },

	setup() {
		const feedsStore = useFeedsStore()
		return { feedsStore }
	},

	data() {
		return {
			formVisible: false,
			settingsVisible: false,
			editingFeed: null,
			deleteTarget: null,
		}
	},

	async mounted() {
		await Promise.all([
			this.feedsStore.loadFeeds(),
			this.feedsStore.loadSettings(),
		])
	},

	methods: {
		t,

		showForm(feed = null) {
			this.editingFeed = feed
			this.formVisible = true
			this.settingsVisible = false
		},

		async onSaved() {
			this.formVisible = false
			await this.feedsStore.loadFeeds()
		},

		async onSettingsSaved(settings) {
			this.feedsStore.settings = settings
			this.settingsVisible = false
		},

		confirmDelete(feed) {
			this.deleteTarget = feed
		},

		async doDelete() {
			if (!this.deleteTarget) return
			await this.feedsStore.deleteFeed(this.deleteTarget.id)
			this.deleteTarget = null
		},
	},
}
</script>

<style scoped>
.calbridge-header {
	padding: 20px 20px 0;
}
.calbridge-subtitle {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
.calbridge-layout {
	display: flex;
	gap: 24px;
	padding: 20px;
	flex-wrap: wrap;
}
.calbridge-footer-actions {
	padding: 0 20px 20px;
}
</style>
