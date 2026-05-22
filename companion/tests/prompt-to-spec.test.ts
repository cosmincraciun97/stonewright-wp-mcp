/**
 * Unit tests for the prompt_to_spec module.
 *
 * Approach: mock @anthropic-ai/sdk via vi.mock so we don't need to expose a
 * `client` injection in the production code path. The mocked Anthropic class
 * captures every call so we can assert on the system blocks, image content,
 * cache_control placement, etc.
 *
 * The live API is NOT exercised — every request is intercepted by the mock.
 * An integration test that gates on ANTHROPIC_API_KEY is a separate concern.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// ---------------------------------------------------------------------------
// Mock @anthropic-ai/sdk BEFORE importing the module under test.
//
// We control the mock's behaviour through `mockState` (declared below) so each
// test can swap in a different response without re-mocking the module.
// ---------------------------------------------------------------------------

interface MockState {
	responseText: string;
	stopReason: string;
	contentOverride: Array<{ type: string; text?: string }> | null;
	throwError: Error | null;
	usage: {
		input_tokens: number;
		output_tokens: number;
		cache_creation_input_tokens: number;
		cache_read_input_tokens: number;
	};
}

const VALID_SPEC = {
	version: '1.0.0',
	page: { title: 'Pricing' },
	sections: [
		{
			id: 'sec-1',
			blocks: [{ type: 'heading', text: 'Choose your plan', level: 1 }],
		},
	],
};

const mockState: MockState = {
	responseText: JSON.stringify(VALID_SPEC),
	stopReason: 'end_turn',
	contentOverride: null,
	throwError: null,
	usage: {
		input_tokens: 1234,
		output_tokens: 567,
		cache_creation_input_tokens: 0,
		cache_read_input_tokens: 0,
	},
};

const messagesCreate = vi.fn((params: Record<string, unknown>) => {
	void params; // captured by mock for assertions via .mock.calls
	if (mockState.throwError) {
		return Promise.reject(mockState.throwError);
	}
	return Promise.resolve({
		id: 'msg_test',
		type: 'message',
		role: 'assistant',
		model: 'claude-opus-4-7',
		content: mockState.contentOverride ?? [
			{ type: 'text', text: mockState.responseText },
		],
		stop_reason: mockState.stopReason,
		stop_sequence: null,
		usage: mockState.usage,
	});
});

vi.mock('@anthropic-ai/sdk', () => {
	class MockAnthropic {
		public messages = { create: messagesCreate };
		constructor(public opts?: { apiKey?: string }) {
			void this.opts;
		}
	}
	return { default: MockAnthropic };
});

// Now safe to import the module under test — its `new Anthropic(...)` call
// resolves to MockAnthropic.
import { promptToSpec, PromptToSpecError, __test__ } from '../src/prompt-to-spec.js';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function resetMockState(): void {
	mockState.responseText = JSON.stringify(VALID_SPEC);
	mockState.stopReason = 'end_turn';
	mockState.contentOverride = null;
	mockState.throwError = null;
	mockState.usage = {
		input_tokens: 1234,
		output_tokens: 567,
		cache_creation_input_tokens: 0,
		cache_read_input_tokens: 0,
	};
	messagesCreate.mockClear();
}

/**
 * Read the params that were passed into the latest messagesCreate call.
 * Throws when no call has happened yet.
 */
