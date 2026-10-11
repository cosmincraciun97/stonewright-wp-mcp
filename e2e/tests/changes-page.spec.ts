import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { expectNoAxeViolations, settle } from './helpers/axe-gate';
import { login } from './helpers/login';

/**
 * The Changes page (Stonewright > Activity > Changes).
 *
 * Part one runs on a fixture: the page class rendered from ledger rows with synthetic content, with its drawer open
 * (plugin/tests/fixtures/admin-ui/changes-page.html, kept in step by ChangesPageSnapshotTest). It is served from the
 * repository through request interception together with the real stylesheets and scripts, so the drawer, the diff
 * and the page script are measured in a real browser at every viewport whatever the site's history holds. It needs
 * no WordPress login.
 *
 * Part two opens the real page on the site under test. The page loop of admin-ui.spec.ts and the UI contract
 * already hold it to the shared measurements; this part adds what is specific to it and skips what depends on the
 * site having recorded changes.
 */

const repository = path.resolve(__dirname, '..', '..');
const ORIGIN = 'https://changes-sheet.test';
const OPEN = 'cs-aaaaaaaaaaaaaaaaaaaaaaaa';

const FILES: Record<string, string> = {
	'changes-page.html': 'plugin/tests/fixtures/admin-ui/changes-page.html',
	'sw-ui.css': 'plugin/assets/admin/sw-ui.css',
	'sw-ui.js': 'plugin/assets/admin/sw-ui.js',
	'shell.css': 'plugin/assets/admin/shell.css',
	'admin.css': 'plugin/assets/admin/admin.css',
	'stonewright-admin.css': 'plugin/assets/css/stonewright-admin.css',
	'changes.css': 'plugin/assets/admin/pages/changes.css',
	'changes.js': 'plugin/assets/admin/pages/changes.js',
	'stonewright-logo.png': 'plugin/assets/admin/stonewright-logo.png',
};

const MIME: Record<string, string> = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png' };

