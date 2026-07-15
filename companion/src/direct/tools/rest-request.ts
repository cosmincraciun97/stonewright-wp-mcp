import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import type { DirectToolContext } from './types.js';

const WRITE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

export async function restRequest(
	ctx: DirectToolContext,
	input: {
		method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
		path: string;
		query?: Record<string, string | number | boolean> | undefined;
		body?: Record<string, unknown> | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-rest-request');
	if (!input.path.startsWith('/') || input.path.includes('..') || /^https?:/i.test(input.path)) {
		throw new Error('path must be a REST route starting with "/", e.g. /custom-plugin/v1/items');
	}
	if (WRITE_METHODS.has(input.method)) {
		assertWriteAllowed({
			mode: ctx.writeMode,
			destructive: true,
			...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
			tool: 'stonewright-rest-request',
		});
	}
	const opts: { query?: Record<string, string | number | boolean | null | undefined>; body?: unknown } = {};
	if (input.query !== undefined) {
		opts.query = input.query;
	}
	if (input.body !== undefined) {
		opts.body = input.body;
	}
	switch (input.method) {
		case 'GET':
			return ctx.client.get(input.path, opts);
		case 'DELETE':
			return ctx.client.del(input.path, opts);
		case 'PUT':
			return ctx.client.put(input.path, { ...opts, body: input.body ?? {} });
		case 'PATCH':
			return ctx.client.request('PATCH', input.path, { ...opts, body: input.body ?? {} });
		default:
			return ctx.client.post(input.path, { ...opts, body: input.body ?? {} });
	}
}
