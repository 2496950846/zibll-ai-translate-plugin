(function ($) {
    'use strict';

    $(function () {
        // =========================================================
        // 一键翻译（评论）
        // =========================================================
        // 使用插件自带 zibll_ait.ajax_url，不再依赖主题 zib_ajax / _win 全局变量
        if (typeof zibll_ait === 'undefined' || !zibll_ait.ajax_url) {
            return;
        }

        // =========================================================
        // 音效播放
        // =========================================================
        var sound = null;
        function playSuccessSound() {
            if (!zibll_ait.sound_enabled || !zibll_ait.sound_url) {
                return;
            }
            try {
                if (!sound) {
                    sound = new Audio(zibll_ait.sound_url);
                }
                sound.volume = (zibll_ait.sound_volume || 50) / 100;
                sound.currentTime = 0;
                sound.play().catch(function() { /* 忽略自动播放限制 */ });
            } catch (e) {
                // 忽略错误
            }
        }

        // 调试日志：便于排查“文章翻译按钮不显示”问题（不影响功能）
        try {
            console.log('[zibll_ait] post_dropdown =', zibll_ait.post_dropdown);
        } catch (e) { /* ignore */ }

        // 是否需要先弹出语言选择框：开启“先选择语言”且配置了多于一种可选语言时才弹
        function needLangModal() {
            if (!zibll_ait.require_lang) {
                return false;
            }
            var langs = zibll_ait.langs || [];
            return langs.length > 1;
        }

        // 评论“翻译”：多语言时先选择语言，再执行翻译
        $('body').on('click', '.zibll-ait-translate', function () {
            var $btn = $(this);
            // 防止重复点击：上一次翻译尚未结束时不再触发
            if ($btn.data('zibll-ait-loading')) {
                return;
            }
            var mode = $btn.data('mode') || 'modal';
            var action = $btn.attr('form-action');
            var data = {};
            try {
                data = $btn.attr('form-data') ? JSON.parse($btn.attr('form-data')) : {};
            } catch (e) {
                data = {};
            }

            if (!action) {
                return;
            }

            // 读取同条目下的目标语言选择（旧版内联下拉框，未启用“先选择语言”时生效）
            var $lang = $btn.closest('.zibll-ait-action').find('.zibll-ait-lang');
            if ($lang.length && $lang.val()) {
                data.target = $lang.val();
            }

            // 多语言且开启“先选择语言”：弹出语言选择框，选择后再翻译
            if (needLangModal()) {
                pendingTranslate = { type: 'comment', btn: $btn, action: action, mode: mode, data: data };
                if (openLangModal()) {
                    return;
                }
            }

            // 单语言或直接翻译：取首个可选语言（若有）作为目标语言
            var langs = zibll_ait.langs || [];
            var target = langs.length === 1 ? langs[0] : '';
            runCommentTranslate($btn, action, mode, data, target);
        });

        /**
         * 执行评论翻译 AJAX（点击翻译、选择语言后调用）
         */
        function runCommentTranslate($btn, action, mode, data, target) {
            if (!action || $btn.data('zibll-ait-loading')) {
                return;
            }
            if (target) {
                data.target = target;
            }

            // 标记按钮为加载中并禁用，同时弹出蓝色「翻译中…」弹窗
            $btn.data('zibll-ait-loading', true).attr('disabled', 'disabled');
            openLoadingModal();

            $.ajax({
                url: zibll_ait.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: $.extend({ action: action }, data),
                success: function (res) {
                    // 无论成功失败，先关闭「翻译中…」弹窗
                    closeLoadingModal();

                    if (!res || res.success === false) {
                        var msg = (res && res.data) ? res.data : '操作失败';
                        if (typeof notyf === 'function') {
                            notyf(msg, 'danger');
                        }
                        resetTranslateBtn($btn);
                        return;
                    }
                    if (!res.data || !res.data.html) {
                        resetTranslateBtn($btn);
                        return;
                    }

                    // 播放成功音效
                    playSuccessSound();

                    if (mode === 'reply') {
                        handleTranslateReply($btn, res.data.html);
                    } else if (mode === 'modal') {
                        handleTranslateModal($btn, res.data.html);
                    } else {
                        handleTranslateInline($btn, res.data.html);
                    }
                },
                error: function () {
                    closeLoadingModal();
                    if (typeof notyf === 'function') {
                        notyf('请求失败，请稍后重试', 'danger');
                    }
                    resetTranslateBtn($btn);
                }
            });
        }

        // 点击语言选择器时不冒泡，避免误关评论操作菜单
        $('body').on('click', '.zibll-ait-lang', function (e) {
            e.stopPropagation();
        });

        /**
         * 模式一：AI 以回复用户身份，在原评论下生成一条译文回复
         */
        function handleTranslateReply($btn, html) {
            var id = $btn.data('id');
            if (!id) {
                try {
                    var fd = JSON.parse($btn.attr('form-data') || '{}');
                    id = fd.comment_id;
                } catch (e) {
                    id = 0;
                }
            }

            var $parentItem = $('#div-comment-' + id);
            if (!$parentItem.length) {
                console.log('[zibll_ait] Parent comment not found:', id);
                return;
            }

            // 解析服务端返回的 HTML，提取评论节点
            var $temp = $('<div>').html(html);
            console.log('[zibll_ait] Server HTML:', html.substring(0, 200));
            
            // 优先查找带 div-comment- ID 的节点（子比主题标准结构）
            var $reply = $temp.find('[id^="div-comment-"]').first();
            if (!$reply.length) {
                $reply = $temp.find('li.comment').first();
            }
            if (!$reply.length) {
                $reply = $temp.find('.comment').first();
            }
            if (!$reply.length) {
                // 如果返回的是 ul/ol 包裹的多个评论，取第一个 li
                $reply = $temp.find('ul.children > li, ol.children > li').first();
            }
            if (!$reply.length) {
                // 最后尝试取第一个子元素
                $reply = $temp.children().first();
            }
            console.log('[zibll_ait] Found reply:', $reply.length ? $reply.attr('id') || $reply.attr('class') : 'none');
            
            if (!$reply.length) {
                return;
            }

            // 移除可能存在的额外包装元素（如 ul.children）
            if ($reply.is('ul.children, ol.children, div')) {
                $reply = $reply.children('li.comment, [id^="div-comment-"]').first();
            }
            if (!$reply.length) {
                return;
            }

            // 确保评论有唯一的 ID（从属性或 data 读取）
            var commentId = $reply.attr('id') || $reply.data('comment-id');
            if (!commentId) {
                commentId = 'div-comment-' + id + '-' + Date.now();
                $reply.attr('id', commentId);
            }

            // 复用主题评论区的 children 容器；不存在则新建
            var $children = $parentItem.children('ul.children');
            if (!$children.length) {
                $children = $('<ul class="children"></ul>');
                $parentItem.append($children);
            }
            $children.append($reply);

            // 注入免责声明（必须在显示前完成）
            // 1. 先清除服务端可能已写入正文的提示（避免样式/重复问题），
            // 2. 再由 JS 统一在译文回复末尾追加标准提示，确保可见、样式一致。
            $reply.find('.zibll-ait-tip').remove();
            $reply.find('em, i, b, strong, span').filter(function () {
                return $(this).text().indexOf('内容由AI生成，仅供参考') !== -1;
            }).remove();
            if (!$reply.find('.zibll-ait-tip').length && $reply.text().indexOf('内容由AI生成，仅供参考') === -1) {
                $reply.append('<div class="zibll-ait-tip muted-2-color em09 mt5 text-center">内容由AI生成，仅供参考</div>');
            }
            // 给评论添加标记类
            $reply.addClass('zibll-ait-translation-reply');

            // 确保评论可见（移除可能的 hidden/display:none）
            $reply.css({'display': '', 'visibility': ''});
            
            // 强制浏览器重排，确保样式正确应用
            void $reply[0].offsetWidth;
            
            // 滚动到新插入的评论
            setTimeout(function() {
                $('html, body').animate({
                    scrollTop: $reply.offset().top - 100
                }, 200);
            }, 50);
            
            // 触发主题评论区的重新初始化（如果存在相关函数）
            if (typeof zib_refresh_comment === 'function') {
                zib_refresh_comment($reply);
            }
            // 触发自定义事件，允许其他插件监听
            $('body').trigger('zibll_ait_comment_inserted', [$reply]);
            
            console.log('[zibll_ait] Comment inserted:', $reply.attr('id'));

            // 关闭三个点下拉菜单，并将按钮标记为已完成
            $btn.closest('.dropdown').removeClass('open');
            $btn.closest('li').html('<span class="muted-2-color em09"><i class="fa fa-check mr6"></i>已生成译文</span>');
        }

        /**
         * 模式二：仅在原评论下方内联显示译文
         */
        function handleTranslateInline($btn, html) {
            var id = $btn.data('id');
            if (!id) {
                try {
                    var fd = JSON.parse($btn.attr('form-data') || '{}');
                    id = fd.comment_id;
                } catch (e) {
                    id = 0;
                }
            }
            var $content = $('#comment-content-' + id);
            if (!$content.length) {
                return;
            }
            // 译文框（zibll_ait_render_translation 已自带“内容由AI生成，仅供参考”提示，无需重复追加）
            $content.next('.zibll-ait-result').remove();
            $content.after('<div class="zibll-ait-result mt10">' + html + '</div>');

            $btn.closest('.dropdown').removeClass('open');
            $btn.closest('li').html('<span class="muted-2-color em09"><i class="fa fa-check mr6"></i>已翻译</span>');
        }

        /**
         * 模式三：在弹窗（Modal）中显示译文，不改动评论区
         */
        function handleTranslateModal($btn, html) {
            openTranslateModal(html);
            $btn.closest('.dropdown').removeClass('open');
            // 翻译成功后恢复按钮状态（蓝色 loading 弹窗已关闭）
            resetTranslateBtn($btn);
        }

        // =========================================================
        // 文章 / 帖子翻译（正文下方“翻译菜单”）
        // =========================================================
        // 点击主按钮：多语言且开启“先选择语言”时弹出语言框；旧版下拉菜单仍可展开；单语言直接翻译
        $('body').on('click', '.zibll-ait-post-translate', function (e) {
            e.preventDefault();
            var $btn = $(this);

            // 已显示译文：点击主按钮切换收起 / 展开
            if ($btn.data('zibll-ait-shown')) {
                togglePostResult($btn, false);
                return;
            }

            // 多语言且开启“先选择语言”：弹出语言选择框，选择后再翻译
            if (needLangModal()) {
                pendingTranslate = { type: 'post', btn: $btn };
                if (openLangModal()) {
                    return;
                }
            }

            // 含语言菜单（旧版下拉，未开启“先选择语言”时生效）：点击仅展开 / 收起下拉
            var $menu = $btn.closest('.zibll-ait-post-menu').find('.zibll-ait-lang-menu');
            if ($menu.length) {
                $btn.closest('.dropup').toggleClass('open');
                return;
            }

            // 单语言：直接翻译该语言（或默认语言）
            var langs = zibll_ait.langs || [];
            var target = langs.length === 1 ? langs[0] : '';
            doPostTranslate($btn, target);
        });

        // 选择下拉菜单中的目标语言，触发翻译
        $('body').on('click', '.zibll-ait-lang-item', function (e) {
            e.preventDefault();
            var $item = $(this);
            var $wrap = $item.closest('.dropup');
            $wrap.removeClass('open');
            var $btn = $wrap.find('.zibll-ait-post-translate');
            doPostTranslate($btn, $item.data('lang'));
        });

        // 点击页面其它位置收起语言菜单
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.zibll-ait-post-menu').length) {
                $('.zibll-ait-post-menu.open').removeClass('open');
            }
        });

        // =========================================================
        // 文章“三个点”菜单中的“文章翻译”按钮
        // =========================================================
        $('body').on('click', '.zibll-ait-post-dropdown', function (e) {
            e.preventDefault();
            var $item = $(this);

            // 关闭所在的三个点下拉菜单（兼容 dropdown / dropup 两种方向）
            $item.closest('.dropdown, .dropup').removeClass('open');

            // 多语言且开启“先选择语言”：弹出语言选择框，选择后再翻译
            if (needLangModal()) {
                pendingTranslate = { type: 'post_dropdown', item: $item };
                if (openLangModal()) {
                    return;
                }
            }

            runPostDropdown($item, '');
        });

        /**
         * 执行文章/帖子翻译 AJAX（来自“三个点”菜单的“文章翻译”按钮，选择语言后调用）
         */
        function runPostDropdown($item, target) {
            var postId = $item.data('id');
            var nonce = $item.data('nonce');
            var mode = $item.data('mode') || 'inline';

            if (!postId || !nonce || $item.data('zibll-ait-loading')) {
                return;
            }
            $item.data('zibll-ait-loading', true);
            openLoadingModal();

            $.ajax({
                url: zibll_ait.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_ait_translate_post',
                    post_id: postId,
                    target: target,
                    _wpnonce: nonce
                },
                success: function (res) {
                    closeLoadingModal();
                    $item.removeData('zibll-ait-loading');

                    if (!res || res.success === false) {
                        var msg = (res && res.data) ? res.data : '操作失败';
                        if (typeof notyf === 'function') {
                            notyf(msg, 'danger');
                        }
                        return;
                    }

                    // 播放成功音效
                    playSuccessSound();

                    var html = (res.data && res.data.html) ? res.data.html : '';
                    if (mode === 'modal') {
                        openTranslateModal(html);
                        return;
                    }

                    // 内联模式：在文章正文末尾插入译文（找不到正文容器时回退为弹窗）
                    var $content = $('.article-content').first();
                    if (!$content.length) {
                        openTranslateModal(html);
                        return;
                    }
                    $content.next('.zibll-ait-post-result').remove();
                    $content.after('<div class="zibll-ait-post-result mt10">' + html + '</div>');
                },
                error: function () {
                    closeLoadingModal();
                    $item.removeData('zibll-ait-loading');
                    if (typeof notyf === 'function') {
                        notyf('请求失败，请稍后重试', 'danger');
                    }
                }
            });
        }

        // 把“文章翻译”注入文章“三个点”下拉菜单（无需修改主题模板）
        // 主题 zib_get_post_more_dropdown() 不提供过滤器（插件端 zib_post_more_dropdown_items
        // 钩子不会被主题调用），且单篇元信息框会被主题通过 AJAX 刷新/重渲染，
        // 因此只能依靠前端注入，并且要做成“自愈”式：菜单被重渲染后必须重新补回按钮。
        ensurePostDropdownItem();

        // 兜底：页面加载后延迟重试，应对菜单延迟渲染、AJAX 刷新等时序问题
        setTimeout(ensurePostDropdownItem, 300);
        setTimeout(ensurePostDropdownItem, 1200);

        /**
         * 确保文章“三个点”菜单中存在“文章翻译”按钮（幂等）
         *
         * 主题会通过 AJAX 刷新单篇元信息框（点赞 / 收藏 / 浏览数等），整体重渲染
         * .single-metabox，导致此前注入的按钮被清除而“消失”。本函数设计为幂等：
         * 已存在则跳过，缺失则补回；并在下拉展开、区域变动、延迟重试时反复调用，
         * 保证按钮始终存在。数据来自 wp_localize_script 输出的 zibll_ait.post_dropdown。
         */
        function ensurePostDropdownItem() {
            var cfg = zibll_ait.post_dropdown;
            if (!cfg || !cfg.enabled || !cfg.post_id || !cfg.nonce) {
                return;
            }
            // 已存在（可能在三个点菜单内，或独立兜底按钮）则不重复添加
            if ($('.zibll-ait-post-dropdown').first().length) {
                return;
            }
            // 1) 优先注入到“三个点”下拉菜单（主题仅在该菜单存在时可用）
            var $trigger = $('.single-metabox .post-drop-meta').first();
            if (!$trigger.length) {
                $trigger = $('.post-drop-meta').first();
            }
            if ($trigger.length) {
                var $menu = $trigger.closest('.dropdown, .dropup').find('.dropdown-menu').first();
                if ($menu.length) {
                    appendPostDropdownItem($menu, cfg);
                    return;
                }
            }
            // 2) 兜底：访客 / 无权限用户没有“三个点”菜单（主题直接不渲染），
            //    直接在单篇元信息框追加一个独立“文章翻译”按钮，保证功能始终可见可用。
            var $box = $('.single-metabox').first();
            if (!$box.length) {
                return;
            }
            var $btn = $('<a href="javascript:;" class="zibll-ait-post-dropdown c-blue but hollow radius ml6">' +
                '<i class="fa fa-language mr6 fa-fw" aria-hidden="true"></i>' +
                escHtml(cfg.label || '文章翻译') + '</a>');
            $btn.attr('data-id', cfg.post_id)
                .attr('data-nonce', cfg.nonce)
                .attr('data-mode', cfg.mode || 'inline');
            var $metas = $box.find('.post-metas').first();
            if ($metas.length) {
                $metas.after($btn);
            } else {
                $box.append($btn);
            }
        }

        // 生成并追加“文章翻译”菜单项（<li><a>）
        function appendPostDropdownItem($menu, cfg) {
            var $li = $('<li><a href="javascript:;" class="zibll-ait-post-dropdown c-blue">' +
                '<i class="fa fa-language mr6 fa-fw" aria-hidden="true"></i>' +
                escHtml(cfg.label || '文章翻译') + '</a></li>');
            $li.find('a')
                .attr('data-id', cfg.post_id)
                .attr('data-nonce', cfg.nonce)
                .attr('data-mode', cfg.mode || 'inline');
            $menu.append($li);
        }

        // 点击“三个点”时（无论菜单是否已渲染 / 被刷新过）立即确保按钮存在，
        // 保证用户展开菜单时“文章翻译”一定在。用委托事件，菜单被重渲染也依然生效。
        $(document).on('click', '.post-drop-meta', function () {
            ensurePostDropdownItem();
        });

        // 监听单篇元信息框的变动：主题 AJAX 刷新点赞 / 收藏等会重渲染该区域，
        // 导致注入的按钮被清除，此处监测到后重新注入，避免按钮“消失”。
        var $metabox = $('.single-metabox').first();
        if ($metabox.length && typeof MutationObserver !== 'undefined') {
            var aitEnsureTimer = null;
            var aitObserver = new MutationObserver(function () {
                if (aitEnsureTimer) {
                    return;
                }
                aitEnsureTimer = setTimeout(function () {
                    aitEnsureTimer = null;
                    ensurePostDropdownItem();
                }, 60);
            });
            aitObserver.observe($metabox[0], { childList: true, subtree: true });
        }

        // 复制译文（使用命名空间事件避免重复绑定）
        $('body').off('click.zibll-ait-copy').on('click.zibll-ait-copy', '.zibll-ait-copy', function (e) {
            e.preventDefault();
            var $btn = $(this);
            // 防止重复点击
            if ($btn.data('zibll-ait-copying')) {
                return;
            }
            $btn.data('zibll-ait-copying', true);

            // 根据按钮所在容器类型确定正确的 body 选择器
            var $postBox = $btn.closest('.zibll-ait-post-box');
            var $commentBox = $btn.closest('.zibll-ait-box');
            var $body;
            if ($postBox.length) {
                // 文章/帖子译文：优先找内联容器，其次找弹窗内容容器
                $body = $postBox.find('.zibll-ait-post-box-body').first();
            } else if ($commentBox.length) {
                // 评论译文
                $body = $commentBox.find('.zibll-ait-box-body').first();
            }
            // 兜底：按钮可能直接位于 .zibll-ait-modal-content 中（弹窗模式）
            if (!$body.length) {
                var $modalContent = $btn.closest('.zibll-ait-modal-content');
                if ($modalContent.length) {
                    $body = $modalContent.find('.zibll-ait-post-box-body, .zibll-ait-box-body').first();
                }
            }
            var text = $body && $body.length ? $body.text() : '';

            if (!text) {
                $btn.removeData('zibll-ait-copying');
                if (typeof notyf === 'function') {
                    notyf('未找到可复制的译文内容', 'danger');
                }
                return;
            }

            copyText(text, function () {
                $btn.removeData('zibll-ait-copying');
                if (typeof notyf === 'function') {
                    // 移除旧提示，避免重复显示
                    var $existing = $('.notyf__toast[data-type="success"]');
                    if ($existing.length) {
                        $existing.remove();
                    }
                    notyf('译文已复制到剪贴板', 'success');
                }
            });
        });

        // 隐藏译文：缓存 HTML，切换为「显示译文」按钮
        $('body').on('click', '.zibll-ait-hide', function (e) {
            e.preventDefault();
            var $box = $(this).closest('.zibll-ait-post-box');
            var $bar = $box.closest('.zibll-ait-post-result').prev('.zibll-ait-post-bar');
            // 在移除前缓存译文 HTML，供「显示译文」恢复使用
            var html = $box.html();
            $box.closest('.zibll-ait-post-result').data('zibll-ait-cached-html', html);
            $box.slideUp(200, function () {
                $box.remove();
            });
            var $btn = $bar.find('.zibll-ait-post-translate');
            if ($btn.length) {
                $btn.removeData('zibll-ait-shown');
                // 切换类名：隐藏后改为 .zibll-ait-show，避免再次点击触发翻译请求
                $btn.removeClass('zibll-ait-post-translate').addClass('zibll-ait-show');
                var caret = $btn.data('has-menu') === '1'
                    ? '<i class="fa fa-angle-down ml6" aria-hidden="true"></i>' : '';
                $btn.html('<i class="fa fa-eye mr6 fa-fw"></i>显示译文' + caret);
            }
        });

        /**
         * 执行文章/帖子翻译 AJAX
         */
        function doPostTranslate($btn, target) {
            if ($btn.data('zibll-ait-loading')) {
                return;
            }
            var postId = $btn.data('id');
            var nonce = $btn.data('nonce');
            var mode = $btn.data('mode') || 'inline';
            var $bar = $btn.closest('.zibll-ait-post-bar');
            var $result = $bar.next('.zibll-ait-post-result');

            if (!postId || !nonce) {
                return;
            }

            // 若已显示其它译文，先收起，避免堆叠
            if ($btn.data('zibll-ait-shown')) {
                $result.slideUp(200);
                $btn.data('zibll-ait-shown', false);
                resetPostBtn($btn);
            }

            $btn.data('zibll-ait-loading', true).attr('disabled', 'disabled');
            openLoadingModal();

            $.ajax({
                url: zibll_ait.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_ait_translate_post',
                    post_id: postId,
                    target: target,
                    _wpnonce: nonce
                },
                success: function (res) {
                    closeLoadingModal();
                    $btn.removeData('zibll-ait-loading').removeAttr('disabled');

                    if (!res || res.success === false) {
                        var msg = (res && res.data) ? res.data : '操作失败';
                        if (typeof notyf === 'function') {
                            notyf(msg, 'danger');
                        }
                        return;
                    }

                    // 播放成功音效
                    playSuccessSound();

                    var html = (res.data && res.data.html) ? res.data.html : '';
                    if (mode === 'modal') {
                        openTranslateModal(html);
                    } else {
                        $result.html(html).hide().slideDown(200);
                        $btn.removeData('zibll-ait-shown')
                            .data('zibll-ait-cached-html', html)
                            .html('<i class="fa fa-eye-slash mr6 fa-fw"></i>隐藏译文');
                    }
                },
                error: function () {
                    closeLoadingModal();
                    $btn.removeData('zibll-ait-loading').removeAttr('disabled');
                    if (typeof notyf === 'function') {
                        notyf('请求失败，请稍后重试', 'danger');
                    }
                }
            });
        }

        // 收起 / 展开译文（切换显示）
        function togglePostResult($btn, show) {
            var $bar = $btn.closest('.zibll-ait-post-bar');
            var $result = $bar.next('.zibll-ait-post-result');
            if (show) {
                $result.slideDown(200);
                $btn.data('zibll-ait-shown', true)
                    .html('<i class="fa fa-eye-slash mr6 fa-fw"></i>隐藏译文');
            } else {
                $result.slideUp(200);
                $btn.data('zibll-ait-shown', false);
                resetPostBtn($btn);
            }
        }

        // 还原"翻译"按钮文字（根据是否含语言菜单显示下拉箭头）
        function resetPostBtn($btn) {
            $btn.removeData('zibll-ait-shown');
            var caret = $btn.data('has-menu') === '1'
                ? '<i class="fa fa-angle-down ml6" aria-hidden="true"></i>' : '';
            $btn.html('<i class="fa fa-language mr6 fa-fw" aria-hidden="true"></i>翻译' + caret);
        }

        // 显示译文：从缓存恢复译文内容
        $('body').on('click', '.zibll-ait-show', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $bar = $btn.closest('.zibll-ait-post-bar');
            var $result = $bar.next('.zibll-ait-post-result');
            var html = $result.data('zibll-ait-cached-html') || '';
            if (!html) {
                return;
            }
            $result.html(html).hide().slideDown(200);
            // 恢复后改回 .zibll-ait-post-translate，以便后续点击可重新翻译
            $btn.removeClass('zibll-ait-show').addClass('zibll-ait-post-translate');
            $btn.data('zibll-ait-shown', true)
                .html('<i class="fa fa-eye-slash mr6 fa-fw"></i>隐藏译文');
        });

        /**
         * 复制文本到剪贴板（优先 Clipboard API，失败回退 execCommand，最终提示用户手动复制）
         */
        function copyText(text, cb) {
            text = (text == null) ? '' : String(text);
            var called = false;
            var safeCb = function () {
                if (called) return;
                called = true;
                if (cb) cb();
            };

            if (!text) {
                safeCb();
                return;
            }

            // 优先尝试现代 Clipboard API（需要 HTTPS 上下文）
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(text).then(
                    function () { safeCb(); },
                    function () { fallbackCopy(text, safeCb); }
                );
                return;
            }

            // 回退：execCommand 方式
            fallbackCopy(text, safeCb);
        }

        function fallbackCopy(text, cb) {
            // 创建临时 textarea（低透明度置于视口外，不遮挡内容）
            var $ta = $('<textarea>')
                .css({
                    position: 'fixed',
                    top: '0',
                    left: '0',
                    width: '2em',
                    height: '2em',
                    padding: 0,
                    border: 0,
                    outline: 0,
                    boxShadow: 'none',
                    background: 'transparent',
                    opacity: 0.01
                })
                .val(text)
                .appendTo('body');

            // 聚焦并选中文本
            $ta[0].focus();
            $ta[0].select();

            try {
                var success = document.execCommand('copy');
                if (success) {
                    $ta.remove();
                    if (cb) cb();
                    return;
                }
            } catch (e) {
                // 忽略错误
            }

            $ta.remove();

            // execCommand 也不可用时：选中文本提示用户手动复制
            if (typeof notyf === 'function') {
                notyf('浏览器限制了自动复制，请手动 Ctrl+C 复制译文', 'danger');
            }
            if (cb) cb();
        }

        // =========================================================
        // 翻译弹窗（复用子比主题自带的 zib_modal 弹窗）
        // =========================================================
        function openTranslateModal(html) {
            var $modal = $('#zibll-ait-modal');
            if (!$modal.length) {
                return;
            }
            // 注入译文后以 Bootstrap 模态框方式打开（关闭/遮罩/ESC 由主题处理）
            $modal.find('.zibll-ait-modal-content').html(html);
            $modal.modal('show');
        }

        // =========================================================
        // 「翻译中…」加载弹窗（蓝色炫彩头部，复用主题弹窗样式）
        // =========================================================
        function openLoadingModal() {
            var $modal = $('#zibll-ait-loading-modal');
            if ($modal.length && typeof $modal.modal === 'function') {
                $modal.modal('show');
            }
        }

        function closeLoadingModal() {
            var $modal = $('#zibll-ait-loading-modal');
            if ($modal.length && typeof $modal.modal === 'function') {
                $modal.modal('hide');
            }
        }

        // 恢复按钮可点击状态，允许下次翻译
        function resetTranslateBtn($btn) {
            $btn.removeData('zibll-ait-loading').removeAttr('disabled');
        }

        // =========================================================
        // 选择翻译语言弹窗（点击翻译先选语言）
        // =========================================================
        var pendingTranslate = null; // 待执行翻译的上下文：{type:'comment'|'post'|'post_dropdown', ...}

                // 打开"选择翻译语言"弹窗，动态渲染可选语言按钮
        function openLangModal() {
            var $modal = $('#zibll-ait-lang-modal');
            if (!$modal.length || typeof $modal.modal !== 'function') {
                return false;
            }
            var $list = $modal.find('.zibll-ait-lang-list');
            $list.empty();
            var langs = (zibll_ait.langs && zibll_ait.langs.length) ? zibll_ait.langs : [zibll_ait.default_lang];
            var def = zibll_ait.default_lang;
            var maxLen = 0;
            $.each(langs, function (i, lg) {
                var cls = (lg === def) ? ' but jb-green' : ' but hollow c-blue';
                $list.append('<button type="button" class="zibll-ait-lang-pick radius mb6' + cls + '" data-lang="' +
                    escHtml(lg) + '">' + escHtml(lg) + '</button>');
                if (lg.length > maxLen) {
                    maxLen = lg.length;
                }
            });
            // 根据最长语言名字符数动态调整弹窗宽度，避免文字被截断
            var minW = 280 + maxLen * 9;
            $modal.find('.modal-dialog').css('width', Math.min(minW, 480) + 'px');
            $modal.modal('show');
            return true;
        }

        // 选择语言后，按上下文执行对应类型的翻译
        $('body').on('click', '.zibll-ait-lang-pick', function (e) {
            e.preventDefault();
            var lang = $(this).data('lang');
            $('#zibll-ait-lang-modal').modal('hide');

            var p = pendingTranslate;
            pendingTranslate = null;
            if (!p) {
                return;
            }
            if (p.type === 'comment') {
                runCommentTranslate(p.btn, p.action, p.mode, p.data, lang);
            } else if (p.type === 'post') {
                doPostTranslate(p.btn, lang);
            } else if (p.type === 'post_dropdown') {
                runPostDropdown(p.item, lang);
            }
        });

        // 关闭语言弹窗（取消选择）时清空待执行上下文，避免误触发
        $(document).on('hidden.bs.modal', '#zibll-ait-lang-modal', function () {
            pendingTranslate = null;
        });

        // =========================================================
        // 免责声明注入：页面加载完成后扫描所有译文回复，注入提示
        // 通过 zibll-ait-translation-reply 类精准定位，逐条判重
        // =========================================================
        function zibll_ait_apply_tip_to_page_comments() {
            // 只针对带 zibll-ait-translation-reply 类的译文回复注入提示，
            // 避免误伤原文评论（原文可能偶然包含【XXX译文】字样）
            $('.zibll-ait-translation-reply').each(function () {
                var $li = $(this);
                // 已有提示元素或已有提示文字均跳过
                if ($li.find('.zibll-ait-tip').length > 0) return;
                if ($li.text().indexOf('内容由AI生成，仅供参考') !== -1) return;
                var $contentDiv = $li.find('[id^="comment-content-"]').first();
                if (!$contentDiv.length) {
                    $contentDiv = $li.find('.comment-text, .comment-body, .c-text, .comment-content').first();
                }
                var tipHtml = '<div class="zibll-ait-tip muted-2-color em09 mt5 text-center">内容由AI生成，仅供参考</div>';
                if ($contentDiv.length) {
                    $contentDiv.after(tipHtml);
                } else {
                    $li.append(tipHtml);
                }
            });
        }
        // 页面加载完成后执行
        setTimeout(zibll_ait_apply_tip_to_page_comments, 300);
        // AJAX 插入译文回复后也执行一次
        $('body').on('DOMNodeInserted', '.children, .comment-list', zibll_ait_apply_tip_to_page_comments);
    });

    /**
     * 简单的 HTML 转义，避免拼接时 XSS
     */
    function escHtml(str) {
        if (str == null) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})(jQuery);
