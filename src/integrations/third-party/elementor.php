<?php

namespace Yoast\WP\SEO\Integrations;

use WP_Post;
use WP_Screen;
use WPSEO_Admin_Asset;
use WPSEO_Admin_Asset_Analysis_Worker_Location;
use WPSEO_Admin_Asset_Manager;
use WPSEO_Admin_Asset_Yoast_Components_L10n;
use WPSEO_Admin_Recommended_Replace_Vars;
use WPSEO_Language_Utils;
use WPSEO_Meta;
use WPSEO_Metabox_Formatter;
use WPSEO_Post_Metabox_Formatter;
use WPSEO_Utils;
use Yoast\WP\SEO\Conditionals\Admin\Elementor_Edit_Conditional;
use Yoast\WP\SEO\Helpers\Capability_Helper;
use Yoast\WP\SEO\Helpers\Options_Helper;
use Yoast\WP\SEO\Presenters\Admin\Meta_Fields_Presenter;

/**
 * Adds customizations to the front end for breadcrumbs.
 */
class Elementor_Integration implements Integration_Interface {

	/**
	 * Represents the post.
	 *
	 * @var WP_Post|null
	 */
	protected $post;

	/**
	 * Represents the admin asset manager.
	 *
	 * @var WPSEO_Admin_Asset_Manager
	 */
	protected $asset_manager;

	/**
	 * Represents the options helper.
	 *
	 * @var \Yoast\WP\SEO\Helpers\Options_Helper
	 */
	protected $options;

	/**
	 * Represents the capability helper.
	 *
	 * @var \Yoast\WP\SEO\Helpers\Capability_Helper
	 */
	protected $capability;

	/**
	 * @var bool
	 */
	protected $social_is_enabled;

	/**
	 * @var bool
	 */
	protected $is_advanced_metadata_enabled;

	/**
	 * Returns the conditionals based in which this loadable should be active.
	 *
	 * @return array
	 */
	public static function get_conditionals() {
		return [ Elementor_Edit_Conditional::class ];
	}

	/**
	 * Constructor.
	 *
	 * @param WPSEO_Admin_Asset_Manager $asset_manager The asset manager.
	 * @param Options_Helper            $options       The options helper.
	 * @param Capability_Helper         $capability    The capability helper.
	 */
	public function __construct( WPSEO_Admin_Asset_Manager $asset_manager, Options_Helper $options, Capability_Helper $capability ) {
		$this->asset_manager = $asset_manager;
		$this->options       = $options;
		$this->capability    = $capability;
	}

	/**
	 * @codeCoverageIgnore
	 * @inheritDoc
	 */
	public function register_hooks() {
		\add_action( 'elementor/editor/before_enqueue_scripts', [ $this, 'init' ] );
		\add_action( 'wp_ajax_wpseo_elementor_save', 'save_postdata' );
	}

	/**
	 * Renders the breadcrumbs.
	 *
	 * @return string The rendered breadcrumbs.
	 */
	public function init() {
		$this->social_is_enabled            = $this->options->get( 'opengraph', false ) || $this->options->get( 'twitter', false );
		$this->is_advanced_metadata_enabled = $this->capability->current_user_can( 'wpseo_edit_advanced_metadata' ) || $this->options->get( 'disableadvanced_meta' ) === false;

		$this->asset_manager->register_assets();
		$this->enqueue();
		$this->render_hidden_fields();
	}

	// Below is mostly copied from `class-metabox.php`. That constructor has side-effects we do not need.

