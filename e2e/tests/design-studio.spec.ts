import { expect, test } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { restPost, wpRestNonce } from './helpers/wp-rest';
import {
	ACTIVE_SYNCED,
	DRAFT_WITH_ISSUES,
	EMPTY,
	INCOMPLETE_EVIDENCE,
	READY_INACTIVE,
	REVISION_HISTORY,
	STUDIO_URL,
	contract,
	login,
	stubStudio,
} from './helpers/design-studio';

/**
 * Design Studio — state fixtures, real write round-trips, screenshot matrix.
 *
 * The presentation blocks render stubbed REST payloads so all eight states are
 * reachable on a stock wp-env. The write-path block is not stubbed: it drives
 * save, activate, and restore against the live routes, so the fixtures never
 * become the only evidence that the page works.
 */

const artifactDir = path.join(process.cwd(), 'artifacts', 'design-studio');

/* -------------------------------------------------------------------------- */
/* Presentation states                                                         */
/* -------------------------------------------------------------------------- */

test.describe('Design Studio states', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		// State proof is viewport-independent; the matrix test covers geometry.
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'State proof runs once.');
		await login(page);
	});

	test('empty state explains what a direction is and offers the first step', async ({ page }) => {
		await stubStudio(page, EMPTY);
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		const empty = page.locator('[data-sw-panel="overview"] .sw-ds-empty');
		await expect(empty).toBeVisible();
		await expect(empty.getByRole('heading')).toHaveText('No design direction yet');
		await expect(empty.getByRole('button', { name: 'Create the first direction' })).toBeVisible();
		await expect(page.locator('[data-sw-ds-status]')).toHaveText(
			'No design direction is stored yet.',
		);
	});

	test('active direction leads with readiness, hashes, and its specimen', async ({ page }) => {
		await stubStudio(page, ACTIVE_SYNCED);
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		const hero = page.locator('[data-sw-panel="overview"] .sw-ds-hero');
		await expect(hero.getByRole('heading', { name: 'Quarry' })).toBeVisible();
		await expect(hero.locator('.sw-ds-badge', { hasText: 'active' })).toBeVisible();
		await expect(hero.locator('.sw-ds-badge', { hasText: 'ready' }).first()).toBeVisible();
		await expect(hero.locator('.sw-ds-swatch')).toHaveCount(4);
		await expect(hero.locator('.sw-ds-dial')).toHaveCount(3);
		// Activate is refused for the direction that is already active.
		await expect(hero.getByRole('button', { name: 'Activate' })).toBeDisabled();
	});

	test('a direction that is ready but not active can be activated', async ({ page }) => {
		await stubStudio(page, READY_INACTIVE);
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		await page.locator('#sw-ds-direction-picker').selectOption('12');
		const hero = page.locator('[data-sw-panel="overview"] .sw-ds-hero');
		await expect(hero.getByRole('heading', { name: 'Foundry' })).toBeVisible();
		await expect(hero.getByRole('button', { name: 'Activate' })).toBeEnabled();
	});

	test('a draft with readiness issues says so instead of looking finished', async ({ page }) => {
		await stubStudio(page, DRAFT_WITH_ISSUES);
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		const hero = page.locator('[data-sw-panel="overview"] .sw-ds-hero');
		await expect(hero.locator('.sw-ds-badge', { hasText: 'not ready' })).toBeVisible();
		await expect(hero.locator('.sw-ds-badge', { hasText: 'sync blocked' })).toBeVisible();
		await expect(hero.locator('.sw-ds-badge', { hasText: '3 issues' })).toBeVisible();
		await expect(hero.locator('.sw-ds-swatch')).toHaveCount(0);
	});

	test('editor reports invalid fields inline and in a summary, and blocks the save', async ({
		page,
	}) => {
		await stubStudio(page, ACTIVE_SYNCED);
		await page.goto(`${STUDIO_URL}&view=editor`, { waitUntil: 'domcontentloaded' });

		const save = page.locator('[data-sw-ds-save]');
		await expect(save).toBeEnabled();

		await page.locator('#sw-ds-field-name').fill('');
		await page.locator('#sw-ds-field-tokens').fill('{ not json');
		await page.locator('#sw-ds-field-variance').fill('180');

		const summary = page.locator('[data-sw-ds-error-summary]');
		await expect(summary).toBeVisible();
		await expect(summary.getByRole('heading')).toContainText('need attention');
		await expect(page.locator('[data-sw-field="name"]')).toHaveClass(/is-invalid/);
		await expect(page.locator('#sw-ds-field-tokens')).toHaveAttribute('aria-invalid', 'true');
		await expect(page.locator('[data-sw-field="variance"] .sw-ds-field__error')).toContainText(
			'0 to 100',
		);
		await expect(save).toBeDisabled();
	});

	test('a sync plan that the kit moved under is shown as blocked, not applied', async ({
		page,
	}) => {
		await stubStudio(page, ACTIVE_SYNCED);
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		await page.getByRole('button', { name: 'Plan Elementor sync' }).click();

		const drawer = page.locator('[data-sw-ds-drawer]');
		await expect(drawer).toBeVisible();
		await expect(drawer.getByRole('heading')).toHaveText('Apply this sync to the Elementor kit');
		await expect(drawer.locator('.sw-ds-review__value--danger')).toHaveCount(2);
		await expect(drawer.locator('.sw-ds-finding')).toHaveCount(1);

		await drawer.getByRole('button', { name: 'Apply is blocked' }).click();
		await expect(page.locator('[data-sw-ds-status]')).toContainText('blocked');
		await expect(page.locator('[data-sw-ds-status]')).toHaveAttribute('data-tone', 'danger');
	});

	test('a failing quality report shows measured evidence and a repair hint', async ({ page }) => {
		await stubStudio(page, ACTIVE_SYNCED);
		await page.goto(`${STUDIO_URL}&view=quality`, { waitUntil: 'domcontentloaded' });

		await page.locator('#sw-ds-post-id').fill('7');
		await page.getByRole('button', { name: 'Load reports' }).click();

		await expect(page.locator('.sw-ds-badge', { hasText: 'fail' })).toBeVisible();
		await expect(page.locator('.sw-ds-finding')).toHaveCount(3);
		await expect(page.locator('.sw-ds-finding__evidence').first()).toContainText('"ratio": 3.02');
		await expect(page.locator('.sw-ds-finding__repair').first()).toContainText('4.5:1');

		await page.locator('#sw-ds-filter-severity').selectOption('error');
		await expect(page.locator('.sw-ds-finding')).toHaveCount(2);
		await page.locator('#sw-ds-filter-viewport').selectOption('mobile');
		await expect(page.locator('.sw-ds-finding')).toHaveCount(2);
		await page.locator('#sw-ds-filter-rule').fill('target');
		await expect(page.locator('.sw-ds-finding')).toHaveCount(1);
	});

	test('incomplete evidence never reads as a pass', async ({ page }) => {
		await stubStudio(page, INCOMPLETE_EVIDENCE);
		await page.goto(`${STUDIO_URL}&view=quality`, { waitUntil: 'domcontentloaded' });

		await page.locator('#sw-ds-post-id').fill('7');
		await page.getByRole('button', { name: 'Load reports' }).click();

		await expect(page.locator('.sw-ds-badge', { hasText: 'not_checked' })).toBeVisible();
		await expect(page.locator('.sw-ds-badge', { hasText: 'pass' })).toHaveCount(0);
		await expect(page.locator('.sw-ds-badge', { hasText: '0 rules checked' })).toBeVisible();
		await expect(page.locator('.sw-ds-card__meta')).toBeVisible();
		await expect(page.getByText('Treat these rules as unverified')).toBeVisible();
	});

	test('history lists revisions and reviews a restore before running it', async ({ page }) => {
		await stubStudio(page, REVISION_HISTORY);
		await page.goto(`${STUDIO_URL}&view=history`, { waitUntil: 'domcontentloaded' });

		await expect(page.locator('.sw-ds-table tbody tr')).toHaveCount(3);
		await expect(page.locator('.sw-ds-table__hash').first()).toHaveText('a1b2c3d4e5f6');

		await page.locator('.sw-ds-table tbody tr').nth(2).getByRole('button', { name: 'Restore' }).click();

		const drawer = page.locator('[data-sw-ds-drawer]');
		await expect(drawer.getByRole('heading')).toHaveText('Restore revision 2');
		await expect(drawer.locator('.sw-ds-review li')).toHaveCount(5);

		await drawer.getByRole('button', { name: 'Restore revision' }).click();
		await expect(page.locator('[data-sw-ds-status]')).toContainText('Restored revision 2');
	});
});

