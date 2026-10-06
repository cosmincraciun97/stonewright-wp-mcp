<?php
/**
 * Screens imported skill text for instructions that would turn guidance against the site.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

/**
 * Three kinds of instruction keep a file out of the site: telling an agent to
 * ignore or override the plugin's rules, safety gates, or earlier instructions;
 * telling it to disable or skip confirmation tokens; and telling it to send
 * credentials or secrets to an external destination. The same words written as
 * a prohibition ("never", "do not") or reported as someone else's request are
 * warnings, and so is any other mention of credentials, so a reviewer reads it.
 */
final class InstructionScreen {

	private const OVERRIDE = '/\b(?:ignore|disregard|override|bypass|circumvent|forget|skip|defeat|work around|turn off|switch off|disable)\b[^.!?;\n]{0,60}?\b(?:hard rules?|operating rules?|safety (?:rules?|gates?|checks?)|security (?:rules?|gates?|checks?)|guard ?rails?|permission (?:checks?|gates?|callbacks?)|approval (?:gates?|steps?)|(?:previous|prior|earlier|system|above) instructions?|system prompt)\b/i';

	private const TOKEN_OVERRIDE = '/\b(?:disable|turn off|switch off|skip|bypass|remove|drop|omit|ignore|circumvent|forge|fake)\b[^.!?;\n]{0,40}?\bconfirmation[ _-]?tokens?\b/i';

	private const VERB = '\b(?:send|post|upload|forward|transmit|exfiltrate|share|leak|e-?mail|copy|submit|push)\b';

	private const SENSITIVE = '\b(?:passwords?|passphrases?|credentials?|secrets?|api[ _-]?keys?|access[ _-]tokens?|refresh[ _-]tokens?|bearer[ _-]tokens?|application[ _-]passwords?|app[ _-]passwords?|cookies?|private[ _-]keys?|wp-config(?:\.php)?)\b';

	private const PLACE = '(?:https?:\/\/|\bwebhooks?\b|\b(?:external|remote|third[- ]party|outside|unknown)\b[^.!?;\n]{0,25}?\b(?:urls?|servers?|endpoints?|sites?|services?|address(?:es)?|hosts?|domains?|inbox(?:es)?)\b)';

	/** A verb, then a secret, then where it goes; or a verb, then where, then the secret. */
	private const OUTBOUND = [
		'/' . self::VERB . '[^.!?;\n]{0,80}?' . self::SENSITIVE . '[^.!?;\n]{0,60}?\b(?:to|into|at|via|on)\b[^.!?;\n]{0,20}?' . self::PLACE . '/i',
		'/' . self::VERB . '[^.!?;\n]{0,20}?\b(?:to|at|via)\b[^.!?;\n]{0,20}?' . self::PLACE . '[^.!?;\n]{0,80}?' . self::SENSITIVE . '/i',
	];

	/** A prohibition or a reported request right before the instruction verb. */
	private const DISARMED = '/(?:\b(?:never|not|no|don\'?t|doesn\'?t|mustn\'?t|shouldn\'?t|cannot|can\'?t|won\'?t|avoid|refuse to)\s+(?:\w+\s+){0,2}|\b(?:asks?|asked|tells?|told|wants?|requests?|instructs?|tries|tried|attempts?)\s+(?:\w+\s+){0,3}?to\s+)$/i';

	/**
	 * Bytes read before an instruction verb to find a disarming phrase. A phrase spans at most
	 * five words, so one that starts further back is not read and the verb counts as an instruction.
	 */
	private const DISARM_REACH = 256;