/** Open the fixture the way the address of a change does: `?change=<id>` names the drawer to open. */
async function openFixture(page: Page, query = `?change=${OPEN}`): Promise<void> {
	await page.route(`${ORIGIN}/**`, async (route) => {
		const name = new URL(route.request().url()).pathname.replace(/^\//, '');
		const relative = FILES[name];
		if (!relative) {
			await route.fulfill({ status: 404, contentType: 'text/plain', body: `not part of the fixture: ${name}` });
			return;
		}
		const file = path.join(repository, relative);
		await route.fulfill({ status: 200, contentType: MIME[path.extname(file)] ?? 'application/octet-stream', body: fs.readFileSync(file) });
	});
	await page.goto(`${ORIGIN}/changes-page.html${query}`, { waitUntil: 'load' });
	await page.waitForFunction(() => Boolean((window as Window & { Stonewright?: { ui?: unknown } }).Stonewright?.ui));
}

async function horizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.describe('Changes page fixture', () => {
	test('the drawer opens as a modal on load, with the Close control focused and the diff inside it', async ({ page }) => {
		await openFixture(page);

		const drawer = page.locator('[data-sw-changes-drawer]');
		await expect(drawer).toBeVisible();
		expect(await drawer.evaluate((node) => node.matches(':modal'))).toBe(true);
		await expect(drawer.getByRole('link', { name: /^Close/ })).toBeFocused();
		await expect(drawer.locator('.sw-ui-dialog__title')).toHaveText(/^Change cs-aaaaaaaaaaaa/);
		await expect(drawer.locator('[data-sw-ui-diff="text"]')).toHaveCount(1);
		await expect(drawer.locator('.sw-ui-tabs__tab[aria-selected="true"]')).toHaveText('Diff');
	});

	test('Escape closes the drawer, gives focus back to the row that opened it and takes the change out of the address', async ({ page }) => {
		await openFixture(page);
		const opener = page.locator(`[data-sw-changes-open="${OPEN}"]`);

		await page.keyboard.press('Escape');

		await expect(page.locator('[data-sw-changes-drawer]')).toBeHidden();
		await expect(opener).toBeFocused();
		expect(new URL(page.url()).search).toBe('');
	});

	test('Tab and Shift+Tab stay inside the open drawer', async ({ page }) => {
		await openFixture(page);
		const drawer = page.locator('[data-sw-changes-drawer]');

		for (let press = 0; press < 14; press += 1) {
			await page.keyboard.press('Tab');
			expect(await drawer.evaluate((node) => node.contains(document.activeElement)), `Tab ${press + 1} left the drawer`).toBe(true);
		}
		for (let press = 0; press < 14; press += 1) {
			await page.keyboard.press('Shift+Tab');
			expect(await drawer.evaluate((node) => node.contains(document.activeElement)), `Shift+Tab ${press + 1} left the drawer`).toBe(true);
		}
	});

	test('the diff names every line change in words and shows a script tag in a line as text', async ({ page }) => {
		await openFixture(page);
		const diff = page.locator('[data-sw-changes-drawer] [data-sw-ui-diff="text"]');

		await expect(diff.locator('.sw-ui-diff__line--add').first()).toContainText('Added line');
		await expect(diff.locator('.sw-ui-diff__line--del').first()).toContainText('Removed line');
		await expect(diff.locator('.sw-ui-diff__mark').filter({ hasText: '+' }).first()).toBeVisible();
		await expect(diff.locator('.sw-ui-diff__mark').filter({ hasText: '-' }).first()).toBeVisible();
		await expect(diff).toContainText("<script>alert('shown as text')</script>");
		expect(await page.locator('script:not([src])').count()).toBe(0);
		expect(await diff.locator('script, img, iframe').count()).toBe(0);
	});

	test('the lines scroll inside their own block, which the keyboard can reach, and the page never scrolls sideways', async ({ page }) => {
		await openFixture(page);
		const body = page.locator('[data-sw-changes-drawer] [data-sw-ui-diff="text"] .sw-ui-diff__body');

		await expect(body).toHaveAttribute('tabindex', '0');
		await expect(body).toHaveAttribute('role', 'region');
		await expect(body).toHaveAttribute('aria-label', /changed lines$/);
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
		expect(await body.evaluate((node) => node.scrollWidth > node.clientWidth), 'a long line scrolls inside the block').toBe(true);
		const outside = await body.evaluate((node) => {
			const box = node.getBoundingClientRect();
			const drawer = (node.closest('dialog') as HTMLElement).getBoundingClientRect();
			return box.right - drawer.right;
		});
		expect(outside, 'the block stays inside the drawer').toBeLessThanOrEqual(1);

		await body.focus();
		await expect(body).toBeFocused();
		// The arrow keys scroll a focused block sideways; End scrolls it to the bottom, not to the right.
		for (let press = 0; press < 5; press++) {
			await page.keyboard.press('ArrowRight');
		}
		await expect.poll(() => body.evaluate((node) => node.scrollLeft), { message: 'the keyboard scrolls the block sideways' }).toBeGreaterThan(0);
		expect(await horizontalOverflow(page), 'the page still does not scroll sideways').toBeLessThanOrEqual(0);
	});

	test('the tabs are links with the view in their address, and Details and History switch in place', async ({ page }) => {
		await openFixture(page);
		const drawer = page.locator('[data-sw-changes-drawer]');

		expect(await drawer.locator('a.sw-ui-tabs__tab').evaluateAll((links) => links.map((link) => new URL((link as HTMLAnchorElement).href).searchParams.get('view')))).toEqual([null, 'details', 'history']);

		await drawer.getByRole('tab', { name: 'History' }).click();
		await expect(drawer.getByRole('tab', { name: 'History' })).toHaveAttribute('aria-selected', 'true');
		await expect(drawer.locator('#sw-changes-panel-history')).toBeVisible();
		await expect(drawer.locator('#sw-changes-panel-diff')).toBeHidden();
		await expect(drawer.locator('[data-sw-changes-node]')).toHaveCount(2);
		await expect(drawer.locator('[data-sw-changes-node][aria-current="true"]')).toContainText('This change');
		await expect(drawer.getByRole('link', { name: 'View diff' })).toHaveCount(1);

		await drawer.getByRole('tab', { name: 'Details' }).click();
		await expect(drawer.locator('#sw-changes-panel-details')).toBeVisible();
		await expect(drawer.locator('#sw-changes-panel-details dt')).toContainText(['Change ID', 'Ability', 'Restorable']);
	});

	test('Redo opens the confirmation dialog over the drawer: Cancel has the focus, Escape closes it and focus goes back', async ({ page }) => {
		await openFixture(page);
		const redo = page.getByRole('link', { name: 'Redo this change' });
		const dialog = page.locator('#sw-changes-undo-dialog');

		await expect(dialog).toBeHidden();
		await redo.click();

		await expect(dialog).toBeVisible();
		await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
		await expect(dialog.getByRole('heading', { level: 2 })).toContainText('Redo change');
		await expect(dialog).toContainText('This puts code back');
		await expect(dialog.locator('form[method="post" i]')).toHaveCount(1);
		await expect(dialog.locator('input[name="_stonewright_nonce"]')).toHaveCount(1);
		await expect(dialog.locator('.sw-ui-diff')).not.toHaveCount(0);

		await page.keyboard.press('Escape');
		await expect(dialog).toBeHidden();
		await expect(redo).toBeFocused();
		await expect(page.locator('[data-sw-changes-drawer]')).toBeVisible();
	});

	test('the Undo dialog keeps focus inside while it is open and has no axe finding', async ({ page }, testInfo) => {
		await openFixture(page);
		await page.getByRole('link', { name: 'Redo this change' }).click();
		const dialog = page.locator('#sw-changes-undo-dialog');
		await expect(dialog).toBeVisible();

		for (let press = 0; press < 12; press++) {
			await page.keyboard.press('Tab');
			expect(await page.evaluate(() => Boolean(document.activeElement?.closest('#sw-changes-undo-dialog'))), `focus left the dialog at tab ${press}`).toBe(true);
		}
		await expectNoAxeViolations(page, testInfo, 'changes-undo-dialog', '#sw-changes-undo-dialog');
	});

	test('at 400px the Undo dialog fits the screen, scrolls inside itself and nothing scrolls sideways', async ({ page }) => {
		await page.setViewportSize({ width: 400, height: 800 });
		await openFixture(page);
		await page.getByRole('link', { name: 'Redo this change' }).click();
		const dialog = page.locator('#sw-changes-undo-dialog');
		await expect(dialog).toBeVisible();
		const box = await dialog.boundingBox();

		expect(box?.x ?? -1).toBeGreaterThanOrEqual(0);
		expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(400);
		expect(box?.height ?? 0).toBeLessThanOrEqual(800);
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
		for (const target of await dialog.locator('a, button, input').all()) {
			const size = await target.boundingBox();
			if (size && size.width > 0) {
				expect(size.height, 'a target under 24px').toBeGreaterThanOrEqual(24);
			}
		}
	});

	test('without script the Undo link prints the dialog open and the form posts without it', async ({ browser }) => {
		const context = await browser.newContext({ javaScriptEnabled: false });
		const page = await context.newPage();
		await page.route(`${ORIGIN}/**`, async (route) => {
			const name = new URL(route.request().url()).pathname.replace(/^\//, '');
			const relative = FILES[name];
			await route.fulfill(relative ? { status: 200, contentType: MIME[path.extname(relative)] ?? 'application/octet-stream', body: fs.readFileSync(path.join(repository, relative)) } : { status: 404, body: 'not part of the fixture' });
		});
		await page.goto(`${ORIGIN}/changes-page.html?change=${OPEN}`, { waitUntil: 'load' });

		await expect(page.locator('#sw-changes-undo-dialog')).toBeHidden();
		await expect(page.getByRole('link', { name: 'Redo this change' })).toHaveAttribute('href', /undo=1/);
		await context.close();
	});

	test('the list lists newest first with a View diff link per row, named after its change', async ({ page }) => {
		await openFixture(page);
		await page.keyboard.press('Escape');
		await expect(page.locator('[data-sw-changes-drawer]')).toBeHidden();
		const rows = page.locator('tr[id^="sw-change-"]');
		await expect(rows).toHaveCount(4);
		expect(await rows.evaluateAll((nodes) => nodes.map((node) => node.id))).toEqual([
			'sw-change-cs-dddddddddddddddddddddddd',
			'sw-change-cs-cccccccccccccccccccccccc',
			'sw-change-cs-bbbbbbbbbbbbbbbbbbbbbbbb',
			'sw-change-cs-aaaaaaaaaaaaaaaaaaaaaaaa',
		]);
		const links = page.getByRole('link', { name: /^View diff of change cs-/ });
		await expect(links).toHaveCount(4);
		for (const link of await links.all()) {
			expect(await link.getAttribute('href')).toContain('page=stonewright-changes&change=cs-');
		}
	});

	test('with reduced motion no animation runs while the drawer is open', async ({ page }) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await openFixture(page);
		await settle(page);

		expect(await page.evaluate(() => document.getAnimations().filter((animation) => animation.playState === 'running').length)).toBe(0);
	});

	test('the drawer has no axe finding at this viewport', async ({ page }, testInfo) => {
		await openFixture(page);
		await expectNoAxeViolations(page, testInfo, 'changes-drawer', '[data-sw-changes-drawer]');
	});

	test('at 400px the drawer fills the screen, the diff scrolls inside it and nothing scrolls sideways', async ({ page }) => {
		await page.setViewportSize({ width: 400, height: 800 });
		await openFixture(page);
		const box = await page.locator('[data-sw-changes-drawer]').boundingBox();

		expect(box?.width ?? 0).toBeGreaterThanOrEqual(370);
		expect(box?.x ?? 1).toBeGreaterThanOrEqual(0);
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
		for (const target of await page.locator('[data-sw-changes-drawer] a, [data-sw-changes-drawer] button').all()) {
			const size = await target.boundingBox();
			if (size && size.width > 0) {
				expect(size.height, `a target under 24px: ${await target.innerText()}`).toBeGreaterThanOrEqual(24);
			}
		}
	});

	test('without script the server renders the drawer open and Close is a link back to the list', async ({ browser }) => {
		const context = await browser.newContext({ javaScriptEnabled: false });
		const page = await context.newPage();
		await page.route(`${ORIGIN}/**`, async (route) => {
			const name = new URL(route.request().url()).pathname.replace(/^\//, '');
			const relative = FILES[name];
			if (!relative) {
				await route.fulfill({ status: 404, body: 'x' });
				return;
			}
			const file = path.join(repository, relative);
			await route.fulfill({ status: 200, contentType: MIME[path.extname(file)] ?? 'application/octet-stream', body: fs.readFileSync(file) });
		});
		await page.goto(`${ORIGIN}/changes-page.html?change=${OPEN}`, { waitUntil: 'load' });

		const drawer = page.locator('[data-sw-changes-drawer]');
		await expect(drawer).toBeVisible();
		await expect(drawer.locator('[data-sw-ui-diff="text"]')).toBeVisible();
		expect(new URL((await drawer.getByRole('link', { name: /^Close/ }).getAttribute('href')) ?? '', ORIGIN).searchParams.get('change')).toBeNull();
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
		await context.close();
	});
});

test.describe('Changes page on the site under test', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright-changes', { waitUntil: 'domcontentloaded' });
		await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
	});

	test('is in the Activity group of the band with the EXP marker, shows one h1 and has no horizontal overflow', async ({ page }) => {
		await expect(page.locator('h1')).toHaveCount(1);
		await expect(page.locator('h1')).toHaveText('Changes');
		const link = page.locator('.sw-ui-band__link[aria-current="page"]');
		await expect(link).toContainText('Changes');
		await expect(link.locator('.sw-ui-band__exp')).toHaveText('EXP');
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
	});

	test('the filters are a search form that names how each field matches, and a filter that finds nothing says so', async ({ page }) => {
		const form = page.getByRole('search', { name: 'Filter the changes' });
		await expect(form).toBeVisible();
		await expect(form.locator('#sw-changes-f-ability-rule')).toContainText('Exact');
		await expect(form.getByRole('button', { name: 'Filter' })).toBeVisible();
		await expect(form.getByRole('link', { name: 'Reset filters' })).toBeVisible();

		await page.goto('/wp-admin/admin.php?page=stonewright-changes&ability=no-such-ability-here', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('.sw-ui-empty--no-results')).toContainText('No changes match these filters');
		await expect(page.locator('[data-sw-changes-drawer]')).toHaveCount(0);
	});

	test('an address that names no known change says so and opens no drawer', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=stonewright-changes&change=cs-000000000000000000000000', { waitUntil: 'domcontentloaded' });

		await expect(page.locator('.sw-ui-notice--warn')).toContainText('That change is not in the history');
		await expect(page.locator('[data-sw-changes-drawer]')).toHaveCount(0);
	});

	test('a row opens its diff in the drawer, the drawer keeps focus and Escape gives it back', async ({ page }) => {
		const opener = page.locator('[data-sw-changes-open]').first();
		test.skip((await opener.count()) === 0, 'The change history has no rows on this site.');

		await opener.click();
		const drawer = page.locator('[data-sw-changes-drawer]');
		await expect(drawer).toBeVisible();
		await expect(drawer.getByRole('link', { name: /^Close/ })).toBeFocused();
		await expect(drawer.locator('.sw-ui-tabs__tab[aria-selected="true"]')).toHaveText('Diff');
		expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);

		await page.keyboard.press('Escape');
		await expect(drawer).toBeHidden();
		await expect(page.locator('[data-sw-changes-open]').first()).toBeFocused();
	});

	test('Rescue links to the Changes page', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=stonewright-rescue', { waitUntil: 'domcontentloaded' });

		await expect(page.getByRole('link', { name: 'View changes' })).toHaveAttribute('href', /page=stonewright-changes/);
	});
});
