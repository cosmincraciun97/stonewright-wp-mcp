import { expect, test, type Locator, type Page } from '@playwright/test';
import { EXP_HINT, STONEWRIGHT_BAND, STONEWRIGHT_EXP_LINKS, STONEWRIGHT_PAGES } from './helpers/admin-pages';
import { gotoAdmin } from './helpers/goto-admin';
import { login } from './helpers/login';

/**
 * The band at the top of every Stonewright page, and the EXP markers in the band and the sidebar.
 *
 * The band is a dark panel with the product mark and name, then one link to every page in groups; the page that is
 * open is the current link. A page that is still changing has a small "EXP" marker on its link and in the sidebar,
 * with the tooltip "This feature is experimental.": in the band on hover, keyboard focus and Escape, in the sidebar
 * on pointer hover over the marker. These checks run on the real pages, where WordPress's own stylesheets load.
 */

const DESIGN = '/wp-admin/admin.php?page=stonewright-design';
const SETUP = '/wp-admin/admin.php?page=stonewright';

const WIDTHS = [1440, 1024, 782, 400, 320] as const;

interface Box {
	x: number;
	y: number;
	width: number;
	height: number;
	right: number;
	bottom: number;
}

async function box(locator: Locator): Promise<Box> {
	return locator.evaluate((element) => {
		const rect = element.getBoundingClientRect();
		return { x: rect.x, y: rect.y, width: rect.width, height: rect.height, right: rect.right, bottom: rect.bottom };
	});
}

async function open(page: Page, url = DESIGN): Promise<void> {
	await gotoAdmin(page, url);
	await page.locator('.sw-ui-band').waitFor({ state: 'visible', timeout: 15_000 });
}

function expLink(page: Page, label: string): Locator {
	return page.locator('.sw-ui-band__link--exp', { hasText: label });
}

