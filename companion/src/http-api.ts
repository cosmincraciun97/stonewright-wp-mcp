/**
 * Companion QA REST handlers.
 *
 * Each handler receives a parsed, size-limited JSON body and writes the
 * response. Handlers import types from contracts/generated.ts — no inline
 * ad-hoc interfaces.
 *
 * Route table (registered by index.ts):
 *   POST /screenshot
 *   POST /diff
 *   POST /axe
 *   POST /layout
 *   POST /lighthouse
 *   GET  /health  (handled in index.ts; /health response includes contract_version)
 *
 * Security invariants enforced here:
 *   1. artifact_path validated against the allowed pattern before any FS write.
 *   2. URL must be http/https — no file://, data:, or relative URLs.
 *   3. All response fields come from contract-typed objects — no raw pass-through.
 */

import type { IncomingMessage, ServerResponse } from 'node:http';
import { mkdirSync, existsSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { join, resolve as pathResolve, dirname as pathDirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { screenshot as playwrightScreenshot } from './playwright-runner.js';
import { diff as pixelDiff } from './pixel-diff.js';
import { log } from './lib/log.js';
import { parseUrl, ingestFigmaNode } from './figma-bridge.js';
import { promptToSpec, PromptToSpecError, type Viewport } from './prompt-to-spec.js';
import { assertInsideArtifacts, getArtifactsRoot } from './lib/paths.js';
import type {
  ScreenshotRequest,
  ScreenshotResponse,
  DiffRequest,
  DiffResponse,
  AxeRequest,
  AxeResponse,
  AxeViolation,
  LayoutRequest,
  LayoutResponse,
  LayoutSection,
  AlignmentDiff,
  LighthouseRequest,
  LighthouseResponse,
} from './contracts/generated.js';

/**
 * Absolute path to the locally-vendored axe-core 4.9.1 script.
 * Resolved once at module load from import.meta.url so there is no per-request
 * filesystem cost. The `src/` / `dist/` directory is one level inside the
 * companion package root, so `..` takes us to the package root and `vendor/`
 * is directly inside it.
 */
const AXE_VENDOR_PATH = pathResolve(
  pathDirname(fileURLToPath(import.meta.url)),
  '..',
  'vendor',
  'axe-core-4.9.1.min.js',
);

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/**
 * Regex that artifact_path values MUST match.
 * Lowercase-only is safer and is the source of truth — schemas must mirror this.
 * Matches PHP-generated paths like /tmp/…/stonewright-qa/<uuid>/
 */
const ARTIFACT_PATH_RE = /^\/[a-z0-9/_.-]+stonewright-qa\/[a-z0-9-]+\/?$/;

const ALLOWED_URL_RE = /^https?:\/\//;

/**
 * UUID v4 regex. request_id MUST match before any filesystem join.
 * Prevents path-traversal via crafted request_id values.
 */
const UUID_V4_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function send(res: ServerResponse, status: number, body: unknown): void {
  const json = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(json);
}

function bad(res: ServerResponse, message: string): void {
  send(res, 400, { error: message });
}

/** Validate artifact_path and ensure the directory exists. */
function validateArtifactPath(path: string | undefined, res: ServerResponse): string | null {
  if (typeof path !== 'string' || !ARTIFACT_PATH_RE.test(path)) {
    bad(res, 'artifact_path does not match the allowed pattern (must be a PHP-reserved stonewright-qa directory)');
    return null;
  }
  if (!existsSync(path)) {
    mkdirSync(path, { recursive: true, mode: 0o700 });
  }
  return path;
}

/** Validate a target URL. */
function validateUrl(url: string | undefined, res: ServerResponse): string | null {
  if (typeof url !== 'string' || !ALLOWED_URL_RE.test(url)) {
    bad(res, 'url must be an absolute http:// or https:// URL');
    return null;
  }
  return url;
}

/**
 * Validate request_id is a UUID v4.
 * Rejects with HTTP 400 if not — prevents path traversal via crafted request_id.
 */
function validateRequestId(requestId: string | undefined, res: ServerResponse): string | null {
  if (typeof requestId !== 'string' || !UUID_V4_RE.test(requestId)) {
    bad(res, 'request_id must be a UUID v4 (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx)');
    return null;
  }
  return requestId;
}

/** Find an artifact file by artifact_id inside a stonewright-qa directory. */
function resolveArtifactPath(artifactId: string): string | null {
  // The artifact lives at <artifact_path>/<artifact_id>.png.
  // We accept any path passed by PHP in a prior /screenshot call.
  // Since we can't recover the original artifact_path without storing state,
  // we rely on the caller to use /diff with full paths encoded in artifact_ids
  // in the form "<artifact_path>/<name>".
  return artifactId; // artifact_id IS the full path
}

// ---------------------------------------------------------------------------
// POST /screenshot
// ---------------------------------------------------------------------------

export async function handleScreenshot(
  _req: IncomingMessage,
  res: ServerResponse,
  body: unknown,
): Promise<void> {
  const data = body as Partial<ScreenshotRequest>;

  const requestId = validateRequestId(data.request_id, res);
  if (requestId === null) return;

  const url = validateUrl(data.url, res);
  if (url === null) return;

  const artifactPath = validateArtifactPath(data.artifact_path, res);
  if (artifactPath === null) return;

  const viewport = data.viewport ?? { width: 1280, height: 800 };
  const waitFor = data.wait_for ?? 'networkidle';
  const waitMs = data.wait_ms ?? 500;
  const fullPage = data.full_page ?? true;

  try {
    const screenshotOpts: Parameters<typeof playwrightScreenshot>[1] = {
      viewport,
      full_page: fullPage,
      wait_for: waitFor,
      delay_ms: waitMs,
    };
    if (data.selector !== undefined) {
      screenshotOpts.selector = data.selector;
    }
    const result = await playwrightScreenshot(url, screenshotOpts);

    const artifactId = `screenshot-${requestId}`;
    const filePath = join(artifactPath, `${artifactId}.png`);
    // Defense-in-depth: assert write target is inside the artifacts root.
    assertInsideArtifacts(filePath, getArtifactsRoot());
    await writeFile(filePath, result.png);

    // Build a URL relative to wp-content/uploads by stripping the fs prefix.
    // The companion doesn't know the WP URL — PHP maps paths to URLs.
    // We return the path; PHP QaArtifactStore maps it to a URL.
    const publicUrl = filePath; // PHP resolves to public URL

    const response: ScreenshotResponse = {
      request_id: requestId,
      artifact_id: filePath,  // full path used as artifact_id so /diff can resolve it
      path: filePath,
      url: publicUrl,
      width: result.width,
      height: result.height,
      viewport: { width: result.width, height: result.height },
      created_at: new Date().toISOString(),
    };

    send(res, 200, response);
  } catch (err) {
    log.error('Screenshot failed', { error: err instanceof Error ? err.message : String(err) });
    send(res, 500, { error: 'Screenshot failed', detail: err instanceof Error ? err.message : String(err) });
  }
}

// ---------------------------------------------------------------------------
// POST /diff
// ---------------------------------------------------------------------------

export async function handleDiff(
  _req: IncomingMessage,
  res: ServerResponse,
  body: unknown,
): Promise<void> {
  const data = body as Partial<DiffRequest>;

  const requestId = validateRequestId(data.request_id, res);
  if (requestId === null) return;

  const artifactPath = validateArtifactPath(data.artifact_path, res);
  if (artifactPath === null) return;

  const refId = data.reference_artifact_id;
  const actualId = data.actual_artifact_id;

  if (typeof refId !== 'string' || refId.length === 0) {
    bad(res, 'reference_artifact_id is required');
    return;
  }
  if (typeof actualId !== 'string' || actualId.length === 0) {
    bad(res, 'actual_artifact_id is required');
    return;
  }

  const refPath = resolveArtifactPath(refId);
  const actualFilePath = resolveArtifactPath(actualId);

  if (!refPath || !existsSync(refPath)) {
    const response: DiffResponse = { request_id: requestId, needs_reference: true };
    send(res, 200, response);
    return;
  }

  if (!actualFilePath || !existsSync(actualFilePath)) {
    bad(res, 'actual_artifact_id does not resolve to an existing file');
    return;
  }

  const threshold = data.threshold ?? 0.1;
  const ignoreRegions = (data.ignore_regions ?? []).map((r) => ({
    x: r.x,
    y: r.y,
    width: r.width,
    height: r.height,
  }));

  try {
    const diffOutputPath = join(artifactPath, `diff-${requestId}.png`);
    // Defense-in-depth: assert write target is inside the artifacts root.
    assertInsideArtifacts(diffOutputPath, getArtifactsRoot());
    const result = await pixelDiff(refPath, actualFilePath, {
      threshold,
      ignore_regions: ignoreRegions,
      diff_output_path: diffOutputPath,
    });

    const response: DiffResponse = {
      request_id: requestId,
      needs_reference: false,
      diff_ratio: result.ratio,
      passed: result.ratio <= threshold,
      threshold,
      diff_url: diffOutputPath, // PHP maps to public URL
      mismatch_regions: [],     // clustering not yet implemented; future enhancement
    };

    send(res, 200, response);
  } catch (err) {
    log.error('Diff failed', { error: err instanceof Error ? err.message : String(err) });
    send(res, 500, { error: 'Diff failed', detail: err instanceof Error ? err.message : String(err) });
  }
}

// ---------------------------------------------------------------------------
// POST /axe
// ---------------------------------------------------------------------------

export async function handleAxe(
  _req: IncomingMessage,
  res: ServerResponse,
  body: unknown,
): Promise<void> {
  const data = body as Partial<AxeRequest>;

  const requestId = validateRequestId(data.request_id, res);
  if (requestId === null) return;

  const url = validateUrl(data.url, res);
  if (url === null) return;

  const ruleset = data.ruleset ?? 'wcag2aa';

  try {
    // Dynamically import playwright to get a page; run axe-core via page.evaluate.
    const { chromium } = await import('playwright');
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });

      // Load axe-core from the locally-vendored file (safer than CDN — no network
      // dependency, no CDN compromise risk, pinned to 4.9.1).
      await page.addScriptTag({ path: AXE_VENDOR_PATH });

      const axeResult = await page.evaluate((rs: string) => {
        return new Promise<{
          violations: Array<{
            id: string;
            impact: string | null;
            help: string;
            nodes: Array<{ html: string; target: string[] }>;
          }>;
          passes: Array<unknown>;
          incomplete: Array<unknown>;
        }>((resolve, reject) => {
          // @ts-expect-error axe is injected at runtime
          // eslint-disable-next-line @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access
          window.axe.run({ runOnly: { type: 'tag', values: [rs] } }, (err: Error | null, results: unknown) => {
            if (err) reject(err);
            else resolve(results as never);
          });
        });
      }, ruleset);

      const violations: AxeViolation[] = axeResult.violations.map((v) => ({
        rule: v.id,
        impact: (v.impact ?? 'minor') as AxeViolation['impact'],
        help: v.help,
        nodes: v.nodes,
      }));

      const response: AxeResponse = {
        request_id: requestId,
        violations,
        passes_count: axeResult.passes.length,
        incomplete_count: axeResult.incomplete.length,
      };

      send(res, 200, response);
    } finally {
      await page.close().catch(() => undefined);
      await ctx.close().catch(() => undefined);
      await browser.close().catch(() => undefined);
    }
  } catch (err) {
    log.error('Axe audit failed', { error: err instanceof Error ? err.message : String(err) });
    send(res, 500, { error: 'Axe audit failed', detail: err instanceof Error ? err.message : String(err) });
  }
}

