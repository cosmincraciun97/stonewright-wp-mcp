<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\Content\BulkCreate;
use Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts;
use Stonewright\WpMcp\Abilities\System\ContextBootstrap;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Support\Utf8;
use Stonewright\WpMcp\Abilities\Content\CreatePage;
use Stonewright\WpMcp\Abilities\Content\CreatePost;
use Stonewright\WpMcp\Abilities\Content\DuplicatePage;
use Stonewright\WpMcp\Abilities\Content\GetPage;
use Stonewright\WpMcp\Abilities\Content\UpdatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePost;
use Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow;
use Stonewright\WpMcp\Abilities\Design\ApplyToPost;
use Stonewright\WpMcp\Abilities\Design\BuildSpec;
use Stonewright\WpMcp\Abilities\Design\ChooseRenderer;
use Stonewright\WpMcp\Abilities\Design\ExtractTokens;
use Stonewright\WpMcp\Abilities\Design\ImplementationContract;
use Stonewright\WpMcp\Abilities\Design\ImportImage;
use Stonewright\WpMcp\Abilities\Design\NormalizeAssets;
use Stonewright\WpMcp\Abilities\Design\PreviewRender;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV3;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV4;
use Stonewright\WpMcp\Abilities\Design\SpecToGutenberg;
use Stonewright\WpMcp\Abilities\Design\ValidateSpec;
use Stonewright\WpMcp\Abilities\Design\WidgetIntentResolve;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\ApplyBundle as ElementorV3ApplyBundle;
use Stonewright\WpMcp\Abilities\ElementorV3\BackupPage;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary as ElementorV3CapabilitiesSummary;
use Stonewright\WpMcp\Abilities\ElementorV3\ContainerSchema;
use Stonewright\WpMcp\Abilities\ElementorV3\GetElement;
use Stonewright\WpMcp\Abilities\ElementorV3\GetKitGlobals;
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
use Stonewright\WpMcp\Abilities\Memory\LearningRecord;
use Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine;
use Stonewright\WpMcp\Abilities\Knowledge\DescribeWidget;
use Stonewright\WpMcp\Abilities\Knowledge\ExplainEditor;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeRefresh;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeSearch;
use Stonewright\WpMcp\Abilities\Knowledge\WidgetImplementationGuide;
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
use Stonewright\WpMcp\Abilities\System\KnowledgeExport;
use Stonewright\WpMcp\Abilities\System\KnowledgeImport;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Abilities\System\WorkflowPreflight;
use Stonewright\WpMcp\Abilities\Skills\SkillsList;
use Stonewright\WpMcp\Abilities\Skills\SkillsGet;
use Stonewright\WpMcp\Abilities\Skills\SkillsSave;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate as ThemeBuilderApplyTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate as ThemeBuilderCreateTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate as ThemeBuilderDeleteTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate as ThemeBuilderGetTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates as ThemeBuilderListTemplates;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions as ThemeBuilderSetConditions;
use Stonewright\WpMcp\Abilities\WpCli\BatchRun as WpCliBatchRun;
use Stonewright\WpMcp\Abilities\WpCli\Discover as WpCliDiscover;
use Stonewright\WpMcp\Abilities\WpCli\JobStart as WpCliJobStart;
use Stonewright\WpMcp\Abilities\WpCli\JobStatus as WpCliJobStatus;
use Stonewright\WpMcp\Abilities\WpCli\Run as WpCliRun;
use Stonewright\WpMcp\Abilities\WpCli\Status as WpCliStatus;
use Stonewright\WpMcp\Abilities\System\InstructionsGet;
use Stonewright\WpMcp\Abilities\System\InstructionsSet;
use Stonewright\WpMcp\Abilities\Media\GetMedia;
use Stonewright\WpMcp\Abilities\Media\ListMedia;
use Stonewright\WpMcp\Abilities\Media\OptimizeMedia;
use Stonewright\WpMcp\Abilities\Media\SetAlt;
use Stonewright\WpMcp\Abilities\Media\UploadMedia;
use Stonewright\WpMcp\Abilities\Media\UploadMediaBatch;
use Stonewright\WpMcp\Abilities\Menu\MenuAddItem;
use Stonewright\WpMcp\Abilities\Menu\MenuAssignLocation;
use Stonewright\WpMcp\Abilities\Menu\MenuCreate;
use Stonewright\WpMcp\Abilities\Menu\MenuDelete;
use Stonewright\WpMcp\Abilities\Menu\MenuList;
use Stonewright\WpMcp\Abilities\Patterns\CreatePattern;
use Stonewright\WpMcp\Abilities\Patterns\ListPatterns;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Abilities\Security\CreateOneTimeLink;
use Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken;
use Stonewright\WpMcp\Abilities\Site\BackupPage as SiteBackupPage;
use Stonewright\WpMcp\Abilities\Site\Capabilities;
use Stonewright\WpMcp\Abilities\Site\CreateRevision;
use Stonewright\WpMcp\Abilities\Site\DiscoverShortcodes;
use Stonewright\WpMcp\Abilities\Site\Environment;
use Stonewright\WpMcp\Abilities\Site\Health;
use Stonewright\WpMcp\Abilities\Site\Info;
use Stonewright\WpMcp\Abilities\Site\ListPlugins;
use Stonewright\WpMcp\Abilities\Site\Ping;
use Stonewright\WpMcp\Abilities\Site\SetFrontPage;
use Stonewright\WpMcp\Abilities\Site\Theme as SiteTheme;

