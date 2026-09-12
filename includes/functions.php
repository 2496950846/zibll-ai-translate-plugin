<?php
/**
 * 翻译按钮（评论 / 文章 / 帖子）、AJAX 处理、资源加载
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 可选目标语言列表（后台多语言配置、前端语言选择器共用）
 */
if (!function_exists('zibll_ait_language_options')) {
    function zibll_ait_language_options()
    {
        return array(
            '简体中文'   => '简体中文',
            '繁體中文'   => '繁體中文',
            'English'   => 'English',
            '日本語'     => '日本語',
            '한국어'     => '한국어',
            'Français'  => 'Français',
            'Deutsch'   => 'Deutsch',
            'Español'   => 'Español',
            'Italiano'  => 'Italiano',
            'Português' => 'Português',
            'Русский'   => 'Русский',
            'العربية'   => 'العربية',
            'ไทย'       => 'ไทย',
            'Tiếng Việt' => 'Tiếng Việt',
            'हिन्दी'     => 'हिन्दी',
        );
    }
}

/**
 * 在评论操作菜单（回复右侧的三个点）中追加“翻译”按钮
 * 挂钩：comments_action_lists ($lists, $comment)
 *
 * 点击后默认由 AI 以“回复用户”身份，在原评论下生成一条译文回复；
 * 后台关闭“翻译以 AI 回复形式展示”时，则仅在原评论下方内联显示译文。
 */
add_filter('comments_action_lists', 'zibll_ait_comment_action_list', 10, 2);
function zibll_ait_comment_action_list($lists, $comment)
{
    if (!zibll_ait_options('enabled') || !zibll_ait_options('button_enabled', 1)) {
        return $lists;
    }

    // 译文回复本身不再显示“翻译”按钮
    if (get_comment_meta($comment->comment_ID, 'zibll_ait_is_translation', true)) {
        return $lists;
    }

    // 仅对外文评论显示（按设置）
    if (zibll_ait_options('foreign_only', 1) && !zibll_ait_is_foreign($comment->comment_content)) {
        return $lists;
    }

    $nonce   = wp_create_nonce('zibll_ait_translate');
    $mode    = zibll_ait_options('display_mode', 'modal');
    $action  = ('reply' === $mode) ? 'zibll_ait_translate_reply' : 'zibll_ait_translate';
    $data    = esc_attr(wp_json_encode(array(
        '_wpnonce'   => $nonce,
        'comment_id' => $comment->comment_ID,
    )));

    // 前端语言选择器：后台开启且配置了可选语言、且未启用“点击翻译先选择语言”时，
    // 渲染下拉框供访客选择目标语言；启用“先选择语言”后统一改用弹窗选择，避免重复 UI
    $lang_html = '';
    if (zibll_ait_options('language_selector') && !zibll_ait_options('lang_select_first')) {
        $avail  = (array) zibll_ait_options('target_languages', array());
        $default = zibll_ait_options('target_language', '简体中文');
        if (empty($avail)) {
            $avail = array($default);
        }
        $lang_html .= '<select class="zibll-ait-lang form-control" title="选择翻译语言">';
        foreach ($avail as $lg) {
            $lang_html .= '<option value="' . esc_attr($lg) . '"' .
                selected($lg, $default, false) . '>' . esc_html($lg) . '</option>';
        }
        $lang_html .= '</select>';
    }

    $lists .= '<li class="zibll-ait-action flex ac">' .
        '<a href="javascript:;" class="zibll-ait-translate c-blue" ' .
        'data-id="' . $comment->comment_ID . '" ' .
        'data-mode="' . esc_attr($mode) . '" ' .
        'form-action="' . $action . '" form-data="' . $data . '">' .
        '<i class="fa fa-globe mr6 fa-fw" aria-hidden="true"></i>翻译</a>' .
        $lang_html .
        '</li>';

    return $lists;
}

/**
 * 在译文回复的评论正文末尾追加 AI 免责声明
 * 此过滤器同时覆盖服务端渲染（页面刷新后已存在的译文回复）和 AJAX 动态插入两种场景，
 * 与 JS 中的 handleTranslateReply 逻辑互补，确保无论何时渲染都能显示提示。
 */
add_filter('comment_text', 'zibll_ait_append_ai_tip', 10, 2);
function zibll_ait_append_ai_tip($comment_text, $comment)
{
    if (!get_comment_meta($comment->comment_ID, 'zibll_ait_is_translation', true)) {
        return $comment_text;
    }
    // 避免重复追加（兼容旧版已写入评论正文、或正文内已含提示文本的情况）
    if (strpos($comment_text, 'zibll-ait-tip') !== false || strpos($comment_text, '内容由AI生成，仅供参考') !== false) {
        return $comment_text;
    }
    $comment_text .= '<div class="zibll-ait-tip muted-2-color em09 mt5 text-center">内容由AI生成，仅供参考</div>';
    return $comment_text;
}

/**
 * 为译文回复追加标记类，确保页面刷新后服务端渲染的评论也能命中专用样式
 */
add_filter('comment_class', 'zibll_ait_translation_reply_class', 10, 3);
function zibll_ait_translation_reply_class($classes, $class, $comment_id)
{
    if (get_comment_meta($comment_id, 'zibll_ait_is_translation', true)) {
        $classes[] = 'zibll-ait-translation-reply';
    }
    return $classes;
}

