import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import type { DirectWriteMode } from '../src/direct/writes.js';
import { contentUpdate } from '../src/direct/tools/content.js';
import { templatePartUpdate, templateUpdate } from '../src/direct/tools/templates.js';
import { globalStylesUpdate } from '../src/direct/tools/global-styles.js';
import { applyBlueprint, listBlueprints } from '../src/direct/tools/blueprints.js';
import { elementorDataUpdate } from '../src/direct/tools/elementor-direct.js';
import { backupList, backupRestore } from '../src/direct/backups.js';
import { stableJson, stateHash } from '../src/direct/verify.js';
import { makeFakeWp, pageRow, templateRow, type FakeWp, type Row } from './helpers/fake-wp.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'editor-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

const RESTORE_TOOL = 'stonewright-direct-backup-restore';

describe('direct backup list and restore', () => {
	let stateDir: string;
	const saved: Record<string, string | undefined> = {};

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-backups-'));
		for (const key of ['STONEWRIGHT_STATE_DIR', 'STONEWRIGHT_DIRECT_WRITES', 'STONEWRIGHT_DIRECT_PROBE']) saved[key] = process.env[key];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		process.env['STONEWRIGHT_DIRECT_PROBE'] = 'off';
		delete process.env['STONEWRIGHT_DIRECT_WRITES'];
	});

	afterEach(() => {
		for (const [key, value] of Object.entries(saved)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		rmSync(stateDir, { recursive: true, force: true });
		vi.restoreAllMocks();
	});

	function ctx(wp: FakeWp, writeMode: DirectWriteMode = 'on') {
		return { client: new WpRestClient(site, { fetchImpl: wp.fetchImpl as never }), site, writeMode, env: process.env };
	}

	function auditRows(): Row[] {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Row);
	}

	function writes(wp: FakeWp) {
		return wp.calls.filter((call) => call.method !== 'GET');
	}

	it('lists what a write stored, newest first, with the id restore takes', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const first = (await contentUpdate(ctx(wp), { id: 4, content: '<p>one</p>' })) as Row;
		const second = (await contentUpdate(ctx(wp), { id: 4, content: '<p>two</p>' })) as Row;
		expect(first['backup_id']).toMatch(/^stonewright-content-update\/pages-4\/.+\.json$/);

		const listed = (await backupList(ctx(wp), {})) as { items: Row[]; total: number };
		expect(listed.total).toBe(2);
		expect(listed.items[0]).toMatchObject({
			backup_id: second['backup_id'],
			kind: 'preimage',
			tool: 'stonewright-content-update',
			resource: 'pages-4',
			integrity: 'ok',
			backup_sha256: second['backup_sha256'],
		});
		expect(listed.items[1]?.['backup_id']).toBe(first['backup_id']);
		expect(JSON.stringify(listed)).not.toContain('<p>');
	});

	it('filters by resource and tool', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4), '/wp/v2/pages/5': pageRow(5) });
		await contentUpdate(ctx(wp), { id: 4, content: '<p>a</p>' });
		await contentUpdate(ctx(wp), { id: 5, content: '<p>b</p>' });
		const only = (await backupList(ctx(wp), { resource: 'pages-5' })) as { items: Row[] };
		expect(only.items.map((item) => item['resource'])).toEqual(['pages-5']);
		const none = (await backupList(ctx(wp), { tool: 'stonewright-template-update' })) as { items: Row[] };
		expect(none.items).toEqual([]);
	});

	it('round trip: write, restore, and the stored page equals the pre-image', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const before = structuredClone(wp.store.get('/wp/v2/pages/4')!);
		const written = (await contentUpdate(ctx(wp), { id: 4, title: 'New title', content: '<p>new</p>', status: 'draft' })) as Row;
		expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ status: 'draft' });

		const restored = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
		expect(restored).toMatchObject({ ok: true, restored: true, restored_from: written['backup_id'] });
		expect((restored['verification'] as Row)['status']).toBe('verified');
		expect(stateHash(wp.store.get('/wp/v2/pages/4'))).toBe(stateHash(before));
		expect(restored['matches_backup']).toBe(true);
	});

	it('lists the meta keys added after the backup, which a restore does not remove', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4, { meta: { kept_key: 'a' } }) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		const page = wp.store.get('/wp/v2/pages/4') as { meta: Row };
		page.meta = { ...page.meta, added_later: 'x' };

		const dry = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), dry_run: true })) as Row;
		expect(dry['meta_not_in_backup']).toEqual(['added_later']);

		const restored = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
		expect(restored['ok']).toBe(true);
		expect(restored['meta_not_in_backup']).toEqual(['added_later']);
		expect(restored['matches_backup']).toBe(false);
		expect((wp.store.get('/wp/v2/pages/4') as { meta: Row }).meta).toMatchObject({ kept_key: 'a', added_later: 'x' });
	});

	it('reports no extra meta keys when the page holds only what the backup holds', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4, { meta: { kept_key: 'a' } }) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		const restored = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
		expect(restored['meta_not_in_backup']).toEqual([]);
		expect(restored['matches_backup']).toBe(true);
	});

	it('takes a pre-image of the current state first, so the restore can be undone', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		const restored = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
		expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: '<p>old 4</p>' } });
		expect(restored['backup_id']).toMatch(new RegExp(`^${RESTORE_TOOL}/pages-4/`));

		const undone = (await backupRestore(ctx(wp), { backup_id: String(restored['backup_id']), confirm: true })) as Row;
		expect(undone['ok']).toBe(true);
		expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: '<p>new</p>' } });
	});

	it('refuses without confirm:true even when writes are on, and sends nothing', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		wp.calls.length = 0;
		await expect(backupRestore(ctx(wp), { backup_id: String(written['backup_id']) })).rejects.toMatchObject({ code: 'confirmation_required' });
		await expect(backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: false })).rejects.toMatchObject({ code: 'confirmation_required' });
		expect(wp.calls).toHaveLength(0);
		expect(readdirSync(join(stateDir, 'backups', 'local')).includes(RESTORE_TOOL)).toBe(false);
	});

	it('is blocked when direct writes are off', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		await expect(backupRestore(ctx(wp, 'off'), { backup_id: String(written['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'direct_writes_disabled' });
	});

	it('dry_run reports what would change without confirm and writes nothing', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		wp.calls.length = 0;
		const out = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), dry_run: true })) as Row;
		expect(out).toMatchObject({ ok: true, dry_run: true, integrity: 'ok' });
		expect(out['would_change']).toEqual(['content']);
		expect(writes(wp)).toHaveLength(0);
		expect(readdirSync(join(stateDir, 'backups', 'local')).includes(RESTORE_TOOL)).toBe(false);
	});

	it('reports nothing to restore when the page already matches', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true });
		wp.calls.length = 0;
		const again = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
		expect(again).toMatchObject({ ok: true, restored: false, unchanged: true });
		expect(writes(wp)).toHaveLength(0);
	});

	describe('integrity', () => {
		async function written() {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			wp.calls.length = 0;
			return { wp, out };
		}

		it('refuses a file whose body was edited, shows it as tampered and writes nothing', async () => {
			const { wp, out } = await written();
			const path = String(out['backup_path']);
			const record = JSON.parse(readFileSync(path, 'utf8')) as { body: { content: { raw: string } } };
			record.body.content.raw = '<p>planted</p>';
			writeFileSync(path, JSON.stringify(record, null, 2));
			const listed = (await backupList(ctx(wp), {})) as { items: Row[] };
			expect(listed.items[0]?.['integrity']).toBe('tampered');
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'backup_integrity_failed' });
			expect(writes(wp)).toHaveLength(0);
		});

		it('refuses a file that is not a stored backup', async () => {
			const { wp, out } = await written();
			writeFileSync(String(out['backup_path']), 'not json');
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'backup_integrity_failed' });
			expect(writes(wp)).toHaveLength(0);
		});

		it('checks the hash the write returned when the caller passes it', async () => {
			const { wp, out } = await written();
			await expect(
				backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true, expected_sha256: 'f'.repeat(64) }),
			).rejects.toMatchObject({ code: 'backup_hash_mismatch' });
			expect(writes(wp)).toHaveLength(0);
			const ok = (await backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true, expected_sha256: String(out['backup_sha256']) })) as Row;
			expect(ok['ok']).toBe(true);
		});

		it('refuses a file without a stored hash unless the caller supplies its file hash', async () => {
			const { wp, out } = await written();
			const path = String(out['backup_path']);
			const record = JSON.parse(readFileSync(path, 'utf8')) as Row;
			delete record['body_sha256'];
			const bytes = Buffer.from(`${JSON.stringify(record, null, 2)}\n`, 'utf8');
			writeFileSync(path, bytes);
			const listed = (await backupList(ctx(wp), {})) as { items: Row[] };
			expect(listed.items[0]?.['integrity']).toBe('unverifiable');
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'backup_unverifiable' });
			const sha = createHash('sha256').update(bytes).digest('hex');
			const ok = (await backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true, expected_sha256: sha })) as Row;
			expect(ok['ok']).toBe(true);
		});

		it.each(['../../audit-direct.jsonl', '/etc/passwd', 'a/b/../../../x.json', 'stonewright-content-update/pages-4/not-a-backup.json', 'C:\\x.json', ''])(
			'refuses the id %j without touching anything',
			async (id) => {
				const { wp } = await written();
				await expect(backupRestore(ctx(wp), { backup_id: id, confirm: true })).rejects.toMatchObject({ code: 'backup_not_found' });
				expect(wp.calls).toHaveLength(0);
			},
		);

		it('refuses a backup of another site', async () => {
			const { wp, out } = await written();
			const path = String(out['backup_path']);
			const record = JSON.parse(readFileSync(path, 'utf8')) as Row;
			record['site'] = 'other';
			// Keep the stored hash valid so the only reason to refuse is the site.
			writeFileSync(path, `${JSON.stringify(record, null, 2)}\n`);
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'backup_site_mismatch' });
		});

		it.each([
			['a users route', '/wp/v2/users/1'],
			['a plugin route', '/wp/v2/plugins/akismet'],
			['a route with a query', '/wp/v2/pages/4?force=true'],
			['a nested route', '/wp/v2/pages/4/revisions/9'],
			['a template for a content backup', '/wp/v2/templates/theme%2F%2Fhome'],
		])('refuses a backup whose stored hash is valid but whose route is %s', async (_label, resource) => {
			const { wp, out } = await written();
			const path = String(out['backup_path']);
			const record = JSON.parse(readFileSync(path, 'utf8')) as Row;
			record['resource'] = resource;
			writeFileSync(path, `${JSON.stringify(record, null, 2)}
`);
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'restore_unsupported' });
			expect(wp.calls).toHaveLength(0);
		});

		it('refuses a backup that names a different tool than its folder', async () => {
			const { wp, out } = await written();
			const path = String(out['backup_path']);
			const record = JSON.parse(readFileSync(path, 'utf8')) as Row;
			record['tool'] = 'stonewright-template-update';
			writeFileSync(path, `${JSON.stringify(record, null, 2)}
`);
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'backup_integrity_failed' });
			expect(wp.calls).toHaveLength(0);
		});

		it.skipIf(process.platform === 'win32')('refuses a symlinked backup file', async () => {
			const { wp, out } = await written();
			const path = String(out['backup_path']);
			const target = join(stateDir, 'elsewhere.json');
			writeFileSync(target, readFileSync(path));
			rmSync(path);
			symlinkSync(target, path);
			await expect(backupRestore(ctx(wp), { backup_id: String(out['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'backup_not_found' });
		});
	});

	it('refuses when the resource no longer exists and does not recreate it', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		wp.store.delete('/wp/v2/pages/4');
		wp.calls.length = 0;
		await expect(backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })).rejects.toMatchObject({ code: 'resource_missing' });
		expect(writes(wp)).toHaveLength(0);
	});

	it('writes the audit rows for the restore with before and after hashes', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		const afterWrite = stateHash(wp.store.get('/wp/v2/pages/4'));
		await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true });
		const restoreRows = auditRows().filter((row) => row['tool'] === RESTORE_TOOL);
		const [write, verifier] = restoreRows;
		expect(write).toMatchObject({
			status: 'ok',
			event_type: 'direct_tool',
			resource_ref: 'pages/4',
			before_sha256: afterWrite,
			verification_status: 'verified',
		});
		expect(String(write?.['validator_summary'])).toContain(String(written['backup_id']));
		expect(write?.['after_sha256']).toBe(stateHash(wp.store.get('/wp/v2/pages/4')));
		expect(verifier).toMatchObject({ status: 'ok', event_type: 'direct_verifier', verification_status: 'verified' });
	});

	it('reports a restore the site did not keep as a mismatch and keeps the undo copy', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
		wp.sanitize = (_path, body) => ({ ...body, content: '<p>stripped</p>' });
		const out = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
		expect(out).toMatchObject({ ok: false, error: 'verification_mismatch', written: true });
		expect((out['verification'] as Row)['mismatched_fields']).toEqual(['content']);
		expect(String(out['backup_id'])).toContain(RESTORE_TOOL);
	});

	describe('resource kinds', () => {
		it('restores a template', async () => {
			const id = 'theme//home';
			const wp = makeFakeWp({ [`/wp/v2/templates/${id}`]: templateRow(id) });
			const before = structuredClone(wp.store.get(`/wp/v2/templates/${id}`)!);
			const written = (await templateUpdate(ctx(wp), { id, title: 'Changed', content: '<p>changed</p>', description: 'changed' })) as Row;
			const out = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
			expect(out['ok']).toBe(true);
			expect(stateHash(wp.store.get(`/wp/v2/templates/${id}`))).toBe(stateHash(before));
		});

		it('restores a template part', async () => {
			const id = 'theme//header';
			const wp = makeFakeWp({ [`/wp/v2/template-parts/${id}`]: templateRow(id, { type: 'wp_template_part', area: 'header' }) });
			const before = structuredClone(wp.store.get(`/wp/v2/template-parts/${id}`)!);
			const written = (await templatePartUpdate(ctx(wp), { id, content: '<p>changed</p>' })) as Row;
			const out = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
			expect(out['ok']).toBe(true);
			expect(stateHash(wp.store.get(`/wp/v2/template-parts/${id}`))).toBe(stateHash(before));
		});

		it('restores global styles', async () => {
			const original = { id: 12, title: { raw: 'Styles' }, settings: { color: { palette: [] } }, styles: { color: { background: '#fff' } } };
			const wp = makeFakeWp({ '/wp/v2/global-styles/12': original });
			const written = (await globalStylesUpdate(ctx(wp), { id: '12', styles: { color: { background: '#000' } } })) as Row;
			expect(wp.store.get('/wp/v2/global-styles/12')).toMatchObject({ styles: { color: { background: '#000' } } });
			const out = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
			expect(out['ok']).toBe(true);
			expect(wp.store.get('/wp/v2/global-styles/12')).toMatchObject({ styles: { color: { background: '#fff' } }, settings: { color: { palette: [] } } });
		});

		it('restores a page that a blueprint overwrote', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/9': pageRow(9) });
			const before = structuredClone(wp.store.get('/wp/v2/pages/9')!);
			const wpClient = new WpRestClient(site, { fetchImpl: wp.fetchImpl as never });
			const blueprintId = listBlueprints()[0]?.id;
			if (!blueprintId) throw new Error('no bundled blueprint');
			const applied = (await applyBlueprint(wpClient, { id: blueprintId, post_id: 9, confirm: true }, process.env)) as Row;
			expect(applied['backup_id']).toBeTruthy();
			expect(wp.store.get('/wp/v2/pages/9')).not.toMatchObject({ content: { raw: '<p>old 9</p>' } });
			const out = (await backupRestore(ctx(wp), { backup_id: String(applied['backup_id']), confirm: true })) as Row;
			expect(out['ok']).toBe(true);
			expect(stateHash(wp.store.get('/wp/v2/pages/9'))).toBe(stateHash(before));
		});

		it('restores content that already held custom code, because it is putting back what was there', async () => {
			const withCode = '<p>old</p><script>legacy()</script>';
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4, { content: { raw: withCode, rendered: withCode } }) });
			const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>clean</p>' })) as Row;
			const out = (await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true })) as Row;
			expect(out['ok']).toBe(true);
			expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: withCode } });
		});

		it('uses the same route and method the original tool used', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			const written = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			wp.calls.length = 0;
			await backupRestore(ctx(wp), { backup_id: String(written['backup_id']), confirm: true });
			const sent = writes(wp);
			expect(sent).toHaveLength(1);
			expect(sent[0]).toMatchObject({ method: 'PUT', path: '/wp/v2/pages/4' });
			expect(Object.keys(sent[0]?.body ?? {}).sort()).toEqual(['content', 'excerpt', 'parent', 'slug', 'status', 'template', 'title']);
		});
	});

	describe('elementor file backups', () => {
		const tree = [{ id: 'a1', elType: 'container', settings: {}, elements: [] }];
		const next = [{ id: 'b2', elType: 'container', settings: {}, elements: [] }];

		function restClient(wp: FakeWp) {
			const client = new WpRestClient(site, { fetchImpl: wp.fetchImpl as never });
			return client;
		}

		const noCli = vi.fn(() =>
			Promise.resolve({ ok: false, available: false, command: [], cwd: '', stdout: '', stderr: 'missing', exit_code: 1, duration_ms: 1, wp_cli_source: 'test' }),
		);

		function elementorPage(data: unknown) {
			return pageRow(7, { meta: { _elementor_data: JSON.stringify(data), _elementor_edit_mode: 'builder' } });
		}

		it('lists the file backup and restores it through the same tool', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/7': elementorPage(tree) });
			const env = { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' };
			const client = restClient(wp);
			const updated = (await elementorDataUpdate(env, { post_id: 7, data: next, confirm: true, force_destructive: true }, noCli as never, client)) as Row;
			expect(updated['ok']).toBe(true);
			expect(updated['backup_id']).toMatch(/^post-7-.+\.json$/);
			expect(updated['backup_sha256']).toMatch(/^[0-9a-f]{64}$/);

			const listed = (await backupList(ctx(wp), { resource: 'post-7' })) as { items: Row[] };
			expect(listed.items[0]).toMatchObject({ backup_id: updated['backup_id'], kind: 'elementor', tool: 'stonewright-elementor-data-update', resource: 'post-7', integrity: 'ok' });

			const out = (await backupRestore(ctx(wp), { backup_id: String(updated['backup_id']), confirm: true }, { cli: noCli as never })) as Row;
			expect(out).toMatchObject({ ok: true, post_id: 7, restored_from: updated['backup_id'] });
			const stored = wp.store.get('/wp/v2/pages/7') as { meta: { _elementor_data: string } };
			expect(JSON.parse(stored.meta._elementor_data)).toEqual(tree);
			// The state before the restore is kept as a new file backup.
			const after = (await backupList(ctx(wp), { resource: 'post-7' })) as { items: Row[] };
			expect(after.items.length).toBe(2);
			const rows = auditRows().filter((row) => row['tool'] === RESTORE_TOOL);
			expect(rows.some((row) => row['status'] === 'ok' && row['resource_ref'] === 'post:7')).toBe(true);
		});

		it('reports an Elementor write the site did not keep as a mismatch and still returns the backup to restore', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/7': elementorPage(tree) });
			// The site ignores the meta it was sent, so the stored document is still the old tree.
			wp.sanitize = (_path, body) => ({ ...body, meta: {} });
			const env = { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' };
			const out = (await elementorDataUpdate(env, { post_id: 7, data: next, confirm: true, force_destructive: true }, noCli as never, restClient(wp))) as Row;
			expect(out).toMatchObject({ ok: false, error: 'verification_mismatch', written: true, transport: 'rest' });
			expect((out['verification'] as Row)['mismatched_fields']).toEqual(['_elementor_data']);
			expect(out['restore_with']).toMatchObject({ tool: RESTORE_TOOL, backup_id: out['backup_id'] });
			const verifier = auditRows().filter((row) => row['event_type'] === 'direct_verifier').at(-1);
			expect(verifier).toMatchObject({ tool: 'stonewright-elementor-data-update', status: 'error', code: 'verification_mismatch' });
		});

		it('refuses a tampered elementor backup and one that is gone from the site', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/7': elementorPage(tree) });
			const env = { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' };
			const updated = (await elementorDataUpdate(env, { post_id: 7, data: next, confirm: true, force_destructive: true }, noCli as never, restClient(wp))) as Row;
			const path = String(updated['backup_path']);
			const record = JSON.parse(readFileSync(path, 'utf8')) as { data: unknown[] };
			const good = readFileSync(path);
			record.data = [{ id: 'evil', elType: 'container', settings: {}, elements: [] }];
			writeFileSync(path, JSON.stringify(record));
			await expect(backupRestore(ctx(wp), { backup_id: String(updated['backup_id']), confirm: true }, { cli: noCli as never })).rejects.toMatchObject({ code: 'backup_integrity_failed' });

			writeFileSync(path, good);
			wp.store.delete('/wp/v2/pages/7');
			await expect(backupRestore(ctx(wp), { backup_id: String(updated['backup_id']), confirm: true }, { cli: noCli as never })).rejects.toMatchObject({ code: 'resource_missing' });
		});

		it('does not list or restore a legacy backup that has no stored hash without the file hash', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/7': elementorPage(next) });
			const dir = join(stateDir, 'backups', 'local');
			mkdirSync(dir, { recursive: true });
			const name = 'post-7-2026-10-01T00-00-00-000Z.json';
			const bytes = Buffer.from(JSON.stringify({ post_id: 7, backed_up_at: '2026-10-01T00:00:00.000Z', transport: 'rest', data: tree }), 'utf8');
			writeFileSync(join(dir, name), bytes);
			const listed = (await backupList(ctx(wp), {})) as { items: Row[] };
			expect(listed.items[0]).toMatchObject({ backup_id: name, kind: 'elementor', integrity: 'unverifiable' });
			await expect(backupRestore(ctx(wp), { backup_id: name, confirm: true }, { cli: noCli as never })).rejects.toMatchObject({ code: 'backup_unverifiable' });
			const sha = createHash('sha256').update(bytes).digest('hex');
			const ok = (await backupRestore(ctx(wp), { backup_id: name, confirm: true, expected_sha256: sha }, { cli: noCli as never })) as Row;
			expect(ok['ok']).toBe(true);
		});
	});

	it('hashes the stored body with sorted keys', () => {
		expect(stableJson({ b: 1, a: { d: 2, c: 3 } })).toBe('{"a":{"c":3,"d":2},"b":1}');
	});
});
