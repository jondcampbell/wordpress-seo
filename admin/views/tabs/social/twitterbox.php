<?php
/**
 * WPSEO plugin file.
 *
 * @package WPSEO\Admin\Views
 *
 * @uses Yoast_Form $yform                                   Form object.
 * @uses array      WPSEO_Option_Social::$twitter_card_types
 */

if ( ! defined( 'WPSEO_VERSION' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}
echo '<div class="yoast-paper yoast-paper--bottom">';
echo '<h2>' . esc_html__( 'Twitter settings', 'wordpress-seo' ) . '</h2>';

printf(
	'<p>%s</p>',
	esc_html__( 'Twitter uses Open Graph metadata just like Facebook, so be sure to keep the "Add Open Graph meta data" setting on the Facebook tab enabled if you want to optimize your site for Twitter.', 'wordpress-seo' )
);

echo '<p>';
esc_html_e( 'Enable this feature if you want Twitter to display a preview with images and a text excerpt when a link to your site is shared.', 'wordpress-seo' );
echo '</p>';

$yform->light_switch(
	'twitter',
	__( 'Twitter card meta data', 'wordpress-seo' )
);

echo '<div id="wpseo-twitter-settings">';
$yform->radio(
	'twitter_card_type',
	WPSEO_Option_Social::$twitter_card_types,
	__( 'The default card type to use', 'wordpress-seo' )
);
echo '</div>'; // twitter-settings.

echo '</div>';

do_action( 'wpseo_admin_twitter_section' );
