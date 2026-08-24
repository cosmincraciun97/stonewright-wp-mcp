import { createHash } from 'node:crypto';
import { DirectIncidentStore } from '../../src/direct/incidents.js';

const [baseDir, action, worker, countRaw, incidentId = ''] = process.argv.slice(2);
if (!baseDir || !action || !worker) process.exit(2);
const count = Math.max(1, Number(countRaw ?? 1));
const siteFingerprint = createHash('sha256').update('site-a').digest('hex');
const store = new DirectIncidentStore(baseDir, siteFingerprint);

for (let index = 0; index < count; index += 1) {
	if (action === 'failure') {
		store.observeFailure({
			event_id: `${worker}-${index}`,
			correlation_id: `${worker}-${index}`,
			idempotency_key: createHash('sha256').update(`${worker}|${index}`).digest('hex'),
			ability: 'stonewright-content-update',
			error_code: 'write_failed',
			cause_key: 'content-update|write_failed',
			severity: 'high',
			timestamp: new Date(Date.UTC(2026, 7, 24, 8, 0, index)).toISOString(),
		});
	} else if (action === 'resolve' && incidentId) {
		store.markResolved(incidentId, {
			repair_receipt_id: createHash('sha256').update(`repair|${worker}|${index}`).digest('hex'),
			resolution_event_id: `${worker}-${index}`,
			resolved_at: new Date(Date.UTC(2026, 7, 24, 9, 0, index)).toISOString(),
		});
	} else {
		process.exit(3);
	}
}
