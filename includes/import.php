<?php
/**
 * Import manuálů ze ZIP (submenu „Import" pod Manuály).
 *
 * Postup pro každý manuál:
 *   1) sideload médií z media/ do knihovny cílového webu,
 *   2) přepis starých URL v obsahu na nové (dle manifestu),
 *   3) založení článku,
 *   4) povolené role nastaveny na DEFAULTY cílového webu (cizí slugy nepřenášíme).
 *
 * Přístup jen administrátor.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=time_manual',
        __('Import Manuals', 'time-manual'),
        __('Import', 'time-manual'),
        'manage_options',
        'tman-import',
        'tman_render_import_page'
    );
});

function tman_render_import_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'time-manual'));
    }

    if (isset($_POST['tman_import_submit'])) {
        check_admin_referer('tman_import');
        tman_handle_import();
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import Manuals', 'time-manual'); ?></h1>
        <p><?php esc_html_e('Upload a ZIP created by the manuals export. Media will be downloaded into this site’s library and the links in the content will be rewritten. Role visibility will be set to this site’s defaults.', 'time-manual'); ?></p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('tman_import'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tman_import_file"><?php esc_html_e('ZIP file', 'time-manual'); ?></label></th>
                    <td><input type="file" name="tman_import_file" id="tman_import_file" accept=".zip"></td>
                </tr>
            </table>
            <?php submit_button(__('Import', 'time-manual'), 'primary', 'tman_import_submit'); ?>
        </form>
    </div>
    <?php
}

function tman_handle_import() {
    if (empty($_FILES['tman_import_file']['name'])) {
        echo '<div class="notice notice-warning"><p>'
           . esc_html__('No file was selected.', 'time-manual') . '</p></div>';
        return;
    }
    if (!empty($_FILES['tman_import_file']['error'])) {
        echo '<div class="notice notice-error"><p>'
           . esc_html__('Error while uploading the file.', 'time-manual') . '</p></div>';
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    WP_Filesystem();

    $tmp_upload = $_FILES['tman_import_file']['tmp_name'];
    $work_dir   = trailingslashit(get_temp_dir()) . 'tman-import-' . wp_generate_password(8, false);

    $unzipped = unzip_file($tmp_upload, $work_dir);
    if (is_wp_error($unzipped)) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            sprintf(
                /* translators: %s: error message returned by unzip_file(). */
                esc_html__('The ZIP could not be extracted: %s', 'time-manual'),
                esc_html($unzipped->get_error_message())
            )
        );
        return;
    }

    $json_path = trailingslashit($work_dir) . 'manual.json';
    if (!file_exists($json_path)) {
        echo '<div class="notice notice-error"><p>'
           . esc_html__('The manual.json file is missing from the ZIP.', 'time-manual') . '</p></div>';
        tman_rrmdir($work_dir);
        return;
    }

    $data = json_decode(file_get_contents($json_path), true);
    if (!is_array($data) || empty($data['manuals']) || !is_array($data['manuals'])) {
        echo '<div class="notice notice-error"><p>'
           . esc_html__('manual.json is corrupted or empty.', 'time-manual') . '</p></div>';
        tman_rrmdir($work_dir);
        return;
    }

    $imported = 0;
    foreach ($data['manuals'] as $manual) {
        if (tman_import_single($manual, $work_dir)) {
            $imported++;
        }
    }

    tman_rrmdir($work_dir);

    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        sprintf(
            /* translators: %s: number of imported manuals. */
            esc_html(_n('%s manual imported.', '%s manuals imported.', $imported, 'time-manual')),
            number_format_i18n($imported)
        )
    );
}

/**
 * Import jednoho manuálu. Vrací true při úspěchu.
 *
 * @param array  $manual
 * @param string $work_dir  rozbalený obsah ZIPu
 * @return bool
 */
function tman_import_single($manual, $work_dir) {
    if (empty($manual['title']) && empty($manual['content'])) {
        return false;
    }

    $content = isset($manual['content']) ? (string) $manual['content'] : '';

    // 1) + 2) sideload médií a přepis URL
    if (!empty($manual['media']) && is_array($manual['media'])) {
        foreach ($manual['media'] as $item) {
            if (empty($item['file']) || empty($item['original_url'])) {
                continue;
            }
            $src = trailingslashit($work_dir) . $item['file'];
            if (!file_exists($src)) {
                continue;
            }

            $new_url = tman_sideload_file($src);
            if ($new_url) {
                $content = str_replace($item['original_url'], $new_url, $content);
            }
        }
    }

    // 3) založení článku
    $post_id = wp_insert_post(array(
        'post_type'    => 'time_manual',
        'post_status'  => 'publish',
        'post_title'   => isset($manual['title']) ? wp_strip_all_tags($manual['title']) : __('(no title)', 'time-manual'),
        'post_content' => $content,
    ), true);

    if (is_wp_error($post_id) || !$post_id) {
        return false;
    }

    // 4) povolené role = defaulty cílového webu (cizí slugy nepřenášíme)
    update_post_meta($post_id, TMAN_META_ROLES, tman_get_read_roles());

    return true;
}

/**
 * Sideload souboru do knihovny médií. Vrací novou URL nebo false.
 *
 * @param string $src  cesta k souboru na disku
 * @return string|false
 */
function tman_sideload_file($src) {
    // media_handle_sideload přesouvá tmp_name → zkopírujeme, ať nesaháme na originál v ZIPu.
    $tmp = wp_tempnam(basename($src));
    if (!$tmp || !@copy($src, $tmp)) {
        return false;
    }

    $file_array = array(
        'name'     => basename($src),
        'tmp_name' => $tmp,
    );

    $attachment_id = media_handle_sideload($file_array, 0);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return false;
    }

    // Médium manuálu → skrýt v knihovně neadminům (viz media.php).
    tman_mark_media($attachment_id);

    return wp_get_attachment_url($attachment_id);
}

/**
 * Rekurzivní smazání dočasné složky.
 *
 * @param string $dir
 */
function tman_rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            tman_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
