<?php
/**
 * Stránka nastavení pluginu (submenu pod „Manuály").
 * Allow-list rolí (vrstva 1): které role smí manuály obecně číst.
 * Po uložení se capability `read_time_manual` synchronizuje napříč rolemi.
 * Přístup jen administrátor.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=time_manual',
        __('Manuals Settings', 'time-manual'),
        __('Settings', 'time-manual'),
        'manage_options',
        'tman-settings',
        'tman_render_settings_page'
    );
});

function tman_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'time-manual'));
    }

    // Uložení
    if (isset($_POST['tman_settings_submit'])) {
        check_admin_referer('tman_save_settings');

        $raw   = isset($_POST['tman_read_roles']) ? (array) $_POST['tman_read_roles'] : array();
        $roles = array_values(array_map('sanitize_key', $raw));
        // administrator se v allow-listu neřeší (cap dostane vždy)
        $roles = array_diff($roles, array('administrator'));

        update_option(TMAN_OPTION, array('read_roles' => $roles));
        tman_sync_capabilities();

        echo '<div class="notice notice-success is-dismissible"><p>'
           . esc_html__('Settings saved. Role capabilities have been synchronized.', 'time-manual')
           . '</p></div>';
    }

    $selected  = tman_get_read_roles();
    $all_roles = wp_roles()->get_names();
    // administrator se v allow-listu nenabízí (cap dostane vždy)
    unset($all_roles['administrator']);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Manuals Settings', 'time-manual'); ?></h1>
        <p><?php
            printf(
                /* translators: %s: Administrator role name, wrapped in <strong>. */
                esc_html__('Select the roles allowed to read internal manuals. Other roles will not see the manuals on the dashboard at all. %s always has access.', 'time-manual'),
                '<strong>' . esc_html(translate_user_role('Administrator')) . '</strong>'
            );
        ?></p>

        <form method="post">
            <?php wp_nonce_field('tman_save_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Roles with access', 'time-manual'); ?></th>
                    <td>
                        <fieldset>
                            <label class="tman-label-all" style="display:block;margin-bottom:8px;font-weight:600">
                                <input type="checkbox" id="tman-check-all">
                                <?php esc_html_e('Select all', 'time-manual'); ?>
                            </label>
                        <?php foreach ($all_roles as $slug => $name) : ?>
                            <label style="display:block;margin-bottom:4px">
                                <input type="checkbox" name="tman_read_roles[]" class="tman-read-role"
                                       value="<?php echo esc_attr($slug); ?>"
                                       <?php checked(in_array($slug, $selected, true)); ?>>
                                <?php echo esc_html(translate_user_role($name)); ?>
                                <code style="opacity:.6"><?php echo esc_html($slug); ?></code>
                            </label>
                        <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Do not try to handle a site with thousands of cloned roles here — the capability can be assigned in bulk with a one-off script.', 'time-manual'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'time-manual'), 'primary', 'tman_settings_submit'); ?>
        </form>
        <script>
        (function () {
            var all   = document.getElementById('tman-check-all');
            var roles = Array.prototype.slice.call(document.querySelectorAll('.tman-read-role'));
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
    </div>
    <?php
}
