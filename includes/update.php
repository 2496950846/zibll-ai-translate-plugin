<?php
/**
 * 插件在线更新（检测 + 一键升级）。
 *
 * 通过 GitHub Release API 检测最新版本并提供后台一键升级功能。
 * 仿照 zibll-plugin 的 CFS_Module::update() 样式实现。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 获取 GitHub Release 数据（带缓存）
 * 优先直连 GitHub，失败后自动切换国内镜像代理重试。
 */
function zibll_ait_get_github_release()
{
    $cache_key = 'zibll_ait_github_release';
    $cached = get_transient($cache_key);
    if (false !== $cached) {
        return $cached;
    }

    $base_url = 'https://api.github.com/repos/' . ZIBLL_AIT_REPO . '/releases/tags/' . ZIBLL_AIT_TAG;
    // 主镜像与备用镜像
    $proxies = array();
    if (ZIBLL_AIT_PROXY) {
        $proxies[] = rtrim(ZIBLL_AIT_PROXY, '/') . '/' . ltrim($base_url, 'http://');
    }
    if (ZIBLL_AIT_PROXY_BAK && ZIBLL_AIT_PROXY_BAK !== ZIBLL_AIT_PROXY) {
        $proxies[] = rtrim(ZIBLL_AIT_PROXY_BAK, '/') . '/' . ltrim($base_url, 'http://');
    }

    // 依次尝试：直连 → 主镜像 → 备用镜像
    $attempts = array($base_url);
    $attempts = array_merge($attempts, $proxies);

    foreach ($attempts as $attempt_url) {
        $response = wp_remote_get($attempt_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
            'sslverify' => false,
        ));

        if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if ($data && isset($data['tag_name'])) {
                set_transient($cache_key, $data, HOUR_IN_SECONDS);
                return $data;
            }
        }

        // 首次失败后用更长超时重试同 URL
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            $retry = wp_remote_get($attempt_url, array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                ),
                'sslverify' => false,
            ));
            if (!is_wp_error($retry) && 200 === (int) wp_remote_retrieve_response_code($retry)) {
                $body = wp_remote_retrieve_body($retry);
                $data = json_decode($body, true);
                if ($data && isset($data['tag_name'])) {
                    set_transient($cache_key, $data, HOUR_IN_SECONDS);
                    return $data;
                }
            }
        }
    }

    error_log('[ZibllAI翻译] GitHub API 直连与所有镜像代理均失败');
    set_transient($cache_key, false, HOUR_IN_SECONDS);
    return false;
}

/**
 * 比较两个版本号（支持 v/V 前缀）
 */
function zibll_ait_version_compare($a, $b)
{
    $norm = function ($v) {
        $v = preg_replace('/^[vV]/', '', trim((string) $v));
        return array_pad(array_map('intval', explode('.', $v)), 3, 0);
    };
    $pa = $norm($a);
    $pb = $norm($b);
    for ($i = 0; $i < 3; $i++) {
        if ($pa[$i] < $pb[$i]) return 1;
        if ($pa[$i] > $pb[$i]) return -1;
    }
    return 0;
}

/**
 * 检测是否有可用更新
 *
 * @param bool $force 是否强制刷新缓存
 * @return array|false 有更新返回数据，否则 false
 */
function zibll_ait_check_update($force = false)
{
    $current_version = ZIBLL_AIT_PLUGIN_VERSION;
    $skipped = (string) get_option('zibll_ait_skipped_version', '');

    $release = zibll_ait_get_github_release();
    if (!$release) {
        return false;
    }

    // tag_name 是版本号（如 v1.0.0），直接用 tag_name 提取版本号而非 name
    $remote_version = ltrim($release['tag_name'], 'vV');

    if (zibll_ait_version_compare($current_version, $remote_version) >= 0) {
        return false;
    }

    if ($skipped === $remote_version) {
        return false;
    }

    return array(
        'current'          => $current_version,
        'latest'           => $remote_version,
        'version'          => $remote_version,
        'tag'              => $release['tag_name'],
        'name'             => $release['name'],
        'body'             => $release['body'] ?? '',
        'download_url'     => $release['zipball_url'],
        'html_url'         => $release['html_url'],
        'update_description' => $release['body'] ?? '',
        'update_content'   => ($release['body'] ?? '')
            ? '<p>' . nl2br(esc_html($release['body'])) . '</p>'
            : '<p>本次更新为 Bug 修复与功能优化，请及时升级。</p>',
    );
}

/**
 * 忽略某版本更新
 */
function zibll_ait_skip_update($version)
{
    update_option('zibll_ait_skipped_version', sanitize_text_field((string) $version), false);
}

