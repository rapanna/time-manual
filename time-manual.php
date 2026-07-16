<?php
/**
 * Plugin Name: Time Manual
 * Plugin URI:  https://ovanet.cz
 * Description: Internal manuals (how-tos) for site editors. Articles visible only to allowed roles, available from the admin dashboard. Self-contained plugin, deployable on any WP site.
 * Version:     1.0.6
 * Author:      Radomír Panna
 * Author URI:  https://ovanet.cz
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: time-manual
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Zabránění přímému přístupu
}

define('TMAN_VERSION', '1.0.6');
define('TMAN_FILE', __FILE__);
define('TMAN_PATH', plugin_dir_path(__FILE__));
define('TMAN_URL', plugin_dir_url(__FILE__));

/**
 * Načtení překladů z /languages.
 *
 * Priorita 0 na `init`, protože CPT se registruje na `init` (10) a jeho labely
 * už překlad potřebují. Dřív než `init` to volat nelze — WP 6.7+ na to hlásí
 * _load_textdomain_just_in_time.
 */
add_action('init', function () {
    load_plugin_textdomain(
        'time-manual',
        false,
        dirname(plugin_basename(TMAN_FILE)) . '/languages'
    );
}, 0);

require_once TMAN_PATH . 'includes/access.php';
require_once TMAN_PATH . 'includes/cpt.php';
require_once TMAN_PATH . 'includes/metabox.php';
require_once TMAN_PATH . 'includes/settings.php';
require_once TMAN_PATH . 'includes/media.php';
require_once TMAN_PATH . 'includes/dashboard.php';
require_once TMAN_PATH . 'includes/ajax.php';
require_once TMAN_PATH . 'includes/export.php';
require_once TMAN_PATH . 'includes/import.php';

/**
 * Aktivace: zaregistruje CPT (kvůli capabilities) a nasype capability
 * oprávněným rolím podle nastavení (nebo defaultům).
 */
register_activation_hook(__FILE__, function () {
    tman_register_cpt();
    tman_sync_capabilities();
    flush_rewrite_rules();
});

/**
 * Deaktivace: odebere capability ze všech rolí, ať po sobě neuklízí ručně.
 */
register_deactivation_hook(__FILE__, function () {
    tman_remove_all_capabilities();
    flush_rewrite_rules();
});