	private const MESSAGES = [
		'safety_override'         => [
			'error'   => 'The text tells an agent to ignore or override the plugin rules, safety gates, or earlier instructions.',
			'warning' => 'The text mentions overriding the plugin rules or safety gates as a prohibition or as someone else\'s request. Check that it reads that way.',
		],
		'confirmation_override'   => [
			'error'   => 'The text tells an agent to disable or skip confirmation tokens.',
			'warning' => 'The text mentions disabling confirmation tokens as a prohibition or as someone else\'s request. Check that it reads that way.',
		],
		'credential_exfiltration' => [
			'error'   => 'The text tells an agent to send credentials or secrets to an external destination.',
			'warning' => 'The text mentions sending credentials or secrets elsewhere as a prohibition or as someone else\'s request. Check that it reads that way.',
		],
		'credential_mention'      => [
			'warning' => 'The text mentions credentials or secrets. Check that it never asks an agent to reveal or move them.',
		],
	];

	/**
	 * Findings for a skill's description (line 0) and body (lines counted from 1).
	 * Each rule is reported once per severity, at its first line.
	 *
	 * @return array<int, array{rule: string, severity: string, message: string, line: int}>
	 */
	public static function findings( string $description, string $body ): array {
		$found = [];
		$lines = [ 0 => $description ];
		foreach ( explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $body ) ) as $index => $line ) {
			$lines[ $index + 1 ] = $line;
		}
		foreach ( $lines as $number => $line ) {
			foreach ( preg_split( '/(?<=[.!?;])\s+/', $line ) ?: [] as $sentence ) {
				foreach ( self::sentence_findings( $sentence ) as $rule => $severity ) {
					$found[ $rule . ':' . $severity ] ??= [
						'rule'     => $rule,
						'severity' => $severity,
						'message'  => self::MESSAGES[ $rule ][ $severity ],
						'line'     => $number,
					];
				}
			}
		}
		return array_values( $found );
	}

	/** @param array<int, array{rule: string, severity: string, message: string, line: int}> $findings */
	public static function blocks( array $findings ): bool {
		return in_array( 'error', array_column( $findings, 'severity' ), true );
	}

	/** @return array<string, string> Severity by rule. */
	private static function sentence_findings( string $sentence ): array {
		// Web addresses become one placeholder, so their dots and path words are not read as prose.
		$sentence = (string) preg_replace( '#\bhttps?://\S+#i', 'https://link', $sentence );
		$found    = [];
		$checks   = [
			'safety_override'         => [ self::OVERRIDE ],
			'confirmation_override'   => [ self::TOKEN_OVERRIDE ],
			'credential_exfiltration' => self::OUTBOUND,
		];
		foreach ( $checks as $rule => $patterns ) {
			$severity = self::severity( $patterns, $sentence );
			if ( null !== $severity ) {
				$found[ $rule ] = $severity;
			}
		}
		if ( ! isset( $found['credential_exfiltration'] ) && 1 === preg_match( '/' . self::SENSITIVE . '/i', $sentence ) ) {
			$found['credential_mention'] = 'warning';
		}
		return $found;
	}

	/**
	 * An error when a match at any starting point is an instruction; a warning when
	 * every match is disarmed. Starting points may overlap, so a disarmed verb does
	 * not hide a later one in the same sentence.
	 *
	 * @param array<int, string> $patterns
	 */
	private static function severity( array $patterns, string $sentence ): ?string {
		$severity = null;
		$length   = strlen( $sentence );
		foreach ( $patterns as $pattern ) {
			$offset = 0;
			while ( $offset < $length && 1 === preg_match( $pattern, $sentence, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				$start = (int) $match[0][1];
				if ( ! self::disarmed( $sentence, $start ) ) {
					return 'error';
				}
				$severity = 'warning';
				$offset   = $start + 1;
			}
		}
		return $severity;
	}

	/**
	 * Whether the words right before the instruction verb at $start disarm it. Only
	 * the last DISARM_REACH bytes are read, so a long sentence takes time in
	 * proportion to its length.
	 */
	private static function disarmed( string $sentence, int $start ): bool {
		$from   = max( 0, $start - self::DISARM_REACH );
		$before = substr( $sentence, $from, $start - $from );
		// A word cut in half by the start of the stretch must not read as a whole disarming word.
		return 1 === preg_match( self::DISARMED, $from > 0 ? 'x' . $before : $before );
	}
}
