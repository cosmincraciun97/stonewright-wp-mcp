import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers/design-studio';
import { restGet, restPost, wpRestNonce } from './helpers/wp-rest';

/**
 * Visual Workspace — adapter detection, the write ladder, and the drawer.
 *
 * The workspace host page is not an editor screen, so the editor runtimes are
 * installed with an init script before the bundle boots. They are the same
 * globals the adapters read in a real editor (`window.elementor` + `window.$e`,
 * or `window.wp.blocks` + `window.wp.data`), which is what makes detection
 * provable here; the Gutenberg double is complete enough for the read and the
 * write to run through the real adapter, schema validation, and readback.
 *
 * Nothing here asserts that a design looks right. It asserts the ladder holds:
 * no write without a read, a preview, and an explicit allow, and no "complete"
 * without evidence.
 */

const WORKSPACE_URL = '/wp-admin/admin.php?page=stonewright-visual-workspace';
const SLUG = 'sw-visual-workspace-target';
const WIDE = 'desktop-1440-light';
const NARROW = ['desktop-1024-light', 'tablet-782-light', 'mobile-390-light'];

/** A report whose findings are observations that passed, so evidence exists. */
const VERIFIED_REPORT = {
	report_id: 'qr-visual-01',
	schema_version: '1.0',
	status: 'pass',
	coverage: { checked: ['contrast.text'], not_checked: [], not_checked_rules: [] },
	findings: [
		{
			rule_id: 'contrast.text',
			severity: 'pass',
			viewport: 'desktop',
			element_ref: 'hero > h1',
			evidence: { ratio: 7.1, required: 4.5 },
			waived: false,
		},
	],
	truncated_findings: 0,
	direction_revision: 4,
	direction_hash: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
	render_hash: '99887766554433221100ffeeddccbbaa',
};

const FAILING_REPORT = {
	...VERIFIED_REPORT,
	report_id: 'qr-visual-02',
	status: 'fail',
	coverage: {
		checked: ['contrast.text', 'target.size'],
		not_checked: ['state.focus'],
		not_checked_rules: ['state.focus'],
	},
	findings: [
		{
			rule_id: 'contrast.text',
			severity: 'error',
			viewport: 'mobile',
			element_ref: 'hero > h1',
			evidence: { ratio: 3.02, required: 4.5 },
			repair_hint: 'Raise the heading colour.',
			waived: false,
		},
	],
};

/** Serves the read-only quality route the workspace reads its evidence from. */
async function stubQuality(page: Page, reports: unknown[]): Promise<void> {
	await page.route(
		(url) =>
			url.href.includes('design-studio/quality') &&
			(url.pathname.includes('/wp-json/') || url.searchParams.has('rest_route')),
		async (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, count: reports.length, reports }),
			}),
	);
}

/**
 * A Gutenberg double: one registered paragraph type, one block, and the two
 * stores the runtime talks to. Writes land in the same object the reads come
 * from, so the adapter's readback is a real check rather than a formality.
 *
 * Unknown stores resolve to no-op members instead of `undefined`, so an
 * unrelated admin script asking for `core/notices` cannot break the page.
 */
