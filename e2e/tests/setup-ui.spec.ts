import { expect, test, type Page } from '@playwright/test';
import { PAGE_GATE_PROJECTS, viewportKind } from './helpers/admin-pages';
import { expectNoNewAxeViolations, settle } from './helpers/axe-gate';
import { gotoAdmin } from './helpers/goto-admin';
import { login } from './helpers/login';
import { H1_TOP_MAX, budgetFor } from './helpers/ui-budget';
import { measureUi } from './helpers/ui-probe';

/**
 * Setup is built from the shared UI layer. This spec holds each of its four views to the page contract (nothing under
 * 12px, no target under 24px, every control named, no repeated id, the h1 near the top, no horizontal overflow, axe),
 * and checks the behaviour the views add: tabs that work from the keyboard and from a link, a link into a hidden view,
 * stored secrets that never reach the page, the domain lock that says what it did, and reduced motion.
 */

const VIEWS = [
	{ tab: 'get-started', name: 'Get started' },
	{ tab: 'settings', name: 'Settings' },
	{ tab: 'connections', name: 'Connections' },
	{ tab: 'updates', name: 'Updates' },
] as const;

function setupUrl(tab: string, extra = ''): string {
	return `/wp-admin/admin.php?page=stonewright${tab === 'get-started' ? '' : `&tab=${tab}`}${extra}`;
}

async function openSetup(page: Page, tab: string, extra = ''): Promise<void> {
	await gotoAdmin(page, setupUrl(tab, extra));
	await page.locator('.sw-setup .sw-ui-tabs').waitFor({ state: 'visible', timeout: 15_000 });
}

/** Turn AI abilities on once per run, so every view is measured with its guides and the sign-in choice available. */
let abilitiesOn = false;

