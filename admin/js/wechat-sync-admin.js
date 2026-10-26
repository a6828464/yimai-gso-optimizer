/**
 * 公众号同步 · 后台交互
 *
 * 只在两处加载：
 *  - 插件子菜单「公众号同步」（同步设置 + 最近任务表）
 *  - 文章编辑页（metabox 的「立即同步」/「不同步这篇文章」）
 *
 * 三条纪律：
 *  1) 不写内联样式。所有视觉由 admin/css/components.css 的 .yimai-gso-* 组件类负责，
 *     这里只切换 is-success / is-error 这类状态类，以及用 is-muted 之类的徽章变体。
 *  2) 不拼 HTML 字符串。表格行、徽章全部用 createElement/textContent 建节点，
 *     服务端返回的文案（含微信 errmsg）永远不会被当成 HTML 解析。
 *  3) 三个动作共用一个 nonce（yimai_gso_wx_admin），由 PHP 通过 wp_localize_script 传下来。
 *
 * @since 2.10.0
 */
(function () {
    'use strict';

    var data = window.yimaiGsoWxSync || {};
    var i18n = data.i18n || {};

    /**
     * 取本地化文案（缺 key 时用兜底，保证界面永远有字）
     */
    function t(key, fallback) {
        return (typeof i18n[key] === 'string' && i18n[key] !== '') ? i18n[key] : fallback;
    }

    /**
     * 对象 → application/x-www-form-urlencoded
     */
    function encode(params) {
        var parts = [];
        var key;

        for (key in params) {
            if (!Object.prototype.hasOwnProperty.call(params, key)) {
                continue;
            }
            var value = params[key];
            if (value === null || typeof value === 'undefined') {
                value = '';
            }
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        }

        return parts.join('&');
    }

    /**
     * 发一个 admin-ajax 请求
     *
     * 先取 text 再 JSON.parse：后台被安全插件插话时返回的是 HTML，
     * 直接 response.json() 会抛出难以理解的解析错误，解析不到就换成一句人话。
     *
     * @return {Promise}
     */
    function post(action, params) {
        var body = 'action=' + encodeURIComponent(action)
            + '&nonce=' + encodeURIComponent(data.nonce || '')
            + '&' + encode(params || {});

        return fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body
        }).then(function (response) {
            return response.text().then(function (text) {
                var json = null;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    throw new Error(t('badResponse', '服务器返回了非 JSON 内容'));
                }
                if (!json || json.success !== true) {
                    var message = (json && json.data && json.data.message)
                        ? json.data.message
                        : t('failed', '操作失败');
                    var error = new Error(message);
                    error.payload = (json && json.data) ? json.data : null;
                    throw error;
                }
                return json.data || {};
            });
        });
    }

    /* ==================== 状态 → 徽章 ==================== */

    // 与 PHP 的 state_info() 一一对应（后端加了新状态时这里会退化成灰色原样显示）
    var STATES = {
        pending: { label: '待处理', badge: 'is-info' },
        processing: { label: '处理中', badge: 'is-info' },
        drafted: { label: '已进草稿箱', badge: 'is-pass' },
        failed: { label: '失败', badge: 'is-warn' },
        dead: { label: '已放弃', badge: 'is-fail' },
        skipped: { label: '已跳过', badge: 'is-muted' }
    };

    /**
     * 生成一个状态徽章节点
     */
    function badgeNode(state, label, badge) {
        var info = STATES[state] || { label: '', badge: 'is-muted' };
        var span = document.createElement('span');

        span.className = 'yimai-gso-badge ' + (badge || info.badge);
        span.textContent = label || info.label || state || '未同步';

        return span;
    }

    /* ==================== 任务表行内操作 ==================== */

    /**
     * 按状态给出唯一正确的行内操作（与 PHP 的 render_row_actions() 保持一致）
     */
    function actionNode(state, jobId, postId) {
        if (state === 'failed' || state === 'dead') {
            return actionButton('重试', 'retry', jobId, postId);
        }
        if (state === 'pending') {
            return actionButton('取消', 'skip', jobId, postId);
        }
        if (state === 'drafted') {
            var link = document.createElement('a');
            link.className = 'button button-small';
            link.href = data.mpUrl || 'https://mp.weixin.qq.com/';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = '查看草稿';
            return link;
        }

        return null;
    }

    function actionButton(text, action, jobId, postId) {
        var btn = document.createElement('button');

        btn.type = 'button';
        btn.className = 'button button-small';
        btn.setAttribute('data-yimai-wx-action', action);
        btn.setAttribute('data-job-id', jobId || '');
        btn.setAttribute('data-post-id', postId || '');
        btn.textContent = text;

        return btn;
    }

    /**
     * 用服务端返回的状态快照就地更新一行（徽标 + 操作），不整页刷新
     */
    function updateRow(row, payload) {
        if (!row || !payload || typeof payload.state === 'undefined') {
            return;
        }

        row.setAttribute('data-yimai-wx-state', payload.state);

        var badgeCell = row.querySelector('[data-yimai-wx-badge]');
        if (badgeCell) {
            badgeCell.textContent = '';
            badgeCell.appendChild(badgeNode(payload.state, payload.label, payload.badge));
        }

        var actionCell = row.querySelector('[data-yimai-wx-actions]');
        if (actionCell) {
            var node = actionNode(payload.state, row.getAttribute('data-job-id'), row.getAttribute('data-post-id'));
            actionCell.textContent = '';
            if (node) {
                actionCell.appendChild(node);
            } else {
                actionCell.textContent = '—';
            }
        }
    }

    /* ==================== 提示区 ==================== */

    function pageNotice() {
        return document.getElementById('yimai-gso-wx-page-result');
    }

    /**
     * 页面级提示（同步页底部的 .yimai-gso-notice）
     */
    function showNotice(el, ok, message) {
        if (!el) {
            return;
        }

        el.className = 'yimai-gso-notice ' + (ok ? 'is-success' : 'is-error');
        el.hidden = false;

        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }

        var p = document.createElement('p');
        var strong = document.createElement('strong');
        strong.textContent = ok ? '完成：' : '没成功：';
        p.appendChild(strong);
        p.appendChild(document.createTextNode(message || ''));
        el.appendChild(p);

        if (typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * metabox 里的一行结果（复用 .yimai-gso-field-hint 的正常/报错着色）
     *
     * @param {string} kind ok|error|info
     */
    function showMetaResult(el, kind, message) {
        if (!el) {
            return;
        }

        el.className = 'yimai-gso-field-hint'
            + (kind === 'ok' ? ' is-success' : (kind === 'error' ? ' is-error' : ''));
        el.textContent = message || '';
    }

    /**
     * 按钮忙碌态：禁用 + 换文案，结束后恢复原文案
     */
    function setBusy(btn, busy, busyText) {
        if (!btn) {
            return;
        }

        if (busy) {
            if (!btn.getAttribute('data-yimai-wx-label')) {
                btn.setAttribute('data-yimai-wx-label', btn.textContent);
            }
            btn.disabled = true;
            btn.textContent = busyText || t('working', '提交中…');
            return;
        }

        btn.disabled = false;
        var label = btn.getAttribute('data-yimai-wx-label');
        if (label) {
            btn.textContent = label;
        }
    }

    /* ==================== 事件绑定 ==================== */

    document.addEventListener('click', function (event) {
        var target = event.target;
        var trigger = (target && typeof target.closest === 'function')
            ? target.closest('[data-yimai-wx-action]')
            : null;

        if (!trigger) {
            return;
        }

        event.preventDefault();

        var action = trigger.getAttribute('data-yimai-wx-action');
        var jobId = trigger.getAttribute('data-job-id') || '';
        var postId = trigger.getAttribute('data-post-id') || '';
        var row = (typeof trigger.closest === 'function') ? trigger.closest('[data-yimai-wx-row]') : null;
        var endpoint = '';
        var params = null;

        if (action === 'retry') {
            endpoint = 'yimai_gso_wx_retry';
            params = { job_id: jobId };
        } else if (action === 'skip') {
            if (!window.confirm(t('skipConfirm', '确定取消这次排队吗？'))) {
                return;
            }
            endpoint = 'yimai_gso_wx_skip';
            params = { post_id: postId };
        } else {
            return;
        }

        setBusy(trigger, true);

        post(endpoint, params)
            .then(function (payload) {
                updateRow(row, payload);
                showNotice(pageNotice(), true, payload.message || '已完成');
            })
            .catch(function (error) {
                if (error && error.payload) {
                    updateRow(row, error.payload);
                }
                showNotice(pageNotice(), false, (error && error.message) ? error.message : t('failed', '操作失败'));
            })
            .then(function () {
                setBusy(trigger, false);
            });
    });

    /**
     * 页头「立即同步最新一篇」
     */
    (function bindSyncLatest() {
        var btn = document.getElementById('yimai-gso-wx-sync-latest');
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            var postId = btn.getAttribute('data-post-id');

            if (!postId || postId === '0') {
                showNotice(pageNotice(), false, t('noPost', '没有拿到文章 ID'));
                return;
            }

            setBusy(btn, true, t('syncing', '同步中…'));

            post('yimai_gso_wx_sync_now', { post_id: postId })
                .then(function (payload) {
                    // 这里同步成功后统计卡与任务表都变了，但直接刷新会吞掉提示，
                    // 所以提示里明说「刷新页面」可以看最新数据。
                    showNotice(pageNotice(), true, (payload.message || '已写入草稿箱') + ' 刷新页面可更新下方统计与任务列表。');
                })
                .catch(function (error) {
                    showNotice(pageNotice(), false, (error && error.message) ? error.message : t('failed', '操作失败'));
                })
                .then(function () {
                    setBusy(btn, false);
                });
        });
    }());

    /**
     * 编辑页 metabox「立即同步」
     */
    (function bindMetaSync() {
        var btn = document.getElementById('yimai-gso-wx-sync-now');
        if (!btn) {
            return;
        }

        var resultEl = document.getElementById('yimai-gso-wx-meta-result');
        var badgeEl = document.getElementById('yimai-gso-wx-meta-badge');
        var field = document.getElementById('yimai-gso-wx-post-id');

        btn.addEventListener('click', function () {
            var postId = field ? field.value : '';

            if (!postId) {
                showMetaResult(resultEl, 'error', t('noPost', '没有拿到文章 ID'));
                return;
            }

            setBusy(btn, true, t('syncing', '同步中…'));
            showMetaResult(resultEl, 'info', '正在同步，可能需要几十秒，请不要关闭页面…');

            post('yimai_gso_wx_sync_now', { post_id: postId })
                .then(function (payload) {
                    if (badgeEl) {
                        badgeEl.textContent = '';
                        badgeEl.appendChild(badgeNode(payload.state, payload.label, payload.badge));
                    }
                    showMetaResult(resultEl, 'ok', payload.message || '已写入草稿箱');
                })
                .catch(function (error) {
                    var payload = error && error.payload ? error.payload : null;
                    if (payload && badgeEl) {
                        badgeEl.textContent = '';
                        badgeEl.appendChild(badgeNode(payload.state, payload.label, payload.badge));
                    }
                    showMetaResult(resultEl, 'error', (error && error.message) ? error.message : t('failed', '操作失败'));
                })
                .then(function () {
                    setBusy(btn, false);
                });
        });
    }());

    /**
     * 兜底封面选择器（wp.media）
     *
     * 只用宿主自带媒体库，不引第三方 picker：少一个依赖，也少一份 375px 下的布局风险。
     */
    (function bindCoverPicker() {
        var pick = document.getElementById('yimai-gso-wx-cover-pick');
        var clear = document.getElementById('yimai-gso-wx-cover-clear');
        var input = document.getElementById('wx_cover_fallback_id');
        var preview = document.getElementById('yimai-gso-wx-cover-preview');

        if (!pick || !input || !preview) {
            return;
        }

        if (!window.wp || !window.wp.media) {
            // 媒体库没加载（极端情况：别的插件 deregister 了 media）时，
            // 不要给一个点了没反应的按钮，直接说明原因。
            pick.disabled = true;
            pick.textContent = '媒体库未加载';
            return;
        }

        function renderPreview(url, width, height) {
            preview.textContent = '';

            if (!url) {
                var hint = document.createElement('span');
                hint.className = 'yimai-gso-field-hint';
                hint.textContent = t('coverEmpty', '未选择兜底封面。');
                preview.appendChild(hint);
                return;
            }

            var img = document.createElement('img');
            img.src = url;
            img.alt = t('coverAlt', '兜底封面');
            if (width) {
                img.width = width;
            }
            if (height) {
                img.height = height;
            }
            preview.appendChild(img);
        }

        pick.addEventListener('click', function () {
            var frame = window.wp.media({
                title: t('coverTitle', '选择兜底封面'),
                button: { text: t('coverButton', '用这张图') },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var selection = frame.state().get('selection').first();
                if (!selection) {
                    return;
                }

                var attachment = selection.toJSON();
                var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail : null;

                input.value = attachment.id;
                renderPreview(thumb ? thumb.url : attachment.url, thumb ? thumb.width : 0, thumb ? thumb.height : 0);
            });

            frame.open();
        });

        if (clear) {
            clear.addEventListener('click', function () {
                input.value = '0';
                renderPreview('');
            });
        }
    }());
}());
