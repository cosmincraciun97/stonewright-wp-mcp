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

async function ensurePluginEnabled(page: Page): Promise<void> {
	await page.goto('/wp-admin/admin.php?page=stonewright', {
		waitUntil: 'domcontentloaded',
	});
	const enabled = page.locator('#stonewright_enabled');
	if ((await enabled.count()) && !(await enabled.isChecked())) {
		await enabled.check();
		await page
			.locator('form input[type="submit"], form button[type="submit"]')
			.first()
			.click();
		await page.waitForLoadState('domcontentloaded');
	}
}

async function runAbility(
	page: Page,
	nonce: string,
	name: string,
	input: Record<string, unknown>,
) {
	return restPost(page, '/stonewright/v1/abilities/run', { name, input }, nonce);
}

test('Stonewright and WooCommerce activate together without an autoloader fatal', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'Compatibility gate runs once.');
	await login(page);
	const response = await page.goto('/wp-admin/plugins.php', {
		waitUntil: 'domcontentloaded',
	});

	expect(response?.ok()).toBe(true);
	await expect(page.locator('body')).not.toContainText(
		/critical error|autoload_filemap|deep-copy/i,
	);
	await expect(page.locator('tr[data-slug="woocommerce"]')).toHaveClass(/active/);
	await expect(page.locator('tr[data-slug="stonewright"]')).toHaveClass(/active/);

	const restIndex = await page.request.get('/wp-json/');
	expect(restIndex.ok()).toBe(true);
	const body = (await restIndex.json()) as { routes?: Record<string, unknown> };
	expect(body.routes).toHaveProperty('/mcp/stonewright');
});

test('native WooCommerce catalog save verifies readback and cleans up', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'WooCommerce CRUD gate runs once.');
	await login(page);
	await ensurePluginEnabled(page);
	const nonce = await wpRestNonce(page);
	const taskStart = await runAbility(page, nonce, 'stonewright/task-start', {
		task: 'Verify native WooCommerce beta catalog behavior',
		surface: 'woocommerce',
		intent: 'create, read back, and remove one synthetic draft product',
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

	const status = await runAbility(page, nonce, 'stonewright/wc-status', {});
	expect(status.ok, JSON.stringify(status.body)).toBeTruthy();
	const statusBody = status.body as {
		result?: { supported?: boolean; version?: string; product_types?: string[] };
	};
	expect(statusBody.result?.supported).toBe(true);
	expect(statusBody.result?.version).toBe('10.9.4');
	expect(statusBody.result?.product_types).toContain('simple');

	const marker = `${Date.now()}-${testInfo.workerIndex}`;
	const productInput = {
		name: `Stonewright package fixture ${marker}`,
		status: 'draft',
		sku: `SW-FIXTURE-${marker}`,
		regular_price: '19.99',
		stonewright_context_token: contextToken,
	};
	const preview = await runAbility(page, nonce, 'stonewright/wc-product-save', {
		...productInput,
	});
	expect(preview.ok, JSON.stringify(preview.body)).toBeTruthy();
	const previewBody = preview.body as {
		result?: { dry_run?: boolean; execution_status?: string; product?: { id?: number } };
	};
	expect(previewBody.result?.dry_run).toBe(true);
	expect(previewBody.result?.execution_status).toBe('preview');
	expect(previewBody.result?.product?.id ?? 0).toBe(0);

	let productId = 0;
	try {
		const applied = await runAbility(page, nonce, 'stonewright/wc-product-save', {
			...productInput,
			dry_run: false,
		});
		expect(applied.ok, JSON.stringify(applied.body)).toBeTruthy();
		const appliedBody = applied.body as {
			result?: {
				effect_verified?: boolean;
				verification_status?: string;
				product?: { id?: number; name?: string; sku?: string; regular_price?: string };
			};
		};
		productId = Number(appliedBody.result?.product?.id ?? 0);
		expect(productId).toBeGreaterThan(0);
		expect(appliedBody.result?.effect_verified).toBe(true);
		expect(appliedBody.result?.verification_status).toBe('passed');
		expect(appliedBody.result?.product?.name).toBe(productInput.name);
		expect(appliedBody.result?.product?.sku).toBe(productInput.sku);
		expect(appliedBody.result?.product?.regular_price).toBe('19.99');

		const read = await runAbility(page, nonce, 'stonewright/wc-product-get', {
			id: productId,
		});
		expect(read.ok, JSON.stringify(read.body)).toBeTruthy();
		const readBody = read.body as {
			result?: { product?: { id?: number; status?: string; description?: string } };
		};
		expect(readBody.result?.product?.id).toBe(productId);
		expect(readBody.result?.product?.status).toBe('draft');
		expect(readBody.result?.product).toHaveProperty('description');
	} finally {
		if (productId > 0) {
			const removed = await runAbility(page, nonce, 'stonewright/wc-product-delete', {
				id: productId,
				force: true,
				dry_run: false,
				stonewright_context_token: contextToken,
			});
			expect(removed.ok, JSON.stringify(removed.body)).toBeTruthy();
			const removedBody = removed.body as {
				result?: { deleted?: boolean; effect_verified?: boolean };
			};
			expect(removedBody.result?.deleted).toBe(true);
			expect(removedBody.result?.effect_verified).toBe(true);
		}
	}
});
