import { expect, test, type Locator, type Page } from '@playwright/test';
import { gotoAdmin } from './helpers/goto-admin';
import { login } from './helpers/login';

/**
 * Pointer focus and keyboard focus on Stonewright controls.
 *
 * WordPress's own admin CSS draws a ring on every `:focus` (`a:focus`, `.wp-core-ui .button:focus`,
 * `.wp-core-ui .button-primary:focus`), the mouse included, so a clicked tab or button kept a box until the next
 * page loaded. Stonewright's controls show nothing after a click or a tap; a keyboard reaching the same control
 * (`:focus-visible`) sees a ring of at least 2px (WCAG 2.4.7 and 2.4.11). These checks run on the real pages, where
 * WordPress's stylesheets are loaded, because the component sheet has none of them.
 */

const SETUP = '/wp-admin/admin.php?page=stonewright';
const ABILITIES = '/wp-admin/admin.php?page=stonewright-abilities';
const SANDBOX = '/wp-admin/admin.php?page=stonewright-sandbox';
const MEMORY = '/wp-admin/admin.php?page=stonewright-memory';
const SKILLS_IMPORT = '/wp-admin/admin.php?page=stonewright-skills&view=import';

interface Paint {
	outlineStyle: string;
	outlineWidth: number;
	outlineColor: string;
	boxShadow: string;
	borders: string;
}

interface Target {
	name: string;
	page: string;
	/** The control under test; it must not be the current tab or link. */
	find: (page: Page) => Locator;
	/** A control that navigates, copies or saves is held back from doing so: only its focus is under test. */
	inert?: boolean;
	/** A control the mouse does not focus (a file field opens its dialog instead): only the keyboard ring is checked here. */
	keyboardOnly?: boolean;
	/** Runs after the page loads and before the control is found, for a control the page does not print itself. */
	prepare?: (page: Page) => Promise<void>;
}

/**
 * Every page prints its controls with the shared UI layer, so no page has older markup of its own. This adds, at the
 * start of the content region, the markup a page or another plugin could still print there: a plain link and a core
 * `.button`.
 */
async function addOlderMarkup(page: Page): Promise<void> {
	await page.evaluate(() => {
		const main = document.querySelector('.sw-shell__main');
		if (!main || main.querySelector('[data-older-markup]')) {
			return;
		}
		const holder = document.createElement('p');
		holder.setAttribute('data-older-markup', '');
		const link = document.createElement('a');
		link.href = '#older-markup';
		link.textContent = 'Older markup link';
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'button';
		button.textContent = 'Older markup button';
		holder.append(link, ' ', button);
		main.prepend(holder);
	});
}

const TARGETS: Target[] = [
	{ name: 'band link', page: SETUP, find: (page) => page.locator('.sw-ui-band__link:not([aria-current])').first(), inert: true },
	{ name: 'band EXP link', page: SETUP, find: (page) => page.locator('.sw-ui-band__link--exp:not([aria-current])').first(), inert: true },
	{ name: 'page tab', page: SANDBOX, find: (page) => page.locator('.sw-ui-hubnav__link:not([aria-current])').first(), inert: true },
	{ name: 'view tab', page: SETUP, find: (page) => page.locator('.sw-setup .sw-ui-tabs > a.sw-ui-tabs__tab[aria-selected="false"]').first(), inert: true },
	{ name: 'primary button', page: SETUP, find: (page) => page.locator('button.sw-ui-btn--primary:visible').first(), inert: true },
	{ name: 'primary link', page: SANDBOX, find: (page) => page.locator('a.sw-ui-btn--primary:visible', { hasText: 'New file' }).first(), inert: true },
	{ name: 'secondary button', page: SETUP, find: (page) => page.locator('button.sw-ui-btn:not(.sw-ui-btn--primary):visible').first(), inert: true },
	{ name: 'secondary link', page: MEMORY, find: (page) => page.locator('a.sw-ui-btn:not(.sw-ui-btn--primary):visible').first(), inert: true },
	{ name: 'step choice card', page: SETUP, find: (page) => page.locator('.sw-ui-choice[role="radio"]:not([disabled]):visible').first(), inert: true },
	{ name: 'client choice', page: SETUP, find: (page) => page.locator('.sw-ui-choice[role="tab"][aria-selected="false"]:visible').first(), inert: true },
	{ name: 'Skills import file field', page: SKILLS_IMPORT, find: (page) => page.locator('.sw-ui-dropzone input[type="file"]'), inert: true, keyboardOnly: true },
	{ name: 'disclosure summary', page: SETUP, find: (page) => page.locator('.sw-setup summary:visible').first(), inert: true },
	{ name: 'older-markup link', page: ABILITIES, prepare: addOlderMarkup, find: (page) => page.locator('.sw-shell__main [data-older-markup] a'), inert: true },
	{ name: 'older-markup core .button', page: SANDBOX, prepare: addOlderMarkup, find: (page) => page.locator('.sw-shell__main [data-older-markup] button.button'), inert: true },
	{ name: 'older-markup button', page: ABILITIES, find: (page) => page.locator('.sw-shell__main button:visible, .sw-shell__main .button:visible').first(), inert: true },
];

