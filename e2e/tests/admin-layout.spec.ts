import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { STONEWRIGHT_PAGES, STONEWRIGHT_VIEWS } from './helpers/admin-pages';
import { findings, measurePage, menuPages, type MenuPage } from './helpers/layout-crawl';
import { probeLayout } from './helpers/layout-probe';
import { login } from './helpers/login';

/**
 * The layout check of every Stonewright admin page.
 *
 * The pages are the ones the admin menu lists (read from the sidebar of a signed-in administrator), so a page added
 * to the menu is measured without being added here. Each page is measured as loaded, with each of its tabs selected
 * and with each of its dialogs open. In every state:
 *
 * - controls (buttons, links styled as buttons, text and search inputs, selects) that share a row have the same
 *   height, within 1px, and are centred on the same line, within 1px;
 * - no control and no text runs past the edge of the box that holds it, and the page is not wider than the viewport;
 * - no two interactive controls overlap.
 *
 * - every action group (the one row a page puts its buttons in) holds controls of the height of their size tier, level on
 *   one top, with one gap, on one line when they fit on one, and with their help text under the row.
 *
 * The second part of the file measures the action groups of Rescue and Changes on populated fixtures: pages rendered by
 * their classes from journal entries and ledger rows in every state, so a row of actions with something in it is
 * measured and not only the empty screen a fresh site shows.
 *
 * The measurements are `probeLayout` in `helpers/layout-probe.ts`. To keep a deliberate exception out of a check, mark
 * the element `data-sw-layout-ignore`; there is none today, and a new one needs a reason in the review.
 *
 * Run it on its own: `WP_BASE_URL=... WP_USERNAME=... WP_PASSWORD=... npx playwright test admin-layout.spec.ts`
 * (the default projects are the 1440px, 782px and 390px widths this spec runs at).
 */

const WIDTHS = ['desktop-1440-light', 'tablet-782-light', 'mobile-390-light'];

test.describe('Admin layout', () => {
	test.beforeEach(async ({ page }, info) => {
		test.skip(!WIDTHS.includes(info.project.name), 'The layout check runs at 1440, 782 and 390px.');
		await login(page);
	});

	test('every page of the admin menu keeps its controls the same height, centred, inside their box and apart', async ({ page }) => {
		test.setTimeout(900_000);
		const pages = await menuPages(page);
		// The menu is the source of truth, and it must still reach every page this suite knows by name, except the pages
		// that are the tab of another one (Rescue at its own address): those have no entry of their own.
		expect(
			pages.map((entry) => entry.slug),
			'The admin menu lists every Stonewright page the suite knows',
		).toEqual(expect.arrayContaining(STONEWRIGHT_PAGES.map((entry) => entry.slug).filter((slug) => !(STONEWRIGHT_VIEWS as readonly string[]).includes(slug))));
		// An entry point that has an address of its own is measured too, with its tab bar.
		for (const slug of STONEWRIGHT_VIEWS) {
			pages.push({ slug, path: `/wp-admin/admin.php?page=${slug}`, label: STONEWRIGHT_PAGES.find((entry) => entry.slug === slug)?.label ?? slug } satisfies MenuPage);
		}

		const report: string[] = [];
		let measured = 0;
		for (const entry of pages) {
			await test.step(`${entry.label} (${entry.slug})`, async () => {
				const states = await measurePage(page, entry);
				if (process.env.SW_LAYOUT_VERBOSE) {
					console.log(`${entry.path}: ${states.map((state) => `${state.state} (${state.probe.controls})`).join(', ')}`);
				}
				for (const state of states) {
					measured += state.probe.controls;
					for (const line of findings(state.probe)) {
						report.push(`${entry.slug} / ${state.state} / ${line}`);
					}
				}
			});
		}
		expect(measured, 'The check measured controls').toBeGreaterThan(20);
		expect(report, 'Layout findings').toEqual([]);
	});
});

/**
 * Action groups on populated fixtures. The pages are served from the repository (plugin/tests/fixtures/admin-ui, kept in
 * step with the page classes by RescuePageSnapshotTest and ChangesPageSnapshotTest) with the real stylesheets and scripts,
 * so no WordPress login is needed and the rows have content whatever the site under test holds.
 */
const ORIGIN = 'https://layout-sheet.test';
const repository = path.resolve(__dirname, '..', '..');
const SHEETS: Record<string, string> = {
	'rescue-page.html': 'plugin/tests/fixtures/admin-ui/rescue-page.html',
	'rescue-review.html': 'plugin/tests/fixtures/admin-ui/rescue-review.html',
	'changes-page.html': 'plugin/tests/fixtures/admin-ui/changes-page.html',
	'changes-page-review.html': 'plugin/tests/fixtures/admin-ui/changes-page-review.html',
	'sw-ui.css': 'plugin/assets/admin/sw-ui.css',
	'sw-ui.js': 'plugin/assets/admin/sw-ui.js',
	'shell.css': 'plugin/assets/admin/shell.css',
	'admin.css': 'plugin/assets/admin/admin.css',
	'stonewright-admin.css': 'plugin/assets/css/stonewright-admin.css',
	'rescue.css': 'plugin/assets/admin/pages/rescue.css',
	'rescue.js': 'plugin/assets/admin/pages/rescue.js',
	'changes.css': 'plugin/assets/admin/pages/changes.css',
	'changes.js': 'plugin/assets/admin/pages/changes.js',
	'stonewright-logo.png': 'plugin/assets/admin/stonewright-logo.png',
};
const MIME: Record<string, string> = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png' };

