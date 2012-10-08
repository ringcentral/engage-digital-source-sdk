<?php

add_action( 'admin_init', array( SmccSdkOptions::instance(), 'init' ) );
add_action( 'admin_menu', array( SmccSdkOptions::instance(), 'run' ) );

class SmccSdkOptions {

	protected static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new SmccSdkOptions();
		}
		return self::$instance;
	}

	public function init() {
		if ( get_option( 'smcc_sdk_options' ) === false ) {
			update_option( 'smcc_sdk_options', array( 'secret_key' => '' ) );
		}

		register_setting( 
			$options_group = 'smcc_sdk_options', // used in the view to identify the settings
			$options_name  = 'smcc_sdk_options', // saved in db under this name
			$validation	   = array( SmccSdkOptions::instance(), 'validate_options' ) // called with the array of settings
		);

		add_settings_section(
			$section_id	   = 'smcc_sdk_options_main', // string for use in the 'id' attribute of tags
			$section_title = 'Main Settings', // title of the section set in the view
			$function	   = array( SmccSdkOptions::instance(), 'section_main' ), // function that should generate the header for this section
			$page		   = 'smcc_sdk_options_page' // should match $menu_slug from the options page
		);

		add_settings_field(
			$field_id	 = 'smcc_sdk_secret_key', // string for use in the 'id' attribute of tags
			$field_title = 'Secret Key', // title of the field
			$function	 = array( SmccSdkOptions::instance(), 'field_secret_key' ), // generates input markup
			$page		 = 'smcc_sdk_options_page', // should match $menu_slug from the options page
			$section	 = 'smcc_sdk_options_main' // should match section id from above
		);
	}

	public function section_main() {
		echo '<p>Please contact us to receive the secret key.</p>';
	}

	public function field_secret_key() {
		$options = get_option( 'smcc_sdk_options' );
		echo "<input id='smcc_sdk_secret_key' name='smcc_sdk_options[secret_key]' size='40' type='text' value='{$options['secret_key']}'/>";
	}

	public function validate_options( $input ) {
		$settings = array( 'secret_key' => trim( $input['secret_key'] ) );
		if ( ! preg_match( '/^[a-zA-Z0-9]{8,64}$/', $settings['secret_key'] ) ) {
			$settings['secret_key'] = '';
		}
		return $settings;
	}

	public function run() {
		add_options_page(
			$page_title = 'SMCC SDK Options',
			$menu_title = 'SMCC SDK',
			$capability = 'manage_options',
			$menu_slug  = 'smcc_sdk_options_page',
			$function   = array( $this, 'options_page' )
		);
	}

	public function options_page() {
		if ( ! current_user_can( 'manage_options' ) )  {
			wp_die( 'You do not have sufficient permissions to access this page.' );
		}

		require SMCC_SDK_PATH . '/options_page.php';
	}

}