// ---------------------------------------------------------------------------
// POST /layout
// ---------------------------------------------------------------------------

export async function handleLayout(
  _req: IncomingMessage,
  res: ServerResponse,
  body: unknown,
): Promise<void> {
  const data = body as Partial<LayoutRequest>;

  const requestId = validateRequestId(data.request_id, res);
  if (requestId === null) return;

  const url = validateUrl(data.url, res);
  if (url === null) return;

  const viewport = data.viewport ?? { width: 1440, height: 900 };

  try {
    const { chromium } = await import('playwright');
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const ctx = await browser.newContext({ viewport });
    const page = await ctx.newPage();

    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });

      type DomSection = { name: string; tag: string; selector: string; rect: { x: number; y: number; width: number; height: number }; overflow: boolean };
      type DomOverlap = { a: string; b: string };
      type DomData = { sections: DomSection[]; docOverflow: boolean; overlaps: DomOverlap[] };

      // page.evaluate() runs in the browser context where DOM globals (document,
      // window, Element, HTMLElement) are available. We pass the logic as a string
      // so TypeScript does not try to type-check the DOM API calls against the
      // Node.js lib — the function is serialised and executed in Chromium.
      const domScript = `(() => {
        const sections = [];
        const cssSelectors = ['section','article','header','footer','main','nav','[class*="section"]','[class*="row"]','[class*="container"]'];
        const seen = new Set();
        for (const sel of cssSelectors) {
          for (const el of document.querySelectorAll(sel)) {
            if (seen.has(el)) continue;
            seen.add(el);
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;
            const styles = window.getComputedStyle(el);
            const ox = styles.overflowX;
            sections.push({
              name: el.getAttribute('aria-label') || (el.className || '').toString().split(' ')[0] || el.tagName.toLowerCase(),
              tag: el.tagName.toLowerCase(),
              selector: sel,
              rect: { x: r.x, y: r.y, width: r.width, height: r.height },
              overflow: ox !== 'hidden' && ox !== 'clip' && el.scrollWidth > el.clientWidth,
            });
          }
        }
        const docOverflow = document.documentElement.scrollWidth > document.documentElement.clientWidth;
        const overlaps = [];
        for (let i = 0; i < sections.length; i++) {
          for (let j = i + 1; j < sections.length; j++) {
            const a = sections[i].rect;
            const b = sections[j].rect;
            if (a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y) {
              overlaps.push({ a: sections[i].name, b: sections[j].name });
            }
          }
        }
        return { sections, docOverflow, overlaps };
      })()`;

      // eslint-disable-next-line @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unnecessary-type-assertion
      const domData = (await page.evaluate(domScript)) as unknown as DomData;

      const sections: LayoutSection[] = domData.sections.map((s) => ({
        name: s.name,
        tag: s.tag,
        selector: s.selector,
        rect: s.rect,
      }));

      const alignmentDiffs: AlignmentDiff[] = [
        ...domData.sections
          .filter((s) => s.overflow)
          .map((s): AlignmentDiff => ({
            type: 'overflow' as const,
            detail: `Element "${s.name}" has horizontal scroll overflow`,
            element: s.selector,
          })),
        ...domData.overlaps.map((o): AlignmentDiff => ({
          type: 'overlap' as const,
          detail: `Elements "${o.a}" and "${o.b}" overlap`,
        })),
      ];

      const response: LayoutResponse = {
        request_id: requestId,
        sections,
        alignment_diffs: alignmentDiffs,
        has_horizontal_overflow: domData.docOverflow || domData.sections.some((s) => s.overflow),
        has_element_overlap: domData.overlaps.length > 0,
      };

      send(res, 200, response);
    } finally {
      await page.close().catch(() => undefined);
      await ctx.close().catch(() => undefined);
      await browser.close().catch(() => undefined);
    }
  } catch (err) {
    log.error('Layout check failed', { error: err instanceof Error ? err.message : String(err) });
    send(res, 500, { error: 'Layout check failed', detail: err instanceof Error ? err.message : String(err) });
  }
}

