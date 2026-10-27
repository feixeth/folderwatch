<!--
  - SPDX-FileCopyrightText: 2026 ZEDD
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<li class="watch-row" :aria-busy="busy" :data-file-id="watch.fileId">
		<div class="watch-row__header">
			<FolderIcon :size="20" class="watch-row__folder-icon" />
			<a v-if="watch.accessible" :href="watch.url" class="watch-row__path">{{ watch.path }}</a>
			<span v-else class="watch-row__path watch-row__path--missing">{{ t('Unavailable folder') }}</span>

			<div class="watch-row__remove">
				<template v-if="!confirmingRemoval">
					<NcButton
						variant="tertiary"
						:aria-label="t('Stop watching {folder}', { folder: label })"
						:disabled="busy"
						@click="confirmingRemoval = true">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('Remove') }}
					</NcButton>
				</template>
				<template v-else>
					<NcButton variant="error" :disabled="busy" @click="$emit('remove')">
						{{ t('Yes, remove') }}
					</NcButton>
					<NcButton variant="tertiary" @click="confirmingRemoval = false">
						{{ t('Cancel') }}
					</NcButton>
				</template>
			</div>
		</div>

		<NcNoteCard v-if="!watch.accessible" type="warning">
			{{ t('This folder is not reachable any more: it may be in the trash or no longer shared with you. Alerts are paused and resume if it comes back.') }}
		</NcNoteCard>

		<div class="watch-row__options">
			<fieldset class="watch-row__group">
				<legend>{{ t('Scope') }}</legend>
				<NcCheckboxRadioSwitch
					:modelValue="watch.recursive"
					@update:modelValue="$emit('change', { recursive: $event })">
					{{ t('Include sub-folders') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="watch-row__group">
				<legend>{{ t('Alert me when something is') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="event in EVENTS"
					:key="event"
					:modelValue="watch.events.includes(event)"
					:disabled="watch.events.length === 1 && watch.events.includes(event)"
					@update:modelValue="toggle('events', event, $event)">
					{{ eventLabels[event] }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="watch-row__group">
				<legend>{{ t('Alert me by') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="channel in CHANNELS"
					:key="channel"
					:modelValue="watch.channels.includes(channel)"
					:disabled="(channel === 'mail' && mailUnavailable !== null) || (watch.channels.length === 1 && watch.channels.includes(channel))"
					@update:modelValue="toggle('channels', channel, $event)">
					{{ channelLabels[channel] }}
				</NcCheckboxRadioSwitch>
				<p v-if="mailUnavailable !== null" class="watch-row__hint" data-cy-mail-unavailable>
					{{ mailMessages[mailUnavailable] }}
				</p>
			</fieldset>

			<fieldset class="watch-row__group watch-row__group--frequency">
				<legend>{{ t('Delivery') }}</legend>
				<NcSelect
					:modelValue="watch.frequency"
					:options="frequencyOptions"
					label="label"
					:reduce="(option: Option) => option.id"
					:clearable="false"
					:searchable="false"
					:inputLabel="t('Frequency')"
					@update:modelValue="$emit('change', { frequency: $event })" />
				<NcSelect
					v-if="watch.frequency === 'daily'"
					:modelValue="watch.digestHour"
					:options="hourOptions"
					label="label"
					:reduce="(option: Option) => option.id"
					:clearable="false"
					:searchable="false"
					:inputLabel="t('Send at')"
					@update:modelValue="$emit('change', { digestHour: $event })" />
			</fieldset>
		</div>
	</li>
</template>

<script setup lang="ts">
import type { ChannelName, EventName, MailUnavailable, Watch, WatchChanges } from '../types.ts'

import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import { t } from '../l10n.ts'
import { CHANNELS, EVENTS } from '../types.ts'

interface Option {
	id: string | number
	label: string
}

const props = defineProps<{
	watch: Watch
	mailUnavailable: MailUnavailable | null
	busy: boolean
}>()

const emit = defineEmits<{
	change: [changes: WatchChanges]
	remove: []
}>()

const confirmingRemoval = ref(false)
const label = computed(() => props.watch.path ?? t('Unavailable folder'))

const eventLabels: Record<EventName, string> = {
	created: t('added'),
	modified: t('modified'),
	deleted: t('removed'),
	moved_in: t('moved in'),
}

const channelLabels: Record<ChannelName, string> = {
	notification: t('Nextcloud notification'),
	mail: t('E-mail'),
}

const mailMessages: Record<MailUnavailable, string> = {
	disabled: t('E-mail is switched off on this server.'),
	not_configured: t('The e-mail server of this Nextcloud is not configured: no e-mail can be sent.'),
	no_address: t('Your profile has no e-mail address: add one in your personal info to receive e-mails.'),
}

const frequencyOptions: Option[] = [
	{ id: 'immediate', label: t('As soon as possible') },
	{ id: 'hourly', label: t('Hourly digest') },
	{ id: 'daily', label: t('Daily digest') },
]

const hourOptions: Option[] = Array.from({ length: 24 }, (_, hour) => ({
	id: hour,
	label: String(hour).padStart(2, '0') + ':00',
}))

/**
 * Turns one value of a list on or off, keeping the list in its natural order.
 */
function toggle(field: 'events', value: EventName, on: boolean): void
function toggle(field: 'channels', value: ChannelName, on: boolean): void
function toggle(field: 'events' | 'channels', value: EventName | ChannelName, on: boolean): void {
	const order: readonly string[] = field === 'events' ? EVENTS : CHANNELS
	const current: string[] = props.watch[field]
	const next = order.filter((item) => item === value ? on : current.includes(item))
	if (next.length === 0) {
		return
	}
	emit('change', { [field]: next } as WatchChanges)
}
</script>

<style scoped lang="scss">
.watch-row {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);

	&__header {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__folder-icon {
		color: var(--color-primary-element);
	}

	&__path {
		flex: 1 1 12rem;
		min-width: 0;
		overflow-wrap: anywhere;
		font-weight: bold;

		&--missing {
			color: var(--color-text-maxcontrast);
			font-style: italic;
		}
	}

	&__remove {
		display: flex;
		gap: var(--default-grid-baseline);
	}

	&__options {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
		gap: calc(var(--default-grid-baseline) * 4);
	}

	&__group {
		margin: 0;
		padding: 0;
		border: 0;
		min-width: 0;

		legend {
			padding: 0;
			margin-bottom: var(--default-grid-baseline);
			color: var(--color-text-maxcontrast);
		}

		&--frequency {
			display: flex;
			flex-direction: column;
			gap: calc(var(--default-grid-baseline) * 2);
		}
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}
}
</style>
