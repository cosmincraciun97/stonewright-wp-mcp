/**
 * HTTP endpoint round-trip tests for the companion QA REST API.
 *
 * Tests spin up a real HTTP server using the handler functions from http-api.ts,
 * with Playwright and pixel-diff mocked so no real browser is launched.
 *
 * Coverage:
 *   - Malformed request → 400
 *   - Valid request → 200 with schema-conformant response
 *   - Artifact path rejection on bad paths
 *   - /health includes contract_version
 */

import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { createServer, type Server, type IncomingMessage, type ServerResponse } from 'node:http';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import Ajv from 'ajv';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-argument
const __dirname: string = dirname(fileURLToPath(import.meta.url));
const contractsDir = join(__dirname, '..', 'src', 'contracts');

// ---------------------------------------------------------------------------
// Mock heavy dependencies before importing http-api
// ---------------------------------------------------------------------------

const mockPng = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]); // PNG header

vi.mock('../src/playwright-runner.js', () => ({
  screenshot: vi.fn().mockResolvedValue({
    png: mockPng,
    width: 1440,
    height: 900,
    url: 'https://example.com',
    tookMs: 100,
  }),
  closeBrowser: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../src/pixel-diff.js', () => ({
  diff: vi.fn().mockResolvedValue({
    mismatched_pixels: 10,
    total_pixels: 1000,
    ratio: 0.01,
    diff_png_path: '/tmp/diff.png',
  }),
}));

// Mock playwright for axe/layout handlers
vi.mock('playwright', () => {
  const mockPage = {
    goto: vi.fn().mockResolvedValue(undefined),
    addScriptTag: vi.fn().mockResolvedValue(undefined),
    evaluate: vi.fn().mockImplementation((fn: unknown, ..._args: unknown[]) => {
      // Return mock data depending on context
      const fnStr = fn?.toString() ?? '';
      if (fnStr.includes('axe')) {
        return Promise.resolve({ violations: [], passes: [{}], incomplete: [] });
      }
      // layout evaluate
      return Promise.resolve({
        sections: [{ name: 'header', tag: 'header', selector: 'header', rect: { x: 0, y: 0, width: 1440, height: 80 }, overflow: false }],
        docOverflow: false,
        overlaps: [],
      });
    }),
    close: vi.fn().mockResolvedValue(undefined),
  };
  const mockCtx = {
    newPage: vi.fn().mockResolvedValue(mockPage),
    close: vi.fn().mockResolvedValue(undefined),
  };
  const mockBrowser = {
    isConnected: vi.fn().mockReturnValue(true),
    newContext: vi.fn().mockResolvedValue(mockCtx),
    close: vi.fn().mockResolvedValue(undefined),
  };
  return { chromium: { launch: vi.fn().mockResolvedValue(mockBrowser) } };
});

// ---------------------------------------------------------------------------
// Test artifact directory setup
// ---------------------------------------------------------------------------

const UUID = '550e8400-e29b-41d4-a716-446655440000';

// Use a hardcoded /tmp path (lowercase) so it matches ARTIFACT_PATH_RE.
// Set COMPANION_ARTIFACTS_ROOT to cover it so assertInsideArtifacts passes.
// Note: on macOS /tmp is a symlink to /private/tmp — both are lowercase.
const TEST_QA_DIR = '/tmp/stonewright-qa/' + UUID;
const VALID_ARTIFACT_PATH = TEST_QA_DIR;

// Ensure the artifacts root covers our test directory.
process.env['COMPANION_ARTIFACTS_ROOT'] = '/tmp';

// We need an actual file on disk for the diff test (reference path)
const REF_PNG = join(TEST_QA_DIR, `screenshot-${UUID}.png`);
const ACTUAL_PNG = join(TEST_QA_DIR, 'actual.png');

mkdirSync(TEST_QA_DIR, { recursive: true });
writeFileSync(REF_PNG, mockPng);
writeFileSync(ACTUAL_PNG, mockPng);

// ---------------------------------------------------------------------------
// Import handlers (after mocks are set up)
// ---------------------------------------------------------------------------

const { dispatchQaRoute } = await import('../src/http-api.js');
const { CONTRACT_VERSION } = await import('../src/contracts/version.js');

// ---------------------------------------------------------------------------
// Minimal test HTTP server
// ---------------------------------------------------------------------------

type ParsedBody = Record<string, unknown>;

let server: Server;
let baseUrl: string;

async function readBody(req: IncomingMessage): Promise<Buffer> {
  const chunks: Buffer[] = [];
  for await (const chunk of req) chunks.push(chunk as Buffer);
  return Buffer.concat(chunks);
}

beforeAll(async () => {
  server = createServer((req: IncomingMessage, res: ServerResponse) => {
    const url = req.url ?? '/';

    if (url === '/health') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'ok', contract_version: CONTRACT_VERSION }));
      return;
    }

    void (async () => {
      const bodyBuf = await readBody(req);
      let parsed: ParsedBody = {};
      try {
        parsed = bodyBuf.length > 0 ? (JSON.parse(bodyBuf.toString()) as ParsedBody) : {};
      } catch {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Invalid JSON' }));
        return;
      }

      if (req.method !== 'POST') {
        res.writeHead(405, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Method not allowed' }));
        return;
      }

      const handled = await dispatchQaRoute(url, req, res, parsed);
      if (!handled) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Not found' }));
      }
    })();
  });

  await new Promise<void>((resolve, reject) => {
    server.listen(0, '127.0.0.1', () => {
      const addr = server.address();
      const port = addr && typeof addr === 'object' ? addr.port : 0;
      baseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
    server.once('error', reject);
  });
});

afterAll(async () => {
  await new Promise<void>((resolve, reject) => {
    server.close((err) => (err ? reject(err) : resolve()));
  });
});

