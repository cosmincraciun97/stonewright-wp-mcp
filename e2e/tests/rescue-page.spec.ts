import { expect, test, type Browser, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { expectNoAxeViolations } from './helpers/axe-gate';

/**
 * The Rescue page (Stonewright > Activity > Changes > Rescue, and `page=stonewright-rescue`).
 *
 * It runs on fixtures: the page class rendered from journal entries and ledger rows with synthetic content, in every
 * state the page shows (plugin/tests/fixtures/admin-ui/rescue-page.html, rescue-review.html, kept in step by
 * RescuePageSnapshotTest), served from the repository through request interception with the real stylesheets and scripts.
 * It needs no WordPress login, and a change that needs attention is there whatever the site under test holds.
 *
 * What is checked is what the page promises when something is wrong with the browser: the first press of Roll back
 * is a link to a review that changes nothing, with the script, without it, when the script does not load and when the
 * browser has no showModal; Cancel is a link; the typed phrase holds the button back only as an enhancement.
 */

const repository = path.resolve(__dirname, '..', '..');
const ORIGIN = 'https://rescue-sheet.test';
const SITE = 'https://example.test';
const NOT_VERIFIED = 'cs-3333333333333333333333cc';
const NO_RESULT = 'cs-4444444444444444444444dd';

const FILES: Record<string, string> = {
	'rescue-page.html': 'plugin/tests/fixtures/admin-ui/rescue-page.html',
	'rescue-review.html': 'plugin/tests/fixtures/admin-ui/rescue-review.html',
	'sw-ui.css': 'plugin/assets/admin/sw-ui.css',
	'sw-ui.js': 'plugin/assets/admin/sw-ui.js',
	'shell.css': 'plugin/assets/admin/shell.css',
	'admin.css': 'plugin/assets/admin/admin.css',
	'stonewright-admin.css': 'plugin/assets/css/stonewright-admin.css',
	'rescue.css': 'plugin/assets/admin/pages/rescue.css',
	'rescue.js': 'plugin/assets/admin/pages/rescue.js',
	'stonewright-logo.png': 'plugin/assets/admin/stonewright-logo.png',
};

const MIME: Record<string, string> = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png' };

function serve(file: string): { status: number; contentType: string; body: Buffer | string } {
	const relative = FILES[file];
	if (!relative) {
		return { status: 404, contentType: 'text/plain', body: `not part of the fixture: ${file}` };
	}
	const absolute = path.join(repository, relative);
	return { status: 200, contentType: MIME[path.extname(absolute)] ?? 'application/octet-stream', body: fs.readFileSync(absolute) };
}

/**
 * Serve the fixture files at ORIGIN, and answer the address the Roll back link points at (the site's own admin address)
 * with the review page, the way the server does: a link that is followed reaches a page that changes nothing.
 */
async function route(page: Page, options: { blockScript?: boolean } = {}): Promise<void> {
	await page.route(`${ORIGIN}/**`, async (handler) => {
		const name = new URL(handler.request().url()).pathname.replace(/^\//, '');
		if (options.blockScript && name === 'rescue.js') {
			await handler.abort('failed');
			return;
		}
		await handler.fulfill(serve(name));
	});
	await page.route((url) => url.origin === SITE && url.pathname === '/wp-admin/admin.php', async (handler) => {
		const target = new URL(handler.request().url());
		if (target.searchParams.get('review')) {
			// The review page's own stylesheets and scripts are relative: send the browser to the fixture origin.
			await handler.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: String(serve('rescue-review.html').body).replace(/(href|src)="([a-z-]+\.(?:css|js))"/g, `$1="${ORIGIN}/$2"`) });
			return;
		}
		await handler.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: String(serve('rescue-page.html').body).replace(/(href|src)="([a-z-]+\.(?:css|js))"/g, `$1="${ORIGIN}/$2"`) });
	});
}

async function open(page: Page, file = 'rescue-page.html', options: { blockScript?: boolean } = {}): Promise<void> {
	await route(page, options);
	await page.goto(`${ORIGIN}/${file}`, { waitUntil: 'load' });
}

