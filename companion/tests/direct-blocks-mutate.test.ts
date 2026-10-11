import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import './helpers/task-start.js';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createMcpServer } from '../src/mcp-server.js';
import { runWithDirectCallContext } from '../src/direct/plugin-presence.js';
import { DirectSafetyBlockedError } from '../src/direct/writes.js';
import { resetConfirmationsForTests } from '../src/direct/confirm-token.js';
import {
	DIRECT_BOOTSTRAP_TOOL_NAMES,
	DIRECT_ESSENTIAL_TOOL_NAMES,
	DIRECT_TOOL_NAMES,
	shouldRegisterDirectTool,
	type DirectToolProfile,
} from '../src/direct/registry.js';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { blocksBatchMutate, type BlockOperation } from '../src/direct/tools/blocks.js';
import { resetBlockRegistryCacheForTests } from '../src/direct/block-registry.js';
import { parseBlockMarkup, serializeBlocks } from '../src/direct/block-markup.js';
import { makeFakeWp, pageRow, type FakeWp } from './helpers/fake-wp.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

const sha = (text: string) => createHash('sha256').update(text).digest('hex');
const para = (text: string) => `<!-- wp:paragraph -->\n<p>${text}</p>\n<!-- /wp:paragraph -->`;
const group = (...inner: string[]) =>
	`<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">\n\n${inner.join('\n\n')}\n\n</div>\n<!-- /wp:group -->`;
const heading = (text: string) => `<!-- wp:heading -->\n<h2 class="wp-block-heading">${text}</h2>\n<!-- /wp:heading -->`;

const PAGE = [heading('Title'), para('Intro'), group(para('Inside one'), para('Inside two')), '<!-- wp:latest-posts {"postsToShow":3} /-->'].join('\n\n');

