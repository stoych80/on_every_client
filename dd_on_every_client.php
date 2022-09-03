<?php
/*
Plugin Name: On_every_client
Plugin URI: http://
Description: As its name says it has to be on every client. This plugin handles the connection, auth and automatic updates of our plugins and child-themes in the admin area from Bitbucket. It will show php warnings/notices only to a list of IP addresses. The IP addresses are updated every night, there is also force update for it in the Admin section. Mail Queue feature.
Version: 1.0.9
Author: Stoycho Stoychev
Depends: 
Bitbucket Plugin URI:
--------------------------------------------------------------------------------
*/
defined('ABSPATH') || die('do not access this file directly');
if (!defined('RECOVERY_MODE_EMAIL')) define('RECOVERY_MODE_EMAIL', 'errors@example.com');
class dd_on_every_client {
	private static $instance;
	private static $is_admin_looking_ip_addresses_url = 'https://example.com';
	public static $is_staging;
	public static $is_admin_looking;
	public static $is_ddteam_user_loggedin;

	public function __construct() {
		dd_on_every_client::$is_staging = strpos(defined('WP_SITEURL') ? WP_SITEURL : get_site_url(), 'staging') !== false;
		$is_admin_looking_ip_addresses = get_option(__CLASS__.'_is_admin_looking_ip_addresses', []);
		dd_on_every_client::$is_admin_looking =  isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], $is_admin_looking_ip_addresses);
		if (!function_exists('is_user_logged_in')) {
			require_once ABSPATH.'wp-includes/pluggable.php';
		}
		dd_on_every_client::$is_ddteam_user_loggedin = dd_on_every_client::isDDteamUserLoggedIn();
		
		add_filter('recovery_mode_email_rate_limit', function(){return MINUTE_IN_SECONDS;});
		add_filter('is_protected_endpoint', '__return_true'); //this is for the recovery_mode_email, to send it for every section whereever the error occurs
		
		add_action(__CLASS__.'_daily_cron',array($this,__CLASS__.'_daily_cron'));
		add_action(__CLASS__.'_hourly_cron',array($this,__CLASS__.'_hourly_cron'));
		add_filter('github_updater_disable_wpcron', '__return_true');
		add_filter('auto_update_plugin', '__return_false',100);
		add_filter('auto_update_theme', '__return_false',100);
		if (!function_exists('get_plugins')) {
			require_once ABSPATH.'wp-admin/includes/plugin.php';
		}
		if (!function_exists('wp_get_themes')) {
			require_once ABSPATH.'wp-includes/theme.php';
		}
		add_filter('github_updater_set_options', array($this,'github_updater_set_options'));
		if (is_admin()) {
			add_action('admin_menu', function () {
				if (dd_on_every_client::$is_ddteam_user_loggedin) {
					add_menu_page('DD Settings', 'DD Settings', 'manage_options', __CLASS__, array($this, __CLASS__.'_settings'), plugin_dir_url(__FILE__).'images/dd_logo.png', 1);
				}
				add_submenu_page(__CLASS__, 'DD Mail Queue List', 'DD Mail Queue List', 'manage_options', __CLASS__.'_mail_queue', array($this,__CLASS__.'_mail_queue'));
			});
			add_action('admin_enqueue_scripts', function () {
				$plugin_data = get_plugin_data(__FILE__);
				wp_register_style(__CLASS__.'-admin', '/wp-content/plugins/'.__CLASS__.'/css/admin.css', array(), $plugin_data['Version']);
				wp_enqueue_style(__CLASS__.'-admin');
				wp_register_style(__CLASS__.'-jquery-ui', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css', array(), $plugin_data['Version']);
				wp_enqueue_style(__CLASS__.'-jquery-ui');
				
				wp_register_script(__CLASS__.'-admin', '/wp-content/plugins/'.__CLASS__.'/js/admin.js', array(), $plugin_data['Version']);
				wp_enqueue_script(__CLASS__.'-admin');
				
				wp_register_script(__CLASS__.'-jquery-ui', 'https://code.jquery.com/ui/1.12.1/jquery-ui.js', array(), $plugin_data['Version']);
				wp_enqueue_script(__CLASS__.'-jquery-ui');
			});
			add_action('admin_notices', array($this, 'admin_notices'));
		}
		add_action('wp_loaded', array($this, 'wp_loaded'));
		
		add_filter('login_headerurl', array($this, 'login_headerurl'));
		add_filter('login_headertext', array($this, 'login_headertext'));
		add_filter('wp_mail', array($this, 'wp_mail'),900);
		add_filter('sanitize_option_admin_email', array($this, 'sanitize_option_admin_email'),10,3);
		add_filter('wp_mail_from', array($this, 'wp_mail_from'));
		add_filter('wp_mail_from_name', array($this, 'wp_mail_from_name'));
		add_filter('user_has_cap', array($this, 'user_has_cap'),10,4);
		
		//LIMIT THE NUMBER OF LOGIN ATTEMPTS
		add_filter('authenticate', function($user, $username, $password) {return dd_on_every_client::check_if_authenticate_is_allowed($user);}, 30, 3);
		add_action('wp_login_failed', function($username) {dd_on_every_client::record_login_failed();});
		//Handle the different actions in wp-login.php
		add_action('login_form_postpass', function() {
			if (is_wp_error(($ret=dd_on_every_client::check_if_authenticate_is_allowed('',8)))) {wp_die($ret->get_error_message());}
			if (array_key_exists('post_password', $_POST)) {dd_on_every_client::record_login_failed(8);}
		});
		add_action('login_form_confirmaction', function() {
			if (is_wp_error(($ret=dd_on_every_client::check_if_authenticate_is_allowed('')))) {wp_die($ret->get_error_message());}
			if (isset($_GET['request_id']) && isset($_GET['confirm_key'])) {dd_on_every_client::record_login_failed();}
		});
		add_action('validate_password_reset', function($errors, $user) {
			if (is_wp_error(($ret=dd_on_every_client::check_if_authenticate_is_allowed('',8)))) {$errors->add('dd_on_every_client_too_many_attempted_login', $ret->get_error_message());}
			if ($errors->get_error_code()) {dd_on_every_client::record_login_failed(8);}
		},10,2);
		add_action('lostpassword_post', function($errors) {
			if (is_wp_error(($ret=dd_on_every_client::check_if_authenticate_is_allowed('',8)))) {$errors->add('dd_on_every_client_too_many_attempted_login', $ret->get_error_message());}
			dd_on_every_client::record_login_failed(8);
		});
		//Mepr support for limit the number of login attempts
		add_filter('mepr-validate-forgot-password', function($errors) {return dd_on_every_client::mepr_check_if_authenticate_is_allowed($errors);});
		add_filter('mepr-validate-login', function($errors) {return dd_on_every_client::mepr_check_if_authenticate_is_allowed($errors);});
		
		add_action("wp_ajax_dd_mail_queue_msg_get", array($this, 'dd_mail_queue_msg_get'));
		add_action("wp_ajax_nopriv_dd_mail_queue_msg_get", function() {die('allowed only when logged in');});
		add_action("wp_ajax_dd_mail_queue_msg_resend", array($this, 'dd_mail_queue_msg_resend'));
		add_action("wp_ajax_nopriv_dd_mail_queue_msg_resend", function() {die('allowed only when logged in');});
	}
	
	private static function check_if_authenticate_is_allowed($user, $number_of_attempts_to_lock_on=3) {
		$transient = get_transient('dd_on_every_client_attempted_login-'.$_SERVER['REMOTE_ADDR']);
		if ($transient && $transient >= $number_of_attempts_to_lock_on) {
			$until_stamp = get_option('_transient_timeout_' . 'dd_on_every_client_attempted_login-'.$_SERVER['REMOTE_ADDR']);
			return new WP_Error('dd_on_every_client_too_many_attempted_login',  sprintf( __( '<strong>ERROR</strong>: You have made too many attempts, you may try again in %1$s.'), human_time_diff(time(),$until_stamp)));
		}
		return $user;
	}
	private static function record_login_failed($number_of_attempts_to_lock_on=3) {
		$transient = get_transient('dd_on_every_client_attempted_login-'.$_SERVER['REMOTE_ADDR']);
		if ($transient && is_numeric($transient) && $transient>=0) {
			$transient++;
			if ($transient <= $number_of_attempts_to_lock_on) set_transient('dd_on_every_client_attempted_login-'.$_SERVER['REMOTE_ADDR'], $transient, 1200);
		} else {
			set_transient('dd_on_every_client_attempted_login-'.$_SERVER['REMOTE_ADDR'], 1, 1200);
		}
	}
	private static function mepr_check_if_authenticate_is_allowed($errors) {
		if (is_wp_error(($ret=dd_on_every_client::check_if_authenticate_is_allowed('')))) {
			$errors=[str_replace('<strong>ERROR</strong>: ', '', $ret->get_error_message())];
		} else if(!empty($errors)) {
			dd_on_every_client::record_login_failed();
		}
		return $errors;
	}
	
	public function github_updater_set_options($config) {
		$config['bitbucket_username'] = '***';
		$config['bitbucket_password'] = '***';
		
		//connect all Bitbucket themes and plugins
		$plugins     = get_plugins();
		$additions = apply_filters( 'github_updater_additions', null, $plugins, 'plugin' );
		$plugins   = array_merge( $plugins, (array) $additions );
		foreach ($plugins as $plugin) {
			if (!empty($plugin['Bitbucket Plugin URI']) && (strpos($plugin['Bitbucket Plugin URI'], '/plugins/') !== false || strpos($plugin['Bitbucket Plugin URI'], '/child-themes/') !== false)) {
				$config[$plugin['TextDomain']] = 1;
			}
		}

		$themes = wp_get_themes(['errors' => null]);
		$additions = apply_filters( 'github_updater_additions', null, $themes, 'theme' );
		foreach ($themes as $key => $theme) {
			$bitbucket_theme_uri = $theme->get('Bitbucket Theme URI');
			if (!empty($bitbucket_theme_uri) && (strpos($bitbucket_theme_uri, '/plugins/') !== false || strpos($bitbucket_theme_uri, '/child-themes/') !== false)) {
				$config[$key] = 1;
			}
		}
		return $config;
	}
	public static function update_is_admin_looking_ip_addresses() {
		//get the list of IP addresses from the txt file
		if (($ip_addresses_text = file_get_contents(dd_on_every_client::$is_admin_looking_ip_addresses_url))) {
			$lines = explode("\n",$ip_addresses_text);
			$lines = array_map('trim', $lines);
			$is_admin_looking_ip_addresses = [];
			foreach ($lines as $line) {
				if ($line && substr($line,0,1)!='#') {
					$is_admin_looking_ip_addresses[] = $line;
				}
			}
			update_option(__CLASS__.'_is_admin_looking_ip_addresses', $is_admin_looking_ip_addresses);
		} else {
			update_option(__CLASS__.'_is_admin_looking_ip_addresses', []);
		}
	}
	public function dd_on_every_client_daily_cron() {
		dd_on_every_client::update_is_admin_looking_ip_addresses();
		global $wpdb;
		$wpdb->query('DELETE FROM dd_on_every_client_mail_queue WHERE date_created < DATE_ADD(NOW(), INTERVAL -21 DAY)');
		//clear expired transients
		$results = $wpdb->get_col("SELECT option_name FROM wp_options WHERE option_name LIKE '_transient_timeout_%' AND (option_value='' OR option_value<".time().')');
		foreach ($results as $option_name) {
			$wpdb->query("DELETE FROM wp_options WHERE option_name='$option_name' OR option_name='".str_replace('timeout_', '', $option_name)."'");
		}
		if (($j=date('j'))==1 || $j==15) $wpdb->query('TRUNCATE TABLE dd_on_every_client_frontend_requests;');
		else if ($j==8 || $j==22) $wpdb->query('UPDATE dd_on_every_client_frontend_requests SET request_uri=NULL;');
	}
	public function dd_on_every_client_hourly_cron() {
		$mail_queue_hourly_rate_limit = get_option(__CLASS__.'_mail_queue_hourly_rate_limit',70);
		if ($mail_queue_hourly_rate_limit && ($remaining_emails_that_can_be_sent_for_the_current_hour = $mail_queue_hourly_rate_limit - self::get_numberof_emails_sent_in_the_last_1hour()) > 0) {
			global $wpdb;
			$results = $wpdb->get_results('SELECT * FROM dd_on_every_client_mail_queue WHERE date_sent IS NULL ORDER BY date_created LIMIT '.$remaining_emails_that_can_be_sent_for_the_current_hour);
			foreach ($results as $result) {
				$content_type = $result->content_type;
				add_filter('wp_mail_content_type',function() use($content_type) {return $content_type;});
				//add flag to bypass the wp_mail hook here
				remove_filter('wp_mail', array($this, 'wp_mail'),900);
				wp_mail(maybe_unserialize($result->to), $result->subject, $result->message, maybe_unserialize($result->headers), maybe_unserialize($result->attachments));
				add_filter('wp_mail', array($this, 'wp_mail'),900);
				$wpdb->update('dd_on_every_client_mail_queue', ['date_sent'=>date('Y-m-d H:i:s')], ['id'=>$result->id]);
			}
		}
	}
	
	
	public static function show_icon_information($with_tooltip='', $echo_or_return = 'echo') {
		ob_start(); ?>
		<img src="/<?=PLUGINDIR.'/'.__CLASS__?>/images/icon_information.gif" align="absmiddle"<?=$with_tooltip!='' ? ' title="'. esc_attr($with_tooltip).'"' : ''?> class="icon_information" />
	<?php
		$return = ob_get_contents();
		ob_end_clean();
		if ($echo_or_return == 'echo') {
			echo $return;
		} else {
			return $return;
		}
	}
	public function wp_loaded() {
		if (is_admin()) {
			if (isset($_POST['submit'])) {
				if (isset($_POST['mail_queue_hourly_rate_limit'])) { //DD Settings are submitted
					if (isset($_POST['force_update_is_admin_looking_ip_addresses']) && $_POST['force_update_is_admin_looking_ip_addresses']==1) {
						dd_on_every_client::update_is_admin_looking_ip_addresses();
					}
					$errors = [];$fields_to_pass_string = '';
					$is_staging_visible_only_to_is_admin_looking_ip_addresses = isset($_POST['is_staging_visible_only_to_is_admin_looking_ip_addresses']) ? $_POST['is_staging_visible_only_to_is_admin_looking_ip_addresses'] : 0;
					$fields_to_pass_string .= '&fields_to_pass[is_staging_visible_only_to_is_admin_looking_ip_addresses]='.urlencode($is_staging_visible_only_to_is_admin_looking_ip_addresses);
					$extra_ip_addresses_staging_is_visible_for =isset($_POST['extra_ip_addresses_staging_is_visible_for']) ? stripslashes($_POST['extra_ip_addresses_staging_is_visible_for']) : '';
					$fields_to_pass_string .= '&fields_to_pass[extra_ip_addresses_staging_is_visible_for]='.urlencode($extra_ip_addresses_staging_is_visible_for);
					$disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours = isset($_POST['disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours']) && $_POST['disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours']==1 ? time() : null;
					$enable_code_termination_with_error_on_php_warnings_notices = isset($_POST['enable_code_termination_with_error_on_php_warnings_notices']) && $_POST['enable_code_termination_with_error_on_php_warnings_notices']==1 ? $_POST['enable_code_termination_with_error_on_php_warnings_notices'] : 0;
					$fields_to_pass_string .= '&fields_to_pass[enable_code_termination_with_error_on_php_warnings_notices]='.$enable_code_termination_with_error_on_php_warnings_notices;
					$remove_plugins_access_to_non_dd_users = isset($_POST['remove_plugins_access_to_non_dd_users']) && $_POST['remove_plugins_access_to_non_dd_users']==1 ? $_POST['remove_plugins_access_to_non_dd_users'] : 0;
					$fields_to_pass_string .= '&fields_to_pass[remove_plugins_access_to_non_dd_users]='.$remove_plugins_access_to_non_dd_users;
					$mail_queue_hourly_rate_limit = $_POST['mail_queue_hourly_rate_limit'];
					if ($mail_queue_hourly_rate_limit!='' && (!is_numeric($mail_queue_hourly_rate_limit) || $mail_queue_hourly_rate_limit < 1)) {
						$errors[] = '"Mail Queue hourly rate limit" '.$mail_queue_hourly_rate_limit.' is not a positive number';
					}
					$fields_to_pass_string .= '&fields_to_pass[mail_queue_hourly_rate_limit]='.urlencode($mail_queue_hourly_rate_limit);
					if ($errors) {
						wp_safe_redirect('/wp-admin/admin.php?page='.__CLASS__.'&msg_type_dd_on_every_client=error&msg_dd_on_every_client='.urlencode('<ul><li>'.implode('</li><li>', $errors).'</li></ul>').$fields_to_pass_string);exit;
					}
					update_option(__CLASS__.'_is_staging_visible_only_to_is_admin_looking_ip_addresses', $is_staging_visible_only_to_is_admin_looking_ip_addresses);
					update_option(__CLASS__.'_extra_ip_addresses_staging_is_visible_for', $extra_ip_addresses_staging_is_visible_for ? array_map('trim',explode("\n",$extra_ip_addresses_staging_is_visible_for)) : []);
					if ($disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours)
						update_option(__CLASS__.'_disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours', $disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours);
					update_option(__CLASS__.'_enable_code_termination_with_error_on_php_warnings_notices', $enable_code_termination_with_error_on_php_warnings_notices);
					update_option(__CLASS__.'_remove_plugins_access_to_non_dd_users', $remove_plugins_access_to_non_dd_users);
					update_option(__CLASS__.'_mail_queue_hourly_rate_limit', round($mail_queue_hourly_rate_limit));
					wp_safe_redirect('/wp-admin/admin.php?page='.__CLASS__.'&msg_dd_on_every_client='.urlencode('DD Settings have been saved'));exit;
				}
			}
			if (isset($_GET['dd_on_every_client_check_for_db_upgrade']) && $_GET['dd_on_every_client_check_for_db_upgrade']==1 && dd_on_every_client::check_compatibility_version()) {
				wp_safe_redirect('/wp-admin/admin.php?page=dd_on_every_client&msg_dd_on_every_client='.urlencode('dd_on_every_client DB has been upgraded.'));exit;
			}
		}
	}
	
	public static function get_is_staging_visible_only_to_is_admin_looking_ip_addresses() {
		return get_option(__CLASS__.'_is_staging_visible_only_to_is_admin_looking_ip_addresses',0);
	}
	public static function get_remaining_seconds_for_disabled_php_warnings_notices_for_is_admin_looking_ip_addresses() {
		if (($stamp = get_option(__CLASS__.'_disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours'))) {
			return 60*60*12 - (time() - $stamp);
		}
	}
	public static function are_php_warnings_notices_for_is_admin_looking_ip_addresses_enabled() {
		if (($remaining_seconds = dd_on_every_client::get_remaining_seconds_for_disabled_php_warnings_notices_for_is_admin_looking_ip_addresses())) {
			return $remaining_seconds < 0;
		}
		return true;
	}
	public function dd_on_every_client_settings() {
		if (isset($_GET['fields_to_pass']['mail_queue_hourly_rate_limit'])) {
			$is_staging_visible_only_to_is_admin_looking_ip_addresses = isset($_GET['fields_to_pass']['is_staging_visible_only_to_is_admin_looking_ip_addresses']) ? $_GET['fields_to_pass']['is_staging_visible_only_to_is_admin_looking_ip_addresses'] : 0;
			$extra_ip_addresses_staging_is_visible_for = isset($_GET['fields_to_pass']['extra_ip_addresses_staging_is_visible_for']) ? $_GET['fields_to_pass']['extra_ip_addresses_staging_is_visible_for'] : '';
			$enable_code_termination_with_error_on_php_warnings_notices = isset($_GET['fields_to_pass']['enable_code_termination_with_error_on_php_warnings_notices']) ? $_GET['fields_to_pass']['enable_code_termination_with_error_on_php_warnings_notices'] : 0;
			$remove_plugins_access_to_non_dd_users = isset($_GET['fields_to_pass']['remove_plugins_access_to_non_dd_users']) ? $_GET['fields_to_pass']['remove_plugins_access_to_non_dd_users'] : 0;
			$mail_queue_hourly_rate_limit = $_GET['fields_to_pass']['mail_queue_hourly_rate_limit'];
		} else {
			$is_staging_visible_only_to_is_admin_looking_ip_addresses = dd_on_every_client::get_is_staging_visible_only_to_is_admin_looking_ip_addresses();
			if (($extra_ip_addresses_staging_is_visible_for = get_option(__CLASS__.'_extra_ip_addresses_staging_is_visible_for',[]))) $extra_ip_addresses_staging_is_visible_for = implode("\n", $extra_ip_addresses_staging_is_visible_for); else $extra_ip_addresses_staging_is_visible_for = '';
			$enable_code_termination_with_error_on_php_warnings_notices = get_option(__CLASS__.'_enable_code_termination_with_error_on_php_warnings_notices');
			$remove_plugins_access_to_non_dd_users = get_option(__CLASS__.'_remove_plugins_access_to_non_dd_users');
			$mail_queue_hourly_rate_limit = get_option(__CLASS__.'_mail_queue_hourly_rate_limit',70);
		} ?>
		<h2 style="margin-top: 25px;margin-left:15px;">DD Settings</h2>
		<div class="<?=__CLASS__?>-wrapper">
			<form method="post" action="" class="<?=__CLASS__?>-settings-admin-form" autocomplete="off">
			<div class="container">
				<div class="row">
					<div class="col-sm-4" style="vertical-align: top;"><b>Is admin looking IP addresses</b><?php self::show_icon_information('i.e. php warnings/notices will be shown to this list of IP addresses.'); ?></div>
					<div class="col-sm-8">
						<?php
						if (($is_admin_looking_ip_addresses = get_option(__CLASS__.'_is_admin_looking_ip_addresses', []))) {
							echo implode('<br>', $is_admin_looking_ip_addresses);
						} else echo 'None';
						?>
						<br><br><label><input type="checkbox" name="force_update_is_admin_looking_ip_addresses" value="1" /> <i>Force update is admin looking IP addresses</i></label>
						<br><br>if the staging system is down for your IP address, click <a href="<?=get_site_url().'/?force_update_is_admin_looking_ip_addresses=1';?>" target="_blank">here</a> to force update your IP address (after you have added it in <a href="<?=dd_on_every_client::$is_admin_looking_ip_addresses_url?>" target="_blank"><?=dd_on_every_client::$is_admin_looking_ip_addresses_url?></a>).
					</div>
				</div>
				<?php if (dd_on_every_client::$is_staging) { ?>
				<div class="row">
					<div class="col-sm-4" style="vertical-align: top;"><b>Is staging visible only to "Is admin looking IP addresses"</b><?php self::show_icon_information('if unticked staging will be visible to everyone. if ticked it will be visible only to the "Is admin looking IP addresses" above + the "Extra IP Addresses staging is visible for" below.'); ?></div>
					<div class="col-sm-8"><input type="checkbox" name="is_staging_visible_only_to_is_admin_looking_ip_addresses"<?=$is_staging_visible_only_to_is_admin_looking_ip_addresses==1 ? ' checked' : ''?> value="1" /><hr>Extra IP Addresses staging is visible for (new line each)<br><textarea name="extra_ip_addresses_staging_is_visible_for" style="min-width:270px;height:80px;"><?=$extra_ip_addresses_staging_is_visible_for?></textarea></div>
				</div>
				<?php } ?>
				<div class="row">
					<div class="col-sm-4" style="vertical-align: top;"><b>Disable php warnings/notices for "Is admin looking IP addresses" in the next 12 hours.</b><?php self::show_icon_information('By default php warnings/notices are shown for "Is admin looking IP addresses". Tick this checkbox to disable them in the next 12 hours.'); ?></div>
					<div class="col-sm-8"><input type="checkbox" name="disable_php_warnings_notices_for_is_admin_looking_ip_addresses_in_the_next_12_hours" value="1" /><br>
					currently php warnings/notices are <b><?=dd_on_every_client::are_php_warnings_notices_for_is_admin_looking_ip_addresses_enabled() ? 'enabled' : 'disabled'?></b> for "Is admin looking IP addresses".
					<?php
					if (($remaining_seconds = dd_on_every_client::get_remaining_seconds_for_disabled_php_warnings_notices_for_is_admin_looking_ip_addresses()) && $remaining_seconds>0) {
						$s = $remaining_seconds%60;
						$m = floor(($remaining_seconds%3600)/60);
						$h = floor(($remaining_seconds%86400)/3600);
//						$d = floor(($remaining_seconds%2592000)/86400);
//						$M = floor($remaining_seconds/2592000);
						echo '<br>Remaining time to be disabled: <b>'."$h hours : $m minutes : $s seconds</b>";
					}
					?>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-4" style="vertical-align: top;"><b>Enable code termination with error on php warnings/notices for "Is admin looking IP addresses".</b><?php self::show_icon_information('By default php warnings/notices only show a message and allow the code to continue execution. Tick this checkbox to enable it for "Is admin looking IP addresses".'); ?></div>
					<div class="col-sm-8"><input type="checkbox" name="enable_code_termination_with_error_on_php_warnings_notices" value="1"<?=$enable_code_termination_with_error_on_php_warnings_notices ? ' checked' :''?> /><br>
					<span style="color:red;">WARNING: you may end up with not being able to access the system as a user if there are too many php warnings/notices. If this happens change the option 'dd_on_every_client_enable_code_termination_with_error_on_php_warnings_notices' to 0 in the DB table `wp_options`.</span>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-4" style="vertical-align: top;"><b>Remove plugins access to non DD users</b><?php self::show_icon_information('DD users are those whose email ends with @example.com. If ticked, only they will have access to the plugins in the system.'); ?></div>
					<div class="col-sm-8">
						<input type="checkbox" name="remove_plugins_access_to_non_dd_users" value="1"<?=$remove_plugins_access_to_non_dd_users ? ' checked' :''?> />
					</div>
				</div>
				<div class="row">
					<div class="col-sm-4" style="vertical-align: top;"><b>Mail Queue hourly rate limit</b><?php self::show_icon_information('i.e. Maximum how many emails per hour to be sent out. This is to avoid the hosting provider email restriction. Leave empty to disable.'); ?></div>
					<div class="col-sm-8">
						<input type="number" name="mail_queue_hourly_rate_limit" value="<?=$mail_queue_hourly_rate_limit?>" />
					</div>
				</div>
				<div class="row">
					<div class="col-sm-4"></div>
					<div class="col-sm-8"><input type="submit" name="submit" class="button button-primary button dd_on_every_client-large-button" value="Save" /></div>
				</div>
			</div>
			</form>
		</div>
	<?php
	}
	public function dd_on_every_client_mail_queue() { ?>
		<script type="text/javascript">
		jQuery(function ($) {
			$(document).on('click','#dd_mail_queue_item_resend_button',function() {
				var forid = $(this).attr('forid');
				$('#dd_mail_queue_item_resend_resp').html('<img src="/wp-admin/images/spinner.gif" align="absmiddle" />');
				$.ajax({
					url:'<?=admin_url('admin-ajax.php');?>',
					type:'POST',
					cache : false,
					dataType:'html',
					data : {
						action:'dd_mail_queue_msg_resend',
						forid:forid,
						resend_to:$('#dd_mail_queue_item_resend_to').val(),
						nonce:'<?=wp_create_nonce('***'.get_current_user_id().date('Y-m-d'));?>'
					},
					success : function(data) {
						$('#dd_mail_queue_item_resend_resp').html(data);
					}
				});
			});
			$('.dd_mail_queue_item_button_message').click(function(e) {
				var forid = $(this).attr('forid'), forto = $(this).attr('forto'), forsubject = $(this).attr('forsubject');
				$('<div></div>').appendTo('body').html('<div style="margin-bottom:12px;border-bottom:2px dashed #BBB;">Re-Send To:<span style="color:red;">*</span> <input type="email" id="dd_mail_queue_item_resend_to" value="'+forto+'" style="min-width:290px;" /> <input type="button" id="dd_mail_queue_item_resend_button" value="Re-Send" style="cursor:pointer;" forid="'+forid+'" /> <span id="dd_mail_queue_item_resend_resp"></span></div><div id="dd_mail_queue_msg_loader"><img src="/wp-admin/images/spinner-2x.gif" align="absmiddle" /></div>').dialog({
					modal: true,
					title: 'To email "'+forto+'", subject "'+forsubject+'"',
					zIndex: 10000,
					autoOpen: true,
					width:'85%',
					height:'530',
					position:{'my':'left','at':'right'},
					resizable: false,
					closeOnEscape: true,
					buttons: {
					  Close: function() {
						$(this).dialog("close");
					  }
					},
					close: function() {$(this).dialog('destroy').remove();}
				});
				$.ajax({
					url:'<?=admin_url('admin-ajax.php');?>',
					type:'POST',
					cache : false,
					dataType:'html',
					data : {
						action:'dd_mail_queue_msg_get',
						forid:forid,
						nonce:'<?=wp_create_nonce('***'.get_current_user_id().date('Y-m-d'));?>'
					},
					success : function(data) {
						$('#dd_mail_queue_msg_loader').html(data);
					}
				});
			});
			function toggleFilters() {
				$('.dd_on_every_client_filters_wrapper').slideToggle(400,function () {
					$('.dd_on_every_client_filters_title h4').css('background-position-y',$(this).is(':visible') ? '49%' : '5%');
				});
			}
			<?php if (isset($_REQUEST['dd_on_every_client_filter'])) : ?>
			toggleFilters();
			<?php endif; ?>
			$('.dd_on_every_client_filters_title').click(function (e) {
				toggleFilters();
			});
			$('.dd_on_every_client_filters_date').datepicker({
				dateFormat: 'dd/mm/yy',
				changeMonth : true,
				changeYear : true
			});
			$('#date_sent_filter_operator').change(function (e) {
				if ($(this).val() == 'NOT SENT YET') {
					$(this).next('#date_sent_filter_dates_wrapper').slideUp();
				} else $(this).next('#date_sent_filter_dates_wrapper').slideDown();
			});
		});
		</script>
		<style>
			.dd_on_every_client_filters_title {background-color: #EAEAEA;padding: 10px;border: solid 1px #CCC;border-radius:4px;cursor:pointer;}
			.dd_on_every_client_filters_wrapper {background-color: #EAEAEA;border:solid 1px #CCC;padding:0px 15px 15px 15px;display: none;overflow:auto;}
			.dd_on_every_client_filters_wrapper input, .dd_on_every_client_filters_wrapper select {border:solid 1px #AAA;margin: 0px;}
			.dd_on_every_client_filters_wrapper input[type="text"],.dd_on_every_client_filters_wrapper input[type="email"],.dd_on_every_client_filters_wrapper input[type="number"], .dd_on_every_client_filters_wrapper select {width:250px;}
			.dd_on_every_client_filters_wrapper input[type="text"].dd_on_every_client_filters_date {width:120px;}
			.dd_on_every_client_filters_wrapper select {max-width:200px;}
			.dd_on_every_client_filters_wrapper .asmContainer {display: inline;}
			.dd_on_every_client_filters_title h4 {font-size: 1.2em;margin: 0px;padding-left:19px;background-image: url(/wp-includes/images/toggle-arrow.png);background-repeat: no-repeat;background-position-y:5%;}
			.row .celll {vertical-align: top;}
			.row .celll.labl {text-align:right;}
		</style>
		<div id="html_wrappers" style="margin-top:15px;">
			<h3>Mail queue list</h3>
			<div class="dd_on_every_client_filters_title"><h4>Filters</h4></div>
			<div class="dd_on_every_client_filters_wrapper">
				<form action="" method="get">
					<input type="hidden" name="page" value="dd_on_every_client_mail_queue" />
					<div class="container" style="width:100%;">
					<div class="row">
						<div class="col-sm-1 celll labl"><b>Date created:</b></div>
						<div class="col-sm-5 celll"><select name="dd_on_every_client_filter[date_created][operator]" style="width:105px;">
							<option value="BETWEEN"<?=isset($_REQUEST['dd_on_every_client_filter']['date_created']['operator']) && $_REQUEST['dd_on_every_client_filter']['date_created']['operator']=='BETWEEN' ? ' selected' : ''?>>BETWEEN</option>
							</select> <input type="text" autocomplete="off" class="dd_on_every_client_filters_date" name="dd_on_every_client_filter[date_created_1][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['date_created_1']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['date_created_1']['value'])) : ''?>" /> &amp; <input type="text" autocomplete="off" class="dd_on_every_client_filters_date" name="dd_on_every_client_filter[date_created_2][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['date_created_2']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['date_created_2']['value'])) : ''?>" /></div>
						<div class="col-sm-1 celll labl"><b>Date sent:</b></div>
						<div class="col-sm-5 celll"><select id="date_sent_filter_operator" name="dd_on_every_client_filter[date_sent][operator]" style="width:105px;">
							<option value="BETWEEN"<?=isset($_REQUEST['dd_on_every_client_filter']['date_sent']['operator']) && $_REQUEST['dd_on_every_client_filter']['date_sent']['operator']=='BETWEEN' ? ' selected' : ''?>>BETWEEN</option>
							<option value="NOT SENT YET"<?=isset($_REQUEST['dd_on_every_client_filter']['date_sent']['operator']) && $_REQUEST['dd_on_every_client_filter']['date_sent']['operator']=='NOT SENT YET' ? ' selected' : ''?>>NOT SENT YET</option>
							</select> <span id="date_sent_filter_dates_wrapper"><input type="text" autocomplete="off" class="dd_on_every_client_filters_date" name="dd_on_every_client_filter[date_sent_1][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['date_sent_1']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['date_sent_1']['value'])) : ''?>" /> &amp; <input type="text" autocomplete="off" class="dd_on_every_client_filters_date" name="dd_on_every_client_filter[date_sent_2][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['date_sent_2']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['date_sent_2']['value'])) : ''?>" /></span></div>
					</div>
					<div class="row">
						<div class="col-sm-1 celll labl"><b>Subject:</b></div>
						<div class="col-sm-5 celll"><select name="dd_on_every_client_filter[subject][operator]" style="width:105px;">
							<option value="CONTAINS"<?=isset($_REQUEST['dd_on_every_client_filter']['subject']['operator']) && $_REQUEST['dd_on_every_client_filter']['subject']['operator']=='CONTAINS' ? ' selected' : ''?>>CONTAINS</option>
							<option value="NOT CONTAINS"<?=isset($_REQUEST['dd_on_every_client_filter']['subject']['operator']) && $_REQUEST['dd_on_every_client_filter']['subject']['operator']=='NOT CONTAINS' ? ' selected' : ''?>>NOT CONTAINS</option>
							</select> <input type="text" name="dd_on_every_client_filter[subject][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['subject']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['subject']['value'])) : ''?>" /></div>
						<div class="col-sm-1 celll labl"><b>Message:</b></div>
						<div class="col-sm-5 celll"><select name="dd_on_every_client_filter[message][operator]" style="width:105px;">
							<option value="CONTAINS"<?=isset($_REQUEST['dd_on_every_client_filter']['message']['operator']) && $_REQUEST['dd_on_every_client_filter']['message']['operator']=='CONTAINS' ? ' selected' : ''?>>CONTAINS</option>
							<option value="NOT CONTAINS"<?=isset($_REQUEST['dd_on_every_client_filter']['message']['operator']) && $_REQUEST['dd_on_every_client_filter']['message']['operator']=='NOT CONTAINS' ? ' selected' : ''?>>NOT CONTAINS</option>
							</select> <input type="text" name="dd_on_every_client_filter[message][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['message']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['message']['value'])) : ''?>" /></div>
					</div>
					<div class="row">
						<div class="col-sm-1 celll labl"><b>"To":</b></div>
						<div class="col-sm-5 celll"><select name="dd_on_every_client_filter[to][operator]" style="width:105px;">
							<option value="CONTAINS"<?=isset($_REQUEST['dd_on_every_client_filter']['to']['operator']) && $_REQUEST['dd_on_every_client_filter']['to']['operator']=='CONTAINS' ? ' selected' : ''?>>CONTAINS</option>
							<option value="NOT CONTAINS"<?=isset($_REQUEST['dd_on_every_client_filter']['to']['operator']) && $_REQUEST['dd_on_every_client_filter']['to']['operator']=='NOT CONTAINS' ? ' selected' : ''?>>NOT CONTAINS</option>
							</select> <input type="text" name="dd_on_every_client_filter[to][value]" value="<?=isset($_REQUEST['dd_on_every_client_filter']['to']['value']) ? esc_attr(stripslashes($_REQUEST['dd_on_every_client_filter']['to']['value'])) : ''?>" /></div>
						<div class="col-sm-1"></div>
						<div class="col-sm-5">
						<a href="/wp-admin/admin.php?page=dd_on_every_client_mail_queue">Clear</a> &nbsp;&nbsp;<input type="submit" name="submit" value="Submit" style="margin-left: 0px;margin-top:10px;font-size:120%;cursor:pointer;" />
						</div>
					</div>
					</div>
				</form>
			</div><br>
			<form method="post" action="">
			<div style="color:red;">Items here will be kept for upto 3 weeks from "Date created"</div>
				<?php
				require_once dirname(__FILE__).'/classes_list/mail_queue_list.php';
				$mail_queue_list = new mail_queue_list();
				$mail_queue_list->prepare_items();
				$mail_queue_list->display(); ?>
			</form>
		</div>
	<?php
	}
	public function dd_mail_queue_msg_get() {
		if (!is_user_logged_in() || !current_user_can('administrator')) {
			return;
		}
		$wp_user_id = get_current_user_id();
		if (!isset($_POST['nonce']) || (!wp_verify_nonce($_POST['nonce'], '***'.$wp_user_id.date('Y-m-d')) && !wp_verify_nonce($_POST['nonce'], '***'.$wp_user_id.date('Y-m-d', strtotime('-1 day'))))) {
			die('<span style="color:red;">nonce failed.</span>');
		}
		if (!isset($_POST['forid']) || !is_numeric($_POST['forid'])) {
			die('<span style="color:red;">wrong params.</span>');
		}
		global $wpdb;
		if (!($row = $wpdb->get_row('SELECT content_type,message FROM dd_on_every_client_mail_queue WHERE id='.$_POST['forid']))) die('');
		die($row->content_type=='text/plain' ? nl2br($row->message) : $row->message);
	}
	public function dd_mail_queue_msg_resend() {
		if (!is_user_logged_in() || !current_user_can('administrator')) {
			return;
		}
		$wp_user_id = get_current_user_id();
		if (!isset($_POST['nonce']) || (!wp_verify_nonce($_POST['nonce'], '***'.$wp_user_id.date('Y-m-d')) && !wp_verify_nonce($_POST['nonce'], '***'.$wp_user_id.date('Y-m-d', strtotime('-1 day'))))) {
			die('<span style="color:red;">nonce failed.</span>');
		}
		if (!isset($_POST['forid']) || !is_numeric($_POST['forid']) || !isset($_POST['resend_to'])) {
			die('<span style="color:red;">wrong params.</span>');
		}
		if (trim($_POST['resend_to']) == '' || !is_email($_POST['resend_to'])) {
			die('<span style="color:red;font-weight:bold;">"Re-Send To" is not valid email.</span>');
		}
		global $wpdb;
		$row=$wpdb->get_row('SELECT content_type,subject,message FROM dd_on_every_client_mail_queue WHERE id='.$_POST['forid']);
		$content_type = $row->content_type;
		add_filter('wp_mail_content_type',function() use($content_type) {return $content_type;});
		wp_mail($_POST['resend_to'], $row->subject, $row->message);
		die('<span style="color:green;font-weight:bold;">The email has been successfully re-sent.</span>');
	}
	
	public function admin_notices() {
		if (isset($_REQUEST['msg_dd_on_every_client'])) {
			$class = 'notice';
			$class .= isset($_REQUEST['msg_type_dd_on_every_client']) && $_REQUEST['msg_type_dd_on_every_client']=='error' ? ' notice-error' : ' updated';
			printf('<div class="%1$s"><p>%2$s</p></div>', $class, stripslashes_deep($_REQUEST['msg_dd_on_every_client']));
		}
		$plugin_data = get_plugin_data(__FILE__);
		$db_version = get_option(__CLASS__.'_db_version','0.99.99');
		if (version_compare($db_version, $plugin_data['Version']) < 0) {
			echo '<div class="notice notice-error"><p style="font-size:130%;">Click <a href="/wp-admin/admin.php?page=dd_on_every_client&dd_on_every_client_check_for_db_upgrade=1">here</a> to check for "dd_on_every_client" DB upgrade.</p></div>';
		}
	}
	
	public function login_headerurl($login_header_url) {
		$login_header_url = '/';
		return $login_header_url;
	}
	public function login_headertext($text) {
		$text = 'Powered by My company';
		return $text;
	}
	//to find it - in /wp-includes/formatting.php search for sanitize_option_
	public function sanitize_option_admin_email($value, $option, $original_value) {
		//bypass the sanitasation for the admin_email (i.e. return the $original_value) so multiple email addresses can be added
		return $original_value;
	}
	public function wp_mail_from($from_email) {
		$from_email = get_option('admin_email');
		$from_email = explode(',',$from_email);
		return trim($from_email[0]);
	}
	public function wp_mail_from_name($from_name) {
		$from_name = get_option('blogname');
		return $from_name;
	}
	public function user_has_cap($allcaps, $caps, $args, $wp_user) {
		if (get_option(__CLASS__.'_remove_plugins_access_to_non_dd_users') && !dd_on_every_client::$is_ddteam_user_loggedin)
		foreach (['update_plugins', 'activate_plugins', 'edit_plugins', 'delete_plugins', 'install_plugins', 'update_core', 'update_themes', 'install_themes', 'delete_themes', 'edit_themes'] as $cap) {
			if (isset($allcaps[$cap])) {
				unset($allcaps[$cap]);
			}
		}
		return $allcaps;
	}
	
	public function wp_mail($atts) {
		if (empty($atts['to'])) return $atts;
		$mail_queue_hourly_rate_limit = get_option(__CLASS__.'_mail_queue_hourly_rate_limit',70);
		if ($mail_queue_hourly_rate_limit) {
			global $wpdb;
			$date_now = date('Y-m-d H:i:s');
			$date_sent = self::get_numberof_emails_sent_in_the_last_1hour() < $mail_queue_hourly_rate_limit ? $date_now : null;
			$wpdb->insert('dd_on_every_client_mail_queue', ['date_created'=>$date_now, 'date_sent'=>$date_sent, 'content_type'=>apply_filters( 'wp_mail_content_type', 'text/plain'), 'to'=>maybe_serialize($atts['to']), 'subject'=>$atts['subject'], 'message'=>$atts['message'], 'headers'=> maybe_serialize($atts['headers']), 'attachments'=> maybe_serialize($atts['attachments'])]);
			if (!$date_sent) {
				$atts['to'] = [];
			}
		}
		return $atts;
	}
	private static function get_numberof_emails_sent_in_the_last_1hour() {
		global $wpdb;
		return $wpdb->get_var("SELECT COUNT(id) FROM dd_on_every_client_mail_queue WHERE date_sent IS NOT NULL AND date_sent >= '".date('Y-m-d H:i:s', strtotime('-1 hour'))."'");
	}
	
	/**
	 * Identifies by given email or by the logged in user if he is from the dd team i.e. has email ending with @example.com
	 * @param string $user_email
	 * @return bool
	 */
	public static function isDDteamUserLoggedIn($user_email = null) {
		if ($user_email) {
			return substr($user_email, -strlen('@example.com'))=='@example.com';
		}
		return is_user_logged_in() && ($wp_user_id = get_current_user_id()) && ($wp_user = get_user_by('id', $wp_user_id)) && substr($wp_user->user_email, -strlen('@example.com'))=='@example.com';
	}
	
	public static function check_compatibility_version() {
		global $wpdb;
		$plugin_data = get_plugin_data(__FILE__);
		$db_version = get_option(__CLASS__.'_db_version','0.99.99');
		$compatibility_version_to_be_updated = false;
		while (version_compare($db_version, $plugin_data['Version']) < 0) {
			$compatibility_version_to_be_updated = true;
			if ($db_version == '0.99.99') {
				$wpdb->query('CREATE TABLE IF NOT EXISTS '.__CLASS__.'_mail_queue (
					`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`date_created` DATETIME NOT NULL,
					`date_sent` DATETIME DEFAULT NULL COMMENT \'If NULL - the email is not sent yet\',
					`content_type` VARCHAR(255) NOT NULL,
					`to` TEXT NOT NULL,
					`subject` TEXT DEFAULT NULL,
					`message` MEDIUMTEXT DEFAULT NULL,
					`headers` MEDIUMTEXT DEFAULT NULL,
					`attachments` MEDIUMTEXT DEFAULT NULL,
					PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8;');
			} else if ($db_version == '1.0.5') {
				$wpdb->query('CREATE TABLE IF NOT EXISTS dd_on_every_client_frontend_requests (
					`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					`ip_address` VARCHAR(255) NOT NULL,
					`number_of_requests` INT UNSIGNED NOT NULL,
					`timestamp_requests_initiated` VARCHAR(255) NOT NULL,
					`on_hold` TINYINT(1) UNSIGNED DEFAULT NULL,
					PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8;');
			} else if ($db_version == '1.0.7') {
				$wpdb->query('ALTER TABLE dd_on_every_client_frontend_requests ADD request_uri MEDIUMTEXT DEFAULT NULL;');
			}
			$db_version=dd_on_every_client::increment_version($db_version);
		}
		if ($compatibility_version_to_be_updated) update_option(__CLASS__.'_db_version', $plugin_data['Version']);
		return $compatibility_version_to_be_updated;
	}
	public static function increment_version($version) {
		$parts = explode('.', $version);
		if ($parts[2] + 1 < 99) {
			$parts[2]++;
		} else {
			$parts[2] = 0;
			if ($parts[1] + 1 < 99) {
				$parts[1]++;
			} else {
				$parts[1] = 0;
				$parts[0]++;
			}
		}
		return implode('.', $parts);
	}
	
	/** Singleton instance */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

register_activation_hook(__FILE__, function () {
	if (!wp_next_scheduled('dd_on_every_client_daily_cron')) {
		wp_schedule_event(strtotime(rand(0,6).':'.rand(1,28).':00'), 'daily', 'dd_on_every_client_daily_cron');
	}
	if (!wp_next_scheduled('dd_on_every_client_hourly_cron')) {
		wp_schedule_event(strtotime(rand(0,6).':'.rand(31,58).':00'), 'hourly', 'dd_on_every_client_hourly_cron');
	}
	dd_on_every_client::check_compatibility_version();
});
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('dd_on_every_client_daily_cron');
	wp_clear_scheduled_hook('dd_on_every_client_hourly_cron');
});
/*
//Forbid some crawlers
if(!empty($_SERVER['HTTP_USER_AGENT']) && (
//	strpos($_SERVER['HTTP_USER_AGENT'], 'facebookexternalhit') !== false ||
	strpos($_SERVER['HTTP_USER_AGENT'], 'MoodleBot') !== false
//	|| strpos($_SERVER['HTTP_USER_AGENT'], 'Twitterbot') !== false
	|| strpos($_SERVER['HTTP_USER_AGENT'], 'SemrushBot') !== false
	|| strpos($_SERVER['HTTP_USER_AGENT'], 'bingbot') !== false
	|| strpos($_SERVER['HTTP_USER_AGENT'], 'Adsbot') !== false
	|| strpos($_SERVER['HTTP_USER_AGENT'], 'SeznamBot') !== false
	|| strpos($_SERVER['HTTP_USER_AGENT'], 'YandexBot') !== false
	|| $_SERVER['REQUEST_URI'] == '/autodiscover/autodiscover.xml'
)) {
	die('Forbidden...');
}
//if there are too many requests for a short period of time - block that IP
if (!function_exists('is_user_logged_in')) {
	require_once ABSPATH . WPINC .'/pluggable.php';
}
if (!wp_doing_ajax() && !is_admin() && !(is_user_logged_in() && current_user_can('administrator')) && !(isset($_GET['q']) && $_GET['q']=='civicrm/file' && ((isset($_GET['id']) && isset($_GET['eid']) && isset($_GET['fcs']) && is_numeric($_GET['id']) && is_numeric($_GET['eid'])) || isset($_GET['filename'])) && ((isset($_GET['page']) && $_GET['page']=='CiviCRM') || (isset($_GET['civiwp']) && $_GET['civiwp']=='CiviCRM'))) && !(isset($_GET['q']) && $_GET['q']=='civicrm/ajax/l10n-js/en_US' && ((isset($_GET['page']) && $_GET['page']=='CiviCRM') || (isset($_GET['civiwp']) && $_GET['civiwp']=='CiviCRM')))) {
	global $wpdb;
	if (($row=$wpdb->get_row("SELECT id,number_of_requests,timestamp_requests_initiated,on_hold,request_uri FROM dd_on_every_client_frontend_requests WHERE ip_address='".esc_sql($_SERVER['REMOTE_ADDR'])."'"))) {
		$arr=[];$now_stamp=time();
		if ($row->on_hold && $row->timestamp_requests_initiated >= $now_stamp-600) {
			die('You have made too many requests for a short period of time. Please wait 10 mins and refresh the page.');
		}
		if ($row->timestamp_requests_initiated >= $now_stamp-10) {
			$arr['number_of_requests']=(int)$row->number_of_requests+1;
			if ($row->number_of_requests>=9) {
				$arr['on_hold']=1;
			}
		} else {
			//the number of requests is in a fair distance, reset the initiated timestamp
			$arr['number_of_requests']=1;
			$arr['timestamp_requests_initiated']=$now_stamp;
			$arr['on_hold']=0;
		}
		$arr['request_uri'] = $row->request_uri.($row->request_uri ? "\n" : '').date('d/m/Y H:i:s').' - '.$_SERVER['REQUEST_URI'];
		$wpdb->update('dd_on_every_client_frontend_requests',$arr, ['id'=>$row->id]);
	} else {
		$wpdb->insert('dd_on_every_client_frontend_requests',['ip_address'=>$_SERVER['REMOTE_ADDR'], 'number_of_requests'=>1, 'timestamp_requests_initiated'=>time(),'request_uri'=>date('d/m/Y H:i:s').' - '.$_SERVER['REQUEST_URI']]);
	}
}*/

//if the staging system is down for your IP, use the $_GET below to force update your IP
if (isset($_GET['force_update_is_admin_looking_ip_addresses']) && $_GET['force_update_is_admin_looking_ip_addresses']==1) {
	dd_on_every_client::update_is_admin_looking_ip_addresses();
}
dd_on_every_client::get_instance();
if (dd_on_every_client::$is_staging && !dd_on_every_client::$is_admin_looking && !dd_on_every_client::$is_ddteam_user_loggedin && dd_on_every_client::get_is_staging_visible_only_to_is_admin_looking_ip_addresses() && isset($_SERVER['REMOTE_ADDR']) && !in_array($_SERVER['REMOTE_ADDR'], get_option('dd_on_every_client_extra_ip_addresses_staging_is_visible_for',[])) && !(defined('DOING_CRON') && DOING_CRON)) { // if $_SERVER['REMOTE_ADDR'] was null then the script was called from a server cron job, in this case do not end it
	wp_die('<h2 style="text-align:center;">The site is down for Maintenance</h2>');
}
if ((dd_on_every_client::$is_admin_looking || dd_on_every_client::$is_ddteam_user_loggedin) && dd_on_every_client::are_php_warnings_notices_for_is_admin_looking_ip_addresses_enabled()) {
	error_reporting(E_ALL);
	ini_set('display_errors',1);
	if (get_option('dd_on_every_client_enable_code_termination_with_error_on_php_warnings_notices')) {
		set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
			$msg = "$errStr in $errFile on line $errLine";
			if ($errNo == E_NOTICE || $errNo == E_WARNING) {
				echo '<pre>';
				var_dump($errNo==E_NOTICE ? 'E_NOTICE' : 'E_WARNING', $msg);
				exit;
			}
		});
	}
} else {
	error_reporting(E_STRICT);
	ini_set('display_errors',0);
}
if (dd_on_every_client::$is_ddteam_user_loggedin) {
	require_once 'github-updater/github-updater.php';
}