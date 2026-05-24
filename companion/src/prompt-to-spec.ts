/**
 * PromptToSpec — generate a DesignSpec from a reference image + prompt
 * using Anthropic's vision-capable Claude models.
 *
 * Use this when you have a screenshot or Figma export but no machine-readable
 * spec. The caller supplies an image (URL or base64), a free-form prompt, and
 * optionally a viewport hint. The function returns a parsed DesignSpec object
 * that conforms to companion/src/contracts/design-spec.schema.json.
 *
 * Caching strategy:
 *   The system prompt + DesignSpec schema do not change across calls, so they
 *   are placed in the `system` array with `cache_control: {type: 'ephemeral'}`
 *   on the last block. Anthropic renders `system` BEFORE `messages`, so the
 *   image + user prompt land after the cacheable prefix. Cache hits show up
 *   in `usage.cache_read_input_tokens` after the first call.
 *
 * The Anthropic API key is read from `ANTHROPIC_API_KEY`. The function throws
 * a `PromptToSpecError` with a stable `code` field when the key is missing or
 * when the model returns something that cannot be parsed as JSON — callers
 * (MCP tool, HTTP route) surface these as structured errors rather than raw
 * exceptions.
 */

import Anthropic from '@anthropic-ai/sdk';
import { log } from './lib/log.js';

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

export type Viewport = 'desktop' | 'mobile';

export interface PromptToSpecInput {
	/** Public http/https URL of the reference image. Mutually exclusive with imageBase64. */
	imageUrl?: string;
	/** Base64-encoded image bytes (no data: prefix). Mutually exclusive with imageUrl. */
	imageBase64?: string;
	/** MIME type for base64 images. Defaults to image/png. Ignored when imageUrl is set. */
	imageMediaType?: 'image/png' | 'image/jpeg' | 'image/gif' | 'image/webp';
	/** Free-form description of what to extract from the image. */
	prompt: string;
	/** Layout target — hints the model about responsive behaviour. */
	viewport?: Viewport;
}

/**
 * DesignSpec — minimal structural type mirroring the JSON Schema at
 * companion/src/contracts/design-spec.schema.json. We intentionally keep this
 * loose (Record-typed) because the schema is the source of truth and the
 * companion's job is to return whatever the model produced, not to over-constrain
 * the TS surface. PHP-side Validator does deep checking.
 */
export interface DesignSpec {
	version: string;
	page: { title: string; description?: string; slug?: string };
	sections: Array<Record<string, unknown>>;
	tokens?: Record<string, unknown>;
	assets?: Array<Record<string, unknown>>;
	breakpoints?: Array<Record<string, unknown>>;
	meta?: Record<string, unknown>;
}

/** Structured error so callers can map to a stable error envelope. */
export class PromptToSpecError extends Error {
	public readonly code: string;
	public readonly detail?: string | undefined;
	constructor(code: string, message: string, detail?: string) {
		super(message);
		this.name = 'PromptToSpecError';
		this.code = code;
		this.detail = detail;
	}
}

// ---------------------------------------------------------------------------
// Cacheable system content — stable across every call
// ---------------------------------------------------------------------------

/**
 * Anthropic model used for vision + spec extraction.
 *
 * `claude-opus-4-7` is the latest Opus at the time of writing — it supports
 * vision and is the most capable model. If the caller's budget or latency
 * constraints demand a cheaper model, this can be swapped to
 * `claude-sonnet-4-6` without other changes.
 */
const MODEL_ID = 'claude-opus-4-7';

const SYSTEM_INSTRUCTIONS = `You are a design-to-spec converter for the Stonewright WordPress platform.

Your job: given a reference image of a webpage section (or full page) and a free-form prompt describing what the user wants, produce a Stonewright DesignSpec JSON object that captures the layout, content, and styling.

Hard rules:
- Output ONLY the JSON object. No prose, no markdown fences, no commentary.
- The JSON MUST be valid and parseable by JSON.parse with no fix-ups.
- Use only block types defined in the schema. Use native Elementor-intent blocks whenever the visual pattern calls for them: nav-menu for navigation, image-gallery for photo galleries, form for newsletter/contact forms, social-icons for social icon rows, icon-list for footer link columns or icon bullet rows, countdown for countdown timers, tabs for tabbed content, accordion/toggle for FAQ stacks, testimonial for quote cards, divider for section label underlines, and container for layout wrappers.
- Do NOT use HTML, html blocks, or arbitrary embed code as a fallback. If a native widget-intent block exists, use it.
- Every section MUST contain a non-empty "blocks" array.
- Prefer semantic block ordering that matches the image's visual reading order.
- Image references should use the "assetRef" field on the block plus an entry in the top-level "assets" array; do NOT inline data: URIs.
- Preserve layout intent: full-width outer sections, centered max-width inner containers, row containers for two-column heroes, native gallery widgets for galleries, native form widgets for forms, and exact image/SVG assets from the reference.
- Background rule: flat colors belong in background.color; simple linear gradients should be represented in native style/background data when available; complex glow/radial blur/shadow backgrounds should be represented as exported background assets via background.imageRef.
- Set "page.title" from the most prominent heading in the image.
- Use the "tokens" object to capture named colours and typography when they appear consistently.
- When in doubt about a property, omit it — the WordPress side fills defaults.`;