/**
 * 渲染 CSF 更新字段数组（供 admin-options.php 调用）
 */
function zibll_ait_update_csf_fields()
{
    $current = ZIBLL_AIT_PLUGIN_VERSION;
    $data = zibll_ait_check_update(false);

    if ($data) {
        $notice = '<div class="ajax-form">'
            . '<p style="color:#ff2f86"><i class="csf-tab-icon fa fa-cloud-upload fa-2x"></i></p>'
            . '<p><b>' . sprintf(
                '当前插件版本：V%s，可更新到最新版本：%s',
                esc_html($current),
                '<code style="color:#ff1919;background:#fbeeee;font-size:16px;">V' . esc_html($data['version']) . '</code>'
            ) . '</b></p>'
            . ($data['update_description'] ? '<p>' . esc_html($data['update_description']) . '</p>' : '')
            . '<div>'
            . '<input type="hidden" ajax-name="action" value="zibll_ait_admin_skip_update">'
            . '<input type="hidden" ajax-name="version" value="' . esc_attr($data['version']) . '">'
            . '<div class="progress"><div class="progress-bar"></div></div>'
            . '<p class="ajax-notice"></p>'
            . '<a href="javascript:;" class="but jb-blue mr10 zibll-ait-online-update" data-version="' . esc_attr($data['version']) . '"><i class="fa fa-cloud-download fa-fw"></i> 在线更新</a>'
            . '<a href="javascript:;" class="but c-yellow ajax-submit"><i class="fa fa-ban fa-fw"></i> 忽略此次更新</a>'
            . '</div>'
            . '<div style="text-align:right;font-size:12px;opacity:.5;"><a style="color:inherit;" target="_blank" href="' . esc_url($data['html_url']) . '">查看 GitHub Release</a></div>'
            . '</div>';

        $log = '<div class="box-theme">' . $data['update_content'] . '</div>';

        return array(
            array(
                'type'    => 'notice',
                'style'   => 'info',
                'content' => $notice,
            ),
            array(
                'title'   => '更新日志',
                'type'    => 'content',
                'content' => $log,
            ),
        );
    }

    // 已是最新版
    $notice = '<div class="ajax-form">'
        . '<h3 class="c-red"><i class="fa fa-thumbs-o-up fa-fw" aria-hidden="true"></i> 当前插件已经是最新版啦</h3>'
        . '<p><b>当前插件版本：V' . esc_html($current) . '</b></p>'
        . '<p class="ajax-notice"></p>'
        . '<p><a href="javascript:;" class="but jb-blue ajax-submit">检测更新</a></p>'
        . '<input type="hidden" ajax-name="action" value="zibll_ait_admin_detect_update">'
        . '</div>';

    $proxy_hint = '';
    if (ZIBLL_AIT_PROXY) {
        $proxy_hint = '<p class="muted-2-color">当前使用镜像代理检测：<code>' . esc_html(rtrim(ZIBLL_AIT_PROXY, '/')) . '</code>。若直连 GitHub 正常，可留空此常量改用直连。</p>';
    }

    return array(
        array(
            'type'    => 'notice',
            'style'   => 'info',
            'content' => $notice,
        ),
        array(
            'title'   => '说明',
            'type'    => 'content',
            'content' => '<p>插件更新来源为 GitHub Release（<a href="' . esc_url(ZIBLL_AIT_REPO_URL) . '" target="_blank" rel="noopener">子比AI翻译插件</a>，Tag: <code>' . esc_html(ZIBLL_AIT_TAG) . '</code>）。</p>'
                . '<p>点击「检测更新」立即检查；有新版时点「在线更新」自动下载并覆盖升级。</p>'
                . $proxy_hint
                . '<p class="muted-2-color">升级前建议备份插件目录与数据库；在线更新过程请勿刷新页面。</p>',
        ),
    );
}

/**
 * Ajax：检测更新（刷新缓存）
 */
function zibll_ait_ajax_detect_update()
{
    if (!current_user_can('manage_options')) {
        wp_send_json(array('error' => 1, 'msg' => '无权限'));
    }
    $data = zibll_ait_check_update(true);
    wp_send_json(array(
        'error' => $data ? 0 : 1,
        'msg'   => $data ? '发现新版本 V' . $data['version'] . '，请刷新页面查看更新面板' : '当前已是最新版本',
        'action' => '',
    ));
}
add_action('wp_ajax_zibll_ait_admin_detect_update', 'zibll_ait_ajax_detect_update');

/**
 * Ajax：忽略本次更新
 */
