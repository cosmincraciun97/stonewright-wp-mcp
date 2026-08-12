import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { releaseChannelFromNotes, releaseFlags } from './release-flags.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const errors = [];

function read(relativePath) {
	return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function fail(message) {
	errors.push(message);
}

function gitPaths(args) {
	try {
		return execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8' })
			.split('\0')
			.filter(Boolean)
			.map((entry) => entry.split(path.sep).join('/'));
	} catch (error) {
		fail(`Could not enumerate Markdown through git: ${error instanceof Error ? error.message : String(error)}`);
		return [];
	}
}

function gitMarkdownFiles() {
	const relativePaths = gitPaths(['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', '*.md']);
	const files = [];
	for (const relative of [...new Set(relativePaths)].sort()) {
		const absolute = path.resolve(repoRoot, relative);
		if (absolute !== repoRoot && !absolute.startsWith(`${repoRoot}${path.sep}`)) {
			fail(`${relative} resolves outside the repository.`);
			continue;
		}
		try {
			const stat = fs.lstatSync(absolute);
			if (stat.isSymbolicLink()) {
				const target = fs.realpathSync(absolute);
				if (target !== repoRoot && !target.startsWith(`${repoRoot}${path.sep}`)) {
					fail(`${relative} is a Markdown symlink whose target leaves the repository.`);
					continue;
				}
				if (!fs.statSync(target).isFile()) {
					fail(`${relative} is a Markdown symlink that does not target a file.`);
					continue;
				}
			} else if (!stat.isFile()) {
				fail(`${relative} is listed by git as Markdown but is not a file.`);
				continue;
			}
			files.push(absolute);
		} catch (error) {
			fail(`${relative} cannot be read: ${error instanceof Error ? error.message : String(error)}`);
		}
	}
	return files;
}

function globToRegExp(glob) {
	let source = '^';
	for (let index = 0; index < glob.length; index += 1) {
		const character = glob[index];
		if (character === '*' && glob[index + 1] === '*') {
			if (glob[index + 2] === '/') {
				source += '(?:.*/)?';
				index += 2;
			} else {
				source += '.*';
				index += 1;
			}
		} else if (character === '*') {
			source += '[^/]*';
		} else if (character === '?') {
			source += '[^/]';
		} else {
			source += character.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		}
	}
	return new RegExp(`${source}$`);
}

function matchesGlob(relativePath, glob) {
	return globToRegExp(glob).test(relativePath);
}

function manifestRuleFor(relativePath, rules) {
	return rules.filter((rule) => {
		const included = Array.isArray(rule.paths) && rule.paths.some((glob) => typeof glob === 'string' && matchesGlob(relativePath, glob));
		const excluded = Array.isArray(rule.exclude) && rule.exclude.some((glob) => typeof glob === 'string' && matchesGlob(relativePath, glob));
		return included && !excluded;
	});
}

function checkDiffWhitespace(untrackedMarkdown) {
	try {
		execFileSync('git', ['diff', '--check', 'HEAD', '--'], { cwd: repoRoot, encoding: 'utf8', stdio: 'pipe' });
	} catch (error) {
		const output = `${error?.stdout ?? ''}${error?.stderr ?? ''}`.trim();
		fail(`git diff --check HEAD failed${output ? `: ${output}` : '.'}`);
	}

	for (const relative of untrackedMarkdown) {
		const absolute = path.join(repoRoot, relative);
		try {
			execFileSync('git', ['diff', '--no-index', '--check', '--', '/dev/null', absolute], {
				cwd: repoRoot,
				encoding: 'utf8',
				stdio: 'pipe',
			});
		} catch (error) {
			const output = `${error?.stdout ?? ''}${error?.stderr ?? ''}`.trim();
			if (output) fail(`${relative} fails the untracked Markdown whitespace check: ${output}`);
			else if (typeof error?.status === 'number' && error.status > 1) fail(`${relative} could not be checked for whitespace errors.`);
		}
	}
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
let docsManifest;
try {
	docsManifest = JSON.parse(read('scripts/docs-manifest.json'));
} catch (error) {
	fail(`Could not read scripts/docs-manifest.json: ${error instanceof Error ? error.message : String(error)}`);
	docsManifest = { rules: [] };
}
const docsManifestRules = Array.isArray(docsManifest.rules) ? docsManifest.rules : [];
if (docsManifestRules.length === 0) {
	fail('scripts/docs-manifest.json must declare at least one classification rule.');
}
const manifestRuleIds = new Set();
for (const [index, rule] of docsManifestRules.entries()) {
	if (!rule || typeof rule !== 'object') {
		fail(`docs-manifest rule ${index} must be an object.`);
		continue;
	}
	if (typeof rule.id !== 'string' || !rule.id.trim()) fail(`docs-manifest rule ${index} requires a stable id.`);
	else if (manifestRuleIds.has(rule.id)) fail(`docs-manifest rule id ${rule.id} is duplicated.`);
	else manifestRuleIds.add(rule.id);
	if (typeof rule.class !== 'string' || !rule.class.trim()) fail(`docs-manifest rule ${rule.id ?? index} requires a class.`);
	if (!Array.isArray(rule.paths) || rule.paths.length === 0 || rule.paths.some((glob) => typeof glob !== 'string' || !glob)) {
		fail(`docs-manifest rule ${rule.id ?? index} requires non-empty path globs.`);
	}
	if (rule.exclude !== undefined && (!Array.isArray(rule.exclude) || rule.exclude.some((glob) => typeof glob !== 'string' || !glob))) {
		fail(`docs-manifest rule ${rule.id ?? index} has invalid exclude globs.`);
	}
}

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
} else if (canonicalVersion) {
	try {
		const releaseChannel = releaseChannelFromNotes(read(`docs/releases/${canonicalVersion}.md`));
		releaseFlags(canonicalVersion, releaseChannel);
	} catch (error) {
		fail(`docs/releases/${canonicalVersion}.md has an invalid release channel: ${error instanceof Error ? error.message : String(error)}`);
	}
}

const rootReadme = read('README.md');
const supportedReleaseStart = '<!-- supported-release:start -->';
const supportedReleaseEnd = '<!-- supported-release:end -->';
const supportedStartCount = rootReadme.split(supportedReleaseStart).length - 1;
const supportedEndCount = rootReadme.split(supportedReleaseEnd).length - 1;
let supportedReleaseBlock = '';
if (supportedStartCount !== 1 || supportedEndCount !== 1) {
	fail('README.md must contain exactly one supported-release marker pair.');
} else {
	const start = rootReadme.indexOf(supportedReleaseStart);
	const end = rootReadme.indexOf(supportedReleaseEnd, start + supportedReleaseStart.length);
	if (end < start) {
		fail('README.md supported-release markers are reversed.');
	} else {
		supportedReleaseBlock = rootReadme.slice(start, end + supportedReleaseEnd.length);
	}
}
if (canonicalVersion && supportedReleaseBlock) {
	const releaseBase = `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases`;
	const releaseLabel = canonicalVersion.includes('-') ? 'Public Beta' : 'Stable';
	const expectedSupportedMarkers = [
		`Current release: ${canonicalVersion} — ${releaseLabel}`,
		`${releaseBase}/tag/v${canonicalVersion}`,
		`${releaseBase}/download/v${canonicalVersion}/stonewright-${canonicalVersion}.zip`,
		`${releaseBase}/download/v${canonicalVersion}/stonewright-companion-${canonicalVersion}.tgz`,
		`${releaseBase}/download/v${canonicalVersion}/SHA256SUMS.txt`,
		'href="docs/installation.md"',
	];
	for (const marker of expectedSupportedMarkers) {
		if (!supportedReleaseBlock.includes(marker)) {
			fail(`README.md supported-release block is missing: ${marker}`);
		}
	}
}

const abilityCount = Number(read('docs/ability-truth-matrix.md').match(/Total abilities registered: \*\*(\d+)\*\*/)?.[1]);
if (!Number.isInteger(abilityCount) || abilityCount < 1) {
	fail('Could not read the generated plugin ability count.');
}
// Premium surface has grown past 300; fail loudly if matrix regresses below the known floor.
if (abilityCount < 300) {
	fail(`Plugin ability count ${abilityCount} is below the expected premium floor (300+).`);
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

const rootChangelog = read('CHANGELOG.md');
const unreleasedBody = rootChangelog.match(
	/^## \[Unreleased\]([\s\S]*?)(?=^## \[[^\]]+\] - \d{4}-\d{2}-\d{2}$)/m,
)?.[1] ?? '';
const hasUnreleasedChanges = /^### (?:Added|Changed|Deprecated|Removed|Fixed|Security)$/m.test(unreleasedBody);

const truthMarkers = [
	['README.md', [`**${abilityCount}** abilities`, `**${directToolCount}** tools`, `exposes **${directToolCount}** tools`]],
	['companion/README.md', [`**${directToolCount}** Direct tools`]],
	['docs/direct-mode-e2e.md', [`**${directToolCount}** tools`]],
	['docs/abilities.md', [`(**${abilityCount}** abilities)`]],
];

// A feature branch may legitimately move capability counts ahead of the last
// immutable release note. Once Unreleased is empty (release preparation), the
// canonical version's note must match the generated surface.
if (!hasUnreleasedChanges) {
	truthMarkers.push([
		`docs/releases/${canonicalVersion}.md`,
		[`**${abilityCount}**`, `**${directToolCount}**`],
	]);
}

for (const [relativePath, expected] of truthMarkers) {
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
	relativePath.startsWith('docs/superpowers/') ||
	relativePath.startsWith('research/') ||
	[
		'docs/premium-corrections-handoff-report.md',
		'docs/migration-elementor-v3-tools.md',
		'docs/elementor-v3-editor-adapter.md',
	].includes(relativePath);

const allMarkdownFiles = gitMarkdownFiles();
const allMarkdownRelative = allMarkdownFiles.map((absolute) => path.relative(repoRoot, absolute).split(path.sep).join('/'));
for (const rule of docsManifestRules) {
	if (!rule || typeof rule !== 'object' || !Array.isArray(rule.paths)) continue;
	for (const glob of rule.paths) {
		if (typeof glob !== 'string') continue;
		const matches = allMarkdownRelative.filter((relative) =>
			matchesGlob(relative, glob) && !(Array.isArray(rule.exclude) && rule.exclude.some((excluded) => typeof excluded === 'string' && matchesGlob(relative, excluded))),
		);
		if (matches.length === 0) fail(`docs-manifest rule ${rule.id ?? rule.class ?? 'unknown'} has stale or zero-match path glob: ${glob}`);
	}
	for (const glob of Array.isArray(rule.exclude) ? rule.exclude : []) {
		if (typeof glob !== 'string') continue;
		const matches = allMarkdownRelative.filter((relative) => matchesGlob(relative, glob));
		if (matches.length === 0) fail(`docs-manifest rule ${rule.id ?? rule.class ?? 'unknown'} has stale or zero-match exclude glob: ${glob}`);
	}
}
for (const absolute of allMarkdownFiles) {
	const relative = path.relative(repoRoot, absolute).split(path.sep).join('/');
	const matches = manifestRuleFor(relative, docsManifestRules);
	if (matches.length !== 1) {
		fail(`${relative} must match exactly one docs-manifest classification (matched ${matches.length}).`);
	}
	if (matches[0]?.class === 'generated' && relative === 'docs/ability-truth-matrix.md') {
		const matrix = fs.readFileSync(absolute, 'utf8');
		if (!matrix.includes('Total abilities registered:')) fail(`${relative} is missing its generated total.`);
	}
	if (matches[0]?.class === 'imported-knowledge') {
		const sourceManifest = matches[0].source;
		if (typeof sourceManifest === 'string' && !fs.existsSync(path.join(repoRoot, sourceManifest))) {
			fail(`${relative} references missing imported source metadata ${sourceManifest}.`);
		}
		const basename = path.basename(relative);
		const content = fs.readFileSync(absolute, 'utf8');
		if (!['README.md', '_change_log.md'].includes(basename) && (!content.startsWith('---\n') || !content.includes('\nsource_url:') || !content.includes('\ncontent_hash:'))) {
			fail(`${relative} is imported knowledge without required source frontmatter.`);
		}
	}
}

const untrackedMarkdown = gitPaths(['ls-files', '-z', '--others', '--exclude-standard', '--', '*.md']);
checkDiffWhitespace(untrackedMarkdown);

const markdownFiles = allMarkdownFiles.filter((absolute) => {
	const relative = path.relative(repoRoot, absolute).split(path.sep).join('/');
	const matches = manifestRuleFor(relative, docsManifestRules);
	return matches.length !== 1 || matches[0].class !== 'imported-knowledge';
});

for (const absolute of markdownFiles) {
	const relative = path.relative(repoRoot, absolute).split(path.sep).join('/');
	const content = fs.readFileSync(absolute, 'utf8');
	const evergreenContent = relative === 'README.md' && supportedReleaseBlock
		? content.replace(supportedReleaseBlock, '')
		: content;

	if (!historicalMarkdown(relative) && /releases\/download\/v1\.0\.0-(?:beta|rc)\.\d+\//.test(evergreenContent)) {
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

// Premium docs expected for connection center + transactions.
for (const relativePath of ['docs/transactions.md', 'docs/contracts/public-api-v1.json', 'docs/contracts/direct-tools-v1.json']) {
	if (!fs.existsSync(path.join(repoRoot, relativePath))) {
		fail(`Missing required truth artifact: ${relativePath}`);
	}
}

const transactions = read('docs/transactions.md');
for (const required of [
	'stonewright-elementor-v3-transaction-run',
	'Verify connection',
	'doctor',
	'public-api-v1.json',
]) {
	if (!transactions.includes(required)) fail(`docs/transactions.md is missing: ${required}`);
}

if (errors.length > 0) {
	console.error(`Documentation freshness failed with ${errors.length} error(s):`);
	for (const error of errors) console.error(`- ${error}`);
	process.exit(1);
}

console.log(`Documentation freshness passed for ${allMarkdownFiles.length} classified Markdown files (${markdownFiles.length} maintained; ${canonicalVersion}, ${abilityCount} plugin abilities, ${directToolCount} Direct tools).`);
