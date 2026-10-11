import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	attachVerification,
	compareSent,
	stateHash,
	verifyWrite,
} from '../src/direct/verify.js';
import { WpRestError } from '../src/direct/wp-rest-client.js';

type Row = Record<string, unknown>;

describe('stateHash', () => {
	it('ignores volatile fields and the rendered half of raw/rendered pairs', () => {
		const a = {
			id: 4,
			modified: '2026-10-01T00:00:00',
			title: { raw: 'T', rendered: 'T' },
			content: { raw: '<p>x</p>', rendered: '<p>x</p>\n', protected: false, block_version: 1 },
			_links: { self: [{ href: 'a' }] },
		};
		const b = {
			id: 4,
			modified: '2026-10-02T09:00:00',
			title: { raw: 'T', rendered: 'T!' },
			content: { raw: '<p>x</p>', rendered: '<p>changed</p>', protected: true, block_version: 2 },
			_links: {},
		};
		expect(stateHash(a)).toBe(stateHash(b));
		expect(stateHash(a)).toMatch(/^[0-9a-f]{64}$/);
	});

	it('keeps nested keys such as link and raw, which are content in theme.json and widgets', () => {
		const a = { id: 12, styles: { elements: { link: { color: { text: 'red' } } } }, instance: { raw: { title: 'a' } } };
		const b = { id: 12, styles: { elements: { link: { color: { text: 'blue' } } } }, instance: { raw: { title: 'a' } } };
		expect(stateHash(a)).not.toBe(stateHash(b));
		const c = { id: 12, styles: { elements: { link: { color: { text: 'red' } } } }, instance: { raw: { title: 'b' } } };
		expect(stateHash(a)).not.toBe(stateHash(c));
	});

	it('changes when a stored value changes and does not depend on key order', () => {
		expect(stateHash({ a: 1, b: { raw: 'x' } })).toBe(stateHash({ b: { raw: 'x' }, a: 1 }));
		expect(stateHash({ a: 1, b: { raw: 'x' } })).not.toBe(stateHash({ a: 1, b: { raw: 'y' } }));
	});
});

describe('compareSent', () => {
	it('compares raw fields to the sent string and ignores line-ending differences', () => {
		const out = compareSent(
			{ title: 'A', content: 'line1\r\nline2' },
			{ title: { raw: 'A', rendered: 'A' }, content: { raw: 'line1\nline2', rendered: '<p>x</p>' } },
		);
		expect(out).toEqual({ checked: ['title', 'content'], mismatched: [], skipped: [] });
	});

	it('flags a field the site changed or dropped', () => {
		const out = compareSent(
			{ title: 'A', content: '<p>a</p>', status: 'publish', slug: 'a' },
			{ title: { raw: 'A' }, content: { raw: '<p>a</p>' }, status: 'draft' },
		);
		expect(out.mismatched).toEqual(['status', 'slug']);
	});

	it('treats scalars loosely, arrays as sets and objects as subsets', () => {
		const out = compareSent(
			{ parent: 0, roles: ['b', 'a'], meta: { count: '5', note: 'x' }, settings: { color: { palette: [{ slug: 'a' }] } } },
			{ parent: '0', roles: ['a', 'b'], meta: { count: 5, note: 'x', other: 1 }, settings: { color: { palette: [{ slug: 'a' }], x: 1 } } },
		);
		expect(out.mismatched).toEqual([]);
	});

	it('reports a meta key the site did not store', () => {
		const out = compareSent({ meta: { kept: '1', dropped: '2' } }, { meta: { kept: '1' } });
		expect(out.mismatched).toEqual(['meta.dropped']);
	});

	it('compares plain ACF values and skips structured ones, which come back in the field return format', () => {
		const out = compareSent(
			{ acf: { subtitle: 'x', image: 12, gallery: [1, 2], link: { url: 'u' }, missing: 'z' } },
			{ acf: { subtitle: 'x', image: { id: 12, url: 'u' }, gallery: [{ id: 1 }], link: 'u' } },
		);
		expect(out.mismatched).toEqual(['acf.missing']);
		expect(out.skipped).toEqual(['acf.image', 'acf.gallery', 'acf.link']);
		const wrong = compareSent({ acf: { subtitle: 'x' } }, { acf: { subtitle: 'y' } });
		expect(wrong.mismatched).toEqual(['acf.subtitle']);
	});

	it('skips a field that the readback cannot show raw', () => {
		const out = compareSent({ content: 'x', title: 'T' }, { content: { rendered: '<p>x</p>' }, title: { raw: 'T' } });
		expect(out).toEqual({ checked: ['title'], mismatched: [], skipped: ['content'] });
	});
});