/** Computed paint of a control that a focus ring could change. */
async function paintOf(control: Locator): Promise<Paint> {
	return control.evaluate((element) => {
		const style = getComputedStyle(element);
		return {
			outlineStyle: style.outlineStyle,
			outlineWidth: parseFloat(style.outlineWidth),
			outlineColor: style.outlineColor,
			boxShadow: style.boxShadow,
			borders: [style.borderTopColor, style.borderRightColor, style.borderBottomColor, style.borderLeftColor, style.borderTopWidth, style.borderBottomWidth].join(' '),
		};
	});
}

/** Wait for the control's own transitions: a colour halfway through a 100ms fade is not the state it ends in. */
async function settleControl(control: Locator): Promise<void> {
	await control.evaluate(async (element) => {
		await Promise.all(element.getAnimations().map((animation) => animation.finished.catch(() => undefined)));
	});
	await control.page().waitForTimeout(250);
}

/** Make the next click a focus-only event: nothing is followed, submitted, copied or saved. */
async function holdBackActions(page: Page): Promise<void> {
	await page.evaluate(() => {
		document.addEventListener(
			'click',
			(event) => {
				event.preventDefault();
				event.stopImmediatePropagation();
			},
			true,
		);
	});
}

function alpha(color: string): number {
	const match = color.match(/rgba?\(([^)]+)\)/);
	if (!match) {
		return 0;
	}
	const parts = match[1].split(/[ ,/]+/).filter(Boolean);
	return parts.length > 3 ? parseFloat(parts[3]) : 1;
}

/** A ring a person can see: a solid outline of 2px or more in a visible colour, or a shadow that spreads 2px or more. */
function hasVisibleRing(paint: Paint): boolean {
	const outline = paint.outlineStyle !== 'none' && paint.outlineWidth >= 2 && alpha(paint.outlineColor) > 0;
	const spread = paint.boxShadow.match(/rgba?\([^)]+\)\s+0px\s+0px\s+(?:0px\s+)?(\d+(?:\.\d+)?)px/);
	const shadow = paint.boxShadow !== 'none' && spread !== null && parseFloat(spread[1]) >= 2;
	return outline || shadow;
}

function drawsNothing(paint: Paint): boolean {
	return (paint.outlineStyle === 'none' || paint.outlineWidth === 0) && paint.boxShadow === 'none';
}

