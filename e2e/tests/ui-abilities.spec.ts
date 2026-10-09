import { expect, test, type Locator, type Page } from '@playwright/test';
import { login } from './helpers/login';
import { expectNoAxeViolations, settle } from './helpers/axe-gate';
import { isRestRoute, restRouteUrl } from './helpers/rest-route';

/**
 * AI Abilities, built from the shared UI layer.
 *
 * Behaviour is checked at one desktop width and one phone width (the widths of the page contract). Every test puts
 * the abilities back the way it found them: the option is shared by the whole suite.
 */

const URL = '/wp-admin/admin.php?page=stonewright-abilities';
const DESKTOP = 'desktop-1440-light';
const PHONE = 'mobile-390-light';
// Matchers for the REST calls the page makes; they match plain permalinks (?rest_route=...) as well as /wp-json/.
const TOGGLE_ROUTE = restRouteUrl('stonewright/v1/admin/abilities/toggle');
const PARAMETERS_ROUTE = restRouteUrl('stonewright/v1/admin/abilities/parameters');

/**
 * Behaviour does not depend on the viewport, so it runs once, at desktop width. A test tagged @phone also runs at
 * phone width, one tagged @phone-only runs there alone, and a title starting "every viewport:" runs at every width.
 */
test.beforeEach(async ({ page }, testInfo) => {
	const project = testInfo.project.name;
	const phoneOnly = testInfo.tags.includes('@phone-only');
	const phoneToo = testInfo.tags.includes('@phone');
	const everyViewport = testInfo.title.startsWith('every viewport:');
	const runs = everyViewport || (phoneOnly ? project === PHONE : project === DESKTOP || (phoneToo && project === PHONE));
	test.skip(!runs, 'Behaviour runs at desktop width; layout checks tagged @phone also run at phone width.');
	await login(page);
});

async function open(page: Page): Promise<void> {
	await page.goto(URL, { waitUntil: 'domcontentloaded' });
	await expect(page.locator('.sw-abilities')).toBeVisible();
	await page.waitForFunction(() => Boolean(document.querySelector('[data-sw-category-actions]:not([hidden])')));
}

/** The row of one ability, with its category opened by searching for it. */
async function row(page: Page, search: string): Promise<Locator> {
	await page.locator('#stonewright-ability-search').fill(search);
	const found = page.locator('tr:not([hidden]):has([data-sw-ability])').first();
	await expect(found).toBeVisible();
	return found;
}

async function switchOf(tr: Locator): Promise<Locator> {
	return tr.locator('input[role="switch"]');
}

/** Read the state the server holds, through the page's own bulk route, then restore it. */
async function restore(page: Page, names: string[], enabled: boolean): Promise<void> {
	// A switch change still in flight could land after this request and undo it, so let the page's requests finish first.
	await page.waitForLoadState('networkidle').catch(() => undefined);
	const config = await page.evaluate(() => {
		const root = document.querySelector('[data-sw-abilities]') as HTMLElement;
		return { url: root.dataset.restUrl ?? '', rest: root.dataset.restNonce ?? '', bulk: root.dataset.bulkNonce ?? '' };
	});
	const response = await page.request.post(`${config.url}/bulk`, {
		headers: { 'X-WP-Nonce': config.rest },
		data: { action: enabled ? 'enable_selected' : 'disable_selected', abilities: names, action_nonce: config.bulk },
	});
	expect(response.ok(), 'restoring the abilities').toBeTruthy();
}

