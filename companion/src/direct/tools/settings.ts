import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { DirectToolContext } from './types.js';

export async function settingsGet(ctx: DirectToolContext) {
	assertToolEnabled(ctx.site, 'stonewright-settings-get');
	const settings = await ctx.client.get<Record<string, unknown>>('/wp/v2/settings');
	return { settings: settings ?? {} };
}

export async function settingsUpdate(
	ctx: DirectToolContext,
	input: { settings: Record<string, unknown>; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-settings-update');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-settings-update',
	});
	if (!input.settings || typeof input.settings !== 'object' || Array.isArray(input.settings)) {
		throw new Error('settings must be an object of option keys to update');
	}
	try {
		const settings = await ctx.client.post<Record<string, unknown>>('/wp/v2/settings', {
			body: input.settings,
		});
		appendDirectAudit({
			tool: 'stonewright-settings-update',
			site: ctx.site.alias,
			resource: 'settings',
			status: 'ok',
		});
		return { settings: settings ?? {} };
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-settings-update',
			site: ctx.site.alias,
			resource: 'settings',
			status: 'error',
		});
		throw err;
	}
}