const DESIGN_SPEC_SCHEMA_REFERENCE = `Stonewright DesignSpec — top-level shape:
{
  "version": "1.0.0",
  "page": { "title": string, "description"?: string, "slug"?: string },
  "sections": [
    {
      "id"?: string,
      "label"?: string,
      "background"?: { "color"?: string, "imageRef"?: string },
      "padding"?: string,
      "margin"?: string,
      "fullWidth"?: boolean,
      "blocks": [
        // discriminated by "type":
        { "type": "heading",   "text": string, "level"?: 1..6 },
        { "type": "paragraph", "text": string },
        { "type": "image",     "src": string, "alt"?: string, "width"?: number, "height"?: number, "assetRef"?: string },
        { "type": "image-gallery", "images": [{ "url"?: string, "src"?: string, "assetRef"?: string, "alt"?: string }], "columns"?: number },
        { "type": "button",    "text": string, "url"?: string, "variant"?: "primary"|"secondary"|"outline"|"link" },
        { "type": "nav-menu",  "items"?: [{ "text": string, "url": string }], "layout"?: "horizontal"|"vertical", "dropdown"?: "mobile", "toggle"?: "hamburger" },
        { "type": "form",      "form_name"?: string, "button_text"?: string, "fields": [{ "type": "text"|"email"|"select"|"textarea"|"tel"|"checkbox"|"radio", "label": string, "required"?: boolean }] },
        { "type": "icon-list", "items": [{ "text": string, "url"?: string }] },
        { "type": "social-icons", "icons": [{ "network": string, "url": string }] },
        { "type": "countdown", "due_date"?: string },
        { "type": "divider",   "weight"?: number, "width"?: number, "color"?: string },
        { "type": "container", "layout"?: "flex"|"grid", "direction"?: "row"|"column", "blocks": [ /* nested blocks */ ] },
        { "type": "spacer",    "height"?: string },
        { "type": "video",     "src": string, "poster"?: string, "autoplay"?: boolean },
        { "type": "embed",     "url": string },
        { "type": "icon",      "icon": string, "size"?: string },
        { "type": "card",      "title"?: string, "body"?: string, "imageRef"?: string, "cta"?: string, "ctaUrl"?: string }
      ]
    }
  ],
  "tokens"?: {
    "colors"?: { [name]: "#rrggbb" or css color },
    "typography"?: { [name]: { "fontFamily": string, "fontSize": number, "fontWeight": number, "lineHeightPx"?: number, "letterSpacing"?: number } },
    "spacing"?: { [name]: "<number>(px|rem|em|vh|vw|%)" }
  },
  "assets"?: [ { "id": string, "url": string, "altText"?: string, "width"?: number, "height"?: number, "mimeType"?: string } ],
  "breakpoints"?: [ { "id": string, "label"?: string, "maxWidth"?: number } ],
  "meta"?: { ... arbitrary metadata ... }
}`;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function viewportInstruction(viewport: Viewport | undefined): string {
	if (viewport === 'mobile') {
		return 'Target viewport: MOBILE (narrow, single-column when reasonable). Use breakpoint id "mobile" in any responsive overrides.';
	}
	if (viewport === 'desktop') {
		return 'Target viewport: DESKTOP (wide, multi-column layouts allowed). Use breakpoint id "desktop" in any responsive overrides.';
	}
	return 'Target viewport: UNSPECIFIED. Produce a layout that reads naturally on a desktop screen but lists any obvious mobile overrides under "responsive".';
}

/**
 * Build the user-side image content block. Throws when neither imageUrl nor
 * imageBase64 is supplied (we deliberately require an image for this tool —
 * a text-only path belongs in a separate ability).
 */
function buildImageBlock(input: PromptToSpecInput): Anthropic.ImageBlockParam {
	if (input.imageUrl) {
		return {
			type: 'image',
			source: { type: 'url', url: input.imageUrl },
		};
	}
	if (input.imageBase64) {
		return {
			type: 'image',
			source: {
				type: 'base64',
				media_type: input.imageMediaType ?? 'image/png',
				data: input.imageBase64,
			},
		};
	}
	throw new PromptToSpecError(
		'missing_image',
		'Either imageUrl or imageBase64 is required to generate a DesignSpec from a reference image.',
	);
}

/**
 * Strip markdown code fences if the model wraps its JSON in them despite the
 * instruction. We tolerate ```json ... ``` and bare ``` ... ``` because real
 * Anthropic responses occasionally include them on edge cases.
 */
