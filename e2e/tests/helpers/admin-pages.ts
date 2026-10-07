/**
 * Stonewright admin pages that render inside the shared shell (`.sw-shell`). The page loop of admin-ui.spec.ts,
 * its axe gate and the UI contract of ui-contract.spec.ts all visit this list, so a page added here is covered by
 * every gate at once.
 */
export const STONEWRIGHT_PAGES = [
	{ slug: 'stonewright-status', label: 'Overview', title: 'Overview', hub: 'overview', tab: 'Overview' },
	{ slug: 'stonewright', label: 'Setup', title: 'Setup', hub: 'setup', tab: 'Setup' },
	{ slug: 'stonewright-troubleshoot', label: 'Troubleshoot', title: 'Troubleshoot', hub: 'setup', tab: 'Troubleshoot' },
	{ slug: 'stonewright-abilities', label: 'AI Abilities', title: 'AI Abilities', hub: 'abilities', tab: 'AI Abilities' },
	{ slug: 'stonewright-skills', label: 'Skills', title: 'Skills', hub: 'knowledge', tab: 'Skills' },
	{ slug: 'stonewright-memory', label: 'Memory', title: 'Memory & instructions', hub: 'knowledge', tab: 'Memory' },
	{ slug: 'stonewright-context', label: 'Context', title: 'Context', hub: 'knowledge', tab: 'Context' },
	{ slug: 'stonewright-design', label: 'Design', title: 'Design', hub: 'knowledge', tab: 'Design' },
	{ slug: 'stonewright-prompts', label: 'Prompt library', title: 'Prompt library', hub: 'knowledge', tab: 'Prompt library' },
	{ slug: 'stonewright-sandbox', label: 'Custom code', title: 'Custom code', hub: 'custom-code', tab: 'Drafts' },
	{ slug: 'stonewright-custom-code-approval', label: 'Custom code approval', title: 'Custom code approval', hub: 'custom-code', tab: 'Approvals' },
	{ slug: 'stonewright-audit-log', label: 'Audit log', title: 'Audit log', hub: 'activity', tab: 'Audit log' },
	{ slug: 'stonewright-block-finalizer', label: 'Block queue', title: 'Block queue', hub: 'activity', tab: 'Block queue' },
	{ slug: 'stonewright-rescue', label: 'Rescue', title: 'Rescue', hub: 'activity', tab: 'Rescue' },
] as const;

/**
 * The hubs of the Stonewright sidebar: the pages of one hub share a landing page and one tab bar. A hub with a
 * single page has no tab bar. The order is the order of the tabs.
 */
export const STONEWRIGHT_HUBS = [
	{ id: 'overview', label: 'Overview', tabs: ['Overview'] },
	{ id: 'setup', label: 'Setup', tabs: ['Setup', 'Troubleshoot'] },
	{ id: 'abilities', label: 'AI Abilities', tabs: ['AI Abilities'] },
	{ id: 'knowledge', label: 'Knowledge', tabs: ['Skills', 'Memory', 'Context', 'Design', 'Prompt library'] },
	{ id: 'custom-code', label: 'Custom code', tabs: ['Drafts', 'Library', 'Active', 'Crash recovery', 'Approvals'] },
	{ id: 'activity', label: 'Activity', tabs: ['Audit log', 'Block queue', 'Rescue'] },
] as const;

/** The sidebar entries under "Stonewright", in order: a hub's landing page carries the hub name. */
export const STONEWRIGHT_SIDEBAR = [
	'Overview',
	'Setup',
	'Troubleshoot Beta',
	'AI Abilities',
	'Knowledge',
	'Memory',
	'Context Beta',
	'Design Beta',
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