/**
 * Lists every Stonewright ability and registers it with the Abilities API.
 */
final class AbilityRegistry {

	/**
	 * @return array<class-string<Ability>>
	 */
	public static function list(): array {
		$base = [
			// Security.
			ContextBootstrap::class,
			IssueConfirmationToken::class,
			CreateOneTimeLink::class,

			// Runtime.
			PhpExecute::class,

			// Site.
			Ping::class,
			Info::class,
			Capabilities::class,
			Environment::class,
			Health::class,
			ListPlugins::class,
			SiteTheme::class,
			SetFrontPage::class,
			SiteBackupPage::class,
			CreateRevision::class,
			DiscoverShortcodes::class,

			// Content.
			CreatePage::class,
			UpdatePage::class,
			GetPage::class,
			DuplicatePage::class,
			CreatePost::class,
			UpdatePost::class,
			BulkCreate::class,
			BulkUpsertPosts::class,

			// Content model.
			CptAcfLoopGridFlow::class,

			// Media.
			ListMedia::class,
			UploadMedia::class,
			UploadMediaBatch::class,
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
			ElementorV3CapabilitiesSummary::class,
			GetKitGlobals::class,
			ContainerSchema::class,
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
			BatchMutate::class,
			ElementorV3ApplyBundle::class,
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

			// Elementor V4 — atomic widget definer.
			AtomicWidgetDefine::class,

			// Elementor V4 — atomic introspection.
			ListAtomicNodeTypes::class,
			DescribeAtomicWidget::class,

			// Design.
			ValidateSpec::class,
			ImplementationContract::class,
			ExtractTokens::class,
			BuildSpec::class,
			NormalizeAssets::class,
			ImportImage::class,
			ChooseRenderer::class,
			SpecToGutenberg::class,
			SpecToElementorV3::class,
			SpecToElementorV4::class,
			PreviewRender::class,
			ApplyToPost::class,

			// Design — smart-detection intent resolver.
			WidgetIntentResolve::class,

			// Memory (Wave 3a).
			MemoryList::class,
			MemoryGet::class,
			MemorySave::class,
			LearningRecord::class,
			MemoryDelete::class,

			// System (Wave 3b).
			InstructionsGet::class,
			InstructionsSet::class,
			KnowledgeExport::class,
			KnowledgeImport::class,
			AbilitiesList::class,
			ToolProfile::class,
			WorkflowPreflight::class,

			// WP-CLI companion bridge.
			WpCliStatus::class,
			WpCliDiscover::class,
			WpCliRun::class,
			WpCliBatchRun::class,
			WpCliJobStart::class,
			WpCliJobStatus::class,

			// Skills.
			SkillsList::class,
			SkillsGet::class,
			SkillsSave::class,

			// Elementor Widget Builder.
			WidgetDefine::class,
			WidgetRegister::class,
			ElementorWidgetList::class,

			// Custom-widget high-level pipeline.
			CreateCustomWidget::class,

			// Elementor knowledge base query + self-update.
			KnowledgeSearch::class,
			DescribeWidget::class,
			ExplainEditor::class,
			WidgetImplementationGuide::class,
			KnowledgeRefresh::class,

			// Sandbox.
			SandboxListAbility::class,
			SandboxRead::class,
			SandboxWrite::class,
			SandboxEdit::class,
			SandboxDelete::class,
			SandboxActivate::class,
			SandboxDeactivate::class,
			SandboxToggle::class,

			// Theme Builder.
			ThemeBuilderApplyTemplate::class,
			ThemeBuilderCreateTemplate::class,
			ThemeBuilderSetConditions::class,
			ThemeBuilderListTemplates::class,
			ThemeBuilderGetTemplate::class,
			ThemeBuilderDeleteTemplate::class,

			// Menu.
			MenuCreate::class,
			MenuAddItem::class,
			MenuList::class,
			MenuDelete::class,
			MenuAssignLocation::class,
		];

		// Auto-generated per-widget Elementor V3 abilities (one per
		// slug in plugin/includes/Elementor/WidgetRegistry/manifest.json).
		// Re-run plugin/bin/generate-widget-abilities.php after manifest changes.
		return array_merge( $base, self::widget_ability_classes() );
	}

