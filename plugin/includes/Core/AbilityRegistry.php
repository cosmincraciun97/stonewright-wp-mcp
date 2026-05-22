<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\Content\BulkCreate;
use Stonewright\WpMcp\Abilities\Content\CreatePage;
use Stonewright\WpMcp\Abilities\Content\CreatePost;
use Stonewright\WpMcp\Abilities\Content\DuplicatePage;
use Stonewright\WpMcp\Abilities\Content\GetPage;
use Stonewright\WpMcp\Abilities\Content\UpdatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePost;
use Stonewright\WpMcp\Abilities\Design\ApplyToPost;
use Stonewright\WpMcp\Abilities\Design\BuildSpec;
use Stonewright\WpMcp\Abilities\Design\ChooseRenderer;
use Stonewright\WpMcp\Abilities\Design\ExtractTokens;
use Stonewright\WpMcp\Abilities\Design\FigmaToSpec;
use Stonewright\WpMcp\Abilities\Design\ImportFigmaNode;
use Stonewright\WpMcp\Abilities\Design\ImportImage;
use Stonewright\WpMcp\Abilities\Design\IngestFigma;
use Stonewright\WpMcp\Abilities\Design\NormalizeAssets;
use Stonewright\WpMcp\Abilities\Design\PreviewRender;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV3;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV4;
use Stonewright\WpMcp\Abilities\Design\SpecToGutenberg;
use Stonewright\WpMcp\Abilities\Design\ValidateSpec;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BackupPage;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Abilities\ElementorV3\GetElement;
use Stonewright\WpMcp\Abilities\ElementorV3\GetPageStructure;
use Stonewright\WpMcp\Abilities\ElementorV3\GetWidgetSchema;
use Stonewright\WpMcp\Abilities\ElementorV3\ListWidgets;
use Stonewright\WpMcp\Abilities\ElementorV3\MoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\RemoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\SaveTemplate;
use Stonewright\WpMcp\Abilities\ElementorV3\Status as ElementorStatus;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;
use Stonewright\WpMcp\Abilities\ElementorV4\AtomicWidgetDefine;
use Stonewright\WpMcp\Abilities\ElementorV4\CreateClass;
use Stonewright\WpMcp\Abilities\ElementorV4\CreateVariable;
use Stonewright\WpMcp\Abilities\ElementorV4\DescribeAtomicWidget;
use Stonewright\WpMcp\Abilities\ElementorV4\ListAtomicNodeTypes;
use Stonewright\WpMcp\Abilities\ElementorV4\ListClasses;
use Stonewright\WpMcp\Abilities\ElementorV4\ListVariables;
use Stonewright\WpMcp\Abilities\ElementorV4\ReadAtomicTree;
use Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec as RenderV4FromSpec;
use Stonewright\WpMcp\Abilities\ElementorV4\Status as ElementorV4Status;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateClass;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateVariable;
use Stonewright\WpMcp\Abilities\FSE\CreateTemplatePart;
use Stonewright\WpMcp\Abilities\FSE\GetThemeJson;
use Stonewright\WpMcp\Abilities\FSE\ListTemplates;
use Stonewright\WpMcp\Abilities\FSE\ReadGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\ReadTemplate;
use Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\UpdateTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplatePart;
use Stonewright\WpMcp\Abilities\Gutenberg\ApplyToPost as GutenbergApplyToPost;
use Stonewright\WpMcp\Abilities\Gutenberg\GetBlockSchema;
use Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\RenderBlocks;
use Stonewright\WpMcp\Abilities\Gutenberg\ListRegisteredBlocks;
use Stonewright\WpMcp\Abilities\Gutenberg\ParseBlocks;
use Stonewright\WpMcp\Abilities\Gutenberg\RemoveBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\SerializeBlocks;
use Stonewright\WpMcp\Abilities\Gutenberg\TransformHtml;
use Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock;
use Stonewright\WpMcp\Abilities\Memory\MemoryDelete;
use Stonewright\WpMcp\Abilities\Memory\MemoryGet;
use Stonewright\WpMcp\Abilities\Memory\MemoryList;
use Stonewright\WpMcp\Abilities\Memory\MemorySave;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetList as ElementorWidgetList;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxDeactivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxEdit;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxList as SandboxListAbility;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxRead;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxToggle;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxWrite;
use Stonewright\WpMcp\Abilities\System\AbilitiesList;
use Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate as ThemeBuilderCreateTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate as ThemeBuilderDeleteTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate as ThemeBuilderGetTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates as ThemeBuilderListTemplates;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions as ThemeBuilderSetConditions;
use Stonewright\WpMcp\Abilities\System\InstructionsGet;
use Stonewright\WpMcp\Abilities\System\InstructionsSet;
use Stonewright\WpMcp\Abilities\Media\GetMedia;
use Stonewright\WpMcp\Abilities\Media\OptimizeMedia;
use Stonewright\WpMcp\Abilities\Media\SetAlt;
use Stonewright\WpMcp\Abilities\Media\UploadMedia;
use Stonewright\WpMcp\Abilities\Patterns\CreatePattern;
use Stonewright\WpMcp\Abilities\Patterns\ListPatterns;
use Stonewright\WpMcp\Abilities\QA\AccessibilityCheck;
use Stonewright\WpMcp\Abilities\QA\ApplyFixPlan;
use Stonewright\WpMcp\Abilities\QA\DiffLayout;
use Stonewright\WpMcp\Abilities\QA\DiffScreenshot;
use Stonewright\WpMcp\Abilities\QA\Lighthouse;
use Stonewright\WpMcp\Abilities\QA\Report as QaReport;
use Stonewright\WpMcp\Abilities\QA\ResponsiveCheck;
use Stonewright\WpMcp\Abilities\QA\ScreenshotPage;
use Stonewright\WpMcp\Abilities\QA\SuggestFixes;
use Stonewright\WpMcp\Abilities\QA\VerifyAgainstReference;
use Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken;
use Stonewright\WpMcp\Abilities\Site\BackupPage as SiteBackupPage;
use Stonewright\WpMcp\Abilities\Site\Capabilities;
use Stonewright\WpMcp\Abilities\Site\CreateRevision;
use Stonewright\WpMcp\Abilities\Site\Environment;
use Stonewright\WpMcp\Abilities\Site\Health;
use Stonewright\WpMcp\Abilities\Site\Info;
use Stonewright\WpMcp\Abilities\Site\ListPlugins;
use Stonewright\WpMcp\Abilities\Site\Ping;
use Stonewright\WpMcp\Abilities\Site\Theme as SiteTheme;

