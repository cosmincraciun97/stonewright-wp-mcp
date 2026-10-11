/**
 * Gutenberg block markup: a parser, a serializer and the diagnostics of a document.
 *
 * Written from the public block grammar (comment delimiters `<!-- wp:name {json} -->` ... `<!-- /wp:name -->`
 * and the self-closing `<!-- wp:name /-->`). A document is read into a tree of blocks; text outside any
 * block is a freeform (classic) block. Nothing here talks to WordPress.
 *
 * A node keeps the source text it came from (`raw`). Serializing a document writes an untouched node back
 * exactly as it was found and re-writes only the nodes that were changed (`dirty`) and their ancestors, so
 * a surgical edit leaves the rest of the page byte for byte as it was.
 */

export interface BlockNode {
	/** `core/paragraph`, `acme/hero`; null for freeform HTML. */
	name: string | null;
	attrs: Record<string, unknown>;
	innerBlocks: BlockNode[];
	/** The text around the inner blocks: strings, with a null where each inner block goes. */
	innerContent: Array<string | null>;
	/** The strings of `innerContent` joined. */
	innerHTML: string;
	selfClosing?: boolean;
	/** The source text of the node, kept while it is unchanged. */
	raw?: string;
	/** Set on a node whose own delimiters, attributes or content changed. */
	dirty?: boolean;
	/** Whitespace-only text between root blocks: written back, never addressed. */
	blank?: boolean;
	/** 1-based line of the opening comment in the markup it was read from; absent on a block made by a program. */
	line?: number;
}

export interface BlockDiagnostic {
	severity: 'error' | 'warning';
	code:
		| 'unterminated_comment'
		| 'malformed_block_comment'
		| 'invalid_attributes'
		| 'unclosed_block'
		| 'unexpected_close'
		| 'mismatched_close'
		| 'unknown_block'
		| 'custom_code';
	message: string;
	/** 1-based line of the comment in the markup. */
	line: number;
	/** The block the diagnostic is about, as `core/heading`. */
	block?: string;
	/** Path of the block in the document, when it is known. */
	path?: number[];
}

export interface ParsedBlocks {
	blocks: BlockNode[];
	diagnostics: BlockDiagnostic[];
}

const NAME = /^(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?=\s|$)/;

export function normalizeBlockName(name: string): string {
	return name.includes('/') ? name : `core/${name}`;
}

/** The name as it is written in a delimiter: `core/` is left off. */
export function shortBlockName(name: string): string {
	return name.startsWith('core/') ? name.slice(5) : name;
}

export function lineOf(markup: string, offset: number): number {
	let line = 1;
	for (let i = 0; i < offset && i < markup.length; i += 1) {
		if (markup.charCodeAt(i) === 10) line += 1;
	}
	return line;
}

type Frame = {
	name: string;
	attrs: Record<string, unknown>;
	innerBlocks: BlockNode[];
	innerContent: Array<string | null>;
	start: number;
	line: number;
};

function freeform(text: string): BlockNode {
	return {
		name: null,
		attrs: {},
		innerBlocks: [],
		innerContent: [text],
		innerHTML: text,
		raw: text,
		...(text.trim() === '' ? { blank: true } : {}),
	};
}

function finish(frame: Frame, end: number, markup: string): BlockNode {
	return {
		name: frame.name,
		attrs: frame.attrs,
		innerBlocks: frame.innerBlocks,
		innerContent: frame.innerContent,
		innerHTML: frame.innerContent.filter((part): part is string => part !== null).join(''),
		raw: markup.slice(frame.start, end),
		line: frame.line,
	};
}

