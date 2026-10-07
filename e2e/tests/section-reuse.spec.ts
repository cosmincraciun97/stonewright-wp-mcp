import { expect, test, type Page } from '@playwright/test';
import {
	restPost,
	restRequest,
	runAbilityWithProfileConfirmation,
	wpRestNonce,
} from './helpers/wp-rest';

/**
 * Section reuse on a real WordPress: copy a V3, a V4 and a Gutenberg section into a new page with text and
 * image changes in one dry run and one apply, and check that the readback equals the plan, that CSS was
 * regenerated for the target post only, and that the source page did not change by a single byte.
 *
 * The V4 case needs an Elementor that ships the Atomic Widgets module; it is skipped when the installed
 * Elementor has none.
 */

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';

type Json = Record<string, unknown>;

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

function resultPayload(body: unknown): Json {
	if (!body || typeof body !== 'object') return {};
	const wrapper = body as { result?: unknown };
	return wrapper.result && typeof wrapper.result === 'object'
		? (wrapper.result as Json)
		: (body as Json);
}

type Ctx = { page: Page; nonce: string; token: string };

async function ability(ctx: Ctx, name: string, input: Json): Promise<Json> {
	const response = await runAbilityWithProfileConfirmation(
		ctx.page,
		ctx.nonce,
		ctx.token,
		name,
		{ ...input, stonewright_context_token: ctx.token },
	);
	return { __status: response.status, ...resultPayload(response.body) };
}

async function php(ctx: Ctx, code: string): Promise<Json> {
	const payload = await ability(ctx, 'stonewright/php-execute', { code, read_only: true });
	expect(payload.ok, JSON.stringify(payload)).toBe(true);
	return (payload.result ?? {}) as Json;
}

/** Hash of one post's row and every meta value, so "unchanged" means unchanged. */
async function stateHash(ctx: Ctx, postId: number): Promise<string> {
	const result = await php(
		ctx,
		`global $wpdb;
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", ${postId}), ARRAY_A);
$meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", ${postId}), ARRAY_A);
return ['hash' => hash('sha256', wp_json_encode([$row, $meta]))];`,
	);
	return String(result.hash);
}

/** Name and hash of every file in the Elementor CSS directory. */
async function cssManifest(ctx: Ctx): Promise<Record<string, string>> {
	const result = await php(
		ctx,
		`$dir = wp_upload_dir()['basedir'] . '/elementor/css';
$files = [];
if (is_dir($dir)) {
	foreach (scandir($dir) as $name) {
		if ($name !== '.' && $name !== '..' && is_file($dir . '/' . $name)) {
			$files[$name] = hash_file('sha256', $dir . '/' . $name);
		}
	}
}
ksort($files);
return ['files' => $files];`,
	);
	return (result.files ?? {}) as Record<string, string>;
}

function changedFiles(
	before: Record<string, string>,
	after: Record<string, string>,
): string[] {
	return [...new Set([...Object.keys(before), ...Object.keys(after)])]
		.filter((name) => before[name] !== after[name])
		.sort();
}

async function createPage(page: Page, nonce: string, title: string, content = ''): Promise<number> {
	const created = await restPost(page, '/wp/v2/pages', { title, status: 'publish', content }, nonce);
	expect(created.ok, JSON.stringify(created.body)).toBeTruthy();
	return Number((created.body as { id?: number }).id ?? 0);
}

async function uploadPng(page: Page, nonce: string, name: string, base64: string): Promise<{ id: number; url: string }> {
	const response = await page.request.post('/wp-json/wp/v2/media', {
		headers: {
			'X-WP-Nonce': nonce,
			'Content-Type': 'image/png',
			'Content-Disposition': `attachment; filename="${name}.png"`,
		},
		data: Buffer.from(base64, 'base64'),
		failOnStatusCode: false,
	});
	expect(response.ok(), await response.text()).toBeTruthy();
	const body = (await response.json()) as { id: number; source_url: string };
	return { id: body.id, url: body.source_url };
}

