import { describe, expect, it } from 'vitest';
import { serializeDesignSpec } from '../src/direct/gutenberg-serializer.js';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

describe('serializeDesignSpec', () => {
	it('emits heading paragraph image button groups from construction blueprint', () => {
		const raw = JSON.parse(
			readFileSync(join(process.cwd(), 'blueprints/construction.json'), 'utf8'),
		) as { spec: Parameters<typeof serializeDesignSpec>[0] };
		const markup = serializeDesignSpec(raw.spec);
		expect(markup).toContain('<!-- wp:heading');
		expect(markup).toContain('<!-- wp:paragraph');
		expect(markup).toContain('<!-- wp:image');
		expect(markup).toContain('<!-- wp:button');
		expect(markup).toContain('<!-- wp:group');
		expect(markup.length).toBeGreaterThan(500);
	});
});
