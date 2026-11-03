<?php
/**
*Version: 1.0.12
*/

if(get_option('htpm_status') != 'active'){
	return;
}
// If the request is from cron job
if( !isset($_SERVER['HTTP_HOST']) ){
	return;
}

// Free plan: how many groups are enforced (the rest are kept but ignored).
if ( ! defined( 'HTPM_FREE_GROUP_LIMIT' ) ) {
	define( 'HTPM_FREE_GROUP_LIMIT', 2 );
}

$htpm_request_uri = !empty( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH ) : '';
$htpm_is_admin = strpos( $htpm_request_uri, '/wp-admin/' );

/**
 * Check if the current request is an AJAX request.
 * 
 * @return bool
 */
function htpmpro_doing_ajax(){
    if( isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest' ){
        return true;
    }

    return false;
}

/**
 * Deactivate plugins for non admin users (Frontend)
 */
if( !is_admin() && false === $htpm_is_admin ){
    // Deactivate plugins base on the condition meets
    add_filter( 'option_active_plugins', 'htpm_filter_plugins' );

    // Network-activated plugins load through a completely separate WordPress
    // core list (`active_sitewide_plugins`) that never intersects with the
    // per-site `active_plugins` option above, so they need their own filter
    // to be affected by the exact same rules.
    if ( is_multisite() ) {
        add_filter( 'site_option_active_sitewide_plugins', 'htpm_filter_network_active_plugins' );
    }
}

/**
 * Site rules with network-wide rules (configured once at Network Admin
 * level) as the fallback default for every subsite; a site's own rule for
 * the same plugin, if any, takes precedence over the network default.
 *
 * @return array Plugin file => settings, keyed the same as htpm_options['htpm_list_plugins'].
 */
function htpm_get_merged_rules(){
	$htpm_options = get_option( 'htpm_options' );
	$htpm_list_plugins = ( isset( $htpm_options['htpm_list_plugins'] ) ? $htpm_options['htpm_list_plugins'] : array() );

	if ( is_multisite() ) {
		$network_options = get_site_option( 'htpm_network_options', array() );
		$network_list = ( isset( $network_options['htpm_list_plugins'] ) ? $network_options['htpm_list_plugins'] : array() );
		if ( ! empty( $network_list ) ) {
			$htpm_list_plugins = array_replace( $network_list, $htpm_list_plugins );
		}
	}

	return $htpm_list_plugins;
}

/**
 * Groups (named, reusable frontend rule sets) with network groups as the
 * fallback; a site's own group with the same id replaces the network one.
 *
 * @return array id => group
 */
function htpm_get_merged_groups(){
	$htpm_options = get_option( 'htpm_options' );
	$groups = ( isset( $htpm_options['htpm_groups'] ) && is_array( $htpm_options['htpm_groups'] ) ) ? $htpm_options['htpm_groups'] : array();

	if ( is_multisite() ) {
		$network_options = get_site_option( 'htpm_network_options', array() );
		$network_groups = ( isset( $network_options['htpm_groups'] ) && is_array( $network_options['htpm_groups'] ) ) ? $network_options['htpm_groups'] : array();
		if ( ! empty( $network_groups ) ) {
			$own    = $groups;
			$groups = array_replace( $network_groups, $groups );

			// A subsite can switch an inherited network group on or off for itself.
			$switches = ( isset( $htpm_options['htpm_group_status'] ) && is_array( $htpm_options['htpm_group_status'] ) ) ? $htpm_options['htpm_group_status'] : array();
			foreach ( $switches as $switch_id => $switch_status ) {
				if ( isset( $groups[ $switch_id ] ) && ! isset( $own[ $switch_id ] ) ) {
					$groups[ $switch_id ]['status'] = (bool) $switch_status;
				}
			}
		}
	}

	return $groups;
}

/**
 * Every frontend rule that applies to each plugin: its own individual rule
 * plus the rule of each enabled group it belongs to.
 *
 * @return array Plugin file => list of rules.
 */
