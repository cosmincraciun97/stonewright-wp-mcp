import { appendDirectAudit, defaultAuditPath } from './audit.js';

/**
 * Custom-code boundary for Direct writes.
 *
 * Direct mode has no authenticated wp-admin one-time-grant boundary, so it never writes custom code:
 * CSS in theme.json or Elementor settings, HTML-type Elementor widgets, and script, style, iframe,
 * object, embed, link and meta http-equiv elements, inline event handlers and javascript: URLs in markup. A write that carries any of these
 * is refused before anything is sent, with the same approval envelope `stonewright-custom-css` returns.
 */

export type CustomCodeKind =
	| 'style'
	| 'script'
	| 'iframe'
	| 'object'
	| 'embed'
	| 'link'
	| 'meta'
	| 'event_handler'
	| 'javascript_url'
	| 'custom_css_key'
	| 'html_widget'
	| 'unscannable';

export interface CustomCodeFinding {
	kind: CustomCodeKind;
	path: string;
}

const MAX_FINDINGS = 50;
const MAX_LISTED_FINDINGS = 20;
const MAX_DEPTH = 64;

const CSS_KEYS = new Set([
	'custom_css',
	'_custom_css',
	'customcss',
	'page_custom_css',
	'kit_custom_css',
	'additional_custom_css',
	'custom_css_pro',
]);

const HTML_WIDGET_TYPES = new Set(['html', 'raw-html', 'raw_html', 'text-path']);

const NAMED_ELEMENT = /<(style|script|iframe)\b/gi;
// Elements that load or redirect to something outside the page. A name continued by a dash or colon is another element.
const EXTERNAL_ELEMENT = /<(object|embed|link)(?![a-z0-9_:-])/gi;
const LOOSE_TAG = /<([a-z][a-z0-9:-]*)([^<>]*)/gi;
const TAG_NAME = /^<([a-z][a-z0-9:-]*)/i;
const ATTRIBUTE = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'<>=`]+)))?/g;
const BLOCK_COMMENT_ATTRS = /^\s*\/?wp:[a-z0-9_-]+(?:\/[a-z0-9_-]+)?\s+(\{[\s\S]*\})\s*\/?\s*$/;

const KIND_ORDER: readonly CustomCodeKind[] = [
	'style',
	'script',
	'iframe',
	'object',
	'embed',
	'link',
	'meta',
	'event_handler',
	'javascript_url',
	'unscannable',
];

/** Custom CSS keys: the fixed names, plus any `custom_css` key with a suffix (case and dashes ignored). */
export function isCustomCssKey(key: string): boolean {
	const normalized = key.toLowerCase().replace(/-/g, '_');
	return CSS_KEYS.has(normalized) || /^_?custom_css(?:_|$)/.test(normalized);
}

/** theme.json also carries custom CSS under a plain `css` key (top level and per block). */
function isThemeJsonCssKey(key: string): boolean {
	return key.toLowerCase() === 'css' || isCustomCssKey(key);
}

export function isHtmlWidgetType(widgetType: string): boolean {
	const normalized = widgetType.trim().toLowerCase();
	if (normalized === '') return false;
	return HTML_WIDGET_TYPES.has(normalized) || normalized.includes('html');
}

const NAMED_ENTITIES: Record<string, string> = {
	colon: ':',
	tab: '\t',
	newline: '\n',
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: "'",
	nbsp: ' ',
	lpar: '(',
	rpar: ')',
};

function decodeEntities(value: string): string {
	return value
		.replace(/&#(x[0-9a-f]+|[0-9]+);?/gi, (whole, code: string) => {
			const point = code[0] === 'x' || code[0] === 'X' ? Number.parseInt(code.slice(1), 16) : Number.parseInt(code, 10);
			if (!Number.isFinite(point) || point < 0 || point > 0x10ffff) return whole;
			return String.fromCodePoint(point);
		})
		.replace(/&([a-z]+);/gi, (whole, name: string) => NAMED_ENTITIES[name.toLowerCase()] ?? whole);
}

/** True when a browser would read the value as a javascript: URL once it has decoded entities and dropped ignored whitespace. */
function isScriptUrl(value: string): boolean {
	if (value === '') return false;
	// A browser ignores whitespace and control characters inside a URL scheme, so drop them before comparing.
	// eslint-disable-next-line no-control-regex
	const compact = decodeEntities(value).replace(/[\u0000- ]+/g, '');
	return compact.slice(0, 11).toLowerCase() === 'javascript:';
}

