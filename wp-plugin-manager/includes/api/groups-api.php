<?php
/**
 * Plugin Groups (frontend rules only).
 *
 * A group is a named, reusable frontend rule set plus a list of plugin files it
 * applies to. Groups are stored inside the same option as the per-plugin rules
 * (`htpm_options['htpm_groups']`, keyed by group id) so network/site scoping,
 * the X-HTPM-Network header and the existing save helpers work unchanged.
 *
 * At runtime a plugin's effective rules are its own individual rule plus the
 * rule of every group it belongs to (see the mu-plugin): disable always wins.
 *
 * NOTE: this file is shared verbatim by the free and the pro plugin (they are
 * never loaded together), so it must only depend on helpers both define.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Free users can create this many groups; Pro is unlimited.
 */
if ( ! defined( 'HTPM_FREE_GROUP_LIMIT' ) ) {
    define( 'HTPM_FREE_GROUP_LIMIT', 2 );
}

/**
 * @return bool Whether the pro plugin is the one running.
 */
function htpm_groups_is_pro() {
    return defined( 'WP_PLUGIN_MANAGER_PRO_VERSION' ) || class_exists( 'HTPMPRO_Main' );
}

/**
 * Raw groups saved for the current request scope (network option when the
 * request is network scoped, otherwise this site's option).
 *
 * @param WP_REST_Request|null $request
 * @return array id => group
 */
function htpm_groups_get_saved( $request = null ) {
    $options = htpm_get_saved_options( $request );
    return ( isset( $options['htpm_groups'] ) && is_array( $options['htpm_groups'] ) ) ? $options['htpm_groups'] : array();
}

/**
 * Groups as enforced for a site: network groups first, a site's own group with
 * the same id replaces the network one (same precedence as plugin rules).
 *
 * @param WP_REST_Request|null $request
 * @return array id => group
 */
function htpm_groups_get_effective( $request = null ) {
    $groups = htpm_groups_get_saved( $request );

    if ( is_multisite() && ! htpm_request_is_network( $request ) ) {
        $network_options = get_site_option( 'htpm_network_options', array() );
        $network_groups  = ( isset( $network_options['htpm_groups'] ) && is_array( $network_options['htpm_groups'] ) ) ? $network_options['htpm_groups'] : array();
        if ( ! empty( $network_groups ) ) {
            $own    = $groups;
            $groups = array_replace( $network_groups, $groups );

            // A subsite can switch an inherited network group on or off for
            // itself. Only the on/off state is stored per site, never a copy of
            // the rules, so later changes made in Network Admin still reach it.
            $site_options = htpm_get_saved_options( $request );
            $switches     = ( isset( $site_options['htpm_group_status'] ) && is_array( $site_options['htpm_group_status'] ) ) ? $site_options['htpm_group_status'] : array();
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
 * Sanitises the frontend rule fields of a group. Mirrors the frontend part of
 * htpm_update_plugin_settings(), and additionally enforces the free/pro field
 * limits server-side (the UI gating alone can be bypassed).
 *
 * @param array $settings Raw rules from the request.
 * @return array
 */
function htpm_groups_sanitize_rules( $settings ) {
    $is_pro = htpm_groups_is_pro();
    $rules  = array();

    // Device
    $device = sanitize_text_field( $settings['device_type'] ?? 'all' );
    $valid_devices = array( 'all', 'desktop', 'tablet', 'mobile', 'desktop_plus_tablet', 'tablet_plus_mobile' );
    if ( ! in_array( $device, $valid_devices, true ) || ( ! $is_pro && 'all' !== $device ) ) {
        $device = 'all';
    }
    $rules['device_type'] = $device;

    // Action
    $condition = sanitize_text_field( $settings['condition_type'] ?? 'disable_on_selected' );
    if ( ! in_array( $condition, array( 'disable_on_selected', 'enable_on_selected' ), true ) || ( ! $is_pro && 'disable_on_selected' !== $condition ) ) {
        $condition = 'disable_on_selected';
    }
    $rules['condition_type'] = $condition;

    // Page type
    $uri_type  = sanitize_text_field( $settings['uri_type'] ?? 'page' );
    $valid_uri = $is_pro ? array( 'page', 'post', 'page_post', 'page_post_cpt', 'custom' ) : array( 'page', 'post', 'page_post' );
    if ( ! in_array( $uri_type, $valid_uri, true ) ) {
        $uri_type = 'page';
    }
    $rules['uri_type'] = $uri_type;

    // Post types / pages / posts
    if ( $is_pro && isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
        $rules['post_types'] = array_values( array_map( 'sanitize_text_field', $settings['post_types'] ) );
    } else {
        $rules['post_types'] = array( 'page', 'post' );
    }

    foreach ( array( 'pages', 'posts' ) as $key ) {
        $rules[ $key ] = ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) )
            ? array_values( array_map( 'sanitize_text_field', $settings[ $key ] ) )
            : array();
    }

    // Custom post type selections (Pro only)
    if ( $is_pro ) {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        foreach ( $post_types as $post_type ) {
            if ( 'page' === $post_type || 'post' === $post_type ) {
                continue;
            }
            $key = $post_type . 's';
            if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                $rules[ $key ] = array_values( array_map( 'sanitize_text_field', $settings[ $key ] ) );
            }
        }
    }

    // Custom URI conditions (Pro only, via the 'custom' page type)
    $names  = array();
    $values = array();
    if ( isset( $settings['condition_list']['name'] ) && is_array( $settings['condition_list']['name'] ) ) {
        $names  = array_values( array_map( 'sanitize_text_field', $settings['condition_list']['name'] ) );
        $values = ( isset( $settings['condition_list']['value'] ) && is_array( $settings['condition_list']['value'] ) )
            ? array_values( array_map( 'sanitize_text_field', $settings['condition_list']['value'] ) )
            : array();
    }
    // Keep both lists the same length: the mu-plugin walks them in parallel.
    $count = count( $names );
    for ( $i = 0; $i < $count; $i++ ) {
        if ( ! isset( $values[ $i ] ) ) {
            $values[ $i ] = '';
        }
    }
    $values = array_slice( $values, 0, $count );
    if ( ! $count ) {
        $names  = array( 'uri_equals' );
        $values = array( '' );
    }
    $rules['condition_list'] = array(
        'name'  => $names,
        'value' => $values,
    );

    return $rules;
}

