import { describe, it, expect, vi, afterEach } from 'vitest';
import { log } from '../src/lib/log.js';

describe('log', () => {
	afterEach(() => vi.restoreAllMocks());

	it('writes a JSON line to stdout for info', () => {
		const write = vi.spyOn(process.stdout, 'write').mockImplementation(() => true);
		log.info('hello', { foo: 'bar' });
		expect(write).toHaveBeenCalledOnce();
		const line = write.mock.calls[0]?.[0] as string;
		const parsed = JSON.parse(line) as Record<string, unknown>;
		expect(parsed['level']).toBe('info');
		expect(parsed['msg']).toBe('hello');
		expect(parsed['foo']).toBe('bar');
	});

	it('writes a JSON line to stderr for error', () => {
		const write = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);
		log.error('boom');
		expect(write).toHaveBeenCalledOnce();
		const line = write.mock.calls[0]?.[0] as string;
		const parsed = JSON.parse(line) as Record<string, unknown>;
		expect(parsed['level']).toBe('error');
	});

	it('child logger merges context', () => {
		const write = vi.spyOn(process.stdout, 'write').mockImplementation(() => true);
		const child = log.child({ service: 'test' });
		child.info('nested');
		const line = write.mock.calls[0]?.[0] as string;
		const parsed = JSON.parse(line) as Record<string, unknown>;
		expect(parsed['service']).toBe('test');
		expect(parsed['msg']).toBe('nested');
	});
});
