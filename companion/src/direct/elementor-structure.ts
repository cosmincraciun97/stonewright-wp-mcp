/**
 * Elementor tree checks for Direct mode that need no live schema: a canonical tree hash, the architecture of a
 * document (classic V3, Atomic V4, mixed), and structure rules for a whole-tree write.
 * The write gate in elementor-integrity.ts keeps the size, double-encoding and widget-type checks.
 */

import { sha256Text, stableJson } from './verify.js';
import type { IntegrityError, IntegrityResult } from './elementor-integrity.js';

/** Largest tree Direct writes in one call, as encoded JSON. */
export const MAX_TREE_BYTES = 4 * 1024 * 1024;
export const MAX_TREE_ELEMENTS = 5000;
export const MAX_TREE_DEPTH = 32;

/**
 * Canonical hash of a whole tree: object keys sorted, lists kept in order, then SHA-256 of the stable JSON.
 * The same idea as the plugin's TreeHasher, computed here; the two values are not interchangeable.
 */
export function treeHash(tree: unknown): string {
	return sha256Text(stableJson(tree ?? null));
}

export type Architecture = 'empty' | 'v3' | 'v4' | 'mixed';

export type ArchitectureReport = {
	architecture: Architecture;
	/** Elements with an `e-` type, plus elements whose settings carry a `$$type` envelope. */
	atomic: number;
	other: number;
};

type Node = Record<string, unknown>;

