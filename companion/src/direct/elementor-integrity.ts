/**
 * P0 Elementor document integrity gate for Direct mode writes.
 * Mirrors plugin DocumentIntegrityGate rules (no schema — structure only).
 */

export const MIN_SIZE_RATIO = 0.85;

export type IntegrityOptions = {
	force_destructive?: boolean;
	allow_widget_type_remap?: boolean;
	min_size_ratio?: number;
};

export type IntegrityError = {
	ok: false;
	error_code: string;
	message: string;
	data?: Record<string, unknown>;
};

export type IntegrityOk = { ok: true };

export type IntegrityResult = IntegrityOk | IntegrityError;

function jsonBytes(value: unknown): number {
	if (typeof value === 'string') return Buffer.byteLength(value, 'utf8');
	try {
		return Buffer.byteLength(JSON.stringify(value), 'utf8');
	} catch {
		return 0;
	}
}

function looksDoubleEncodedTree(tree: unknown[]): boolean {
	if (tree.length !== 1 || typeof tree[0] !== 'string') {
		return false;
	}
	try {
		const d: unknown = JSON.parse(tree[0]);
		return Array.isArray(d) || (typeof d === 'object' && d !== null);
	} catch {
		return false;
	}
}

function walk(tree: unknown[], visitor: (el: Record<string, unknown>) => void): void {
	for (const el of tree) {
		if (!el || typeof el !== 'object' || Array.isArray(el)) continue;
		const node = el as Record<string, unknown>;
		visitor(node);
		const kids = node['elements'];
		if (Array.isArray(kids)) walk(kids, visitor);
	}
}

export function widgetTypeMap(tree: unknown[]): Record<string, string> {
	const map: Record<string, string> = {};
	walk(tree, (el) => {
		const id = typeof el['id'] === 'string' || typeof el['id'] === 'number' ? String(el['id']).trim() : '';
		if (!id) return;
		if (String(el['elType'] ?? '') === 'widget') {
			map[id] = String(el['widgetType'] ?? '');
		}
	});
	return map;
}

export function widgetTypeRemaps(
	previous: unknown[],
	incoming: unknown[],
): Array<{ id: string; from: string; to: string }> {
	const prev = widgetTypeMap(previous);
	const next = widgetTypeMap(incoming);
	const remaps: Array<{ id: string; from: string; to: string }> = [];
	for (const [id, from] of Object.entries(prev)) {
		const to = next[id];
		if (to !== undefined && from !== to && from !== '' && to !== '') {
			remaps.push({ id, from, to });
		}
	}
	return remaps;
}

function assertBasicStructure(tree: unknown[], path: string): IntegrityResult {
	for (let i = 0; i < tree.length; i += 1) {
		const el = tree[i];
		const p = `${path}.${i}`;
		if (!el || typeof el !== 'object' || Array.isArray(el)) {
			return {
				ok: false,
				error_code: 'stonewright_elementor_integrity_invalid_element',
				message: 'Each Elementor tree node must be an object.',
				data: { path: p },
			};
		}
		const node = el as Record<string, unknown>;
		const id = typeof node['id'] === 'string' || typeof node['id'] === 'number' ? String(node['id']).trim() : '';
		if (!id) {
			return {
				ok: false,
				error_code: 'stonewright_elementor_integrity_missing_id',
				message: 'Every Elementor node needs a non-empty id.',
				data: { path: `${p}.id` },
			};
		}
		const elType = String(node['elType'] ?? '');
		if (!elType) {
			return {
				ok: false,
				error_code: 'stonewright_elementor_integrity_missing_eltype',
				message: 'Every Elementor node needs elType.',
				data: { path: `${p}.elType`, id },
			};
		}
		if (elType === 'widget') {
			const wt = String(node['widgetType'] ?? '');
			if (!wt) {
				return {
					ok: false,
					error_code: 'stonewright_elementor_integrity_missing_widget_type',
					message: 'Widget nodes need widgetType.',
					data: { path: `${p}.widgetType`, id },
				};
			}
		}
		const kids = node['elements'];
		if (kids !== undefined) {
			if (!Array.isArray(kids)) {
				return {
					ok: false,
					error_code: 'stonewright_elementor_integrity_invalid_children',
					message: 'elements must be an array.',
					data: { path: `${p}.elements`, id },
				};
			}
			const child = assertBasicStructure(kids, `${p}.elements`);
			if (!child.ok) return child;
		}
	}
	return { ok: true };
}

