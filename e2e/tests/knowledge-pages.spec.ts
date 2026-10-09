import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers/login';
import { expectNoAxeViolations, settle } from './helpers/axe-gate';
import { PAGE_GATE_PROJECTS } from './helpers/admin-pages';
import { runAbility, runAbilityWithProfileConfirmation, wpRestNonce } from './helpers/wp-rest';

/**
 * The Knowledge hub (Skills, Memory, Context, Design, Prompt library) on the shared UI layer.
 *
 * Behaviour tests run once at 1440px; the accessibility and motion gates run at the page-gate widths. Test data is
 * synthetic and every key carries the run's timestamp, so the file can run twice against the same site.
 */

const RUN = String(Date.now());
const url = (slug: string, query = '') => `/wp-admin/admin.php?page=${slug}${query}`;

async function open(page: Page, slug: string, query = ''): Promise<void> {
	await page.goto(url(slug, query), { waitUntil: 'domcontentloaded' });
	await page.locator('.sw-shell').waitFor({ state: 'visible', timeout: 15_000 });
}

/**
 * A fresh site holds no memory entry, and the behaviour test above deletes the one it makes, so the edit view has nothing
 * to open until an entry exists. This creates one through the add form when the list is empty.
 */
async function ensureMemoryEntry(page: Page): Promise<void> {
	await open(page, 'stonewright-memory', '&type=all');
	if ((await page.getByRole('link', { name: /^Edit / }).count()) > 0) {
		return;
	}
	await open(page, 'stonewright-memory', '&add=1');
	const addForm = page.locator('#sw-memory-add');
	await addForm.getByLabel('Name').fill('Edit view entry');
	await addForm.getByLabel('Key').fill(`qa-edit-view-${RUN}`);
	await addForm.getByLabel(/^Value/).fill('A value to open in the edit view');
	await addForm.getByRole('button', { name: 'Create entry' }).click();
	await expect(page.getByRole('status').filter({ hasText: 'Memory entry created.' })).toBeVisible();
}

/**
 * The skills that ship with Stonewright cannot be moved to the trash, so a fresh site has no skill whose Trash button is
 * enabled. This saves one site skill (an upsert by slug, so a second run replaces it) through the abilities route.
 */
async function ensureSiteSkill(page: Page): Promise<void> {
	await open(page, 'stonewright-skills');
	const nonce = await wpRestNonce(page);
	const started = await runAbility(page, nonce, 'stonewright/task-start', {
		task: 'Save one site skill for the review drawer',
		surface: 'runtime',
		intent: 'create a synthetic skill',
		responseMode: 'compact',
	});
	expect(started.ok, JSON.stringify(started.body)).toBeTruthy();
	const body = started.body as { result?: { context_token?: string }; context_token?: string };
	const token = String(body.result?.context_token ?? body.context_token ?? '');
	expect(token).toMatch(/^swctx_/);

	const saved = await runAbilityWithProfileConfirmation(page, nonce, token, 'stonewright/skills-save', {
		slug: 'qa-review-drawer',
		title: 'Review drawer check',
		description: 'Use when a test needs a site skill that can be moved to the trash.',
		content: '# Review drawer check\n\nA synthetic playbook.\n',
		stonewright_context_token: token,
	});
	expect(saved.ok, JSON.stringify(saved.body)).toBeTruthy();
}

const DESIGN_DRAFT = (name: string) =>
	'---\n' +
	JSON.stringify({
		schema_version: '1.0',
		identity: { name, summary: 'A direction that is not ready yet.' },
		tokens: { colors: { brand: '#1a2b3c' } },
		dials: { variance: 30, density: 60, motion: 20 },
		guidance: { do: ['Keep surfaces quiet.'], avoid: ['Decorative gradients.'] },
		readiness: { ready: false, sync_ready: false, issues: ['No brand colour has been confirmed.'] },
	}) +
	'\n---\n\nQuiet surfaces.\n';

