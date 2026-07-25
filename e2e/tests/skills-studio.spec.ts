import { expect, test, type Page, type Route } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { login } from './helpers/design-studio';

/**
 * Skills — catalog, keyboard tabs, review drawer, screenshot matrix.
 *
 * The catalog is stubbed so provenance states (built-in, local, external,
 * trashed) are all reachable on a stock wp-env. Trash and restore run against
 * the stubbed lifecycle routes: what this file proves is the review path a
 * user walks, not the persistence, which the PHP suite already covers.
 */

const SKILLS_URL = '/wp-admin/admin.php?page=stonewright-skills';
const artifactDir = path.join(process.cwd(), 'artifacts', 'skills-studio');

type SkillRow = {
	id: number;
	slug: string;
	title: string;
	description: string;
	source: string;
	status: string;
	enabled: number;
	enable_agentic: number;
	enable_prompt: number;
	revision: number;
	verification_count: number;
	updated_at: string;
	trashed_at?: string;
};

const skill = (over: Partial<SkillRow> & Pick<SkillRow, 'id' | 'slug' | 'title'>): SkillRow => ({
	description: 'Use when a task needs this playbook.',
	source: 'user',
	status: 'active',
	enabled: 1,
	enable_agentic: 1,
	enable_prompt: 1,
	revision: 2,
	verification_count: 1,
	updated_at: '2026-07-20 09:14:00',
	...over,
});

const CATALOG = {
	ok: true,
	skills: [
		skill({
			id: 1,
			slug: 'stonewright-elementor-v3-builder',
			title: 'Elementor V3 Builder',
			description: 'Use whenever a task reads, plans, builds, or repairs an Elementor V3 page.',
			source: 'builtin',
			revision: 4,
			verification_count: 12,
		}),
		skill({
			id: 2,
			slug: 'quarry-tone-of-voice',
			title: 'Quarry tone of voice',
			description: 'Use when writing customer-facing copy for the Quarry brand.',
		}),
		skill({
			id: 3,
			slug: 'partner-plugin:migration-notes',
			title: 'Migration notes',
			description: 'Use when moving content off the legacy builder.',
			source: 'uploaded',
			status: 'draft',
			enabled: 0,
			revision: 1,
			verification_count: 0,
		}),
	],
	conflicts: [],
	sources: [
		{ id: 'database', label: 'This site' },
		{ id: 'partner-plugin', label: 'Partner plugin' },
	],
	trashed: [
		skill({
			id: 9,
			slug: 'retired-launch-checklist',
			title: 'Retired launch checklist',
			description: 'Use when preparing a launch. Superseded by the quality loop.',
			status: 'trashed',
			enabled: 0,
			trashed_at: '2026-07-21 11:02:00',
		}),
	],
};

async function stubSkills(page: Page): Promise<void> {
	await page.route(
		(url) =>
			url.href.includes('skills-studio') &&
			(url.pathname.includes('/wp-json/') || url.searchParams.has('rest_route')),
		async (route: Route) => {
			const url = new URL(route.request().url());
			const target = url.searchParams.get('rest_route') ?? url.pathname;
			const json = (body: unknown) =>
				route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

			if (target.includes('/trash')) {
				return json({ ok: true, skill_id: 2, action: 'trash' });
			}
			if (target.includes('/restore')) {
				return json({ ok: true, skill_id: 9, action: 'restore' });
			}
			if (target.includes('/export')) {
				return json({ ok: true, filename: 'quarry-tone-of-voice.md', markdown: '# Quarry' });
			}

			return json(CATALOG);
		},
	);
}

async function openCatalog(page: Page): Promise<void> {
	await stubSkills(page);
	await page.goto(SKILLS_URL, { waitUntil: 'domcontentloaded' });
	await expect(page.locator('[data-sw-panel="catalog"] .sw-skill-row').first()).toBeVisible();
}

/* -------------------------------------------------------------------------- */
/* Behaviour                                                                   */
/* -------------------------------------------------------------------------- */

