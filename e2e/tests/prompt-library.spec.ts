import { expect, test } from '@playwright/test';

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

async function login(page: import('@playwright/test').Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	if (!page.url().includes('wp-login.php')) {
		return;
	}
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/\/wp-admin\//, { timeout: 45_000 });
}

test.describe('Prompt Library tab', () => {
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('nav tab loads cards and copy buttons', async ({ page, context }) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => undefined);
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright-prompts', {
			waitUntil: 'domcontentloaded',
		});
		await expect(page.locator('h1')).toContainText(/Prompt Library/i);
		const cards = page.locator('[data-sw-prompt-card]');
		await expect(cards.first()).toBeVisible();
		expect(await cards.count()).toBeGreaterThanOrEqual(20);
		const copy = page.locator('.sw-copy-prompt').first();
		await copy.click();
		await expect(copy).toContainText(/Copied|Copy/i, { timeout: 3_000 });
	});
});
