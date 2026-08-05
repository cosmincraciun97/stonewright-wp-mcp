<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Manifest;

/** Responsive carousel behavior contract; missing evidence is a hard diagnostic. */
final class CarouselIntent {

	private const DEVICES = [ 'desktop', 'tablet', 'mobile' ];

	/**
	 * @param array<string,mixed> $input
	 * @param array<int,array<string,mixed>> $assets
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate( array $input, array $assets = [] ): array|\WP_Error {
		$errors = [];
		$slides = self::responsive_numbers( $input['slides_visible'] ?? null, true, 'slides_visible', $errors );
		$gap    = self::responsive_numbers( $input['gap'] ?? null, false, 'gap', $errors );
		$arrows = self::responsive_bools( $input['arrows_enabled'] ?? null, 'arrows_enabled', $errors );
		$dots   = self::responsive_bools( $input['dots_enabled'] ?? null, 'dots_enabled', $errors );

		$booleans = [];
		foreach ( [ 'loop', 'autoplay', 'pause_on_hover', 'pause_on_focus', 'swipe', 'keyboard', 'rtl' ] as $key ) {
			$booleans[ $key ] = self::optional_bool( $input, $key, $errors );
		}

		$content_source = self::text( $input['content_source'] ?? '', 190 );
		if ( '' === $content_source ) {
			$errors[] = self::error( 'content_source', 'carousel_content_source_missing', 'Declare the native content source for the carousel.' );
		}

		$confidence = is_numeric( $input['confidence'] ?? null ) ? (float) $input['confidence'] : -1.0;
		if ( $confidence < 0 || $confidence > 1 ) {
			$errors[] = self::error( 'confidence', 'carousel_confidence_invalid', 'Confidence must be between 0 and 1.' );
		}

		$duration = null;
		if ( array_key_exists( 'duration_ms', $input ) && null !== $input['duration_ms'] ) {
			if ( ! is_numeric( $input['duration_ms'] ) || (int) $input['duration_ms'] < 0 ) {
				$errors[] = self::error( 'duration_ms', 'carousel_duration_invalid', 'Duration must be a non-negative number of milliseconds.' );
			} else {
				$duration = (int) $input['duration_ms'];
			}
		}
		if ( true === $booleans['autoplay'] && ( null === $duration || $duration < 1000 ) ) {
			$errors[] = self::error( 'duration_ms', 'carousel_duration_invalid', 'Autoplay requires an explicit duration of at least 1000 milliseconds.' );
		}

		$arrow_contract = null;
		$arrows_active  = in_array( true, $arrows, true );
		if ( $arrows_active ) {
			$raw_contract = $input['arrow_contract'] ?? null;
			if ( ! is_array( $raw_contract ) ) {
				$errors[] = self::error( 'arrow_contract', 'carousel_arrow_contract_missing', 'Active arrows require previous and next asset contracts.' );
			} else {
				$arrow_contract = ArrowAssetContract::validate( $raw_contract, true, $assets );
				if ( $arrow_contract instanceof \WP_Error ) {
					$errors[] = self::error( 'arrow_contract', (string) $arrow_contract->get_error_code(), $arrow_contract->get_error_message() );
				}
			}
		}

		if ( [] !== $errors ) {
			return new \WP_Error(
				'stonewright_carousel_intent_invalid',
				__( 'Carousel intent is incomplete or unsafe to render.', 'stonewright' ),
				[ 'status' => 400, 'diagnostics' => $errors ]
			);
		}

		return [
			'type'                 => 'carousel',
			'content_source'       => $content_source,
			'slides_visible'       => $slides,
			'gap'                  => $gap,
			'loop'                 => $booleans['loop'],
			'autoplay'             => $booleans['autoplay'],
			'duration_ms'          => $duration,
			'pause_on_hover'       => $booleans['pause_on_hover'],
			'pause_on_focus'       => $booleans['pause_on_focus'],
			'swipe'                => $booleans['swipe'],
			'keyboard'             => $booleans['keyboard'],
			'arrows_enabled'       => $arrows,
			'dots_enabled'         => $dots,
			'arrow_contract'       => $arrow_contract,
			'offsets'              => self::safe_map( $input['offsets'] ?? [] ),
			'rtl'                  => $booleans['rtl'],
			'accessibility_labels' => self::safe_map( $input['accessibility_labels'] ?? [] ),
			'provenance'           => self::safe_map( $input['provenance'] ?? [] ),
			'required_controls'    => self::strings( $input['required_controls'] ?? $input['controls'] ?? [] ),
			'confidence'           => $confidence,
		];
	}

	/** @param list<array{path:string,code:string,repair:string}> $errors @return array<string,float> */
	private static function responsive_numbers( mixed $value, bool $positive, string $path, array &$errors ): array {
		if ( ! is_array( $value ) ) {
			$errors[] = self::error( $path, 'carousel_responsive_evidence_missing', 'Provide explicit desktop, tablet, and mobile measurements.' );
			return [];
		}
		$out = [];
		foreach ( self::DEVICES as $device ) {
			$number = $value[ $device ] ?? null;
			if ( ! is_numeric( $number ) || ( $positive ? (float) $number <= 0 : (float) $number < 0 ) ) {
				$errors[] = self::error( $path . '.' . $device, 'carousel_responsive_value_invalid', $positive ? 'Provide a value greater than zero.' : 'Provide a value greater than or equal to zero.' );
				continue;
			}
			$out[ $device ] = (float) $number;
		}
		return $out;
	}

