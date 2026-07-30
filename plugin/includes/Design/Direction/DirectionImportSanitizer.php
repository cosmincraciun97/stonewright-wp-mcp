<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Turns an untrusted design direction document into a trusted contract.
 *
 * An imported document has two halves. The front matter is a machine block:
 * a `---` delimited JSON object validated against the direction contract. The
 * body is human prose, and prose is never trusted: it is scanned for
 * instruction-shaped content, offending lines are reported as trust findings
 * and dropped, and only the remaining plain paragraphs survive as rationale.
 *
 * The verbatim document is preserved in `raw_source` for audit and export.
 * Nothing else in the return value carries unsanitized input, so a workflow
 * consumer can treat `contract` and `sanitized_rationale` as safe.
 */
final class DirectionImportSanitizer {

	/** @var string Front matter delimiter line. */
	private const DELIMITER = '---';

	/** @var int Maximum length of a reported excerpt. */
	private const EXCERPT_LENGTH = 120;

	/**
	 * Untrusted-prose detection rules, evaluated per line.
	 *
	 * Each entry maps a rule id to a severity and the literal needles that
	 * trigger it. Needles are matched case-insensitively.
	 *
	 * @var array<string,array{severity:string,needles:list<string>}>
	 */
	private const TRUST_RULES = [
		'trust.credential_request' => [
			'severity' => 'high',
			'needles'  => [
				'api key',
				'api_key',
				'apikey',
				'password',
				'passwd',
				'secret key',
				'access token',
				'bearer token',
				'private key',
				'credential',
			],
		],
		'trust.tool_instruction'   => [
			'severity' => 'high',
			'needles'  => [
				'stonewright/',
				'php-execute',
				'wp eval',
				'wp-cli',
				'wp cli',
				'mcp tool',
				'call the tool',
				'run the command',
			],
		],
		'trust.permission_bypass'  => [
			'severity' => 'high',
			'needles'  => [
				'ignore previous',
				'ignore all previous',
				'ignore the previous',
				'disregard previous',
				'disregard the previous',
				'skip the backup',
				'skip backup',
				'bypass',
				'without confirmation',
				'no confirmation',
				'disable permission',
				'disable the permission',
				'override the permission',
				'as an administrator',
			],
		],
		'trust.embedded_markup'    => [
			'severity' => 'medium',
			'needles'  => [
				'display:none',
				'display: none',
				'visibility:hidden',
				'visibility: hidden',
			],
		],
	];

	/** @var string Any HTML tag or comment opener. */
	private const MARKUP_PATTERN = '/<(?:!--|\/?[a-z][a-z0-9]*)/i';

	/** @var string A base64-looking run long enough to hide an instruction. */
	private const BASE64_PATTERN = '/[A-Za-z0-9+\/]{24,}={0,2}/';

	/** @var string Repeated percent-encoding. */
	private const PERCENT_PATTERN = '/(?:%[0-9A-Fa-f]{2}){2,}/';

	/**
	 * Sanitizes an imported design direction document.
	 *
	 * @param string $markdown    Untrusted document source.
	 * @param string $source_type One of DirectionContract::SOURCE_TYPES.
	 * @return array{raw_source:string,sanitized_rationale:string,contract:array<string,mixed>,trust_findings:list<array<string,string>>}|WP_Error
	 */
	public static function sanitize( string $markdown, string $source_type ) {
		if ( ! in_array( $source_type, DirectionContract::SOURCE_TYPES, true ) ) {
			return self::error( 'Unsupported direction source type.' );
		}

		if ( strlen( $markdown ) > DirectionContract::MAX_SOURCE_BYTES ) {
			return self::error(
				'Imported source exceeds the ' . DirectionContract::MAX_SOURCE_BYTES . ' byte limit.',
				[ 'bytes' => strlen( $markdown ) ]
			);
		}

		$split = self::split_document( $markdown );
		if ( $split instanceof WP_Error ) {
			return $split;
		}

		$decoded = json_decode( $split['front_matter'], true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::error( 'Direction front matter is not valid JSON.' );
		}

		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return self::error( 'Direction front matter must be a JSON object.' );
		}

		$contract = DirectionContractValidator::validate( $decoded );
		if ( $contract instanceof WP_Error ) {
			return $contract;
		}

		$scan = self::scan_prose( $split['body'] );

