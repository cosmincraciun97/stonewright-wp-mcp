import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';

const artifactDir = path.join(process.cwd(), 'artifacts');

/** Stonewright admin pages exercised by the Phase 0 baseline gate. */
const STONEWRIGHT_PAGES = [
	{ slug: 'stonewright-status', label: 'Dashboard' },
	{ slug: 'stonewright', label: 'Setup' },
	{ slug: 'stonewright-abilities', label: 'AI Abilities' },
	{ slug: 'stonewright-blueprints', label: 'Blueprints' },
	{ slug: 'stonewright-sandbox', label: 'Sandbox' },
	{ slug: 'stonewright-skills', label: 'Skills' },
	{ slug: 'stonewright-memory', label: 'Memory' },
	{ slug: 'stonewright-audit-log', label: 'Audit Log' },
] as const;

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

async function login(page: Page): Promise<void> {
	await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
	// Already authenticated (storageState / cookie reuse).
	if (!page.url().includes('wp-login.php')) {
		return;
	}
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);
	await Promise.all([
		page.waitForURL(/\/wp-admin\//, { timeout: 30_000 }),
		page.locator('#wp-submit').click(),
	]);
}

async function noHorizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(
		() => document.documentElement.scrollWidth - document.documentElement.clientWidth,
	);
}

test.describe('Stonewright admin UI', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	for (const { slug, label } of STONEWRIGHT_PAGES) {
		test(`${label} (${slug}) loads without overflow or console errors`, async ({
			page,
		}, testInfo) => {
			const consoleErrors: string[] = [];
			page.on('console', (msg) => {
				if (msg.type() === 'error') {
					consoleErrors.push(msg.text());
				}
			});
			page.on('pageerror', (err) => {
				consoleErrors.push(err.message);
			});

			const response = await page.goto(`/wp-admin/admin.php?page=${slug}`, {
				waitUntil: 'domcontentloaded',
			});

			expect(response, `${label} must return a response`).not.toBeNull();
			expect(response!.status(), `${label} HTTP status`).toBeLessThan(400);

			// Wait for body so layout measurements are meaningful.
			await page.locator('body').waitFor({ state: 'visible' });

			const overflow = await noHorizontalOverflow(page);
			expect(overflow, `${label}: horizontal overflow must be <= 0`).toBeLessThanOrEqual(0);

			// Soft filter: ignore known third-party/WP noise if any; product code
			// must not introduce new console errors. Fail on any remaining errors.
			const productErrors = consoleErrors.filter(
				(text) =>
					!text.includes('favicon') &&
					!text.includes('Download the React DevTools'),
			);
			expect(
				productErrors,
				`${label}: console errors\n${productErrors.join('\n')}`,
			).toEqual([]);

			const safeName = `${testInfo.project.name}-${slug}`.replace(/[^a-z0-9-_]+/gi, '-');
			await page.screenshot({
				path: path.join(artifactDir, `${safeName}.png`),
				fullPage: true,
			});
		});
	}
});
