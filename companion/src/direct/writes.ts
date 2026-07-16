import type { ResolvedSite } from './sites-config.js';

export type DirectWriteMode = 'on' | 'off' | 'confirm';

/** Session flag: Direct writes require a prior stonewright-task-start (default ON). */
let taskStartSeen = false;

export function markTaskStartSeen(): void {
	taskStartSeen = true;
}

export function resetTaskStartSeenForTests(): void {
	taskStartSeen = false;
}

export function hasTaskStartSeen(): boolean {
	return taskStartSeen;
}

export function resolveDirectWriteMode(env: NodeJS.ProcessEnv = process.env, siteUrl?: string): DirectWriteMode {
	const raw = (env['STONEWRIGHT_DIRECT_WRITES'] ?? '').trim().toLowerCase();
	if (raw === 'on' || raw === 'off' || raw === 'confirm') {
		return raw;
	}

	if (siteUrl) {
		try {
			const host = new URL(siteUrl).hostname;
			if (
				host === 'localhost' ||
				host === '127.0.0.1' ||
				host.endsWith('.local') ||
				host.endsWith('.test')
			) {
				return 'on';
			}
		} catch {
			// fall through
		}
	}

	return 'confirm';
}

export function assertWriteAllowed(args: {
	mode: DirectWriteMode;
	destructive: boolean;
	confirm?: boolean | undefined;
	tool: string;
	env?: NodeJS.ProcessEnv;
}): void {
	const env = args.env ?? process.env;
	const requireTaskStart = (env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'] ?? 'on').trim().toLowerCase() !== 'off';
	if (requireTaskStart && !taskStartSeen) {
		throw new Error(
			'Call stonewright-task-start before write tools (it loads this site\'s skills, memory, and recurring errors). Then retry this call.',
		);
	}
	if (args.mode === 'off') {
		throw new Error(`Direct writes are disabled (STONEWRIGHT_DIRECT_WRITES=off). Tool: ${args.tool}`);
	}
	if (args.mode === 'confirm' && args.destructive && args.confirm !== true) {
		throw new Error(
			`Destructive Direct tool "${args.tool}" requires confirm:true when STONEWRIGHT_DIRECT_WRITES=confirm (or remote sites).`,
		);
	}
}

export function assertToolEnabled(site: ResolvedSite, tool: string): void {
	if (site.disabledTools.includes(tool)) {
		throw new Error(`Tool "${tool}" is disabled for site "${site.alias}" via sites.json disabledTools.`);
	}
}