/** Name and attribute text of every element start, honoring quoted values (a quoted ">" does not end the tag). */
function quoteAwareTagBodies(markup: string): Array<[string, string]> {
	const bodies: Array<[string, string]> = [];
	const length = markup.length;
	let index = 0;
	while (index < length) {
		const open = markup.indexOf('<', index);
		if (open < 0) break;
		const name = TAG_NAME.exec(markup.slice(open, open + 80));
		if (!name) {
			index = open + 1;
			continue;
		}
		const start = open + name[0].length;
		let quote = '';
		let end = start;
		for (; end < length; end += 1) {
			const char = markup.charAt(end);
			if (quote !== '') {
				if (char === quote) quote = '';
			} else if (char === '"' || char === "'") {
				quote = char;
			} else if (char === '>') {
				break;
			}
		}
		bodies.push([(name[1] ?? '').toLowerCase(), markup.slice(start, end)]);
		index = end + 1;
	}
	return bodies;
}

function attributePairs(body: string): Array<[string, string]> {
	const pairs: Array<[string, string]> = [];
	for (const match of body.matchAll(ATTRIBUTE)) {
		pairs.push([match[1] ?? '', match[2] ?? match[3] ?? match[4] ?? '']);
	}
	return pairs;
}

function scanText(text: string, found: Set<CustomCodeKind>): void {
	if (!text.includes('<')) return;
	for (const match of text.matchAll(NAMED_ELEMENT)) {
		found.add((match[1] ?? '').toLowerCase() as CustomCodeKind);
	}
	for (const match of text.matchAll(EXTERNAL_ELEMENT)) {
		found.add((match[1] ?? '').toLowerCase() as CustomCodeKind);
	}
	const bodies = quoteAwareTagBodies(text);
	for (const match of text.matchAll(LOOSE_TAG)) {
		bodies.push([(match[1] ?? '').toLowerCase(), match[2] ?? '']);
	}
	for (const [tag, body] of bodies) {
		for (const [name, value] of attributePairs(body)) {
			if (tag === 'meta' && name.toLowerCase() === 'http-equiv') found.add('meta');
			if (/^on[a-z0-9_:.-]+$/i.test(name)) found.add('event_handler');
			if (isScriptUrl(value)) found.add('javascript_url');
		}
	}
}

function jsonStrings(value: unknown, visit: (text: string) => void, depth = 0): void {
	if (depth > MAX_DEPTH) return;
	if (typeof value === 'string') {
		visit(value);
	} else if (Array.isArray(value)) {
		for (const item of value) jsonStrings(item, visit, depth + 1);
	} else if (value !== null && typeof value === 'object') {
		for (const item of Object.values(value as Record<string, unknown>)) jsonStrings(item, visit, depth + 1);
	}
}

function unescapeJsonUnicode(text: string): string {
	return text.replace(/\\u([0-9a-f]{4})/gi, (_whole, hex: string) => String.fromCharCode(Number.parseInt(hex, 16)));
}

/** Block comment attributes are JSON, so a string in them is read as markup and as a URL. */
function scanBlockComments(markup: string, found: Set<CustomCodeKind>): void {
	let index = 0;
	while (index < markup.length) {
		const open = markup.indexOf('<!--', index);
		if (open < 0) return;
		const close = markup.indexOf('-->', open + 4);
		if (close < 0) return;
		const body = markup.slice(open + 4, close);
		index = close + 3;
		const attrs = BLOCK_COMMENT_ATTRS.exec(body);
		if (!attrs?.[1]) continue;
		try {
			jsonStrings(JSON.parse(attrs[1]) as unknown, (text) => {
				scanText(text, found);
				if (isScriptUrl(text)) found.add('javascript_url');
			});
		} catch {
			const decoded = unescapeJsonUnicode(attrs[1]);
			scanText(decoded, found);
			if (/javascript\s*:/i.test(decodeEntities(decoded))) found.add('javascript_url');
		}
	}
}

function orderedKinds(found: Set<CustomCodeKind>): CustomCodeKind[] {
	return KIND_ORDER.filter((kind) => found.has(kind));
}

