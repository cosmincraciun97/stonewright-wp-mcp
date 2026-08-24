/**
 * Stop the suite before 180 viewport retries when WordPress itself is down.
 * The recovery screen has no Stonewright chrome, so `.sw-shell` waits hide
 * the PHP error and burn the 45-minute job budget.
 */
export default async function globalSetup(): Promise<void> {
	const baseURL = (process.env.WP_BASE_URL ?? 'http://localhost:8888').replace(/\/$/, '');
	const url = `${baseURL}/wp-admin/`;
	let response: Response;
	try {
		response = await fetch(url, { redirect: 'follow' });
	} catch (error) {
		throw new Error(
			`WordPress is unreachable at ${url}: ${error instanceof Error ? error.message : String(error)}`,
		);
	}

	const html = await response.text();
	const text = html
		.replace(/<script[\s\S]*?<\/script>/gi, ' ')
		.replace(/<style[\s\S]*?<\/style>/gi, ' ')
		.replace(/<[^>]+>/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();

	if (
		/There has been a critical error/i.test(text) ||
		/Fatal error:/i.test(text) ||
		/Parse error:/i.test(text) ||
		/Uncaught (?:Error|TypeError|Exception|Throwable)/i.test(text)
	) {
		throw new Error(`WordPress PHP fatal on ${url} (HTTP ${response.status}): ${text.slice(0, 2500)}`);
	}

	if (!response.ok && response.status >= 500) {
		throw new Error(`WordPress HTTP ${response.status} on ${url}: ${text.slice(0, 2500)}`);
	}
}
