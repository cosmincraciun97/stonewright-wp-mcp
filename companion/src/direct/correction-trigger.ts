/**
 * Correction trigger for Direct writes.
 *
 * When a write to a resource fails, or its readback shows the stored resource differs from what was
 * sent, and a later write to the same resource on the same site succeeds, the successful answer carries a
 * `learning_offer`. The offer asks the agent to put the question to the user and, only on a yes, to
 * record the lesson with the existing `stonewright-learning-record` tool. Nothing is recorded here and no
 * store is added: the failures are held in this process only, so a restart forgets them.
 */

const FAILURE_TTL_MS = 12 * 60 * 60 * 1000;
const MAX_PENDING = 100;

const WRITE_TOOL = /-(create|create-page|create-post|update|delete|apply|install|activate|deactivate|manage|restore|mutate|revoke|upload)$|^stonewright-(blocks-batch-mutate|elementor-data-update)$/;

/** Codes that are refusals or mistakes in the call itself, not a write that failed. */
const IGNORED_CODES = new Set([
	'invalid_arguments',
	'confirmation_required',
	'task_start_required',
	'custom_code_approval_required',
	'direct_writes_disabled',
]);

type Failure = { tool: string; code: string; at: number; eventId?: string };

const pending = new Map<string, Failure>();

export function resetCorrectionTriggerForTests(): void {
	pending.clear();
}

/** True for the tools that change the site, so a read is never taken for a fix. */
export function isWriteTool(tool: string): boolean {
	// Local memory and skill tools write files on this machine, not a site.
	if (/^stonewright-(memory|skill|incident|learning)-/.test(tool)) return false;
	return WRITE_TOOL.test(tool);
}

function pick(input: Record<string, unknown>, keys: readonly string[]): string | null {
	for (const key of keys) {
		const value = input[key];
		if (typeof value === 'number' && Number.isFinite(value) && value > 0) return String(value);
		if (typeof value === 'string' && value.trim() !== '') return value.trim();
	}
	return null;
}

/**
 * The resource a write acts on, named the same way whichever tool wrote it: a page, post or custom post
 * item is `post:<id>` (ids are unique across post types), a template `template:<id>`, and so on.
 * A create has no resource yet, so it is not tracked.
 */
export function resourceKeyFor(tool: string, input: Record<string, unknown>): string | null {
	if (/^stonewright-(content|blocks|elementor|acf)-/.test(tool) || tool === 'stonewright-blueprint-apply') {
		if (/-create(-page|-post)?$/.test(tool)) return null;
		const id = pick(input, tool === 'stonewright-blueprint-apply' ? ['post_id'] : ['post_id', 'id']);
		return id === null ? null : `post:${id}`;
	}
	if (tool.startsWith('stonewright-template-part-')) {
		const id = pick(input, ['id']);
		return id === null ? null : `template-part:${id}`;
	}
	if (tool.startsWith('stonewright-template-')) {
		const id = pick(input, ['id']);
		return id === null ? null : `template:${id}`;
	}
	if (tool.startsWith('stonewright-global-styles-')) return 'global-styles';
	if (tool.startsWith('stonewright-plugin-')) {
		const id = pick(input, ['plugin']);
		return id === null ? null : `plugin:${id}`;
	}
	if (tool.startsWith('stonewright-theme-')) {
		const id = pick(input, ['stylesheet']);
		return id === null ? null : `theme:${id}`;
	}
	const family = /^stonewright-(menu|widget|media|comment|user|taxonomy|settings)-?/.exec(tool)?.[1];
	if (family === undefined) return null;
	if (family === 'settings') return 'settings';
	const id = pick(input, ['id', 'widget_id']);
	return id === null ? null : `${family}:${id}`;
}

type ToolResult = { isError?: boolean; content: Array<{ type: 'text'; text: string }> };

