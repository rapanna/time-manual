<?php
/**
 * Dashboard widget na úvodní stránce adminu.
 * Vypíše jen manuály, které daný uživatel smí vidět (vrstva 1 ∧ vrstva 2).
 * Klik na položku → JS načte obsah přes AJAX do modalu.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_dashboard_setup', function () {
    if (!tman_user_can_read()) {
        return;
    }

    // Context 'side' = pravý sloupec nástěnky (WP 5.6+). Platí pro uživatele,
    // který si rozložení nástěnky ještě nepřetáhl – vlastní pořadí má přednost.
    wp_add_dashboard_widget(
        'tman_dashboard_widget',
        __('Internal Manuals', 'time-manual'),
        'tman_render_dashboard_widget',
        null,
        null,
        'side',
        'high'
    );
});

function tman_render_dashboard_widget() {
    $posts = tman_readable_posts();

    if (empty($posts)) {
        echo '<p>' . esc_html__('There are no manuals yet.', 'time-manual') . '</p>';
        return;
    }

    echo '<ul class="tman-manual-list">';
    foreach ($posts as $post) {
        printf(
            '<li><a href="#" class="tman-manual-link" data-id="%d">%s</a></li>',
            (int) $post->ID,
            esc_html(get_the_title($post))
        );
    }
    echo '</ul>';

    // Kontejner modalu (naplní JS)
    echo '<div id="tman-modal" class="tman-modal" aria-hidden="true">'
       . '  <div class="tman-modal__overlay" data-tman-close></div>'
       . '  <div class="tman-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tman-modal-title">'
       . '    <button type="button" class="tman-modal__close" data-tman-close aria-label="'
       . esc_attr__('Close', 'time-manual') . '">&times;</button>'
       . '    <h2 id="tman-modal-title" class="tman-modal__title"></h2>'
       . '    <div class="tman-modal__body"></div>'
       . '  </div>'
       . '</div>';
}

/**
 * Načíst CSS/JS jen na nástěnce a jen pro oprávněné uživatele.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'index.php' || !tman_user_can_read()) {
        return;
    }

    wp_enqueue_style(
        'tman-dashboard',
        TMAN_URL . 'assets/dashboard.css',
        array(),
        TMAN_VERSION
    );

    wp_enqueue_script(
        'tman-dashboard',
        TMAN_URL . 'assets/dashboard.js',
        array('jquery'),
        TMAN_VERSION,
        true
    );

    wp_localize_script('tman-dashboard', 'tmanData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('tman_ajax'),
        'loading' => __('Loading…', 'time-manual'),
        'error'   => __('The content could not be loaded.', 'time-manual'),
    ));
});
