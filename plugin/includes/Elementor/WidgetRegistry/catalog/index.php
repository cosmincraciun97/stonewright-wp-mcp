<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'version' => '1.0.0',
  'generated_at' => '2026-07-14T14:27:25+00:00',
  'generator' => 'plugin/bin/manifest-synthesize.php',
  'elementor_version' => NULL,
  'pro_version' => NULL,
  'sources' =>
  array (
    'inventory' => 'docs/elementor/widget-registry-data/widget-inventory.json',
    'controls' => 'docs/elementor/widget-registry-data/widget-controls/',
    'knowledge' => 'docs/knowledge/elementor/widgets/',
  ),
  'group_activator_rules' =>
  array (
    'typography' => 'custom',
    'border' => 'solid',
    'background' => 'classic',
    'box-shadow' => 'yes',
    'text-shadow' => 'yes',
    'css-filter' => 'custom',
    'text-stroke' => 'yes',
  ),
  'totals' =>
  array (
    'inventory_widgets' => 95,
    'with_controls' => 90,
    'with_knowledge' => 58,
    'with_group_activators' => 64,
  ),
  'widgets' =>
  array (
    'accordion' =>
    array (
      'shard' => 'shards/accordion.php',
      'hash' => '67f9393a923b820153cba8b9c8ddfd596ddd357d3bd2e6d61834e3c8e8c8305f',
      'source' => 'free',
      'widget_type' => 'accordion',
      'title' => 'Accordion',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'accordion',
        1 => 'tabs',
        2 => 'toggle',
      ),
      'control_count' => 23,
    ),
    'alert' =>
    array (
      'shard' => 'shards/alert.php',
      'hash' => '5395f32a8a9dc088be95914dec3fb6ff7ec9e61fbe72414e9f5eb5ceb90852e2',
      'source' => 'free',
      'widget_type' => 'alert',
      'title' => 'Alert',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'alert',
        1 => 'notice',
        2 => 'message',
      ),
      'control_count' => 20,
    ),
    'animated-headline' =>
    array (
      'shard' => 'shards/animated-headline.php',
      'hash' => 'd891c60fe5777cd426269d41bd4740f61c15c06d2ea42143bb1dcfd7a15d654e',
      'source' => 'pro',
      'widget_type' => 'animated-headline',
      'title' => 'Animated Headline',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'headline',
        1 => 'heading',
        2 => 'animation',
        3 => 'title',
        4 => 'text',
      ),
      'control_count' => 32,
    ),
    'archive-posts' =>
    array (
      'shard' => 'shards/archive-posts.php',
      'hash' => '07841b92485a2c03aaccf8150d43bd258d32a72795044ea50bcfb931f9b9d0ae',
      'source' => 'pro',
      'widget_type' => 'archive-posts',
      'title' => 'Archive Posts',
      'categories' =>
      array (
        0 => 'theme-elements-archive',
      ),
      'keywords' =>
      array (
        0 => 'posts',
        1 => 'cpt',
        2 => 'archive',
        3 => 'loop',
        4 => 'query',
        5 => 'cards',
        6 => 'custom post type',
      ),
      'control_count' => 3,
    ),
    'audio' =>
    array (
      'shard' => 'shards/audio.php',
      'hash' => 'de3fb0835e2dcb4c722447f8a850b513c7ed256ee3dc343d36c5e406c81612ac',
      'source' => 'free',
      'widget_type' => 'audio',
      'title' => 'SoundCloud',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'audio',
        1 => 'player',
        2 => 'soundcloud',
        3 => 'embed',
      ),
      'control_count' => 13,
    ),
    'blockquote' =>
    array (
      'shard' => 'shards/blockquote.php',
      'hash' => '83da60c00151ee278d4232c4a79831fed75cf112c6a296de47074c6e8c6319aa',
      'source' => 'pro',
      'widget_type' => 'blockquote',
      'title' => 'Blockquote',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'blockquote',
        1 => 'quote',
        2 => 'paragraph',
        3 => 'testimonial',
        4 => 'text',
        5 => 'twitter',
        6 => 'tweet',
      ),
      'control_count' => 48,
    ),
    'button' =>
    array (
      'shard' => 'shards/button.php',
      'hash' => '758759514e2c1da494d5d2d52762bf81738a31a3171cb2bddd8916ec3c9a4ee0',
      'source' => 'free',
      'widget_type' => 'button',
      'title' => 'Button',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 24,
    ),
    'call-to-action' =>
    array (
      'shard' => 'shards/call-to-action.php',
      'hash' => '81dd05439ac988d37fe1ab8e750ef04ca9c158eb4ac36ac255736df72e263875',
      'source' => 'pro',
      'widget_type' => 'call-to-action',
      'title' => 'Call to Action',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'call to action',
        1 => 'cta',
        2 => 'button',
      ),
      'control_count' => 85,
    ),
    'code-highlight' =>
    array (
      'shard' => 'shards/code-highlight.php',
      'hash' => 'b5f59e5813e8b7696da34fc75385b2c71fe69d955909686d1634ed70577781e7',
      'source' => 'pro',
      'widget_type' => 'code-highlight',
      'title' => 'Code Highlight',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'code',
        1 => 'highlight',
        2 => 'syntax',
        3 => 'highlighter',
        4 => 'javascript',
        5 => 'css',
        6 => 'php',
        7 => 'html',
        8 => 'java',
        9 => 'js',
      ),
      'control_count' => 9,
    ),
    'countdown' =>
    array (
      'shard' => 'shards/countdown.php',
      'hash' => 'a10062ce28e195f8e30e4385d67ee99f9b34008327add1c972fa2cdb71689e94',
      'source' => 'pro',
      'widget_type' => 'countdown',
      'title' => 'Countdown',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'countdown',
        1 => 'number',
        2 => 'timer',
        3 => 'time',
        4 => 'date',
        5 => 'evergreen',
      ),
      'control_count' => 42,
    ),
    'counter' =>
    array (
      'shard' => 'shards/counter.php',
      'hash' => 'a01573a6f791f784bd90889bd8ebc8a4af86c8171424692a2111a3e3f992af90',
      'source' => 'free',
      'widget_type' => 'counter',
      'title' => 'Counter',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'counter',
      ),
      'control_count' => 24,
    ),
    'divider' =>
    array (
      'shard' => 'shards/divider.php',
      'hash' => '758dcb4a001538cb8686de75e8d61bfe5cd5f0e7e4aa10807b84cff67c8318ab',
      'source' => 'free',
      'widget_type' => 'divider',
      'title' => 'Divider',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'divider',
        1 => 'hr',
        2 => 'line',
        3 => 'border',
      ),
      'control_count' => 30,
    ),
    'facebook-button' =>
    array (
      'shard' => 'shards/facebook-button.php',
      'hash' => '9155b9096930601375fcc30560dd1c4e33cb92311f29b35d808d234148251b83',
      'source' => 'pro',
      'widget_type' => 'facebook-button',
      'title' => 'Facebook Button',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'facebook',
        1 => 'social',
        2 => 'embed',
        3 => 'button',
        4 => 'like',
        5 => 'share',
        6 => 'recommend',
        7 => 'follow',
      ),
      'control_count' => 9,
    ),
    'facebook-comments' =>
    array (
      'shard' => 'shards/facebook-comments.php',
      'hash' => '0a60e68e539c4429a2a2182e21870b0ef873e5a05da1fd99ab14ae9d4483ed45',
      'source' => 'pro',
      'widget_type' => 'facebook-comments',
      'title' => 'Facebook Comments',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'facebook',
        1 => 'comments',
        2 => 'embed',
      ),
      'control_count' => 5,
    ),
    'facebook-embed' =>
    array (
      'shard' => 'shards/facebook-embed.php',
      'hash' => '8d73c29acf5b85c69a147f0a00acd54375744b50be8f261232a0b91b2405afc7',
      'source' => 'pro',
      'widget_type' => 'facebook-embed',
      'title' => 'Facebook Embed',
      'categories' =>
      array (
        0 => 'pro-elements',
      ),
      'keywords' =>
      array (
        0 => 'facebook',
        1 => 'social',
        2 => 'embed',
        3 => 'video',
        4 => 'post',
        5 => 'comment',
      ),
      'control_count' => 10,
    ),
    'facebook-page' =>
    array (
      'shard' => 'shards/facebook-page.php',
      'hash' => '08613bbc3c094a2549a190983daff5f422b4108af82e0fd9e7c72ac9ad176f5b',
      'source' => 'pro',
      'widget_type' => 'facebook-page',
      'title' => 'Facebook Page',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'facebook',
        1 => 'social',
        2 => 'embed',
        3 => 'page',
      ),
      'control_count' => 8,
    ),
    'flip-box' =>
    array (
      'shard' => 'shards/flip-box.php',
      'hash' => 'e9e43c621c15555b629a8b8983129da83a28109e5cb38d915178952cfff71502',
      'source' => 'pro',
      'widget_type' => 'flip-box',
      'title' => 'Flip Box',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 82,
    ),
    'gallery' =>
    array (
      'shard' => 'shards/gallery.php',
      'hash' => '050d38c283fc22cba7ca9f8557133fa1033acd22a4d3a2e0fada2b9b30dfa28c',
      'source' => 'pro',
      'widget_type' => 'gallery',
      'title' => 'Gallery',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 62,
    ),
    'global' =>
    array (
      'shard' => 'shards/global.php',
      'hash' => 'c0e264898a7774b716b83fc70c816977b28e5eaf3075aa1de922488743d5b1c4',
      'source' => 'pro',
      'widget_type' => 'global',
      'title' => 'Global',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 0,
    ),
    'google_maps' =>
    array (
      'shard' => 'shards/google_maps.php',
      'hash' => '46fada5eb4db9d791148e87dcd01ba7d6e335e1eb2178f3f0fe7a676a98f561a',
      'source' => 'free',
      'widget_type' => 'google_maps',
      'title' => 'Google Maps',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'google',
        1 => 'map',
        2 => 'embed',
        3 => 'location',
      ),
      'control_count' => 7,
    ),
    'heading' =>
    array (
      'shard' => 'shards/heading.php',
      'hash' => 'f96d7f5fb0d7d289b846f147177bf5b7b21f1c3e47dc8bd64c6baf83d949feb3',
      'source' => 'free',
      'widget_type' => 'heading',
      'title' => 'Heading',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'heading',
        1 => 'title',
        2 => 'text',
      ),
      'control_count' => 13,
    ),
    'hotspot' =>
    array (
      'shard' => 'shards/hotspot.php',
      'hash' => 'df782139951a04bf5acf72d2a967c435bc33764089322daaf57805eaec3c69d0',
      'source' => 'pro',
      'widget_type' => 'hotspot',
      'title' => 'Hotspot',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'image',
        1 => 'tooltip',
        2 => 'CTA',
        3 => 'dot',
      ),
      'control_count' => 27,
    ),
    'html' =>
    array (
      'shard' => 'shards/html.php',
      'hash' => '1429e6ead354c47bc8283ffc0e136115771a7ac85136d39f82164daac489ce2d',
      'source' => 'free',
      'widget_type' => 'html',
      'title' => 'HTML',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'html',
        1 => 'code',
        2 => 'embed',
        3 => 'script',
      ),
      'control_count' => 1,
    ),
    'icon' =>
    array (
      'shard' => 'shards/icon.php',
      'hash' => '0133eec515cde9cb5f84e8d403bc75587023884306151168f7c0b2acbde93613',
      'source' => 'free',
      'widget_type' => 'icon',
      'title' => 'Icon',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'icon',
      ),
      'control_count' => 16,
    ),
    'icon-box' =>
    array (
      'shard' => 'shards/icon-box.php',
      'hash' => '670b8a05db21d85aa6b2684997802978e63d056a0edd639c6d926e7d5d4d29a6',
      'source' => 'free',
      'widget_type' => 'icon-box',
      'title' => 'Icon Box',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'icon box',
        1 => 'icon',
      ),
      'control_count' => 34,
    ),
    'icon-list' =>
    array (
      'shard' => 'shards/icon-list.php',
      'hash' => 'ba240172d881a4cae27335677618fc8a0a26090c9d99c9ef2bd7b1a99e712fdc',
      'source' => 'free',
      'widget_type' => 'icon-list',
      'title' => 'Icon List',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'icon list',
        1 => 'icon',
        2 => 'list',
      ),
      'control_count' => 24,
    ),
    'image' =>
    array (
      'shard' => 'shards/image.php',
      'hash' => '26fb08204c1142e3ddc1c2e12b231741f79cfca1389d498a992c9e77d8bfadfd',
      'source' => 'free',
      'widget_type' => 'image',
      'title' => 'Image',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'image',
        1 => 'photo',
        2 => 'visual',
      ),
      'control_count' => 29,
    ),
    'image-box' =>
    array (
      'shard' => 'shards/image-box.php',
      'hash' => '31916bcfdf955af7b1ddd60c53657b3cc74b792cc20faa6eba100a06c23df25b',
      'source' => 'free',
      'widget_type' => 'image-box',
      'title' => 'Image Box',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'image',
        1 => 'photo',
        2 => 'visual',
        3 => 'box',
      ),
      'control_count' => 36,
    ),
    'image-carousel' =>
    array (
      'shard' => 'shards/image-carousel.php',
      'hash' => 'cdd7baa5c66dc6eff04cf9195fc332a95097e28ab504c4b65c4f866bd9235d40',
      'source' => 'free',
      'widget_type' => 'image-carousel',
      'title' => 'Image Carousel',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'image',
        1 => 'photo',
        2 => 'visual',
        3 => 'carousel',
        4 => 'slider',
      ),
      'control_count' => 42,
    ),
    'image-gallery' =>
    array (
      'shard' => 'shards/image-gallery.php',
      'hash' => '582090393772c1e84ad756569daaf236a9fbf7c988e8df680fbda377de649759',
      'source' => 'free',
      'widget_type' => 'image-gallery',
      'title' => 'Basic Gallery',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'image',
        1 => 'photo',
        2 => 'visual',
        3 => 'gallery',
      ),
      'control_count' => 16,
    ),
    'inner-section' =>
    array (
      'shard' => 'shards/inner-section.php',
      'hash' => 'ce842cf51017dbad0b50f94f29d3385b2745da93bfdd6759633fbdf73032988f',
      'source' => 'free',
      'widget_type' => 'inner-section',
      'title' => 'Inner Section',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'row',
        1 => 'columns',
        2 => 'nested',
      ),
      'control_count' => 0,
    ),
    'login' =>
    array (
      'shard' => 'shards/login.php',
      'hash' => 'cc10c256170e51a10aafa90c2916adccd3d2aeb3ddac16261f92ae325a0a2793',
      'source' => 'pro',
      'widget_type' => 'login',
      'title' => 'Login',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'login',
        1 => 'user',
        2 => 'form',
      ),
      'control_count' => 43,
    ),
    'lottie' =>
    array (
      'shard' => 'shards/lottie.php',
      'hash' => 'c2a5b5db624634eda481125f54c5bebbbc60a3ad0ab4fa866762a30e5eeafaa4',
      'source' => 'pro',
      'widget_type' => 'lottie',
      'title' => 'Lottie',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 33,
    ),
    'menu-anchor' =>
    array (
      'shard' => 'shards/menu-anchor.php',
      'hash' => '0ff3b4d06e6b65f2bb75e5acd0f072097121acb9d37ab6a6cc3a0416e4f3f09e',
      'source' => 'free',
      'widget_type' => 'menu-anchor',
      'title' => 'Menu Anchor',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'menu',
        1 => 'anchor',
        2 => 'link',
      ),
      'control_count' => 2,
    ),
    'nav-menu' =>
    array (
      'shard' => 'shards/nav-menu.php',
      'hash' => '516bc8a179ed521cdace089e747c1ed3341c69053c83a1ea049bddb8033e16df',
      'source' => 'pro',
      'widget_type' => 'nav-menu',
      'title' => 'WordPress Menu',
      'categories' =>
      array (
        0 => 'pro-elements',
        1 => 'theme-elements',
      ),
      'keywords' =>
      array (
        0 => 'menu',
        1 => 'nav',
        2 => 'button',
        3 => 'nav menu',
      ),
      'control_count' => 61,
    ),
    'portfolio' =>
    array (
      'shard' => 'shards/portfolio.php',
      'hash' => 'ffeb038d01660f47ad0413016c919d2ab374a06e2a71dd84452d08fcaf9c8e34',
      'source' => 'pro',
      'widget_type' => 'portfolio',
      'title' => 'Portfolio',
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
        5 => 'portfolio',
        6 => 'custom post type',
      ),
      'control_count' => 21,
    ),
    'posts' =>
    array (
      'shard' => 'shards/posts.php',
      'hash' => 'ca23176ab9b705f4b6ff6731d2a964630bde1a35bec569e509d16bb7a96dbb94',
      'source' => 'pro',
      'widget_type' => 'posts',
      'title' => 'Posts',
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
      'control_count' => 0,
    ),
    'price-list' =>
    array (
      'shard' => 'shards/price-list.php',
      'hash' => 'd368a653e9bc5eae9754ded7d5c5aa6021972e9ccebc899610194ef8f6775f64',
      'source' => 'pro',
      'widget_type' => 'price-list',
      'title' => 'Price List',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'pricing',
        1 => 'list',
        2 => 'product',
        3 => 'image',
        4 => 'menu',
      ),
      'control_count' => 24,
    ),
    'price-table' =>
    array (
      'shard' => 'shards/price-table.php',
      'hash' => 'c87a5cc16f5b790c73ad69f3a537e76ded91152a08d06dd2116ad961c144e462',
      'source' => 'pro',
      'widget_type' => 'price-table',
      'title' => 'Price Table',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'pricing',
        1 => 'table',
        2 => 'product',
        3 => 'image',
        4 => 'plan',
        5 => 'button',
      ),
      'control_count' => 79,
    ),
    'progress' =>
    array (
      'shard' => 'shards/progress.php',
      'hash' => 'a5382fbc23018e82f5c05d891b647586eafca4eb0255cf375835d9f9b0b9bb63',
      'source' => 'free',
      'widget_type' => 'progress',
      'title' => 'Progress Bar',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'progress',
        1 => 'bar',
      ),
      'control_count' => 20,
    ),
    'progress-tracker' =>
    array (
      'shard' => 'shards/progress-tracker.php',
      'hash' => '17ceed3f7d28fdf6b1d5c6935320904dcdb56c8a0381f98486031b40581b4e45',
      'source' => 'pro',
      'widget_type' => 'progress-tracker',
      'title' => 'Progress Tracker',
      'categories' =>
      array (
        0 => 'pro-elements',
        1 => 'theme-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'progress',
        1 => 'tracker',
        2 => 'read',
        3 => 'scroll',
      ),
      'control_count' => 32,
    ),
    'rating' =>
    array (
      'shard' => 'shards/rating.php',
      'hash' => '2c6d0f79f0f2b0b161e4b9e097f4ba00a15faa47f4542f837baeae2a11ac531b',
      'source' => 'free',
      'widget_type' => 'rating',
      'title' => 'Rating',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'star',
        1 => 'rating',
        2 => 'review',
        3 => 'score',
        4 => 'scale',
      ),
      'control_count' => 8,
    ),
    'read-more' =>
    array (
      'shard' => 'shards/read-more.php',
      'hash' => 'd0901eecd44cd24087b47674cd06d123a0f7a33afc1b295d948f0e1fb82f8d55',
      'source' => 'free',
      'widget_type' => 'read-more',
      'title' => 'Read More',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'read',
        1 => 'more',
        2 => 'tag',
        3 => 'excerpt',
      ),
      'control_count' => 2,
    ),
    'search' =>
    array (
      'shard' => 'shards/search.php',
      'hash' => '3a3916c57e6a0decb1063dc3871b8f6262e2299fe69240e5f3a3f935df42438f',
      'source' => 'pro',
      'widget_type' => 'search',
      'title' => 'Search',
      'categories' =>
      array (
        0 => 'pro-elements',
      ),
      'keywords' =>
      array (
        0 => 'search',
      ),
      'control_count' => 81,
    ),
    'share-buttons' =>
    array (
      'shard' => 'shards/share-buttons.php',
      'hash' => '9d1c521f250e0c4621665052a301f6f1dfdae0c95d10eab8fe2ca11349edd12a',
      'source' => 'pro',
      'widget_type' => 'share-buttons',
      'title' => 'Share Buttons',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'sharing',
        1 => 'social',
        2 => 'icon',
        3 => 'button',
        4 => 'like',
      ),
      'control_count' => 22,
    ),
    'shortcode' =>
    array (
      'shard' => 'shards/shortcode.php',
      'hash' => 'a41bce5d0e86fcb8256e8a57e96c7cd97f3e5ac99b3c42e7908fb743e5985de6',
      'source' => 'free',
      'widget_type' => 'shortcode',
      'title' => 'Shortcode',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'shortcode',
        1 => 'code',
      ),
      'control_count' => 1,
    ),
    'sidebar' =>
    array (
      'shard' => 'shards/sidebar.php',
      'hash' => 'd54fe7eb6243af09cab40547972a470ce40f6b41e8dafecce15237d0c1875f74',
      'source' => 'free',
      'widget_type' => 'sidebar',
      'title' => 'Sidebar',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'sidebar',
        1 => 'widget',
      ),
      'control_count' => 1,
    ),
    'slides' =>
    array (
      'shard' => 'shards/slides.php',
      'hash' => '7cdf43ed859942606bfea9b50eb53cf47053132742d0cad268eaed21cb4f8ae6',
      'source' => 'pro',
      'widget_type' => 'slides',
      'title' => 'Slides',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'slides',
        1 => 'carousel',
        2 => 'image',
        3 => 'title',
        4 => 'slider',
      ),
      'control_count' => 47,
    ),
    'social-icons' =>
    array (
      'shard' => 'shards/social-icons.php',
      'hash' => '1e8bdfc6b0afeba298efa10c3b71c488825df4e8f8b18b8cda95316eeb8719d2',
      'source' => 'free',
      'widget_type' => 'social-icons',
      'title' => 'Social Icons',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'social',
        1 => 'icon',
        2 => 'link',
      ),
      'control_count' => 17,
    ),
    'spacer' =>
    array (
      'shard' => 'shards/spacer.php',
      'hash' => '36acc31e98ee4222d9325f249a84f4b0815c6548c329271741f0899950282acb',
      'source' => 'free',
      'widget_type' => 'spacer',
      'title' => 'Spacer',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'space',
      ),
      'control_count' => 1,
    ),
    'star-rating' =>
    array (
      'shard' => 'shards/star-rating.php',
      'hash' => '316604b1f0d3dcc05aa34a1cab0121753ffd66e06b493444deb5da42a530d266',
      'source' => 'free',
      'widget_type' => 'star-rating',
      'title' => 'Star Rating',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'star',
        1 => 'rating',
        2 => 'rate',
        3 => 'review',
      ),
      'control_count' => 14,
    ),
    'table-of-contents' =>
    array (
      'shard' => 'shards/table-of-contents.php',
      'hash' => '56053c766ae977235b4576e517840d64cc88f616e92815c6410eb458dc531e60',
      'source' => 'pro',
      'widget_type' => 'table-of-contents',
      'title' => 'Table of Contents',
      'categories' =>
      array (
        0 => 'pro-elements',
        1 => 'theme-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'toc',
      ),
      'control_count' => 44,
    ),
    'tabs' =>
    array (
      'shard' => 'shards/tabs.php',
      'hash' => 'e8caffeb8051b4b02d9d503ec16417bcc7c4007bad43ba52d937c0bcd064be03',
      'source' => 'free',
      'widget_type' => 'tabs',
      'title' => 'Tabs',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'tabs',
        1 => 'accordion',
        2 => 'toggle',
      ),
      'control_count' => 19,
    ),
    'taxonomy-filter' =>
    array (
      'shard' => 'shards/taxonomy-filter.php',
      'hash' => '994e2eb67ed31d2d330dacf81fa44a317ac6e9b9a19b73f7595d2d24a1381494',
      'source' => 'pro',
      'widget_type' => 'taxonomy-filter',
      'title' => 'Taxonomy Filter',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'filter',
        1 => 'loop',
        2 => 'filter bar',
        3 => 'taxonomy',
        4 => 'categories',
        5 => 'tags',
      ),
      'control_count' => 37,
    ),
    'template' =>
    array (
      'shard' => 'shards/template.php',
      'hash' => 'fdb40f9b03c28e9f80be87fa81cd86842d08b32d24f947db9792c08cba0bbe81',
      'source' => 'pro',
      'widget_type' => 'template',
      'title' => 'Template',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'elementor',
        1 => 'template',
        2 => 'library',
        3 => 'block',
        4 => 'page',
      ),
      'control_count' => 1,
    ),
    'testimonial' =>
    array (
      'shard' => 'shards/testimonial.php',
      'hash' => 'f40ded4e124a41b29b4b7ba0f08c29278610a33df28c90239f17bccdaa3822c7',
      'source' => 'free',
      'widget_type' => 'testimonial',
      'title' => 'Testimonial',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'testimonial',
        1 => 'blockquote',
      ),
      'control_count' => 20,
    ),
    'text-editor' =>
    array (
      'shard' => 'shards/text-editor.php',
      'hash' => '32e52d0960f4f639c83d6250a0007361dfa21c0c671adcd957cd4dbc491371e5',
      'source' => 'free',
      'widget_type' => 'text-editor',
      'title' => 'Text Editor',
      'categories' =>
      array (
        0 => 'basic',
      ),
      'keywords' =>
      array (
        0 => 'text',
        1 => 'editor',
      ),
      'control_count' => 22,
    ),
    'theme-archive-title' =>
    array (
      'shard' => 'shards/theme-archive-title.php',
      'hash' => 'a17f3a2fb7c69cca19011960da3061ebeafeba1eef67353b006088e9a8c4bdd8',
      'source' => 'pro',
      'widget_type' => 'theme-archive-title',
      'title' => 'Archive Title',
      'categories' =>
      array (
        0 => 'theme-elements-archive',
      ),
      'keywords' =>
      array (
        0 => 'title',
        1 => 'heading',
        2 => 'archive',
      ),
      'control_count' => 0,
    ),
    'theme-page-title' =>
    array (
      'shard' => 'shards/theme-page-title.php',
      'hash' => '798ca75e13e1e61a7226030d8b57f679cbaf6db9a2751a4c5ce99f040f7a8dc6',
      'source' => 'pro',
      'widget_type' => 'theme-page-title',
      'title' => 'Page Title',
      'categories' =>
      array (
        0 => 'theme-elements',
      ),
      'keywords' =>
      array (
        0 => 'title',
        1 => 'heading',
        2 => 'page',
      ),
      'control_count' => 0,
    ),
    'theme-post-content' =>
    array (
      'shard' => 'shards/theme-post-content.php',
      'hash' => '8305a2717bf4539d890fa72945a22a17ab8d0cbcc3303fcb5e5bdd2fd2d94b58',
      'source' => 'pro',
      'widget_type' => 'theme-post-content',
      'title' => 'Post Content',
      'categories' =>
      array (
        0 => 'theme-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'content',
        1 => 'post',
      ),
      'control_count' => 3,
    ),
    'theme-post-excerpt' =>
    array (
      'shard' => 'shards/theme-post-excerpt.php',
      'hash' => 'be38723c7c9cbd11e8f54f64f93128ebc65eb56f1d7d25ed0a59df97fe8bea08',
      'source' => 'pro',
      'widget_type' => 'theme-post-excerpt',
      'title' => 'Post Excerpt',
      'categories' =>
      array (
        0 => 'theme-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'post',
        1 => 'excerpt',
        2 => 'description',
      ),
      'control_count' => 10,
    ),
    'theme-post-featured-image' =>
    array (
      'shard' => 'shards/theme-post-featured-image.php',
      'hash' => 'a3bae76e98ce1bbcfbc4b13889e7e404ffb62dca01dfdd679388528b246fce30',
      'source' => 'pro',
      'widget_type' => 'theme-post-featured-image',
      'title' => 'Featured Image',
      'categories' =>
      array (
        0 => 'theme-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'image',
        1 => 'featured',
        2 => 'thumbnail',
      ),
      'control_count' => 0,
    ),
    'theme-post-title' =>
    array (
      'shard' => 'shards/theme-post-title.php',
      'hash' => '8468a2fee0f66ae95d6038c3b7c48c4889bd613e7ff6810e6942c28736fa531b',
      'source' => 'pro',
      'widget_type' => 'theme-post-title',
      'title' => 'Post Title',
      'categories' =>
      array (
        0 => 'theme-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'title',
        1 => 'heading',
        2 => 'post',
      ),
      'control_count' => 0,
    ),
    'theme-site-logo' =>
    array (
      'shard' => 'shards/theme-site-logo.php',
      'hash' => '80948e949b9cd04272828267fb504e39030fa251108e193ca4e92d7d79dc8465',
      'source' => 'pro',
      'widget_type' => 'theme-site-logo',
      'title' => 'Site Logo',
      'categories' =>
      array (
        0 => 'theme-elements',
      ),
      'keywords' =>
      array (
        0 => 'site',
        1 => 'logo',
        2 => 'branding',
      ),
      'control_count' => 0,
    ),
    'theme-site-title' =>
    array (
      'shard' => 'shards/theme-site-title.php',
      'hash' => '4eb3e39690afb445d23390eddeaf95d2db91f1d40444085e965dbb1558c021c7',
      'source' => 'pro',
      'widget_type' => 'theme-site-title',
      'title' => 'Site Title',
      'categories' =>
      array (
        0 => 'theme-elements',
      ),
      'keywords' =>
      array (
        0 => 'site',
        1 => 'title',
        2 => 'name',
      ),
      'control_count' => 0,
    ),
    'toggle' =>
    array (
      'shard' => 'shards/toggle.php',
      'hash' => '26d1a4703df12c99a12f818c32142022446fd8362463c3a22ed89c65e384369f',
      'source' => 'free',
      'widget_type' => 'toggle',
      'title' => 'Toggle',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'tabs',
        1 => 'accordion',
        2 => 'toggle',
      ),
      'control_count' => 24,
    ),
    'video' =>
    array (
      'shard' => 'shards/video.php',
      'hash' => '06ccdec4d55cc417e3cdfd61afa53eb40fe477a21efb59b51d12e1914b211fc7',
      'source' => 'free',
      'widget_type' => 'video',
      'title' => 'Video',
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
      'control_count' => 48,
    ),
    'video-playlist' =>
    array (
      'shard' => 'shards/video-playlist.php',
      'hash' => 'ccc75f48681398b86b6f0f14c391a7edc65af0fb2a69b5e5dce684514860dd07',
      'source' => 'pro',
      'widget_type' => 'video-playlist',
      'title' => 'Video Playlist',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 86,
    ),
    'wc-add-to-cart' =>
    array (
      'shard' => 'shards/wc-add-to-cart.php',
      'hash' => '356574fc301056baf1b3445f1005d863d6297e66fb38613670b0a7097e058b89',
      'source' => 'wc',
      'widget_type' => 'wc-add-to-cart',
      'title' => 'Custom Add To Cart',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'cart',
        4 => 'product',
        5 => 'button',
        6 => 'add to cart',
      ),
      'control_count' => 4,
    ),
    'wc-categories' =>
    array (
      'shard' => 'shards/wc-categories.php',
      'hash' => 'b42b9784f57489dd6ebe260e55b09d7dacba0c0393b0540310ebdfec0e8f3959',
      'source' => 'wc',
      'widget_type' => 'wc-categories',
      'title' => 'Product Categories',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce-elements',
        1 => 'shop',
        2 => 'store',
        3 => 'categories',
        4 => 'product',
      ),
      'control_count' => 22,
    ),
    'wc-elements' =>
    array (
      'shard' => 'shards/wc-elements.php',
      'hash' => 'd400c71437a47f7a58aff7e1935e2a65768dbb4cb88c376fa262f4d99d9cdae6',
      'source' => 'wc',
      'widget_type' => 'wc-elements',
      'title' => 'WooCommerce Pages',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'cart',
        4 => 'checkout',
        5 => 'account',
        6 => 'order tracking',
        7 => 'shortcode',
        8 => 'product',
      ),
      'control_count' => 2,
    ),
    'wc-single-elements' =>
    array (
      'shard' => 'shards/wc-single-elements.php',
      'hash' => '01b2fc1f1629a48edf672bdaab714b5a510dd6790b13187f5506282bfde62546',
      'source' => 'wc',
      'widget_type' => 'wc-single-elements',
      'title' => 'Woo - Single Elements',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 1,
    ),
    'woocommerce-archive-description' =>
    array (
      'shard' => 'shards/woocommerce-archive-description.php',
      'hash' => 'bcb9639f5a85a6e3cc91925860d99c4364ec8de59f74578ee505063f8d86b581',
      'source' => 'wc',
      'widget_type' => 'woocommerce-archive-description',
      'title' => 'Archive Description',
      'categories' =>
      array (
        0 => 'woocommerce-elements-archive',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'text',
        4 => 'description',
        5 => 'category',
        6 => 'product',
        7 => 'archive',
      ),
      'control_count' => 4,
    ),
    'woocommerce-breadcrumb' =>
    array (
      'shard' => 'shards/woocommerce-breadcrumb.php',
      'hash' => '903a248b2f076dca27cde1a4983cae41bb164b8c54d32ad7b76945d3a7b62c0f',
      'source' => 'wc',
      'widget_type' => 'woocommerce-breadcrumb',
      'title' => 'WooCommerce Breadcrumbs',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
        1 => 'woocommerce-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce-elements',
        1 => 'shop',
        2 => 'store',
        3 => 'breadcrumbs',
        4 => 'internal links',
        5 => 'product',
      ),
      'control_count' => 5,
    ),
    'woocommerce-cart' =>
    array (
      'shard' => 'shards/woocommerce-cart.php',
      'hash' => 'cca23f75f75ed00dc630dab5883186994d03a75388546f1f8391de665250b086',
      'source' => 'wc',
      'widget_type' => 'woocommerce-cart',
      'title' => 'Cart',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'cart',
      ),
      'control_count' => 163,
    ),
    'woocommerce-category-image' =>
    array (
      'shard' => 'shards/woocommerce-category-image.php',
      'hash' => 'af730a3ad6264f56bf28ba0f08515db81e336424b651532c56aa6a6319638519',
      'source' => 'wc',
      'widget_type' => 'woocommerce-category-image',
      'title' => 'Category Image',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
        1 => 'woocommerce-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'category',
        2 => 'image',
        3 => 'thumbnail',
      ),
      'control_count' => 0,
    ),
    'woocommerce-checkout-page' =>
    array (
      'shard' => 'shards/woocommerce-checkout-page.php',
      'hash' => '5f4dcd740509d22d75e475e0c9c91a0ae0fa505736a7273a5b72e13ffdaa2057',
      'source' => 'wc',
      'widget_type' => 'woocommerce-checkout-page',
      'title' => 'Checkout',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'checkout',
      ),
      'control_count' => 255,
    ),
    'woocommerce-menu-cart' =>
    array (
      'shard' => 'shards/woocommerce-menu-cart.php',
      'hash' => '2b3245243aa18925e7aad73f0a0d73446b5048adc511a139ca1f0ceb48ee630f',
      'source' => 'wc',
      'widget_type' => 'woocommerce-menu-cart',
      'title' => 'Menu Cart',
      'categories' =>
      array (
        0 => 'theme-elements',
        1 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 122,
    ),
    'woocommerce-my-account' =>
    array (
      'shard' => 'shards/woocommerce-my-account.php',
      'hash' => 'd8d7afd40c4b4315b6a93dca724cb421e8026eb6dc67ab819c9bc64d1fdb164f',
      'source' => 'wc',
      'widget_type' => 'woocommerce-my-account',
      'title' => 'My Account',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 127,
    ),
    'woocommerce-notices' =>
    array (
      'shard' => 'shards/woocommerce-notices.php',
      'hash' => 'b156061797563855dff133b2b100658700e64a34b83b1bdcee73fbcda8ddd085',
      'source' => 'wc',
      'widget_type' => 'woocommerce-notices',
      'title' => 'WooCommerce Notices',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'notices',
        2 => 'notifications',
      ),
      'control_count' => 3,
    ),
    'woocommerce-product-add-to-cart' =>
    array (
      'shard' => 'shards/woocommerce-product-add-to-cart.php',
      'hash' => '671f5739d10cd92bda742686a31edb0992831ce50e52e5dff3fe9ab64020a804',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-add-to-cart',
      'title' => 'Add To Cart',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'cart',
        4 => 'product',
        5 => 'button',
        6 => 'add to cart',
      ),
      'control_count' => 43,
    ),
    'woocommerce-product-additional-information' =>
    array (
      'shard' => 'shards/woocommerce-product-additional-information.php',
      'hash' => '84545e8009e84174b7983ee1c2c9ba2131930bb29006be66323c8b310d757f8a',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-additional-information',
      'title' => 'Additional Information',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
      ),
      'control_count' => 5,
    ),
    'woocommerce-product-data-tabs' =>
    array (
      'shard' => 'shards/woocommerce-product-data-tabs.php',
      'hash' => '7e7e1f6812cb36fde242d430ffa3154f9b0b40e6f234f04fec87e521bbee4d5b',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-data-tabs',
      'title' => 'Product Data Tabs',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'data',
        4 => 'product',
        5 => 'tabs',
      ),
      'control_count' => 17,
    ),
    'woocommerce-product-images' =>
    array (
      'shard' => 'shards/woocommerce-product-images.php',
      'hash' => '53bdc0d79a95e2f489d606909e038e47f92d770fbbaf36bda94160e9c14b7d5d',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-images',
      'title' => 'Product Images',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'image',
        4 => 'product',
        5 => 'gallery',
        6 => 'lightbox',
      ),
      'control_count' => 9,
    ),
    'woocommerce-product-meta' =>
    array (
      'shard' => 'shards/woocommerce-product-meta.php',
      'hash' => 'e610e085fc20189e5df1fe543143b56ed5eae25c3efdf03ac8cc65f7cbf7f609',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-meta',
      'title' => 'Product Meta',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'meta',
        4 => 'data',
        5 => 'product',
      ),
      'control_count' => 24,
    ),
    'woocommerce-product-price' =>
    array (
      'shard' => 'shards/woocommerce-product-price.php',
      'hash' => '9dca54423c0010b472ba15550e47fa55dc4f9252bf90b533daef1492fd563077',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-price',
      'title' => 'Product Price',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'price',
        4 => 'product',
        5 => 'sale',
      ),
      'control_count' => 9,
    ),
    'woocommerce-product-rating' =>
    array (
      'shard' => 'shards/woocommerce-product-rating.php',
      'hash' => '2ddcbb613a4e33d2a2e226c442cc8523005068eba618f5a26c825d7fad65f5b9',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-rating',
      'title' => 'Product Rating',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'rating',
        4 => 'review',
        5 => 'comments',
        6 => 'stars',
        7 => 'product',
      ),
      'control_count' => 8,
    ),
    'woocommerce-product-related' =>
    array (
      'shard' => 'shards/woocommerce-product-related.php',
      'hash' => 'd84e68e716d94a48bae9297d52217e847313f7a59e07b4314cddb2d0052e12ce',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-related',
      'title' => 'Product Related',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'related',
        4 => 'similar',
        5 => 'product',
      ),
      'control_count' => 8,
    ),
    'woocommerce-product-short-description' =>
    array (
      'shard' => 'shards/woocommerce-product-short-description.php',
      'hash' => 'c7d197dda5dd66f90960c1765c08dcd4be29b2c851805b3557eb67ad9c17c70a',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-short-description',
      'title' => 'Short Description',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'text',
        4 => 'description',
        5 => 'product',
      ),
      'control_count' => 4,
    ),
    'woocommerce-product-stock' =>
    array (
      'shard' => 'shards/woocommerce-product-stock.php',
      'hash' => '8e6b0cc444574712a916369a7566eba41505ef7599910703f7245190482fa2a5',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-stock',
      'title' => 'Product Stock',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'stock',
        4 => 'quantity',
        5 => 'product',
      ),
      'control_count' => 3,
    ),
    'woocommerce-product-title' =>
    array (
      'shard' => 'shards/woocommerce-product-title.php',
      'hash' => '8044d3ae99b8933000208a2eae337d87660d72764c33020db32267fa4688785a',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-title',
      'title' => 'Product Title',
      'categories' =>
      array (
        0 => 'woocommerce-elements-single',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'title',
        4 => 'heading',
        5 => 'product',
      ),
      'control_count' => 0,
    ),
    'woocommerce-product-upsell' =>
    array (
      'shard' => 'shards/woocommerce-product-upsell.php',
      'hash' => '27ebeea87a695c437f59848a31a0f0520239491af6108035879c4f0e668626b3',
      'source' => 'wc',
      'widget_type' => 'woocommerce-product-upsell',
      'title' => 'Upsells',
      'categories' =>
      array (
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'upsell',
        4 => 'product',
      ),
      'control_count' => 7,
    ),
    'woocommerce-products' =>
    array (
      'shard' => 'shards/woocommerce-products.php',
      'hash' => '3bc0409998666587712f053a6c38b85c6eda195c7d42f46b8a55143bf0c6b273',
      'source' => 'wc',
      'widget_type' => 'woocommerce-products',
      'title' => 'Products',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'shop',
        2 => 'store',
        3 => 'product',
        4 => 'archive',
        5 => 'upsells',
        6 => 'cross-sells',
        7 => 'cross sells',
        8 => 'related',
      ),
      'control_count' => 13,
    ),
    'woocommerce-purchase-summary' =>
    array (
      'shard' => 'shards/woocommerce-purchase-summary.php',
      'hash' => '4f0c60a88eec396c675901ef2065191bfd7b4170f7ac2e8467be5d5ee33aa6d4',
      'source' => 'wc',
      'widget_type' => 'woocommerce-purchase-summary',
      'title' => 'Purchase Summary',
      'categories' =>
      array (
        0 => 'woocommerce-elements',
      ),
      'keywords' =>
      array (
        0 => 'woocommerce',
        1 => 'summary',
        2 => 'thank you',
        3 => 'confirmation',
        4 => 'purchase',
      ),
      'control_count' => 106,
    ),
    'wp-widget-' =>
    array (
      'shard' => 'shards/wp-widget-.php',
      'hash' => '7405855c8deab91a32dba4cbd10e3e4e90ed8b709e7920d11baae2fd545d00ff',
      'source' => 'free',
      'widget_type' => 'wp-widget-',
      'title' => NULL,
      'categories' =>
      array (
        0 => 'wordpress',
      ),
      'keywords' =>
      array (
        0 => 'wordpress',
        1 => 'widget',
      ),
      'control_count' => 0,
    ),
  ),
);
