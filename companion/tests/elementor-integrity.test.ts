import { describe, expect, it } from 'vitest';
import {
	assertWriteAllowed,
	normalizeToTree,
	widgetTypeRemaps,
} from '../src/direct/elementor-integrity.js';

describe('Direct Elementor integrity gate', () => {
	it('rejects double-encoded string payload', () => {
		const tree = [{ id: 'a1', elType: 'container', elements: [] }];
		const double = JSON.stringify(JSON.stringify(tree));
		const result = normalizeToTree(double);
		expect(result.ok).toBe(false);
		if (!result.ok) {
			expect(result.error_code).toBe('stonewright_elementor_double_encoded');
		}
	});

	it('rejects double-encoded tree wrapper', () => {
		const tree = [{ id: 'a1', elType: 'container', elements: [] }];
		const result = assertWriteAllowed([JSON.stringify(tree)], []);
		expect(result.ok).toBe(false);
		if (!result.ok) {
			expect(result.error_code).toBe('stonewright_elementor_double_encoded');
		}
	});

	it('rejects size collapse', () => {
		const previous = Array.from({ length: 40 }, (_, i) => ({
			id: `id${i}xxx`.slice(0, 7),
			elType: 'widget',
			widgetType: 'heading',
			settings: { title: 'Hello world '.repeat(15) },
			elements: [],
		}));
		const incoming = [
			{
				id: 'id0xxxx'.slice(0, 7),
				elType: 'widget',
				widgetType: 'heading',
				settings: { title: 'x' },
				elements: [],
			},
		];
		const result = assertWriteAllowed(incoming, previous);
		expect(result.ok).toBe(false);
		if (!result.ok) {
			expect(result.error_code).toBe('stonewright_elementor_size_collapse');
		}
	});

	it('rejects widget type remaps', () => {
		const previous = [
			{
				id: 'abc1234',
				elType: 'widget',
				widgetType: 'e-paragraph',
				settings: { paragraph: 'hi' },
				elements: [],
			},
		];
		const incoming = [
			{
				id: 'abc1234',
				elType: 'widget',
				widgetType: 'text-editor',
				settings: { editor: '&nbsp;' },
				elements: [],
			},
		];
		expect(widgetTypeRemaps(previous, incoming)).toHaveLength(1);
		const result = assertWriteAllowed(incoming, previous);
		expect(result.ok).toBe(false);
		if (!result.ok) {
			expect(result.error_code).toBe('stonewright_elementor_widget_type_remap_blocked');
		}
	});

	it('allows same-type surgical patch', () => {
		const tree = [
			{
				id: 'abc1234',
				elType: 'widget',
				widgetType: 'loop-grid',
				settings: { offset_sides: 'right', offset_width: 80 },
				elements: [],
			},
		];
		expect(assertWriteAllowed(tree, tree).ok).toBe(true);
	});
});
