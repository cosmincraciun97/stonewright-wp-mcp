/**
 * MCP server naming: stonewright-<sanitized-alias>
 * On collision with an existing name for a different site, suffix with a stable site-id fragment.
 */

export function sanitizeAlias(alias: string): string {
	return alias
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.replace(/-+/g, '-') || 'site';
}

/** Case-insensitive alias key after slug normalization. */
export function aliasKey(alias: string): string {
	return sanitizeAlias(alias);
}

export function mcpServerName(alias: string, siteId: string, taken: ReadonlySet<string>): string {
	const base = `stonewright-${sanitizeAlias(alias)}`;
	if (!taken.has(base)) {
		return base;
	}
	// Stable short suffix from site id (last 6 alphanumeric chars).
	const fragment = siteId.replace(/[^a-zA-Z0-9]/g, '').slice(-6).toLowerCase() || 'x';
	const candidate = `${base}-${fragment}`;
	if (!taken.has(candidate)) {
		return candidate;
	}
	let n = 2;
	while (taken.has(`${candidate}-${n}`)) {
		n += 1;
	}
	return `${candidate}-${n}`;
}

export function isStonewrightServerName(name: string): boolean {
	return /^stonewright(-[a-z0-9]+)+$/i.test(name) || name === 'stonewright';
}
