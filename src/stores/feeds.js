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
				await this.loadFeeds()
				const feed = this.feeds.find(f => f.id === id)
				if (feed?.lastStatus === 1) {
					showError(t('calendarbridge', 'Sync failed: {error}', { error: feed.lastError }))
				} else if (data.held > 0) {
					showError(t('calendarbridge', 'Sync completed, but {held} deletions are on hold. Please review the feed.', data))
				} else {
					showSuccess(
						t('calendarbridge', 'Sync completed: {added} added, {updated} updated, {deleted} deleted', data)
					)
				}
			} catch (e) {
				const msg = e.response?.data?.error ?? t('calendarbridge', 'Sync failed')
				showError(msg)
			} finally {
				this.syncing = { ...this.syncing, [id]: false }
			}
		},

		async resolveQuarantine(id, action) {
			this.syncing = { ...this.syncing, [id]: true }
			try {
				const { data } = await feedsApi.quarantine(id, action)
				await this.loadFeeds()
				const feed = this.feeds.find(f => f.id === id)
				if (action === 'reject') {
					showSuccess(t('calendarbridge', 'The events are kept.'))
				} else if (feed?.quarantine) {
					showError(t('calendarbridge', 'The feed changed in the meantime. Please review the new result.'))
				} else {
					showSuccess(t('calendarbridge', 'Change applied: {deleted} events removed.', data))
				}
			} catch (e) {
				showError(e.response?.data?.error ?? t('calendarbridge', 'Action failed'))
			} finally {
				this.syncing = { ...this.syncing, [id]: false }
			}
		},
	},
})
