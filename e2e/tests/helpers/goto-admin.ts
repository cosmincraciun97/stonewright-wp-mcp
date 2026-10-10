import type { Page } from '@playwright/test';

/**
 * Open a wp-admin page and read it, even when the first admin request after activation is sent to the Overview.
 *
 * Activating Stonewright arms a one-time redirect to the Overview that the first admin request takes (see
 * ActivationRedirect). On a site that was activated seconds before the suite starts, that request can be the one a
 * test makes, and the test then reads the Overview where it expects another page. The redirect is taken once, so a
 * second request for the same address shows the page that was asked for.
 */
export async function gotoAdmin(page: Page, path: string): Promise<void> {
	const wanted = new URL(path, 'http://admin.invalid').searchParams.get('page');
	await page.goto(path, { waitUntil: 'domcontentloaded' });
	if (wanted !== null && wanted !== 'stonewright-status' && new URL(page.url()).searchParams.get('page') === 'stonewright-status') {
		await page.goto(path, { waitUntil: 'domcontentloaded' });
	}
}
