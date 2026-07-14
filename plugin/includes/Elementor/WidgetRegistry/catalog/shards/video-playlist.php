<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'video-playlist',
  'source' => 'pro',
  'widget_type' => 'video-playlist',
  'title' => 'Video Playlist',
  'icon' => 'eicon-video-playlist',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/video-playlist/widgets/video-playlist.php',
  'intent' => 'In the panel, in the Thumbnail section, click the default thumbnail image and add a different image. For more details, see Add images and icons.',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Video Playlist widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Adding a Video Playlist widget – Step-by-step',
    2 => 'Give the Playlist name an HTML tag of H1 – H6, Div, or Span. Use the dropdown menu to designate the title as a header (H1-6). This helps search engines find and understand the playlist, boosting SEO. The title can also be tagged as a paragraph, span or div.',
    3 => 'Type – Use the dropdown to select among the compatible video formats: YouTube, Vimeo, and Self Hosted. YouTube and Vimeo require links to the videos, Self Hosted videos are uploaded from the Media Library. There is a fourth option, Section. This will place a text section between the playlist items. This is a good way to divide your video playlist into different groups. For example, Videos for beginners and Videos for advanced users.',
    4 => 'Get Video Data – Automatically grabs the title, duration and thumbnail from YouTube or Vimeo.',
    5 => 'Title – Give this video a name that will be displayed in the playlist.',
    6 => 'HTML tag – Give the video name an HTML tag of H1- H6, Div, or Span. Use the dropdown menu to designate the title as a header (H1-6). This helps search engines find and understand the video, boosting SEO. The title can also be tagged as a paragraph, span or div..',
    7 => 'Duration – Limit the length of the video in the playlist.',
  ),
  'limits' =>
  array (
    0 => 'Duration: Limit the length of the video in the playlist.',
    1 => 'Toggle to Show if you want to limit the amount of text visible to visitors. This will bring up the following options:',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_playlist',
      'label' => 'Playlist',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs_direction',
          'type' => 'hidden',
          'label' => 'Position',
          'default' => 'vertical',
          'options' =>
          array (
            'horizontal' => 'Horizontal',
            'vertical' => 'Vertical',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'playlist_title',
          'type' => 'text',
          'label' => 'Playlist Name',
          'default' => 'Playlist',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'playlist_title_tag',
          'type' => 'select',
          'label' => 'HTML Tag',
          'default' => 'h2',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
          ),
          'condition' =>
          array (
            'playlist_title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'tabs',
          'type' => 'repeater',
          'label' => 'Playlist Items',
          'default' =>
          array (
            0 =>
            array (
              'title' => 'Sample Video',
              'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
              'duration' => '0:16',
              'thumbnail' =>
              array (
                'url' => 'https://img.youtube.com/vi/XHOmBV4js_E/maxresdefault.jpg',
              ),
            ),
            1 =>
            array (
              'title' => 'Sample Video',
              'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
              'duration' => '0:16',
              'thumbnail' =>
              array (
                'url' => 'https://img.youtube.com/vi/XHOmBV4js_E/maxresdefault.jpg',
              ),
            ),
            2 =>
            array (
              'title' => 'Sample Video',
              'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
              'duration' => '0:16',
              'thumbnail' =>
              array (
                'url' => 'https://img.youtube.com/vi/XHOmBV4js_E/maxresdefault.jpg',
              ),
            ),
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
      ),
      'repeaters' =>
      array (
      ),
    ),
    1 =>
    array (
      'id' => 'section_inner_tab',
      'label' => 'Tabs',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'inner_tab_title_1',
          'type' => 'text',
          'label' => 'Tab 1 Name',
          'default' => 'Tab #1',
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
          'key' => 'inner_tab_title_2',
          'type' => 'text',
          'label' => 'Tab 2 Name',
          'default' => 'Tab #2',
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
          'key' => 'inner_tab_is_content_collapsible',
          'type' => 'switcher',
          'label' => 'Collapsible',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'inner_tab_label_show_more',
          'type' => 'text',
          'label' => 'Read More Label',
          'default' => 'Show More',
          'options' => NULL,
          'condition' =>
          array (
            'inner_tab_is_content_collapsible' => 'collapsible',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'inner_tab_label_show_less',
          'type' => 'text',
          'label' => 'Read Less Label',
          'default' => 'Show Less',
          'options' => NULL,
          'condition' =>
          array (
            'inner_tab_is_content_collapsible' => 'collapsible',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'inner_tab_collapsible_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' =>
          array (
            'size' => 54,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'inner_tab_is_content_collapsible' => 'collapsible',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_image_overlay',
      'label' => 'Image Overlay',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_image_overlay',
          'type' => 'switcher',
          'label' => 'Image Overlay',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'image_overlay',
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
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'show_play_icon',
          'type' => 'icons',
          'label' => 'Play Icon',
          'default' =>
          array (
            'value' => 'far fa-play-circle',
            'library' => 'fa-regular',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'image-size',
          'name' => 'image_overlay',
          'label' => NULL,
          'selector' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
          ),
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
      'id' => 'section_additional_options',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_autoplay',
          'type' => 'heading',
          'label' => 'Autoplay',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'autoplay_on_load',
          'type' => 'switcher',
          'label' => 'On Load',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'autoplay_next',
          'type' => 'switcher',
          'label' => 'Next Up',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'show_watched_indication',
          'type' => 'switcher',
          'label' => 'Indicate Watched',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_video_count',
          'type' => 'switcher',
          'label' => 'Video Count',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'show_duration',
          'type' => 'switcher',
          'label' => 'Duration',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'show_thumbnail',
          'type' => 'switcher',
          'label' => 'Thumbnails',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'play_icon',
          'type' => 'icons',
          'label' => 'Play Icon',
          'default' =>
          array (
            'value' => 'fas fa-play-circle',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'watched_icon',
          'type' => 'icons',
          'label' => 'Watched Icon',
          'default' =>
          array (
            'value' => 'fas fa-check-circle',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'lazy_load',
          'type' => 'switcher',
          'label' => 'Lazy Load',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'section_style_layout',
      'label' => 'Layout',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs_alignment',
          'type' => 'choose',
          'label' => 'Video Position',
          'default' => 'end',
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'layout_height',
          'type' => 'slider',
          'label' => 'Height',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    5 =>
    array (
      'id' => 'section_style_top_bar',
      'label' => 'Top Bar',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_playlist_name',
          'type' => 'heading',
          'label' => 'Playlist Name',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'playlist_name_background',
          'type' => 'color',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'playlist_name_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_videos_amount',
          'type' => 'heading',
          'label' => 'Video Count',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'videos_amount_color',
          'type' => 'color',
          'label' => 'Color',
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
          'name' => 'playlist_name_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-header .e-tabs-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'videos_amount_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-header .e-tabs-videos-count',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    6 =>
    array (
      'id' => 'section_style_videos',
      'label' => 'Videos',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_tab_normal',
          'type' => 'heading',
          'label' => 'Item',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'normal_background',
          'type' => 'color',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'normal_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_duration_normal',
          'type' => 'heading',
          'label' => 'Duration',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'normal_duration_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'heading_icon_normal',
          'type' => 'heading',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'normal_icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'normal_icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'heading_separator_normal',
          'type' => 'heading',
          'label' => 'Separator',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'normal_separator_style',
          'type' => 'select',
          'label' => 'Style',
          'default' => '',
          'options' =>
          array (
            '' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'normal_separator_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'normal_separator_style!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'normal_separator_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'normal_separator_style!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'heading_tab_active',
          'type' => 'heading',
          'label' => 'Item',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'active_background',
          'type' => 'color',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'active_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '#556068',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'heading_duration_active',
          'type' => 'heading',
          'label' => 'Duration',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'active_duration_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'heading_icon_active',
          'type' => 'heading',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'active_icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'active_icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        20 =>
        array (
          'key' => 'heading_separator_active',
          'type' => 'heading',
          'label' => 'Separator',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        21 =>
        array (
          'key' => 'active_separator_style',
          'type' => 'select',
          'label' => 'Style',
          'default' => '',
          'options' =>
          array (
            '' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        22 =>
        array (
          'key' => 'active_separator_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'active_separator_style!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        23 =>
        array (
          'key' => 'active_separator_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'active_separator_style!' => '',
          ),
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
          'name' => 'normal_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-items .e-tab-title .e-tab-title-text button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'normal_duration_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tab-title .e-tab-duration',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'normal_icon_top_text_shadow',
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'active_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-items-wrapper .e-tab-title:where( .e-active, :hover ) .e-tab-title-text button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'active_duration_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-items-wrapper .e-tab-title:where( .e-active, :hover ) .e-tab-duration',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'text-shadow',
          'name' => 'active_icon_top_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tab-title:where( .e-active, :hover ) i, {{WRAPPER}} .e-tab-title:where( .e-active, :hover ) svg',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    7 =>
    array (
      'id' => 'section_style_sections',
      'label' => 'Sections',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_section',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'section_background',
          'type' => 'color',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'section_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'section_border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => 'solid',
          'options' =>
          array (
            '' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'section_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'section_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
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
          'name' => 'section_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-items-wrapper .e-section-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    8 =>
    array (
      'id' => 'section_inner_tab_style',
      'label' => 'Tabs',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'inner_tab_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'inner_tab_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'inner_tab_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_inner_tab_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'inner_tab_title_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'inner_tab_active_title_color',
          'type' => 'color',
          'label' => 'Active Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'heading_inner_tab_content',
          'type' => 'heading',
          'label' => 'Content',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'inner_tab_content_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'inner_tab_content_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'heading_inner_tab_show_more',
          'type' => 'heading',
          'label' => 'Show More',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'inner_tab_normal_show_more_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'inner_tab_hover_show_more_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'inner_tab_hover_show_more_color_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 'ms',
          ),
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
          'name' => 'inner_tab_title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-inner-tabs .e-inner-tabs-wrapper .e-inner-tab-title a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'inner_tab_content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-inner-tabs .e-inner-tabs-content-wrapper .e-inner-tab-content .e-inner-tab-text',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'inner_tab_show_more_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-tabs-inner-tabs .e-inner-tabs-content-wrapper .e-inner-tab-content button',
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
      'name' => 'image_overlay',
      'label' => NULL,
      'selector' => NULL,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'playlist_name_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-header .e-tabs-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'videos_amount_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-header .e-tabs-videos-count',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'normal_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-items .e-tab-title .e-tab-title-text button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'normal_duration_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tab-title .e-tab-duration',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'normal_icon_top_text_shadow',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'active_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-items-wrapper .e-tab-title:where( .e-active, :hover ) .e-tab-title-text button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'typography',
      'name' => 'active_duration_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-items-wrapper .e-tab-title:where( .e-active, :hover ) .e-tab-duration',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'text-shadow',
      'name' => 'active_icon_top_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tab-title:where( .e-active, :hover ) i, {{WRAPPER}} .e-tab-title:where( .e-active, :hover ) svg',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'typography',
      'name' => 'section_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-items-wrapper .e-section-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'typography',
      'name' => 'inner_tab_title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-inner-tabs .e-inner-tabs-wrapper .e-inner-tab-title a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'typography',
      'name' => 'inner_tab_content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-inner-tabs .e-inner-tabs-content-wrapper .e-inner-tab-content .e-inner-tab-text',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'typography',
      'name' => 'inner_tab_show_more_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-tabs-inner-tabs .e-inner-tabs-content-wrapper .e-inner-tab-content button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
    0 =>
    array (
      'var' => 'repeater',
      'fields' =>
      array (
        0 =>
        array (
          'key' => 'type',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'youtube',
          'options' =>
          array (
            'youtube' => 'YouTube',
            'vimeo' => 'Vimeo',
            'hosted' => 'Self Hosted',
            'section' => 'Section',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'youtube_url',
          'type' => 'text',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'youtube',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'vimeo_url',
          'type' => 'text',
          'label' => 'Link',
          'default' => 'https://vimeo.com/235215203',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'vimeo',
          ),
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::POST_META_CATEGORY',
              ),
              1 =>
              array (
                '__unresolved__' => 'TagsModule::URL_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'vimeo_fetch_data',
          'type' => 'button',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'youtube',
              1 => 'vimeo',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'is_external_url',
          'type' => 'switcher',
          'label' => 'External URL',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'hosted',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'hosted_url',
          'type' => 'media',
          'label' => 'Choose File',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'hosted',
            'is_external_url' => '',
          ),
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::MEDIA_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'external_url',
          'type' => 'url',
          'label' => 'URL',
          'default' => NULL,
          'options' => false,
          'condition' =>
          array (
            'type' => 'hosted',
            'is_external_url' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::POST_META_CATEGORY',
              ),
              1 =>
              array (
                '__unresolved__' => 'TagsModule::URL_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Title',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'section_html_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'h3',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
          ),
          'condition' =>
          array (
            'type' => 'section',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'video_html_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'h4',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
          ),
          'condition' =>
          array (
            'type!' => 'section',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'duration',
          'type' => 'text',
          'label' => 'Duration',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type!' => 'section',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'thumbnail',
          'type' => 'media',
          'label' => 'Thumbnail',
          'default' =>
          array (
            'url' =>
            array (
              '__unresolved__' => 'Utils::get_placeholder_image_src()',
            ),
          ),
          'options' => NULL,
          'condition' =>
          array (
            'type!' => 'section',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'inner_tab_is_content_visible',
          'type' => 'switcher',
          'label' => 'Contents Tabs ',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'youtube',
              1 => 'hosted',
              2 => 'vimeo',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'inner_tab_content_1',
          'type' => 'wysiwyg',
          'label' => '',
          'default' => '<p>Add some content for each one of your videos, like a description, transcript or external links.To add, remove or edit tab names, go to Tabs.</p>',
          'options' => NULL,
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'youtube',
              1 => 'hosted',
              2 => 'vimeo',
            ),
            'inner_tab_is_content_visible' => 'show',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'inner_tab_content_2',
          'type' => 'wysiwyg',
          'label' => '',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'youtube',
              1 => 'hosted',
              2 => 'vimeo',
            ),
            'inner_tab_is_content_visible' => 'show',
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
    'tabs_direction' =>
    array (
      'section' => 'section_playlist',
      'type' => 'hidden',
      'default' => 'vertical',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'playlist_title' =>
    array (
      'section' => 'section_playlist',
      'type' => 'text',
      'default' => 'Playlist',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'playlist_title_tag' =>
    array (
      'section' => 'section_playlist',
      'type' => 'select',
      'default' => 'h2',
      'responsive' => false,
      'condition' =>
      array (
        'playlist_title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs' =>
    array (
      'section' => 'section_playlist',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'title' => 'Sample Video',
          'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
          'duration' => '0:16',
          'thumbnail' =>
          array (
            'url' => 'https://img.youtube.com/vi/XHOmBV4js_E/maxresdefault.jpg',
          ),
        ),
        1 =>
        array (
          'title' => 'Sample Video',
          'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
          'duration' => '0:16',
          'thumbnail' =>
          array (
            'url' => 'https://img.youtube.com/vi/XHOmBV4js_E/maxresdefault.jpg',
          ),
        ),
        2 =>
        array (
          'title' => 'Sample Video',
          'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
          'duration' => '0:16',
          'thumbnail' =>
          array (
            'url' => 'https://img.youtube.com/vi/XHOmBV4js_E/maxresdefault.jpg',
          ),
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_title_1' =>
    array (
      'section' => 'section_inner_tab',
      'type' => 'text',
      'default' => 'Tab #1',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_title_2' =>
    array (
      'section' => 'section_inner_tab',
      'type' => 'text',
      'default' => 'Tab #2',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_is_content_collapsible' =>
    array (
      'section' => 'section_inner_tab',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_label_show_more' =>
    array (
      'section' => 'section_inner_tab',
      'type' => 'text',
      'default' => 'Show More',
      'responsive' => false,
      'condition' =>
      array (
        'inner_tab_is_content_collapsible' => 'collapsible',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_label_show_less' =>
    array (
      'section' => 'section_inner_tab',
      'type' => 'text',
      'default' => 'Show Less',
      'responsive' => false,
      'condition' =>
      array (
        'inner_tab_is_content_collapsible' => 'collapsible',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_collapsible_height' =>
    array (
      'section' => 'section_inner_tab',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 54,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'inner_tab_is_content_collapsible' => 'collapsible',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_image_overlay' =>
    array (
      'section' => 'section_image_overlay',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_overlay' =>
    array (
      'section' => 'section_image_overlay',
      'type' => 'media',
      'default' =>
      array (
        'url' =>
        array (
          '__unresolved__' => 'Utils::get_placeholder_image_src()',
        ),
      ),
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_play_icon' =>
    array (
      'section' => 'section_image_overlay',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'far fa-play-circle',
        'library' => 'fa-regular',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_overlay_image_size' =>
    array (
      'section' => 'section_image_overlay',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
      ),
      'group' => 'image-size',
      'group_prefix' => 'image_overlay',
    ),
    'heading_autoplay' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay_on_load' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay_next' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_watched_indication' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_video_count' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_duration' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_thumbnail' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_icon' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-play-circle',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'watched_icon' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-check-circle',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lazy_load' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_alignment' =>
    array (
      'section' => 'section_style_layout',
      'type' => 'choose',
      'default' => 'end',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'layout_height' =>
    array (
      'section' => 'section_style_layout',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_playlist_name' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'playlist_name_background' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'playlist_name_color' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_videos_amount' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'videos_amount_color' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'playlist_name_typography_typography' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'playlist_name_typography',
    ),
    'videos_amount_typography_typography' =>
    array (
      'section' => 'section_style_top_bar',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'videos_amount_typography',
    ),
    'heading_tab_normal' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_background' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_duration_normal' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_duration_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_icon_normal' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_icon_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_icon_size' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_separator_normal' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_separator_style' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_separator_weight' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'normal_separator_style!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_separator_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'normal_separator_style!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_tab_active' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_background' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => '#556068',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_duration_active' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_duration_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_icon_active' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_icon_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_icon_size' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_separator_active' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_separator_style' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_separator_weight' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'active_separator_style!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'active_separator_color' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'active_separator_style!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'normal_typography_typography' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'normal_typography',
    ),
    'normal_duration_typography_typography' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'normal_duration_typography',
    ),
    'normal_icon_top_text_shadow_text_shadow' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'normal_icon_top_text_shadow',
    ),
    'active_typography_typography' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'active_typography',
    ),
    'active_duration_typography_typography' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'active_duration_typography',
    ),
    'active_icon_top_text_shadow_text_shadow' =>
    array (
      'section' => 'section_style_videos',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'active_icon_top_text_shadow',
    ),
    'heading_section' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_background' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_color' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_border_type' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'select',
      'default' => 'solid',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_border_width' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_border_color' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_typography_typography' =>
    array (
      'section' => 'section_style_sections',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'section_typography',
    ),
    'inner_tab_border_width' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_border_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_background_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_inner_tab_title' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_title_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_active_title_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_inner_tab_content' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_content_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_content_padding' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_inner_tab_show_more' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_normal_show_more_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_hover_show_more_color' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_hover_show_more_color_transition_duration' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'ms',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_tab_title_typography_typography' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'inner_tab_title_typography',
    ),
    'inner_tab_content_typography_typography' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'inner_tab_content_typography',
    ),
    'inner_tab_show_more_typography_typography' =>
    array (
      'section' => 'section_inner_tab_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'inner_tab_show_more_typography',
    ),
  ),
  'group_activators' =>
  array (
    'image_overlay_image_size' => 'custom',
    'playlist_name_typography_typography' => 'custom',
    'videos_amount_typography_typography' => 'custom',
    'normal_typography_typography' => 'custom',
    'normal_duration_typography_typography' => 'custom',
    'normal_icon_top_text_shadow_text_shadow' => 'yes',
    'active_typography_typography' => 'custom',
    'active_duration_typography_typography' => 'custom',
    'active_icon_top_text_shadow_text_shadow' => 'yes',
    'section_typography_typography' => 'custom',
    'inner_tab_title_typography_typography' => 'custom',
    'inner_tab_content_typography_typography' => 'custom',
    'inner_tab_show_more_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/video-playlist-widget-pro.md',
    1 => 'docs/knowledge/elementor/widgets/video-playlist-widget-pro.md',
  ),
  'control_count' => 86,
);