function isNode(value: unknown): value is Node {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function visitAll(tree: unknown[], visitor: (el: Node) => void): void {
	for (const el of tree) {
		if (!isNode(el)) continue;
		visitor(el);
		const kids = el['elements'];
		if (Array.isArray(kids)) visitAll(kids, visitor);
	}
}

function hasTypeEnvelope(value: unknown, depth = 0): boolean {
	if (depth > 40 || value === null || typeof value !== 'object') return false;
	if (Array.isArray(value)) return value.some((item) => hasTypeEnvelope(item, depth + 1));
	const row = value as Node;
	if (Object.prototype.hasOwnProperty.call(row, '$$type')) return true;
	return Object.values(row).some((item) => hasTypeEnvelope(item, depth + 1));
}

/** Tells a classic, an Atomic and a mixed document apart, as the plugin's inspector does: by type prefix, and by `$$type` envelopes. */
export function inspectArchitecture(tree: unknown): ArchitectureReport {
	let atomic = 0;
	let other = 0;
	visitAll(Array.isArray(tree) ? tree : [], (el) => {
		const elType = String(el['elType'] ?? '');
		const kind = elType === 'widget' ? String(el['widgetType'] ?? '') : elType;
		if (kind.startsWith('e-')) {
			atomic += 1;
		} else if (hasTypeEnvelope(el['settings'])) {
			// A classic element that carries Atomic settings is a mix.
			atomic += 1;
			other += 1;
		} else {
			other += 1;
		}
	});
	const architecture: Architecture =
		atomic > 0 && other > 0 ? 'mixed' : atomic > 0 ? 'v4' : other > 0 ? 'v3' : 'empty';
	return { architecture, atomic, other };
}

const INVALID_ELEMENT_TYPES = new Set(['inner-section', 'inner_section', 'innersection']);

const UNSAFE_COLOR_VALUE = /[;{}<>]|javascript:/i;

function idOf(node: Node): string {
	return typeof node['id'] === 'string' || typeof node['id'] === 'number' ? String(node['id']).trim() : '';
}

function structureError(code: string, message: string, data: Record<string, unknown>): IntegrityError {
	return { ok: false, error_code: `stonewright_elementor_integrity_${code}`, message, data };
}

function findUnsafeColor(value: unknown, key: string, path: string, depth = 0): string | null {
	if (depth > 40) return null;
	if (typeof value === 'string') {
		return /color/i.test(key) && UNSAFE_COLOR_VALUE.test(value) ? path : null;
	}
	if (value === null || typeof value !== 'object') return null;
	for (const [childKey, child] of Object.entries(value as Node)) {
		// A list keeps the key of the field that holds it.
		const found = findUnsafeColor(child, Array.isArray(value) ? key : childKey, `${path}.${childKey}`, depth + 1);
		if (found !== null) return found;
	}
	return null;
}

function jsonBytes(value: unknown): number {
	try {
		return Buffer.byteLength(JSON.stringify(value), 'utf8');
	} catch {
		return 0;
	}
}

/**
 * Structure checks that need no Elementor schema: size caps, unique ids, nothing but containers or sections at
 * the document root, no widget inside a widget, settings that are objects, and no CSS in a colour value.
 * A widget or column the stored document already keeps at its root is left alone, so an old page stays editable.
 */
export function assertTreeStructure(tree: unknown[], previous: unknown[] = []): IntegrityResult {
	const bytes = jsonBytes(tree);
	if (bytes > MAX_TREE_BYTES) {
		return structureError('too_large', 'The Elementor document is larger than Direct writes in one call.', {
			bytes,
			max_bytes: MAX_TREE_BYTES,
			fix: ['write_a_smaller_tree', 'use_plugin_mode_for_large_documents'],
		});
	}
	const tolerated = new Set<string>();
	for (const el of previous) {
		if (!isNode(el)) continue;
		const type = String(el['elType'] ?? '');
		if (type === 'widget' || type === 'column') tolerated.add(idOf(el));
	}

	const seen = new Set<string>();
	let count = 0;
	let failure: IntegrityError | null = null;

	const visit = (nodes: unknown[], path: string, depth: number, parentIsPlainWidget: boolean): void => {
		for (let i = 0; i < nodes.length && failure === null; i += 1) {
			const el = nodes[i];
			if (!isNode(el)) continue;
			const here = `${path}.${i}`;
			const id = idOf(el);
			const elType = String(el['elType'] ?? '');
			const widgetType = String(el['widgetType'] ?? '');

			count += 1;
			if (count > MAX_TREE_ELEMENTS) {
				failure = structureError('too_large', 'The Elementor document has more elements than Direct writes in one call.', {
					max_elements: MAX_TREE_ELEMENTS,
					fix: ['write_a_smaller_tree', 'use_plugin_mode_for_large_documents'],
				});
				return;
			}
			if (depth >= MAX_TREE_DEPTH) {
				failure = structureError('too_deep', 'The Elementor document is nested deeper than Direct writes.', {
					path: here,
					max_depth: MAX_TREE_DEPTH,
				});
				return;
			}
			if (id !== '') {
				if (seen.has(id)) {
					failure = structureError('duplicate_id', 'Elementor element ids must be unique within the document.', { path: `${here}.id`, id });
					return;
				}
				seen.add(id);
			}
			if (INVALID_ELEMENT_TYPES.has(elType)) {
				failure = structureError(
					'invalid_element_type',
					'"inner-section" is not an Elementor element type. A nested section is elType "section" with isInner:true, or a nested container.',
					{ path: `${here}.elType`, id, elType },
				);
				return;
			}
			if (depth === 0 && !tolerated.has(id)) {
				if (elType === 'widget') {
					failure = structureError('widget_at_root', 'The document root holds containers or sections; a widget belongs inside one.', { path: here, id });
					return;
				}
				if (elType === 'column') {
					failure = structureError('invalid_root_element', 'The document root holds containers or sections; a column belongs inside a section.', { path: here, id });
					return;
				}
			}
			if (parentIsPlainWidget && elType === 'widget') {
				failure = structureError('widget_in_widget', 'A widget cannot sit directly inside another widget; put it in a container.', { path: here, id });
				return;
			}
			const settings = el['settings'];
			if (settings !== undefined) {
				const isObject = settings !== null && typeof settings === 'object' && !Array.isArray(settings);
				// Elementor stores empty settings as an empty list.
				const isEmptyList = Array.isArray(settings) && settings.length === 0;
				if (!isObject && !isEmptyList) {
					failure = structureError('invalid_settings', 'settings must be an object.', { path: `${here}.settings`, id });
					return;
				}
				const unsafe = findUnsafeColor(settings, 'settings', `${here}.settings`);
				if (unsafe !== null) {
					failure = structureError('unsafe_value', 'A colour setting holds characters that are not part of a colour value (; { } < >).', { path: unsafe, id });
					return;
				}
			}
			const kids = el['elements'];
			if (Array.isArray(kids) && kids.length > 0) {
				visit(kids, `${here}.elements`, depth + 1, elType === 'widget' && !widgetType.startsWith('e-'));
			}
		}
	};
	visit(tree, 'root', 0, false);
	return failure ?? { ok: true };
}

/**
 * Refuses a whole-tree raw write when the stored document or the incoming tree is Atomic (V4) or mixed.
 * `atomic` in the result says whether the override was needed.
 */
export function assertNoAtomicRawWrite(
	tree: unknown[],
	previous: unknown[],
	options: { allow_atomic_raw?: boolean } = {},
): IntegrityResult & { atomic: boolean } {
	const incoming = inspectArchitecture(tree);
	const stored = inspectArchitecture(previous);
	const atomic = incoming.atomic > 0 || stored.atomic > 0;
	if (!atomic) return { ok: true, atomic: false };
	if (options.allow_atomic_raw === true) return { ok: true, atomic: true };
	return {
		ok: false,
		atomic: true,
		error_code: 'stonewright_elementor_atomic_raw_write_blocked',
		message:
			'This document uses Elementor Atomic (V4) elements, or the tree being written does. Direct writes the whole tree as plain JSON and cannot type V4 settings, so it does not rewrite such a document. Use Plugin mode for typed V4 writes. To replace the whole tree anyway, pass allow_atomic_raw:true together with confirm.',
		data: {
			stored_architecture: stored.architecture,
			incoming_architecture: incoming.architecture,
			fix: ['use_plugin_mode', 'allow_atomic_raw_with_confirm'],
		},
	};
}
