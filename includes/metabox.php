<?php
/**
 * Per-článek metabox: komu z oprávněných rolí je manuál viditelný (vrstva 2).
 *
 * Nabízí zaškrtávátka JEN rolí, které mají capability `read_time_manual`
 * (tj. jsou v allow-listu nastavení) — jde o pár rolí, ne o stovky klonů.
 * U nového článku jsou předzaškrtnuté defaulty z nastavení.
 * Uloženo jako post meta `_tman_allowed_roles` (pole slugů rolí).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Role, které lze v metaboxu nabídnout (mají capability ke čtení).
 *
 * @return array<string,string> slug => název role
 */
function tman_selectable_roles() {
    $out   = array();
    $names = wp_roles()->get_names();

    foreach (tman_get_read_roles() as $slug) {
        if (isset($names[$slug])) {
            $out[$slug] = translate_user_role($names[$slug]);
        }
    }

    return $out;
}

add_action('add_meta_boxes', function () {
    add_meta_box(
        'tman_roles_box',
        __('Role visibility', 'time-manual'),
        'tman_render_roles_metabox',
        'time_manual',
        'side',
        'default'
    );
});

function tman_render_roles_metabox($post) {
    wp_nonce_field('tman_save_roles', 'tman_roles_nonce');

    $roles = tman_selectable_roles();

    // U nového (auto-draft) článku předvyplnit defaulty z nastavení.
    $saved = get_post_meta($post->ID, TMAN_META_ROLES, true);
    if ($post->post_status === 'auto-draft' && $saved === '') {
        $selected = tman_get_read_roles();
    } else {
        $selected = is_array($saved) ? $saved : array();
    }

    if (empty($roles)) {
        echo '<p>' . esc_html__('No role is allowed to read manuals yet. Set them up in Manuals → Settings.', 'time-manual') . '</p>';
        return;
    }

    echo '<p>' . esc_html__('Select the roles that will see this manual. Nothing checked = everyone allowed will see it.', 'time-manual') . '</p>';
    echo '<ul style="margin:0">';
    // „Zaškrtnout vše" se nabízí jen když je z čeho vybírat (>1 role).
    if (count($roles) > 1) {
        printf(
            '<li style="margin-bottom:6px"><label style="font-weight:600"><input type="checkbox" id="tman-roles-all"> %s</label></li>',
            esc_html__('Select all', 'time-manual')
        );
    }
    foreach ($roles as $slug => $name) {
        printf(
            '<li><label><input type="checkbox" name="tman_allowed_roles[]" class="tman-role" value="%s" %s> %s</label></li>',
            esc_attr($slug),
            checked(in_array($slug, $selected, true), true, false),
            esc_html($name)
        );
    }
    echo '</ul>';

    if (count($roles) > 1) {
        ?>
        <script>
        (function () {
            var all   = document.getElementById('tman-roles-all');
            var roles = Array.prototype.slice.call(document.querySelectorAll('#tman_roles_box .tman-role'));
            if (!all || !roles.length) {
                return;
            }

            // Stav „zaškrtni vše" se odvozuje od rolí – i při prvním vykreslení.
            function sync() {
                var checked = roles.filter(function (cb) { return cb.checked; }).length;
                all.checked       = checked === roles.length;
                all.indeterminate = checked > 0 && checked < roles.length;
            }

            all.addEventListener('change', function () {
                roles.forEach(function (cb) { cb.checked = all.checked; });
            });
            roles.forEach(function (cb) { cb.addEventListener('change', sync); });
            sync();
        })();
        </script>
        <?php
    }
}

add_action('save_post_time_manual', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['tman_roles_nonce']) || !wp_verify_nonce($_POST['tman_roles_nonce'], 'tman_save_roles')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $raw     = isset($_POST['tman_allowed_roles']) ? (array) $_POST['tman_allowed_roles'] : array();
    $raw     = array_map('sanitize_key', $raw);
    // ponechat jen role, které jsou reálně v allow-listu
    $allowed = array_values(array_intersect($raw, tman_get_read_roles()));

    update_post_meta($post_id, TMAN_META_ROLES, $allowed);
});