function zibll_ait_ajax_skip_update()
{
    if (!current_user_can('manage_options')) {
        wp_send_json(array('error' => 1, 'msg' => '无权限'));
    }
    $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';
    if ('' === $version) {
        wp_send_json(array('error' => 1, 'msg' => '缺少版本号'));
    }
    zibll_ait_skip_update($version);
    wp_send_json(array('error' => 0, 'msg' => '已忽略 V' . $version . '，刷新后不再提示'));
}
add_action('wp_ajax_zibll_ait_admin_skip_update', 'zibll_ait_ajax_skip_update');

/**
 * 将插件信息注入 WordPress 核心插件更新系统
 */
add_filter('pre_set_site_transient_update_plugins', 'zibll_ait_register_for_update_check');
function zibll_ait_register_for_update_check($transient)
{
    if (empty($transient->checked) || !is_object($transient)) {
        return $transient;
    }

    $release = zibll_ait_check_update(false);
    if (!$release) {
        return $transient;
    }

    $plugin_file = ZIBLL_AIT_PLUGIN_BASENAME;

    $response = new stdClass();
    $response->new_version      = $release['version'];
    $response->package          = $release['download_url'];
    $response->url              = $release['html_url'];
    $response->slug             = ZIBLL_AIT_PLUGIN_SLUG;
    $response->plugin           = $plugin_file;
    $response->sections         = array('description' => $release['update_content'] ?? '');

    $transient->response[$plugin_file] = $response;
    return $transient;
}

/**
 * 插件激活时：清除跳过版本记录与 GitHub Release 缓存
 */
register_activation_hook(__FILE__, 'zibll_ait_on_activate');
function zibll_ait_on_activate()
{
    delete_option('zibll_ait_skipped_version');
    delete_transient('zibll_ait_github_release');
}

/**
 * 插件停用前：清除相关缓存与选项，避免停用后再启用时读取旧状态
 */
register_deactivation_hook(__FILE__, 'zibll_ait_on_deactivate');
function zibll_ait_on_deactivate()
{
    delete_option('zibll_ait_skipped_version');
    delete_transient('zibll_ait_github_release');
}

/**
 * Ajax：在线更新（下载 GitHub Release zip 并覆盖插件目录）
 */
function zibll_ait_ajax_online_update()
{
    if (!current_user_can('manage_options')) {
        wp_send_json(array('error' => 1, 'msg' => '无权限'));
    }

    $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';
    if ('' === $version) {
        wp_send_json(array('error' => 1, 'msg' => '缺少版本号'));
    }

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    if (!$wp_filesystem || !is_object($wp_filesystem)) {
        wp_send_json(array('error' => 1, 'msg' => '文件系统初始化失败'));
    }

    $download_url = zibll_ait_get_download_url($version);
    if (!$download_url) {
        wp_send_json(array('error' => 1, 'msg' => '无法获取下载链接'));
    }

    // 创建临时目录（使用 tempnam 生成唯一文件名后改为目录）
    $temp_base = wp_tempdir();
    if (is_wp_error($temp_base)) {
        wp_send_json(array('error' => 1, 'msg' => '临时目录创建失败'));
    }
    $temp_dir = $temp_base . '/zibll_ait_' . md5($version . time());
    @mkdir($temp_dir, 0755, true);

    // 下载 zip
    $zip_file = $temp_dir . '/update.zip';
    $download = download_url($download_url);
    if (is_wp_error($download)) {
        @rmdir($temp_dir);
        wp_send_json(array('error' => 1, 'msg' => '下载失败：' . $download->get_error_message()));
    }

    // 解压
    $unzip = unzip_file($download, $temp_dir);
    @unlink($download);

    if (is_wp_error($unzip)) {
        @rmdir($temp_dir);
        wp_send_json(array('error' => 1, 'msg' => '解压失败：' . $unzip->get_error_message()));
    }

    // 找到解压后的插件根目录（去除顶层文件夹）
    $files = $wp_filesystem->dirlist($temp_dir);
    $plugin_root = null;
    foreach ($files as $name => $info) {
        if ($info['type'] === 'd') {
            $plugin_root = $temp_dir . '/' . $name;
            break;
        }
    }
    if (!$plugin_root) {
        $plugin_root = $temp_dir;
    }

    // 备份当前插件目录
    $plugin_dir = untrailingslashit(ZIBLL_AIT_PATH);
    $backup_dir = dirname($plugin_dir) . '/zibll-ai-translate-plugin-backup-' . gmdate('YmdHis');
    $wp_filesystem->copy($plugin_dir, $backup_dir, true);

    // 清空旧文件
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    // 复制新版本
    $src = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($src as $file) {
        $relative = str_replace($plugin_root . '/', '', $file->getPathname());
        if ($file->isDir()) {
            @mkdir($plugin_dir . '/' . $relative);
        } else {
            $wp_filesystem->copy($file->getPathname(), $plugin_dir . '/' . $relative, true);
        }
    }

    // 清理
    $wp_filesystem->delete($temp_dir, true);
    delete_option('zibll_ait_skipped_version');
    delete_transient('zibll_ait_github_release');

    wp_send_json(array(
        'error' => 0,
        'msg'   => '更新完成，插件已升级至 V' . esc_html($version) . '。为避免缓存问题，建议刷新页面。',
        'action' => '',
    ));
}
add_action('wp_ajax_zibll_ait_online_update', 'zibll_ait_ajax_online_update');