function payloadOf(result: ToolResult): Record<string, unknown> | null {
	const raw = result.content?.[0]?.text;
	if (typeof raw !== 'string') return null;
	try {
		const parsed = JSON.parse(raw) as unknown;
		return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : null;
	} catch {
		return null;
	}
}

function codeOf(payload: Record<string, unknown>): string {
	for (const key of ['error', 'code']) {
		const value = payload[key];
		if (typeof value === 'string' && value !== '') return value.slice(0, 80);
	}
	return 'error';
}

type Outcome = { kind: 'failure'; code: string } | { kind: 'success' } | { kind: 'ignore' };

function classify(result: ToolResult): Outcome {
	const payload = payloadOf(result);
	if (payload === null) return { kind: 'ignore' };
	// A guard that stopped the call, a custom-code refusal, a dry run and a call that wrote nothing changed nothing.
	if (
		payload['blocked'] === true ||
		payload['supported'] === false ||
		payload['dry_run'] === true ||
		payload['written'] === false
	) {
		return { kind: 'ignore' };
	}
	const failed = result.isError === true || payload['ok'] === false || typeof payload['error'] === 'string';
	if (failed) {
		const code = codeOf(payload);
		return IGNORED_CODES.has(code) ? { kind: 'ignore' } : { kind: 'failure', code };
	}
	return { kind: 'success' };
}

function remember(key: string, failure: Failure): void {
	pending.delete(key);
	pending.set(key, failure);
	while (pending.size > MAX_PENDING) {
		const oldest = pending.keys().next().value;
		if (oldest === undefined) break;
		pending.delete(oldest);
	}
}

function withOffer(result: ToolResult, offer: Record<string, unknown>): ToolResult {
	const payload = payloadOf(result);
	if (payload === null) return result;
	return { ...result, content: [{ type: 'text', text: JSON.stringify({ ...payload, learning_offer: offer }, null, 2) }] };
}

/**
 * Looks at the answer of one finished Direct call. A failed write on a resource is remembered; a later
 * successful write on the same resource (same site) gets the offer added to its answer, once.
 * Any other call is returned unchanged.
 */
export function observeCorrection<T extends ToolResult>(args: {
	tool: string;
	/** The site's canonical URL (or alias), so two sites never share a resource. */
	site: string;
	input: Record<string, unknown>;
	result: T;
	/** Audit event id of the failed call, cited in the offer as evidence. */
	failureEventId?: string;
	now?: number;
}): T {
	if (!isWriteTool(args.tool)) return args.result;
	const resource = resourceKeyFor(args.tool, args.input);
	if (resource === null) return args.result;
	const key = `${args.site.replace(/\/+$/, '')}|${resource}`;
	const now = args.now ?? Date.now();
	const outcome = classify(args.result);

	if (outcome.kind === 'failure') {
		remember(key, {
			tool: args.tool,
			code: outcome.code,
			at: now,
			...(args.failureEventId !== undefined ? { eventId: args.failureEventId } : {}),
		});
		return args.result;
	}
	if (outcome.kind !== 'success') return args.result;

	const earlier = pending.get(key);
	if (earlier === undefined) return args.result;
	if (now - earlier.at > FAILURE_TTL_MS) {
		pending.delete(key);
		return args.result;
	}
	pending.delete(key);
	const evidence = `${earlier.tool} on ${resource} failed with ${earlier.code}${earlier.eventId ? ` (audit event ${earlier.eventId})` : ''}; ${args.tool} then succeeded`;
	return withOffer(args.result, {
		trigger: 'write_failed_then_succeeded',
		resource,
		earlier_failure: { tool: earlier.tool, code: earlier.code },
		fixed_by: { tool: args.tool },
		next: 'An earlier write to this resource failed and this one worked. If what changed is worth keeping, tell the user what failed and what fixed it, and ask the user whether to record it. Only if they agree, call stonewright-learning-record. Do not record it on your own.',
		record_with: { tool: 'stonewright-learning-record', source: 'write-recovery', kind: 'lesson', evidence },
	}) as T;
}
