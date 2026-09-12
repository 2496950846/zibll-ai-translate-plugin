<?php
/*
 * Plugin Name: 子比AI翻译插件
 * Description: 子比AI翻译插件 — 子比主题一键 AI 翻译：支持评论、文章、帖子等多种场景的外文翻译。需后台配置 AI 模型（OpenAI 兼容接口）。
 * Version: 1.0.0
 * Author: Zibll
 * Author QQ: 2496950846
 * Requires at least: 5.2
 * Requires PHP: 7.0
 * Text Domain: zibll-ai-translate-plugin
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 依赖主题未启用时的后台错误提示
 */
function zibll_ait_theme_error_notices()
{
    $con = '<div class="notice notice-error is-dismissible">
                <h3>插件错误！</h3>
                <p>此插件依赖于 zibll 主题，请先启用 zibll 子比主题</p></div>';
    echo $con;
}

// 仅在 zibll 父主题下运行（get_template 兼容子比子主题，子主题下 get_stylesheet 会返回子主题 slug）
if (function_exists('get_template') && get_template() != 'zibll') {
    add_action('admin_notices', 'zibll_ait_theme_error_notices');
    return;
}

// 插件基本信息常量
define('ZIBLL_AIT_PLUGIN_VERSION', '1.0.0');
define('ZIBLL_AIT_PLUGIN_NAME', '子比AI翻译插件');
define('ZIBLL_AIT_PLUGIN_DESCRIPTION', '子比主题 AI 翻译插件');
define('ZIBLL_AIT_PLUGIN_AUTHOR', 'Zibll');
define('ZIBLL_AIT_PLUGIN_SLUG', 'zibll-ai-translate-plugin');
define('ZIBLL_AIT_SLUG', ZIBLL_AIT_PLUGIN_SLUG);
define('ZIBLL_AIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// 路径与 URL 常量（唯一前缀 zibll_ait）
define('ZIBLL_AIT_PATH', plugin_dir_path(__FILE__));
define('ZIBLL_AIT_URL', plugin_dir_url(__FILE__));

// 在线更新：GitHub 仓库与 Release Tag（请替换为实际地址）
define('ZIBLL_AIT_REPO', '2496950846/zibll-ai-translate-plugin');
define('ZIBLL_AIT_TAG', 'V1.0.0');
define('ZIBLL_AIT_REPO_URL', 'https://github.com/2496950846/zibll-ai-translate-plugin/releases');
// 国内镜像代理（GitHub API / zipball 下载加速，留空则直连 GitHub）
define('ZIBLL_AIT_PROXY', 'https://mirror.ghproxy.com/');
// 备用镜像（主镜像不可用时自动切换）
define('ZIBLL_AIT_PROXY_BAK', 'https://gh-proxy.com/');

// 统一读取独立 option（严禁写入 zibll_options）
if (!function_exists('zibll_ait_options')) {
    function zibll_ait_options($key = '', $default = null)
    {
        static $options = null;
        if (null === $options) {
            $options = get_option('zibll_ait_options', array());
        }
        if ('' === $key) {
            return $options;
        }
        return isset($options[$key]) ? $options[$key] : $default;
    }
}

// 主题 functions.php 在 after_setup_theme 阶段加载内置 CSF；
// 插件在 init 阶段（晚于主题加载、早于 admin_menu）再加载业务文件，
// 此时 CSF 类已存在，可安全复用主题内置的 Codestar Framework。
add_action('init', 'zibll_ait_init', 10);

function zibll_ait_init()
{
    // 仅当主题内置 CSF 可用时加载，避免类未定义导致致命错误
    if (!class_exists('CSF')) {
        return;
    }

    $require_once = array(
        'includes/update.php',
        'includes/ai.php',
        'includes/functions.php',
        'includes/admin-options.php',
    );
    foreach ($require_once as $require) {
        require_once ZIBLL_AIT_PATH . $require;
    }
}

// 添加插件操作链接：在禁用按钮左侧添加"插件配置"绿色按钮
add_filter('plugin_action_links_' . ZIBLL_AIT_PLUGIN_BASENAME, 'zibll_ait_plugin_action_links');
function zibll_ait_plugin_action_links($links)
{
    $config_link = '<a href="' . esc_url(admin_url('admin.php?page=zibll_ait_options')) . '" style="color:#52c41a;font-weight:600;">插件配置</a>';
    array_unshift($links, $config_link);
    return $links;
}