// ---------------------------------------------------------------------------
// POST /lighthouse
// ---------------------------------------------------------------------------

/** Detect whether the `lighthouse` CLI binary is available. */
async function lighthouseAvailable(): Promise<boolean> {
  try {
    const { execFile } = await import('node:child_process');
    const { promisify } = await import('node:util');
    const execFileAsync = promisify(execFile);
    await execFileAsync('lighthouse', ['--version'], { timeout: 5000 });
    return true;
  } catch {
    return false;
  }
}

export async function handleLighthouse(
  _req: IncomingMessage,
  res: ServerResponse,
  body: unknown,
): Promise<void> {
  const data = body as Partial<LighthouseRequest>;

  const requestId = validateRequestId(data.request_id, res);
  if (requestId === null) return;

  const url = validateUrl(data.url, res);
  if (url === null) return;

  // Validate artifact_path when provided (mirrors handleScreenshot/handleDiff).
  let artifactPath: string | null = null;
  if (data.artifact_path !== undefined) {
    artifactPath = validateArtifactPath(data.artifact_path, res);
    if (artifactPath === null) return;
  }

  if (!(await lighthouseAvailable())) {
    const response: LighthouseResponse = { request_id: requestId, available: false };
    send(res, 200, response);
    return;
  }

  const categories = data.categories ?? ['performance', 'accessibility', 'best-practices', 'seo'];

  try {
    const { execFile } = await import('node:child_process');
    const { promisify } = await import('node:util');
    const execFileAsync = promisify(execFile);

    const outputBase = artifactPath !== null
      ? join(artifactPath, `lighthouse-${requestId}`)
      : join(getArtifactsRoot(), `lighthouse-${requestId}`);
    const outputPath = `${outputBase}.html`;
    // Defense-in-depth: assert write target is inside the artifacts root.
    assertInsideArtifacts(outputPath, getArtifactsRoot());

    const lighthouseArgs = [
      url,
      `--output=json,html`,
      `--output-path=${outputBase}`,
      `--only-categories=${categories.join(',')}`,
      '--chrome-flags=--headless --no-sandbox --disable-dev-shm-usage',
      '--quiet',
    ];

    const { stdout } = await execFileAsync('lighthouse', lighthouseArgs, {
      timeout: 120_000,
      maxBuffer: 20 * 1024 * 1024,
    });

    let lhResult: {
      categories?: {
        performance?: { score: number | null };
        accessibility?: { score: number | null };
        'best-practices'?: { score: number | null };
        seo?: { score: number | null };
      };
      audits?: Record<string, { score: number | null; id: string }>;
    };

    try {
      lhResult = JSON.parse(stdout) as typeof lhResult;
    } catch {
      // Lighthouse writes JSON to the output file, not stdout
      const { readFileSync } = await import('node:fs');
      const jsonPath = `${outputBase}.report.json`;
      lhResult = JSON.parse(readFileSync(jsonPath, 'utf8')) as typeof lhResult;
    }

    const cats = lhResult.categories ?? {};
    const scores = {
      performance: cats['performance']?.score ?? null,
      accessibility: cats['accessibility']?.score ?? null,
      'best-practices': cats['best-practices']?.score ?? null,
      seo: cats['seo']?.score ?? null,
    };

    const auditsFailed = Object.entries(lhResult.audits ?? {})
      .filter(([, a]) => a.score !== null && a.score < 0.9)
      .map(([id]) => id);

    const response: LighthouseResponse = {
      request_id: requestId,
      available: true,
      scores,
      report_url: `${outputPath}`,
      audits_failed: auditsFailed,
    };

    send(res, 200, response);
  } catch (err) {
    log.error('Lighthouse audit failed', { error: err instanceof Error ? err.message : String(err) });
    send(res, 500, { error: 'Lighthouse audit failed', detail: err instanceof Error ? err.message : String(err) });
  }
}