test.describe('The band', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	test('shows the mark, the name and a link to every page in groups, and the groups of two or more carry a label', async ({ page }) => {
		await open(page);
		const width = page.viewportSize()?.width ?? 1440;

		const logo = page.locator('.sw-ui-band__logo');
		await expect(logo).toHaveAttribute('alt', 'Stonewright');
		expect(await logo.evaluate((image: HTMLImageElement) => ({ natural: image.naturalWidth, shown: Math.round(image.getBoundingClientRect().width), height: Math.round(image.getBoundingClientRect().height) }))).toEqual({ natural: 256, shown: 28, height: 28 });
		await expect(page.locator('.sw-ui-band__name')).toHaveText('Stonewright');
		await expect(page.locator('.sw-ui-band__brand a, .sw-ui-band__brand [tabindex]')).toHaveCount(0);
		expect(await page.locator('.sw-ui-band__name').evaluate((element) => ({ weight: getComputedStyle(element).fontWeight, size: getComputedStyle(element).fontSize }))).toEqual({ weight: '700', size: '14px' });

		const groups = await page.locator('.sw-ui-band__group').evaluateAll((nodes) =>
			nodes.map((node) => {
				const label = node.querySelector<HTMLElement>('.sw-ui-band__label');
				const style = getComputedStyle(node);
				return {
					links: Array.from(node.querySelectorAll('a')).map((anchor) => (anchor.childNodes[0]?.textContent ?? '').trim()),
					label: label ? { text: label.textContent, shown: getComputedStyle(label).display !== 'none', shownText: label.innerText, hidden: label.getAttribute('aria-hidden') } : null,
					rule: style.borderLeftWidth,
					role: node.getAttribute('role'),
				};
			}),
		);
		expect(groups.map((group) => group.links)).toEqual(STONEWRIGHT_BAND.map((group) => [...group.links]));
		for (const [index, definition] of STONEWRIGHT_BAND.entries()) {
			const group = groups[index];
			expect(group.role, 'groups have no role').toBeNull();
			if (definition.links.length < 2) {
				expect(group.label, `${definition.hub} is one link: no label`).toBeNull();
				expect(group.rule, `${definition.hub} is one link: no rule`).toBe('0px');
				continue;
			}
			expect(group.label?.text).toBe(definition.hub);
			expect(group.label?.hidden).toBe('true');
			if (width > 400) {
				expect(group.label?.shown, `${definition.hub} label is shown above 400px`).toBe(true);
				expect(group.label?.shownText, 'in capitals').toBe(definition.hub.toUpperCase());
				// The first group has no rule; the others have one thin rule.
				expect(group.rule, `${definition.hub} has a thin rule`).toBe(index === 0 ? '0px' : '1px');
			} else {
				expect(group.label?.shown, 'labels are hidden at 400px and below').toBe(false);
				expect(group.rule, 'no rule at 400px and below').toBe('0px');
			}
		}
	});

	test('marks the page that is open as the current link, with the indigo pill, and only that one', async ({ page }) => {
		await open(page);
		const current = page.locator('.sw-ui-band a[aria-current="page"]');

		await expect(current).toHaveCount(1);
		await expect(current).toHaveText(/^Design/);
		expect(await current.evaluate((link) => getComputedStyle(link).backgroundColor)).toBe('rgba(79, 70, 229, 0.35)');
		await current.hover();
		await page.waitForTimeout(250);
		expect(await current.evaluate((link) => getComputedStyle(link).backgroundColor), 'hover changes nothing on the current page').toBe('rgba(79, 70, 229, 0.35)');
	});

	test('draws the rest, hover and link sizes of the band', async ({ page }) => {
		await open(page, SETUP);
		const width = page.viewportSize()?.width ?? 1440;
		const link = page.locator('.sw-ui-band__link:not([aria-current])', { hasText: 'AI Abilities' });

		const rest = await link.evaluate((anchor) => {
			const style = getComputedStyle(anchor);
			return { background: style.backgroundColor, color: style.color, weight: style.fontWeight, size: style.fontSize, radius: style.borderRadius, decoration: style.textDecorationLine, height: anchor.getBoundingClientRect().height, padding: style.paddingLeft, cursor: style.cursor };
		});
		expect(rest.background).toBe('rgba(0, 0, 0, 0)');
		expect(rest.color).toBe('rgba(248, 249, 250, 0.78)');
		expect(rest.weight).toBe('500');
		expect(rest.radius).toBe('4px');
		expect(rest.decoration).toBe('none');
		expect(rest.size).toBe(width <= 400 ? '12px' : '13px');
		expect(rest.height).toBe(width <= 400 ? 28 : 32);
		expect(rest.padding).toBe(width <= 400 ? '8px' : '12px');

		await link.hover();
		await page.waitForTimeout(250);
		expect(await link.evaluate((anchor) => ({ background: getComputedStyle(anchor).backgroundColor, color: getComputedStyle(anchor).color }))).toEqual({ background: 'rgba(255, 255, 255, 0.08)', color: 'rgb(248, 249, 250)' });
	});

	test('sits across the page with the spec padding, a dark square panel, and the page header below it', async ({ page }) => {
		await open(page);
		const width = page.viewportSize()?.width ?? 1440;

		const band = await page.locator('.sw-ui-band').evaluate((element) => {
			const style = getComputedStyle(element);
			const rect = element.getBoundingClientRect();
			const title = (document.querySelector('.sw-shell__chrome h1.sw-ui-page-title') as HTMLElement).getBoundingClientRect();
			const content = (document.getElementById('wpcontent') as HTMLElement).getBoundingClientRect();
			return {
				x: rect.x - content.x,
				right: Math.round(rect.right),
				background: style.backgroundColor,
				color: style.color,
				radius: style.borderRadius,
				border: style.borderTopWidth,
				shadow: style.boxShadow,
				padding: [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft],
				position: style.position,
				gapToTitle: Math.round(title.top - rect.bottom),
			};
		});
		expect(band.background).toBe('rgb(22, 24, 29)');
		expect(band.color).toBe('rgb(248, 249, 250)');
		expect(band.radius).toBe('0px');
		expect(band.border).toBe('0px');
		expect(band.shadow).toContain('rgba(23, 25, 33, 0.1)');
		expect(band.position).toBe('static');
		expect(band.padding).toEqual(['12px', width > 782 ? '24px' : width > 400 ? '12px' : '8px', '12px', width > 782 ? '24px' : width > 400 ? '12px' : '8px']);
		// WordPress gives the content area 20px of padding on the left (10px at 782px and below); the phone adds an 8px inset.
		expect(Math.round(band.x)).toBe(width > 782 ? 20 : width > 400 ? 10 : 18);
		expect(band.right, 'the band touches the right edge, or is inset 8px on a phone').toBe(width > 400 ? width : width - 8);
		expect(band.gapToTitle, 'the page title sits about 24px below the band; 16px at 782px and below').toBe(width > 782 ? 24 : 16);
	});

	test('scrolls away with the page: it is not pinned', async ({ page }) => {
		await open(page);
		const before = await box(page.locator('.sw-ui-band'));
		await page.evaluate(() => window.scrollTo(0, 800));
		const after = await box(page.locator('.sw-ui-band'));

		expect(after.y).toBeLessThan(before.y - 400);
	});

	test('prints the page header right below the band with no overlap, whatever notices WordPress shows', async ({ page }) => {
		await open(page);
		const band = await box(page.locator('.sw-ui-band'));
		const h1 = await box(page.locator('.sw-ui-page-title'));

		expect(h1.y).toBeGreaterThan(band.bottom);
		expect(h1.y - band.bottom).toBeLessThanOrEqual(60);
	});

	test('puts the EXP marker at the top right of the five links that are still changing, and nowhere else', async ({ page }) => {
		await open(page);
		const width = page.viewportSize()?.width ?? 1440;

		const marked = await page.locator('.sw-ui-band__exp').evaluateAll((nodes) => nodes.map((node) => (node.closest('a')?.childNodes[0]?.textContent ?? '').trim()));
		expect(marked).toEqual([...STONEWRIGHT_EXP_LINKS]);

		for (const label of STONEWRIGHT_EXP_LINKS) {
			const link = expLink(page, label);
			const marker = link.locator('.sw-ui-band__exp');
			const linkBox = await box(link);
			const markerBox = await box(marker);
			const style = await marker.evaluate((element) => {
				const computed = getComputedStyle(element);
				return { size: computed.fontSize, weight: computed.fontWeight, height: computed.lineHeight, transform: computed.textTransform, color: computed.color, cursor: computed.cursor, spacing: computed.letterSpacing, ariaHidden: element.getAttribute('aria-hidden') };
			});

			expect(Math.round(markerBox.y - linkBox.y), `${label}: 4px below the top of the link`).toBe(4);
			expect(Math.round(linkBox.right - markerBox.right), `${label}: 6px in from the right edge`).toBe(6);
			expect(style).toEqual({ size: '8px', weight: '600', height: '8px', transform: 'uppercase', color: 'rgb(248, 249, 250)', cursor: 'help', spacing: '0.48px', ariaHidden: 'true' });
			expect(await marker.innerText()).toBe('EXP');
			// The marker never covers the label: the link's right padding leaves room for it.
			const textRight = await link.evaluate((anchor) => {
				const range = document.createRange();
				range.selectNodeContents(anchor.childNodes[0]);
				return range.getBoundingClientRect().right;
			});
			expect(textRight, `${label}: the marker starts after the end of the label`).toBeLessThan(markerBox.x);
			expect(await link.evaluate((anchor) => parseFloat(getComputedStyle(anchor).paddingRight))).toBeCloseTo(width <= 400 ? 30 : 32, 0);
		}
	});

	test('gives each EXP link a permanent description in hidden text and keeps the marker out of the accessible name', async ({ page }) => {
		await open(page);

		for (const label of STONEWRIGHT_EXP_LINKS) {
			const link = expLink(page, label);
			await expect(link.locator('.sw-ui-visually-hidden')).toHaveText(EXP_HINT);
			await expect(link.locator('.sw-ui-band__exp')).toHaveAttribute('aria-hidden', 'true');
			await expect(link).not.toHaveAttribute('aria-describedby', /.+/);
		}
		const bandLinks = page.getByRole('navigation', { name: 'Stonewright admin' }).getByRole('link');
		await expect(bandLinks.filter({ hasText: 'Design' })).toHaveAccessibleName(`Design ${EXP_HINT}`);
		await expect(bandLinks).toHaveCount(STONEWRIGHT_BAND.reduce((total, group) => total + group.links.length, 0));
	});
});