async function installGutenberg(page: Page): Promise<void> {
	await page.addInitScript(() => {
		type Block = {
			clientId: string;
			name: string;
			attributes: Record<string, unknown>;
			innerBlocks: Block[];
		};

		const blocks: Block[] = [
			{
				clientId: 'block-1',
				name: 'core/paragraph',
				attributes: { content: 'Original copy', fontSize: 'medium' },
				innerBlocks: [],
			},
		];
		const applied: Array<Record<string, unknown>> = [];
		const paragraph = {
			name: 'core/paragraph',
			title: 'Paragraph',
			category: 'text',
			attributes: { content: { type: 'string' }, fontSize: { type: 'string' } },
			supports: {},
		};

		const blocksApi = {
			getBlockTypes: () => [paragraph],
			getBlockType: (name: string) => (name === paragraph.name ? paragraph : undefined),
			createBlock: (name: string, attributes: Record<string, unknown> = {}) => ({
				clientId: `block-${blocks.length + 1}`,
				name,
				attributes,
				innerBlocks: [],
			}),
			serialize: () => '<!-- wp:paragraph --><p>Original copy</p><!-- /wp:paragraph -->',
		};

		const stores: Record<string, Record<string, unknown>> = {
			'select:core/editor': { getCurrentPostId: () => 1, isEditedPostDirty: () => false },
			'select:core/block-editor': {
				getBlocks: () => blocks,
				getBlock: (clientId: string) => blocks.find((item) => item.clientId === clientId) || null,
				getBlockRootClientId: () => '',
				getBlockIndex: () => 0,
			},
			'dispatch:core/editor': { savePost: async () => undefined },
			'dispatch:core/block-editor': {
				insertBlock: (block: Block) => blocks.push(block),
				updateBlockAttributes: (clientId: string, attributes: Record<string, unknown>) => {
					const block = blocks.find((item) => item.clientId === clientId);
					if (block) {
						Object.assign(block.attributes, attributes);
						applied.push({ clientId, attributes });
					}
				},
				moveBlockToPosition: () => undefined,
				removeBlock: () => undefined,
				undo: () => undefined,
				redo: () => undefined,
			},
		};

		const inert = () => new Proxy({}, { get: () => () => undefined });
		const dataApi = {
			select: (store: string) => stores[`select:${store}`] || inert(),
			dispatch: (store: string) => stores[`dispatch:${store}`] || inert(),
		};

		const scope = window as unknown as Record<string, unknown>;
		const wp = (scope.wp as Record<string, unknown>) || {};
		// Locked so a late admin script cannot replace the doubles mid-test.
		Object.defineProperty(wp, 'blocks', { get: () => blocksApi, set: () => undefined });
		Object.defineProperty(wp, 'data', { get: () => dataApi, set: () => undefined });
		scope.wp = wp;
		scope.swAppliedWrites = applied;
	});
}

/** Enough Elementor for detection and adapter construction, nothing more. */
async function installElementor(page: Page, atomic: boolean): Promise<void> {
	await page.addInitScript((isAtomic: boolean) => {
		const container = { id: 'document', type: 'document', children: [] as unknown[] };
		const scope = window as unknown as Record<string, unknown>;
		scope.elementor = {
			config: { version: isAtomic ? '4.0.0' : '3.25.0', controls: {}, elements: {} },
			widgetsCache: isAtomic
				? {
						'e-paragraph': {
							name: 'e-paragraph',
							title: 'Paragraph',
							atomic: true,
							atomic_props_schema: {},
							controls: {},
						},
					}
				: { heading: { name: 'heading', title: 'Heading', controls: {} } },
			getContainer: () => container,
			getPreviewContainer: () => container,
			documents: { getCurrent: () => ({ id: 1, container }) },
		};
		scope.$e = {
			run: async () => ({}),
			commands: { getAll: () => [] },
			components: { get: () => ({ isEditorChanged: () => false }) },
		};
	}, atomic);
}

/** Creates (or reuses) the post the workspace is opened against. */
async function targetPostId(page: Page): Promise<number> {
	const nonce = await wpRestNonce(page);
	const existing = await restGet(
		page,
		`/wp/v2/pages?slug=${encodeURIComponent(SLUG)}&status=publish,draft`,
		nonce,
	);
	if (existing.ok && Array.isArray(existing.body) && existing.body.length > 0) {
		return Number((existing.body[0] as { id: number }).id);
	}

	const created = await restPost(
		page,
		'/wp/v2/pages',
		{
			status: 'publish',
			slug: SLUG,
			title: 'Visual workspace target',
			content: '<p>Original copy</p>',
		},
		nonce,
	);
	if (!created.ok) {
		throw new Error(`Could not seed the workspace target page: HTTP ${created.status}`);
	}
	return Number((created.body as { id?: number }).id || 0);
}

async function openWorkspace(page: Page, postId: number): Promise<void> {
	await page.goto(`${WORKSPACE_URL}&post_id=${postId}`, { waitUntil: 'domcontentloaded' });
	await expect(page.locator('[data-sw-visual-workspace]')).toBeVisible();
	// The bundle stamps state onto the root as soon as it paints.
	await expect(page.locator('[data-sw-visual-workspace][data-sw-state]')).toHaveCount(1);
}

/**
 * Opens the workspace and waits for the adapter to attach.
 *
 * Connecting is asynchronous, so driving the controller straight after the
 * first paint would race the boot and read an `idle` workspace.
 */
async function openConnected(page: Page, postId: number): Promise<void> {
	await openWorkspace(page, postId);
	await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
		'data-sw-state',
		'connected',
	);
}