test.describe('Skills catalog', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Behaviour proof runs once.');
		await login(page);
	});

	test('the catalog states provenance and protects built-in skills', async ({ page }) => {
		await openCatalog(page);

		const rows = page.locator('[data-sw-panel="catalog"] .sw-skill-row');
		await expect(rows).toHaveCount(3);

		const builtIn = rows.filter({ hasText: 'Elementor V3 Builder' });
		await expect(builtIn.locator('.sw-skill-row__badges')).toContainText('built-in');
		await expect(builtIn.getByRole('button', { name: 'Trash' })).toBeDisabled();

		const local = rows.filter({ hasText: 'Quarry tone of voice' });
		await expect(local.getByRole('button', { name: 'Trash' })).toBeEnabled();
	});

	test('search narrows the catalog without a page load', async ({ page }) => {
		await openCatalog(page);

		await page.locator('[data-sw-skills-search]').fill('quarry');
		await expect(page.locator('[data-sw-panel="catalog"] .sw-skill-row')).toHaveCount(1);
		await expect(page.locator('[data-sw-panel="catalog"] .sw-skill-row')).toContainText('Quarry');
	});

	test('the tablist is operable from the keyboard alone', async ({ page }) => {
		await openCatalog(page);

		const tabs = page.getByRole('tab');
		await tabs.filter({ hasText: 'Catalog' }).focus();
		await page.keyboard.press('ArrowRight');
		await page.keyboard.press('ArrowRight');

		// Editor is a real navigation, so ArrowRight lands on Import instead.
		const importTab = tabs.filter({ hasText: 'Import' });
		await expect(importTab).toBeFocused();
		await page.keyboard.press('Enter');
		await expect(importTab).toHaveAttribute('aria-selected', 'true');
		await expect(page.locator('[data-sw-panel="import"]')).toBeVisible();
		await expect(page.locator('[data-sw-panel="catalog"]')).toBeHidden();
	});

	test('trashing asks in a drawer, not a browser dialog, and can be undone', async ({ page }) => {
		let nativeDialog = false;
		page.on('dialog', async (d) => {
			nativeDialog = true;
			await d.dismiss();
		});

		await openCatalog(page);

		const row = page.locator('.sw-skill-row').filter({ hasText: 'Quarry tone of voice' });
		await row.getByRole('button', { name: 'Trash' }).click();

		const drawer = page.locator('[data-sw-skills-drawer]');
		await expect(drawer).toBeVisible();
		await expect(drawer).toHaveAttribute('aria-modal', 'true');
		await expect(drawer).toContainText('Move this skill to trash?');
		await expect(drawer).toContainText('yes, from the trash view');

		await drawer.getByRole('button', { name: 'Move to trash' }).click();
		await expect(drawer).toBeHidden();
		await expect(page.locator('[data-sw-skills-undo]')).toBeVisible();
		await expect(page.locator('[data-sw-skills-undo]').getByRole('button', { name: 'Undo' })).toBeVisible();

		expect(nativeDialog).toBe(false);
	});

	test('Escape closes the drawer and returns focus to the control that opened it', async ({ page }) => {
		await openCatalog(page);

		const trigger = page
			.locator('.sw-skill-row')
			.filter({ hasText: 'Quarry tone of voice' })
			.getByRole('button', { name: 'Trash' });
		await trigger.click();

		const drawer = page.locator('[data-sw-skills-drawer]');
		await expect(drawer).toBeVisible();
		await page.keyboard.press('Escape');
		await expect(drawer).toBeHidden();
		await expect(trigger).toBeFocused();
	});

	test('the trash view offers restore for a trashed skill', async ({ page }) => {
		await openCatalog(page);

		await page.getByRole('tab', { name: 'Trash' }).click();
		const row = page.locator('[data-sw-panel="trash"] .sw-skill-row');
		await expect(row).toContainText('Retired launch checklist');
		await expect(row.getByRole('button', { name: 'Restore' })).toBeVisible();
	});

	test('reduced motion removes the transitions the page would otherwise run', async ({ page }) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await openCatalog(page);

		const duration = await page
			.locator('.sw-skills-tab')
			.first()
			.evaluate((node) => window.getComputedStyle(node).transitionDuration);

		expect(['0s', '0s, 0s', '']).toContain(duration);
	});
});

/* -------------------------------------------------------------------------- */
/* Screenshot matrix                                                           */
/* -------------------------------------------------------------------------- */

test('skills catalog screenshot', async ({ page }, testInfo) => {
	await login(page);
	await openCatalog(page);

	fs.mkdirSync(artifactDir, { recursive: true });
	await page.screenshot({
		path: path.join(artifactDir, `catalog-${testInfo.project.name}.png`),
		fullPage: true,
	});

	// The shell must not scroll sideways at any width in the matrix.
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - document.documentElement.clientWidth,
	);
	expect(overflow).toBeLessThanOrEqual(1);
});
