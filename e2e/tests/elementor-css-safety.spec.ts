import { expect, test, type Page } from '@playwright/test';
import {
	restPost,
	restRequest,
	runAbility,
	runAbilityWithProfileConfirmation,
	wpRestNonce,
} from './helpers/wp-rest';
import {
	cleanupElementorCssSentinels,
	seedElementorCssSentinels,
} from './helpers/wp-env';

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';
const PINNED_ELEMENTOR_VERSION = '3.30.0';
const PROTECTED_SENTINELS = [
	'custom-frontend.min.css',
	'custom-pro-widget-nav-menu.min.css',
] as const;

type CssManifest = {
	file_count: number;
	files: Array<{ name: string; size: number; sha256: string }>;
};

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

async function ensureStonewrightEnabled(page: Page): Promise<void> {
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

function resultPayload(body: unknown): Record<string, unknown> {
	if (!body || typeof body !== 'object') return {};
	const wrapper = body as { result?: unknown };
	return wrapper.result && typeof wrapper.result === 'object'
		? (wrapper.result as Record<string, unknown>)
		: (body as Record<string, unknown>);
}

async function runPhp(
	page: Page,
	nonce: string,
	contextToken: string,
	code: string,
	readOnly: boolean,
): Promise<Record<string, unknown>> {
	const execution = await runAbilityWithProfileConfirmation(
		page,
		nonce,
		contextToken,
		'stonewright/php-execute',
		{
			code,
			read_only: readOnly,
			stonewright_context_token: contextToken,
		},
	);
	expect(execution.ok, JSON.stringify(execution.body)).toBeTruthy();
	const payload = resultPayload(execution.body);
	expect(payload.ok, JSON.stringify(execution.body)).toBe(true);
	return payload.result && typeof payload.result === 'object'
		? (payload.result as Record<string, unknown>)
		: {};
}

async function readCssManifest(
	page: Page,
	nonce: string,
	contextToken: string,
): Promise<CssManifest> {
	const result = await runPhp(
		page,
		nonce,
		contextToken,
		`$uploads = wp_upload_dir();
$dir = rtrim((string) $uploads['basedir'], '/\\\\') . '/elementor/css';
$files = [];
if (is_dir($dir)) {
	$iterator = new \\FilesystemIterator($dir, \\FilesystemIterator::SKIP_DOTS);
	foreach ($iterator as $entry) {
		$name = $entry->getFilename();
		if (!$entry->isFile() || $entry->isLink() || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\\.css$/D', $name)) {
			continue;
		}
		$sha256 = hash_file('sha256', $entry->getPathname());
		if (false === $sha256) {
			continue;
		}
		$files[] = ['name' => $name, 'size' => $entry->getSize(), 'sha256' => $sha256];
	}
}
usort($files, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));
return ['file_count' => count($files), 'files' => $files];`,
		true,
	);
	return resultAsManifest(result);
}

function resultAsManifest(result: Record<string, unknown>): CssManifest {
	return {
		file_count: Number(result.file_count ?? 0),
		files: Array.isArray(result.files)
			? result.files.map((file) => {
				const row = file as Record<string, unknown>;
				return {
					name: String(row.name ?? ''),
					size: Number(row.size ?? 0),
					sha256: String(row.sha256 ?? ''),
				};
			})
			: [],
	};
}

async function assertCssHttp200(page: Page, names: readonly string[]): Promise<void> {
	for (const name of names) {
		const response = await page.request.get(
			`/wp-content/uploads/elementor/css/${encodeURIComponent(name)}`,
			{ failOnStatusCode: false },
		);
		expect(response.status(), name).toBe(200);
	}
}

function assertProtectedSentinelsPreserved(
	before: CssManifest,
	after: CssManifest,
): void {
	const beforeByName = new Map(before.files.map((file) => [file.name, file]));
	const afterByName = new Map(after.files.map((file) => [file.name, file]));
	for (const name of PROTECTED_SENTINELS) {
		const expected = beforeByName.get(name);
		const actual = afterByName.get(name);
		expect(expected, `missing baseline sentinel ${name}`).toBeDefined();
		expect(actual, `missing post-write sentinel ${name}`).toBeDefined();
		expect(actual?.size).toBe(expected?.size);
		expect(actual?.sha256).toBe(expected?.sha256);
	}
}

test('real Elementor regenerates only target post CSS and survives verification', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'Elementor CSS gate runs once.');
	await login(page);
	await ensureStonewrightEnabled(page);
	const nonce = await wpRestNonce(page);

	const task = await runAbility(page, nonce, 'stonewright/task-start', {
		task: 'Verify real Elementor post-only CSS regeneration',
		surface: 'elementor-design',
		intent: 'create one synthetic draft, regenerate its CSS, verify, and remove it',
		responseMode: 'compact',
		target_architecture: 'v3',
	});
	expect(task.ok, JSON.stringify(task.body)).toBeTruthy();
	const contextToken = String(resultPayload(task.body).context_token ?? '');
	expect(contextToken).toMatch(/^swctx_/);

	const runtime = await runPhp(
		page,
		nonce,
		contextToken,
		`return ['elementor_version' => defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : ''];`,
		true,
	);
	expect(runtime.elementor_version).toBe(PINNED_ELEMENTOR_VERSION);

	// php-execute permanently blocks filesystem mutation APIs. Seed uploads
	// CSS sentinels through wp-env WP-CLI instead of stonewright/php-execute.
	const seededSentinels = seedElementorCssSentinels();
	expect(seededSentinels).toEqual([...PROTECTED_SENTINELS]);
	const sentinelsPrepared = true;

	let postId = 0;
	let pageCleanup: { ok: boolean; status: number; body: unknown } | null = null;
	let sentinelCleanup: Record<string, unknown> | null = null;
	try {
		const created = await restPost(
			page,
			'/wp/v2/pages',
			{ title: 'Stonewright Elementor CSS safety fixture', status: 'draft' },
			nonce,
		);
		expect(created.ok, JSON.stringify(created.body)).toBeTruthy();
		postId = Number((created.body as { id?: number }).id ?? 0);
		expect(postId).toBeGreaterThan(0);

		const beforeBuild = await readCssManifest(page, nonce, contextToken);
		expect(beforeBuild.file_count).toBeGreaterThanOrEqual(PROTECTED_SENTINELS.length);
		assertProtectedSentinelsPreserved(beforeBuild, beforeBuild);
		await assertCssHttp200(page, PROTECTED_SENTINELS);
		expect(beforeBuild.files.some((file) => file.name === `post-${postId}.css`)).toBe(false);

		const build = await runAbilityWithProfileConfirmation(
			page,
			nonce,
			contextToken,
			'stonewright/elementor-build-tree',
			{
				post_id: postId,
				stonewright_context_token: contextToken,
				tree: [
					{
						id: 'csssafe1',
						elType: 'container',
						settings: { content_width: 'boxed' },
						elements: [
							{
								id: 'csssafe2',
								elType: 'widget',
								widgetType: 'heading',
								settings: { title: 'Elementor CSS safety' },
								elements: [],
							},
						],
					},
				],
			},
		);
		expect(build.ok, JSON.stringify(build.body)).toBeTruthy();
		const built = resultPayload(build.body);
		const buildCss = (built.css ?? {}) as Record<string, unknown>;
		expect(buildCss.target).toBe(`post-${postId}.css`);
		expect(buildCss.collateral_change_count).toBe(0);
		expect(Number(buildCss.after_file_count ?? 0)).toBeGreaterThanOrEqual(1);
		const afterBuild = await readCssManifest(page, nonce, contextToken);
		expect(afterBuild.file_count).toBe(beforeBuild.file_count + 1);
		assertProtectedSentinelsPreserved(beforeBuild, afterBuild);
		await assertCssHttp200(page, PROTECTED_SENTINELS);
		await assertCssHttp200(page, [`post-${postId}.css`]);

		const verify = await runAbilityWithProfileConfirmation(
			page,
			nonce,
			contextToken,
			'stonewright/elementor-post-write-verify',
			{
				post_id: postId,
				element_ids: ['csssafe1', 'csssafe2'],
				stonewright_context_token: contextToken,
			},
		);
		expect(verify.ok, JSON.stringify(verify.body)).toBeTruthy();
		const verified = resultPayload(verify.body);
		expect(verified.verification_status).toBe('passed');
		const verifyCss = (verified.css ?? {}) as Record<string, unknown>;
		expect(verifyCss.target).toBe(`post-${postId}.css`);
		expect(verifyCss.collateral_change_count).toBe(0);
		expect(Number(verifyCss.before_file_count ?? 0)).toBeGreaterThanOrEqual(1);
		expect(Number(verifyCss.after_file_count ?? 0)).toBeGreaterThanOrEqual(1);
		const afterVerify = await readCssManifest(page, nonce, contextToken);
		expect(afterVerify.files).toEqual(afterBuild.files);
		assertProtectedSentinelsPreserved(beforeBuild, afterVerify);
		await assertCssHttp200(page, PROTECTED_SENTINELS);
		await assertCssHttp200(page, [`post-${postId}.css`]);
		const probes = (verifyCss.protected_probes_after ?? []) as Array<{
			asset?: string;
			status?: number;
			url_sha256?: string;
		}>;
		for (const asset of PROTECTED_SENTINELS) {
			expect(probes).toContainEqual({
				asset,
				status: 200,
				url_sha256: expect.any(String),
			});
		}
		expect(probes).toContainEqual({
			asset: `post-${postId}.css`,
			status: 200,
			url_sha256: expect.any(String),
		});

		const published = await restRequest(page, 'POST', `/wp/v2/pages/${postId}`, {
			nonce,
			data: { status: 'publish' },
		});
		expect(published.ok, JSON.stringify(published.body)).toBeTruthy();

		await page.goto(`/?p=${postId}`, { waitUntil: 'domcontentloaded' });
		await expect(
			page.locator('.elementor-element-csssafe1, [data-id="csssafe1"]').first(),
		).toBeVisible({ timeout: 15_000 });
		await expect(
			page.locator('.elementor-element-csssafe2, [data-id="csssafe2"]').first(),
		).toContainText('Elementor CSS safety');

		const beforeNegative = await readCssManifest(page, nonce, contextToken);
		const negative = await runAbilityWithProfileConfirmation(
			page,
			nonce,
			contextToken,
			'stonewright/elementor-post-write-verify',
			{
				post_id: postId,
				element_ids: ['missing-css-assertion'],
				stonewright_context_token: contextToken,
			},
		);
		expect(negative.ok, JSON.stringify(negative.body)).toBeTruthy();
		const negativeResult = resultPayload(negative.body);
		expect(negativeResult.ok).toBe(false);
		expect(negativeResult.verification_status).toBe('failed');
		const negativeCss = (negativeResult.css ?? {}) as Record<string, unknown>;
		expect(negativeCss.rollback_status).toBe('succeeded');
		expect(negativeCss.manifest_rollback_status).toBe('succeeded');
		expect(negativeCss.metadata_rollback_status).toBe('succeeded');
		const afterNegative = await readCssManifest(page, nonce, contextToken);
		expect(afterNegative.files).toEqual(beforeNegative.files);
		await assertCssHttp200(page, PROTECTED_SENTINELS);
		await assertCssHttp200(page, [`post-${postId}.css`]);
	} finally {
		try {
			if (postId > 0) {
				pageCleanup = await restRequest(page, 'DELETE', `/wp/v2/pages/${postId}`, {
					nonce,
					data: { force: true },
				});
			}
		} finally {
			if (sentinelsPrepared) {
				const remaining = cleanupElementorCssSentinels();
				sentinelCleanup = { ok: remaining.length === 0, remaining };
			}
		}
	}
	if (postId > 0) {
		expect(pageCleanup?.ok, JSON.stringify(pageCleanup)).toBe(true);
		expect((pageCleanup?.body as { deleted?: boolean; id?: number }).deleted).toBe(true);
		expect((pageCleanup?.body as { deleted?: boolean; id?: number }).id).toBe(postId);
	}
	expect(sentinelCleanup?.ok, JSON.stringify(sentinelCleanup)).toBe(true);
	expect(sentinelCleanup?.remaining).toEqual([]);
});