/**
 * Kinds of custom code a markup string carries: `style`, `script` and `iframe` elements, `event_handler`
 * for an inline on* attribute, and `javascript_url` for an attribute or block attribute value that
 * navigates to a javascript: URL (entity-encoded, spaced and mixed-case forms included). Escaped markup
 * and prose that only mentions code are not code.
 */
export function scanMarkup(markup: string): CustomCodeKind[] {
	const found = new Set<CustomCodeKind>();
	scanText(markup, found);
	scanBlockComments(markup, found);
	return orderedKinds(found);
}

function isEmptyValue(value: unknown): boolean {
	if (value === null || value === undefined) return true;
	if (typeof value === 'string') return value.trim() === '';
	if (Array.isArray(value)) return value.length === 0;
	if (typeof value === 'object') return Object.keys(value as Record<string, unknown>).length === 0;
	return false;
}

type StructuredOptions = {
	isCssKey: (key: string) => boolean;
};

function scanStructured(
	value: unknown,
	path: string,
	options: StructuredOptions,
	out: CustomCodeFinding[],
	depth = 0,
): void {
	if (out.length >= MAX_FINDINGS) return;
	if (depth > MAX_DEPTH) {
		out.push({ kind: 'unscannable', path });
		return;
	}
	if (typeof value === 'string') {
		const found = new Set<CustomCodeKind>(scanMarkup(value));
		if (isScriptUrl(value)) found.add('javascript_url');
		for (const kind of orderedKinds(found)) out.push({ kind, path });
		return;
	}
	if (Array.isArray(value)) {
		value.forEach((item, index) => scanStructured(item, `${path}.${index}`, options, out, depth + 1));
		return;
	}
	if (value === null || typeof value !== 'object') return;
	for (const [key, member] of Object.entries(value as Record<string, unknown>)) {
		const memberPath = path === '' ? key : `${path}.${key}`;
		if (options.isCssKey(key)) {
			if (!isEmptyValue(member)) out.push({ kind: 'custom_css_key', path: memberPath });
			continue;
		}
		scanStructured(member, memberPath, options, out, depth + 1);
	}
}

/** Custom code in an Elementor element tree: custom CSS keys, HTML-type widgets, and code in any string setting. */
export function scanElementorTree(tree: unknown): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	const options: StructuredOptions = { isCssKey: isCustomCssKey };
	const walk = (nodes: unknown, path: string, depth: number): void => {
		if (out.length >= MAX_FINDINGS) return;
		if (depth > MAX_DEPTH) {
			out.push({ kind: 'unscannable', path });
			return;
		}
		const list = Array.isArray(nodes) ? nodes : nodes !== null && typeof nodes === 'object' ? [nodes] : [];
		list.forEach((node, index) => {
			if (node === null || typeof node !== 'object' || Array.isArray(node)) return;
			const element = node as Record<string, unknown>;
			const id =
				typeof element['id'] === 'string' || typeof element['id'] === 'number' ? String(element['id']) : String(index);
			const nodePath = `${path}[${id}]`;
			const widgetType = element['widgetType'];
			if (typeof widgetType === 'string' && isHtmlWidgetType(widgetType)) {
				out.push({ kind: 'html_widget', path: `${nodePath}.widgetType` });
			}
			const { elements, ...ownFields } = element;
			scanStructured(ownFields, nodePath, options, out, depth);
			walk(elements, `${nodePath}.elements`, depth + 1);
		});
	};
	walk(tree, 'elementor', 0);
	return out;
}

/** Custom code in a theme.json document: `css` (top level, per block, per variation) and code in any string. */
export function scanGlobalStyles(input: {
	settings?: Record<string, unknown> | undefined;
	styles?: Record<string, unknown> | undefined;
}): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	const options: StructuredOptions = { isCssKey: isThemeJsonCssKey };
	if (input.settings !== undefined) scanStructured(input.settings, 'settings', options, out);
	if (input.styles !== undefined) scanStructured(input.styles, 'styles', options, out);
	return out;
}