/* -------------------------------------------------------------------------- */
/* Driving the controller                                                     */
/* -------------------------------------------------------------------------- */

type Step = 'read' | 'preview' | 'confirm' | 'allow' | 'deny';

const OPERATION = {
	tool: 'update_block',
	target: 'core/paragraph block-1',
	summary: 'Set the paragraph copy from the direction voice',
	before: 'Original copy',
	after: 'Direction copy',
	args: {
		client_id: 'block-1',
		attributes: { content: 'Direction copy' },
		idempotency_key: 'e2e-visual-1',
		confirm_write: true,
	},
};

interface DriveResult {
	state: string;
	errors: string[];
}

/**
 * Runs named steps against the controller the bundle exposed.
 *
 * Steps are an enum rather than a script string: the page must never be handed
 * arbitrary code to evaluate, and a fixed set is enough to walk the ladder.
 */
async function drive(page: Page, steps: Step[], stopOnError = true): Promise<DriveResult> {
	return page.evaluate(
		async (input: { steps: Step[]; operation: typeof OPERATION; stopOnError: boolean }) => {
			const controller = (
				window as unknown as {
					stonewrightVisual?: {
						getState: () => string;
						read: () => Promise<void>;
						preview: (operations: unknown[]) => void;
						requestConfirmation: () => void;
						decide: (decision: 'allow' | 'deny') => Promise<void>;
					};
				}
			).stonewrightVisual;
			if (!controller) {
				throw new Error('The workspace controller was not exposed on window.');
			}

			const errors: string[] = [];
			for (const step of input.steps) {
				try {
					if (step === 'read') {
						await controller.read();
					} else if (step === 'preview') {
						controller.preview([input.operation]);
					} else if (step === 'confirm') {
						controller.requestConfirmation();
					} else {
						await controller.decide(step);
					}
				} catch (cause) {
					errors.push(cause instanceof Error ? cause.message : String(cause));
					if (input.stopOnError) {
						break;
					}
				}
			}

			return { state: controller.getState(), errors };
		},
		{ steps, operation: OPERATION, stopOnError },
	);
}

async function appliedWrites(page: Page): Promise<Array<Record<string, unknown>>> {
	return page.evaluate(
		() => (window as unknown as { swAppliedWrites: Array<Record<string, unknown>> }).swAppliedWrites,
	);
}

/* -------------------------------------------------------------------------- */
/* Adapter detection                                                          */
/* -------------------------------------------------------------------------- */

test.describe('Visual Workspace adapters', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== WIDE, 'Adapter detection is proven once.');
		await login(page);
		await stubQuality(page, []);
	});

	test('reports that no editor is attached instead of pretending one is', async ({ page }) => {
		const postId = await targetPostId(page);
		await openWorkspace(page, postId);

		const adapter = page.locator('[data-sw-visual-adapter] [data-sw-adapter]');
		await expect(adapter).toHaveAttribute('data-sw-adapter', 'none');
		await expect(adapter).toContainText('No supported editor was found on this page.');
		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-state',
			'failed',
		);
	});

	test('detects a classic Elementor runtime as V3', async ({ page }) => {
		const postId = await targetPostId(page);
		await installElementor(page, false);
		await openWorkspace(page, postId);

		const adapter = page.locator('[data-sw-visual-adapter] [data-sw-adapter]');
		await expect(adapter).toHaveAttribute('data-sw-adapter', 'elementor-v3');
		await expect(adapter).toContainText('Elementor V3');
	});

	test('detects an atomic Elementor runtime as V4', async ({ page }) => {
		const postId = await targetPostId(page);
		await installElementor(page, true);
		await openWorkspace(page, postId);

		const adapter = page.locator('[data-sw-visual-adapter] [data-sw-adapter]');
		await expect(adapter).toHaveAttribute('data-sw-adapter', 'elementor-v4');
		await expect(adapter).toContainText('Elementor V4 Atomic');
	});

	test('detects the block editor and reports it connected', async ({ page }) => {
		const postId = await targetPostId(page);
		await installGutenberg(page);
		await openWorkspace(page, postId);

		const adapter = page.locator('[data-sw-visual-adapter] [data-sw-adapter]');
		await expect(adapter).toHaveAttribute('data-sw-adapter', 'gutenberg');
		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-state',
			'connected',
		);
	});
});