test.describe('search', () => {
	test('filters the rows, opens the categories that match, says how many are shown and offers a way out', async ({ page }) => {
		await open(page);
		const meta = page.locator('[data-sw-abilities-meta]');
		const total = await page.locator('[data-sw-ability]').count();
		await expect(meta).toContainText(`${total} abilities`);
		await expect(page.locator('details.sw-abilities__category[open]')).toHaveCount(0);

		await page.locator('#stonewright-ability-search').fill('ping');
		const visible = page.locator('tr:not([hidden]):has([data-sw-ability])');
		await expect(visible.first()).toBeVisible();
		const shown = await visible.count();
		expect(shown).toBeGreaterThan(0);
		expect(shown).toBeLessThan(total);
		await expect(meta).toContainText(`${shown} of ${total} abilities`);
		await expect(page.locator('details.sw-abilities__category[open]').first()).toBeVisible();
		await expect(page.locator('mark').first()).toBeVisible();

		await page.locator('#stonewright-ability-search').fill('zzzz-no-such-ability');
		await expect(page.locator('[data-sw-abilities-empty]')).toBeVisible();
		await expect(page.locator('[data-sw-abilities-empty]')).toContainText('No abilities match “zzzz-no-such-ability”');
		await expect(meta).toContainText(`0 of ${total} abilities`);

		await page.getByRole('button', { name: 'Clear search' }).click();
		await expect(page.locator('#stonewright-ability-search')).toHaveValue('');
		await expect(page.locator('#stonewright-ability-search')).toBeFocused();
		await expect(page.locator('[data-sw-abilities-empty]')).toBeHidden();
		await expect(page.locator('details.sw-abilities__category[open]')).toHaveCount(0);
		await expect(meta).toContainText(`${total} abilities`);
	});

	test('the slash key focuses the search field and Escape clears it', async ({ page }) => {
		test.skip(test.info().project.name !== DESKTOP, 'Keyboard shortcuts are for desktop.');
		await open(page);
		await page.locator('body').click({ position: { x: 5, y: 5 } });
		await page.keyboard.press('/');
		await expect(page.locator('#stonewright-ability-search')).toBeFocused();
		await page.keyboard.type('memory');
		await expect(page.locator('tr:not([hidden]):has([data-sw-ability])').first()).toBeVisible();
		await page.keyboard.press('Escape');
		await expect(page.locator('#stonewright-ability-search')).toHaveValue('');
	});
});

