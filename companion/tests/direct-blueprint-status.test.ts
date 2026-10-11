import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { applyBlueprint } from '../src/direct/tools/blueprints.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('blueprint-apply keeps the status of an existing page', () => {
	let stateDir: string;
	const original = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-bp-status-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});

	afterEach(() => {
		if (original === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = original;
		rmSync(stateDir, { recursive: true, force: true });
	});

	/** A page that is published and keeps what is written to it. */
	function client() {
		const page: Record<string, unknown> = { id: 9, status: 'publish', link: 'http://example.test/about/' };
		const bodies: Array<Record<string, unknown>> = [];
		const fetchImpl = vi.fn((_url: unknown, init?: RequestInit) => {
			if (init?.body) {
				const body = JSON.parse(String(init.body)) as Record<string, unknown>;
				bodies.push(body);
				for (const [key, value] of Object.entries(body)) {
					page[key] = ['title', 'content'].includes(key) && typeof value === 'string' ? { raw: value } : value;
				}
			}
			return Promise.resolve(new Response(JSON.stringify(page), { status: 200, headers: { 'content-type': 'application/json' } }));
		});
		return { bodies, page, client: new WpRestClient(site, { fetchImpl }) };
	}

	const env = { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on' };

	it('does not send a status when post_id is given without one', async () => {
		const { bodies, page, client: wp } = client();
		const result = await applyBlueprint(wp, { id: 'agency', post_id: 9 }, env);
		expect(result).toMatchObject({ ok: true, post_id: 9 });
		expect(bodies).toHaveLength(1);
		expect(bodies[0]).not.toHaveProperty('status');
		expect(page['status']).toBe('publish');
	});

	it('still sets the status when one is given with post_id', async () => {
		const { bodies, page, client: wp } = client();
		await applyBlueprint(wp, { id: 'agency', post_id: 9, status: 'draft' }, env);
		expect(bodies[0]).toMatchObject({ status: 'draft' });
		expect(page['status']).toBe('draft');
	});

	it('creates a new page as a draft when no status is given', async () => {
		const { bodies, client: wp } = client();
		await applyBlueprint(wp, { id: 'agency' }, env);
		expect(bodies[0]).toMatchObject({ status: 'draft' });
	});
});
