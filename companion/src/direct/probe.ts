import { appendDirectAudit, defaultAuditPath } from './audit.js';
import { backupRestore, BACKUP_RESTORE_TOOL } from './backups.js';
import { fetchPublicPage } from './media-source.js';
import { isLocalHttpHost } from './wp-rest-client.js';
import type { DirectToolContext } from './tools/types.js';

/**
 * Front-end probe after a page, post or template write.
 *
 * After the write the public page is fetched and checked: HTTP 200, a non-empty body, no PHP fatal or
 * parse error text and not the WordPress critical-error page. The fetch is the same pinned, redirect-checked
 * fetch the media download uses; only the configured local development host may be a non-public address.
 *
 * Defaults: on for a local site (localhost, `.test`, `.local`, loopback), off for a remote site, because a
 * remote probe is one more request to a live site that may sit behind a cache, a firewall or a login. A call can
 * ask for it with `probe:true`, and `STONEWRIGHT_DIRECT_PROBE=on|off` sets the default.
 *
 * A failed probe never changes the site by itself. It returns the saved copy to restore from; only
 * `rollback_on_probe_failure:true` restores it, and that restore is a normal, audited, undoable restore.
 */

export const PROBE_ENV = 'STONEWRIGHT_DIRECT_PROBE';

export interface ProbeOptions {
	probe?: boolean | undefined;
	rollback_on_probe_failure?: boolean | undefined;
}

export type ProbeStatus = 'passed' | 'failed' | 'unavailable' | 'skipped';

export interface ProbeOutcome {
	status: ProbeStatus;
	url?: string;
	http_status?: number;
	bytes?: number;
	reasons: string[];
}

export type ProbeTarget =
	| { kind: 'home' }
	/** A published page or post: its permalink as WordPress returned it. */
	| { kind: 'page'; status: string | undefined; link: string | undefined };