	/**
	 * Saves the WP SEO metadata for posts.
	 *
	 * {@internal $_POST parameters are validated via sanitize_post_meta().}}
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool|void Boolean false if invalid save post request.
	 */
	public function save_postdata( $post_id ) {
		if ( ! \current_user_can( 'manage_options' ) ) {
			die( '-1' );
		}
		\check_ajax_referer( 'wpseo_elementor_save' );

		// Debug return.
		return [
			'status' => 'hi',
		];
		wp_die();

		// Bail if this is a multisite installation and the site has been switched.
		if ( \is_multisite() && \ms_is_switched() ) {
			return false;
		}

		if ( $post_id === null ) {
			return false;
		}

		if ( \wp_is_post_revision( $post_id ) ) {
			$post_id = \wp_is_post_revision( $post_id );
		}

		/**
		 * Determine we're not accidentally updating a different post.
		 * We can't use filter_input here as the ID isn't available at this point, other than in the $_POST data.
		 */
		if ( ! isset( $_POST['ID'] ) || $post_id !== (int) $_POST['ID'] ) {
			return false;
		}

		\clean_post_cache( $post_id );
		$post = \get_post( $post_id );

		if ( ! \is_object( $post ) ) {
			// Non-existent post.
			return false;
		}

		\do_action( 'wpseo_save_compare_data', $post );

		$social_fields = [];
		if ( $this->social_is_enabled ) {
			$social_fields = WPSEO_Meta::get_meta_field_defs( 'social' );
		}

		$meta_boxes = \apply_filters( 'wpseo_save_metaboxes', [] );
		$meta_boxes = \array_merge(
			$meta_boxes,
			WPSEO_Meta::get_meta_field_defs( 'general', $post->post_type ),
			WPSEO_Meta::get_meta_field_defs( 'advanced' ),
			$social_fields,
			WPSEO_Meta::get_meta_field_defs( 'schema', $post->post_type )
		);

		foreach ( $meta_boxes as $key => $meta_box ) {
			// If analysis is disabled remove that analysis score value from the DB.
			if ( $this->is_meta_value_disabled( $key ) ) {
				WPSEO_Meta::delete( $key, $post_id );
				continue;
			}

			$data       = null;
			$field_name = WPSEO_Meta::$form_prefix . $key;

			if ( $meta_box['type'] === 'checkbox' ) {
				$data = isset( $_POST[ $field_name ] ) ? 'on' : 'off';
			}
			else {
				if ( isset( $_POST[ $field_name ] ) ) {
					$data = \wp_unslash( $_POST[ $field_name ] );

					// For multi-select.
					if ( \is_array( $data ) ) {
						$data = \array_map( [ 'WPSEO_Utils', 'sanitize_text_field' ], $data );
					}

					if ( \is_string( $data ) ) {
						$data = ( $key !== 'canonical' ) ? WPSEO_Utils::sanitize_text_field( $data ) : WPSEO_Utils::sanitize_url( $data );
					}
				}

				// Reset options when no entry is present with multiselect - only applies to `meta-robots-adv` currently.
				if ( ! isset( $_POST[ $field_name ] ) && ( $meta_box['type'] === 'multiselect' ) ) {
					$data = [];
				}
			}

			if ( $data !== null ) {
				WPSEO_Meta::set_value( $key, $data, $post_id );
			}
		}

		\do_action( 'wpseo_saved_postdata' );
	}

	/**
	 * Enqueues all the needed JS and CSS.
	 *
	 */
	public function enqueue() {
		$post_id = \get_queried_object_id();
		if ( empty( $post_id ) && isset( $_GET['post'] ) ) {
			$post_id = \sanitize_text_field( $_GET['post'] );
		}

		if ( $post_id !== 0 ) {
			// Enqueue files needed for upload functionality.
			\wp_enqueue_media( [ 'post' => $post_id ] );
		}

		$this->asset_manager->enqueue_style( 'metabox-css' );
		$this->asset_manager->enqueue_style( 'scoring' );
		$this->asset_manager->enqueue_style( 'select2' );
		$this->asset_manager->enqueue_style( 'monorepo' );
		$this->asset_manager->enqueue_style( 'admin-css' );
		$this->asset_manager->enqueue_style( 'elementor' );

		$this->asset_manager->enqueue_script( 'elementor' );

		$yoast_components_l10n = new WPSEO_Admin_Asset_Yoast_Components_L10n();
		$yoast_components_l10n->localize_script( WPSEO_Admin_Asset_Manager::PREFIX . 'elementor' );

		\wp_localize_script( WPSEO_Admin_Asset_Manager::PREFIX . 'elementor', 'wpseoAdminL10n', WPSEO_Utils::get_admin_l10n() );
		\wp_localize_script( WPSEO_Admin_Asset_Manager::PREFIX . 'elementor', 'wpseoFeaturesL10n', WPSEO_Utils::retrieve_enabled_features() );

		$analysis_worker_location          = new WPSEO_Admin_Asset_Analysis_Worker_Location( $this->asset_manager->flatten_version( WPSEO_VERSION ) );
		$used_keywords_assessment_location = new WPSEO_Admin_Asset_Analysis_Worker_Location( $this->asset_manager->flatten_version( WPSEO_VERSION ), 'used-keywords-assessment' );

		$script_data = [
			'analysis'         => [
				'plugins' => [
					'replaceVars' => [
						'no_parent_text'           => __( '(no parent)', 'wordpress-seo' ),
						'replace_vars'             => $this->get_replace_vars(),
						'recommended_replace_vars' => $this->get_recommended_replace_vars(),
						'scope'                    => $this->determine_scope(),
						'has_taxonomies'           => $this->current_post_type_has_taxonomies(),
					],
					'shortcodes'  => [
						'wpseo_filter_shortcodes_nonce' => \wp_create_nonce( 'wpseo-filter-shortcodes' ),
						'wpseo_shortcode_tags'          => $this->get_valid_shortcode_tags(),
					],
				],
				'worker'  => [
					'url'                     => $analysis_worker_location->get_url( $analysis_worker_location->get_asset(), WPSEO_Admin_Asset::TYPE_JS ),
					'keywords_assessment_url' => $used_keywords_assessment_location->get_url( $used_keywords_assessment_location->get_asset(), WPSEO_Admin_Asset::TYPE_JS ),
					'log_level'               => WPSEO_Utils::get_analysis_worker_log_level(),
					// We need to make the feature flags separately available inside of the analysis web worker.
					'enabled_features'        => WPSEO_Utils::retrieve_enabled_features(),
				],
			],
			'media'            => [
				'choose_image' => __( 'Use Image', 'wordpress-seo' ),
			],
			'metabox'          => $this->get_metabox_script_data(),
			'userLanguageCode' => WPSEO_Language_Utils::get_language( WPSEO_Language_Utils::get_user_locale() ),
			'isPost'           => true,
			'isBlockEditor'    => WP_Screen::get()->is_block_editor(),
		];

		if ( post_type_supports( get_post_type(), 'thumbnail' ) ) {
			$this->asset_manager->enqueue_style( 'featured-image' );

			$script_data['featuredImage'] = [
				'featured_image_notice' => __( 'SEO issue: The featured image should be at least 200 by 200 pixels to be picked up by Facebook and other social media sites.', 'wordpress-seo' ),
			];
		}

		\wp_localize_script( WPSEO_Admin_Asset_Manager::PREFIX . 'elementor', 'wpseoScriptData', $script_data );
	}

