<?php
/**
 * Export manuálů do ZIP.
 *
 * Dvě cesty ke stejné funkci:
 *   - submenu „Export" pod Manuály (protějšek Importu, výběr zaškrtávátky),
 *   - bulk action „Exportovat (ZIP)" v seznamu manuálů.
 *
 * ZIP obsahuje:
 *   manual.json  — pole manuálů: title, content, allowed_roles, manifest médií
 *   media/       — skutečné soubory (obrázky i PDF) odkazované z obsahu
 *
 * Čistý JSON nestačí kvůli obrázkům a PDF, proto ZIP.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Najde v obsahu absolutní URL směřující do složky uploads (obrázky, PDF, …).
 *
 * @param string $content
 * @return string[] unikátní URL
 */
function tman_extract_media_urls($content) {
    $uploads = wp_get_upload_dir();
    $baseurl = $uploads['baseurl'];
    if (empty($baseurl)) {
        return array();
    }

    $quoted = preg_quote($baseurl, '#');
    // URL v uvozovkách za src / href (a případně i holé)
    if (!preg_match_all('#' . $quoted . '/[^\s"\'<>()]+#i', $content, $m)) {
        return array();
    }

    return array_values(array_unique($m[0]));
}

/**
 * Sestaví strukturu jednoho manuálu pro export a doplní soubory do ZIPu.
 *
 * @param WP_Post     $post
 * @param ZipArchive  $zip
 * @param array       $seen  reference – už přidané soubory (dedup napříč manuály)
 * @return array
 */
function tman_build_manual_entry($post, $zip, &$seen) {
    $uploads     = wp_get_upload_dir();
    $content     = $post->post_content;
    $media_items = array();

    foreach (tman_extract_media_urls($content) as $url) {
        $relative = ltrim(str_replace($uploads['baseurl'], '', $url), '/');
        $path     = trailingslashit($uploads['basedir']) . $relative;

        if (!file_exists($path)) {
            continue;
        }

        // Ve zipu zachováme relativní cestu uploadů, aby nedošlo ke kolizi jmen.
        $zip_path = 'media/' . $relative;

        if (!isset($seen[$zip_path])) {
            $zip->addFile($path, $zip_path);
            $seen[$zip_path] = true;
        }

        $media_items[] = array(
            'original_url' => $url,
            'file'         => $zip_path,
        );
    }

    $roles = get_post_meta($post->ID, TMAN_META_ROLES, true);

    return array(
        'title'         => $post->post_title,
        'content'       => $content,
        'allowed_roles' => is_array($roles) ? $roles : array(),
        'media'         => $media_items,
    );
}

/**
 * Sestaví ZIP z daných manuálů a odešle ho ke stažení.
 *
 * Při úspěchu skript ukončí (readfile + exit), takže se z ní nevrací.
 * Při chybě vrací klíč hlášky pro `tman_export` query arg.
 *
 * @param int[] $post_ids
 * @return string  'nozip' | 'fail' | 'empty'
 */
function tman_stream_export_zip($post_ids) {
    if (!class_exists('ZipArchive')) {
        return 'nozip';
    }

    $tmp = wp_tempnam('tman-export');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        return 'fail';
    }

    $seen    = array();
    $manuals = array();

    foreach ($post_ids as $id) {
        $post = get_post($id);
        if ($post && $post->post_type === 'time_manual') {
            $manuals[] = tman_build_manual_entry($post, $zip, $seen);
        }
    }

    if (empty($manuals)) {
        $zip->close();
        @unlink($tmp);
        return 'empty';
    }

    $payload = array(
        'version'   => '1.0',
        'site'      => home_url(),
        'generated' => current_time('mysql'),
        'manuals'   => $manuals,
    );

    $zip->addFromString('manual.json', wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $filename = 'manualy-export-' . gmdate('Y-m-d-His') . '.zip';

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));

    readfile($tmp);
    @unlink($tmp);
    exit;
}

// Submenu „Export" pod Manuály (nad Importem – řadí se dle pořadí registrace).
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=time_manual',
        __('Export Manuals', 'time-manual'),
        __('Export', 'time-manual'),
        'manage_options',
        'tman-export',
        'tman_render_export_page'
    );
});