function htpm_get_plugin_rule_sets(){
	$rule_sets = array();

	foreach ( htpm_get_merged_rules() as $plugin => $rule ) {
		$rule_sets[ $plugin ][] = $rule;
	}

	$position = 0;
	foreach ( htpm_get_merged_groups() as $group ) {
		$position++;
		// Only the first groups are usable on the free plan; extra groups
		// (e.g. left over from Pro) stay saved but are not enforced.
		if ( $position > HTPM_FREE_GROUP_LIMIT ) {
			break;
		}
		if ( empty( $group['status'] ) || empty( $group['plugins'] ) || empty( $group['rules'] ) ) {
			continue;
		}
		$rule = $group['rules'];
		// The free engine is disable-only on all devices. A Pro-only rule
		// ("enable only on", device targeting) would be misread, so skip it.
		if ( ( isset( $rule['condition_type'] ) && 'disable_on_selected' !== $rule['condition_type'] )
			|| ( isset( $rule['device_type'] ) && 'all' !== $rule['device_type'] ) ) {
			continue;
		}
		// Groups carry only the frontend fields; mark them on so the same
		// matcher used for individual rules can evaluate them.
		$rule['enable_deactivation'] = 'yes';
		$rule['frontend_status'] = true;
		foreach ( (array) $group['plugins'] as $plugin ) {
			$rule_sets[ $plugin ][] = $rule;
		}
	}

	return $rule_sets;
}

/**
 * Works out, for the current request, which plugin files should be removed
 * based on the effective rules (individual + group). Shared by both the
 * per-site and the network-active plugin filters so the URL-matching logic
 * isn't duplicated. A plugin is removed as soon as any one of its rules
 * matches the current page. The result is memoised: the filters fire several
 * times per request.
 *
 * @return array Plugin file paths to remove.
 */