/**
 * Normalize incoming data to an array tree or fail.
 */
export function normalizeToTree(data: string | unknown[] | Record<string, unknown>): IntegrityResult & { tree?: unknown[] } {
	if (Array.isArray(data)) {
		if (looksDoubleEncodedTree(data)) {
			return {
				ok: false,
				error_code: 'stonewright_elementor_double_encoded',
				message: 'Elementor document appears double-encoded JSON. Decode once before write.',
				data: { fix: ['json_decode_once'] },
			};
		}
		return { ok: true, tree: data };
	}
	if (typeof data === 'string') {
		const trim = data.trim();
		let once: unknown;
		try {
			once = JSON.parse(trim);
		} catch {
			return {
				ok: false,
				error_code: 'stonewright_elementor_integrity_invalid_json',
				message: 'Elementor meta string is not valid JSON.',
			};
		}
		if (typeof once === 'string') {
			try {
				const twice: unknown = JSON.parse(once);
				if (Array.isArray(twice) || (typeof twice === 'object' && twice !== null)) {
					return {
						ok: false,
						error_code: 'stonewright_elementor_double_encoded',
						message: 'Elementor meta string is double-encoded JSON. Decode once before write.',
						data: { fix: ['json_decode_once'] },
					};
				}
			} catch {
				// not double
			}
			return {
				ok: false,
				error_code: 'stonewright_elementor_integrity_not_array',
				message: 'Decoded JSON must be an array tree.',
			};
		}
		if (!Array.isArray(once)) {
			return {
				ok: false,
				error_code: 'stonewright_elementor_integrity_not_list',
				message: 'Elementor document root must be a JSON list of elements.',
			};
		}
		return { ok: true, tree: once };
	}
	return {
		ok: false,
		error_code: 'stonewright_elementor_integrity_not_array',
		message: 'Elementor document must be a JSON array tree, not an object map.',
	};
}

export function assertWriteAllowed(
	incoming: unknown[],
	previous: unknown[] = [],
	options: IntegrityOptions = {},
): IntegrityResult {
	if (looksDoubleEncodedTree(incoming)) {
		return {
			ok: false,
			error_code: 'stonewright_elementor_double_encoded',
			message: 'Elementor document appears double-encoded JSON. Decode once before write.',
		};
	}
	const structure = assertBasicStructure(incoming, 'root');
	if (!structure.ok) return structure;

	const force = Boolean(options.force_destructive);
	const allowRemap = Boolean(options.allow_widget_type_remap);
	let ratio = options.min_size_ratio ?? MIN_SIZE_RATIO;
	if (ratio <= 0 || ratio > 1) ratio = MIN_SIZE_RATIO;

	const prevBytes = jsonBytes(previous);
	const nextBytes = jsonBytes(incoming);
	if (!force && prevBytes > 2048 && nextBytes < Math.floor(prevBytes * ratio)) {
		return {
			ok: false,
			error_code: 'stonewright_elementor_size_collapse',
			message: 'Incoming Elementor document is much smaller than the existing one. Refusing silent layout strip.',
			data: {
				previous_bytes: prevBytes,
				incoming_bytes: nextBytes,
				min_size_ratio: ratio,
				fix: ['use_surgical_patch', 'do_not_strip_unknown_settings'],
			},
		};
	}

	if (!allowRemap && previous.length > 0) {
		const remaps = widgetTypeRemaps(previous, incoming);
		if (remaps.length > 0) {
			return {
				ok: false,
				error_code: 'stonewright_elementor_widget_type_remap_blocked',
				message:
					'Widget type changes on existing element ids are blocked. Do not convert e-paragraph/text-editor to pass validation.',
				data: {
					remaps: remaps.slice(0, 20),
					count: remaps.length,
					fix: ['keep_original_widgetType', 'path_settings_patch_only'],
				},
			};
		}
	}

	return { ok: true };
}

export function encodeTreeOnce(tree: unknown[]): string {
	return JSON.stringify(tree);
}