/**
 * Keeps only plugin files that really exist and are not this plugin itself.
 *
 * @param mixed $plugins
 * @return array
 */
function htpm_groups_sanitize_plugins( $plugins ) {
    if ( ! is_array( $plugins ) ) {
        return array();
    }
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $installed = get_plugins();
    $clean     = array();
    foreach ( $plugins as $file ) {
        $file = sanitize_text_field( (string) $file );
        if ( '' === $file || ! isset( $installed[ $file ] ) ) {
            continue;
        }
        // Never let a group disable the plugin manager itself.
        if ( 0 === strpos( $file, 'wp-plugin-manager/' ) || 0 === strpos( $file, 'wp-plugin-manager-pro/' ) ) {
            continue;
        }
        $clean[ $file ] = $file;
    }
    return array_values( $clean );
}

/**
 * Builds a clean group record from request data.
 *
 * @param array  $data
 * @param string $id
 * @return array
 */
function htpm_groups_build( $data, $id ) {
    $name = sanitize_text_field( $data['name'] ?? '' );
    return array(
        'id'      => $id,
        'name'    => $name,
        'status'  => isset( $data['status'] ) ? (bool) $data['status'] : true,
        'plugins' => htpm_groups_sanitize_plugins( $data['plugins'] ?? array() ),
        'rules'   => htpm_groups_sanitize_rules( isset( $data['rules'] ) && is_array( $data['rules'] ) ? $data['rules'] : array() ),
    );
}

/**
 * Whether a group's rules only use features the free version can enforce:
 * disable-on-selected, all devices and the page/post page types. A group
 * created under Pro (enable-only, device targeting, custom post types, custom
 * URL rules) must not be applied by the free engine, which would otherwise
 * read "enable only on X" as "disable on X".
 *
 * @param array $rules
 * @return bool
 */
function htpm_groups_rules_are_free_compatible( $rules ) {
    $condition = isset( $rules['condition_type'] ) ? $rules['condition_type'] : 'disable_on_selected';
    $device    = isset( $rules['device_type'] ) ? $rules['device_type'] : 'all';
    $uri_type  = isset( $rules['uri_type'] ) ? $rules['uri_type'] : 'page';

    return 'disable_on_selected' === $condition
        && 'all' === $device
        && in_array( $uri_type, array( 'page', 'post', 'page_post' ), true );
}

/**
 * Groups the current plan can't use, id => reason. Empty on Pro.
 *  - 'limit':     beyond the free cap (kept, but neither enforced nor editable)
 *  - 'pro_rules': uses Pro-only rule features (editing it converts it to free rules)
 *
 * Order matters: the first HTPM_FREE_GROUP_LIMIT groups are the usable ones,
 * the same order the mu-plugin walks.
 *
 * @param array $groups id => group
 * @return array
 */
