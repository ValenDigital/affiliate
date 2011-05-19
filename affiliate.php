<?php
/*
Plugin Name: Affiliate
Plugin URI: http://premium.wpmudev.org/project/wordpress-mu-affiliate
Description: This plugin adds a simple affiliate system to your site.
Author: Barry
Version: 2.3
Author URI: http://incsub.com
WDP ID: 106
*/
// Uncomment to have the system check all pages and referrers
define('AFFILIATE_CHECKALL', 'yes');
// Uncomment to have the system set a 'browser-session' cookie if no referrer is found - this reduces server load
// and is recommended if the above setting is un-commented
define('AFFILIATE_SETNOCOOKIE', 'yes');
// Pay the affiliate only once
define('AFFILIATE_PAYONCE', 'yes');


// Front end and reporting part of the affiliate system
class affiliate {

	var $build = 1;

	var $db;

	// The page on the public side of the site that has details of the affiliate plan
	var $affiliateinformationpage = 'affiliates';

	var $affiliatedata = '';
	var $affiliatereferrers = '';

	var $mylocation = "";
	var $plugindir = "";
	var $base_uri = '';

	var $onmu = false;

	function __construct() {

		global $wpdb;

		// Grab our own local reference to the database class
		$this->db =& $wpdb;

		$this->detect_location(1);

		if(function_exists('is_plugin_active_for_network') && is_plugin_active_for_network('affiliate/affiliate.php')) {
			// we're activated site wide
			$this->affiliatedata = $this->db->base_prefix . 'affiliatedata';
			$this->affiliatereferrers = $this->db->base_prefix . 'affiliatereferrers';
		} else {
			if(defined('AFFILIATE_USE_BASE_PREFIX_IF_EXISTS') && AFFILIATE_USE_BASE_PREFIX_IF_EXISTS == 'yes' && !empty($this->db->base_prefix)) {
				$this->affiliatedata = $this->db->base_prefix . 'affiliatedata';
				$this->affiliatereferrers = $this->db->base_prefix . 'affiliatereferrers';
			} else {
				// we're only activated on a blog level so put the admin menu in the main area
				$this->affiliatedata = $this->db->prefix . 'affiliatedata';
				$this->affiliatereferrers = $this->db->prefix . 'affiliatereferrers';
			}

		}

		register_activation_hook(__FILE__, array(&$this, 'install'));

		add_action( 'init', array(&$this, 'handle_affiliate_link' ) );

		// Global generic functions
		add_action('affiliate_click', array(&$this, 'record_click'), 10, 1);
		add_action('affiliate_signup', array(&$this, 'record_signup'), 10);
		add_action('affiliate_purchase', array(&$this, 'record_complete'), 10, 2);

		add_action('affiliate_credit', array(&$this, 'record_credit'), 10, 2);
		add_action('affiliate_debit', array(&$this, 'record_debit'), 10, 2);

		add_action('affiliate_referrer', array(&$this, 'record_referrer'), 10, 2);

		// Include affiliate plugins
		$thedir = affiliate_dir('/affiliateincludes/plugins');

		if ( is_dir( $thedir ) ) {
			if ( $dh = opendir( $thedir ) ) {
				$aff_plugins = array ();
				while ( ( $plugin = readdir( $dh ) ) !== false )
					if ( substr( $plugin, -4 ) == '.php' )
						$aff_plugins[] = $plugin;
				closedir( $dh );
				sort( $aff_plugins );
				foreach( $aff_plugins as $aff_plugin )
					include_once( $thedir . '/' . $aff_plugin );
			}
		}

	}

	function __destruct() {

	}

	function affiliatelite() {
		$this->__construct();
	}

	function install() {
		if($this->db->get_var( "SHOW TABLES LIKE '" . $this->affiliatedata . "' ") != $this->affiliatedata) {
			 $sql = "CREATE TABLE `" . $this->affiliatedata . "` (
			  	`user_id` bigint(20) default NULL,
			  	`period` varchar(6) default NULL,
			  	`uniques` bigint(20) default '0',
			  	`signups` bigint(20) default '0',
			  	`completes` bigint(20) default '0',
			  	`debits` decimal(10,2) default '0.00',
			  	`credits` decimal(10,2) default '0.00',
			  	`payments` decimal(10,2) default '0.00',
			  	`lastupdated` datetime default '0000-00-00 00:00:00',
			  	UNIQUE KEY `user_period` (`user_id`,`period`),
			  	KEY `period` (`period`),
			  	KEY `user_id` (`user_id`)
				)";

			$this->db->query($sql);

			$sql = "CREATE TABLE `" . $this->affiliatereferrers . "` (
			  	`user_id` bigint(20) default NULL,
			  	`period` varchar(6) default NULL,
			  	`url` varchar(250) default NULL,
			  	`referred` bigint(20) default '0',
			  	UNIQUE KEY `user_id` (`user_id`,`period`,`url`)
				)";

