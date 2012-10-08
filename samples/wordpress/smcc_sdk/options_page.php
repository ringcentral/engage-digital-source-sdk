<div class="wrap">
	<h2>Dimelo's SMCC SDK</h2>

	<p>
		<label>
			Base URI:
			<input type="text" value="<?php echo rtrim( get_site_url(), '/' ) . '/?dimelo_smcc_sdk=1'; ?>" size="40"/>
		</label>
	</p>

	<form action="options.php" method="post">
		<?php settings_fields( 'smcc_sdk_options' ); ?>
		<?php do_settings_sections( 'smcc_sdk_options_page' ); ?>
		<?php submit_button(); ?>
	</form>
</div>
