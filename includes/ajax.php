<?php
/**
 * AJAX: načtení obsahu manuálu do modalu.
 * Server VŽDY znovu ověří oprávnění (frontendu se nevěří) — nonce + obě vrstvy.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_tman_get_manual', function () {
    check_ajax_referer('tman_ajax', 'nonce');

    $post_id = isset($_POST['id']) ? absint($_POST['id']) : 0;

    if (!$post_id || !tman_user_can_read_post($post_id)) {
        wp_send_json_error(array('message' => __('You are not allowed to view this manual.', 'time-manual')), 403);
    }

    $post = get_post($post_id);

    wp_send_json_success(array(
        'title'   => get_the_title($post),
        'content' => apply_filters('the_content', $post->post_content),
    ));
});