test.describe('Pointer focus on Stonewright controls', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	for (const target of TARGETS) {
		const pointerTest = target.keyboardOnly ? test.skip : test;
		pointerTest(`${target.name}: no ring, outline, border or shadow after a mouse click`, async ({ page }) => {
			await gotoAdmin(page, target.page);
			await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
			await target.prepare?.(page);
			const control = target.find(page);
			await expect(control, `a ${target.name} to measure`).toBeVisible();
			if (target.inert) {
				await holdBackActions(page);
			}

			await control.scrollIntoViewIfNeeded();
			await control.hover();
			await settleControl(control);
			const hovered = await paintOf(control);

			await control.click();
			await settleControl(control);
			const state = await control.evaluate((element) => ({ focused: element.matches(':focus'), keyboardFocus: element.matches(':focus-visible') }));
			const clicked = await paintOf(control);

			expect(state, `${target.name} is focused by the mouse and not by the keyboard`).toEqual({ focused: true, keyboardFocus: false });
			expect(drawsNothing(clicked), `${target.name} after a click: outline ${clicked.outlineStyle} ${clicked.outlineWidth}px ${clicked.outlineColor}, shadow ${clicked.boxShadow}`).toBe(true);
			expect(clicked.borders, `${target.name}: a click changes no border (it is compared with the hovered control)`).toBe(hovered.borders);
		});

		test(`${target.name}: a ring of at least 2px when the keyboard reaches it`, async ({ page }) => {
			await gotoAdmin(page, target.page);
			await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
			await target.prepare?.(page);
			const control = target.find(page);
			await expect(control).toBeVisible();

			// One real Tab press puts the page in keyboard mode; focusing the control then counts as keyboard focus.
			await page.keyboard.press('Tab');
			await control.focus();
			await settleControl(control);
			const state = await control.evaluate((element) => ({ focused: element.matches(':focus'), keyboardFocus: element.matches(':focus-visible') }));
			const ring = await paintOf(control);

			expect(state, `${target.name} is focused by the keyboard`).toEqual({ focused: true, keyboardFocus: true });
			expect(hasVisibleRing(ring), `${target.name} with the keyboard: outline ${ring.outlineStyle} ${ring.outlineWidth}px ${ring.outlineColor}, shadow ${ring.boxShadow}`).toBe(true);
		});
	}

	test('a clicked view tab becomes the current one: it keeps its underline and draws no box', async ({ page }) => {
		await gotoAdmin(page, SETUP);
		const tab = page.locator('.sw-setup .sw-ui-tabs > a.sw-ui-tabs__tab', { hasText: 'Settings' });
		await expect(tab).toHaveAttribute('aria-selected', 'false');

		await tab.click();
		await expect(tab).toHaveAttribute('aria-selected', 'true');
		await settleControl(tab);

		const current = await tab.evaluate((element) => {
			const style = getComputedStyle(element);
			return { underline: style.borderBottomWidth, underlineStyle: style.borderBottomStyle, underlineAlpha: style.borderBottomColor, keyboardFocus: element.matches(':focus-visible') };
		});
		const paint = await paintOf(tab);
		expect(current.keyboardFocus).toBe(false);
		expect(drawsNothing(paint), `outline ${paint.outlineStyle} ${paint.outlineWidth}px, shadow ${paint.boxShadow}`).toBe(true);
		expect(current.underline).toBe('2px');
		expect(current.underlineStyle).toBe('solid');
		expect(alpha(current.underlineAlpha)).toBeGreaterThan(0);
	});

	test('the tabs of a page keep their underline on the current tab after a click on another one', async ({ page }) => {
		await gotoAdmin(page, SANDBOX);
		await holdBackActions(page);
		const current = page.locator('.sw-ui-hubnav__link[aria-current="page"]');
		const before = await current.evaluate((element) => getComputedStyle(element).borderBottomColor);
		await page.locator('.sw-ui-hubnav__link:not([aria-current])').first().click();
		await settleControl(current);

		expect(await current.evaluate((element) => getComputedStyle(element).borderBottomColor)).toBe(before);
		expect(alpha(before)).toBeGreaterThan(0);
	});

	test('the band keeps the tint of the current link after a click on another one, and the clicked one draws no box', async ({ page }) => {
		await gotoAdmin(page, SETUP);
		await holdBackActions(page);
		const current = page.locator('.sw-ui-band__link[aria-current="page"]');
		const other = page.locator('.sw-ui-band__link:not([aria-current])').first();
		const before = await current.evaluate((element) => getComputedStyle(element).backgroundColor);
		await other.click();
		await settleControl(other);
		await settleControl(current);

		expect(await current.evaluate((element) => getComputedStyle(element).backgroundColor)).toBe(before);
		expect(alpha(before)).toBeGreaterThan(0);
		const paint = await paintOf(other);
		expect(drawsNothing(paint), `outline ${paint.outlineStyle} ${paint.outlineWidth}px, shadow ${paint.boxShadow}`).toBe(true);
	});

	test('a band link reached by the keyboard has one 2px outline and no shadow ring on top of it', async ({ page }) => {
		await gotoAdmin(page, SETUP);
		const link = page.locator('.sw-ui-band__link:not([aria-current])').first();

		await page.keyboard.press('Tab');
		await link.focus();
		await settleControl(link);
		const ring = await paintOf(link);

		expect(ring.outlineStyle).toBe('solid');
		expect(ring.outlineWidth).toBe(2);
		expect(ring.boxShadow).toBe('none');
	});
});
