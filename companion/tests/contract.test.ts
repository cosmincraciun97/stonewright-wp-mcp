/**
 * Contract schema tests.
 *
 * For each endpoint, validates that request/response fixture objects conform
 * to the JSON Schema files in companion/src/contracts/.
 * Uses Ajv (draft-07 mode) since json-schema-to-typescript uses draft-07 refs.
 *
 * Phase 6 addition: covers design-spec.schema.json and figma-ingest.schema.json.
 */

import { describe, it, expect, beforeAll } from 'vitest';
import Ajv from 'ajv';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const contractsDir = join(__dirname, '..', 'src', 'contracts');

/** Load a schema and normalise $defs → definitions for Ajv draft-07 */
function loadSchema(endpoint: string): Record<string, unknown> {
  const raw = readFileSync(join(contractsDir, `${endpoint}.schema.json`), 'utf8');
  // draft-2020-12 uses $defs; Ajv (draft-07) uses definitions
  return JSON.parse(
    raw
      .replace(/"\$defs"/g, '"definitions"')
      .replace(/#\/\$defs\//g, '#/definitions/'),
  ) as Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// Minimal valid DesignSpec for use in figma-ingest fixture
// ---------------------------------------------------------------------------

const VALID_DESIGN_SPEC = {
	version: '1.0.0',
	page: { title: 'Test Page' },
	sections: [
		{
			id: 'section_0',
			blocks: [
				{ type: 'heading', text: 'Hello World', level: 1 },
			],
		},
	],
};

// ---------------------------------------------------------------------------
// Fixtures (valid and invalid) per endpoint
// ---------------------------------------------------------------------------

const UUID = '550e8400-e29b-41d4-a716-446655440000';
const ARTIFACT_PATH = '/tmp/wp-content/uploads/stonewright-qa/550e8400-e29b-41d4-a716-446655440000';

const FIXTURES = {
  screenshot: {
    validRequest: {
      request_id: UUID,
      url: 'https://example.com/page',
      artifact_path: ARTIFACT_PATH,
      viewport: { width: 1440, height: 900 },
      full_page: true,
      wait_ms: 500,
    },
    invalidRequest: { url: 'https://example.com' }, // missing request_id + artifact_path
    validResponse: {
      request_id: UUID,
      artifact_id: `${ARTIFACT_PATH}/screenshot-${UUID}.png`,
      path: `${ARTIFACT_PATH}/screenshot-${UUID}.png`,
      url: 'https://example.com/wp-content/uploads/stonewright-qa/screenshot.png',
      width: 1440,
      height: 900,
      viewport: { width: 1440, height: 900 },
      created_at: '2026-05-22T00:00:00.000Z',
    },
    invalidResponse: { request_id: UUID }, // missing required fields
  },
  diff: {
    validRequest: {
      request_id: UUID,
      reference_artifact_id: `${ARTIFACT_PATH}/ref.png`,
      actual_artifact_id: `${ARTIFACT_PATH}/actual.png`,
      artifact_path: ARTIFACT_PATH,
      threshold: 0.05,
    },
    invalidRequest: { request_id: UUID }, // missing artifact IDs and path
    validResponse: {
      request_id: UUID,
      needs_reference: false,
      diff_ratio: 0.001,
      passed: true,
      threshold: 0.05,
      diff_url: `${ARTIFACT_PATH}/diff.png`,
      mismatch_regions: [],
    },
    invalidResponse: {}, // missing all required fields
  },
  axe: {
    validRequest: {
      request_id: UUID,
      url: 'https://example.com/page',
      ruleset: 'wcag2aa',
    },
    invalidRequest: { ruleset: 'wcag2aa' }, // missing request_id and url
    validResponse: {
      request_id: UUID,
      violations: [],
      passes_count: 42,
    },
    invalidResponse: { request_id: UUID, passes_count: 'not-an-int' }, // wrong type
  },
  layout: {
    validRequest: {
      request_id: UUID,
      url: 'https://example.com/page',
      viewport: { width: 1440, height: 900 },
    },
    invalidRequest: { viewport: { width: 1440, height: 900 } }, // missing request_id and url
    validResponse: {
      request_id: UUID,
      sections: [{ name: 'header', tag: 'header', rect: { x: 0, y: 0, width: 1440, height: 80 } }],
      alignment_diffs: [],
      has_horizontal_overflow: false,
      has_element_overlap: false,
    },
    invalidResponse: { request_id: UUID, sections: [] }, // missing 3 required fields
  },
  lighthouse: {
    validRequest: {
      request_id: UUID,
      url: 'https://example.com/page',
      categories: ['performance', 'accessibility'],
    },
    invalidRequest: { categories: ['performance'] }, // missing request_id and url
    validResponse: {
      request_id: UUID,
      available: true,
      scores: { performance: 0.98, accessibility: 1.0 },
      report_url: `${ARTIFACT_PATH}/report.html`,
      audits_failed: [],
    },
    invalidResponse: { available: 'yes' }, // wrong type, missing request_id
  },
  health: {
    validRequest: {},
    invalidRequest: null, // any non-object input; health request has no required fields
    validResponse: {
      status: 'ok',
      contract_version: '1.0.0',
    },
    invalidResponse: { status: 'degraded' }, // const violation + missing contract_version
  },
} as const;

// ---------------------------------------------------------------------------
// Phase 6: figma-ingest schema tests (separate describe block — uses $ref
// to design-spec.schema.json so we load both schemas into Ajv).
// ---------------------------------------------------------------------------

describe('figma-ingest schema (Phase 6)', () => {
  let ajv: Ajv;

  beforeAll(() => {
    // No allErrors — oneOf with many Block branches reports non-matching branch
    // errors even when validation passes, making validate.errors non-empty on success.
    ajv = new Ajv({ strict: false });

    // Register design-spec schema so $ref resolution works.
    // Strip $schema field — Ajv v8 defaults to draft-07 and errors on 2020-12 meta-schema.
    const designSpecRaw = readFileSync(join(contractsDir, 'design-spec.schema.json'), 'utf8');
    const designSpecSchema = JSON.parse(
      designSpecRaw
        .replace(/"\$defs"/g, '"definitions"')
        .replace(/#\/\$defs\//g, '#/definitions/'),
    ) as Record<string, unknown>;
    delete designSpecSchema['$schema'];
    ajv.addSchema(designSpecSchema, 'https://stonewright.dev/companion/contracts/design-spec');

    const ingestRaw = readFileSync(join(contractsDir, 'figma-ingest.schema.json'), 'utf8');
    const ingestSchema = JSON.parse(
      ingestRaw
        .replace(/"\$defs"/g, '"definitions"')
        .replace(/#\/\$defs\//g, '#/definitions/'),
    ) as Record<string, unknown>;
    delete ingestSchema['$schema'];
    ajv.addSchema(ingestSchema, 'https://stonewright.dev/companion/contracts/figma-ingest');
  });

  const getIngestDefs = (): Record<string, unknown> => {
    const schema = ajv.getSchema('https://stonewright.dev/companion/contracts/figma-ingest') as { schema: { definitions?: Record<string, unknown> } } | undefined;
    return (schema?.schema?.['definitions'] as unknown as Record<string, unknown>) ?? {};
  };

  describe('/figma-ingest request', () => {
    const getRequestSchema = () => {
      const defs = getIngestDefs();
      return defs['FigmaIngestRequest'] as Record<string, unknown> | undefined;
    };

    it('valid request with figma_url passes', () => {
      const def = getRequestSchema();
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: getIngestDefs() });
      const valid = validate({ figma_url: 'https://www.figma.com/file/ABC123/Title?node-id=1-2' });
      expect(validate.errors ?? []).toEqual([]);
      expect(valid).toBe(true);
    });

    it('valid request with file_key + node_id passes', () => {
      const def = getRequestSchema();
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: getIngestDefs() });
      const valid = validate({ file_key: 'ABC123', node_id: '1:2' });
      expect(validate.errors ?? []).toEqual([]);
      expect(valid).toBe(true);
    });

    it('request with neither figma_url nor file_key+node_id fails', () => {
      const def = getRequestSchema();
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: getIngestDefs() });
      const valid = validate({ token_override: 'tok' });
      expect(valid).toBe(false);
    });
  });

  describe('/figma-ingest response', () => {
    it('DesignSpec schema defines typed container blocks', () => {
      const designSpecSchema = ajv.getSchema('https://stonewright.dev/companion/contracts/design-spec') as { schema: { definitions?: Record<string, unknown> } } | undefined;
      const defs = designSpecSchema?.schema?.definitions ?? {};

      expect(defs['ContainerBlock']).toBeDefined();
    });

    it('valid response may contain a flex container block', () => {
      const defs = getIngestDefs();
      const def = defs['FigmaIngestResponse'] as Record<string, unknown> | undefined;
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: defs });

      const valid = validate({
        spec: {
          ...VALID_DESIGN_SPEC,
          sections: [
            {
              id: 'hero',
              blocks: [
                {
                  type: 'container',
                  layout: 'flex',
                  direction: 'row',
                  blocks: [{ type: 'heading', text: 'Hello World', level: 1 }],
                },
              ],
            },
          ],
        },
        warnings: [],
        asset_count: 0,
      });

      expect(validate.errors ?? []).toEqual([]);
      expect(valid).toBe(true);
    });

    it('valid response may contain a native video block with poster asset', () => {
      const defs = getIngestDefs();
      const def = defs['FigmaIngestResponse'] as Record<string, unknown> | undefined;
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: defs });

      const valid = validate({
        spec: {
          ...VALID_DESIGN_SPEC,
          sections: [
            {
              id: 'aftermovie',
              blocks: [
                {
                  type: 'video',
                  url: '',
                  poster: {
                    url: 'https://figma-cdn.example.com/poster.png',
                    assetRef: 'asset_poster',
                    alt: 'Aftermovie poster',
                  },
                },
              ],
            },
          ],
        },
        warnings: [],
        asset_count: 1,
      });

      expect(validate.errors ?? []).toEqual([]);
      expect(valid).toBe(true);
    });

    it('valid response passes', () => {
      const defs = getIngestDefs();
      const def = defs['FigmaIngestResponse'] as Record<string, unknown> | undefined;
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: defs });

      const valid = validate({
        spec: VALID_DESIGN_SPEC,
        warnings: [],
        asset_count: 0,
      });
      expect(validate.errors ?? []).toEqual([]);
      expect(valid).toBe(true);
    });

    it('response missing spec fails', () => {
      const defs = getIngestDefs();
      const def = defs['FigmaIngestResponse'] as Record<string, unknown> | undefined;
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: defs });

      const valid = validate({ warnings: [], asset_count: 0 });
      expect(valid).toBe(false);
    });

    it('response with wrong asset_count type fails', () => {
      const defs = getIngestDefs();
      const def = defs['FigmaIngestResponse'] as Record<string, unknown> | undefined;
      if (!def) return;
      const validate = ajv.compile({ ...def, definitions: defs });

      const valid = validate({ spec: VALID_DESIGN_SPEC, warnings: [], asset_count: 'many' });
      expect(valid).toBe(false);
    });
  });
});

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

