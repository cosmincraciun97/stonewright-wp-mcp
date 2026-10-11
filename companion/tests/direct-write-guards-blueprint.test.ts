import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const serialized = vi.hoisted(() => ({ markup: '' }));

vi.mock('../src/direct/gutenberg-serializer.js', async (importOriginal) => {
	const original = await importOriginal<typeof import('../src/direct/gutenberg-serializer.js')>();
	return { ...original, serializeDesignSpec: () => serialized.markup };
});

const { applyBlueprint } = await import('../src/direct/tools/blueprints.js');

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('blueprint-apply custom-code boundary', () => {
	let stateDir: string;
	const original = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-bp-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});

	afterEach(() => {
		if (original === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = original;
		rmSync(stateDir, { recursive: true, force: true });
	});

	function client() {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{"id":9}', { status: 200, headers: { 'content-type': 'application/json' } })));
		return { fetchImpl, client: new WpRestClient(site, { fetchImpl }) };
	}

	it.each([
		['script', '<!-- wp:html --><script>x()</script><!-- /wp:html -->', 'script'],
		['style', '<style>a{}</style>', 'style'],
		['iframe', '<iframe src="https://example.com"></iframe>', 'iframe'],
		['inline handler', '<img src="a.png" onerror="x()">', 'event_handler'],
		['javascript URL', '<a href="&#106;avascript:x()">x</a>', 'javascript_url'],
	])('refuses a blueprint whose markup carries a %s, over an existing page, and sends nothing', async (_label, markup, kind) => {
		serialized.markup = markup;
		const { fetchImpl, client: wp } = client();
		const result = await applyBlueprint(wp, { id: 'agency', post_id: 9 }, { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' });
		expect(result).toMatchObject({
			supported: false,
			approval_required: true,
			agent_must_stop: true,
			error: 'custom_code_approval_required',
			tool: 'stonewright-blueprint-apply',
			written: false,
		});
		expect((result['findings'] as Array<{ kind: string }>).map((f) => f.kind)).toContain(kind);
		expect(fetchImpl).not.toHaveBeenCalled();
		expect(existsSync(join(stateDir, 'backups'))).toBe(false);
	});

	it('refuses the create path as well', async () => {
		serialized.markup = '<script>x()</script>';
		const { fetchImpl, client: wp } = client();
		const result = await applyBlueprint(wp, { id: 'agency' }, { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' });
		expect(result).toMatchObject({ approval_required: true, agent_must_stop: true, written: false });
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('refuses code in a caller-supplied title', async () => {
		serialized.markup = '<!-- wp:paragraph --><p>ok</p><!-- /wp:paragraph -->';
		const { fetchImpl, client: wp } = client();
		const result = await applyBlueprint(wp, { id: 'agency', title: '<script>x()</script>' }, { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' });
		expect(result).toMatchObject({ approval_required: true, written: false });
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('writes ordinary markup', async () => {
		serialized.markup = '<!-- wp:paragraph --><p>See <a href="https://example.com">this</a>.</p><!-- /wp:paragraph -->';
		const { fetchImpl, client: wp } = client();
		const result = await applyBlueprint(wp, { id: 'agency' }, { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' });
		expect(result).toMatchObject({ ok: true, engine: 'gutenberg' });
		expect(fetchImpl).toHaveBeenCalledOnce();
	});
});