/** Reads block markup into a tree and the problems found on the way. Never throws. */
export function parseBlockMarkup(markup: string): ParsedBlocks {
	const diagnostics: BlockDiagnostic[] = [];
	const root: BlockNode[] = [];
	const stack: Frame[] = [];

	const addNode = (node: BlockNode): void => {
		const parent = stack.at(-1);
		if (parent === undefined) {
			root.push(node);
			return;
		}
		parent.innerBlocks.push(node);
		parent.innerContent.push(null);
	};
	const addText = (text: string): void => {
		if (text === '') return;
		const parent = stack.at(-1);
		if (parent === undefined) {
			root.push(freeform(text));
			return;
		}
		const last = parent.innerContent.at(-1);
		if (typeof last === 'string') parent.innerContent[parent.innerContent.length - 1] = last + text;
		else parent.innerContent.push(text);
	};
	const report = (diagnostic: Omit<BlockDiagnostic, 'severity'> & { severity?: BlockDiagnostic['severity'] }): void => {
		diagnostics.push({ severity: 'error', ...diagnostic });
	};

	let index = 0;
	while (index < markup.length) {
		const open = markup.indexOf('<!--', index);
		if (open < 0) {
			addText(markup.slice(index));
			break;
		}
		addText(markup.slice(index, open));
		const close = markup.indexOf('-->', open + 4);
		if (close < 0) {
			report({
				code: 'unterminated_comment',
				message: 'A comment is opened with "<!--" and never closed with "-->".',
				line: lineOf(markup, open),
			});
			addText(markup.slice(open));
			break;
		}
		const end = close + 3;
		const body = markup.slice(open + 4, close);
		index = end;
		const trimmed = body.trim();
		const candidate = trimmed.startsWith('wp:') || trimmed.startsWith('/wp:') || /^\/?\s*wp:/i.test(trimmed);
		if (!candidate) {
			addText(markup.slice(open, end));
			continue;
		}
		const line = lineOf(markup, open);
		const selfClosing = body.endsWith('/');
		const core = selfClosing ? body.slice(0, -1) : body;
		const named = NAME.exec(core.trim());
		if (!/^\s/.test(core) || !/\s$/.test(core) || named === null) {
			report({
				code: 'malformed_block_comment',
				message:
					'A comment starts like a block comment but is not one WordPress reads: it needs a lowercase block name, and a space after "<!--" and before "-->" (or "/-->").',
				line,
			});
			addText(markup.slice(open, end));
			continue;
		}
		const closing = named[1] === '/';
		const name = normalizeBlockName(named[2] ?? '');
		const rest = core.trim().slice(named[0].length).trim();

		if (closing) {
			if (rest !== '' || selfClosing) {
				report({ code: 'malformed_block_comment', message: `The closing comment of ${name} carries more than its name.`, line, block: name });
				addText(markup.slice(open, end));
				continue;
			}
			if (stack.length === 0) {
				report({ code: 'unexpected_close', message: `A closing comment for ${name} has no block open to close.`, line, block: name });
				continue;
			}
			let depth = stack.length - 1;
			while (depth >= 0 && stack[depth]?.name !== name) depth -= 1;
			if (depth < 0) {
				report({
					code: 'mismatched_close',
					message: `A closing comment for ${name} does not match the block open here, ${stack.at(-1)?.name ?? ''}.`,
					line,
					block: name,
				});
				continue;
			}
			if (depth !== stack.length - 1) {
				report({
					code: 'mismatched_close',
					message: `A closing comment for ${name} arrives while ${stack.at(-1)?.name ?? ''} is still open (unbalanced block comments).`,
					line,
					block: name,
				});
			}
			while (stack.length - 1 > depth) {
				// The blocks that were left open are reported above; what they held is kept, as a changed node.
				const node = finish(stack.pop() as Frame, close, markup);
				delete node.raw;
				addNode(node);
			}
			const frame = stack.pop() as Frame;
			addNode(finish(frame, end, markup));
			continue;
		}

		let attrs: Record<string, unknown> = {};
		if (rest !== '') {
			let parsed: unknown;
			let valid = rest.startsWith('{') && rest.endsWith('}');
			if (valid) {
				try {
					parsed = JSON.parse(rest) as unknown;
				} catch {
					valid = false;
				}
			}
			if (valid && parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
				attrs = parsed as Record<string, unknown>;
			} else {
				report({
					code: 'invalid_attributes',
					message: `The attributes of ${name} are not a valid JSON object.`,
					line,
					block: name,
				});
			}
		}
		if (selfClosing) {
			addNode({
				name,
				attrs,
				innerBlocks: [],
				innerContent: [],
				innerHTML: '',
				selfClosing: true,
				raw: markup.slice(open, end),
				line,
			});
			continue;
		}
		stack.push({ name, attrs, innerBlocks: [], innerContent: [], start: open, line });
	}

	while (stack.length > 0) {
		const frame = stack.pop() as Frame;
		report({
			code: 'unclosed_block',
			message: `The block comments are unbalanced: ${frame.name} is opened at line ${frame.line} and never closed.`,
			line: frame.line,
			block: frame.name,
		});
		const node = finish(frame, markup.length, markup);
		delete node.raw;
		addNode(node);
	}
	diagnostics.sort((a, b) => a.line - b.line);
	return { blocks: root, diagnostics };
}