test.describe('The band tooltip', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	async function tip(page: Page): Promise<Locator> {
		const tooltip = page.locator('[role="tooltip"].sw-ui-band-tip');
		await expect(tooltip).toHaveCount(1);
		return tooltip;
	}

	test('shows on hover anywhere over the link, above it and centred, 8px away, and is the link\'s description while it shows', async ({ page }) => {
		await open(page);

		for (const label of STONEWRIGHT_EXP_LINKS) {
			const link = expLink(page, label);
			await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);
			await link.hover({ position: { x: 6, y: 16 } });
			const tooltip = await tip(page);
			await expect(tooltip).toHaveText(EXP_HINT);
			await expect(tooltip).toHaveCSS('opacity', '1');

			const linkBox = await box(link);
			const tipBox = await box(tooltip);
			// Centred on the whole link, unless that would put it closer than 8px to a viewport edge: then it is kept 8px inside.
			const viewport = await page.evaluate(() => document.documentElement.clientWidth);
			const centred = linkBox.x + linkBox.width / 2 - tipBox.width / 2;
			const expectedX = Math.min(Math.max(centred, 8), viewport - 8 - tipBox.width);
			expect(Math.abs(tipBox.x - expectedX), `${label}: centred on the whole link, or kept 8px inside the viewport`).toBeLessThanOrEqual(1);
			expect(Math.round(linkBox.y - tipBox.bottom), `${label}: 8px above the link`).toBe(8);
			expect(await link.getAttribute('aria-describedby')).toBe(await tooltip.getAttribute('id'));

			const style = await tooltip.evaluate((element) => {
				const computed = getComputedStyle(element);
				return { background: computed.backgroundColor, color: computed.color, size: computed.fontSize, weight: computed.fontWeight, radius: computed.borderRadius, padding: computed.padding, pointer: computed.pointerEvents, border: computed.borderTopWidth, parent: element.parentElement?.className ?? '' };
			});
			expect(style).toMatchObject({ background: 'rgb(23, 25, 33)', color: 'rgb(247, 248, 251)', size: '12px', weight: '400', radius: '4px', padding: '8px 12px', pointer: 'none', border: '0px' });
			expect(style.parent, 'it is added to the portal of the layer, outside the band').toContain('sw-ui-portal');
			expect(tipBox.width).toBeLessThanOrEqual(280);

			// Over the marker counts as over the link; leaving the link removes the tooltip and the description at once.
			await link.locator('.sw-ui-band__exp').hover();
			await expect(page.locator('.sw-ui-band-tip')).toHaveCount(1);
			await page.mouse.move(2, 2);
			await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);
			await expect(link).not.toHaveAttribute('aria-describedby', /.+/);
		}
	});

	test('never shows on a link without the marker, and shows one at a time', async ({ page }) => {
		await open(page);

		await page.locator('.sw-ui-band__link:not(.sw-ui-band__link--exp)', { hasText: 'Skills' }).hover();
		await page.waitForTimeout(300);
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);

		await expLink(page, 'Context').hover();
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(1);
		await expLink(page, 'Design').hover();
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(1);
	});

	test('shows on keyboard focus, closes with Escape, and the next Tab hides it', async ({ page }) => {
		await open(page);
		const design = expLink(page, 'Design');

		await page.keyboard.press('Tab');
		await design.focus();
		const tooltip = await tip(page);
		await expect(tooltip).toHaveText(EXP_HINT);
		expect(await design.getAttribute('aria-describedby')).toBe(await tooltip.getAttribute('id'));

		await page.keyboard.press('Escape');
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);
		await expect(design).not.toHaveAttribute('aria-describedby', /.+/);
		await expect(design).toBeFocused();

		await design.blur();
		await design.focus();
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(1);
		await page.keyboard.press('Tab');
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);
	});

	test('Escape closes it while the pointer is over the link, and it stays closed until the pointer leaves and comes back', async ({ page }) => {
		await open(page);
		const context = expLink(page, 'Context');

		await context.hover();
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(1);
		await page.keyboard.press('Escape');
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);
		await context.locator('.sw-ui-band__exp').hover();
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(0);
		await page.mouse.move(2, 2);
		await context.hover();
		await expect(page.locator('.sw-ui-band-tip')).toHaveCount(1);
	});

	test('fades in over 0.15s, and not at all under reduced motion', async ({ page }) => {
		await open(page);
		await expLink(page, 'Troubleshoot').hover();
		const tooltip = await tip(page);
		expect(await tooltip.evaluate((element) => ({ property: getComputedStyle(element).transitionProperty, duration: getComputedStyle(element).transitionDuration, timing: getComputedStyle(element).transitionTimingFunction }))).toEqual({
			property: 'opacity',
			duration: '0.15s',
			timing: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
		});

		await page.mouse.move(2, 2);
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await expLink(page, 'Troubleshoot').hover();
		const calm = await tip(page);
		expect(await calm.evaluate((element) => getComputedStyle(element).transitionDuration)).toBe('0s');
		await expect(calm).toHaveCSS('opacity', '1');
	});

	test('is above the admin bar', async ({ page }) => {
		await open(page);
		await expLink(page, 'Design').hover();
		const tooltip = await tip(page);

		const layers = await tooltip.evaluate((element) => ({ tip: parseInt(getComputedStyle(element).zIndex, 10), bar: parseInt(getComputedStyle(document.getElementById('wpadminbar') as HTMLElement).zIndex, 10) }));
		expect(layers.tip).toBe(100000);
		expect(layers.tip).toBeGreaterThan(layers.bar);
	});

	test('stays 8px inside the viewport at 320px, for every link that has one', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 568 });
		await open(page);
		const width = await page.evaluate(() => document.documentElement.clientWidth);

		for (const label of STONEWRIGHT_EXP_LINKS) {
			await page.mouse.move(2, 2);
			await expLink(page, label).hover();
			const tooltip = await tip(page);
			await expect(tooltip).toHaveText(EXP_HINT);
			const tipBox = await box(tooltip);
			expect(tipBox.x, `${label}: 8px from the left edge`).toBeGreaterThanOrEqual(7.5);
			expect(tipBox.right, `${label}: 8px from the right edge`).toBeLessThanOrEqual(width - 7.5);
		}
	});
});

