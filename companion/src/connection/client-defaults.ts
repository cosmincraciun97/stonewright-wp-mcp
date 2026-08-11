/**
 * Client-aware profile defaults.
 *
 * - Unknown clients and unset env default to essential-static (NOT bootstrap, NOT full).
 * - full is never selected implicitly.
 * - Known reliable dynamic clients may still start on bootstrap then activate essential.
 */

export type ClientReliability = 'unknown' | 'static' | 'dynamic-reliable';

/** Clients known to honor tools/list_changed without restart. */
const DYNAMIC_RELIABLE_CLIENT_HINTS = [
	'claude-code',
	'claude_code',
	'codex',
	'cursor',
] as const;

export function classifyClientReliability(clientHint: string | null | undefined): ClientReliability {
	const normalized = (clientHint ?? '').trim().toLowerCase().replace(/[\s_]+/g, '-');
	if (!normalized || normalized === 'unknown' || normalized === 'auto') {
		return 'unknown';
	}
	if (DYNAMIC_RELIABLE_CLIENT_HINTS.some((hint) => normalized.includes(hint))) {
		return 'dynamic-reliable';
	}
	if (normalized.includes('static') || normalized.includes('antigravity') || normalized.includes('gemini')) {
		return 'static';
	}
	return 'unknown';
}

/**
 * Default MCP tool profile when env is unset.
 * Unknown / static clients → essential-static.
 * Dynamic-reliable may still prefer bootstrap for progressive expansion.
 */
export function defaultProxyProfileForClient(
	clientHint: string | null | undefined,
	options: { allowBootstrapForDynamic?: boolean } = {},
): 'essential-static' | 'bootstrap' {
	const reliability = classifyClientReliability(clientHint);
	if (options.allowBootstrapForDynamic && reliability === 'dynamic-reliable') {
		return 'bootstrap';
	}
	return 'essential-static';
}

/** full is never selected implicitly from empty/auto/unknown env. */
export function isImplicitFullForbidden(raw: string | null | undefined): boolean {
	const normalized = (raw ?? '').trim().toLowerCase();
	return normalized === '' || normalized === 'auto' || normalized === 'default' || normalized === 'unknown';
}