test.describe('Knowledge pages: behaviour', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Behaviour proof runs once.');
		await login(page);
	});

	test('Memory: every action ends in a message, and a key that is in use is refused', async ({ page }) => {
		const key = `qa-key-${RUN}`;
		// Page timers run on a clock the test can wind forward, so "never goes away by itself" needs no real wait.
		await page.clock.install();
		await open(page, 'stonewright-memory', '&add=1');

		const addForm = page.locator('#sw-memory-add');
		await expect(addForm).toHaveAttribute('open', '');
		await addForm.getByLabel('Name').fill('QA entry');
		await addForm.getByLabel('Key').fill(key);
		await addForm.getByLabel(/^Value/).fill('First value');
		await addForm.getByRole('button', { name: 'Create entry' }).click();
		await expect(page.getByRole('status').filter({ hasText: 'Memory entry created.' })).toBeVisible();

		// The same scope and key again: refused, nothing replaced, and the page says which entry holds it.
		await open(page, 'stonewright-memory', '&add=1');
		await addForm.getByLabel('Name').fill('Another name');
		await addForm.getByLabel('Key').fill(key);
		await addForm.getByLabel(/^Value/).fill('Replacement value');
		await addForm.getByRole('button', { name: 'Create entry' }).click();
		const refusal = page.getByRole('alert').filter({ hasText: 'that scope and key are already in use' });
		await expect(refusal).toBeVisible();
		await expect(refusal).toContainText('Nothing was changed.');
		await expect(refusal).toContainText('QA entry');

		// The original entry still holds its own name and value.
		await refusal.getByRole('link', { name: /Edit/ }).click();
		await expect(page.locator('#sw-memory-edit-name')).toHaveValue('QA entry');
		await expect(page.locator('#sw-memory-edit-value')).toHaveValue('First value');

		await page.locator('#sw-memory-edit-value').fill('Edited value');
		await page.getByRole('button', { name: 'Save changes' }).click();
		await expect(page.getByRole('status').filter({ hasText: 'Memory entry saved.' })).toBeVisible();

		// Delete needs a second step, and the message stays on the page.
		await page.getByRole('link', { name: /^Edit QA entry/ }).first().click();
		await page.getByText('Delete this entry').click();
		await page.getByRole('button', { name: /^Delete entry/ }).click();
		await expect(page.getByRole('status').filter({ hasText: 'Memory entry deleted.' })).toBeVisible();
		await page.clock.runFor(10_000);
		await expect(page.getByRole('status').filter({ hasText: 'Memory entry deleted.' }), 'A message never goes away by itself.').toBeVisible();
	});

	test('Memory: saving settings keeps the instructions, and says it saved', async ({ page }) => {
		await open(page, 'stonewright-memory');
		const instructions = page.getByRole('textbox', { name: 'Custom instructions' });
		await instructions.fill(`Prefer native widgets ${RUN}.`);
		await page.getByRole('button', { name: 'Save settings' }).click();
		await expect(page.getByRole('status').filter({ hasText: 'Settings saved.' })).toBeVisible();
		await expect(page.getByRole('textbox', { name: 'Custom instructions' })).toHaveValue(`Prefer native widgets ${RUN}.`);

		// Switching the memory abilities off and on again must not clear the instructions.
		const switchEl = page.getByRole('switch', { name: 'Enable memory abilities' });
		await switchEl.uncheck();
		await page.getByRole('button', { name: 'Save settings' }).click();
		await expect(page.getByRole('status').filter({ hasText: 'Settings saved.' })).toBeVisible();
		await expect(page.getByRole('textbox', { name: 'Custom instructions' })).toHaveValue(`Prefer native widgets ${RUN}.`);
		await page.getByRole('switch', { name: 'Enable memory abilities' }).check();
		await page.getByRole('button', { name: 'Save settings' }).click();
		await expect(page.getByRole('switch', { name: 'Enable memory abilities' })).toBeChecked();
	});

	test('Context: saving says what reaches agents', async ({ page }) => {
		await open(page, 'stonewright-context');
		await page.getByLabel('Persisted user context').fill(`This bakery ships sourdough on Tuesdays ${RUN}.`);
		await page.getByRole('switch', { name: 'Include user context in task start' }).check();
		await page.getByRole('button', { name: 'Save user context' }).click();
		await expect(page.getByRole('status').filter({ hasText: 'User context saved.' })).toContainText('Agents receive it at task start.');
	});

	test('Design: an imported draft is visible, deactivating can be undone, and each message is its own', async ({ page }) => {
		const draft = `QA draft ${RUN}`;
		await open(page, 'stonewright-design');
		await page.getByRole('textbox', { name: 'DESIGN.md' }).fill(DESIGN_DRAFT(draft));
		await page.getByRole('button', { name: 'Import direction' }).click();

		const note = page.getByRole('alert').filter({ hasText: 'stored as a draft' });
		await expect(note).toBeVisible();
		await expect(page.getByRole('status').filter({ hasText: 'Design direction updated.' })).toHaveCount(0);
		const row = page.getByRole('row').filter({ hasText: draft });
		await expect(row).toContainText('Draft');
		await expect(row).toContainText('No brand colour has been confirmed.');
		await expect(row.getByRole('button')).toHaveCount(0);

		// Deactivate the active direction: the message says so, and the way back is on the page.
		const deactivate = page.getByRole('button', { name: /^Deactivate/ }).first();
		if (await deactivate.count()) {
			await deactivate.click();
			await expect(page.getByRole('status').filter({ hasText: 'Design direction deactivated.' })).toContainText('Activate it again');
			await expect(page.getByText('No active design direction.')).toBeVisible();
			await page.getByRole('button', { name: /^Activate/ }).first().click();
			await expect(page.getByRole('status').filter({ hasText: 'Design direction activated.' })).toBeVisible();
		}
	});

	test('Prompt library: the search filters the cards, says how many are left and copy confirms next to the button', async ({ page, context }) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => undefined);
		await open(page, 'stonewright-prompts');
		const cards = page.locator('[data-sw-prompt-card]');
		await expect(cards).toHaveCount(29);
		await expect(page.locator('[data-sw-ui-filter-count]')).toHaveText('Showing 29 of 29 prompts');

		await page.getByLabel('Search prompts').fill('figma');
		await expect(page.locator('[data-sw-prompt-card]:visible').first()).toBeVisible();
		const visible = await page.locator('[data-sw-prompt-card]:not([hidden])').count();
		expect(visible).toBeGreaterThan(0);
		expect(visible).toBeLessThan(29);
		await expect(page.locator('[data-sw-ui-filter-count]')).toHaveText(`Showing ${visible} of 29 prompts`);

		await page.getByLabel('Search prompts').fill('zzzz-no-such-prompt');
		await expect(page.getByText('No prompt matches')).toBeVisible();
		await page.getByLabel('Search prompts').fill('');
		await expect(cards.first()).toBeVisible();

		const copy = page.getByRole('button', { name: /^Copy prompt/ }).first();
		await copy.click();
		await expect(copy.locator('xpath=following-sibling::*[@role="status"]')).toHaveText('Copied');
	});
});

