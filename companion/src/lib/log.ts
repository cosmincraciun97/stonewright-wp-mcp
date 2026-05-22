/**
 * Structured logger — thin wrapper around console with pino-style levels.
 * Writes JSON lines to stdout so any log aggregator can parse them.
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
	if (level === 'error' || level === 'warn') {
		process.stderr.write(line + '\n');
	} else {
		process.stdout.write(line + '\n');
	}
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
