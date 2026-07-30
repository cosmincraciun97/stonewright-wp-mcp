import { describe, expect, it, beforeEach } from 'vitest';
import {
	escalateDirectError,
	noteDirectErrorOccurrence,
	resetDirectErrorOccurrencesForTests,
} from '../src/direct/audit.js';

describe('direct error escalation', () => {
	beforeEach(() => {
		resetDirectErrorOccurrencesForTests();
	});

	it('prepends STOP guidance after two identical failures', () => {
		const first = escalateDirectError(
			'stonewright-elementor-data-update',
			{ ok: false, error: 'x_failed', message: 'Update failed.' },
			1,
		);
		expect(first.message).not.toContain('STOP');

		const third = escalateDirectError(
			'stonewright-elementor-data-update',
			{ ok: false, error: 'x_failed', message: 'Update failed.' },
			3,
		);
		expect(third.message).toContain('STOP');
		expect(third.occurrences).toBe(3);
		expect(third.repair).toBeTruthy();
		expect(third.message).toContain('Next step:');
	});

	it('tracks in-process occurrences for identical signatures', () => {
		const a = noteDirectErrorOccurrence(
			'stonewright-elementor-data-update',
			'integrity_blocked',
			'Double-encoded JSON',
		);
		const b = noteDirectErrorOccurrence(
			'stonewright-elementor-data-update',
			'integrity_blocked',
			'Double-encoded JSON',
		);
		const c = noteDirectErrorOccurrence(
			'stonewright-elementor-data-update',
			'other',
			'Different',
		);
		expect(a).toBe(1);
		expect(b).toBe(2);
		expect(c).toBe(1);

		const escalated = escalateDirectError(
			'stonewright-elementor-data-update',
			{ ok: false, error: 'integrity_blocked', message: 'Double-encoded JSON' },
			b,
		);
		expect(escalated.message).toContain('STOP');
		expect(escalated.occurrences).toBe(2);
	});
});
