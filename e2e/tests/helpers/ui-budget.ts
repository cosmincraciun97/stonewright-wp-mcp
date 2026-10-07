/**
 * What each existing page is still allowed to get wrong, so the UI gates can run on every page today and only
 * ever tighten.
 *
 * A page that adopts the shared UI layer drops its entry here (and an entry that no longer matches what the page
 * measures is reported, so it is not forgotten). A page that has no entry has no allowance: the new component
 * sheet and every page added to the list from now on must measure clean.
 *
 * The numbers are counts of offenders the contract's probe finds in `.sw-shell` (see ui-probe.ts) at 1440 px
 * (desktop) and 390 px (mobile), plus the axe rule ids a page may still report at serious or critical impact.
 */
export interface PageBudget {
	/** Visible text under 12px: [desktop, mobile]. */
	readonly smallText: readonly [number, number];
	/** Controls under 24 x 24 that are not links inside a sentence: [desktop, mobile]. */
	readonly smallTargets: readonly [number, number];
	/** Ids that may appear more than once, because WordPress prints them once per form. */
	readonly duplicateIds: readonly string[];
	/** axe rule ids the page may still report at serious or critical impact. */
	readonly axe: readonly string[];
}

/** The chrome every shell page prints: the page header and a hub tab bar. It measures clean, so it carries no allowance. */
const SHELL: Pick<PageBudget, 'smallText' | 'smallTargets'> = { smallText: [0, 0], smallTargets: [0, 0] };

function onTopOfShell(extra: { smallText?: readonly [number, number]; smallTargets?: readonly [number, number]; duplicateIds?: readonly string[]; axe?: readonly string[] }): PageBudget {
	const text = extra.smallText ?? [0, 0];
	const targets = extra.smallTargets ?? [0, 0];

	return {
		smallText: [SHELL.smallText[0] + text[0], SHELL.smallText[1] + text[1]],
		smallTargets: [SHELL.smallTargets[0] + targets[0], SHELL.smallTargets[1] + targets[1]],
		duplicateIds: extra.duplicateIds ?? [],
		axe: extra.axe ?? [],
	};
}

/** No allowance beyond the shell chrome. */
export const SHELL_ONLY: PageBudget = onTopOfShell({});

export const PAGE_BUDGETS: Readonly<Record<string, PageBudget>> = {
	stonewright: onTopOfShell({ smallText: [1, 0], smallTargets: [5, 4], duplicateIds: ['_wpnonce', 'submit'], axe: ['scrollable-region-focusable'] }),
	'stonewright-prompts': onTopOfShell({ smallTargets: [1, 1] }),
	'stonewright-custom-code-approval': SHELL_ONLY,
	'stonewright-sandbox': SHELL_ONLY,
	'stonewright-skills': SHELL_ONLY,
	'stonewright-memory': onTopOfShell({ smallTargets: [3, 0], duplicateIds: ['_wpnonce', 'submit', '_stonewright_nonce'] }),
	'stonewright-audit-log': onTopOfShell({ smallTargets: [2, 2], duplicateIds: ['_stonewright_nonce'] }),
	'stonewright-troubleshoot': onTopOfShell({ smallTargets: [1, 1] }),
	'stonewright-context': onTopOfShell({ smallTargets: [1, 1] }),
	'stonewright-design': SHELL_ONLY,
};

/** A page not listed above is held to the shell chrome only. */
export function budgetFor(slug: string): PageBudget {
	return PAGE_BUDGETS[slug] ?? SHELL_ONLY;
}

/**
 * Highest allowed top edge of the first h1, in page pixels (the WordPress admin bar included). The page header
 * is a single row, so the title starts within 120px at 1440 and 200px at 390.
 */
export const H1_TOP_MAX = { desktop: 120, mobile: 200 } as const;

/** Sticky and fixed chrome (admin bar, sticky headers and filter bars) may cover at most this share of the viewport height. */
export const STICKY_CHROME_MAX_SHARE = 0.25;
