import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { markTaskStartSeen } from '../src/direct/writes.js';
import { contentCreate, contentUpdate } from '../src/direct/tools/content.js';
import { templateUpdate } from '../src/direct/tools/templates.js';
import { probeFrontEnd, probeWanted } from '../src/direct/probe.js';
import { makeFakeWp, pageRow, templateRow, type FakeWp, type Row } from './helpers/fake-wp.js';

const localSite: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'editor-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

const remoteSite: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'editor-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

const PUBLIC_LOOKUP = () => Promise.resolve([{ address: '93.184.216.34', family: 4 }]);

describe('front-end probe', () => {
	let stateDir: string;
	const saved: Record<string, string | undefined> = {};

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-probe-'));
		for (const key of ['STONEWRIGHT_STATE_DIR', 'STONEWRIGHT_DIRECT_WRITES', 'STONEWRIGHT_DIRECT_PROBE']) saved[key] = process.env[key];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		delete process.env['STONEWRIGHT_DIRECT_PROBE'];
		process.env['STONEWRIGHT_DIRECT_WRITES'] = 'on';
		markTaskStartSeen(remoteSite);
	});

	afterEach(() => {
		for (const [key, value] of Object.entries(saved)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		rmSync(stateDir, { recursive: true, force: true });
		vi.restoreAllMocks();
	});

	function ctx(wp: FakeWp, target: ResolvedSite = localSite) {
		return {
			client: new WpRestClient(target, { fetchImpl: wp.fetchImpl as never }),
			site: target,
			writeMode: 'on' as const,
			env: process.env,
			fetchImpl: wp.fetchImpl as never,
			lookupImpl: PUBLIC_LOOKUP,
		};
	}

	function frontCalls(wp: FakeWp) {
		return wp.calls.filter((call) => !call.path.startsWith('/wp/v2'));
	}

	function auditRows(): Row[] {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Row);
	}

	describe('policy', () => {
		it('is on for a local site and off for a remote one, and a call or the environment can change it', () => {
			expect(probeWanted({}, { url: 'http://example.test' }, {})).toBe(true);
			expect(probeWanted({}, { url: 'http://localhost:8080' }, {})).toBe(true);
			expect(probeWanted({}, { url: 'https://example.com' }, {})).toBe(false);
			expect(probeWanted({ probe: true }, { url: 'https://example.com' }, {})).toBe(true);
			expect(probeWanted({ probe: false }, { url: 'http://example.test' }, {})).toBe(false);
			expect(probeWanted({}, { url: 'https://example.com' }, { STONEWRIGHT_DIRECT_PROBE: 'on' })).toBe(true);
			expect(probeWanted({}, { url: 'http://example.test' }, { STONEWRIGHT_DIRECT_PROBE: 'off' })).toBe(false);
			expect(probeWanted({ rollback_on_probe_failure: true }, { url: 'https://example.com' }, {})).toBe(true);
			expect(probeWanted({ rollback_on_probe_failure: true }, { url: 'http://example.test' }, { STONEWRIGHT_DIRECT_PROBE: 'off' })).toBe(true);
			expect(probeWanted({ probe: false, rollback_on_probe_failure: true }, { url: 'http://example.test' }, {})).toBe(false);
		});
	});

	describe('after a content write', () => {
		it('checks the published page on a local site and reports it healthy', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			expect(frontCalls(wp).map((call) => call.path)).toEqual(['/page-4/']);
			expect(out['probe']).toMatchObject({ status: 'passed', http_status: 200, url: 'http://example.test/page-4/', reasons: [] });
			expect(out['site_status']).toBe('ok');
			expect(out['ok']).toBeUndefined();
		});

		it('does not touch a remote site unless asked', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4, { link: 'https://example.com/page-4/' }) }, 'https://example.com');
			const quiet = (await contentUpdate(ctx(wp, remoteSite), { id: 4, content: '<p>new</p>' })) as Row;
			expect(frontCalls(wp)).toHaveLength(0);
			expect(quiet['probe']).toBeUndefined();

			const asked = (await contentUpdate(ctx(wp, remoteSite), { id: 4, content: '<p>newer</p>', probe: true })) as Row;
			expect(frontCalls(wp).map((call) => call.path)).toEqual(['/page-4/']);
			expect(asked['probe']).toMatchObject({ status: 'passed' });
		});

		it('skips a page that is not public, and says so only when asked', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4, { status: 'draft' }) });
			const quiet = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			expect(frontCalls(wp)).toHaveLength(0);
			expect(quiet['probe']).toBeUndefined();
			const asked = (await contentUpdate(ctx(wp), { id: 4, content: '<p>newer</p>', probe: true })) as Row;
			expect(asked['probe']).toMatchObject({ status: 'skipped', reasons: ['not_published'] });
		});

		it('never fetches a link that points off the site', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4, { link: 'http://other.example.org/page-4/' }) });
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>', probe: true })) as Row;
			expect(frontCalls(wp)).toHaveLength(0);
			expect(out['probe']).toMatchObject({ status: 'skipped', reasons: ['link_not_on_site'] });
		});

		it('can be switched off per call', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>', probe: false });
			expect(frontCalls(wp)).toHaveLength(0);
		});

		it('probes a page that was just created', async () => {
			const wp = makeFakeWp({});
			wp.fetchImpl.mockImplementationOnce((() => Promise.resolve(new Response(JSON.stringify(pageRow(31, { status: 'publish' })), { status: 201, headers: { 'content-type': 'application/json' } }))) as never);
			wp.store.set('/wp/v2/pages/31', pageRow(31, { title: { raw: 'T' }, content: { raw: '<p>c</p>' } }));
			const out = (await contentCreate(ctx(wp), { kind: 'page', title: 'T', content: '<p>c</p>', status: 'publish' })) as Row;
			expect(out['probe']).toMatchObject({ status: 'passed', url: 'http://example.test/page-31/' });
		});
	});

	describe('a failed probe', () => {
		const failing: Array<[string, { status: number; body: string }, string]> = [
			['an HTTP 500', { status: 500, body: '<html>Internal Server Error</html>' }, 'http_500'],
			['an HTTP 404', { status: 404, body: '<html>Not found</html>' }, 'http_404'],
			['an empty body', { status: 200, body: '   ' }, 'empty_body'],
			['a PHP fatal error', { status: 200, body: '<html><b>Fatal error</b>: Uncaught Error: Call to undefined function x() in /var/www/a.php:3</html>' }, 'php_fatal_error'],
			['a PHP parse error', { status: 200, body: 'PHP Parse error: syntax error, unexpected end of file' }, 'php_fatal_error'],
			['the WordPress critical error page', { status: 200, body: '<body id="error-page"><div class="wp-die-message"><p>There has been a critical error on this website.</p></div></body>' }, 'wp_critical_error_page'],
		];

		it.each(failing)('reports %s, offers the restore and changes nothing', async (_label, response, reason) => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			wp.front.set('/page-4/', response);
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			expect(out).toMatchObject({ ok: false, error: 'frontend_probe_failed', written: true, site_status: 'probe_failed' });
			expect((out['probe'] as Row)['reasons']).toContain(reason);
			expect(out['rollback_offer']).toEqual({ tool: 'stonewright-direct-backup-restore', args: { backup_id: out['backup_id'] }, needs: 'confirmation' });
			expect(out['rolled_back']).toBeUndefined();
			// Nothing was rolled back: the new content is still stored.
			expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: '<p>new</p>' } });
			expect(auditRows().some((row) => row['code'] === 'frontend_probe_failed' && row['status'] === 'error' && row['event_type'] === 'direct_probe')).toBe(true);
		});

		it('does not call a page that writes about PHP errors a failure when it wrote that text itself', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			const text = '<p>A PHP Fatal error: looks like this.</p>';
			wp.front.set('/page-4/', { status: 200, body: `<html><body>${text}</body></html>` });
			const out = (await contentUpdate(ctx(wp), { id: 4, content: text })) as Row;
			expect((out['probe'] as Row)['status']).toBe('passed');
		});

		it('restores the saved copy only when rollback_on_probe_failure is true, and the restore is audited', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			wp.frontHandler = (_url, store) => {
				const content = ((store.get('/wp/v2/pages/4')?.['content'] ?? {}) as Row)['raw'];
				return String(content).includes('BROKEN')
					? { status: 500, body: '<html>boom</html>' }
					: { status: 200, body: '<html><body>fine</body></html>' };
			};
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>BROKEN</p>', rollback_on_probe_failure: true })) as Row;
			expect(out).toMatchObject({ ok: false, error: 'frontend_probe_failed', site_status: 'rolled_back', rolled_back: true });
			expect((out['rollback'] as Row)['status']).toBe('restored');
			expect((out['probe_after_rollback'] as Row)['status']).toBe('passed');
			expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: '<p>old 4</p>' } });
			const restoreRows = auditRows().filter((row) => row['tool'] === 'stonewright-direct-backup-restore');
			expect(restoreRows.some((row) => row['event_type'] === 'direct_verifier' && row['verification_status'] === 'verified')).toBe(true);
		});

		it('reports a rollback that could not run, and leaves the offer', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			wp.front.set('/page-4/', { status: 500, body: 'boom' });
			wp.sanitize = (_path, body) => body;
			// The page is deleted by someone else between the write and the restore.
			const original = wp.fetchImpl.getMockImplementation();
			let probed = false;
			wp.fetchImpl.mockImplementation(((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
				if (!new URL(String(url)).pathname.startsWith('/wp-json')) probed = true;
				else if (probed) wp.store.delete('/wp/v2/pages/4');
				return (original as typeof fetch)(url, init);
			}) as never);
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>', rollback_on_probe_failure: true })) as Row;
			expect(out).toMatchObject({ ok: false, error: 'frontend_probe_failed', site_status: 'rollback_failed', rolled_back: false });
			expect((out['rollback'] as Row)['code']).toBe('resource_missing');
		});

		it('has no saved copy for a page that was just created and says so', async () => {
			const wp = makeFakeWp({});
			wp.store.set('/wp/v2/pages/31', pageRow(31));
			wp.front.set('/page-31/', { status: 500, body: 'boom' });
			wp.fetchImpl.mockImplementationOnce((() => Promise.resolve(new Response(JSON.stringify(pageRow(31)), { status: 201, headers: { 'content-type': 'application/json' } }))) as never);
			const out = (await contentCreate(ctx(wp), { kind: 'page', title: 'Page 31', content: '<p>old 31</p>', status: 'publish' })) as Row;
			expect(out).toMatchObject({ ok: false, error: 'frontend_probe_failed', site_status: 'probe_failed', rollback_offer: null });
		});

		it('keeps a readback mismatch as the error and adds the probe result next to it', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			wp.sanitize = (_path, body) => ({ ...body, content: '<p>stripped</p>' });
			wp.front.set('/page-4/', { status: 500, body: 'boom' });
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			expect(out['error']).toBe('verification_mismatch');
			expect((out['probe'] as Row)['status']).toBe('failed');
			expect(out['site_status']).toBe('probe_failed');
		});
	});

	describe('an unreachable page is not a verdict', () => {
		it('reports the page as unavailable and does not fail or offer a rollback', async () => {
			const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
			const original = wp.fetchImpl.getMockImplementation() as typeof fetch;
			wp.fetchImpl.mockImplementation(((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
				if (!new URL(String(url)).pathname.startsWith('/wp-json')) return Promise.reject(new Error('connect ECONNREFUSED'));
				return original(url, init);
			}) as never);
			const out = (await contentUpdate(ctx(wp), { id: 4, content: '<p>new</p>' })) as Row;
			expect(out['ok']).toBeUndefined();
			expect(out['probe']).toMatchObject({ status: 'unavailable' });
			expect(out['rollback_offer']).toBeUndefined();
		});
	});

	describe('safe fetch rules', () => {
		it('only the configured local host may resolve to a non-public address', async () => {
			const wp = makeFakeWp({});
			const loopback = () => Promise.resolve([{ address: '127.0.0.1', family: 4 }]);
			const local = await probeFrontEnd({ ...ctx(wp), lookupImpl: loopback }, { kind: 'page', status: 'publish', link: 'http://example.test/a/' });
			expect(local.status).toBe('passed');

			const remote = await probeFrontEnd({ ...ctx(wp, remoteSite), lookupImpl: loopback }, { kind: 'page', status: 'publish', link: 'https://example.com/a/' });
			expect(remote).toMatchObject({ status: 'unavailable', reasons: ['media_url_address_not_public'] });
		});

		it('checks every redirect hop: a redirect off the local host to a private address is refused', async () => {
			const wp = makeFakeWp({});
			let hop = 0;
			wp.fetchImpl.mockImplementation((() => {
				hop += 1;
				return Promise.resolve(new Response(null, { status: 302, headers: { location: 'http://intranet.example.org/secret' } }));
			}) as never);
			const lookup = (host: string) => Promise.resolve([{ address: host === 'example.test' ? '127.0.0.1' : '10.0.0.5', family: 4 }]);
			const out = await probeFrontEnd({ ...ctx(wp), lookupImpl: lookup }, { kind: 'home' });
			expect(out).toMatchObject({ status: 'unavailable', reasons: ['media_url_address_not_public'] });
			expect(hop).toBe(1);
		});

		it('fetches the home page for a template write', async () => {
			const id = 'theme//home';
			const wp = makeFakeWp({ [`/wp/v2/templates/${id}`]: templateRow(id) });
			const out = (await templateUpdate(ctx(wp), { id, content: '<p>x</p>' })) as Row;
			expect(frontCalls(wp).map((call) => call.path)).toEqual(['/']);
			expect(out['probe']).toMatchObject({ status: 'passed', url: 'http://example.test/' });
		});

		it('rolls back a template write whose home page breaks', async () => {
			const id = 'theme//home';
			const wp = makeFakeWp({ [`/wp/v2/templates/${id}`]: templateRow(id) });
			wp.frontHandler = (_url, store) => {
				const raw = ((store.get(`/wp/v2/templates/${id}`)?.['content'] ?? {}) as Row)['raw'];
				return String(raw).includes('BROKEN') ? { status: 200, body: 'Fatal error: Uncaught Error in theme' } : undefined;
			};
			const out = (await templateUpdate(ctx(wp), { id, content: '<p>BROKEN</p>', rollback_on_probe_failure: true })) as Row;
			expect(out['site_status']).toBe('rolled_back');
			expect(wp.store.get(`/wp/v2/templates/${id}`)).toMatchObject({ content: { raw: '<!-- wp:paragraph --><p>old</p><!-- /wp:paragraph -->' } });
		});
	});
});
