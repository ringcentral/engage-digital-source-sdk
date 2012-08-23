# Wordpress blog

To integrate you will need to follow these steps:

1. copy the file `smcc_sdk.php` in the folder `wp-content/plugins`.
2. edit the file and change the `SMCC_SDK_ACCESS_TOKEN` constant to a random secret key.
3. from the control panel of the blog you will need to activate the Smcc Sdk plugin.
4. The option `Comment author must fill out name and e-mail` *must* be enabled.

Limitations:

- right now the plugin does not do anything about moderated content, ie. the contents will be displayed on the SMCC side.
