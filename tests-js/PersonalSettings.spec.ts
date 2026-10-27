// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type * as ApiModule from '../src/api.ts'
import type { PersonalState } from '../src/types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PersonalSettings from '../src/components/PersonalSettings.vue'
import { createWatch, deleteWatch, listWatches, saveIgnoreOwnActions, updateWatch } from '../src/api.ts'
import { pickFolder } from '../src/folderPicker.ts'
import { makeWatch } from './fixtures.ts'

let state: PersonalState

vi.mock('@nextcloud/initial-state', () => ({ loadState: () => state }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../src/folderPicker.ts', () => ({ pickFolder: vi.fn() }))
vi.mock('../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	createWatch: vi.fn(),
	deleteWatch: vi.fn(),
	listWatches: vi.fn(),
	saveIgnoreOwnActions: vi.fn(),
	updateWatch: vi.fn(),
}))

function baseState(overrides: Partial<PersonalState> = {}): PersonalState {
	return {
		watches: [makeWatch({ id: 2, fileId: 200, path: '/Zeta', name: 'Zeta' }), makeWatch({ id: 1, fileId: 100, path: '/Alpha', name: 'Alpha' })],
		ignoreOwnActions: true,
		maxWatches: 50,
		mailUnavailable: null,
		delayedAlerts: false,
		...overrides,
	}
}

function render() {
	return mount(PersonalSettings)
}

function rows(wrapper: ReturnType<typeof render>) {
	return wrapper.findAll('li.watch-row')
}

function checkbox(scope: { findAll: (selector: string) => Array<{ find: (selector: string) => { text: () => string }, element: Element }> }, text: string) {
	const item = scope.findAll('.checkbox-radio-switch').find((candidate) => candidate.find('.checkbox-radio-switch__text').text() === text)
	if (!item) {
		throw new Error(`No checkbox "${text}"`)
	}
	return item as unknown as { find: (selector: string) => { setValue: (value: boolean) => Promise<void> } }
}

function deferred<T>() {
	let resolve!: (value: T) => void
	const promise = new Promise<T>((done) => {
		resolve = done
	})
	return { promise, resolve }
}