describe('verifyWrite', () => {
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];
	const site = { alias: 'local', url: 'http://example.test' };

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-verify-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});
	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		rmSync(stateDir, { recursive: true, force: true });
	});

	function rows(): Row[] {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Row);
	}

	const base = { site, tool: 'stonewright-content-update', resource: 'pages/4', resourceType: 'pages', readPath: '/wp/v2/pages/4' };

	it('verifies, hashes before and after, and writes a write row and a verifier row', async () => {
		const client = { get: () => Promise.resolve(({ id: 4, title: { raw: 'New' }, content: { raw: '<p>n</p>' } }) as never) };
		const out = await verifyWrite({ ...base, client, sent: { title: 'New', content: '<p>n</p>' }, beforeSha256: 'a'.repeat(64) });
		expect(out.status).toBe('verified');
		expect(out.mismatched_fields).toEqual([]);
		expect(out.before_sha256).toBe('a'.repeat(64));
		expect(out.after_sha256).toMatch(/^[0-9a-f]{64}$/);

		const [write, verifier] = rows();
		expect(write).toMatchObject({
			tool: 'stonewright-content-update',
			status: 'ok',
			event_type: 'direct_tool',
			resource_type: 'pages',
			before_sha256: 'a'.repeat(64),
			after_sha256: out.after_sha256,
			verification_status: 'verified',
			effect_verified: true,
			change_set_id: out.change_set_id,
		});
		expect(verifier).toMatchObject({
			tool: 'stonewright-content-update',
			status: 'ok',
			event_type: 'direct_verifier',
			verification_status: 'verified',
			effect_verified: true,
			change_set_id: out.change_set_id,
			resource_ref: 'pages/4',
			parent_request_id: write?.['event_id'],
		});
	});

	it('reports a mismatch as an error verifier row without a repair-path change set', async () => {
		const client = { get: () => Promise.resolve(({ id: 4, title: { raw: 'New' }, content: { raw: '<p>stripped</p>' } }) as never) };
		const out = await verifyWrite({ ...base, client, sent: { title: 'New', content: '<p>n</p><script>x</script>' } });
		expect(out.status).toBe('mismatch');
		expect(out.mismatched_fields).toEqual(['content']);
		const [write, verifier] = rows();
		expect(write).toMatchObject({ status: 'ok', verification_status: 'mismatch', effect_verified: false, change_set_id: out.change_set_id });
		expect(verifier).toMatchObject({
			status: 'error',
			event_type: 'direct_verifier',
			code: 'verification_mismatch',
			verification_status: 'mismatch',
			effect_verified: false,
			change_set_id: null,
			correlation_id: out.change_set_id,
		});
	});

	it('records not_checked when the readback fails', async () => {
		const client = {
			get: () => Promise.reject(new Error('boom')),
		};
		const out = await verifyWrite({ ...base, client, sent: { title: 'x' } });
		expect(out.status).toBe('not_checked');
		expect(out.reason).toMatch(/readback_failed/);
		expect(out.after_sha256).toBeNull();
		const [write, verifier] = rows();
		expect(write).toMatchObject({ status: 'ok', verification_status: 'not_checked', effect_verified: false });
		expect(verifier).toMatchObject({ status: 'ok', event_type: 'direct_verifier', verification_status: 'not_checked' });
	});

	it('records not_checked when nothing sent can be compared', async () => {
		const client = { get: () => Promise.resolve(({ id: 4 }) as never) };
		const out = await verifyWrite({ ...base, client, sent: {} });
		expect(out.status).toBe('not_checked');
		expect(out.reason).toBe('no_comparable_fields');
	});

	it('verifies a delete when the resource is gone and flags it when it is not', async () => {
		const gone = {
			get: () => Promise.reject(new WpRestError({ code: 'rest_post_invalid_id', status: 404, message: 'x', hint: 'x' })),
		};
		expect((await verifyWrite({ ...base, client: gone, expect: 'absent' })).status).toBe('verified');
		const still = { get: () => Promise.resolve(({ id: 4 }) as never) };
		const out = await verifyWrite({ ...base, client: still, expect: 'absent' });
		expect(out.status).toBe('mismatch');
		expect(out.mismatched_fields).toEqual(['resource_still_present']);
	});
});

describe('attachVerification', () => {
	const verified = {
		status: 'verified' as const,
		checked_fields: ['title'],
		mismatched_fields: [],
		skipped_fields: [],
		before_sha256: null,
		after_sha256: 'b'.repeat(64),
		change_set_id: 'c1',
	};

	it('adds verification to a verified result and leaves the rest alone', () => {
		const shown = Object.fromEntries(Object.entries(verified).filter(([key]) => key !== 'skipped_fields'));
		expect(attachVerification({ id: 4 }, verified)).toEqual({ id: 4, verification: shown });
	});

	it('turns a mismatch into ok:false with the fields and the restore hint', () => {
		const out = attachVerification({ id: 4, backup_id: 'tool/res/ts.json' }, { ...verified, status: 'mismatch', mismatched_fields: ['content'] }) as Row;
		expect(out).toMatchObject({ ok: false, error: 'verification_mismatch', written: true, id: 4 });
		expect(String(out['message'])).toContain('content');
		expect(out['restore_with']).toEqual({ tool: 'stonewright-direct-backup-restore', backup_id: 'tool/res/ts.json', needs: 'confirmation' });
		expect((out['verification'] as Row)['status']).toBe('mismatch');
	});

	it('marks a result that could not be read back', () => {
		const out = attachVerification({ id: 4 }, { ...verified, status: 'not_checked', reason: 'readback_failed', after_sha256: null }) as Row;
		expect(out['ok']).toBeUndefined();
		expect((out['verification'] as Row)['status']).toBe('not_checked');
	});
});
