import { expect, test, type Page } from '@playwright/test';
import { restPost, wpRestNonce } from './helpers/wp-rest';

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

async function login(page: Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	if (!page.url().includes('wp-login.php')) return;
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/\/wp-admin\//, {
		timeout: 45_000,
		waitUntil: 'domcontentloaded',
	});
}

async function runAbility(
	page: Page,
	nonce: string,
	name: string,
	input: Record<string, unknown>,
) {
	return restPost(page, '/stonewright/v1/abilities/run', { name, input }, nonce);
}

test('audit incidents remain readable and payloads stay inside the page', async ({
	page,
}, testInfo) => {
	test.skip(
		testInfo.project.name !== 'desktop-1024-light',
		'Responsive audit regression runs once at the former failure width.',
	);
	await login(page);
	await page.goto('/wp-admin/admin.php?page=stonewright', {
		waitUntil: 'domcontentloaded',
	});
	const nonce = await wpRestNonce(page);

	const taskStart = await runAbility(page, nonce, 'stonewright/task-start', {
		task: 'Seed one redacted audit row for the admin UI regression',
		surface: 'runtime',
		intent: 'read-only verification',
		responseMode: 'compact',
	});
	expect(taskStart.ok, JSON.stringify(taskStart.body)).toBeTruthy();
	const taskBody = taskStart.body as {
		result?: { context_token?: string };
		context_token?: string;
	};
	const contextToken = String(
		taskBody.result?.context_token ?? taskBody.context_token ?? '',
	);
	expect(contextToken).toMatch(/^swctx_/);

	const execution = await runAbility(page, nonce, 'stonewright/php-execute', {
		code: 'return ["audit_fixture" => str_repeat("contained-", 80)];',
		read_only: true,
		stonewright_context_token: contextToken,
	});
	expect(execution.ok, JSON.stringify(execution.body)).toBeTruthy();

	await page.goto('/wp-admin/admin.php?page=stonewright-audit-log', {
		waitUntil: 'domcontentloaded',
	});
	const row = page
		.locator('.sw-audit-row')
		.filter({ hasText: 'stonewright/php-execute' })
		.first();
	await expect(row).toBeVisible();
	await expect(row.locator('[data-label="Details"]')).toBeVisible();
	await row.locator('summary').click();

	const payload = row.locator('.sw-audit-payload');
	await expect(payload).toBeVisible();
	await expect(row.getByRole('button', { name: 'Copy payload' })).toBeVisible();

	const containment = await payload.evaluate((node) => {
		const payloadRect = node.getBoundingClientRect();
		const content = document.querySelector('.sw-shell__content');
		const contentRect = content?.getBoundingClientRect();
		return {
			documentOverflow:
				document.documentElement.scrollWidth - document.documentElement.clientWidth,
			contained:
				!!contentRect &&
				payloadRect.left >= contentRect.left - 1 &&
				payloadRect.right <= contentRect.right + 1,
		};
	});
	expect(containment.documentOverflow).toBeLessThanOrEqual(2);
	expect(containment.contained).toBe(true);
});

test('legacy Sandbox audit links lead to the single dedicated Audit Log', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'Redirect UX runs once.');
	await login(page);
	await page.goto('/wp-admin/admin.php?page=stonewright-sandbox&tab=audit', {
		waitUntil: 'domcontentloaded',
	});
	await expect(page.getByRole('heading', { name: 'Audit Log moved' })).toBeVisible();
	const link = page.getByRole('link', { name: 'Open Audit Log' });
	await expect(link).toBeVisible();
	await link.click();
	await expect(page).toHaveURL(/page=stonewright-audit-log/);
});

test('companion update handoff is explicit and never claims browser-side installation', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'Connect handoff runs once.');
	await login(page);
	await page.route('**/stonewright/v1/admin/companion-update-status*', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ok: true,
				plugin_version: '1.0.0-beta.1',
				latest_release_version: '1.0.0-beta.1',
				plugin_update_available: false,
				companion_status: 'unverified',
				companion_package:
					'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.1/stonewright-companion-1.0.0-beta.1.tgz',
				checksums:
					'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.1/SHA256SUMS.txt',
				bridge: { reachable: false, version: '' },
				update_prompt:
					'Update the Stonewright companion used by this AI client to 1.0.0-beta.1. Do not print, reveal, move, or commit credentials.',
				boundary:
					'WordPress cannot replace a local stdio companion process. Update it in the AI client.',
			}),
		});
	});
	await page.goto('/wp-admin/admin.php?page=stonewright', {
		waitUntil: 'domcontentloaded',
	});

	const check = page.getByRole('button', { name: 'Check latest companion' });
	await expect(check).toBeVisible();
	await expect(page.locator('.sw-update-guide')).toContainText(
		'The browser cannot replace an stdio process on your computer.',
	);
	await check.click();
	const prompt = page.locator('#stonewright-companion-update-prompt');
	await expect(prompt).toBeVisible();
	await expect(prompt).toHaveValue(/Do not print, reveal, move, or commit credentials/);
	await expect(page.getByRole('button', { name: 'Copy update prompt' })).toBeVisible();
});
