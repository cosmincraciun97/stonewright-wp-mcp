import { describe, expect, it } from 'vitest';
import { parseCommandRecipe, isPlaceholderToken } from '../src/commands/schema.js';
import { classifyWpCliRisk, validateRecipeWpCliCommand, CommandError } from '../src/commands/risk.js';
import { MAX_RECIPE_BYTES } from '../src/commands/limits.js';
import type { CommandRecipeV1 } from '../src/commands/types.js';

function baseRecipe(overrides: Partial<CommandRecipeV1> = {}): CommandRecipeV1 {
	return {
		schema_version: 1,
		slug: 'check-core',
		title: 'Check core version',
		description: 'Read-only core version check.',
		parameters: {},
		steps: [
			{ id: 'version', kind: 'wp_cli', argv: ['core', 'version'] },
		],
		...overrides,
	};
}

describe('command recipe schema v1', () => {
	it('accepts a valid read-only recipe', () => {
		const parsed = parseCommandRecipe(baseRecipe());
		expect(parsed.ok).toBe(true);
	});

	it('rejects unknown top-level fields', () => {
		const recipe = { ...baseRecipe(), extra: true };
		const parsed = parseCommandRecipe(recipe);
		expect(parsed.ok).toBe(false);
		if (!parsed.ok) expect(parsed.error).toContain('Invalid recipe');
	});

	it('rejects unknown step fields', () => {
		const recipe = baseRecipe({
			steps: [{ id: 'a', kind: 'wp_cli', argv: ['core', 'version'], shell: '/bin/sh' } as never],
		});
		expect(parseCommandRecipe(recipe).ok).toBe(false);
	});

	it('rejects invalid slug and oversize recipes', () => {
		expect(parseCommandRecipe(baseRecipe({ slug: 'Bad_Slug' })).ok).toBe(false);
		const huge = baseRecipe({ description: 'x'.repeat(MAX_RECIPE_BYTES + 10) });
		const result = parseCommandRecipe(huge);
		expect(result.ok).toBe(false);
		if (!result.ok) expect(result.error).toContain('bytes');
	});

	it('rejects more than 25 steps and more than 64 argv tokens', () => {
		const manySteps = baseRecipe({
			steps: Array.from({ length: 26 }, (_, i) => ({
				id: `step-${i}`,
				kind: 'wp_cli' as const,
				argv: ['core', 'version'],
			})),
		});
		expect(parseCommandRecipe(manySteps).ok).toBe(false);

		const longArgv = baseRecipe({
			steps: [{ id: 'long', kind: 'wp_cli', argv: ['post', 'list', ...Array.from({ length: 70 }, (_, i) => `--field${i}`)] }],
		});
		expect(parseCommandRecipe(longArgv).ok).toBe(false);
	});

	it('rejects partial placeholder interpolation and unknown parameters', () => {
		const partial = baseRecipe({
			parameters: { name: { type: 'string' } },
			steps: [{ id: 'p', kind: 'wp_cli', argv: ['post', 'list', '--name={{name}}-suffix'] }],
		});
		expect(parseCommandRecipe(partial).ok).toBe(false);

		const unknown = baseRecipe({
			steps: [{ id: 'u', kind: 'wp_cli', argv: ['post', 'get', '{{missing}}'] }],
		});
		expect(parseCommandRecipe(unknown).ok).toBe(false);
	});

	it('requires write recipes to end with a verified read step', () => {
		const badWrite = baseRecipe({
			steps: [
				{ id: 'flush', kind: 'wp_cli', argv: ['cache', 'flush'] },
			],
		});
		expect(parseCommandRecipe(badWrite).ok).toBe(false);

		const goodWrite = baseRecipe({
			steps: [
				{ id: 'flush', kind: 'wp_cli', argv: ['cache', 'flush'] },
				{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		});
		expect(parseCommandRecipe(goodWrite).ok).toBe(true);
	});

	it('recognizes whole-token placeholders only', () => {
		expect(isPlaceholderToken('{{name}}')).toBe(true);
		expect(isPlaceholderToken('{{name}}-suffix')).toBe(false);
		expect(isPlaceholderToken('pre-{{name}}')).toBe(false);
	});
});

describe('wp-cli risk classification', () => {
	it('classifies allowlisted reads as read', () => {
		expect(classifyWpCliRisk(['core', 'version'])).toBe('read');
		expect(classifyWpCliRisk(['post', 'list', '--format=json'])).toBe('read');
		expect(classifyWpCliRisk(['plugin', 'status'])).toBe('read');
		expect(classifyWpCliRisk(['db', 'check'])).toBe('read');
	});

	it('classifies unknown commands as write (fail closed)', () => {
		expect(classifyWpCliRisk(['cache', 'flush'])).toBe('write');
		expect(classifyWpCliRisk(['option', 'get', 'blogname'])).toBe('write');
		expect(classifyWpCliRisk(['db', 'query', 'SELECT 1'])).toBe('write');
		expect(classifyWpCliRisk(['user', 'meta', 'get', '1', 'foo'])).toBe('write');
	});

	it('refuses eval-style groups and flags', () => {
		for (const group of ['eval', 'eval-file', 'shell', 'package']) {
			expect(() => validateRecipeWpCliCommand([group, 'code'])).toThrow(CommandError);
		}
		for (const flag of ['--exec', '--require', '--prompt']) {
			expect(() => validateRecipeWpCliCommand(['core', 'version', flag])).toThrow(CommandError);
			expect(() => validateRecipeWpCliCommand(['core', 'version', `${flag}=x`])).toThrow(CommandError);
		}
	});

	it('refuses target overrides in both forms and config explicitly', () => {
		for (const override of ['--path', '--url', '--user', '--context', '--ssh', '--http']) {
			expect(() => validateRecipeWpCliCommand([override, '/tmp'])).toThrow(CommandError);
			expect(() => validateRecipeWpCliCommand([`${override}=/tmp`])).toThrow(CommandError);
		}
		try {
			validateRecipeWpCliCommand(['config', 'get', 'table_prefix']);
			expect.unreachable('config must be refused');
		} catch (err) {
			expect((err as CommandError).code).toBe('command_config_refused');
		}
	});
});
