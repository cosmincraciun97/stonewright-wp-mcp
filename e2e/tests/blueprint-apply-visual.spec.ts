import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { restGet, restPost, wpRestNonce } from './helpers/wp-rest';

/**
 * Real blueprint-apply + front-end visual proof.
 *
 * Uses page.request (session cookies) + rest_route fallback — never the
 * isolated Playwright `request` fixture (no cookies → auth/routing failures).
 * Pinned to desktop-1440-light only.
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

async function runAbility(
	page: Page,
	nonce: string,
	name: string,
	input: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; body: unknown; url: string }> {
	return restPost(page, '/stonewright/v1/abilities/run', { name, input }, nonce);
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
			(document.querySelector('h1') as HTMLElement | null) ||
			(document.querySelector('.entry-title') as HTMLElement | null);
		if (!hero) {
			return null;
		}
		const rect = hero.getBoundingClientRect();
		const center = rect.left + rect.width / 2;
		return Math.abs(center - window.innerWidth / 2);
	});
}

test.describe('Blueprint apply + front-end visual proof', () => {
	test('apply dental/saas/restaurant (gutenberg+fse) and screenshot front-end', async ({
		page,
	}, testInfo) => {
		test.skip(
			testInfo.project.name !== 'desktop-1440-light',
			'Heavy visual write runs once.',
		);
		fs.mkdirSync(artifactDir, { recursive: true });
		await login(page);
		await ensurePluginEnabled(page);
		// Setup page often has data-rest-nonce; fall back to editor bootstrap.
		let nonce = '';
		try {
			nonce = await wpRestNonce(page);
		} catch {
			await page.goto('/wp-admin/admin.php?page=stonewright', {
				waitUntil: 'domcontentloaded',
			});
			nonce = await wpRestNonce(page);
		}
		const taskStart = await runAbility(page, nonce, 'stonewright/task-start', {
			task: 'Apply representative blueprints for e2e visual verification',
			surface: 'blueprints',
			intent: 'write and verify',
			responseMode: 'compact',
			target_architecture: 'v3',
		});
		expect(
			taskStart.ok,
			`task-start failed: ${JSON.stringify(taskStart.body)} via ${taskStart.url}`,
		).toBeTruthy();
		const taskPayload = taskStart.body as {
			result?: { context_token?: string };
			context_token?: string;
		};
		const contextToken = String(
			taskPayload.result?.context_token ?? taskPayload.context_token ?? '',
		);
		expect(contextToken).toMatch(/^swctx_/);

		const engines: Array<'gutenberg' | 'fse' | 'elementor'> = ['gutenberg', 'fse', 'elementor'];
		const applied: Array<{ blueprint: string; engine: string; url: string; postId: number }> =
			[];

		for (const blueprint_id of BLUEPRINTS) {
			for (const engine of engines) {
				if (engine === 'elementor' && blueprint_id !== 'dental') {
					continue;
				}

				const result = await runAbility(page, nonce, 'stonewright/blueprint-apply', {
					blueprint_id,
					engine,
					mode: 'publish',
					page_title: `SW Apply ${blueprint_id} ${engine}`,
					stonewright_context_token: contextToken,
				});

				if (engine === 'elementor' && !result.ok) {
					const bodyStr = JSON.stringify(result.body);
					expect(
						bodyStr.includes('stonewright_engine_unavailable') ||
							bodyStr.includes('engine_unavailable') ||
							bodyStr.includes('not_found') ||
							result.status === 400 ||
							result.status === 403 ||
							result.status === 404,
						`elementor failure should be unavailable/auth, got ${result.status} ${bodyStr} via ${result.url}`,
					).toBeTruthy();
					continue;
				}

				expect(
					result.ok,
					`${blueprint_id}/${engine} apply failed: ${JSON.stringify(result.body)} via ${result.url}`,
				).toBeTruthy();

				const payload = result.body as {
					result?: {
						ok?: boolean;
						post_id?: number;
						page_id?: number;
						engine_used?: string;
					};
					// Some adapters return the ability payload at the top level.
					ok?: boolean;
					post_id?: number;
					page_id?: number;
					engine_used?: string;
				};
				const out = payload.result ?? payload;
				expect(out?.ok ?? true).toBeTruthy();
				const postId = Number(out?.post_id ?? out?.page_id ?? 0);
				expect(postId, `${blueprint_id}/${engine} missing post_id`).toBeGreaterThan(0);
				if (engine !== 'elementor' && out?.engine_used) {
					expect(out.engine_used).toBe(engine);
				}

				const pageRes = await restGet(page, `/wp/v2/pages/${postId}`, nonce);
				let url = `/?p=${postId}`;
				if (pageRes.ok && pageRes.body && typeof pageRes.body === 'object') {
					const link = (pageRes.body as { link?: string }).link;
					if (link) {
						url = link.startsWith('http')
							? new URL(link).pathname + new URL(link).search
							: link;
					}
				}
				applied.push({ blueprint: blueprint_id, engine, url, postId });
			}
		}

		expect(applied.length).toBeGreaterThanOrEqual(6); // 3 × gutenberg+fse

		for (const row of applied) {
			await page.goto(row.url, { waitUntil: 'domcontentloaded' });
			expect(page.url()).not.toContain('wp-login.php');

			const overflow = await horizontalOverflow(page);
			expect(overflow, `${row.blueprint}/${row.engine} overflow`).toBe(0);

			const offset = await heroCenterOffset(page);
			// FSE/gutenberg blueprints always emit headings; allow soft skip if theme hides title only.
			if (offset !== null) {
				expect(
					offset,
					`${row.blueprint}/${row.engine} not centered`,
				).toBeLessThanOrEqual(12);
			}

			const shot = path.join(
				artifactDir,
				`${row.blueprint}-${row.engine}-${testInfo.project.name}.png`,
			);
			await page.screenshot({ path: shot, fullPage: true });
		}
	});
});
