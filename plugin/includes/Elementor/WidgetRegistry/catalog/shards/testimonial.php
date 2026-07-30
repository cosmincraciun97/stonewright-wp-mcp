<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'testimonial',
  'source' => 'free',
  'widget_type' => 'testimonial',
  'title' => 'Testimonial',
  'icon' => 'eicon-testimonial',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'testimonial',
    1 => 'blockquote',
  ),
  'file' => 'elementor/includes/widgets/testimonial.php',
  'intent' => 'Set the image size from thumbnail to full, or enter a custom size. Name',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
    4 => 'Showcasing a featured customer success story prominently on a service or product page',
    5 => 'Building a testimonials section with multiple side-by-side single quotes in a grid layout',
    6 => 'Adding social proof to landing pages or checkout pages to reduce purchase hesitation',
    7 => 'Displaying a quote with the reviewer\'s photo, name, and company for full credibility context',
    8 => 'Pulling testimonials dynamically from custom post types or ACF fields',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add a Testimonial widget – Step-by-step',
    1 => 'Content options – Configure general content, title, tags, and icons.',
    2 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    3 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    4 => '**Review content**: The quote text (supports HTML inline formatting)',
    5 => '**Image**: Reviewer photo with size and shape controls (circle, square, custom border-radius)',
    6 => '**Name**: Reviewer\'s full name displayed below the quote',
    7 => '**Job/Title**: Reviewer\'s role or company for added authority context',
    8 => '**Link**: Optional URL wrapping the reviewer\'s name or image',
    9 => '**Alignment**: Left / center / right alignment for quote and metadata',
    10 => '**Image size**: Control rendered pixel dimensions of the avatar',
    11 => '**Typography (content)**: Font controls for the quote text',
    12 => '**Typography (name/title)**: Separate font controls for the reviewer\'s name line',
    13 => '**Color controls**: Independent colors for quote text, name, and title',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'Single testimonial only per widget instance; for carousels or grids use Testimonial Carousel or a loop with a custom template',
    3 => 'Dynamic testimonial sources (CPT + dynamic tags) require proper field setup; manual entry is simpler for one-off implementations',
    4 => 'Image aspect ratios need matching (square photos work best with circular crop); portrait or landscape photos require CSS adjustment to avoid distortion',
    5 => 'Responsive behavior on mobile compresses multi-column testimonial grids; preview across breakpoints before publishing',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_testimonial',
      'label' => 'Testimonial',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'testimonial_content',
          'type' => 'textarea',
          'label' => 'Content',
          'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'testimonial_image',
          'type' => 'media',
          'label' => 'Choose Image',
          'default' =>
          array (
            'url' =>
            array (
              '__unresolved__' => 'Utils::get_placeholder_image_src()',
            ),
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'testimonial_name',
          'type' => 'text',
          'label' => 'Name',
          'default' => 'John Doe',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'testimonial_job',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Designer',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'testimonial_image_position',
          'type' => 'choose',
          'label' => 'Image Position',
          'default' => 'aside',
          'options' =>
          array (
            'aside' =>
            array (
              'title' => 'Aside',
              'icon' => 'eicon-h-align-left',
            ),
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
          ),
          'condition' =>
          array (
            'testimonial_image[url]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'testimonial_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => 'center',
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-text-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'image-size',
          'name' => 'testimonial_image',
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    1 =>
    array (
      'id' => 'section_style_testimonial_content',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'content_content_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-content',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'content_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-content',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_style_testimonial_image',
      'label' => 'Image',
      'tab' => 'style',
      'condition' =>
      array (
        'testimonial_image[url]!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'image_size',
          'type' => 'slider',
          'label' => 'Image Resolution',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'image_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-wrapper .elementor-testimonial-image img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'section_style_testimonial_name',
      'label' => 'Name',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'name_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'name_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-name',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'name_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-name',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'section_style_testimonial_job',
      'label' => 'Title',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'job_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'job_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-job',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'job_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-testimonial-job',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'image-size',
      'name' => 'testimonial_image',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-content',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'content_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-content',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-wrapper .elementor-testimonial-image img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'name_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-name',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'name_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-name',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'job_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-job',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'text-shadow',
      'name' => 'job_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-testimonial-job',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'testimonial_content' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'testimonial_image' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'media',
      'default' =>
      array (
        'url' =>
        array (
          '__unresolved__' => 'Utils::get_placeholder_image_src()',
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'testimonial_name' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'text',
      'default' => 'John Doe',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'testimonial_job' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'text',
      'default' => 'Designer',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'testimonial_image_position' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'choose',
      'default' => 'aside',
      'responsive' => false,
      'condition' =>
      array (
        'testimonial_image[url]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'testimonial_alignment' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'testimonial_image_image_size' =>
    array (
      'section' => 'section_testimonial',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'image-size',
      'group_prefix' => 'testimonial_image',
    ),
    'content_content_color' =>
    array (
      'section' => 'section_style_testimonial_content',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_typography_typography' =>
    array (
      'section' => 'section_style_testimonial_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'content_typography',
    ),
    'content_shadow_text_shadow' =>
    array (
      'section' => 'section_style_testimonial_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'content_shadow',
    ),
    'image_size' =>
    array (
      'section' => 'section_style_testimonial_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'section_style_testimonial_image',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_border' =>
    array (
      'section' => 'section_style_testimonial_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'name_text_color' =>
    array (
      'section' => 'section_style_testimonial_name',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'name_typography_typography' =>
    array (
      'section' => 'section_style_testimonial_name',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'name_typography',
    ),
    'name_shadow_text_shadow' =>
    array (
      'section' => 'section_style_testimonial_name',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'name_shadow',
    ),
    'job_text_color' =>
    array (
      'section' => 'section_style_testimonial_job',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'job_typography_typography' =>
    array (
      'section' => 'section_style_testimonial_job',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'job_typography',
    ),
    'job_shadow_text_shadow' =>
    array (
      'section' => 'section_style_testimonial_job',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'job_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'testimonial_image_image_size' => 'custom',
    'content_typography_typography' => 'custom',
    'content_shadow_text_shadow' => 'yes',
    'image_border_border' => 'solid',
    'name_typography_typography' => 'custom',
    'name_shadow_text_shadow' => 'yes',
    'job_typography_typography' => 'custom',
    'job_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'testimonial_content',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/testimonial-widget.md',
    1 => 'docs/knowledge/elementor/widgets/testimonial-widget.md',
    2 => 'docs/knowledge/elementor/widgets/testimonial-intent.md',
    3 => 'docs/knowledge/elementor/widgets/testimonial-intent.md',
  ),
  'control_count' => 20,
);
