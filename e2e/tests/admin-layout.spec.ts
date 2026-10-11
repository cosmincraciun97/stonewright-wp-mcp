import { expect, test } from '@playwright/test';
import { STONEWRIGHT_PAGES } from './helpers/admin-pages';
import { findings, measurePage, menuPages } from './helpers/layout-crawl';
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
		// The menu is the source of truth, and it must still reach every page this suite knows by name.
		expect(
			pages.map((entry) => entry.slug),
			'The admin menu lists every Stonewright page the suite knows',
		).toEqual(expect.arrayContaining(STONEWRIGHT_PAGES.map((entry) => entry.slug)));

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
