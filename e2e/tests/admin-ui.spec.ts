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

	await page.locator('#wp-submit').click();
	try {
		await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
	} catch {
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
 * Product-surface overflow: shell (and content) must not create a horizontal
 * scrollbar. Uses scrollWidth vs clientWidth with a 2px sub-pixel tolerance.
 * Tables/pre inside overflow:auto/clip containers are contained by design.
 */
async function productHorizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(() => {
		const shell = document.querySelector('.sw-shell') as HTMLElement | null;
		const content = document.querySelector('.sw-shell__content') as HTMLElement | null;
		const targets = [shell, content].filter(Boolean) as HTMLElement[];
		if (targets.length === 0) {
			const docDelta =
				document.documentElement.scrollWidth - document.documentElement.clientWidth;
			return docDelta > 2 ? docDelta : 0;
		}
		let worst = 0;
		for (const el of targets) {
			const delta = el.scrollWidth - el.clientWidth;
			if (delta > worst) {
				worst = delta;
			}
		}
		return worst > 2 ? worst : 0;
	});
}

/**
 * Console noise that is not a product JS bug:
 * - Chrome "Failed to load resource" for 4xx (WP heartbeat, REST, missing assets under race)
 * - Opaque "Object" pageerror serializations
 * Keep real SyntaxError / ReferenceError / stonewright script failures.
 */
function isIgnorableConsoleNoise(text: string): boolean {
	const t = text.trim();
	if (t === '' || t === 'Object' || t === '[object Object]') {
		return true;
	}
	if (t.includes('favicon')) {
		return true;
	}
	if (t.includes('Download the React DevTools')) {
		return true;
	}
	// Network resource status noise (not uncaught product exceptions).
	if (/Failed to load resource:/i.test(t)) {
		return true;
	}
	if (/the server responded with a status of (400|401|403|404|429)/i.test(t)) {
		return true;
	}
	// WP core / emoji / heartbeat chatter.
	if (/net::ERR_/i.test(t)) {
		return true;
	}
	return false;
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

			if (page.url().includes('wp-login.php')) {
				await login(page);
				await page.goto(`/wp-admin/admin.php?page=${slug}`, {
					waitUntil: 'domcontentloaded',
				});
			}

			expect(response, `${label} must return a response`).not.toBeNull();
			expect(page.url(), `${label} should be on the target page`).toContain(`page=${slug}`);

			await page.locator('body').waitFor({ state: 'visible' });
			await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });

			// Let sticky header / flex nav settle before measuring overflow.
			await page.waitForTimeout(100);

			const overflow = await productHorizontalOverflow(page);
			expect(overflow, `${label}: horizontal overflow must be <= 0`).toBeLessThanOrEqual(0);

			const productErrors = consoleErrors.filter((text) => !isIgnorableConsoleNoise(text));
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
