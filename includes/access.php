<?php
/**
 * Přístupová vrstva pluginu Time Manual.
 *
 * Dvouvrstvý model čtení:
 *   Vrstva 1 – capability `read_time_manual` (allow-list rolí v nastavení).
 *              Kdo cap nemá, manuály vůbec nevidí (widget, AJAX i export vrací 403).
 *   Vrstva 2 – per-článek metabox: seznam rolí (post meta `_tman_allowed_roles`),
 *              kterým je konkrétní článek zpřístupněn. Slouží k zúžení okruhu.
 *
 * Administrátor (manage_options) má vždy plný přístup a obě vrstvy obchází.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TMAN_CAP', 'read_time_manual');
define('TMAN_OPTION', 'tman_settings');
define('TMAN_META_ROLES', '_tman_allowed_roles');

/**
 * Výchozí slugy rolí, kterým se má capability přiřadit, pokud v nastavení
 * ještě nic není. Bereme jen ty, které na webu reálně existují.
 *
 * @return string[]
 */
function tman_default_read_roles() {
    $candidates = array('editor', 'sefredaktor', 'chief_editor');
    $roles      = wp_roles()->roles;
    $defaults   = array();

    foreach ($candidates as $slug) {
        if (isset($roles[$slug])) {
            $defaults[] = $slug;
        }
    }

    // Kdyby na webu nebyla ani jedna z očekávaných rolí, ať aspoň editor.
    if (empty($defaults) && isset($roles['editor'])) {
        $defaults[] = 'editor';
    }

    return $defaults;
}

/**
 * Slugy rolí, které mají mít capability ke čtení manuálů (allow-list z nastavení).
 *
 * @return string[]
 */
function tman_get_read_roles() {
    $settings = get_option(TMAN_OPTION, array());

    if (!empty($settings['read_roles']) && is_array($settings['read_roles'])) {
        return array_values(array_filter(array_map('sanitize_key', $settings['read_roles'])));
    }

    return tman_default_read_roles();
}

/**
 * Přiřadí/odebere capability `read_time_manual` napříč všemi rolemi podle
 * aktuálního allow-listu. Administrátor cap dostane vždy.
 */
function tman_sync_capabilities() {
    $allowed = tman_get_read_roles();

    foreach (wp_roles()->role_objects as $slug => $role) {
        $should_have = in_array($slug, $allowed, true) || $slug === 'administrator';

        if ($should_have) {
            $role->add_cap(TMAN_CAP);
        } elseif ($role->has_cap(TMAN_CAP)) {
            $role->remove_cap(TMAN_CAP);
        }
    }
}

/**
 * Odebere capability ze všech rolí (deaktivace pluginu).
 */
function tman_remove_all_capabilities() {
    foreach (wp_roles()->role_objects as $role) {
        if ($role->has_cap(TMAN_CAP)) {
            $role->remove_cap(TMAN_CAP);
        }
    }
}

/**
 * Slugy rolí aktuálního (nebo zadaného) uživatele.
 *
 * @param WP_User|null $user
 * @return string[]
 */
function tman_user_roles($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }

    return ($user && !empty($user->roles)) ? (array) $user->roles : array();
}

/**
 * Vrstva 1: smí uživatel manuály obecně vidět?
 *
 * @param WP_User|null $user
 * @return bool
 */
function tman_user_can_read($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }

    if (!$user || !$user->exists()) {
        return false;
    }

    return user_can($user, 'manage_options') || user_can($user, TMAN_CAP);
}

/**
 * Vrstva 2: smí uživatel vidět konkrétní článek?
 * Musí projít vrstvou 1 a zároveň mít alespoň jednu roli v allow-listu článku.
 * Prázdný allow-list článku = viditelný pro všechny, kdo projdou vrstvou 1.
 *
 * @param int          $post_id
 * @param WP_User|null $user
 * @return bool
 */
function tman_user_can_read_post($post_id, $user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }

    if (!tman_user_can_read($user)) {
        return false;
    }

    // Administrátor obchází per-článek omezení.
    if (user_can($user, 'manage_options')) {
        return true;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'time_manual' || $post->post_status !== 'publish') {
        return false;
    }

    $allowed = get_post_meta($post_id, TMAN_META_ROLES, true);
    if (empty($allowed) || !is_array($allowed)) {
        return true; // článek bez omezení
    }

    return (bool) array_intersect(tman_user_roles($user), $allowed);
}

/**
 * Publikované manuály, které smí daný uživatel vidět.
 *
 * @param WP_User|null $user
 * @return WP_Post[]
 */
function tman_readable_posts($user = null) {
    if (!tman_user_can_read($user)) {
        return array();
    }

    $posts = get_posts(array(
        'post_type'        => 'time_manual',
        'post_status'      => 'publish',
        'numberposts'      => -1,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'suppress_filters' => false,
    ));

    return array_values(array_filter($posts, function ($post) use ($user) {
        return tman_user_can_read_post($post->ID, $user);
    }));
}