/**
 * Lists every Stonewright ability and registers it with the Abilities API.
 */
final class AbilityRegistry {

	/**
	 * @return array<class-string<Ability>>
	 */
	public static function list(): array {
		return [
			// Security.
			IssueConfirmationToken::class,

			// Site.
			Ping::class,
			Info::class,
			Capabilities::class,
			Environment::class,
			Health::class,
			ListPlugins::class,
			SiteTheme::class,
			SiteBackupPage::class,
			CreateRevision::class,

			// Content.
			CreatePage::class,
			UpdatePage::class,
			GetPage::class,
			DuplicatePage::class,
			CreatePost::class,
			UpdatePost::class,
			BulkCreate::class,

			// Media.
			UploadMedia::class,
			GetMedia::class,
			SetAlt::class,
			OptimizeMedia::class,

			// Gutenberg.
			ListRegisteredBlocks::class,
			GetBlockSchema::class,
			ParseBlocks::class,
			SerializeBlocks::class,
			TransformHtml::class,
			InsertBlock::class,
			UpdateBlock::class,
			RemoveBlock::class,
			RenderBlocks::class,
			GutenbergApplyToPost::class,

			// Patterns.
			ListPatterns::class,
			CreatePattern::class,

			// FSE.
			GetThemeJson::class,
			UpdateGlobalStyles::class,
			ListTemplates::class,
			UpdateTemplate::class,
			CreateTemplatePart::class,
			ReadTemplate::class,
			ReadGlobalStyles::class,
			WriteTemplate::class,
			WriteTemplatePart::class,
			WriteGlobalStyles::class,

			// Elementor V3.
			ElementorStatus::class,
			ListWidgets::class,
			GetWidgetSchema::class,
			GetPageStructure::class,
			GetElement::class,
			AddContainer::class,
			AddWidget::class,
			UpdateElement::class,
			MoveElement::class,
			RemoveElement::class,
			BuildPageFromSpec::class,
			UpdatePageSettings::class,
			UpdateKitColors::class,
			UpdateKitTypography::class,
			SaveTemplate::class,
			BackupPage::class,

			// Elementor V4 experimental.
			ElementorV4Status::class,
			ReadAtomicTree::class,
			ListVariables::class,
			CreateVariable::class,
			UpdateVariable::class,
			ListClasses::class,
			CreateClass::class,
			UpdateClass::class,
			RenderV4FromSpec::class,

			// Elementor V4 — atomic widget definer (Phase 2.3).
			AtomicWidgetDefine::class,

			// Elementor V4 — atomic introspection (Phase 2.4).
			ListAtomicNodeTypes::class,
			DescribeAtomicWidget::class,

			// Design.
			ValidateSpec::class,
			ExtractTokens::class,
			BuildSpec::class,
			NormalizeAssets::class,
			ImportFigmaNode::class,
			ImportImage::class,
			ChooseRenderer::class,
			SpecToGutenberg::class,
			SpecToElementorV3::class,
			SpecToElementorV4::class,
			IngestFigma::class,
			PreviewRender::class,
			ApplyToPost::class,

			// Design (Phase 2.5).
			FigmaToSpec::class,

			// QA.
			ScreenshotPage::class,
			DiffScreenshot::class,
			DiffLayout::class,
			ResponsiveCheck::class,
			Lighthouse::class,
			AccessibilityCheck::class,
			SuggestFixes::class,
			ApplyFixPlan::class,
			QaReport::class,
			VerifyAgainstReference::class,

			// Memory (Wave 3a).
			MemoryList::class,
			MemoryGet::class,
			MemorySave::class,
			MemoryDelete::class,

			// System (Wave 3b).
			InstructionsGet::class,
			InstructionsSet::class,
			AbilitiesList::class,

			// Elementor Widget Builder (Phase 5).
			WidgetDefine::class,
			WidgetRegister::class,
			ElementorWidgetList::class,

			// Sandbox (Wave 3c).
			SandboxListAbility::class,
			SandboxRead::class,
			SandboxWrite::class,
			SandboxEdit::class,
			SandboxDelete::class,
			SandboxActivate::class,
			SandboxDeactivate::class,
			SandboxToggle::class,

			// Theme Builder (Phase 1.6+).
			ThemeBuilderCreateTemplate::class,
			ThemeBuilderSetConditions::class,
			ThemeBuilderListTemplates::class,
			ThemeBuilderGetTemplate::class,
			ThemeBuilderDeleteTemplate::class,
		];
	}