	/**
	 * Returns the auto-generated per-widget ability class list emitted by
	 * `plugin/bin/generate-widget-abilities.php`. Returns an empty array
	 * if the file is missing (e.g. generator hasn't run yet).
	 *
	 * @return array<int, class-string<Ability>>
	 */
	private static function widget_ability_classes(): array {
		$path = __DIR__ . '/../Abilities/ElementorWidgets/_class_list.php';
		if ( ! is_file( $path ) ) {
			return [];
		}
		$loaded = include $path;
		return is_array( $loaded ) ? $loaded : [];
	}

	/**
	 * Tracks whether register_all() has already run in this request, so that
	 * listening on multiple Abilities API init actions (the wp_-prefixed core
	 * pair and the un-prefixed vendor fallback) cannot re-register and trigger
	 * `_doing_it_wrong( 'Ability already registered' )` notices.
	 *
	 * @var bool
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

		foreach ( self::public_classes() as $class ) {
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

			$input_schema = self::input_schema_for_ability( $ability );
			$args         = [
				'label'               => $ability->label(),
				'description'         => $ability->description(),
				'ability_class'       => RegisteredAbility::class,
				'category'            => $ability->category(),
				'input_schema'        => $input_schema,
				'output_schema'       => self::output_schema_for_ability( $ability ),
				'permission_callback' => [ $ability, 'permission_callback' ],
				// Wrap execute with UTF-8 deep_sanitize so all ability inputs
				// are guaranteed valid UTF-8 regardless of client encoding.
				// This transparently handles Windows PowerShell \uXXXX escapes.
				'execute_callback'    => static function ( array $input ) use ( $ability ): mixed {
					return self::execute_with_context_guard( $ability, Utf8::deep_sanitize( $input ) );
				},
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

	public static function ability_by_name( string $name ): ?Ability {
		foreach ( self::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability = new $class();
			if ( $ability->name() === $name ) {
				return $ability;
			}
		}

		return null;
	}

	/**
	 * Returns the ability names that should be handed to the MCP server for
	 * this request. Mirrors register_all() so server startup does not probe
	 * disabled or non-essential abilities when compact mode is active.
	 *
	 * @return list<string>
	 */
	public static function mcp_server_ability_names(): array {
		$master_enabled     = (bool) get_option( 'stonewright_enabled', false );
		$disabled_abilities = array_fill_keys(
			array_map( 'strval', (array) get_option( 'stonewright_disabled_abilities', [] ) ),
			true
		);
		$names              = [];

		foreach ( self::public_classes() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability = new $class();
			$name    = $ability->name();

			if ( ! $master_enabled && 'stonewright/ping' !== $name ) {
				continue;
			}

			if ( isset( $disabled_abilities[ $name ] ) ) {
				continue;
			}

			$names[] = $name;
		}

		return $names;
	}

