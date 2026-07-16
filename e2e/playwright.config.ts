import { defineConfig, devices, type Project } from '@playwright/test';

/**
 * Admin-ui e2e gate.
 *
 * baseURL defaults to wp-env (http://localhost:8888). Override with WP_BASE_URL
 * for Local sites (e.g. http://transavia-local.local).
 */
const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8888';

const viewports = [
	{ name: 'desktop-1440', width: 1440, height: 900 },
	{ name: 'desktop-1024', width: 1024, height: 768 },
	{ name: 'tablet-782', width: 782, height: 1024 },
	{ name: 'mobile-390', width: 390, height: 844 },
	{ name: 'mobile-320', width: 320, height: 568 },
] as const;

const colorSchemes = ['light', 'dark'] as const;

function projects(): Project[] {
	const list: Project[] = [];
	for (const viewport of viewports) {
		for (const colorScheme of colorSchemes) {
			list.push({
				name: `${viewport.name}-${colorScheme}`,
				use: {
					...devices['Desktop Chrome'],
					viewport: { width: viewport.width, height: viewport.height },
					colorScheme,
					baseURL,
				},
			});
		}
	}
	return list;
}

export default defineConfig({
	testDir: './tests',
	// The suite shares one WordPress database and mutates global options/posts.
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: process.env.CI ? 1 : undefined,
	reporter: process.env.CI ? [['github'], ['list']] : 'list',
	timeout: 60_000,
	expect: { timeout: 15_000 },
	use: {
		baseURL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'off',
		// wp-env / Local credentials (override with env in CI if needed).
		storageState: undefined,
	},
	outputDir: 'artifacts/test-results',
	projects: projects(),
});
