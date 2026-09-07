<?php
/**
 * Removes what the plugin stored.
 *
 * This file runs WITHOUT the plugin being loaded: no constants, no classes, no
 * functions of ours exist here. The option name is therefore written out in
 * full, and has to be kept in step with ULCAR\Settings::OPTION by hand.
 *
 * @package UltralightCarouselViaSse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Mirrors ULCAR\Settings::OPTION. */
const ULCAR_UNINSTALL_OPTION = 'ulcar_settings';

delete_option( ULCAR_UNINSTALL_OPTION );

if ( is_multisite() ) {
	/*
	 * Walking every site of a very large network in one request times out, and
	 * a stranded option row is a smaller problem than a half-finished
	 * uninstall.
	 */
	if ( ! wp_is_large_network() ) {
		$ulcar_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $ulcar_sites as $ulcar_site_id ) {
			switch_to_blog( $ulcar_site_id );
			delete_option( ULCAR_UNINSTALL_OPTION );
			restore_current_blog();
		}
	}

	delete_site_option( ULCAR_UNINSTALL_OPTION );
}
