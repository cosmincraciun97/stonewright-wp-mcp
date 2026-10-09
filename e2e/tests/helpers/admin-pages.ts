/**
 * Stonewright admin pages that render inside the shared shell (`.sw-shell`). The page loop of admin-ui.spec.ts,
 * its axe gate and the UI contract of ui-contract.spec.ts all visit this list, so a page added here is covered by
 * every gate at once. `link` is the text of the page's link in the band at the top of every page.
 */
export const STONEWRIGHT_PAGES = [
	{ slug: 'stonewright-status', label: 'Overview', title: 'Overview', hub: 'overview', link: 'Overview' },
	{ slug: 'stonewright', label: 'Setup', title: 'Setup', hub: 'setup', link: 'Setup' },
	{ slug: 'stonewright-troubleshoot', label: 'Troubleshoot', title: 'Troubleshoot', hub: 'setup', link: 'Troubleshoot' },
	{ slug: 'stonewright-abilities', label: 'AI Abilities', title: 'AI Abilities', hub: 'abilities', link: 'AI Abilities' },
	{ slug: 'stonewright-skills', label: 'Skills', title: 'Skills', hub: 'knowledge', link: 'Skills' },
	{ slug: 'stonewright-memory', label: 'Memory', title: 'Memory & instructions', hub: 'knowledge', link: 'Memory' },
	{ slug: 'stonewright-context', label: 'Context', title: 'Context', hub: 'knowledge', link: 'Context' },
	{ slug: 'stonewright-design', label: 'Design', title: 'Design', hub: 'knowledge', link: 'Design' },
	{ slug: 'stonewright-prompts', label: 'Prompt library', title: 'Prompt library', hub: 'knowledge', link: 'Prompt library' },
	{ slug: 'stonewright-sandbox', label: 'Custom code', title: 'Custom code', hub: 'custom-code', link: 'Custom code' },
	{ slug: 'stonewright-custom-code-approval', label: 'Custom code approval', title: 'Custom code approval', hub: 'custom-code', link: 'Code approval' },
	{ slug: 'stonewright-audit-log', label: 'Audit log', title: 'Audit log', hub: 'activity', link: 'Audit log' },
	{ slug: 'stonewright-block-finalizer', label: 'Block queue', title: 'Block queue', hub: 'activity', link: 'Block queue' },
	{ slug: 'stonewright-rescue', label: 'Rescue', title: 'Rescue', hub: 'activity', link: 'Rescue' },
] as const;

/**
 * The groups of the band, in order, and the text of each link. A group with two links or more shows its name in
 * capitals and a thin rule; a group with one link shows neither.
 */
export const STONEWRIGHT_BAND = [
	{ hub: 'Overview', links: ['Overview'] },
	{ hub: 'Setup', links: ['Setup', 'Troubleshoot'] },
	{ hub: 'AI Abilities', links: ['AI Abilities'] },
	{ hub: 'Knowledge', links: ['Skills', 'Memory', 'Context', 'Design', 'Prompt library'] },
	{ hub: 'Custom code', links: ['Custom code', 'Code approval'] },
	{ hub: 'Activity', links: ['Audit log', 'Block queue', 'Rescue'] },
] as const;

/** The pages that are still changing: their band link and their sidebar entry carry the EXP marker. */
export const STONEWRIGHT_EXP_LINKS = ['Troubleshoot', 'Context', 'Design', 'Block queue'] as const;

/** The words of the marker's tooltip and of its hidden text. */
export const EXP_HINT = 'This feature is experimental.';

/** The tab bars of the pages that have tabs of their own, by slug. Setup's four views are page tabs, not this bar. */
export const STONEWRIGHT_OWN_TABS: Readonly<Record<string, readonly string[]>> = {
	'stonewright-sandbox': ['Drafts', 'Library', 'Active', 'Crash recovery'],
};

/** The sidebar entries under "Stonewright", in order: a hub's landing page carries the hub name. */
export const STONEWRIGHT_SIDEBAR = [
	'Overview',
	'Setup',
	`Troubleshoot EXP ${EXP_HINT}`,
	'AI Abilities',
	'Knowledge',
	'Memory',
	`Context EXP ${EXP_HINT}`,
	`Design EXP ${EXP_HINT}`,
	'Prompt library',
	'Custom code',
	'Code approval',
	'Activity',
	'Rescue',
] as const;

/** Projects (viewports) the per-page gates run in: one desktop and one phone width. */
export const PAGE_GATE_PROJECTS = ['desktop-1440-light', 'mobile-390-light'] as const;

/** "desktop" or "mobile", the key the budgets use. */
export function viewportKind(projectName: string): 'desktop' | 'mobile' {
	return projectName.startsWith('mobile') ? 'mobile' : 'desktop';
}