/**
 * AJAX：一键翻译某条评论
 */
add_action('wp_ajax_zibll_ait_translate', 'zibll_ait_ajax_translate');
add_action('wp_ajax_nopriv_zibll_ait_translate', 'zibll_ait_ajax_translate');
function zibll_ait_ajax_translate()
{
    if (!zibll_ait_options('enabled')) {
        wp_send_json_error('翻译功能未开启');
    }

    if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'zibll_ait_translate')) {
        wp_send_json_error('安全校验失败');
    }

    $comment_id = isset($_POST['comment_id']) ? absint($_POST['comment_id']) : 0;
    $comment    = get_comment($comment_id);
    if (!$comment) {
        wp_send_json_error('评论不存在');
    }

    // 目标语言：前端传入优先，否则使用后台默认语言
    $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';
    $target = $target ?: zibll_ait_options('target_language', '简体中文');

    // 读取缓存（按语言存储，避免不同语言互相覆盖）
    if (zibll_ait_options('cache_enabled', 1)) {
        $cached_all = get_comment_meta($comment_id, 'zibll_ait_translations', true);
        if (is_array($cached_all) && !empty($cached_all[$target])) {
            wp_send_json_success(array('html' => zibll_ait_render_translation($cached_all[$target], $target)));
        }
    }

    $result = zibll_ait_call_ai($comment->comment_content, $target);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    if (zibll_ait_options('cache_enabled', 1)) {
        $cached_all = get_comment_meta($comment_id, 'zibll_ait_translations', true);
        if (!is_array($cached_all)) {
            $cached_all = array();
        }
        $cached_all[$target] = $result;
        update_comment_meta($comment_id, 'zibll_ait_translations', $cached_all);
    }

    wp_send_json_success(array('html' => zibll_ait_render_translation($result, $target)));
}

/**
 * AJAX：点击“翻译”，由 AI 以“回复用户”身份生成一条译文回复
 *
 * 与一键内联翻译不同，此动作会在原评论下插入一条子评论（child comment），
 * 评论内容为该评论的 AI 译文；返回渲染好的评论 HTML 供前端直接插入页面。
 */
add_action('wp_ajax_zibll_ait_translate_reply', 'zibll_ait_ajax_translate_reply');
add_action('wp_ajax_nopriv_zibll_ait_translate_reply', 'zibll_ait_ajax_translate_reply');
function zibll_ait_ajax_translate_reply()
{
    if (!zibll_ait_options('enabled')) {
        wp_send_json_error('翻译功能未开启');
    }

    if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'zibll_ait_translate')) {
        wp_send_json_error('安全校验失败');
    }

    $comment_id = isset($_POST['comment_id']) ? absint($_POST['comment_id']) : 0;
    $comment    = get_comment($comment_id);
    if (!$comment) {
        wp_send_json_error('评论不存在');
    }

    // 目标语言：前端传入优先，否则使用后台默认语言
    $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';
    $target = $target ?: zibll_ait_options('target_language', '简体中文');

    // 同一评论、同一语言不重复生成译文回复
    // 若译文回复已被删除，则自动清理记录允许重新生成
    $done = get_comment_meta($comment_id, 'zibll_ait_translated_replies', true);
    if (!is_array($done)) {
        $done = array();
    }
    // 检查该评论下是否仍存在译文子评论
    $translation_reply = get_comments(array(
        'post_id'      => $comment->comment_post_ID,
        'parent'       => $comment_id,
        'meta_key'     => 'zibll_ait_is_translation',
        'count'        => true,
        'no_found_rows' => true,
    ));
    if (empty($translation_reply)) {
        // 译文已被删除，清理记录
        delete_comment_meta($comment_id, 'zibll_ait_translated_replies');
        $done = array();
    }
    if (in_array($target, $done, true)) {
        wp_send_json_error('该评论已生成' . $target . '译文回复');
    }

    $reply_user = absint(zibll_ait_options('reply_user'));
    if (!$reply_user) {
        wp_send_json_error('请先在后台设置“回复用户”');
    }
    $user = get_userdata($reply_user);
    if (!$user) {
        wp_send_json_error('回复用户不存在');
    }

    // 调用 AI 翻译
    $result = zibll_ait_call_ai($comment->comment_content, $target);
    if (is_wp_error($result) || !$result) {
        wp_send_json_error(is_wp_error($result) ? $result->get_error_message() : '翻译失败');
    }

    $content = '【' . $target . '译文】' . $result;
    // 将免责声明直接写入评论正文，确保页面刷新、服务端渲染、AJAX 插入都能显示；
    // 使用 <em> 标签以提高在 WordPress 评论 HTML 过滤中的存活率。
    $content .= '<em class="zibll-ait-tip muted-2-color em09 mt5 text-center">内容由AI生成，仅供参考</em>';

    $reply_data = array(
        'comment_post_ID'      => $comment->comment_post_ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => $user->user_url,
        'comment_content'      => wp_kses_post($content),
        'comment_type'         => 'comment',
        'comment_parent'       => $comment_id,
        'user_id'              => $reply_user,
        'comment_approved'     => 1,
    );

    $new_id = wp_insert_comment($reply_data);
    if (!$new_id) {
        wp_send_json_error('译文回复发布失败');
    }

    // 标记：原评论已生成该语言的译文回复；译文回复自身不再参与翻译 / 自动回复
    $done[] = $target;
    update_comment_meta($comment_id, 'zibll_ait_translated_replies', $done);
    update_comment_meta($new_id, 'zibll_ait_is_translation', 1);
    // 标记类通过 comment_class 过滤器统一追加（见 zibll_ait_translation_reply_class）

    // 复用主题评论模板渲染译文回复，供前端直接插入
    $html = '';
    $reply_comment = get_comment($new_id);
    
    // 优先使用主题函数渲染
    if (function_exists('zib_get_comments_list')) {
        $html = zib_get_comments_list($reply_comment, 0, false);
    }
    // 备选：使用 WordPress 原生评论 walkers
    elseif (function_exists('walk_comment')) {
        $html = walk_comment($reply_comment, 0, array('max_depth' => 1));
    }
    // 最后备选：手动构建评论HTML（完整结构）
    else {
        $author_url = esc_url(get_comment_author_url($new_id));
        $author = esc_html(get_comment_author($new_id));
        $avatar = get_avatar($new_id, 32);
        $content = get_comment_text($new_id);
        
        $html = '<li id="div-comment-' . $new_id . '" class="comment">
            <div id="comment-' . $new_id . '">
                <div class="comment-body flex ac">
                    <div class="comment-avatar mr10">' . $avatar . '</div>
                    <div class="comment-main flex1">
                        <div class="comment-head flex ac mb8">
                            <span class="comment-author">' . $author . '</span>
                        </div>
                        <div id="comment-content-' . $new_id . '" class="comment-content">' . $content . '</div>
                    </div>
                </div>
            </div>
        </li>';
    }

    // 确保返回的 HTML 只包含单个评论节点
    if (!empty($html)) {
        // 使用 DOMDocument 更可靠地提取单个评论节点
        $dom = new DOMDocument();
        @$dom->loadHTML('<meta charset="utf-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        // 查找 li.comment 或 id 以 div-comment- 开头的节点
        $nodes = $xpath->query('//li[contains(@class, "comment") or starts-with(@id, "div-comment-")]');
        if ($nodes && $nodes->length > 0) {
            $html = $dom->saveHTML($nodes->item(0));
        } else {
            // 备选：提取第一个 div 或 li
            $nodes = $xpath->query('//div|//li');
            if ($nodes && $nodes->length > 0) {
                $html = $dom->saveHTML($nodes->item(0));
            }
        }
    }

    wp_send_json_success(array('html' => $html, 'msg' => '已生成译文回复'));
}