test.describe('the switch', () => {
	test('changes the ability without leaving the page, confirms it in a toast that can be undone, and keeps it after a reload', async ({ page }) => {
		await open(page);
		let navigations = 0;
		page.on('framenavigated', (frame) => {
			if (frame === page.mainFrame()) {
				navigations += 1;
			}
		});

		const tr = await row(page, 'site-info');
		const toggle = await switchOf(tr);
		const name = (await tr.locator('[data-sw-ability]').getAttribute('data-sw-ability')) ?? '';
		const label = (await tr.locator('[data-ability-label]').getAttribute('data-ability-label')) ?? '';
		await expect(toggle).toBeChecked();
		const before = navigations;

		try {
			await toggle.click();
			await expect(toggle).not.toBeChecked();
			await expect(tr.locator('[data-sw-ability-state]')).toHaveText('Off');
			const toast = page.locator('.sw-ui-toast').filter({ hasText: `${label} turned off.` });
			await expect(toast).toBeVisible();
			await expect(page.locator('[data-sw-ui-live="polite"], .sw-ui-toast-region').first()).toBeAttached();
			expect(navigations, 'no page load for a switch').toBe(before);

			await page.reload({ waitUntil: 'domcontentloaded' });
			await page.locator('#stonewright-ability-search').fill('site-info');
			await expect(page.locator(`input[role="switch"][data-sw-ability-switch="${name}"]`)).not.toBeChecked();

			await page.locator(`input[role="switch"][data-sw-ability-switch="${name}"]`).click();
			await expect(page.locator(`input[role="switch"][data-sw-ability-switch="${name}"]`)).toBeChecked();
			await page.locator('.sw-ui-toast').filter({ hasText: 'turned on.' }).getByRole('button', { name: 'Undo' }).click();
			await expect(page.locator(`input[role="switch"][data-sw-ability-switch="${name}"]`)).not.toBeChecked();
			await expect(page.locator('.sw-ui-toast').filter({ hasText: 'Change undone.' })).toBeVisible();
		} finally {
			await restore(page, [name], true);
		}
	});

	test('can be operated with the keyboard alone', async ({ page }) => {
		test.skip(test.info().project.name !== DESKTOP, 'Keyboard operation is checked at desktop width.');
		await open(page);
		const tr = await row(page, 'site-info');
		const toggle = await switchOf(tr);
		const name = (await tr.locator('[data-sw-ability]').getAttribute('data-sw-ability')) ?? '';
		try {
			await toggle.focus();
			await page.keyboard.press('Space');
			await expect(toggle).not.toBeChecked();
			await page.keyboard.press('Space');
			await expect(toggle).toBeChecked();
		} finally {
			await restore(page, [name], true);
		}
	});

	test('puts the switch back and says so, in a notice that stays, when the server refuses', async ({ page }) => {
		await page.clock.install();
		await open(page);
		await page.route(TOGGLE_ROUTE, (route) =>
			route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ code: 'stonewright_abilities_invalid_nonce', message: 'This page has expired. Reload it and try again.' }) }),
		);
		const tr = await row(page, 'site-info');
		const toggle = await switchOf(tr);
		await toggle.click();

		await expect(toggle).toBeChecked();
		const notice = page.locator('[data-sw-abilities-failure]');
		await expect(notice).toBeVisible();
		await expect(notice).toHaveAttribute('role', 'alert');
		await expect(notice).toContainText('The change was not saved');
		await expect(notice).toContainText('This page has expired');
		// Let the time a toast lives pass, without waiting for it.
		await page.clock.fastForward(10_000);
		await expect(notice, 'an error never goes away by itself').toBeVisible();
	});

	test('puts the switch back when the server cannot be reached', async ({ page }) => {
		await open(page);
		await page.route(TOGGLE_ROUTE, (route) => route.abort('failed'));
		const tr = await row(page, 'site-info');
		const toggle = await switchOf(tr);
		await toggle.click();

		await expect(toggle).toBeChecked();
		await expect(page.locator('[data-sw-abilities-failure]')).toContainText('could not be reached');
	});

	test('hands the change to the form when the REST route is blocked', async ({ page }) => {
		await open(page);
		const name = (await (await row(page, 'site-info')).locator('[data-sw-ability]').getAttribute('data-sw-ability')) ?? '';
		// Start from the ability switched on, whatever an earlier test of the group left behind.
		await restore(page, [name], true);
		await open(page);
		await page.route(TOGGLE_ROUTE, (route) => route.fulfill({ status: 404, contentType: 'text/html', body: '<p>Blocked</p>' }));
		const tr = await row(page, 'site-info');
		const toggle = await switchOf(tr);
		await expect(toggle).toBeChecked();
		try {
			await Promise.all([page.waitForURL(/stonewright_toggled=disabled/), toggle.click()]);
			await expect(page.locator('.sw-ui-notice--ok')).toContainText('Ability turned off.');
		} finally {
			await page.unroute(TOGGLE_ROUTE);
			await page.goto(URL, { waitUntil: 'domcontentloaded' });
			await restore(page, [name], true);
		}
	});
});