test.describe('Prompt library layout', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	test('each outcome is a full-width section; its cards sit in aligned rows of equal height with no holes, and every card has the same parts', async ({ page }) => {
		await open(page, 'stonewright-prompts');
		await expect(page.locator('[data-sw-prompt-card]')).toHaveCount(29);

		const problems = await page.evaluate(() => {
			const found: string[] = [];
			const near = (a: number, b: number, slack = 1) => Math.abs(a - b) <= slack;
			const box = (element: Element) => element.getBoundingClientRect();
			const list = document.querySelector('.sw-prompts__groups') as HTMLElement;
			const listBox = box(list);
			let previousBottom = -Infinity;

			for (const section of Array.from(list.querySelectorAll(':scope > section'))) {
				const name = section.getAttribute('data-sw-prompt-outcome') ?? '?';
				const sectionBox = box(section);
				if (!near(sectionBox.left, listBox.left) || !near(sectionBox.width, listBox.width)) {
					found.push(`${name}: the section is not as wide as the list`);
				}
				if (sectionBox.top < previousBottom - 1) {
					found.push(`${name}: the section starts above the end of the one before it (side by side)`);
				}
				previousBottom = sectionBox.bottom;

				const grid = section.querySelector('.sw-prompts__grid') as HTMLElement;
				const gridBox = box(grid);
				const gridStyle = getComputedStyle(grid);
				const rowGap = parseFloat(gridStyle.rowGap);
				const columnGap = parseFloat(gridStyle.columnGap);
				const cards = Array.from(grid.querySelectorAll(':scope > article'));
				const rows: Element[][] = [];
				for (const card of cards) {
					const top = box(card).top;
					const row = rows.find((existing) => near(box(existing[0]).top, top));
					if (row) {
						row.push(card);
					} else {
						rows.push([card]);
					}
				}

				let rowBottom: number | null = null;
				for (const row of rows) {
					const first = box(row[0]);
					// The first row starts at the top of the grid; each next row starts one gap under the one before.
					if (rowBottom === null ? !near(first.top, gridBox.top) : !near(first.top - rowBottom, rowGap)) {
						found.push(`${name}: a row starts ${rowBottom === null ? first.top - gridBox.top : first.top - rowBottom}px from what is above it (${rowBottom === null ? 0 : rowGap}px expected)`);
					}
					rowBottom = Math.max(...row.map((card) => box(card).bottom));
					if (!near(first.left, gridBox.left)) {
						found.push(`${name}: a row does not start at the left edge of the grid`);
					}

					const parts = (card: Element) => ({
						header: card.querySelector(':scope > .sw-ui-card__header'),
						body: card.querySelector(':scope > .sw-ui-card__body'),
						footer: card.querySelector(':scope > .sw-ui-card__footer'),
					});
					const reference = parts(row[0]);
					row.forEach((card, index) => {
						const own = parts(card);
						const title = card.querySelector('.sw-ui-card__title')?.textContent ?? '?';
						if (!own.header || !own.body || !own.footer || card.children.length !== 3) {
							found.push(`${title}: the card is not a header, a body and a footer`);
							return;
						}
						if (own.header.querySelector('[aria-label="Available modes"]') || !own.footer.querySelector('[aria-label="Available modes"]')) {
							found.push(`${title}: the modes are not in the footer`);
						}
						const cardBox = box(card);
						const footerBox = box(own.footer);
						if (!near(cardBox.height, first.height)) {
							found.push(`${title}: ${cardBox.height}px tall in a row of ${first.height}px`);
						}
						if (reference.body && reference.footer && (!near(box(own.body).top, box(reference.body).top) || !near(footerBox.top, box(reference.footer).top))) {
							found.push(`${title}: its body or footer is not on the line of the first card of the row`);
						}
						if (cardBox.bottom - footerBox.bottom > 2) {
							found.push(`${title}: the footer is not at the bottom of the card`);
						}
						if (index > 0 && !near(cardBox.left - box(row[index - 1]).right, columnGap)) {
							found.push(`${title}: ${cardBox.left - box(row[index - 1]).right}px between columns (${columnGap}px expected)`);
						}
					});
				}
			}
			return found;
		});

		expect(problems).toEqual([]);
	});
});

