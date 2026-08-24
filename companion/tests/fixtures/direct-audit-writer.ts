import { appendDirectAudit } from '../../src/direct/audit.js';

const [path, worker, countRaw] = process.argv.slice(2);
if (!path || !worker) process.exit(2);
const count = Number(countRaw ?? 1);
for (let index = 0; index < count; index += 1) {
	appendDirectAudit({
		tool: 'stonewright-content-update',
		site: 'site-a',
		resource: `post:${worker}-${index}`,
		status: 'ok',
		eventId: `${worker === 'a' ? 'aaaaaaaa' : 'bbbbbbbb'}-0000-4000-8000-${String(index).padStart(12, '0')}`,
		operationId: `${worker === 'a' ? 'cccccccc' : 'dddddddd'}-0000-4000-8000-${String(index).padStart(12, '0')}`,
		idempotencyKey: `${worker}:${index}`,
		payload: { worker, index },
	}, path, { maxBytes: 300, maxAgeMs: 86_400_000, maxFiles: 100 });
}
