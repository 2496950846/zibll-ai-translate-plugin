<?php
/**
 * AI 接口调用、翻译缓存
 * 兼容任意 OpenAI Chat Completions 接口（OpenAI / DeepSeek / 自建等）
 * 支持纯文本（评论）与 HTML（文章/帖子正文）两种翻译场景。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 调用 AI 翻译接口
 * @param string $text   待翻译文本（评论为纯文本，文章/帖子为正文 HTML）
 * @param string $target 目标语言（前端可覆盖，传空则用后台默认语言）
 * @param array  $opts   选项：
 *                       - html  (bool)   是否保留 HTML 结构（文章/帖子正文用）
 *                       - limit (int)    截断长度，0 表示不截断
 * @return string|WP_Error 成功返回译文，失败返回 WP_Error
 */
function zibll_ait_call_ai($text, $target = '', $opts = array())
{
    $opts = wp_parse_args($opts, array(
        'html'  => false,
        'limit' => 4000,
    ));

    if ($opts['html']) {
        // 文章/帖子正文：保留 HTML，仅做首尾去空白与可选长度截断
        $text = trim($text);
        if ($opts['limit'] > 0) {
            $text = mb_substr($text, 0, $opts['limit']);
        }
    } else {
        // 评论：去除 HTML 标签并截断，避免过长
        $text = trim(strip_tags($text));
        $text = mb_substr($text, 0, $opts['limit'] > 0 ? $opts['limit'] : 4000);
    }

    if ('' === $text) {
        return new WP_Error('empty', '没有可翻译的内容');
    }

    $api_base = rtrim(zibll_ait_options('api_base', 'https://api.openai.com/v1'), '/');
    $api_key  = zibll_ait_options('api_key');
    $model    = zibll_ait_options('model', 'gpt-4o-mini');
    $target   = $target ?: zibll_ait_options('target_language', '简体中文');
    $temp     = (float) zibll_ait_options('temperature', 0.3);

    if (!$api_key) {
        return new WP_Error('no_key', '未配置 API Key');
    }

    if ($opts['html']) {
        $system = '你是一个翻译助手。请将用户提供的 HTML 文本翻译为' . $target .
            '。必须完整保留原有的 HTML 标签、属性和整体结构，只翻译其中可见的文本内容，' .
            '不要添加任何解释、引号或额外说明，也不要输出代码块标记。';
    } else {
        $system = '你是一个翻译助手。请将用户提供的文本翻译为' . $target .
            '。只输出译文本身，不要包含任何解释、引号或额外说明。';
    }

    $body = wp_json_encode(array(
        'model'    => $model,
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $text),
        ),
        'temperature' => $temp,
    ));

    $response = wp_remote_post($api_base . '/chat/completions', array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => $body,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);

    if (200 !== $code) {
        // 尝试解析 API 返回的错误详情（如 OpenAI 格式的错误信息）
        $error_detail = trim($raw);
        $decoded = json_decode($error_detail, true);
        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $error_detail = $decoded['error']['message'];
        }
        return new WP_Error('api_error', '接口返回错误（HTTP ' . $code . '）：' . $error_detail);
    }

    $data = json_decode($raw, true);
    if (empty($data['choices'][0]['message']['content'])) {
        return new WP_Error('api_empty', '接口未返回译文');
    }

    return trim($data['choices'][0]['message']['content']);
}

/**
 * 调用 AI 生成评论回复（以所选用户身份）
 * @param string $text 原评论内容
 * @return string|WP_Error 成功返回回复内容，失败返回 WP_Error
 */
