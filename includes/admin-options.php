<?php
/**
 * 后台设置页（复用子比主题自带的 Codestar Framework）
 * 独立 option key：zibll_ait_options，不污染主题 zibll_options
 */

if (!defined('ABSPATH')) {
    exit;
}

// 本文件由主文件在 init 阶段（主题 CSF 已加载后）require，
// 直接调用注册即可，admin_menu 在 init 之后触发，菜单可正常挂载。
function zibll_ait_create_options()
{
    // 仅后台需要菜单；且必须等 CSF 类加载完成
    static $done = false;
    if ($done) {
        return;
    }
    if (!is_admin() || !class_exists('CSF')) {
        return;
    }
    $done = true;
    $prefix = 'zibll_ait_options';

    // 独立顶级菜单，保持唯一 menu_slug，不污染主题 zibll_options
    CSF::createOptions($prefix, array(
        'menu_title'      => 'AI翻译',
        'menu_slug'       => 'zibll_ait_options',
        'framework_title' => 'AI翻译 <small>v1.1.6</small>',
        'theme'           => 'light',
    ));

    // 基础设置
    CSF::createSection($prefix, array(
        'id'     => 'base',
        'title'  => '基础设置',
        'icon'   => 'fa fa-cog',
        'fields' => array(
            array(
                'id'      => 'enabled',
                'type'    => 'switcher',
                'title'   => '启用翻译功能',
                'default' => false,
            ),
            array(
                'id'      => 'cache_enabled',
                'type'    => 'switcher',
                'title'   => '缓存翻译结果',
                'default' => true,
                'desc'    => '开启后，评论译文存评论 meta、文章/帖子译文存文章 meta，按语言分别缓存，避免重复消耗额度。',
                'dependency' => array('enabled', '==', '1'),
            ),
            array(
                'id'      => 'lang_select_first',
                'type'    => 'switcher',
                'title'   => '点击翻译先选择语言',
                'default' => true,
                'desc'    => '开启后，点击"翻译"或"文章翻译"会先弹出语言选择框，选择目标语言后再显示译文；关闭则按后台默认语言直接翻译（若开启了对应语言选择器，仍显示下拉框）。',
                'dependency' => array('enabled', '==', '1'),
            ),
        ),
    ));

    // 评论翻译
    CSF::createSection($prefix, array(
        'id'     => 'comment',
        'title'  => '评论翻译',
        'icon'   => 'fa fa-comments',
        'fields' => array(
            array(
                'id'      => 'button_enabled',
                'type'    => 'switcher',
                'title'   => '显示评论一键翻译按钮',
                'default' => true,
                'dependency' => array('enabled', '==', '1'),
            ),
            array(
                'id'      => 'foreign_only',
                'type'    => 'switcher',
                'title'   => '仅对外文评论显示按钮',
                'default' => true,
                'dependency' => array('button_enabled', '==', '1'),
            ),
            array(
                'id'      => 'display_mode',
                'type'    => 'select',
                'title'   => '评论翻译结果展示方式',
                'options' => array(
                    'modal'  => '弹窗显示（推荐）',
                    'reply'  => '以 AI 回复形式展示',
                    'inline' => '评论下方内联显示',
                ),
                'default' => 'modal',
                'desc'    => '弹窗：点击"翻译"后在弹窗中显示译文（不改动评论区）；AI 回复：由 AI 以"回复用户"身份在原评论下生成译文回复；内联：仅在该评论下方显示译文。',
                'dependency' => array('button_enabled', '==', '1'),
            ),
            array(
                'id'      => 'auto_reply_enabled',
                'type'    => 'switcher',
                'title'   => '启用 AI 自动回复评论',
                'default' => false,
                'desc'    => '开启后，新评论将由下方所选用户身份自动回复',
                'dependency' => array('enabled', '==', '1'),
            ),
            array(
                'id'         => 'reply_user',
                'type'       => 'select',
                'title'      => '回复用户',
                'options'    => zibll_ait_user_options(),
                'default'    => '0',
                'desc'       => '翻译以 AI 回复形式展示时，译文将以该用户的身份（作者名/头像）发布',
                'dependency' => array('enabled', '==', '1', 'display_mode', '==', 'reply'),
            ),
            array(
                'id'         => 'reply_prompt',
                'type'       => 'textarea',
                'title'      => '回复提示词（系统指令）',
                'default'    => '你是一个友好的网站客服兼作者助手。请根据用户评论的内容，给出简洁、得体、有帮助的回复，语气自然。只输出回复正文本身，不要包含任何解释、引号或额外说明。',
                'desc'       => '留空则使用默认提示词',
                'dependency' => array('auto_reply_enabled', '==', '1'),
            ),
        ),
    ));

    // 文章 / 帖子翻译
    // 注意：section 的 id 不要使用 WordPress 核心保留词（如 post/page/comment），
    // 否则 CSF 在解析/渲染该选项卡时可能被查询变量干扰而显示空白。
    CSF::createSection($prefix, array(
        'id'     => 'post_translate',
        'title'  => '文章/帖子翻译',
        'icon'   => 'fa fa-file-text-o',
        'fields' => array(
            array(
                'id'      => 'post_enabled',
                'type'    => 'switcher',
                'title'   => '启用文章/帖子翻译',
                'default' => false,
                'desc'    => '开启后，在文章/帖子正文下方显示"翻译"按钮，可一键翻译整篇内容。注意：需先在"基础设置"开启"启用翻译功能"总开关，前端才会真正显示。',
            ),
            array(
                'id'      => 'post_button_enabled',
                'type'    => 'switcher',
                'title'   => '显示翻译按钮',
                'default' => true,
                'dependency' => array('post_enabled', '==', '1'),
            ),
            array(
                'id'         => 'post_types',
                'type'       => 'select',
                'title'      => '适用的文章类型',
                'options'    => array(
                    'post'       => '文章 (post)',
                    'page'       => '页面 (page)',
                    'forum_post' => '论坛帖子 (forum_post)',
                ),
                'chosen'     => true,
                'multiple'   => true,
                'default'    => array('post', 'page'),
                'desc'       => '仅在所选文章类型的详情页显示翻译按钮。论坛帖子需主题已启用 BBS 模块。',
                'dependency' => array('post_enabled', '==', '1'),
            ),
            array(
                'id'      => 'post_display_mode',
                'type'    => 'select',
                'title'   => '译文展示方式',
                'options' => array(
                    'inline' => '正文下方内联显示（推荐）',
                    'modal'  => '弹窗显示',
                ),
                'default' => 'inline',
                'desc'    => '内联：译文直接显示在正文下方，可点击"隐藏译文"收起；弹窗：在弹窗中显示译文。',
                'dependency' => array('post_enabled', '==', '1'),
            ),
            array(
                'id'      => 'post_language_selector',
                'type'    => 'switcher',
                'title'   => '显示语言选择器',
                'default' => false,
                'desc'    => '开启后，翻译按钮旁会显示语言下拉框（使用下方"可选目标语言"），访客可自主选择目标语言。',
                'dependency' => array('post_enabled', '==', '1'),
            ),
        ),
    ));

    // AI 模型配置
    CSF::createSection($prefix, array(
        'id'     => 'model',
        'title'  => 'AI 模型配置',
        'icon'   => 'fa fa-cogs',
        'fields' => array(
            array(
                'id'      => 'api_base',
                'type'    => 'text',
                'title'   => 'API 地址',
                'default' => 'https://api.openai.com/v1',
                'desc'    => 'OpenAI 兼容接口地址，例如 DeepSeek：https://api.deepseek.com/v1',
            ),
            array(
                'id'      => 'api_key',
                'type'    => 'text',
                'title'   => 'API Key',
                'attributes' => array('type' => 'password'),
            ),
            array(
                'id'      => 'model',
                'type'    => 'text',
                'title'   => '模型名称',
                'default' => 'gpt-4o-mini',
            ),
            array(
                'id'      => 'target_language',
                'type'    => 'select',
                'title'   => '默认目标翻译语言',
                'options' => zibll_ait_language_options(),
                'default' => '简体中文',
                'desc'    => '未在前端选择语言时（或关闭语言选择器时）使用的默认翻译语言。',
            ),
            array(
                'id'         => 'target_languages',
                'type'       => 'select',
                'title'      => '可选目标语言（前端展示）',
                'options'    => zibll_ait_language_options(),
                'chosen'     => true,
                'multiple'   => true,
                'default'    => array('简体中文', 'English', '日本語', '한국어'),
                'desc'       => '开启评论或文章的"语言选择器"后，访客可从中选择翻译目标语言；留空则只使用默认语言。',
            ),
            array(
                'id'      => 'language_selector',
                'type'    => 'switcher',
                'title'   => '评论区显示语言选择器',
                'default' => false,
                'desc'    => '开启后，评论区的翻译按钮旁会显示语言下拉框，访客可自主选择目标语言',
                'dependency' => array('button_enabled', '==', '1'),
            ),
            array(
                'id'      => 'temperature',
                'type'    => 'slider',
                'title'   => '随机性 (temperature)',
                'min'     => 0,
                'max'     => 1,
                'step'    => 0.1,
                'default' => 0.3,
                'unit'    => '',
            ),
        ),
    ));
}

// 文件被主文件在 init 阶段 require 时直接注册（CSF 此时已可用）
zibll_ait_create_options();
