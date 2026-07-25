import { type Page, type Route } from '@playwright/test';

/**
 * Design Studio e2e fixtures and REST stubbing.
 *
 * Presentation states are driven by stubbing the Design Studio REST routes.
 * A page that only renders what the server sent should be provable without
 * having to manufacture eight different databases, and states like "the kit
 * moved under the plan" cannot be created on a stock wp-env at all. The write
 * path is exercised separately against the live routes in design-studio.spec.ts.
 */

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';
export const STUDIO_URL = '/wp-admin/admin.php?page=stonewright-design-studio';

export async function login(page: Page): Promise<void> {
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

/* -------------------------------------------------------------------------- */
/* Fixtures                                                                    */
/* -------------------------------------------------------------------------- */

type DirectionRow = {
	id: number;
	slug: string;
	name: string;
	status: string;
	revision: number;
	contract_hash: string;
	source_type: string;
	ready: boolean;
	sync_ready: boolean;
	issue_count: number;
	updated_at: string;
	active: boolean;
};

export type StudioFixture = {
	directions: DirectionRow[];
	activeId: number;
	detail?: Record<string, { direction: unknown; versions: unknown[] }>;
	quality?: unknown;
	syncPlan?: unknown;
};

function row(overrides: Partial<DirectionRow> = {}): DirectionRow {
	return {
		id: 11,
		slug: 'quarry',
		name: 'Quarry',
		status: 'published',
		revision: 4,
		contract_hash: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
		source_type: 'manual',
		ready: true,
		sync_ready: true,
		issue_count: 0,
		updated_at: '2026-07-24 10:15:00',
		active: true,
		...overrides,
	};
}

export function contract(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		schema_version: '1.0',
		identity: { name: 'Quarry', summary: 'Quiet stone surfaces, one accent, generous rhythm.' },
		tokens: {
			colors: {
				surface: '#f7f6f3',
				ink: '#1d1c1a',
				accent: '#8a5a2b',
				muted: '#6b6862',
			},
			// Typography entries are maps of CSS properties, not bare sizes —
			// the write-path test posts this contract to the real validator.
			typography: {
				body: { family: 'Inter', size: '16px', line_height: 1.5 },
				lead: { family: 'Inter', size: '20px', line_height: 1.4 },
				display: { family: 'Inter', size: '32px', line_height: 1.15, weight: 600 },
			},
			spacing: { xs: '4px', sm: '8px', md: '16px', lg: '32px' },
			radii: { sm: '4px', md: '10px' },
			elevation: {},
			motion: {},
		},
		components: {},
		dials: { variance: 25, density: 40, motion: 15 },
		guidance: {
			do: ['Lead with one accent colour.', 'Keep body copy at 16px or larger.'],
			avoid: ['Gradient text.', 'More than two type families.'],
		},
		// Provenance is keyed by contract path, and each entry names a source
		// and a reference.
		provenance: { 'tokens.colors': { source: 'elementor-kit', reference: 'kit:42' } },
		waivers: [],
		readiness: { ready: true, sync_ready: true, issues: [] },
		...overrides,
	};
}

const FAILING_REPORT = {
	report_id: 'qr-2026-07-24-01',
	schema_version: '1.0',
	status: 'fail',
	coverage: {
		checked: ['contrast.text', 'target.size', 'overflow.horizontal', 'token.spacing'],
		not_checked: ['state.focus', 'state.hover'],
		not_checked_rules: ['state.focus', 'state.hover'],
	},
	findings: [
		{
			rule_id: 'contrast.text',
			severity: 'error',
			viewport: 'mobile',
			element_ref: 'hero > h1',
			evidence: { ratio: 3.02, required: 4.5, text_color: '#8a8a8a', background_color: '#ffffff' },
			repair_hint: 'Raise the heading colour to at least 4.5:1 against the hero surface.',
			waived: false,
			waiver_reason: '',
		},
		{
			rule_id: 'target.size',
			severity: 'error',
			viewport: 'mobile',
			element_ref: 'hero > a.cta',
			evidence: { width: 20, height: 20, minimum: 24 },
			repair_hint: 'Give the call to action at least a 24 by 24 pixel hit area.',
			waived: false,
			waiver_reason: '',
		},
		{
			rule_id: 'token.spacing',
			severity: 'warning',
			viewport: 'desktop',
			element_ref: 'section.features',
			evidence: { observed: 27, nearest: 32, scale: [4, 8, 16, 32] },
			repair_hint: 'Snap the section padding to the 32px step.',
			waived: false,
			waiver_reason: '',
		},
	],
	truncated_findings: 0,
	direction_revision: 4,
	direction_hash: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
	render_hash: '99887766554433221100ffeeddccbbaa',
};

const INCOMPLETE_REPORT = {
	report_id: 'qr-2026-07-24-02',
	schema_version: '1.0',
	status: 'not_checked',
	coverage: {
		checked: [],
		not_checked: ['contrast.text', 'contrast.focus', 'target.size', 'token.typography'],
		not_checked_rules: ['contrast.text', 'contrast.focus', 'target.size', 'token.typography'],
	},
	findings: [],
	truncated_findings: 0,
	direction_revision: 4,
	direction_hash: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
	render_hash: '0011223344556677889900aabbccddee',
};

