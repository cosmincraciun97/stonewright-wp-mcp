<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Quality;

/**
 * The deterministic rendered-quality rule set.
 *
 * Two kinds of rule live here, and the split is deliberate:
 *
 * - Objective rules measure something a browser reported and a person can
 *   verify: a contrast ratio, a scroll width, a target size. They fail hard,
 *   because they describe a page that is measurably broken.
 * - Guidance rules compare the rendered page with the active direction's own
 *   tokens. A direction is intent, not law — a designer may deviate for a good
 *   reason — so these warn and never fail. Promoting one to a hard failure
 *   requires a direction field the locked contract does not have yet.
 *
 * Every rule reports `not_checked` rather than a pass when its evidence is
 * absent. That is the whole point of the registry: an incomplete browser
 * session must be visibly incomplete instead of quietly green.
 */
final class QualityRuleRegistry {

	/**
	 * The rule set, in stable evaluation order.
	 *
	 * @return list<QualityRule>
	 */
	public static function rules(): array {
		return [
			self::text_contrast(),
			self::focus_contrast(),
			self::horizontal_overflow(),
			self::target_size(),
			self::clipped_text(),
			self::focus_state(),
			self::hover_state(),
			self::spacing_tokens(),
			self::typography_tokens(),
		];
	}

	/**
	 * Compact anti-slop floor for admin and agent context.
	 *
	 * @return list<array{id:string,summary:string,severity:string,guidance:bool}>
	 */
	public static function floor(): array {
		$floor = [];
		foreach ( self::rules() as $rule ) {
			$floor[] = [
				'id'       => $rule->id(),
				'summary'  => $rule->summary(),
				'severity' => $rule->severity(),
				'guidance' => $rule->is_guidance(),
			];
		}

		return $floor;
	}