	/**
	 * Tracks whether register_all() has already run in this request, so that
	 * listening on multiple Abilities API init actions (the wp_-prefixed core
	 * pair and the un-prefixed vendor fallback) cannot re-register and trigger
	 * `_doing_it_wrong( 'Ability already registered' )` notices.
	 */
	private static bool $registered_once = false;

	public static function register_all(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( self::$registered_once ) {
			return;
		}
		self::$registered_once = true;

		$master_enabled     = (bool) get_option( 'stonewright_enabled', false );
		$disabled_abilities = (array) get_option( 'stonewright_disabled_abilities', [] );

		foreach ( self::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability = new $class();
			$name    = $ability->name();

			// Master toggle: skip all abilities except ping when disabled.
			if ( ! $master_enabled && 'stonewright/ping' !== $name ) {
				continue;
			}

			// Per-ability disable list.
			if ( in_array( $name, $disabled_abilities, true ) ) {
				continue;
			}

			$args = [
				'label'               => $ability->label(),
				'description'         => $ability->description(),
				'category'            => $ability->category(),
				'input_schema'        => $ability->input_schema(),
				'output_schema'       => $ability->output_schema(),
				'permission_callback' => [ $ability, 'permission_callback' ],
				'execute_callback'    => [ $ability, 'execute' ],
				'meta'                => array_merge(
					[
						'mcp'          => [ 'public' => true ],
						// WordPress core's `/wp-json/wp-abilities/v1/abilities`
						// list endpoint filters by `meta.show_in_rest === true`
						// (see WP_REST_Abilities_V1_List_Controller::get_items).
						// Without this, every Stonewright ability is invisible
						// to standard MCP/REST clients even though they are
						// registered. Per-ability `meta()` overrides can opt out.
						'show_in_rest' => true,
					],
					$ability->meta()
				),
			];

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Returns metadata for ALL abilities regardless of enabled/disabled state.
	 * Used by the admin Abilities page.
	 *
	 * @return array<int, array{name: string, label: string, description: string, category: string, enabled: bool}>
	 */
	public static function enabled_abilities(): array {
		$disabled_abilities = (array) get_option( 'stonewright_disabled_abilities', [] );
		$result             = [];

		foreach ( self::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability  = new $class();
			$name     = $ability->name();
			$result[] = [
				'name'        => $name,
				'label'       => $ability->label(),
				'description' => $ability->description(),
				'category'    => $ability->category(),
				'enabled'     => ! in_array( $name, $disabled_abilities, true ),
			];
		}

		return $result;
	}

	/**
	 * @return array<string,string>
	 */
	private static function categories(): array {
		return [
			'security'  => __( 'Security', 'stonewright' ),
			'site'      => __( 'Site', 'stonewright' ),
			'content'   => __( 'Content', 'stonewright' ),
			'media'     => __( 'Media', 'stonewright' ),
			'gutenberg' => __( 'Gutenberg', 'stonewright' ),
			'patterns'  => __( 'Patterns', 'stonewright' ),
			'fse'       => __( 'Full Site Editing', 'stonewright' ),
			'elementor' => __( 'Elementor', 'stonewright' ),
			'design'    => __( 'Design', 'stonewright' ),
			'qa'        => __( 'Quality Assurance', 'stonewright' ),
			'memory'    => __( 'Memory', 'stonewright' ),
			'system'    => __( 'System', 'stonewright' ),
			'sandbox'           => __( 'Sandbox', 'stonewright' ),
			'elementor-widget'  => __( 'Elementor Widget Builder', 'stonewright' ),
			'theme-builder'     => __( 'Theme Builder', 'stonewright' ),
		];
	}

	public static function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		foreach ( self::categories() as $slug => $label ) {
			wp_register_ability_category(
				$slug,
				[
					'label'       => $label,
					'description' => $label,
				]
			);
		}
	}

	/**
	 * Reset the in-process registration guard. For tests only.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered_once = false;
	}
}
