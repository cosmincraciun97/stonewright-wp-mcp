import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers/login';
import { expectNoAxeViolations, settle } from './helpers/axe-gate';

/**
 * The Custom code hub (Drafts, Library, Active, Crash recovery, Approvals), built from the shared UI layer.
 *
 * File tests create their own sandbox file and remove it again. The approval views that need a staged proposal
 * run only when SW_E2E_PROPOSAL_ID names one; the markup of those views is covered by unit tests.
 */

const DRAFTS = '/wp-admin/admin.php?page=stonewright-sandbox&tab=drafts';
const LIBRARY = '/wp-admin/admin.php?page=stonewright-sandbox&tab=library';
const ACTIVE = '/wp-admin/admin.php?page=stonewright-sandbox&tab=mu-plugins';
const CRASH = '/wp-admin/admin.php?page=stonewright-sandbox&tab=crash-recovery';
const APPROVALS = '/wp-admin/admin.php?page=stonewright-custom-code-approval';
const DESKTOP = 'desktop-1440-light';
const PHONE = 'mobile-390-light';

/**
 * Behaviour does not depend on the viewport, so it runs once, at desktop width. A test tagged @phone also runs at
 * phone width, and the page contract below runs at the two widths of the page gate.
 */
test.beforeEach(async ({ page }, testInfo) => {
	const project = testInfo.project.name;
	const runs = project === DESKTOP || (project === PHONE && testInfo.tags.includes('@phone'));
	test.skip(!runs, 'Behaviour runs at desktop width; tests tagged @phone also run at phone width.');
	await login(page);
});

async function removeFile(page: Page, name: string): Promise<void> {
	await page.goto(DRAFTS, { waitUntil: 'domcontentloaded' });
	const row = page.locator('tbody tr', { hasText: name });
	if ((await row.count()) === 0) {
		return;
	}
	await row.getByRole('button', { name: `Delete ${name}` }).click();
	await page.locator('dialog[open]').getByRole('button', { name: 'Delete file' }).click();
	await expect(page.locator('.sw-ui-notice--ok')).toBeVisible();
}

async function createFile(page: Page, name: string, contents: string): Promise<void> {
	await page.goto(DRAFTS, { waitUntil: 'domcontentloaded' });
	await page.getByRole('link', { name: 'New file' }).first().click();
	await expect(page.getByRole('heading', { name: 'New file' })).toBeVisible();
	await page.getByLabel('File name').fill(name);
	await page.getByLabel('Contents').fill(contents);
	await page.getByRole('button', { name: 'Create file' }).click();
	await expect(page.locator('.sw-ui-notice--ok')).toContainText('Action completed successfully.');
}

