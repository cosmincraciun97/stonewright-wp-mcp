import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers/login';
import { detectRestMode, restGet, restUrl, wpRestNonce } from './helpers/wp-rest';
import {
	createApplicationPassword,
	deleteApplicationPassword,
	mcpHandshake,
} from './helpers/mcp-client';

const WP_USER = process.env.WP_USERNAME ?? 'admin';

function routeNames(body: unknown): string[] {
	const routes = (body as { routes?: Record<string, unknown> }).routes ?? {};
	return Object.keys(routes);
}

async function enableStonewright(page: Page): Promise<void> {
	await page.goto('/wp-admin/admin.php?page=stonewright', {
		waitUntil: 'domcontentloaded',
	});
	const enabled = page.locator('#stonewright_enabled');
	if ((await enabled.count()) && !(await enabled.isChecked())) {
		await enabled.check();
		await page.locator('form input[type="submit"], form button[type="submit"]').first().click();
		await page.waitForLoadState('domcontentloaded');
	}
}

test('Stonewright MCP registers and completes an authenticated handshake', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'MCP registration gate runs once.');
	await login(page);
	await enableStonewright(page);

	const index = await restGet(page, '/');
	expect(index.ok, JSON.stringify(index.body)).toBeTruthy();
	const names = routeNames(index.body);
	expect(names).toContain('/mcp/stonewright');
	expect(names).toContain('/mcp/stonewright-oauth');

	const nonce = await wpRestNonce(page);
	const credential = await createApplicationPassword(page, nonce, `stonewright-e2e-mcp-${Date.now()}`);
	try {
		if (names.includes('/mcp')) {
			const defaultEndpoint = restUrl('/mcp', await detectRestMode(page));
			let defaultHandshake: Awaited<ReturnType<typeof mcpHandshake>> | null = null;
			let defaultTransportError: string | null = null;
			try {
				defaultHandshake = await mcpHandshake(page, {
					username: WP_USER,
					password: credential.password,
				}, defaultEndpoint);
			} catch (error) {
				defaultTransportError = error instanceof Error ? error.message : 'transport_error';
			}
			if (defaultHandshake !== null) {
				const defaultName = String(
					((defaultHandshake.initializeResult.serverInfo as { name?: string } | undefined)?.name) ?? '',
				);
				expect(defaultName).not.toBe('Stonewright');
			} else {
				expect(defaultTransportError).not.toBeNull();
			}
		}

		const handshake = await mcpHandshake(page, {
			username: WP_USER,
			password: credential.password,
		});
		const serverName = String(
			((handshake.initializeResult.serverInfo as { name?: string } | undefined)?.name) ?? '',
		);
		expect(serverName).toBe('Stonewright');
		expect(handshake.toolNames).toContain('stonewright-task-start');
		expect(handshake.taskStartResult.isError).toBe(false);
		expect(handshake.taskStartResult.ok).not.toBe(false);
	} finally {
		await deleteApplicationPassword(page, nonce, credential.uuid);
	}
});
