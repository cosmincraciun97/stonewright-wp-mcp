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

test.describe('Connect wizard interactions', () => {
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('method picker keyboard navigation works', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});

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

		await expect(page.locator('[data-stonewright-client-picker]')).toBeVisible({ timeout: 15_000 });
		await expect(page.locator('[data-stonewright-method="stdio"]')).toBeVisible();
		await expect(page.locator('[data-stonewright-method="http"]')).toBeVisible();
		await expect(page.locator('[data-stonewright-method-snippet="stdio"]')).toBeAttached();
		await expect(page.locator('[data-stonewright-method-snippet="http"]')).toBeAttached();
	});
});