/**
 * The fixtures and what is measured in each: a region (a modal covers the page under it, so a dialog or a drawer is
 * measured on its own and the page again once it is closed) and the least number of action groups it must hold. A page
 * that lost its rows would otherwise pass for having nothing to measure.
 */
const FIXTURES = [
	{
		name: 'Rescue with a change in every state and the safe-mode action in the header',
		file: 'rescue-page.html',
		query: '',
		steps: [{ scope: 'body', groups: 7 }],
	},
	{
		name: 'Rescue, the review of one rollback in production-safe mode',
		file: 'rescue-review.html',
		query: '?review=cs-4444444444444444444444dd',
		steps: [{ scope: 'body', groups: 1 }],
	},
	{
		name: 'Changes with the drawer of a change open, then the list and its filters',
		file: 'changes-page.html',
		query: '?change=cs-aaaaaaaaaaaaaaaaaaaaaaaa',
		steps: [{ scope: '[data-sw-changes-drawer]', groups: 1 }, { scope: 'body', groups: 1, after: 'Escape' }],
	},
	{
		name: 'Changes, the Undo review printed open',
		file: 'changes-page-review.html',
		query: '?change=cs-aaaaaaaaaaaaaaaaaaaaaaaa&undo=1',
		steps: [{ scope: '#sw-changes-undo-dialog', groups: 1 }],
	},
] as const;