function lastCreateParams(): Record<string, unknown> {
	const calls = messagesCreate.mock.calls;
	if (calls.length === 0) throw new Error('messagesCreate was not called');
	const lastCall = calls[calls.length - 1];
	if (!lastCall) throw new Error('messagesCreate last call is undefined');
	return lastCall[0];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('promptToSpec — happy path', () => {
	beforeEach(() => {
		process.env['ANTHROPIC_API_KEY'] = 'sk-ant-test-key';
		resetMockState();
	});

	afterEach(() => {
		delete process.env['ANTHROPIC_API_KEY'];
	});

	it('returns a parsed DesignSpec when the model produces valid JSON', async () => {
		const out = await promptToSpec({
			prompt: 'A pricing section with three plans',
			imageUrl: 'https://example.com/pricing.png',
		});
		expect(out.version).toBe('1.0.0');
		expect(out.page.title).toBe('Pricing');
		expect(out.sections).toHaveLength(1);
		expect(messagesCreate).toHaveBeenCalledTimes(1);
	});

	it('tolerates fenced code block output from the model', async () => {
		mockState.responseText = '```json\n' + JSON.stringify(VALID_SPEC) + '\n```';
		const out = await promptToSpec({
			prompt: 'Hero section',
			imageBase64: 'aGVsbG8=',
		});
		expect(out.version).toBe('1.0.0');
	});
});

describe('promptToSpec — input validation', () => {
	beforeEach(() => {
		delete process.env['ANTHROPIC_API_KEY'];
		resetMockState();
	});

	it('throws PromptToSpecError(missing_api_key) when ANTHROPIC_API_KEY is unset', async () => {
		await expect(
			promptToSpec({
				prompt: 'A pricing section',
				imageUrl: 'https://example.com/pricing.png',
			}),
		).rejects.toMatchObject({ code: 'missing_api_key' });
	});

	it('throws PromptToSpecError(missing_prompt) when prompt is whitespace', async () => {
		process.env['ANTHROPIC_API_KEY'] = 'sk-ant-test';
		await expect(
			promptToSpec({
				prompt: '   ',
				imageUrl: 'https://example.com/foo.png',
			}),
		).rejects.toMatchObject({ code: 'missing_prompt' });
	});

	it('throws PromptToSpecError(missing_image) when neither imageUrl nor imageBase64 is supplied', async () => {
		process.env['ANTHROPIC_API_KEY'] = 'sk-ant-test';
		await expect(
			promptToSpec({
				prompt: 'A pricing section',
			}),
		).rejects.toMatchObject({ code: 'missing_image' });
	});
});

describe('promptToSpec — request shape', () => {
	beforeEach(() => {
		process.env['ANTHROPIC_API_KEY'] = 'sk-ant-test-key';
		resetMockState();
	});

	afterEach(() => {
		delete process.env['ANTHROPIC_API_KEY'];
	});

	it('passes the user prompt + image URL into the Anthropic call', async () => {
		await promptToSpec({
			prompt: 'Build a 3-card pricing grid',
			imageUrl: 'https://cdn.example.com/ref.png',
		});

		expect(messagesCreate).toHaveBeenCalledTimes(1);
		const call = lastCreateParams();
		expect(call['model']).toBe(__test__.MODEL_ID);

		const messages = call['messages'] as Array<{ role: string; content: unknown[] }>;
		expect(messages).toHaveLength(1);
		expect(messages[0]?.role).toBe('user');

		const content = messages[0]?.content as Array<Record<string, unknown>>;
		const imageBlock = content.find((b) => b['type'] === 'image');
		const textBlock = content.find((b) => b['type'] === 'text');
		expect(imageBlock).toBeDefined();
		const imageSource = imageBlock?.['source'] as Record<string, unknown>;
		expect(imageSource['type']).toBe('url');
		expect(imageSource['url']).toBe('https://cdn.example.com/ref.png');
		expect(textBlock).toBeDefined();
		const textBlockText = textBlock?.['text'] as string;
		expect(textBlockText.includes('Build a 3-card pricing grid')).toBe(true);
	});

	it('passes base64 image data with the requested media_type', async () => {
		await promptToSpec({
			prompt: 'Convert this',
			imageBase64: 'AAECAwQFBg==',
			imageMediaType: 'image/jpeg',
		});

		const call = lastCreateParams();
		const messages = call['messages'] as Array<{ content: Array<Record<string, unknown>> }>;
		const content = messages[0]?.content ?? [];
		const imageBlock = content.find((b) => b['type'] === 'image');
		const source = imageBlock?.['source'] as Record<string, unknown>;
		expect(source['type']).toBe('base64');
		expect(source['media_type']).toBe('image/jpeg');
		expect(source['data']).toBe('AAECAwQFBg==');
	});

	it('includes a mobile viewport hint in the user prompt when viewport=mobile', async () => {
		await promptToSpec({
			prompt: 'Section spec',
			imageUrl: 'https://example.com/x.png',
			viewport: 'mobile',
		});
		const call = lastCreateParams();
		const messages = call['messages'] as Array<{ content: Array<Record<string, unknown>> }>;
		const textBlock = messages[0]?.content.find((b) => b['type'] === 'text');
		const text = textBlock?.['text'] as string;
		expect(text.toLowerCase()).toContain('mobile');
	});

	it('includes a desktop viewport hint when viewport=desktop', async () => {
		await promptToSpec({
			prompt: 'Section spec',
			imageUrl: 'https://example.com/x.png',
			viewport: 'desktop',
		});
		const call = lastCreateParams();
		const messages = call['messages'] as Array<{ content: Array<Record<string, unknown>> }>;
		const textBlock = messages[0]?.content.find((b) => b['type'] === 'text');
		const text = textBlock?.['text'] as string;
		expect(text.toLowerCase()).toContain('desktop');
	});
});

describe('promptToSpec — prompt caching', () => {
	beforeEach(() => {
		process.env['ANTHROPIC_API_KEY'] = 'sk-ant-test-key';
		resetMockState();
	});

	afterEach(() => {
		delete process.env['ANTHROPIC_API_KEY'];
	});

	it('marks the last system block with cache_control: ephemeral', async () => {
		await promptToSpec({
			prompt: 'Anything',
			imageUrl: 'https://example.com/x.png',
		});

		const call = lastCreateParams();
		const system = call['system'] as Array<Record<string, unknown>>;
		expect(Array.isArray(system)).toBe(true);
		expect(system.length).toBeGreaterThanOrEqual(2);

		const lastBlock = system[system.length - 1];
		expect(lastBlock).toBeDefined();
		expect(lastBlock?.['cache_control']).toEqual({ type: 'ephemeral' });

		// The schema reference is what we're trying to cache, so the cacheable
		// block's text must actually mention the DesignSpec.
		const lastBlockText = lastBlock?.['text'] as string;
		expect(lastBlockText.includes('DesignSpec')).toBe(true);
	});

	it('does NOT put cache_control on the per-request user message', async () => {
		await promptToSpec({
			prompt: 'Anything',
			imageUrl: 'https://example.com/x.png',
		});
		const call = lastCreateParams();
		const messages = call['messages'] as Array<{ content: Array<Record<string, unknown>> }>;
		const content = messages[0]?.content ?? [];
		for (const block of content) {
			expect(block['cache_control']).toBeUndefined();
		}
	});
});

describe('promptToSpec — error surfacing', () => {
	beforeEach(() => {
		process.env['ANTHROPIC_API_KEY'] = 'sk-ant-test-key';
		resetMockState();
	});

	afterEach(() => {
		delete process.env['ANTHROPIC_API_KEY'];
	});

	it('wraps Anthropic API errors in PromptToSpecError(api_error) with status in detail', async () => {
		const apiErr = Object.assign(new Error('rate limit exceeded'), { status: 429 });
		mockState.throwError = apiErr;
		// Capture the thrown error so we can assert directly on its fields without
		// using expect.stringContaining (which returns `any` and trips no-unsafe-assignment).
		let caught: unknown;
		try {
			await promptToSpec({
				prompt: 'foo',
				imageUrl: 'https://example.com/x.png',
			});
		} catch (err) {
			caught = err;
		}
		expect(caught).toBeInstanceOf(PromptToSpecError);
		const err = caught as PromptToSpecError;
		expect(err.code).toBe('api_error');
		expect(err.detail ?? '').toContain('429');
		expect(err.detail ?? '').toContain('rate limit exceeded');
	});

	it('throws PromptToSpecError(invalid_json) when model output is not parseable JSON', async () => {
		mockState.responseText = 'sorry, I cannot help with that';
		await expect(
			promptToSpec({
				prompt: 'foo',
				imageUrl: 'https://example.com/x.png',
			}),
		).rejects.toMatchObject({ code: 'invalid_json' });
	});

	it('throws PromptToSpecError(invalid_shape) when JSON parses but is missing required fields', async () => {
		mockState.responseText = JSON.stringify({ version: '1.0.0' });
		await expect(
			promptToSpec({
				prompt: 'foo',
				imageUrl: 'https://example.com/x.png',
			}),
		).rejects.toMatchObject({ code: 'invalid_shape' });
	});

	it('throws PromptToSpecError(no_text_response) when content array has no text block', async () => {
		mockState.contentOverride = [];
		await expect(
			promptToSpec({
				prompt: 'foo',
				imageUrl: 'https://example.com/x.png',
			}),
		).rejects.toMatchObject({ code: 'no_text_response' });
	});

	it('PromptToSpecError instances are structured (have code field, are Error subclass)', () => {
		const err = new PromptToSpecError('test_code', 'test message', 'test detail');
		expect(err).toBeInstanceOf(Error);
		expect(err.name).toBe('PromptToSpecError');
		expect(err.code).toBe('test_code');
		expect(err.message).toBe('test message');
		expect(err.detail).toBe('test detail');
	});
});

describe('stripCodeFences (unit test of helper)', () => {
	it('strips ```json fences', () => {
		expect(__test__.stripCodeFences('```json\n{"a":1}\n```')).toBe('{"a":1}');
	});

	it('strips bare ``` fences', () => {
		expect(__test__.stripCodeFences('```\n{"a":1}\n```')).toBe('{"a":1}');
	});

	it('returns text unchanged when there are no fences', () => {
		expect(__test__.stripCodeFences('{"a":1}')).toBe('{"a":1}');
	});

	it('trims whitespace', () => {
		expect(__test__.stripCodeFences('  \n{"a":1}\n  ')).toBe('{"a":1}');
	});
});
