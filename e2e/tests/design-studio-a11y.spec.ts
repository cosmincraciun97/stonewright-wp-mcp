import { expect, test, type Page } from '@playwright/test';
import { ACTIVE_SYNCED, STUDIO_URL, login, stubStudio } from './helpers/design-studio';

/**
 * Design Studio — keyboard operation, focus management, and motion.
 *
 * Everything here is a promise the page makes to somebody who is not using a
 * mouse: the tabs are a real tablist, the review drawer is a real dialog that
 * gives focus back, state changes are announced, and nothing important is
 * delegated to a native browser dialog.
 */

async function activeElement(page: Page): Promise<{ tag: string; text: string; inDrawer: boolean }> {
	return page.evaluate(() => {
		const el = document.activeElement as HTMLElement | null;
		return {
			tag: el?.tagName.toLowerCase() ?? '',
			text: (el?.textContent ?? '').trim(),
			inDrawer: !!el?.closest('[data-sw-ds-drawer]'),
		};
	});
}

test.describe('Design Studio accessibility', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Keyboard proof runs once.');
		await login(page);
		await stubStudio(page, ACTIVE_SYNCED);
	});

	test('the tabs are a tablist with roving focus and URL state', async ({ page }) => {
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		const tabs = page.locator('[role="tab"]');
		await expect(tabs).toHaveCount(4);
		await expect(page.locator('[role="tablist"]')).toBeVisible();
		await expect(tabs.nth(0)).toHaveAttribute('aria-selected', 'true');
		await expect(tabs.nth(0)).toHaveAttribute('tabindex', '0');
		await expect(tabs.nth(1)).toHaveAttribute('tabindex', '-1');
		await expect(tabs.nth(0)).toHaveAttribute('aria-controls', 'sw-ds-panel-overview');

		await tabs.nth(0).focus();
		await page.keyboard.press('ArrowRight');
		await expect(tabs.nth(1)).toBeFocused();
		await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
		await expect(page).toHaveURL(/view=editor/);
		await expect(page.locator('[data-sw-panel="editor"]')).toBeVisible();
		await expect(page.locator('[data-sw-panel="overview"]')).toBeHidden();

		await page.keyboard.press('End');
		await expect(tabs.nth(3)).toBeFocused();
		await expect(page).toHaveURL(/view=history/);

		await page.keyboard.press('Home');
		await expect(tabs.nth(0)).toBeFocused();
		await expect(page).toHaveURL(/view=overview/);

		await page.keyboard.press('ArrowLeft');
		await expect(tabs.nth(3)).toBeFocused();

		// The back button walks the same history the tabs pushed.
		await page.goBack();
		await expect(page).toHaveURL(/view=overview/);
		await expect(page.locator('[data-sw-panel="overview"]')).toBeVisible();
	});

	test('the focused tab shows a visible focus ring', async ({ page }) => {
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		// Reach the tab by keyboard so :focus-visible applies the way a keyboard
		// user would see it.
		await page.locator('[role="tab"]').nth(0).focus();
		await page.keyboard.press('ArrowRight');
		const outline = await page.locator('[role="tab"]').nth(1).evaluate((node) => {
			const style = window.getComputedStyle(node);
			return {
				width: style.outlineWidth,
				style: style.outlineStyle,
				color: style.outlineColor,
			};
		});

		expect(outline.style).not.toBe('none');
		expect(parseFloat(outline.width)).toBeGreaterThanOrEqual(2);
		expect(outline.color).not.toBe('rgba(0, 0, 0, 0)');
	});

	test('the review drawer is a modal dialog that traps and returns focus', async ({ page }) => {
		const dialogs: string[] = [];
		page.on('dialog', (dialog) => {
			dialogs.push(dialog.message());
			void dialog.dismiss();
		});

		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		const planButton = page.getByRole('button', { name: 'Plan Elementor sync' });
		await planButton.click();

		const drawer = page.locator('[data-sw-ds-drawer]');
		await expect(drawer).toBeVisible();
		await expect(drawer).toHaveAttribute('role', 'dialog');
		await expect(drawer).toHaveAttribute('aria-modal', 'true');
		await expect(drawer).toHaveAttribute('aria-labelledby', 'sw-ds-drawer-title');
		await expect(page.locator('#sw-ds-drawer-title')).toBeVisible();

		// Focus lands inside the drawer and cannot Tab out of it.
		await expect.poll(async () => (await activeElement(page)).inDrawer).toBe(true);
		for (let i = 0; i < 6; i += 1) {
			await page.keyboard.press('Tab');
			expect((await activeElement(page)).inDrawer, `Tab ${i + 1} escaped the drawer`).toBe(true);
		}
		await page.keyboard.press('Shift+Tab');
		expect((await activeElement(page)).inDrawer).toBe(true);

		await page.keyboard.press('Escape');
		await expect(drawer).toHaveCount(0);
		await expect(planButton).toBeFocused();

		expect(dialogs, 'the page must not use native browser dialogs').toEqual([]);
	});

	test('state changes are announced through a polite live region', async ({ page }) => {
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		const status = page.locator('[data-sw-ds-status]');
		await expect(status).toHaveAttribute('role', 'status');
		await expect(status).toHaveAttribute('aria-live', 'polite');
		await expect(status).toContainText('Loaded 1 design directions.');

		await page.getByRole('button', { name: 'Plan Elementor sync' }).click();
		await page.locator('[data-sw-ds-drawer]').getByRole('button', { name: 'Apply is blocked' }).click();
		await expect(status).toContainText('blocked');
		await expect(status).toHaveAttribute('data-tone', 'danger');
	});

	test('leaving the editor with unsaved work is reviewed, not silently dropped', async ({
		page,
	}) => {
		await page.goto(`${STUDIO_URL}&view=editor`, { waitUntil: 'domcontentloaded' });

		await page.locator('#sw-ds-field-summary').fill('An edit that has not been saved.');
		await page.getByRole('tab', { name: 'Overview' }).click();

		const drawer = page.locator('[data-sw-ds-drawer]');
		await expect(drawer.getByRole('heading')).toHaveText(
			'Leave the editor with unsaved changes?',
		);
		await drawer.getByRole('button', { name: 'Cancel' }).click();
		await expect(page.locator('[data-sw-panel="editor"]')).toBeVisible();

		await page.getByRole('tab', { name: 'Overview' }).click();
		await drawer.getByRole('button', { name: 'Leave the editor' }).click();
		await expect(page.locator('[data-sw-panel="overview"]')).toBeVisible();

		// The draft survives in the session, so the edit is recoverable.
		const stored = await page.evaluate(() =>
			Object.keys(window.sessionStorage).filter((key) =>
				key.startsWith('stonewright.design-studio.draft.'),
			),
		);
		expect(stored.length).toBeGreaterThan(0);
	});
});

test.describe('Design Studio reduced motion', () => {
	test('the drawer does not animate when motion is reduced', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'desktop-1440-light', 'Motion proof runs once.');
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await login(page);
		await stubStudio(page, ACTIVE_SYNCED);
		await page.goto(STUDIO_URL, { waitUntil: 'domcontentloaded' });

		await page.getByRole('button', { name: 'Plan Elementor sync' }).click();
		const drawer = page.locator('[data-sw-ds-drawer]');
		await expect(drawer).toBeVisible();

		const motion = await drawer.evaluate((node) => {
			const style = window.getComputedStyle(node);
			return { duration: style.transitionDuration, transform: style.transform };
		});

		expect(parseFloat(motion.duration)).toBeLessThanOrEqual(0.01);
		expect(['none', 'matrix(1, 0, 0, 1, 0, 0)']).toContain(motion.transform);
	});
});
