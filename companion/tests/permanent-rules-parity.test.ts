import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	CANONICAL_OPERATING_RULES,
	canonicalRulesFingerprint,
	DIRECT_PERMANENT_RULES,
	permanentRulesGuidance,
} from '../src/direct/permanent-rules.js';

const PLUGIN_POLICY = resolve(
	import.meta.dirname,
	'../../plugin/includes/Core/McpUsePolicy.php',
);

describe('permanent rules parity', () => {
	it('exports eight canonical rules', () => {
		expect(Object.keys(CANONICAL_OPERATING_RULES)).toHaveLength(8);
		expect(CANONICAL_OPERATING_RULES.verified_learning).toMatch(/verified:true/);
		expect(CANONICAL_OPERATING_RULES.elementor_responsive_preview).toMatch(
			/Never resize the whole editor browser window/,
		);
		expect(CANONICAL_OPERATING_RULES.separate_verification_tab).toMatch(
			/verification_page/,
		);
		expect(CANONICAL_OPERATING_RULES.design_section_isolation).toMatch(
			/section manifest/,
		);
		expect(CANONICAL_OPERATING_RULES.breakpoint_isolation).toMatch(
			/unsupported_responsive_control/,
		);
		expect(CANONICAL_OPERATING_RULES.fastest_safe_interface).toMatch(/typed_api/);
		expect(CANONICAL_OPERATING_RULES.custom_code_operator_grant).toMatch(
			/custom-code grant/,
		);
	});

	it('includes canonical rules in Direct permanent list and guidance', () => {
		for (const text of Object.values(CANONICAL_OPERATING_RULES)) {
			expect(DIRECT_PERMANENT_RULES).toContain(text);
			expect(permanentRulesGuidance().some((g) => g.includes(text))).toBe(true);
		}
	});

	it('matches plugin PHP rule texts byte-for-byte', () => {
		const php = readFileSync(PLUGIN_POLICY, 'utf8');
		for (const [id, text] of Object.entries(CANONICAL_OPERATING_RULES)) {
			expect(php, `missing id ${id}`).toContain(`'${id}'`);
			// PHP single-quoted strings escape ' as \'
			const phpLiteral = text.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
			expect(php, `drift on ${id}`).toContain(phpLiteral);
		}
		const expectedFp = createHash('sha256')
			.update(
				Object.keys(CANONICAL_OPERATING_RULES)
					.sort()
					.map((k) => CANONICAL_OPERATING_RULES[k])
					.join('\n'),
			)
			.digest('hex');
		expect(canonicalRulesFingerprint()).toBe(expectedFp);
	});

	it('never brands a specific site', () => {
		const blob = DIRECT_PERMANENT_RULES.join('\n').toLowerCase();
		expect(blob).not.toContain('transavia');
	});
});
