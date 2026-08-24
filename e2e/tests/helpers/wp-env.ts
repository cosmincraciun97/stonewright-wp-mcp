/**
 * Host-side wp-env CLI helpers for fixtures that php-execute must not write.
 *
 * Stonewright permanently blocks filesystem mutation APIs inside
 * stonewright/php-execute. E2E sentinel files under uploads/elementor/css
 * therefore seed and clean through WP-CLI in the wp-env container.
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const WP_ENV_CONFIG = process.env.WP_ENV_CONFIG ?? '.wp-env.package.json';
const WP_ENV_CWD = process.env.WP_ENV_CWD ?? path.resolve(__dirname, '../..');

const SENTINELS: Record<string, string> = {
	'custom-frontend.min.css':
		'/* stonewright-global-sentinel-frontend-v1 */ .elementor-sentinel-frontend { color: rgb(17, 34, 51); }',
	'custom-pro-widget-nav-menu.min.css':
		'/* stonewright-global-sentinel-pro-v1 */ .elementor-sentinel-pro { color: rgb(68, 85, 102); }',
};

function phpStringLiteral(value: string): string {
	return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

export function wpEnvEval(php: string): string {
	const output = execFileSync(
		'npx',
		[
			'wp-env',
			'--config',
			WP_ENV_CONFIG,
			'run',
			'cli',
			'--',
			'wp',
			'eval',
			php,
		],
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
	const payload = Buffer.from(JSON.stringify(SENTINELS), 'utf8').toString('base64');
	const result = wpEnvEval(
		`$sentinels = json_decode(base64_decode(${phpStringLiteral(payload)}), true);
$uploads = wp_upload_dir();
$dir = rtrim((string) $uploads['basedir'], '/\\\\') . '/elementor/css';
if (!is_array($sentinels) || !wp_mkdir_p($dir)) {
	echo 'sentinel_directory_unavailable';
	return;
}
$created = [];
foreach ($sentinels as $name => $contents) {
	$path = $dir . '/' . $name;
	if (is_file($path)) {
		echo 'sentinel_conflict:' . $name;
		return;
	}
	if (false === file_put_contents($path, (string) $contents, LOCK_EX)) {
		foreach ($created as $created_name) {
			@unlink($dir . '/' . $created_name);
		}
		echo 'sentinel_write_failed:' . $name;
		return;
	}
	$created[] = $name;
}
echo 'ok:' . implode(',', $created);`,
	);
	if (!result.startsWith('ok:')) {
		throw new Error(`Failed to seed Elementor CSS sentinels: ${result}`);
	}
	return result.slice(3).split(',').filter(Boolean);
}

export function cleanupElementorCssSentinels(): string[] {
	const payload = Buffer.from(JSON.stringify(Object.keys(SENTINELS)), 'utf8').toString(
		'base64',
	);
	const result = wpEnvEval(
		`$names = json_decode(base64_decode(${phpStringLiteral(payload)}), true);
$uploads = wp_upload_dir();
$dir = rtrim((string) $uploads['basedir'], '/\\\\') . '/elementor/css';
$remaining = [];
if (!is_array($names)) {
	echo 'sentinel_cleanup_invalid';
	return;
}
foreach ($names as $name) {
	$path = $dir . '/' . $name;
	if (is_file($path) && !unlink($path)) {
		$remaining[] = $name;
	}
}
echo 'ok:' . implode(',', $remaining);`,
	);
	if (!result.startsWith('ok:')) {
		throw new Error(`Failed to clean Elementor CSS sentinels: ${result}`);
	}
	return result.slice(3).split(',').filter(Boolean);
}
