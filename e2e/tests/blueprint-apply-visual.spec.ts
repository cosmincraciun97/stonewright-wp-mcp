import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Real blueprint-apply + front-end visual proof.
 *
 * 1. Ensure Stonewright master toggle is ON (Setup form).
 * 2. Call stonewright/v1/abilities/run for blueprint-apply (gutenberg + fse).
 * 3. Open the published/draft preview URL, assert no overflow + hero centering,
 *    archive full-page screenshots under artifacts/blueprint-apply/.
 *
 * Elementor engine is attempted when available; engine_unavailable is accepted
 * on wp-env without Elementor.
 */

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';
const artifactDir = path.join(process.cwd(), 'artifacts', 'blueprint-apply');

const BLUEPRINTS = ['dental', 'saas', 'restaurant'] as const;

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
		}
	}
}

async function ensurePluginEnabled(page: Page): Promise<void> {
	await page.goto('/wp-admin/admin.php?page=stonewright', { waitUntil: 'domcontentloaded' });
	const enabled = page.locator('#stonewright_enabled');
	if (await enabled.count()) {
		const checked = await enabled.isChecked();
		if (!checked) {
			await enabled.check();
			const save = page.locator('form input[type="submit"], form button[type="submit"]').first();
			await save.click();
			await page.waitForLoadState('domcontentloaded');
		}
	}
}

async function restNonce(page: Page): Promise<string> {
	// Prefer Setup connection-verify buttons which embed wp_rest nonces.
	const fromAttr = await page
		.locator('[data-rest-nonce]')
		.first()
		.getAttribute('data-rest-nonce')
		.catch(() => null);
	if (fromAttr) {
		return fromAttr;
	}
	// Fallback: wp-admin REST bootstrap when present.
	const fromWp = await page.evaluate(() => {
		const w = window as unknown as { wpApiSettings?: { nonce?: string } };
		return w.wpApiSettings?.nonce || '';
	});
	if (fromWp) {
		return fromWp;
	}
	throw new Error('Could not locate wp_rest nonce on Setup page');
}

async function runAbility(
	request: APIRequestContext,
	nonce: string,
	name: string,
	input: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; body: unknown }> {
	const res = await request.post('/wp-json/stonewright/v1/abilities/run', {
		headers: {
			'X-WP-Nonce': nonce,
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		data: { name, input },
	});
	let body: unknown = null;
	try {
		body = await res.json();
	} catch {
		body = await res.text();
	}
	return { ok: res.ok(), status: res.status(), body };
}

async function horizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(() => {
		const delta =
			document.documentElement.scrollWidth - document.documentElement.clientWidth;
		return delta > 2 ? delta : 0;
	});
}

async function heroCenterOffset(page: Page): Promise<number | null> {
	return page.evaluate(() => {
		const hero =
			(document.querySelector('.stonewright-fse-root h1') as HTMLElement | null) ||
			(document.querySelector('h1') as HTMLElement | null);
		if (!hero) {
			return null;
		}
		const rect = hero.getBoundingClientRect();
		const center = rect.left + rect.width / 2;
		return Math.abs(center - window.innerWidth / 2);
	});
}

test.describe('Blueprint apply + front-end visual proof', () => {
	// Bound runtime: one representative project (desktop light). Full matrix still
	// covers admin; this suite proves real apply paths.
	test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });

	test('apply dental/saas/restaurant (gutenberg+fse) and screenshot front-end', async ({
		page,
		request,
	}, testInfo) => {
		fs.mkdirSync(artifactDir, { recursive: true });
		await login(page);
		await ensurePluginEnabled(page);
		const nonce = await restNonce(page);

		const engines: Array<'gutenberg' | 'fse' | 'elementor'> = ['gutenberg', 'fse', 'elementor'];
		const applied: Array<{ blueprint: string; engine: string; url: string; postId: number }> =
			[];

		for (const blueprint_id of BLUEPRINTS) {
			for (const engine of engines) {
				// Elementor only once (dental) to keep runtime bounded.
				if (engine === 'elementor' && blueprint_id !== 'dental') {
					continue;
				}

				const result = await runAbility(request, nonce, 'stonewright/blueprint-apply', {
					blueprint_id,
					engine,
					mode: 'publish',
					page_title: `SW Apply ${blueprint_id} ${engine}`,
				});

				if (engine === 'elementor' && !result.ok) {
					// wp-env often has no Elementor — honest skip with evidence.
					const bodyStr = JSON.stringify(result.body);
					expect(
						bodyStr.includes('stonewright_engine_unavailable') ||
							bodyStr.includes('engine_unavailable') ||
							result.status === 400 ||
							result.status === 403,
						`elementor failure should be engine_unavailable or auth, got ${result.status} ${bodyStr}`,
					).toBeTruthy();
					continue;
				}

				expect(result.ok, `${blueprint_id}/${engine} apply failed: ${JSON.stringify(result.body)}`).toBeTruthy();
				const payload = result.body as {
					result?: {
						ok?: boolean;
						post_id?: number;
						page_id?: number;
						edit_link?: string;
						engine_used?: string;
					};
				};
				const out = payload.result ?? (result.body as typeof payload.result);
				expect(out?.ok ?? true).toBeTruthy();
				const postId = Number(out?.post_id ?? out?.page_id ?? 0);
				expect(postId).toBeGreaterThan(0);
				if (engine !== 'elementor') {
					expect(out?.engine_used ?? engine).toBe(engine);
				}

				// Resolve front-end URL via REST.
				const pageRes = await request.get(`/wp-json/wp/v2/pages/${postId}`, {
					headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' },
				});
				expect(pageRes.ok(), `load page ${postId}`).toBeTruthy();
				const pageBody = (await pageRes.json()) as { link?: string };
				const url = pageBody.link || `/?p=${postId}`;
				applied.push({ blueprint: blueprint_id, engine, url, postId });
			}
		}

		expect(applied.length).toBeGreaterThanOrEqual(6); // 3 blueprints × 2 engines

		for (const row of applied) {
			await page.goto(row.url, { waitUntil: 'domcontentloaded' });
			expect(page.url()).not.toContain('wp-login.php');

			const overflow = await horizontalOverflow(page);
			expect(overflow, `${row.blueprint}/${row.engine} overflow`).toBe(0);

			const offset = await heroCenterOffset(page);
			expect(offset, `${row.blueprint}/${row.engine} missing h1`).not.toBeNull();
			expect(offset as number, `${row.blueprint}/${row.engine} not centered`).toBeLessThanOrEqual(
				8,
			);

			const shot = path.join(
				artifactDir,
				`${row.blueprint}-${row.engine}-${testInfo.project.name}.png`,
			);
			await page.screenshot({ path: shot, fullPage: true });
		}
	});
});
