import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { runInNewContext } from 'node:vm';
import { describe, expect, it } from 'vitest';

type Timer = { id: number; callback: () => void; delay: number; active: boolean };

function response(status: number, body: unknown = {}): { ok: boolean; status: number; json: () => Promise<unknown> } {
	return { ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) };
}

function harness(fetchImpl: (url: string, init?: Record<string, unknown>) => Promise<ReturnType<typeof response>>) {
	const script = readFileSync(resolve(process.cwd(), '..', 'plugin', 'blocks', 'finalizer', 'finalizer.js'), 'utf8');
	let nextTimer = 1;
	const timers: Timer[] = [];
	const listeners = new Map<string, () => void>();
	const fetches: string[] = [];
	const classList = { toggle: () => undefined };
	const elements = new Map<string, Record<string, unknown>>();
	const element = (id: string) => {
		if (!elements.has(id)) elements.set(id, { id, textContent: '', classList, setAttribute: () => undefined, appendChild: () => undefined, replaceChildren: () => undefined });
		return elements.get(id)!;
	};
	const frame = element('stonewright-finalizer-frame');
	frame.dataset = { post: '42' };
	frame.contentWindow = {
		wp: {
			data: {},
			blocks: {
				createBlock: (name: string) => ({ name }),
				serialize: (blocks: Array<{ name: string }>) => `<!-- wp:${blocks[0]?.name ?? ''} --><p>ok</p><!-- /wp:${blocks[0]?.name ?? ''} -->`,
				parse: (html: string) => [{ name: html.includes('core/paragraph') ? 'core/paragraph' : '' }],
				getBlockType: () => ({}),
			},
		},
	};
	const windowObject = {
		stonewrightBlockFinalizer: { token: 'token', restBase: '/wp-json/stonewright/v1/block-finalizer/', leaseId: 'lease' },
		crypto: undefined,
		TextEncoder: undefined,
		requestAnimationFrame: (callback: () => void) => callback(),
		setTimeout: (callback: () => void, delay: number) => {
			const timer = { id: nextTimer++, callback, delay, active: true };
			timers.push(timer);
			return timer.id;
		},
		clearTimeout: (id: number) => {
			const timer = timers.find((candidate) => candidate.id === id);
			if (timer) timer.active = false;
		},
		addEventListener: (name: string, callback: () => void) => listeners.set(name, callback),
	};
	const documentObject = {
		readyState: 'complete',
		getElementById: (id: string) => element(id),
		createElement: () => ({ className: '', textContent: '', appendChild: () => undefined }),
		addEventListener: (name: string, callback: () => void) => listeners.set(name, callback),
	};
	const fetch = (url: string, init?: Record<string, unknown>) => {
		fetches.push(url);
		return fetchImpl(url, init);
	};
	runInNewContext(script, { window: windowObject, document: documentObject, fetch, Promise, Date, Math, JSON, Object, Array, Number, String, Error, encodeURIComponent, setImmediate });
	return {
		fetches,
		timers,
		listeners,
		element,
		activeTimers: () => timers.filter((timer) => timer.active),
	};
}

async function settle(): Promise<void> {
	for (let index = 0; index < 12; index += 1) await new Promise<void>((resolveTick) => setImmediate(resolveTick));
}

describe('block finalizer runtime lifecycle', () => {
	for (const status of [400, 401, 403, 404, 409, 422, 429]) {
		it(`closes and stops all timers on terminal HTTP ${status}`, async () => {
			const runtime = harness((url) => Promise.resolve(url.includes('pending?') ? response(status) : response(200)));
			await settle();

			expect(runtime.activeTimers()).toHaveLength(0);
			const before = runtime.fetches.length;
			for (const timer of runtime.timers) timer.callback();
			await settle();
			expect(runtime.fetches).toHaveLength(before);
		});
	}

	it('retries only network timeout and 5xx failures with bounded timers', async () => {
		for (const failure of ['network', 'timeout', '500'] as const) {
			const runtime = harness((url) => {
				if (!url.includes('pending?')) return Promise.resolve(response(200));
				if (failure === '500') return Promise.resolve(response(500));
				return Promise.reject(new Error(failure));
			});
			await settle();
			const delays = runtime.activeTimers().map((timer) => timer.delay);
			expect(delays).toContain(2000);
			expect(delays).toContain(15000);
		}
	});

	it('does not count a rejected result submission as applied', async () => {
		const runtime = harness((url) => {
			if (url.includes('pending?')) {
				return Promise.resolve(response(200, { items: [{ id: 'change-1', post_id: 42, editor_url: '/editor', status: 'queued', block_spec: { name: 'core/paragraph' } }] }));
			}
			if (url.endsWith('result')) return Promise.resolve(response(409));
			return Promise.resolve(response(200));
		});
		await settle();

		expect(runtime.fetches.some((url) => url.endsWith('result'))).toBe(true);
		expect(runtime.element('stonewright-finalizer-applied-count').textContent).toBe('0');
		expect(runtime.activeTimers()).toHaveLength(0);
	});

	it('cleans up timers on unload and stays closed', async () => {
		const runtime = harness((url) => Promise.resolve(url.includes('pending?') ? response(200, { items: [] }) : response(200)));
		await settle();
		expect(runtime.activeTimers().length).toBeGreaterThan(0);

		runtime.listeners.get('unload')?.();
		expect(runtime.activeTimers()).toHaveLength(0);
		const before = runtime.fetches.length;
		for (const timer of runtime.timers) timer.callback();
		await settle();
		expect(runtime.fetches).toHaveLength(before);
	});
});
