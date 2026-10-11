import { scanMarkup, scanTextFields, type CustomCodeFinding } from './custom-code-guard.js';
import { walkBlocks, type BlockNode } from './block-markup.js';

/** The label of a block in findings: its path in the document, for example `block 1.0`. */
export function blockPathLabel(path: readonly number[]): string {
	return `block ${path.join('.')}`;
}

/** The text a block holds itself: its strings, not what its inner blocks hold. */
function ownMarkup(block: BlockNode): string {
	return block.innerContent.filter((part): part is string => part !== null).join('');
}

/**
 * Custom code in a block tree, block by block: script, style, iframe and other elements, inline event
 * handlers and javascript: URLs in the markup of every block (html and classic blocks included), and the
 * same in every string of the block's attributes. A finding names the block by its path. Escaped markup
 * and prose that only mentions code are not code.
 */
export function scanBlockTree(blocks: readonly BlockNode[]): CustomCodeFinding[] {
	const findings: CustomCodeFinding[] = [];
	const seen = new Set<string>();
	const add = (kind: CustomCodeFinding['kind'], path: string): void => {
		const key = `${kind}@${path}`;
		if (seen.has(key)) return;
		seen.add(key);
		findings.push({ kind, path });
	};
	walkBlocks(blocks, (block, path) => {
		const label = blockPathLabel(path);
		for (const kind of scanMarkup(ownMarkup(block))) add(kind, label);
		if (Object.keys(block.attrs).length > 0) {
			for (const finding of scanTextFields({ attrs: block.attrs })) add(finding.kind, label);
		}
	});
	return findings;
}