			$this->db->query($sql);
		}

	}

	function detect_location($level = 1) {
		$directories = explode(DIRECTORY_SEPARATOR,dirname(__FILE__));

		$mydir = array();
		for($depth = $level; $depth >= 1; $depth--) {
			$mydir[] = $directories[count($directories)-$depth];
		}

		$mydir = implode('/', $mydir);

		if($mydir == 'mu-plugins') {
			$this->mylocation = basename(__FILE__);
			$level = 0;
		} else {
			$this->mylocation = $mydir . DIRECTORY_SEPARATOR . basename(__FILE__);
		}

		if(defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $this->mylocation)) {
			$this->plugindir = WP_PLUGIN_URL;
			$this->onmu = false;
		} else {
			$this->plugindir = WPMU_PLUGIN_URL;
			$this->onmu = true;
		}

		$this->base_uri = trailingslashit($this->plugindir . '/' . $directories[count($directories)-$level]);

	}

	// Recording of affiliate information

	function record_click($user_id) {

		// Record the click in the affiliate table - v0.2+
		$period = date(Ym);

		$sql = $this->db->prepare( "INSERT INTO {$this->affiliatedata} (user_id, period, uniques, lastupdated) VALUES (%d, %s, %d, now()) ON DUPLICATE KEY UPDATE uniques = uniques + %d", $user_id, $period, 1, 1 );
		$queryresult = $this->db->query($sql);

	}

	function record_signup() {

		if(isset( $_COOKIE['affiliate_' . COOKIEHASH])) {
			// Get the cookie hash so we know who the referrer is
			$hash = addslashes($_COOKIE['affiliate_' . COOKIEHASH]);

			$user_id = $this->db->get_var( $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = 'affiliate_hash' AND meta_value = %s", $hash) );

			if($user_id) {

				$period = date(Ym);

				$sql = $this->db->prepare( "INSERT INTO {$this->affiliatedata} (user_id, period, signups, lastupdated) VALUES (%d, %s, %d, now()) ON DUPLICATE KEY UPDATE signups = signups + %d", $user_id, $period, 1, 1 );
				$queryresult = $this->db->query($sql);

				if(!defined( 'AFFILIATEID' )) define( 'AFFILIATEID', $user_id );

			}
		}

	}

	function record_complete($user_id, $amount = false) {

		if( !empty($user_id) && is_numeric($user_id) && $amount ) {

			$period = date(Ym);

			// Need to get the amount paid and calculate the commision
			$amount = number_format($amount, 2);

			$sql = $this->db->prepare( "INSERT INTO {$this->affiliatedata} (user_id, period, completes, credits, lastupdated) VALUES (%d, %s, %d, %01.2f, now()) ON DUPLICATE KEY UPDATE completes = completes + %d, credits = credits + %01.2f ", $user_id, $period, 1, $amount, 1, $amount );
			$queryresult = $this->db->query($sql);

		}

	}

	function record_credit($user_id, $amount = false) {

		if( !empty($user_id) && is_numeric($user_id) && $amount ) {

			$period = date(Ym);

			// Need to get the amount paid and calculate the commision
			$amount = number_format($amount, 2);

			$sql = $this->db->prepare( "INSERT INTO {$this->affiliatedata} (user_id, period, credits, lastupdated) VALUES (%d, %s, %01.2f, now()) ON DUPLICATE KEY UPDATE credits = credits + %01.2f ", $user_id, $period, $amount, $amount );
			$queryresult = $this->db->query($sql);

		}

	}

	function record_debit($user_id, $amount = false) {

		if( !empty($user_id) && is_numeric($user_id) && $amount ) {

			$period = date(Ym);

			// Need to get the amount paid and calculate the commision
			$amount = number_format($amount, 2);

			$sql = $this->db->prepare( "INSERT INTO {$this->affiliatedata} (user_id, period, debits, lastupdated) VALUES (%d, %s, %01.2f, now()) ON DUPLICATE KEY UPDATE debits = debits + %01.2f ", $user_id, $period, $amount, $amount );
			$queryresult = $this->db->query($sql);

		}

	}

	function record_referrer($user_id, $url = false) {

		if( !empty($user_id) && is_numeric($user_id) && $url ) {

			$period = date(Ym);

			// Need to get the amount paid and calculate the commision
			$amount = number_format($amount, 2);

			$sql = $this->db->prepare( "INSERT INTO {$this->affiliatereferrers} (user_id, period, url, referred) VALUES (%d, %s, %s, %d) ON DUPLICATE KEY UPDATE referred = referred + %d ", $user_id, $period, $url, 1, 1 );

			$queryresult = $this->db->query($sql);

		}

	}

	function handle_affiliate_link() {

		if(isset($_COOKIE['noaffiliate_' . COOKIEHASH])) {
			return true;
		}

		if(isset($_GET['ref'])) {
			// There is an affiliate type query item, check it for validity and then redirect

			if(!isset( $_COOKIE['affiliate_' . COOKIEHASH])) {
				// We haven't already been referred here by someone else - note only the first referrer
				// within a time period gets the cookie.

				// Check if the user is a valid referrer
				$affiliate = $this->db->get_var( $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = 'affiliate_reference' AND meta_value='%s'", $_GET['ref']) );
				if($affiliate) {
					// Update a quick count for this month
					do_action( 'affiliate_click', $affiliate);

					// Grab the referrer
					$referrer = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
					do_action( 'affiliate_referrer', $affiliate, $referrer );

					// Write the affiliate hash out - valid for 30 days.
					@setcookie('affiliate_' . COOKIEHASH, 'aff' . md5(AUTH_SALT . $_GET['ref']), (time() + (60*60*24*30)), COOKIEPATH, COOKIE_DOMAIN);
				}
			}

			// The cookie is set so redirect to the page called but without the ref in the url
			// for SEO reasons.
			$this->redirect( remove_query_arg( array('ref') ) );
			die();
		}

		if(defined('AFFILIATE_CHECKALL')) {
			// We are here if there isn't a reference passed, so we need to check the referrer.
			if(!isset( $_COOKIE['affiliate_' . COOKIEHASH]) && isset($_SERVER['HTTP_REFERER'])) {
				// We haven't already been referred here by someone else - note only the first referrer
				// within a time period gets the cookie.
				$referrer = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);

				// Check if the user is a valid referrer
				$affiliate = $this->db->get_var( $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = 'affiliate_referrer' AND meta_value='%s'", $referrer) );
				if($affiliate) {
					// Update a quick count for this month
					do_action( 'affiliate_click', $affiliate);
					// Store the referrer
					do_action( 'affiliate_referrer', $affiliate, $referrer );

					// Write the affiliate hash out - valid for 30 days.
					@setcookie('affiliate_' . COOKIEHASH, 'aff' . md5(AUTH_SALT . $_GET['ref']), (time() + (60*60*24*30)), COOKIEPATH, COOKIE_DOMAIN);
				} else {
					if(defined('AFFILIATE_SETNOCOOKIE')) @setcookie('noaffiliate_' . COOKIEHASH, 'notanaff', 0, COOKIEPATH, COOKIE_DOMAIN);
				}
			}
		}

	}

	function redirect($location, $status = 302) {
		// Put our own version of the redirect function here because even though the
		// proper WordPress one asks for a status code, it doesn't actually use it.

		global $is_IIS;

		$location = apply_filters('wp_redirect', $location, $status);
		$status = apply_filters('wp_redirect_status', $status, $location);

		if ( !$location ) // allows the wp_redirect filter to cancel a redirect
			return false;

		$location = wp_sanitize_redirect($location);

		if ( $is_IIS ) {
			header("Refresh: 0;url=$location", true, $status);
		} else {
			if ( php_sapi_name() != 'cgi-fcgi' )
				status_header($status); // This causes problems on IIS and some FastCGI setups
			header("Location: $location", true, $status);
		}
	}

}

require_once('affiliateincludes/includes/config.php');
require_once('affiliateincludes/includes/functions.php');
// Set up my location
set_affiliate_url(__FILE__);
set_affiliate_dir(__FILE__);

if(is_admin()) {
	// Only include the administration side of things when we need to
	include_once('affiliateincludes/classes/affiliateadmin.php');
	include_once('affiliateincludes/classes/affiliatedashboard.php');
}

// Include the new shortcodes class
include_once('affiliateincludes/classes/affiliateshortcodes.php');

$affiliate =& new affiliate();

?>