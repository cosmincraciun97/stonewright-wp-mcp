const EFFECTS = new Set([
	'fade-in',
	'fade-up',
	'slide-in-inline',
	'scale-in-subtle',
	'card-lift',
	'link-underline',
	'stagger-reveal',
]);

type JsonObject = Record<string, unknown>;

export interface MotionSemanticError {
	code: string;
	path: string;
}

interface MotionEntry {
	item: JsonObject;
	section: JsonObject;
	block: JsonObject | null;
	path: string;
}

function objects(value: unknown): JsonObject[] {
	return Array.isArray(value)
		? value.filter((entry): entry is JsonObject => typeof entry === 'object' && entry !== null && !Array.isArray(entry))
		: [];
}

function entries(spec: JsonObject): MotionEntry[] {
	const result: MotionEntry[] = [];
	objects(spec.sections).forEach((section, sectionIndex) => {
		objects(section.motion).forEach((item, motionIndex) => {
			result.push({ item, section, block: null, path: `/sections/${sectionIndex}/motion/${motionIndex}` });
		});
		objects(section.blocks).forEach((block, blockIndex) => {
			objects(block.motion).forEach((item, motionIndex) => {
				result.push({ item, section, block, path: `/sections/${sectionIndex}/blocks/${blockIndex}/motion/${motionIndex}` });
			});
		});
	});
	return result;
}

function idCount(spec: JsonObject, id: string): number {
	let count = 0;
	for (const section of objects(spec.sections)) {
		if (String(section.id ?? '') === id) count++;
		for (const block of objects(section.blocks)) {
			if (String(block.id ?? '') === id) count++;
		}
	}
	return count;
}

function scopedTargetCount(entry: MotionEntry, id: string): number {
	if (entry.block) return String(entry.block.id ?? '') === id ? 1 : 0;
	let count = String(entry.section.id ?? '') === id ? 1 : 0;
	for (const block of objects(entry.section.blocks)) {
		if (String(block.id ?? '') === id) count++;
	}
	return count;
}

/** Mirrors the plugin checks that JSON Schema cannot express. */
export function validateMotionSemantics(spec: JsonObject): MotionSemanticError[] {
	const motion = entries(spec);
	const errors: MotionSemanticError[] = [];
	const seen = new Set<string>();

	for (const entry of motion) {
		const id = String(entry.item.id ?? '');
		const target = String(entry.item.target_id ?? '');
		const effect = String(entry.item.effect ?? '');
		if (seen.has(id)) errors.push({ code: 'motion_duplicate_id', path: entry.path });
		seen.add(id);
		if (!EFFECTS.has(effect)) errors.push({ code: 'motion_effect_unknown', path: entry.path });
		if (effect === 'stagger-reveal' && typeof entry.item.stagger !== 'object') {
			errors.push({ code: 'motion_stagger_required', path: entry.path });
		}

		const targets = scopedTargetCount(entry, target);
		if (targets === 0) errors.push({ code: 'motion_target_missing', path: entry.path });
		if (targets > 1) errors.push({ code: 'motion_target_ambiguous', path: entry.path });

		if (entry.item.playback === 'loop') {
			if (entry.item.purpose === 'decorative') errors.push({ code: 'motion_loop_decoration', path: entry.path });
			const controlId = String(entry.item.control_target_id ?? '');
			if (!controlId || !String(entry.item.control_label ?? '')) {
				errors.push({ code: 'motion_loop_requires_control', path: entry.path });
			} else if (idCount(spec, controlId) === 0) {
				errors.push({ code: 'motion_loop_control_missing', path: entry.path });
			}
		}

		if (entry.item.engine === 'provider' && !String(entry.item.provider_id ?? '')) {
			errors.push({ code: 'motion_provider_id_required', path: entry.path });
		}

		const stagger = entry.item.stagger;
		if (typeof stagger === 'object' && stagger !== null && !Array.isArray(stagger)) {
			const value = stagger as JsonObject;
			const count = Array.isArray(value.target_ids) ? value.target_ids.length : 0;
			const interval = Number(value.interval_ms ?? 0);
			const span = Number(value.span_ms ?? 0);
			if (count >= 2 && interval * (count - 1) > span) {
				errors.push({ code: 'motion_stagger_span_exceeded', path: entry.path });
			}
		}
	}

	for (const entry of motion) {
		if (entry.item.trigger !== 'hover') continue;
		const twin = motion.some((candidate) =>
			candidate.item.trigger === 'focus-visible'
			&& String(candidate.item.target_id ?? '') === String(entry.item.target_id ?? '')
			&& String(candidate.item.effect ?? '') === String(entry.item.effect ?? '')
		);
		if (!twin) errors.push({ code: 'motion_hover_focus_parity', path: entry.path });
	}

	return errors;
}