	/**
	 * Renders the metabox hidden fields.
	 */
	protected function render_hidden_fields() {
		printf( '<form id="yoast-form" method="post" action="%1$s"><input type="hidden" name="action" value="wpseo_elementor_save" />', \admin_url( 'admin-ajax.php' ) );

		\wp_nonce_field( 'wpseo_elementor_save', '_wpnonce' );
		echo new Meta_Fields_Presenter( $this->get_metabox_post(), 'general' );

		if ( $this->is_advanced_metadata_enabled ) {
			echo new Meta_Fields_Presenter( $this->get_metabox_post(), 'advanced' );
		}

		echo new Meta_Fields_Presenter( $this->get_metabox_post(), 'schema', $this->get_metabox_post()->post_type );

		if ( $this->social_is_enabled ) {
			echo new Meta_Fields_Presenter( $this->get_metabox_post(), 'social' );
		}

		/**
		 * Filter: 'wpseo_content_meta_section_content' - Allow filtering the metabox content before outputting.
		 *
		 * @api string $post_content The metabox content string.
		 */
		echo \apply_filters( 'wpseo_content_meta_section_content', '' );

		echo "</form>";
	}

	/**
	 * Returns post in metabox context.
	 *
	 * @returns WP_Post|null
	 */
	protected function get_metabox_post() {
		if ( $this->post !== null ) {
			return $this->post;
		}

		$post = \filter_input( INPUT_GET, 'post' );
		if ( ! empty( $post ) ) {
			$post_id = (int) WPSEO_Utils::validate_int( $post );

			$this->post = \get_post( $post_id );

			return $this->post;
		}

		if ( isset( $GLOBALS['post'] ) ) {
			$this->post = $GLOBALS['post'];

			return $this->post;
		}

		return null;
	}

	/**
	 * Passes variables to js for use with the post-scraper.
	 *
	 * @return array
	 */
	protected function get_metabox_script_data() {
		$permalink = '';

		if ( \is_object( $this->get_metabox_post() ) ) {
			$permalink = \get_sample_permalink( $this->get_metabox_post()->ID );
		}

		$post_formatter = new WPSEO_Metabox_Formatter(
			new WPSEO_Post_Metabox_Formatter( $this->get_metabox_post(), [], $permalink )
		);

		$values = $post_formatter->get_values();

		/** This filter is documented in admin/filters/class-cornerstone-filter.php. */
		$post_types = \apply_filters( 'wpseo_cornerstone_post_types', YoastSEO()->helpers->post_type->get_accessible_post_types() );
		if ( $values['cornerstoneActive'] && ! \in_array( $this->get_metabox_post()->post_type, $post_types, true ) ) {
			$values['cornerstoneActive'] = false;
		}

		return $values;
	}