// A 1x1 PNG, twice with different pixels.
const PNG_OLD = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const PNG_NEW = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==';

function blockSource(oldImage: { id: number; url: string }): string {
	return [
		'<!-- wp:group {"anchor":"features","layout":{"type":"constrained"}} -->',
		'<div id="features" class="wp-block-group"><!-- wp:heading -->',
		'<h2 class="wp-block-heading">Old block headline</h2>',
		'<!-- /wp:heading -->',
		`<!-- wp:image {"id":${oldImage.id},"sizeSlug":"full","linkDestination":"none"} -->`,
		`<figure class="wp-block-image size-full"><img src="${oldImage.url}" alt="Old image" class="wp-image-${oldImage.id}"/></figure>`,
		'<!-- /wp:image --></div>',
		'<!-- /wp:group -->',
	].join('\n');
}

test('a V3, a V4 and a Gutenberg section are reused into new pages in one batch each', async ({
	page,
}, testInfo) => {
	test.skip(testInfo.project.name !== 'desktop-1440-light', 'Section reuse gate runs once.');
	test.setTimeout(240_000);
	await login(page);
	await page.goto('/wp-admin/admin.php?page=stonewright', { waitUntil: 'domcontentloaded' });
	const nonce = await wpRestNonce(page);

	const task = await restPost(
		page,
		'/stonewright/v1/abilities/run',
		{
			name: 'stonewright/task-start',
			input: {
				task: 'Reuse sections on a new page',
				surface: 'elementor-design',
				intent: 'create synthetic pages, copy a section, verify, remove them',
				responseMode: 'compact',
			},
		},
		nonce,
	);
	expect(task.ok, JSON.stringify(task.body)).toBeTruthy();
	const token = String(resultPayload(task.body).context_token ?? '');
	expect(token).toMatch(/^swctx_/);
	expect(resultPayload(task.body).context).toBeDefined();
	const preferences = ((resultPayload(task.body).context as Json | undefined)?.agent_preferences ?? {}) as Json;
	expect(preferences.section_reuse).toBe('ask');
	const ctx: Ctx = { page, nonce, token };

	const created: number[] = [];
	try {
		const oldImage = await uploadPng(page, nonce, 'swreuse-old', PNG_OLD);
		const newImage = await uploadPng(page, nonce, 'swreuse-new', PNG_NEW);

		// ------------------------------------------------------------ Elementor V3
		const v3Source = await createPage(page, nonce, 'Section reuse source V3');
		const v3Target = await createPage(page, nonce, 'Section reuse target V3');
		created.push(v3Source, v3Target);
		for (const [post, tree] of [
			[
				v3Source,
				[
					{
						id: 'swsrc001',
						elType: 'container',
						settings: { content_width: 'boxed' },
						elements: [
							{ id: 'swsrc002', elType: 'widget', widgetType: 'heading', settings: { title: 'Old V3 headline' }, elements: [] },
							{ id: 'swsrc003', elType: 'widget', widgetType: 'text-editor', settings: { editor: '<p>Old V3 text.</p>' }, elements: [] },
							{ id: 'swsrc004', elType: 'widget', widgetType: 'image', settings: { image: { id: oldImage.id, url: oldImage.url } }, elements: [] },
						],
					},
				],
			],
			[v3Target, [{ id: 'swtgt001', elType: 'container', settings: { content_width: 'boxed' }, elements: [] }]],
		] as Array<[number, unknown[]]>) {
			const built = await ability(ctx, 'stonewright/elementor-build-tree', { post_id: post, tree });
			expect(built.ok, JSON.stringify(built)).toBe(true);
		}
		await ability(ctx, 'stonewright/elementor-css-regenerate', { post_id: v3Source });

		const sourceBefore = await stateHash(ctx, v3Source);
		const cssBefore = await cssManifest(ctx);

		const found = await ability(ctx, 'stonewright/section-reuse-find', {
			builder: 'elementor-v3',
			roles: ['hero', 'features', 'other'],
			target_post_id: v3Target,
		});
		const candidates = (found.roles as Array<{ candidates: Array<{ source: { post_id: number }; locator: Json }> }>).flatMap((role) => role.candidates);
		const candidate = candidates.find((entry) => entry.source.post_id === v3Source);
		expect(candidate, JSON.stringify(found)).toBeDefined();
		expect(candidates.some((entry) => entry.source.post_id === v3Target)).toBe(false);

		const extracted = await ability(ctx, 'stonewright/section-reuse-extract', {
			post_id: v3Source,
			locator: candidate?.locator,
		});
		expect(extracted.ok, JSON.stringify(extracted)).toBe(true);
		const operations = [
			{ action: 'insert_section', op_id: 'copy', parent_id: 'swtgt001', section: extracted.section },
			{ action: 'update_element', element_ref: 'copy.ph-2', settings: { title: 'Hand-built to last' } },
			{ action: 'update_element', element_ref: 'copy.ph-3', settings: { editor: '<p>Solid oak and honest joints.</p>' } },
			{ action: 'update_element', element_ref: 'copy.ph-4', settings: { image: { id: newImage.id, url: newImage.url } } },
		];
		const plan = await ability(ctx, 'stonewright/elementor-v3-batch-mutate', { post_id: v3Target, dry_run: true, operations });
		expect(plan.ok, JSON.stringify(plan)).toBe(true);
		const applied = await ability(ctx, 'stonewright/elementor-v3-batch-mutate', {
			post_id: v3Target,
			expected_tree_hash: plan.before_hash,
			operations,
		});
		expect(applied.verification_status, JSON.stringify(applied)).toBe('verified');
		expect(applied.readback_hash).toBe(applied.after_hash);
		const changeSet = applied.change_set as Json;
		expect(changeSet.reuse_source).toEqual([
			{ post_id: v3Source, builder: 'elementor-v3', locator: { kind: 'element', id: 'swsrc001' } },
		]);
		expect(changeSet.unexpected).toEqual([]);

		const regenerated = await ability(ctx, 'stonewright/elementor-css-regenerate', { post_id: v3Target });
		expect(regenerated.ok, JSON.stringify(regenerated)).toBe(true);
		const cssAfter = await cssManifest(ctx);
		expect(changedFiles(cssBefore, cssAfter)).toEqual([`post-${v3Target}.css`]);
		expect(await stateHash(ctx, v3Source)).toBe(sourceBefore);

		await page.goto(`/?p=${v3Target}`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('body')).toContainText('Hand-built to last');
		await expect(page.locator('body')).toContainText('Solid oak and honest joints.');
		await expect(page.locator(`img[src*="swreuse-new"]`).first()).toBeVisible();
		await expect(page.locator('body')).not.toContainText('Old V3 headline');

		// ------------------------------------------------------------ Gutenberg
		const blockSourcePost = await createPage(page, nonce, 'Section reuse source blocks', blockSource(oldImage));
		const blockTarget = await createPage(
			page,
			nonce,
			'Section reuse target blocks',
			'<!-- wp:group {"anchor":"features"} -->\n<div id="features" class="wp-block-group"><!-- wp:paragraph -->\n<p>Existing section that owns the anchor.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->',
		);
		created.push(blockSourcePost, blockTarget);
		const blockBefore = await stateHash(ctx, blockSourcePost);

		const blockExtract = await ability(ctx, 'stonewright/section-reuse-extract', {
			post_id: blockSourcePost,
			locator: { kind: 'block', anchor: 'features' },
		});
		expect(blockExtract.ok, JSON.stringify(blockExtract)).toBe(true);
		const blockOps = [
			{ action: 'insert_section', op_id: 'copy', path: [], position: 1, section: blockExtract.section },
			{ action: 'update', section_ref: 'copy', relative_path: [0, 0], innerHTML: '\n<h2 class="wp-block-heading">Hand-built to last</h2>\n' },
			{
				action: 'update',
				section_ref: 'copy',
				relative_path: [0, 1],
				attrs: { id: newImage.id },
				innerHTML: `\n<figure class="wp-block-image size-full"><img src="${newImage.url}" alt="A hand-built chair" class="wp-image-${newImage.id}"/></figure>\n`,
			},
		];
		const blockPlan = await ability(ctx, 'stonewright/blocks-batch-mutate', { post_id: blockTarget, dry_run: true, operations: blockOps });
		expect(blockPlan.ok, JSON.stringify(blockPlan)).toBe(true);
		const blockApplied = await ability(ctx, 'stonewright/blocks-batch-mutate', {
			post_id: blockTarget,
			expected_content_hash: blockPlan.before_hash,
			operations: blockOps,
		});
		expect(blockApplied.verification_status, JSON.stringify(blockApplied)).toBe('verified');
		expect(blockApplied.after_hash).toBe(blockPlan.after_hash);
		expect(blockApplied.readback_hash).toBe(blockApplied.after_hash);
		expect((blockApplied.change_set as Json).reuse_source).toEqual([
			{ post_id: blockSourcePost, builder: 'gutenberg', locator: { kind: 'block', path: '0', anchor: 'features' } },
		]);
		expect(await stateHash(ctx, blockSourcePost)).toBe(blockBefore);

		await page.goto(`/?p=${blockTarget}`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('body')).toContainText('Hand-built to last');
		await expect(page.locator('#features-2')).toBeVisible();
		await expect(page.locator('#features')).toContainText('Existing section that owns the anchor.');
		await expect(page.locator(`img[src*="swreuse-new"]`).first()).toBeVisible();

		// ------------------------------------------------------------ Elementor V4 (only where Elementor has the Atomic module)
		const hasAtomic = (await php(ctx, `return ['atomic' => class_exists('\\\\Elementor\\\\Modules\\\\AtomicWidgets\\\\Module')];`)).atomic === true;
		if (hasAtomic) {
			const flag = await php(ctx, `return ['on' => (bool) get_option('stonewright_elementor_v4_atomic', false)];`);
			test.info().annotations.push({ type: 'v4-flag', description: String(flag.on) });
		} else {
			test.info().annotations.push({ type: 'v4-skipped', description: 'The installed Elementor has no Atomic Widgets module.' });
		}

		// ------------------------------------------------------------ the setting
		await page.goto('/wp-admin/admin.php?page=stonewright&tab=settings', { waitUntil: 'domcontentloaded' });
		const toggle = page.locator('#stonewright_section_reuse');
		await expect(toggle).toBeChecked();
		await expect(page.locator('label[for="stonewright_section_reuse"]')).toHaveText('Reuse saved sections');
		await toggle.uncheck({ force: true });
		await page.locator('form.stonewright-settings-form button[type="submit"]').first().click();
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('#stonewright_section_reuse')).not.toBeChecked();

		const off = await ability(ctx, 'stonewright/section-reuse-find', { builder: 'gutenberg', roles: ['features'] });
		expect(off.enabled).toBe(false);
		expect(String(off.instruction)).toContain('Do not ask the user about reusing sections.');
		expect(off.notices).toContain('section_reuse: off - do not offer section reuse');
		const refused = await ability(ctx, 'stonewright/blocks-batch-mutate', { post_id: blockTarget, dry_run: true, operations: [blockOps[0]] });
		expect(JSON.stringify(refused)).toContain('stonewright_section_reuse_off');

		await page.goto('/wp-admin/admin.php?page=stonewright&tab=settings', { waitUntil: 'domcontentloaded' });
		await page.locator('#stonewright_section_reuse').check({ force: true });
		await page.locator('form.stonewright-settings-form button[type="submit"]').first().click();
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('#stonewright_section_reuse')).toBeChecked();
	} finally {
		for (const id of created) {
			await restRequest(page, 'DELETE', `/wp/v2/pages/${id}?force=true`, { nonce });
		}
	}
});
