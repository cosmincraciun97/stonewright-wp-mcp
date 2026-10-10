import { expect, test, type Page } from '@playwright/test';
import { settle } from './helpers/axe-gate';
import { login } from './helpers/login';

/**
 * Behaviour of the Activity pages that the page loop and the UI contract cannot see: the Audit log drawers and the
 * typed delete confirmation, and the Troubleshoot run. Each is checked once, at desktop width; the layout at every
 * width is the job of the contract specs.
 *
 * Nothing here deletes or writes: the delete dialog is opened and closed, never submitted, and the Troubleshoot
 * request is answered by a synthetic report so the result does not depend on the site under test.
 */

const DESKTOP = 'desktop-1440-light';

const SYNTHETIC_REPORT = {
	method: 'oauth-http',
	counts: { problem: 1, warning: 1, info: 0, ok: 1, skipped: 0 },
	versions: { plugin: '0.0.0-example', companion_contract: '1.0.0' },
	checks: [
		{ id: 'endpoint', status: 'problem', label: 'MCP endpoint', summary: 'The endpoint answered HTTP 403.', remedy: 'Ask the host to allow POST requests to the MCP route, then run the checks again.', action: { type: 'retry', label: 'Run the checks again', target: 'run' } },
		{ id: 'bot_filter', status: 'warning', label: 'Bot filter', summary: 'A client user agent was blocked.', remedy: 'Ask the host to allow it.', copy: 'Please allow this client on https://example.test', action: { type: 'copy', label: 'Copy hosting request', target: 'stonewright-diag-ticket-bot_filter' } },
		{ id: 'transport', status: 'ok', label: 'Connection transport', summary: 'HTTPS is active.' },
	],
};

test.beforeEach(async ({ page }, testInfo) => {
	test.skip(testInfo.project.name !== DESKTOP, 'Behaviour does not depend on the viewport; it is checked once.');
	await login(page);
});

async function animationsRunning(page: Page): Promise<number> {
	return page.evaluate(() => document.getAnimations().filter((animation) => animation.playState === 'running').length);
}

test.describe('Audit log', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=stonewright-audit-log', { waitUntil: 'domcontentloaded' });
		await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
	});

	test('a row opens in the drawer, the drawer keeps focus, and Escape gives it back', async ({ page }) => {
		const opener = page.locator('[data-sw-audit-open]').first();
		test.skip((await opener.count()) === 0, 'The log has no rows on this site.');
		await expect(opener).toBeVisible();
		const name = await opener.evaluate((node) => node.getAttribute('data-sw-audit-title'));

		await opener.click();
		const drawer = page.locator('#sw-audit-drawer');
		await expect(drawer).toBeVisible();
		await expect(drawer.locator('.sw-ui-dialog__title')).toHaveText(name ?? '');
		await expect(drawer.locator('[data-sw-audit-panel]:not([hidden])')).toHaveCount(1);
		await expect(drawer.getByRole('button', { name: /^Close/ })).toBeFocused();

		await page.keyboard.press('Escape');
		await expect(drawer).toBeHidden();
		await expect(opener).toBeFocused();
	});

	test('the delete dialog starts on its safe action and enables the delete button only for the exact phrase', async ({ page }) => {
		await page.getByRole('button', { name: 'Delete all logs' }).first().click();
		const dialog = page.locator('#sw-audit-purge-dialog');
		await expect(dialog).toBeVisible();
		await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
		await expect(dialog).toContainText('incidents');

		const submit = dialog.locator('[data-sw-ui-confirm-submit]');
		await expect(submit).toBeDisabled();
		await dialog.getByLabel('Type DELETE').fill('delete');
		await expect(submit).toBeDisabled();
		await dialog.getByLabel('Type DELETE').fill('DELETE');
		await expect(submit).toBeEnabled();

		await page.keyboard.press('Escape');
		await expect(dialog).toBeHidden();
		await expect(dialog.getByLabel('Type DELETE')).toHaveValue('');
	});

	test('each filter says how it matches, and the views are links with the current one marked', async ({ page }) => {
		await expect(page.locator('.sw-audit-rules')).toContainText('match part of what you type');
		await expect(page.locator('.sw-audit-views a[aria-current="true"]')).toHaveCount(1);
		await expect(page.locator('#sw-audit-f-operation_class-rule')).toHaveText('Contains');
		await expect(page.locator('#sw-audit-f-status-rule')).toHaveText('Exact');
	});

	test('with reduced motion no animation runs while the drawer is open', async ({ page }) => {
		const opener = page.locator('[data-sw-audit-open]').first();
		test.skip((await opener.count()) === 0, 'The log has no rows on this site.');
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await opener.click();
		await expect(page.locator('#sw-audit-drawer')).toBeVisible();
		await settle(page);
		expect(await animationsRunning(page)).toBe(0);
	});
});

test.describe('Troubleshoot', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=stonewright-troubleshoot', { waitUntil: 'domcontentloaded' });
		await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
	});

	test('the run button is above the results and the run paints problems first, announces and stays accessible', async ({ page }) => {
		await page.route('**/admin-ajax.php', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: SYNTHETIC_REPORT }) }));
		const run = page.getByRole('button', { name: 'Run diagnostics' });
		const runBox = await run.boundingBox();
		const resultsBox = await page.locator('[data-sw-diag-results]').boundingBox();
		expect(runBox && resultsBox && runBox.y < resultsBox.y, 'Run diagnostics sits above the results').toBe(true);

		await run.click();
		await expect(page.locator('[data-sw-diag-results]')).toHaveAttribute('aria-busy', 'false');
		await expect(page.locator('[data-sw-diag-summary]')).toContainText('1 problem and 1 warning to look at.');
		const rows = page.locator('[data-sw-diag-results] > table tbody tr');
		await expect(rows).toHaveCount(2);
		await expect(rows.first()).toContainText('MCP endpoint');
		await expect(rows.first().locator('.sw-ui-badge')).toHaveText('Problem');
		await expect(page.locator('[data-sw-diag-results] details summary')).toHaveText('1 check passed');
		await expect(page.getByRole('button', { name: 'Copy hosting request' })).toBeVisible();
	});

	test('a failed run says so in a notice that stays, and the next run clears it', async ({ page }) => {
		await page.route('**/admin-ajax.php', (route) => route.abort());
		const run = page.getByRole('button', { name: 'Run diagnostics' });
		await run.click();
		const notice = page.locator('[data-sw-diag-error]');
		await expect(notice).toBeVisible();
		await expect(notice).toHaveAttribute('role', 'alert');
		await expect(notice).toContainText('The checks could not run');
		await page.waitForTimeout(6_000);
		await expect(notice, 'An error never disappears by itself').toBeVisible();

		await page.unroute('**/admin-ajax.php');
		await page.route('**/admin-ajax.php', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: SYNTHETIC_REPORT }) }));
		await run.click();
		await expect(page.locator('[data-sw-diag-results] > table')).toBeVisible();
		await expect(notice).toHaveCount(0);
	});

	test('the symptom help is text on the page, not a tooltip', async ({ page }) => {
		await page.getByLabel('What do you see in your AI client?').selectOption('auth');
		await expect(page.locator('[data-sw-diag-help]')).toBeVisible();
		await expect(page.locator('[data-sw-diag-help]')).toContainText('reconnect from Setup');
	});
});
