<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/**
 * Reads an uploaded skill the way a suspicious reviewer would.
 *
 * An imported skill is instructions somebody else wrote that an agent will
 * later follow, so the scan looks for the things a hostile file would try:
 * asking for credentials, sending them somewhere, talking the agent out of its
 * own safety steps, hiding text where a human reviewer cannot see it, or
 * pretending to be tool output.
 *
 * The scan reports. It never rewrites the file, and a warning never becomes a
 * silent edit.
 *
 * @stonewright-status stable
 */
final class SkillImportSanitizer {

	public const SEVERITY_BLOCK   = 'block';
	public const SEVERITY_WARNING = 'warning';

	/** Characters that put text on the page without showing it to a reviewer. */
	private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

	/**
	 * Rules, in report order.
	 *
	 * @var array<string, array{pattern: string, severity: string, message: string}>
	 */
	private const RULES = [
		'credential_request'      => [
			'pattern'  => '/\b(?:ask|prompt|request|tell)\b[^.\n]{0,60}\b(?:for\s+(?:the|their|your)\s+)?(?:password|application\s+password|api[\s_-]?key|secret|credential|access\s+token)\b|\b(?:enter|type|paste|provide)\b[^.\n]{0,40}\b(?:password|api[\s_-]?key|secret\s+key|credit\s+card|credential)\b/i',
			'severity' => self::SEVERITY_BLOCK,
			'message'  => 'Asks for a credential.',
		],
		'credential_exfiltration' => [
			'pattern'  => '/\b(?:send|post|email|upload|transmit|forward|share|reveal|print|echo|leak|exfiltrat\w*)\b[^.\n]{0,60}\b(?:password|api[\s_-]?key|secret|credential|access\s+token|auth\s+token|private\s+key)\b/i',
			'severity' => self::SEVERITY_BLOCK,
			'message'  => 'Moves a credential somewhere else.',
		],
		'guardrail_bypass'        => [
			'pattern'  => '/\b(?:ignore|disregard|forget|override|bypass|skip|disable|turn\s+off|work\s+around)\b[^.\n]{0,60}\b(?:previous\s+instructions?|earlier\s+instructions?|system\s+prompt|safety|guardrails?|permission\s+checks?|confirmation\s+tokens?|backups?|validation|audit\s+log)\b/i',
			'severity' => self::SEVERITY_BLOCK,
			'message'  => 'Tells the agent to drop one of its own safety steps.',
		],
		'destructive_shell'       => [
			'pattern'  => '/\brm\s+-[a-z]*[rf][a-z]*\s|\bdrop\s+(?:table|database)\b|\btruncate\s+table\b|\bmysqldump\b|\b(?:curl|wget)\b[^|\n]*\|\s*(?:sudo\s+)?(?:ba)?sh\b|\bchmod\s+-?R?\s*777\b|:\(\)\s*\{\s*:\|:/i',
			'severity' => self::SEVERITY_BLOCK,
			'message'  => 'Contains a destructive shell or SQL command.',
		],
		'tool_impersonation'      => [
			'pattern'  => '/<\/?(?:system|assistant|tool_result|tool_use|function_results|function_calls)\b|\[\/?INST\]|<\|im_(?:start|end)\|>/i',
			'severity' => self::SEVERITY_BLOCK,
			'message'  => 'Imitates system, assistant, or tool output.',
		],
		'hidden_html'             => [
			'pattern'  => '/<\s*(?:script|iframe|object|embed|form|style)\b|\son[a-z]+\s*=\s*["\']|style\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0)/i',
			'severity' => self::SEVERITY_BLOCK,
			'message'  => 'Contains markup that can hide or run content.',
		],
	];

	/**
	 * Scan imported Markdown.
	 *
	 * @return array{findings: list<array{rule: string, severity: string, message: string, excerpt: string, line: int}>, blocked: bool}
	 */
	public static function scan( string $content ): array {
		$findings = [];

		foreach ( self::RULES as $rule => $definition ) {
			$matches = [];
			if ( ! preg_match_all( $definition['pattern'], $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$text   = (string) $match[0];
				$offset = (int) $match[1];

				if ( self::is_prohibition( $content, $offset ) ) {
					continue;
				}

				$findings[] = [
					'rule'     => $rule,
					'severity' => $definition['severity'],
					'message'  => $definition['message'],
					'excerpt'  => self::excerpt( $content, $offset, $text ),
					'line'     => self::line_of( $content, $offset ),
				];
				break;
			}
		}

		if ( preg_match( self::INVISIBLE, $content, $match, PREG_OFFSET_CAPTURE ) ) {
			$findings[] = [
				'rule'     => 'hidden_characters',
				'severity' => self::SEVERITY_BLOCK,
				'message'  => 'Contains invisible or direction-changing characters.',
				'excerpt'  => self::excerpt( $content, (int) $match[0][1], '' ),
				'line'     => self::line_of( $content, (int) $match[0][1] ),
			];
		}

		if ( preg_match( '/<!--/', $content, $match, PREG_OFFSET_CAPTURE ) ) {
			$findings[] = [
				'rule'     => 'html_comment',
				'severity' => self::SEVERITY_WARNING,
				'message'  => 'Contains an HTML comment a reader will not see.',
				'excerpt'  => self::excerpt( $content, (int) $match[0][1], '<!--' ),
				'line'     => self::line_of( $content, (int) $match[0][1] ),
			];
		}

		$blocked = [] !== array_filter(
			$findings,
			static fn( array $finding ): bool => self::SEVERITY_BLOCK === $finding['severity']
		);

		return [
			'findings' => $findings,
			'blocked'  => $blocked,
		];
	}

	/**
	 * Is the match part of a rule telling the agent *not* to do the thing?
	 *
	 * Stonewright's own skills are full of sentences like "never skip the
	 * backup". Reading those as attacks would make the scanner useless, so a
	 * negation in front of the match clears it.
	 */
	private static function is_prohibition( string $content, int $offset ): bool {
		$start  = max( 0, $offset - 48 );
		$before = strtolower( substr( $content, $start, $offset - $start ) );

		return 1 === preg_match(
			'/\b(?:never|not|n\'t|must\s+not|may\s+not|cannot|can\'t|refuse\s+to|refuses\s+to|without|no)\b[^.\n]{0,24}$/',
			$before
		);
	}

	private static function excerpt( string $content, int $offset, string $match ): string {
		$start   = max( 0, $offset - 24 );
		$length  = strlen( $match ) + 48;
		$excerpt = (string) substr( $content, $start, $length );
		$excerpt = (string) preg_replace( '/\s+/', ' ', $excerpt );

		return trim( $excerpt );
	}

	private static function line_of( string $content, int $offset ): int {
		return substr_count( substr( $content, 0, $offset ), "\n" ) + 1;
	}
}