test.describe('bulk actions', () => {
	test('Apply with nothing chosen says what is missing, moves focus to it and does not reload (QA-29)', async ({ page }) => {
		await open(page);
		let posts = 0;
		page.on('request', (request) => {
			if (request.method() === 'POST') {
				posts += 1;
			}
		});
		await page.getByRole('button', { name: 'Apply' }).click();
		const error = page.locator('[data-sw-abilities-error]');
		await expect(error).toBeVisible();
		await expect(error).toHaveText('Choose a bulk action, then press Apply.');
		await expect(page.locator('#stonewright-bulk-action')).toBeFocused();
		await expect(page.locator('#stonewright-bulk-action')).toHaveAttribute('aria-invalid', 'true');
		await expect(page.locator('[data-sw-ui-live="assertive"]')).toHaveText('Choose a bulk action, then press Apply.');

		await page.selectOption('#stonewright-bulk-action', 'enable_selected');
		await expect(error).toBeHidden();
		await page.getByRole('button', { name: 'Apply' }).click();
		await expect(error).toHaveText('Select at least one ability, then press Apply.');

		await page.selectOption('#stonewright-bulk-action', 'disable_category');
		await expect(page.locator('#stonewright-bulk-category')).toBeEnabled();
		await page.getByRole('button', { name: 'Apply' }).click();
		await expect(error).toHaveText('Choose a category for that action, then press Apply.');
		await expect(page.locator('#stonewright-bulk-category')).toBeFocused();
		expect(posts, 'nothing was sent').toBe(0);
	});

	test('disables the selected abilities, says how many, counts them and can be undone', async ({ page }) => {
		await open(page);
		await page.locator('#stonewright-ability-search').fill('memory');
		const visible = page.locator('tr:not([hidden]):has([data-sw-ability])');
		await expect(visible.first()).toBeVisible();
		const names = await visible.locator('[data-sw-ability]').evaluateAll((nodes) => nodes.map((node) => (node as HTMLElement).dataset.swAbility ?? ''));
		expect(names.length).toBeGreaterThan(1);
		const statsBefore = (await page.locator('[data-sw-abilities-stats]').textContent()) ?? '';

		try {
			await page.locator('[data-sw-abilities-select-all]').check();
			await expect(page.locator('[data-sw-abilities-selected]')).toHaveText(`${names.length} selected`);
			await page.selectOption('#stonewright-bulk-action', 'disable_selected');
			await page.getByRole('button', { name: 'Apply' }).click();

			const toast = page.locator('.sw-ui-toast').first();
			await expect(toast).toContainText(/abilit(y|ies) turned off|already off/);
			for (const name of names) {
				await expect(page.locator(`input[role="switch"][data-sw-ability-switch="${name}"]`)).not.toBeChecked();
			}
			await expect(page.locator('[data-sw-abilities-selected]')).toHaveText('');
			const statsAfter = (await page.locator('[data-sw-abilities-stats]').textContent()) ?? '';
			expect(statsAfter).not.toBe(statsBefore);

			await toast.getByRole('button', { name: 'Undo' }).click();
			await expect(page.locator('.sw-ui-toast').filter({ hasText: 'Change undone.' })).toBeVisible();
			await expect(page.locator('[data-sw-abilities-stats]')).toHaveText(statsBefore);
		} finally {
			await restore(page, names, true);
		}
	});

	test('a category can be turned off and on from its own buttons', async ({ page }) => {
		await open(page);
		const category = page.locator('details.sw-abilities__category[data-category="memory"]').first();
		await category.locator(':scope > summary').click();
		const names = await category.locator('[data-sw-ability]').evaluateAll((nodes) => nodes.map((node) => (node as HTMLElement).dataset.swAbility ?? ''));
		try {
			await category.getByRole('button', { name: 'Disable all in Memory' }).click();
			await expect(page.locator('.sw-ui-toast').first()).toContainText(/turned off|already off/);
			await expect(category.locator('[data-sw-category-count]')).toHaveText(`0 of ${names.length} on`);
			await category.getByRole('button', { name: 'Enable all in Memory' }).click();
			await expect(page.locator('.sw-ui-toast').filter({ hasText: /turned on|already on/ }).first()).toBeVisible();
		} finally {
			await restore(page, names, true);
		}
	});
});

