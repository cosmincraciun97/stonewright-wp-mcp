<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'video',
  'source' => 'free',
  'widget_type' => 'video',
  'title' => 'Video',
  'icon' => 'eicon-youtube',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'video',
    1 => 'player',
    2 => 'embed',
    3 => 'youtube',
    4 => 'vimeo',
    5 => 'dailymotion',
    6 => 'videopress',
  ),
  'file' => 'elementor/includes/widgets/video.php',
  'intent' => 'Toggle to Yes if you want the YouTube captions to show in your video. Privacy Mode',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Video widget',
    4 => 'All available elements are displayed',
    5 => 'Click or drag the element to the canvas',
    6 => 'What is the Video element',
    7 => 'What is the Video Playlist widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add a Video widget – Step-by-step',
    2 => 'Note – The options below the Source field change based on the type of video source you choose. For this example, we’ll add a video from the Elementor YouTube channel, How to Create a Landing page in WordPress With Elementor Hosting.',
    3 => 'Tip – Leave the Start and End Time fields empty if you want the full video.',
    4 => 'To access and use a widget – In Elementor Editor, click +. All available elements are displayed.Click or drag the element to the canvas. For more information, see Add elements to a page.',
    5 => 'Adding a Video element – Step-by-step',
    6 => 'Content options – Configure general content, title, tags, and icons.',
    7 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    8 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    9 => 'Adding a Video Playlist widget – Step-by-step',
    10 => 'Give the Playlist name an HTML tag of H1 – H6, Div, or Span. Use the dropdown menu to designate the title as a header (H1-6). This helps search engines find and understand the playlist, boosting SEO. The title can also be tagged as a paragraph, span or div.',
    11 => 'Type – Use the dropdown to select among the compatible video formats: YouTube, Vimeo, and Self Hosted. YouTube and Vimeo require links to the videos, Self Hosted videos are uploaded from the Media Library. There is a fourth option, Section. This will place a text section between the playlist items. This is a good way to divide your video playlist into different groups. For example, Videos for beginners and Videos for advanced users.',
    12 => 'Get Video Data – Automatically grabs the title, duration and thumbnail from YouTube or Vimeo.',
    13 => 'Title – Give this video a name that will be displayed in the playlist.',
    14 => 'HTML tag – Give the video name an HTML tag of H1- H6, Div, or Span. Use the dropdown menu to designate the title as a header (H1-6). This helps search engines find and understand the video, boosting SEO. The title can also be tagged as a paragraph, span or div..',
    15 => 'Duration – Limit the length of the video in the playlist.',
  ),
  'limits' =>
  array (
    0 => 'They then add the relevant workout video with a custom thumbnail image on the hero section. This quickly grabs visitors’ attention and gets them to understand what the blog is about.',
    1 => 'The options below the Source field change based on the type of video source you choose. For this example, we’ll add a video from the Elementor YouTube channel, How to Create a Landing page in WordPress With Elementor Hosting.',
    2 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    3 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    4 => 'Duration: Limit the length of the video in the playlist.',
    5 => 'Toggle to Show if you want to limit the amount of text visible to visitors. This will bring up the following options:',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_video',
      'label' => 'Video',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'video_type',
          'type' => 'select',
          'label' => 'Source',
          'default' => 'youtube',
          'options' =>
          array (
            'youtube' => 'YouTube',
            'vimeo' => 'Vimeo',
            'dailymotion' => 'Dailymotion',
            'videopress' => 'VideoPress',
            'hosted' => 'Self Hosted',
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
          'default' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'youtube',
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
        2 =>
        array (
          'key' => 'vimeo_url',
          'type' => 'text',
          'label' => 'Link',
          'default' => 'https://vimeo.com/235215203',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'vimeo',
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
          'key' => 'dailymotion_url',
          'type' => 'text',
          'label' => 'Link',
          'default' => 'https://www.dailymotion.com/video/x6tqhqb',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'dailymotion',
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
        4 =>
        array (
          'key' => 'insert_url',
          'type' => 'switcher',
          'label' => 'External URL',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'hosted',
              1 => 'videopress',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'hosted_url',
          'type' => 'media',
          'label' => 'Choose Video File',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'hosted',
              1 => 'videopress',
            ),
            'insert_url' => '',
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
            'video_type' => 'hosted',
            'insert_url' => 'yes',
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
          'key' => 'videopress_url',
          'type' => 'text',
          'label' => 'URL',
          'default' => 'https://videopress.com/v/ZCAOzTNk',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'videopress',
            'insert_url' => 'yes',
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
        8 =>
        array (
          'key' => 'start',
          'type' => 'number',
          'label' => 'Start Time',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Specify a start time (in seconds)',
        ),
        9 =>
        array (
          'key' => 'end',
          'type' => 'number',
          'label' => 'End Time',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'youtube',
              1 => 'hosted',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Specify an end time (in seconds)',
        ),
        10 =>
        array (
          'key' => 'video_options',
          'type' => 'heading',
          'label' => 'Video Options',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'autoplay',
          'type' => 'switcher',
          'label' => 'Autoplay',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
        12 =>
        array (
          'key' => 'play_on_mobile',
          'type' => 'switcher',
          'label' => 'Play On Mobile',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'autoplay' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'mute',
          'type' => 'switcher',
          'label' => 'Mute',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'loop',
          'type' => 'switcher',
          'label' => 'Loop',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type!' => 'dailymotion',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'controls',
          'type' => 'switcher',
          'label' => 'Player Controls',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'video_type!' => 'vimeo',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'showinfo',
          'type' => 'switcher',
          'label' => 'Video Info',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'dailymotion',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'cc_load_policy',
          'type' => 'switcher',
          'label' => 'Captions',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'youtube',
            ),
            'controls' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'logo',
          'type' => 'switcher',
          'label' => 'Logo',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'dailymotion',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'yt_privacy',
          'type' => 'switcher',
          'label' => 'Privacy Mode',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'youtube',
              1 => 'vimeo',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'When you turn on privacy mode, YouTube/Vimeo won\'t store information about visitors on your website unless they play the video.',
        ),
        20 =>
        array (
          'key' => 'lazy_load',
          'type' => 'switcher',
          'label' => 'Lazy Load',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'video_type',
                'operator' => '===',
                'value' => 'youtube',
              ),
              1 =>
              array (
                'relation' => 'and',
                'terms' =>
                array (
                  0 =>
                  array (
                    'name' => 'show_image_overlay',
                    'operator' => '===',
                    'value' => 'yes',
                  ),
                  1 =>
                  array (
                    'name' => 'video_type',
                    'operator' => '!==',
                    'value' => 'hosted',
                  ),
                ),
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        21 =>
        array (
          'key' => 'rel',
          'type' => 'select',
          'label' => 'Suggested Videos',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Current Video Channel',
            'yes' => 'Any Video',
          ),
          'condition' =>
          array (
            'video_type' => 'youtube',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        22 =>
        array (
          'key' => 'vimeo_title',
          'type' => 'switcher',
          'label' => 'Intro Title',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'vimeo',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        23 =>
        array (
          'key' => 'vimeo_portrait',
          'type' => 'switcher',
          'label' => 'Intro Portrait',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'vimeo',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        24 =>
        array (
          'key' => 'vimeo_byline',
          'type' => 'switcher',
          'label' => 'Intro Byline',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'vimeo',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        25 =>
        array (
          'key' => 'color',
          'type' => 'color',
          'label' => 'Controls Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'video_type' =>
            array (
              0 => 'vimeo',
              1 => 'dailymotion',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        26 =>
        array (
          'key' => 'download_button',
          'type' => 'switcher',
          'label' => 'Download Button',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'hosted',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        27 =>
        array (
          'key' => 'preload',
          'type' => 'select',
          'label' => 'Preload',
          'default' => 'metadata',
          'options' =>
          array (
            'metadata' => 'Metadata',
            'auto' => 'Auto',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'video_type' => 'hosted',
            'autoplay' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
        28 =>
        array (
          'key' => 'poster',
          'type' => 'media',
          'label' => 'Poster',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'video_type' => 'hosted',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
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
          'type' => 'switcher',
          'label' => 'Play Icon',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'image_overlay[url]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'play_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'show_play_icon!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'lightbox',
          'type' => 'switcher',
          'label' => 'Lightbox',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'image_overlay[url]!' => '',
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
    2 =>
    array (
      'id' => 'section_video_style',
      'label' => 'Video',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'aspect_ratio',
          'type' => 'select',
          'label' => 'Aspect Ratio',
          'default' => '169',
          'options' =>
          array (
            169 => '16:9',
            219 => '21:9',
            43 => '4:3',
            32 => '3:2',
            11 => '1:1',
            916 => '9:16',
          ),
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
          'group' => 'css-filter',
          'name' => 'css_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-wrapper',
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
      'id' => 'section_image_overlay_style',
      'label' => 'Image Overlay',
      'tab' => 'style',
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'play_icon_title',
          'type' => 'heading',
          'label' => 'Play Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'show_play_icon' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'play_icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'show_play_icon' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'play_icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'show_play_icon' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'text-shadow',
          'name' => 'play_icon_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-custom-embed-play i',
          'condition' =>
          array (
            'show_image_overlay' => 'yes',
            'show_play_icon' => 'yes',
            'play_icon[library]!' => 'svg',
          ),
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
      'id' => 'section_lightbox_style',
      'label' => 'Lightbox',
      'tab' => 'style',
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'image_overlay[url]!' => '',
        'lightbox' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'lightbox_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'lightbox_ui_color',
          'type' => 'color',
          'label' => 'UI Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'lightbox_ui_color_hover',
          'type' => 'color',
          'label' => 'UI Hover Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'lightbox_content_animation',
          'type' => 'animation',
          'label' => 'Entrance Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'deprecation_warning',
          'type' => 'alert',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'lightbox_video_width!' => '',
            'lightbox_content_position!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'lightbox_video_width',
          'type' => 'slider',
          'label' => 'Content Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'lightbox_video_width!' => '',
            'lightbox_content_position!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'lightbox_content_position',
          'type' => 'select',
          'label' => 'Content Position',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Center',
            'top' => 'Top',
          ),
          'condition' =>
          array (
            'lightbox_video_width!' => '',
            'lightbox_content_position!' => '',
          ),
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
      'group' => 'css-filter',
      'name' => 'css_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-wrapper',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'play_icon_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-custom-embed-play i',
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon' => 'yes',
        'play_icon[library]!' => 'svg',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'video_type' =>
    array (
      'section' => 'section_video',
      'type' => 'select',
      'default' => 'youtube',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'youtube_url' =>
    array (
      'section' => 'section_video',
      'type' => 'text',
      'default' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'youtube',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vimeo_url' =>
    array (
      'section' => 'section_video',
      'type' => 'text',
      'default' => 'https://vimeo.com/235215203',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'vimeo',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dailymotion_url' =>
    array (
      'section' => 'section_video',
      'type' => 'text',
      'default' => 'https://www.dailymotion.com/video/x6tqhqb',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'dailymotion',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'insert_url' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'hosted',
          1 => 'videopress',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hosted_url' =>
    array (
      'section' => 'section_video',
      'type' => 'media',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'hosted',
          1 => 'videopress',
        ),
        'insert_url' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'external_url' =>
    array (
      'section' => 'section_video',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'hosted',
        'insert_url' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'videopress_url' =>
    array (
      'section' => 'section_video',
      'type' => 'text',
      'default' => 'https://videopress.com/v/ZCAOzTNk',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'videopress',
        'insert_url' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'start' =>
    array (
      'section' => 'section_video',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'end' =>
    array (
      'section' => 'section_video',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'youtube',
          1 => 'hosted',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'video_options' =>
    array (
      'section' => 'section_video',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_on_mobile' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'autoplay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'mute' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'loop' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type!' => 'dailymotion',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'controls' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'video_type!' => 'vimeo',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'showinfo' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'dailymotion',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'cc_load_policy' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'youtube',
        ),
        'controls' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'logo' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'dailymotion',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'yt_privacy' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'youtube',
          1 => 'vimeo',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lazy_load' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'video_type',
            'operator' => '===',
            'value' => 'youtube',
          ),
          1 =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'show_image_overlay',
                'operator' => '===',
                'value' => 'yes',
              ),
              1 =>
              array (
                'name' => 'video_type',
                'operator' => '!==',
                'value' => 'hosted',
              ),
            ),
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rel' =>
    array (
      'section' => 'section_video',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'youtube',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vimeo_title' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'vimeo',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vimeo_portrait' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'vimeo',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vimeo_byline' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'vimeo',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color' =>
    array (
      'section' => 'section_video',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' =>
        array (
          0 => 'vimeo',
          1 => 'dailymotion',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'download_button' =>
    array (
      'section' => 'section_video',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'hosted',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'preload' =>
    array (
      'section' => 'section_video',
      'type' => 'select',
      'default' => 'metadata',
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'hosted',
        'autoplay' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'poster' =>
    array (
      'section' => 'section_video',
      'type' => 'media',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'video_type' => 'hosted',
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
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'image_overlay[url]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_icon' =>
    array (
      'section' => 'section_image_overlay',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lightbox' =>
    array (
      'section' => 'section_image_overlay',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'image_overlay[url]!' => '',
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
    'aspect_ratio' =>
    array (
      'section' => 'section_video_style',
      'type' => 'select',
      'default' => '169',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'css_filters_css_filter' =>
    array (
      'section' => 'section_video_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters',
    ),
    'play_icon_title' =>
    array (
      'section' => 'section_image_overlay_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_icon_color' =>
    array (
      'section' => 'section_image_overlay_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_icon_size' =>
    array (
      'section' => 'section_image_overlay_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_icon_text_shadow_text_shadow' =>
    array (
      'section' => 'section_image_overlay_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_image_overlay' => 'yes',
        'show_play_icon' => 'yes',
        'play_icon[library]!' => 'svg',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'play_icon_text_shadow',
    ),
    'lightbox_color' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lightbox_ui_color' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lightbox_ui_color_hover' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lightbox_content_animation' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'animation',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'deprecation_warning' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'lightbox_video_width!' => '',
        'lightbox_content_position!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lightbox_video_width' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'lightbox_video_width!' => '',
        'lightbox_content_position!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lightbox_content_position' =>
    array (
      'section' => 'section_lightbox_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'lightbox_video_width!' => '',
        'lightbox_content_position!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'image_overlay_image_size' => 'custom',
    'css_filters_css_filter' => 'custom',
    'play_icon_text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'video_type',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/video-widget.md',
    1 => 'docs/knowledge/elementor/widgets/video-widget.md',
    2 => 'docs/knowledge/elementor/widgets/video-element.md',
    3 => 'docs/knowledge/elementor/widgets/video-playlist-widget-pro.md',
  ),
  'control_count' => 48,
);
