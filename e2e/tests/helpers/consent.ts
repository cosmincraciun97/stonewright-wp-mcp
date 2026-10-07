import crypto from 'node:crypto';
import type { Page } from '@playwright/test';

const registered = new Map<string, string>();

/**
 * The OAuth consent screen is a hidden admin page that only exists for a pending authorization request, so the page
 * loop cannot open it by slug. This registers a throw-away client through the site's own registration route, starts an
 * authorization request for it with PKCE as that client would, and follows the redirect to the consent screen.
 *
 * Returns false when the site does not serve OAuth (the registration route answers 404), so a site without it skips
 * the screen instead of failing on a page that cannot exist there.
 */
export async function openConsentScreen(page: Page, clientName = 'Example editor client', callback = 'http://127.0.0.1:7999/callback'): Promise<boolean> {
	// One client per worker: registration is limited per address, so a suite that opens the screen once per viewport
	// must not register once per viewport.
	const cached = registered.get(`${clientName}|${callback}`);
	const clientId = cached ?? (await registerClient(page, clientName, callback));
	if (clientId === null) {
		return false;
	}
	registered.set(`${clientName}|${callback}`, clientId);
	return startAuthorization(page, clientId, callback);
}

async function registerClient(page: Page, clientName: string, callback: string): Promise<string | null> {
	const registration = await page.request.post('/?rest_route=/stonewright/v1/oauth/register', {
		data: {
			client_name: clientName,
			redirect_uris: [callback],
			token_endpoint_auth_method: 'none',
			grant_types: ['authorization_code', 'refresh_token'],
			response_types: ['code'],
		},
	});
	if (registration.status() === 404) {
		return null;
	}
	if (!registration.ok()) {
		throw new Error(`OAuth client registration answered HTTP ${registration.status()}`);
	}
	return ((await registration.json()) as { client_id: string }).client_id;
}

async function startAuthorization(page: Page, clientId: string, callback: string): Promise<boolean> {
	const verifier = crypto.randomBytes(32).toString('base64url');
	const query = new URLSearchParams({
		page: 'stonewright-oauth-authorize',
		response_type: 'code',
		client_id: clientId,
		redirect_uri: callback,
		code_challenge: crypto.createHash('sha256').update(verifier).digest('base64url'),
		code_challenge_method: 'S256',
		state: 'example-state',
		scope: 'mcp',
	});
	await page.goto(`/wp-admin/admin.php?${query.toString()}`, { waitUntil: 'domcontentloaded' });
	await page.waitForURL(/page=stonewright-oauth-consent/, { timeout: 15_000 });
	await page.locator('.sw-oauth-consent').waitFor({ state: 'visible', timeout: 15_000 });
	return true;
}
