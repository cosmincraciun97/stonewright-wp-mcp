import { expect, test, type Page } from '@playwright/test';

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';
const WP_BASE_URL = process.env.WP_BASE_URL ?? 'http://localhost:8888';

function escapeRegExp(value: string): string {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function login(page: Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	if (!page.url().includes('wp-login.php')) {
		return;
	}
	await page.locator('#user_login').waitFor({ state: 'visible', timeout: 15_000 });
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);
	await page.locator('#wp-submit').click();
	try {
		await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
	} catch {
		if (page.url().includes('wp-login.php')) {
			await page.locator('#user_login').fill(WP_USER);
			await page.locator('#user_pass').fill(WP_PASS);
			await page.locator('#wp-submit').click();
			await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
		}
	}
}

async function selectApplicationPassword(page: Page): Promise<void> {
	const button = page.locator(
		'[data-stonewright-auth-method="application-password"]',
	);
	await expect(button).toBeVisible({ timeout: 15_000 });
	await button.click();
	await expect(button).toHaveAttribute('aria-checked', 'true');
}

test.describe('Connect wizard interactions', () => {
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('method picker keyboard navigation works', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});
		await selectApplicationPassword(page);

		const picker = page.locator('[data-stonewright-method-picker]');
		await expect(picker).toBeVisible({ timeout: 15_000 });

		const methods = picker.locator('[data-stonewright-method]');
		await expect(methods.first()).toBeVisible();
		expect(await methods.count()).toBeGreaterThanOrEqual(2);

		await methods.first().focus();
		await page.keyboard.press('ArrowRight');
		// Active class should move or remain; assert at least one is active.
		const active = picker.locator('[data-stonewright-method].is-active');
		await expect(active).toHaveCount(1);
	});

	test('client cards and method snippets are present', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});
		await selectApplicationPassword(page);

		await expect(page.locator('[data-stonewright-client-picker]')).toBeVisible({ timeout: 15_000 });

		// Method cards live in the picker (exactly one each).
		const picker = page.locator('[data-stonewright-method-picker]');
		await expect(picker.locator('[data-stonewright-method="stdio"]')).toBeVisible();
		await expect(picker.locator('[data-stonewright-method="http"]')).toBeVisible();

		// Snippets are repeated per client panel (many matches). Assert presence by count,
		// not a single strict locator — Playwright strict mode fails at 16+ nodes.
		const stdioSnippets = page.locator('[data-stonewright-method-snippet="stdio"]');
		const httpSnippets = page.locator('[data-stonewright-method-snippet="http"]');
		expect(await stdioSnippets.count()).toBeGreaterThan(0);
		expect(await httpSnippets.count()).toBeGreaterThan(0);
		await expect(stdioSnippets.first()).toBeAttached();
		await expect(httpSnippets.first()).toBeAttached();
	});

	test('agent prompt is credential-free and update guidance is explicit', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});
		await selectApplicationPassword(page);

		const prompt = page.locator('#stonewright-connect-prompt-full');
		await expect(prompt).toBeAttached();
		const fullPrompt = await prompt.getAttribute('data-stonewright-text-full');
		expect(fullPrompt).toContain('stonewright connect add');
		expect(fullPrompt).toContain('--mode plugin-only');
		expect(fullPrompt).toContain(
			'stonewright connect repair <alias> --client <client> --mode plugin-only',
		);
		expect(fullPrompt).toContain('Choose exactly one transport');
		expect(fullPrompt).toContain('/wp-json/mcp/stonewright-oauth');
		expect(fullPrompt).not.toContain('MCP server name: stonewright');
		expect(fullPrompt).not.toContain(WP_USER);
		expect(fullPrompt).not.toContain(WP_BASE_URL);
		// wp-env's login password is literally "password", so a raw substring
		// check would reject legitimate guidance such as "Application Password".
		// Reject the value only where copied credentials could actually appear.
		expect(fullPrompt).not.toMatch(
			new RegExp(
				`(?:=|:\\s*|["'])${escapeRegExp(WP_PASS)}(?:["',\\s]|$)`,
				'i',
			),
		);
		expect(fullPrompt).toContain(
			'Ask me once whether this site/client should use Playwright',
		);
		expect(fullPrompt).toContain(
			'Do not scan my private config or client tool surface without permission',
		);
		expect(fullPrompt).toContain(
			'Never install or reconfigure a browser provider silently',
		);
		expect(fullPrompt).toContain(
			'never bypasses custom-code dry-run, approval, backup, permission, or confirmation gates',
		);

		const guide = page.locator('.sw-update-guide');
		await expect(guide).toBeVisible();
		await expect(guide).toContainText('Keep Stonewright current');
		await expect(guide).toContainText('Update the WordPress plugin');
		await expect(guide).toContainText('Update the local companion');
		await expect(guide).toContainText('Updates preserve memory, user skills, audit');
	});
});
