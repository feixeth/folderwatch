<!--
  - SPDX-FileCopyrightText: 2026 ZEDD
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="folderwatch-admin">
		<NcSettingsSection
			:name="t('Folder Watch')"
			:description="t('Alerts for the folders users choose to watch. These settings apply to the whole server.')">
			<NcNoteCard
				v-for="issue in state.issues"
				:key="issue.id"
				type="warning"
				class="folderwatch-admin__issue"
				:data-cy-issue="issue.id">
				<p>{{ issue.message }}</p>
				<p>
					<a :href="issue.docUrl" target="_blank" rel="noopener noreferrer">{{ t('Documentation') }}</a>
				</p>
			</NcNoteCard>

			<NcCheckboxRadioSwitch v-model="form.mailEnabled" type="switch">
				{{ t('Allow alerts by e-mail') }}
			</NcCheckboxRadioSwitch>
			<p class="folderwatch-admin__hint">
				{{ t('When off, users only get Nextcloud notifications. The e-mail server of Nextcloud must be configured for e-mails to leave.') }}
			</p>

			<div class="folderwatch-admin__fields">
				<NcTextField
					v-model="form.maxWatchesPerUser"
					type="number"
					:label="t('Folders one user may watch')"
					:helperText="t('Between {min} and {max}', { min: 1, max: 1000 })"
					:error="errors.maxWatchesPerUser"
					:success="false"
					:min="1"
					:max="1000" />
				<NcTextField
					v-model="form.maxSendsPerHour"
					type="number"
					:label="t('Alerts one user may receive per hour')"
					:helperText="t('0 for no limit. Beyond the limit, alerts wait and leave together later: none is lost.')"
					:error="errors.maxSendsPerHour"
					:success="false"
					:min="0"
					:max="1000" />
			</div>

			<NcTextArea
				v-model="form.ignoredPatterns"
				class="folderwatch-admin__patterns"
				:label="t('Ignored file names')"
				:helperText="t('One pattern per line. * stands for any characters. Matching files never raise an alert.')"
				:error="errors.ignoredPatterns"
				:rows="6"
				resize="vertical" />

			<div class="folderwatch-admin__actions">
				<NcButton variant="primary" :disabled="!dirty || saving" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('Save') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="saving" @click="resetPatterns">
					{{ t('Restore the default file names') }}
				</NcButton>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script setup lang="ts">
import type { AdminState } from '../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, saveAdminSettings } from '../api.ts'
import { t } from '../l10n.ts'

const state = loadState<AdminState>('folderwatch', 'admin')

interface Form {
	mailEnabled: boolean
	maxWatchesPerUser: string
	maxSendsPerHour: string
	ignoredPatterns: string
}

function toForm(settings: AdminState['settings']): Form {
	return {
		mailEnabled: settings.mailEnabled,
		maxWatchesPerUser: String(settings.maxWatchesPerUser),
		maxSendsPerHour: String(settings.maxSendsPerHour),
		ignoredPatterns: settings.ignoredPatterns.join('\n'),
	}
}

const saved = ref<Form>(toForm(state.settings))
const form = reactive<Form>({ ...saved.value })
const saving = ref(false)

/**
 * An integer in [min, max], or null.
 */
function parseBounded(value: string, min: number, max: number): number | null {
	const number = Number(value)
	return value.trim() !== '' && Number.isInteger(number) && number >= min && number <= max ? number : null
}

const errors = computed(() => ({
	maxWatchesPerUser: parseBounded(form.maxWatchesPerUser, 1, 1000) === null,
	maxSendsPerHour: parseBounded(form.maxSendsPerHour, 0, 1000) === null,
	ignoredPatterns: patterns().length > 100 || patterns().some((pattern) => pattern.length > 255),
}))

const dirty = computed(() => JSON.stringify(form) !== JSON.stringify(saved.value) && !Object.values(errors.value).some(Boolean))

function patterns(): string[] {
	return form.ignoredPatterns.split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
}

function resetPatterns(): void {
	form.ignoredPatterns = state.defaultPatterns.join('\n')
}

async function save(): Promise<void> {
	saving.value = true
	try {
		const settings = await saveAdminSettings({
			mailEnabled: form.mailEnabled,
			maxWatchesPerUser: parseBounded(form.maxWatchesPerUser, 1, 1000) as number,
			maxSendsPerHour: parseBounded(form.maxSendsPerHour, 0, 1000) as number,
			ignoredPatterns: patterns(),
		})
		saved.value = toForm(settings)
		Object.assign(form, saved.value)
		showSuccess(t('Settings saved'))
	} catch (error) {
		showError(errorMessage(error, t('Could not save the settings.')))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped lang="scss">
.folderwatch-admin {
	&__issue {
		margin-bottom: calc(var(--default-grid-baseline) * 4);
	}

	&__hint {
		margin: var(--default-grid-baseline) 0 calc(var(--default-grid-baseline) * 4);
		color: var(--color-text-maxcontrast);
	}

	&__fields {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
		gap: calc(var(--default-grid-baseline) * 4);
		max-width: 44rem;
		margin-bottom: calc(var(--default-grid-baseline) * 4);
	}

	&__patterns {
		max-width: 44rem;
	}

	&__actions {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
		margin-top: calc(var(--default-grid-baseline) * 4);
	}
}
</style>
