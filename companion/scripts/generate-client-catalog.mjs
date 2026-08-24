import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const companionDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const sourceDir = join(companionDir, '..', 'plugin', 'data', 'clients');
const target = join(companionDir, 'src', 'contracts', 'client-catalog.generated.ts');
const clients = readdirSync(sourceDir)
	.filter((name) => name.endsWith('.json'))
	.map((name) => JSON.parse(readFileSync(join(sourceDir, name), 'utf8')))
	.map((client) => ({
		id: client.slug,
		label: client.label,
		supportTier: client.support_tier,
		configFormat: client.config_format,
		officialCliAdd: client.official_cli_add,
		oauthSupport: client.oauth_support,
		appPasswordSupport: client.app_password_support,
		relistBehavior: client.relist_behavior,
		newTaskRequiredAfterCatalogChange: client.new_task_required_after_catalog_change,
		safeToolBudget: client.safe_tool_budget,
		defaultProfile: client.default_profile,
	}))
	.sort((a, b) => a.id.localeCompare(b.id));
const output = `/** Generated from plugin/data/clients/*.json. Do not edit by hand. */\nexport const AUTHORITATIVE_CLIENT_CATALOG = ${JSON.stringify(clients, null, '\t')} as const;\n`;

if (process.argv.includes('--check')) {
	if (readFileSync(target, 'utf8') !== output) {
		throw new Error('Generated companion client catalog is stale. Run npm run catalog:generate.');
	}
} else {
	writeFileSync(target, output, 'utf8');
}
