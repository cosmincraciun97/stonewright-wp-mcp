import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { PAGE_GATE_PROJECTS, STONEWRIGHT_PAGES, viewportKind } from './helpers/admin-pages';
import { expectNoAxeViolations, settle } from './helpers/axe-gate';
import { login } from './helpers/login';
import { H1_TOP_MAX, STICKY_CHROME_MAX_SHARE, budgetFor } from './helpers/ui-budget';
import { measureUi } from './helpers/ui-probe';

/**
 * The UI contract.
 *
 * Part one holds the shared UI layer (sw-ui.css and sw-ui.js) to every rule at every viewport on the component
 * sheet, a page rendered from the PHP helpers next to the legacy shell stylesheets. It needs no WordPress login:
 * the page and its assets are served from the repository through request interception.
 *
 * Part two holds each existing Stonewright page to the same measurements, with the allowance in
 * helpers/ui-budget.ts for what a page has not migrated yet.
 */

const repository = path.resolve(__dirname, '..', '..');
const SHEET_ORIGIN = 'https://ui-sheet.test';

const SHEET_FILES: Record<string, string> = {
	'component-sheet.html': 'plugin/tests/fixtures/admin-ui/component-sheet.html',
	'sw-ui.css': 'plugin/assets/admin/sw-ui.css',
	'sw-ui.js': 'plugin/assets/admin/sw-ui.js',
	'shell.css': 'plugin/assets/admin/shell.css',
	'admin.css': 'plugin/assets/admin/admin.css',
	'stonewright-admin.css': 'plugin/assets/css/stonewright-admin.css',
};

const MIME: Record<string, string> = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };

async function openSheet(page: Page): Promise<void> {
	await page.route(`${SHEET_ORIGIN}/**`, async (route) => {
		const name = new URL(route.request().url()).pathname.replace(/^\//, '');
		const relative = SHEET_FILES[name];
		if (!relative) {
			await route.fulfill({ status: 404, contentType: 'text/plain', body: `not part of the sheet: ${name}` });
			return;
		}
		const file = path.join(repository, relative);
		await route.fulfill({ status: 200, contentType: MIME[path.extname(file)] ?? 'application/octet-stream', body: fs.readFileSync(file) });
	});
	await page.goto(`${SHEET_ORIGIN}/component-sheet.html`, { waitUntil: 'load' });
	await expect(page.locator('.sw-ui-page')).toBeVisible();
	await page.waitForFunction(() => Boolean((window as Window & { Stonewright?: { ui?: unknown } }).Stonewright?.ui));
}

const DESKTOP = 'desktop-1440-light';

test.describe('UI layer on the component sheet', () => {
	test.describe('every viewport', () => {
		test('loads the layer ahead of the legacy shell stylesheets and keeps both working', async ({ page }) => {
			await openSheet(page);

			const sheets = await page.evaluate(() => Array.from(document.styleSheets).map((sheet) => (sheet.href ?? '').split('/').pop()));
			expect(sheets.slice(0, 4)).toEqual(['sw-ui.css', 'shell.css', 'admin.css', 'stonewright-admin.css']);

			const tokens = await page.evaluate(() => {
				const root = getComputedStyle(document.querySelector('.sw-ui') as Element);
				return { accent: root.getPropertyValue('--sw-accent').trim(), motion: root.getPropertyValue('--sw-motion-scale').trim() };
			});
			expect(tokens.accent).not.toBe('');
			expect(tokens.motion).toBe('1');
		});

		test('measures clean: no overflow, no text under 12px, no target under 24px, every control named', async ({ page }) => {
			await openSheet(page);
			await settle(page);
			const measured = await measureUi(page, '.sw-ui-page');

			expect.soft(measured.horizontalOverflow, 'horizontal overflow').toBe(0);
			expect.soft(measured.smallText, 'text under 12px').toEqual([]);
			expect.soft(measured.smallTargets, 'targets under 24px').toEqual([]);
			expect.soft(measured.duplicateIds, 'duplicate ids').toEqual([]);
			expect.soft(measured.unnamedControls, 'controls without a name').toEqual([]);
			expect.soft(measured.unlabelledFields, 'fields without a label').toEqual([]);
			expect.soft(measured.drawerOwnContent, 'own content in the notice drawer').toEqual([]);
			expect.soft(measured.wrongPrimaryFills, 'primary buttons not painted with the accent fill').toEqual([]);
		});

		test('has no WCAG 2.2 AA violation', async ({ page }, testInfo) => {
			await openSheet(page);
			await expectNoAxeViolations(page, testInfo, `sheet-${testInfo.project.name}`);
		});

		test('has no WCAG 2.2 AA violation with each overlay open', async ({ page }, testInfo) => {
			await openSheet(page);
			for (const [opener, dialog] of [
				['#open-confirm', '#confirm-dialog'],
				['#open-typed', '#typed-dialog'],
				['#open-drawer', '#detail-drawer'],
			] as const) {
				await page.locator(opener).click();
				await expect(page.locator(dialog)).toBeVisible();
				await expectNoAxeViolations(page, testInfo, `sheet-${dialog.slice(1)}-${testInfo.project.name}`);
				await page.keyboard.press('Escape');
				await expect(page.locator(dialog)).toBeHidden();
			}
		});

		test('stacks tables below 783px and keeps the row actions reachable', async ({ page }) => {
			await openSheet(page);
			const width = page.viewportSize()?.width ?? 0;
			const stacked = await page.evaluate(() => {
				const table = document.querySelector('.sw-ui-table') as HTMLElement;
				const header = table.querySelector('thead') as HTMLElement;
				const cell = table.querySelector('td[data-label]') as HTMLElement;
				return {
					headerIsHidden: header.getBoundingClientRect().height <= 1,
					cellDisplay: getComputedStyle(cell).display,
					cellLabel: getComputedStyle(cell, '::before').content,
					scrolls: table.scrollWidth > table.clientWidth + 1,
				};
			});

			if (width <= 782) {
				expect(stacked.headerIsHidden).toBe(true);
				expect(stacked.cellDisplay).toBe('flex');
				expect(stacked.cellLabel).not.toBe('none');
				expect(stacked.scrolls).toBe(false);
			} else {
				expect(stacked.headerIsHidden).toBe(false);
				expect(stacked.cellDisplay).toBe('table-cell');
			}
			for (const action of await page.locator('.sw-ui-table__actions button').all()) {
				await expect(action).toBeVisible();
				await expect(action).toHaveAccessibleName(/^Disconnect Example (command-line|editor) client$/);
			}
		});

		test('sizes the controls for touch at phone width', async ({ page }) => {
			await openSheet(page);
			const width = page.viewportSize()?.width ?? 0;
			const smallest = await page.evaluate(() => {
				const heights = Array.from(document.querySelectorAll<HTMLElement>('.sw-ui-btn:not(.sw-ui-btn--xs), .sw-ui-input, .sw-ui-select, .sw-ui-hubnav__link, .sw-ui-tabs__tab'))
					.filter((el) => el.getClientRects().length > 0)
					.map((el) => el.getBoundingClientRect().height);
				return Math.min(...heights);
			});

			// 24px is the floor everywhere (measured above); at 782px and below the working size is 40px or more.
			expect(smallest).toBeGreaterThanOrEqual(width <= 782 ? 40 : 32);
		});
	});

	test.describe('desktop', () => {
		test.beforeEach(async ({ page, context }, testInfo) => {
			test.skip(testInfo.project.name !== DESKTOP, 'Behaviour does not depend on the viewport; it is checked once.');
			await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: SHEET_ORIGIN });
			await openSheet(page);
		});

		test('tabs move with the arrow, Home and End keys and show one panel', async ({ page }) => {
			const tabs = page.getByRole('tab');
			await expect(tabs).toHaveCount(3);
			await tabs.first().focus();

			await page.keyboard.press('ArrowRight');
			await expect(page.getByRole('tab', { name: 'Editor' })).toBeFocused();
			await expect(page.getByRole('tab', { name: 'Editor' })).toHaveAttribute('aria-selected', 'true');
			await expect(page.getByRole('tab', { name: 'Catalog' })).toHaveAttribute('tabindex', '-1');
			await expect(page.getByRole('tabpanel')).toHaveText('Editor panel');

			await page.keyboard.press('End');
			await expect(page.getByRole('tab', { name: 'Import' })).toBeFocused();
			await page.keyboard.press('ArrowRight');
			await expect(page.getByRole('tab', { name: 'Catalog' })).toBeFocused();
			await page.keyboard.press('ArrowLeft');
			await expect(page.getByRole('tab', { name: 'Import' })).toBeFocused();
			await page.keyboard.press('Home');
			await expect(page.getByRole('tab', { name: 'Catalog' })).toBeFocused();
			await expect(page.getByRole('tabpanel')).toHaveText('Catalog panel');
		});

		test('a dialog opens on its safe action, keeps Tab inside, closes on Escape and returns focus', async ({ page }) => {
			const opener = page.locator('#open-confirm');
			const dialog = page.getByRole('dialog', { name: 'Disconnect Example client?' });
			await opener.click();

			await expect(dialog).toBeVisible();
			await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
			await page.keyboard.press('Tab');
			await expect(dialog.getByRole('button', { name: 'Disconnect', exact: true })).toBeFocused();
			await page.keyboard.press('Tab');
			await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
			await page.keyboard.press('Shift+Tab');
			await expect(dialog.getByRole('button', { name: 'Disconnect', exact: true })).toBeFocused();

			await page.keyboard.press('Escape');
			await expect(dialog).toBeHidden();
			await expect(opener).toBeFocused();
		});

		test('a typed confirmation enables the action only for the exact phrase and is forgotten when the dialog closes', async ({ page }) => {
			const submit = page.locator('#typed-dialog [data-sw-ui-confirm-submit]');
			const phrase = page.locator('#typed-input');
			await page.locator('#open-typed').click();

			await expect(submit).toBeDisabled();
			await phrase.fill('delete');
			await expect(submit).toBeDisabled();
			await phrase.fill('DELETE');
			await expect(submit).toBeEnabled();

			await page.keyboard.press('Escape');
			await page.locator('#open-typed').click();
			await expect(phrase).toHaveValue('');
			await expect(submit).toBeDisabled();
			await page.keyboard.press('Tab');
			await expect(phrase).toBeFocused();
			await page.keyboard.press('Tab');
			await expect(page.locator('#typed-dialog').getByRole('button', { name: 'Cancel' })).toBeFocused();
		});

		test('a drawer opens at the edge and closes on a click outside it', async ({ page }) => {
			await page.locator('#open-drawer').click();
			const drawer = page.locator('#detail-drawer');
			await expect(drawer).toBeVisible();
			const box = await drawer.boundingBox();
			const viewport = page.viewportSize();
			expect(box?.x ?? 0).toBeGreaterThan((viewport?.width ?? 0) / 2);
			expect(box?.height ?? 0).toBeGreaterThanOrEqual((viewport?.height ?? 0) - 1);

			await page.mouse.click(40, 400);
			await expect(drawer).toBeHidden();
		});

		test('copy puts the value on the clipboard and says so', async ({ page }) => {
			const field = page.locator('.sw-ui-copy').first();
			await field.getByRole('button', { name: 'Copy MCP server URL' }).click();

			await expect(field.locator('.sw-ui-copy__status')).toHaveText('Copied');
			expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('https://example.test/wp-json/mcp/stonewright-oauth');
			await expect(field.locator('.sw-ui-copy__status')).toHaveText('', { timeout: 4000 });
		});

		test('a secret is masked until it is revealed, and the toggle says which it will do', async ({ page }) => {
			const secret = page.locator('.sw-ui-copy--secret input');
			const toggle = page.getByRole('button', { name: /^(Show|Hide) value$/ });
			await expect(secret).toHaveAttribute('type', 'password');
			await expect(toggle).toHaveAccessibleName('Show value');

			await toggle.click();
			await expect(secret).toHaveAttribute('type', 'text');
			await expect(toggle).toHaveAccessibleName('Hide value');
			await expect(toggle).toHaveAttribute('aria-pressed', 'true');

			await toggle.click();
			await expect(secret).toHaveAttribute('type', 'password');
		});

		test('a disclosure remembers whether it was left open', async ({ page }) => {
			const summary = page.locator('details[data-sw-ui-remember="sheet-web"] > summary');
			const details = page.locator('details[data-sw-ui-remember="sheet-web"]');
			await expect(details).not.toHaveAttribute('open', '');
			await summary.click();
			await expect(details).toHaveAttribute('open', '');
			// The toggle event writes the choice after the attribute changes; wait for it before leaving the page.
			await expect.poll(() => page.evaluate(() => window.localStorage.getItem('stonewright.ui.open.sheet-web'))).toBe('1');

			await page.reload();
			await expect(details).toHaveAttribute('open', '');
			await page.evaluate(() => window.localStorage.clear());
		});

		test('the slash key moves to search, and leaves typing alone', async ({ page }) => {
			await page.locator('.sw-ui-page-title').click();
			await page.keyboard.press('/');
			const search = page.locator('#f-search');
			await expect(search).toBeFocused();

			await page.keyboard.type('a/b');
			await expect(search).toHaveValue('a/b');
		});

		test('toasts stack at most three, announce themselves and go away by themselves', async ({ page }) => {
			await page.evaluate(() => {
				const ui = (window as unknown as { Stonewright: { ui: { toast: (text: string) => void; announce: (text: string) => void } } }).Stonewright.ui;
				ui.toast('Settings saved');
				ui.toast('Copied MCP server URL');
				ui.toast('Ability disabled');
				ui.toast('Fourth toast');
				ui.announce('Showing 12 of 40 abilities');
			});

			await expect(page.locator('.sw-ui-toast')).toHaveCount(3);
			await expect(page.locator('.sw-ui-toast').first()).toHaveText('Copied MCP server URL');
			await expect(page.locator('[data-sw-ui-live="polite"]')).toHaveText('Showing 12 of 40 abilities');
			await expect(page.locator('.sw-ui-toast')).toHaveCount(0, { timeout: 8000 });
		});

		test('a notice inserted by script is announced and is not removed by the page', async ({ page }) => {
			await page.evaluate(() => {
				const ui = (window as unknown as { Stonewright: { ui: { notify: (container: Element, options: object) => void } } }).Stonewright.ui;
				ui.notify(document.querySelector('.sw-ui-page') as Element, { variant: 'danger', title: 'Could not save', text: 'Check the connection and try again.' });
			});

			const notice = page.getByRole('alert').filter({ hasText: 'Could not save' });
			await expect(notice).toBeVisible();
			await page.waitForTimeout(6000);
			await expect(notice).toBeVisible();
		});

		test('every control shows a focus ring of at least 2px when reached with the keyboard', async ({ page }) => {
			const failures: string[] = [];
			await page.locator('body').click({ position: { x: 2, y: 400 } });
			for (let stop = 0; stop < 400; stop += 1) {
				await page.keyboard.press('Tab');
				const result = await page.evaluate(() => {
					const active = document.activeElement as HTMLElement | null;
					if (!active || active === document.body) {
						return { done: true, ok: true, name: '' };
					}
					// A switch or radio draws its ring on the visible part that follows or wraps the hidden input.
					let target: HTMLElement = active;
					if (active instanceof HTMLInputElement && getComputedStyle(active).opacity === '0') {
						target = (active.type === 'radio' ? active.closest('label') : active.nextElementSibling) as HTMLElement;
					}
					const style = getComputedStyle(target);
					const width = parseFloat(style.outlineWidth);
					const ok = style.outlineStyle !== 'none' && width >= 2;
					return { done: false, ok, name: `${active.tagName.toLowerCase()}.${active.className}`.slice(0, 80) };
				});
				if (result.done) {
					break;
				}
				if (!result.ok) {
					failures.push(result.name);
				}
			}

			expect(failures, 'controls without a visible focus ring').toEqual([]);
		});

		test('the accent holds its contrast in all nine colour schemes, current and earlier', async ({ page }) => {
			const failures = await page.evaluate(() => {
				const schemes = JSON.parse((document.getElementById('sheet-schemes') as HTMLElement).textContent ?? '{}') as Record<string, Record<string, string[]>>;
				const canvas = document.createElement('canvas');
				canvas.width = 1;
				canvas.height = 1;
				const context = canvas.getContext('2d', { willReadFrequently: true }) as CanvasRenderingContext2D;
				const parse = (value: string): number[] => {
					context.clearRect(0, 0, 1, 1);
					context.fillStyle = '#000';
					context.fillStyle = value;
					context.fillRect(0, 0, 1, 1);
					return Array.from(context.getImageData(0, 0, 1, 1).data.slice(0, 3));
				};
				const linear = (channel: number): number => {
					const value = channel / 255;
					return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
				};
				const luminance = (rgb: number[]): number => 0.2126 * linear(rgb[0]) + 0.7152 * linear(rgb[1]) + 0.0722 * linear(rgb[2]);
				const ratio = (a: number[], b: number[]): number => {
					const [x, y] = [luminance(a), luminance(b)];
					return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
				};
				const scope = document.querySelector('.sw-ui') as HTMLElement;
				const probe = document.createElement('i');
				scope.appendChild(probe);
				const token = (name: string): number[] => {
					probe.style.background = `var(${name})`;
					return parse(getComputedStyle(probe).backgroundColor);
				};

				const found: string[] = [];
				for (const palette of ['current', 'earlier']) {
					for (const [name, steps] of Object.entries(schemes[palette])) {
						document.body.className = `wp-admin wp-core-ui admin-color-${name}`;
						document.body.style.setProperty('--wp-admin-theme-color', steps[0]);
						document.body.style.setProperty('--wp-admin-theme-color-darker-10', steps[1]);
						document.body.style.setProperty('--wp-admin-theme-color-darker-20', steps[2]);

						const surface = token('--sw-surface');
						const checks: Array<[string, number, number]> = [
							['accent text on the surface', ratio(token('--sw-accent-text'), surface), 4.5],
							['accent text on the accent tint', ratio(token('--sw-accent-text'), token('--sw-accent-soft')), 4.5],
							['label on the accent fill', ratio(token('--sw-on-accent'), token('--sw-accent-fill')), 4.5],
							['label on the hovered fill', ratio(token('--sw-on-accent'), token('--sw-accent-fill-hover')), 4.5],
							['state indicator on the surface', ratio(token('--sw-accent-fill'), surface), 3],
							['focus ring on the surface', ratio(token('--sw-focus-ring'), surface), 3],
						];
						for (const [label, value, minimum] of checks) {
							if (value < minimum) {
								found.push(`${palette}/${name}: ${label} is ${value.toFixed(2)}:1, needs ${minimum}:1`);
							}
						}
					}
				}
				probe.remove();

				return found;
			});

			expect(failures).toEqual([]);
		});

		test('reduced motion removes every transition and animation', async ({ page }) => {
			await page.emulateMedia({ reducedMotion: 'reduce' });
			await page.reload();
			await expect(page.locator('.sw-ui-page')).toBeVisible();

			const result = await page.evaluate(() => {
				const toMilliseconds = (list: string): number[] => list.split(',').map((item) => (item.trim().endsWith('ms') ? parseFloat(item) : parseFloat(item) * 1000));
				let longest = 0;
				const offenders: string[] = [];
				document.querySelectorAll('.sw-ui, .sw-ui *').forEach((element) => {
					for (const pseudo of [null, '::before', '::after']) {
						const style = getComputedStyle(element, pseudo);
						const longestHere = Math.max(...toMilliseconds(style.transitionDuration), ...toMilliseconds(style.animationDuration), ...toMilliseconds(style.transitionDelay), ...toMilliseconds(style.animationDelay));
						longest = Math.max(longest, longestHere);
						if (longestHere > 0 && offenders.length < 10) {
							offenders.push(`${element.tagName.toLowerCase()}.${(element.getAttribute('class') ?? '').split(' ')[0]}${pseudo ?? ''}`);
						}
					}
				});
				const ui = (window as unknown as { Stonewright: { ui: { motionOK: () => boolean } } }).Stonewright.ui;

				return { longest, offenders, running: document.getAnimations().length, motionOk: ui.motionOK(), scale: getComputedStyle(document.querySelector('.sw-ui') as Element).getPropertyValue('--sw-motion-scale').trim() };
			});

			expect(result.offenders, 'elements that still move').toEqual([]);
			expect(result.longest).toBe(0);
			expect(result.running).toBe(0);
			expect(result.motionOk).toBe(false);
			expect(result.scale).toBe('0');
		});

		test('with motion on, nothing that is not a loop takes longer than 240ms', async ({ page }) => {
			const slow = await page.evaluate(() => {
				const toMilliseconds = (list: string): number[] => list.split(',').map((item) => (item.trim().endsWith('ms') ? parseFloat(item) : parseFloat(item) * 1000));
				const found: string[] = [];
				document.querySelectorAll('.sw-ui, .sw-ui *').forEach((element) => {
					for (const pseudo of [null, '::before', '::after']) {
						const style = getComputedStyle(element, pseudo);
						const transitions = Math.max(...toMilliseconds(style.transitionDuration));
						const animated = style.animationIterationCount.split(',').every((count) => count.trim() === 'infinite');
						const animations = animated ? 0 : Math.max(...toMilliseconds(style.animationDuration));
						if (Math.max(transitions, animations) > 240) {
							found.push(`${element.tagName.toLowerCase()}.${(element.getAttribute('class') ?? '').split(' ')[0]}${pseudo ?? ''} ${Math.max(transitions, animations)}ms`);
						}
					}
				});
				return found;
			});

			expect(slow).toEqual([]);
		});

		test('forced colours keep borders, icons and a highlighted selection', async ({ page }) => {
			await page.emulateMedia({ forcedColors: 'active' });
			await page.reload();
			await expect(page.locator('.sw-ui-page')).toBeVisible();

			const result = await page.evaluate(() => {
				const style = (selector: string, pseudo?: string): CSSStyleDeclaration => getComputedStyle(document.querySelector(selector) as Element, pseudo);
				const system = (name: string): string => {
					const probe = document.createElement('i');
					probe.style.background = name;
					document.body.appendChild(probe);
					const colour = getComputedStyle(probe).backgroundColor;
					probe.remove();
					return colour;
				};
				const hasBorder = (selector: string): boolean => parseFloat(style(selector).borderTopWidth) >= 1 && style(selector).borderTopStyle !== 'none';
				const highlight = system('Highlight');

				return {
					forced: matchMedia('(forced-colors: active)').matches,
					badgeBorder: hasBorder('.sw-ui-badge--ok') && hasBorder('.sw-ui-badge--danger'),
					badgeKeepsIcon: document.querySelectorAll('.sw-ui-badge svg').length > 0,
					buttonBorder: hasBorder('.sw-ui-btn'),
					inputBorder: hasBorder('.sw-ui-input'),
					cardBorder: hasBorder('.sw-ui-card'),
					noticeBorder: hasBorder('.sw-ui-notice--danger'),
					switchBorder: hasBorder('.sw-ui-switch__track'),
					primaryUsesHighlight: style('.sw-ui-btn--primary').backgroundColor === highlight,
					// With the browser's own adjustment left on, text on a Highlight fill sits on a Canvas backplate and vanishes.
					highlightPairsAreExplicit: ['.sw-ui-btn--primary', '.sw-ui-chip-filter[aria-pressed="true"]', '.sw-ui-toc a[aria-current="location"]'].every((selector) => style(selector).getPropertyValue('forced-color-adjust') === 'none'),
					pressedUsesHighlight: style('.sw-ui-chip-filter[aria-pressed="true"]').backgroundColor === highlight,
					unpressedDoesNot: style('.sw-ui-chip-filter[aria-pressed="false"]').backgroundColor !== highlight,
					currentTabMarked: style('.sw-ui-hubnav__link[aria-current="page"]').borderBottomColor !== style('.sw-ui-hubnav__link:not([aria-current])').borderBottomColor,
					checkedSwitchUsesHighlight: (() => {
						const input = document.querySelector('.sw-ui-switch input:checked') as HTMLElement;
						return getComputedStyle(input.nextElementSibling as Element).backgroundColor === highlight;
					})(),
				};
			});

			expect(result).toEqual({
				forced: true,
				badgeBorder: true,
				badgeKeepsIcon: true,
				buttonBorder: true,
				inputBorder: true,
				cardBorder: true,
				noticeBorder: true,
				switchBorder: true,
				primaryUsesHighlight: true,
				highlightPairsAreExplicit: true,
				pressedUsesHighlight: true,
				unpressedDoesNot: true,
				currentTabMarked: true,
				checkedSwitchUsesHighlight: true,
			});
		});

		test('no rule of the layer reaches markup outside .sw-ui', async ({ page }) => {
			// Markup of the older kind next to the scoped page. Every computed value of every element in it is read with
			// the layer's stylesheet on and again with it off; any difference is a rule that escaped its scope.
			const escaped = await page.evaluate(() => {
				const host = document.querySelector('.sw-shell__content') as HTMLElement;
				const legacy = document.createElement('div');
				legacy.id = 'legacy-markup';
				legacy.innerHTML =
					'<h2>Heading</h2><p>Text <a href="#">link</a> <code>code</code> <strong>bold</strong></p>' +
					'<a class="button button-primary" href="#">Save</a> <button class="button">Cancel</button> <button type="button">Plain</button>' +
					'<input type="text" value="x"><input type="checkbox"><select><option>a</option></select><textarea>t</textarea>' +
					'<table class="widefat"><caption>c</caption><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>' +
					'<ul><li>one</li></ul><details><summary>Open</summary>body</details><dl><dt>term</dt><dd>desc</dd></dl>' +
					'<span class="sw-badge">Badge</span><div class="sw-card"><h3 class="sw-card__title">Card</h3></div>' +
					'<div class="notice notice-info"><p>Notice</p></div><svg width="16" height="16"><use href="#none"></use></svg>';
				host.appendChild(legacy);

				const fingerprint = (): string[] =>
					Array.from(legacy.querySelectorAll('*')).map((element) => {
						const style = getComputedStyle(element);
						return `${element.tagName.toLowerCase()}.${(element.getAttribute('class') ?? '').split(' ')[0]} ` + Array.from(style).map((property) => `${property}:${style.getPropertyValue(property)}`).join(';');
					});

				const layer = Array.from(document.styleSheets).find((sheet) => (sheet.href ?? '').endsWith('/sw-ui.css')) as CSSStyleSheet;
				const withLayer = fingerprint();
				layer.disabled = true;
				const withoutLayer = fingerprint();
				layer.disabled = false;

				return withLayer.map((value, index) => (value === withoutLayer[index] ? '' : value.split(' ')[0])).filter((name) => name !== '');
			});

			expect(escaped, 'legacy elements whose computed style depends on sw-ui.css').toEqual([]);
		});
	});
});