/* -------------------------------------------------------------------------- */
/* The write ladder                                                           */
/* -------------------------------------------------------------------------- */

test.describe('Visual Workspace write ladder', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== WIDE, 'The ladder is proven once.');
		await login(page);
		await installGutenberg(page);
	});

	test('refuses to preview, confirm, or apply out of order', async ({ page }) => {
		await stubQuality(page, [VERIFIED_REPORT]);
		const postId = await targetPostId(page);
		await openConnected(page, postId);

		const result = await drive(page, ['preview', 'confirm', 'allow'], false);

		expect(result.errors).toHaveLength(3);
		expect(result.errors[0]).toMatch(/must read the page before it can preview/i);
		expect(result.errors[1]).toMatch(/nothing to confirm/i);
		expect(result.errors[2]).toMatch(/no confirmation is pending/i);
		expect(result.state).toBe('connected');
		expect(await appliedWrites(page)).toHaveLength(0);
	});

	test('previews the diff, applies on allow, and completes on evidence', async ({ page }) => {
		await stubQuality(page, [VERIFIED_REPORT]);
		const postId = await targetPostId(page);
		await openConnected(page, postId);

		const previewed = await drive(page, ['read', 'preview']);
		expect(previewed.errors).toEqual([]);
		expect(previewed.state).toBe('previewing');

		const confirm = page.locator('.sw-visual-confirm');
		await expect(confirm.getByRole('heading')).toContainText('Apply 1 change(s)');
		await expect(confirm.locator('.sw-visual-confirm__change')).toContainText(
			'Original copy → Direction copy',
		);
		const apply = confirm.getByRole('button', { name: 'Apply changes' });
		await expect(apply).toBeEnabled();

		await drive(page, ['confirm']);
		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-state',
			'awaiting_confirmation',
		);

		await apply.click();

		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-state',
			'complete',
		);
		const applied = await appliedWrites(page);
		expect(applied).toHaveLength(1);
		expect(applied[0]).toMatchObject({ clientId: 'block-1' });
		await expect(page.locator('.sw-visual-evidence')).toHaveAttribute(
			'data-sw-evidence',
			'verified',
		);
	});

	test('cancelling a confirmation writes nothing and returns to connected', async ({ page }) => {
		await stubQuality(page, [VERIFIED_REPORT]);
		const postId = await targetPostId(page);
		await openConnected(page, postId);

		await drive(page, ['read', 'preview', 'confirm']);
		await page.locator('.sw-visual-confirm').getByRole('button', { name: 'Cancel' }).click();

		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-state',
			'connected',
		);
		await expect(page.locator('.sw-visual-confirm')).toHaveCount(0);
		expect(await appliedWrites(page)).toHaveLength(0);
	});

	test('an applied change with no evidence is reported unverified, not complete', async ({
		page,
	}) => {
		await stubQuality(page, []);
		const postId = await targetPostId(page);
		await openConnected(page, postId);

		await drive(page, ['read', 'preview', 'confirm']);
		await page.locator('.sw-visual-confirm').getByRole('button', { name: 'Apply changes' }).click();

		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-state',
			'failed',
		);
		await expect(page.locator('.sw-visual__status')).toContainText('unverified');
		// The write did happen; what failed is any claim that it is correct.
		expect(await appliedWrites(page)).toHaveLength(1);
	});
});

/* -------------------------------------------------------------------------- */
/* Evidence, viewports, direction                                             */
/* -------------------------------------------------------------------------- */

