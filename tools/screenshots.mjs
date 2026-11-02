// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Takes the screenshots of the README and the App Store from the dev instance, with the scene of
// tools/seed-demo.sh. Chrome is driven through puppeteer-core (no browser is downloaded).
//
//   tools/seed-demo.sh fr && node tools/screenshots.mjs fr
//
// Environment: NC_URL, MAILPIT_URL, CHROME_PATH, DEMO_PASSWORD (test instance only).

import { execFileSync } from 'node:child_process'
import { mkdir } from 'node:fs/promises'
import puppeteer from 'puppeteer-core'

const lang = process.argv[2] ?? 'en'
const base = process.env.NC_URL ?? 'http://localhost:8080'
const mailpit = process.env.MAILPIT_URL ?? 'http://localhost:8025'
const chrome = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
const password = process.env.DEMO_PASSWORD ?? 'Passw0rd-test-42'
const out = new URL(`../screenshots/${lang}/`, import.meta.url).pathname
const compose = new URL('../docker-compose.dev.yml', import.meta.url).pathname
const occ = (...args) => execFileSync('docker', ['compose', '-f', compose, 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args], { encoding: 'utf8' }).trim()
await mkdir(out, { recursive: true })

const text = {
	fr: { watchAction: 'Surveiller ce dossier', target: 'Documents', mailSubject: 'nouveaux éléments dans À traiter' },
	en: { watchAction: 'Watch this folder', target: 'Documents', mailSubject: 'new items in To process' },
}[lang]

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox', `--lang=${lang}`] })
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

async function open(width = 1280, height = 860) {
	const context = await browser.createBrowserContext()
	const page = await context.newPage()
	await page.setViewport({ width, height, deviceScaleFactor: 1 })
	await page.setExtraHTTPHeaders({ 'Accept-Language': lang })
	await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }])
	return page
}

async function login(page, user, pass) {
	await page.goto(`${base}/login`, { waitUntil: 'networkidle2' })
	await page.type('#user', user)
	await page.type('#password', pass)
	await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.keyboard.press('Enter')])
}

async function shot(page, name, options = {}) {
	await sleep(700)
	await page.screenshot({ path: `${out}${name}.png`, ...options })
	console.log('wrote', `screenshots/${lang}/${name}.png`)
}

// ---- Alice: Files, personal settings, notifications
{
	const page = await open()
	await login(page, 'alice', password)

	await page.goto(`${base}/settings/user/folderwatch`, { waitUntil: 'networkidle2' })
	await page.waitForSelector('.watch-row')
	await sleep(500)
	// tall enough for the four watched folders: this is the banner of the App Store page
	await page.setViewport({ width: 1280, height: 1880, deviceScaleFactor: 1 })
	await shot(page, 'personal-settings')
	await page.setViewport({ width: 1280, height: 860, deviceScaleFactor: 1 })

	await page.goto(`${base}/apps/files/files`, { waitUntil: 'networkidle2' })
	await page.waitForSelector('[data-cy-files-list-row-name]')
	await sleep(800)
	const row = await page.evaluateHandle((name) => [...document.querySelectorAll('[data-cy-files-list-row-name]')].find((el) => el.getAttribute('data-cy-files-list-row-name') === name)?.closest('tr'), text.target)
	await row.asElement().hover()
	// the "Actions" button of the row (the one that opens a menu)
	const toggle = await row.asElement().$('button[aria-haspopup="menu"]')
	await toggle.click()
	await page.waitForFunction((label) => [...document.querySelectorAll('[role="menuitem"], .action-button')].some((el) => el.textContent.includes(label)), { timeout: 8000 }, text.watchAction)
	await sleep(900)
	await shot(page, 'files-action')
	await page.keyboard.press('Escape')

	await page.setViewport({ width: 1280, height: 860, deviceScaleFactor: 1 })
	await page.goto(`${base}/apps/files/files`, { waitUntil: 'networkidle2' })
	await sleep(800)
	await page.click('.notifications-button, [aria-label*="otification"]').catch(() => {})
	await page.waitForSelector('.notification', { timeout: 8000 }).catch(() => {})
	await shot(page, 'notifications')
	await page.close()
}

// ---- Admin
{
	const page = await open()
	await login(page, 'admin', 'admin')
	// the dev instance answers on localhost: show the settings the way a real server has them
	const cliUrl = occ('config:system:get', 'overwrite.cli.url')
	const adminLang = occ('user:setting', 'admin', 'core', 'lang')
	occ('config:system:set', 'overwrite.cli.url', '--value=https://cloud.example.com')
	occ('user:setting', 'admin', 'core', 'lang', lang)
	try {
		await page.goto(`${base}/settings/admin/folderwatch`, { waitUntil: 'networkidle2' })
		await page.waitForSelector('.folderwatch-admin')
		await page.setViewport({ width: 1280, height: 760, deviceScaleFactor: 1 })
		await shot(page, 'admin-settings')
	} finally {
		occ('config:system:set', 'overwrite.cli.url', `--value=${cliUrl}`)
		if (adminLang) occ('user:setting', 'admin', 'core', 'lang', adminLang)
		else occ('user:setting', 'admin', 'core', 'lang', '--delete')
	}
	await page.close()
}

// ---- The mail, as Mailpit shows it
{
	const page = await open(1200, 640)
	const list = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent('to:alice@example.com subject:"' + text.mailSubject + '"')}`)).json()
	const id = list.messages?.[0]?.ID
	if (id) {
		await page.goto(`${mailpit}/view/${id}.html`, { waitUntil: 'networkidle2' })
		// the mail alone, on the grey of a mail client
		await page.addStyleTag({ content: 'html { background: #eef0f3 }' })
		await shot(page, 'mail')
	} else {
		console.warn('no mail found for the screenshot')
	}
	await page.close()
}

await browser.close()
