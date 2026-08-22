import Ajv2020 from 'ajv/dist/2020';
import type { ValidateFunction } from 'ajv';
import { describe, expect, it } from 'vitest';
import schema from '../src/contracts/design-spec.schema.json';
import { validateMotionSemantics } from '../src/contracts/design-spec-motion.js';

/**
 * Schema-level parity with the plugin's stonewright.schema.v2.json motion
 * contract: the same payloads are accepted or rejected on both sides.
 *
 * Semantic checks that JSON Schema cannot express are mirrored by the
 * companion validator so Direct and plugin accept/reject the same payloads.
 */
function compile(): ValidateFunction<Record<string, unknown>> {
	const ajv = new Ajv2020({ strict: false, allErrors: true });
	return ajv.compile(schema);
}

function validateSpec(payload: Record<string, unknown>): boolean {
	return compile()({ spec: payload }) && validateMotionSemantics(payload).length === 0;
}

function spec(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		version: '2.0.0',
		page: { title: 'Hero page' },
		sections: [
			{
				id: 'hero',
				blocks: [{ id: 'hero-copy', type: 'paragraph', text: 'Real copy text.' }],
			},
		],
		...overrides,
	};
}

const motionItem = {
	id: 'hero-copy-enter',
	purpose: 'orient',
	target_id: 'hero-copy',
	trigger: 'viewport-enter',
	effect: 'fade-up',
	duration: 'standard',
	delay_ms: 0,
	playback: 'once',
	engine: 'auto',
	reduced_motion: 'replace-with-fade',
};

const motionPolicy = {
	level: 'subtle',
	durations: { instant: 0, fast: 160, standard: 280, slow: 480 },
	easings: { standard: 'standard', enter: 'decelerate', exit: 'accelerate' },
	distances: { small: 8, medium: 16, large: 32 },
	max_concurrent: 3,
	reduced_motion: 'replace_nonessential',
};

function sectionWithMotion(motion: unknown[]): Record<string, unknown> {
	return {
		sections: [
			{
				id: 'hero',
				blocks: [{ id: 'hero-copy', type: 'paragraph', text: 'Real copy text.' }],
				motion,
			},
		],
	};
}

describe('design-spec motion contract (companion parity)', () => {
	it('accepts a valid spec with policy, section motion, and block motion', () => {
		const payload = spec({
			motion_policy: motionPolicy,
			sections: [
				{
					id: 'hero',
					motion: [motionItem],
					blocks: [{ id: 'hero-copy', type: 'paragraph', text: 'Real copy text.', motion: [{ ...motionItem, id: 'hero-copy-enter-block' }] }],
				},
			],
		});

		expect(validateSpec(payload)).toBe(true);
	});

	it('accepts a spec without motion (full backward compatibility)', () => {
		expect(validateSpec(spec())).toBe(true);
	});

	it('rejects an unknown top-level property', () => {
		expect(validateSpec(spec({ motion_polic: motionPolicy }))).toBe(false);
	});

	it('rejects a motion item missing required fields', () => {
		const incomplete = { id: 'incomplete', target_id: 'hero-copy' };
		expect(validateSpec(spec(sectionWithMotion([incomplete])))).toBe(false);
	});

	it('rejects every trigger outside the allowlist', () => {
		for (const trigger of ['scroll-by', 'appear', 'LOAD', '']) {
			const item = { ...motionItem, trigger };
			expect(validateSpec(spec(sectionWithMotion([item])))).toBe(false);
		}
	});

	it('rejects delay_ms above the bound but accepts the boundary value', () => {
		const over = { ...motionItem, delay_ms: 2001 };
		const edge = { ...motionItem, delay_ms: 2000 };
		expect(validateSpec(spec(sectionWithMotion([over])))).toBe(false);
		expect(validateSpec(spec(sectionWithMotion([edge])))).toBe(true);
	});

	it('rejects a raw duration above the bound', () => {
		const item = { ...motionItem, duration: 3001 };
		expect(validateSpec(spec(sectionWithMotion([item])))).toBe(false);
	});

	it('rejects duplicate device entries', () => {
		const item = { ...motionItem, devices: ['desktop', 'desktop'] };
		expect(validateSpec(spec(sectionWithMotion([item])))).toBe(false);
	});

	it('rejects a stagger list under two targets or over twelve', () => {
		const tooFew = {
			...motionItem,
			stagger: { target_ids: ['a'], interval_ms: 10, span_ms: 100 },
		};
		const tooMany = {
			...motionItem,
			stagger: {
				target_ids: Array.from({ length: 13 }, (_, i) => `t${i}`),
				interval_ms: 10,
				span_ms: 1000,
			},
		};
		expect(validateSpec(spec(sectionWithMotion([tooFew])))).toBe(false);
		expect(validateSpec(spec(sectionWithMotion([tooMany])))).toBe(false);
	});

	it('rejects semantic drift: unresolved target, duplicate id, and missing focus twin', () => {
		const hover = { ...motionItem, id: 'same', target_id: 'missing', trigger: 'hover', effect: 'card-lift' };
		const duplicate = { ...motionItem, id: 'same' };
		const payload = spec(sectionWithMotion([hover, duplicate]));

		expect(validateSpec(payload)).toBe(false);
		expect(validateMotionSemantics(payload).map((error) => error.code)).toEqual(expect.arrayContaining([
			'motion_target_missing',
			'motion_duplicate_id',
			'motion_hover_focus_parity',
		]));
	});

	it('accepts hover only when an equivalent focus-visible item exists', () => {
		const hover = { ...motionItem, id: 'card-hover', trigger: 'hover', effect: 'card-lift', reduced_motion: 'static-end-state' };
		const focus = { ...hover, id: 'card-focus', trigger: 'focus-visible' };
		expect(validateSpec(spec(sectionWithMotion([hover, focus])))).toBe(true);
	});

	it('rejects loop/provider/stagger semantic violations', () => {
		const loop = { ...motionItem, id: 'loop', playback: 'loop', purpose: 'decorative' };
		const provider = { ...motionItem, id: 'provider', engine: 'provider' };
		const stagger = {
			...motionItem,
			id: 'stagger',
			effect: 'stagger-reveal',
			stagger: { target_ids: ['a', 'b', 'c'], interval_ms: 250, span_ms: 100 },
		};
		const errors = validateMotionSemantics(spec(sectionWithMotion([loop, provider, stagger]))).map((error) => error.code);
		expect(errors).toEqual(expect.arrayContaining([
			'motion_loop_decoration',
			'motion_loop_requires_control',
			'motion_provider_id_required',
			'motion_stagger_span_exceeded',
		]));
	});
});