async function openSheet(page: Page, file: string, query: string): Promise<void> {
	await page.route(`${ORIGIN}/**`, async (route) => {
		const name = new URL(route.request().url()).pathname.replace(/^\//, '');
		const relative = SHEETS[name];
		if (!relative) {
			await route.fulfill({ status: 404, contentType: 'text/plain', body: `not part of the fixture: ${name}` });
			return;
		}
		const file_ = path.join(repository, relative);
		await route.fulfill({ status: 200, contentType: MIME[path.extname(file_)] ?? 'application/octet-stream', body: fs.readFileSync(file_) });
	});
	await page.goto(`${ORIGIN}/${file}${query}`, { waitUntil: 'load' });
	await page.evaluate(() => document.fonts?.ready).catch(() => undefined);
	await page.waitForTimeout(250);
}

test.describe('Action groups on populated fixtures', () => {
	test.beforeEach(async ({}, info) => {
		test.skip(!WIDTHS.includes(info.project.name), 'The layout check runs at 1440, 782 and 390px.');
	});

	for (const fixture of FIXTURES) {
		test(`${fixture.name}: the controls of every row of actions are one height, level, one gap apart and on one line when they fit`, async ({ page }) => {
			await openSheet(page, fixture.file, fixture.query);

			for (const step of fixture.steps) {
				if ('after' in step) {
					await page.keyboard.press(step.after);
					await page.waitForTimeout(250);
				}
				const probe = await page.evaluate(probeLayout, { scope: step.scope });
				expect(probe.actionGroups, `${fixture.file} ${step.scope}: the fixture holds rows of actions`).toBeGreaterThanOrEqual(step.groups);
				expect(findings(probe), `${fixture.file} ${step.scope}`).toEqual([]);
			}

			// Every dialog that is not open yet, opened the way the layout crawl opens one (showModal(), nothing is submitted).
			const closed = await page.locator('dialog:not([open])').elementHandles();
			for (const [index, dialog] of closed.entries()) {
				await dialog.evaluate((node) => {
					(node as HTMLElement).setAttribute('data-sw-probe', 'dialog');
					(node as HTMLDialogElement).showModal();
				});
				await page.waitForTimeout(150);
				const probe = await page.evaluate(probeLayout, { scope: 'dialog[data-sw-probe]' });
				expect(probe.actionGroups, `${fixture.file}: the buttons of dialog ${index + 1} are a row of actions`).toBeGreaterThanOrEqual(1);
				expect(findings(probe), `${fixture.file}: dialog ${index + 1}`).toEqual([]);
				await dialog.evaluate((node) => {
					(node as HTMLDialogElement).close();
					(node as HTMLElement).removeAttribute('data-sw-probe');
				});
			}
		});
	}

	test('the Rescue row of a change that was never verified has Roll back, Check again and View diff on one line at desktop width, level and evenly spaced', async ({ page }, info) => {
		await openSheet(page, 'rescue-page.html', '');
		const row = page.locator('tr[data-sw-rescue-row="cs-3333333333333333333333cc"]');
		const group = row.locator('[data-sw-actions]').first();
		const controls = group.locator('a, button');

		await expect(controls).toHaveCount(3);
		await expect(controls).toHaveText([/^Roll back/, /^Check again/, /^View diff/]);
		const boxes = await controls.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect()).map((box) => ({ top: box.top, height: box.height, left: box.left, right: box.right })));
		expect(new Set(boxes.map((box) => Math.round(box.height))).size, 'one height').toBe(1);
		if (info.project.name === 'desktop-1440-light') {
			expect(Math.max(...boxes.map((box) => box.top)) - Math.min(...boxes.map((box) => box.top)), 'one top: the three are on one line').toBeLessThanOrEqual(1);
			const gaps = [boxes[1].left - boxes[0].right, boxes[2].left - boxes[1].right];
			expect(Math.abs(gaps[0] - gaps[1]), `the gaps ${gaps.join(' and ')} are one`).toBeLessThanOrEqual(1);
		}
	});

	test('the safe-mode button and its help text: the help is under the button and the button does not move when the help wraps', async ({ page }) => {
		await openSheet(page, 'rescue-page.html', '');
		const panel = page.locator('.sw-rescue-header-action');
		const button = panel.getByRole('button', { name: 'Open in safe mode' });
		const help = panel.locator('#sw-rescue-safe-help');

		await expect(button).toHaveAttribute('aria-describedby', 'sw-rescue-safe-help');
		const before = await button.boundingBox();
		const helpBox = await help.boundingBox();
		expect(helpBox!.y, 'the help is under the button').toBeGreaterThanOrEqual(before!.y + before!.height - 1);
		await help.evaluate((node) => {
			node.textContent = `${node.textContent} ${'A very long second sentence that makes the help wrap onto several lines. '.repeat(6)}`;
		});
		const after = await button.boundingBox();
		expect(after!.y, 'a longer help moves nothing above it').toBe(before!.y);
		expect(after!.height).toBe(before!.height);
	});

	test('the check sees a row that has gone wrong: an uneven height, a stacked row and an uneven gap are each reported', async ({ page }) => {
		await openSheet(page, 'rescue-page.html', '');
		// A row of three buttons that fits on one line at every width: the page's own rows are as wide as their column.
		await page.evaluate(() => {
			const holder = document.createElement('div');
			holder.className = 'sw-ui';
			holder.id = 'sw-probe-row';
			holder.innerHTML = '<div class="sw-ui-actions" data-sw-actions><a class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm" href="#a">One</a><button type="button" class="sw-ui-btn sw-ui-btn--sm">Two</button><a class="sw-ui-btn sw-ui-btn--sm" href="#c">Three</a></div>';
			document.querySelector('.sw-rescue-page')?.prepend(holder);
		});
		const clean = await page.evaluate(probeLayout, { scope: '#sw-probe-row' });
		expect(clean.actionGroups).toBe(1);
		expect(findings(clean), 'the row as it is built').toEqual([]);

		const row = page.locator('#sw-probe-row .sw-ui-actions');
		const first = row.locator('a').first();
		await first.evaluate((node) => ((node as HTMLElement).style.height = '64px'));
		const height = await page.evaluate(probeLayout, { scope: '#sw-probe-row' });
		expect(height.actionGroupMismatches.join('\n'), 'a button taller than its size').toMatch(/is 64px, the height of its size is/);
		await first.evaluate((node) => ((node as HTMLElement).style.height = ''));

		const second = row.locator('button');
		await second.evaluate((node) => ((node as HTMLElement).style.marginLeft = '10px'));
		const gap = await page.evaluate(probeLayout, { scope: '#sw-probe-row' });
		expect(gap.actionGroupMismatches.join('\n'), 'an uneven gap').toMatch(/gap of/);
		await second.evaluate((node) => ((node as HTMLElement).style.marginLeft = ''));

		await row.evaluate((node) => ((node as HTMLElement).style.flexDirection = 'column'));
		const stacked = await page.evaluate(probeLayout, { scope: '#sw-probe-row' });
		expect(stacked.actionGroupMismatches.join('\n'), 'buttons that stack while they fit on one line').toMatch(/lines for 3 controls that fit on one/);
		await row.evaluate((node) => ((node as HTMLElement).style.flexDirection = ''));

		await first.evaluate((node) => ((node as HTMLElement).style.alignSelf = 'flex-end'));
		await row.evaluate((node) => ((node as HTMLElement).style.height = '120px'));
		const tops = await page.evaluate(probeLayout, { scope: '#sw-probe-row' });
		expect(tops.actionGroupMismatches.join('\n'), 'a button that is not level with the others').toMatch(/tops differ|lines for 3 controls that fit on one/);
	});
});
