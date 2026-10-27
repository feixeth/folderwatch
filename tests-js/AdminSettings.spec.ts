// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type * as ApiModule from '../src/api.ts'
import type { AdminState } from '../src/types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSettings from '../src/components/AdminSettings.vue'
import { saveAdminSettings } from '../src/api.ts'

let state: AdminState

vi.mock('@nextcloud/initial-state', () => ({ loadState: () => state }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	saveAdminSettings: vi.fn(),
}))

function baseState(overrides: Partial<AdminState> = {}): AdminState {
	return {
		settings: { mailEnabled: true, maxWatchesPerUser: 50, maxSendsPerHour: 20, ignoredPatterns: ['*.part', '.DS_Store'] },
		defaultPatterns: ['*.part', '.~lock.*', '~$*', '.DS_Store', 'Thumbs.db'],
		issues: [],
		...overrides,
	}
}

function numberInputs(wrapper: ReturnType<typeof mount>) {
	return wrapper.findAll('input[type="number"]')
}

function saveButton(wrapper: ReturnType<typeof mount>) {
	return wrapper.findAll('button').find((button) => button.text() === 'Save')!
}

describe('AdminSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		state = baseState()
	})

	describe('what the instance lacks', () => {
		const issues = [
			{ id: 'background_jobs', message: 'Background jobs are not run by the system cron (current mode: ajax).', docUrl: 'https://docs.example/cron' },
			{ id: 'cli_url', message: 'overwrite.cli.url is not set in config.php, or points to localhost.', docUrl: 'https://docs.example/cli-url' },
			{ id: 'mail', message: 'The e-mail server of Nextcloud is not configured.', docUrl: 'https://docs.example/mail' },
		]

		it('shows each issue the server reports, with the same message and a link to the documentation', () => {
			state = baseState({ issues })
			const wrapper = mount(AdminSettings)
			const shown = wrapper.findAll('[data-cy-issue]')
			expect(shown.map((note) => note.attributes('data-cy-issue'))).toEqual(['background_jobs', 'cli_url', 'mail'])
			expect(shown[0].text()).toContain('Background jobs are not run by the system cron (current mode: ajax).')
			expect(shown[1].find('a').attributes('href')).toBe('https://docs.example/cli-url')
			expect(shown[2].find('a').attributes('rel')).toContain('noopener')
		})

		it('shows only the issues that exist', () => {
			state = baseState({ issues: [issues[1]] })
			expect(mount(AdminSettings).findAll('[data-cy-issue]')).toHaveLength(1)
		})

		it('shows nothing when the instance is fine', () => {
			expect(mount(AdminSettings).find('[data-cy-issue]').exists()).toBe(false)
		})
	})

	it('shows the current values', () => {
		const wrapper = mount(AdminSettings)
		expect(numberInputs(wrapper).map((input) => (input.element as HTMLInputElement).value)).toEqual(['50', '20'])
		expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('*.part\n.DS_Store')
	})

	it('cannot be saved before something changes', () => {
		expect(saveButton(mount(AdminSettings)).attributes('disabled')).toBeDefined()
	})

	it('saves the changes with the right types, then settles', async () => {
		vi.mocked(saveAdminSettings).mockResolvedValue({ mailEnabled: false, maxWatchesPerUser: 25, maxSendsPerHour: 0, ignoredPatterns: ['*.tmp', 'Thumbs.db'] })
		const wrapper = mount(AdminSettings)

		await wrapper.findAll('.checkbox-radio-switch')[0].find('input').setValue(false)
		await numberInputs(wrapper)[0].setValue('25')
		await numberInputs(wrapper)[1].setValue('0')
		await wrapper.find('textarea').setValue('*.tmp\n\n  Thumbs.db  \n')
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(saveAdminSettings).toHaveBeenCalledWith({
			mailEnabled: false,
			maxWatchesPerUser: 25,
			maxSendsPerHour: 0,
			ignoredPatterns: ['*.tmp', 'Thumbs.db'],
		})
		expect(showSuccess).toHaveBeenCalledOnce()
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it.each([
		['0 folders', 0, '0'],
		['1001 folders', 0, '1001'],
		['-1 alerts per hour', 1, '-1'],
		['1001 alerts per hour', 1, '1001'],
		['1.5 folders', 0, '1.5'],
	])('refuses %s', async (_label, index, value) => {
		const wrapper = mount(AdminSettings)
		await numberInputs(wrapper)[index].setValue(value)
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('refuses an over-long pattern and too many patterns', async () => {
		const wrapper = mount(AdminSettings)
		await wrapper.find('textarea').setValue('a'.repeat(256))
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		await wrapper.find('textarea').setValue(Array.from({ length: 101 }, (_, i) => `f${i}`).join('\n'))
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		await wrapper.find('textarea').setValue(Array.from({ length: 100 }, (_, i) => `f${i}`).join('\n'))
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('restores the default patterns without saving', async () => {
		const wrapper = mount(AdminSettings)
		await wrapper.findAll('button').find((button) => button.text() === 'Restore the default file names')!.trigger('click')

		expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('*.part\n.~lock.*\n~$*\n.DS_Store\nThumbs.db')
		expect(saveAdminSettings).not.toHaveBeenCalled()
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('shows the reason when the server refuses, and stays editable', async () => {
		vi.mocked(saveAdminSettings).mockRejectedValue({ response: { data: { ocs: { data: { message: 'Valeur invalide' } } } } })
		const wrapper = mount(AdminSettings)
		await numberInputs(wrapper)[0].setValue('30')

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Valeur invalide')
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
	})
})
