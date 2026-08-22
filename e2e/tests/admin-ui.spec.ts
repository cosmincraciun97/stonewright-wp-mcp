import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';

const artifactDir = path.join(process.cwd(), 'artifacts');

/** Stonewright admin pages exercised by the Phase 0 baseline gate. */
const STONEWRIGHT_PAGES = [
	{ slug: 'stonewright-status', label: 'Dashboard' },
	{ slug: 'stonewright', label: 'Setup' },
	{ slug: 'stonewright-abilities', label: 'AI Abilities' },
	{ slug: 'stonewright-prompts', label: 'Prompts' },
	{ slug: 'stonewright-custom-code-approval', label: 'Code Approval' },
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

	test('Setup OAuth chooser switches all client instructions and preserves fallback auth', async ({
		page,
	}) => {
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});

		const oauthButton = page.locator('[data-stonewright-auth-method="oauth"]');
		const passwordButton = page.locator(
			'[data-stonewright-auth-method="application-password"]',
		);
		await expect(oauthButton).toHaveAttribute('aria-checked', 'true');
		await expect(page.locator('[data-sw-oauth-tab]')).toHaveCount(21);

		const codexTab = page.locator('[data-sw-oauth-tab="codex-cli"]');
		await codexTab.click();
		await expect(codexTab).toHaveAttribute('aria-selected', 'true');
		await expect(page.locator('[data-sw-oauth-panel="codex-cli"]')).toBeVisible();
		await expect(page.locator('#stonewright-oauth-code-codex-cli')).toContainText(
			'[mcp_servers.',
		);

		await passwordButton.click();
		await expect(passwordButton).toHaveAttribute('aria-checked', 'true');
		await expect(
			page.locator('[data-stonewright-auth-panel="application-password"]').first(),
		).toBeVisible();

		await oauthButton.click();
		await expect(oauthButton).toHaveAttribute('aria-checked', 'true');
		await expect(page.getByRole('link', { name: 'Manage connected apps' }).first()).toBeVisible();
	});

	test('Setup Save Settings returns to Stonewright instead of exposing options.php', async ({
		page,
	}) => {
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});

		const settingsForm = page.locator('form.stonewright-settings-form');
		await expect(settingsForm).toHaveCount(1);
		await expect(settingsForm).toHaveAttribute('action', 'options.php');
		const save = settingsForm.getByRole('button', { name: 'Save Settings' });
		await expect(save).toBeVisible();
		expect(await save.evaluate((button) => button.form?.classList.contains('stonewright-settings-form'))).toBe(true);
		expect(await settingsForm.locator('form').count()).toBe(0);

		await Promise.all([
			page.waitForURL(/\/wp-admin\/admin\.php\?page=stonewright(?:&|$)/, {
				timeout: 30_000,
				waitUntil: 'domcontentloaded',
			}),
			save.click(),
		]);

		expect(page.url()).not.toContain('/wp-admin/options.php');
		await expect(page.locator('form.stonewright-settings-form')).toBeVisible();
	});

	test('Application Password generation stays in-page, fills only private snippets, and revokes cleanly', async ({
		page,
	}, testInfo) => {
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});
		const passwordButton = page.locator(
			'[data-stonewright-auth-method="application-password"]',
		);
		await passwordButton.click();
		await expect(passwordButton).toHaveAttribute('aria-checked', 'true');

		const form = page.locator('[data-stonewright-app-password-form]');
		await form.scrollIntoViewIfNeeded();
		const urlBefore = page.url();
		const documentMarker = `same-document-${Date.now()}`;
		await page.evaluate((marker) => {
			(window as Window & { __stonewrightE2EMarker?: string }).__stonewrightE2EMarker = marker;
		}, documentMarker);
		const scrollBefore = await page.evaluate(() => window.scrollY);
		const label = `Stonewright E2E ${testInfo.project.name} ${Date.now()}`;
		await form.locator('#stonewright_app_password_name').fill(label);
		await form.locator('[data-stonewright-app-password-submit]').click();

		const passwordInput = form.locator('#stonewright-generated-app-password');
		await expect(passwordInput).toBeVisible();
		await expect(passwordInput).not.toHaveValue('');
		expect(page.url()).toBe(urlBefore);
		await expect.poll(() => page.evaluate(() =>
			(window as Window & { __stonewrightE2EMarker?: string }).__stonewrightE2EMarker,
		)).toBe(documentMarker);
		await expect(passwordButton).toHaveAttribute('aria-checked', 'true');
		const scrollAfter = await page.evaluate(() => window.scrollY);
		expect(Math.abs(scrollAfter - scrollBefore)).toBeLessThanOrEqual(8);

		const containment = await page.evaluate(() => {
			const input = document.querySelector<HTMLInputElement>('#stonewright-generated-app-password');
			const password = input?.value ?? '';
			const privateText = Array.from(
				document.querySelectorAll('[data-stonewright-method-snippet] pre'),
			).map((node) => node.textContent ?? '').join('\n');
			const prompt = document.querySelector<HTMLElement>('#stonewright-connect-prompt-full');
			const promptText = [
				prompt?.textContent ?? '',
				prompt?.getAttribute('data-stonewright-text-full') ?? '',
			].join('\n');
			return {
				hasPassword: password.length > 0,
				privateSnippetFilled: password.length > 0 && privateText.includes(password),
				promptCredentialFree: password.length > 0 && !promptText.includes(password),
				placeholderReplaced: !privateText.includes('<your-application-password>'),
			};
		});
		expect(containment).toEqual({
			hasPassword: true,
			privateSnippetFilled: true,
			promptCredentialFree: true,
			placeholderReplaced: true,
		});

		const row = page.locator('.stonewright-app-password-table tbody tr').filter({ hasText: label });
		await expect(row).toBeVisible();
		page.once('dialog', (dialog) => dialog.accept());
		await row.getByRole('button', { name: 'Revoke' }).click();
		await expect(row).toHaveCount(0);
		await expect(passwordInput).toHaveCount(0);
		const placeholdersRestored = await page.evaluate(() =>
			Array.from(document.querySelectorAll('[data-stonewright-method-snippet] pre'))
				.some((node) => (node.textContent ?? '').includes('<your-application-password>')),
		);
		expect(placeholdersRestored).toBe(true);
	});
});
