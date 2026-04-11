<template>
	<div class="calbridge-feed-list">
		<div class="calbridge-feed-list__header">
			<h3>{{ t('calendarbridge', 'Configured feeds') }}</h3>
			<NcButton type="primary" @click="$emit('add')">
				<template #icon><IconPlus :size="20" /></template>
				{{ t('calendarbridge', 'Add feed') }}
			</NcButton>
		</div>

		<NcEmptyContent
			v-if="!loading && feeds.length === 0"
			:name="t('calendarbridge', 'No feeds configured')"
			:description="t('calendarbridge', 'Add an external iCal feed to get started.')"
		>
			<template #icon><IconCalendar :size="64" /></template>
		</NcEmptyContent>

		<NcLoadingIcon v-else-if="loading" />

		<ul v-else class="calbridge-feed-list__items">
			<li
				v-for="feed in feeds"
				:key="feed.id"
				class="calbridge-feed-card"
				:class="{ 'calbridge-feed-card--error': feed.lastStatus === 1, 'calbridge-feed-card--disabled': !feed.active }"
			>
				<div class="calbridge-feed-card__color" :style="{ background: feed.calendarColor }" />

				<div class="calbridge-feed-card__body">
					<div class="calbridge-feed-card__title">
						{{ feed.feedName }}
						<span v-if="!feed.active" class="calbridge-badge calbridge-badge--grey">
							{{ t('calendarbridge', 'Paused') }}
						</span>
						<span v-if="feed.duplicateUrlWarning" class="calbridge-badge calbridge-badge--warning" :title="t('calendarbridge', 'This URL is used by another feed')">
							{{ t('calendarbridge', 'Duplicate URL') }}
						</span>
					</div>

					<div class="calbridge-feed-card__meta">
						<span class="calbridge-feed-card__url" :title="feed.feedUrl">{{ feed.feedUrl }}</span>
						<span>·</span>
						<span>{{ t('calendarbridge', 'Groups: {groups}', { groups: (feed.targetGroups || []).join(', ') || t('calendarbridge', 'none') }) }}</span>
						<span>·</span>
						<span>{{ formatInterval(feed.syncInterval) }}</span>
					</div>

					<div v-if="feed.lastStatus === 1" class="calbridge-feed-card__error">
						<IconAlertCircle :size="16" />
						{{ feed.lastError }}
						<span v-if="feed.lastSyncedAt" class="calbridge-feed-card__timestamp">
							({{ formatDate(feed.lastSyncedAt) }})
						</span>
					</div>

					<div v-else-if="feed.lastSyncedAt" class="calbridge-feed-card__synced">
						{{ t('calendarbridge', 'Last synced: {date}', { date: formatDate(feed.lastSyncedAt) }) }}
					</div>
				</div>

				<div class="calbridge-feed-card__actions">
					<NcButton
						type="tertiary"
						:title="t('calendarbridge', 'Sync now')"
						:disabled="syncing[feed.id]"
						@click="$emit('sync', feed.id)"
					>
						<template #icon>
							<NcLoadingIcon v-if="syncing[feed.id]" :size="20" />
							<IconRefresh v-else :size="20" />
						</template>
					</NcButton>
					<NcButton type="tertiary" :title="t('calendarbridge', 'Edit')" @click="$emit('edit', feed)">
						<template #icon><IconPencil :size="20" /></template>
					</NcButton>
					<NcButton type="tertiary-no-background" :title="t('calendarbridge', 'Delete')" @click="$emit('delete', feed)">
						<template #icon><IconDelete :size="20" /></template>
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconCalendar from 'vue-material-design-icons/Calendar.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'FeedList',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, IconPlus, IconCalendar, IconRefresh, IconPencil, IconDelete, IconAlertCircle },
	props: {
		feeds: { type: Array, required: true },
		syncing: { type: Object, default: () => ({}) },
		loading: { type: Boolean, default: false },
	},
	emits: ['add', 'edit', 'delete', 'sync'],

	methods: {
		t,

		formatInterval(seconds) {
			if (!seconds || isNaN(seconds)) return ''
			if (seconds < 3600) return t('calendarbridge', 'Every {min} min', { min: seconds / 60 })
			if (seconds < 86400) return t('calendarbridge', 'Every {h} h', { h: seconds / 3600 })
			return t('calendarbridge', 'Every {d} days', { d: Math.round(seconds / 86400) })
		},

		formatDate(isoString) {
			if (!isoString) return ''
			return new Date(isoString).toLocaleString()
		},
	},
}
</script>

<style scoped>
.calbridge-feed-list {
	flex: 1;
	min-width: 320px;
}
.calbridge-feed-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}
.calbridge-feed-list__items {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.calbridge-feed-card {
	display: flex;
	align-items: center;
	gap: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
}
.calbridge-feed-card--error {
	border-color: var(--color-error);
}
.calbridge-feed-card--disabled {
	opacity: 0.6;
}
.calbridge-feed-card__color {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex-shrink: 0;
}
.calbridge-feed-card__body {
	flex: 1;
	min-width: 0;
}
.calbridge-feed-card__title {
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}
.calbridge-feed-card__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
.calbridge-feed-card__url {
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
	vertical-align: bottom;
}
.calbridge-feed-card__error {
	color: var(--color-error);
	font-size: 0.85em;
	margin-top: 4px;
	display: flex;
	align-items: center;
	gap: 4px;
}
.calbridge-feed-card__synced {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	margin-top: 2px;
}
.calbridge-feed-card__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}
.calbridge-badge {
	font-size: 0.75em;
	padding: 2px 8px;
	border-radius: 99px;
	font-weight: 500;
}
.calbridge-badge--grey {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
.calbridge-badge--warning {
	background: var(--color-warning);
	color: #fff;
}
</style>