test.describe('Drafts', () => {
	test('a file goes from new to draft to active and back, each step said in words, and is deleted after a question', async ({ page }, testInfo) => {
		test.setTimeout(180_000);
		const name = `e2e-ui-${Date.now()}.php`;
		try {
			await createFile(page, name, "<?php\n// e2e draft\n");
			const row = page.locator('tbody tr', { hasText: name });
			await expect(row).toBeVisible();
			await expect(row).toContainText('Draft');

			await row.getByRole('button', { name: `Activate ${name}` }).click();
			await expect(page.locator('.sw-ui-notice--ok')).toBeVisible();
			await expect(row).toContainText('Active');
			await expect(row.getByRole('button', { name: `Deactivate ${name}` })).toBeVisible();
			await expect(row.getByRole('button', { name: `Disable ${name}` })).toBeVisible();

			await row.getByRole('button', { name: `Disable ${name}` }).click();
			await expect(row).toContainText('Disabled');
			await row.getByRole('button', { name: `Enable ${name}` }).click();
			await row.getByRole('button', { name: `Deactivate ${name}` }).click();
			await expect(row).toContainText('Draft');

			// Delete asks first; Cancel keeps the file and focus goes back to the button that opened the question.
			const opener = row.getByRole('button', { name: `Delete ${name}` });
			await opener.click();
			const dialog = page.locator('dialog[open]');
			await expect(dialog).toBeVisible();
			await expect(dialog).toContainText(`Delete ${name}?`);
			await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
			await page.keyboard.press('Escape');
			await expect(dialog).toBeHidden();
			await expect(opener).toBeFocused();
			await expect(row).toBeVisible();

			await opener.click();
			await page.locator('dialog[open]').getByRole('button', { name: 'Delete file' }).click();
			await expect(page.locator('.sw-ui-notice--ok')).toBeVisible();
			await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(0);
		} finally {
			await removeFile(page, name);
		}
	});

	test('the name rule stops a bad name in the field before anything is sent', async ({ page }, testInfo) => {
		await page.goto(`${DRAFTS}&new=1`, { waitUntil: 'domcontentloaded' });
		const field = page.getByLabel('File name');
		await expect(field).toBeFocused();
		await expect(field).toHaveAttribute('required', '');
		for (const bad of ['Upper.php', 'has space.php', 'name.txt', 'dir/name.php']) {
			await field.fill(bad);
			expect(await field.evaluate((node) => (node as HTMLInputElement).validity.patternMismatch), bad).toBe(true);
		}
		for (const good of ['my-snippet.php', 'under_score_1.php']) {
			await field.fill(good);
			expect(await field.evaluate((node) => (node as HTMLInputElement).validity.valid), good).toBe(true);
		}
		await expect(page.locator('.sw-ui-field__help')).toContainText('Lowercase letters, digits, hyphens and underscores, ending in .php.');
	});

	test('a file is edited and saved, the editor keeps its lines and says it saved', async ({ page }, testInfo) => {
		test.setTimeout(180_000);
		const name = `e2e-ui-edit-${Date.now()}.php`;
		try {
			await createFile(page, name, "<?php\n// first\n");
			await page.locator('tbody tr', { hasText: name }).getByRole('link', { name: `Edit ${name}` }).click();
			const editor = page.getByLabel('Contents');
			await expect(editor).toHaveValue("<?php\n// first\n");
			await expect(editor).toHaveCSS('white-space', 'pre');
			const family = await editor.evaluate((node) => getComputedStyle(node).fontFamily);
			expect(family.toLowerCase()).toMatch(/mono|consolas|courier/);
			await editor.fill("<?php\n// second\n");
			await page.getByRole('button', { name: 'Save changes' }).click();
			await expect(page.locator('.sw-ui-notice--ok')).toBeVisible();
			await expect(page.getByLabel('Contents')).toHaveValue("<?php\n// second\n");
		} finally {
			await removeFile(page, name);
		}
	});

	test('an unsafe file is refused on activation with its cause, in a notice that stays, and remains a draft', async ({ page }) => {
		test.setTimeout(180_000);
		const name = `e2e-ui-unsafe-${Date.now()}.php`;
		try {
			await createFile(page, name, "<?php\neval( 'x' );\n");
			await page.clock.install();
			const row = page.locator('tbody tr', { hasText: name });
			await row.getByRole('button', { name: `Activate ${name}` }).click();
			const notice = page.locator('.sw-ui-notice--danger');
			await expect(notice).toBeVisible();
			await expect(notice).toHaveAttribute('role', 'alert');
			await expect(notice).toContainText('Static guard blocked activation');
			// Let the time a toast lives pass, without waiting for it.
			await page.clock.fastForward(10_000);
			await expect(notice, 'a notice is never removed by a timer').toBeVisible();
			await expect(page.locator('tbody tr', { hasText: name })).toContainText('Draft');
		} finally {
			await removeFile(page, name);
		}
	});
});