const CONFLICTED_PLAN = {
	ok: true,
	id: 11,
	slug: 'quarry',
	kit_id: 42,
	contract_hash: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
	base_hash: 'feedfacecafebeef00112233445566778899aabbccddeeff',
	operations: [{ path: 'system_colors.primary', from: '#111111', to: '#8a5a2b' }],
	warnings: ['The kit defines two custom colours the direction does not name.'],
	blocked: [
		{
			path: 'custom_typography.0',
			reason: 'The kit typography entry changed after this plan was built. Re-plan before applying.',
		},
	],
	ready_to_apply: false,
};

/**
 * Serves the Design Studio routes from a fixture. Matching works for pretty
 * permalinks and for the plain-permalink `?rest_route=` form wp-env uses.
 */
export async function stubStudio(page: Page, fixture: StudioFixture): Promise<void> {
	await page.route(
		// REST traffic only — the admin page URL also carries the slug.
		(url) =>
			url.href.includes('design-studio') &&
			(url.pathname.includes('/wp-json/') || url.searchParams.has('rest_route')),
		async (route: Route) => {
			const url = new URL(route.request().url());
			const target = url.searchParams.get('rest_route') ?? url.pathname;
			const method = route.request().method();
			const json = (body: unknown) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(body),
				});

			if (target.includes('/quality')) {
				return json(
					fixture.quality ?? {
						ok: true,
						post_id: Number(url.searchParams.get('post_id') ?? 0),
						count: 0,
						reports: [],
					},
				);
			}

			const detailMatch = target.match(/\/directions\/(\d+)(\/([a-z-]+))?$/);
			if (detailMatch) {
				const id = detailMatch[1];
				const action = detailMatch[3] ?? '';

				if (action === 'sync-plan') {
					return json(fixture.syncPlan ?? CONFLICTED_PLAN);
				}
				if (action === 'activate') {
					return json({
						ok: true,
						id: Number(id),
						active_id: Number(id),
						previous_active_id: fixture.activeId,
						revision: 4,
						effect_verified: true,
					});
				}
				if (action === 'restore') {
					return json({ ok: true, id: Number(id), revision: 5, restored_revision: 2 });
				}

				const detail = fixture.detail?.[id];
				return json(
					detail ?? {
						ok: true,
						direction: { ...row({ id: Number(id) }), contract: contract() },
						versions: [],
					},
				);
			}

			if (method === 'POST') {
				return json({
					ok: true,
					id: 11,
					slug: 'quarry',
					status: 'draft',
					revision: 5,
					contract_hash: 'bb00bb00bb00bb00bb00bb00bb00bb00',
					effect_verified: true,
				});
			}

			return json({
				ok: true,
				count: fixture.directions.length,
				active_id: fixture.activeId,
				directions: fixture.directions,
			});
		},
	);
}

export const EMPTY: StudioFixture = { directions: [], activeId: 0 };

export const ACTIVE_SYNCED: StudioFixture = {
	directions: [row()],
	activeId: 11,
	detail: {
		'11': { direction: { ...row(), contract: contract() }, versions: [] },
	},
	quality: { ok: true, post_id: 7, count: 1, reports: [FAILING_REPORT] },
};

export const READY_INACTIVE: StudioFixture = {
	directions: [
		row({ id: 12, slug: 'foundry', name: 'Foundry', active: false, revision: 1 }),
		row(),
	],
	activeId: 11,
	detail: {
		'12': {
			direction: { ...row({ id: 12, slug: 'foundry', name: 'Foundry', active: false }), contract: contract() },
			versions: [],
		},
	},
};

export const DRAFT_WITH_ISSUES: StudioFixture = {
	directions: [
		row({
			id: 13,
			slug: 'draft-quarry',
			name: 'Quarry draft',
			status: 'draft',
			ready: false,
			sync_ready: false,
			issue_count: 3,
			active: false,
			revision: 1,
		}),
	],
	activeId: 0,
	detail: {
		'13': {
			direction: {
				...row({ id: 13, slug: 'draft-quarry', name: 'Quarry draft', ready: false, active: false }),
				contract: contract({
					tokens: { colors: {}, typography: {}, spacing: {}, radii: {}, elevation: {}, motion: {} },
					readiness: {
						ready: false,
						sync_ready: false,
						issues: ['tokens.colors is empty', 'tokens.typography is empty', 'guidance.do is empty'],
					},
				}),
			},
			versions: [],
		},
	},
};

export const REVISION_HISTORY: StudioFixture = {
	directions: [row()],
	activeId: 11,
	detail: {
		'11': {
			direction: { ...row(), contract: contract() },
			versions: [
				{
					revision: 4,
					status: 'published',
					contract_hash: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
					source_type: 'manual',
					created_at: '2026-07-24 10:15:00',
				},
				{
					revision: 3,
					status: 'published',
					contract_hash: 'c0ffee00c0ffee00c0ffee00c0ffee00',
					source_type: 'elementor',
					created_at: '2026-07-22 09:02:00',
				},
				{
					revision: 2,
					status: 'draft',
					contract_hash: 'deadbeefdeadbeefdeadbeefdeadbeef',
					source_type: 'import',
					created_at: '2026-07-20 16:41:00',
				},
			],
		},
	},
};

export const INCOMPLETE_EVIDENCE: StudioFixture = {
	...ACTIVE_SYNCED,
	quality: { ok: true, post_id: 7, count: 1, reports: [INCOMPLETE_REPORT] },
};