test.describe('The sidebar EXP marker', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	/** Show the Stonewright submenu the way the viewport does: open in the sidebar, as a flyout when folded, or in the mobile menu. */
	async function showSubmenu(page: Page): Promise<void> {
		const width = page.viewportSize()?.width ?? 1440;
		if (width <= 782) {
			await page.locator('#wp-admin-bar-menu-toggle a').click();
		} else if (width <= 960) {
			await page.locator('#toplevel_page_stonewright > a').hover();
		}
		await expect(page.locator('#toplevel_page_stonewright .wp-submenu')).toBeVisible();
	}

	test('has the EXP marker after the label of the five entries that are still changing, with the words as hidden text', async ({ page }) => {
		await open(page, SETUP);
		await showSubmenu(page);
		const entries = page.locator('#toplevel_page_stonewright .wp-submenu li:not(.wp-submenu-head) a', { has: page.locator('.sw-menu-exp') });

		await expect(entries).toHaveCount(5);
		await expect(entries.locator('.sw-menu-label')).toHaveText(['Troubleshoot', 'Context', 'Design', 'Rescue', 'Changes']);
		await expect(entries.locator('.sw-menu-exp')).toHaveText(['EXP', 'EXP', 'EXP', 'EXP', 'EXP']);
		expect(await entries.locator('.sw-menu-exp').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-hidden')))).toEqual(['true', 'true', 'true', 'true', 'true']);
		await expect(entries.locator('.screen-reader-text')).toHaveText([EXP_HINT, EXP_HINT, EXP_HINT, EXP_HINT, EXP_HINT]);
		await expect(page.locator('#toplevel_page_stonewright .sw-menu-beta')).toHaveCount(0);

		const marker = page.locator('#toplevel_page_stonewright .sw-menu-exp').first();
		const paint = await marker.evaluate((element) => {
			const style = getComputedStyle(element);
			return { size: style.fontSize, weight: style.fontWeight, transform: style.textTransform, color: style.color, cursor: style.cursor, gap: style.marginLeft, height: element.getBoundingClientRect().height, tabindex: element.getAttribute('tabindex') };
		});
		expect(paint).toEqual({ size: '9px', weight: '600', transform: 'uppercase', color: 'rgb(255, 255, 255)', cursor: 'help', gap: '10px', height: 18, tabindex: null });
	});

	test('shows its tooltip to the right of the marker while the pointer is over the marker, instantly, and not otherwise', async ({ page }) => {
		await open(page, SETUP);
		await showSubmenu(page);
		const context = page.locator('#toplevel_page_stonewright .wp-submenu a', { has: page.locator('.sw-menu-exp') }).filter({ hasText: 'Context' });
		const marker = context.locator('.sw-menu-exp');
		const after = (locator: Locator) =>
			locator.evaluate((element) => {
				const style = getComputedStyle(element, '::after');
				return { display: style.display, content: style.content, background: style.backgroundColor, color: style.color, size: style.fontSize, radius: style.borderRadius, padding: style.padding, transition: style.transitionDuration, z: style.zIndex, nowrap: style.whiteSpace, pointer: style.pointerEvents };
			});

		// Over the label, or on the keyboard: nothing.
		await context.locator('.sw-menu-label').hover();
		expect((await after(marker)).display).toBe('none');
		await context.focus();
		expect((await after(marker)).display).toBe('none');

		await marker.hover();
		const shown = await after(marker);
		expect(shown).toMatchObject({ display: 'block', content: `"${EXP_HINT}"`, background: 'rgb(29, 35, 39)', color: 'rgb(255, 255, 255)', size: '11px', radius: '3px', padding: '5px 8px', transition: '0s', z: '100000', nowrap: 'nowrap', pointer: 'none' });

		await page.mouse.move(2, 2);
		expect((await after(marker)).display, 'gone at once when the pointer leaves').toBe('none');
	});

	test('is not clipped by the sidebar, the flyout or the mobile menu: it reaches the page area to the right', async ({ page }) => {
		await open(page, SETUP);
		await showSubmenu(page);
		const marker = page.locator('#toplevel_page_stonewright .sw-menu-exp').filter({ hasText: 'EXP' }).nth(1);
		await marker.hover();

		const report = await marker.evaluate((element) => {
			const markerBox = element.getBoundingClientRect();
			// The tooltip starts 8px right of the marker and is one line of 11px text with 8px padding at each side.
			const reach = { left: markerBox.right + 8, top: markerBox.top, right: markerBox.right + 8 + 140, bottom: markerBox.bottom };
			const clipping: string[] = [];
			// The body and the root are the page's own scroll containers (a plugin can set overflow on the body); the
			// marker is in view while it is hovered, so only the sidebar, the flyout and the menu can cut the tooltip.
			for (let node = element.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
				const style = getComputedStyle(node);
				const clips = [style.overflowX, style.overflowY].some((value) => value !== 'visible') || style.contain.includes('paint') || style.clipPath !== 'none';
				if (!clips) {
					continue;
				}
				const rect = node.getBoundingClientRect();
				if (rect.right < reach.right || rect.left > reach.left || rect.top > reach.top || rect.bottom < reach.bottom) {
					clipping.push(`${node.tagName.toLowerCase()}#${node.id}.${node.className} overflow ${style.overflowX}/${style.overflowY}`);
				}
			}
			return { clipping, markerRight: markerBox.right, viewport: document.documentElement.clientWidth };
		});

		expect(report.clipping, 'ancestors that would cut the tooltip').toEqual([]);
		expect(report.viewport - report.markerRight, 'there is room to the right of the marker in the viewport').toBeGreaterThan(140);
	});

	test('shows its tooltip in the folded flyout on a WordPress screen that is not a Stonewright page', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'The check sets its own width, so it runs once.');
		await page.setViewportSize({ width: 900, height: 900 });
		await gotoAdmin(page, '/wp-admin/edit.php');
		await expect(page.locator('#stonewright-admin-menu')).toHaveCount(1);
		await page.locator('#toplevel_page_stonewright > a').hover();
		const marker = page.locator('#toplevel_page_stonewright .wp-submenu a', { hasText: 'Context' }).locator('.sw-menu-exp');
		await expect(marker).toBeVisible();
		await marker.hover();

		const shown = await marker.evaluate((element) => {
			const after = getComputedStyle(element, '::after');
			const box = element.getBoundingClientRect();
			return { display: after.display, content: after.content, markerRight: box.right, viewport: document.documentElement.clientWidth };
		});
		expect(shown.display).toBe('block');
		expect(shown.content).toBe(`"${EXP_HINT}"`);
		expect(shown.viewport - shown.markerRight, 'room for the tooltip to the right of the flyout').toBeGreaterThan(160);
	});
});