	/**
	 * Execute an ability after enforcing Stonewright's task context bootstrap.
	 *
	 * Read-only discovery abilities remain callable without a token so an agent
	 * can fetch context, skills, memory, and knowledge. Mutating or destructive
	 * abilities require the short-lived token from stonewright/context-bootstrap.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function execute_with_context_guard( Ability $ability, array $input ): mixed {
		$name = $ability->name();
		if ( ! self::requires_context_token( $ability ) ) {
			unset( $input['stonewright_context_token'] );
			return $ability->execute( $input );
		}

		$token = isset( $input['stonewright_context_token'] ) && is_string( $input['stonewright_context_token'] )
			? $input['stonewright_context_token']
			: '';

		$verified = ContextToken::verify( $token, $name );
		if ( $verified instanceof \WP_Error ) {
			return $verified;
		}

		unset( $input['stonewright_context_token'] );
		return $ability->execute( $input );
	}

	private static function requires_context_token( Ability $ability ): bool {
		$name = $ability->name();
		if ( in_array( $name, self::context_exempt_abilities(), true ) ) {
			return false;
		}

		foreach ( self::read_only_name_markers() as $marker ) {
			if ( str_contains( $name, $marker ) ) {
				return false;
			}
		}

		return ! in_array( $ability->category(), [ 'site', 'system', 'knowledge', 'skills', 'memory' ], true )
			|| preg_match( '/(create|update|write|delete|remove|apply|insert|save|set|move|upload|optimize|activate|deactivate|toggle|register|define|bulk|record)/', $name ) === 1;
	}

	/**
	 * Adds the mandatory Stonewright task context token to public schemas for
	 * abilities that the execution gate will reject without it.
	 *
	 * @return array<string, mixed>
	 */
	private static function input_schema_for_ability( Ability $ability ): array {
		$schema = $ability->input_schema();
		if ( self::requires_context_token( $ability ) ) {
			if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
				$schema['properties'] = [];
			}

			/** @var array<string, mixed> $properties */
			$properties                              = $schema['properties'];
			$properties['stonewright_context_token'] = [
				'type'        => 'string',
				'description' => 'Required. Call MCP tool stonewright-context-bootstrap (WordPress ability stonewright/context-bootstrap) at the start of the task and pass the returned context token.',
			];
			$schema['properties']                    = $properties;

			$required = isset( $schema['required'] ) && is_array( $schema['required'] )
				? array_values( array_map( 'strval', $schema['required'] ) )
				: [];
			if ( ! in_array( 'stonewright_context_token', $required, true ) ) {
				$required[] = 'stonewright_context_token';
			}
			$schema['required'] = $required;
		}

		/** @var array<string, mixed> $schema */
		$schema = self::normalise_schema_object_maps( $schema );
		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function output_schema_for_ability( Ability $ability ): array {
		/** @var array<string, mixed> $schema */
		$schema = self::normalise_schema_object_maps( $ability->output_schema() );
		return $schema;
	}

	/**
	 * Keeps MCP JSON Schema shapes accepted by stricter clients.
	 *
	 * Empty object maps encode as `{}` instead of `[]`, and permissive arrays
	 * still declare `items: {}` so clients that require an items schema do not
	 * hide the tool during discovery.
	 *
	 * @param array<int|string, mixed> $schema JSON Schema fragment.
	 * @return array<int|string, mixed>
	 */
	private static function normalise_schema_object_maps( array $schema ): array {
		if ( 'array' === ( $schema['type'] ?? null ) && ! array_key_exists( 'items', $schema ) ) {
			$schema['items'] = new \stdClass();
		}

		foreach ( $schema as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( [] === $value && self::is_schema_object_map_key( (string) $key ) ) {
				$schema[ $key ] = new \stdClass();
				continue;
			}

			$schema[ $key ] = self::normalise_schema_object_maps( $value );
		}

		return $schema;
	}

	private static function is_schema_object_map_key( string $key ): bool {
		return in_array( $key, [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ], true );
	}