test.describe('UI contract on Stonewright pages', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(!(PAGE_GATE_PROJECTS as readonly string[]).includes(testInfo.project.name), 'The page contract runs at one desktop and one phone width.');
		await login(page);
	});

	for (const { slug, label } of STONEWRIGHT_PAGES) {
		test(`${label} (${slug}) meets the contract`, async ({ page }, testInfo) => {
			await page.goto(`/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
			await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
			await settle(page);

			const kind = viewportKind(testInfo.project.name);
			const index = kind === 'desktop' ? 0 : 1;
			const budget = budgetFor(slug);
			const viewport = page.viewportSize();
			const measured = await measureUi(page, '.sw-shell');

			expect.soft(measured.horizontalOverflow, `${label}: horizontal overflow`).toBe(0);
			expect.soft(measured.drawerOwnContent, `${label}: own content in the notice drawer`).toEqual([]);
			expect.soft(measured.wrongPrimaryFills, `${label}: primary buttons not painted with the accent fill`).toEqual([]);
			expect.soft(measured.unnamedControls, `${label}: controls without a name`).toEqual([]);
			expect.soft(measured.unlabelledFields, `${label}: fields without a label`).toEqual([]);
			expect.soft(
				measured.duplicateIds.filter((id) => !budget.duplicateIds.includes(id)),
				`${label}: duplicate ids outside the allowance`,
			).toEqual([]);
			expect.soft(measured.smallText.length, `${label}: text under 12px (allowance ${budget.smallText[index]}): ${measured.smallText.join(', ')}`).toBeLessThanOrEqual(budget.smallText[index]);
			expect.soft(measured.smallTargets.length, `${label}: targets under 24px (allowance ${budget.smallTargets[index]}): ${measured.smallTargets.join(', ')}`).toBeLessThanOrEqual(budget.smallTargets[index]);

			if (measured.h1Top !== null) {
				expect.soft(measured.h1Top, `${label}: the h1 starts at ${measured.h1Top}px`).toBeLessThanOrEqual(H1_TOP_MAX[kind]);
			}
			expect.soft(measured.stickyChromeBottom, `${label}: sticky chrome reaches ${measured.stickyChromeBottom}px`).toBeLessThanOrEqual((viewport?.height ?? 0) * STICKY_CHROME_MAX_SHARE);

			// An allowance that is no longer used is reported so it can be removed.
			const unused: string[] = [];
			if (measured.smallText.length < budget.smallText[index]) {
				unused.push(`smallText ${measured.smallText.length}/${budget.smallText[index]}`);
			}
			if (measured.smallTargets.length < budget.smallTargets[index]) {
				unused.push(`smallTargets ${measured.smallTargets.length}/${budget.smallTargets[index]}`);
			}
			if (unused.length > 0) {
				testInfo.annotations.push({ type: 'allowance-unused', description: `${slug} at ${kind}: ${unused.join(', ')}` });
			}
		});
	}
});
