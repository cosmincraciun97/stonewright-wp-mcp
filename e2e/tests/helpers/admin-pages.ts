/**
 * Stonewright admin pages that render inside the shared shell (`.sw-shell`). The page loop of admin-ui.spec.ts,
 * its axe gate and the UI contract of ui-contract.spec.ts all visit this list, so a page added here is covered by
 * every gate at once.
 */
export const STONEWRIGHT_PAGES = [
	{ slug: 'stonewright-status', label: 'Dashboard' },
	{ slug: 'stonewright', label: 'Setup' },
	{ slug: 'stonewright-abilities', label: 'AI Abilities' },
	{ slug: 'stonewright-prompts', label: 'Prompts' },
	{ slug: 'stonewright-custom-code-approval', label: 'Code Approval' },
	{ slug: 'stonewright-sandbox', label: 'Sandbox' },
	{ slug: 'stonewright-skills', label: 'Skills' },
	{ slug: 'stonewright-memory', label: 'Memory' },
	{ slug: 'stonewright-audit-log', label: 'Audit Log' },
	{ slug: 'stonewright-troubleshoot', label: 'Troubleshoot' },
	{ slug: 'stonewright-context', label: 'Context' },
	{ slug: 'stonewright-design', label: 'Design' },
] as const;

/** Projects (viewports) the per-page gates run in: one desktop and one phone width. */
export const PAGE_GATE_PROJECTS = ['desktop-1440-light', 'mobile-390-light'] as const;

/** "desktop" or "mobile", the key the budgets use. */
export function viewportKind(projectName: string): 'desktop' | 'mobile' {
	return projectName.startsWith('mobile') ? 'mobile' : 'desktop';
}
