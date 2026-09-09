<?php
/**
*Version: 1.0.9
*/

if(get_option('htpm_status') != 'active'){
	return;
}
// If the request is from cron job
if( !isset($_SERVER['HTTP_HOST']) ){
	return;
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
 * Works out, for the current request, which plugin files should be removed
 * based on the effective (merged) rules. Shared by both the per-site and the
 * network-active plugin filters so the URL-matching logic isn't duplicated.
 *
 * @return array Plugin file paths to remove.
 */
function htpm_get_plugins_to_remove(){
	global $htpm_request_uri;
	$htpm_options = htpm_get_merged_rules();

	// first plugin use, htpm_options has no data fix
	if( empty( $htpm_options ) ){
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

	$is_front_page = ( $page_path === '/' );

	// loop through each active plugin
	foreach($htpm_options as $plugin => $individual_options){
		if(isset($individual_options['enable_deactivation']) && $individual_options['enable_deactivation'] == 'yes'){
             // Check frontend status
        $frontend_enabled = !isset($individual_options['frontend_status']) || $individual_options['frontend_status'] === true;
        
        if (!$frontend_enabled) {
            continue; // Skip this plugin if frontend is disabled
        }
			$uri_type = $individual_options['uri_type'];

			if($uri_type == 'page'){
				$page_list = isset($individual_options['pages']) ? $individual_options['pages'] : array();
				$current_page = ( $page_path !== '/' ) ? get_page_by_path( trim($page_path, '/'), 'OBJECT', 'page' ) : null;
				if(in_array('all_pages,all_pages', $page_list) && ( $is_front_page || (!empty($current_page) && $current_page->post_status === 'publish') )){
					$remove_plugins[] = $plugin;
				} else {
					foreach($page_list as $page_info){
						$page_info_arr = explode(',', $page_info);
						$page_id = $page_info_arr[0];
						$page_link = htpm_strip_host( $page_info_arr[1] );

						if(
							$page_link && $page_link == $page_path
						){
							$remove_plugins[] = $plugin;
						}
					}
				}
			}

			if($uri_type == 'post'){
				$post_list = isset($individual_options['posts']) ? $individual_options['posts'] : array();
				$current_page = get_page_by_path( basename($page_path), 'OBJECT', 'post' );
				if(in_array('all_posts,all_posts', $post_list) && !empty($current_page) && $current_page->post_status === 'publish'){
					$remove_plugins[] = $plugin;
				} else {
					foreach($post_list as $post_info){
						$post_info_arr = explode(',', $post_info);
						$post_id = $post_info_arr[0];
						$post_link = htpm_strip_host( $post_info_arr[1] );

						if(
							$post_link && $post_link == $page_path
						){
							$remove_plugins[] = $plugin;
						}
					}
				}
			}

			if($uri_type == 'page_post'){
				$page_list = isset($individual_options['pages']) ? $individual_options['pages'] : array();
				$post_list = isset($individual_options['posts']) ? $individual_options['posts'] : array();
				$page_nd_post_list = array_merge($page_list, $post_list );
				$current_page = ( $page_path !== '/' ) ? get_page_by_path( trim($page_path, '/'), 'OBJECT', 'page' ) : null;
				$current_post = get_page_by_path( basename($page_path), 'OBJECT', 'post' );
				if(in_array('all_pages,all_pages', $page_nd_post_list) && ( $is_front_page || (!empty($current_page) && $current_page->post_status === 'publish') )){
					$remove_plugins[] = $plugin;
				} elseif(in_array('all_posts,all_posts', $page_nd_post_list) && !empty($current_post) && $current_post->post_status === 'publish'){
					$remove_plugins[] = $plugin;
				} else {
					foreach($page_nd_post_list as $post_info){
						$post_info_arr = explode(',', $post_info);
						$post_id = $post_info_arr[0];
						$post_link = htpm_strip_host( $post_info_arr[1] );

						if(
							$post_link && $post_link == $page_path
						){
							$remove_plugins[] = $plugin;
						}
					}
				}
			}

			if( $uri_type == 'custom' ){
				$condition_list = array(
					'name' => array(),
					'value' => array()
				);
            	$condition_list = $individual_options['condition_list'] ? $individual_options['condition_list'] : array(
					'name' => array(),
					'value' => array()
				);

				$individual_condition_list = array();
				for( $i = 0; $i < count($condition_list['name']); $i++ ){
					$individual_condition_list[] = $condition_list['name'][$i] . ',' . $condition_list['value'][$i];
				}

				foreach($individual_condition_list as $item){
					$item = explode(',', $item);
					$name = $item[0];
					$value = trim($item[1], '/');

					if($name == 'uri_equals'){
						if($current_page_slug == $value){
							$remove_plugins[] = $plugin;
						}
					}

					if($name == 'uri_not_equals'){
						if($value && $current_page_slug != $value){
							$remove_plugins[] = $plugin;
						}
					}

					if($name == 'uri_contains'){
						if($value && strpos( $current_page_url, $value )){
							$remove_plugins[] = $plugin;
						}
					}

					if($name == 'uri_not_contains'){
						if($value && !strpos( $current_page_url, $value )){
							$remove_plugins[] = $plugin;
						}
					}
				}
			}
		}
	}

	return $remove_plugins;
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