function htpm_groups_locked_map( $groups ) {
    $locked = array();
    if ( htpm_groups_is_pro() ) {
        return $locked;
    }
    $position = 0;
    foreach ( $groups as $id => $group ) {
        $position++;
        if ( $position > HTPM_FREE_GROUP_LIMIT ) {
            $locked[ $id ] = 'limit';
        } elseif ( ! htpm_groups_rules_are_free_compatible( isset( $group['rules'] ) ? $group['rules'] : array() ) ) {
            $locked[ $id ] = 'pro_rules';
        }
    }
    return $locked;
}

function htpm_groups_register_routes() {
    $permission = function ( $request ) {
        return htpm_check_permission( $request, 'activate_plugins' );
    };

    register_rest_route( 'htpm/v1', '/groups', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'htpm_groups_rest_list',
            'permission_callback' => $permission,
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'htpm_groups_rest_create',
            'permission_callback' => $permission,
        ),
    ) );

    register_rest_route( 'htpm/v1', '/groups/(?P<id>[A-Za-z0-9_-]+)', array(
        'methods'             => 'POST',
        'callback'            => 'htpm_groups_rest_update',
        'permission_callback' => $permission,
    ) );

    register_rest_route( 'htpm/v1', '/groups/(?P<id>[A-Za-z0-9_-]+)/status', array(
        'methods'             => 'POST',
        'callback'            => 'htpm_groups_rest_status',
        'permission_callback' => $permission,
    ) );

    register_rest_route( 'htpm/v1', '/groups/(?P<id>[A-Za-z0-9_-]+)/delete', array(
        'methods'             => 'POST',
        'callback'            => 'htpm_groups_rest_delete',
        'permission_callback' => $permission,
    ) );
}
add_action( 'rest_api_init', 'htpm_groups_register_routes' );

function htpm_groups_response( $request ) {
    $saved     = htpm_groups_get_saved( $request );
    $effective = htpm_groups_get_effective( $request );
    $locked    = htpm_groups_locked_map( $effective );
    $list      = array();
    foreach ( $effective as $id => $group ) {
        $group['id']        = $id;
        // Inherited from the Network Admin (not editable from a subsite).
        $group['inherited'] = ! isset( $saved[ $id ] );
        // Not usable on the current plan (see htpm_groups_locked_map()).
        $group['locked']    = isset( $locked[ $id ] ) ? $locked[ $id ] : '';
        $list[]             = $group;
    }
    return array(
        'success' => true,
        'data'    => $list,
        'limit'   => htpm_groups_is_pro() ? 0 : HTPM_FREE_GROUP_LIMIT, // 0 = unlimited
    );
}

function htpm_groups_rest_list( $request ) {
    return new WP_REST_Response( htpm_groups_response( $request ), 200 );
}

function htpm_groups_rest_create( $request ) {
    $data = $request->get_json_params();
    if ( ! is_array( $data ) || '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
        return new WP_Error( 'htpm_group_name', esc_html__( 'Group name is required', 'wp-plugin-manager' ), array( 'status' => 400 ) );
    }

    $options = htpm_get_saved_options( $request );
    $groups  = ( isset( $options['htpm_groups'] ) && is_array( $options['htpm_groups'] ) ) ? $options['htpm_groups'] : array();

    // On a subsite the inherited network groups use up the same free slots.
    if ( ! htpm_groups_is_pro() && count( htpm_groups_get_effective( $request ) ) >= HTPM_FREE_GROUP_LIMIT ) {
        return new WP_Error( 'htpm_group_limit', esc_html__( 'Free version supports up to 2 groups. Upgrade to Pro for unlimited groups.', 'wp-plugin-manager' ), array( 'status' => 403 ) );
    }

    // Lowercase only: ids come back through sanitize_key() in the routes.
    $id            = 'grp_' . strtolower( substr( wp_generate_password( 12, false, false ), 0, 10 ) );
    $groups[ $id ] = htpm_groups_build( $data, $id );

    $options['htpm_groups'] = $groups;
    htpm_save_options( $request, $options );

    return new WP_REST_Response( htpm_groups_response( $request ), 200 );
}