	/**
	 * @return array<int, string>
	 */
	private static function context_exempt_abilities(): array {
		return [
			'stonewright/context-bootstrap',
			'stonewright/workflow-preflight',
			'stonewright/ping',
			'stonewright/site-info',
			'stonewright/site-capabilities',
			'stonewright/site-environment',
			'stonewright/site-health',
			'stonewright/site-plugins-list',
			'stonewright/site-theme',
			'stonewright/system-abilities-list',
			'stonewright/tool-profile',
			'stonewright/system-instructions-get',
			'stonewright/knowledge-export',
			'stonewright/skills-list',
			'stonewright/skills-get',
			'stonewright/memory-list',
			'stonewright/memory-get',
			'stonewright/elementor-knowledge-search',
			'stonewright/elementor-describe-widget',
			'stonewright/elementor-explain-editor',
			'stonewright/widget-intent-resolve',
			'stonewright/elementor-v3-capabilities-summary',
			'stonewright/elementor-v3-container-schema',
			'stonewright/elementor-widget-implementation-guide',
		];
	}

	/**
	 * @return array<int, string>
	 */
	private static function read_only_name_markers(): array {
		return [
			'-get',
			'-list',
			'-read',
			'-describe',
			'-discover',
			'-explain',
			'-search',
			'-validate',
			'-status',
			'-preview',
			'-parse',
			'-serialize',
		];
	}

	/**
	 * Returns metadata for ALL abilities regardless of enabled/disabled state.
	 * Used by the admin Abilities page.
	 *
	 * @return array<int, array{name: string, mcp_tool_name: string, label: string, description: string, category: string, enabled: bool, input_schema: array<string, mixed>}>
	 */
	public static function enabled_abilities(): array {
		$disabled_abilities = (array) get_option( 'stonewright_disabled_abilities', [] );
		$result             = [];

		foreach ( self::public_classes() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability  = new $class();
			$name     = $ability->name();
			$result[] = [
				'name'          => $name,
				'mcp_tool_name' => self::mcp_tool_name( $name ),
				'label'         => $ability->label(),
				'description'   => $ability->description(),
				'category'      => $ability->category(),
				'enabled'       => ! in_array( $name, $disabled_abilities, true ),
				'input_schema'  => self::input_schema_for_ability( $ability ),
			];
		}

		return $result;
	}

	/**
	 * @return array<int, class-string<Ability>>
	 */
	private static function public_classes(): array {
		$classes = self::list();
		if ( ! (bool) get_option( 'stonewright_essential_tools_mode', true ) ) {
			return $classes;
		}

		$allowed = array_fill_keys(
			array_merge(
				self::essential_ability_names(),
				self::essential_extra_ability_names()
			),
			true
		);

		return array_values(
			array_filter(
				$classes,
				static function ( string $class ) use ( $allowed ): bool {
					if ( ! class_exists( $class ) ) {
						return false;
					}

					/** @var Ability $ability */
					$ability = new $class();
					return isset( $allowed[ $ability->name() ] );
				}
			)
		);
	}

