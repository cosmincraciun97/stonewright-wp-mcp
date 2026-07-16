import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const errors = [];

function read(relativePath) {
	return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function fail(message) {
	errors.push(message);
}

function walk(directory, output = []) {
	for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
		if (['.git', '.worktrees', 'dist', 'node_modules', 'vendor'].includes(entry.name)) continue;
		const absolute = path.join(directory, entry.name);
		if (entry.isDirectory()) walk(absolute, output);
		else if (entry.isFile() && entry.name.endsWith('.md')) output.push(absolute);
	}
	return output;
}

const pluginBootstrap = read('plugin/stonewright.php');
const pluginHeaderVersion = pluginBootstrap.match(/^ \* Version:\s*(\S+)/m)?.[1];
const pluginConstantVersion = pluginBootstrap.match(/define\(\s*'STONEWRIGHT_VERSION',\s*'([^']+)'\s*\)/)?.[1];
const companionPackageVersion = JSON.parse(read('companion/package.json')).version;
const companionSourceVersion = read('companion/src/version.ts').match(/APP_VERSION\s*=\s*'([^']+)'/)?.[1];

const versionValues = {
	'plugin header': pluginHeaderVersion,
	'plugin constant': pluginConstantVersion,
	'companion package': companionPackageVersion,
	'companion source': companionSourceVersion,
};
const canonicalVersion = pluginHeaderVersion;

for (const [label, value] of Object.entries(versionValues)) {
	if (!value) fail(`Could not read ${label} version.`);
	else if (canonicalVersion && value !== canonicalVersion) {
		fail(`${label} version ${value} does not match ${canonicalVersion}.`);
	}
}

const pluginReadmeVersion = read('plugin/README.md').match(/^Version:\s*(\S+)/m)?.[1];
if (canonicalVersion && pluginReadmeVersion !== canonicalVersion) {
	fail(`plugin/README.md version ${pluginReadmeVersion ?? 'missing'} does not match ${canonicalVersion}.`);
}

if (canonicalVersion && !fs.existsSync(path.join(repoRoot, `docs/releases/${canonicalVersion}.md`))) {
	fail(`Missing docs/releases/${canonicalVersion}.md.`);
}

const abilityCount = Number(read('docs/ability-truth-matrix.md').match(/Total abilities registered: \*\*(\d+)\*\*/)?.[1]);
if (!Number.isInteger(abilityCount) || abilityCount < 1) {
	fail('Could not read the generated plugin ability count.');
}

const directRegistry = read('companion/src/direct/registry.ts');
const directArrays = new Map();
for (const match of directRegistry.matchAll(/export const (DIRECT_[A-Z0-9_]+) = \[([\s\S]*?)\] as const;/g)) {
	directArrays.set(match[1], {
		names: [...match[2].matchAll(/'((?:stonewright)-[^']+)'/g)].map((item) => item[1]),
		spreads: [...match[2].matchAll(/\.\.\.(DIRECT_[A-Z0-9_]+)/g)].map((item) => item[1]),
	});
}

function resolveDirectNames(name, stack = new Set()) {
	if (stack.has(name)) throw new Error(`Circular Direct tool array reference: ${name}`);
	const value = directArrays.get(name);
	if (!value) throw new Error(`Missing Direct tool array: ${name}`);
	const nextStack = new Set(stack).add(name);
	return [...value.names, ...value.spreads.flatMap((spread) => resolveDirectNames(spread, nextStack))];
}

let directToolCount = 0;
try {
	directToolCount = new Set(resolveDirectNames('DIRECT_TOOL_NAMES')).size;
} catch (error) {
	fail(error instanceof Error ? error.message : String(error));
}

for (const [relativePath, expected] of [
	['README.md', [`**${abilityCount}** abilities`, `**${directToolCount}** tools`]],
	['companion/README.md', [`**${directToolCount}** Direct tools`]],
	['docs/direct-mode-e2e.md', [`**${directToolCount}** tools`]],
	[`docs/releases/${canonicalVersion}.md`, [`**${abilityCount}**`, `**${directToolCount}**`]],
]) {
	const content = read(relativePath);
	for (const marker of expected) {
		if (!content.includes(marker)) fail(`${relativePath} is missing current count marker: ${marker}`);
	}
}

