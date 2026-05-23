import { describe, it, expect } from 'vitest';
import { validateCommand, WPCLI_ALLOWLIST } from '../src/wpcli.js';

describe('validateCommand', () => {
	it('allows every command in the allowlist', () => {
		for (const cmd of WPCLI_ALLOWLIST) {
			expect(validateCommand(cmd)).toBeNull();
			expect(validateCommand(`${cmd} some-subcommand --flag`)).toBeNull();
		}
	});

	it('rejects commands not in the allowlist', () => {
		expect(validateCommand('eval')).toContain('not permitted');
		expect(validateCommand('shell')).toContain('not permitted');
		expect(validateCommand('wp')).toContain('not permitted');
	});

	it('rejects empty command', () => {
		expect(validateCommand('')).toContain('not permitted');
		expect(validateCommand('   ')).toContain('not permitted');
	});

	it('only checks the first token', () => {
		expect(validateCommand('option get siteurl')).toBeNull();
		expect(validateCommand('eval option get siteurl')).toContain('not permitted');
	});
});