function zibll_ait_call_ai_reply($text)
{
    $text = trim(strip_tags($text));
    $text = mb_substr($text, 0, 4000);

    if ('' === $text) {
        return new WP_Error('empty', '没有可回复的内容');
    }

    $api_base = rtrim(zibll_ait_options('api_base', 'https://api.openai.com/v1'), '/');
    $api_key  = zibll_ait_options('api_key');
    $model    = zibll_ait_options('model', 'gpt-4o-mini');
    $temp     = (float) zibll_ait_options('temperature', 0.3);
    $prompt   = trim(zibll_ait_options('reply_prompt'));
    if (!$prompt) {
        $prompt = '你是一个友好的网站客服兼作者助手。请根据用户评论的内容，给出简洁、得体、有帮助的回复，语气自然。只输出回复正文本身，不要包含任何解释、引号或额外说明。';
    }

    if (!$api_key) {
        return new WP_Error('no_key', '未配置 API Key');
    }

    $body = wp_json_encode(array(
        'model'    => $model,
        'messages' => array(
            array('role' => 'system', 'content' => $prompt),
            array('role' => 'user', 'content' => $text),
        ),
        'temperature' => $temp,
    ));

    $response = wp_remote_post($api_base . '/chat/completions', array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => $body,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);

    if (200 !== $code) {
        // 尝试解析 API 返回的错误详情（如 OpenAI 格式的错误信息）
        $error_detail = trim($raw);
        $decoded = json_decode($error_detail, true);
        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $error_detail = $decoded['error']['message'];
        }
        return new WP_Error('api_error', '接口返回错误（HTTP ' . $code . '）：' . $error_detail);
    }

    $data = json_decode($raw, true);
    if (empty($data['choices'][0]['message']['content'])) {
        return new WP_Error('api_empty', '接口未返回回复内容');
    }

    return trim($data['choices'][0]['message']['content']);
}

/**
 * 判断文本是否"外文"（不含中日韩字符）
 */
function zibll_ait_is_foreign($text)
{
    return !preg_match('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $text);
}

/**
 * 渲染评论译文展示块
 * @param string $text   译文内容
 * @param string $target 目标语言（用于展示标签）
 */
function zibll_ait_render_translation($text, $target = '')
{
    $text = wp_kses_post($text);
    $label = esc_html(($target ?: zibll_ait_options('target_language', '简体中文')) . '译文');
    return '<div class="zibll-ait-box">' .
        '<div class="zibll-ait-box-head flex ac">' .
        '<span class="zibll-ait-label but c-blue radius mr6">' . $label . '</span>' .
        '<button type="button" class="zibll-ait-copy but hollow c-yellow radius ml6" title="复制译文">' .
        '<i class="fa fa-clone mr6 fa-fw" aria-hidden="true"></i>复制</button>' .
        '</div>' .
        '<div class="zibll-ait-box-body mt10">' . $text . '</div>' .
        '<div class="zibll-ait-tip muted-2-color em09 mt5 text-center">内容由AI生成，仅供参考</div>' .
        '</div>';
}

/**
 * 渲染文章/帖子译文展示块
 * @param string $html   译文 HTML
 * @param string $target 目标语言（用于展示标签）
 */
function zibll_ait_render_post_translation($html, $target = '')
{
    $html  = wp_kses_post($html);
    $label = esc_html(($target ?: zibll_ait_options('target_language', '简体中文')) . '译文');
    return '<div class="zibll-ait-post-box">' .
        '<div class="zibll-ait-post-box-head flex ac">' .
        '<span class="zibll-ait-label but c-blue radius mr6">' . $label . '</span>' .
        '<div class="zibll-ait-post-box-actions flex1 flex je">' .
        '<button type="button" class="zibll-ait-copy but hollow c-yellow radius mr6">' .
        '<i class="fa fa-clone mr6 fa-fw" aria-hidden="true"></i>复制译文</button>' .
        '<button type="button" class="zibll-ait-hide but hollow muted-2-color radius">' .
        '<i class="fa fa-eye-slash mr6 fa-fw" aria-hidden="true"></i>隐藏译文</button>' .
        '</div>' .
        '</div>' .
        '<div class="zibll-ait-post-box-body mt10">' . $html . '</div>' .
        '<div class="zibll-ait-tip muted-2-color em09 mt5 text-center">内容由AI生成，仅供参考</div>' .
        '</div>';
}