		return [
			'raw_source'          => $markdown,
			'sanitized_rationale' => $scan['rationale'],
			'contract'            => $contract,
			'trust_findings'      => $scan['findings'],
		];
	}

	/**
	 * Splits a document into its machine block and its prose body.
	 *
	 * @param string $markdown Untrusted document source.
	 * @return array{front_matter:string,body:string}|WP_Error
	 */
	private static function split_document( string $markdown ) {
		$normalized = str_replace( [ "\r\n", "\r" ], "\n", $markdown );
		$lines      = explode( "\n", $normalized );

		if ( self::DELIMITER !== trim( (string) array_shift( $lines ) ) ) {
			return self::error( 'Direction document must open with a --- front matter block.' );
		}

		$front_matter = [];
		$closed       = false;

		while ( [] !== $lines ) {
			$line = (string) array_shift( $lines );

			if ( self::DELIMITER === trim( $line ) ) {
				$closed = true;
				break;
			}

			$front_matter[] = $line;
		}

		if ( ! $closed ) {
			return self::error( 'Direction front matter block is not terminated.' );
		}

		return [
			'front_matter' => implode( "\n", $front_matter ),
			'body'         => implode( "\n", $lines ),
		];
	}

	/**
	 * Scans prose line by line, dropping every line that carries
	 * instruction-shaped or encoded content.
	 *
	 * @param string $body Untrusted prose body.
	 * @return array{rationale:string,findings:list<array<string,string>>}
	 */
	private static function scan_prose( string $body ): array {
		$findings = [];
		$kept     = [];

		foreach ( explode( "\n", $body ) as $line ) {
			$rule_ids = self::match_rules( $line );

			if ( [] !== $rule_ids ) {
				foreach ( $rule_ids as $rule_id ) {
					$findings[] = [
						'rule_id'  => $rule_id,
						'severity' => self::severity_for( $rule_id ),
						'excerpt'  => self::excerpt( $line ),
					];
				}

				continue;
			}

			$plain = self::to_plain_paragraph( $line );
			if ( '' !== $plain ) {
				$kept[] = $plain;
			}
		}

		return [
			'rationale' => self::cap( implode( "\n\n", $kept ) ),
			'findings'  => $findings,
		];
	}

	/**
	 * Returns every trust rule the line triggers.
	 *
	 * @param string $line Untrusted prose line.
	 * @return list<string>
	 */
	private static function match_rules( string $line ): array {
		$rule_ids = [];

		foreach ( self::TRUST_RULES as $rule_id => $rule ) {
			foreach ( $rule['needles'] as $needle ) {
				if ( false !== stripos( $line, $needle ) ) {
					$rule_ids[] = $rule_id;
					break;
				}
			}
		}

		if ( 1 === preg_match( self::MARKUP_PATTERN, $line ) && ! in_array( 'trust.embedded_markup', $rule_ids, true ) ) {
			$rule_ids[] = 'trust.embedded_markup';
		}

		if ( 1 === preg_match( self::BASE64_PATTERN, $line ) || 1 === preg_match( self::PERCENT_PATTERN, $line ) ) {
			$rule_ids[] = 'trust.encoded_payload';
		}

		return $rule_ids;
	}

	private static function severity_for( string $rule_id ): string {
		if ( isset( self::TRUST_RULES[ $rule_id ]['severity'] ) ) {
			return self::TRUST_RULES[ $rule_id ]['severity'];
		}

		return 'trust.encoded_payload' === $rule_id ? 'high' : 'medium';
	}

	/**
	 * Reduces a surviving line to a plain paragraph: no markup, no Markdown
	 * syntax, no link targets, collapsed whitespace.
	 */
	private static function to_plain_paragraph( string $line ): string {
		$plain = wp_strip_all_tags( $line );

		// Markdown links and images keep their label, never their target.
		$plain = (string) preg_replace( '/!?\[([^\]]*)\]\([^)]*\)/', '$1', $plain );

		// Leading structure markers and inline emphasis.
		$plain = (string) preg_replace( '/^\s{0,3}(?:#{1,6}\s+|[-*+]\s+|\d+\.\s+|>\s?)/', '', $plain );
		$plain = str_replace( [ '**', '__', '`' ], '', $plain );

		$plain = (string) preg_replace( '/\s+/', ' ', $plain );

		return trim( $plain );
	}

	private static function excerpt( string $line ): string {
		$collapsed = trim( (string) preg_replace( '/\s+/', ' ', $line ) );

		if ( strlen( $collapsed ) <= self::EXCERPT_LENGTH ) {
			return $collapsed;
		}

		return substr( $collapsed, 0, self::EXCERPT_LENGTH - 1 ) . '…';
	}

	private static function cap( string $rationale ): string {
		if ( strlen( $rationale ) <= DirectionContract::MAX_STRING_LENGTH ) {
			return $rationale;
		}

		return rtrim( substr( $rationale, 0, DirectionContract::MAX_STRING_LENGTH ) );
	}

	/**
	 * @param string              $message Human-readable reason.
	 * @param array<string,mixed> $data    Structured error data.
	 */
	private static function error( string $message, array $data = [] ): WP_Error {
		return new WP_Error( DirectionContract::ERROR_CODE, $message, $data );
	}
}
