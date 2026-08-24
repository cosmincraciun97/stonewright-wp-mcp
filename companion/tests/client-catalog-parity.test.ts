import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { listClientCatalog } from '../src/cli/clients/index.js';

type PluginClient = {
	slug: string;
	label: string;
	config_format: string;
	official_cli_add: string;
	oauth_support: boolean;
	app_password_support: boolean;
	relist_behavior: string;
	new_task_required_after_catalog_change: boolean;
	safe_tool_budget: number;
	default_profile: string;
	support_tier: string;
};

describe('authoritative client catalog parity', () => {
	it('keeps companion OAuth, profile, and relist semantics equal to the plugin catalog', () => {
		const dir = join(import.meta.dirname, '../../plugin/data/clients');
		const plugin = readdirSync(dir)
			.filter((name) => name.endsWith('.json'))
			.map((name) => JSON.parse(readFileSync(join(dir, name), 'utf8')) as PluginClient)
			.sort((a, b) => a.slug.localeCompare(b.slug));
		const companion = new Map(listClientCatalog().map((client) => [client.id, client]));

		for (const expected of plugin) {
			expect(companion.get(expected.slug), expected.slug).toEqual(expect.objectContaining({
				id: expected.slug,
				label: expected.label,
				configFormat: expected.config_format,
				officialCliAdd: expected.official_cli_add,
				oauthSupport: expected.oauth_support,
				appPasswordSupport: expected.app_password_support,
				relistBehavior: expected.relist_behavior,
				newTaskRequiredAfterCatalogChange: expected.new_task_required_after_catalog_change,
				safeToolBudget: expected.safe_tool_budget,
				defaultProfile: expected.default_profile,
				supportTier: expected.support_tier,
			}));
		}
	});
});