/**
 * Stahování musí proběhnout dřív, než admin odešle hlavičky a HTML,
 * proto se formulář zpracovává na admin_init, ne až v render callbacku.
 */
add_action('admin_init', function () {
    if (empty($_POST['tman_export_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'time-manual'));
    }
    check_admin_referer('tman_export');

    $redirect = admin_url('edit.php?post_type=time_manual&page=tman-export');
    $ids      = isset($_POST['tman_ids']) ? array_map('intval', (array) $_POST['tman_ids']) : array();

    if (empty($ids)) {
        wp_safe_redirect(add_query_arg('tman_export', 'empty', $redirect));
        exit;
    }

    // Vrací se jen při chybě – při úspěchu ZIP odteče a skript skončí.
    $error = tman_stream_export_zip($ids);
    wp_safe_redirect(add_query_arg('tman_export', $error, $redirect));
    exit;
});

function tman_render_export_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'time-manual'));
    }

    $posts = get_posts(array(
        'post_type'        => 'time_manual',
        'post_status'      => array('publish', 'draft', 'pending', 'private'),
        'numberposts'      => -1,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'suppress_filters' => false,
    ));
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Export Manuals', 'time-manual'); ?></h1>
        <p><?php esc_html_e('Select the manuals and download them as a ZIP. The package contains the article content, the configured roles and every media file (images and PDFs) referenced from the content — so it can be imported on another site.', 'time-manual'); ?></p>

        <?php if (empty($posts)) : ?>
            <p><em><?php esc_html_e('There are no manuals to export yet.', 'time-manual'); ?></em></p>
        <?php else : ?>
            <form method="post">
                <?php wp_nonce_field('tman_export'); ?>
                <table class="widefat striped" style="max-width:820px;">
                    <thead>
                        <tr>
                            <td class="check-column" style="width:2.2em;">
                                <input type="checkbox" id="tman-check-all" checked>
                            </td>
                            <th scope="col"><?php esc_html_e('Manual', 'time-manual'); ?></th>
                            <th scope="col" style="width:10em;"><?php esc_html_e('Status', 'time-manual'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($posts as $post) : ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="tman_ids[]" class="tman-export-id"
                                       value="<?php echo (int) $post->ID; ?>" checked>
                            </th>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">
                                    <?php echo esc_html(get_the_title($post) ?: __('(no title)', 'time-manual')); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(get_post_status_object($post->post_status)->label); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Export selected (ZIP)', 'time-manual'), 'primary', 'tman_export_submit'); ?>
            </form>
            <script>
            document.getElementById('tman-check-all').addEventListener('change', function () {
                document.querySelectorAll('.tman-export-id').forEach(function (cb) {
                    cb.checked = this.checked;
                }, this);
            });
            </script>
        <?php endif; ?>
    </div>
    <?php
}

// Přidat bulk akci „Exportovat" do seznamu manuálů.
add_filter('bulk_actions-edit-time_manual', function ($actions) {
    $actions['tman_export'] = __('Export (ZIP)', 'time-manual');
    return $actions;
});

// Obsloužit bulk akci – sestaví ZIP a odešle ho ke stažení.
add_filter('handle_bulk_actions-edit-time_manual', function ($redirect_to, $action, $post_ids) {
    if ($action !== 'tman_export') {
        return $redirect_to;
    }
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'time-manual'));
    }
    if (empty($post_ids)) {
        return add_query_arg('tman_export', 'empty', $redirect_to);
    }

    // Vrací se jen při chybě – při úspěchu ZIP odteče a skript skončí.
    return add_query_arg('tman_export', tman_stream_export_zip($post_ids), $redirect_to);
}, 10, 3);

// Drobná admin hláška při chybě exportu.
add_action('admin_notices', function () {
    if (empty($_GET['tman_export'])) {
        return;
    }
    $map = array(
        'empty' => array('warning', __('No manual was selected for export.', 'time-manual')),
        'nozip' => array('error', __('Export cannot run: the PHP ZipArchive extension is missing on the server.', 'time-manual')),
        'fail'  => array('error', __('Export failed — the ZIP could not be created.', 'time-manual')),
    );
    $key = sanitize_key($_GET['tman_export']);
    if (isset($map[$key])) {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($map[$key][0]),
            esc_html($map[$key][1])
        );
    }
});
