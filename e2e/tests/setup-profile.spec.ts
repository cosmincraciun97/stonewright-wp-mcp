import { expect, test, type Page } from '@playwright/test';

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

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

test.describe('Setup MCP surface Apply now', () => {
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('surface select + Apply now saves and round-trips', async ({ page }, testInfo) => {
		test.skip(
			testInfo.project.name !== 'desktop-1440-light',
			'Global MCP surface mutation runs once.',
		);
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});

		const select = page.locator('#stonewright_mcp_surface');
		await expect(select).toBeVisible({ timeout: 15_000 });

		const apply = page.locator('[data-sw-apply-mcp-surface]');
		await expect(apply).toBeVisible();
		await expect(apply).toContainText(/Apply now/i);

		// Prefer essential for a stable mid value; fall back to full if already essential.
		const current = await select.inputValue();
		const next = current === 'essential' ? 'full' : 'essential';
		try {
			await select.selectOption(next);
			await apply.click();

			await expect(apply).toContainText(/Applied|Apply now|Failed/i, { timeout: 8_000 });

			// Round-trip: reload and assert saved value.
			await page.reload({ waitUntil: 'domcontentloaded' });
			await expect(page.locator('#stonewright_mcp_surface')).toHaveValue(next, {
				timeout: 15_000,
			});
		} finally {
			// This option is shared by every project and must never leak test state.
			await page.goto('/wp-admin/admin.php?page=stonewright', {
				waitUntil: 'domcontentloaded',
			});
			const restoreSelect = page.locator('#stonewright_mcp_surface');
			await expect(restoreSelect).toBeVisible({ timeout: 15_000 });
			await restoreSelect.selectOption(current);
			await page.locator('[data-sw-apply-mcp-surface]').click();
			await page.reload({ waitUntil: 'domcontentloaded' });
			await expect(page.locator('#stonewright_mcp_surface')).toHaveValue(current, {
				timeout: 15_000,
			});
		}
	});
});
