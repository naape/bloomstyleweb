<?php
/**
 * "Site care": one screen showing what still needs attention for search,
 * security, backups and speed, with short instructions.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'bloomstyle-store', __( 'Site care', 'bloomstyle-store' ), __( 'Site care', 'bloomstyle-store' ), 'manage_options', 'bloomstyle-site-care', 'bloomstyle_render_site_care' );
	},
	35
);

/**
 * Current status of each check.
 *
 * @return array<int,array{0:string,1:bool,2:string}> Label, whether it is good, and where to fix it.
 */
function bloomstyle_site_care_checks() {
	$analytics = (array) get_option( 'woocommerce_google_analytics_settings', array() );
	$storage   = get_option( 'updraft_service', array() );
	$storage   = array_filter( array_diff( (array) $storage, array( 'none', '' ) ) );
	$admins    = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
	$social    = bloomstyle_setting( 'facebook_url' ) || bloomstyle_setting( 'instagram_url' ) || bloomstyle_setting( 'tiktok_url' );
	$updates   = wp_get_update_data();

	return array(
		array( __( 'Site uses HTTPS', 'bloomstyle-store' ), 0 === strpos( home_url(), 'https://' ), '' ),
		array( __( 'Search engines allowed', 'bloomstyle-store' ), (bool) get_option( 'blog_public' ), admin_url( 'options-reading.php' ) ),
		array( __( 'Privacy Policy published', 'bloomstyle-store' ), '' !== get_privacy_policy_url(), admin_url( 'options-privacy.php' ) ),
		array( __( 'Debug mode off', 'bloomstyle-store' ), ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ), '' ),
		array( __( 'Wordfence active', 'bloomstyle-store' ), class_exists( 'wordfence' ), admin_url( 'admin.php?page=Wordfence' ) ),
		array( __( 'Automatic backups scheduled', 'bloomstyle-store' ), wp_next_scheduled( 'updraft_backup' ) && wp_next_scheduled( 'updraft_backup_database' ), admin_url( 'options-general.php?page=updraftplus' ) ),
		array( __( 'Backups stored away from this server', 'bloomstyle-store' ), (bool) $storage, admin_url( 'options-general.php?page=updraftplus' ) ),
		array( __( 'Page caching on', 'bloomstyle-store' ), defined( 'WP_CACHE' ) && WP_CACHE, admin_url( 'admin.php?page=wpo_cache' ) ),
		array( __( 'Google Analytics connected', 'bloomstyle-store' ), ! empty( $analytics['ga_id'] ), admin_url( 'admin.php?page=wc-settings&tab=integration' ) ),
		array( __( 'Social media links entered', 'bloomstyle-store' ), (bool) $social, admin_url( 'admin.php?page=bloomstyle-store' ) ),
		/* translators: %d: number of administrator accounts. */
		array( sprintf( __( 'Few administrator accounts (now %d)', 'bloomstyle-store' ), $admins ), $admins <= 2, admin_url( 'users.php?role=administrator' ) ),
		array( __( 'WordPress, plugins and theme up to date', 'bloomstyle-store' ), 0 === (int) $updates['counts']['total'], admin_url( 'update-core.php' ) ),
	);
}

/**
 * Render the Site care screen.
 */
function bloomstyle_render_site_care() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Site care', 'bloomstyle-store' ) . '</h1>';
	echo '<table class="widefat striped" style="max-width:720px"><tbody>';
	foreach ( bloomstyle_site_care_checks() as $check ) {
		echo '<tr><td>' . esc_html( $check[0] ) . '</td><td style="font-weight:600;color:' . ( $check[1] ? '#17703a' : '#9a5b00' ) . '">' . esc_html( $check[1] ? __( 'Yes', 'bloomstyle-store' ) : __( 'Not yet', 'bloomstyle-store' ) ) . '</td><td>';
		if ( ! $check[1] && $check[2] ) {
			echo '<a href="' . esc_url( $check[2] ) . '">' . esc_html__( 'Open', 'bloomstyle-store' ) . '</a>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	$guides = array(
		__( 'Every week', 'bloomstyle-store' )               => __( 'Install updates, after checking that the latest backup finished. Then place a test order. Read any Wordfence email about scan results.', 'bloomstyle-store' ),
		__( 'Backups', 'bloomstyle-store' )                  => __( 'Under Settings, UpdraftPlus Backups, connect Google Drive or another storage service. A backup kept only on this server is lost if the server fails.', 'bloomstyle-store' ),
		__( 'Restoring a backup', 'bloomstyle-store' )       => __( 'Open UpdraftPlus, choose the date under Existing backups and click Restore. Practise once, soon after launch, so you know it works.', 'bloomstyle-store' ),
		__( 'Passwords and accounts', 'bloomstyle-store' )   => __( 'Use long, unique passwords and turn on two-factor login under Wordfence, Login Security. Give staff the Shop manager role, not Administrator.', 'bloomstyle-store' ),
		__( 'Reviews and spam', 'bloomstyle-store' )         => __( 'Every review waits for your approval under Products, Reviews. Antispam Bee filters most spam on its own.', 'bloomstyle-store' ),
		__( 'Search results', 'bloomstyle-store' )           => __( 'Give each product a clear name and description. In the Yoast SEO box you can edit how a page appears in Google. Submit your sitemap (/sitemap_index.xml) in Google Search Console.', 'bloomstyle-store' ),
		__( 'Speed', 'bloomstyle-store' )                    => __( 'Upload photos under about 2000 pixels wide. WordPress makes lighter copies automatically. After big changes, clear the cache under WP-Optimize.', 'bloomstyle-store' ),
	);
	echo '<h2>' . esc_html__( 'How to', 'bloomstyle-store' ) . '</h2>';
	foreach ( $guides as $title => $text ) {
		echo '<h3>' . esc_html( $title ) . '</h3><p style="max-width:720px">' . esc_html( $text ) . '</p>';
	}
	echo '</div>';
}

// wp-config.php can set the site address, which hides the stored value from
// WordPress, so a search and replace cannot update it. Keep them in step, or the
// site would break if those lines were ever removed.
add_action(
	'admin_init',
	function () {
		global $wpdb;
		foreach ( array( 'siteurl' => 'WP_SITEURL', 'home' => 'WP_HOME' ) as $option => $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( constant( $constant ) !== $stored ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => constant( $constant ) ), array( 'option_name' => $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_delete( 'alloptions', 'options' );
			}
		}
	}
);
