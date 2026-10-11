import net from 'node:net';

/**
 * Network guard for the test suite. A test may talk to this machine (loopback) and nothing else:
 * a connection to any other address fails before DNS and before a packet is sent. A test that
 * really needs a remote host names it with `allowTestNetworkHost`.
 */

const LOOPBACK = /^(localhost|127\.\d+\.\d+\.\d+|::1|\[::1\]|0\.0\.0\.0|::ffff:127\.\d+\.\d+\.\d+)$/i;

const allowed = new Set<string>();
const blockedAttempts: string[] = [];

export function allowTestNetworkHost(host: string): void {
	allowed.add(host.toLowerCase());
}

export function resetTestNetworkAllowList(): void {
	allowed.clear();
}

export function isTestNetworkAllowed(host: string): boolean {
	const h = host.toLowerCase();
	return h === '' || LOOPBACK.test(h) || allowed.has(h);
}

/** Hosts a test tried to reach and was refused, in order. */
export function blockedTestNetworkAttempts(): readonly string[] {
	return blockedAttempts;
}

type LookupFn = (hostname: string, options: unknown, callback: (...args: unknown[]) => void) => void;

function guardedLookup(inner: LookupFn, host: string): LookupFn {
	return (hostname, options, callback) => {
		inner(hostname, options, (...result: unknown[]) => {
			const [error, address] = result;
			const addresses = Array.isArray(address)
				? (address as Array<{ address?: string }>).map((row) => String(row.address ?? ''))
				: [String(address ?? '')];
			if (!error && addresses.some((row) => !isTestNetworkAllowed(row))) {
				blockedAttempts.push(host);
				callback(new Error(`Test network guard: ${host} resolved to a non-local address and the connection was blocked.`));
				return;
			}
			callback(...result);
		});
	};
}

let installed = false;

export function installTestNetworkGuard(): void {
	if (installed) return;
	installed = true;
	// eslint-disable-next-line @typescript-eslint/unbound-method -- called back with the socket as `this` below
	const original = net.Socket.prototype.connect as unknown as (this: net.Socket, ...args: unknown[]) => net.Socket;
	net.Socket.prototype.connect = function guardedConnect(this: net.Socket, ...args: unknown[]): net.Socket {
		// net.connect hands over already normalized arguments: [options, callback].
		const first = Array.isArray(args[0]) ? (args[0] as unknown[])[0] : args[0];
		let host = 'localhost';
		if (first !== null && typeof first === 'object') {
			const options = first as { host?: string; path?: string; lookup?: unknown };
			// A named pipe or Unix socket is local.
			if (options.path) return original.apply(this, args);
			host = options.host ?? 'localhost';
			// A name that the caller resolves itself (a pinned address) is fine when the answer is local.
			if (!isTestNetworkAllowed(host) && typeof options.lookup === 'function') {
				options.lookup = guardedLookup(options.lookup as LookupFn, host);
				return original.apply(this, args);
			}
		} else if (typeof args[1] === 'string') {
			host = args[1];
		}
		if (!isTestNetworkAllowed(host)) {
			blockedAttempts.push(host);
			const refused = new Error(`Test network guard: a connection to ${host} was blocked. Tests may reach loopback only; use allowTestNetworkHost for an exception.`);
			queueMicrotask(() => this.destroy(refused));
			return this;
		}
		return original.apply(this, args);
	} as typeof net.Socket.prototype.connect;
}