function htpm_groups_rest_update( $request ) {
    $id   = sanitize_key( $request->get_param( 'id' ) );
    $data = $request->get_json_params();

    $options = htpm_get_saved_options( $request );
    $groups  = ( isset( $options['htpm_groups'] ) && is_array( $options['htpm_groups'] ) ) ? $options['htpm_groups'] : array();

    $effective = htpm_groups_get_effective( $request );
    if ( ! isset( $groups[ $id ] ) && ! isset( $effective[ $id ] ) ) {
        return new WP_Error( 'htpm_group_not_found', esc_html__( 'Group not found', 'wp-plugin-manager' ), array( 'status' => 404 ) );
    }
    // An inherited network group is edited in Network Admin only; a subsite
    // may just switch it on or off (see htpm_groups_rest_status()).
    if ( ! isset( $groups[ $id ] ) ) {
        return new WP_Error( 'htpm_group_network', esc_html__( 'This group is managed by the network admin.', 'wp-plugin-manager' ), array( 'status' => 403 ) );
    }
    if ( ! is_array( $data ) || '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
        return new WP_Error( 'htpm_group_name', esc_html__( 'Group name is required', 'wp-plugin-manager' ), array( 'status' => 400 ) );
    }

    // A group beyond the free cap can't be changed: saving it would silently
    // rewrite its Pro rules into free ones. Delete or upgrade instead.
    $locked = htpm_groups_locked_map( $effective );
    if ( isset( $locked[ $id ] ) && 'limit' === $locked[ $id ] ) {
        return new WP_Error( 'htpm_group_locked', esc_html__( 'This group needs Pro. Upgrade to Pro, or delete other groups to use it.', 'wp-plugin-manager' ), array( 'status' => 403 ) );
    }

    $groups[ $id ]          = htpm_groups_build( $data, $id );
    $options['htpm_groups'] = $groups;
    htpm_save_options( $request, $options );

    return new WP_REST_Response( htpm_groups_response( $request ), 200 );
}

function htpm_groups_rest_delete( $request ) {
    $id = sanitize_key( $request->get_param( 'id' ) );

    $options = htpm_get_saved_options( $request );
    $groups  = ( isset( $options['htpm_groups'] ) && is_array( $options['htpm_groups'] ) ) ? $options['htpm_groups'] : array();

    if ( ! isset( $groups[ $id ] ) ) {
        if ( isset( htpm_groups_get_effective( $request )[ $id ] ) ) {
            return new WP_Error( 'htpm_group_network', esc_html__( 'This group is managed by the network admin.', 'wp-plugin-manager' ), array( 'status' => 403 ) );
        }
        return new WP_Error( 'htpm_group_not_found', esc_html__( 'Group not found', 'wp-plugin-manager' ), array( 'status' => 404 ) );
    }

    unset( $groups[ $id ] );
    $options['htpm_groups'] = $groups;
    htpm_save_options( $request, $options );

    return new WP_REST_Response( htpm_groups_response( $request ), 200 );
}

/**
 * Switch a group on or off without touching its rules. For an inherited network
 * group (subsite request) only a per-site on/off flag is stored.
 */
function htpm_groups_rest_status( $request ) {
    $id   = sanitize_key( $request->get_param( 'id' ) );
    $data = $request->get_json_params();

    if ( ! is_array( $data ) || ! array_key_exists( 'status', $data ) ) {
        return new WP_Error( 'htpm_group_status', esc_html__( 'Status is required', 'wp-plugin-manager' ), array( 'status' => 400 ) );
    }
    $status = (bool) $data['status'];

    $options = htpm_get_saved_options( $request );
    $groups  = ( isset( $options['htpm_groups'] ) && is_array( $options['htpm_groups'] ) ) ? $options['htpm_groups'] : array();

    $effective = htpm_groups_get_effective( $request );
    if ( ! isset( $effective[ $id ] ) ) {
        return new WP_Error( 'htpm_group_not_found', esc_html__( 'Group not found', 'wp-plugin-manager' ), array( 'status' => 404 ) );
    }

    // A group the current plan can't use is neither applied nor switchable.
    $locked = htpm_groups_locked_map( $effective );
    if ( isset( $locked[ $id ] ) ) {
        return new WP_Error( 'htpm_group_locked', esc_html__( 'This group needs Pro. Upgrade to Pro, or delete other groups to use it.', 'wp-plugin-manager' ), array( 'status' => 403 ) );
    }

    if ( isset( $groups[ $id ] ) ) {
        $groups[ $id ]['status'] = $status;
        $options['htpm_groups']  = $groups;
    } else {
        $switches = ( isset( $options['htpm_group_status'] ) && is_array( $options['htpm_group_status'] ) ) ? $options['htpm_group_status'] : array();
        $switches[ $id ]            = $status;
        $options['htpm_group_status'] = $switches;
    }
    htpm_save_options( $request, $options );

    return new WP_REST_Response( htpm_groups_response( $request ), 200 );
}
