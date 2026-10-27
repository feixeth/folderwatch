<!--
  - SPDX-FileCopyrightText: 2026 ZEDD
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="folderwatch-personal">
		<NcSettingsSection
			:name="t('Folder Watch')"
			:description="t('Get a notification or an e-mail when something is added or changed in the folders you choose.')">
			<NcNoteCard v-if="delayedAlerts" type="warning" data-cy-delayed-alerts>
				{{ t('Alerts may arrive late: this server does not run its background jobs with the system cron. Ask your administrator.') }}
			</NcNoteCard>

			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="ignoreOwnActions"
				@update:modelValue="saveIgnoreOwn">
				{{ t('Ignore my own actions') }}
			</NcCheckboxRadioSwitch>
			<p class="folderwatch-personal__hint">
				{{ t('Do not alert me about what I add or change myself.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection :name="t('Watched folders')">
			<div class="folderwatch-personal__toolbar">
				<NcButton variant="primary" :disabled="atLimit || adding" @click="addFolder">
					<template #icon>
						<NcLoadingIcon v-if="adding" :size="20" />
						<PlusIcon v-else :size="20" />
					</template>
					{{ t('Add a folder') }}
				</NcButton>
				<span class="folderwatch-personal__count" :class="{ 'folderwatch-personal__count--full': atLimit }">
					{{ t('{count} of {max} folders', { count: watches.length, max: maxWatches }) }}
				</span>
			</div>
			<p v-if="atLimit" class="folderwatch-personal__hint">
				{{ t('You reached the limit. Remove a folder to watch another one.') }}
			</p>

			<NcEmptyContent
				v-if="watches.length === 0"
				:name="t('No watched folder yet')"
				:description="t('Add a folder here, or use “Watch this folder” in the Files app.')">
				<template #icon>
					<EyeIcon :size="64" />
				</template>
			</NcEmptyContent>

			<ul v-else class="folderwatch-personal__list">
				<WatchRow
					v-for="watch in sortedWatches"
					:key="watch.id"
					:watch="watch"
					:mailUnavailable="mailUnavailable"
					:busy="saving.has(watch.id) || removing.has(watch.id)"
					@change="save(watch, $event)"
					@remove="remove(watch)" />
			</ul>
		</NcSettingsSection>
	</div>
</template>

<script setup lang="ts">
import type { PersonalState, Watch, WatchChanges } from '../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import EyeIcon from 'vue-material-design-icons/EyeOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import WatchRow from './WatchRow.vue'
import { createWatch, deleteWatch, errorMessage, listWatches, saveIgnoreOwnActions, updateWatch } from '../api.ts'
import { pickFolder } from '../folderPicker.ts'
import { t } from '../l10n.ts'

const state = loadState<PersonalState>('folderwatch', 'personal')
const { maxWatches, mailUnavailable, delayedAlerts } = state

const watches = ref<Watch[]>(state.watches)
const ignoreOwnActions = ref(state.ignoreOwnActions)
const saving = reactive(new Map<number, number>())
const removing = reactive(new Set<number>())
const chains = new Map<number, Promise<void>>()
const adding = ref(false)

const sortedWatches = computed(() => [...watches.value].sort((a, b) => Number(a.path === null) - Number(b.path === null) || (a.path ?? '').localeCompare(b.path ?? '')))
const atLimit = computed(() => watches.value.length >= maxWatches)

async function saveIgnoreOwn(value: boolean): Promise<void> {
	const previous = ignoreOwnActions.value
	ignoreOwnActions.value = value
	try {
		await saveIgnoreOwnActions(value)
	} catch (error) {
		ignoreOwnActions.value = previous
		showError(errorMessage(error, t('Could not save this setting.')))
	}
}

async function addFolder(): Promise<void> {
	adding.value = true
	try {
		const path = await pickFolder()
		if (path === null) {
			return
		}
		const watch = await createWatch({ path })
		watches.value.push(watch)
		showSuccess(t('You now watch "{name}".', { name: watch.name ?? path }))
	} catch (error) {
		showError(errorMessage(error, t('Could not watch this folder.')))
	} finally {
		adding.value = false
	}
}

/**
 * Saves right away. Changes to one watch are sent one after the other, and the row takes the
 * server's answer once the last one is back, or what the server has if one failed.
 */
function save(watch: Watch, changes: WatchChanges): Promise<void> {
	Object.assign(watch, changes)
	saving.set(watch.id, (saving.get(watch.id) ?? 0) + 1)

	const next = (chains.get(watch.id) ?? Promise.resolve()).then(async () => {
		try {
			const updated = await updateWatch(watch.id, changes)
			if (saving.get(watch.id) === 1) {
				Object.assign(watch, updated)
			}
		} catch (error) {
			showError(errorMessage(error, t('Could not save this setting.')))
			await reload()
		} finally {
			const left = (saving.get(watch.id) ?? 1) - 1
			if (left > 0) {
				saving.set(watch.id, left)
			} else {
				saving.delete(watch.id)
				chains.delete(watch.id)
			}
		}
	})
	chains.set(watch.id, next)
	return next
}

async function reload(): Promise<void> {
	try {
		watches.value = await listWatches()
	} catch {
		// keep what is shown
	}
}

async function remove(watch: Watch): Promise<void> {
	removing.add(watch.id)
	try {
		await deleteWatch(watch.id)
		watches.value = watches.value.filter((item) => item.id !== watch.id)
		showSuccess(t('You no longer watch "{name}".', { name: watch.name ?? watch.path ?? '' }))
	} catch (error) {
		showError(errorMessage(error, t('Could not stop watching this folder.')))
	} finally {
		removing.delete(watch.id)
	}
}
</script>

<style scoped lang="scss">
.folderwatch-personal {
	&__hint {
		margin-top: var(--default-grid-baseline);
		color: var(--color-text-maxcontrast);
	}

	&__toolbar {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		flex-wrap: wrap;
		margin-bottom: calc(var(--default-grid-baseline) * 3);
	}

	&__count {
		color: var(--color-text-maxcontrast);

		&--full {
			color: var(--color-warning-text);
		}
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
		margin: 0;
		padding: 0;
		list-style: none;
	}
}
</style>