/** Custom code in post meta. `_elementor_data` is unpacked and judged as an Elementor tree. */
export function scanMeta(meta: Record<string, unknown> | undefined): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	if (meta === undefined) return out;
	const options: StructuredOptions = { isCssKey: isCustomCssKey };
	for (const [key, value] of Object.entries(meta)) {
		const path = `meta.${key}`;
		if (key === '_elementor_data') {
			let tree: unknown = value;
			if (typeof value === 'string') {
				try {
					tree = JSON.parse(value) as unknown;
				} catch {
					tree = undefined;
				}
			}
			if (tree !== undefined && typeof tree !== 'string') {
				out.push(...scanElementorTree(tree).map((finding) => ({ ...finding, path: `${path}.${finding.path}` })));
				continue;
			}
		}
		if (isCustomCssKey(key)) {
			if (!isEmptyValue(value)) out.push({ kind: 'custom_css_key', path });
			continue;
		}
		scanStructured(value, path, options, out);
	}
	return out;
}

/** Custom code in a widget write: an HTML-type widget, and code or CSS anywhere in its instance. */
export function scanWidget(input: {
	id_base?: string | undefined;
	instance?: Record<string, unknown> | undefined;
}): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	if (input.id_base !== undefined && isHtmlWidgetType(input.id_base)) {
		out.push({ kind: 'html_widget', path: 'id_base' });
	}
	if (input.instance !== undefined) {
		scanStructured(input.instance, 'instance', { isCssKey: isCustomCssKey }, out);
	}
	return out;
}

/** Custom code in named markup fields of a write (content, title, description). */
export function scanMarkupFields(fields: Record<string, string | undefined>): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	for (const [name, value] of Object.entries(fields)) {
		if (typeof value !== 'string' || value === '') continue;
		for (const kind of scanMarkup(value)) out.push({ kind, path: name });
	}
	return out;
}

/**
 * Custom code in the free-text fields of a write (titles, names, captions, descriptions, URLs, setting values).
 * Markup is read as in content, and a value that is itself a javascript: URL counts too.
 */
export function scanTextFields(fields: Record<string, unknown>): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	scanStructured(fields, '', { isCssKey: () => false }, out);
	return out;
}

/** Custom code in ACF field values, at any depth (repeaters, flexible content); a custom CSS field counts as code. */
export function scanAcfValues(acf: unknown): CustomCodeFinding[] {
	const out: CustomCodeFinding[] = [];
	scanStructured(acf, 'acf', { isCssKey: isCustomCssKey }, out);
	return out;
}

export const CUSTOM_CODE_REFUSAL_CODE = 'custom_code_approval_required';

const CUSTOM_CODE_HINT =
	'Direct mode cannot write custom code (CSS, script, style, iframe, object, embed, link or meta http-equiv elements, inline event handlers, javascript: URLs, or HTML widgets) because it has no authenticated wp-admin one-time-grant boundary. Nothing was written. Direct checks everything it is sent, so if the page, template or widget being saved already contains custom code (an HTML widget, custom CSS or inline script), Direct cannot save it either, even when you did not add that code. Use Plugin mode and its custom-code approval (the Stonewright plugin tool stonewright-theme-custom-css for site CSS: run dry_run, show the human approval URL, exact path, byte counts, and summary, then stop), or stop and ask a human. Otherwise remove the custom code you added and use native blocks, Elementor widgets, or theme presets.';

export function customCodeRefusal(tool: string, findings: readonly CustomCodeFinding[]) {
	return {
		supported: false as const,
		error: CUSTOM_CODE_REFUSAL_CODE,
		approval_required: true as const,
		agent_must_stop: true as const,
		tool,
		written: false as const,
		findings: findings.slice(0, MAX_LISTED_FINDINGS).map((finding) => ({ kind: finding.kind, path: finding.path })),
		hint: CUSTOM_CODE_HINT,
	};
}

export type CustomCodeRefusal = ReturnType<typeof customCodeRefusal>;

/**
 * Build the refusal and record it as a blocked audit row (a blocked row opens no incident).
 * Returns null when there is nothing to refuse.
 */
export function refuseCustomCode(
	target: { alias: string; url: string },
	tool: string,
	resource: string,
	findings: readonly CustomCodeFinding[],
	env: NodeJS.ProcessEnv = process.env,
): CustomCodeRefusal | null {
	if (findings.length === 0) return null;
	try {
		appendDirectAudit({
			tool,
			site: target.alias,
			targetIdentity: target.url,
			resource,
			status: 'blocked',
			code: CUSTOM_CODE_REFUSAL_CODE,
		}, defaultAuditPath(env));
	} catch {
		// The refusal stands even when the audit row cannot be written.
	}
	return customCodeRefusal(tool, findings);
}
