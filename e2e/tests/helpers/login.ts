import type { Page } from '@playwright/test';

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

/**
 * Fail immediately when WordPress rendered a PHP fatal. The recovery screen
 * has no Stonewright chrome, so later `.sw-shell` waits burn 15s each and
 * hide the actual error.
 */
export async function assertNoWordpressFatal(page: Page): Promise<void> {
	const body = (await page.locator('body').innerText().catch(() => '')).trim();
	if (
		!/There has been a critical error/i.test(body) &&
		!/Fatal error:/i.test(body) &&
		!/Parse error:/i.test(body) &&
		!/Uncaught (?:Error|TypeError|Exception|Throwable)/i.test(body)
	) {
		return;
	}
	throw new Error(
		`WordPress PHP fatal on ${page.url()}: ${body.replace(/\s+/g, ' ').slice(0, 2500)}`,
	);
}

/** Shared wp-admin login for active product-surface suites. */
export async function login(page: Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	await assertNoWordpressFatal(page);
	if (!page.url().includes('wp-login.php')) return;
	await page.locator('#user_login').waitFor({ state: 'visible', timeout: 15_000 });
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
	await assertNoWordpressFatal(page);
}