/** The blocks a path can address at the top level: blank text between them is skipped. */
export function addressableBlocks(blocks: readonly BlockNode[]): BlockNode[] {
	return blocks.filter((block) => block.blank !== true);
}

/** The block at a path such as `[1, 0]` (second top-level block, its first inner block), or null. */
export function blockAt(blocks: readonly BlockNode[], path: readonly number[]): BlockNode | null {
	let level: readonly BlockNode[] = addressableBlocks(blocks);
	let found: BlockNode | null = null;
	for (const step of path) {
		if (!Number.isInteger(step) || step < 0) return null;
		found = level[step] ?? null;
		if (found === null) return null;
		level = found.innerBlocks;
	}
	return found;
}

export function countBlocks(blocks: readonly BlockNode[]): number {
	let total = 0;
	for (const block of addressableBlocks(blocks)) total += 1 + countBlocks(block.innerBlocks);
	return total;
}

/** Every block with its path, depth first. */
export function walkBlocks(blocks: readonly BlockNode[], visit: (block: BlockNode, path: number[]) => void, prefix: number[] = []): void {
	addressableBlocks(blocks).forEach((block, index) => {
		const path = [...prefix, index];
		visit(block, path);
		walkBlocks(block.innerBlocks, visit, path);
	});
}

/** Block attributes as WordPress writes them in a delimiter: JSON with `--`, `<`, `>`, `&` and quotes escaped. */
export function serializeBlockAttributes(attrs: Record<string, unknown>): string {
	return JSON.stringify(attrs)
		.replace(/--/g, '\\u002d\\u002d')
		.replace(/</g, '\\u003c')
		.replace(/>/g, '\\u003e')
		.replace(/&/g, '\\u0026')
		.replace(/\\"/g, '\\u0022');
}

function subtreeDirty(node: BlockNode): boolean {
	return node.dirty === true || node.raw === undefined || node.innerBlocks.some(subtreeDirty);
}

function serializeNode(node: BlockNode): string {
	if (!subtreeDirty(node)) return node.raw as string;
	if (node.name === null) return node.innerHTML;
	const short = shortBlockName(node.name);
	const attrs = Object.keys(node.attrs).length > 0 ? ` ${serializeBlockAttributes(node.attrs)}` : '';
	let inner = '';
	let child = 0;
	for (const part of node.innerContent) {
		if (part === null) {
			const next = node.innerBlocks[child];
			child += 1;
			if (next !== undefined) inner += serializeNode(next);
		} else {
			inner += part;
		}
	}
	if (inner === '' && node.innerBlocks.length === 0) return `<!-- wp:${short}${attrs} /-->`;
	return `<!-- wp:${short}${attrs} -->${inner}<!-- /wp:${short} -->`;
}

/** The markup of a block list; an untouched block is written back exactly as it was read. */
export function serializeBlocks(blocks: readonly BlockNode[]): string {
	return blocks.map(serializeNode).join('');
}

/** The names of the blocks in a list, depth first, as they are written in the delimiters. */
export function writtenBlockNames(blocks: readonly BlockNode[]): string[] {
	const names: string[] = [];
	walkBlocks(blocks, (block) => {
		if (block.name !== null) names.push(shortBlockName(block.name));
	});
	return names;
}
