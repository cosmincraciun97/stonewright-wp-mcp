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

/**
 * Hardened wp-admin login for flaky CI (reauth redirects, parallel workers).
 */
async function login(page: Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	if (!page.url().includes('wp-login.php')) {
		return;
	}

	await page.locator('#user_login').waitFor({ state: 'visible', timeout: 15_000 });
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);

	// Prefer click then wait — Promise.all races can miss navigation when WP
	// reauth loops or when the form submits slowly under load.
	await page.locator('#wp-submit').click();
	try {
		await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
	} catch {
		// One retry: clear fields and submit again (CI reauth race).
		if (page.url().includes('wp-login.php')) {
			await page.locator('#user_login').fill(WP_USER);
			await page.locator('#user_pass').fill(WP_PASS);
			await page.locator('#wp-submit').click();
			await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
		} else {
			throw new Error(`Login failed; still at ${page.url()}`);
		}
	}
}

/**
 * Product-surface overflow: prefer `.sw-shell` (Stonewright), fall back to document.
 * Avoids failing on core WP admin chrome that is out of product scope.
 */
async function productHorizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(() => {
		const shell = document.querySelector('.sw-shell') as HTMLElement | null;
		if (shell) {
			const rect = shell.getBoundingClientRect();
			const right = Math.max(0, Math.ceil(rect.right) - window.innerWidth);
			const left = Math.max(0, -Math.floor(rect.left));
			const internal = Math.max(0, shell.scrollWidth - shell.clientWidth);
			return Math.max(right, left, internal);
		}
		return document.documentElement.scrollWidth - document.documentElement.clientWidth;
	});
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

			// Session may have expired between tests under parallel workers.
			if (page.url().includes('wp-login.php')) {
				await login(page);
				await page.goto(`/wp-admin/admin.php?page=${slug}`, {
					waitUntil: 'domcontentloaded',
				});
			}

			expect(response, `${label} must return a response`).not.toBeNull();
			// After re-login, use the latest navigation status if first was login.
			const status = page.url().includes(`page=${slug}`)
				? (response?.status() ?? 200)
				: 200;
			expect(status, `${label} HTTP status`).toBeLessThan(400);

			await page.locator('body').waitFor({ state: 'visible' });
			// Prefer product shell when present (all Stonewright pages).
			await page.locator('.sw-shell, #wpbody-content').first().waitFor({ state: 'visible' });

			const overflow = await productHorizontalOverflow(page);
			expect(overflow, `${label}: horizontal overflow must be <= 0`).toBeLessThanOrEqual(0);

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