/* -------------------------------------------------------------------------- */
/* Live write path                                                             */
/* -------------------------------------------------------------------------- */

test.describe('Design Studio write path', () => {
	test('save, activate, and restore run against the real routes', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Write proof runs once.');
		await login(page);

		const nonce = await wpRestNonce(page);
		const slug = `e2e-direction-${Date.now()}`;
		const created = await restPost(
			page,
			'/stonewright/v1/design-studio/directions',
			{ slug, contract: contract({ identity: { name: 'E2E direction', summary: 'Seeded by Playwright.' } }) },
			nonce,
		);
		expect(created.ok, `seed failed: ${JSON.stringify(created.body)}`).toBe(true);
		const seeded = created.body as { id: number };
		expect(seeded.id).toBeGreaterThan(0);

		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });
		await page.locator('#sw-ds-direction-picker').selectOption(String(seeded.id));
		await expect(page.getByRole('heading', { name: 'E2E direction' })).toBeVisible();

		// Save a second revision through the editor and its review drawer.
		const saved = page.evaluate(
			() =>
				new Promise<number>((resolve) => {
					document.addEventListener(
						'stonewright:direction-saved',
						(event) => resolve(Number((event as CustomEvent).detail.revision)),
						{ once: true },
					);
				}),
		);
		await page.getByRole('button', { name: 'Open in editor' }).click();
		await page.locator('#sw-ds-field-summary').fill('Edited by the Playwright write-path test.');
		await page.locator('[data-sw-ds-save]').click();
		await page.locator('[data-sw-ds-drawer]').getByRole('button', { name: 'Save direction' }).click();
		expect(await saved).toBeGreaterThanOrEqual(2);

		// Activate it, then read the history the save produced.
		const activated = page.evaluate(
			() =>
				new Promise<number>((resolve) => {
					document.addEventListener(
						'stonewright:direction-activated',
						(event) => resolve(Number((event as CustomEvent).detail.active_id)),
						{ once: true },
					);
				}),
		);
		// The view switcher is a tablist, and the admin shell has its own
		// "Overview" navigation group — asking for a link would leave the page.
		await page.getByRole('tab', { name: 'Overview' }).click();
		await page.locator('#sw-ds-direction-picker').selectOption(String(seeded.id));
		await page.getByRole('button', { name: 'Activate' }).click();
		await page.locator('[data-sw-ds-drawer]').getByRole('button', { name: 'Activate' }).click();
		expect(await activated).toBe(seeded.id);

		await page.getByRole('tab', { name: 'History' }).click();
		const rows = page.locator('.sw-ds-table tbody tr');
		await expect(rows.first()).toBeVisible();
		expect(await rows.count()).toBeGreaterThanOrEqual(2);

		await rows.last().getByRole('button', { name: 'Restore' }).click();
		await page.locator('[data-sw-ds-drawer]').getByRole('button', { name: 'Restore revision' }).click();
		await expect(page.locator('[data-sw-ds-status]')).toContainText('Restored revision');
	});
});

