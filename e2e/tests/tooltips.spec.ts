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

test.describe('Tooltips', () => {
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('method cards expose tooltips on hover and Escape hides them', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright', { waitUntil: 'domcontentloaded' });
		const card = page.locator('[data-stonewright-method="stdio"][data-sw-tooltip]').first();
		await expect(card).toBeVisible();
		await card.hover();
		const tip = page.locator('[role="tooltip"].sw-tooltip.is-visible');
		await expect(tip).toBeVisible({ timeout: 5_000 });
		await expect(tip).toContainText(/companion|Node/i);
		await page.keyboard.press('Escape');
		await expect(page.locator('[role="tooltip"].sw-tooltip.is-visible')).toHaveCount(0);
	});

	test('blueprint primary button has white text and tooltip', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/admin.php?page=stonewright-blueprints', {
			waitUntil: 'domcontentloaded',
		});
		const btn = page.locator('.sw-btn--primary').first();
		await expect(btn).toBeVisible();
		const color = await btn.evaluate((el) => getComputedStyle(el).color);
		// rgb(255, 255, 255) or white
		expect(color.replace(/\s/g, '')).toMatch(/rgb\(255,255,255\)|#fff(fff)?/i);
		await btn.focus();
		await expect(page.locator('[role="tooltip"].sw-tooltip.is-visible')).toBeVisible({
			timeout: 5_000,
		});
	});
});