for (const changelog of ['CHANGELOG.md', 'plugin/CHANGELOG.md']) {
	const changelogContent = read(changelog);
	const headings = [...changelogContent.matchAll(/^## \[([^\]]+)\] - \d{4}-\d{2}-\d{2}$/gm)];
	const firstVersionHeading = headings[0]?.[1];
	if (canonicalVersion && firstVersionHeading !== canonicalVersion) {
		fail(`${changelog} first dated release is ${firstVersionHeading ?? 'missing'}, expected ${canonicalVersion}.`);
	}
	if (headings.length > 5) fail(`${changelog} retains ${headings.length} releases; maximum is 5.`);
}

const historicalMarkdown = (relativePath) =>
	relativePath === 'CHANGELOG.md' ||
	relativePath === 'plugin/CHANGELOG.md' ||
	relativePath.startsWith('docs/releases/') ||
	relativePath.startsWith('docs/plans/') ||
	relativePath.startsWith('research/') ||
	[
		'docs/premium-corrections-handoff-report.md',
		'docs/migration-elementor-v3-tools.md',
		'docs/elementor-v3-editor-adapter.md',
	].includes(relativePath);

const markdownFiles = walk(repoRoot).filter((absolute) => {
	const relative = path.relative(repoRoot, absolute).split(path.sep).join('/');
	return !relative.startsWith('docs/knowledge/');
});

for (const absolute of markdownFiles) {
	const relative = path.relative(repoRoot, absolute).split(path.sep).join('/');
	const content = fs.readFileSync(absolute, 'utf8');

	if (!historicalMarkdown(relative) && /releases\/download\/v1\.0\.0-(?:alpha|beta|rc)\.\d+\//.test(content)) {
		fail(`${relative} pins a release asset; use the VERSION placeholder.`);
	}

	const linkPattern = /!?\[[^\]]*\]\(([^)]+)\)/g;
	for (const match of content.matchAll(linkPattern)) {
		let target = match[1].trim();
		if (target.startsWith('<') && target.endsWith('>')) target = target.slice(1, -1);
		target = target.split(/\s+["']/u, 1)[0];
		if (!target || target.startsWith('#') || /^(?:https?:|mailto:|tel:)/i.test(target)) continue;
		const withoutFragment = target.split('#', 1)[0];
		if (!withoutFragment || withoutFragment.includes('{') || withoutFragment.includes('VERSION')) continue;
		let resolved;
		try {
			resolved = decodeURIComponent(withoutFragment);
		} catch {
			fail(`${relative} contains an invalid encoded link: ${target}`);
			continue;
		}
		if (resolved.startsWith('/')) continue;
		const destination = path.resolve(path.dirname(absolute), resolved);
		if (!fs.existsSync(destination)) fail(`${relative} links to missing ${target}.`);
	}
}

const installPrompts = read('docs/install-prompts.md');
for (const required of [
	'vVERSION/stonewright-companion-VERSION.tgz',
	'Option A — With the Stonewright plugin',
	'Option B — Without the plugin',
	'stonewright-task-start',
	'STONEWRIGHT_DIRECT_WRITES=confirm',
]) {
	if (!installPrompts.includes(required)) fail(`docs/install-prompts.md is missing: ${required}`);
}

const agentRules = read('AGENTS.md');
for (const required of [
	'## Documentation freshness',
	'node scripts/check-docs-freshness.mjs',
	'docs/install-prompts.md',
	'`stonewright-task-start` is the canonical first call',
]) {
	if (!agentRules.includes(required)) fail(`AGENTS.md is missing documentation rule: ${required}`);
}

if (errors.length > 0) {
	console.error(`Documentation freshness failed with ${errors.length} error(s):`);
	for (const error of errors) console.error(`- ${error}`);
	process.exit(1);
}

console.log(`Documentation freshness passed for ${markdownFiles.length} maintained Markdown files (${canonicalVersion}, ${abilityCount} plugin abilities, ${directToolCount} Direct tools).`);
