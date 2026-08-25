import { describe, expect, it, vi } from 'vitest';
import {
	classifyTransportFailure,
	classifyHttpStatus,
	PluginTransportError,
} from '../../src/connection/transport-diagnostic.js';
import { probePluginEndpoint } from '../../src/direct/mode.js';
import { resolveRuntimeMode } from '../../src/direct/mode.js';

describe('classifyTransportFailure', () => {
	const cases = [
		['ENOTFOUND', 'dns_error', false],
		['CERT_HAS_EXPIRED', 'tls_error', false],
		['ECONNREFUSED', 'connection_refused', false],
		['ECONNRESET', 'connection_reset', true],
		['UND_ERR_CONNECT_TIMEOUT', 'timeout', true],
	] as const;

	for (const [code, kind, mayHaveReachedServer] of cases) {
		it(`classifies ${code}`, () => {
			const error = Object.assign(new TypeError('fetch failed'), { cause: { code } });
			const diagnostic = classifyTransportFailure(error, {
				phase: 'initialize',
				attempt: 1,
				startedAt: 100,
				now: () => 160,
			});
			expect(diagnostic.kind).toBe(kind);
			expect(diagnostic.safe_cause_code).toBe(code);
			expect(diagnostic.duration_ms).toBe(60);
			expect(diagnostic.request_may_have_reached_server).toBe(mayHaveReachedServer);
		});
	}

	it('never embeds raw nested cause text in the diagnostic', () => {
		const error = Object.assign(new TypeError('fetch failed'), {
			cause: { code: 'ECONNRESET', message: 'secret-token-abc bearer xyz' },
		});
		const diagnostic = classifyTransportFailure(error, {
			phase: 'tool_call',
			attempt: 2,
			startedAt: 0,
			now: () => 10,
		});
		expect(JSON.stringify(diagnostic)).not.toContain('secret-token');
		expect(JSON.stringify(diagnostic)).not.toContain('bearer');
		expect(diagnostic.safe_cause_code).toBe('ECONNRESET');
	});

	it('classifies HTTP statuses into safe kinds', () => {
		expect(classifyHttpStatus(401, { phase: 'endpoint_get', attempt: 1, startedAt: 0, now: () => 5 }).kind).toBe(
			'auth_error',
		);
		expect(classifyHttpStatus(404, { phase: 'endpoint_get', attempt: 1, startedAt: 0, now: () => 5 }).kind).toBe(
			'plugin_route_missing',
		);
		expect(classifyHttpStatus(405, { phase: 'endpoint_head', attempt: 1, startedAt: 0, now: () => 5 }).kind).toBe(
			'http_error',
		);
		expect(classifyHttpStatus(500, { phase: 'initialize', attempt: 1, startedAt: 0, now: () => 5 }).kind).toBe(
			'http_error',
		);
	});

	it('PluginTransportError carries the diagnostic', () => {
		const diagnostic = classifyHttpStatus(401, {
			phase: 'initialize',
			attempt: 1,
			startedAt: 0,
			now: () => 1,
		});
		const err = new PluginTransportError('auth failed', diagnostic);
		expect(err.name).toBe('PluginTransportError');
		expect(err.diagnostic.kind).toBe('auth_error');
		expect(err.message).toBe('auth failed');
	});
});

describe('probePluginEndpoint controllers', () => {
	it('HEAD timeout does not poison a successful GET fallback', async () => {
		const signals: Array<AbortSignal | null | undefined> = [];
		const fetchImpl = vi.fn(async (_url: unknown, init?: RequestInit) => {
			await Promise.resolve();
			signals.push(init?.signal);
			if (signals.length === 1) {
				// Simulate HEAD abort without marking the shared signal for later attempts.
				const err = Object.assign(new Error('aborted'), { name: 'AbortError', cause: { code: 'UND_ERR_CONNECT_TIMEOUT' } });
				throw err;
			}
			return new Response('', { status: 200 });
		});

		const probe = await probePluginEndpoint(
			'https://example.test/wp-json/mcp/stonewright',
			fetchImpl as unknown as typeof fetch,
			50,
		);

		expect(probe.present).toBe(true);
		expect(probe.status).toBe(200);
		expect(signals).toHaveLength(2);
		expect(signals[0]).not.toBe(signals[1]);
		expect(signals[1]?.aborted).toBe(false);
	});

	it('classifies 401 as reachable, 404 as missing, 405 as reachable', async () => {
		for (const [status, present] of [
			[401, true],
			[403, true],
			[405, true],
			[404, false],
		] as const) {
			const probe = await probePluginEndpoint(
				'https://example.test/wp-json/mcp/stonewright',
				vi.fn(() => Promise.resolve(new Response('', { status }))) as unknown as typeof fetch,
			);
			expect(probe.present).toBe(present);
			expect(probe.status).toBe(status);
		}
	});
});

describe('plugin-only force_probe', () => {
	it('runs a real endpoint probe when force_probe is true in plugin-only mode', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('', { status: 401 })));
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WP_URL: 'https://example.test',
			},
			fetchImpl: fetchImpl as unknown as typeof fetch,
			forceProbe: true,
		});
		expect(fetchImpl).toHaveBeenCalled();
		expect(result.mode).toBe('plugin');
		expect(result.pluginEndpointStatus).toBe(401);
		expect(result.reason).toMatch(/401|responded/i);
	});

	it('does not treat route reachability as authentication success', async () => {
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WP_URL: 'https://example.test',
			},
			fetchImpl: vi.fn(() => Promise.resolve(new Response('', { status: 401 }))) as unknown as typeof fetch,
			forceProbe: true,
		});
		expect(result.pluginEndpointStatus).toBe(401);
		expect(result.reason).not.toMatch(/authenticated|initialize succeeded/i);
	});
});