test.describe('without script', () => {
	test.use({ javaScriptEnabled: false });

	test('the bulk form still works, and Apply with nothing chosen is not silent (QA-29)', async ({ page }) => {
		await page.goto(URL, { waitUntil: 'domcontentloaded' });
		await page.getByRole('button', { name: 'Apply' }).click();
		const notice = page.locator('.sw-ui-notice--warn');
		await expect(notice).toBeVisible();
		await expect(notice).toHaveAttribute('role', 'alert');
		await expect(notice).toContainText('Choose a bulk action, then press Apply.');

		await page.selectOption('#stonewright-bulk-action', 'enable_selected');
		await page.getByRole('button', { name: 'Apply' }).click();
		await expect(page.locator('.sw-ui-notice--warn')).toContainText('Select at least one ability, then press Apply.');

		await page.selectOption('#stonewright-bulk-action', 'enable_category');
		await page.getByRole('button', { name: 'Apply' }).click();
		await expect(page.locator('.sw-ui-notice--warn')).toContainText('Choose a category for that action, then press Apply.');
	});

	test('a selection is applied by the form and reported in a notice', async ({ page }) => {
		await page.goto(URL, { waitUntil: 'domcontentloaded' });
		const first = page.locator('input[name="stonewright_abilities[]"]').first();
		const name = (await first.getAttribute('value')) ?? '';
		await first.evaluate((node) => ((node as HTMLInputElement).checked = true));
		await page.selectOption('#stonewright-bulk-action', 'disable_selected');
		await page.getByRole('button', { name: 'Apply' }).click();
		await expect(page.locator('.sw-ui-notice--ok')).toContainText(/1 ability turned off|already off/);

		await page.locator('input[name="stonewright_abilities[]"]').first().evaluate((node) => ((node as HTMLInputElement).checked = true));
		await page.selectOption('#stonewright-bulk-action', 'enable_selected');
		await page.getByRole('button', { name: 'Apply' }).click();
		await expect(page.locator('.sw-ui-notice--ok')).toContainText(/1 ability turned on|already on/);
		expect(name).not.toBe('');
	});
});