/**
 * 获取指定版本的下载 URL（自动拼接镜像代理前缀）
 */
function zibll_ait_get_download_url($version)
{
    // 确保 tag 有 v 前缀（GitHub 标准格式）
    $tag = 'v' . ltrim((string) $version, 'vV');
    // zipball API：https://api.github.com/repos/{repo}/zipball/{tag}
    $raw_url = 'https://api.github.com/repos/' . ZIBLL_AIT_REPO . '/zipball/' . $tag;
    // 若有镜像代理，则拼接前缀（优先主镜像，回退备用镜像）
    $proxy = ZIBLL_AIT_PROXY ? rtrim(ZIBLL_AIT_PROXY, '/') : (ZIBLL_AIT_PROXY_BAK ? rtrim(ZIBLL_AIT_PROXY_BAK, '/') : '');
    return $proxy ? $proxy . '/' . ltrim($raw_url, 'http://') : $raw_url;
}

/**
 * 后台注入：在线更新按钮 JS
 */
function zibll_ait_update_admin_js()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !isset($screen->id) || false === strpos((string) $screen->id, 'zibll_ait_options')) {
        return;
    }
    ?>
<script type="text/javascript">
(function ($) {
  'use strict';
  $(function () {
    $('body').on('click', '.zibll-ait-online-update', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      var _this = $(this);
      var _form = _this.parents('.ajax-form');
      var _progress = _form.find('.progress');
      var _notice = _form.find('.ajax-notice');
      var version = _this.attr('data-version') || '';

      if (_this.attr('disabled')) return false;
      if (!version) { _notice.html('<b class="c-red">缺少版本号，无法更新</b>'); return false; }

      if (!_this.attr('show-remind')) {
        _notice.html(
          '<div style="padding:15px;margin:0;" class="notice notice-warning"><b>' +
          '<span class="c-red em12"><i class="fa fa-info-circle fa-fw" aria-hidden="true"></i> 更新前请确认</span><ul>' +
          '<li>建议先备份插件目录与数据库</li>' +
          '<li>更新过程中请勿刷新或关闭页面</li>' +
          '<li>若出现白屏，请用备份目录（zibll-ai-translate-plugin-backup-*）手动回滚</li>' +
          '<li class="c-red">升级有风险，请谨慎操作</li>' +
          '<li class="c-blue" style="margin:10px 0 -15px 0;">再次点击「在线更新」开始升级</li>' +
          '</ul></b></div>'
        );
        _this.attr('show-remind', true);
        return false;
      }

      _this.attr('disabled', true).siblings('.ajax-submit').fadeOut(150);
      _progress.css('opacity', 1).find('.progress-bar').css({ width: '5%', transition: 'width .3s' });
      _notice.html('<b>正在下载并升级，请勿刷新页面…</b>');

      $.post(ajaxurl, {
        action: 'zibll_ait_online_update',
        version: version
      }, function (n) {
        try { if (typeof n === 'string') n = JSON.parse(n); } catch (err) {}
        if (n && n.error) {
          _notice.html('<b class="c-red">' + (n.msg || '更新失败') + '</b>');
          _this.attr('disabled', false).siblings('.ajax-submit').fadeIn(150);
          _progress.css('opacity', 0).find('.progress-bar').css({ width: '0' });
        } else {
          _progress.find('.progress-bar').css({ width: '100%', transition: 'width .5s' });
          _notice.html('<b class="c-blue">' + (n && n.msg ? n.msg : '更新完成') + '</b>');
        }
      }).fail(function (xhr) {
        _notice.html('<b class="c-red">请求失败（' + (xhr.status || '?') + '），请重试或手动下载更新</b>');
        _this.attr('disabled', false).siblings('.ajax-submit').fadeIn(150);
        _progress.css('opacity', 0).find('.progress-bar').css({ width: '0' });
      });
    });
  });
})(jQuery);
</script>
	<?php
}
add_action('admin_footer', 'zibll_ait_update_admin_js');