test.describe('Skills import: the file field', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	test('is a layer control inside the drop zone: the secondary button at its size, the file name beside it, still labelled, a keyboard ring and none after a click', async ({ page }) => {
		await open(page, 'stonewright-skills', '&view=import');
		const zone = page.locator('.sw-ui-dropzone');
		const input = zone.getByLabel('Skill file');
		await expect(input).toBeVisible();
		await expect(input).toHaveAttribute('type', 'file');
		await expect(input).toHaveClass(/sw-ui-file/);

		const look = await input.evaluate((element) => {
			const field = getComputedStyle(element);
			const button = getComputedStyle(element, '::file-selector-button');
			return {
				fieldBorder: field.borderTopWidth,
				fieldBackground: field.backgroundColor,
				fieldHeight: element.getBoundingClientRect().height,
				button: { height: button.minHeight, padding: `${button.paddingLeft} ${button.paddingRight}`, border: button.borderTopWidth, edge: button.borderTopColor, radius: button.borderTopLeftRadius, background: button.backgroundColor, color: button.color, size: button.fontSize },
				phone: window.matchMedia('(max-width: 782px)').matches,
			};
		});
		expect(look.fieldBorder, 'no box around the field: the drop zone is the box').toBe('0px');
		expect(look.fieldBackground).toBe('rgba(0, 0, 0, 0)');
		expect(look.button).toMatchObject({ height: look.phone ? '44px' : '40px', padding: '16px 16px', border: '1px', edge: 'rgb(128, 132, 138)', radius: '2px', background: 'rgb(255, 255, 255)', color: 'rgb(29, 35, 39)', size: '13px' });
		expect(look.fieldHeight).toBeGreaterThanOrEqual(look.phone ? 44 : 40);

		// A keyboard reaches it and sees a ring of at least 2px.
		await page.keyboard.press('Tab');
		await input.focus();
		const keyboard = await input.evaluate((element) => ({ visible: element.matches(':focus-visible'), width: parseFloat(getComputedStyle(element).outlineWidth), style: getComputedStyle(element).outlineStyle }));
		expect(keyboard.visible).toBe(true);
		expect(keyboard.style).not.toBe('none');
		expect(keyboard.width).toBeGreaterThanOrEqual(2);

		// A mouse click leaves no ring (the click is held back so the file dialog does not open).
		await page.evaluate(() => document.addEventListener('click', (event) => event.preventDefault(), true));
		await zone.locator('p').first().click();
		await input.click();
		const pointer = await input.evaluate((element) => ({ keyboard: element.matches(':focus-visible'), style: getComputedStyle(element).outlineStyle, shadow: getComputedStyle(element).boxShadow }));
		expect(pointer.keyboard).toBe(false);
		expect(pointer.style).toBe('none');
		expect(pointer.shadow).toBe('none');
	});
});

