// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { MailUnavailable, Watch } from '../src/types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WatchRow from '../src/components/WatchRow.vue'
import { makeWatch } from './fixtures.ts'

function render(watch: Watch = makeWatch(), props: { mailUnavailable?: MailUnavailable | null, busy?: boolean } = {}) {
	return mount(WatchRow, { props: { watch, mailUnavailable: null, busy: false, ...props } })
}

/** The input of the checkbox (or switch) whose text is $text. */
function checkbox(wrapper: ReturnType<typeof render>, text: string) {
	const item = wrapper.findAll('.checkbox-radio-switch').find((candidate) => candidate.find('.checkbox-radio-switch__text').text() === text)
	if (!item) {
		throw new Error(`No checkbox "${text}"`)
	}
	return item.find('input')
}

describe('WatchRow', () => {
	it('shows the folder as a link to the Files app', () => {
		const link = render().find('a.watch-row__path')
		expect(link.text()).toBe('/Docs')
		expect(link.attributes('href')).toBe('http://localhost:8080/f/154')
	})

	it('shows the current choices', () => {
		const wrapper = render()
		expect((checkbox(wrapper, 'added').element as HTMLInputElement).checked).toBe(true)
		expect((checkbox(wrapper, 'moved in').element as HTMLInputElement).checked).toBe(true)
		expect((checkbox(wrapper, 'modified').element as HTMLInputElement).checked).toBe(false)
		expect((checkbox(wrapper, 'E-mail').element as HTMLInputElement).checked).toBe(true)
		expect((checkbox(wrapper, 'Include sub-folders').element as HTMLInputElement).checked).toBe(true)
	})

	it('adds an event, keeping the natural order', async () => {
		const wrapper = render()
		await checkbox(wrapper, 'modified').setValue(true)
		expect(wrapper.emitted('change')?.[0]).toEqual([{ events: ['created', 'modified', 'moved_in'] }])
	})

	it('removes an event', async () => {
		const wrapper = render()
		await checkbox(wrapper, 'moved in').setValue(false)
		expect(wrapper.emitted('change')?.[0]).toEqual([{ events: ['created'] }])
	})

	it('does not let the last event be unchecked', () => {
		const wrapper = render(makeWatch({ events: ['deleted'] }))
		expect((checkbox(wrapper, 'removed').element as HTMLInputElement).disabled).toBe(true)
		expect((checkbox(wrapper, 'added').element as HTMLInputElement).disabled).toBe(false)
	})

	it('does not let the last channel be unchecked', () => {
		const wrapper = render(makeWatch({ channels: ['notification'] }))
		expect((checkbox(wrapper, 'Nextcloud notification').element as HTMLInputElement).disabled).toBe(true)
	})

	it('changes the channels', async () => {
		const wrapper = render()
		await checkbox(wrapper, 'E-mail').setValue(false)
		expect(wrapper.emitted('change')?.[0]).toEqual([{ channels: ['notification'] }])
	})

	it('toggles the recursion', async () => {
		const wrapper = render()
		await checkbox(wrapper, 'Include sub-folders').setValue(false)
		expect(wrapper.emitted('change')?.[0]).toEqual([{ recursive: false }])
	})

	it.each([
		['disabled', 'E-mail is switched off on this server.'],
		['not_configured', 'The e-mail server of this Nextcloud is not configured: no e-mail can be sent.'],
		['no_address', 'Your profile has no e-mail address: add one in your personal info to receive e-mails.'],
	] as const)('greys the e-mail channel out and says why (%s)', (reason, message) => {
		const wrapper = render(makeWatch({ channels: ['notification'] }), { mailUnavailable: reason })
		expect((checkbox(wrapper, 'E-mail').element as HTMLInputElement).disabled).toBe(true)
		expect(wrapper.find('[data-cy-mail-unavailable]').text()).toBe(message)
	})

	it('says nothing about e-mail when it is available', () => {
		const wrapper = render()
		expect(wrapper.find('[data-cy-mail-unavailable]').exists()).toBe(false)
		expect((checkbox(wrapper, 'E-mail').element as HTMLInputElement).disabled).toBe(false)
	})

	it('greys the channel out even when the watch already has it, and keeps the choice', () => {
		const wrapper = render(makeWatch({ channels: ['notification', 'mail'] }), { mailUnavailable: 'not_configured' })
		const mail = checkbox(wrapper, 'E-mail').element as HTMLInputElement
		expect(mail.disabled).toBe(true)
		expect(mail.checked).toBe(true)
		expect(wrapper.find('[data-cy-mail-unavailable]').exists()).toBe(true)
	})

	it('asks for the hour only for a daily digest', async () => {
		expect(render().text()).not.toContain('Send at')
		expect(render(makeWatch({ frequency: 'hourly' })).text()).not.toContain('Send at')
		expect(render(makeWatch({ frequency: 'daily', digestHour: 17 })).text()).toContain('Send at')
	})

	it('needs a confirmation before removing', async () => {
		const wrapper = render()
		await wrapper.find('.watch-row__remove button').trigger('click')
		expect(wrapper.emitted('remove')).toBeUndefined()

		const buttons = wrapper.findAll('.watch-row__remove button')
		expect(buttons.map((button) => button.text())).toEqual(['Yes, remove', 'Cancel'])
		await buttons[0].trigger('click')
		expect(wrapper.emitted('remove')).toHaveLength(1)
	})

	it('can cancel the removal', async () => {
		const wrapper = render()
		await wrapper.find('.watch-row__remove button').trigger('click')
		await wrapper.findAll('.watch-row__remove button')[1].trigger('click')
		expect(wrapper.findAll('.watch-row__remove button').map((button) => button.text())).toEqual(['Remove'])
		expect(wrapper.emitted('remove')).toBeUndefined()
	})

	it('explains a folder that is gone and does not link to it', () => {
		const wrapper = render(makeWatch({ accessible: false, path: null, name: null }))
		expect(wrapper.find('a.watch-row__path').exists()).toBe(false)
		expect(wrapper.text()).toContain('Unavailable folder')
		expect(wrapper.text()).toContain('Alerts are paused and resume if it comes back.')
	})

	it('flags a save in progress to assistive technologies', () => {
		expect(render(makeWatch(), { busy: true }).attributes('aria-busy')).toBe('true')
		expect(render().attributes('aria-busy')).toBe('false')
	})

	it('cannot remove while a save is running', () => {
		const button = render(makeWatch(), { busy: true }).find('.watch-row__remove button')
		expect(button.attributes('disabled')).toBeDefined()
	})
})