	/**
	 * Text must meet the WCAG AA ratio for its rendered size.
	 */
	private static function text_contrast(): QualityRule {
		return new QualityRule(
			'contrast.text',
			'error',
			'element',
			[ 'text_color', 'background_color', 'font.size_px' ],
			'Text meets the WCAG AA contrast ratio for its rendered size.',
			false,
			static fn ( array $element ): bool => 'container' !== ( $element['kind'] ?? '' ),
			static function ( array $element ): ?array {
				$text       = self::color_at( $element, 'text_color' );
				$background = self::color_at( $element, 'background_color' );
				$size       = self::number_at( $element, [ 'font', 'size_px' ] );
				if ( null === $text || null === $background || null === $size ) {
					return null;
				}

				$ratio = QualityEvaluator::contrast_ratio( $text, $background );
				if ( null === $ratio ) {
					return null;
				}

				$weight   = self::number_at( $element, [ 'font', 'weight' ] ) ?? 400.0;
				$required = QualityEvaluator::is_large_text( $size, $weight )
					? QualityEvaluator::MIN_CONTRAST_LARGE
					: QualityEvaluator::MIN_CONTRAST_TEXT;

				if ( $ratio >= $required ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => round( $ratio, 2 ),
							'required' => $required,
							'text'     => $text,
							'backdrop' => $background,
						],
						'repair_hint' => sprintf(
							/* translators: 1: required contrast ratio, 2: text color, 3: background color. */
							__( 'Reach %1$s:1 at this size. %2$s on %3$s does not, so pick a direction color pair that does.', 'stonewright' ),
							(string) $required,
							$text,
							$background
						),
					],
				];
			}
		);
	}

	/**
	 * A focus indicator must be visible against the surface it sits on.
	 */
	private static function focus_contrast(): QualityRule {
		return new QualityRule(
			'contrast.focus',
			'error',
			'element',
			[ 'states.focus.outline_color', 'background_color' ],
			'The keyboard focus indicator is visible against its own backdrop.',
			false,
			static fn ( array $element ): bool => 'interactive' === ( $element['kind'] ?? '' ),
			static function ( array $element ): ?array {
				$outline    = self::color_at( $element, [ 'states', 'focus', 'outline_color' ] );
				$background = self::color_at( $element, 'background_color' );
				if ( null === $outline || null === $background ) {
					return null;
				}

				$ratio = QualityEvaluator::contrast_ratio( $outline, $background );
				if ( null === $ratio ) {
					return null;
				}
				if ( $ratio >= QualityEvaluator::MIN_CONTRAST_NON_TEXT ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => round( $ratio, 2 ),
							'required' => QualityEvaluator::MIN_CONTRAST_NON_TEXT,
							'outline'  => $outline,
							'backdrop' => $background,
						],
						'repair_hint' => __( 'Use a focus outline color that reaches 3:1 against the element surface, not a darker shade of it.', 'stonewright' ),
					],
				];
			}
		);
	}

	/**
	 * A rendered page must not scroll sideways.
	 */
	private static function horizontal_overflow(): QualityRule {
		return new QualityRule(
			'overflow.horizontal',
			'error',
			'viewport',
			[ 'scroll_width', 'width' ],
			'The document does not scroll horizontally at this viewport.',
			false,
			static fn (): bool => true,
			static function ( array $viewport ): ?array {
				$scroll = self::number_at( $viewport, 'scroll_width' );
				$width  = self::number_at( $viewport, 'width' );
				if ( null === $scroll || null === $width ) {
					return null;
				}
				if ( $scroll <= $width + QualityEvaluator::LAYOUT_TOLERANCE_PX ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => self::exact( $scroll ),
							'required' => self::exact( $width ),
						],
						'repair_hint' => __( 'Find the element wider than the viewport and constrain it; a fixed width or a negative margin is the usual cause.', 'stonewright' ),
					],
				];
			}
		);
	}

	/**
	 * Interactive elements must be large enough to hit.
	 */
	private static function target_size(): QualityRule {
		return new QualityRule(
			'target.size',
			'error',
			'element',
			[ 'box.width', 'box.height' ],
			'Interactive elements meet the minimum target size.',
			false,
			static fn ( array $element ): bool => 'interactive' === ( $element['kind'] ?? '' ),
			static function ( array $element ): ?array {
				$width  = self::number_at( $element, [ 'box', 'width' ] );
				$height = self::number_at( $element, [ 'box', 'height' ] );
				if ( null === $width || null === $height ) {
					return null;
				}

				$smallest = min( $width, $height );
				if ( $smallest >= QualityEvaluator::MIN_TARGET_PX ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => self::exact( $smallest ),
							'required' => QualityEvaluator::MIN_TARGET_PX,
						],
						'repair_hint' => sprintf(
							/* translators: %d: minimum target size in pixels. */
							__( 'Give the control at least %dpx on its smallest side, through padding rather than a fixed height.', 'stonewright' ),
							QualityEvaluator::MIN_TARGET_PX
						),
					],
				];
			}
		);
	}

	/**
	 * Text must not be cut off by its own box.
	 */
	private static function clipped_text(): QualityRule {
		return new QualityRule(
			'text.clipped',
			'error',
			'element',
			[ 'content_box.scroll_width', 'content_box.client_width' ],
			'Text is not clipped by its container.',
			false,
			static fn ( array $element ): bool => 'container' !== ( $element['kind'] ?? '' ),
			static function ( array $element ): ?array {
				$scroll = self::number_at( $element, [ 'content_box', 'scroll_width' ] );
				$client = self::number_at( $element, [ 'content_box', 'client_width' ] );
				if ( null === $scroll || null === $client ) {
					return null;
				}
				if ( $scroll <= $client + QualityEvaluator::LAYOUT_TOLERANCE_PX ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => self::exact( $scroll ),
							'required' => self::exact( $client ),
						],
						'repair_hint' => __( 'Let the text wrap or shrink the type step; do not widen the container past the layout grid.', 'stonewright' ),
					],
				];
			}
		);
	}

	/**
	 * A captured interaction pass must include a keyboard focus state.
	 */
	private static function focus_state(): QualityRule {
		return new QualityRule(
			'state.focus_visible',
			'error',
			'element',
			[ 'states.focus' ],
			'Interactive elements expose a keyboard focus state.',
			false,
			static fn ( array $element ): bool => 'interactive' === ( $element['kind'] ?? '' ),
			static function ( array $element ): ?array {
				$states = $element['states'] ?? null;
				if ( ! is_array( $states ) ) {
					return null;
				}

				$focus = $states['focus'] ?? null;
				if ( is_array( $focus ) && [] !== $focus ) {
					$width = self::number_at( $states, [ 'focus', 'outline_width_px' ] );
					if ( null === $width || $width > 0.0 ) {
						return [];
					}
				}

				return [
					[
						'evidence'    => [
							'actual'   => [] === array_keys( $states ) ? 'none' : implode( ', ', array_keys( $states ) ),
							'required' => 'focus',
						],
						'repair_hint' => __( 'Give the control a visible :focus-visible outline. Keyboard users have no other way to see where they are.', 'stonewright' ),
					],
				];
			}
		);
	}

	/**
	 * A captured interaction pass should include a hover state.
	 */
	private static function hover_state(): QualityRule {
		return new QualityRule(
			'state.hover_defined',
			'warning',
			'element',
			[ 'states.hover' ],
			'Interactive elements acknowledge pointer hover.',
			false,
			static fn ( array $element ): bool => 'interactive' === ( $element['kind'] ?? '' ),
			static function ( array $element ): ?array {
				$states = $element['states'] ?? null;
				if ( ! is_array( $states ) ) {
					return null;
				}

				$hover = $states['hover'] ?? null;
				if ( is_array( $hover ) && [] !== $hover ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => [] === array_keys( $states ) ? 'none' : implode( ', ', array_keys( $states ) ),
							'required' => 'hover',
						],
						'repair_hint' => __( 'Add a hover treatment so pointer users get the same feedback keyboard users get from focus.', 'stonewright' ),
					],
				];
			}
		);
	}

	/**
	 * Observed spacing should land on a direction spacing token.
	 */
	private static function spacing_tokens(): QualityRule {
		return new QualityRule(
			'token.spacing',
			'warning',
			'element',
			[ 'spacing', 'direction.tokens.spacing' ],
			'Observed spacing matches a direction spacing token.',
			true,
			static fn (): bool => true,
			static function ( array $element, array $context ): ?array {
				$spacing = $element['spacing'] ?? null;
				/** @var list<float> $scale */
				$scale = $context['spacing_scale'] ?? [];
				if ( ! is_array( $spacing ) || [] === $spacing || [] === $scale ) {
					return null;
				}

				$findings = [];
				foreach ( $spacing as $property => $observed ) {
					if ( ! is_int( $observed ) && ! is_float( $observed ) ) {
						continue;
					}
					$nearest = QualityEvaluator::nearest( (float) $observed, $scale );
					if ( null === $nearest || abs( (float) $observed - $nearest ) <= QualityEvaluator::LAYOUT_TOLERANCE_PX ) {
						continue;
					}

					$findings[] = [
						'evidence'    => [
							'actual'   => self::exact( (float) $observed ),
							'required' => self::exact( $nearest ),
							'property' => (string) $property,
						],
						'repair_hint' => sprintf(
							/* translators: 1: measured spacing, 2: nearest direction token value. */
							__( 'Measured %1$spx. The nearest direction spacing token is %2$spx — use the token or add the value to the direction.', 'stonewright' ),
							(string) self::exact( (float) $observed ),
							(string) self::exact( $nearest )
						),
					];
				}

				return $findings;
			}
		);
	}

	/**
	 * Observed type sizes should land on a direction typography token.
	 */
	private static function typography_tokens(): QualityRule {
		return new QualityRule(
			'token.typography',
			'warning',
			'element',
			[ 'font.size_px', 'direction.tokens.typography' ],
			'Observed type size matches a direction typography token.',
			true,
			static fn ( array $element ): bool => 'container' !== ( $element['kind'] ?? '' ),
			static function ( array $element, array $context ): ?array {
				$size = self::number_at( $element, [ 'font', 'size_px' ] );
				/** @var list<float> $ramp */
				$ramp = $context['type_scale'] ?? [];
				if ( null === $size || [] === $ramp ) {
					return null;
				}

				$nearest = QualityEvaluator::nearest( $size, $ramp );
				if ( null === $nearest || abs( $size - $nearest ) <= QualityEvaluator::TYPE_TOLERANCE_PX ) {
					return [];
				}

				return [
					[
						'evidence'    => [
							'actual'   => self::exact( $size ),
							'required' => self::exact( $nearest ),
						],
						'repair_hint' => sprintf(
							/* translators: 1: measured type size, 2: nearest direction token value. */
							__( 'Measured %1$spx. The nearest direction type step is %2$spx — use the step or extend the direction ramp.', 'stonewright' ),
							(string) self::exact( $size ),
							(string) self::exact( $nearest )
						),
					],
				];
			}
		);
	}

	/**
	 * Read a normalized color from an evidence path.
	 *
	 * @param array<string,mixed>  $evidence Evidence subtree.
	 * @param string|list<string>  $path     Key or key path.
	 */
	private static function color_at( array $evidence, $path ): ?string {
		$value = self::at( $evidence, $path );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Read a numeric measurement from an evidence path.
	 *
	 * @param array<string,mixed>  $evidence Evidence subtree.
	 * @param string|list<string>  $path     Key or key path.
	 */
	private static function number_at( array $evidence, $path ): ?float {
		$value = self::at( $evidence, $path );

		return is_int( $value ) || is_float( $value ) ? (float) $value : null;
	}

	/**
	 * Read a value from an evidence path without inventing defaults.
	 *
	 * @param array<string,mixed>  $evidence Evidence subtree.
	 * @param string|list<string>  $path     Key or key path.
	 * @return mixed
	 */
	private static function at( array $evidence, $path ) {
		$keys    = is_array( $path ) ? $path : [ $path ];
		$current = $evidence;
		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
				return null;
			}
			$current = $current[ $key ];
		}

		return $current;
	}

	/**
	 * Report whole measurements as integers so evidence reads like the browser reported it.
	 *
	 * @return int|float
	 */
	private static function exact( float $value ): int|float {
		return $value === floor( $value ) ? (int) $value : round( $value, 2 );
	}
}