	/**
	 * Compact public MCP surface for fast startup and low-token discovery.
	 *
	 * @return list<string>
	 */
	private static function essential_ability_names(): array {
		return [
			// Bootstrap, runtime, and site context.
			'stonewright/context-bootstrap',
			'stonewright/security-issue-confirmation-token',
			'stonewright/security-create-one-time-link',
			'stonewright/php-execute',
			'stonewright/ping',
			'stonewright/site-info',
			'stonewright/site-environment',
			'stonewright/site-health',
			'stonewright/site-plugins-list',
			'stonewright/site-theme',
			'stonewright/sandbox-write',
			'stonewright/sandbox-activate',

			// Fast workflow and compact instructions.
			'stonewright/workflow-preflight',
			'stonewright/tool-profile',
			'stonewright/skills-get',
			'stonewright/system-abilities-list',
			'stonewright/system-instructions-get',

			// Content and media.
			'stonewright/content-create-page',
			'stonewright/content-get-page',
			'stonewright/content-update-page',
			'stonewright/content-bulk-upsert-posts',
			'stonewright/content-model-loop-grid-flow',
			'stonewright/media-list',
			'stonewright/media-upload-batch',

			// Elementor V3 fast paths and discovery.
			'stonewright/elementor-v3-status',
			'stonewright/elementor-v3-capabilities-summary',
			'stonewright/elementor-v3-get-kit-globals',
			'stonewright/elementor-v3-container-schema',
			'stonewright/elementor-v3-list-widgets',
			'stonewright/elementor-v3-get-widget-schema',
			'stonewright/elementor-v3-get-page-structure',
			'stonewright/elementor-v3-get-element',
			'stonewright/widget-intent-resolve',
			'stonewright/elementor-widget-implementation-guide',
			'stonewright/elementor-v3-build-page-from-spec',
			'stonewright/elementor-v3-batch-mutate',
			'stonewright/elementor-v3-apply-bundle',
			'stonewright/elementor-v3-update-page-settings',
			'stonewright/elementor-v3-update-kit-colors',
			'stonewright/elementor-v3-update-kit-typography',
			'stonewright/elementor-v3-save-template',
			'stonewright/theme-builder-apply-template',

			// Design pipeline.
			'stonewright/design-implementation-contract',
			'stonewright/design-validate-spec',
			'stonewright/design-build-spec',
			'stonewright/design-spec-to-elementor-v3',
			'stonewright/design-spec-to-gutenberg',
			'stonewright/design-apply-to-post',

			// Gutenberg and FSE fast paths.
			'stonewright/blocks-list-registered',
			'stonewright/blocks-get-schema',
			'stonewright/blocks-parse',
			'stonewright/blocks-serialize',
			'stonewright/gutenberg-render-blocks',
			'stonewright/gutenberg-apply-to-post',
			'stonewright/fse-get-theme-json',
			'stonewright/fse-list-templates',
			'stonewright/fse-read-template',
			'stonewright/fse-write-template',
			'stonewright/fse-write-global-styles',

			// Plugin/theme/CPT/Woo operations stay fast via runtime and WP-CLI tools.
			'stonewright/wp-cli-status',
			'stonewright/wp-cli-discover',
			'stonewright/wp-cli-run',
			'stonewright/wp-cli-batch-run',
			'stonewright/wp-cli-job-start',
			'stonewright/wp-cli-job-status',
		];
	}

	/**
	 * @return list<string>
	 */
	private static function essential_extra_ability_names(): array {
		$extra = (array) get_option( 'stonewright_essential_extra_abilities', [] );

		return array_values(
			array_filter(
				array_map( 'strval', $extra ),
				static fn( string $name ): bool => str_starts_with( $name, 'stonewright/' )
			)
		);
	}

	public static function mcp_tool_name( string $ability_name ): string {
		return str_replace( '/', '-', $ability_name );
	}

	/**
	 * @return array<string,string>
	 */
	private static function categories(): array {
		return [
			'security'  => __( 'Security', 'stonewright' ),
			'site'      => __( 'Site', 'stonewright' ),
			'content'   => __( 'Content', 'stonewright' ),
			'content-model' => __( 'Content Model', 'stonewright' ),
			'media'     => __( 'Media', 'stonewright' ),
			'gutenberg' => __( 'Gutenberg', 'stonewright' ),
			'patterns'  => __( 'Patterns', 'stonewright' ),
			'fse'       => __( 'Full Site Editing', 'stonewright' ),
			'elementor' => __( 'Elementor', 'stonewright' ),
			'design'    => __( 'Design', 'stonewright' ),
			'knowledge' => __( 'Knowledge', 'stonewright' ),
			'memory'    => __( 'Memory', 'stonewright' ),
			'runtime'   => __( 'Runtime', 'stonewright' ),
			'system'    => __( 'System', 'stonewright' ),
			'wp-cli'    => __( 'WP-CLI', 'stonewright' ),
			'sandbox'           => __( 'Sandbox', 'stonewright' ),
			'elementor-widget'  => __( 'Elementor Widget Builder', 'stonewright' ),
			'theme-builder'     => __( 'Theme Builder', 'stonewright' ),
			'menu'              => __( 'Menu', 'stonewright' ),
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
