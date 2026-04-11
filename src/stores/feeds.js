import { defineStore } from 'pinia'
import { feedsApi, settingsApi } from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export const useFeedsStore = defineStore('feeds', {
	state: () => ({
		feeds: [],
		settings: {},
		loading: false,
		syncing: {},   // feedId → true while syncing
	}),

	actions: {
		async loadFeeds() {
			this.loading = true
			try {
				const { data } = await feedsApi.list()
				this.feeds = data
			} catch (e) {
				showError(t('calendarbridge', 'Could not load feeds'))
			} finally {
				this.loading = false
			}
		},

		async loadSettings() {
			try {
				const { data } = await settingsApi.get()
				this.settings = data
			} catch (e) {
				showError(t('calendarbridge', 'Could not load settings'))
			}
		},

		async deleteFeed(id) {
			await feedsApi.delete(id)
			this.feeds = this.feeds.filter(f => f.id !== id)
			showSuccess(t('calendarbridge', 'Feed deleted'))
		},

		async manualSync(id) {
			this.syncing = { ...this.syncing, [id]: true }
			try {
				const { data } = await feedsApi.manualSync(id)
				showSuccess(
					t('calendarbridge', 'Sync completed: {added} added, {updated} updated, {deleted} deleted', data)
				)
				await this.loadFeeds()
			} catch (e) {
				const msg = e.response?.data?.error ?? t('calendarbridge', 'Sync failed')
				showError(msg)
			} finally {
				this.syncing = { ...this.syncing, [id]: false }
			}
		},
	},
})