test.describe('The band at every width', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'The sweep sets its own widths, so it runs once.');
		await login(page);
	});

	for (const width of WIDTHS) {
		test(`has no horizontal overflow at ${width}px on any page, and the band fits the content area`, async ({ page }) => {
			test.setTimeout(240_000);
			await page.setViewportSize({ width, height: width < 500 ? 800 : 900 });

			for (const { slug, label } of STONEWRIGHT_PAGES) {
				await gotoAdmin(page, `/wp-admin/admin.php?page=${slug}`);
				await page.locator('.sw-ui-band').waitFor({ state: 'visible', timeout: 15_000 });
				const measured = await page.evaluate(() => {
					const band = (document.querySelector('.sw-ui-band') as HTMLElement).getBoundingClientRect();
					const root = document.documentElement;
					return { overflow: root.scrollWidth - root.clientWidth, bandRight: band.right, bandLeft: band.left, viewport: root.clientWidth };
				});
				expect(measured.overflow, `${label} at ${width}px: horizontal overflow`).toBeLessThanOrEqual(0);
				expect(measured.bandRight, `${label} at ${width}px: the band stays in the viewport`).toBeLessThanOrEqual(measured.viewport);
				expect(measured.bandLeft, `${label} at ${width}px`).toBeGreaterThanOrEqual(0);
			}
		});
	}

	test('matches the measured rectangle of the band at each width: x and width on the Design page', async ({ page }) => {
		// WordPress sidebar 160px wide, 36px folded between 783 and 960, hidden at 782 and below; the content area adds 20px (10px) on the left.
		const expected: Record<number, { x: number; width: number }> = {
			1440: { x: 180, width: 1260 },
			1024: { x: 180, width: 844 },
			782: { x: 10, width: 772 },
			400: { x: 18, width: 374 },
			320: { x: 18, width: 294 },
		};
		for (const width of WIDTHS) {
			await page.setViewportSize({ width, height: 900 });
			await open(page);
			const band = await box(page.locator('.sw-ui-band'));
			expect({ x: Math.round(band.x), width: Math.round(band.width) }, `at ${width}px`).toEqual(expected[width]);
		}
	});
});
