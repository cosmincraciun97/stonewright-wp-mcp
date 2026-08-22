import type { APIResponse, Page } from '@playwright/test';

/**
 * WordPress REST helpers for Playwright against wp-env.
 *
 * Some environments rewrite /wp-json/* poorly (Apache 404 HTML). Prefer pretty
 * routes, then fall back to index.php?rest_route=. Always use page.request so
 * the browser session cookies from login are sent (the isolated `request`
 * fixture does not share cookies).
 */

export type RestMode = 'pretty' | 'query';

let cachedMode: RestMode | null = null;

export function restUrl(route: string, mode: RestMode = cachedMode ?? 'pretty'): string {
	const normalized = route.startsWith('/') ? route : `/${route}`;
	if (mode === 'query') {
		return `/index.php?rest_route=${encodeURIComponent(normalized)}`;
	}
	return `/wp-json${normalized}`;
}

export async function detectRestMode(page: Page): Promise<RestMode> {
	if (cachedMode) {
		return cachedMode;
	}
	const pretty = await page.request.get('/wp-json/', { failOnStatusCode: false });
	if (pretty.ok()) {
		const ct = pretty.headers()['content-type'] || '';
		if (ct.includes('json') || (await pretty.text()).trim().startsWith('{')) {
			cachedMode = 'pretty';
			return cachedMode;
		}
	}
	const query = await page.request.get('/index.php?rest_route=/', {
		failOnStatusCode: false,
	});
	if (query.ok()) {
		cachedMode = 'query';
		return cachedMode;
	}
	// Last resort: force pretty and let callers surface errors.
	cachedMode = 'pretty';
	return cachedMode;
}

export async function wpRestNonce(page: Page): Promise<string> {
	// Prefer explicit nonce attributes from Setup / connection UI.
	const nonceSource = page.locator('[data-rest-nonce]').first();
	const fromAttr = (await nonceSource.count())
		? await nonceSource.getAttribute('data-rest-nonce')
		: null;
	if (fromAttr) {
		return fromAttr;
	}

	const fromWp = await page.evaluate(() => {
		const w = window as unknown as { wpApiSettings?: { nonce?: string } };
		return w.wpApiSettings?.nonce || '';
	});
	if (fromWp) {
		return fromWp;
	}

	// Editor screens always bootstrap wpApiSettings.
	await page.goto('/wp-admin/post-new.php?post_type=page', {
		waitUntil: 'domcontentloaded',
	});
	const fromEditor = await page.evaluate(() => {
		const w = window as unknown as { wpApiSettings?: { nonce?: string } };
		return w.wpApiSettings?.nonce || '';
	});
	if (fromEditor) {
		return fromEditor;
	}

	throw new Error('Could not resolve wp_rest nonce (data-rest-nonce / wpApiSettings).');
}

async function parseBody(res: APIResponse): Promise<unknown> {
	const text = await res.text();
	try {
		return JSON.parse(text);
	} catch {
		return text;
	}
}

export async function restRequest(
	page: Page,
	method: 'GET' | 'POST' | 'PUT' | 'DELETE',
	route: string,
	options: {
		nonce?: string;
		data?: unknown;
	} = {},
): Promise<{ ok: boolean; status: number; body: unknown; url: string }> {
	const mode = await detectRestMode(page);
	const headers: Record<string, string> = {
		Accept: 'application/json',
	};
	if (options.nonce) {
		headers['X-WP-Nonce'] = options.nonce;
	}
	if (options.data !== undefined) {
		headers['Content-Type'] = 'application/json';
	}

	const tryModes: RestMode[] = mode === 'pretty' ? ['pretty', 'query'] : ['query', 'pretty'];
	let last: { ok: boolean; status: number; body: unknown; url: string } | null = null;

	for (const m of tryModes) {
		const url = restUrl(route, m);
		const res = await page.request.fetch(url, {
			method,
			headers,
			data: options.data !== undefined ? options.data : undefined,
			failOnStatusCode: false,
		});
		const body = await parseBody(res);
		last = { ok: res.ok(), status: res.status(), body, url };
		// Apache HTML 404 → try alternate routing.
		if (res.status() === 404 && typeof body === 'string' && body.includes('Not Found')) {
			continue;
		}
		// WordPress JSON 404 rest_no_route → don't thrash modes for wrong paths.
		return last;
	}
	return last ?? { ok: false, status: 0, body: 'no response', url: restUrl(route) };
}

export async function restGet(
	page: Page,
	route: string,
	nonce?: string,
): Promise<{ ok: boolean; status: number; body: unknown; url: string }> {
	return restRequest(page, 'GET', route, { nonce });
}

/**
 * Execute a Stonewright ability through the authenticated admin REST route.
 */
export async function runAbility(
	page: Page,
	nonce: string,
	name: string,
	input: Record<string, unknown>,
	confirmationToken = '',
): Promise<{ ok: boolean; status: number; body: unknown; url: string }> {
	const data: Record<string, unknown> = { name, input };
	if (confirmationToken) {
		data.confirmation_token = confirmationToken;
	}
	return restPost(page, '/stonewright/v1/abilities/run', data, nonce);
}

/**
 * Execute an ability that is intentionally outside the persisted MCP profile.
 * The explicit, one-use wrapper token keeps the REST profile gate exercised
 * instead of mutating shared profile state or bypassing the gate in tests.
 */
export async function runAbilityWithProfileConfirmation(
	page: Page,
	nonce: string,
	contextToken: string,
	name: string,
	input: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; body: unknown; url: string }> {
	const wrapperArgs = { name, input };
	const issued = await runAbility(
		page,
		nonce,
		'stonewright/security-issue-confirmation-token',
		{
			ability: 'stonewright/rest-abilities-run',
			args: wrapperArgs,
			stonewright_context_token: contextToken,
		},
	);
	if (!issued.ok) {
		throw new Error(
			`Could not issue REST profile confirmation: ${JSON.stringify(issued.body)}`,
		);
	}
	const body = issued.body as {
		result?: { token?: string };
		token?: string;
	};
	const token = String(body.result?.token ?? body.token ?? '');
	if (!token) {
		throw new Error(
			`REST profile confirmation returned no token: ${JSON.stringify(issued.body)}`,
		);
	}
	return runAbility(page, nonce, name, input, token);
}

export async function restPost(
	page: Page,
	route: string,
	data: unknown,
	nonce?: string,
): Promise<{ ok: boolean; status: number; body: unknown; url: string }> {
	return restRequest(page, 'POST', route, { nonce, data });
}