test.describe('Knowledge pages: accessibility and motion', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(!(PAGE_GATE_PROJECTS as readonly string[]).includes(testInfo.project.name), 'The accessibility gate runs at one desktop and one phone width.');
		await login(page);
	});

	const views: Array<{ name: string; slug: string; query?: string; before?: (page: Page) => Promise<void> }> = [
		{ name: 'Skills catalog', slug: 'stonewright-skills' },
		{ name: 'Skills editor', slug: 'stonewright-skills', query: '&view=editor' },
		{ name: 'Memory', slug: 'stonewright-memory' },
		{ name: 'Memory with the add form open', slug: 'stonewright-memory', query: '&add=1' },
		{ name: 'Memory edit view', slug: 'stonewright-memory', query: '&type=all', before: async (page) => {
			await ensureMemoryEntry(page);
			await open(page, 'stonewright-memory', '&type=all');
			await page.getByRole('link', { name: /^Edit / }).first().click();
			await page.locator('#sw-memory-edit').waitFor();
		} },
		{ name: 'Memory refusal', slug: 'stonewright-memory', query: '&memory_notice=exists' },
		{ name: 'Context', slug: 'stonewright-context', query: '&stonewright_context_notice=saved' },
		{ name: 'Design', slug: 'stonewright-design', query: '&stonewright_design_notice=imported-draft' },
		{ name: 'Prompt library', slug: 'stonewright-prompts' },
	];

	for (const view of views) {
		test(`${view.name}: no axe violation of any impact`, async ({ page }, testInfo) => {
			await open(page, view.slug, view.query ?? '');
			await settle(page);
			if (view.before) {
				await view.before(page);
			}
			await expectNoAxeViolations(page, testInfo, `${view.slug}-${view.name}`, '.sw-shell');
		});
	}

	for (const slug of ['stonewright-skills', 'stonewright-memory', 'stonewright-context', 'stonewright-design', 'stonewright-prompts']) {
		test(`${slug}: under reduced motion nothing animates and no duration is above zero`, async ({ page }) => {
			await page.emulateMedia({ reducedMotion: 'reduce' });
			await open(page, slug);
			await settle(page);

			const result = await page.evaluate(() => {
				const running = document.getAnimations().filter((animation) => animation.playState === 'running').length;
				const timed = Array.from(document.querySelectorAll('.sw-ui *')).filter((node) => {
					const style = window.getComputedStyle(node);
					return style.transitionDuration.split(',').some((d) => parseFloat(d) > 0) || style.animationDuration.split(',').some((d) => parseFloat(d) > 0);
				}).length;
				return { running, timed };
			});
			expect(result.running).toBe(0);
			expect(result.timed).toBe(0);
		});
	}

	test('Skills: the review drawer is a dialog that opens on its safe action, keeps focus and returns it', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Keyboard proof runs once.');
		await ensureSiteSkill(page);
		await open(page, 'stonewright-skills');
		const trash = page.getByRole('button', { name: /^Trash/ }).and(page.locator(':enabled')).first();
		await trash.waitFor();
		await trash.click();
		const drawer = page.locator('dialog[data-sw-skills-drawer]');
		await expect(drawer).toBeVisible();
		await expect(drawer.getByRole('button', { name: 'Cancel' })).toBeFocused();
		await expectNoAxeViolations(page, testInfo, 'skills-drawer', 'dialog[data-sw-skills-drawer]');
		await page.keyboard.press('Escape');
		await expect(drawer).toHaveCount(0);
		await expect(trash).toBeFocused();
	});
});