describe('stonewright-blocks-batch-mutate', () => {
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];
	let wp: FakeWp;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-blocks-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		resetBlockRegistryCacheForTests();
		wp = makeFakeWp({ '/wp/v2/pages/7': pageRow(7, { content: { raw: PAGE, rendered: '<p>x</p>' } }) });
	});

	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		rmSync(stateDir, { recursive: true, force: true });
	});

	const env = { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'on', STONEWRIGHT_DIRECT_PROBE: 'off' };

	function ctx(fetchImpl: unknown = wp.fetchImpl) {
		const client = new WpRestClient(site, { fetchImpl: fetchImpl as typeof fetch });
		return { client, site, writeMode: 'on' as const, env: { ...env, STONEWRIGHT_STATE_DIR: stateDir } };
	}

	const stored = () => ((wp.store.get('/wp/v2/pages/7')?.['content'] as Record<string, unknown>)['raw'] as string);
	const puts = () => wp.calls.filter((call) => call.method === 'PUT' || call.method === 'POST');

	async function plan(operations: BlockOperation[]) {
		const out = await blocksBatchMutate(ctx(), { post_id: 7, operations, dry_run: true });
		return out as Record<string, unknown>;
	}

	async function apply(operations: BlockOperation[], hash = sha(PAGE)) {
		return (await blocksBatchMutate(ctx(), { post_id: 7, operations, expected_content_hash: hash })) as Record<string, unknown>;
	}

	describe('planning and the hash', () => {
		it('a dry run reports both hashes and the blocks, and writes nothing', async () => {
			const out = await plan([{ action: 'update', path: [1], innerHTML: '\n<p>Changed</p>\n' }]);
			expect(out).toMatchObject({ ok: true, dry_run: true, written: false, applied: 1, before_hash: sha(PAGE) });
			expect(out['after_hash']).toMatch(/^[a-f0-9]{64}$/);
			expect(out['after_hash']).not.toBe(out['before_hash']);
			expect(out['preview_summary']).toMatchObject({ root_block_count: 4 });
			expect(puts()).toEqual([]);
			expect(stored()).toBe(PAGE);
		});

		it('a write without expected_content_hash is refused and names the current hash', async () => {
			await expect(
				blocksBatchMutate(ctx(), { post_id: 7, operations: [{ action: 'remove', path: [1] }] }),
			).rejects.toThrow(new RegExp(`${sha(PAGE)}[\\s\\S]*missing_expected_content_hash`));
			expect(puts()).toEqual([]);
		});

		it('a stale hash is refused as a conflict and nothing is written', async () => {
			await expect(apply([{ action: 'remove', path: [1] }], 'a'.repeat(64))).rejects.toThrow(/content_conflict/);
			expect(puts()).toEqual([]);
		});

		it('refuses a conflict when the page changes between the read and the write', async () => {
			let pageReads = 0;
			const racing = ((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
				const path = new URL(String(url)).pathname;
				if (path.endsWith('/wp/v2/pages/7') && (init?.method ?? 'GET') === 'GET') {
					pageReads += 1;
					// Read 1: the plan. Read 2: the saved copy. Read 3: the check just before the write.
					if (pageReads === 3) {
						(wp.store.get('/wp/v2/pages/7')?.['content'] as Record<string, unknown>)['raw'] = `${PAGE}\n\n${para('Added by someone else')}`;
					}
				}
				return wp.fetchImpl(url, init) as Promise<Response>;
			}) as typeof fetch;
			await expect(
				blocksBatchMutate(ctx(racing), { post_id: 7, operations: [{ action: 'remove', path: [1] }], expected_content_hash: sha(PAGE) }),
			).rejects.toThrow(/content_conflict/);
			expect(puts()).toEqual([]);
		});
	});

	describe('update', () => {
		it('changes one block and leaves every other byte of the page as it was', async () => {
			const out = await apply([{ action: 'update', path: [1], innerHTML: '\n<p>Changed intro</p>\n' }]);
			expect(out).toMatchObject({ ok: true, dry_run: false, applied: 1, before_hash: sha(PAGE) });
			expect(stored()).toBe(PAGE.replace('<p>Intro</p>', '<p>Changed intro</p>'));
			expect(out['after_hash']).toBe(sha(stored()));
			expect(out['readback_hash']).toBe(sha(stored()));
			expect(puts()).toHaveLength(1);
			expect(puts()[0]?.body).toEqual({ content: stored() });
		});

		it('saves a copy first, reads the page back and says how to restore', async () => {
			const out = await apply([{ action: 'update', path: [1], innerHTML: '\n<p>Changed</p>\n' }]);
			expect(String(out['backup_id'])).toMatch(/^stonewright-blocks-batch-mutate\/pages-7\/.+\.json$/);
			expect(out['backup_sha256']).toMatch(/^[a-f0-9]{64}$/);
			expect(out['verification']).toMatchObject({ status: 'verified' });
		});

		it('changes a nested block by its path', async () => {
			await apply([{ action: 'update', path: [2, 1], innerHTML: '\n<p>Deep change</p>\n' }]);
			expect(stored()).toBe(PAGE.replace('<p>Inside two</p>', '<p>Deep change</p>'));
		});

		it('merges attributes into a block that has no markup of its own, and removes one set to null', async () => {
			await apply([{ action: 'update', path: [3], attrs: { postsToShow: 5, order: 'asc' } }]);
			expect(stored()).toContain('<!-- wp:latest-posts {"postsToShow":5,"order":"asc"} /-->');
			wp.store.get('/wp/v2/pages/7')!['content'] = { raw: stored(), rendered: '' };
			await blocksBatchMutate(ctx(), { post_id: 7, operations: [{ action: 'update', path: [3], attrs: { order: null } }], expected_content_hash: sha(stored()) });
			expect(stored()).toContain('<!-- wp:latest-posts {"postsToShow":5} /-->');
		});

		it('takes attributes and the markup that matches them together on a static block', async () => {
			await apply([{ action: 'update', path: [0], attrs: { level: 3 }, innerHTML: '\n<h3 class="wp-block-heading">Title</h3>\n' }]);
			expect(stored()).toContain('<!-- wp:heading {"level":3} -->\n<h3 class="wp-block-heading">Title</h3>\n<!-- /wp:heading -->');
		});

		it('refuses attributes alone on a block whose markup would then be out of date', async () => {
			await expect(apply([{ action: 'update', path: [0], attrs: { level: 3 } }])).rejects.toThrow(/markup_not_regenerated/);
			await expect(apply([{ action: 'update', path: [2], attrs: { layout: { type: 'flex' } } }])).rejects.toThrow(/markup_not_regenerated/);
			expect(puts()).toEqual([]);
		});

		it('refuses new markup on a block that holds inner blocks', async () => {
			await expect(apply([{ action: 'update', path: [2], innerHTML: '<div></div>' }])).rejects.toThrow(/unsafe_nested_structure/);
		});

		it('does not write when nothing changed', async () => {
			const out = await apply([{ action: 'update', path: [1], innerHTML: '\n<p>Intro</p>\n' }]);
			expect(out).toMatchObject({ ok: true, unchanged: true, written: false });
			expect(puts()).toEqual([]);
		});
	});

	describe('insert', () => {
		const newPara = { blockName: 'core/paragraph', innerHTML: '\n<p>New</p>\n' };

		it('appends at the root by default, after the blocks that are there', async () => {
			const out = await apply([{ action: 'insert', block: newPara }]);
			expect(out['items']).toEqual([{ index: 0, ok: true, action: 'insert', path: [4] }]);
			expect(stored()).toBe(`${PAGE}\n\n${para('New')}`);
		});

		it('inserts at a position, and before or after an anchor', async () => {
			await apply([{ action: 'insert', position: 1, block: newPara }]);
			expect(stored()).toBe([heading('Title'), para('New'), para('Intro'), group(para('Inside one'), para('Inside two')), '<!-- wp:latest-posts {"postsToShow":3} /-->'].join('\n\n'));
			const out = await plan([{ action: 'insert', before_path: [0], block: newPara }, { action: 'insert', after_path: [1], block: newPara }]);
			expect(out['items']).toEqual([
				{ index: 0, ok: true, action: 'insert', path: [0] },
				{ index: 1, ok: true, action: 'insert', path: [2] },
			]);
		});

		it('inserts into a container between its children', async () => {
			await apply([{ action: 'insert', path: [2], position: 1, block: newPara }]);
			expect(stored()).toBe(PAGE.replace(para('Inside two'), `${para('New')}\n\n${para('Inside two')}`));
			const parsed = parseBlockMarkup(stored());
			expect(parsed.diagnostics).toEqual([]);
		});

		it('appends to the end of a container', async () => {
			await apply([{ action: 'insert', path: [2], block: newPara }]);
			expect(stored()).toBe(PAGE.replace(para('Inside two'), `${para('Inside two')}\n\n${para('New')}`));
		});

		it('inserts a block that has children of its own', async () => {
			await apply([
				{
					action: 'insert',
					position: 0,
					block: {
						blockName: 'core/group',
						attrs: { layout: { type: 'constrained' } },
						innerContent: ['\n<div class="wp-block-group">\n\n', null, '\n\n</div>\n'],
						innerBlocks: [newPara],
					},
				},
			]);
			expect(stored().startsWith(`${group(para('New'))}\n\n${heading('Title')}`)).toBe(true);
			expect(parseBlockMarkup(stored()).diagnostics).toEqual([]);
		});

		it('refuses a container that has no children where a child would go', async () => {
			const empty = `<!-- wp:group -->\n<div class="wp-block-group"></div>\n<!-- /wp:group -->`;
			wp.store.set('/wp/v2/pages/8', pageRow(8, { content: { raw: empty, rendered: '' } }));
			await expect(
				blocksBatchMutate(ctx(), { post_id: 8, operations: [{ action: 'insert', path: [0], block: newPara }], expected_content_hash: sha(empty) }),
			).rejects.toThrow(/unsafe_nested_structure/);
		});

		it('refuses an unknown block when the site lists its blocks, and allows it when it does not', async () => {
			const withRegistry = ((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
				if (new URL(String(url)).pathname.endsWith('/wp/v2/block-types')) {
					return Promise.resolve(new Response(JSON.stringify([{ name: 'core/paragraph' }, { name: 'core/heading' }]), { status: 200, headers: { 'content-type': 'application/json' } }));
				}
				return wp.fetchImpl(url, init) as Promise<Response>;
			}) as typeof fetch;
			await expect(
				blocksBatchMutate(ctx(withRegistry), { post_id: 7, operations: [{ action: 'insert', block: { blockName: 'acme/hero', innerHTML: '<div></div>' } }], dry_run: true }),
			).rejects.toThrow(/unknown_block/);
			const ok = await blocksBatchMutate(ctx(withRegistry), { post_id: 7, operations: [{ action: 'insert', block: newPara }], dry_run: true });
			expect(ok).toMatchObject({ ok: true });
			// Without a readable list nothing is refused for its name.
			resetBlockRegistryCacheForTests();
			const unlisted = await blocksBatchMutate(ctx(), { post_id: 7, operations: [{ action: 'insert', block: { blockName: 'acme/hero', innerHTML: '<div></div>' } }], dry_run: true });
			expect(unlisted).toMatchObject({ ok: true });
		});

		it('refuses a block without a valid name, attributes or content', async () => {
			await expect(plan([{ action: 'insert', block: { innerHTML: 'x' } as never }])).rejects.toThrow(/invalid_block/);
			await expect(plan([{ action: 'insert', block: { blockName: 'Bad Name', innerHTML: 'x' } }])).rejects.toThrow(/invalid_block/);
			await expect(plan([{ action: 'insert', block: { blockName: 'core/paragraph', attrs: [] as never } }])).rejects.toThrow(/invalid_block/);
			await expect(
				plan([{ action: 'insert', block: { blockName: 'core/group', innerHTML: '<div>', innerBlocks: [newPara] } }]),
			).rejects.toThrow(/unsafe_nested_structure/);
		});
	});

	describe('remove', () => {
		it('removes a root block and the blank line after it', async () => {
			await apply([{ action: 'remove', path: [1] }]);
			expect(stored()).toBe([heading('Title'), group(para('Inside one'), para('Inside two')), '<!-- wp:latest-posts {"postsToShow":3} /-->'].join('\n\n'));
		});

		it('removes the last root block', async () => {
			await apply([{ action: 'remove', path: [3] }]);
			expect(stored()).toBe([heading('Title'), para('Intro'), group(para('Inside one'), para('Inside two'))].join('\n\n'));
		});

		it('removes a child from a container, first and last', async () => {
			await apply([{ action: 'remove', path: [2, 0] }]);
			expect(stored()).toBe(PAGE.replace(`${para('Inside one')}\n\n`, ''));
			wp.store.get('/wp/v2/pages/7')!['content'] = { raw: PAGE, rendered: '' };
			await apply([{ action: 'remove', path: [2, 1] }]);
			expect(stored()).toBe(PAGE.replace(`\n\n${para('Inside two')}`, ''));
			expect(parseBlockMarkup(stored()).diagnostics).toEqual([]);
		});

		it('removes the only child of a container and keeps the container', async () => {
			const single = group(para('Only'));
			wp.store.set('/wp/v2/pages/8', pageRow(8, { content: { raw: single, rendered: '' } }));
			await blocksBatchMutate(ctx(), { post_id: 8, operations: [{ action: 'remove', path: [0, 0] }], expected_content_hash: sha(single) });
			const after = (wp.store.get('/wp/v2/pages/8')?.['content'] as Record<string, string>)['raw'];
			const parsed = parseBlockMarkup(after);
			expect(parsed.diagnostics).toEqual([]);
			expect(parsed.blocks[0]?.innerBlocks).toHaveLength(0);
			expect(after).toContain('<div class="wp-block-group">');
		});
	});

	describe('a batch is all or nothing, in order', () => {
		it('applies the operations one after the other, each on what the last one left', async () => {
			const out = await apply([
				{ action: 'remove', path: [0] },
				{ action: 'update', path: [0], innerHTML: '\n<p>Now first</p>\n' },
			]);
			expect(out).toMatchObject({ ok: true, applied: 2 });
			expect(stored().startsWith(para('Now first'))).toBe(true);
		});

		it('writes nothing when a later operation fails, and names it', async () => {
			await expect(
				apply([
					{ action: 'update', path: [1], innerHTML: '\n<p>One</p>\n' },
					{ action: 'update', path: [9], innerHTML: '\n<p>Two</p>\n' },
				]),
			).rejects.toThrow(/Operation 1[\s\S]*invalid_path[\s\S]*batch_operation_failed/);
			expect(puts()).toEqual([]);
			expect(stored()).toBe(PAGE);
		});

		it('refuses a path that does not exist', async () => {
			await expect(plan([{ action: 'remove', path: [] }])).rejects.toThrow(/invalid_path/);
			await expect(plan([{ action: 'remove', path: [2, 5] }])).rejects.toThrow(/invalid_path/);
			await expect(plan([{ action: 'insert', path: [9], block: { blockName: 'core/paragraph', innerHTML: 'x' } }])).rejects.toThrow(/invalid_path/);
			await expect(plan([{ action: 'insert', before_path: [0], after_path: [1], block: { blockName: 'core/paragraph', innerHTML: 'x' } }])).rejects.toThrow(/ambiguous_anchor/);
		});
	});

	describe('the markup it will not write', () => {
		it('refuses to edit a page whose block comments are already broken', async () => {
			const broken = `${para('a')}\n\n<!-- wp:heading -->\n<h2>b</h2>`;
			wp.store.set('/wp/v2/pages/8', pageRow(8, { content: { raw: broken, rendered: '' } }));
			await expect(
				blocksBatchMutate(ctx(), { post_id: 8, operations: [{ action: 'remove', path: [0] }], expected_content_hash: sha(broken) }),
			).rejects.toThrow(/invalid_block_markup/);
		});

		it('does not let new markup carry block comments', async () => {
			await expect(plan([{ action: 'update', path: [1], innerHTML: '<p>x</p><!-- /wp:paragraph --><!-- wp:paragraph -->' }])).rejects.toThrow(/block comment/i);
			await expect(plan([{ action: 'insert', block: { blockName: 'core/html', innerHTML: '<!-- wp:group -->' } }])).rejects.toThrow(/block comment/i);
		});

		it.each([
			['a script in an inserted html block', { action: 'insert', block: { blockName: 'core/html', innerHTML: '\n<script>x()</script>\n' } }, 'script'],
			['an iframe in an inserted html block', { action: 'insert', block: { blockName: 'core/html', innerHTML: '<iframe src="https://example.com"></iframe>' } }, 'iframe'],
			['an inline handler in an update', { action: 'update', path: [1], innerHTML: '\n<p onclick="x()">a</p>\n' }, 'event_handler'],
			['a javascript URL in a block attribute', { action: 'update', path: [3], attrs: { url: 'javascript:alert(1)' } }, 'javascript_url'],
			['a style element in a nested inserted block', { action: 'insert', block: { blockName: 'core/group', innerContent: ['<div>', null, '</div>'], innerBlocks: [{ blockName: 'core/html', innerHTML: '<style>a{}</style>' }] } }, 'style'],
		] as Array<[string, BlockOperation, string]>)('refuses %s with the custom-code envelope, in a plan and in a write', async (_label, operation, kind) => {
			for (const dryRun of [true, false]) {
				const out = (await blocksBatchMutate(ctx(), {
					post_id: 7,
					operations: [operation],
					...(dryRun ? { dry_run: true } : { expected_content_hash: sha(PAGE) }),
				})) as Record<string, unknown>;
				expect(out).toMatchObject({ supported: false, approval_required: true, agent_must_stop: true, error: 'custom_code_approval_required', written: false });
				expect((out['findings'] as Array<{ kind: string }>).map((finding) => finding.kind)).toContain(kind);
			}
			expect(puts()).toEqual([]);
		});

		it('does not refuse a page for custom code it already holds, when the edit does not add any', async () => {
			const held = `${para('a')}\n\n<!-- wp:html -->\n<script>old()</script>\n<!-- /wp:html -->`;
			wp.store.set('/wp/v2/pages/8', pageRow(8, { content: { raw: held, rendered: '' } }));
			const out = (await blocksBatchMutate(ctx(), { post_id: 8, operations: [{ action: 'update', path: [0], innerHTML: '\n<p>b</p>\n' }], expected_content_hash: sha(held) })) as Record<string, unknown>;
			expect(out).toMatchObject({ ok: true });
			const after = (wp.store.get('/wp/v2/pages/8')?.['content'] as Record<string, string>)['raw'];
			expect(after).toContain('<script>old()</script>');
		});
	});

	describe('confirmation in confirm mode', () => {
		beforeEach(() => resetConfirmationsForTests());

		const confirmCtx = () => ({ ...ctx(), writeMode: 'confirm' as const });
		const edit: BlockOperation[] = [{ action: 'remove', path: [1] }];

		function run(confirmationId?: string, operations: BlockOperation[] = edit) {
			const args = { post_id: 7, operations, expected_content_hash: sha(PAGE), ...(confirmationId ? { confirmation_id: confirmationId } : {}) };
			return runWithDirectCallContext(
				{ tool: 'stonewright-blocks-batch-mutate', confirm: false, args, confirmationId, env: confirmCtx().env },
				() => blocksBatchMutate(confirmCtx(), { post_id: 7, operations, expected_content_hash: sha(PAGE) }),
			);
		}

		it('a plan needs no confirmation', async () => {
			const out = await blocksBatchMutate(confirmCtx(), { post_id: 7, operations: edit, dry_run: true });
			expect(out).toMatchObject({ ok: true, dry_run: true });
		});

		it('a write is refused with a confirmation_id, writes nothing, and goes through when repeated with it', async () => {
			let refused: DirectSafetyBlockedError | undefined;
			await run().catch((error: unknown) => {
				refused = error as DirectSafetyBlockedError;
			});
			expect(refused?.code).toBe('confirmation_required');
			const id = String(refused?.details['confirmation_id']);
			expect(puts()).toEqual([]);
			expect(stored()).toBe(PAGE);
			const done = (await run(id)) as Record<string, unknown>;
			expect(done).toMatchObject({ ok: true, written: true });
			expect(stored()).not.toBe(PAGE);
		});

		it('an id does not fit a different edit, and a plain confirm:true does not count on a remote site', async () => {
			let refused: DirectSafetyBlockedError | undefined;
			await run().catch((error: unknown) => {
				refused = error as DirectSafetyBlockedError;
			});
			const id = String(refused?.details['confirmation_id']);
			await expect(run(id, [{ action: 'remove', path: [0] }])).rejects.toMatchObject({ code: 'confirmation_invalid' });
			expect(puts()).toEqual([]);
			await expect(
				blocksBatchMutate({ ...confirmCtx(), site: { ...site, alias: 'remote', url: 'https://example.com' } }, { post_id: 7, operations: edit, expected_content_hash: sha(PAGE), confirm: true }),
			).rejects.toMatchObject({ code: 'confirmation_required' });
		});

		it('a bad operation is reported before any confirmation is asked for', async () => {
			await expect(run(undefined, [{ action: 'remove', path: [9] }])).rejects.toMatchObject({ code: 'batch_operation_failed' });
		});
	});

	describe('after the write', () => {
		it('reports a readback mismatch with the way back, and keeps the saved copy', async () => {
			wp.sanitize = (_path, body) => ({ ...body, content: PAGE });
			const out = await apply([{ action: 'update', path: [1], innerHTML: '\n<p>Changed</p>\n' }]);
			expect(out).toMatchObject({ ok: false, error: 'verification_mismatch', written: true });
			expect(out['restore_with']).toMatchObject({ tool: 'stonewright-direct-backup-restore' });
		});

		it('fails closed when the copy cannot be saved', async () => {
			const failing = ((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
				const reads = wp.calls.filter((call) => call.method === 'GET' && call.path.endsWith('/wp/v2/pages/7')).length;
				if ((init?.method ?? 'GET') === 'GET' && reads >= 1) {
					return Promise.resolve(new Response(JSON.stringify({ code: 'rest_forbidden', message: 'no' }), { status: 403, headers: { 'content-type': 'application/json' } }));
				}
				return wp.fetchImpl(url, init) as Promise<Response>;
			}) as typeof fetch;
			await expect(
				blocksBatchMutate(ctx(failing), { post_id: 7, operations: [{ action: 'remove', path: [1] }], expected_content_hash: sha(PAGE) }),
			).rejects.toThrow(/preimage_unavailable|backed up/);
			expect(puts()).toEqual([]);
		});

		it('finds a post by id when the type is not given, and honors a given type', async () => {
			wp.store.set('/wp/v2/posts/21', pageRow(21, { type: 'post', content: { raw: para('post body'), rendered: '' } }));
			const out = (await blocksBatchMutate(ctx(), { post_id: 21, operations: [{ action: 'update', path: [0], innerHTML: '\n<p>edited</p>\n' }], expected_content_hash: sha(para('post body')) })) as Record<string, unknown>;
			expect(out).toMatchObject({ ok: true, post_id: 21 });
			const explicit = (await blocksBatchMutate(ctx(), { post_id: 21, type: 'posts', operations: [{ action: 'remove', path: [0] }], dry_run: true })) as Record<string, unknown>;
			expect(explicit).toMatchObject({ ok: true });
		});

		it('keeps untouched markup identical through a parse and write cycle', () => {
			expect(serializeBlocks(parseBlockMarkup(PAGE).blocks)).toBe(PAGE);
		});
	});
});

