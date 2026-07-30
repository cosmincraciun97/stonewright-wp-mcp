<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'posts',
  'source' => 'pro',
  'widget_type' => 'posts',
  'title' => 'Posts',
  'icon' => NULL,
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'posts',
    1 => 'cpt',
    2 => 'item',
    3 => 'loop',
    4 => 'query',
    5 => 'cards',
    6 => 'custom post type',
  ),
  'file' => 'pro-elements/modules/posts/widgets/posts.php',
  'intent' => 'Display Your Posts With Featured Layouts WidgetsPro WidgetsPosts Get Elementor Pro Watch video Posts WidgetShow Your Blog Posts On Any Page Control Your Post’s Displays Customize the columns, posts per page, image position, and more for complete design freedom. Layout Options Match Posts to Your Website’s Design Choose the styling, colors, typography, spacing, and overall appearance of your posts Design Customization Choose the Content That’s Included Decide which content to include or exclude based on criteria like author, date, term, and more. Advanced queries Get Inspired by Must-Click Posts Explore exceptionally designed websites that highlight their posts in beautiful ways that viewers can’t resist. Learn How to Design Posts That Stand Out on Your Page Master the Posts widget to create a blog page that displays custom post types in various layouts that highlight the content perfectly. HOW IT WORKS How to Create a Blog Page in WordPress Using Elementor Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Animated Headline Animated Headline Slides Widget Slides Nav Menu Widget Nav Menu Call To Action Widget Call to Action',
  'use_cases' =>
  array (
    0 => 'Animated Headline Animated Headline Slides Widget Slides Nav Menu Widget Nav Menu Call To Action Widget Call to Action',
    1 => 'Organizing your layout design and structuring content elements inside Elementor.',
    2 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    3 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
    4 => 'In Elementor Editor, click +',
    5 => 'All available widgets are displayed',
    6 => 'Click or drag the widget to the canvas',
    7 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => 'Add a Posts widget – Step-by-step',
    4 => 'Choose Classic.Note – You can choose from three Skin options: Classic, Card, and Full Content. Customization options in the content tab will vary based on the skin type you select.',
    5 => 'Choose Cards.Note – You can choose from three Skin options: Classic, Card, and Full Content. Customization options in the content tab will vary based on the skin type you select.',
    6 => 'Choose Full Content.Note – You can choose from three Skin options: Classic, Card, and Full Content. Customization options in the content tab will vary based on the skin type you select.',
    7 => 'Page Limit – Set the number of posts to display per page.Previous Label: Customize the text for the previous button.Next Label: Customize the text for the next button.Alignment: Choose the alignment of pagination controls.',
    8 => 'Page Limit – Set the number of posts to display per page.Shorten: Toggle to shorten the display if needed.Alignment: Choose the alignment of pagination controls (Right, Center, Left).',
    9 => 'If choose – Previous / Next',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'Choose Classic.Note: You can choose from three Skin options: Classic, Card, and Full Content. Customization options in the content tab will vary based on the skin type you select.',
    3 => 'Choose Cards.Note: You can choose from three Skin options: Classic, Card, and Full Content. Customization options in the content tab will vary based on the skin type you select.',
    4 => 'Choose Full Content.Note: You can choose from three Skin options: Classic, Card, and Full Content. Customization options in the content tab will vary based on the skin type you select.',
    5 => 'Page Limit: Set the number of posts to display per page.Previous Label: Customize the text for the previous button.Next Label: Customize the text for the next button.Alignment: Choose the alignment of pagination controls.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_query',
      'label' => 'Query',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => NULL,
          'name' =>
          array (
            '__unresolved__' => '->get_name()',
          ),
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'posts_per_page',
          ),
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
      'group' => NULL,
      'name' =>
      array (
        '__unresolved__' => '->get_name()',
      ),
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'posts_per_page',
      ),
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
    0 =>
    array (
      'var' => 'widget',
      'fields' =>
      array (
        0 =>
        array (
          'key' => 'archive_query_note',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            0 => 'current_query',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
    ),
  ),
  'settings_index' =>
  array (
  ),
  'group_activators' =>
  array (
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/posts-widget.md',
    1 => 'docs/knowledge/elementor/widgets/posts-widget.md',
    2 => 'docs/knowledge/elementor/widgets/posts-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/posts-widget-pro.md',
  ),
  'control_count' => 0,
);
