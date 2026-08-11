import { expect, test } from '@playwright/test';
import { login } from './helpers/login';
const REMOVED_PAGES = [
	'stonewright-blueprints',
	'stonewright-design-studio',
	'stonewright-visual-workspace',
] as const;

test.describe('Design Library is disabled', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Route registration is viewport-independent.');
		await login(page);
	});

	test('removed pages have no navigation entry or renderable product surface', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=stonewright', { waitUntil: 'domcontentloaded' });
		for (const slug of REMOVED_PAGES) {
			await expect(page.locator(`a[href*="page=${slug}"]`)).toHaveCount(0);
		}

		for (const slug of REMOVED_PAGES) {
			await page.goto(`/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('.sw-shell')).toHaveCount(0);
			await expect(page.locator('[data-sw-visual-workspace], [data-sw-design-studio], .sw-blueprint-card')).toHaveCount(0);
		}
	});
});
