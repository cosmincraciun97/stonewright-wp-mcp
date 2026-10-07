import { expect, test, type Locator, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { PAGE_GATE_PROJECTS, STONEWRIGHT_PAGES } from './helpers/admin-pages';
import { expectNoNewAxeViolations } from './helpers/axe-gate';
import { openConsentScreen } from './helpers/consent';

const artifactDir = path.join(process.cwd(), 'artifacts');

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

test('Setup shows the exact four-call post-update verification flow', async ({ page }) => {
	await login(page);
	await page.goto('/wp-admin/admin.php?page=stonewright', { waitUntil: 'domcontentloaded' });
	const steps = page.locator('[data-stonewright-runtime-verification-flow] > li');
	await expect(steps).toHaveCount(4);
	await expect(steps).toHaveText([
		'Call stonewright-task-start first with a non-empty task.',
		'Call stonewright-setup-profile.',
		'Call stonewright-wordpress-mcp-status.',
		'Call stonewright-client-surface-check with expected_tool=stonewright-task-start and the process-bound catalog observation from the current tool list.',
	]);
});

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

/**
 * Client slugs of the shipped catalog (plugin/data/clients/*.json). Every client list on
 * the Setup screen is built from that catalog, so it is the oracle for "every client".
 */
function catalogClientSlugs(): string[] {
	const dir = path.join(__dirname, '..', '..', 'plugin', 'data', 'clients');
	return fs
		.readdirSync(dir)
		.filter((file) => file.endsWith('.json'))
		.map(
			(file) =>
				JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8')) as {
					slug?: unknown;
					label?: unknown;
				},
		)
		.filter(
			(client) =>
				typeof client.slug === 'string' &&
				typeof client.label === 'string' &&
				client.label !== '',
		)
		.map((client) => String(client.slug).toLowerCase().replace(/[^a-z0-9_-]/g, ''))
		.filter((slug) => slug !== '')
		.sort();
}

/** One attribute of every node a locator matches, in document order. */
async function attributeValues(locator: Locator, attribute: string): Promise<string[]> {
	return locator.evaluateAll(
		(nodes, name) => nodes.map((node) => node.getAttribute(name) ?? ''),
		attribute,
	);
}

/** How many of the matched nodes currently take up space on the page. */
async function renderedCount(locator: Locator): Promise<number> {
	return locator.evaluateAll(
		(nodes) => nodes.filter((node) => node.getClientRects().length > 0).length,
	);
}

/**
 * Setup shows the panels of the chosen authentication method and hides the other
 * method's: every panel of the chosen method is visible, every other panel is hidden.
 */
async function expectAuthPanels(
	page: Page,
	chosen: 'oauth' | 'application-password',
): Promise<void> {
	const other = chosen === 'oauth' ? 'application-password' : 'oauth';
	const shown = page.locator(`[data-stonewright-auth-panel="${chosen}"]`);
	const hidden = page.locator(`[data-stonewright-auth-panel="${other}"]`);
	expect(await shown.count(), `Setup must render ${chosen} panels`).toBeGreaterThan(0);
	expect(await hidden.count(), `Setup must render ${other} panels`).toBeGreaterThan(0);
	for (const panel of await shown.all()) {
		await expect(panel, `${chosen} panels must be visible`).toBeVisible();
	}
	for (const panel of await hidden.all()) {
		await expect(panel, `${other} panels must be hidden`).toBeHidden();
	}
}

