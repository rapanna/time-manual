<?php
/**
 * Registrace CPT `time_manual` + menu „Manuály".
 *
 * CPT je zvenčí zcela nepřístupný (public=false, publicly_queryable=false,
 * exclude_from_search=true) — žádná frontend šablona, i s přesnou URL nic.
 * Zobrazuje se pouze v adminu. Editovat/psát smí jen administrátor
 * (všechny meta-capabilities jsou mapované na `manage_options`).
 */

if (!defined('ABSPATH')) {
    exit;
}

function tman_register_cpt() {
    $labels = array(
        'name'               => _x('Manuals', 'post type general name', 'time-manual'),
        'singular_name'      => _x('Manual', 'post type singular name', 'time-manual'),
        'menu_name'          => _x('Manuals', 'admin menu', 'time-manual'),
        'name_admin_bar'     => _x('Manual', 'add new on admin bar', 'time-manual'),
        'add_new'            => __('Add New', 'time-manual'),
        'add_new_item'       => __('Add New Manual', 'time-manual'),
        'new_item'           => __('New Manual', 'time-manual'),
        'edit_item'          => __('Edit Manual', 'time-manual'),
        'view_item'          => __('View Manual', 'time-manual'),
        'all_items'          => __('All Manuals', 'time-manual'),
        'search_items'       => __('Search Manuals', 'time-manual'),
        'not_found'          => __('No manuals found.', 'time-manual'),
        'not_found_in_trash' => __('No manuals found in Trash.', 'time-manual'),
    );

    // Jen PRIMITIVNÍ capabilities mapované na manage_options → edituje jen admin.
    // Meta capabilities (edit_post/read_post/delete_post) sem NEpatří –
    // ty WP odvozuje sám přes map_meta_cap vůči konkrétnímu příspěvku.
    $admin_cap    = 'manage_options';
    $capabilities = array(
        'edit_posts'             => $admin_cap,
        'edit_others_posts'      => $admin_cap,
        'publish_posts'          => $admin_cap,
        'read_private_posts'     => $admin_cap,
        'delete_posts'           => $admin_cap,
        'delete_private_posts'   => $admin_cap,
        'delete_published_posts' => $admin_cap,
        'delete_others_posts'    => $admin_cap,
        'edit_private_posts'     => $admin_cap,
        'edit_published_posts'   => $admin_cap,
        'create_posts'           => $admin_cap,
    );

    $args = array(
        'labels'              => $labels,
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => false,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'hierarchical'        => false,
        'menu_position'       => 100,
        'menu_icon'           => 'dashicons-book-alt',
        'supports'            => array('title', 'editor'), // vědomě BEZ featured image
        'capabilities'        => $capabilities,
        'map_meta_cap'        => true,
        'show_in_rest'        => true, // kvůli blokovému editoru (editace jen admin)
    );

    register_post_type('time_manual', $args);
}
add_action('init', 'tman_register_cpt');
