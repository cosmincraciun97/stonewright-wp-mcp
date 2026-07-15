import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

export const HEALTH_TESTS = [
	'authorization-header',
	'background-updates',
	'dotorg-communication',
	'https-status',
	'loopback-requests',
	'page-cache',
] as const;

export type HealthTestName = (typeof HEALTH_TESTS)[number];

export async function healthTest(ctx: DirectToolContext, input: { test: HealthTestName }) {
	assertToolEnabled(ctx.site, 'stonewright-health-test');
	return ctx.client.get(`/wp-site-health/v1/tests/${input.test}`);
}

export async function healthCheck(ctx: DirectToolContext) {
	assertToolEnabled(ctx.site, 'stonewright-health-check');
	const results = await Promise.all(
		HEALTH_TESTS.map(async (test) => {
			try {
				return { test, result: await ctx.client.get(`/wp-site-health/v1/tests/${test}`) };
			} catch (err) {
				return { test, error: err instanceof Error ? err.message : String(err) };
			}
		}),
	);
	let directory_sizes: unknown;
	try {
		directory_sizes = await ctx.client.get('/wp-site-health/v1/directory-sizes');
	} catch (err) {
		directory_sizes = { error: err instanceof Error ? err.message : String(err) };
	}
	return {
		tests: results,
		directory_sizes,
		note: 'Site Health REST routes require an administrator application password (view_site_health_checks capability).',
	};
}