const PHP_FATAL = /(?:<b>)?(?:PHP )?(?:Fatal error|Parse error)(?:<\/b>)?\s*:\s|Uncaught (?:[A-Za-z\\]*Error|[A-Za-z\\]*Exception)\b/i;
const WP_CRITICAL = /There has been a critical error on this website|id=["']error-page["']|class=["']wp-die-message["']|Error establishing a database connection/i;

function envSwitch(env: NodeJS.ProcessEnv): boolean | undefined {
	const raw = (env[PROBE_ENV] ?? '').trim().toLowerCase();
	if (raw === 'on') return true;
	if (raw === 'off') return false;
	return undefined;
}

function isLocalSite(url: string): boolean {
	try {
		return isLocalHttpHost(new URL(url).hostname);
	} catch {
		return false;
	}
}

/** Whether to probe, from the call, the environment and the kind of site. */
export function probeWanted(input: ProbeOptions, site: { url: string }, env: NodeJS.ProcessEnv): boolean {
	if (typeof input.probe === 'boolean') return input.probe;
	// A call that asks for a rollback needs the check, whatever the default is.
	if (input.rollback_on_probe_failure === true) return true;
	const configured = envSwitch(env);
	if (configured !== undefined) return configured;
	return isLocalSite(site.url);
}

function homeUrl(siteUrl: string): string {
	const url = new URL(siteUrl);
	if (!url.pathname.endsWith('/')) url.pathname = `${url.pathname}/`;
	return url.toString();
}

function sameHost(a: string, b: string): boolean {
	try {
		return new URL(a).host.toLowerCase() === new URL(b).host.toLowerCase();
	} catch {
		return false;
	}
}

function resolveUrl(target: ProbeTarget, siteUrl: string): { url: string } | { skipped: string } {
	if (target.kind === 'home') return { url: homeUrl(siteUrl) };
	if (target.status !== 'publish') return { skipped: 'not_published' };
	if (!target.link) return { skipped: 'no_public_url' };
	if (!sameHost(target.link, siteUrl)) return { skipped: 'link_not_on_site' };
	return { url: target.link };
}

/** Text a marker may legitimately appear in because the write itself put it there. */
function legitimate(pattern: RegExp, sent: string | undefined): boolean {
	return sent !== undefined && pattern.test(sent);
}

function failureCode(error: unknown): string {
	const text = error instanceof Error ? error.message : String(error);
	return /code=([a-z_]+)/.exec(text)?.[1] ?? 'fetch_failed';
}

export async function probeFrontEnd(
	ctx: DirectToolContext,
	target: ProbeTarget,
	sentText?: string,
): Promise<ProbeOutcome> {
	const resolved = resolveUrl(target, ctx.site.url);
	if ('skipped' in resolved) return { status: 'skipped', reasons: [resolved.skipped] };
	let host = '';
	try {
		host = new URL(ctx.site.url).hostname;
	} catch {
		return { status: 'skipped', reasons: ['site_url_invalid'] };
	}
	let page: Awaited<ReturnType<typeof fetchPublicPage>>;
	try {
		page = await fetchPublicPage(resolved.url, {
			fetchImpl: ctx.fetchImpl,
			lookupImpl: ctx.lookupImpl,
			// Only a local development site may be reached on a non-public address, and only itself.
			...(isLocalHttpHost(host) ? { trustedHost: host } : {}),
		});
	} catch (error) {
		return { status: 'unavailable', url: resolved.url, reasons: [failureCode(error)] };
	}
	const reasons: string[] = [];
	if (page.status !== 200) reasons.push(`http_${page.status}`);
	if (page.text.trim() === '') reasons.push('empty_body');
	if (PHP_FATAL.test(page.text) && !legitimate(PHP_FATAL, sentText)) reasons.push('php_fatal_error');
	if (WP_CRITICAL.test(page.text) && !legitimate(WP_CRITICAL, sentText)) reasons.push('wp_critical_error_page');
	return {
		status: reasons.length === 0 ? 'passed' : 'failed',
		url: page.finalUrl.toString(),
		http_status: page.status,
		bytes: Buffer.byteLength(page.text, 'utf8'),
		reasons,
	};
}

export interface ProbeStep {
	tool: string;
	/** Audit label of the resource that was written. */
	resource: string;
	input: ProbeOptions;
	target: ProbeTarget;
	/** The text the write put on the site, so a page that talks about PHP errors does not trip the check. */
	sentText?: string | undefined;
	/** The saved copy of the resource before the write; absent for a newly created resource. */
	backupId?: string | undefined;
}

type Result = Record<string, unknown>;

/** Runs the probe for a finished write and returns the result with the outcome attached. */
export async function probeAfterWrite<T extends object>(
	ctx: DirectToolContext,
	step: ProbeStep,
	result: T,
): Promise<T & Record<string, unknown>> {
	const env = ctx.env ?? process.env;
	const wanted = probeWanted(step.input, ctx.site, env);
	const asked = step.input.probe === true || step.input.rollback_on_probe_failure === true;
	if (!wanted) {
		return (asked
			? { ...result, probe: { status: 'skipped', reasons: ['probe_disabled'] } }
			: result) as T & Record<string, unknown>;
	}
	const outcome = await probeFrontEnd(ctx, step.target, step.sentText);
	const base: Result = { ...result, probe: outcome };
	if (outcome.status === 'skipped' && !asked) return result as T & Record<string, unknown>;
	if (outcome.status !== 'failed') {
		return { ...base, ...(outcome.status === 'passed' ? { site_status: 'ok' } : {}) } as T & Record<string, unknown>;
	}

	const auditPath = defaultAuditPath(env);
	const summary = `GET ${outcome.url ?? ''} -> ${outcome.http_status ?? 'no response'}: ${outcome.reasons.join(', ')}`.slice(0, 300);
	try {
		appendDirectAudit(
			{
				tool: step.tool,
				site: ctx.site.alias,
				targetIdentity: ctx.site.url,
				resource: step.resource,
				status: 'error',
				code: 'frontend_probe_failed',
				error: summary,
				eventType: 'direct_probe',
				category: 'READ',
				smokeSummary: summary,
			},
			auditPath,
		);
	} catch {
		// best effort
	}

	// A write that already failed its readback keeps that error; the probe result is added next to it.
	const failure = (extra: Result, advice: string): T & Record<string, unknown> => {
		const out: Result = { ...base, ...extra };
		if (base['ok'] !== false) {
			out['ok'] = false;
			out['error'] = 'frontend_probe_failed';
			out['written'] = true;
			out['message'] = `The write was accepted, but the public page now fails its check (${outcome.reasons.join(', ')}). ${advice}`;
		} else {
			out['probe_advice'] = advice;
		}
		return out as T & Record<string, unknown>;
	};

	if (!step.backupId) {
		return failure(
			{ site_status: 'probe_failed', rollback_offer: null },
			'There is no saved copy because the resource was just created. Fix it, or remove it.',
		);
	}
	if (step.input.rollback_on_probe_failure !== true) {
		const offer = { tool: BACKUP_RESTORE_TOOL, args: { backup_id: step.backupId }, needs: 'confirmation' };
		return failure(
			{ site_status: 'probe_failed', rollback_offer: offer },
			'Restore the saved copy with rollback_offer, or fix it. Nothing was rolled back.',
		);
	}

	try {
		const restored = await backupRestore(ctx, { backup_id: step.backupId, confirm: true }, { confirmed: true });
		const again = await probeFrontEnd(ctx, step.target, step.sentText);
		const recovered = again.status === 'passed';
		return failure(
			{
				site_status: recovered ? 'rolled_back' : 'rolled_back_still_failing',
				rolled_back: true,
				rollback: {
					status: 'restored',
					restored_from: step.backupId,
					undo_backup_id: restored['backup_id'],
					verification: restored['verification'],
				},
				probe_after_rollback: again,
			},
			recovered
				? 'The saved copy was restored automatically (rollback_on_probe_failure) and the page passes again.'
				: 'The saved copy was restored automatically but the page still fails; the cause is not this write.',
		);
	} catch (error) {
		const code = error && typeof error === 'object' && 'code' in error ? String((error as { code?: unknown }).code) : 'error';
		return failure(
			{
				site_status: 'rollback_failed',
				rolled_back: false,
				rollback: { status: 'failed', code, message: (error instanceof Error ? error.message : String(error)).slice(0, 200) },
			},
			'Rolling back failed. Restore the saved copy by hand.',
		);
	}
}