test.describe('the routes keep the gates of the form handlers', () => {
	test('a write needs the REST nonce and the nonce of its form', async ({ page }) => {
		await open(page);
		const config = await page.evaluate(() => {
			const root = document.querySelector('[data-sw-abilities]') as HTMLElement;
			return { url: root.dataset.restUrl ?? '', rest: root.dataset.restNonce ?? '', toggle: root.dataset.toggleNonce ?? '', bulk: root.dataset.bulkNonce ?? '' };
		});
		const name = 'stonewright/ping';
		const body = (nonce: string) => ({ name, enabled: true, action_nonce: nonce });

		const noRestNonce = await page.request.post(`${config.url}/toggle`, { data: body(config.toggle) });
		expect([401, 403]).toContain(noRestNonce.status());

		const wrongFormNonce = await page.request.post(`${config.url}/toggle`, { headers: { 'X-WP-Nonce': config.rest }, data: body(config.bulk) });
		expect(wrongFormNonce.status()).toBe(403);
		expect((await wrongFormNonce.json()).code).toBe('stonewright_abilities_invalid_nonce');

		const noFormNonce = await page.request.post(`${config.url}/toggle`, { headers: { 'X-WP-Nonce': config.rest }, data: { name, enabled: true } });
		expect(noFormNonce.status()).toBe(403);

		const refused = await page.request.post(`${config.url}/bulk`, { headers: { 'X-WP-Nonce': config.rest }, data: { action: '', action_nonce: config.bulk } });
		expect(refused.status()).toBe(400);
		expect((await refused.json()).message).toBe('Choose a bulk action, then press Apply.');

		const allowed = await page.request.post(`${config.url}/toggle`, { headers: { 'X-WP-Nonce': config.rest }, data: body(config.toggle) });
		expect(allowed.ok()).toBeTruthy();
		const payload = await allowed.json();
		expect(payload).toMatchObject({ ok: true, name, enabled: true });
		expect(payload.stats.total).toBeGreaterThan(100);
	});

	test('the parameters of an ability load when its row is opened, and only then', async ({ page }) => {
		await open(page);
		const requests: string[] = [];
		page.on('request', (request) => {
			if (isRestRoute(request.url(), 'stonewright/v1/admin/abilities/parameters')) {
				requests.push(request.url());
			}
		});
		const tr = await row(page, 'create-post');
		const details = tr.locator('details[data-sw-ability-params]');
		expect(requests).toHaveLength(0);
		await details.locator(':scope > summary').click();
		await expect(details.locator('dl.sw-ui-kv')).toBeVisible();
		await expect(details.locator('dt code').first()).toBeVisible();
		expect(requests).toHaveLength(1);
		await details.locator(':scope > summary').click();
		await details.locator(':scope > summary').click();
		expect(requests, 'loaded once').toHaveLength(1);
	});

	test('a failed parameter request says so and can be retried', async ({ page }) => {
		await open(page);
		let fail = true;
		await page.route(PARAMETERS_ROUTE, (route) => (fail ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"x","message":"x"}' }) : route.continue()));
		const tr = await row(page, 'create-post');
		const details = tr.locator('details[data-sw-ability-params]');
		await details.locator(':scope > summary').click();
		await expect(details).toContainText('Parameters could not be loaded.');
		fail = false;
		await details.getByRole('button', { name: 'Try again' }).click();
		await expect(details.locator('dl.sw-ui-kv')).toBeVisible();
	});
});

test.describe('page states', () => {
	test('reduced motion removes every transition and animation on the page', async ({ page }) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await open(page);
		await page.locator('details.sw-abilities__category > summary').first().click();
		await settle(page);
		const result = await page.evaluate(() => {
			const toMilliseconds = (list: string): number[] => list.split(',').map((item) => (item.trim().endsWith('ms') ? parseFloat(item) : parseFloat(item) * 1000));
			const offenders: string[] = [];
			document.querySelectorAll('.sw-abilities, .sw-abilities *').forEach((element) => {
				for (const pseudo of [null, '::before', '::after']) {
					const style = getComputedStyle(element, pseudo);
					const longest = Math.max(...toMilliseconds(style.transitionDuration), ...toMilliseconds(style.animationDuration), ...toMilliseconds(style.transitionDelay), ...toMilliseconds(style.animationDelay));
					if (longest > 0 && offenders.length < 10) {
						offenders.push(`${element.tagName.toLowerCase()}.${(element.getAttribute('class') ?? '').split(' ')[0]}${pseudo ?? ''}`);
					}
				}
			});
			return { offenders, running: document.getAnimations().length };
		});
		expect(result.offenders).toEqual([]);
		expect(result.running).toBe(0);
	});

	test('the filter bar sticks under the admin bar from 783px up and scrolls away on a phone', { tag: '@phone' }, async ({ page }, testInfo) => {
		await open(page);
		await page.locator('details.sw-abilities__category > summary').first().click();
		await page.evaluate(() => window.scrollTo(0, 1200));
		const top = await page.locator('.sw-ui-toolbar').evaluate((node) => Math.round(node.getBoundingClientRect().top));
		if (testInfo.project.name === DESKTOP) {
			expect(top).toBeGreaterThanOrEqual(0);
			expect(top).toBeLessThanOrEqual(40);
		} else {
			expect(top).toBeLessThan(0);
		}
	});

	test('rows stack into cards at phone width and every control keeps a touch-sized target', { tag: '@phone-only' }, async ({ page }) => {
		await open(page);
		await page.locator('details.sw-abilities__category > summary').first().click();
		const sizes = await page.evaluate(() => {
			const small: string[] = [];
			document.querySelectorAll<HTMLElement>('.sw-abilities__category[open] input[role="switch"], .sw-abilities__category[open] input[type="checkbox"]').forEach((input) => {
				const box = (input.closest('label') ?? input).getBoundingClientRect();
				if (box.height < 40 || box.width < 24) {
					small.push(`${input.getAttribute('data-sw-ability-switch') ?? input.getAttribute('value')} ${Math.round(box.width)}x${Math.round(box.height)}`);
				}
			});
			const header = document.querySelector('.sw-abilities__table thead');
			return { small, headerHidden: header ? getComputedStyle(header).clipPath !== 'none' : false };
		});
		expect(sizes.small).toEqual([]);
		expect(sizes.headerHidden).toBe(true);
	});
});

test.describe('on every viewport', () => {
	test('every viewport: has no WCAG 2.2 AA violation of any impact and nothing scrolls sideways, with a category, a parameter list and a selection open', async ({ page }, testInfo) => {
		// Axe walks every node of a page of about 11,000 elements.
		test.setTimeout(120_000);
		await open(page);
		const tr = await row(page, 'create-post');
		await tr.locator('details[data-sw-ability-params] > summary').click();
		await expect(tr.locator('dl.sw-ui-kv')).toBeVisible();
		await page.locator('[data-sw-abilities-select-all]').check();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow).toBe(0);
		await expectNoAxeViolations(page, testInfo, 'abilities-open', '.sw-shell');
	});
});