// ---------------------------------------------------------------------------
// Ajv helper
// ---------------------------------------------------------------------------

function loadResponseSchema(endpoint: string): Record<string, unknown> {
  const raw = readFileSync(join(contractsDir, `${endpoint}.schema.json`), 'utf8');
  const schema = JSON.parse(
    raw.replace(/"\$defs"/g, '"definitions"').replace(/#\/\$defs\//g, '#/definitions/'),
  ) as Record<string, unknown>;
  const defs = (schema['definitions'] ?? {}) as Record<string, unknown>;
  const responseDefName = Object.keys(defs).find((n) => n.toLowerCase().includes('response'));
  if (!responseDefName) return { type: 'object' };
  return { ...(defs[responseDefName] as Record<string, unknown>), definitions: defs };
}

const ajv = new Ajv({ strict: false, allErrors: true });

async function postJson(path: string, body: unknown): Promise<{ status: number; data: unknown }> {
  const res = await fetch(`${baseUrl}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return { status: res.status, data: await res.json() };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('GET /health', () => {
  it('returns 200 with contract_version', async () => {
    const res = await fetch(`${baseUrl}/health`);
    expect(res.status).toBe(200);
    const body = await res.json() as Record<string, unknown>;
    expect(body['status']).toBe('ok');
    expect(typeof body['contract_version']).toBe('string');
    expect(body['contract_version']).toMatch(/^\d+\.\d+\.\d+$/);
  });
});

describe('POST /screenshot', () => {
  it('malformed request (missing required fields) → 400', async () => {
    const { status } = await postJson('/screenshot', { url: 'https://example.com' });
    expect(status).toBe(400);
  });

  it('bad artifact_path → 400', async () => {
    const { status } = await postJson('/screenshot', {
      request_id: UUID,
      url: 'https://example.com',
      artifact_path: '/etc/passwd',
    });
    expect(status).toBe(400);
  });

  it('valid request → 200 with schema-conformant response', async () => {
    const { status, data } = await postJson('/screenshot', {
      request_id: UUID,
      url: 'https://example.com',
      artifact_path: VALID_ARTIFACT_PATH,
    });
    expect(status).toBe(200);
    const validate = ajv.compile(loadResponseSchema('screenshot'));
    const valid = validate(data);
    expect(validate.errors ?? []).toEqual([]);
    expect(valid).toBe(true);
  });
});

describe('POST /diff', () => {
  it('malformed request → 400', async () => {
    const { status } = await postJson('/diff', { request_id: UUID });
    expect(status).toBe(400);
  });

  it('missing reference → 200 with needs_reference=true', async () => {
    const { status, data } = await postJson('/diff', {
      request_id: UUID,
      reference_artifact_id: '/nonexistent/path/ref.png',
      actual_artifact_id: ACTUAL_PNG,
      artifact_path: VALID_ARTIFACT_PATH,
    });
    expect(status).toBe(200);
    expect((data as Record<string, unknown>)['needs_reference']).toBe(true);
  });

  it('valid request with existing reference → 200 with diff result', async () => {
    const { status, data } = await postJson('/diff', {
      request_id: UUID,
      reference_artifact_id: REF_PNG,
      actual_artifact_id: ACTUAL_PNG,
      artifact_path: VALID_ARTIFACT_PATH,
    });
    expect(status).toBe(200);
    const d = data as Record<string, unknown>;
    expect(d['needs_reference']).toBe(false);
    expect(typeof d['diff_ratio']).toBe('number');
    const validate = ajv.compile(loadResponseSchema('diff'));
    expect(validate(data)).toBe(true);
  });
});

describe('POST /axe', () => {
  it('malformed request → 400', async () => {
    const { status } = await postJson('/axe', { ruleset: 'wcag2aa' });
    expect(status).toBe(400);
  });

  it('valid request → 200 with schema-conformant response', async () => {
    const { status, data } = await postJson('/axe', {
      request_id: UUID,
      url: 'https://example.com',
      ruleset: 'wcag2aa',
    });
    expect(status).toBe(200);
    const validate = ajv.compile(loadResponseSchema('axe'));
    const valid = validate(data);
    expect(validate.errors ?? []).toEqual([]);
    expect(valid).toBe(true);
  });
});

describe('POST /layout', () => {
  it('malformed request → 400', async () => {
    const { status } = await postJson('/layout', {});
    expect(status).toBe(400);
  });

  it('valid request → 200 with schema-conformant response', async () => {
    const { status, data } = await postJson('/layout', {
      request_id: UUID,
      url: 'https://example.com',
    });
    expect(status).toBe(200);
    const validate = ajv.compile(loadResponseSchema('layout'));
    const valid = validate(data);
    expect(validate.errors ?? []).toEqual([]);
    expect(valid).toBe(true);
  });
});

describe('POST /lighthouse', () => {
  it('malformed request → 400', async () => {
    const { status } = await postJson('/lighthouse', { categories: ['performance'] });
    expect(status).toBe(400);
  });

  it('valid request when lighthouse unavailable → 200 with available=false', async () => {
    // lighthouse binary is not present in test env → graceful degradation
    const { status, data } = await postJson('/lighthouse', {
      request_id: UUID,
      url: 'https://example.com',
    });
    expect(status).toBe(200);
    const d = data as Record<string, unknown>;
    // Either available=false (no binary) or schema-conformant success
    expect(typeof d['available']).toBe('boolean');
    const validate = ajv.compile(loadResponseSchema('lighthouse'));
    expect(validate(data)).toBe(true);
  });
});

describe('Unknown route', () => {
  it('returns 404', async () => {
    const { status } = await postJson('/unknown', {});
    expect(status).toBe(404);
  });
});