// ---------------------------------------------------------------------------
// POST /figma-ingest
// ---------------------------------------------------------------------------

interface FigmaIngestRequestBody {
	figma_url?: string;
	file_key?: string;
	node_id?: string;
	token_override?: string;
}

export async function handleFigmaIngest(
	_req: IncomingMessage,
	res: ServerResponse,
	body: unknown,
): Promise<void> {
	const data = body as Partial<FigmaIngestRequestBody>;

	// Resolve file_key + node_id from figma_url OR direct fields.
	let fileKey = data.file_key ?? '';
	let nodeId  = data.node_id ?? '';

	if (data.figma_url) {
		try {
			const ref = parseUrl(data.figma_url);
			if (!fileKey) fileKey = ref.fileKey;
			if (!nodeId && ref.nodeId) nodeId = ref.nodeId;
		} catch (err) {
			bad(res, `Invalid figma_url: ${err instanceof Error ? err.message : String(err)}`);
			return;
		}
	}

	if (!fileKey) {
		bad(res, 'file_key (or figma_url containing a file key) is required');
		return;
	}
	if (!nodeId) {
		bad(res, 'node_id (or figma_url containing a node-id) is required');
		return;
	}

	// Resolve Figma token: caller override → env var.
	const token = data.token_override ?? process.env['FIGMA_TOKEN'] ?? '';
	if (!token) {
		bad(res, 'Figma personal access token required (pass token_override or set FIGMA_TOKEN env var)');
		return;
	}

	try {
		const result = await ingestFigmaNode(fileKey, nodeId, token);
		send(res, 200, result);
	} catch (err) {
		log.error('Figma ingest failed', { error: err instanceof Error ? err.message : String(err) });
		send(res, 500, {
			error: 'Figma ingest failed',
			detail: err instanceof Error ? err.message : String(err),
		});
	}
}

