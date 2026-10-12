import type { Page } from '@playwright/test';
import { gotoAdmin } from './goto-admin';
import { probeLayout, type LayoutProbe } from './layout-probe';

/** One page of the plugin as the admin menu lists it. */
export interface MenuPage {
	/** The `page` query argument ("stonewright-skills"). */
	slug: string;
	/** The path to open, with its query. */
	path: string;
	/** The text of the menu entry. */
	label: string;
}


/** One state of one page that was measured. */
export interface LayoutState {
	/** "page", `tab "Active"` or `dialog "Edit"`. */
	state: string;
	probe: LayoutProbe;
}

const CONTENT = '#wpbody-content';

/**
 * Every Stonewright page of the admin menu, read from the sidebar of a signed-in administrator, so a page that
 * registers a menu entry is measured without being added to a list. The band at the top of the Overview names the
 * same pages (and the ones a hub's landing entry stands for), so its links are read as well.
 */
export async function menuPages(page: Page): Promise<MenuPage[]> {
	await gotoAdmin(page, '/wp-admin/admin.php?page=stonewright-status');
	const found = await page.evaluate(() => {
		const links = Array.from(document.querySelectorAll<HTMLAnchorElement>('#adminmenu a[href*="page=stonewright"], .sw-ui-band a[href*="page=stonewright"]'));
		return links.map((link) => ({ href: link.getAttribute('href') ?? '', label: (link.textContent ?? '').replace(/\s+/g, ' ').trim() }));
	});
	const pages = new Map<string, MenuPage>();
	for (const entry of found) {
		const url = new URL(entry.href, 'http://admin.invalid/wp-admin/');
		const slug = url.searchParams.get('page') ?? '';
		const tab = url.searchParams.get('tab') ?? '';
		const key = `${slug}|${tab}`;
		if (!slug.startsWith('stonewright') || pages.has(key)) {
			continue;
		}
		const query = `page=${encodeURIComponent(slug)}${tab ? `&tab=${encodeURIComponent(tab)}` : ''}`;
		pages.set(key, { slug, path: `/wp-admin/admin.php?${query}`, label: entry.label });
	}
	return Array.from(pages.values());
}

/** Wait until the page has stopped loading its own content (skeletons and busy regions gone). */
export async function settle(page: Page): Promise<void> {
	await page.waitForLoadState('load').catch(() => undefined);
	await page
		.waitForFunction(() => !document.querySelector(`${'#wpbody-content'} [aria-busy="true"], #wpbody-content .sw-ui-skeleton`), undefined, { timeout: 10_000 })
		.catch(() => undefined);
	// Layout of fonts and late script work.
	await page.evaluate(() => document.fonts?.ready).catch(() => undefined);
	await page.waitForTimeout(250);
}

/**
 * Measure a page in every state it can be put in without changing anything: as loaded, with each of its tabs
 * selected (a tab is a button that shows a panel, or a link to the same page with another `tab`), and with each of
 * its dialogs open (a dialog is opened with `showModal()`, never through its trigger, so nothing is submitted).
 */
export async function measurePage(page: Page, entry: MenuPage): Promise<LayoutState[]> {
	const states: LayoutState[] = [];
	await gotoAdmin(page, entry.path);
	await settle(page);
	states.push({ state: 'page', probe: await page.evaluate(probeLayout, { scope: CONTENT }) });

	// Tabs that are buttons.
	const tabs = await page.locator(`${CONTENT} [role="tab"]`).evaluateAll((nodes) =>
		nodes.map((node, index) => ({ index, name: (node.textContent ?? '').replace(/\s+/g, ' ').trim(), selected: node.getAttribute('aria-selected') === 'true' })),
	);
	for (const tab of tabs) {
		if (tab.selected) {
			continue;
		}
		const target = page.locator(`${CONTENT} [role="tab"]`).nth(tab.index);
		if (!(await target.isVisible())) {
			continue;
		}
		await target.click();
		await settle(page);
		states.push({ state: `tab "${tab.name}"`, probe: await page.evaluate(probeLayout, { scope: CONTENT }) });
	}

	// Tabs that are links to the same page.
	const tabLinks = await page.evaluate((slug) => {
		const out = new Map<string, string>();
		document.querySelectorAll<HTMLAnchorElement>('#wpbody-content a[href*="tab="]').forEach((link) => {
			const url = new URL(link.href, location.href);
			if (url.searchParams.get('page') === slug && link.getClientRects().length > 0 && !out.has(url.search)) {
				out.set(url.search, (link.textContent ?? '').replace(/\s+/g, ' ').trim());
			}
		});
		return Array.from(out.entries());
	}, entry.slug);
	for (const [search, name] of tabLinks) {
		if (entry.path.endsWith(search)) {
			continue;
		}
		await gotoAdmin(page, `/wp-admin/admin.php${search}`);
		await settle(page);
		states.push({ state: `tab link "${name}"`, probe: await page.evaluate(probeLayout, { scope: CONTENT }) });
	}

	// Dialogs, on the page as first loaded.
	await gotoAdmin(page, entry.path);
	await settle(page);
	const dialogs = await page.locator(`${CONTENT} dialog`).evaluateAll((nodes) =>
		nodes.map((node, index) => ({ index, name: (node.getAttribute('aria-label') ?? node.id ?? '').slice(0, 40) })),
	);
	for (const dialog of dialogs) {
		const opened = await page
			.locator(`${CONTENT} dialog`)
			.nth(dialog.index)
			.evaluate((node) => {
				const element = node as HTMLDialogElement;
				if (typeof element.showModal !== 'function') {
					return false;
				}
				element.showModal();
				return true;
			});
		if (!opened) {
			continue;
		}
		await page.waitForTimeout(250);
		states.push({ state: `dialog "${dialog.name}"`, probe: await page.evaluate(probeLayout, { scope: `${CONTENT} dialog[open]` }) });
		await page.locator(`${CONTENT} dialog`).nth(dialog.index).evaluate((node) => (node as HTMLDialogElement).close());
	}
	return states;
}

/** The findings of one state as readable lines, empty when the state is clean. */
export function findings(probe: LayoutProbe): string[] {
	return [
		...probe.heightMismatches.map((line) => `height: ${line}`),
		...probe.centreMismatches.map((line) => `centre: ${line}`),
		...probe.overflows.map((line) => `overflow: ${line}`),
		...probe.overlaps.map((line) => `overlap: ${line}`),
		...probe.actionGroupMismatches.map((line) => `action group: ${line}`),
	];
}