function htpm_get_plugins_to_remove(){
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	$rule_sets = htpm_get_plugin_rule_sets();

	// first plugin use, htpm_options has no data fix
	if( empty( $rule_sets ) ){
		return array();
	}

	// Don't disable any while on ajax request
	if( htpmpro_doing_ajax() ){
		return array();
	}

	$remove_plugins = array();

	// main domain
	$main_domain = get_bloginfo('url');
	$main_domain = str_replace(array('http://','https://'), '', $main_domain);

	// current page url
	$server_host = !empty($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
	$req_uri = !empty($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

	$current_page_url = $server_host . $req_uri;
	$current_page_url = trim( $current_page_url, "/" );
	$current_page_slug = trim(str_replace($main_domain, '', $current_page_url), '/');

	$page_path = $current_page_slug;
	if($main_domain === $current_page_url){
		$page_path = '/';
	}
	$page_path = explode('?', $page_path)[0];

	$context = array(
		'page_path' => $page_path,
		'is_front_page' => ( $page_path === '/' ),
		'current_page_url' => $current_page_url,
		'current_page_slug' => $current_page_slug,
	);

	// loop through each plugin that has at least one rule
	foreach($rule_sets as $plugin => $rules){
		foreach($rules as $rule){
			if( htpm_rule_matches_current_page( $rule, $context ) ){
				$remove_plugins[] = $plugin;
				break; // one matching rule is enough
			}
		}
	}

	$cached = $remove_plugins;
	return $cached;
}

/**
 * Whether one frontend rule (an individual plugin rule or a group's rule)
 * says the plugin should be removed on the current page.
 *
 * @param array $rule
 * @param array $context page_path, is_front_page, current_page_url, current_page_slug.
 * @return bool
 */
function htpm_rule_matches_current_page( $rule, $context ){
	if( ! isset($rule['enable_deactivation']) || $rule['enable_deactivation'] != 'yes' ){
		return false;
	}

	// Check frontend status
	$frontend_enabled = !isset($rule['frontend_status']) || $rule['frontend_status'] === true;
	if (!$frontend_enabled) {
		return false;
	}

	$page_path = $context['page_path'];
	$is_front_page = $context['is_front_page'];
	$current_page_url = $context['current_page_url'];
	$current_page_slug = $context['current_page_slug'];
	$uri_type = isset($rule['uri_type']) ? $rule['uri_type'] : '';

	if($uri_type == 'page'){
		$page_list = isset($rule['pages']) ? $rule['pages'] : array();
		$current_page = ( $page_path !== '/' ) ? get_page_by_path( trim($page_path, '/'), 'OBJECT', 'page' ) : null;
		if(in_array('all_pages,all_pages', $page_list) && ( $is_front_page || (!empty($current_page) && $current_page->post_status === 'publish') )){
			return true;
		}
		foreach($page_list as $page_info){
			$page_info_arr = explode(',', $page_info);
			$page_link = htpm_strip_host( isset($page_info_arr[1]) ? $page_info_arr[1] : '' );

			if( $page_link && $page_link == $page_path ){
				return true;
			}
		}
	}

	if($uri_type == 'post'){
		$post_list = isset($rule['posts']) ? $rule['posts'] : array();
		$current_page = get_page_by_path( basename($page_path), 'OBJECT', 'post' );
		if(in_array('all_posts,all_posts', $post_list) && !empty($current_page) && $current_page->post_status === 'publish'){
			return true;
		}
		foreach($post_list as $post_info){
			$post_info_arr = explode(',', $post_info);
			$post_link = htpm_strip_host( isset($post_info_arr[1]) ? $post_info_arr[1] : '' );

			if( $post_link && $post_link == $page_path ){
				return true;
			}
		}
	}

	if($uri_type == 'page_post'){
		$page_list = isset($rule['pages']) ? $rule['pages'] : array();
		$post_list = isset($rule['posts']) ? $rule['posts'] : array();
		$page_nd_post_list = array_merge($page_list, $post_list );
		$current_page = ( $page_path !== '/' ) ? get_page_by_path( trim($page_path, '/'), 'OBJECT', 'page' ) : null;
		$current_post = get_page_by_path( basename($page_path), 'OBJECT', 'post' );
		if(in_array('all_pages,all_pages', $page_nd_post_list) && ( $is_front_page || (!empty($current_page) && $current_page->post_status === 'publish') )){
			return true;
		} elseif(in_array('all_posts,all_posts', $page_nd_post_list) && !empty($current_post) && $current_post->post_status === 'publish'){
			return true;
		}
		foreach($page_nd_post_list as $post_info){
			$post_info_arr = explode(',', $post_info);
			$post_link = htpm_strip_host( isset($post_info_arr[1]) ? $post_info_arr[1] : '' );

			if( $post_link && $post_link == $page_path ){
				return true;
			}
		}
	}

	if( $uri_type == 'custom' ){
		$condition_list = !empty($rule['condition_list']) ? $rule['condition_list'] : array(
			'name' => array(),
			'value' => array()
		);

		for( $i = 0; $i < count($condition_list['name']); $i++ ){
			$name = $condition_list['name'][$i];
			$item = explode(',', $condition_list['name'][$i] . ',' . ( isset($condition_list['value'][$i]) ? $condition_list['value'][$i] : '' ));
			$value = trim(isset($item[1]) ? $item[1] : '', '/');

			if($name == 'uri_equals'){
				if($current_page_slug == $value){
					return true;
				}
			}

			if($name == 'uri_not_equals'){
				if($value && $current_page_slug != $value){
					return true;
				}
			}

			if($name == 'uri_contains'){
				if($value && strpos( $current_page_url, $value )){
					return true;
				}
			}

			if($name == 'uri_not_contains'){
				if($value && !strpos( $current_page_url, $value )){
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Strips protocol + domain from a stored "id,url" link, leaving just the
 * relative path. A page/post picked in the Select Pages/Posts dropdown is
 * stored with the domain it was configured on baked in — on a single site
 * that's always this site's own domain, but a rule configured once at
 * Network Admin level and inherited by every subsite would otherwise never
 * match, since each subsite has its own domain. Comparing paths only keeps
 * single-site matching identical (a site's own domain always matches itself)
 * while making the rule portable across a network's subsites.
 *
 * @param string $link
 * @return string
 */
function htpm_strip_host( $link ) {
	$link = str_replace( array( 'http://', 'https://' ), '', $link );
	$link = trim( $link, '/' );
	$slash_pos = strpos( $link, '/' );
	$path = ( false === $slash_pos ) ? '' : trim( substr( $link, $slash_pos + 1 ), '/' );

	// A WordPress install in a subdirectory (example.com/blog) carries that
	// directory in the stored link, but the current path this is compared
	// against is already measured relative to the site root — so drop the
	// same prefix here to keep both sides comparable.
	$base_path = trim( (string) wp_parse_url( get_bloginfo('url'), PHP_URL_PATH ), '/' );
	if ( '' !== $base_path ) {
		if ( 0 === strpos( $path, $base_path . '/' ) ) {
			$path = trim( substr( $path, strlen( $base_path ) + 1 ), '/' );
		} elseif ( $path === $base_path ) {
			$path = '';
		}
	}

	return ( '' === $path ) ? '/' : $path;
}

function htpm_filter_plugins( $plugins ){
	$remove_plugins = htpm_get_plugins_to_remove();
	if ( empty( $remove_plugins ) ) {
		return $plugins;
	}
	return array_diff( $plugins, $remove_plugins );
}

/**
 * Same rules as htpm_filter_plugins(), applied to the network-wide active
 * plugins list instead (keyed by plugin file => activation timestamp).
 *
 * @param array $plugins
 * @return array
 */
function htpm_filter_network_active_plugins( $plugins ){
	$remove_plugins = htpm_get_plugins_to_remove();
	if ( empty( $remove_plugins ) ) {
		return $plugins;
	}
	return array_diff_key( $plugins, array_flip( $remove_plugins ) );
}