async function withoutScript(browser: Browser, file: string): Promise<{ page: Page; close: () => Promise<void> }> {
	const context = await browser.newContext({ javaScriptEnabled: false });
	const page = await context.newPage();
	await open(page, file);
	return { page, close: () => context.close() };
}

async function horizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

const rowOf = (page: Page, id: string) => page.locator(`tr[data-sw-rescue-row="${id}"]`);

test.describe('Rescue page fixture', () => {
	test('a change that needs attention shows its object and what was done, and keeps the ability, the ids and the codes under Details', async ({ page }) => {
		await open(page);
		const row = rowOf(page, NOT_VERIFIED);

		await expect(row.locator('th .sw-rescue-object')).toHaveText('About us');
		await expect(row.locator('th > .sw-rescue-sub').first()).toHaveText('Changed the team section of the page.');
		await expect(row.locator('[data-sw-rescue-verification="not_started"]')).toHaveText(/could not start.*says nothing about the site/);
		const details = row.locator('details.sw-rescue-details');
		await expect(details).not.toHaveAttribute('open', '');
		await expect(details.locator('code', { hasText: 'stonewright/elementor-v3-batch-mutate' })).toBeHidden();
		await details.locator('summary').click();
		await expect(details.locator('code', { hasText: 'stonewright/elementor-v3-batch-mutate' })).toBeVisible();
		await expect(details.locator('code', { hasText: 'token_unavailable' })).toBeVisible();
		await expect(details.locator('code.sw-rescue-id')).toHaveText(NOT_VERIFIED.slice(3, 11));
	});

	test('after the script has run a button still says its short label and keeps the words that name what it acts on as hidden text', async ({ page }) => {
		await open(page);
		const check = rowOf(page, NOT_VERIFIED).getByRole('button', { name: /^Check again change set/ });

		expect(await check.evaluate((node) => node.firstChild?.nodeValue)).toBe('Check again');
		await expect(check.locator('.sw-ui-visually-hidden')).toHaveText(' change set 33333333');
		expect(await check.evaluate((node) => node.getBoundingClientRect().width), 'the hidden words take no room').toBeLessThan(140);
	});

	test('Not verified says nothing about the site and never blames the host', async ({ page }) => {
		await open(page);
		const text = (await page.locator('.sw-rescue-page').innerText()).toLowerCase();

		expect(text).toContain('does not mean the site is broken');
		expect(text).not.toContain('hosting');
		expect(text).not.toContain('blocks requests');
		await expect(rowOf(page, 'cs-4444444444444444444444dd').locator('[data-sw-rescue-verification="unknown"]')).toContainText('says nothing about the site');
		await expect(rowOf(page, 'cs-2222222222222222222222bb').locator('[data-sw-rescue-verification="site_failed"]')).toContainText('did not finish');
		await expect(page.locator('[data-sw-rescue-result]')).toContainText('no answer it could judge');
	});

	test('the first press of Roll back goes to a review that changes nothing, and with script it opens as a dialog with Cancel focused', async ({ page }) => {
		await open(page);
		const link = rowOf(page, NOT_VERIFIED).getByRole('link', { name: /^Roll back change set/ });
		await expect(link).toHaveAttribute('href', new RegExp(`page=stonewright-rescue&review=${NOT_VERIFIED}`));

		await link.click();
		const open_ = page.locator(`dialog[id="${await link.getAttribute('data-sw-rescue-open')}"]`);
		await expect(open_).toBeVisible();
		expect(await open_.evaluate((node) => node.matches(':modal'))).toBe(true);
		await expect(open_.getByRole('link', { name: 'Cancel' })).toBeFocused();
		await expect(open_.getByRole('heading', { level: 2 })).toContainText('Roll back change set');

		await page.keyboard.press('Escape');
		await expect(open_).toBeHidden();
		await expect(link).toBeFocused();
		expect(page.url(), 'a dialog never leaves the page').toBe(`${ORIGIN}/rescue-page.html`);

		await link.click();
		await open_.getByRole('link', { name: 'Cancel' }).click();
		await expect(open_).toBeHidden();
		expect(page.url(), 'Cancel closes the dialog in place').toBe(`${ORIGIN}/rescue-page.html`);
	});

	test('without script the Roll back link is followed to the review, which holds the form, a Cancel link and an enabled confirm', async ({ browser }) => {
		const { page, close } = await withoutScript(browser, 'rescue-page.html');
		const link = rowOf(page, NOT_VERIFIED).getByRole('link', { name: /^Roll back change set/ });

		await expect(page.locator('dialog.sw-rescue-dialog').first()).toBeHidden();
		await link.click();
		await expect(page).toHaveURL(new RegExp(`page=stonewright-rescue&review=${NOT_VERIFIED}`));

		const review = page.locator('[data-sw-rescue-review]');
		await expect(review).toBeVisible();
		await expect(review.getByRole('heading', { level: 2 })).toContainText('Roll back change set');
		const cancel = review.getByRole('link', { name: 'Cancel' });
		await expect(cancel).toHaveAttribute('href', /page=stonewright-rescue$/);
		const confirm = review.getByRole('button', { name: 'Roll back' });
		await expect(confirm).toBeEnabled();
		await expect(review.locator('form[method="post" i]')).toHaveCount(1);
		await expect(review.locator('input[name="action"]')).toHaveValue('stonewright_rescue_rollback');
		await expect(review.locator('input[name="confirm_phrase"]'), 'the phrase the server checks in production-safe mode').toBeVisible();
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
		await close();
	});

	test('when the script does not load the link still goes to the review', async ({ page }) => {
		await open(page, 'rescue-page.html', { blockScript: true });
		const link = rowOf(page, NOT_VERIFIED).getByRole('link', { name: /^Roll back change set/ });
		await expect(page.locator('[data-sw-rescue-copy-text]').first(), 'the copy buttons stay hidden: the script that shows them did not run').toBeHidden();

		await link.click();

		await expect(page).toHaveURL(new RegExp(`review=${NOT_VERIFIED}`));
		await expect(page.locator('[data-sw-rescue-review]')).toBeVisible();
	});

	test('when the browser has no showModal the link still goes to the review', async ({ page }) => {
		await page.addInitScript(() => {
			Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { value: undefined, configurable: true });
		});
		await open(page);
		const link = rowOf(page, NOT_VERIFIED).getByRole('link', { name: /^Roll back change set/ });

		await link.click();

		await expect(page).toHaveURL(new RegExp(`review=${NOT_VERIFIED}`));
		await expect(page.locator('[data-sw-rescue-review]')).toBeVisible();
		await expect(page.locator('dialog.sw-rescue-dialog:visible')).toHaveCount(0);
	});

	test('on the review the script holds the confirm button back until the phrase is typed, and the typed phrase unlocks it', async ({ page }) => {
		await open(page, 'rescue-review.html');
		const confirm = page.locator('[data-sw-rescue-review]').getByRole('button', { name: 'Roll back' });
		const phrase = page.getByLabel('Type ROLL BACK to confirm');

		await expect(confirm).toBeDisabled();
		await phrase.fill('roll back');
		await expect(confirm).toBeDisabled();
		await phrase.fill('ROLL BACK');
		await expect(confirm).toBeEnabled();
		await phrase.fill('');
		await expect(confirm).toBeDisabled();
	});

	test('the page and the review have no axe finding', async ({ page }, testInfo) => {
		await open(page);
		await expectNoAxeViolations(page, testInfo, 'rescue-page', '.sw-rescue-page');
		await open(page, 'rescue-review.html');
		await expectNoAxeViolations(page, testInfo, 'rescue-review', '.sw-rescue-page');
	});

	test('nothing runs past the screen: no horizontal scroll with every row open', async ({ page }) => {
		await open(page);
		for (const summary of await page.locator('details.sw-rescue-details > summary').all()) {
			await summary.click();
		}
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
	});

	test('with reduced motion no animation runs', async ({ page }) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await open(page);

		expect(await page.evaluate(() => document.getAnimations().filter((animation) => animation.playState === 'running').length)).toBe(0);
	});
});
