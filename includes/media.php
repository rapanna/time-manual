<?php
/**
 * Média manuálů — skrytí z knihovny médií.
 *
 * Obrázky a PDF vložené do manuálu končí ve společné knihovně médií, kde je
 * vidí i redaktor/šéfredaktor a může je smazat (a rozbít tím manuál).
 * Proto se přílohy nahrané v kontextu manuálu (nebo naimportované ze ZIPu)
 * značí metou `_tman_media` a:
 *
 *   1) filtrují se ze všech dotazů na přílohy (mřížka, seznam, REST),
 *   2) map_meta_cap jim neadminům zakáže edit/delete/read.
 *
 * Administrátor (manage_options) vidí a spravuje všechno jako dřív.
 *
 * Pozn.: značka je na příloze, ne na vazbě. Když se tentýž soubor použije
 * i v běžném příspěvku, zmizí z knihovny neadminům i tam — u dedikovaných
 * screenshotů k manuálům to je žádoucí, jinde je potřeba nahrát kopii.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TMAN_META_MEDIA', '_tman_media');

/**
 * Označí přílohu jako médium manuálu.
 *
 * @param int $attachment_id
 */
function tman_mark_media($attachment_id) {
    update_post_meta((int) $attachment_id, TMAN_META_MEDIA, 1);
}

/**
 * @param int $attachment_id
 * @return bool
 */
function tman_is_manual_media($attachment_id) {
    return (bool) get_post_meta((int) $attachment_id, TMAN_META_MEDIA, true);
}

/**
 * Má se aktuálnímu uživateli knihovna filtrovat? (Adminovi ne.)
 *
 * @return bool
 */
function tman_should_hide_media() {
    return !current_user_can('manage_options');
}

/**
 * Podmínka meta_query: vynech přílohy označené jako média manuálu.
 *
 * @return array
 */
function tman_media_exclusion_clause() {
    return array(
        'key'     => TMAN_META_MEDIA,
        'compare' => 'NOT EXISTS',
    );
}

/* ---------------------------------------------------------------------------
 * 1) Značkování při nahrání
 * ------------------------------------------------------------------------- */

/**
 * Příloha nahraná do rozepsaného manuálu. Blokový editor posílá rodiče přes
 * REST (post_parent je nastavený už při vzniku), klasický upload přes
 * $_REQUEST['post_id'] — kontrolujeme obojí.
 */
add_action('add_attachment', function ($attachment_id) {
    $attachment = get_post($attachment_id);
    $parent_id  = $attachment ? (int) $attachment->post_parent : 0;

    if (!$parent_id && !empty($_REQUEST['post_id'])) {
        $parent_id = (int) $_REQUEST['post_id'];
    }

    if ($parent_id && get_post_type($parent_id) === 'time_manual') {
        tman_mark_media($attachment_id);
    }
});

/* ---------------------------------------------------------------------------
 * 2) Skrytí z knihovny
 * ------------------------------------------------------------------------- */

/**
 * Mřížka knihovny (media modal i upload.php?mode=grid) — AJAX dotaz.
 */
add_filter('ajax_query_attachments_args', function ($args) {
    if (!tman_should_hide_media()) {
        return $args;
    }

    $meta_query   = isset($args['meta_query']) ? (array) $args['meta_query'] : array();
    $meta_query[] = tman_media_exclusion_clause();
    $args['meta_query'] = $meta_query;

    return $args;
});

/**
 * Seznamové zobrazení (upload.php?mode=list) a ostatní dotazy na přílohy.
 */
add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !tman_should_hide_media()) {
        return;
    }

    $post_type = $query->get('post_type');
    $types     = is_array($post_type) ? $post_type : array($post_type);

    if (!in_array('attachment', $types, true)) {
        return;
    }

    $meta_query   = (array) $query->get('meta_query');
    $meta_query[] = tman_media_exclusion_clause();
    $query->set('meta_query', $meta_query);
});

/**
 * REST (blokový editor si přílohy tahá přes /wp/v2/media).
 */
add_filter('rest_attachment_query', function ($args, $request) {
    if (!tman_should_hide_media()) {
        return $args;
    }

    $meta_query   = isset($args['meta_query']) ? (array) $args['meta_query'] : array();
    $meta_query[] = tman_media_exclusion_clause();
    $args['meta_query'] = $meta_query;

    return $args;
}, 10, 2);

/* ---------------------------------------------------------------------------
 * 3) Zákaz manipulace (kdyby se k příloze někdo dostal přímo přes ID)
 * ------------------------------------------------------------------------- */

add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if (!in_array($cap, array('edit_post', 'delete_post', 'read_post'), true)) {
        return $caps;
    }
    if (empty($args[0])) {
        return $caps;
    }

    $post = get_post($args[0]);
    if (!$post || $post->post_type !== 'attachment') {
        return $caps;
    }
    if (!tman_is_manual_media($post->ID)) {
        return $caps;
    }
    if (user_can($user_id, 'manage_options')) {
        return $caps;
    }

    return array('do_not_allow');
}, 10, 4);

/* ---------------------------------------------------------------------------
 * 4) Doznačkování médií nahraných před touto verzí
 * ------------------------------------------------------------------------- */

/**
 * URL zmenšeniny (`foto-300x200.jpg`) na URL originálu (`foto.jpg`),
 * jinak ji attachment_url_to_postid() nespáruje.
 *
 * @param string $url
 * @return string
 */
function tman_normalize_media_url($url) {
    return preg_replace('#-\d+x\d+(?=\.[a-zA-Z0-9]{2,5}(?:$|\?))#', '', $url);
}

/**
 * Projde manuály a označí jejich média — jednak přílohy navěšené na manuál,
 * jednak vše, na co manuál odkazuje v obsahu.
 *
 * @return int  počet označených příloh
 */
function tman_backfill_media() {
    $manuals = get_posts(array(
        'post_type'        => 'time_manual',
        'post_status'      => 'any',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => false,
    ));

    $marked = 0;

    foreach ($manuals as $manual_id) {
        $children = get_posts(array(
            'post_type'   => 'attachment',
            'post_status' => 'any',
            'post_parent' => $manual_id,
            'numberposts' => -1,
            'fields'      => 'ids',
        ));

        foreach ($children as $attachment_id) {
            tman_mark_media($attachment_id);
            $marked++;
        }

        $post = get_post($manual_id);
        if (!$post) {
            continue;
        }

        foreach (tman_extract_media_urls($post->post_content) as $url) {
            $attachment_id = attachment_url_to_postid(tman_normalize_media_url($url));
            if ($attachment_id) {
                tman_mark_media($attachment_id);
                $marked++;
            }
        }
    }

    return $marked;
}

/**
 * Jednorázově po nasazení verze s filtrováním médií.
 */
add_action('admin_init', function () {
    if (get_option('tman_media_backfilled')) {
        return;
    }

    tman_backfill_media();
    update_option('tman_media_backfilled', 1);
});