test.describe('Visual Workspace evidence and controls', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== WIDE, 'Control behaviour is proven once.');
		await login(page);
		await installGutenberg(page);
		await stubQuality(page, [FAILING_REPORT]);
	});

	test('shows the stored quality findings, including what was never checked', async ({ page }) => {
		const postId = await targetPostId(page);
		await openWorkspace(page, postId);

		const evidence = page.locator('.sw-visual-evidence');
		await expect(evidence).toHaveAttribute('data-sw-evidence', 'unverified');
		await expect(evidence.locator('[data-sw-evidence-status="fail"]')).toHaveCount(1);
		await expect(evidence.locator('[data-sw-evidence-status="not_checked"]')).toHaveCount(1);
		await expect(evidence.locator('.sw-visual-evidence__notice')).toContainText('unverified');
	});

	test('viewport controls are a keyboard-operable pressed-state group', async ({ page }) => {
		const postId = await targetPostId(page);
		await openWorkspace(page, postId);

		const group = page.getByRole('group', { name: 'Viewport' });
		await expect(group.getByRole('button', { name: 'Desktop' })).toHaveAttribute(
			'aria-pressed',
			'true',
		);

		const mobile = group.getByRole('button', { name: 'Mobile' });
		await mobile.focus();
		await page.keyboard.press('Enter');

		await expect(mobile).toHaveAttribute('aria-pressed', 'true');
		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-viewport',
			'mobile',
		);
	});

	test('names the active direction without shipping the contract to the browser', async ({
		page,
	}) => {
		const postId = await targetPostId(page);
		await openWorkspace(page, postId);

		await expect(page.locator('.sw-visual__direction')).toContainText('Direction:');
		const keys = await page.evaluate(() => {
			const payload = (
				window as unknown as { stonewrightVisualWorkspace?: { direction?: unknown } }
			).stonewrightVisualWorkspace?.direction;
			return payload !== null && typeof payload === 'object'
				? Object.keys(payload as Record<string, unknown>)
				: [];
		});
		expect(keys).not.toContain('contract');
		expect(keys).not.toContain('tokens');
	});
});

/* -------------------------------------------------------------------------- */
/* Narrow-screen drawer                                                       */
/* -------------------------------------------------------------------------- */

test.describe('Visual Workspace inspector drawer', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(!NARROW.includes(testInfo.project.name), 'Drawer behaviour needs a narrow viewport.');
		await login(page);
		await installGutenberg(page);
		await stubQuality(page, []);
	});

	test('opens from the toggle, closes on Escape, and returns focus', async ({ page }) => {
		const postId = await targetPostId(page);
		await openWorkspace(page, postId);

		const toggle = page.locator('[data-sw-visual-inspector-toggle]');
		const inspector = page.locator('#sw-visual-inspector');

		await expect(toggle).toBeVisible();
		await expect(toggle).toHaveAttribute('aria-expanded', 'false');
		await expect(inspector).toBeHidden();

		await toggle.click();
		await expect(toggle).toHaveAttribute('aria-expanded', 'true');
		await expect(inspector).toBeVisible();
		await expect(inspector).toHaveAttribute('aria-modal', 'true');

		await page.keyboard.press('Escape');
		await expect(toggle).toHaveAttribute('aria-expanded', 'false');
		await expect(inspector).toBeHidden();
		await expect(toggle).toBeFocused();
	});

	test('keeps the workspace usable with motion reduced', async ({ page }) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		const postId = await targetPostId(page);
		await openWorkspace(page, postId);

		const toggle = page.locator('[data-sw-visual-inspector-toggle]');
		await toggle.click();
		await expect(page.locator('#sw-visual-inspector')).toBeVisible();
	});
});

/* -------------------------------------------------------------------------- */
/* Entry points                                                               */
/* -------------------------------------------------------------------------- */

test.describe('Visual Workspace entry points', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== WIDE, 'Entry behaviour is proven once.');
		await login(page);
	});

	test('asks for a post when the URL names none', async ({ page }) => {
		await page.goto(WORKSPACE_URL, { waitUntil: 'domcontentloaded' });

		await expect(page.locator('[data-sw-visual-picker]')).toBeVisible();
		await expect(page.locator('[data-sw-visual-workspace]')).toHaveCount(0);
		await expect(page.getByRole('button', { name: 'Open workspace' })).toBeVisible();
	});

	test('an unusable post id lands on the picker rather than a broken workspace', async ({
		page,
	}) => {
		await page.goto(`${WORKSPACE_URL}&post_id=not-a-number`, { waitUntil: 'domcontentloaded' });

		await expect(page.locator('[data-sw-visual-picker]')).toBeVisible();
	});

	test('the picker opens the workspace for the post it was given', async ({ page }) => {
		const postId = await targetPostId(page);
		await page.goto(WORKSPACE_URL, { waitUntil: 'domcontentloaded' });

		await page.locator('#sw-visual-post-id').fill(String(postId));
		await page.getByRole('button', { name: 'Open workspace' }).click();

		await expect(page.locator('[data-sw-visual-workspace]')).toHaveAttribute(
			'data-sw-post-id',
			String(postId),
		);
	});
});
