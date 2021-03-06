<?php

class Smartcrawl_Settings_Settings extends Smartcrawl_Settings_Admin {


	private static $_instance;

	public static function get_instance() {
		if ( empty( self::$_instance ) ) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	/**
	 * Validate submitted options
	 *
	 * @param array $input Raw input
	 *
	 * @return array Validated input
	 */
	public function validate( $input ) {
		$result = array();
		$saving_user_roles = isset( $input['saving_user_roles'] ) && $input['saving_user_roles'];

		// The options page is broken down into two parts. The following operation fills in option values from the missing part.
		if ( $saving_user_roles ) {
			$input = wp_parse_args(
				$input,
				self::get_specific_options( $this->option_name )
			);
		} else {
			$input = wp_parse_args(
				$input,
				$this->get_old_user_role_options()
			);
		}

		if ( ! empty( $input['wds_settings-setup'] ) ) { $result['wds_settings-setup'] = true; }

		$booleans = array_keys( Smartcrawl_Settings::get_known_components() );
		foreach ( $booleans as $bool ) {
			if ( ! empty( $input[ $bool ] ) ) { $result[ $bool ] = true; }
		}

		// Analysis/readability
		$result['analysis-seo'] = ! empty( $input['analysis-seo'] );
		$result['analysis-readability'] = ! empty( $input['analysis-readability'] );
		$result['extras-admin_bar'] = ! empty( $input['extras-admin_bar'] );

		if ( ! empty( $input['redirections-code'] ) && is_numeric( $input['redirections-code'] ) ) {
			$code = (int) $input['redirections-code'];
			if ( in_array( $code, array( 301, 302 ) ) ) { $result['redirections-code'] = $code; }
		}
		if ( ! empty( $input['metabox-lax_enforcement'] ) ) {
			$result['metabox-lax_enforcement'] = true;
		} else {
			$result['metabox-lax_enforcement'] = false;
		}
		if ( ! empty( $input['general-suppress-generator'] ) ) {
			$result['general-suppress-generator'] = true;
		} else {
			$result['general-suppress-generator'] = false;
		}
		if ( ! empty( $input['general-suppress-redundant_canonical'] ) ) {
			$result['general-suppress-redundant_canonical'] = true;
		} else {
			$result['general-suppress-redundant_canonical'] = false;
		}

		$strings = array(
			'access-id',
			'secret-key',
		);
		foreach ( $strings as $str ) {
			if ( ! empty( $input[ $str ] ) ) { $result[ $str ] = sanitize_text_field( $input[ $str ] ); }
		}

		// Roles
		foreach ( $this->_get_permission_contexts() as $ctx ) {
			if ( empty( $input[ $ctx ] ) ) { continue; }
			$roles = array_keys( $this->_get_filtered_roles( "wds-{$ctx}" ) );
			$check_context = is_array( $input[ $ctx ] )
				? $input[ $ctx ]
				: array( $input[ $ctx ] )
			;
			$result[ $ctx ] = array();
			foreach ( $check_context as $ctx_item ) {
				if ( in_array( $ctx_item, $roles ) ) { $result[ $ctx ][] = $ctx_item; }
			}
		}

		if ( isset( $input['verification-google-meta'] ) ) {
			$this->_validate_and_save_extra_options( $input );
		}

		return $result;
	}

	/**
	 * Processes extra options passed on from the main form
	 *
	 * This is a side-effect method - the extra options don't update
	 * the tab option key, but go to an extternal location
	 *
	 * @param array $input Raw form input
	 */
	private function _validate_and_save_extra_options( $input ) {
		// Blog tabs
		if ( is_multisite() && current_user_can( 'manage_network_options' ) ) {
			$raw = ! empty( $input['wds_blog_tabs'] ) && is_array( $input['wds_blog_tabs'] )
				? $input['wds_blog_tabs']
				: array()
			;
			$tabs = array();
			foreach ( $raw as $key => $tab ) {
				if ( ! empty( $tab ) ) { $tabs[ $key ] = true; }
			}

			update_site_option( 'wds_blog_tabs', $tabs );

			update_site_option( 'wds_sitewide_mode', (int) ! empty( $input['wds_sitewide_mode'] ) );
		}

		// Sitemaps validation/save
		$sitemaps = Smartcrawl_Settings::get_component_options( Smartcrawl_Settings::COMP_SITEMAP );
		$sitemaps_updated = false;
		if ( ! empty( $input['verification-google'] ) ) {
			$sitemaps['verification-google'] = sanitize_text_field( $input['verification-google'] );
			$sitemaps_updated = true;
		}
		if ( ! empty( $input['verification-bing'] ) ) {
			$sitemaps['verification-bing'] = sanitize_text_field( $input['verification-bing'] );
			$sitemaps_updated = true;
		}
		if ( ! empty( $input['verification-pages'] ) ) {
			$pages = $input['verification-pages'];
			if ( in_array( $pages, array( '', 'home' ) ) ) { $sitemaps['verification-pages'] = $pages; }
			$sitemaps_updated = true;
		}

		// Meta tags
		if ( ! empty( $input['verification-google-meta'] ) ) {
			$sitemaps['verification-google-meta'] = $input['verification-google-meta'];
			$sitemaps['verification-google'] = false;
			$sitemaps_updated = true;
		}
		if ( ! empty( $input['verification-bing-meta'] ) ) {
			$sitemaps['verification-bing-meta'] = $input['verification-bing-meta'];
			$sitemaps['verification-bing'] = false;
			$sitemaps_updated = true;
		}

		$custom_values_key = 'additional-metas';
		if ( ! empty( $input[ $custom_values_key ] ) && is_array( $input[ $custom_values_key ] ) ) {
			$custom_values = $input[ $custom_values_key ];
			$sanitized_custom_values = array();
			foreach ( $custom_values as $index => $custom_value ) {
				if ( trim( $custom_value ) ) {
					$sanitized = wp_kses($custom_value, array(
						'meta' => array(
							'charset'    => array(),
							'content'    => array(),
							'http-equiv' => array(),
							'name'       => array(),
							'scheme'     => array(),
						),
					));
					if ( preg_match( '/<meta\b/', trim( $sanitized ) ) ) {
						$sanitized_custom_values[] = $sanitized;
					}
				}
			}
			$sitemaps[ $custom_values_key ] = $sanitized_custom_values;
			$sitemaps_updated = true;
		}

		if ( $sitemaps_updated ) {
			Smartcrawl_Settings::update_component_options( Smartcrawl_Settings::COMP_SITEMAP, $sitemaps );
		}
	}

	public function init() {
		$this->option_name = 'wds_settings_options';
		$this->name        = 'settings';
		$this->slug        = Smartcrawl_Settings::TAB_SETTINGS;
		$this->action_url  = admin_url( 'options.php' );
		$this->title       = __( 'Settings', 'wds' );
		$this->page_title  = __( 'SmartCrawl Wizard: Settings', 'wds' );

		add_action( 'admin_init', array( $this, 'activate_component' ) );
		add_action( 'admin_init', array( $this, 'save_moz_api_credentials' ) );

		if ( ! class_exists( 'Smartcrawl_Controller_IO' ) ) {
			require_once( SMARTCRAWL_PLUGIN_DIR . '/core/class_wds_controller_io.php' );
		}
		Smartcrawl_Controller_IO::serve();

		parent::init();
	}

	/**
	 * Updates the options to activate a component.
	 */
	function activate_component() {
		if ( isset( $_POST['wds-activate-component'] ) ) {

			$component = sanitize_key( $_POST['wds-activate-component'] );
			$options = self::get_specific_options( $this->option_name );
			$options[ $component ] = 1;

			self::update_specific_options( $this->option_name, $options );

			wp_redirect( esc_url_raw( add_query_arg( array() ) ) );
		}
	}

	function save_moz_api_credentials() {
		if ( isset( $_POST['wds-moz-access-id'] ) || isset( $_POST['wds-moz-secret-key'] ) ) {
			$options = self::get_specific_options( $this->option_name );
			$options['access-id'] = sanitize_text_field( $_POST['wds-moz-access-id'] );
			$options['secret-key'] = sanitize_text_field( $_POST['wds-moz-secret-key'] );

			self::update_specific_options( $this->option_name, $options );

			wp_redirect( esc_url_raw( add_query_arg( array() ) ) );
		}
	}

	/**
	 * Get allowed blog tabs
	 *
	 * @return array
	 */
	public static function get_blog_tabs() {
		$blog_tabs = get_site_option( 'wds_blog_tabs' );
		return is_array( $blog_tabs )
			? $blog_tabs
			: array()
		;
	}

	/**
	 * Get (optionally filtered) default roles
	 *
	 * @param string $context_filter Optional filter to pass the roles through first
	 *
	 * @return array List of roles
	 */
	protected function _get_filtered_roles( $context_filter = false ) {
		$default_roles = array(
			'manage_network'       => __( 'Super Admin' ),
			'list_users'           => sprintf( __( '%s (and up)', 'wds' ), __( 'Site Admin' ) ),
			'moderate_comments'    => sprintf( __( '%s (and up)', 'wds' ), __( 'Editor' ) ),
			'edit_published_posts' => sprintf( __( '%s (and up)', 'wds' ), __( 'Author' ) ),
			'edit_posts'           => sprintf( __( '%s (and up)', 'wds' ), __( 'Contributor' ) ),
		);
		if ( ! is_multisite() ) { unset( $default_roles['manage_network'] ); }

		return ! empty( $context_filter )
			? (array) apply_filters( $context_filter, $default_roles )
			: $default_roles
		;
	}

	/**
	 * Get a list of permission contexts used for roles filtering
	 *
	 * @return array
	 */
	protected function _get_permission_contexts() {
		return array(
			'seo_metabox_permission_level',
			'seo_metabox_301_permission_level',
			'urlmetrics_metabox_permission_level',
		);
	}

	/**
	 * Add admin settings page
	 */
	public function options_page() {
		parent::options_page();

		$arguments['default_roles'] = $this->_get_filtered_roles();

		$arguments['active_components'] = Smartcrawl_Settings::get_known_components();
		if ( ! empty( $arguments['active_components'][ Smartcrawl_Settings::COMP_SEOMOZ ] ) ) { unset( $arguments['active_components'][ Smartcrawl_Settings::COMP_SEOMOZ ] ); }

		$arguments['slugs'] = array(
			Smartcrawl_Settings::TAB_ONPAGE => __( 'Title & Meta', 'wds' ),
			Smartcrawl_Settings::TAB_CHECKUP => __( 'SEO Checkup', 'wds' ),
			Smartcrawl_Settings::TAB_SITEMAP => __( 'Sitemaps', 'wds' ),
			Smartcrawl_Settings::TAB_AUTOLINKS => __( 'Advanced Tools', 'wds' ),
			Smartcrawl_Settings::TAB_SOCIAL => __( 'Social', 'wds' ),
			Smartcrawl_Settings::TAB_SETTINGS => __( 'Settings', 'wds' ),
		);

		if ( is_multisite() ) {
			$arguments['blog_tabs'] = self::get_blog_tabs();
		} else {
			$arguments['blog_tabs'] = array();
		}

		foreach ( $this->_get_permission_contexts() as $ctx ) {
			$arguments[ $ctx ] = $this->_get_filtered_roles( "wds-{$ctx}" );
		}

		$arguments['wds_sitewide_mode'] = smartcrawl_is_switch_active( 'SMARTCRAWL_SITEWIDE' ) || (bool) get_site_option( 'wds_sitewide_mode' );

		$smartcrawl_options = Smartcrawl_Settings::get_options();
		$sitemap_settings = Smartcrawl_Sitemap_Settings::get_instance();
		$arguments['sitemap_option_name'] = $sitemap_settings->option_name;

		$arguments['verification_pages'] = array(
			''     => __( 'All pages', 'wds' ),
			'home' => __( 'Home page', 'wds' ),
		);

		$arguments['google_msg'] = ! empty( $smartcrawl_options['verification-google'] )
			? '<code>' . esc_html( '<meta name="google-site-verification" value="' ) . esc_attr( $smartcrawl_options['verification-google'] ) . esc_html( '" />' ) . '</code>'
			: '<small>' . esc_html( __( 'No META tag will be added', 'wds' ) ) . '</small>';

		$arguments['bing_msg'] = ! empty( $smartcrawl_options['verification-bing'] )
			? '<code>' . esc_html( '<meta name="msvalidate.01" content="' ) . esc_attr( $smartcrawl_options['verification-bing'] ) . esc_html( '" />' ) . '</code>'
			: '<small>' . esc_html( __( 'No META tag will be added', 'wds' ) ) . '</small>';

		$arguments['active_tab'] = $this->_get_last_active_tab( 'tab_general_settings' );

		wp_enqueue_script( 'wds-admin-settings' );
		$this->_render_page( 'settings/settings', $arguments );
	}

	/**
	 * Default settings
	 */
	public function defaults() {
		$this->options = self::get_specific_options( $this->option_name );

		if ( empty( $this->options ) ) {
			if ( empty( $this->options['onpage'] ) ) {
				$this->options['onpage'] = 1;
			}

			if ( empty( $this->options['autolinks'] ) ) {
				$this->options['autolinks'] = 0;
			}

			if ( empty( $this->options['seomoz'] ) ) {
				$this->options['seomoz'] = 0;
			}

			if ( empty( $this->options['sitemap'] ) ) {
				$this->options['sitemap'] = 0;
			}

			if ( empty( $this->options['social'] ) ) {
				$this->options['social'] = 1;
			}

			if ( empty( $this->options['checkup'] ) ) {
				$this->options['checkup'] = 0;
			}
		}

		if ( empty( $this->options['seo_metabox_permission_level'] ) ) {
			$this->options['seo_metabox_permission_level'] = ( is_multisite() ? 'manage_network' : 'list_users' );
		}

		if ( empty( $this->options['urlmetrics_metabox_permission_level'] ) ) {
			$this->options['urlmetrics_metabox_permission_level'] = ( is_multisite() ? 'manage_network' : 'list_users' );
		}

		if ( empty( $this->options['seo_metabox_301_permission_level'] ) ) {
			$this->options['seo_metabox_301_permission_level'] = ( is_multisite() ? 'manage_network' : 'list_users' );
		}

		if ( empty( $this->options['access-id'] ) ) {
			$this->options['access-id'] = '';
		}

		if ( empty( $this->options['secret-key'] ) ) {
			$this->options['secret-key'] = '';
		}

		if ( ! isset( $this->options['analysis-seo'] ) ) { $this->options['analysis-seo'] = false; }
		if ( ! isset( $this->options['analysis-readability'] ) ) { $this->options['analysis-readability'] = false; }
		if ( ! isset( $this->options['extras-admin_bar'] ) ) { $this->options['extras-admin_bar'] = true; }

		apply_filters( 'wds_defaults', $this->options );

		self::update_specific_options( $this->option_name, $this->options );
	}

	private function get_old_user_role_options() {
		$option_keys = array(
			'seo_metabox_permission_level',
			'seo_metabox_301_permission_level',
			'urlmetrics_metabox_permission_level',
		);

		$old_options = self::get_specific_options( $this->option_name );

		$user_role_options = array();
		foreach ( $option_keys as $option_key ) {
			if ( $option_value = smartcrawl_get_array_value( $old_options, $option_key ) ) {
				$user_role_options[ $option_key ] = $option_value;
			}
		}

		return $user_role_options;
	}
}