type EndpointKey = keyof typeof FIXTURES;
const endpoints = Object.keys(FIXTURES) as EndpointKey[];

describe('Companion contract schemas', () => {
  let ajv: Ajv;

  beforeAll(() => {
    ajv = new Ajv({ strict: false, allErrors: true });
  });

  for (const endpoint of endpoints) {
    const schema = loadSchema(endpoint);
    const defs = (schema['definitions'] ?? {}) as Record<string, unknown>;
    const fixture = FIXTURES[endpoint];

    // Determine the request/response definition names by convention
    const defNames = Object.keys(defs);
    const requestDefName = defNames.find((n) =>
      n.toLowerCase().includes('request') && !n.toLowerCase().includes('response'),
    );
    const responseDefName = defNames.find((n) => n.toLowerCase().includes('response'));

    describe(`/${endpoint}`, () => {
      it('request schema: valid fixture passes', () => {
        if (!requestDefName) return; // health has empty request
        const def = defs[requestDefName] as Record<string, unknown>;
        const schemaWithDefs = { ...def, definitions: defs };
        const validate = ajv.compile(schemaWithDefs);
        const valid = validate(fixture.validRequest);
        expect(validate.errors ?? []).toEqual([]);
        expect(valid).toBe(true);
      });

      if (fixture.invalidRequest !== null) {
        it('request schema: invalid fixture fails', () => {
          if (!requestDefName) return;
          const def = defs[requestDefName] as Record<string, unknown>;
          const schemaWithDefs = { ...def, definitions: defs };
          const validate = ajv.compile(schemaWithDefs);
          const valid = validate(fixture.invalidRequest);
          expect(valid).toBe(false);
        });
      }

      it('response schema: valid fixture passes', () => {
        if (!responseDefName) return;
        const def = defs[responseDefName] as Record<string, unknown>;
        const schemaWithDefs = { ...def, definitions: defs };
        const validate = ajv.compile(schemaWithDefs);
        const valid = validate(fixture.validResponse);
        expect(validate.errors ?? []).toEqual([]);
        expect(valid).toBe(true);
      });

      it('response schema: invalid fixture fails', () => {
        if (!responseDefName) return;
        const def = defs[responseDefName] as Record<string, unknown>;
        const schemaWithDefs = { ...def, definitions: defs };
        const validate = ajv.compile(schemaWithDefs);
        const valid = validate(fixture.invalidResponse);
        expect(valid).toBe(false);
      });
    });
  }
});