	/**
	 * Prepares the replace vars for localization.
	 *
	 * @return array Replace vars.
	 */
	protected function get_replace_vars() {
		$cached_replacement_vars = [];

		$vars_to_cache = [
			'date',
			'id',
			'sitename',
			'sitedesc',
			'sep',
			'page',
			'currentyear',
		];

		foreach ( $vars_to_cache as $var ) {
			$cached_replacement_vars[ $var ] = \wpseo_replace_vars( '%%' . $var . '%%', $this->get_metabox_post() );
		}

		// Merge custom replace variables with the WordPress ones.
		return \array_merge( $cached_replacement_vars, $this->get_custom_replace_vars( $this->get_metabox_post() ) );
	}

	/**
	 * Prepares the recommended replace vars for localization.
	 *
	 * @return array Recommended replacement variables.
	 */
	protected function get_recommended_replace_vars() {
		$recommended_replace_vars = new WPSEO_Admin_Recommended_Replace_Vars();

		// What is recommended depends on the current context.
		$post_type = $recommended_replace_vars->determine_for_post( $this->get_metabox_post() );

		return $recommended_replace_vars->get_recommended_replacevars_for( $post_type );
	}

	/**
	 * Gets the custom replace variables for custom taxonomies and fields.
	 *
	 * @param WP_Post $post The post to check for custom taxonomies and fields.
	 *
	 * @return array Array containing all the replacement variables.
	 */
	protected function get_custom_replace_vars( $post ) {
		return [
			'custom_fields'     => $this->get_custom_fields_replace_vars( $post ),
			'custom_taxonomies' => $this->get_custom_taxonomies_replace_vars( $post ),
		];
	}

	/**
	 * Gets the custom replace variables for custom taxonomies.
	 *
	 * @param WP_Post $post The post to check for custom taxonomies.
	 *
	 * @return array Array containing all the replacement variables.
	 */
	protected function get_custom_taxonomies_replace_vars( $post ) {
		$taxonomies          = \get_object_taxonomies( $post, 'objects' );
		$custom_replace_vars = [];

		foreach ( $taxonomies as $taxonomy_name => $taxonomy ) {

			if ( is_string( $taxonomy ) ) { // If attachment, see https://core.trac.wordpress.org/ticket/37368 .
				$taxonomy_name = $taxonomy;
				$taxonomy      = \get_taxonomy( $taxonomy_name );
			}

			if ( $taxonomy->_builtin && $taxonomy->public ) {
				continue;
			}

			$custom_replace_vars[ $taxonomy_name ] = [
				'name'        => $taxonomy->name,
				'description' => $taxonomy->description,
			];
		}

		return $custom_replace_vars;
	}

	/**
	 * Gets the custom replace variables for custom fields.
	 *
	 * @param WP_Post $post The post to check for custom fields.
	 *
	 * @return array Array containing all the replacement variables.
	 */
	protected function get_custom_fields_replace_vars( $post ) {
		$custom_replace_vars = [];

		// If no post object is passed, return the empty custom_replace_vars array.
		if ( ! \is_object( $post ) ) {
			return $custom_replace_vars;
		}

		$custom_fields = \get_post_custom( $post->ID );

		foreach ( $custom_fields as $custom_field_name => $custom_field ) {
			// Skip private custom fields.
			if ( \substr( $custom_field_name, 0, 1 ) === '_' ) {
				continue;
			}

			// Skip custom field values that are serialized.
			if ( \is_serialized( $custom_field[0] ) ) {
				continue;
			}

			$custom_replace_vars[ $custom_field_name ] = $custom_field[0];
		}

		return $custom_replace_vars;
	}

	/**
	 * Determines the scope based on the post type.
	 * This can be used by the replacevar plugin to determine if a replacement needs to be executed.
	 *
	 * @return string String describing the current scope.
	 */
	protected function determine_scope() {
		if ( $this->get_metabox_post()->post_type === 'page' ) {
			return 'page';
		}

		return 'post';
	}

	/**
	 * Determines whether or not the current post type has registered taxonomies.
	 *
	 * @return bool Whether the current post type has taxonomies.
	 */
	protected function current_post_type_has_taxonomies() {
		$post_taxonomies = \get_object_taxonomies( $this->get_metabox_post()->post_type );

		return ! empty( $post_taxonomies );
	}

	/**
	 * Returns an array with shortcode tags for all registered shortcodes.
	 *
	 * @return array
	 */
	protected function get_valid_shortcode_tags() {
		$shortcode_tags = [];

		foreach ( $GLOBALS['shortcode_tags'] as $tag => $description ) {
			$shortcode_tags[] = $tag;
		}

		return $shortcode_tags;
	}
}