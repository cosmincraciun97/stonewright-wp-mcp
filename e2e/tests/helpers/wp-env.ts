/**
 * Host-side wp-env helpers for fixtures that php-execute must not write.
 *
 * Stonewright permanently blocks filesystem mutation APIs inside
 * stonewright/php-execute. E2E sentinel files under uploads/elementor/css
 * therefore seed and clean through the wp-env container filesystem without
 * bootstrapping WordPress (wp eval OOMs under Stonewright + Elementor + Woo).
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const WP_ENV_CONFIG = process.env.WP_ENV_CONFIG ?? '.wp-env.package.json';
const WP_ENV_CWD = process.env.WP_ENV_CWD ?? path.resolve(__dirname, '../..');
const CSS_DIR = '/var/www/html/wp-content/uploads/elementor/css';

const SENTINELS: Record<string, string> = {
	'custom-frontend.min.css':
		'/* stonewright-global-sentinel-frontend-v1 */ .elementor-sentinel-frontend { color: rgb(17, 34, 51); }',
	'custom-pro-widget-nav-menu.min.css':
		'/* stonewright-global-sentinel-pro-v1 */ .elementor-sentinel-pro { color: rgb(68, 85, 102); }',
};

function shellSingleQuote(value: string): string {
	return "'" + value.replace(/'/g, `'\"'\"'`) + "'";
}

function wpEnvBash(script: string): string {
	const output = execFileSync(
		'npx',
		['wp-env', '--config', WP_ENV_CONFIG, 'run', 'cli', '--', 'bash', '-lc', script],
		{
			cwd: WP_ENV_CWD,
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'pipe'],
			timeout: 60_000,
		},
	);
	return output.trim();
}

export function seedElementorCssSentinels(): string[] {
	const parts = [
		`mkdir -p ${shellSingleQuote(CSS_DIR)}`,
		`cd ${shellSingleQuote(CSS_DIR)}`,
	];
	for (const [name, contents] of Object.entries(SENTINELS)) {
		parts.push(
			`if [ -e ${shellSingleQuote(name)} ]; then echo sentinel_conflict:${name}; exit 1; fi`,
			`printf %s ${shellSingleQuote(contents)} > ${shellSingleQuote(name)}`,
		);
	}
	parts.push(`echo ok:${Object.keys(SENTINELS).join(',')}`);
	const result = wpEnvBash(parts.join(' && '));
	const okLine = result.split(/\r?\n/).filter(Boolean).at(-1) ?? result;
	if (!okLine.startsWith('ok:')) {
		throw new Error(`Failed to seed Elementor CSS sentinels: ${result}`);
	}
	return okLine.slice(3).split(',').filter(Boolean);
}

export function cleanupElementorCssSentinels(): string[] {
	const parts = [`mkdir -p ${shellSingleQuote(CSS_DIR)}`, `cd ${shellSingleQuote(CSS_DIR)}`, 'remaining='];
	for (const name of Object.keys(SENTINELS)) {
		parts.push(
			`if [ -e ${shellSingleQuote(name)} ]; then rm -f ${shellSingleQuote(name)} || remaining="$remaining${name},"; fi`,
		);
	}
	parts.push('echo "ok:${remaining%,}"');
	const result = wpEnvBash(parts.join('; '));
	const okLine = result.split(/\r?\n/).filter(Boolean).at(-1) ?? result;
	if (!okLine.startsWith('ok:')) {
		throw new Error(`Failed to clean Elementor CSS sentinels: ${result}`);
	}
	return okLine.slice(3).split(',').filter(Boolean);
}
