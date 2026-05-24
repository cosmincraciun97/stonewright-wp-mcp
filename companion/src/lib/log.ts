/**
 * Structured logger — thin wrapper around console with pino-style levels.
 *
 * Writes JSON lines to **stderr** at every level. This is non-negotiable for the
 * stdio MCP transport: stdout is the JSON-RPC channel, so any informational
 * line on stdout corrupts the protocol stream (the client tries to parse
 * `{level,time,msg}` records as JSON-RPC and rejects them with
 * `unrecognized_keys` / `invalid_union`). Log aggregators tail stderr too, so
 * we lose nothing by being strict here.
 */

export type LogLevel = 'debug' | 'info' | 'warn' | 'error';

export interface LogRecord {
	level: LogLevel;
	time: string;
	msg: string;
	[key: string]: unknown;
}

const LEVEL_RANK: Record<LogLevel, number> = { debug: 10, info: 20, warn: 30, error: 40 };

function resolveMinLevel(): LogLevel {
	const raw = process.env['LOG_LEVEL']?.toLowerCase();
	if (raw && raw in LEVEL_RANK) return raw as LogLevel;
	return process.env['NODE_ENV'] === 'production' ? 'info' : 'debug';
}

const minRank = LEVEL_RANK[resolveMinLevel()];

function emit(level: LogLevel, msg: string, extra: Record<string, unknown> = {}): void {
	if (LEVEL_RANK[level] < minRank) return;
	const record: LogRecord = { level, time: new Date().toISOString(), msg, ...extra };
	const line = JSON.stringify(record);
	// All levels go to stderr — stdout is reserved for the MCP JSON-RPC stream.
	process.stderr.write(line + '\n');
}

export const log = {
	debug(msg: string, extra?: Record<string, unknown>): void { emit('debug', msg, extra); },
	info(msg: string, extra?: Record<string, unknown>): void { emit('info', msg, extra); },
	warn(msg: string, extra?: Record<string, unknown>): void { emit('warn', msg, extra); },
	error(msg: string, extra?: Record<string, unknown>): void { emit('error', msg, extra); },
	child(context: Record<string, unknown>) {
		return {
			debug(msg: string, extra?: Record<string, unknown>): void { emit('debug', msg, { ...context, ...extra }); },
			info(msg: string, extra?: Record<string, unknown>): void { emit('info', msg, { ...context, ...extra }); },
			warn(msg: string, extra?: Record<string, unknown>): void { emit('warn', msg, { ...context, ...extra }); },
			error(msg: string, extra?: Record<string, unknown>): void { emit('error', msg, { ...context, ...extra }); },
		};
	},
};