	/** @param list<array{path:string,code:string,repair:string}> $errors @return array<string,bool> */
	private static function responsive_bools( mixed $value, string $path, array &$errors ): array {
		if ( ! is_array( $value ) ) {
			$errors[] = self::error( $path, 'carousel_responsive_evidence_missing', 'Provide explicit desktop, tablet, and mobile boolean values.' );
			return [];
		}
		$out = [];
		foreach ( self::DEVICES as $device ) {
			if ( ! array_key_exists( $device, $value ) || ! is_bool( $value[ $device ] ) ) {
				$errors[] = self::error( $path . '.' . $device, 'carousel_responsive_boolean_invalid', 'Use an explicit JSON boolean for each viewport.' );
				continue;
			}
			$out[ $device ] = $value[ $device ];
		}
		return $out;
	}

	/** @param array<string,mixed> $input @param list<array{path:string,code:string,repair:string}> $errors */
	private static function optional_bool( array $input, string $key, array &$errors ): ?bool {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		if ( ! is_bool( $input[ $key ] ) ) {
			$errors[] = self::error( $key, 'carousel_boolean_invalid', 'Use an explicit JSON boolean.' );
			return null;
		}
		return $input[ $key ];
	}

	/** @return array<string,mixed> */
	private static function safe_map( mixed $value, int $depth = 0 ): array {
		if ( ! is_array( $value ) || $depth > 3 ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 50, true ) as $key => $item ) {
			$safe_key = is_string( $key ) ? sanitize_key( $key ) : (string) $key;
			if ( '' === $safe_key ) {
				continue;
			}
			if ( is_array( $item ) ) {
				$out[ $safe_key ] = self::safe_map( $item, $depth + 1 );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) || null === $item ) {
				$out[ $safe_key ] = $item;
			} elseif ( is_scalar( $item ) ) {
				$out[ $safe_key ] = self::text( $item, 190 );
			}
		}
		return $out;
	}

	/** @return list<string> */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 50 ) as $item ) {
			$key = is_scalar( $item ) ? sanitize_key( (string) $item ) : '';
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}

	/** @return array{path:string,code:string,repair:string} */
	private static function error( string $path, string $code, string $repair ): array {
		return [ 'path' => $path, 'code' => $code, 'repair' => $repair ];
	}
}
