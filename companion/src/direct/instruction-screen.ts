/**
 * Screens text for instructions that would turn an agent against the plugin's rules.
 *
 * This is the same screen the plugin applies to imported skills and to memory
 * (plugin/includes/SkillLibrary/Site/InstructionScreen.php), written the same way so a memory entry is
 * judged alike in Plugin and Direct mode. plugin/tests/fixtures/memory-screen-cases.json holds the shared cases.
 *
 * Three kinds of instruction are errors: telling an agent to ignore or override the plugin's rules, safety
 * gates or earlier instructions; telling it to disable or skip confirmation tokens; and telling it to send
 * credentials or secrets to an external destination. The same words written as a prohibition ("never",
 * "do not") or reported as someone else's request are warnings, and so is any other mention of
 * credentials.
 */

export type ScreenSeverity = 'error' | 'warning';

export type ScreenFinding = { rule: string; severity: ScreenSeverity; line: number };

const OVERRIDE = new RegExp(
	String.raw`\b(?:ignore|disregard|override|bypass|circumvent|forget|skip|defeat|work around|turn off|switch off|disable)\b[^.!?;\n]{0,60}?\b(?:hard rules?|operating rules?|safety (?:rules?|gates?|checks?)|security (?:rules?|gates?|checks?)|guard ?rails?|permission (?:checks?|gates?|callbacks?)|approval (?:gates?|steps?)|(?:previous|prior|earlier|system|above) instructions?|system prompt)\b`,
	'i',
);

const TOKEN_OVERRIDE = new RegExp(
	String.raw`\b(?:disable|turn off|switch off|skip|bypass|remove|drop|omit|ignore|circumvent|forge|fake)\b[^.!?;\n]{0,40}?\bconfirmation[ _-]?tokens?\b`,
	'i',
);

const VERB = String.raw`\b(?:send|post|upload|forward|transmit|exfiltrate|share|leak|e-?mail|copy|submit|push)\b`;

const SENSITIVE = String.raw`\b(?:passwords?|passphrases?|credentials?|secrets?|api[ _-]?keys?|access[ _-]tokens?|refresh[ _-]tokens?|bearer[ _-]tokens?|application[ _-]passwords?|app[ _-]passwords?|cookies?|private[ _-]keys?|wp-config(?:\.php)?)\b`;

const PLACE = String.raw`(?:https?:\/\/|\bwebhooks?\b|\b(?:external|remote|third[- ]party|outside|unknown)\b[^.!?;\n]{0,25}?\b(?:urls?|servers?|endpoints?|sites?|services?|address(?:es)?|hosts?|domains?|inbox(?:es)?)\b)`;

/** A verb, then a secret, then where it goes; or a verb, then where, then the secret. */
const OUTBOUND = [
	new RegExp(
		`${VERB}[^.!?;\\n]{0,80}?${SENSITIVE}[^.!?;\\n]{0,60}?\\b(?:to|into|at|via|on)\\b[^.!?;\\n]{0,20}?${PLACE}`,
		'i',
	),
	new RegExp(
		`${VERB}[^.!?;\\n]{0,20}?\\b(?:to|at|via)\\b[^.!?;\\n]{0,20}?${PLACE}[^.!?;\\n]{0,80}?${SENSITIVE}`,
		'i',
	),
];

/** A prohibition or a reported request right before the instruction verb. */
const DISARMED = new RegExp(
	String.raw`(?:\b(?:never|not|no|don'?t|doesn'?t|mustn'?t|shouldn'?t|cannot|can'?t|won'?t|avoid|refuse to)\s+(?:\w+\s+){0,2}|\b(?:asks?|asked|tells?|told|wants?|requests?|instructs?|tries|tried|attempts?)\s+(?:\w+\s+){0,3}?to\s+)$`,
	'i',
);

/**
 * Characters read before an instruction verb to find a disarming phrase. A phrase spans at most five words,
 * so one that starts further back is not read and the verb counts as an instruction.
 */
const DISARM_REACH = 256;

const SENSITIVE_ANYWHERE = new RegExp(SENSITIVE, 'i');

/**
 * Whether the words right before the instruction verb at `start` disarm it. Only the last DISARM_REACH
 * characters are read, so a long sentence takes time in proportion to its length.
 */
function disarmed(sentence: string, start: number): boolean {
	const from = Math.max(0, start - DISARM_REACH);
	const before = sentence.slice(from, start);
	// A word cut in half by the start of the stretch must not read as a whole disarming word.
	return DISARMED.test(from > 0 ? `x${before}` : before);
}

/**
 * An error when a match at any starting point is an instruction; a warning when every match is disarmed.
 * Starting points may overlap, so a disarmed verb does not hide a later one in the same sentence.
 */
function severity(patterns: readonly RegExp[], sentence: string): ScreenSeverity | null {
	let result: ScreenSeverity | null = null;
	for (const pattern of patterns) {
		const search = new RegExp(pattern.source, 'gi');
		let offset = 0;
		while (offset < sentence.length) {
			search.lastIndex = offset;
			const match = search.exec(sentence);
			if (match === null) break;
			if (!disarmed(sentence, match.index)) return 'error';
			result = 'warning';
			offset = match.index + 1;
		}
	}
	return result;
}

function sentenceFindings(rawSentence: string): Map<string, ScreenSeverity> {
	// Web addresses become one placeholder, so their dots and path words are not read as prose.
	const sentence = rawSentence.replace(/\bhttps?:\/\/\S+/gi, 'https://link');
	const found = new Map<string, ScreenSeverity>();
	const checks: Array<[string, readonly RegExp[]]> = [
		['safety_override', [OVERRIDE]],
		['confirmation_override', [TOKEN_OVERRIDE]],
		['credential_exfiltration', OUTBOUND],
	];
	for (const [rule, patterns] of checks) {
		const level = severity(patterns, sentence);
		if (level !== null) found.set(rule, level);
	}
	if (!found.has('credential_exfiltration') && SENSITIVE_ANYWHERE.test(sentence)) {
		found.set('credential_mention', 'warning');
	}
	return found;
}

/**
 * Findings for a description (line 0) and a body (lines counted from 1). Each rule is reported once per
 * severity, at its first line.
 */
export function screenFindings(description: string, body: string): ScreenFinding[] {
	const lines = [description, ...body.replace(/\r\n?/g, '\n').split('\n')];
	const found = new Map<string, ScreenFinding>();
	lines.forEach((line, number) => {
		for (const sentence of line.split(/(?<=[.!?;])\s+/)) {
			for (const [rule, level] of sentenceFindings(sentence)) {
				const key = `${rule}:${level}`;
				if (!found.has(key)) found.set(key, { rule, severity: level, line: number });
			}
		}
	});
	return [...found.values()];
}

/**
 * The rules a memory text breaks as an instruction. A prohibition and a mention of credentials are
 * warnings in the screen and do not count here.
 */
export function memoryBlockingRules(text: string, name = ''): string[] {
	const rules: string[] = [];
	for (const finding of screenFindings(name, text)) {
		if (finding.severity === 'error' && !rules.includes(finding.rule)) rules.push(finding.rule);
	}
	return rules;
}
