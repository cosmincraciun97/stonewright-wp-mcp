<?php
/**
 * Server-side render for stonewright/recipe-hero.
 *
 * @var array  $attributes
 * @var string $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$attrs = wp_parse_args(
	(array) $attributes,
	[
		'breadcrumb'      => '',
		'category'        => '',
		'title'           => '',
		'difficulty'      => '',
		'time'            => '',
		'servings'        => '',
		'image'           => '',
		'imageAlt'        => '',
		'backgroundColor' => '#1a171c',
		'textColor'       => '#ffffff',
		'accentColor'     => '#e2001a',
		'showPlayButton'  => true,
		'videoUrl'        => '',
		'stats'           => [],
	]
);

$style_vars = sprintf(
	'--sw-rh-bg:%1$s;--sw-rh-text:%2$s;--sw-rh-accent:%3$s;',
	esc_attr( (string) $attrs['backgroundColor'] ),
	esc_attr( (string) $attrs['textColor'] ),
	esc_attr( (string) $attrs['accentColor'] )
);
if ( ! empty( $attrs['image'] ) ) {
	$style_vars .= '--sw-rh-image:url(' . esc_url( $attrs['image'] ) . ');';
}

$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
	? get_block_wrapper_attributes(
		[
			'class' => 'sw-recipe-hero',
			'style' => $style_vars,
		]
	)
	: sprintf( 'class="sw-recipe-hero" style="%s"', esc_attr( $style_vars ) );
?>
<section <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="sw-rh__backdrop" aria-hidden="true"></div>
	<div class="sw-rh__inner">
		<div class="sw-rh__col sw-rh__col--text">
			<?php if ( ! empty( $attrs['breadcrumb'] ) ) : ?>
				<nav class="sw-rh__breadcrumb" aria-label="<?php esc_attr_e( 'Navigare', 'stonewright' ); ?>">
					<?php echo esc_html( (string) $attrs['breadcrumb'] ); ?>
				</nav>
			<?php endif; ?>
			<?php if ( ! empty( $attrs['category'] ) ) : ?>
				<span class="sw-rh__category"><?php echo esc_html( (string) $attrs['category'] ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $attrs['title'] ) ) : ?>
				<h1 class="sw-rh__title"><?php echo wp_kses_post( (string) $attrs['title'] ); ?></h1>
			<?php endif; ?>

			<div class="sw-rh__info-card">
				<?php if ( ! empty( $attrs['difficulty'] ) ) : ?>
					<div class="sw-rh__info-item">
						<span class="sw-rh__info-label"><?php esc_html_e( 'Dificultate', 'stonewright' ); ?></span>
						<span class="sw-rh__info-value"><?php echo esc_html( (string) $attrs['difficulty'] ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $attrs['time'] ) ) : ?>
					<div class="sw-rh__info-item">
						<span class="sw-rh__info-label"><?php esc_html_e( 'Timp', 'stonewright' ); ?></span>
						<span class="sw-rh__info-value"><?php echo esc_html( (string) $attrs['time'] ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $attrs['servings'] ) ) : ?>
					<div class="sw-rh__info-item">
						<span class="sw-rh__info-label"><?php esc_html_e( 'Porții', 'stonewright' ); ?></span>
						<span class="sw-rh__info-value"><?php echo esc_html( (string) $attrs['servings'] ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $attrs['stats'] ) ) : ?>
				<ul class="sw-rh__stats" role="list">
					<?php foreach ( (array) $attrs['stats'] as $stat ) :
						$stat  = (array) $stat;
						$label = (string) ( $stat['label'] ?? '' );
						$value = (string) ( $stat['value'] ?? '' );
						$unit  = (string) ( $stat['unit'] ?? '' );
						if ( $label === '' && $value === '' ) {
							continue;
						}
						?>
						<li class="sw-rh__stat">
							<span class="sw-rh__stat-value"><?php echo esc_html( $value ); ?><small><?php echo esc_html( $unit ); ?></small></span>
							<span class="sw-rh__stat-label"><?php echo esc_html( $label ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="sw-rh__col sw-rh__col--media">
			<div class="sw-rh__image-frame">
				<?php if ( ! empty( $attrs['image'] ) ) : ?>
					<img src="<?php echo esc_url( $attrs['image'] ); ?>" alt="<?php echo esc_attr( (string) $attrs['imageAlt'] ); ?>" loading="eager" decoding="async"/>
				<?php else : ?>
					<div class="sw-rh__image-placeholder" aria-hidden="true"></div>
				<?php endif; ?>
				<?php if ( $attrs['showPlayButton'] ) : ?>
					<a class="sw-rh__play" href="<?php echo esc_url( $attrs['videoUrl'] ?: '#' ); ?>" aria-label="<?php esc_attr_e( 'Redă video', 'stonewright' ); ?>">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" aria-hidden="true">
							<path d="M8 5v14l11-7-11-7z" fill="currentColor"/>
						</svg>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php