async function ensureAbilitiesOn(page: Page): Promise<void> {
	if (abilitiesOn) {
		return;
	}
	await openSetup(page, 'settings');
	const enable = page.locator('#stonewright_enabled');
	if (!(await enable.isChecked())) {
		await enable.check({ force: true });
		await Promise.all([page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded' }), page.locator('form.stonewright-settings-form').getByRole('button', { name: 'Save settings' }).click()]);
	}
	abilitiesOn = true;
}

const DESKTOP = 'desktop-1440-light';

test.describe('Setup views', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await ensureAbilitiesOn(page);
	});

	for (const { tab, name } of VIEWS) {
		test(`${name} meets the page contract`, async ({ page }, testInfo) => {
			test.skip(!(PAGE_GATE_PROJECTS as readonly string[]).includes(testInfo.project.name), 'The page contract runs at one desktop and one phone width.');
			await openSetup(page, tab);
			await settle(page);

			const kind = viewportKind(testInfo.project.name);
			const budget = budgetFor('stonewright');
			const measured = await measureUi(page, '.sw-shell');

			expect.soft(measured.horizontalOverflow, `${name}: horizontal overflow`).toBe(0);
			expect.soft(measured.drawerOwnContent, `${name}: own content in the notice drawer`).toEqual([]);
			expect.soft(measured.wrongPrimaryFills, `${name}: primary buttons not painted with the accent fill`).toEqual([]);
			expect.soft(measured.unnamedControls, `${name}: controls without a name`).toEqual([]);
			expect.soft(measured.unlabelledFields, `${name}: fields without a label`).toEqual([]);
			expect.soft(measured.duplicateIds, `${name}: duplicate ids`).toEqual([]);
			expect.soft(measured.smallText, `${name}: text under 12px`).toEqual([]);
			expect.soft(measured.smallTargets, `${name}: targets under 24px`).toEqual([]);
			expect.soft(measured.h1Top, `${name}: the h1 starts at ${measured.h1Top}px`).toBeLessThanOrEqual(H1_TOP_MAX[kind]);

			// One primary button per region: a card is a region, so no card holds two, and the page header holds none here.
			const crowded = await page.evaluate(() =>
				Array.from(document.querySelectorAll('.sw-setup .sw-ui-tabs__panel:not([hidden]) .sw-ui-card')).filter(
					(card) => card.querySelectorAll('.sw-ui-btn--primary').length > 1,
				).length,
			);
			expect.soft(crowded, `${name}: a card with more than one primary button`).toBe(0);

			// Destructive actions are never primary.
			const dangerous = await page.locator('.sw-setup .sw-ui-btn--primary', { hasText: /disconnect|revoke|clear domain lock|delete/i }).count();
			expect.soft(dangerous, `${name}: a destructive action styled as primary`).toBe(0);

			await expectNoNewAxeViolations(page, 'stonewright', testInfo);
			expect(budget.axe, 'Setup has no axe allowance left').toEqual([]);
		});
	}

	test('long content: with every disclosure open, each sign-in method stays inside the page', async ({ page }, testInfo) => {
		test.skip(!(PAGE_GATE_PROJECTS as readonly string[]).includes(testInfo.project.name), 'The page contract runs at one desktop and one phone width.');
		await openSetup(page, 'get-started');
		for (const method of ['application-password', 'oauth']) {
			await page.locator(`[data-stonewright-auth-method="${method}"]`).click();
			await page.evaluate(() => document.querySelectorAll('.sw-setup details').forEach((details) => ((details as HTMLDetailsElement).open = true)));
			const measured = await measureUi(page, '.sw-shell');
			expect.soft(measured.horizontalOverflow, `${method}: horizontal overflow with every disclosure open`).toBe(0);
			expect.soft(measured.smallText, `${method}: text under 12px`).toEqual([]);
			expect.soft(measured.smallTargets, `${method}: targets under 24px`).toEqual([]);
			expect.soft(measured.unnamedControls, `${method}: controls without a name`).toEqual([]);
			expect.soft(measured.duplicateIds, `${method}: duplicate ids`).toEqual([]);
		}
	});

	test('tabs move with the arrow, Home and End keys, show one view and keep the choice in the address', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== DESKTOP, 'Tab behaviour runs once.');
		await openSetup(page, 'get-started');
		const tabs = page.locator('.sw-setup .sw-ui-tabs > [role="tab"]');
		await expect(tabs).toHaveCount(4);
		await expect(page.locator('.sw-setup .sw-ui-tabs__panel:not([hidden])')).toHaveCount(1);

		await tabs.first().focus();
		await page.keyboard.press('ArrowRight');
		await expect(page.getByRole('tab', { name: 'Settings' })).toHaveAttribute('aria-selected', 'true');
		await expect(page.locator('.sw-setup .sw-ui-tabs__panel:not([hidden])')).toHaveCount(1);
		await expect(page.locator('#sw-setup-panel-settings')).toBeVisible();
		await expect(page).toHaveURL(/[?&]tab=settings(?:&|$)/);

		await page.keyboard.press('End');
		await expect(page.getByRole('tab', { name: 'Updates' })).toHaveAttribute('aria-selected', 'true');
		await expect(page).toHaveURL(/[?&]tab=updates(?:&|$)/);

		await page.keyboard.press('Home');
		await expect(page.getByRole('tab', { name: 'Get started' })).toHaveAttribute('aria-selected', 'true');
		await expect(page).not.toHaveURL(/[?&]tab=/);

		// Only the selected tab is in the tab order.
		expect(await tabs.evaluateAll((nodes) => nodes.map((node) => (node.getAttribute('tabindex') === '-1' ? 'out' : 'in')))).toEqual(['in', 'out', 'out', 'out']);

		// A reload shows the view the address names.
		await page.getByRole('tab', { name: 'Connections' }).click();
		await page.reload({ waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('tab', { name: /^Connections/ })).toHaveAttribute('aria-selected', 'true');
		await expect(page.locator('#sw-setup-panel-connections')).toBeVisible();
	});

	test('a link to something inside a hidden view opens that view, and the saved form returns to the view that was open', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== DESKTOP, 'Tab behaviour runs once.');
		await openSetup(page, 'get-started');
		await page.locator('[data-stonewright-auth-method="oauth"]').click();
		await page.getByRole('link', { name: 'Review connected OAuth clients' }).first().click();
		await expect(page.locator('#stonewright-oauth-connections')).toBeVisible();
		await expect(page.getByRole('tab', { name: /^Connections/ })).toHaveAttribute('aria-selected', 'true');

		await page.getByRole('tab', { name: 'Settings' }).click();
		const referer = await page.locator('form.stonewright-settings-form input[name="_wp_http_referer"]').inputValue();
		expect(referer).toContain('tab=settings');

		// A direct visit with the anchor opens the view it sits in.
		await page.goto(`${setupUrl('get-started')}#stonewright-domain-lock`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#stonewright-domain-lock')).toBeVisible();
		await expect(page.getByRole('tab', { name: 'Settings' })).toHaveAttribute('aria-selected', 'true');
	});

	test('a stored secret never reaches the page and an empty field keeps it', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== DESKTOP, 'Saving a setting runs once.');
		const secret = `synthetic-pexels-key-${Date.now()}`;
		await openSetup(page, 'settings');
		await page.locator('#stonewright_pexels_api_key').fill(secret);
		await Promise.all([page.waitForURL(/tab=settings/, { waitUntil: 'domcontentloaded' }), page.locator('form.stonewright-settings-form').getByRole('button', { name: 'Save settings' }).click()]);
		await expect(page.getByText('Settings saved', { exact: false }).first()).toBeVisible();

		const field = page.locator('#stonewright_pexels_api_key');
		await expect(field).toHaveValue('');
		await expect(field).toHaveAttribute('placeholder', /Stored/);
		expect(await page.content()).not.toContain(secret);

		// Saving with the field empty keeps it; the explicit control removes it.
		await Promise.all([page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded' }), page.locator('form.stonewright-settings-form').getByRole('button', { name: 'Save settings' }).click()]);
		await expect(page.locator('#stonewright_pexels_api_key')).toHaveAttribute('placeholder', /Stored/);
		await page.getByLabel('Remove the stored value when saving').first().check();
		await Promise.all([page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded' }), page.locator('form.stonewright-settings-form').getByRole('button', { name: 'Save settings' }).click()]);
		await expect(page.locator('#stonewright_pexels_api_key')).not.toHaveAttribute('placeholder', /Stored/);
	});

	test('the two verify buttons share one height and one baseline, and every result keeps its status, name and detail apart', async ({ page }) => {
		await openSetup(page, 'get-started');
		const verify = page.locator('#stonewright-verify');
		await verify.scrollIntoViewIfNeeded();

		// The page script reads its answers from two routes; both are answered here so the lists hold known rows.
		await page.route(/connection-test/, (route) =>
			route.fulfill({
				json: {
					ready: false,
					checks: [
						{ id: 'abilities', status: 'ok', label: 'Stonewright abilities', detail: 'Enabled for this site.' },
						{ id: 'elementor', status: 'warning', label: 'Elementor', detail: 'Not detected.', fix: 'Install and activate Elementor for design tools.' },
					],
				},
			}),
		);
		await page.route(/connection-verify/, (route) =>
			route.fulfill({
				json: {
					ok: false,
					steps: [
						{ id: 'mint_credential', status: 'passed', detail: 'Created a short-lived credential.' },
						{ id: 'initialize', status: 'failed', detail: 'The endpoint answered 403.', fix: 'Ask the host to allow POST requests to the MCP route.' },
						{ id: 'initialized', status: 'failed', detail: 'Not run because initialize failed.', fix: 'Fix the earlier failed step, then retry Verify connection.' },
					],
				},
			}),
		);

		const preflight = verify.getByRole('button', { name: 'Run preflight', exact: true });
		const confirm = verify.getByRole('button', { name: 'Verify connection', exact: true });
		const boxes = async () =>
			page.evaluate(() => {
				const label = (button: Element) => {
					const range = document.createRange();
					range.selectNodeContents(button);
					return range.getBoundingClientRect();
				};
				return Array.from(document.querySelectorAll('#stonewright-verify .sw-ui-actions > button')).map((button) => ({ top: button.getBoundingClientRect().top, height: button.getBoundingClientRect().height, text: label(button).bottom, classes: button.className }));
			});
		const [before, after] = await boxes();
		expect(before.height, 'the same height').toBe(after.height);
		const wrapped = before.top !== after.top && after.top > before.top + before.height - 1;
		if (!wrapped) {
			expect(before.top, 'the same top').toBe(after.top);
			expect(Math.abs(before.text - after.text), 'the label of both sits on one baseline').toBeLessThanOrEqual(0.5);
		}
		expect(`${before.classes} ${after.classes}`, 'two layer buttons, no core .button').not.toMatch(/\bbutton\b/);

		await preflight.click();
		await confirm.click();
		const lists = verify.locator('.sw-ui-checks:not([hidden])');
		await expect(lists).toHaveCount(2);
		const [again, last] = await boxes();
		expect(again.height, 'still the same height with a longer feedback label').toBe(last.height);

		const rows = await verify.locator('.sw-ui-checks__item').evaluateAll((items) =>
			items.map((item) => {
				const [status, name, detail] = Array.from(item.children).map((child) => child.getBoundingClientRect());
				const style = getComputedStyle(item);
				return {
					parts: Array.from(item.children).map((child) => child.className.replace(/ sw-ui-badge--\w+/, '')),
					statusToName: name.left - status.right,
					nameToDetail: detail.left - name.right,
					detailBelowName: detail.top >= name.bottom - 1,
					left: name.left,
					background: style.backgroundColor,
					text: item.textContent ?? '',
					status: item.getAttribute('data-status'),
				};
			}),
		);
		expect(rows).toHaveLength(5);
		const phone = (page.viewportSize()?.width ?? 1440) <= 782;
		for (const row of rows) {
			expect(row.parts, 'a status, a name and a detail of their own').toEqual(['sw-ui-badge', 'sw-ui-checks__label', 'sw-ui-checks__detail']);
			expect(row.statusToName, `${row.text}: space between the status and the name`).toBeGreaterThanOrEqual(8);
			if (phone) {
				expect(row.detailBelowName, `${row.text}: the detail sits under the name on a phone`).toBe(true);
			} else {
				expect(row.nameToDetail, `${row.text}: space between the name and the detail`).toBeGreaterThanOrEqual(8);
			}
			if (row.status === 'problem') {
				expect(row.background, `${row.text}: a failure is tinted`).not.toBe('rgba(0, 0, 0, 0)');
			}
		}
		if (!phone) {
			expect(new Set(rows.map((row) => Math.round(row.left))).size, 'the names form one column').toBe(1);
		}
		await expect(verify.locator('.sw-ui-checks__item[data-status="problem"] .sw-ui-badge')).toHaveText(['Problem', 'Problem']);
		await expect(verify.locator('.sw-ui-checks__item[data-status="problem"] .sw-ui-checks__fix').first()).toHaveText('Ask the host to allow POST requests to the MCP route.');
	});

	test('the domain lock action is a secondary button of the default size in the card footer, clear of the facts and in line with them', async ({ page }) => {
		await openSetup(page, 'settings');
		const card = page.locator('#stonewright-domain-lock');
		await card.scrollIntoViewIfNeeded();
		const clear = card.getByRole('button', { name: 'Clear domain lock' });
		await expect(clear).toBeVisible();
		await expect(card.locator('.sw-ui-card__footer').getByRole('button', { name: 'Clear domain lock' })).toHaveCount(1);
		await expect(clear).toHaveClass(/^sw-ui-btn$/);

		const measured = await page.evaluate(() => {
			const cardNode = document.querySelector('#stonewright-domain-lock') as HTMLElement;
			const button = cardNode.querySelector('.sw-ui-card__footer button') as HTMLElement;
			const save = document.querySelector('form.stonewright-settings-form button.sw-ui-btn--primary') as HTMLElement;
			const label = cardNode.querySelector('.sw-ui-kv dt') as HTMLElement;
			const facts = cardNode.querySelector('.sw-ui-kv') as HTMLElement;
			const footer = cardNode.querySelector('.sw-ui-card__footer') as HTMLElement;
			const hint = footer.querySelector('.sw-ui-hint');
			const buttonBox = button.getBoundingClientRect();
			const footerBox = footer.getBoundingClientRect();
			return {
				height: buttonBox.height,
				saveHeight: save.getBoundingClientRect().height,
				disabled: (button as HTMLButtonElement).disabled,
				disabledBorder: getComputedStyle(button).borderTopColor,
				left: buttonBox.left,
				factsLeft: label.getBoundingClientRect().left,
				belowFacts: buttonBox.top - facts.getBoundingClientRect().bottom,
				centred: hint !== null && hint.getBoundingClientRect().top >= buttonBox.bottom ? 0 : Math.abs(buttonBox.top - footerBox.top - (footerBox.bottom - buttonBox.bottom)),
				hintBeside: hint === null ? null : hint.getBoundingClientRect().left >= buttonBox.right || hint.getBoundingClientRect().top >= buttonBox.bottom,
			};
		});
		expect(measured.height, 'the same height as Save settings').toBe(measured.saveHeight);
		expect(Math.abs(measured.left - measured.factsLeft), 'in line with the labels of the facts').toBeLessThanOrEqual(1);
		expect(measured.belowFacts, 'clear of the facts').toBeGreaterThanOrEqual(8);
		expect(measured.centred, 'equal space above and below in the footer').toBeLessThanOrEqual(1);
		if (measured.disabled) {
			expect(measured.disabledBorder, 'a disabled button reads as disabled the layer\'s way').toBe('rgb(220, 220, 222)');
			expect(measured.hintBeside).toBe(true);
		}
	});

	test('the domain lock says what it did: cleared is shown, and it cannot be cleared while abilities are on', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== DESKTOP, 'Saving a setting runs once.');
		await openSetup(page, 'settings');
		const enable = page.locator('#stonewright_enabled');
		const save = page.locator('form.stonewright-settings-form').getByRole('button', { name: 'Save settings' });
		const clear = page.getByRole('button', { name: 'Clear domain lock' });

		// On: the lock is recorded and clearing is disabled with its reason.
		if (!(await enable.isChecked())) {
			await enable.check({ force: true });
			await Promise.all([page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded' }), save.click()]);
		}
		await expect(page.locator('#stonewright-domain-lock')).toContainText('Locked');
		await expect(clear).toBeDisabled();
		await expect(page.locator('#stonewright-domain-lock')).toContainText('sets the lock again as soon as it loads');

		// Off: clearing works, says so, and the state reads Not set. The run ends with abilities on again.
		try {
			await page.locator('#stonewright_enabled').uncheck({ force: true });
			await Promise.all([page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded' }), save.click()]);
			await expect(clear).toBeEnabled();
			await Promise.all([page.waitForURL(/lock_reset=1/, { waitUntil: 'domcontentloaded' }), clear.click()]);
			const card = page.locator('#stonewright-domain-lock');
			await expect(card.getByRole('status')).toContainText('Domain lock cleared');
			await expect(card).toContainText('Not set');
			await expect(page.getByRole('tab', { name: 'Settings' })).toHaveAttribute('aria-selected', 'true');
		} finally {
			await openSetup(page, 'settings');
			if (!(await page.locator('#stonewright_enabled').isChecked())) {
				await page.locator('#stonewright_enabled').check({ force: true });
				await Promise.all([page.waitForURL(/settings-updated/, { waitUntil: 'domcontentloaded' }), save.click()]);
			}
		}
	});

	test('reduced motion stops every animation and transition in every view', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== DESKTOP, 'Reduced motion runs once.');
		await page.emulateMedia({ reducedMotion: 'reduce' });
		for (const { tab, name } of VIEWS) {
			await openSetup(page, tab);
			await settle(page);
			const result = await page.evaluate(() => {
				const running = document.getAnimations().filter((animation) => animation.playState === 'running').map((animation) => animation.id || animation.constructor.name);
				const moving: string[] = [];
				document.querySelectorAll('.sw-setup *').forEach((el) => {
					const style = getComputedStyle(el);
					const times = (style.transitionDuration + ',' + style.animationDuration + ',' + style.transitionDelay).split(',').map((value) => parseFloat(value));
					if (times.some((value) => value > 0)) {
						moving.push(el.tagName.toLowerCase() + '.' + String(el.className).split(' ')[0]);
					}
				});
				return { running, moving: moving.slice(0, 5) };
			});
			expect(result, `${name}: motion under prefers-reduced-motion`).toEqual({ running: [], moving: [] });
		}
	});

	test('Setup works without script: the server shows the view the address names and the tabs are links', async ({ browser }, testInfo) => {
		test.skip(testInfo.project.name !== DESKTOP, 'The no-script check runs once.');
		const context = await browser.newContext({ javaScriptEnabled: false });
		const page = await context.newPage();
		await login(page);
		await page.goto(setupUrl('settings'), { waitUntil: 'domcontentloaded' });
		// Without script the server still shows the view the address names, and the tabs are links.
		await expect(page.locator('#sw-setup-panel-settings')).toBeVisible();
		await expect(page.locator('#sw-setup-panel-get-started')).toBeHidden();
		const href = await page.getByRole('tab', { name: 'Updates' }).getAttribute('href');
		expect(href).toContain('tab=updates');
		await page.getByRole('tab', { name: 'Updates' }).click();
		await expect(page.locator('#sw-setup-panel-updates')).toBeVisible();
		await context.close();
	});
});
