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
				:class="{
					'calbridge-feed-card--error': feed.lastStatus === 1,
					'calbridge-feed-card--quarantine': !!feed.quarantine,
					'calbridge-feed-card--disabled': !feed.active,
				}"
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

					<div v-if="feed.quarantine" class="calbridge-feed-card__quarantine">
						<div>
							<IconShieldAlert :size="16" />
							<template v-if="feed.quarantine.reason === 'empty'">
								{{ t('calendarbridge', 'The feed is suddenly empty. Its {held} events are kept for now.', { held: feed.quarantine.held }) }}
							</template>
							<template v-else>
								{{ t('calendarbridge', 'The feed would remove {held} events at once. They are kept for now.', { held: feed.quarantine.held }) }}
							</template>
						</div>
						<div class="calbridge-feed-card__hint">
							<template v-if="feed.quarantine.rejected">
								{{ t('calendarbridge', 'You rejected this change. The events stay until the feed changes again.') }}
							</template>
							<template v-else>
								{{ t('calendarbridge', 'The change is applied automatically once the feed returns the same result for a day (since {date}). You can apply or reject it now.', { date: formatDate(feed.quarantine.since) }) }}
							</template>
						</div>
						<div class="calbridge-feed-card__quarantine-actions">
							<NcButton type="error" :disabled="syncing[feed.id]" @click="$emit('quarantine', feed.id, 'apply')">
								{{ t('calendarbridge', 'Apply change') }}
							</NcButton>
							<NcButton v-if="!feed.quarantine.rejected" type="secondary" :disabled="syncing[feed.id]" @click="$emit('quarantine', feed.id, 'reject')">
								{{ t('calendarbridge', 'Keep events') }}
							</NcButton>
						</div>
					</div>

					<div v-if="feed.lastStatus === 1" class="calbridge-feed-card__error">
						<IconAlertCircle :size="16" />
						{{ feed.lastError }}
						<span v-if="feed.lastSyncedAt" class="calbridge-feed-card__timestamp">
							({{ formatDate(feed.lastSyncedAt) }})
						</span>
						<span v-if="feed.nextAttemptAt" class="calbridge-feed-card__timestamp">
							{{ t('calendarbridge', 'Next attempt: {date}', { date: formatDate(feed.nextAttemptAt) }) }}
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
import IconShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'FeedList',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, IconPlus, IconCalendar, IconRefresh, IconPencil, IconDelete, IconAlertCircle, IconShieldAlert },
	props: {
		feeds: { type: Array, required: true },
		syncing: { type: Object, default: () => ({}) },
		loading: { type: Boolean, default: false },
	},
	emits: ['add', 'edit', 'delete', 'sync', 'quarantine'],

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
.calbridge-feed-card--quarantine {
	border-color: var(--color-warning);
}
.calbridge-feed-card__quarantine {
	margin-top: 6px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background: var(--color-warning-hover, var(--color-background-dark));
	font-size: 0.85em;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.calbridge-feed-card__quarantine > div:first-child {
	display: flex;
	align-items: center;
	gap: 4px;
	font-weight: 600;
}
.calbridge-feed-card__hint {
	color: var(--color-text-maxcontrast);
}
.calbridge-feed-card__quarantine-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
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