function stripCodeFences(text: string): string {
	const trimmed = text.trim();
	if (!trimmed.startsWith('```')) return trimmed;
	const withoutOpening = trimmed.replace(/^```(?:json)?\s*/i, '');
	return withoutOpening.replace(/\s*```\s*$/, '');
}

// ---------------------------------------------------------------------------
// Main entry point
// ---------------------------------------------------------------------------

/**
 * Generate a DesignSpec from a reference image + prompt.
 *
 * Throws {@link PromptToSpecError} for missing API key, missing image, JSON
 * parse failures, or unexpected response shape. API-level errors (rate limits,
 * 5xx, invalid model) are re-thrown as PromptToSpecError with the underlying
 * Anthropic error's message and status preserved in `detail`.
 */
export async function promptToSpec(input: PromptToSpecInput): Promise<DesignSpec> {
	const apiKey = process.env['ANTHROPIC_API_KEY'];
	if (!apiKey || apiKey.trim() === '') {
		throw new PromptToSpecError(
			'missing_api_key',
			'ANTHROPIC_API_KEY environment variable is not set.',
		);
	}
	if (!input.prompt || input.prompt.trim() === '') {
		throw new PromptToSpecError('missing_prompt', 'prompt is required and must be non-empty.');
	}

	const imageBlock = buildImageBlock(input);
	const client = new Anthropic({ apiKey });

	// System prompt + schema reference are cacheable — they never change across
	// requests, so we mark the last block ephemeral. The Anthropic API renders
	// `system` before `messages`, so the cacheable prefix sits ahead of the
	// per-request image + prompt.
	const systemBlocks: Anthropic.TextBlockParam[] = [
		{ type: 'text', text: SYSTEM_INSTRUCTIONS },
		{
			type: 'text',
			text: DESIGN_SPEC_SCHEMA_REFERENCE,
			cache_control: { type: 'ephemeral' },
		},
	];

	const userPromptText = `${viewportInstruction(input.viewport)}\n\nUser request:\n${input.prompt}\n\nReturn the DesignSpec JSON now.`;

	let response: Anthropic.Message;
	try {
		response = await client.messages.create({
			model: MODEL_ID,
			max_tokens: 8192,
			system: systemBlocks,
			messages: [
				{
					role: 'user',
					content: [
						imageBlock,
						{ type: 'text', text: userPromptText },
					],
				},
			],
		});
	} catch (err) {
		const message = err instanceof Error ? err.message : String(err);
		// Surface the Anthropic API error as a structured PromptToSpecError so
		// the MCP tool / HTTP route can map it to a clean error envelope.
		// Pull the status code when the SDK provides one (APIError subclasses).
		const status = (err as { status?: number }).status;
		const detail = status !== undefined ? `${status}: ${message}` : message;
		log.error('promptToSpec: Anthropic API call failed', { error: message, status });
		throw new PromptToSpecError('api_error', 'Anthropic API call failed', detail);
	}

	// Extract the first text block. Vision-capable Claude returns a list of
	// content blocks; we discard anything that isn't text (there should be
	// nothing else here, but defensive coding).
	const textBlock = response.content.find(
		(b): b is Anthropic.TextBlock => b.type === 'text',
	);
	if (!textBlock) {
		throw new PromptToSpecError(
			'no_text_response',
			'Anthropic response contained no text block.',
			`stop_reason=${response.stop_reason ?? 'unknown'}`,
		);
	}

	const cleaned = stripCodeFences(textBlock.text);
	let parsed: unknown;
	try {
		parsed = JSON.parse(cleaned);
	} catch (err) {
		const message = err instanceof Error ? err.message : String(err);
		throw new PromptToSpecError(
			'invalid_json',
			'Model output could not be parsed as JSON.',
			`${message} — text starts with: ${cleaned.slice(0, 200)}`,
		);
	}

	if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
		throw new PromptToSpecError(
			'invalid_shape',
			'Model output parsed as JSON but is not an object.',
		);
	}

	// Lightweight shape check — required top-level fields per the DesignSpec schema.
	const spec = parsed as Record<string, unknown>;
	for (const field of ['version', 'page', 'sections']) {
		if (!(field in spec)) {
			throw new PromptToSpecError(
				'invalid_shape',
				`Model output is missing required DesignSpec field: ${field}`,
			);
		}
	}

	log.info('promptToSpec succeeded', {
		viewport: input.viewport ?? 'unspecified',
		stop_reason: response.stop_reason,
		input_tokens: response.usage.input_tokens,
		cache_read_input_tokens: response.usage.cache_read_input_tokens ?? 0,
		cache_creation_input_tokens: response.usage.cache_creation_input_tokens ?? 0,
	});

	return spec as unknown as DesignSpec;
}

// ---------------------------------------------------------------------------
// Test seams — exported for unit tests, not part of the public surface.
// ---------------------------------------------------------------------------

export const __test__ = {
	MODEL_ID,
	SYSTEM_INSTRUCTIONS,
	DESIGN_SPEC_SCHEMA_REFERENCE,
	stripCodeFences,
	viewportInstruction,
};
