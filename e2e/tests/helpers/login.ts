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

/** How long a sign-in may take on a slow runner. It stays under the 60s test timeout so the failure names the login. */
const SIGN_IN_TIMEOUT = 45_000;

/** Shared wp-admin login for active product-surface suites. */
export async function login(page: Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	await assertNoWordpressFatal(page);
	if (!page.url().includes('wp-login.php')) return;
	// The login form is ready when its fields and button are on the page and the page has finished loading: a first
	// load on a cold runner can still be running scripts and setting the cookie WordPress checks when the form is sent.
	await page.waitForLoadState('load', { timeout: 30_000 }).catch(() => undefined);
	const user = page.locator('#user_login');
	const password = page.locator('#user_pass');
	const submit = page.locator('#wp-submit');
	await user.waitFor({ state: 'visible', timeout: 30_000 });
	await password.waitFor({ state: 'visible', timeout: 30_000 });
	await submit.waitFor({ state: 'visible', timeout: 30_000 });
	await user.fill(WP_USER);
	await password.fill(WP_PASS);
	// Wait for the condition that matters, not for one navigation: the page has left the login form (the click and
	// the wait are armed together, so a redirect that finishes first is not missed), or WordPress says why it did not.
	// A redirect that is slow but arrives inside the timeout is a sign-in, not a failure.
	const signedIn = page
		.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'), { timeout: SIGN_IN_TIMEOUT, waitUntil: 'domcontentloaded' })
		.then(() => undefined);
	await Promise.all([signedIn, submit.click()]).catch(async (error: unknown) => {
		const reason = (await page.locator('#login_error').innerText({ timeout: 1_000 }).catch(() => '')).trim();
		const cause = error instanceof Error ? error.message.slice(0, 200) : String(error);
		throw new Error(`Login did not leave wp-login.php${reason ? `: ${reason}` : ''} (${cause})`);
	});
	await assertNoWordpressFatal(page);
}