describe('stonewright-blocks-batch-mutate registration', () => {
	const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
	const TOOL = 'stonewright-blocks-batch-mutate';
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-blocks-reg-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		process.env['STONEWRIGHT_DIRECT_PROBE'] = 'off';
		process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'] = 'off';
		resetBlockRegistryCacheForTests();
	});

	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		delete process.env['STONEWRIGHT_DIRECT_PROBE'];
		delete process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'];
		rmSync(stateDir, { recursive: true, force: true });
	});

	type Tool = {
		inputSchema?: { shape?: Record<string, unknown> };
		description?: string;
		enabled?: boolean;
		handler?: (input: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;
	};

	async function connect(fetchImpl: typeof fetch) {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test',
				STONEWRIGHT_WP_USERNAME: 'editor-user',
				STONEWRIGHT_WP_APP_PASSWORD: 'fixture-app-pass-0001',
				STONEWRIGHT_DIRECT_WRITES: 'on',
				STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
				STONEWRIGHT_DIRECT_PROBE: 'off',
			},
			fetchImpl,
		});
		return (server as { _registeredTools?: Record<string, Tool> })._registeredTools ?? {};
	}

	it('uses the Plugin ability name and its argument names', () => {
		const php = readFileSync(join(repoRoot, 'plugin', 'includes', 'Abilities', 'Gutenberg', 'BlocksBatchMutate.php'), 'utf8');
		expect(php).toContain("'stonewright/blocks-batch-mutate'");
		for (const name of ['post_id', 'operations', 'expected_content_hash', 'dry_run', 'path', 'position', 'before_path', 'after_path', 'block', 'attrs', 'innerHTML']) {
			expect(php, name).toContain(`'${name}'`);
		}
		const matrix = readFileSync(join(repoRoot, 'docs', 'ability-truth-matrix.md'), 'utf8');
		expect(matrix).toContain(`\`${TOOL}\``);
	});

	it('is a Direct tool for the gutenberg and full profiles only, and in the frozen contract', () => {
		expect(DIRECT_TOOL_NAMES as readonly string[]).toContain(TOOL);
		expect(DIRECT_BOOTSTRAP_TOOL_NAMES as readonly string[]).not.toContain(TOOL);
		expect(DIRECT_ESSENTIAL_TOOL_NAMES as readonly string[]).not.toContain(TOOL);
		const profiles: DirectToolProfile[] = ['bootstrap', 'essential', 'essential-static', 'elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full'];
		expect(profiles.filter((profile) => shouldRegisterDirectTool(TOOL, profile))).toEqual(['gutenberg', 'full']);
		const contract = JSON.parse(readFileSync(join(repoRoot, 'docs', 'contracts', 'direct-tools-v1.json'), 'utf8')) as { tools: Array<{ name: string }> };
		expect(contract.tools.map((tool) => tool.name)).toContain(TOOL);
	});

	it('is registered with the documented arguments and runs end to end through the server', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/7': pageRow(7, { content: { raw: PAGE, rendered: '<p>x</p>' } }) });
		const tools = await connect(wp.fetchImpl as never);
		const tool = tools[TOOL];
		expect(tool?.enabled).toBe(true);
		expect(Object.keys(tool?.inputSchema?.shape ?? {}).sort()).toEqual(['confirm', 'confirmation_id', 'dry_run', 'expected_content_hash', 'operations', 'post_id', 'probe', 'rollback_on_probe_failure', 'site', 'type']);
		expect(tool?.description).toMatch(/expected_content_hash/);
		expect(tool?.description).toMatch(/dry_run/);

		const planned = JSON.parse((await tool?.handler?.({ post_id: 7, dry_run: true, operations: [{ action: 'update', path: [1], innerHTML: '\n<p>Via MCP</p>\n' }] }))?.content[0]?.text ?? '{}') as Record<string, unknown>;
		expect(planned).toMatchObject({ ok: true, dry_run: true, before_hash: sha(PAGE) });
		const written = JSON.parse((await tool?.handler?.({ post_id: 7, expected_content_hash: planned['before_hash'], operations: [{ action: 'update', path: [1], innerHTML: '\n<p>Via MCP</p>\n' }] }))?.content[0]?.text ?? '{}') as Record<string, unknown>;
		expect(written).toMatchObject({ ok: true, written: true, verification: { status: 'verified' }, after_hash: planned['after_hash'] });
		expect(((wp.store.get('/wp/v2/pages/7')?.['content'] as Record<string, string>)['raw'])).toContain('<p>Via MCP</p>');
	});

	it('refuses a stale hash through the server as a refusal, not a failure', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/7': pageRow(7, { content: { raw: PAGE, rendered: '<p>x</p>' } }) });
		const tools = await connect(wp.fetchImpl as never);
		const out = await tools[TOOL]?.handler?.({ post_id: 7, expected_content_hash: 'b'.repeat(64), operations: [{ action: 'remove', path: [1] }] });
		expect(out?.isError).toBe(true);
		expect(JSON.parse(out?.content[0]?.text ?? '{}')).toMatchObject({ ok: false, error: 'content_conflict', blocked: true });
	});
});
