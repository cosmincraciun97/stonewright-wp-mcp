import { chromium } from '@playwright/test';
import { login } from './tests/helpers/login';

/**
 * Stop the suite before 180 viewport retries when WordPress itself is down.
 * The recovery screen has no Stonewright chrome, so `.sw-shell` waits hide
 * the PHP error and burn the 45-minute job budget.
 */
export default async function globalSetup(): Promise<void> {
	const baseURL = (process.env.WP_BASE_URL ?? 'http://localhost:8888').replace(/\/$/, '');
	const url = `${baseURL}/wp-admin/`;
	let response: Response;
	try {
		response = await fetch(url, { redirect: 'follow' });
	} catch (error) {
		throw new Error(
			`WordPress is unreachable at ${url}: ${error instanceof Error ? error.message : String(error)}`,
		);
	}

	const html = await response.text();
	const text = html
		.replace(/<script[\s\S]*?<\/script>/gi, ' ')
		.replace(/<style[\s\S]*?<\/style>/gi, ' ')
		.replace(/<[^>]+>/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();

	if (
		/There has been a critical error/i.test(text) ||
		/Fatal error:/i.test(text) ||
		/Parse error:/i.test(text) ||
		/Uncaught (?:Error|TypeError|Exception|Throwable)/i.test(text)
	) {
		throw new Error(`WordPress PHP fatal on ${url} (HTTP ${response.status}): ${text.slice(0, 2500)}`);
	}

	if (!response.ok && response.status >= 500) {
		throw new Error(`WordPress HTTP ${response.status} on ${url}: ${text.slice(0, 2500)}`);
	}

	await switchStonewrightOn(baseURL);
}

/**
 * Bring a fresh site into the state the specs start from: Stonewright's AI abilities switched on.
 *
 * The suite is split into groups that each run on their own fresh site, and a spec that calls an ability needs the
 * switch on. Turning it on here, once, means no spec depends on an earlier spec of its group having done it.
 */
async function switchStonewrightOn(baseURL: string): Promise<void> {
	const browser = await chromium.launch();
	try {
		const page = await (await browser.newContext({ baseURL })).newPage();
		// A fresh site builds its caches on the first admin page, so the first loads are slower than the rest.
		page.setDefaultTimeout(60_000);
		page.setDefaultNavigationTimeout(90_000);
		await login(page);
		const settings = '/wp-admin/admin.php?page=stonewright&tab=settings';
		await page.goto(settings, { waitUntil: 'domcontentloaded' });
		const enabled = page.locator('#stonewright_enabled');
		await enabled.waitFor({ state: 'attached', timeout: 30_000 });
		if (!(await enabled.isChecked())) {
			await enabled.check({ force: true });
			await Promise.all([
				page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded', timeout: 45_000 }),
				page.locator('form.stonewright-settings-form').getByRole('button', { name: 'Save settings' }).click(),
			]);
			await page.goto(settings, { waitUntil: 'domcontentloaded' });
		}
		if (!(await page.locator('#stonewright_enabled').isChecked())) {
			throw new Error('Stonewright could not be switched on before the suite: the Enable switch is off after saving the settings.');
		}
	} finally {
		await browser.close();
	}
}