// ---------------------------------------------------------------------------
// POST /prompt-to-spec
// ---------------------------------------------------------------------------

interface PromptToSpecRequestBody {
	prompt?: string;
	image_url?: string;
	image_base64?: string;
	image_media_type?: 'image/png' | 'image/jpeg' | 'image/gif' | 'image/webp';
	viewport?: Viewport;
}

export async function handlePromptToSpec(
	_req: IncomingMessage,
	res: ServerResponse,
	body: unknown,
): Promise<void> {
	const data = body as Partial<PromptToSpecRequestBody>;

	if (typeof data.prompt !== 'string' || data.prompt.trim() === '') {
		bad(res, 'prompt is required and must be a non-empty string');
		return;
	}
	if (data.image_url === undefined && data.image_base64 === undefined) {
		bad(res, 'image_url or image_base64 is required');
		return;
	}
	if (data.image_url !== undefined && !/^https?:\/\//.test(data.image_url)) {
		bad(res, 'image_url must be an absolute http:// or https:// URL');
		return;
	}
	if (data.viewport !== undefined && data.viewport !== 'desktop' && data.viewport !== 'mobile') {
		bad(res, 'viewport must be "desktop" or "mobile" when provided');
		return;
	}

	try {
		// Build the promptToSpec input without undefined keys (exactOptionalPropertyTypes).
		const input: Parameters<typeof promptToSpec>[0] = {
			prompt: data.prompt,
			...(data.image_url !== undefined ? { imageUrl: data.image_url } : {}),
			...(data.image_base64 !== undefined ? { imageBase64: data.image_base64 } : {}),
			...(data.image_media_type !== undefined ? { imageMediaType: data.image_media_type } : {}),
			...(data.viewport !== undefined ? { viewport: data.viewport } : {}),
		};
		const spec = await promptToSpec(input);
		send(res, 200, { spec });
	} catch (err) {
		if (err instanceof PromptToSpecError) {
			log.error('PromptToSpec failed', { code: err.code, error: err.message });
			// Map known error codes to HTTP statuses. missing_api_key is a server
			// config problem (500); anything user-input-shaped is a 400.
			const status =
				err.code === 'missing_api_key' || err.code === 'api_error' ? 502 : 400;
			send(res, status, { error: err.message, code: err.code, detail: err.detail });
			return;
		}
		const message = err instanceof Error ? err.message : String(err);
		log.error('PromptToSpec unexpected failure', { error: message });
		send(res, 500, { error: 'PromptToSpec failed', detail: message });
	}
}

// ---------------------------------------------------------------------------
// Route dispatcher — called from index.ts
// ---------------------------------------------------------------------------

type Handler = (req: IncomingMessage, res: ServerResponse, body: unknown) => Promise<void>;

const QA_ROUTES: Record<string, Handler> = {
  '/screenshot':     handleScreenshot,
  '/diff':           handleDiff,
  '/axe':            handleAxe,
  '/layout':         handleLayout,
  '/lighthouse':     handleLighthouse,
  '/figma-ingest':   handleFigmaIngest,
  '/prompt-to-spec': handlePromptToSpec,
};

/**
 * Dispatch a POST request to the appropriate QA handler.
 * Returns true if the route was handled, false if not found.
 */
export function dispatchQaRoute(
  path: string,
  req: IncomingMessage,
  res: ServerResponse,
  body: unknown,
): Promise<boolean> {
  const handler = QA_ROUTES[path];
  if (!handler) return Promise.resolve(false);
  return handler(req, res, body).then(() => true);
}

/** List of known QA route paths (for test enumeration). */
export const QA_ROUTE_PATHS = Object.keys(QA_ROUTES);

// Re-export the artifact path regex so tests can verify rejection logic.
export { ARTIFACT_PATH_RE };
