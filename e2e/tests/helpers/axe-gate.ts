import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';
import { budgetFor } from './ui-budget';

/** The success criteria axe is asked about: WCAG 2.0, 2.1 and 2.2 level A and AA. */
export const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * Wait until the page has stopped changing: the network is quiet and no finite animation has run for three checks
 * in a row. An element halfway through a fade has a colour that is not the one the user ends up reading, so a
 * contrast check made earlier reports a failure that is not there. Rows that a script renders after the page
 * loads (the Skills catalog) start their fade after `domcontentloaded`, so one look at the running animations
 * is not enough.
 */
export async function settle(page: Page): Promise<void> {
	await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => undefined);
	await page.evaluate(async () => {
		const pause = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
		let quiet = 0;
		for (let check = 0; check < 40 && quiet < 3; check += 1) {
			const running = document.getAnimations().filter((animation) => animation.effect?.getTiming().iterations !== Infinity && animation.playState === 'running');
			if (running.length > 0) {
				quiet = 0;
				await Promise.all(running.map((animation) => animation.finished.catch(() => undefined)));
			} else {
				quiet += 1;
			}
			await pause(100);
		}
	});
}

interface AxeFinding {
	id: string;
	impact?: string | null;
	nodes: Array<{ target: unknown }>;
}

export function describeFinding(finding: AxeFinding): string {
	const targets = finding.nodes
		.slice(0, 3)
		.map((node) => (Array.isArray(node.target) ? node.target.join(' ') : String(node.target)))
		.join(' | ');
	return `${finding.id} (${finding.impact ?? 'unknown'}) x${finding.nodes.length}: ${targets}`;
}

/**
 * The new component sheet and any page without a budget: no violation of any impact.
 */
export async function expectNoAxeViolations(page: Page, testInfo: TestInfo, name: string, scope?: string): Promise<void> {
	await settle(page);
	let builder = new AxeBuilder({ page }).withTags(WCAG_TAGS);
	if (scope) {
		builder = builder.include(scope);
	}
	const results = await builder.analyze();
	await testInfo.attach(`axe-${name}.json`, { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });

	expect(results.violations.map(describeFinding), `${name}: axe violations`).toEqual([]);
}

/**
 * An existing page: no serious or critical rule that is not already in the page's allowance. Rules that are in the
 * allowance but no longer reported are listed as an annotation, so the allowance is cleaned up when the page is.
 */
export async function expectNoNewAxeViolations(page: Page, slug: string, testInfo: TestInfo, scope = '.sw-shell'): Promise<void> {
	await settle(page);
	const results = await new AxeBuilder({ page }).include(scope).withTags(WCAG_TAGS).analyze();
	await testInfo.attach(`axe-${slug}.json`, { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });

	const serious = results.violations.filter((finding) => finding.impact === 'serious' || finding.impact === 'critical');
	const allowed = new Set(budgetFor(slug).axe);
	const unexpected = serious.filter((finding) => !allowed.has(finding.id));
	const paid = [...allowed].filter((id) => !serious.some((finding) => finding.id === id));
	if (paid.length > 0) {
		testInfo.annotations.push({ type: 'axe-allowance-unused', description: `${slug}: ${paid.join(', ')} no longer reported; remove from PAGE_BUDGETS` });
	}

	expect(unexpected.map(describeFinding), `${slug}: serious or critical axe violations outside the page allowance`).toEqual([]);
}