/**
 * 在文章 / 帖子正文末尾追加“翻译”按钮（及可选语言选择器）
 *
 * 通过 the_content 过滤器挂载，天然支持文章、页面及后台所选的自定义文章类型
 * （含论坛帖子 forum_post）。仅在单篇内容详情页（主查询循环中）显示。
 * 挂钩：the_content
 */
add_filter('the_content', 'zibll_ait_post_translate_bar', 99);
function zibll_ait_post_translate_bar($content)
{
    if (!is_singular() || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (!zibll_ait_options('enabled') || !zibll_ait_options('post_enabled')) {
        return $content;
    }
    if (!zibll_ait_options('post_button_enabled', 1)) {
        return $content;
    }

    global $post;
    if (!$post || empty($post->post_type)) {
        return $content;
    }

    $types = (array) zibll_ait_options('post_types', array('post', 'page'));
    if (!in_array($post->post_type, $types, true)) {
        return $content;
    }

    return $content . zibll_ait_post_menu_html($post->ID);
}

/**
 * 生成文章/帖子“翻译菜单”HTML（下拉选择目标语言）
 *
 * 设计：主按钮“翻译”，若后台开启了“语言选择器”且配置了多语言，则按钮右侧展开
 * 一个下拉菜单列出可选目标语言，点击某一语言即翻译为该语言；若仅默认语言，
 * 点击按钮直接翻译，不展开菜单。供 the_content（文章/页面）与 BBS 帖子详情页复用。
 *
 * @param int $post_id 文章/帖子 ID
 * @return string
 */
function zibll_ait_post_menu_html($post_id)
{
    $post_id = absint($post_id);
    if (!$post_id) {
        return '';
    }

    $nonce  = wp_create_nonce('zibll_ait_translate');
    $target = zibll_ait_options('target_language', '简体中文');
    $mode   = zibll_ait_options('post_display_mode', 'inline');

    // 语言列表：开启语言选择器且未启用“点击翻译先选择语言”时，取“可选目标语言”
    // 供按钮旁下拉菜单使用；启用“先选择语言”后统一改用弹窗，此处不展开菜单
    $langs = array();
    if (zibll_ait_options('post_language_selector') && !zibll_ait_options('lang_select_first')) {
        $avail = (array) zibll_ait_options('target_languages', array());
        if (empty($avail)) {
            $avail = array($target);
        }
        $langs = $avail;
    }

    $has_menu = count($langs) > 1;
    $caret    = $has_menu ? '<i class="fa fa-angle-down ml6" aria-hidden="true"></i>' : '';

    $html  = '<div class="zibll-ait-post-bar flex ac mt20">';
    $html .= '<div class="zibll-ait-post-menu dropup relative">';
    $html .= '<button type="button" class="zibll-ait-post-translate but c-blue radius mr10" ' .
        'data-id="' . $post_id . '" ' .
        'data-mode="' . esc_attr($mode) . '" ' .
        'data-nonce="' . $nonce . '" ' .
        'data-has-menu="' . ($has_menu ? '1' : '0') . '">' .
        '<i class="fa fa-globe mr6 fa-fw" aria-hidden="true"></i>翻译' . $caret . '</button>';

    if ($has_menu) {
        $html .= '<div class="dropdown-menu zibll-ait-lang-menu">';
        foreach ($langs as $lg) {
            $html .= '<a class="zibll-ait-lang-item" href="javascript:;" data-lang="' .
                esc_attr($lg) . '">' . esc_html($lg) . '</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div>'; // .zibll-ait-post-menu
    $html .= '</div>'; // .zibll-ait-post-bar
    $html .= '<div class="zibll-ait-post-result mt10"></div>';

    return $html;
}

/**
 * 在文章“三个点”下拉菜单（zib_get_post_more_dropdown）中追加“文章翻译”按钮
 *
 * 复用后台“文章翻译”总开关、按钮开关与文章类型配置；仅单篇内容详情页显示。
 * 点击后由前端 JS（.zibll-ait-post-dropdown）调用 zibll_ait_translate_post 接口，
 * 按后台配置的展示方式（modal / inline）展示译文。
 * 挂钩：zib_post_more_dropdown_items
 */
add_filter('zib_post_more_dropdown_items', 'zibll_ait_post_dropdown_item', 20, 2);
function zibll_ait_post_dropdown_item($action, $post)
{
    if (!zibll_ait_options('enabled') || !zibll_ait_options('post_enabled') || !zibll_ait_options('post_button_enabled', 1)) {
        return $action;
    }
    // 仅单篇内容详情页（与正文下方翻译菜单的展示范围保持一致）
    if (!is_singular()) {
        return $action;
    }
    if (!is_object($post)) {
        $post = get_post($post);
    }
    if (!$post || empty($post->ID)) {
        return $action;
    }

    $types = (array) zibll_ait_options('post_types', array('post', 'page'));
    if (!in_array($post->post_type, $types, true)) {
        return $action;
    }

    $nonce = wp_create_nonce('zibll_ait_translate');
    $mode  = zibll_ait_options('post_display_mode', 'inline');

    $item = '<li><a href="javascript:;" class="zibll-ait-post-dropdown c-blue" ' .
        'data-id="' . $post->ID . '" ' .
        'data-nonce="' . $nonce . '" ' .
        'data-mode="' . esc_attr($mode) . '">' .
        '<i class="fa fa-globe mr6 fa-fw" aria-hidden="true"></i>文章翻译</a></li>';

    return $action . $item;
}

/**
 * BBS 论坛帖子：在帖子详情页追加“翻译菜单”
 *
 * the_content 过滤器在论坛帖子中通常不会触发，这里用 BBS 专用钩子补全，
 * 使后台勾选 forum_post 时也能显示翻译菜单。仅当帖子类型包含 forum_post 时输出。
 * 挂钩：bbs_posts_page_content
 */
add_action('bbs_posts_page_content', 'zibll_ait_bbs_post_bar', 20);
function zibll_ait_bbs_post_bar()
{
    if (!zibll_ait_options('enabled') || !zibll_ait_options('post_enabled') || !zibll_ait_options('post_button_enabled', 1)) {
        return;
    }
    global $post;
    if (!$post || $post->post_type !== 'forum_post') {
        return;
    }
    $types = (array) zibll_ait_options('post_types', array('post', 'page'));
    if (!in_array('forum_post', $types, true)) {
        return;
    }
    echo zibll_ait_post_menu_html($post->ID);
}

/**
 * AJAX：翻译整篇文章 / 帖子正文（保留 HTML 结构）
 */
add_action('wp_ajax_zibll_ait_translate_post', 'zibll_ait_ajax_translate_post');
add_action('wp_ajax_nopriv_zibll_ait_translate_post', 'zibll_ait_ajax_translate_post');
function zibll_ait_ajax_translate_post()
{
    if (!zibll_ait_options('enabled') || !zibll_ait_options('post_enabled')) {
        wp_send_json_error('文章翻译功能未开启');
    }

    if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'zibll_ait_translate')) {
        wp_send_json_error('安全校验失败');
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $post    = get_post($post_id);
    if (!$post) {
        wp_send_json_error('内容不存在');
    }

    $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';
    $target = $target ?: zibll_ait_options('target_language', '简体中文');

    // 读取缓存（post meta，按语言存储）
    if (zibll_ait_options('cache_enabled', 1)) {
        $cached_all = get_post_meta($post_id, 'zibll_ait_post_translations', true);
        if (is_array($cached_all) && !empty($cached_all[$target])) {
            wp_send_json_success(array('html' => zibll_ait_render_post_translation($cached_all[$target], $target)));
        }
    }

    $result = zibll_ait_call_ai($post->post_content, $target, array('html' => true, 'limit' => 0));
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    if (zibll_ait_options('cache_enabled', 1)) {
        $cached_all = get_post_meta($post_id, 'zibll_ait_post_translations', true);
        if (!is_array($cached_all)) {
            $cached_all = array();
        }
        $cached_all[$target] = $result;
        update_post_meta($post_id, 'zibll_ait_post_translations', $cached_all);
    }

    wp_send_json_success(array('html' => zibll_ait_render_post_translation($result, $target)));
}

/**
 * 生成"回复用户"下拉选项（复用 CSF select 字段）
 * 列出站点用户，AI 将以所选项目的身份回复评论
 */
if (!function_exists('zibll_ait_user_options')) {
    function zibll_ait_user_options()
    {
        $options = array('0' => '— 请选择用户 —');
        $users   = get_users(array(
            'number'   => 200,
            'orderby'  => 'display_name',
            'fields'   => array('ID', 'display_name'),
        ));
        foreach ($users as $u) {
            $options[$u->ID] = $u->display_name . ' (#' . $u->ID . ')';
        }
        return $options;
    }
}

/**
 * 当后台保存"回复用户"设置后，自动将该用户设置为认证用户
 * 认证名称：AI认证，认证时间：1970-01-01 00:00
 * 同时取消之前用户的认证状态
 */
add_action('csf_zibll_ait_options_save_after', 'zibll_ait_sync_reply_user_auth', 10, 2);
function zibll_ait_sync_reply_user_auth($data, $csf_instance)
{
    // 获取新的回复用户ID
    $reply_user = isset($data['reply_user']) ? absint($data['reply_user']) : 0;
    if (!$reply_user) {
        return;
    }

    // 获取之前已认证的用户ID
    $old_auth_user = get_option('zibll_ait_auth_user_id', 0);

    // 如果新旧用户不同，先取消旧用户的认证
    if ($old_auth_user && $old_auth_user != $reply_user) {
        zibll_ait_remove_user_auth($old_auth_user);
    }

    // 设置新用户的认证
    if (function_exists('zib_add_user_auth')) {
        zib_add_user_auth($reply_user, array(
            'name' => 'AI认证',
            'desc' => '',
            'time' => '1970-01-01 00:00',
        ));
    } else {
        update_user_meta($reply_user, 'auth', 1);
        update_user_meta($reply_user, 'auth_info', array(
            'name' => 'AI认证',
            'desc' => '',
            'time' => '1970-01-01 00:00',
        ));
    }

    // 保存当前认证用户ID
    update_option('zibll_ait_auth_user_id', $reply_user);
}

/**
 * 移除用户的 AI 认证状态
 */
function zibll_ait_remove_user_auth($user_id)
{
    if (!$user_id) {
        return;
    }

    // 先验证认证是否存在
    $has_auth = get_user_meta($user_id, 'auth', true);
    if (!$has_auth) {
        return; // 已经没有认证，无需处理
    }

    // 删除认证meta
    delete_user_meta($user_id, 'auth');
    delete_user_meta($user_id, 'auth_info');

    // 强制刷新缓存
    clean_user_cache($user_id);
    wp_cache_delete($user_id, 'users');
    wp_cache_delete($user_id, 'user_meta');

    // 验证删除是否成功
    $remaining = get_user_meta($user_id, 'auth', true);
    if ($remaining) {
        // 如果还有认证数据，再次尝试删除
        delete_user_meta($user_id, 'auth');
        delete_user_meta($user_id, 'auth_info');
        clean_user_cache($user_id);
    }
}

/**
 * 在前台用户访问时，清除过期的AI认证缓存
 */
add_action('init', 'zibll_ait_clear_expired_auth_cache', 1);
function zibll_ait_clear_expired_auth_cache()
{
    if (!is_admin() && is_user_logged_in()) {
        $current_user_id = get_current_user_id();
        $auth_user_id = get_option('zibll_ait_auth_user_id', 0);

        // 如果当前登录用户不是AI认证用户，且拥有AI认证标记，清除其认证
        if ($auth_user_id && $current_user_id != $auth_user_id) {
            $has_auth = get_user_meta($current_user_id, 'auth', true);
            $auth_info = get_user_meta($current_user_id, 'auth_info', true);

            // 检查是否是AI认证的标记
            if ($has_auth && is_array($auth_info) && isset($auth_info['name']) && $auth_info['name'] === 'AI认证') {
                delete_user_meta($current_user_id, 'auth');
                delete_user_meta($current_user_id, 'auth_info');
                clean_user_cache($current_user_id);
            }
        }
    }
}

/**
 * 在评论渲染时清除AI认证（确保实时生效）
 */
add_filter('comments_array', 'zibll_ait_clear_comment_auth', 10, 2);
function zibll_ait_clear_comment_auth($comments, $post_id)
{
    $auth_user_id = get_option('zibll_ait_auth_user_id', 0);
    if (!$auth_user_id) {
        return $comments;
    }

    foreach ($comments as $comment) {
        $comment_user_id = $comment->user_id;
        if ($comment_user_id && $comment_user_id != $auth_user_id) {
            $has_auth = get_user_meta($comment_user_id, 'auth', true);
            $auth_info = get_user_meta($comment_user_id, 'auth_info', true);

            if ($has_auth && is_array($auth_info) && isset($auth_info['name']) && $auth_info['name'] === 'AI认证') {
                delete_user_meta($comment_user_id, 'auth');
                delete_user_meta($comment_user_id, 'auth_info');
                clean_user_cache($comment_user_id);
            }
        }
    }

    return $comments;
}

/**
 * 评论发布后，安排 AI 自动回复（异步，避免阻塞访客提交）
 * 挂钩：comment_post ($comment_id, $comment_approved, $commentdata)
 */
add_action('comment_post', 'zibll_ait_comment_post_reply', 20, 3);
function zibll_ait_comment_post_reply($comment_id, $comment_approved, $commentdata)
{
    // 仅对“已通过审核”的评论立即安排；待审评论在通过审核时由状态转换钩子处理
    if (1 !== (int) $comment_approved) {
        return;
    }
    zibll_ait_maybe_schedule_reply($comment_id);
}

/**
 * 评论由待审转为已审核时，也安排 AI 自动回复
 * 挂钩：transition_comment_status ($new_status, $old_status, $comment)
 */
add_action('transition_comment_status', 'zibll_ait_comment_status_reply', 20, 3);
function zibll_ait_comment_status_reply($new_status, $old_status, $comment)
{
    if ('approved' !== $new_status || 'approved' === $old_status) {
        return;
    }
    zibll_ait_maybe_schedule_reply($comment->comment_ID);
}

/**
 * 统一的调度入口：避免重复调度，并排除 AI 自己的回复
 */
function zibll_ait_maybe_schedule_reply($comment_id)
{
    if (!zibll_ait_options('enabled') || !zibll_ait_options('auto_reply_enabled')) {
        return;
    }

    $reply_user = absint(zibll_ait_options('reply_user'));
    if (!$reply_user) {
        return;
    }

    $comment = get_comment($comment_id);
    if (!$comment) {
        return;
    }

    // 不回复 pingback / trackback 等非普通评论
    if ($comment->comment_type && 'comment' !== $comment->comment_type) {
        return;
    }

    // 不回复 AI 自己的评论，避免无限循环
    if ((int) $comment->user_id === $reply_user) {
        return;
    }

    // 同一评论不重复调度
    if (wp_next_scheduled('zibll_ait_auto_reply', array($comment_id))) {
        return;
    }

    wp_schedule_single_event(time() + 5, 'zibll_ait_auto_reply', array($comment_id));
}

/**
 * 实际执行 AI 自动回复（由 cron 触发，不直接阻塞评论提交）
 */
add_action('zibll_ait_auto_reply', 'zibll_ait_do_auto_reply');
function zibll_ait_do_auto_reply($comment_id)
{
    $comment = get_comment($comment_id);
    if (!$comment) {
        return;
    }

    $reply_user = absint(zibll_ait_options('reply_user'));
    if (!$reply_user) {
        return;
    }

    // 再次防止对 AI 自己的评论回复
    if ((int) $comment->user_id === $reply_user) {
        return;
    }

    // 防止重复回复
    if (get_comment_meta($comment_id, 'zibll_ait_replied', true)) {
        return;
    }

    $result = zibll_ait_call_ai_reply($comment->comment_content);
    if (is_wp_error($result) || !$result) {
        return;
    }

    // 标记已回复（先标记，避免并发重复）
    update_comment_meta($comment_id, 'zibll_ait_replied', 1);

    $user = get_userdata($reply_user);
    if (!$user) {
        return;
    }

    $reply_data = array(
        'comment_post_ID'      => $comment->comment_post_ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => $user->user_url,
        'comment_content'      => wp_kses_post($result),
        'comment_type'         => 'comment',
        'comment_parent'       => $comment_id,
        'user_id'              => $reply_user,
        'comment_approved'     => 1,
    );

    wp_insert_comment($reply_data);
}

/**
 * 加载前端资源（仅文章/帖子详情页）
 */
add_action('wp_enqueue_scripts', 'zibll_ait_enqueue');
function zibll_ait_enqueue()
{
    if (!is_singular()) {
        return;
    }

    // 资源版本按文件修改时间刷新，避免浏览器缓存旧 JS/CSS
    $css_file = ZIBLL_AIT_PATH . 'assets/css/ai-translate-plugin.css';
    $js_file  = ZIBLL_AIT_PATH . 'assets/js/ai-translate-plugin.js';
    $css_ver  = file_exists($css_file) ? filemtime($css_file) : '1.1.4';
    $js_ver   = file_exists($js_file) ? filemtime($js_file) : '1.1.4';

    wp_enqueue_style(
        'zibll-ait',
        ZIBLL_AIT_URL . 'assets/css/ai-translate-plugin.css',
        array(),
        $css_ver
    );

    wp_enqueue_script(
        'zibll-ait',
        ZIBLL_AIT_URL . 'assets/js/ai-translate-plugin.js',
        array('jquery'),
        $js_ver,
        true
    );

    // 文章“三个点”菜单注入“文章翻译”按钮所需数据
    // 由前端 JS 直接把按钮追加进下拉菜单，无需依赖主题模板改动
    $post_dropdown = array('enabled' => false);
    global $post;
    if (
        $post && !empty($post->ID)
        && zibll_ait_options('enabled')
        && zibll_ait_options('post_enabled')
        && zibll_ait_options('post_button_enabled', 1)
    ) {
        $types = (array) zibll_ait_options('post_types', array('post', 'page'));
        if (in_array($post->post_type, $types, true)) {
            $post_dropdown = array(
                'enabled' => true,
                'post_id' => (int) $post->ID,
                'nonce'   => wp_create_nonce('zibll_ait_translate'),
                'mode'    => zibll_ait_options('post_display_mode', 'inline'),
                'label'   => '文章翻译',
            );
        }
    }

    // 语言选择数据：供前端“先选择语言”弹窗使用
    $langs = (array) zibll_ait_options('target_languages', array());
    if (empty($langs)) {
        $langs = array(zibll_ait_options('target_language', '简体中文'));
    }
    $langs = array_values(array_unique($langs));

    wp_localize_script('zibll_ait', 'zibll_ait', array(
        'ajax_url'      => admin_url('admin-ajax.php'),
        'at_nonce'      => wp_create_nonce('zibll_ait_at'),
        'post_dropdown' => $post_dropdown,
        'langs'         => $langs,
        'default_lang'  => zibll_ait_options('target_language', '简体中文'),
        'require_lang'  => (bool) zibll_ait_options('lang_select_first', 1),
    ));
}

/**
 * 临时诊断：访问任意文章/帖子详情页并在 URL 后附加 ?aitdebug=1 即可显示插件配置，
 * 用于排查“翻译按钮不显示”问题。确认后此函数可删除。
 */
add_action('wp_footer', 'zibll_ait_debug');
function zibll_ait_debug()
{
    if (empty($_GET['aitdebug'])) {
        return;
    }
    global $post;
    $pt    = $post ? $post->post_type : '(none)';
    $types = (array) zibll_ait_options('post_types', array('post', 'page'));
    $rows  = array(
        'is_singular'            => is_singular() ? 1 : 0,
        'current post_type'      => $pt,
        'enabled(总开关)'         => zibll_ait_options('enabled') ? 1 : 0,
        'post_enabled(文章翻译)'   => zibll_ait_options('post_enabled') ? 1 : 0,
        'post_button_enabled(显示按钮)' => zibll_ait_options('post_button_enabled', 1) ? 1 : 0,
        'post_types(适用类型)'     => implode(',', $types),
        '当前类型在适用范围内'      => in_array($pt, $types, true) ? 'YES' : 'NO',
        'button_enabled(评论)'     => zibll_ait_options('button_enabled', 1) ? 1 : 0,
    );
    echo '<div id="zibll-ait-debug" style="position:fixed;left:10px;bottom:10px;z-index:99999;background:#fff;border:2px solid #e53935;padding:10px 14px;font:12px/1.6 monospace;color:#222;max-width:380px;box-shadow:0 2px 10px rgba(0,0,0,.2)">';
    echo '<b>Zibll-AIT 调试 (?aitdebug=1)</b><br>';
    foreach ($rows as $k => $v) {
        echo esc_html($k) . ': <b>' . esc_html($v) . '</b><br>';
    }
    echo '</div>';
}

/**
 * 页脚渲染翻译弹窗（复用子比主题自带的前端弹窗 zib_modal）
 *
 * 采用主题的「炫彩头部」样式（colorful_header），由前端 JS 在翻译成功后
 * 把译文注入 .zibll-ait-modal-content，再通过 Bootstrap 的 .modal('show') 打开。
 */
add_action('wp_footer', 'zibll_ait_render_modal');
function zibll_ait_render_modal()
{
    if (!is_singular() || !zibll_ait_options('enabled')) {
        return;
    }
    // 评论弹窗模式 或 文章/帖子弹窗模式 任一开启即渲染（两者共用同一弹窗容器）
    $comment_modal = zibll_ait_options('button_enabled', 1) && 'modal' === zibll_ait_options('display_mode', 'modal');
    $post_modal    = zibll_ait_options('post_enabled') && zibll_ait_options('post_button_enabled', 1)
        && 'modal' === zibll_ait_options('post_display_mode', 'inline');
    if (!$comment_modal && !$post_modal) {
        return;
    }
    if (!function_exists('zib_modal')) {
        return;
    }

    zib_modal(array(
        'id'              => 'zibll-ait-modal',
        'colorful_header' => true,
        'header_class'    => 'jb-green',
        'header_icon'     => '<i class="fa fa-globe" aria-hidden="true"></i>',
        'title'           => '翻译结果',
        'content'         => '<div class="zibll-ait-modal-content"></div>',
        'buttons_class'   => 'but jb-green btn-block',
        'buttons'         => array(
            array(
                'link' => array('text' => '我知道了', 'url' => 'javascript:;'),
                'attr' => 'data-dismiss="modal"',
            ),
        ),
    ));
}

/**
 * 页脚渲染“翻译中”加载弹窗（蓝色炫彩头部，复用子比主题弹窗样式）
 *
 * 点击「翻译」后由前端 JS 打开此弹窗（jb-blue + loading 图标 + “翻译中…”文案），
 * 翻译完成/失败后由 JS 关闭，再按所选展示方式（modal/inline/reply）显示译文。
 * 此弹窗与展示方式无关，所有模式点击翻译后都会先弹出该蓝色加载提示。
 */
add_action('wp_footer', 'zibll_ait_render_loading_modal');
function zibll_ait_render_loading_modal()
{
    if (!is_singular() || !zibll_ait_options('enabled')) {
        return;
    }
    // 评论或文章/帖子翻译开启时都需要“翻译中…”加载弹窗
    $show = zibll_ait_options('button_enabled', 1)
        || (zibll_ait_options('post_enabled') && zibll_ait_options('post_button_enabled', 1));
    if (!$show) {
        return;
    }
    if (!function_exists('zib_get_modal_colorful_header')) {
        return;
    }

    // 蓝色炫彩头部 + 旋转 loading 图标 + “翻译中…”文案（无关闭按钮，不可误关）
    $header = zib_get_modal_colorful_header('jb-blue', '<i class="loading"></i>', '翻译中…', false);
    ?>
    <div class="modal fade" id="zibll-ait-loading-modal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div style="padding: 1px;">
                    <?php echo $header; ?>
                    <div class="modal-body text-center muted-2-color em09" style="padding: 18px 10px;">
                        <span>正在调用 AI 翻译，请稍候…</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 页脚渲染“选择翻译语言”弹窗（绿色炫彩头部，复用主题弹窗样式）
 *
 * “点击翻译先选择语言”开启时，前端点击“翻译”/“文章翻译”会先弹出此弹窗，
 * 访客选择目标语言后，再调用对应 AJAX 翻译接口并显示译文。
 * 语言列表由前端 JS 根据 zibll_ait.langs 动态生成。
 */
add_action('wp_footer', 'zibll_ait_render_lang_modal');
function zibll_ait_render_lang_modal()
{
    if (!is_singular() || !zibll_ait_options('enabled')) {
        return;
    }
    // 评论或文章/帖子翻译开启时才需要语言选择弹窗
    $show = zibll_ait_options('button_enabled', 1)
        || (zibll_ait_options('post_enabled') && zibll_ait_options('post_button_enabled', 1));
    if (!$show) {
        return;
    }
    if (!function_exists('zib_get_modal_colorful_header')) {
        return;
    }

    $header = zib_get_modal_colorful_header('jb-green', '<i class="fa fa-globe"></i>', '选择翻译语言', true);
    ?>
    <div class="modal fade zibll-ait-lang-modal" id="zibll-ait-lang-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div style="padding: 1px;">
                    <?php echo $header; ?>
                    <div class="modal-body">
                        <div class="zibll-ait-lang-list flex ac jsb wrap"></div>
                        <p class="zibll-ait-lang-tip muted-2-color text-center em09 mt10 mb0">请选择要翻译成的目标语言</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 加载前端 CSS / JS，并传递 zibll_ait 全局对象给前端
 */
add_action('wp_enqueue_scripts', 'zibll_ait_enqueue_assets');
function zibll_ait_enqueue_assets()
{
    if (!zibll_ait_options('enabled')) {
        return;
    }

    $version = '1.2.0';

    // CSS
    wp_enqueue_style(
        'zibll-ait-plugin',
        ZIBLL_AIT_URL . 'assets/css/ai-translate-plugin.css',
        array(),
        $version
    );

    // JS（依赖 jQuery，已在子比主题中加载）
    wp_enqueue_script(
        'zibll-ait-plugin',
        ZIBLL_AIT_URL . 'assets/js/ai-translate-plugin.js',
        array('jquery'),
        $version,
        true
    );

    // 向 JS 传递必要数据
    $target_lang    = zibll_ait_options('target_language', '简体中文');
    $target_langs   = (array) zibll_ait_options('target_languages', array($target_lang));
    $require_lang   = (bool) zibll_ait_options('lang_select_first', true);

    // 文章"三个点"菜单配置（仅在单篇文章详情页有效）
    $post_dropdown = array();
    if (is_singular() && zibll_ait_options('post_enabled') && zibll_ait_options('post_button_enabled', 1)) {
        global $post;
        if ($post && in_array($post->post_type, (array) zibll_ait_options('post_types', array('post', 'page')), true)) {
            $post_dropdown = array(
                'enabled' => true,
                'post_id' => (int) $post->ID,
                'nonce'   => wp_create_nonce('zibll_ait_translate'),
                'mode'    => zibll_ait_options('post_display_mode', 'inline'),
                'label'   => '文章翻译',
            );
        }
    }

    wp_localize_script('zibll-ait-plugin', 'zibll_ait', array(
        'ajax_url'      => admin_url('admin-ajax.php'),
        'post_dropdown' => $post_dropdown,
        'langs'         => $target_langs,
        'default_lang'  => $target_lang,
        'require_lang'  => $require_lang,
    ));
}

/**
 * 向主题声明前端 AJAX 动作
 */
add_filter('zib_locale_frontend_ajax_actions', 'zibll_ait_frontend_ajax_actions');
function zibll_ait_frontend_ajax_actions($actions)
{
    $actions[] = 'zibll_ait_translate';
    $actions[] = 'zibll_ait_translate_reply';
    $actions[] = 'zibll_ait_translate_post';
    return $actions;
}
