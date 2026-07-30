<?php
/**
 * Server-side render for stonewright/recipe-slider.
 *
 * @var array  $attributes Block attributes.
 * @var string $content    Block inner content (unused for dynamic blocks).
 * @var WP_Block $block    Block instance.
 */

defined( 'ABSPATH' ) || exit;

$attrs = wp_parse_args(
	(array) $attributes,
	[
		'heading'         => '',
		'subheading'      => '',
		'ctaText'         => '',
		'ctaUrl'          => '#',
		'backgroundColor' => '#00583f',
		'textColor'       => '#ffffff',
		'accentColor'     => '#e2001a',
		'cardColor'       => '#ffffff',
		'autoRotate'      => true,
		'interval'        => 5000,
		'perViewDesktop'  => 4,
		'perViewTablet'   => 2,
		'perViewMobile'   => 1,
		'showDots'        => true,
		'showArrows'      => true,
		'slides'          => [],
	]
);

$slides = is_array( $attrs['slides'] ) ? $attrs['slides'] : [];
if ( empty( $slides ) ) {
	return '';
}

$style_vars = sprintf(
	'--sw-rs-bg:%1$s;--sw-rs-text:%2$s;--sw-rs-accent:%3$s;--sw-rs-card:%4$s;--sw-rs-pv-d:%5$d;--sw-rs-pv-t:%6$d;--sw-rs-pv-m:%7$d;',
	esc_attr( (string) $attrs['backgroundColor'] ),
	esc_attr( (string) $attrs['textColor'] ),
	esc_attr( (string) $attrs['accentColor'] ),
	esc_attr( (string) $attrs['cardColor'] ),
	(int) $attrs['perViewDesktop'],
	(int) $attrs['perViewTablet'],
	(int) $attrs['perViewMobile']
);

$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
	? get_block_wrapper_attributes(
		[
			'class' => 'sw-recipe-slider',
			'style' => $style_vars,
			'data-auto'     => $attrs['autoRotate'] ? '1' : '0',
			'data-interval' => (int) $attrs['interval'],
		]
	)
	: sprintf(
		'class="sw-recipe-slider" style="%1$s" data-auto="%2$s" data-interval="%3$d"',
		esc_attr( $style_vars ),
		$attrs['autoRotate'] ? '1' : '0',
		(int) $attrs['interval']
	);

$difficulty_levels = [ 'Ușoară' => 1, 'Usoara' => 1, 'Medie' => 2, 'Medium' => 2, 'Complexă' => 3, 'Complexa' => 3, 'Complex' => 3 ];

$difficulty_int_map = [ 1 => 'Ușoară', 2 => 'Medie', 3 => 'Complexă' ];
?>
<section <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="sw-rs__bg-decoration" aria-hidden="true"></div>
	<div class="sw-rs__inner">
		<header class="sw-rs__header">
			<?php if ( ! empty( $attrs['heading'] ) ) : ?>
				<h2 class="sw-rs__heading"><?php echo wp_kses_post( (string) $attrs['heading'] ); ?></h2>
			<?php endif; ?>
			<?php if ( ! empty( $attrs['subheading'] ) ) : ?>
				<p class="sw-rs__subheading"><?php echo wp_kses_post( (string) $attrs['subheading'] ); ?></p>
			<?php endif; ?>
		</header>

		<div class="sw-rs__viewport">
			<?php if ( $attrs['showArrows'] ) : ?>
				<button type="button" class="sw-rs__nav sw-rs__nav--prev" aria-label="<?php esc_attr_e( 'Slide anterior', 'stonewright' ); ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			<?php endif; ?>

			<ul class="sw-rs__track" role="list">
				<?php foreach ( $slides as $i => $slide ) :
					$slide       = is_array( $slide ) ? $slide : [];
					$title       = (string) ( $slide['title'] ?? '' );
					$image       = (string) ( $slide['image'] ?? '' );
					$image_alt   = (string) ( $slide['imageAlt'] ?? $title );
					$raw_diff    = $slide['difficulty'] ?? '';
					if ( is_int( $raw_diff ) || ( is_string( $raw_diff ) && ctype_digit( $raw_diff ) ) ) {
						$diff_level  = max( 1, min( 3, (int) $raw_diff ) );
						$difficulty  = $difficulty_int_map[ $diff_level ] ?? '';
					} else {
						$difficulty  = (string) $raw_diff;
						$diff_level  = $difficulty_levels[ $difficulty ] ?? 1;
					}
					$time_label  = (string) ( $slide['time'] ?? '' );
					$url         = (string) ( $slide['url'] ?? '#' );
					?>
					<li class="sw-rs__slide" data-index="<?php echo (int) $i; ?>">
						<article class="sw-rs__card">
							<a class="sw-rs__card-link" href="<?php echo esc_url( $url ); ?>">
								<div class="sw-rs__card-media">
									<?php if ( $image ) : ?>
										<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $image_alt ); ?>" loading="lazy" decoding="async"/>
									<?php else : ?>
										<span class="sw-rs__card-media-fallback" aria-hidden="true">
											<svg width="48" height="48" viewBox="0 0 24 24" fill="none">
												<rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>
												<path d="M3 16l5-5 5 5 3-3 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
												<circle cx="9" cy="9" r="1.5" stroke="currentColor" stroke-width="1.5"/>
											</svg>
										</span>
									<?php endif; ?>
								</div>
								<div class="sw-rs__card-body">
									<h3 class="sw-rs__card-title"><?php echo esc_html( $title ); ?></h3>
									<div class="sw-rs__card-meta">
										<?php if ( $difficulty ) : ?>
											<span class="sw-rs__card-difficulty" data-level="<?php echo (int) $diff_level; ?>">
												<span class="sw-rs__chefs" aria-hidden="true">
													<?php for ( $k = 1; $k <= 3; $k++ ) : ?>
														<span class="sw-rs__chef <?php echo $k <= $diff_level ? 'is-on' : 'is-off'; ?>">
															<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
																<path d="M7 14a4 4 0 1 1-1-7.87A5 5 0 0 1 16 4a5 5 0 0 1 2 9.6V20H7v-6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
															</svg>
														</span>
													<?php endfor; ?>
												</span>
												<span class="sw-rs__card-difficulty-label"><?php echo esc_html( $difficulty ); ?></span>
											</span>
										<?php endif; ?>
										<?php if ( $time_label ) : ?>
											<span class="sw-rs__card-time">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
													<circle cx="12" cy="13" r="8" stroke="currentColor" stroke-width="1.6"/>
													<path d="M12 9v4l2.5 2.5M9 3h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
												</svg>
												<span><?php esc_html_e( 'Gata în', 'stonewright' ); ?> <?php echo esc_html( $time_label ); ?></span>
											</span>
										<?php endif; ?>
									</div>
								</div>
							</a>
						</article>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $attrs['showArrows'] ) : ?>
				<button type="button" class="sw-rs__nav sw-rs__nav--next" aria-label="<?php esc_attr_e( 'Slide următor', 'stonewright' ); ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( $attrs['showDots'] ) : ?>
			<div class="sw-rs__dots" role="tablist" aria-label="<?php esc_attr_e( 'Selectează slide', 'stonewright' ); ?>"></div>
		<?php endif; ?>

		<?php if ( ! empty( $attrs['ctaText'] ) ) : ?>
			<a class="sw-rs__cta" href="<?php echo esc_url( (string) $attrs['ctaUrl'] ); ?>">
				<span><?php echo esc_html( (string) $attrs['ctaText'] ); ?></span>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
					<path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</a>
		<?php endif; ?>
	</div>
</section>
<?php