test.describe('states', () => {
	test('Active and Crash recovery teach what they show and where to start when they are empty', async ({ page }, testInfo) => {
		await page.goto(ACTIVE, { waitUntil: 'domcontentloaded' });
		if ((await page.locator('table').count()) === 0) {
			await expect(page.getByRole('heading', { name: 'No active files' })).toBeVisible();
			await expect(page.getByRole('link', { name: 'Open drafts' })).toBeVisible();
		}
		await page.goto(CRASH, { waitUntil: 'domcontentloaded' });
		if ((await page.locator('table').count()) === 0) {
			await expect(page.getByRole('heading', { name: 'No crashes recorded' })).toBeVisible();
			await expect(page.locator('.sw-ui-empty__text')).toContainText('switches it off');
		}
	});

	test('the Library has one toolbar, no second row of tabs, and a way out of an empty filter', async ({ page }, testInfo) => {
		await page.goto(LIBRARY, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('.nav-tab-wrapper')).toHaveCount(0);
		await expect(page.locator('.sw-ui-toolbar')).toHaveCount(1);
		await expect(page.locator('input[name="library_tab"]')).toHaveCount(3);

		await page.goto(`${LIBRARY}&library_tab=widgets`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('input[name="library_tab"][value="widgets"]')).toBeChecked();
		await expect(page.getByRole('heading', { name: 'Installed Elementor widgets' })).toBeVisible();

		await page.goto(`${LIBRARY}&library_tab=plugins&status=active`, { waitUntil: 'domcontentloaded' });
		if ((await page.locator('table').count()) === 0) {
			await expect(page.getByRole('heading', { name: 'No files match' })).toBeVisible();
			await page.getByRole('link', { name: 'Clear filters' }).click();
			await expect(page).not.toHaveURL(/status=/);
		}
	});

	test('the filter form keeps the hub address and the kind in the address after Filter', async ({ page }, testInfo) => {
		await page.goto(LIBRARY, { waitUntil: 'domcontentloaded' });
		await page.locator('input[name="library_tab"][value="plugins"]').check();
		await page.locator('select[name="status"]').selectOption('pending');
		await page.getByRole('button', { name: 'Filter' }).click();
		await expect(page).toHaveURL(/page=stonewright-sandbox/);
		await expect(page).toHaveURL(/tab=library/);
		await expect(page).toHaveURL(/library_tab=plugins/);
		await expect(page).toHaveURL(/status=pending/);
		await expect(page.locator('input[name="library_tab"][value="plugins"]')).toBeChecked();
	});

	test('the old Library address still opens inside the shell with one heading', async ({ page }, testInfo) => {
		await page.goto('/wp-admin/admin.php?page=stonewright-sandbox-library', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('.sw-shell')).toBeVisible();
		await expect(page.locator('h1')).toHaveCount(1);
		await expect(page.locator('.sw-ui-toolbar')).toBeVisible();
	});

	test('Approvals with nothing to approve says what it is for and keeps the warning in the page', async ({ page }, testInfo) => {
		await page.goto(APPROVALS, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('.sw-ui-callout--warn')).toContainText('Human approval only.');
		await expect(page.locator('.sw-notice-drawer')).toBeHidden();
		await expect(page.getByRole('heading', { name: 'Nothing to approve' })).toBeVisible();
		await expect(page.getByRole('link', { name: 'Open Custom code' })).toHaveClass(/sw-ui-btn--primary/);
		await expect(page.locator('.sw-ui-btn--primary')).toHaveCount(1);
		await expect(page.getByRole('link', { name: /How approvals work/ })).toHaveAttribute('href', /docs/);
	});

	test('a proposal that does not exist is an error with a way back', async ({ page }, testInfo) => {
		await page.goto(`${APPROVALS}&proposal_id=does-not-exist`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('.sw-ui-notice--danger')).toHaveAttribute('role', 'alert');
		await expect(page.locator('.sw-ui-callout--warn')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Issue one-time grant' })).toHaveCount(0);
		await expect(page.getByRole('link', { name: 'Back to Custom code' })).toBeVisible();
	});

	test('a staged proposal shows the exact candidate with one primary action', async ({ page }, testInfo) => {
		const id = process.env.SW_E2E_PROPOSAL_ID;
		test.skip(!id, 'Needs SW_E2E_PROPOSAL_ID: a proposal staged on the site.');
		await page.goto(`${APPROVALS}&proposal_id=${id}`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('dl.sw-ui-kv dt')).toContainText(['Path', 'Language', 'Risk', 'Changed bytes', 'Candidate SHA-256', 'Native gap']);
		await expect(page.locator('pre.sw-ui-code__body')).toHaveAttribute('tabindex', '0');
		await expect(page.locator('.sw-ui-btn--primary')).toHaveCount(1);
		await expect(page.getByRole('button', { name: 'Issue one-time grant' })).toBeVisible();
	});
});

test.describe('at the widths of the page gate', () => {
	const pages: Array<[string, string]> = [
		['Drafts', DRAFTS],
		['Library', LIBRARY],
		['Library widgets', `${LIBRARY}&library_tab=widgets`],
		['Active', ACTIVE],
		['Crash recovery', CRASH],
		['Approvals', APPROVALS],
		['New file', `${DRAFTS}&new=1`],
	];

	for (const [label, url] of pages) {
		test(`${label}: no WCAG 2.2 AA violation of any impact and nothing scrolls sideways`, { tag: '@phone' }, async ({ page }, testInfo) => {
			test.setTimeout(120_000);
			await page.goto(url, { waitUntil: 'domcontentloaded' });
			await page.locator('.sw-code').waitFor();
			await expectNoAxeViolations(page, testInfo, `custom-code-${label.toLowerCase().replace(/\s+/g, '-')}`, '.sw-shell');
			const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
			expect(overflow).toBe(0);
		});
	}

	test('Drafts with a file and its delete dialog open: no violation of any impact', { tag: '@phone' }, async ({ page }, testInfo) => {
		test.setTimeout(120_000);
		const name = `e2e-ui-axe-${Date.now()}.php`;
		try {
			await createFile(page, name, "<?php\n// axe\n");
			await page.locator('tbody tr', { hasText: name }).getByRole('button', { name: `Delete ${name}` }).click();
			await expect(page.locator('dialog[open]')).toBeVisible();
			await expectNoAxeViolations(page, testInfo, 'custom-code-drafts-dialog', 'body');
			await page.keyboard.press('Escape');
			await expectNoAxeViolations(page, testInfo, 'custom-code-drafts-list', '.sw-shell');
		} finally {
			await removeFile(page, name);
		}
	});
});

test.describe('motion', () => {
	test('reduced motion removes every transition and animation on Drafts, the Library and Approvals', async ({ page }, testInfo) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		for (const url of [DRAFTS, LIBRARY, APPROVALS]) {
			await page.goto(url, { waitUntil: 'domcontentloaded' });
			await page.locator('.sw-code').waitFor();
			await settle(page);
			const result = await page.evaluate(() => {
				const toMilliseconds = (list: string): number[] => list.split(',').map((item) => (item.trim().endsWith('ms') ? parseFloat(item) : parseFloat(item) * 1000));
				const offenders: string[] = [];
				document.querySelectorAll('.sw-code, .sw-code *').forEach((element) => {
					for (const pseudo of [null, '::before', '::after']) {
						const style = getComputedStyle(element, pseudo);
						const longest = Math.max(...toMilliseconds(style.transitionDuration), ...toMilliseconds(style.animationDuration), ...toMilliseconds(style.transitionDelay), ...toMilliseconds(style.animationDelay));
						if (longest > 0 && offenders.length < 10) {
							offenders.push(`${element.tagName.toLowerCase()}.${(element.getAttribute('class') ?? '').split(' ')[0]}${pseudo ?? ''}`);
						}
					}
				});
				return { offenders, running: document.getAnimations().length };
			});
			expect(result.offenders, url).toEqual([]);
			expect(result.running, url).toBe(0);
		}
	});
});