describe('PersonalSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		state = baseState()
	})

	it('lists the watched folders by path', () => {
		const wrapper = render()
		expect(rows(wrapper).map((row) => row.find('.watch-row__path').text())).toEqual(['/Alpha', '/Zeta'])
		expect(wrapper.text()).toContain('2 of 50 folders')
	})

	it('invites to add a folder when there is none', () => {
		state = baseState({ watches: [] })
		const wrapper = render()
		expect(rows(wrapper)).toHaveLength(0)
		expect(wrapper.text()).toContain('No watched folder yet')
	})

	describe('adding a folder', () => {
		it('creates the watch for the chosen path and shows it', async () => {
			vi.mocked(pickFolder).mockResolvedValue('/Projects')
			vi.mocked(createWatch).mockResolvedValue(makeWatch({ id: 3, fileId: 300, path: '/Projects', name: 'Projects' }))
			const wrapper = render()

			await wrapper.find('.folderwatch-personal__toolbar button').trigger('click')
			await flushPromises()

			expect(createWatch).toHaveBeenCalledWith({ path: '/Projects' })
			expect(rows(wrapper).map((row) => row.find('.watch-row__path').text())).toEqual(['/Alpha', '/Projects', '/Zeta'])
			expect(showSuccess).toHaveBeenCalledOnce()
		})

		it('does nothing when the dialog is closed', async () => {
			vi.mocked(pickFolder).mockResolvedValue(null)
			const wrapper = render()

			await wrapper.find('.folderwatch-personal__toolbar button').trigger('click')
			await flushPromises()

			expect(createWatch).not.toHaveBeenCalled()
			expect(rows(wrapper)).toHaveLength(2)
		})

		it('shows the reason when the server refuses', async () => {
			vi.mocked(pickFolder).mockResolvedValue('/Alpha')
			vi.mocked(createWatch).mockRejectedValue({ response: { data: { ocs: { data: { message: 'Ce dossier est déjà surveillé.' } } } } })
			const wrapper = render()

			await wrapper.find('.folderwatch-personal__toolbar button').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Ce dossier est déjà surveillé.')
			expect(rows(wrapper)).toHaveLength(2)
		})

		it('is not possible beyond the limit', () => {
			state = baseState({ maxWatches: 2 })
			const wrapper = render()
			expect(wrapper.find('.folderwatch-personal__toolbar button').attributes('disabled')).toBeDefined()
			expect(wrapper.text()).toContain('You reached the limit.')
		})
	})

	describe('changing a watch', () => {
		it('saves right away and shows the answer of the server', async () => {
			vi.mocked(updateWatch).mockImplementation(async (id, changes) => makeWatch({ id, path: '/Alpha', events: ['created', 'moved_in', 'modified'], ...changes }))
			const wrapper = render()

			await checkbox(rows(wrapper)[0] as never, 'modified').find('input').setValue(true)
			await flushPromises()

			expect(updateWatch).toHaveBeenCalledWith(1, { events: ['created', 'modified', 'moved_in'] })
			expect(showError).not.toHaveBeenCalled()
		})

		it('shows what the server has when saving fails', async () => {
			vi.mocked(updateWatch).mockRejectedValue(new Error('offline'))
			vi.mocked(listWatches).mockResolvedValue([makeWatch({ id: 1, path: '/Alpha' })])
			const wrapper = render()

			await checkbox(rows(wrapper)[0] as never, 'modified').find('input').setValue(true)
			await flushPromises()

			expect(showError).toHaveBeenCalledOnce()
			expect(listWatches).toHaveBeenCalledOnce()
			expect(rows(wrapper)).toHaveLength(1)
			const modified = checkbox(rows(wrapper)[0] as never, 'modified').find('input') as unknown as { element: HTMLInputElement }
			expect(modified.element.checked).toBe(false)
		})

		it('sends two quick changes to the same watch one after the other', async () => {
			const first = deferred<ReturnType<typeof makeWatch>>()
			vi.mocked(updateWatch)
				.mockImplementationOnce(() => first.promise)
				.mockImplementationOnce(async () => makeWatch({ id: 1, path: '/Alpha', events: ['created', 'modified', 'moved_in'], recursive: false }))
			const wrapper = render()
			const row = rows(wrapper)[0]

			await checkbox(row as never, 'modified').find('input').setValue(true)
			await checkbox(row as never, 'Include sub-folders').find('input').setValue(false)
			await flushPromises()
			expect(updateWatch).toHaveBeenCalledTimes(1)

			first.resolve(makeWatch({ id: 1, path: '/Alpha', events: ['created', 'modified', 'moved_in'] }))
			await flushPromises()

			expect(updateWatch).toHaveBeenCalledTimes(2)
			expect(vi.mocked(updateWatch).mock.calls.map((call) => call[1])).toEqual([
				{ events: ['created', 'modified', 'moved_in'] },
				{ recursive: false },
			])
			// the final state is the one of the last answer
			expect(((checkbox(rows(wrapper)[0] as never, 'Include sub-folders').find('input') as unknown) as { element: HTMLInputElement }).element.checked).toBe(false)
		})
	})

	describe('removing a watch', () => {
		it('asks first, then removes it from the list', async () => {
			vi.mocked(deleteWatch).mockResolvedValue()
			const wrapper = render()
			const row = rows(wrapper)[0]

			await row.find('.watch-row__remove button').trigger('click')
			expect(deleteWatch).not.toHaveBeenCalled()
			await row.findAll('.watch-row__remove button')[0].trigger('click')
			await flushPromises()

			expect(deleteWatch).toHaveBeenCalledWith(1)
			expect(rows(wrapper).map((item) => item.find('.watch-row__path').text())).toEqual(['/Zeta'])
		})

		it('keeps the watch and says so when it fails', async () => {
			vi.mocked(deleteWatch).mockRejectedValue(new Error('offline'))
			const wrapper = render()
			const row = rows(wrapper)[0]

			await row.find('.watch-row__remove button').trigger('click')
			await row.findAll('.watch-row__remove button')[0].trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledOnce()
			expect(rows(wrapper)).toHaveLength(2)
		})
	})

	describe('own actions', () => {
		it('saves the switch', async () => {
			vi.mocked(saveIgnoreOwnActions).mockResolvedValue()
			const wrapper = mount(PersonalSettings)
			const toggle = wrapper.findAll('.checkbox-radio-switch').find((item) => item.text() === 'Ignore my own actions')!

			await toggle.find('input').setValue(false)
			await flushPromises()

			expect(saveIgnoreOwnActions).toHaveBeenCalledWith(false)
		})

		it('goes back when saving fails', async () => {
			vi.mocked(saveIgnoreOwnActions).mockRejectedValue(new Error('offline'))
			const wrapper = mount(PersonalSettings)
			const toggle = wrapper.findAll('.checkbox-radio-switch').find((item) => item.text() === 'Ignore my own actions')!

			await toggle.find('input').setValue(false)
			await flushPromises()

			expect(showError).toHaveBeenCalledOnce()
			expect((toggle.find('input').element as HTMLInputElement).checked).toBe(true)
		})
	})

	describe('e-mail channel', () => {
		it.each([
			['disabled', 'E-mail is switched off on this server.'],
			['not_configured', 'The e-mail server of this Nextcloud is not configured: no e-mail can be sent.'],
			['no_address', 'Your profile has no e-mail address: add one in your personal info to receive e-mails.'],
		] as const)('is greyed out in every folder, with the reason (%s)', (reason, message) => {
			state = baseState({ mailUnavailable: reason, watches: [makeWatch({ id: 1, path: '/A', channels: ['notification'] }), makeWatch({ id: 2, path: '/B', channels: ['notification'] })] })
			const wrapper = render()
			const hints = wrapper.findAll('[data-cy-mail-unavailable]')
			expect(hints.map((hint) => hint.text())).toEqual([message, message])
			for (const row of rows(wrapper)) {
				const mail = row.findAll('.checkbox-radio-switch').find((item) => item.find('.checkbox-radio-switch__text').text() === 'E-mail')!
				expect((mail.find('input').element as HTMLInputElement).disabled).toBe(true)
			}
		})

		it('is usable and silent when the instance and the account allow it', () => {
			const wrapper = render()
			expect(wrapper.find('[data-cy-mail-unavailable]').exists()).toBe(false)
		})
	})

	describe('late alerts banner', () => {
		it('warns when the server does not run its jobs with cron', () => {
			state = baseState({ delayedAlerts: true })
			expect(render().find('[data-cy-delayed-alerts]').text()).toContain('Alerts may arrive late')
		})

		it('is absent with cron', () => {
			expect(render().find('[data-cy-delayed-alerts]').exists()).toBe(false)
		})
	})
})