/* -------------------------------------------------------------------------- */
/* Screenshot matrix                                                           */
/* -------------------------------------------------------------------------- */

const MATRIX_VIEWPORTS = [
	{ name: '375x812', width: 375, height: 812 },
	{ name: '768x1024', width: 768, height: 1024 },
	{ name: '1024x768', width: 1024, height: 768 },
	{ name: '1280x800', width: 1280, height: 800 },
	{ name: '1440x900', width: 1440, height: 900 },
] as const;

test.describe('Design Studio screenshot matrix', () => {
	test('ten screenshots across five widths in light and dark', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'The matrix drives its own sizes.');
		test.setTimeout(180_000);
		fs.mkdirSync(artifactDir, { recursive: true });

		await login(page);
		await stubStudio(page, ACTIVE_SYNCED);

		const volatile = [
			page.locator('.sw-ds-card__meta'),
			page.locator('.sw-ds-fact__value'),
			page.locator('.sw-shell__version'),
		];

		let dark = false;
		for (const theme of ['light', 'dark'] as const) {
			await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });
			await page.locator('.sw-ds-hero').waitFor({ state: 'visible' });

			if ((theme === 'dark') !== dark) {
				await page.locator('[data-sw-theme-toggle]').click();
				await expect(page.locator('.sw-shell')).toHaveClass(
					theme === 'dark' ? /sw-theme-dark/ : /sw-theme-light/,
				);
				dark = theme === 'dark';
			}

			for (const viewport of MATRIX_VIEWPORTS) {
				await page.setViewportSize({ width: viewport.width, height: viewport.height });
				await page.locator('.sw-ds-hero').waitFor({ state: 'visible' });
				await page.waitForTimeout(120);

				const overflow = await page.evaluate(() => {
					const shell = document.querySelector('.sw-shell') as HTMLElement | null;
					if (!shell) {
						return -1;
					}
					const delta = shell.scrollWidth - shell.clientWidth;
					return delta > 2 ? delta : 0;
				});
				expect(overflow, `${viewport.name} ${theme}: horizontal overflow`).toBe(0);

				await page.screenshot({
					path: path.join(artifactDir, `design-studio-${viewport.name}-${theme}.png`),
					fullPage: true,
					mask: volatile,
				});
			}
		}

		// Leave the shared admin user on the default theme for later specs.
		if (dark) {
			await page.setViewportSize({ width: 1440, height: 900 });
			await page.locator('[data-sw-theme-toggle]').click();
			await expect(page.locator('.sw-shell')).toHaveClass(/sw-theme-light/);
		}

		const written = fs
			.readdirSync(artifactDir)
			.filter((name) => name.startsWith('design-studio-') && name.endsWith('.png'));
		expect(written, `screenshot matrix wrote ${written.length} files`).toHaveLength(10);
	});
});
