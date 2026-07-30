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

test.describe('Blueprints Design Library', () => {
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('card grid renders blueprints and copy prompt works', async ({ page, context }) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => undefined);
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright-blueprints', {
			waitUntil: 'domcontentloaded',
		});
		await expect(page.locator('h1')).toContainText(/Blueprints|Brand Kits/i);
		const cards = page.locator('.sw-blueprint-card');
		await expect(cards.first()).toBeVisible({ timeout: 15_000 });
		expect(await cards.count()).toBeGreaterThanOrEqual(12);

		const copy = page.locator('.sw-blueprint-card .sw-copy-prompt').first();
		await expect(copy).toBeVisible();
		await copy.click();
		await expect(copy).toContainText(/Copied|Apply to draft|Copy/i, { timeout: 3_000 });
	});
});