/** Opens one client's OAuth guide the way a user does, unless it already starts open. */
async function openOAuthGuide(page: Page, slug: string): Promise<Locator> {
	const guide = page.locator(`[data-stonewright-connect-client="${slug}"]`);
	await expect(guide, `${slug} must have an OAuth guide`).toBeVisible();
	if (!(await guide.evaluate((node) => (node as HTMLDetailsElement).open))) {
		await guide.locator('summary').click();
	}
	await expect(guide).toHaveJSProperty('open', true);
	return guide;
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

			// axe at one desktop and one phone width: no serious or critical finding outside the page's allowance.
			if ((PAGE_GATE_PROJECTS as readonly string[]).includes(testInfo.project.name)) {
				await expectNoNewAxeViolations(page, slug, testInfo);
			}

			const safeName = `${testInfo.project.name}-${slug}`.replace(/[^a-z0-9-_]+/gi, '-');
			await page.screenshot({
				path: path.join(artifactDir, `${safeName}.png`),
				fullPage: true,
			});
		});
	}

	// The consent screen has no slug of its own: it exists for a pending authorization request. It is held to the same
	// gates as the pages above (loads, no overflow, no console error, axe) and sits outside the shell on purpose.
	test('Consent screen loads without overflow or console errors', async ({ page }, testInfo) => {
		const consoleErrors: string[] = [];
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				consoleErrors.push(msg.text());
			}
		});
		page.on('pageerror', (err) => {
			consoleErrors.push(err.message);
		});

		const opened = await openConsentScreen(page);
		test.skip(!opened, 'This site does not serve OAuth, so there is no consent screen to open.');

		await expect(page.locator('.sw-oauth-consent h1')).toHaveCount(1);
		const overflow = await page.evaluate(() => {
			const root = document.documentElement;
			return root.scrollWidth - root.clientWidth;
		});
		expect(overflow, 'Consent screen: horizontal overflow must be <= 0').toBeLessThanOrEqual(0);

		const productErrors = consoleErrors.filter((text) => !isIgnorableConsoleNoise(text));
		expect(productErrors, `Consent screen: console errors\n${productErrors.join('\n')}`).toEqual([]);

		if ((PAGE_GATE_PROJECTS as readonly string[]).includes(testInfo.project.name)) {
			await expectNoNewAxeViolations(page, 'stonewright-oauth-consent', testInfo, '.sw-oauth-consent');
		}

		const safeName = `${testInfo.project.name}-stonewright-oauth-consent`.replace(/[^a-z0-9-_]+/gi, '-');
		await page.screenshot({ path: path.join(artifactDir, `${safeName}.png`), fullPage: true });
	});

	test('Setup OAuth chooser switches all client instructions and preserves fallback auth', async ({
		page,
	}) => {
		await page.goto('/wp-admin/admin.php?page=stonewright', {
			waitUntil: 'domcontentloaded',
		});

		const catalog = catalogClientSlugs();
		expect(catalog.length, 'the shipped client catalog must list clients').toBeGreaterThan(0);

		const oauthButton = page.locator('[data-stonewright-auth-method="oauth"]');
		const passwordButton = page.locator(
			'[data-stonewright-auth-method="application-password"]',
		);
		const oauthGuides = page.locator('[data-stonewright-connect-client]');
		const clientCards = page.locator('[data-stonewright-client-card]');
		const clientPanels = page.locator('[data-stonewright-client-panel]');
		// The selected client is a saved per-user choice; it is put back at the end.
		const initialClient = await page.evaluate(() =>
			document
				.querySelector('[data-stonewright-client-card].is-active')
				?.getAttribute('data-stonewright-client-card') ?? null,
		);

		// Auth choice persists per user across tests — select OAuth explicitly.
		await expect(oauthButton, 'OAuth sign-in must be selectable on the test site').toBeEnabled();
		await oauthButton.click();
		await expect(oauthButton).toHaveAttribute('aria-checked', 'true');
		await expect(passwordButton).toHaveAttribute('aria-checked', 'false');

		// Every catalog client owns exactly one OAuth guide, one Application Password
		// card and one Application Password panel; none is missing or duplicated.
		const cardSlugs = await attributeValues(clientCards, 'data-stonewright-client-card');
		const guideSlugs = await attributeValues(oauthGuides, 'data-stonewright-connect-client');
		const panelSlugs = await attributeValues(clientPanels, 'data-stonewright-client-panel');
		expect(new Set(cardSlugs).size, 'client cards must be unique').toBe(cardSlugs.length);
		expect([...cardSlugs].sort(), 'every catalog client must have one card').toEqual(catalog);
		expect(
			[...guideSlugs].sort(),
			'every client card must own one OAuth guide',
		).toEqual(catalog);
		expect(
			[...panelSlugs].sort(),
			'every client card must own one Application Password panel',
		).toEqual(catalog);
		for (const slugs of [cardSlugs, guideSlugs]) {
			expect(slugs).toEqual(expect.arrayContaining([
				'chatgpt',
				'claude-ai',
				'claude-desktop',
				'claude-code',
				'windsurf',
				'codex',
				'codex-cli',
				'cursor',
				'vscode-copilot',
				'generic-mcp',
				'grok-build',
			]));
			expect(slugs).not.toContain('chatgpt-desktop');
			expect(slugs).not.toContain('vscode');
		}

		// OAuth chosen: the OAuth guides are shown and the password client cards are not.
		await expectAuthPanels(page, 'oauth');
		await expect
			.poll(() => renderedCount(oauthGuides), { message: 'every client guide must be shown' })
			.toBe(catalog.length);
		await expect
			.poll(() => renderedCount(clientCards), { message: 'password client cards must be hidden' })
			.toBe(0);
		await expect(
			page.getByRole('link', { name: /Choose Application Password/ }),
			'the OAuth view must point to the Application Password route',
		).toBeVisible();

		// Every guide opens to its own instructions.
		for (const slug of catalog) {
			const guide = await openOAuthGuide(page, slug);
			await expect(
				guide.locator('.sw-connect-steps li').first(),
				`${slug} must list setup steps`,
			).toBeVisible();
		}

		// Every displayed config carries the one suggested server name, and the
		// codex-cli entry carries this site's MCP URL.
		const serverName = ((await page.locator('#sw-connect-server-name').textContent()) ?? '').trim();
		const mcpUrl = ((await page.locator('#sw-connect-mcp-url').textContent()) ?? '').trim();
		expect(serverName, 'Setup must suggest a server name').not.toBe('');
		expect(mcpUrl, 'Setup must show the OAuth MCP server URL').toContain('/mcp/stonewright-oauth');
		const snippets = await page.locator('.sw-connect-snippet__code').evaluateAll((nodes) =>
			nodes.map((node) => ({ id: node.id, text: node.textContent ?? '' })),
		);
		expect(snippets.length, 'OAuth guides must carry configuration snippets').toBeGreaterThan(0);
		for (const snippet of snippets) {
			expect(snippet.text, `${snippet.id} must use the suggested server name`).toContain(serverName);
		}
		const codexConfig = page.locator('#sw-connect-codex-cli-config');
		await expect(codexConfig).toContainText('[mcp_servers.');
		await expect(codexConfig).toContainText(`[mcp_servers.${serverName}]`);
		await expect(codexConfig).toContainText(mcpUrl);

		// Application Password chosen: the fallback route replaces the OAuth guides.
		await passwordButton.click();
		await expect(passwordButton).toHaveAttribute('aria-checked', 'true');
		await expect(oauthButton).toHaveAttribute('aria-checked', 'false');
		await expectAuthPanels(page, 'application-password');
		await expect
			.poll(() => renderedCount(oauthGuides), { message: 'OAuth guides must be hidden' })
			.toBe(0);
		await expect
			.poll(() => renderedCount(clientCards), { message: 'every client card must be shown' })
			.toBe(catalog.length);
		await expect(
			page.locator('[data-stonewright-app-password-form]'),
			'the Application Password form must stay available',
		).toBeVisible();

		// Each client card switches the panel to that client's own snippets.
		for (const slug of catalog) {
			const card = page.locator(`[data-stonewright-client-card="${slug}"]`);
			const panel = page.locator(`[data-stonewright-client-panel="${slug}"]`);
			await card.click();
			await expect(card).toHaveAttribute('aria-selected', 'true');
			await expect(panel, `${slug} must show its panel`).toBeVisible();
			await expect(
				panel.locator('[data-stonewright-method-snippet]:not([hidden]) pre'),
				`${slug} must show a snippet`,
			).not.toBeEmpty();
			await expect
				.poll(() => renderedCount(clientPanels), { message: 'only the chosen client panel is shown' })
				.toBe(1);
			if (slug === 'codex-cli') {
				await expect(panel).toContainText('[mcp_servers.');
			}
		}
		if (initialClient !== null) {
			await page.locator(`[data-stonewright-client-card="${initialClient}"]`).click();
		}

		// Back to OAuth: guides return and the connected clients stay reachable.
		await oauthButton.click();
		await expect(oauthButton).toHaveAttribute('aria-checked', 'true');
		await expect(passwordButton).toHaveAttribute('aria-checked', 'false');
		await expectAuthPanels(page, 'oauth');
		await expect
			.poll(() => renderedCount(oauthGuides), { message: 'every client guide must be shown again' })
			.toBe(catalog.length);
		const connectedLink = page.getByRole('link', { name: 'Review connected OAuth clients' }).first();
		await expect(connectedLink).toBeVisible();
		await expect(connectedLink).toHaveAttribute('href', '#stonewright-oauth-connections');
		await expect(page.locator('#stonewright-oauth-connections')).toBeVisible();
		await expect(
			page.getByRole('heading', { name: 'Connected OAuth clients', exact: true }),
		).toBeVisible();
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
		expect(await save.evaluate((button) => (button as HTMLButtonElement).form?.classList.contains('stonewright-settings-form'))).toBe(true);
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
