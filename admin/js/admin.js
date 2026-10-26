/**
 * 一麦GSO双擎优化 - 后台交互逻辑
 * 修复：配图现在在前端直接嵌入正文，然后整体提交
 */

/**
 * HTML 转义 — 统一实现（原生 DOM，无 jQuery 依赖），全局作用域供所有模块 IIFE 使用
 *
 * 安全修正（S2）：原实现用 createTextNode → innerHTML，按 HTML 规范只转义 & < >（及 nbsp），
 * 不转义引号。本函数有约 30 处用在属性上下文（data-*="' + esc(x) + '"），
 * 因此含引号的输入可闭合属性并注入事件处理器，例如：
 *   esc('/x" onmouseover="alert(1)" data-x="')
 *     → /x" onmouseover="alert(1)" data-x=""   ← 注入成功
 * 修复：在原有转义基础上补上 " 与 '（与同文件 escapeHtml() 语义一致），
 * 使本函数在文本与属性两种上下文中都安全。
 */
function esc(s) {
    if (!s && s !== 0) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s)));
    return d.innerHTML
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

(function ($) {
    'use strict';

    // ==================== 动画样式（注入到页面） ====================
    $('<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>').appendTo('head');

    // ==================== 工具函数 ====================
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ==================== AI摘要批量生成模块 ====================
    var BatchExcerpt = (function () {
        var selected = [];
        var currentPage = 1;
        var totalPages = 1;
        var isRunning = false;
        var abortFlag = false;
        var statusLabels = {publish: '已发布', draft: '草稿', future: '定时'};
        var initialized = false;

        function init() {
            if (initialized) return;
            initialized = true;

            // 搜索按钮
            $(document).off('click.exSearch', '#yimai-gso-ex-search-btn').on('click.exSearch', '#yimai-gso-ex-search-btn', function () {
                currentPage = 1;
                fetchPosts();
            });

            // 回车搜索
            $(document).off('keypress.exSearchEnter', '#yimai-gso-ex-search').on('keypress.exSearchEnter', '#yimai-gso-ex-search', function (e) {
                if (e.which === 13) {
                    currentPage = 1;
                    fetchPosts();
                }
            });

            // 清空选择
            $(document).off('click.exClear', '#yimai-gso-ex-clear-btn').on('click.exClear', '#yimai-gso-ex-clear-btn', function () {
                selected = [];
                renderSelected();
            });

            // 开始按钮
            $(document).off('click.exStart', '#yimai-gso-ex-start-btn').on('click.exStart', '#yimai-gso-ex-start-btn', function () {
                if (isRunning) {
                    abortFlag = true;
                    $(this).text('停止中...').prop('disabled', true);
                    return;
                }
                startBatch();
            });
        }

        function fetchPosts() {
            var keyword = $.trim($('#yimai-gso-ex-search').val());
            var catId = $('#yimai-gso-ex-category').val();
            var status = $('#yimai-gso-ex-status').val();
            var filter = $('#yimai-gso-ex-filter').val();

            var $results = $('#yimai-gso-ex-results');
            $results.html('<div class="yimai-gso-empty"><span class="yimai-gso-loading"></span> 搜索中...</div>');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_excerpt_fetch_posts',
                nonce: yimaiGsoData.nonce,
                keyword: keyword,
                category_id: catId,
                post_status: status,
                excerpt_filter: filter,
                page: currentPage
            }, function (res) {
                if (!res.success) {
                    $results.html('<div class="yimai-gso-empty"><p>❌ ' + escapeHtml(res.data.message || '搜索失败') + '</p></div>');
                    return;
                }
                var data = res.data;
                totalPages = data.pages || 1;
                renderPostList(data.posts || []);
                renderPagination(data.page, totalPages);
            }).fail(function () {
                $results.html('<div class="yimai-gso-empty"><p>❌ 网络请求失败</p></div>');
            });
        }

        function renderPostList(posts) {
            var $results = $('#yimai-gso-ex-results');
            if (!posts.length) {
                $results.html('<div class="yimai-gso-empty"><p>没有找到匹配的文章</p></div>');
                return;
            }

            var html = '';
            posts.forEach(function (p) {
                var isSelected = selected.some(function (s) { return s.id === p.id; });
                var excerptBadge = p.has_excerpt
                    ? '<span class="yimai-gso-ex-has" style="color:#16a34a;font-size:11px;">✓ 有摘要</span>'
                    : '<span class="yimai-gso-ex-no" style="color:#dc2626;font-size:11px;">✗ 无摘要</span>';
                html += '<div class="yimai-gso-br-result-item' + (isSelected ? ' selected' : '') + '" data-id="' + p.id + '">'
                    + '<label class="yimai-gso-br-check">'
                    + '<input type="checkbox" ' + (isSelected ? 'checked' : '') + ' value="' + p.id + '">'
                    + '</label>'
                    + '<div class="yimai-gso-br-result-info">'
                    + '<strong class="yimai-gso-br-result-title">' + escHtml(p.title) + '</strong>'
                    + '<div class="yimai-gso-br-result-meta">'
                    + excerptBadge
                    + '<span class="yimai-gso-br-status-chip yimai-gso-br-status-' + p.status + '">' + (statusLabels[p.status] || p.status) + '</span>'
                    + '<span>📅 ' + p.date + '</span>'
                    + '<span>📝 ' + p.word_count + '字</span>'
                    + '</div>'
                    + '</div>'
                    + '</div>';
            });
            $results.html(html);

            // 绑定 checkbox
            $results.find('input[type="checkbox"]').off('change.exCheck').on('change.exCheck', function () {
                var id = parseInt(this.value);
                var idx = selected.findIndex(function (s) { return s.id === id; });
                if (this.checked) {
                    var post = posts.find(function (p) { return p.id === id; });
                    if (post && idx === -1) {
                        selected.push(post);
                    }
                } else {
                    if (idx !== -1) selected.splice(idx, 1);
                }
                renderSelected();
                $(this).closest('.yimai-gso-br-result-item').toggleClass('selected', this.checked);
            });
        }

        function renderPagination(page, pages) {
            var $pag = $('#yimai-gso-ex-pagination');
            if (pages <= 1) {
                $pag.hide();
                return;
            }
            $pag.show();
            var html = '';
            if (page > 1) {
                html += '<button class="button button-small" data-page="' + (page - 1) + '">« 上一页</button>';
            }
            html += '<span style="margin:0 8px;line-height:28px;">第 ' + page + ' / ' + pages + ' 页</span>';
            if (page < pages) {
                html += '<button class="button button-small" data-page="' + (page + 1) + '">下一页 »</button>';
            }
            $pag.html(html);
            $pag.find('button').off('click.exPage').on('click.exPage', function () {
                currentPage = parseInt($(this).data('page'));
                fetchPosts();
            });
        }

        function renderSelected() {
            var $list = $('#yimai-gso-ex-selected-list');
            var $count = $('#yimai-gso-ex-selected-count');
            var $clearBtn = $('#yimai-gso-ex-clear-btn');

            $count.text(selected.length);
            $clearBtn.toggle(selected.length > 0);

            if (!selected.length) {
                $list.html('<p style="color:#999;margin:8px 0;">从下方搜索并勾选需要生成摘要的文章</p>');
                return;
            }

            var html = '';
            selected.forEach(function (s, i) {
                html += '<div class="yimai-gso-br-selected-item">'
                    + '<span class="yimai-gso-br-selected-num">' + (i + 1) + '</span>'
                    + '<span class="yimai-gso-br-selected-title" title="' + escHtml(s.title) + '">' + escHtml(s.title) + '</span>'
                    + '<button type="button" class="button button-small yimai-gso-br-remove-item" data-id="' + s.id + '" title="移除">×</button>'
                    + '</div>';
            });
            $list.html(html);

            $list.find('.yimai-gso-br-remove-item').off('click.exRemove').on('click.exRemove', function () {
                var id = parseInt($(this).data('id'));
                selected = selected.filter(function (s) { return s.id !== id; });
                renderSelected();
                $('.yimai-gso-br-result-item[data-id="' + id + '"]').removeClass('selected').find('input[type="checkbox"]').prop('checked', false);
            });
        }

        function startBatch() {
            if (!selected.length) {
                alert('请先选择需要生成摘要的文章！');
                return;
            }

            isRunning = true;
            abortFlag = false;

            var $btn = $('#yimai-gso-ex-start-btn');
            $btn.text('⏹ 停止生成').prop('disabled', false).removeClass('button-primary').addClass('button');

            var $progress = $('#yimai-gso-ex-progress');
            var $fill = $('#yimai-gso-ex-progress-fill');
            var $progressText = $('#yimai-gso-ex-progress-text');
            var $resultsArea = $('#yimai-gso-ex-results-area');

            $progress.show();
            $fill.css('width', '0%');
            $resultsArea.empty().show();

            var results = [];
            var total = selected.length;
            var nextIdx = 0;
            var doneCount = 0;
            var concurrent = 2;

            function processNext() {
                while (!abortFlag && nextIdx < total && (nextIdx - doneCount) < concurrent) {
                    (function (itemIndex, item) {
                        $progressText.text('🔄 正在生成第 ' + (itemIndex + 1) + ' / ' + total + ' 篇：' + item.title);

                        $.ajax({
                            url: yimaiGsoData.ajaxUrl,
                            type: 'POST',
                            dataType: 'json',
                            timeout: 120000,
                            data: {
                                action: 'yimai_gso_batch_generate_excerpt',
                                nonce: yimaiGsoData.nonce,
                                post_id: item.id
                            },
                            success: function (res) {
                                var result = {post_id: item.id, title: item.title, success: false};
                                if (res && res.success) {
                                    result.success = true;
                                    result.excerpt = res.data.excerpt;
                                    result.char_count = res.data.char_count;
                                } else {
                                    result.error = (res && res.data && res.data.message) ? res.data.message : '生成失败';
                                }
                                results[itemIndex] = result;
                                appendResultItem(result, itemIndex + 1, total);
                                doneCount++;
                                updateProgress();
                                processNext();
                            },
                            error: function (xhr, statusText) {
                                results[itemIndex] = {post_id: item.id, title: item.title, success: false, error: '请求失败: ' + statusText};
                                appendResultItem(results[itemIndex], itemIndex + 1, total);
                                doneCount++;
                                updateProgress();
                                processNext();
                            }
                        });
                    })(nextIdx, selected[nextIdx]);
                    nextIdx++;
                }

                if (doneCount >= total || (abortFlag && doneCount >= nextIdx)) {
                    finishBatch(results, total);
                }
            }

            function updateProgress() {
                var pct = Math.round((doneCount / total) * 100);
                $fill.css('width', pct + '%');
            }

            processNext();
        }

        function appendResultItem(result, current, total) {
            var $area = $('#yimai-gso-ex-results-area');
            var cls = result.success ? 'yimai-gso-br-result-success' : 'yimai-gso-br-result-error';
            var icon = result.success ? '✅' : '❌';
            var info = result.success
                ? escHtml(result.excerpt) + ' <small>（' + result.char_count + '字）</small>'
                : (result.error || '未知错误');

            $area.append(
                '<div class="yimai-gso-br-result-card ' + cls + '">'
                + '<div class="yimai-gso-br-result-card-header">'
                + '<span>' + icon + ' #' + current + '/' + total + '</span>'
                + '<small class="yimai-gso-br-result-original">' + escHtml(result.title) + '</small>'
                + '</div>'
                + '<div class="yimai-gso-br-result-card-body">' + info + '</div>'
                + '</div>'
            );

            $area.scrollTop($area[0].scrollHeight);
        }

        function finishBatch(results, total) {
            isRunning = false;
            var $btn = $('#yimai-gso-ex-start-btn');
            $btn.text('🚀 批量生成AI摘要').prop('disabled', false).removeClass('button').addClass('button button-primary');

            var $fill = $('#yimai-gso-ex-progress-fill');
            var $progressText = $('#yimai-gso-ex-progress-text');
            // 停止动画并固定100%
            $fill.css({width: '100%', animation: 'none'});

            var successCount = results.filter(function (r) { return r.success; }).length;
            var failCount = total - successCount;

            if (abortFlag) {
                $progressText.text('⏹ 已停止。已完成 ' + successCount + ' / ' + total + ' 篇，失败 ' + failCount + ' 篇');
            } else {
                $progressText.text('🎉 全部完成！成功 ' + successCount + ' 篇，失败 ' + failCount + ' 篇');
            }

            // 清空选择，刷新列表
            selected = [];
            renderSelected();
        }

        return {init: init};
    })();

    // ==================== 状态管理 ====================
    var state = {
        isGenerating: false,
        articleData: null,
        images: [],          // 图片列表 [{url, thumb, alt, source}]
        selectedThumbnailId: 0,
    };

    // ==================== 初始化 ====================
    $(document).ready(function () {
        // 新鲜度标签：事件委托，独立于其它初始化，确保点击始终触发数据加载
        $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="freshness"]', function () {
            if ($(this).hasClass('locked')) return;
            if (typeof window.loadFreshnessStats === 'function') window.loadFreshnessStats();
            if (typeof window.loadFreshnessPosts === 'function') window.loadFreshnessPosts(1);
        });

        initPublishStatusToggle();
        initGenerateBtn();
        initPublishBtn();
        initRegenerateBtn();
        initSettingsPage();
        initImageUpload();
        initBatchPage();
        initTitlesPage();
        initSeoMetaPage();
        initAutopilotPage();
        initMainCenterTabs();

        initCenterQuickActions();
        initDashboardPage();

        // 新鲜度面板保险：若当前为激活态（如 URL 带 ?tab=freshness 或默认激活），强制加载一次
        if ($('.yimai-gso-main-panel[data-tab="freshness"].active').length) {
            if (typeof window.loadFreshnessStats === 'function') window.loadFreshnessStats();
            if (typeof window.loadFreshnessPosts === 'function') window.loadFreshnessPosts(1);
        }
    });


    // ==================== AI内容助手主标签页（侧边栏） ====================
    function initMainCenterTabs() {
        if (!$('.yimai-gso-center-wrap').length) return;

        // 支持 URL 参数中的 tab
        var urlParams = new URLSearchParams(window.location.search);
        var activeTab = urlParams.get('tab');

        // URL 无 tab 参数时，取页面上实际处于激活态的面板
        // （PHP 默认激活页，如内容新鲜度监控，不会带 ?tab= 参数，必须靠 DOM 判定）
        if (!activeTab) {
            var $activePanel = $('.yimai-gso-main-panel.active');
            activeTab = $activePanel.length ? $activePanel.data('tab') : 'dashboard';
        }

        // 初始化时触发对应面板的加载（含 freshness 等默认激活但非 dashboard 的面板）
        if (activeTab && activeTab !== 'dashboard') {
            switchMainTab(activeTab);
        }

        // 检测 sessionStorage 中的保存标记（由 PHP redirect 脚本设置）
        var savedTab = sessionStorage.getItem('yimai_gso_save_tab');
        if (savedTab) {
            sessionStorage.removeItem('yimai_gso_save_tab');
            showSaveSuccessToast(savedTab);
        }

        // 切换标签（支持侧边栏和旧版顶部标签）
        $('.yimai-gso-sidebar-tab, .yimai-gso-main-tab').on('click', function () {
            var tab = $(this).data('tab');
            switchMainTab(tab);
        });

        // 设置子标签切换（settings 主面板内的 AI、GEO、品牌资料等面板）
        $('.yimai-gso-settings-tab').on('click', function () {
            var tab = $(this).data('tab');
            $('.yimai-gso-settings-tab').removeClass('active');
            $(this).addClass('active');
            $('.yimai-gso-settings-panel').removeClass('active');
            $('.yimai-gso-settings-panel[data-tab="' + tab + '"]').addClass('active');
            // 更新隐藏字段，供表单提交时记住当前tab
            $('#yimai-gso-current-subtab').val(tab);
            // 控制表单底部提交按钮：GEO实体/爬虫策略/作者档案 使用 AJAX，不需要表单提交按钮
            var $footer = $('#yimai-gso-settings-form > .yimai-gso-settings-footer');
            if (['ai', 'image', 'publish'].indexOf(tab) >= 0) {
                $footer.show();
            } else {
                $footer.hide();
            }
            // 切换到配图设置时，触发图片提供商切换事件
            if (tab === 'image') {
                $('#setting-img-provider').trigger('change');
            }
        });
    }

    function switchMainTab(tab) {
        // 同时更新侧边栏和旧版顶部标签（兼容）
        $('.yimai-gso-sidebar-tab, .yimai-gso-main-tab').removeClass('active');
        $('.yimai-gso-sidebar-tab[data-tab="' + tab + '"], .yimai-gso-main-tab[data-tab="' + tab + '"]').addClass('active');
        $('.yimai-gso-main-panel').removeClass('active');
        var $panel = $('.yimai-gso-main-panel[data-tab="' + tab + '"]').addClass('active');

        // 更新 URL 参数（不刷新页面）
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        if (tab !== 'generate') {
            url.searchParams.delete('step');
        }
        // 清理 saved 参数，防止保存提示闪烁或重复出现
        url.searchParams.delete('saved');
        history.pushState({}, '', url);

        // 触发对应面板的初始化
        // 离开仪表盘时清理定时器
        if (tab !== 'dashboard' && typeof stopD5Countdown === 'function') {
            stopD5Countdown();
        }
        if (tab === 'history') {
            loadHistoryStats();
            loadHistoryCards(1);
        } else if (tab === 'dashboard') {
            initDashboardPage();
        } else if (tab === 'generate') {
            initGeneratePage();
        } else if (tab === 'single') {
            initSinglePage();
        } else if (tab === 'seo-meta') {
            initSeoMetaPage();
        } else if (tab === 'autopilot') {
            initAutopilotPage();
        } else if (tab === 'ai-excerpt') {
            BatchExcerpt.init();
        } else if (tab === 'schema') {
            SchemaPanel.init();
        } else if (tab === 'seo-score') {
            SeoScorerPanel.init();
        } else if (tab === 'heading') {
            HeadingPanel.init();
        } else if (tab === 'rewrite') {
            // 内容改写模块 — 确保翻新搜索按钮事件已绑定
            if (typeof initRewriteModule === 'function') {
                initRewriteModule();
            }
        } else if (tab.indexOf('l5-') === 0) {
            // L5 监控分析面板 — 动态加载 l5-dashboard.js
            loadL5Dashboard(tab);
        }
        // 新鲜度面板数据加载由 $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="freshness"]') 事件委托统一处理
        // 页面初始加载由 $(document).ready 末尾的可见性保险检测负责
        // 此处在 switchMainTab 中不再重复调用，避免 4 个 AJAX 竞态


    }




    /**
     * 动态加载L5仪表盘JS并初始化指定标签页
     *
     * 修复（P9/L1）：Chart.js 已由 PHP 端（class-admin.php:274）以 handle
     * 'yimai-gso-chartjs' 入队，版本 4.4.7 且带 SRI 完整性校验。
     * 原实现无条件再加载一份 4.4.0（无 SRI），导致同一页面出现两个版本的 Chart.js：
     * 既浪费一次 CDN 请求，又可能让后加载的 4.4.0 覆盖全局 Chart 对象。
     * 现在优先复用已存在的 window.Chart，仅在确实缺失时才回退加载。
     */
    var _l5DashboardLoaded = false;
    function loadL5Dashboard(tab) {
        function _initL5Panel(t) {
            if (typeof window.yimaiGsoL5Init === "function") { window.yimaiGsoL5Init(t); }
        }
        if (_l5DashboardLoaded) { _initL5Panel(tab); return; }

        function _loadL5Script() {
            var s2 = document.createElement("script");
            s2.src = yimaiGsoData.pluginUrl + "admin/js/l5-dashboard.js?ver=" + yimaiGsoData.version;
            s2.onload = function() { _l5DashboardLoaded = true; _initL5Panel(tab); };
            document.body.appendChild(s2);
        }

        // Chart.js 已由 PHP enqueue 提供时直接复用，避免重复加载与版本冲突
        if (typeof window.Chart !== "undefined") {
            _loadL5Script();
            return;
        }

        var s = document.createElement("script");
        s.src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js";
        s.onload = _loadL5Script;
        document.body.appendChild(s);
    }


    // ==================== 快捷操作按钮 ====================
    function initCenterQuickActions() {
        if (!$('.yimai-gso-center-wrap').length) return;

        // 快捷操作跳转
        $('.yimai-gso-quick-action[data-goto], .yimai-gso-view-all[data-goto], .yimai-gso-history-header-actions button[data-goto], .yimai-gso-dashboard-goto[data-goto]')
            .off('click.yimaiGsoGoto')
            .on('click.yimaiGsoGoto', function () {
                var gotoTab = $(this).data('goto');
                if (!gotoTab) return;
                switchMainTab(gotoTab);
            });
    }


    // ==================== 发布状态切换 ====================
    function initPublishStatusToggle() {
        $('input[name="yimai-gso-status"]').on('change', function () {
            if ($(this).val() === 'future') {
                $('#yimai-gso-schedule-group').slideDown(200);
                var tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                tomorrow.setHours(9, 0, 0, 0);
                // 使用本地时间格式化，避免 toISOString 的 UTC 时区偏差
                var local = tomorrow.getFullYear() + '-'
                    + String(tomorrow.getMonth() + 1).padStart(2, '0') + '-'
                    + String(tomorrow.getDate()).padStart(2, '0') + 'T'
                    + String(tomorrow.getHours()).padStart(2, '0') + ':'
                    + String(tomorrow.getMinutes()).padStart(2, '0');
                $('#yimai-gso-scheduled-at').val(local);
            } else {
                $('#yimai-gso-schedule-group').slideUp(200);
            }
        });
    }

    // ==================== 生成文章 ====================
    function initGenerateBtn() {
        $('#yimai-gso-generate-btn').on('click', function () {
            if (state.isGenerating) return;

            var prompt = $.trim($('#yimai-gso-prompt').val());
            if (!prompt) {
                alert('请先填写文章主题/提示词！');
                $('#yimai-gso-prompt').focus();
                return;
            }

            state.isGenerating = true;
            state.images = [];
            state.articleData = null;
            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="yimai-gso-loading"></span> 生成中...');
            $('#yimai-gso-progress').show();
            $('#yimai-gso-preview-panel').hide();
            $('#yimai-gso-publish-result').hide();

            var wordCount  = $('#yimai-gso-word-count').val();
            var imageCount = parseInt($('#yimai-gso-image-count').val()) || 0;

            updateProgress('🤖 正在调用 AI 生成文章...');

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action:     'yimai_gso_generate_article',
                    nonce:      yimaiGsoData.nonce,
                    prompt:     prompt,
                    word_count: wordCount,
                },
                success: function (res) {
                    if (!res.success) {
                        showError('文章生成失败：' + (res.data.message || '未知错误'));
                        resetGenerateBtn($btn);
                        return;
                    }

                    state.articleData = res.data;
                    if (res.data.keywords) {
                        state.articleData.keywords = res.data.keywords;
                    }
                    fillArticleForm(res.data);

                    if (imageCount > 0 && res.data.image_keywords) {
                        updateProgress('🖼️ 正在获取配图...');
                        fetchImages(res.data.image_keywords, imageCount, $btn);
                    } else if (imageCount > 0) {
                        // 没有 image_keywords，用标题关键词
                        updateProgress('🖼️ 正在获取配图...');
                        fetchImages(res.data.title || 'article', imageCount, $btn);
                    } else {
                        updateProgress('✅ 生成完成！');
                        finishGeneration($btn);
                    }
                },
                error: function (xhr) {
                    showError('请求失败，请检查网络连接。');
                    resetGenerateBtn($btn);
                }
            });
        });
    }

    function fetchImages(keywords, count, $btn) {
        $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action:  'yimai_gso_generate_image',
                nonce:   yimaiGsoData.nonce,
                keyword: keywords,
                count:   count,
            },
            success: function (res) {
                if (res.success && res.data.images && res.data.images.length > 0) {
                    state.images = res.data.images;
                    renderImageGrid(res.data.images);
                    updateProgress('✅ 配图获取成功！');
                } else {
                    var errMsg = (res.data && res.data.message) ? res.data.message : '未找到图片';
                    updateProgress('⚠️ 配图获取失败：' + errMsg);
                }
                finishGeneration($btn);
            },
            error: function () {
                updateProgress('⚠️ 配图请求失败，可手动添加');
                finishGeneration($btn);
            }
        });
    }

    function finishGeneration($btn) {
        state.isGenerating = false;
        setTimeout(function () {
            $('#yimai-gso-progress').fadeOut(300);
            $('#yimai-gso-preview-panel').slideDown(400);
            $('html, body').animate({ scrollTop: $('#yimai-gso-preview-panel').offset().top - 80 }, 400);
        }, 600);
        resetGenerateBtn($btn);
    }

    function resetGenerateBtn($btn) {
        state.isGenerating = false;
        $btn.prop('disabled', false).html(
            '<span class="dashicons dashicons-superhero"></span> 一键生成文章'
        );
    }

    function updateProgress(text) {
        $('#yimai-gso-progress-text').text(text);
    }

    // ==================== 填充表单 ====================
    function fillArticleForm(data) {
        $('#yimai-gso-title').val(data.title || '');
        $('#yimai-gso-excerpt').val(data.excerpt || '');
        $('#yimai-gso-tags').val(data.tags || '');
        $('#yimai-gso-content').val(data.content || '');
    }

    // ==================== 图片网格 ====================
    function renderImageGrid(images) {
        var $grid = $('#yimai-gso-images-grid').empty();

        $.each(images, function (i, img) {
            var $item = $('<div class="yimai-gso-img-item">')
                .attr('data-url', img.url)
                .attr('data-alt', img.alt || '')
                .attr('data-index', i);

            $item.append('<img src="' + escapeHtml(img.thumb || img.url) + '" alt="' + escapeHtml(img.alt || '') + '">');
            $item.append('<span class="yimai-gso-img-badge">✓</span>');
            $item.append('<span class="yimai-gso-img-source">' + escapeHtml(img.source || '') + '</span>');

            // 默认选中第一张
            if (i === 0) {
                $item.addClass('selected');
                uploadSingleImage(img, 0);
            }

            $item.on('click', function () {
                $('.yimai-gso-img-item').removeClass('selected');
                $(this).addClass('selected');
                var idx = parseInt($(this).data('index'));
                if (state.images[idx] && state.images[idx].attach_id) {
                    state.selectedThumbnailId = state.images[idx].attach_id;
                    $('#yimai-gso-thumbnail-id').val(state.selectedThumbnailId);
                } else {
                    uploadSingleImage(state.images[idx], idx);
                }
            });

            $grid.append($item);
        });
    }

    /**
     * 上传单张图片到WordPress媒体库（用于封面图）
     */
    function uploadSingleImage(img, index) {
        $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action:    'yimai_gso_upload_image',
                nonce:     yimaiGsoData.nonce,
                image_url: img.url,
                alt_text:  img.alt || '',
            },
            success: function (res) {
                if (res.success) {
                    state.images[index].attach_id = res.data.attach_id;
                    state.images[index].wp_url = res.data.url;
                    state.selectedThumbnailId = res.data.attach_id;
                    $('#yimai-gso-thumbnail-id').val(res.data.attach_id);
                }
            }
        });
    }

    // ==================== 核心：把图片直接嵌入正文HTML ====================
    function injectImagesIntoContent(content, images) {
        if (!images || images.length === 0) return content;

        var imgIndex = 0;
        var total = images.length;

        // 在每个 </h2> 后面插入一张图片
        content = content.replace(/<\/h2>/gi, function (match) {
            if (imgIndex >= total) return match;

            var img = images[imgIndex];
            var imgUrl = img.url || '';
            var imgAlt = escapeHtml(img.alt || '配图');
            imgIndex++;

            var imgTag = '\n<p style="text-align:center;margin:20px 0;">'
                       + '<img src="' + imgUrl + '" alt="' + imgAlt + '" '
                       + 'style="max-width:100%;border-radius:8px;display:inline-block;" />'
                       + '</p>\n';

            return match + imgTag;
        });

        // 如果图片比h2多，剩余追加到文末
        if (imgIndex < total) {
            content += '\n<p style="text-align:center;color:#999;font-size:14px;margin-top:30px;">—— 相关配图 ——</p>\n';
            for (var j = imgIndex; j < total; j++) {
                var extra = images[j];
                content += '<p style="text-align:center;margin:20px 0;">'
                         + '<img src="' + (extra.url || '') + '" alt="' + escapeHtml(extra.alt || '配图') + '" '
                         + 'style="max-width:100%;border-radius:8px;display:inline-block;" />'
                         + '</p>\n';
            }
        }

        return content;
    }

    // ==================== 发布文章 ====================
    function initPublishBtn() {
        $('#yimai-gso-publish-btn').on('click', function () {
            var title   = $.trim($('#yimai-gso-title').val());
            var content = $.trim($('#yimai-gso-content').val());

            if (!title || !content) {
                alert('标题和正文不能为空！');
                return;
            }

            var $btn    = $(this);
            var status  = $('input[name="yimai-gso-status"]:checked').val() || 'draft';
            var schedAt = status === 'future' ? $('#yimai-gso-scheduled-at').val() : '';

            if (status === 'future' && !schedAt) {
                alert('请选择定时发布时间！');
                return;
            }

            // datetime-local 格式为 YYYY-MM-DDTHH:MM，转为 YYYY-MM-DD HH:MM:00 供PHP解析
            if (schedAt && schedAt.indexOf('T') !== -1) {
                schedAt = schedAt.replace('T', ' ') + ':00';
            }

            // ===== 关键：先把图片直接嵌入正文中 =====
            var finalContent = injectImagesIntoContent(content, state.images);

            $btn.prop('disabled', true).html('<span class="yimai-gso-loading"></span> 正在发布...');
            $('#yimai-gso-publish-result').hide();

            var requestData = {
                action:        'yimai_gso_publish_article',
                nonce:         yimaiGsoData.nonce,
                title:         title,
                content:       finalContent,    // 已经包含图片标签的HTML
                excerpt:       $('#yimai-gso-excerpt').val(),
                tags:          $('#yimai-gso-tags').val(),
                category_id:   $('#yimai-gso-category').val(),
                status:        status,
                scheduled_at:  schedAt,
                thumbnail_id:  state.selectedThumbnailId || 0,
                image_count:   state.images ? state.images.length : 0,
            };

            // SEO关键词
            if (state.articleData && state.articleData.keywords) {
                requestData.keywords = state.articleData.keywords;
            }
            // AI 生图 Phase 2：延迟生成的图片prompts
            if (state.articleData && state.articleData.pending_image_prompts && state.articleData.pending_image_prompts.length > 0) {
                requestData.pending_image_prompts = state.articleData.pending_image_prompts;
            }

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function (res) {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-cloud-upload"></span> 发布到 WordPress'
                    );

                    var $result = $('#yimai-gso-publish-result');
                    if (res.success) {
                        var statusText = {
                            publish: '🚀 已发布',
                            draft:   '💾 已存为草稿',
                            future:  '⏰ 已设置定时发布',
                        }[res.data.status] || res.data.status;

                        $result.removeClass('error').addClass('success').html(
                            statusText + '：<strong>' + escapeHtml(res.data.title) + '</strong><br>' +
                            '<a href="' + res.data.post_url + '" target="_blank">查看文章</a> | ' +
                            '<a href="' + res.data.edit_url + '" target="_blank">编辑文章</a>'
                        ).show();
                    } else {
                        $result.removeClass('success').addClass('error').html(
                            '❌ 发布失败：' + (res.data.message || '未知错误')
                        ).show();
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-cloud-upload"></span> 发布到 WordPress'
                    );
                    showError('网络请求失败，请重试');
                }
            });
        });
    }

    // ==================== 重新生成 ====================
    function initRegenerateBtn() {
        $('#yimai-gso-regenerate-btn').on('click', function () {
            $('#yimai-gso-preview-panel').hide();
            $('#yimai-gso-generate-btn').trigger('click');
        });
    }

    // ==================== 设置页面 ====================
    function initSettingsPage() {
        // AI提供商切换
        $('#setting-ai-provider').on('change', function () {
            var providers = yimaiGsoData.providers;
            var key = $(this).val();
            if (providers[key]) {
                var prov = providers[key];
                if (prov.url && key !== 'custom') {
                    var $url = $('input[name="yimai_gso_ai_api_url"]');
                    $url.attr('placeholder', prov.url).val(prov.url);
                }
                if (prov.models && prov.models.length > 0) {
                    $('#model-hint').text('推荐：' + prov.models.join(' / '));
                    $('input[name="yimai_gso_ai_model"]').val(prov.models[0]);
                } else if (key === 'custom') {
                    $('input[name="yimai_gso_ai_api_url"]').attr('placeholder', '填写兼容 OpenAI Chat Completions 的接口地址');
                    $('#model-hint').text('请填写接口支持的模型名称');
                }
            }
        }).trigger('change');

        // 图片提供商切换
        $('#setting-img-provider').on('change', function () {
            var val = $(this).val();
            $('#pexels-key-row, #unsplash-key-row, #pixabay-key-row, #flickr-key-row, #stockapi-key-row, #custom-img-key-row, #custom-img-url-row, #dalle-model-row, #img-prompt-row').hide();
            $('#ai-image-section').hide();
            if (val === 'pexels')           { $('#pexels-key-row').show(); }
            if (val === 'unsplash')         { $('#unsplash-key-row').show(); }
            if (val === 'pixabay')          { $('#pixabay-key-row').show(); }
            if (val === 'flickr')           { $('#flickr-key-row').show(); }
            if (val === 'stockapi')         { $('#stockapi-key-row').show(); }
            if (val === 'custom')           { $('#custom-img-key-row').show(); $('#custom-img-url-row').show(); }
            if (val === 'dalle')            { $('#dalle-model-row').show(); }
            if (val === 'ai')               { $('#ai-image-section').show(); $('#setting-ai-img-provider').trigger('change'); }
            // local 显示图片库筛选配置
            if (val === 'local')            { $('#local-img-config-section').show(); } else { $('#local-img-config-section').hide(); }
            // mtphotos 显示 MT Photos 配置
            if (val === 'mtphotos')         { $('#mtphotos-config-section').show(); $('#img-prompt-row').show(); } else { $('#mtphotos-config-section').hide(); }
        }).trigger('change');

        // ★ AI 生图服务商切换
        $('#setting-ai-img-provider').on('change', function () {
            var val = $(this).val();
            $('.ai-img-key-row').hide();
            $('#ai-key-row-' + val).show();
            updateAiImgModelText(val);
        });

        // ★ AI 生图测试连接
        $('.yimai-gso-test-ai-img-btn').on('click', function () {
            var $btn = $(this);
            var provider = $btn.data('provider');
            var $row = $btn.closest('.ai-img-key-row');
            var apiKey = $row.find('input[type="password"]').val();
            var $result = $row.find('.yimai-gso-test-ai-img-result');

            if (!apiKey) { $result.html(' <span style="color:red">请先填写 API Key</span>'); return; }

            $btn.prop('disabled', true).text('测试中...');
            $result.html(' <span style="color:#888">正在生成测试图...</span>');

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'yimai_gso_test_ai_image',
                    nonce: yimaiGsoData.nonce,
                    provider: provider,
                    api_key: apiKey
                },
                success: function (res) {
                    if (res.success) {
                        var imgHtml = res.data.image_url ? '<br><img src="' + escapeHtml(res.data.image_url) + '" style="max-width:200px;margin-top:8px;border-radius:4px;">' : '';
                        $result.html(' <span style="color:green">✅ ' + escapeHtml(res.data.message) + '</span>' + imgHtml);
                    } else {
                        $result.html(' <span style="color:red">❌ ' + escapeHtml(res.data.message || '失败') + '</span>');
                    }
                    $btn.prop('disabled', false).text('测试连接');
                },
                error: function () {
                    $result.html(' <span style="color:red">❌ 请求失败，请检查网络</span>');
                    $btn.prop('disabled', false).text('测试连接');
                }
            });
        });

        // ★ 风格预设联动提示词模板
        $('#setting-ai-img-style').on('change', function () {
            var style = $(this).val();
            // 仅提示，不自动替换提示词（保留用户自定义内容）
        });

        $('.yimai-gso-auto-image-alt-toggle, .yimai-gso-auto-image-alt-mode')
            .off('.yimaiGsoAutoImageAlt')
            .on('change.yimaiGsoAutoImageAlt', function () {
                applyAutoImageAltFeatureState();
            });
        applyAutoImageAltFeatureState();

        // 测试AI接口
        $('#test-ai-api').on('click', function () {

            var $btn    = $(this);
            var apiKey  = $('input[name="yimai_gso_ai_api_key"]').val();
            // 如果输入框显示掩码值（****开头），说明用户未修改，标记使用已保存的Key
            var useSaved = (apiKey && apiKey.indexOf('****') === 0);
            if (useSaved) { apiKey = ''; }
            var apiUrl  = $('input[name="yimai_gso_ai_api_url"]').val();
            var provider = $('#setting-ai-provider').val();
            var model    = $('input[name="yimai_gso_ai_model"]').val();
            var $result = $('#test-ai-result');

            if (!useSaved && !apiKey) { $result.html(' <span style="color:red">请先填写 API Key</span>'); return; }

            $btn.prop('disabled', true).text('测试中...');
            $result.html(' <span style="color:#888">正在连接...</span>');

            var postData = {
                action: 'yimai_gso_test_api',
                nonce: yimaiGsoData.nonce,
                type: 'ai',
                provider: provider,
                model: model,
                api_url: apiUrl
            };
            if (useSaved) {
                postData.use_saved = '1';
            } else {
                postData.api_key = apiKey;
            }

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: postData,
                success: function (res) {
                    if (res.success) { $result.html(' <span style="color:green">✅ ' + escapeHtml(res.data.message) + '</span>'); }
                    else { $result.html(' <span style="color:red">❌ ' + escapeHtml(res.data.message || '失败') + '</span>'); }
                    $btn.prop('disabled', false).text('测试连接');
                },
                error: function () { $result.html(' <span style="color:red">❌ 请求失败</span>'); $btn.prop('disabled', false).text('测试连接'); }
            });
        });

        // 测试图片接口
        $('#test-img-api').on('click', function () {
            var $btn    = $(this);
            var $row   = $btn.closest('.yimai-gso-field-group');
            var apiKey = $row.find('input').first().val()
                       || $('input[name="yimai_gso_pexels_api_key"]').val()
                       || $('input[name="yimai_gso_unsplash_api_key"]').val()
                       || $('input[name="yimai_gso_image_api_key"]').val()
                       || $('input[name="yimai_gso_flickr_api_key"]').val()
                       || $('input[name="yimai_gso_stockapi_api_key"]').val()
                       || $('input[name="yimai_gso_custom_img_api_key"]').val();
            var $result = $row.next('span#test-img-result');
            if ($result.length === 0) { $result = $btn.parent().next('span#test-img-result'); }

            // 根据当前行的id确定provider
            var provider = 'pexels';
            if ($row.attr('id') === 'unsplash-key-row') provider = 'unsplash';
            else if ($row.attr('id') === 'pixabay-key-row') provider = 'pixabay';
            else if ($row.attr('id') === 'flickr-key-row') provider = 'flickr';
            else if ($row.attr('id') === 'stockapi-key-row') provider = 'stockapi';
            else if ($row.attr('id') === 'custom-img-key-row') provider = 'custom';

            if (!apiKey) { $result.html(' <span style="color:red">请先填写 API Key</span>'); return; }

            $btn.prop('disabled', true).text('测试中...');

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: { action: 'yimai_gso_test_api', nonce: yimaiGsoData.nonce, type: 'image', api_key: apiKey, img_provider: provider },
                success: function (res) {
                    if (res.success) { $result.html(' <span style="color:green">✅ ' + escapeHtml(res.data.message) + '</span>'); }
                    else { $result.html(' <span style="color:red">❌ ' + escapeHtml(res.data.message || '失败') + '</span>'); }
                    $btn.prop('disabled', false).text('测试连接');
                },
                error: function () { $result.html(' <span style="color:red">❌ 请求失败</span>'); $btn.prop('disabled', false).text('测试连接'); }
            });
        });

        // MT Photos 测试连接（直接把表单当前值发给服务端，无需先保存）
        $(document).on('click', '#test-mtphotos-api', function () {
            var $btn = $(this);
            var $result = $('#test-mtphotos-result');
            $btn.prop('disabled', true).text('测试中...');
            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'yimai_gso_test_api',
                    nonce: yimaiGsoData.nonce,
                    type: 'image',
                    img_provider: 'mtphotos',
                    mtphotos_host: $('input[name="yimai_gso_mtphotos_host"]').val() || '',
                    mtphotos_api_key: $('input[name="yimai_gso_mtphotos_api_key"]').val() || '',
                    mtphotos_username: $('input[name="yimai_gso_mtphotos_username"]').val() || '',
                    mtphotos_password: $('input[name="yimai_gso_mtphotos_password"]').val() || ''
                },
                success: function (res) {
                    if (res.success) { $result.html(' <span style="color:green">✅ ' + escapeHtml(res.data.message) + '</span>'); }
                    else { $result.html(' <span style="color:red">❌ ' + escapeHtml(res.data.message || '失败') + '</span>'); }
                    $btn.prop('disabled', false).text('测试连接');
                },
                error: function () { $result.html(' <span style="color:red">❌ 请求失败</span>'); $btn.prop('disabled', false).text('测试连接'); }
            });
        });

        // ★ AI 生图模型版本联动
        function updateAiImgModelText(provider) {
            var defaultModels = {
                'doubao': 'doubao-seedream-5-0-260128',
                'zhipu': 'cogview-4',
                'wanxiang': 'wan2.6-t2i'
            };
            var $input = $('input[name="yimai_gso_ai_image_model"]');
            // 仅当输入框为空或内容是旧的默认值时才自动填充
            var currentVal = $input.val() || '';
            var isDefault = false;
            $.each(defaultModels, function (k, v) {
                if (currentVal === v) isDefault = true;
            });
            if (!currentVal || isDefault) {
                $input.val(defaultModels[provider] || '');
            }
        }

        // 系统提示词折叠
        $('#yimai-gso-toggle-default-prompt').on('click', function () {
            var $box = $('#yimai-gso-default-prompt-box');
            var $btn = $(this);
            if ($box.is(':hidden')) {
                $box.slideDown(300);
                $btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                $btn.find('.btn-text').text('收起默认提示词');
            } else {
                $box.slideUp(300);
                $btn.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                $btn.find('.btn-text').text('查看默认提示词');
            }
        });

        // 单篇生成系统提示词折叠
        $('#yimai-gso-toggle-default-single-prompt').on('click', function () {
            var $box = $('#yimai-gso-default-single-prompt-box');
            var $btn = $(this);
            if ($box.is(':hidden')) {
                $box.slideDown(300);
                $btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                $btn.find('.btn-text').text('收起');
            } else {
                $box.slideUp(300);
                $btn.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                $btn.find('.btn-text').text('查看内置默认');
            }
        });

        // 用户提示词模板折叠
        $('#yimai-gso-toggle-default-user-prompt').on('click', function () {
            var $box = $('#yimai-gso-default-user-prompt-box');
            var $btn = $(this);
            if ($box.is(':hidden')) {
                $box.slideDown(300);
                $btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                $btn.find('.btn-text').text('收起默认用户提示词');
            } else {
                $box.slideUp(300);
                $btn.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                $btn.find('.btn-text').text('查看默认用户提示词');
            }
        });
    }

    // ==================== 自定义图片上传 ====================
    function initImageUpload() {
        $('#yimai-gso-upload-custom').on('click', function (e) {
            e.preventDefault();
            var frame = wp.media({
                title:    '选择封面图片',
                button:   { text: '设为封面图' },
                multiple: false,
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                state.selectedThumbnailId = attachment.id;
                $('#yimai-gso-thumbnail-id').val(attachment.id);

                var $grid = $('#yimai-gso-images-grid');
                var $item = $('<div class="yimai-gso-img-item selected">').attr('data-url', attachment.url);
                $item.append('<img src="' + attachment.url + '" alt="自定义封面">');
                $item.append('<span class="yimai-gso-img-badge">✓</span>');
                $item.append('<span class="yimai-gso-img-source">自定义</span>');
                $('.yimai-gso-img-item').removeClass('selected');
                $grid.prepend($item);
            });

            frame.open();
        });
    }

    // ==================== 工具函数 ====================
    function showError(msg) {
        alert('❌ ' + msg);
        $('#yimai-gso-progress').hide();
    }

    function getFeatureGatePayload(payload) {
        if (payload && typeof payload === 'object') {
            return payload;
        }

        return (window.yimaiGsoData && window.yimaiGsoData.featureGate) ? window.yimaiGsoData.featureGate : {};
    }


    function canUseBatchAdvancedPublish(payload) {
        var gate = getFeatureGatePayload(payload);
        var featureMap = gate.featureMap || {};
        var readonlyFeatures = Array.isArray(gate.readonlyFeatures) ? gate.readonlyFeatures : [];
        // 直接发布和定时发布需要批量生成功能、发布权限，草稿生成不受此门控影响。
        return !!featureMap.batch_generate
            && readonlyFeatures.indexOf('batch_generate') === -1
            && gate.canPublishPosts !== false;
    }

    function canUseBatchDraft(payload) {
        var gate = getFeatureGatePayload(payload);
        var featureMap = gate.featureMap || {};
        var readonlyFeatures = Array.isArray(gate.readonlyFeatures) ? gate.readonlyFeatures : [];
        var hasBatchFeature = !!(featureMap.batch_generate || featureMap.batch_generate_draft);
        return hasBatchFeature
            && readonlyFeatures.indexOf('batch_generate') === -1
            && readonlyFeatures.indexOf('batch_generate_draft') === -1
            && gate.canEditPosts !== false;
    }

    /**
     * 从 paramConfig 获取参数可用值（参数级权限）
     * @param {string} feature 功能名
     * @param {string} param   参数名
     * @param {object} payload 可选的 gate payload
     * @returns {array} 当前配置可用的参数值列表
     */
    function getParamAllowedValues(feature, param, payload) {
        var gate = getFeatureGatePayload(payload);
        var paramConfig = gate.paramConfig || {};
        var plan = gate.plan || 'free';
        var featureConfig = paramConfig[feature];
        if (!featureConfig) return [];
        var paramDef = featureConfig[param];
        if (!paramDef) return [];
        return paramDef[plan] || paramDef['free'] || [];
    }

    function normalizeBatchStatus(status, payload) {
        var nextStatus = typeof status === 'string' ? $.trim(status) : 'draft';

        // 优先使用参数级权限配置
        var allowedStatuses = getParamAllowedValues('batch_generate', 'post_status', payload);
        if (allowedStatuses.length === 0) {
            // 回退到旧逻辑
            allowedStatuses = ['draft', 'publish', 'future'];
            if (!canUseBatchAdvancedPublish(payload) && nextStatus !== 'draft') {
                return 'draft';
            }
        }

        if (nextStatus !== 'draft' && !canUseBatchAdvancedPublish(payload)) {
            return 'draft';
        }

        if (allowedStatuses.indexOf(nextStatus) === -1) {
            // 降级到 default 或第一个可用值
            var gate = getFeatureGatePayload(payload);
            var paramConfig = gate.paramConfig || {};
            var batchConfig = paramConfig.batch_generate || {};
            var statusConfig = batchConfig.post_status || {};
            var defaultValue = statusConfig['default'] || allowedStatuses[0] || 'draft';
            return defaultValue;
        }

        return nextStatus;
    }

    function applyBatchPublishFeatureState(payload) {
        var gate = getFeatureGatePayload(payload);
        var paramConfig = gate.paramConfig || {};
        var plan = gate.plan || 'free';

        // 优先使用参数级权限
        var allowedStatuses = getParamAllowedValues('batch_generate', 'post_status', payload);
        var useParamConfig = allowedStatuses.length > 0;

        var currentStatus = $('input[name="yimai-gso-batch-status"]:checked').val() || 'draft';
        var safeStatus = normalizeBatchStatus(currentStatus, payload);

        $('input[name="yimai-gso-batch-status"]').each(function () {
            var $radio = $(this);
            var val = $radio.val();
            var disabled;

            if (useParamConfig) {
                disabled = allowedStatuses.indexOf(val) === -1
                    || (val !== 'draft' && !canUseBatchAdvancedPublish(payload));
            } else {
                // 回退到旧逻辑
                disabled = !canUseBatchAdvancedPublish(payload) && val !== 'draft';
            }

            $radio.prop('disabled', disabled);
            $radio.closest('.yimai-gso-radio').toggleClass('is-disabled', disabled);
        });

        $('input[name="yimai-gso-batch-status"][value="' + safeStatus + '"]').prop('checked', true);
    }

    function applyBatchAiTagsFeatureState(payload) {
        var gate = getFeatureGatePayload(payload);
        var featureMap = gate.featureMap || {};
        var readonlyFeatures = Array.isArray(gate.readonlyFeatures) ? gate.readonlyFeatures : [];
        var hasBatchGenerate = !!featureMap.batch_generate;
        var hasAiAutoTags = !!featureMap.auto_tags; // 规范化名称
        var isReadonly = readonlyFeatures.indexOf('auto_tags') !== -1;
        var canUseAiAutoTags = hasBatchGenerate && hasAiAutoTags && !isReadonly;
        var $toggle = $('#yimai-gso-batch-ai-tags');
        var $wrap = $('#yimai-gso-batch-ai-tags-wrap');
        var $note = $('#yimai-gso-batch-ai-tags-note');

        if (!$toggle.length) {
            return;
        }

        if (canUseAiAutoTags) {
            $toggle.prop('disabled', false);
            if ($wrap.length) {
                $wrap.css({
                    opacity: '',
                    cursor: ''
                });
            }
            if ($note.length) {
                $note.text('开启后，AI 会为每篇文章自动生成 3-5 个相关标签，与统一标签合并后保存');
            }
            return;
        }

        $toggle.prop('checked', false).prop('disabled', true);

        if ($wrap.length) {
            $wrap.css({
                opacity: '0.55',
                cursor: 'not-allowed'
            });
        }

        if (!$note.length) {
            return;
        }

        if (!hasBatchGenerate) {
            $note.text('当前功能未启用批量生成，AI 自动标签暂不可用');
        } else if (isReadonly) {
            $note.text('当前设置仅保留 AI 自动标签查看态，批量生成时已自动关闭执行');
        } else {
            $note.text('当前配置未开通 AI 自动标签增强项，批量生成时将仅使用统一标签');
        }
    }

    function applyAutoImageAltFeatureState(payload) {
        var gate = getFeatureGatePayload(payload);
        var featureMap = gate.featureMap || {};
        var readonlyFeatures = Array.isArray(gate.readonlyFeatures) ? gate.readonlyFeatures : [];
        var hasFeature = !!featureMap.auto_alt; // 规范化名称
        var isReadonly = readonlyFeatures.indexOf('auto_alt') !== -1;

        $('.yimai-gso-auto-image-alt-root').each(function () {
            var $root = $(this);
            var $toggle = $root.find('.yimai-gso-auto-image-alt-toggle');
            var $mode = $root.find('.yimai-gso-auto-image-alt-mode');
            var $note = $root.find('.yimai-gso-auto-image-alt-note');
            var enabled = $toggle.is(':checked');

            if (!hasFeature || isReadonly) {
                $root.css({ opacity: '0.55' });
                $toggle.prop('checked', false).prop('disabled', true);
                $mode.prop('disabled', true);

                if ($note.length) {
                    $note.text(isReadonly
                        ? '当前设置仅保留自动图片ALT查看态，前台不会实际执行自动生成。'
                        : '当前配置未开通自动图片ALT增强项，将继续沿用图库原始ALT或手动ALT。');
                }
                return;
            }

            $root.css({ opacity: '' });
            $toggle.prop('disabled', false);
            $mode.prop('disabled', !enabled);

            if (!$note.length) {
                return;
            }

            if (!enabled) {
                $note.text('关闭后，将继续沿用图库原始ALT或已有手动ALT，不额外生成。');
            } else if ($mode.val() === 'ai') {
                $note.text('AI 模式会按整篇文章一次性生成各图片ALT；若接口异常，会自动回退到规则模式。');
            } else {
                $note.text('规则模式不增加额外AI调用，会基于标题、关键词和小节语义自动组合ALT。');
            }
        });
    }


    var seoMetaState = {
        isScanning: false,
        isPreviewing: false,
        isApplying: false,
        scanItems: [],
        previewItems: []
    };

    function getSeoMetaRoot() {
        return $('#yimai-gso-seo-meta-root');
    }

    function seoMetaFeatureAvailable() {
        var $root = getSeoMetaRoot();
        if (!$root.length) return false;
        return String($root.data('available')) === '1';
    }

    function seoMetaReadonly() {
        var $root = getSeoMetaRoot();
        if (!$root.length) return false;
        return String($root.data('readonly')) === '1';
    }

    function setSeoMetaStatus(message, type) {
        var $box = $('#yimai-gso-seo-status-box');
        if (!$box.length) return;

        $box.removeClass('success error');
        $box.addClass(type === 'error' ? 'error' : 'success');
        $box.html(escapeHtml(message || ''));
    }

    function updateSeoMetaActionState() {
        var selectedScanCount = $('.yimai-gso-seo-scan-check:checked').length;
        var selectedPreviewCount = $('.yimai-gso-seo-preview-check:checked').length;
        var available = seoMetaFeatureAvailable();
        var readonly = seoMetaReadonly();

        $('#yimai-gso-seo-preview-btn').prop('disabled', !available || seoMetaState.isPreviewing || seoMetaState.isScanning || selectedScanCount === 0);
        $('#yimai-gso-seo-apply-btn').prop('disabled', !available || readonly || seoMetaState.isApplying || seoMetaState.isPreviewing || selectedPreviewCount === 0);
    }

    function updateSeoMetaCounters() {
        var scanCount = $('.yimai-gso-seo-scan-card').length;
        var previewCount = $('.yimai-gso-seo-preview-card[data-success="1"]').length;
        var $scanCounter = $('#yimai-gso-seo-scan-count');
        var $previewCounter = $('#yimai-gso-seo-preview-count');

        if ($scanCounter.length) {
            if (scanCount > 0) {
                $scanCounter.text(scanCount).show();
            } else {
                $scanCounter.hide().text('0');
            }
        }

        if ($previewCounter.length) {
            if (previewCount > 0) {
                $previewCounter.text(previewCount).show();
            } else {
                $previewCounter.hide().text('0');
            }
        }

        updateSeoMetaActionState();
    }

    function renderSeoMetaScanResults(items) {
        var $container = $('#yimai-gso-seo-scan-results');
        if (!$container.length) return;

        seoMetaState.scanItems = items || [];
        $('#yimai-gso-seo-preview-results').html('<div class="yimai-gso-empty"><p>勾选候选文章后点击“生成SEO预览”，这里会逐篇展示建议标题、描述和关键词。</p></div>');
        seoMetaState.previewItems = [];

        if (!items || items.length === 0) {
            $container.html('<div class="yimai-gso-empty"><p>没有扫描到符合条件的文章，试试扩大范围或取消“只扫描缺少SEO字段”。</p></div>');
            updateSeoMetaCounters();
            return;
        }

        var html = '';
        $.each(items, function (_, item) {
            var current = item.current || {};
            html += '<div class="yimai-gso-history-card yimai-gso-seo-scan-card" data-post-id="' + item.id + '" style="margin-bottom:12px;">';
            html += '  <div class="yimai-gso-history-card-header">';
            html += '    <label style="display:flex;align-items:center;gap:8px;font-weight:600;">';
            html += '      <input type="checkbox" class="yimai-gso-seo-scan-check" value="' + item.id + '">';
            html += '      <span>#' + item.id + '</span>';
            html += '    </label>';
            html += '    <span class="yimai-gso-history-time">' + escapeHtml(item.status_text || item.status || '') + ' · ' + escapeHtml(item.date || '') + '</span>';
            html += '  </div>';
            html += '  <div style="font-size:15px;font-weight:700;color:#1e293b;line-height:1.6;">' + escapeHtml(item.title || '') + '</div>';
            html += '  <div style="margin-top:8px;color:#64748b;font-size:13px;line-height:1.8;">';
            html += '    <div>当前SEO标题：' + escapeHtml(current.title || '暂无') + '</div>';
            html += '    <div>当前SEO描述：' + escapeHtml(current.description || '暂无') + '</div>';
            html += '    <div>当前SEO关键词：' + escapeHtml(current.keywords || '暂无') + '</div>';
            html += '  </div>';
            html += '  <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';
            html += '    <span class="yimai-gso-detect-tip">覆盖度：' + escapeHtml(current.coverage_text || '0/3') + '</span>';
            if (item.view_url) {
                html += '    <a href="' + item.view_url + '" target="_blank">查看文章</a>';
            }
            if (item.edit_url) {
                html += '    <a href="' + item.edit_url + '" target="_blank">编辑文章</a>';
            }
            html += '  </div>';
            html += '</div>';
        });

        $container.html(html);
        updateSeoMetaCounters();
    }

    function appendSeoMetaPreviewCard(item, failedMessage) {
        var $container = $('#yimai-gso-seo-preview-results');
        if (!$container.length) return;

        if ($container.find('.yimai-gso-empty').length) {
            $container.empty();
        }

        if (failedMessage) {
            var errorHtml = '<div class="yimai-gso-history-card yimai-gso-seo-preview-card" data-success="0" style="margin-bottom:12px;border-left:4px solid #ef4444;">'
                + '<div class="yimai-gso-history-card-header"><strong>' + escapeHtml(item.post_title || item.title || ('文章 #' + item.id)) + '</strong><span class="yimai-gso-history-time">预览失败</span></div>'
                + '<div style="color:#991b1b;font-size:13px;line-height:1.8;">' + escapeHtml(failedMessage) + '</div>'
                + '</div>';
            $container.append(errorHtml);
            updateSeoMetaCounters();
            return;
        }

        var current = item.current || {};
        var suggested = item.suggested || {};
        var html = '<div class="yimai-gso-history-card yimai-gso-seo-preview-card" data-post-id="' + item.post_id + '" data-success="1" style="margin-bottom:12px;border-left:4px solid #6366f1;">';
        html += '  <div class="yimai-gso-history-card-header">';
        html += '    <label style="display:flex;align-items:center;gap:8px;font-weight:600;">';
        html += '      <input type="checkbox" class="yimai-gso-seo-preview-check" value="' + item.post_id + '" checked>';
        html += '      <span>写回该文章</span>';
        html += '    </label>';
        html += '    <span class="yimai-gso-history-time">' + escapeHtml(item.post_title || '') + '</span>';
        html += '  </div>';
        html += '  <div class="yimai-gso-form-group"><label>SEO标题</label><input type="text" class="large-text yimai-gso-seo-preview-title" value="' + escapeHtml(suggested.title || '') + '"></div>';
        html += '  <div class="yimai-gso-form-group"><label>SEO描述</label><textarea class="yimai-gso-seo-preview-description" rows="4">' + escapeHtml(suggested.description || '') + '</textarea></div>';
        html += '  <div class="yimai-gso-form-group"><label>SEO关键词</label><textarea class="yimai-gso-seo-preview-keywords" rows="2">' + escapeHtml(suggested.keywords || '') + '</textarea></div>';
        html += '  <div class="yimai-gso-result-box success" style="margin-top:10px;">';
        html += '    当前SEO标题：' + escapeHtml(current.title || '暂无') + '<br>';
        html += '    当前SEO描述：' + escapeHtml(current.description || '暂无') + '<br>';
        html += '    当前SEO关键词：' + escapeHtml(current.keywords || '暂无');
        html += '  </div>';
        html += '  <div class="yimai-gso-seo-apply-result" style="display:none;margin-top:10px;"></div>';
        html += '</div>';

        $container.append(html);
        seoMetaState.previewItems.push(item);
        updateSeoMetaCounters();
    }

    function getSelectedSeoScanIds() {
        var ids = [];
        $('.yimai-gso-seo-scan-check:checked').each(function () {
            ids.push(parseInt($(this).val(), 10));
        });
        return ids;
    }

    function getSelectedSeoPreviewPayloads() {
        var items = [];
        $('.yimai-gso-seo-preview-card[data-success="1"]').each(function () {
            var $card = $(this);
            if (!$card.find('.yimai-gso-seo-preview-check').is(':checked')) {
                return;
            }

            items.push({
                postId: parseInt($card.data('post-id'), 10),
                title: $.trim($card.find('.yimai-gso-seo-preview-title').val() || ''),
                description: $.trim($card.find('.yimai-gso-seo-preview-description').val() || ''),
                keywords: $.trim($card.find('.yimai-gso-seo-preview-keywords').val() || '')
            });
        });
        return items;
    }

    function resetSeoMetaButtons() {
        $('#yimai-gso-seo-scan-btn').html('<span class="dashicons dashicons-search"></span> 扫描候选文章');
        $('#yimai-gso-seo-preview-btn').html('<span class="dashicons dashicons-superhero"></span> 生成AI预览');
        $('#yimai-gso-seo-apply-btn').html('<span class="dashicons dashicons-saved"></span> 确认批量写回');
        updateSeoMetaActionState();
    }

    function runSeoMetaScan() {
        if (!seoMetaFeatureAvailable() || seoMetaState.isScanning) return;

        seoMetaState.isScanning = true;
        $('#yimai-gso-seo-scan-btn').prop('disabled', true).html('<span class="yimai-gso-loading"></span> 扫描中...');
        $('#yimai-gso-seo-preview-btn, #yimai-gso-seo-apply-btn').prop('disabled', true);
        setSeoMetaStatus('正在扫描候选文章，请稍候...', 'success');

        $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'yimai_gso_seo_meta_scan',
                nonce: yimaiGsoData.nonce,
                keyword: $('#yimai-gso-seo-keyword').val() || '',
                post_status: $('#yimai-gso-seo-post-status').val() || 'publish',
                limit: $('#yimai-gso-seo-limit').val() || '20',
                only_missing: $('#yimai-gso-seo-only-missing').is(':checked') ? '1' : '0'
            },
            success: function (res) {
                if (!res.success) {
                    renderSeoMetaScanResults([]);
                    setSeoMetaStatus((res.data && res.data.message) || '扫描失败，请重试。', 'error');
                    return;
                }

                renderSeoMetaScanResults((res.data && res.data.items) || []);
                setSeoMetaStatus('扫描完成，共找到 ' + (((res.data && res.data.total) || 0)) + ' 篇候选文章。', 'success');
            },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : '网络请求失败，请稍后重试。';
                renderSeoMetaScanResults([]);
                setSeoMetaStatus(msg, 'error');
            },
            complete: function () {
                seoMetaState.isScanning = false;
                resetSeoMetaButtons();
            }
        });
    }

    function runSeoMetaPreview() {
        var ids = getSelectedSeoScanIds();
        if (!ids.length || seoMetaState.isPreviewing || !seoMetaFeatureAvailable()) {
            if (!ids.length) {
                alert('请先勾选要生成SEO预览的文章！');
            }
            return;
        }

        seoMetaState.isPreviewing = true;
        seoMetaState.previewItems = [];
        $('#yimai-gso-seo-preview-results').html('<div class="yimai-gso-empty"><p>正在逐篇生成SEO预览，请稍候...</p></div>');
        $('#yimai-gso-seo-preview-btn').prop('disabled', true).html('<span class="yimai-gso-loading"></span> 预览生成中...');
        $('#yimai-gso-seo-apply-btn').prop('disabled', true);

        var index = 0;
        var successCount = 0;
        var errorCount = 0;

        function next() {
            if (index >= ids.length) {
                seoMetaState.isPreviewing = false;
                resetSeoMetaButtons();
                if (seoMetaReadonly()) {
                    setSeoMetaStatus('SEO预览生成完成：成功 ' + successCount + ' 篇，失败 ' + errorCount + ' 篇。当前当前为只读态，暂不可批量写回。', errorCount > 0 ? 'error' : 'success');
                } else {
                    setSeoMetaStatus('SEO预览生成完成：成功 ' + successCount + ' 篇，失败 ' + errorCount + ' 篇。请检查预览后再确认写回。', errorCount > 0 ? 'error' : 'success');
                }
                return;
            }

            var currentId = ids[index];
            setSeoMetaStatus('正在生成SEO预览（' + (index + 1) + '/' + ids.length + '）...', 'success');

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'yimai_gso_seo_meta_preview',
                    nonce: yimaiGsoData.nonce,
                    post_id: currentId
                },
                success: function (res) {
                    if (res.success) {
                        appendSeoMetaPreviewCard(res.data);
                        successCount++;
                    } else {
                        appendSeoMetaPreviewCard({ id: currentId, title: '文章 #' + currentId }, (res.data && res.data.message) || '预览生成失败');
                        errorCount++;
                    }
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : '网络请求失败';
                    appendSeoMetaPreviewCard({ id: currentId, title: '文章 #' + currentId }, msg);
                    errorCount++;
                },
                complete: function () {
                    index++;
                    next();
                }
            });
        }

        next();
    }

    function runSeoMetaApply() {
        var payloads = getSelectedSeoPreviewPayloads();
        if (!payloads.length || seoMetaState.isApplying) {
            if (!payloads.length) {
                alert('请先勾选要写回的SEO预览项！');
            }
            return;
        }

        if (seoMetaReadonly()) {
            alert('当前SEO增强能力为只读态，暂不可执行批量写回。');
            return;
        }

        seoMetaState.isApplying = true;
        $('#yimai-gso-seo-apply-btn').prop('disabled', true).html('<span class="yimai-gso-loading"></span> 写回中...');
        $('#yimai-gso-seo-preview-btn').prop('disabled', true);

        var index = 0;
        var successCount = 0;
        var errorCount = 0;

        function next() {
            if (index >= payloads.length) {
                seoMetaState.isApplying = false;
                resetSeoMetaButtons();
                setSeoMetaStatus('批量写回完成：成功 ' + successCount + ' 篇，失败 ' + errorCount + ' 篇。', errorCount > 0 ? 'error' : 'success');
                // 刷新顶部统计数据
                refreshSeoMetaStats();
                return;
            }

            var item = payloads[index];
            var $card = $('.yimai-gso-seo-preview-card[data-post-id="' + item.postId + '"]');
            var $result = $card.find('.yimai-gso-seo-apply-result');
            setSeoMetaStatus('正在写回SEO字段（' + (index + 1) + '/' + payloads.length + '）...', 'success');

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'yimai_gso_seo_meta_apply',
                    nonce: yimaiGsoData.nonce,
                    post_id: item.postId,
                    seo_title: item.title,
                    seo_description: item.description,
                    seo_keywords: item.keywords
                },
                success: function (res) {
                    if (res.success) {
                        successCount++;
                        $result.removeClass('error').addClass('success').html('✅ 写回成功：子比 / Yoast / Rank Math 已同步更新').show();
                    } else {
                        errorCount++;
                        $result.removeClass('success').addClass('error').html('❌ 写回失败：' + escapeHtml((res.data && res.data.message) || '未知错误')).show();
                    }
                },
                error: function (xhr) {
                    errorCount++;
                    var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : '网络请求失败';
                    $result.removeClass('success').addClass('error').html('❌ 写回失败：' + escapeHtml(msg)).show();
                },
                complete: function () {
                    index++;
                    next();
                }
            });
        }

        next();
    }

    /**
     * 刷新批量SEO元数据页面顶部统计数字
     */
    function refreshSeoMetaStats() {
        $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'yimai_gso_seo_meta_stats',
                nonce: yimaiGsoData.nonce
            },
            success: function (res) {
                if (res.success && res.data) {
                    var d = res.data;
                    var tips = $('.yimai-gso-detect-tips').first();
                    if (tips.length) {
                        tips.find('.yimai-gso-detect-tip').eq(0).text('最近写回：' + (d.total || 0) + ' 次');
                        tips.find('.yimai-gso-detect-tip').eq(1).text('成功：' + (d.success || 0) + ' 次');
                        tips.find('.yimai-gso-detect-tip').eq(2).text('失败：' + (d.error || 0) + ' 次');
                        tips.find('.yimai-gso-detect-tip').eq(3).text('最近执行：' + (d.last_run || '暂无'));
                    }
                }
            }
        });
    }

    function initSeoMetaPage() {
        var $panel = $('.yimai-gso-main-panel[data-tab="seo-meta"]');
        if (!$panel.length) return;

        $('#yimai-gso-seo-scan-btn').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            runSeoMetaScan();
        });

        $('#yimai-gso-seo-preview-btn').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            runSeoMetaPreview();
        });

        $('#yimai-gso-seo-apply-btn').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            runSeoMetaApply();
        });

        $('#yimai-gso-seo-select-all').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            $('.yimai-gso-seo-scan-check').prop('checked', true);
            updateSeoMetaActionState();
        });

        $('#yimai-gso-seo-clear-all').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            $('.yimai-gso-seo-scan-check').prop('checked', false);
            updateSeoMetaActionState();
        });

        $('#yimai-gso-seo-preview-select-all').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            $('.yimai-gso-seo-preview-check').prop('checked', true);
            updateSeoMetaActionState();
        });

        $('#yimai-gso-seo-preview-clear-all').off('click.yimaiGsoSeo').on('click.yimaiGsoSeo', function () {
            $('.yimai-gso-seo-preview-check').prop('checked', false);
            updateSeoMetaActionState();
        });

        $panel.off('change.yimaiGsoSeoScan').on('change.yimaiGsoSeoScan', '.yimai-gso-seo-scan-check, .yimai-gso-seo-preview-check', function () {
            updateSeoMetaActionState();
        });

        // 发布后自动SEO元数据 - 分类药丸切换
        var $catContainer = $('#yimai-gso-auto-seo-cats');
        $catContainer.off('click.yimaiGsoAutoSeo').on('click.yimaiGsoAutoSeo', 'label', function (e) {
            if (e.target.tagName === 'INPUT') return;
            var $label = $(this);
            var $checkbox = $label.find('input[type="checkbox"]');
            var newChecked = !$checkbox.prop('checked');
            $checkbox.prop('checked', newChecked);
            if (newChecked) {
                $label.css({ background: '#6c5ce7', color: '#fff' });
            } else {
                $label.css({ background: '#f0f0f1', color: '#333' });
            }
        });

        // 发布后自动SEO元数据 - 保存按钮
        $('#yimai-gso-auto-seo-save-btn').off('click.yimaiGsoAutoSeo').on('click.yimaiGsoAutoSeo', function () {
            var $btn = $(this);
            var $status = $('#yimai-gso-auto-seo-save-status');

            // 收集分类ID
            var catIds = [];
            $('#yimai-gso-auto-seo-cats input[type="checkbox"]:checked').each(function () {
                catIds.push($(this).val());
            });

            var data = {
                action: 'yimai_gso_save_seo_meta_settings',
                nonce: yimaiGsoData.nonce,
                auto_seo_meta_enabled: $('#yimai-gso-auto-seo-enabled').is(':checked') ? 1 : 0,
                auto_seo_meta_skip_manual: $('#yimai-gso-auto-seo-skip-manual').is(':checked') ? 1 : 0,
                auto_seo_meta_categories: catIds.join(',')
            };

            $btn.prop('disabled', true).text('保存中...');
            $status.hide();

            $.post(yimaiGsoData.ajaxUrl, data, function (resp) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> 保存自动设置');
                if (resp && resp.success) {
                    $status.text(resp.data.message || '已保存').css('color', '#46b450').fadeIn().delay(2000).fadeOut();
                } else {
                    $status.text((resp && resp.data && resp.data.message) || '保存失败').css('color', '#dc3232').fadeIn().delay(3000).fadeOut();
                }
            }).fail(function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> 保存自动设置');
                $status.text('网络错误，请重试').css('color', '#dc3232').fadeIn().delay(3000).fadeOut();
            });
        });

        updateSeoMetaCounters();
    }


    function showAutopilotRunResult(type, message) {
        var $result = $('#yimai-gso-autopilot-run-result');
        if (!$result.length) {
            return;
        }

        var className = 'info';
        if (type === 'success') {
            className = 'success';
        } else if (type === 'error') {
            className = 'error';
        }

        $result
            .removeClass('info success error')
            .addClass('apv3-run-result ' + className)
            .html(message)
            .show();
    }

    /**
     * 统一保存成功 Toast：显示 3 秒后自动消失
     * @param {string} tab 当前激活的 tab（用于匹配提示文案）
     */
    function showSaveSuccessToast(tab) {
        var messages = {
            'settings':       '✅ 插件设置已保存',
            'autopilot':      '✅ 永动发布设置已保存',
            'detection':      '✅ 内容检测设置已保存',
            'internal-links': '✅ 智能内链设置已保存',
            'auto-alt':       '✅ 自动图片ALT设置已保存',
            'seo-push':       '✅ SEO推送配置已保存',
            'llms-txt':       '✅ LLMs.txt 设置已保存',
            'history-image':  '✅ 配图已更换',
            'history-delete': '✅ 文章已删除'
        };
        var msg = messages[tab] || '✅ 设置已保存';

        // 移除可能存在的旧 Toast
        $('.yimai-gso-save-toast').remove();

        // 创建 Toast 元素（初始可见，避免动画时序问题）
        var $toast = $('<div class="yimai-gso-save-toast">')
            .css({
                'position':         'fixed',
                'top':              '50px',
                'left':             '50%',
                'transform':        'translateX(-50%)',
                'z-index':          '999999',
                'background':       '#059669',
                'color':            '#fff',
                'padding':          '12px 28px',
                'border-radius':    '8px',
                'font-size':        '14px',
                'font-weight':      '600',
                'box-shadow':       '0 4px 20px rgba(0,0,0,0.25)',
                'opacity':          '1',
                'transition':       'opacity 0.4s ease'
            })
            .text(msg)
            .appendTo('body');

        // 3 秒后淡出消失
        setTimeout(function() {
            $toast.css('opacity', '0');
            setTimeout(function() { $toast.remove(); }, 400);
        }, 3000);
    }

    function initAutopilotPage() {
        var $root = $('#yimai-gso-autopilot-root');
        var $button = $('#yimai-gso-autopilot-run-btn');

        if (!$root.length) {
            return;
        }

        // --- Collapsible sections ---
        $root.find('[data-apv3-collapsible]').each(function () {
            var $section = $(this);
            $section.find('.apv3-section-header').on('click.apv3Collapse', function (e) {
                // Don't collapse when clicking inside form elements
                if ($(e.target).is('input, select, textarea, label, button')) {
                    return;
                }
                $section.toggleClass('collapsed');
            });
        });

        // --- Radio pills ---
        $root.find('.apv3-radio-pill').on('change.apv3Pill', 'input[type="radio"]', function () {
            var $pill = $(this).closest('.apv3-radio-pill');
            $pill.siblings('.apv3-radio-pill').removeClass('active');
            $pill.addClass('active');

            // 定时发布切换：显示/隐藏延迟时间选择
            var $radio = $(this);
            if ($radio.attr('name') === 'yimai_gso_autopilot_post_status') {
                var $delayRow = $('#apv3-future-delay-row');
                if ($radio.val() === 'future') {
                    $delayRow.slideDown(200);
                } else {
                    $delayRow.slideUp(200);
                }
            }
        });

        // --- Toggle switches (计划开关 & AI自动标签) ---
        // 使用全局选择器，确保能选中所有开关
        $(document).on('click', '.apv3-toggle-track', function (e) {
            e.preventDefault();
            var $toggle = $(this);
            var $checkbox = $toggle.find('input[type="checkbox"]');
            if ($checkbox.is(':disabled')) {
                return;
            }
            var isChecked = $checkbox.is(':checked');
            $checkbox.prop('checked', !isChecked).trigger('change');
            $toggle.toggleClass('active', !isChecked);
        });

        $(document).on('click', '.apv3-mini-toggle', function (e) {
            e.preventDefault();
            var $toggle = $(this);
            var $checkbox = $toggle.find('input[type="checkbox"]');
            if ($checkbox.is(':disabled')) {
                return;
            }
            var isChecked = $checkbox.is(':checked');
            $checkbox.prop('checked', !isChecked).trigger('change');
            $toggle.toggleClass('active', !isChecked);
        });

        // --- 永动发布开关：点击后自动保存 ---
        // 监听计划开关（主 Hero 区域）的 checkbox 变化
        $root.find('input[name="yimai_gso_autopilot_enabled"]').off('change.yimaiGsoAutopilotToggle').on('change.yimaiGsoAutopilotToggle', function () {
            var $checkbox = $(this);
            var enabled = $checkbox.is(':checked');
            
            // 禁用开关防止重复点击
            $checkbox.prop('disabled', true);
            
            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'yimai_gso_autopilot_toggle',
                    nonce: yimaiGsoData.nonce,
                    enabled: enabled ? '1' : ''
                },
                success: function (res) {
                    if (res.success) {
                        // 保存成功，重新启用开关
                        $checkbox.prop('disabled', false);
                        showAutopilotRunResult('success', res.data.message || (enabled ? '永动发布已开启' : '永动发布已关闭'));
                    } else {
                        // 保存失败，恢复原状态
                        $checkbox.prop('checked', !enabled).prop('disabled', false);
                        $root.find('.apv3-toggle-track').toggleClass('active', !enabled);
                        showAutopilotRunResult('error', (res.data && res.data.message) || '保存失败，请重试');
                    }
                },
                error: function () {
                    // 保存失败，恢复原状态
                    $checkbox.prop('checked', !enabled).prop('disabled', false);
                    $root.find('.apv3-toggle-track').toggleClass('active', !enabled);
                    showAutopilotRunResult('error', '网络错误，请重试');
                }
            });
        });

        // --- Manual run ---
        if ($button.length) {
            $button.off('click.yimaiGsoAutopilot').on('click.yimaiGsoAutopilot', function () {
                if ($button.prop('disabled')) {
                    return;
                }

                var available = String($root.data('available')) === '1';
                var readonly = String($root.data('readonly')) === '1';

                if (!available) {
                    showAutopilotRunResult('error', '当前配置未开通永动发布，暂不能手动执行。');
                    return;
                }

                if (readonly) {
                    showAutopilotRunResult('error', '当前永动发布能力为只读态，暂不能手动执行。');
                    return;
                }

                var originalHtml = $button.html();
                $button.prop('disabled', true).html('<span class="yimai-gso-loading"></span>正在派发...');
                showAutopilotRunResult('info', '正在派发永动发布任务，后台会继续执行完整链路，请稍候...');

                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_autopilot_manual_run',
                        nonce: yimaiGsoData.nonce
                    },
                    success: function (res) {
                        if (!res.success) {
                            showAutopilotRunResult('error', '执行失败：' + escapeHtml((res.data && res.data.message) || '未知错误'));
                            return;
                        }

                        var run = (res.data && res.data.run) ? res.data.run : {};
                        var successCount = parseInt(run.success_count || 0, 10);
                        var failedCount = parseInt(run.failed_count || 0, 10);
                        var totalCount = parseInt(run.daily_count || 0, 10);
                        var status = String(run.status || 'pending');
                        var serverMessage = (res.data && res.data.message) ? String(res.data.message) : '';

                        if (status === 'pending' || status === 'running') {
                            var queuedMessage = serverMessage || run.last_message || '永动发布任务已派发，后台开始执行。';
                            if (totalCount > 0) {
                                queuedMessage += ' 本轮计划执行 ' + totalCount + ' 篇。';
                            }
                            showAutopilotRunResult('info', escapeHtml(queuedMessage) + ' 2 秒后自动刷新日志。');
                            window.setTimeout(function () {
                                window.location.reload();
                            }, 2000);
                            return;
                        }

                        var statusType = 'success';
                        var summary = '永动发布执行完成';

                        if (status === 'failed' || status === 'aborted') {
                            statusType = 'error';
                            summary = '永动发布执行失败';
                        } else if (failedCount > 0) {
                            statusType = 'info';
                            summary = '永动发布执行完成，但有部分文章失败';
                        }

                        var detail = summary + '：成功 ' + successCount + ' 篇';
                        if (totalCount > 0) {
                            detail += ' / 共 ' + totalCount + ' 篇';
                        }
                        if (failedCount > 0) {
                            detail += '，失败 ' + failedCount + ' 篇';
                        }

                        if (run.last_message) {
                            detail += '。' + escapeHtml(run.last_message);
                        } else if (run.last_error) {
                            detail += '。' + escapeHtml(run.last_error);
                        }

                        if (statusType === 'error') {
                            showAutopilotRunResult(statusType, detail);
                            return;
                        }

                        showAutopilotRunResult(statusType, detail + '，1.2 秒后自动刷新日志。');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 1200);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message
                            : '';
                        if (!msg && xhr && xhr.status) {
                            msg = '网络请求失败（HTTP ' + xhr.status + '）';
                        }
                        if (!msg) {
                            msg = '网络请求失败';
                        }
                        showAutopilotRunResult('error', '执行失败：' + escapeHtml(msg));
                    },
                    complete: function () {
                        $button.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        }

        // --- Cleanup logs ---
        var $cleanupBtn = $('#yimai-gso-cleanup-logs-btn');
        var $cleanupResult = $('#yimai-gso-cleanup-result');

        if ($cleanupBtn.length && $cleanupResult.length) {
            $cleanupBtn.off('click.yimaiGsoCleanup').on('click.yimaiGsoCleanup', function () {
                if ($cleanupBtn.prop('disabled')) {
                    return;
                }

                var originalHtml = $cleanupBtn.html();
                $cleanupBtn.prop('disabled', true).html('<span class="yimai-gso-loading"></span> 清理中...');
                $cleanupResult.removeClass('info success error').hide();

                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_autopilot_cleanup_logs',
                        nonce: yimaiGsoData.nonce
                    },
                    success: function (res) {
                        if (!res.success) {
                            $cleanupResult.addClass('apv3-run-result error').html('清理失败：' + escapeHtml((res.data && res.data.message) || '未知错误')).show();
                            return;
                        }
                        $cleanupResult.addClass('apv3-run-result success').html('<strong>✓ ' + escapeHtml(res.data.message) + '</strong>').show();
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 2000);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message
                            : '网络请求失败';
                        $cleanupResult.addClass('apv3-run-result error').html('清理失败：' + escapeHtml(msg)).show();
                    },
                    complete: function () {
                        $cleanupBtn.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        }
    }

    // ==================== 批量生成 ====================



    var batchState = {
        isRunning: false,
        isStopped: false,
        hasFinished: false,
        stopReason: '',
        finishMessage: '',
        currentIndex: 0,        // 下一个待分配的提示词索引
        completedCount: 0,      // 已完成（成功+失败）的总数
        runningCount: 0,        // 当前正在并发处理的数量
        concurrency: 2,         // 同时最多处理2篇
        total: 0,
        prompts: [],
        activeRequests: {},
        results: [],    // [{status:'success'|'error', title, postUrl, editUrl, message}]
        retryCount: {}, // 记录每个索引的重试次数
        maxRetries: 2,  // 最大重试次数
        startedAt: 0    // v2.9.68: 记录整体开始时间，用于长时任务提示
    };

    /**
     * v2.9.68: 把 jQuery AJAX error 回调的错误信息细化为具体原因
     * 之前统一显示"网络请求失败（已重试2次）"，用户无法分辨是超时/500/网络断开/解析错误
     * 现在按 xhr.status 和 textStatus 给出针对性提示，方便排查
     */
    function formatAjaxErrorMessage(xhr, textStatus, errorThrown) {
        var status = (xhr && typeof xhr.status === 'number') ? xhr.status : 0;
        var serverTip = '';
        if (status === 0 || textStatus === 'timeout' || textStatus === 'abort') {
            serverTip = '（请求在 15 分钟内未完成，常见原因是 AI 接口、配图接口或服务器网关超时；请先关闭自动配图重试）';
        } else if (status >= 500) {
            serverTip = '（服务端 ' + status + ' 错误，请查看 PHP 错误日志）';
        } else if (status === 403) {
            serverTip = '（权限不足或会话失效）';
        } else if (status === 404) {
            serverTip = '（AJAX 端点未注册，请重新保存插件设置）';
        } else if (status >= 400) {
            serverTip = '（客户端 ' + status + ' 错误）';
        } else if (textStatus === 'parsererror') {
            serverTip = '（服务端返回非 JSON 数据，可能是 PHP 致命错误；请查看浏览器控制台和 PHP 错误日志）';
        } else {
            serverTip = '（错误类型：' + (textStatus || 'unknown') + '）';
        }
        var extra = (errorThrown && typeof errorThrown === 'string') ? ' ' + errorThrown : '';
        return '网络请求失败' + serverTip + extra;
    }

    var batchScheduleStorageKey = 'yimai_gso_batch_schedule_settings';

    function formatBatchLocalDate(date) {
        return date.getFullYear() + '-'
            + String(date.getMonth() + 1).padStart(2, '0') + '-'
            + String(date.getDate()).padStart(2, '0') + 'T'
            + String(date.getHours()).padStart(2, '0') + ':'
            + String(date.getMinutes()).padStart(2, '0');
    }

    function getDefaultBatchScheduleStart() {
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        return formatBatchLocalDate(tomorrow);
    }

    function validateBatchScheduleConfig() {
        var status = normalizeBatchStatus($('input[name="yimai-gso-batch-status"]:checked').val() || 'draft');
        if (status !== 'future') {
            return { valid: true };
        }

        // 使用参数级权限检查
        var allowedStatuses = getParamAllowedValues('batch_generate', 'post_status');
        if (allowedStatuses.length > 0) {
            if (allowedStatuses.indexOf('future') === -1) {
                return {
                    valid: false,
                    message: '当前批量发布配置未开放定时发布，请改用草稿或直接发布。'
                };
            }
        } else if (!canUseBatchAdvancedPublish()) {
            // 回退到旧逻辑
            return {
                valid: false,
                message: '当前账号没有发布文章权限，无法使用定时发布。'
            };
        }


        var startTime = $.trim($('#yimai-gso-batch-schedule-start').val() || '');
        if (!startTime) {
            return {
                valid: false,
                message: '请先在批量文章配置里设置定时发布的起始时间。'
            };
        }

        var m = startTime.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
        if (!m) {
            return {
                valid: false,
                message: '起始发布时间格式不正确，请重新选择。'
            };
        }

        var localDate = new Date(
            parseInt(m[1], 10),
            parseInt(m[2], 10) - 1,
            parseInt(m[3], 10),
            parseInt(m[4], 10),
            parseInt(m[5], 10),
            0
        );

        if (isNaN(localDate.getTime())) {
            return {
                valid: false,
                message: '起始发布时间无法解析，请重新选择。'
            };
        }

        if (localDate.getTime() <= Date.now()) {
            return {
                valid: false,
                message: '起始发布时间必须晚于当前时间，请重新设置。'
            };
        }

        return { valid: true };
    }

    function stopBatchForFatalError(message, sourceIndex) {
        if (batchState.stopReason === 'fatal_config') {
            return;
        }

        batchState.isStopped = true;
        batchState.stopReason = 'fatal_config';
        batchState.finishMessage = '🛑 ' + message;
        batchState.currentIndex = batchState.total;

        updateBatchProgress(
            (batchState.completedCount / Math.max(batchState.total, 1)) * 100,
            '🛑 检测到发布配置错误，正在停止剩余任务...'
        );

        $.each(batchState.activeRequests, function (key, xhr) {
            var currentKey = parseInt(key, 10);
            if (currentKey === sourceIndex) {
                return;
            }
            if (xhr && xhr.readyState !== 4) {
                xhr.abort();
            }
        });
    }

    function maybeFinishBatch() {
        if (batchState.hasFinished || !batchState.isRunning) {
            return true;
        }

        if (batchState.isStopped && batchState.runningCount <= 0) {
            finishBatch(batchState.finishMessage || '⏹️ 已停止批量生成');
            return true;
        }

        if (!batchState.isStopped && batchState.currentIndex >= batchState.total && batchState.runningCount <= 0) {
            finishBatch('✅ 全部 ' + batchState.total + ' 篇文章处理完成！');
            return true;
        }

        return false;
    }

    function toggleBatchScheduleFields(animate) {
        var $group = $('#yimai-gso-batch-schedule-group');
        var $intervalGroup = $('#yimai-gso-batch-schedule-interval-group');
        var $start = $('#yimai-gso-batch-schedule-start');
        if (!$group.length || !$intervalGroup.length) {
            return;
        }

        var status = $('input[name="yimai-gso-batch-status"]:checked').val() || 'draft';
        var show = status === 'future';

        if (show && !$start.val()) {
            $start.val(getDefaultBatchScheduleStart());
        }

        if (animate) {
            if (show) {
                $group.stop(true, true).slideDown(200);
                $intervalGroup.stop(true, true).slideDown(200);
            } else {
                $group.stop(true, true).slideUp(200);
                $intervalGroup.stop(true, true).slideUp(200);
            }
        } else {
            $group.toggle(show);
            $intervalGroup.toggle(show);
        }
    }

    function saveBatchScheduleSettings(showFeedback) {
        if (!$('input[name="yimai-gso-batch-status"]').length) {
            return null;
        }

        toggleBatchScheduleFields(false);

        var settings = {
            status: $('input[name="yimai-gso-batch-status"]:checked').val() || 'draft',
            start: $('#yimai-gso-batch-schedule-start').val() || '',
            interval: $('#yimai-gso-batch-schedule-interval').val() || '3600'
        };

        try {
            localStorage.setItem(batchScheduleStorageKey, JSON.stringify(settings));
        } catch (e) {
            // ignore storage failure
        }

        if (showFeedback && $('#yimai-gso-schedule-save-result').length) {
            $('#yimai-gso-schedule-save-result')
                .html('<div class="notice notice-success inline"><p>当前发布策略已保存，批量文章配置区会自动读取这组配置。</p></div>')
                .show();
        }

        return settings;
    }

    function loadBatchScheduleSettings() {
        if (!$('input[name="yimai-gso-batch-status"]').length) {
            return;
        }

        var settings = null;
        try {
            settings = JSON.parse(localStorage.getItem(batchScheduleStorageKey) || 'null');
        } catch (e) {
            settings = null;
        }

        if (settings && settings.status) {
            settings.status = normalizeBatchStatus(settings.status);
            $('input[name="yimai-gso-batch-status"][value="' + settings.status + '"]').prop('checked', true);
        }

        if (settings && settings.start) {
            $('#yimai-gso-batch-schedule-start').val(settings.start);
        }
        if (settings && settings.interval) {
            $('#yimai-gso-batch-schedule-interval').val(String(settings.interval));
        }

        toggleBatchScheduleFields(false);
    }

    function initBatchPage() {
        // 应用批量发布功能状态（禁用直接发布/定时发布）
        applyBatchPublishFeatureState();
        applyBatchAiTagsFeatureState();

        $('input[name="yimai-gso-batch-status"]').off('change.yimaiGsoSchedule').on('change.yimaiGsoSchedule', function () {
            toggleBatchScheduleFields(true);
            saveBatchScheduleSettings(false);
        });

        $('#yimai-gso-batch-schedule-start, #yimai-gso-batch-schedule-interval')
            .off('change.yimaiGsoScheduleSave input.yimaiGsoScheduleSave')
            .on('change.yimaiGsoScheduleSave input.yimaiGsoScheduleSave', function () {
                saveBatchScheduleSettings(false);
            });

        loadBatchScheduleSettings();
        
        // 从sessionStorage恢复标题到文本域
        restoreBatchPromptsFromStorage();
        
        // 自动保存提示词到sessionStorage（用户编辑时实时保存）
        $('#yimai-gso-batch-prompts').off('input.yimaiGsoBatchSave').on('input.yimaiGsoBatchSave', function() {
            sessionStorage.setItem('yimai_gso_batch_prompts', $(this).val());
        });

        // 开始批量生成

        $('#yimai-gso-batch-start-btn').off('click.yimaiGsoBatchStart').on('click.yimaiGsoBatchStart', function (e) {
            e.preventDefault();
            if (batchState.isRunning) return;

            saveBatchScheduleSettings(false);

            var batchGate = getFeatureGatePayload();
            if (!canUseBatchDraft(batchGate)) {
                alert(batchGate.canEditPosts === false
                    ? '当前账号没有编辑文章权限，无法使用批量生成。'
                    : '当前站点未启用批量生成功能，请检查插件设置。');
                return;
            }

            var promptsText = $.trim($('#yimai-gso-batch-prompts').val());

            if (!promptsText) {
                alert('请至少输入一个提示词！');
                $('#yimai-gso-batch-prompts').focus();
                switchStep(2);
                return;
            }

            // 解析提示词（每行一个，忽略空行）
            batchState.prompts = promptsText.split('\n')
                .map(function (line) { return $.trim(line); })
                .filter(function (line) { return line.length > 0; });

            if (batchState.prompts.length === 0) {
                alert('请至少输入一个提示词！');
                switchStep(2);
                return;
            }

            var scheduleValidation = validateBatchScheduleConfig();
            if (!scheduleValidation.valid) {
                alert(scheduleValidation.message || '当前发布配置有误，请先修正后再开始。');
                return;
            }

            batchState.total = batchState.prompts.length;
            batchState.currentIndex = 0;
            batchState.completedCount = 0;
            batchState.runningCount = 0;
            batchState.isRunning = true;
            batchState.isStopped = false;
            batchState.hasFinished = false;
            batchState.stopReason = '';
            batchState.finishMessage = '';
            batchState.activeRequests = {};
            batchState.results = [];


            switchStep(2);

            // UI切换
            $('#yimai-gso-batch-start-btn').hide();
            $('#yimai-gso-batch-stop-btn').show();
            $('#yimai-gso-batch-progress').show();
            $('#yimai-gso-batch-counter').show().text('0/' + batchState.total);
            $('#yimai-gso-batch-results').empty();
            updateBatchProgress(0, '🚀 批量生成开始，共 ' + batchState.total + ' 篇文章，系统将自动执行生成、配图标签与发布流程...');


            // 启动并发处理：一次性发起 concurrency 个请求
            for (var i = 0; i < batchState.concurrency && i < batchState.total; i++) {
                processNextBatchItem();
            }
        });

        // 停止批量生成
        $('#yimai-gso-batch-stop-btn').off('click.yimaiGsoBatchStop').on('click.yimaiGsoBatchStop', function (e) {
            e.preventDefault();
            if (batchState.isRunning) {
                batchState.isStopped = true;
                batchState.stopReason = 'manual';
                batchState.finishMessage = '⏹️ 已停止批量生成';
                $(this).prop('disabled', true).text('正在停止...');
                maybeFinishBatch();
            }
        });
    }

    /**
     * 重试指定索引的批量项（不递增 currentIndex / runningCount）
     * 用于网络错误后的重试，与 processNextBatchItem 共享 AJAX 逻辑
     */
    function retryBatchItem(itemIndex) {
        if (!batchState.isRunning || batchState.hasFinished) {
            return;
        }

        var prompt = batchState.prompts[itemIndex] || '';
        var index  = itemIndex + 1;

        // 动态进度文本
        updateBatchProgress(
            (batchState.completedCount / Math.max(batchState.total, 1)) * 100,
            '🔄 [' + batchState.completedCount + '/' + batchState.total + ' 完成] 重试生成：' + escapeHtml(prompt.substring(0, 30)) + (prompt.length > 30 ? '...' : '')
        );

        // 准备参数（与 processNextBatchItem 一致）
        var status = normalizeBatchStatus($('input[name="yimai-gso-batch-status"]:checked').val() || 'draft');
        $('input[name="yimai-gso-batch-status"][value="' + status + '"]').prop('checked', true);
        var scheduledAt = '';

        if (status === 'future') {
            var startTime = $('#yimai-gso-batch-schedule-start').val();
            var interval  = parseInt($('#yimai-gso-batch-schedule-interval').val(), 10) || 3600;
            if (startTime) {
                var m2 = startTime.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
                if (!m2) {
                    scheduledAt = '';
                } else {
                    var baseTs = new Date(
                        parseInt(m2[1], 10), parseInt(m2[2], 10) - 1, parseInt(m2[3], 10),
                        parseInt(m2[4], 10), parseInt(m2[5], 10), 0
                    ).getTime();
                    var targetTs = baseTs + (itemIndex * interval * 1000);
                    var targetDate = new Date(targetTs);
                    var y = targetDate.getFullYear();
                    var mo = String(targetDate.getMonth() + 1).padStart(2, '0');
                    var d = String(targetDate.getDate()).padStart(2, '0');
                    var h = String(targetDate.getHours()).padStart(2, '0');
                    var min = String(targetDate.getMinutes()).padStart(2, '0');
                    var sec = String(targetDate.getSeconds()).padStart(2, '0');
                    scheduledAt = y + '-' + mo + '-' + d + ' ' + h + ':' + min + ':' + sec;
                }
            }
        }

        var concurrencyIndex = itemIndex % batchState.concurrency;

        var request = $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action:                   'yimai_gso_batch_generate_and_publish',
                nonce:                    yimaiGsoData.nonce,
                prompt:                   prompt,
                word_count:               $('#yimai-gso-batch-word-count').val(),
                image_count:              $('#yimai-gso-batch-image-count').val(),
                category_id:              $('#yimai-gso-batch-category').val(),
                tags:                     $('#yimai-gso-batch-tags').val(),
                use_ai_tags:              $('#yimai-gso-batch-ai-tags').is(':checked') ? '1' : '0',
                status:                   status,
                scheduled_at:             scheduledAt,
                concurrency_index:        concurrencyIndex,
                batch_index:              itemIndex,
            },
            // v2.9.68: timeout 从 5 分钟延长到 10 分钟
            // 单篇处理理论耗时：AI 120s + 配图 60s + 下载 60s + 发布 10s = 4 分钟
            // 并发 2 篇同时跑时单边可能拖到 8-9 分钟，5 分钟容易触发前端 timeout 误判为"网络失败"
            timeout: 600000,
            success: function (res) {
                try {
                    if (res.success) {
                        batchState.retryCount[itemIndex] = 0;
                        batchState.results.push({
                            status:     'success',
                            title:      res.data.title,
                            postUrl:    res.data.post_url,
                            editUrl:    res.data.edit_url,
                            statusText: res.data.status,
                        });
                        safeAppendBatchResult(index, 'success', prompt, res.data);
                    } else if (res.data && res.data.fatal_batch) {
                        var fatalMessage = (res.data && res.data.message) || '当前发布配置有误';
                        batchState.results.push({
                            status:  'error',
                            title:   prompt.substring(0, 30),
                            message: fatalMessage,
                        });
                        safeAppendBatchResult(index, 'error', prompt, { message: fatalMessage + '，已停止整批任务' });
                        stopBatchForFatalError(fatalMessage, itemIndex);
                    } else if (res.data && res.data.code === 'yimai_gso_feature_locked') {
                        batchState.results.push({
                            status:  'error',
                            title:   prompt.substring(0, 30),
                            message: '当前配置未开通批量生成',
                        });
                        safeAppendBatchResult(index, 'error', prompt, { message: res.data.message || '当前配置未开通批量生成' });
                        batchState.isStopped = true;
                        batchState.stopReason = 'locked';
                        batchState.finishMessage = '⛔ 当前配置未开通批量生成，已停止剩余任务';
                        batchState.currentIndex = batchState.total;
                    } else {
                        batchState.retryCount[itemIndex] = 0;
                        batchState.results.push({
                            status:  'error',
                            title:   prompt.substring(0, 30),
                            message: (res.data && res.data.message) || '未知错误',
                        });
                        safeAppendBatchResult(index, 'error', prompt, { message: (res.data && res.data.message) || '未知错误' });
                    }
                } catch (err) {
                    var callbackMessage = '前端处理批量结果时发生异常：' + ((err && err.message) ? err.message : '未知错误');
                    console.error('[YIMAI GSO] 批量生成 success 回调异常:', err, res);
                    batchState.results.push({
                        status:  'error',
                        title:   prompt.substring(0, 30),
                        message: callbackMessage,
                    });
                    safeAppendBatchResult(index, 'error', prompt, { message: callbackMessage });
                }
            },
            error: function (xhr, textStatus, errorThrown) {
                if (textStatus === 'abort') {
                    return;
                }

                batchState.retryCount[itemIndex]++;
                var retryAttempt = batchState.retryCount[itemIndex];
                var delayBase     = 3000;
                var delay         = delayBase * Math.pow(1.5, retryAttempt - 1);

                if (retryAttempt <= batchState.maxRetries) {
                    console.log('[YIMAI GSO] 重试请求失败(#' + index + ')，' + delay + 'ms 后重试 (' + retryAttempt + '/' + batchState.maxRetries + ')', prompt.substring(0, 30));
                    setTimeout(function() {
                        retryBatchItem(itemIndex);
                    }, delay);
                    return;
                }

                // v2.9.68: 细化为具体错误原因（区分 timeout/500/网络断开/解析错误）
                var finalMsg = formatAjaxErrorMessage(xhr, textStatus, errorThrown);
                batchState.results.push({
                    status:  'error',
                    title:   prompt.substring(0, 30),
                    message: finalMsg,
                });
                safeAppendBatchResult(index, 'error', prompt, { message: finalMsg });
            },

            complete: function () {
                var rc = batchState.retryCount[itemIndex];
                if (rc === 0 || rc > batchState.maxRetries) {
                    finalizeBatchRequest(itemIndex);
                }
            }
        });

        batchState.activeRequests[itemIndex] = request;
    }

    function processNextBatchItem() {
        if (!batchState.isRunning || batchState.hasFinished) {
            return;
        }

        if (batchState.isStopped || batchState.currentIndex >= batchState.total) {
            maybeFinishBatch();
            return;
        }

        var itemIndex = batchState.currentIndex; // 记录当前项的索引（并发安全）
        batchState.currentIndex++;
        batchState.runningCount++;

        var prompt = batchState.prompts[itemIndex] || '';
        var index  = itemIndex + 1; // 1-based

        // 动态进度文本（显示并发中正在处理的项）
        updateBatchProgress(
            (batchState.completedCount / Math.max(batchState.total, 1)) * 100,
            '🤖 [' + batchState.completedCount + '/' + batchState.total + ' 完成] 正在生成并发布：' + escapeHtml(prompt.substring(0, 30)) + (prompt.length > 30 ? '...' : '')
        );

        // 准备参数
        var status = normalizeBatchStatus($('input[name="yimai-gso-batch-status"]:checked').val() || 'draft');
        $('input[name="yimai-gso-batch-status"][value="' + status + '"]').prop('checked', true);
        var scheduledAt = '';


        // 定时发布：计算每篇文章的发布时间
        if (status === 'future') {
            var startTime = $('#yimai-gso-batch-schedule-start').val();
            var interval  = parseInt($('#yimai-gso-batch-schedule-interval').val(), 10) || 3600;
            if (startTime) {
                // 修复：用正则直接提取年月日时分，避免依赖 replace/split 顺序导致的解析错误
                // datetime-local 格式为 YYYY-MM-DDTHH:MM 或 YYYY-MM-DD HH:MM
                var m2 = startTime.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
                if (!m2) {
                    scheduledAt = '';
                } else {
                    var baseTs = new Date(
                        parseInt(m2[1], 10),
                        parseInt(m2[2], 10) - 1,
                        parseInt(m2[3], 10),
                        parseInt(m2[4], 10),
                        parseInt(m2[5], 10),
                        0
                    ).getTime();
                    var targetTs = baseTs + (itemIndex * interval * 1000);
                    var targetDate = new Date(targetTs);
                    var y = targetDate.getFullYear();
                    var mo = String(targetDate.getMonth() + 1).padStart(2, '0');
                    var d = String(targetDate.getDate()).padStart(2, '0');
                    var h = String(targetDate.getHours()).padStart(2, '0');
                    var min = String(targetDate.getMinutes()).padStart(2, '0');
                    var sec = String(targetDate.getSeconds()).padStart(2, '0');
                    scheduledAt = y + '-' + mo + '-' + d + ' ' + h + ':' + min + ':' + sec;
                }
            }
        }

        // 计算并发索引（0 或 1）
        var concurrencyIndex = itemIndex % batchState.concurrency;

        // 初始化重试计数
        if (!batchState.retryCount[itemIndex]) {
            batchState.retryCount[itemIndex] = 0;
        }

        var request = $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action:                   'yimai_gso_batch_generate_and_publish',
                nonce:                    yimaiGsoData.nonce,
                prompt:                   prompt,
                word_count:               $('#yimai-gso-batch-word-count').val(),
                image_count:              $('#yimai-gso-batch-image-count').val(),
                category_id:              $('#yimai-gso-batch-category').val(),
                tags:                     $('#yimai-gso-batch-tags').val(),
                use_ai_tags:              $('#yimai-gso-batch-ai-tags').is(':checked') ? '1' : '0',
                status:                   status,
                scheduled_at:             scheduledAt,
                concurrency_index:        concurrencyIndex, // 用于后端生成唯一锁标识
                batch_index:              itemIndex,
            },
            timeout: 900000, // 2.2.0：批量单篇最长等待15分钟
            success: function (res) {
                try {
                    if (res.success) {
                        // 成功时重置重试计数
                        batchState.retryCount[itemIndex] = 0;
                        batchState.results.push({
                            status:     'success',
                            title:      res.data.title,
                            postUrl:    res.data.post_url,
                            editUrl:    res.data.edit_url,
                            statusText: res.data.status,
                        });
                        safeAppendBatchResult(index, 'success', prompt, res.data);
                    } else if (res.data && res.data.fatal_batch) {
                        var fatalMessage = (res.data && res.data.message) || '当前发布配置有误';
                        batchState.results.push({
                            status:  'error',
                            title:   prompt.substring(0, 30),
                            message: fatalMessage,
                        });
                        safeAppendBatchResult(index, 'error', prompt, { message: fatalMessage + '，已停止整批任务' });
                        stopBatchForFatalError(fatalMessage, itemIndex);
                    } else if (res.data && res.data.code === 'yimai_gso_feature_locked') {
                        batchState.results.push({
                            status:  'error',
                            title:   prompt.substring(0, 30),
                            message: '当前配置未开通批量生成',
                        });
                        safeAppendBatchResult(index, 'error', prompt, { message: res.data.message || '当前配置未开通批量生成' });
                        batchState.isStopped = true;
                        batchState.stopReason = 'locked';
                        batchState.finishMessage = '⛔ 当前配置未开通批量生成，已停止剩余任务';
                        batchState.currentIndex = batchState.total;
                    } else {
                        // 业务错误，不重试
                        batchState.retryCount[itemIndex] = 0;
                        batchState.results.push({
                            status:  'error',
                            title:   prompt.substring(0, 30),
                            message: (res.data && res.data.message) || '未知错误',
                        });
                        safeAppendBatchResult(index, 'error', prompt, { message: (res.data && res.data.message) || '未知错误' });
                    }
                } catch (err) {
                    var callbackMessage = '前端处理批量结果时发生异常：' + ((err && err.message) ? err.message : '未知错误');
                    console.error('[YIMAI GSO] 批量生成 success 回调异常:', err, res);
                    batchState.results.push({
                        status:  'error',
                        title:   prompt.substring(0, 30),
                        message: callbackMessage,
                    });
                    safeAppendBatchResult(index, 'error', prompt, { message: callbackMessage });
                }
            },
            error: function (xhr, textStatus, errorThrown) {
                if (textStatus === 'abort') {
                    return;
                }

                batchState.retryCount[itemIndex]++;
                var retryAttempt = batchState.retryCount[itemIndex];
                var delayBase     = 3000;
                var delay         = delayBase * Math.pow(1.5, retryAttempt - 1); // 3s → 4.5s → 6.75s

                if (retryAttempt <= batchState.maxRetries) {
                    console.log('[YIMAI GSO] 请求失败(#' + index + ')，' + delay + 'ms 后重试 (' + retryAttempt + '/' + batchState.maxRetries + ')', prompt.substring(0, 30));
                    // 注意：不要调用 processNextBatchItem()，因为它会递增 runningCount 和 currentIndex
                    // 直接复用同一个 itemIndex 重新发起 AJAX 请求，保持 runningCount 不变
                    setTimeout(function() {
                        retryBatchItem(itemIndex);
                    }, delay);
                    return;
                }

                // v2.9.68: 细化为具体错误原因（区分 timeout/500/网络断开/解析错误）
                var finalMsg = formatAjaxErrorMessage(xhr, textStatus, errorThrown);
                batchState.results.push({
                    status:  'error',
                    title:   prompt.substring(0, 30),
                    message: finalMsg,
                });
                safeAppendBatchResult(index, 'error', prompt, { message: finalMsg });
            },

            complete: function () {
                var rc = batchState.retryCount[itemIndex];
                // rc === 0: 首次请求成功，不走重试路径，调用 finalize
                // rc > maxRetries: 重试已耗尽（已在 error 中标记失败），调用 finalize 收口
                // rc <= maxRetries && rc > 0: 正在重试中，由下一次 complete 回调处理
                if (rc === 0 || rc > batchState.maxRetries) {
                    finalizeBatchRequest(itemIndex);
                }
            }
        });

        batchState.activeRequests[itemIndex] = request;
    }

    function appendBatchResult(index, status, prompt, data) {
        var statusMap = {
            publish: '🚀 已发布',
            draft:   '💾 草稿',
            future:  '⏰ 定时发布',
        };

        var html;
        if (status === 'success') {
            var statusText = statusMap[data.status] || data.status;
            html = '<div class="yimai-gso-batch-item success">'
                 + '<div class="yimai-gso-batch-item-header">'
                 + '<span class="yimai-gso-batch-item-num">#' + index + '</span>'
                 + '<span class="yimai-gso-batch-item-status">' + statusText + '</span>'
                 + '</div>'
                 + '<div class="yimai-gso-batch-item-title">' + escapeHtml(data.title) + '</div>'
                 + '<div class="yimai-gso-batch-item-links">'
                 + '<a href="' + data.post_url + '" target="_blank">查看文章</a> | '
                 + '<a href="' + data.edit_url + '" target="_blank">编辑</a>'
                 + '</div>'
                 + '</div>';
        } else {
            html = '<div class="yimai-gso-batch-item error">'
                 + '<div class="yimai-gso-batch-item-header">'
                 + '<span class="yimai-gso-batch-item-num">#' + index + '</span>'
                 + '<span class="yimai-gso-batch-item-status">❌ 失败</span>'
                 + '</div>'
                 + '<div class="yimai-gso-batch-item-title">' + escapeHtml(prompt.substring(0, 50)) + '</div>'
                 + '<div class="yimai-gso-batch-item-error">' + escapeHtml(data.message || '未知错误') + '</div>'
                 + '</div>';
        }

        $('#yimai-gso-batch-results').append(html);
    }

    function safeAppendBatchResult(index, status, prompt, data) {
        try {
            appendBatchResult(index, status, prompt, data || {});
        } catch (err) {
            console.error('[YIMAI GSO] 追加批量结果失败:', err, {
                index: index,
                status: status,
                prompt: prompt,
                data: data
            });
        }
    }



    function finalizeBatchRequest(itemIndex) {
        delete batchState.activeRequests[itemIndex];
        batchState.completedCount++;
        batchState.runningCount = Math.max(0, batchState.runningCount - 1);

        try {
            $('#yimai-gso-batch-counter').text(batchState.completedCount + '/' + batchState.total);

            updateBatchProgress(
                (batchState.completedCount / Math.max(batchState.total, 1)) * 100,
                '🤖 [' + batchState.completedCount + '/' + batchState.total + ' 完成]'
            );
        } catch (err) {
            console.error('[YIMAI GSO] 批量生成收口更新 UI 失败:', err);
        }

        if (!batchState.isStopped) {
            processNextBatchItem();
        }
        maybeFinishBatch();
    }

    function updateBatchProgress(percent, text) {

        $('.yimai-gso-batch-fill').css('width', Math.max(2, Math.min(percent, 100)) + '%');
        $('#yimai-gso-batch-progress-text').text(text);
    }

    function finishBatch(message) {
        if (batchState.hasFinished) {
            return;
        }

        batchState.hasFinished = true;
        batchState.isRunning = false;
        updateBatchProgress(100, message);


        // 统计结果
        var successCount = batchState.results.filter(function (r) { return r.status === 'success'; }).length;
        var errorCount   = batchState.results.filter(function (r) { return r.status === 'error'; }).length;

        var summaryHtml = '<div class="yimai-gso-batch-summary">'
                        + '<span class="yimai-gso-badge green">✅ 成功 ' + successCount + ' 篇</span>';
        if (errorCount > 0) {
            summaryHtml += ' <span class="yimai-gso-badge" style="background:#fee2e2;color:#991b1b;">❌ 失败 ' + errorCount + ' 篇</span>';
        }
        summaryHtml += '</div>';


        $('#yimai-gso-batch-results').prepend(summaryHtml);

        // 恢复按钮
        $('#yimai-gso-batch-stop-btn').hide().prop('disabled', false).html(
            '<span class="dashicons dashicons-sticky"></span> 停止批量生成'
        );
        $('#yimai-gso-batch-start-btn').show().html(
            '<span class="dashicons dashicons-controls-forward"></span> 再次批量生成'
        );
    }


    var titlesState = {
        titles: [],
        isGenerating: false,
    };

    function initTitlesPage() {
        // 页面加载时获取历史记录
        loadTitleHistory();

        // 角度全选 - 使用事件委托（仅独立页面使用）
        $(document).on('click', '#yimai-gso-angles-select-all', function () {
            $('#yimai-gso-titles-keyword').closest('.yimai-gso-panel, .yimai-gso-main-panel').find('input[name="yimai_gso_angles"]').prop('checked', true);
        });

        // 角度清空 - 使用事件委托（仅独立页面使用）
        $(document).on('click', '#yimai-gso-angles-clear-all', function () {
            $('#yimai-gso-titles-keyword').closest('.yimai-gso-panel, .yimai-gso-main-panel').find('input[name="yimai_gso_angles"]').prop('checked', false);
        });

        // 生成标题按钮 - 使用事件委托
        $(document).on('click', '#yimai-gso-titles-generate-btn', function () {
            if (titlesState.isGenerating) return;

            var keyword = $.trim($('#yimai-gso-titles-keyword').val());
            if (!keyword) {
                alert('请输入行业关键词或主题！');
                $('#yimai-gso-titles-keyword').focus();
                return;
            }

            var count = parseInt($('#yimai-gso-titles-count').val()) || 20;

            titlesState.isGenerating = true;
            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="yimai-gso-loading"></span> AI正在生成标题...');

            $('#yimai-gso-titles-progress').show();
            $('#yimai-gso-titles-progress-text').text('🤖 AI 正在分析行业热点，生成爆款标题...');
            // 只清空标题列表
            $('#yimai-gso-step1-titles-results .yimai-gso-title-item').remove();
            $('#yimai-gso-step1-titles-results .yimai-gso-empty').hide();

            $.ajax({
                url: yimaiGsoData.ajaxUrl,
                type: 'POST',
                data: {
                    action:  'yimai_gso_generate_titles',
                    nonce:   yimaiGsoData.nonce,
                    keyword: keyword,
                    count:   count,
                },
                success: function (res) {
                    titlesState.isGenerating = false;
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-spell-check"></span> 生成热门标题'
                    );

                    if (!res.success) {
                        $('#yimai-gso-titles-progress-text').text('❌ ' + (res.data.message || '生成失败'));
                        return;
                    }

                    titlesState.titles = res.data.titles || [];
                    if (titlesState.titles.length === 0) {
                        $('#yimai-gso-titles-progress-text').text('⚠️ 未生成有效标题，请换个关键词试试');
                        return;
                    }

                    renderTitlesList(titlesState.titles);
                    $('#yimai-gso-titles-progress').hide();
                    // 刷新历史记录
                    loadTitleHistory();
                },
                error: function () {
                    titlesState.isGenerating = false;
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-lightbulb"></span> 一键生成热门标题'
                    );
                    $('#yimai-gso-titles-progress-text').text('❌ 网络请求失败，请重试');
                }
            });
        });

        // 全选 / 取消全选 - 步骤1标题生成结果
        $(document).on('click', '#yimai-gso-step1-titles-select-all', function () {
            $('#yimai-gso-step1-titles-results .yimai-gso-title-item').each(function () {
                $(this).addClass('selected');
                $(this).find('.yimai-gso-title-checkbox').prop('checked', true);
            });
            updateTitlesCounter();
        });

        $(document).on('click', '#yimai-gso-step1-titles-deselect-all', function () {
            $('#yimai-gso-step1-titles-results .yimai-gso-title-item').each(function () {
                $(this).removeClass('selected');
                $(this).find('.yimai-gso-title-checkbox').prop('checked', false);
            });
            updateTitlesCounter();
        });

        // 选中的标题直接进入批量文章步骤
        $(document).on('click', '#yimai-gso-step1-go-batch, #yimai-gso-results-to-batch', function () {
            syncSelectedTitlesToBatch(2);
        });

        // ==================== 步骤1标题生成结果的内联操作按钮 ====================
        // 全选
        $(document).on('click', '#yimai-gso-results-select-all', function () {
            $('#yimai-gso-step1-titles-results .yimai-gso-title-item').each(function () {
                $(this).addClass('selected');
                $(this).find('.yimai-gso-title-checkbox').prop('checked', true);
            });
            updateTitlesCounter();
        });

        // 清空选中
        $(document).on('click', '#yimai-gso-results-clear-all', function () {
            $('#yimai-gso-step1-titles-results .yimai-gso-title-item').each(function () {
                $(this).removeClass('selected');
                $(this).find('.yimai-gso-title-checkbox').prop('checked', false);
            });
            updateTitlesCounter();
        });

        // 双击清空全部并恢复空状态
        $(document).on('dblclick', '#yimai-gso-results-clear-all', function () {
            var $container = $('#yimai-gso-step1-titles-results');
            $container.find('.yimai-gso-title-item').remove();
            $container.find('.yimai-gso-empty').show();
            // 清空后隐藏计数器，重置选中数
            $('#yimai-gso-step1-titles-counter').hide();
            $('#yimai-gso-step1-titles-selected-count').text('0');
            updateTitlesCounter();
        });
    }


    function renderTitlesList(titles) {
        var $container = $('#yimai-gso-step1-titles-results');
        var $counter = $('#yimai-gso-step1-titles-counter');

        // 清空标题列表，保留空状态元素
        $container.find('.yimai-gso-title-item').remove();
        $container.find('.yimai-gso-empty').hide();

        // 显示计数器和按钮（方案C：固定显示在标题栏）
        $counter.show();
        updateTitlesCounter();

        $.each(titles, function (i, title) {
            var $item = $('<div class="yimai-gso-title-item">')
                .attr('data-index', i);

            var $checkbox = $('<input type="checkbox" class="yimai-gso-title-checkbox">').attr('data-index', i);
            var $num = $('<span class="yimai-gso-title-num">').text((i + 1));
            var $text = $('<span class="yimai-gso-title-text">').text(title);
            var $copyBtn = $('<button class="button button-small yimai-gso-title-copy-btn">').text('复制');

            $item.append($checkbox).append($num).append($text).append($copyBtn);

            // 点击行切换选中
            $item.on('click', function (e) {
                // 排除复制按钮和checkbox自身的点击
                if ($(e.target).hasClass('yimai-gso-title-copy-btn') || $(e.target).hasClass('yimai-gso-title-checkbox')) return;
                $item.toggleClass('selected');
                $checkbox.prop('checked', $item.hasClass('selected'));
                updateTitlesCounter();
            });

            // checkbox 点击
            $checkbox.on('change', function () {
                $item.toggleClass('selected', $(this).is(':checked'));
                updateTitlesCounter();
            });

            // 复制按钮
            $copyBtn.on('click', function (e) {
                e.stopPropagation();
                var text = $text.text();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () {
                        $copyBtn.text('已复制').prop('disabled', true);
                        setTimeout(function () { $copyBtn.text('复制').prop('disabled', false); }, 1500);
                    });
                } else {
                    // fallback
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    $copyBtn.text('已复制').prop('disabled', true);
                    setTimeout(function () { $copyBtn.text('复制').prop('disabled', false); }, 1500);
                }
            });

            $container.append($item);
        });
    }

    function updateTitlesCounter() {
        var $container = $('#yimai-gso-step1-titles-results');
        var selected = $container.find('.yimai-gso-title-item.selected').length;
        var total = titlesState.titles.length;
        // 更新头部计数器和工具栏选中计数
        $('#yimai-gso-step1-titles-counter').text(total);
        $('#yimai-gso-step1-titles-selected-count').text(selected);
    }

    function getSelectedTitlesForBatch() {
        var selectedTitles = [];
        $('#yimai-gso-step1-titles-results .yimai-gso-title-item.selected').each(function () {
            selectedTitles.push($(this).find('.yimai-gso-title-text').text());
        });
        return selectedTitles;
    }

    function restoreBatchPromptsFromStorage(forceAppend) {
        var savedPrompts = sessionStorage.getItem('yimai_gso_batch_prompts');
        
        if (!savedPrompts) {
            return;
        }

        // 直接更新可见的文本域（用户可以直接编辑）
        $('#yimai-gso-batch-prompts').val(savedPrompts);
        
        // 只在forceAppend为true时清除sessionStorage（从标题页面跳转过来时）
        if (forceAppend) {
            sessionStorage.removeItem('yimai_gso_batch_prompts');
        }
    }

    function syncSelectedTitlesToBatch(step) {
        var selectedTitles = getSelectedTitlesForBatch();
        if (selectedTitles.length === 0) {
            alert('请先选择要生成的标题！');
            return false;
        }

        sessionStorage.setItem('yimai_gso_batch_prompts', selectedTitles.join('\n'));
        switchMainTab('generate');
        switchStep(step || 2);
        // 延迟确保DOM更新完成
        setTimeout(function() {
            restoreBatchPromptsFromStorage(false);
        }, 100);
        $('html, body').animate({ scrollTop: 0 }, 300);
        return true;
    }

    // ==================== 标题历史记录 ====================

    function loadTitleHistory() {
        $.ajax({
            url: yimaiGsoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'yimai_gso_get_title_history',
                nonce: yimaiGsoData.nonce,
            },
            success: function (res) {
                if (res.success && res.data.history && res.data.history.length > 0) {
                    // 渲染到所有历史记录容器
                    renderTitleHistory(res.data.history);
                    $('#yimai-gso-titles-clear-all').show();
                } else {
                    // 空状态 - 更新所有容器
                    var emptyHtml = '<div class="yimai-gso-empty" style="padding:12px 0;"><p style="font-size:12px;color:#94a3b8;">暂无历史记录</p></div>';
                    $('#yimai-gso-titles-history, #yimai-gso-step1-titles-history').html(emptyHtml);
                    $('#yimai-gso-titles-clear-all').hide();
                }
            }
        });
    }

    function renderTitleHistory(history) {
        // 使用临时容器构建卡片，避免依赖特定页面才存在的容器
        var $container = $('<div>');

        $.each(history, function (i, record) {
            var $card = $('<div class="yimai-gso-history-card">')
                .attr('data-index', i);

            var $header = $('<div class="yimai-gso-history-card-header">');
            var $keyword = $('<span class="yimai-gso-history-keyword">').html(
                '🔑 <strong>' + escapeHtml(record.keyword) + '</strong> · ' + record.count + ' 个标题'
            );
            var $time = $('<span class="yimai-gso-history-time">').text(record.created || '');
            var $deleteBtn = $('<button class="button button-small yimai-gso-history-delete-btn">')
                .text('删除')
                .attr('data-index', i);

            $header.append($keyword).append($time).append($deleteBtn);

            // 标题预览（只显示前5个）
            var previewTitles = (record.titles || []).slice(0, 5);
            var remaining = (record.titles || []).length - previewTitles.length;

            var $preview = $('<div class="yimai-gso-history-preview">');
            $.each(previewTitles, function (j, title) {
                $preview.append($('<span class="yimai-gso-history-tag">').text(title));
            });
            if (remaining > 0) {
                $preview.append($('<span class="yimai-gso-history-more">').text('+' + remaining + ' 个'));
            }

            var $actions = $('<div class="yimai-gso-history-actions">');
            var $viewBtn = $('<button class="button button-small yimai-gso-history-view-btn">')
                .text('查看全部');
            $actions.append($viewBtn);

            $card.append($header).append($preview).append($actions);
            $container.append($card);
        });

        var html = $container.html();
        // 渲染到所有历史记录容器
        $('#yimai-gso-titles-history, #yimai-gso-step1-titles-history').html(html);

        // 绑定事件
        // 删除
        $('#yimai-gso-titles-history').off('click', '.yimai-gso-history-delete-btn').on('click', '.yimai-gso-history-delete-btn', function (e) {
            e.stopPropagation();
            var index = $(this).data('index');
            if (confirm('确定删除这条记录？')) {
                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_delete_title_history',
                        nonce: yimaiGsoData.nonce,
                        index: index,
                    },
                    success: function (res) {
                        if (res.success) {
                            loadTitleHistory();
                        }
                    }
                });
            }
        });

        // 查看全部 - 把历史标题加载到右侧结果区域
        $('#yimai-gso-titles-history').off('click', '.yimai-gso-history-view-btn').on('click', '.yimai-gso-history-view-btn', function (e) {
            e.stopPropagation();
            var index = $(this).closest('.yimai-gso-history-card').data('index');
            var record = history[index];
            if (record && record.titles) {
                titlesState.titles = record.titles;
                renderTitlesList(record.titles);
                // 滚动到顶部
                $('html, body').animate({ scrollTop: 0 }, 300);
            }
        });

        // 步骤1容器中的删除按钮
        $('#yimai-gso-step1-titles-history').off('click', '.yimai-gso-history-delete-btn').on('click', '.yimai-gso-history-delete-btn', function (e) {
            e.stopPropagation();
            var index = $(this).data('index');
            if (confirm('确定删除这条记录？')) {
                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_delete_title_history',
                        nonce: yimaiGsoData.nonce,
                        index: index,
                    },
                    success: function (res) {
                        if (res.success) {
                            loadTitleHistory();
                        }
                    }
                });
            }
        });

        // 步骤1容器中的查看全部按钮
        $('#yimai-gso-step1-titles-history').off('click', '.yimai-gso-history-view-btn').on('click', '.yimai-gso-history-view-btn', function (e) {
            e.stopPropagation();
            var index = $(this).closest('.yimai-gso-history-card').data('index');
            var record = history[index];
            if (record && record.titles) {
                titlesState.titles = record.titles;
                renderTitlesList(record.titles);
                // 滚动到顶部
                $('html, body').animate({ scrollTop: 0 }, 300);
            }
        });

        // 清空全部
        $('#yimai-gso-titles-clear-all').off('click').on('click', function () {
            if (confirm('确定清空所有历史记录？此操作不可撤销。')) {
                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_clear_title_history',
                        nonce: yimaiGsoData.nonce,
                    },
                    success: function (res) {
                        loadTitleHistory();
                    }
                });
            }
        });

        // 选题生成区域的历史记录清空按钮
        $(document).on('click', '#yimai-gso-step1-history-clear', function () {
            if (confirm('确定清空所有历史记录？此操作不可撤销。')) {
                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_clear_title_history',
                        nonce: yimaiGsoData.nonce,
                    },
                    success: function (res) {
                        loadTitleHistory();
                    }
                });
            }
        });
    }

    // ==================== 生成记录（改版） ====================
    function initHistoryPage() {
        // 不在历史页面则跳过
        if (!$('#yimai-gso-history-stats').length) return;

        loadHistoryStats();
        loadHistory(1);

        // 筛选按钮
        $('#yimai-gso-h-filter-btn').off('click').on('click', function () {
            loadHistory(1);
        });

        // 回车搜索
        $('#yimai-gso-h-search').off('keydown').on('keydown', function (e) {
            if (e.key === 'Enter') loadHistory(1);
        });

        // 重置
        $('#yimai-gso-h-reset-btn').off('click').on('click', function () {
            $('#yimai-gso-h-search').val('');
            $('#yimai-gso-h-status').val('');
            $('#yimai-gso-h-source').val('');
            $('#yimai-gso-h-date-from').val('');
            $('#yimai-gso-h-date-to').val('');
            loadHistory(1);
        });

        // 刷新
        $('#yimai-gso-h-refresh-btn').off('click').on('click', function () {
            loadHistoryStats();
            loadHistory(currentHistoryPage);
        });

        // 全选
        $('#yimai-gso-h-check-all').off('change').on('change', function () {
            var checked = this.checked;
            $('#yimai-gso-history-tbody input[type="checkbox"]').prop('checked', checked).trigger('change');
        });

        // 单个选择
        $(document).off('change', '.yimai-gso-h-row-check').on('change', '.yimai-gso-h-row-check', function () {
            var $row = $(this).closest('tr');
            if (this.checked) $row.addClass('yimai-gso-row-selected');
            else $row.removeClass('yimai-gso-row-selected');
            updateBatchDeleteBtn();
        });

        // 批量删除
        $('#yimai-gso-h-batch-delete-btn').off('click').on('click', function () {
            var ids = getSelectedIds();
            if (!ids.length) return;
            if (!confirm('确定要删除选中的 ' + ids.length + ' 篇文章吗？此操作不可撤销。')) return;

            var $btn = $(this);
            $btn.prop('disabled', true).text('删除中...');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_history_delete',
                nonce: yimaiGsoData.nonce,
                ids: ids
            }, function (res) {
                $btn.prop('disabled', false).text('批量删除').hide();
                $('#yimai-gso-h-check-all').prop('checked', false);
                loadHistoryStats();
                loadHistory(currentHistoryPage);
            }).fail(function () {
                $btn.prop('disabled', false).text('批量删除');
                alert('删除失败，请重试');
            });
        });

        // 弹窗关闭
        $(document).off('click', '.yimai-gso-modal-close, .yimai-gso-modal-overlay').on('click', '.yimai-gso-modal-close, .yimai-gso-modal-overlay', function () {
            $('#yimai-gso-history-modal').fadeOut(150);
        });

        $(document).off('keydown').on('keydown', function (e) {
            if (e.key === 'Escape') $('#yimai-gso-history-modal').fadeOut(150);
        });
    }

    var currentHistoryPage = 1;

    function loadHistory(page) {
        currentHistoryPage = page;
        var $tbody = $('#yimai-gso-history-tbody');
        $tbody.html('<tr><td colspan="6" class="yimai-gso-loading-row">加载中...</td></tr>');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_history_query',
            nonce: yimaiGsoData.nonce,
            page: page,
            per_page: 15,
            search: $('#yimai-gso-h-search').val(),
            status: $('#yimai-gso-h-status').val(),
            source: $('#yimai-gso-h-source').val(),
            date_from: $('#yimai-gso-h-date-from').val(),
            date_to: $('#yimai-gso-h-date-to').val(),
            orderby: 'date',
            order: 'DESC'
        }, function (res) {
            if (!res.success) {
                $tbody.html('<tr><td colspan="6" class="yimai-gso-loading-row">加载失败</td></tr>');
                return;
            }
            var d = res.data;
            renderHistoryTable(d.items);
            renderPagination(d.page, d.pages, d.total, loadHistory);
        }).fail(function () {
            $tbody.html('<tr><td colspan="6" class="yimai-gso-loading-row">网络错误，请重试</td></tr>');
        });
    }

    function renderHistoryTable(items) {
        var $tbody = $('#yimai-gso-history-tbody');

        if (!items || items.length === 0) {
            $tbody.html('<tr><td colspan="6" class="yimai-gso-loading-row"><div class="yimai-gso-history-empty"><span class="dashicons dashicons-media-text"></span><p style="margin:0;font-size:14px;">暂无生成记录</p><p style="margin:4px 0 0;font-size:12px;color:#94a3b8;"><a href="admin.php?page=yimai-gso-batch">去生成第一篇文章</a></p></div></td></tr>');
            return;
        }

        var statusMap = {
            'publish': ['已发布', 'publish'],
            'draft': ['草稿', 'draft'],
            'future': ['定时发布', 'future'],
            'trash': ['回收站', 'trash'],
            'pending': ['待审', 'pending'],
            'private': ['私密', 'private']
        };

        var sourceMap = {
            'batch': ['批量生成', 'batch'],
            'manual': ['手动发布', 'manual'],
        };

        var html = '';
        items.forEach(function (item, i) {
            var st = statusMap[item.status] || [item.status, ''];
            var sr = sourceMap[item.source] || [item.source, 'batch'];

            // 缩略图
            var thumbHtml = item.thumbnail
                ? '<img class="yimai-gso-h-thumb" src="' + item.thumbnail + '" alt="">'
                : '<div class="yimai-gso-h-thumb-placeholder">📄</div>';

            // 分类+标签
            var metaParts = [];
            if (item.categories && item.categories.length) metaParts.push(item.categories.join(', '));
            var metaLine = metaParts.join(' · ');

            // 字数统计
            var wordInfo = item.word_count ? item.word_count + '字' : '';

            html += '<tr data-id="' + item.id + '">';
            html += '<td class="yimai-gso-th-check"><input type="checkbox" class="yimai-gso-h-row-check" value="' + item.id + '"></td>';
            html += '<td class="yimai-gso-th-title">';
            html += '<div class="yimai-gso-h-title-cell">';
            html += thumbHtml;
            html += '<div style="min-width:0;flex:1;">';
            html += '<div class="yimai-gso-h-title-text" data-id="' + item.id + '">' + escHtml(item.title) + '</div>';
            if (metaLine || wordInfo) {
                html += '<div class="yimai-gso-h-meta-line">';
                if (metaLine) html += '<span>' + escHtml(metaLine) + '</span>';
                if (wordInfo) html += '<span>· ' + wordInfo + '</span>';
                if (item.image_count > 0) html += '<span>· ' + item.image_count + '张图</span>';
                html += '</div>';
            }
            if (item.scheduled_at) {
                html += '<div class="yimai-gso-h-meta-line" style="color:#2563eb;">⏰ ' + escHtml(item.scheduled_at) + '</div>';
            }
            html += '</div></div></td>';
            html += '<td class="yimai-gso-th-status"><span class="yimai-gso-h-status yimai-gso-h-status-' + st[1] + '">' + st[0] + '</span></td>';
            html += '<td class="yimai-gso-th-source"><span class="yimai-gso-h-source yimai-gso-h-source-' + sr[1] + '">' + sr[0] + '</span></td>';
            html += '<td class="yimai-gso-th-date"><span style="font-size:13px;color:#475569;">' + formatTime(item.generated_at || item.date) + '</span></td>';
            html += '<td class="yimai-gso-th-actions"><div class="yimai-gso-h-actions">';
            html += '<a href="' + item.view_url + '" target="_blank" class="button">查看</a>';
            html += '<a href="' + item.edit_url + '" class="button">编辑</a>';
            html += '<button class="button yimai-gso-h-detail-btn" data-id="' + item.id + '" style="color:var(--yimai-gso-primary);">详情</button>';
            html += '</div></td>';
            html += '</tr>';
        });

        $tbody.html(html);

        // 绑定详情按钮
        $tbody.off('click', '.yimai-gso-h-detail-btn').on('click', '.yimai-gso-h-detail-btn', function () {
            var id = $(this).data('id');
            showDetailModal(id);
        });

        // 绑定标题点击
        $tbody.off('click', '.yimai-gso-h-title-text').on('click', '.yimai-gso-h-title-text', function () {
            var id = $(this).data('id');
            showDetailModal(id);
        });
    }

    // ==================== 分页函数 ====================
    function renderPagination(current, total, items, onPageChange) {
        var $pag = $('#yimai-gso-history-pagination');
        if (total <= 1) { $pag.html(''); return; }

        var html = '<button class="yimai-gso-page-btn' + (current <= 1 ? ' disabled' : '') + '" data-page="' + (current - 1) + '">‹</button>';

        var start = Math.max(1, current - 2);
        var end = Math.min(total, current + 2);

        if (start > 1) {
            html += '<button class="yimai-gso-page-btn" data-page="1">1</button>';
            if (start > 2) html += '<span class="yimai-gso-page-info">...</span>';
        }

        for (var i = start; i <= end; i++) {
            html += '<button class="yimai-gso-page-btn' + (i === current ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }

        if (end < total) {
            if (end < total - 1) html += '<span class="yimai-gso-page-info">...</span>';
            html += '<button class="yimai-gso-page-btn" data-page="' + total + '">' + total + '</button>';
        }

        html += '<button class="yimai-gso-page-btn' + (current >= total ? ' disabled' : '') + '" data-page="' + (current + 1) + '">›</button>';
        html += '<span class="yimai-gso-page-info">共 ' + items + ' 条</span>';

        $pag.html(html);

        $pag.off('click', '.yimai-gso-page-btn:not(.disabled)').on('click', '.yimai-gso-page-btn:not(.disabled)', function () {
            var page = parseInt($(this).data('page'));
            if (onPageChange) {
                onPageChange(page);
            }
        });
    }

    function showDetailModal(id) {
        var $modal = $('#yimai-gso-history-modal');
        var $body = $('#yimai-gso-modal-body');
        $body.html('<div style="text-align:center;padding:30px;color:#94a3b8;">加载中...</div>');
        $modal.fadeIn(150);

        // 从表格行获取缓存数据（避免二次请求）
        // 如果没有缓存则发请求
        var rowData = findRowData(id);
        if (rowData) {
            renderDetailContent(rowData);
        } else {
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_history_query',
                nonce: yimaiGsoData.nonce,
                page: 1,
                per_page: 1,
                // 直接通过 ID 过滤 - 这里简化处理
            }, function (res) {
                if (res.success && res.data.items && res.data.items[0]) {
                    renderDetailContent(res.data.items[0]);
                } else {
                    $body.html('<p style="color:#ef4444;">加载详情失败</p>');
                }
            });
        }
    }

    // 存储当前表格数据用于弹窗
    var historyCache = [];

    // 缓存数据在 renderHistoryTable 中设置

    // 重写 renderHistoryTable 缓存数据
    var _origRenderHistoryTable = renderHistoryTable;
    renderHistoryTable = function (items) {
        historyCache = {};
        if (items) {
            items.forEach(function (item) {
                historyCache[item.id] = item;
            });
        }
        _origRenderHistoryTable(items);
    };

    function findRowData(id) {
        return historyCache[id] || null;
    }

    function renderDetailContent(item) {
        var $body = $('#yimai-gso-modal-body');
        var $title = $('#yimai-gso-modal-title');
        $title.text(item.title);

        var statusColors = { '已发布': '#16a34a', '草稿': '#64748b', '定时发布': '#2563eb', '回收站': '#dc2626' };
        var statusColor = statusColors[item.status] || '#475569';

        var html = '<div class="yimai-gso-detail-grid">';
        html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">状态</div><div class="yimai-gso-detail-value"><span style="color:' + statusColor + ';font-weight:600;">' + escHtml(item.status) + '</span></div></div>';
        html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">来源</div><div class="yimai-gso-detail-value">' + escHtml(item.source) + '</div></div>';
        html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">生成时间</div><div class="yimai-gso-detail-value">' + escHtml(item.date) + '</div></div>';

        if (item.word_count) {
            html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">目标字数</div><div class="yimai-gso-detail-value">' + item.word_count + ' 字</div></div>';
        }
        if (item.image_count > 0) {
            html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">配图数量</div><div class="yimai-gso-detail-value">' + item.image_count + ' 张</div></div>';
        }

        if (item.categories && item.categories.length) {
            html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">分类</div><div class="yimai-gso-detail-value">' + escHtml(item.categories.join(', ')) + '</div></div>';
        }
        if (item.tags && item.tags.length) {
            html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">标签</div><div class="yimai-gso-detail-value"><div class="yimai-gso-detail-tags">';
            item.tags.forEach(function (t) { html += '<span class="yimai-gso-detail-tag">' + escHtml(t) + '</span>'; });
            html += '</div></div></div>';
        }

        html += '<div class="yimai-gso-detail-divider"></div>';

        if (item.prompt) {
            html += '<div class="yimai-gso-detail-item full-width"><div class="yimai-gso-detail-label">生成提示词</div><div class="yimai-gso-detail-prompt">' + escHtml(item.prompt) + '</div></div>';
        }

        if (item.ai_model) {
            html += '<div class="yimai-gso-detail-item"><div class="yimai-gso-detail-label">AI 模型</div><div class="yimai-gso-detail-value">' + escHtml(item.ai_model) + '</div></div>';
        }

        html += '<div class="yimai-gso-detail-actions">';
        html += '<a href="' + item.view_url + '" target="_blank" class="button button-primary">查看文章</a>';
        html += '<a href="' + item.edit_url + '" class="button">编辑文章</a>';
        html += '<button type="button" class="button yimai-gso-detail-change-image-btn" data-id="' + item.id + '">🔄 一键换图</button>';
        html += '<button type="button" class="button yimai-gso-detail-delete-btn" data-id="' + item.id + '" style="color:#dc2626;">🗑 删除文章</button>';
        html += '</div>';

        html += '</div>';
        $body.html(html);
    }

    function getSelectedIds() {
        var ids = [];
        $('#yimai-gso-history-tbody input.yimai-gso-h-row-check:checked').each(function () {
            ids.push(parseInt($(this).val()));
        });
        return ids;
    }

    function updateBatchDeleteBtn() {
        var count = getSelectedIds().length;
        if (count > 0) {
            $('#yimai-gso-h-batch-delete-btn').text('批量删除 (' + count + ')').show();
        } else {
            $('#yimai-gso-h-batch-delete-btn').hide();
        }
    }

    function formatTime(dtStr) {
        if (!dtStr) return '-';
        // 修复：使用 split + join，代码意图更清晰
        var d = new Date(dtStr.split('-').join('/'));
        if (isNaN(d.getTime())) return dtStr;
        var now = new Date();
        var diff = (now - d) / 1000;

        if (diff < 60) return '刚刚';
        if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
        if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
        if (diff < 604800) return Math.floor(diff / 86400) + ' 天前';

        var m = d.getMonth() + 1;
        var day = d.getDate();
        var h = String(d.getHours()).padStart(2, '0');
        var min = String(d.getMinutes()).padStart(2, '0');
        if (d.getFullYear() === now.getFullYear()) {
            return m + '-' + day + ' ' + h + ':' + min;
        }
        return d.getFullYear() + '-' + m + '-' + day;
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    initHistoryPage();

    // ==================== 仪表盘页面 ====================
    // ========== 仪表盘 v5 初始化 ==========
    var yimaiGsoD5Charts = {};       // 图表实例引用
    var yimaiGsoD5Data = null;       // 缓存完整数据
    var yimaiGsoD5Initing = false;   // 防止并发初始化

    function initDashboardPage() {
        var $panel = $('.yimai-gso-main-panel[data-tab="dashboard"]');
        if (!$panel.length || !$panel.hasClass('active')) {
            stopD5Countdown();
            return;
        }

        // 避免重复初始化：已在初始化中 或 已有数据
        if (yimaiGsoD5Initing || yimaiGsoD5Data !== null) return;
        yimaiGsoD5Initing = true;

        // 清理旧图表实例
        $.each(yimaiGsoD5Charts, function (key, chart) {
            if (chart && typeof chart.destroy === 'function') chart.destroy();
        });
        yimaiGsoD5Charts = {};

        // 加载仪表盘数据
        loadDashboardV5Data();

        // 趋势 Tab 切换
        $('#yimai-gso-d5-trend-tabs').off('click.yimaiGsoD5Trend').on('click.yimaiGsoD5Trend', '.yimai-gso-d5-chart-tab', function () {
            var range = $(this).data('range');
            if (!range || !yimaiGsoD5Data) return;
            $('#yimai-gso-d5-trend-tabs .yimai-gso-d5-chart-tab').removeClass('active');
            $(this).addClass('active');
            renderCrawlerTrendChart(yimaiGsoD5Data.crawler_trend[range]);
        });
    }

    /**
     * 加载仪表盘全部数据
     */
    function loadDashboardV5Data() {
        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_dashboard_data',
            nonce: yimaiGsoData.nonce
        }, function (res) {
            yimaiGsoD5Initing = false; // 无论成功失败都重置初始化锁
            if (!res || !res.success || !res.data) {
                console.warn('[一麦GSO Dashboard] 仪表盘数据加载失败', res);
                showDashboardError('数据加载失败，请刷新页面重试');
                return;
            }
            yimaiGsoD5Data = res.data;

            // 更新「数据更新于」时间戳
            if (res.data.generated_at) {
                $('#yimai-gso-d5-update-time').text(res.data.generated_at.substring(0, 16));
            }

            renderKPICards(yimaiGsoD5Data);
            renderHealthRadar(yimaiGsoD5Data.health_radar);
            renderSchemaChart(yimaiGsoD5Data.schema_coverage);
            renderCrawlerFamilyChart(yimaiGsoD5Data.crawler_family);
            renderCrawlerTrendChart(yimaiGsoD5Data.crawler_trend['7']);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            yimaiGsoD5Initing = false; // 重置初始化锁，允许重试
            console.warn('[一麦GSO Dashboard] AJAX 请求失败:', textStatus, errorThrown);
            showDashboardError('网络请求失败 (' + (jqXHR.status || '0') + ')，请检查控制台或刷新页面');
        });
    }

    /**
     * 显示仪表盘错误提示
     */
    function showDashboardError(msg) {
        $('#yimai-gso-d5-health-grid').html('<div class="yimai-gso-d5-health-item"><div class="yimai-gso-d5-health-item-header"><span class="yimai-gso-d5-health-item-name" style="color:#ef4444">' + msg + '</span></div></div>');
    }

    /**
     * 渲染 KPI 卡片
     */
    function renderKPICards(data) {
        var kpi = data.kpi || {};
        var $cards = $('#yimai-gso-d5-kpi-grid .yimai-gso-d5-kpi-card');

        // [0] 已发布文章
        $cards.eq(0).find('.yimai-gso-d5-kpi-value').text(kpi.articles || 0);

        // [1] AI爬虫访问
        $cards.eq(1).find('.yimai-gso-d5-kpi-value').text(formatNumber(kpi.crawler_today || 0));

        // [2] Schema覆盖率
        $cards.eq(2).find('.yimai-gso-d5-kpi-value').text(kpi.schema_rate || 0);

        // [3] AI引荐流量
        $cards.eq(3).find('.yimai-gso-d5-kpi-value').text(formatNumber(kpi.referral_30d || 0));

        // [4] 定时发布队列
        $cards.eq(4).find('.yimai-gso-d5-kpi-value').text(kpi.scheduled || 0);

        // [5] 活跃预警
        var alertVal = kpi.active_alerts || 0;
        var $alertVal = $cards.eq(5).find('.yimai-gso-d5-kpi-value');
        $alertVal.text(alertVal);
        if (alertVal > 0) {
            $alertVal.css('color', '#ef4444');
            $cards.eq(5).find('.yimai-gso-d5-kpi-unit').text('条');
        }
    }

    /**
     * 渲染健康雷达
     */
    function renderHealthRadar(items) {
        if (!items || !items.length) return;
        var html = '';
        items.forEach(function (item) {
            var cls = item.score >= 80 ? 'good' : (item.score >= 50 ? 'warn' : 'bad');
            var fillCls = item.score >= 80 ? 'good' : (item.score >= 50 ? 'warn' : 'bad');
            html += '<div class="yimai-gso-d5-health-item">' +
                '<div class="yimai-gso-d5-health-item-header">' +
                '<span class="yimai-gso-d5-health-item-name">' + escHtml(item.name) + '</span>' +
                '<span class="yimai-gso-d5-health-item-score ' + cls + '">' + item.score + '</span>' +
                '</div>' +
                '<div class="yimai-gso-d5-health-item-bar"><div class="yimai-gso-d5-health-item-fill ' + fillCls + '" style="width:' + Math.max(2, item.score) + '%"></div></div>' +
                '<div class="yimai-gso-d5-health-item-detail">' + (item.detail || '') + '</div>' +
                '</div>';
        });
        $('#yimai-gso-d5-health-grid').html(html);
    }

    /**
     * 爬虫趋势图
     */
    function renderCrawlerTrendChart(trendData) {
        if (!trendData || !trendData.labels) return;
        var ctx = document.getElementById('yimai-gso-d5-crawler-trend');
        if (!ctx) return;

        if (yimaiGsoD5Charts.crawlerTrend) {
            yimaiGsoD5Charts.crawlerTrend.destroy();
        }

        var families = ['OpenAI', 'Anthropic', 'DeepSeek', 'Perplexity', 'ByteDance'];
        var colors = ['rgba(16,185,129,0.08)', 'rgba(245,158,11,0.08)', 'rgba(99,102,241,0.08)', 'rgba(6,182,212,0.08)', 'rgba(139,92,246,0.08)'];
        var borderColors = ['#10b981', '#f59e0b', '#6366f1', '#06b6d4', '#8b5cf6'];
        var datasets = families.map(function (f, i) {
            return {
                label: f,
                data: trendData[f] || [],
                borderColor: borderColors[i],
                backgroundColor: colors[i],
                fill: true,
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4
            };
        });

        // 总访问量线（所有爬虫合计）
        if (trendData.Total && trendData.Total.length) {
            datasets.push({
                label: '总访问量',
                data: trendData.Total,
                borderColor: '#1e293b',
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.4,
                borderWidth: 2.5,
                borderDash: [],
                pointRadius: 0,
                pointHoverRadius: 4,
                order: -1
            });
        }

        yimaiGsoD5Charts.crawlerTrend = new Chart(ctx, {
            type: 'line',
            data: { labels: trendData.labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, pointStyleWidth: 8, padding: 14, boxWidth: 8, font: { size: 10 } } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 7, font: { size: 10 } } },
                    y: { grid: { color: '#f1f5f9' }, beginAtZero: true, ticks: { maxTicksLimit: 5, font: { size: 10 } } }
                }
            }
        });
    }

    /**
     * Schema 覆盖图
     */
    function renderSchemaChart(items) {
        var ctx = document.getElementById('yimai-gso-d5-schema-chart');
        if (!ctx) return;

        if (yimaiGsoD5Charts.schema) {
            yimaiGsoD5Charts.schema.destroy();
        }

        var types = items.map(function (i) { return i.type; });
        var counts = items.map(function (i) { return i.count; });
        var bgColors = [
            'rgba(99,102,241,.7)', 'rgba(99,102,241,.6)', 'rgba(99,102,241,.5)',
            'rgba(16,185,129,.7)', 'rgba(16,185,129,.6)',
            'rgba(245,158,11,.6)', 'rgba(139,92,246,.5)', 'rgba(6,182,212,.5)'
        ];

        yimaiGsoD5Charts.schema = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: types,
                datasets: [{ data: counts, backgroundColor: bgColors.slice(0, types.length), borderRadius: 6, borderSkipped: false }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 45, font: { size: 10 } } },
                    y: { grid: { color: '#f1f5f9' }, beginAtZero: true, ticks: { maxTicksLimit: 4, font: { size: 10 } } }
                }
            }
        });
    }

    /**
     * 爬虫家族分布
     */
    function renderCrawlerFamilyChart(items) {
        var ctx = document.getElementById('yimai-gso-d5-family-chart');
        if (!ctx) return;

        if (yimaiGsoD5Charts.family) {
            yimaiGsoD5Charts.family.destroy();
        }

        var labels = items.map(function (i) { return i.family; });
        var data = items.map(function (i) { return i.count; });
        var colors = ['#10b981', '#6366f1', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#ef4444', '#94a3b8'];

        yimaiGsoD5Charts.family = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: colors.slice(0, labels.length), borderWidth: 0, borderRadius: 2, spacing: 2 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, pointStyleWidth: 8, padding: 10, boxWidth: 8, font: { size: 10 } } }
                }
            }
        });
    }


    /**
     * 停止倒计时（切换 Tab 或页面时调用）—— 已废弃，保留空函数兼容旧调用
     */
    function stopD5Countdown() {
        // v2.9.23 已移除倒计时自动刷新功能
    }

    /**
     * 数字格式化（K/M）
     */
    function formatNumber(n) {
        n = parseInt(n, 10) || 0;
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return n.toString();
    }

    function loadRecentArticles() {
        var $container = $('#yimai-gso-recent-list');
        $container.html('<div class="yimai-gso-recent-loading"><span class="yimai-gso-loading"></span>加载中...</div>');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_history_query',
            nonce: yimaiGsoData.nonce,
            page: 1,
            per_page: 5,
            orderby: 'date',
            order: 'DESC'
        }, function (res) {
            if (!res.success || !res.data.items || res.data.items.length === 0) {
                $container.html(
                    '<div class="yimai-gso-recent-empty">' +
                    '<span class="dashicons dashicons-media-text"></span>' +
                    '<h3>暂无生成记录</h3>' +
                    '<p>点击上方「快速生成」开始创作</p>' +
                    '</div>'
                );
                return;
            }

            var html = '';
            res.data.items.forEach(function (item) {
                var statusClass = item.status === 'publish' ? 'publish' : (item.status === 'draft' ? 'draft' : 'future');
                var statusText = {publish: '已发布', draft: '草稿', future: '定时'}[item.status] || item.status;

                var thumbHtml = item.thumbnail
                    ? '<img src="' + item.thumbnail + '" alt="">'
                    : '<span>📄</span>';

                html += '<div class="yimai-gso-recent-item" data-id="' + item.id + '">' +
                    '<div class="yimai-gso-recent-thumb">' + thumbHtml + '</div>' +
                    '<div class="yimai-gso-recent-info">' +
                    '<div class="yimai-gso-recent-title">' + escHtml(item.title) + '</div>' +
                    '<div class="yimai-gso-recent-meta">' +
                    '<span class="yimai-gso-recent-status yimai-gso-h-status-' + statusClass + '">' + statusText + '</span>' +
                    '<span>' + formatTime(item.generated_at || item.date) + '</span>' +
                    '</div>' +
                    '</div>' +
                    '<div class="yimai-gso-recent-actions">' +
                    '<a href="' + item.view_url + '" target="_blank" class="button button-small">查看</a>' +
                    '<a href="' + item.edit_url + '" class="button button-small">编辑</a>' +
                    '</div>' +
                    '</div>';
            });

            $container.html(html);
        });
    }

    // ==================== 生成页面步骤条 ====================
    function initGeneratePage() {
        // 检查生成页面面板是否存在
        var $panel = $('.yimai-gso-main-panel[data-tab="generate"]');
        if (!$panel.length) return;

        // 步骤切换
        $panel.find('.yimai-gso-batch-tab').off('click.yimaiGsoStep').on('click.yimaiGsoStep', function () {
            var step = $(this).data('step');
            switchStep(step);
        });

        // 发布快捷设置
        $panel.find('.yimai-gso-shortcut-btn').off('click.yimaiGsoShortcut').on('click.yimaiGsoShortcut', function () {
            var requestedStatus = $(this).data('status');
            var safeStatus = normalizeBatchStatus(requestedStatus);
            if (safeStatus !== requestedStatus) {
                alert(requestedStatus === 'draft'
                    ? '当前站点未启用批量生成功能，无法使用此入口。'
                    : '当前账号没有发布文章权限，已切换为保存草稿。');
            }
            $('input[name="yimai-gso-batch-status"][value="' + safeStatus + '"]').prop('checked', true).trigger('change');
        });









        restoreBatchPromptsFromStorage(false);
        loadBatchScheduleSettings();

        var urlParams = new URLSearchParams(window.location.search);
        var requestedStep = urlParams.get('step');
        if (requestedStep && $panel.find('.yimai-gso-batch-tab[data-step="' + requestedStep + '"]').length) {
            switchStep(requestedStep);
        } else {
            var currentStep = String($panel.find('.yimai-gso-batch-tab.active').data('step') || '1');
            switchStep(currentStep);
        }
    }

    function switchStep(step) {
        var stepKey = String(step);
        $('.yimai-gso-batch-tab').removeClass('active');
        $('.yimai-gso-batch-tab[data-step="' + stepKey + '"]').addClass('active');
        $('.yimai-gso-step-content').removeClass('active');
        $('.yimai-gso-step-content[data-step="' + stepKey + '"]').addClass('active');

        if ($('.yimai-gso-main-panel[data-tab="generate"]').hasClass('active')) {
            var url = new URL(window.location.href);
            url.searchParams.set('step', stepKey);
            history.replaceState({}, '', url);
        }

        // 切换到步骤1时加载历史记录
        if (stepKey === '1') {
            loadTitleHistory();
        }
    }



    // ==================== 生成记录卡片式渲染 ====================
    function initHistoryCards() {
        // 内容管理在 .yimai-gso-main-panel[data-tab="history"] 中
        var $panel = $('.yimai-gso-main-panel[data-tab="history"]');
        if (!$panel.length) return;

        // 如果当前tab是history，加载数据
        if ($panel.hasClass('active')) {
            loadHistoryStats();
            loadHistoryCards(1);
        }

        // 筛选
        $('#yimai-gso-h-filter-btn, #yimai-gso-h-search-btn').off('click').on('click', function () {
            loadHistoryCards(1);
        });

        // 回车搜索
        $('#yimai-gso-h-search').off('keydown').on('keydown', function (e) {
            if (e.key === 'Enter') loadHistoryCards(1);
        });

        // 重置
        $('#yimai-gso-h-reset-btn').off('click').on('click', function () {
            $('#yimai-gso-h-search').val('');
            $('#yimai-gso-h-status').val('');
            $('#yimai-gso-h-source').val('');
            $('#yimai-gso-h-date-from').val('');
            $('#yimai-gso-h-date-to').val('');
            loadHistoryCards(1);
        });

        // 刷新
        $('#yimai-gso-h-refresh-btn').off('click').on('click', function () {
            loadHistoryStats();
            loadHistoryCards(currentHistoryPage);
        });

        // 弹窗
        $(document).off('click', '.yimai-gso-modal-close, .yimai-gso-modal-overlay').on('click', '.yimai-gso-modal-close, .yimai-gso-modal-overlay', function () {
            $('#yimai-gso-history-modal').fadeOut(150);
        });

        // 详情弹窗内的一键换图（弹窗内容动态渲染，用委托绑定）→ 打开图片选择弹窗
        $(document).off('click', '.yimai-gso-detail-change-image-btn').on('click', '.yimai-gso-detail-change-image-btn', function () {
            var id = $(this).data('id');
            openImagePicker(id);
        });

        // 详情弹窗内的删除文章
        $(document).off('click', '.yimai-gso-detail-delete-btn').on('click', '.yimai-gso-detail-delete-btn', function () {
            var $btn = $(this);
            var id = $btn.data('id');
            var title = (historyCardCache[id] && historyCardCache[id].title) || '该文章';
            if (!window.confirm('确定删除文章《' + title + '》？\n删除后文章与封面图将进入回收站，此操作可在文章回收站恢复。')) {
                return;
            }
            $btn.prop('disabled', true).html('删除中…');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_history_delete',
                nonce: yimaiGsoData.nonce,
                ids: [id]
            }, function (res) {
                if (!res.success) {
                    $btn.prop('disabled', false).html('🗑 删除文章');
                    alert('删除失败：' + ((res.data && res.data.message) || '未知错误'));
                    return;
                }
                $('#yimai-gso-history-modal').fadeOut(150);
                showSaveSuccessToast('history-delete');
                loadHistoryStats();
                loadHistoryCards(currentHistoryPage);
            }).fail(function () {
                $btn.prop('disabled', false).html('🗑 删除文章');
                alert('删除失败：网络错误，请重试');
            });
        });

        $(document).off('keydown').on('keydown', function (e) {
            if (e.key === 'Escape') $('#yimai-gso-history-modal').fadeOut(150);
        });
    }

    function loadHistoryStats() {
        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_history_stats',
            nonce: yimaiGsoData.nonce
        }, function (res) {
            if (res.success) {
                var d = res.data;
                $('[data-stat="total"]').text(d.total || 0);
                $('[data-stat="today"]').text(d.today || 0);
                $('[data-stat="week"]').text(d.week || 0);
                $('[data-stat="published"]').text(d.published || 0);
                $('[data-stat="drafts"]').text(d.drafts || 0);
                $('[data-stat="scheduled"]').text(d.scheduled || 0);
            }
        });
    }

    function loadHistoryCards(page) {
        currentHistoryPage = page;
        // 使用PHP中的正确容器ID
        var $container = $('#yimai-gso-history-list');
        if (!$container.length) return;
        
        $container.html('<div class="yimai-gso-history-loading"><span class="yimai-gso-loading"></span><span>加载中...</span></div>');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_history_query',
            nonce: yimaiGsoData.nonce,
            page: page,
            per_page: 12,
            search: $('#yimai-gso-h-search').val(),
            status: $('#yimai-gso-h-status').val(),
            source: $('#yimai-gso-h-source').val(),
            date_from: $('#yimai-gso-h-date-from').val(),
            date_to: $('#yimai-gso-h-date-to').val(),
            orderby: 'date',
            order: 'DESC'
        }, function (res) {
            if (!res.success) {
                $container.html('<div class="yimai-gso-history-empty"><span class="dashicons dashicons-warning"></span><h3>加载失败</h3><p>请重试</p></div>');
                return;
            }

            var d = res.data;
            if (!d.items || d.items.length === 0) {
                $container.html(
                    '<div class="yimai-gso-history-empty">' +
                    '<span class="dashicons dashicons-media-text"></span>' +
                    '<h3>暂无生成记录</h3>' +
                    '<p>开始创作你的第一篇文章吧</p>' +
                    '<button class="button button-primary" onclick="switchMainTab(\'generate\')">去生成</button>' +
                    '</div>'
                );
                renderPagination(d.page, d.pages, d.total, loadHistoryCards);
                return;
            }

            var html = '';
            d.items.forEach(function (item) {
                var statusClass = item.status === 'publish' ? 'publish' : (item.status === 'draft' ? 'draft' : (item.status === 'future' ? 'future' : 'pending'));
                var statusText = {publish: '已发布', draft: '草稿', future: '定时', pending: '待审', trash: '回收站', private: '私密'}[item.status] || item.status;
                var sourceClass = item.source === 'manual' ? 'manual' : '';
                var sourceText = {batch: '批量生成', manual: '手动发布'}[item.source] || item.source;

                var thumbHtml = item.thumbnail
                    ? '<img src="' + item.thumbnail + '" alt="">'
                    : '<div class="yimai-gso-card-thumb-placeholder">📄</div>';

                html += '<div class="yimai-gso-article-card" data-id="' + item.id + '">' +
                    '<div class="yimai-gso-card-thumb">' +
                    thumbHtml +
                    '<span class="yimai-gso-card-status ' + statusClass + '">' + statusText + '</span>' +
                    '</div>' +
                    '<div class="yimai-gso-card-body">' +
                    '<div class="yimai-gso-card-title">' + escHtml(item.title) + '</div>' +
                    '<div class="yimai-gso-card-meta">' +
                    '<span class="yimai-gso-card-meta-item"><span class="dashicons dashicons-category"></span>' + (item.categories && item.categories[0] || '未分类') + '</span>' +
                    '<span class="yimai-gso-card-source ' + sourceClass + '">' + sourceText + '</span>' +
                    '</div>' +
                    '<div class="yimai-gso-card-actions">' +
                    '<a href="' + item.view_url + '" target="_blank" class="button">查看</a>' +
                    '<a href="' + item.edit_url + '" class="button">编辑</a>' +
                    '<button class="button yimai-gso-card-change-image-btn" data-id="' + item.id + '" title="按配图设置重新取一张图，不满意可连续点换">🔄 换图</button>' +
                    '<button class="button yimai-gso-card-detail-btn" data-id="' + item.id + '">详情</button>' +
                    '<button class="button yimai-gso-card-delete-btn" data-id="' + item.id + '" style="color:#dc2626;">🗑 删除</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            });

            $container.html(html);

            // 绑定详情按钮
            $('.yimai-gso-card-detail-btn').off('click').on('click', function () {
                var id = $(this).data('id');
                showCardDetailModal(id);
            });

            // 换图：先打开图片选择弹窗（列出全部配图，单张换/一键全换）
            $('.yimai-gso-card-change-image-btn').off('click').on('click', function () {
                var id = $(this).data('id');
                openImagePicker(id);
            });

            // 删除文章（带确认；只删插件生成的文章，后端二次校验）
            $('.yimai-gso-card-delete-btn').off('click').on('click', function () {
                var $btn = $(this);
                var id = $btn.data('id');
                var title = '';
                var cached = historyCardCache[id];
                if (cached && cached.title) {
                    title = cached.title;
                } else {
                    title = $btn.closest('.yimai-gso-article-card').find('.yimai-gso-card-title').text();
                }
                if (!window.confirm('确定删除文章《' + title + '》？\n删除后文章与封面图将进入回收站，此操作可在文章回收站恢复。')) {
                    return;
                }
                $btn.prop('disabled', true).html('删除中…');

                $.post(yimaiGsoData.ajaxUrl, {
                    action: 'yimai_gso_history_delete',
                    nonce: yimaiGsoData.nonce,
                    ids: [id]
                }, function (res) {
                    if (!res.success) {
                        $btn.prop('disabled', false).html('🗑 删除');
                        alert('删除失败：' + ((res.data && res.data.message) || '未知错误'));
                        return;
                    }
                    showSaveSuccessToast('history-delete');
                    loadHistoryStats();
                    loadHistoryCards(currentHistoryPage);
                }).fail(function () {
                    $btn.prop('disabled', false).html('🗑 删除');
                    alert('删除失败：网络错误，请重试');
                });
            });

            renderPagination(d.page, d.pages, d.total, loadHistoryCards);
        });
    }

    var historyCardCache = {};

    // ==================== 换图选择弹窗 ====================
    // 列出文章全部配图：每张可单独换，也可一键全换；换完就地刷新预览与卡片缩略图
    var pickerPostId = 0;

    function openImagePicker(id) {
        pickerPostId = id;
        var $modal = $('#yimai-gso-image-picker-modal');
        var $body = $('#yimai-gso-image-picker-body');
        $body.html('<div style="text-align:center;padding:30px;color:#94a3b8;"><span class="yimai-gso-loading"></span> 正在读取文章配图…</div>');
        $modal.fadeIn(150);

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_history_list_images',
            nonce: yimaiGsoData.nonce,
            post_id: id
        }, function (res) {
            if (!res.success) {
                $body.html('<p style="color:#ef4444;">' + escHtml((res.data && res.data.message) || '读取失败') + '</p>');
                return;
            }
            renderImagePicker(res.data);
        }).fail(function () {
            $body.html('<p style="color:#ef4444;">网络错误，请重试</p>');
        });
    }

    function renderImagePicker(data) {
        var $body = $('#yimai-gso-image-picker-body');
        var items = data.items || [];

        if (!items.length) {
            $body.html(
                '<div style="text-align:center;padding:24px;color:#64748b;">' +
                '<p>这篇文章正文没有配图。</p>' +
                '<button type="button" class="button button-primary yimai-gso-picker-change-all">生成一张配图并设为封面</button>' +
                '</div>'
            );
            return;
        }

        var html = '<p style="margin:0 0 14px;color:#64748b;font-size:13px;">共 ' + items.length + ' 张配图。点「换这张」单独更换该位置的图；第 1 张图会同步为文章封面。</p>';
        html += '<div class="yimai-gso-picker-grid">';
        items.forEach(function (it) {
            html += '<div class="yimai-gso-picker-item" data-index="' + it.index + '">' +
                '<div class="yimai-gso-picker-thumb">' +
                '<img src="' + escHtml(it.thumb) + '" alt="" loading="lazy">' +
                (it.is_cover ? '<span class="yimai-gso-picker-cover-tag">封面</span>' : '') +
                '</div>' +
                '<div class="yimai-gso-picker-item-info">' +
                '<span class="yimai-gso-picker-pos">第 ' + (it.index + 1) + ' 张</span>' +
                '<button type="button" class="button button-small yimai-gso-picker-change-one" data-index="' + it.index + '">🔄 换这张</button>' +
                '</div>' +
                '</div>';
        });
        html += '</div>';
        html += '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;">' +
            '<button type="button" class="button button-primary yimai-gso-picker-change-all">🔁 一键更换全部 ' + items.length + ' 张</button>' +
            '<span class="yimai-gso-picker-status" style="color:#64748b;font-size:12px;"></span>' +
            '</div>';
        $body.html(html);
    }

    function pickerApplyResults(res) {
        var data = res.data || {};
        var results = data.results || [];

        // 就地刷新选择弹窗内对应位置的预览图
        results.forEach(function (r) {
            var $item = $('.yimai-gso-picker-item[data-index="' + r.index + '"]');
            if ($item.length) {
                var sep = (r.thumb_url || r.image_url).indexOf('?') >= 0 ? '&' : '?';
                $item.find('.yimai-gso-picker-thumb img').attr('src', (r.thumb_url || r.image_url) + sep + 't=' + Date.now());
                if (r.index === 0) {
                    if (!$item.find('.yimai-gso-picker-cover-tag').length) {
                        $item.find('.yimai-gso-picker-thumb').append('<span class="yimai-gso-picker-cover-tag">封面</span>');
                    }
                }
            }
        });

        // 换了第 1 张 → 同步刷新内容管理卡片的封面缩略图与缓存
        var first = results.filter(function (r) { return r.index === 0; })[0];
        if (first && (first.thumb_url || first.image_url)) {
            var thumb = first.thumb_url || first.image_url;
            var sep = thumb.indexOf('?') >= 0 ? '&' : '?';
            var $thumb = $('.yimai-gso-article-card[data-id="' + pickerPostId + '"]').find('.yimai-gso-card-thumb img');
            if ($thumb.length) {
                $thumb.attr('src', thumb + sep + 't=' + Date.now());
            } else {
                $('.yimai-gso-article-card[data-id="' + pickerPostId + '"]').find('.yimai-gso-card-thumb').prepend('<img src="' + thumb + sep + 't=' + Date.now() + '" alt="">');
            }
            if (historyCardCache[pickerPostId]) historyCardCache[pickerPostId].thumbnail = thumb;
        }

        $('.yimai-gso-picker-status').css('color', '#059669').text('✅ ' + (data.message || '配图已更换'));
    }

    function pickerRequest(index, $btn, doneLabel) {
        var origLabel = $btn.html();
        $btn.prop('disabled', true).html('⏳ 处理中…');
        $('.yimai-gso-picker-status').css('color', '#64748b').text(index === 'all' ? '正在逐张更换全部配图，图片较多时请耐心等待…' : '正在更换所选配图…');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_history_change_image',
            nonce: yimaiGsoData.nonce,
            post_id: pickerPostId,
            index: index
        }, function (res) {
            $btn.prop('disabled', false).html(doneLabel || origLabel);
            if (!res.success) {
                $('.yimai-gso-picker-status').css('color', '#ef4444').text('❌ ' + ((res.data && res.data.message) || '未知错误'));
                return;
            }
            pickerApplyResults(res);
        }).fail(function () {
            $btn.prop('disabled', false).html(doneLabel || origLabel);
            $('.yimai-gso-picker-status').css('color', '#ef4444').text('❌ 网络错误，请重试');
        });
    }

    // 弹窗内按钮全部用委托（内容动态渲染）
    $(document)
        .off('click', '#yimai-gso-image-picker-modal .yimai-gso-modal-close, #yimai-gso-image-picker-modal .yimai-gso-modal-overlay')
        .on('click', '#yimai-gso-image-picker-modal .yimai-gso-modal-close, #yimai-gso-image-picker-modal .yimai-gso-modal-overlay', function () {
            $('#yimai-gso-image-picker-modal').fadeOut(150);
        })
        .off('click', '.yimai-gso-picker-change-one')
        .on('click', '.yimai-gso-picker-change-one', function () {
            var idx = $(this).data('index');
            pickerRequest(idx, $(this), '🔄 换这张');
        })
        .off('click', '.yimai-gso-picker-change-all')
        .on('click', '.yimai-gso-picker-change-all', function () {
            pickerRequest('all', $(this), '🔁 一键更换全部');
        });

    function showCardDetailModal(id) {
        var $modal = $('#yimai-gso-history-modal');
        var $body = $('#yimai-gso-modal-body');
        $body.html('<div style="text-align:center;padding:30px;color:#94a3b8;">加载中...</div>');
        $modal.fadeIn(150);

        // 尝试从缓存获取
        if (historyCardCache[id]) {
            renderDetailContent(historyCardCache[id]);
            return;
        }

        // 从DOM获取
        var $card = $('.yimai-gso-article-card[data-id="' + id + '"]');
        if ($card.length) {
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_history_query',
                nonce: yimaiGsoData.nonce,
                page: 1,
                per_page: 50
            }, function (res) {
                if (res.success && res.data.items) {
                    res.data.items.forEach(function (item) {
                        historyCardCache[item.id] = item;
                    });
                    if (historyCardCache[id]) {
                        renderDetailContent(historyCardCache[id]);
                    }
                }
            });
        }
    }

    // ==================== 初始化所有新页面 ====================
    $(document).ready(function () {
        // 根据当前激活的面板初始化对应功能
        var $activePanel = $('.yimai-gso-main-panel.active');
        var activeTab = $activePanel.data('tab') || 'dashboard';

        // 初始化当前激活面板
        if (activeTab === 'dashboard') {
            initDashboardPage();
        } else if (activeTab === 'generate') {
            initGeneratePage();
        } else if (activeTab === 'seo-meta') {
            initSeoMetaPage();
        } else if (activeTab === 'history') {
            loadHistoryStats();
            loadHistoryCards(1);
        }

        // 初始化内容管理功能
        initHistoryCards();

        restoreBatchPromptsFromStorage(false);

        // 初始化单篇生成页面
        initSinglePage();


        // 初始化AI摘要页面
        BatchExcerpt.init();

    });

    // ==================== 单篇生成页面 ====================
    var singleState = {
        isGenerating: false,
        generatedTitle: '',
        generatedContent: '',
        generatedExcerpt: '',
        keywords: '',
        pendingImagePrompts: []
    };

    function initSinglePage() {
        var $panel = $('.yimai-gso-main-panel[data-tab="single"]');
        if (!$panel.length) return;

        $(document)
            .off('click.yimaiGsoSingleGenerate', '#yimai-gso-single-generate-btn')
            .on('click.yimaiGsoSingleGenerate', '#yimai-gso-single-generate-btn', function (e) {
                e.preventDefault();

                if (singleState.isGenerating) return;

                var keyword = $.trim($('#yimai-gso-single-keyword').val());
                if (!keyword) {
                    alert('请输入关键词或主题！');
                    $('#yimai-gso-single-keyword').focus();
                    return;
                }

                singleState.isGenerating = true;
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="yimai-gso-loading"></span> AI正在生成文章...');

                $('#yimai-gso-single-progress').show();
                $('#yimai-gso-single-progress-text').text('🤖 AI 正在撰写文章...');

                var data = {
                    action: 'yimai_gso_single_generate',
                    nonce: yimaiGsoData.nonce,
                    keyword: keyword,
                    title: $.trim($('#yimai-gso-single-title').val()),
                    length: $('#yimai-gso-single-length').val(),
                    type: $('#yimai-gso-single-type').val(),
                    angle: $('#yimai-gso-single-angle').val(),
                    style: $.trim($('#yimai-gso-single-style').val()),
                    add_image: $('#yimai-gso-single-image-enabled').is(':checked'),
                    auto_excerpt: $('#yimai-gso-single-auto-excerpt').is(':checked'),
                    auto_seo: $('#yimai-gso-single-auto-seo').is(':checked')
                };

                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: data,
                    timeout: 600000, // 2.2.0：单篇生成最长等待10分钟（含AI生成+配图）
                    success: function (res) {
                        singleState.isGenerating = false;
                        $btn.prop('disabled', false).html('🪄 开始生成');
                        $('#yimai-gso-single-progress').hide();

                        if (res.success) {
                            singleState.generatedTitle = res.data.title;
                            singleState.generatedContent = res.data.content;
                            singleState.generatedExcerpt = res.data.excerpt || '';
                            singleState.keywords = res.data.keywords || '';
                            singleState.pendingImagePrompts = res.data.pending_image_prompts || [];

                            renderSingleResult(res.data);
                        } else {

                            alert('生成失败：' + (((res.data || {}).message) || '未知错误'));
                        }
                    },
                    error: function (xhr, status, err) {
                        singleState.isGenerating = false;
                        $btn.prop('disabled', false).html('🪄 开始生成');
                        $('#yimai-gso-single-progress').hide();
                        console.error('[YIMAI GSO] 单篇生成 AJAX 失败:', status, err, 'Response:', xhr.responseText);
                        var errMsg = (typeof formatAjaxErrorMessage === 'function')
                            ? formatAjaxErrorMessage(xhr, status, err)
                            : '网络错误，请重试';
                        alert(errMsg);
                    }
                });
            });

        $(document)
            .off('click.yimaiGsoSingleCopy', '#yimai-gso-single-copy-btn')
            .on('click.yimaiGsoSingleCopy', '#yimai-gso-single-copy-btn', function (e) {
                e.preventDefault();

                var content = singleState.generatedTitle + '\n\n' + singleState.generatedContent;
                copyToClipboard(content);
                $(this).text('已复制！');
                setTimeout(function () {
                    $('#yimai-gso-single-copy-btn').html('<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;margin:0;vertical-align:middle;"></span> 复制内容');
                }, 2000);
            });

        $(document)
            .off('click.yimaiGsoSinglePublish', '#yimai-gso-single-publish-btn')
            .on('click.yimaiGsoSinglePublish', '#yimai-gso-single-publish-btn', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var title = $.trim($('#yimai-gso-single-results .yimai-gso-rewrite-title-text').text()) || singleState.generatedTitle;
                var status = $('input[name="yimai-gso-single-status"]:checked').val();

                if (!title) {
                    alert('文章标题为空，请先生成文章！');
                    return;
                }

                $btn.prop('disabled', true).html('<span class="yimai-gso-loading"></span> 发布中...');

                $.ajax({
                    url: yimaiGsoData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'yimai_gso_single_publish',
                        nonce: yimaiGsoData.nonce,
                        title: title,
                        content: singleState.generatedContent,
                        excerpt: singleState.generatedExcerpt,
                        category: $('#yimai-gso-single-category').val(),
                        tags: $('#yimai-gso-single-tags').val(),
                        status: status,
                        keywords: singleState.keywords,
                        pending_image_prompts: JSON.stringify(singleState.pendingImagePrompts),
                        auto_excerpt: $('#yimai-gso-single-auto-excerpt').is(':checked'),
                        auto_seo: $('#yimai-gso-single-auto-seo').is(':checked')
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud-upload"></span> 发布到 WordPress');

                        if (res.success) {
                            $('#yimai-gso-single-publish-result').html(
                                '<div class="yimai-gso-success-box">' +
                                '<span class="dashicons dashicons-yes-alt"></span>' +
                                '<div>' +
                                '<h4>发布成功！</h4>' +
                                '<p><a href="' + res.data.post_url + '" target="_blank">查看文章</a> | ' +
                                '<a href="' + res.data.edit_url + '" target="_blank">编辑</a></p>' +
                                '</div>' +
                                '</div>'
                            ).fadeIn();
                        } else {
                            alert('发布失败：' + (((res.data || {}).message) || '未知错误'));
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud-upload"></span> 发布到 WordPress');
                        alert('网络错误，请重试');
                    }
                });
            });

        $(document)
            .off('click.yimaiGsoSingleRegenerate', '#yimai-gso-single-regenerate-btn')
            .on('click.yimaiGsoSingleRegenerate', '#yimai-gso-single-regenerate-btn', function (e) {
                e.preventDefault();
                $('#yimai-gso-single-title').val('');
                $('#yimai-gso-single-generate-btn').trigger('click');
            });

        // ★ HTML 预览标签切换
        $(document)
            .off('click.yimaiGsoPreviewTab', '.yimai-gso-preview-tab')
            .on('click.yimaiGsoPreviewTab', '.yimai-gso-preview-tab', function (e) {
                e.preventDefault();
                var mode = $(this).data('mode');
                $('#yimai-gso-single-preview-tabs .yimai-gso-preview-tab').removeClass('active');
                $(this).addClass('active');
                if (mode === 'html') {
                    $('#yimai-gso-single-preview-text').hide();
                    $('#yimai-gso-single-preview-html').show();
                } else {
                    $('#yimai-gso-single-preview-html').hide();
                    $('#yimai-gso-single-preview-text').show();
                }
            });
    }

    function renderSingleResult(data) {
        var $container = $('#yimai-gso-single-results').empty();

        // ── 内容有效性拦截：模型输出被截断/仍是 JSON 骨架时，不要把它当文章渲染 ──
        var rawContent = (data && data.content ? String(data.content) : '').trim();
        var looksLikeJson = /^\s*\{\s*$|^\s*\{\s*"/.test(rawContent) || /^\s*"(?:title|excerpt|content)"\s*:/.test(rawContent);
        if (!rawContent || looksLikeJson) {
            $container.append(
                '<div class="notice notice-error" style="margin:0;padding:12px 14px;">' +
                '<p style="margin:0 0 6px;"><strong>生成失败：模型返回的内容不完整</strong></p>' +
                '<p style="margin:0;color:#50575e;">模型在输出 JSON 的中途被截断，没有拿到正文。可以：① 直接重试一次；② 在「设置 → AI配置」换成支持长输出的模型（推荐 DeepSeek）；③ 把目标字数调小（如 800 字）再试。</p>' +
                (rawContent ? '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#2271b1;">查看模型原始返回（' + rawContent.length + ' 字符）</summary><pre style="white-space:pre-wrap;word-break:break-all;max-height:220px;overflow:auto;background:#f6f7f7;padding:10px;margin-top:6px;font-size:11px;">' + $('<div>').text(rawContent.substring(0, 2000)).html() + '</pre></details>' : '') +
                '</div>'
            );
            $('#yimai-gso-single-copy-btn').hide();
            return;
        }

        // 标题
        if (data.title) {
            var $titleBox = $('<div class="yimai-gso-rewrite-title-box">')
                .html('<span class="yimai-gso-rewrite-label">生成标题</span><div class="yimai-gso-rewrite-title-text">' + escapeHtml(data.title) + '</div>');
            $container.append($titleBox);
        }

        // 内容预览（修复：使用jQuery解析HTML，正确提取纯文本）
        var plainContent = $('<div>').html(data.content).text().replace(/\n{3,}/g, '\n\n').trim();
        var previewLength = 500;
        var isLong = plainContent.length > previewLength;
        var previewText = isLong ? plainContent.substring(0, previewLength) + '...' : plainContent;

        var $contentBox = $('<div class="yimai-gso-rewrite-content-box">')
            .html('<span class="yimai-gso-rewrite-label">生成内容</span><div class="yimai-gso-rewrite-preview">' + escapeHtml(previewText).replace(/\n/g, '<br>') + '</div>');

        if (isLong) {
            $contentBox.append('<button class="button button-small yimai-gso-single-toggle-full" style="margin-top:8px;">展开全文</button>');
        }

        $container.append($contentBox);

        // 字数统计（修复：使用jQuery解析HTML）
        $container.append('<div class="yimai-gso-single-stats">📝 ' + $('<div>').html(data.content).text().length + ' 字 | 🖼️ ' + (data.image_count || 0) + ' 张配图</div>');

        // 显示复制按钮
        $('#yimai-gso-single-copy-btn').show();

        // 显示发布区
        $('#yimai-gso-single-publish-area').slideDown(300);

        // ★ HTML 预览：将生成内容注入 iframe
        if (data.content) {
            var $iframe = $('#yimai-gso-single-preview-iframe');
            var previewHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' +
                'body{font-family:-apple-system,BlinkMacSystemFont,"Microsoft YaHei",sans-serif;font-size:15px;line-height:1.8;color:#333;padding:20px;max-width:720px;margin:0 auto;}' +
                'h2{font-size:20px;margin:28px 0 14px;padding-bottom:8px;border-bottom:1px solid #eee;}' +
                'h3{font-size:17px;margin:20px 0 10px;color:#555;}' +
                'p{margin:0 0 14px;}' +
                'ul,ol{margin:0 0 14px;padding-left:20px;}' +
                'li{margin-bottom:6px;}' +
                'img{max-width:100%;height:auto;border-radius:6px;margin:12px 0;}' +
                'strong{color:#1a1a1a;}' +
                '</style></head><body>' + data.content + '</body></html>';
            $iframe.attr('srcdoc', previewHtml);

            // ★ 填充文本预览 pane（纯文本全文）
            $('#yimai-gso-single-preview-text').html(
                '<div class="yimai-gso-rewrite-content-box">' +
                '<div class="yimai-gso-rewrite-preview">' + escapeHtml(plainContent).replace(/\n/g, '<br>') + '</div>' +
                '</div>'
            );
        }

        // 显示预览切换标签
        $('#yimai-gso-single-preview-tabs').show();

        // 展开/收起全文
        $container.off('click', '.yimai-gso-single-toggle-full').on('click', '.yimai-gso-single-toggle-full', function () {
            var $preview = $(this).siblings('.yimai-gso-rewrite-preview');
            if ($(this).text() === '展开全文') {
                $preview.html(escapeHtml(plainContent).replace(/\n/g, '<br>'));
                $(this).text('收起全文');
            } else {
                $preview.html(escapeHtml(previewText).replace(/\n/g, '<br>'));
                $(this).text('展开全文');
            }
        });
    }

})(jQuery);

// ===== SEO推送管理模块 =====
(function($) {
    'use strict';

    // 本地 escapeHtml（避免依赖主 IIFE 作用域）

    var SEOPush = {
        logPage: 1,

        init: function() {
            // 子标签切换
            $(document).off('click.spTab', '.yimai-gso-seo-push-tabs .yimai-gso-tab-modern').on('click.spTab', '.yimai-gso-seo-push-tabs .yimai-gso-tab-modern', function() {
                var tab = $(this).data('sp-tab');
                $('.yimai-gso-seo-push-tabs .yimai-gso-tab-modern').removeClass('active');
                $(this).addClass('active');
                $('.yimai-gso-seo-push-panel').removeClass('active');
                $('[data-sp-panel="' + tab + '"]').addClass('active');

                // 切到日志时自动加载
                if (tab === 'logs') {
                    SEOPush.logPage = 1;
                    SEOPush.loadLogs();
                }
            });

            // 测试连接
            $(document).off('click.spTest', '.yimai-gso-sp-test-btn').on('click.spTest', '.yimai-gso-sp-test-btn', function() {
                var platform = $(this).data('platform');
                SEOPush.testConnection(platform);
            });

            // 加载文章列表
            $(document).off('click.spLoad', '#yimai-gso-sp-load-posts').on('click.spLoad', '#yimai-gso-sp-load-posts', function() {
                SEOPush.loadPosts();
            });

            // 全选
            $(document).off('change.spCheckAll', '#yimai-gso-sp-check-all, #yimai-gso-sp-check-all-2').on('change.spCheckAll', '#yimai-gso-sp-check-all, #yimai-gso-sp-check-all-2', function() {
                var checked = $(this).prop('checked');
                $('#yimai-gso-sp-posts-tbody input[type="checkbox"]').prop('checked', checked);
                SEOPush.updateSelectedCount();
            });

            // 单选更新计数
            $(document).off('change.spCheck', '#yimai-gso-sp-posts-tbody input[type="checkbox"]').on('change.spCheck', '#yimai-gso-sp-posts-tbody input[type="checkbox"]', function() {
                SEOPush.updateSelectedCount();
            });

            // 批量推送
            $(document).off('click.spBatch', '#yimai-gso-sp-do-batch-push').on('click.spBatch', '#yimai-gso-sp-do-batch-push', function() {
                SEOPush.batchPush();
            });

            // 推送Sitemap
            $(document).off('click.spSitemap', '#yimai-gso-sp-push-sitemap').on('click.spSitemap', '#yimai-gso-sp-push-sitemap', function() {
                SEOPush.pushSitemap();
            });

            // 日志筛选
            $(document).off('change.spLogFilter', '#yimai-gso-sp-log-platform').on('change.spLogFilter', '#yimai-gso-sp-log-platform', function() {
                SEOPush.logPage = 1;
                SEOPush.loadLogs();
            });

            // 清空日志
            $(document).off('click.spClearLogs', '#yimai-gso-sp-clear-logs').on('click.spClearLogs', '#yimai-gso-sp-clear-logs', function() {
                if (!confirm('确定要清空所有推送日志吗？此操作不可恢复。')) return;
                SEOPush.clearLogs();
            });

            // 页面初始化：加载统计、日志（如果当前可见）
            if ($('[data-tab="seo-push"].active').length) {
                SEOPush.loadStats();
                SEOPush.loadLogs();
            }
            // 监听侧边栏切换到 SEO推送
            $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="seo-push"]', function() {
                setTimeout(function() {
                    SEOPush.loadStats();
                    SEOPush.loadLogs();
                }, 100);
            });

            // 功能可用性检查
            SEOPush.checkFeatureAvailable();

            // ★ AJAX 保存 SEO 推送配置（替代内联 POST，避免 Zibll 等主题干扰）
            $('#yimai-gso-seo-push-form').off('submit.ajaxSave').on('submit.ajaxSave', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $form.find('button[type="submit"]');
                $btn.prop('disabled', true).text('保存中...');
                $.post(yimaiGsoData.ajaxUrl, {
                    action: 'yimai_gso_save_seo_push_settings',
                    nonce: yimaiGsoData.nonce,
                    data: $form.serialize()
                }, function(res) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存推送配置');
                    if (res.success) {
                        alert('推送配置已保存');
                    } else {
                        alert('保存失败：' + (res.data && res.data.message ? res.data.message : '未知错误'));
                    }
                }, 'json').fail(function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存推送配置');
                    alert('网络请求失败，请重试');
                });
            });
        },

        checkFeatureAvailable: function() {
            var $root = $('#yimai-gso-seo-push-root');
            if (!$root.length) return;

            var available = String($root.data('available')) === '1';
            if (!available) {
                // 禁用所有交互元素（保留标签按钮可点击，只禁用功能按钮）
                $('.yimai-gso-seo-push-section input, .yimai-gso-seo-push-section select, .yimai-gso-seo-push-section button').not('.yimai-gso-seo-push-tab').prop('disabled', true);
                $('#yimai-gso-seo-push-form button[type="submit"]').prop('disabled', true);
                $('#yimai-gso-sp-load-posts').prop('disabled', true);
                $('#yimai-gso-sp-check-all, #yimai-gso-sp-check-all-2').prop('disabled', true);
                $('#yimai-gso-sp-do-batch-push').prop('disabled', true);
                $('#yimai-gso-sp-push-sitemap').prop('disabled', true);
                $('#yimai-gso-sp-clear-logs').prop('disabled', true);
            }
        },

        loadStats: function() {
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_get_stats',
                nonce: yimaiGsoData.seoNonce
            }, function(res) {
                if (res.success) {
                    var d = res.data;
                    $('#yimai-gso-sp-baidu-count').text(d.baidu_success || 0);
                    $('#yimai-gso-sp-bing-count').text(d.bing_success || 0);
                    $('#yimai-gso-sp-google-count').text(d.google_success || 0);
                    $('#yimai-gso-sp-success-rate').text((d.success_rate || 0) + '%');
                }
            }, 'json').fail(function() {
                // 静默失败
            });
        },

        testConnection: function(platform) {
            var $btn = $('.yimai-gso-sp-test-btn[data-platform="' + platform + '"]');
            var $result = $('#yimai-gso-sp-test-' + platform);
            $btn.prop('disabled', true).find('.dashicons').addClass('animate-spin');
            $result.html('<span style="color:#666">测试中...</span>');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_test_connection',
                nonce: yimaiGsoData.seoNonce,
                platform: platform
            }, function(res) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('animate-spin');
                if (res.success) {
                    var d = res.data;
                    if (d.success) {
                        $result.html('<span style="color:#4caf50;font-weight:600">' + esc(d.message) + '</span>');
                    } else {
                        $result.html('<span style="color:#e53935;font-weight:600">' + esc(d.message) + '</span>');
                    }
                } else {
                    $result.html('<span style="color:#e53935;font-weight:600">' + esc(res.data && res.data.message ? res.data.message : '验证失败') + '</span>');
                }
            }, 'json').fail(function(xhr) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('animate-spin');
                $result.html('<span style="color:#e53935;font-weight:600">请求失败，请检查网络连接</span>');
            });
        },

        loadPosts: function() {
            var $btn = $('#yimai-gso-sp-load-posts');
            var cat = $('#yimai-gso-sp-push-category').val();
            var perPage = $('#yimai-gso-sp-push-per-page').val();

            $btn.prop('disabled', true).find('.dashicons').addClass('animate-spin');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_push_get_posts',
                nonce: yimaiGsoData.seoNonce,
                category: cat,
                per_page: perPage
            }, function(res) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('animate-spin');
                if (res.success && res.data && res.data.posts) {
                    var posts = res.data.posts;
                    var $tbody = $('#yimai-gso-sp-posts-tbody');
                    $tbody.empty();

                    if (posts.length === 0) {
                        $tbody.append('<tr><td colspan="6" style="text-align:center;padding:40px;color:#999">没有找到已发布的文章</td></tr>');
                    } else {
                        posts.forEach(function(p) {
                            var pushed = p.pushed ? '<span style="color:#4caf50">已推送</span>' : '<span style="color:#999">未推送</span>';
                            $tbody.append(
                                '<tr>' +
                                '<td><input type="checkbox" class="yimai-gso-sp-post-cb" value="' + p.ID + '"></td>' +
                                '<td>' + p.ID + '</td>' +
                                '<td><a href="' + esc(p.permalink) + '" target="_blank">' + esc(p.post_title) + '</a></td>' +
                                '<td>' + esc(p.category || '-') + '</td>' +
                                '<td>' + esc(p.post_date) + '</td>' +
                                '<td>' + pushed + '</td>' +
                                '</tr>'
                            );
                        });
                    }
                    $('#yimai-gso-sp-posts-table-wrap').show();
                    SEOPush.updateSelectedCount();
                } else {
                    alert(res.data && res.data.message ? res.data.message : '加载失败');
                }
            }, 'json').fail(function(xhr) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('animate-spin');
                alert('请求失败，请检查网络连接');
            });
        },

        updateSelectedCount: function() {
            var count = $('#yimai-gso-sp-posts-tbody input[type="checkbox"]:checked').length;
            $('#yimai-gso-sp-selected-count').text('已选 ' + count + ' 篇');
            $('#yimai-gso-sp-do-batch-push').prop('disabled', count === 0);
        },

        batchPush: function() {
            var ids = [];
            $('#yimai-gso-sp-posts-tbody input[type="checkbox"]:checked').each(function() {
                ids.push($(this).val());
            });
            if (ids.length === 0) return;

            if (!confirm('确定要推送选中的 ' + ids.length + ' 篇文章到搜索引擎吗？')) return;

            var $btn = $('#yimai-gso-sp-do-batch-push');
            var $result = $('#yimai-gso-sp-push-result');
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update animate-spin" style="margin-top:4px"></span> 推送中...');
            $result.show().html('<p>正在推送 ' + ids.length + ' 篇文章，请稍候...</p>');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_push_batch',
                nonce: yimaiGsoData.seoNonce,
                post_ids: ids
            }, function(res) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-share-alt2" style="margin-top:4px"></span> 推送选中文章');
                if (res.success) {
                    var r = res.data.results;
                    var html = '<div class="notice notice-success is-dismissible"><p><strong>推送完成!</strong></p>' +
                        '<p>百度：成功 ' + (r.baidu.success || 0) + ' / 失败 ' + (r.baidu.fail || 0) + ' | ' + esc(r.baidu.msg) + '</p>' +
                        '<p>必应：成功 ' + (r.bing.success || 0) + ' / 失败 ' + (r.bing.fail || 0) + ' | ' + esc(r.bing.msg) + '</p>' +
                        '</div>';
                    $result.html(html);
                    SEOPush.loadStats();
                } else {
                    $result.html('<div class="notice notice-error"><p>' + esc(res.data && res.data.message ? res.data.message : '推送失败') + '</p></div>');
                }
            }, 'json').fail(function(xhr) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-share-alt2" style="margin-top:4px"></span> 推送选中文章');
                $result.html('<div class="notice notice-error"><p>请求失败，请检查网络连接</p></div>');
            });
        },

        pushSitemap: function() {
            var $btn = $('#yimai-gso-sp-push-sitemap');
            var $result = $('#yimai-gso-sp-sitemap-result');
            $btn.prop('disabled', true).find('.dashicons').addClass('animate-spin');
            $result.html('<p style="color:#666">正在推送 Sitemap...</p>');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_push_sitemap',
                nonce: yimaiGsoData.seoNonce,
                platforms: ['baidu', 'bing']
            }, function(res) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('animate-spin');
                if (res.success) {
                    var r = res.data.results;
                    var html = '<div class="notice notice-success is-dismissible"><p><strong>Sitemap推送完成</strong></p>';
                    if (r.baidu) html += '<p>百度：' + esc(r.baidu.msg) + (r.baidu.success ? ' ✅' : ' ❌') + '</p>';
                    if (r.bing) html += '<p>必应：' + esc(r.bing.msg) + '</p>';
                    html += '</div>';
                    $result.html(html);
                } else {
                    $result.html('<div class="notice notice-error"><p>' + esc(res.data && res.data.message ? res.data.message : '推送失败') + '</p></div>');
                }
            }, 'json').fail(function(xhr) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('animate-spin');
                $result.html('<div class="notice notice-error"><p>请求失败，请检查网络连接</p></div>');
            });
        },

        loadLogs: function() {
            var platform = $('#yimai-gso-sp-log-platform').val();
            var $tbody = $('#yimai-gso-sp-logs-tbody');
            $tbody.html('<tr><td colspan="8" style="text-align:center;padding:40px;color:#999">加载中...</td></tr>');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_push_get_logs',
                nonce: yimaiGsoData.seoNonce,
                page: SEOPush.logPage,
                platform: platform
            }, function(res) {
                if (res.success) {
                    var data = res.data;
                    $tbody.empty();

                    if (!data.logs || data.logs.length === 0) {
                        $tbody.html('<tr><td colspan="8" style="text-align:center;padding:40px;color:#999">暂无推送日志</td></tr>');
                    } else {
                        data.logs.forEach(function(log) {
                            var typeLabel = {auto: '自动', manual: '手动', batch: '批量', update: '更新'}[log.type] || log.type;
                            var statusIcon = function(val) {
                                return val > 0 ? '<span style="color:#4caf50">成功</span>' : '<span style="color:#ccc">-</span>';
                            };
                            var shortUrl = log.url && log.url.length > 60 ? log.url.substring(0, 60) + '...' : (log.url || '');
                            $tbody.append(
                                '<tr>' +
                                '<td>' + log.id + '</td>' +
                                '<td title="' + esc(log.url) + '">' + esc(shortUrl) + '</td>' +
                                '<td>' + esc(typeLabel) + '</td>' +
                                '<td>' + statusIcon(log.baidu_success) + '</td>' +
                                '<td>' + statusIcon(log.bing_success) + '</td>' +
                                '<td>' + statusIcon(log.google_success) + '</td>' +
                                '<td>' + esc(log.created_at) + '</td>' +
                                '</tr>'
                            );
                        });
                    }

                    // 分页：上一页 + 1..9 页码窗口 + 下一页
                    var $pager = $('#yimai-gso-sp-logs-pagination');
                    $pager.empty();
                    if (data.pages > 1) {
                        var current = parseInt(data.current_page) || 1;
                        var total = parseInt(data.pages) || 1;
                        var windowSize = 9;
                        var half = Math.floor(windowSize / 2);
                        var start = Math.max(1, current - half);
                        var end = Math.min(total, start + windowSize - 1);
                        if (end - start + 1 < windowSize) {
                            start = Math.max(1, end - windowSize + 1);
                        }

                        var html = '<span style="color:#666;margin-right:10px;">共 ' + data.total + ' 条</span>';
                        html += '<button type="button" class="button yimai-gso-sp-log-page-btn" data-page="' + Math.max(1, current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>« 上一页</button>';
                        for (var i = start; i <= end; i++) {
                            var cls = i === current ? 'button button-primary' : 'button';
                            html += '<button type="button" class="' + cls + ' yimai-gso-sp-log-page-btn" data-page="' + i + '">' + i + '</button>';
                        }
                        html += '<button type="button" class="button yimai-gso-sp-log-page-btn" data-page="' + Math.min(total, current + 1) + '"' + (current >= total ? ' disabled' : '') + '>下一页 »</button>';
                        $pager.html(html);
                    }
                }
            }, 'json').fail(function() {
                $tbody.html('<tr><td colspan="8" style="text-align:center;padding:40px;color:#e53935">加载失败，请刷新页面重试</td></tr>');
            });
        },

        clearLogs: function() {
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_push_clear_logs',
                nonce: yimaiGsoData.seoNonce
            }, function(res) {
                if (res.success) {
                    SEOPush.logPage = 1;
                    SEOPush.loadLogs();
                    SEOPush.loadStats();
                }
            }, 'json');
        }
    };

    $(document).ready(function() {
        SEOPush.init();

        // 日志分页
        $(document).off('click.spLogPage', '.yimai-gso-sp-log-page-btn').on('click.spLogPage', '.yimai-gso-sp-log-page-btn', function() {
            if ($(this).prop('disabled')) return;
            SEOPush.logPage = parseInt($(this).data('page'));
            SEOPush.loadLogs();
        });
    });

})(jQuery);

// ==================== 结构化数据面板 ====================
(function($) {

    var SchemaPanel = {
        init: function() {
            var $root = $('#yimai-gso-schema-root');
            if (!$root.length) return;
            var available = String($root.data('available')) === '1';
            if (!available) {
                $('#yimai-gso-schema-root input, #yimai-gso-schema-root select, #yimai-gso-schema-root button').prop('disabled', true);
                return;
            }
            this.loadStats();

            $('#yimai-gso-schema-save-btn').off('click.schemaSave').on('click.schemaSave', function() {
                SchemaPanel.saveSettings();
            });

            $('#yimai-gso-schema-rebuild-btn').off('click.schemaRebuild').on('click.schemaRebuild', function() {
                SchemaPanel.rebuildSchemas();
            });
        },

        loadStats: function() {
            $('#yimai-gso-schema-total, #yimai-gso-schema-article, #yimai-gso-schema-faq, #yimai-gso-schema-howto, #yimai-gso-schema-product').text('--');
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_schema_get_stats',
                nonce: yimaiGsoData.nonce
            }, function(res) {
                if (res.success && res.data) {
                    var d = res.data;
                    $('#yimai-gso-schema-total').text(d.total || 0);
                    $('#yimai-gso-schema-article').text(d.article || 0);
                    $('#yimai-gso-schema-faq').text(d.faq || 0);
                    $('#yimai-gso-schema-howto').text(d.howto || 0);
                    $('#yimai-gso-schema-product').text(d.product || 0);
                }
            }, 'json').fail(function() {});
        },

        saveSettings: function() {
            var types = [];
            $('.yimai-gso-schema-toggle input[type="checkbox"]:checked').each(function() {
                types.push($(this).val());
            });
            var $btn = $('#yimai-gso-schema-save-btn');
            $btn.prop('disabled', true).text('保存中...');
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_schema_save_settings',
                nonce: yimaiGsoData.nonce,
                schema_enabled_types: types
            }, function(res) {
                $btn.prop('disabled', false).text('保存设置');
                alert(res.success ? '设置已保存' : (res.data && res.data.message ? res.data.message : '保存失败'));
            }, 'json').fail(function() {
                $btn.prop('disabled', false).text('保存设置');
                alert('网络请求失败，请重试');
            });
        },

        rebuildSchemas: function() {
            if (!confirm('确定要重新检测所有文章的 Schema 类型吗？此操作不影响文章内容。')) return;
            var $btn = $('#yimai-gso-schema-rebuild-btn');
            var $result = $('#yimai-gso-schema-rebuild-result');
            $btn.prop('disabled', true).text('检测中...');
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_schema_rebuild',
                nonce: yimaiGsoData.nonce
            }, function(res) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;margin:0 4px 0 0;vertical-align:middle;"></span>重新检测所有文章 Schema 类型');
                if (res.success) {
                    var d = res.data;
                    $result.html('<div class="notice notice-success"><p>已完成！更新了 ' + esc(d.updated) + ' 篇文章：Article ' + esc(d.article) + ' 篇、FAQ ' + esc(d.faq) + ' 篇、HowTo ' + esc(d.howto) + ' 篇</p></div>');
                    SchemaPanel.loadStats();
                } else {
                    $result.html('<div class="notice notice-error"><p>' + esc(res.data || '操作失败') + '</p></div>');
                }
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;margin:0 4px 0 0;vertical-align:middle;"></span>重新检测所有文章 Schema 类型');
            });
        }
    };

    window.SchemaPanel = SchemaPanel;
    $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="schema"]', function() {
        setTimeout(function() { SchemaPanel.init(); }, 100);
    });
})(jQuery);

// ==================== SEO 评分面板 ====================
(function($) {

    var SeoScorerPanel = {
        page: 1,
        init: function() {
            var $root = $('#yimai-gso-seo-scorer-root');
            if (!$root.length) return;
            var available = String($root.data('available')) === '1';
            if (!available) {
                $('#yimai-gso-seo-scorer-root input, #yimai-gso-seo-scorer-root select, #yimai-gso-seo-scorer-root button').prop('disabled', true);
                return;
            }
            $('#yimai-gso-ss-search-btn').off('click.ssSearch').on('click.ssSearch', function() {
                SeoScorerPanel.page = 1; SeoScorerPanel.loadPosts();
            });
            $('#yimai-gso-ss-search').off('keydown.ssEnter').on('keydown.ssEnter', function(e) {
                if (e.which === 13) { SeoScorerPanel.page = 1; SeoScorerPanel.loadPosts(); }
            });
            $('#yimai-gso-ss-check-all').off('change.ssCheckAll').on('change.ssCheckAll', function() {
                $('.yimai-gso-ss-check').prop('checked', this.checked);
            });
            $('#yimai-gso-ss-batch-analyze-btn').off('click.ssBatch').on('click.ssBatch', function() {
                SeoScorerPanel.batchAnalyze();
            });
            $('#yimai-gso-ss-detail-close, .yimai-gso-ss-detail-overlay').off('click.ssClose').on('click.ssClose', function() {
                $('#yimai-gso-ss-detail-modal').hide();
            });
            this.loadPosts();
        },

        loadPosts: function() {
            var self = this;
            var $tbody = $('#yimai-gso-ss-tbody');
            $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>加载中...</p></div></td></tr>');
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_seo_score_batch_scan',
                nonce: yimaiGsoData.nonce,
                search: $('#yimai-gso-ss-search').val(),
                category: $('#yimai-gso-ss-category').val(),
                status: $('#yimai-gso-ss-status').val(),
                page: self.page,
                per_page: 20
            }, function(res) {
                if (!res.success) { $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>' + esc(res.data || '加载失败') + '</p></div></td></tr>'); return; }
                var items = res.data.items;
                if (!items || items.length === 0) { $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>未找到文章</p></div></td></tr>'); return; }
                var html = '';
                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    var scoreHtml = item.has_score ? '<strong style="color:' + (item.grade ? item.grade.color : '#666') + '">' + esc(item.overall) + '</strong>' : '<span style="color:#94a3b8;">未评分</span>';
                    var gradeHtml = item.grade ? '<span class="yimai-gso-grade-badge" style="background:' + esc(item.grade.color) + '">' + esc(item.grade.letter) + '</span>' : '';
                    var geoScoreHtml = (item.geo_overall != null)
                        ? '<strong style="color:' + (item.geo_grade ? esc(item.geo_grade.color) : '#7c3aed') + '">' + esc(item.geo_overall) + '</strong>'
                        : '<span style="color:#94a3b8;">-</span>';
                    html += '<tr><td><input type="checkbox" class="yimai-gso-ss-check" value="' + esc(item.post_id) + '"></td>'
                        + '<td><a href="post.php?post=' + esc(item.post_id) + '&action=edit" target="_blank">' + esc(item.post_title) + '</a></td>'
                        + '<td>' + esc((item.post_date || '').substring(0, 10)) + '</td>'
                        + '<td>' + scoreHtml + '</td><td>' + gradeHtml + '</td>'
                        + '<td>' + geoScoreHtml + '</td>'
                        + '<td><button class="button button-small yimai-gso-ss-analyze-btn" data-id="' + esc(item.post_id) + '">SEO</button> '
                        + '<button class="button button-small yimai-gso-geo-analyze-btn" data-id="' + esc(item.post_id) + '" style="color:#7c3aed;border-color:#7c3aed;">GEO</button></td></tr>';
                }
                $tbody.html(html);
                self.renderPagination(res.data);
            }, 'json').fail(function() { $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>请求失败</p></div></td></tr>'); });
        },

        renderPagination: function(data) {
            var $pag = $('#yimai-gso-ss-pagination');
            if (data.pages <= 1) { $pag.hide(); return; }
            var html = '';
            html += '<button class="button yimai-gso-ss-page-btn" data-page="' + Math.max(1, data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>« 上一页</button> ';
            html += '<span style="padding:4px 12px;font-weight:600;color:#374151;">' + data.page + ' / ' + data.pages + '</span> ';
            html += '<button class="button yimai-gso-ss-page-btn" data-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? ' disabled' : '') + '>下一页 »</button> ';
            $pag.html(html).show();
        },

        batchAnalyze: function() {
            var ids = [];
            $('.yimai-gso-ss-check:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('请先勾选需要评分的文章'); return; }
            var $btn = $('#yimai-gso-ss-batch-analyze-btn');
            var $result = $('#yimai-gso-ss-batch-result');
            $btn.prop('disabled', true).text('评分中（' + ids.length + '篇）...');
            $.post(yimaiGsoData.ajaxUrl, { action: 'yimai_gso_seo_score_batch_analyze', nonce: yimaiGsoData.nonce, post_ids: ids }, function(res) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-chart-bar" style="font-size:16px;width:16px;height:16px;margin:0 4px 0 0;vertical-align:middle;"></span>批量评分所选文章（SEO+GEO）');
                if (res.success) {
                    var msg = 'SEO ' + esc(res.data.success) + ' 篇' + (res.data.failed > 0 ? '（失败 ' + esc(res.data.failed) + '）' : '') + ' + GEO ' + esc(res.data.geo_success || 0) + ' 篇' + (res.data.geo_skipped > 0 ? '（跳过 ' + esc(res.data.geo_skipped) + '）' : '');
                    $result.html('<div class="notice notice-success"><p>完成！' + msg + '</p></div>');
                    SeoScorerPanel.loadPosts();
                }
                else { $result.html('<div class="notice notice-error"><p>' + esc(res.data || '失败') + '</p></div>'); }
            }, 'json').fail(function() { $btn.prop('disabled', false).html('<span class="dashicons dashicons-chart-bar" style="font-size:16px;width:16px;height:16px;margin:0 4px 0 0;vertical-align:middle;"></span>批量评分所选文章（SEO+GEO）'); });
        },

        showDetail: function(postId) {
            var $body = $('#yimai-gso-ss-detail-body');
            $body.html('<div class="yimai-gso-empty"><p>分析中...</p></div>');
            $('#yimai-gso-ss-detail-modal').show();
            $.post(yimaiGsoData.ajaxUrl, { action: 'yimai_gso_seo_score_analyze', nonce: yimaiGsoData.nonce, post_id: postId }, function(res) {
                if (!res.success) { $body.html('<div class="notice notice-error"><p>' + esc(res.data || '分析失败') + '</p></div>'); return; }
                var d = res.data, g = d.grade || {};
                var levelColors = { good: '#22c55e', ok: '#3b82f6', warn: '#f59e0b', bad: '#ef4444' };
                var dims = [
                    { key: 'title_compliance', label: '标题合规', icon: 'dashicons-edit' },
                    { key: 'keyword_density', label: '关键词密度', icon: 'dashicons-search' },
                    { key: 'meta_description', label: 'Meta描述', icon: 'dashicons-editor-paragraph' },
                    { key: 'content_depth', label: '内容深度', icon: 'dashicons-media-text' },
                    { key: 'image_optimization', label: '图片优化', icon: 'dashicons-format-image' },
                    { key: 'internal_links', label: '内链建设', icon: 'dashicons-admin-links' },
                    { key: 'heading_structure', label: '标题结构', icon: 'dashicons-heading' },
                    { key: 'readability', label: '可读性', icon: 'dashicons-visibility' },
                    { key: 'external_links', label: '出站链接', icon: 'dashicons-external' }
                ];
                var html = '<div style="text-align:center;margin-bottom:24px;"><div class="yimai-gso-ss-score-ring" style="background:' + esc(g.color || '#94a3b8') + ';">' + esc(d.overall) + '</div>'
                    + '<div style="font-size:16px;font-weight:600;color:' + esc(g.color || '#333') + ';">' + esc(g.label || '') + ' (' + esc(g.letter || '') + ')</div></div>';
                var scores = d.scores || {};
                for (var i = 0; i < dims.length; i++) {
                    var dim = dims[i], s = scores[dim.key] || {}, sv = s.score || 0, bc = levelColors[s.level] || '#94a3b8';
                    html += '<div class="yimai-gso-ss-dim-row"><div class="yimai-gso-ss-dim-icon"><span class="dashicons ' + dim.icon + '"></span></div>'
                        + '<div style="flex:1;"><div style="display:flex;justify-content:space-between;align-items:center;">'
                        + '<span class="yimai-gso-ss-dim-label">' + dim.label + '</span>'
                        + '<span class="yimai-gso-ss-dim-score" style="color:' + bc + ';">' + sv + '</span></div>'
                        + '<div class="yimai-gso-ss-dim-bar"><div class="yimai-gso-ss-dim-bar-fill" style="width:' + sv + '%;background:' + bc + ';"></div></div>';
                    if (s.tips && s.tips.length > 0) {
                        html += '<div class="yimai-gso-ss-dim-tips"><ul style="margin:0;padding-left:16px;">';
                        for (var t = 0; t < s.tips.length; t++) html += '<li>' + esc(s.tips[t]) + '</li>';
                        html += '</ul></div>';
                    }
                    html += '</div></div>';
                }

                // GEO 答案前置检测
                if (d.geo && d.geo.answer_first) {
                    var af = d.geo.answer_first, afScore = af.score || 0, afLevel = af.level || 'ok';
                    var afColors = { good: '#7c3aed', ok: '#8b5cf6', warn: '#f59e0b', bad: '#ef4444' };
                    var afLabels = { good: '优秀·AI高可提取', ok: '良好·基本符合', warn: '一般·建议优化', bad: '较差·需优先优化' };
                    html += '<div style="margin-top:20px;padding-top:16px;border-top:2px solid #7c3aed;">'
                        + '<div style="display:flex;align-items:center;margin-bottom:12px;">'
                        + '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#7c3aed;margin-right:8px;"></span>'
                        + '<span style="font-weight:700;font-size:14px;color:#7c3aed;">GEO 答案前置检测</span>'
                        + '<span style="margin-left:auto;font-size:22px;font-weight:700;color:' + (afColors[afLevel] || '#94a3b8') + ';">' + afScore + '</span>'
                        + '</div>'
                        + '<div style="font-size:12px;color:#6b7280;margin-bottom:8px;">' + (afLabels[afLevel] || '') + '</div>'
                        + '<div class="yimai-gso-ss-dim-bar" style="margin-bottom:6px;"><div class="yimai-gso-ss-dim-bar-fill" style="width:' + afScore + '%;background:' + (afColors[afLevel] || '#8b5cf6') + ';"></div></div>';
                    if (af.tips && af.tips.length > 0) {
                        html += '<div class="yimai-gso-ss-dim-tips"><ul style="margin:0;padding-left:16px;">';
                        for (var t = 0; t < af.tips.length; t++) html += '<li style="font-size:12px;color:#7c3aed;">' + esc(af.tips[t]) + '</li>';
                        html += '</ul></div>';
                    }
                    html += '</div>';
                }

                $('#yimai-gso-ss-detail-title').text(d.post_title || 'SEO评分详情');
                $('#yimai-gso-ss-detail-body').html(html);
            }, 'json');
        }
    };

    $(document).on('click', '.yimai-gso-ss-analyze-btn', function() { SeoScorerPanel.showDetail($(this).data('id')); });
    $(document).on('click', '.yimai-gso-ss-page-btn', function() { SeoScorerPanel.page = parseInt($(this).data('page')); SeoScorerPanel.loadPosts(); });
    $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="seo-score"]', function() { setTimeout(function() { SeoScorerPanel.init(); }, 100); });
    window.SeoScorerPanel = SeoScorerPanel;
})(jQuery);

// ==================== GEO 评分弹窗（第4层） ====================
(function($) {

    var GeoScorerPanel = {
        showDetail: function(postId) {
            var $body = $('#yimai-gso-geo-detail-body');
            $body.html('<div class="yimai-gso-empty"><p>分析中...</p></div>');
            $('#yimai-gso-geo-detail-modal').show();

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_dual_score',
                security: yimaiGsoData.ajaxNonce || yimaiGsoData.seoNonce || yimaiGsoData.nonce,
                post_id: postId
            }, function(res) {
                if (!res.success || !res.data || !res.data.geo) {
                    $body.html('<div class="notice notice-error"><p>获取GEO评分失败</p></div>');
                    return;
                }
                var g = res.data.geo;
                var geoGrade = g.grade || {};
                var dims = g.dimensions || {};

                var html = '';

                // 总分圆环
                html += '<div style="text-align:center;margin-bottom:20px;">'
                    + '<div style="display:inline-block;width:80px;height:80px;line-height:80px;border-radius:50%;background:' + esc(geoGrade.color || '#7c3aed') + ';color:#fff;font-size:28px;font-weight:800;">' + esc(g.overall) + '</div>'
                    + '<div style="margin-top:8px;font-size:15px;font-weight:600;color:' + esc(geoGrade.color || '#7c3aed') + ';">' + esc(geoGrade.label || '') + ' (' + esc(geoGrade.letter || '') + ')</div>'
                    + '</div>';

                // 5 维度详情
                var dimColors = { 80: '#22c55e', 60: '#3b82f6', 40: '#f59e0b', 0: '#ef4444' };
                for (var k in dims) {
                    if (!dims.hasOwnProperty(k)) continue;
                    var d = dims[k];
                    var ds = d.score || 0;
                    var dc = ds >= 80 ? '#22c55e' : (ds >= 60 ? '#3b82f6' : (ds >= 40 ? '#f59e0b' : '#ef4444'));
                    var pct = d.weighted || 0;

                    html += '<div style="margin-bottom:16px;padding:10px;background:#fafbfc;border-radius:6px;">'
                        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
                        + '<span style="font-weight:600;font-size:13px;">' + esc(d.label || k) + '</span>'
                        + '<span style="font-size:18px;font-weight:700;color:' + dc + ';">' + ds + '</span>'
                        + '</div>'
                        + '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">'
                        + '<div style="height:100%;background:' + dc + ';width:' + pct + '%;border-radius:3px;transition:width .3s;"></div>'
                        + '</div>';
                    if (d.detail) {
                        html += '<div style="font-size:11px;color:#6b7280;margin-top:4px;">' + esc(d.detail) + '</div>';
                    }
                    if (d.tips && d.tips.length > 0) {
                        html += '<ul style="margin:6px 0 0 14px;font-size:11px;color:#b45309;">';
                        for (var t = 0; t < d.tips.length; t++) html += '<li>' + esc(d.tips[t]) + '</li>';
                        html += '</ul>';
                    }
                    html += '</div>';
                }

                // 综述建议
                if (g.tips && g.tips.length > 0) {
                    html += '<div style="margin-top:16px;padding:12px;background:#fefce8;border:1px solid #fde68a;border-radius:6px;">'
                        + '<div style="font-weight:700;font-size:12px;color:#92400e;margin-bottom:6px;">优化建议</div>'
                        + '<ul style="margin:0;padding-left:16px;font-size:12px;color:#92400e;">';
                    for (var t = 0; t < g.tips.length; t++) html += '<li>' + esc(g.tips[t]) + '</li>';
                    html += '</ul></div>';
                }

                // 协同建议按钮
                html += '<div style="margin-top:16px;text-align:center;">'
                    + '<button type="button" class="button" id="yimai-gso-geo-synergy-btn" data-post-id="' + postId + '" style="color:#7c3aed;border-color:#7c3aed;">AI 协同建议</button>'
                    + '<div id="yimai-gso-geo-synergy-result" style="margin-top:10px;"></div>'
                    + '</div>';

                // 底部信息
                html += '<div style="margin-top:12px;font-size:11px;color:#9ca3af;text-align:center;">'
                    + (g.computed_at ? '评分时间：' + esc(g.computed_at) : '')
                    + '</div>';

                $('#yimai-gso-geo-detail-title').text('GEO 评分详情');
                $body.html(html);

                // 绑定协同建议按钮
                $('#yimai-gso-geo-synergy-btn').on('click', function() {
                    var $btn = $(this), $result = $('#yimai-gso-geo-synergy-result');
                    $btn.prop('disabled', true).text('生成中...');
                    $result.html('<span class="spinner is-active" style="float:none;margin:0;"></span>');

                    $.post(yimaiGsoData.ajaxUrl, {
                        action: 'yimai_gso_synergy_suggest',
                        security: yimaiGsoData.ajaxNonce || yimaiGsoData.seoNonce || yimaiGsoData.nonce,
                        post_id: postId
                    }, function(sr) {
                        $btn.prop('disabled', false).text('AI 协同建议');
                        if (sr.success && sr.data && sr.data.suggestions) {
                            var sh = '<div style="background:#f5f3ff;padding:10px;border-radius:6px;text-align:left;font-size:12px;">'
                                + '<div style="font-size:10px;color:#9ca3af;margin-bottom:4px;">来源:' + (sr.data.source === 'ai' ? 'AI 分析' : '规则引擎') + '</div>'
                                + '<ul style="margin:0;padding-left:16px;color:#374151;">';
                            for (var i = 0; i < sr.data.suggestions.length; i++) {
                                sh += '<li style="margin-bottom:4px;">' + esc(sr.data.suggestions[i]) + '</li>';
                            }
                            sh += '</ul></div>';
                            $result.html(sh);
                        } else {
                            $result.html('<div style="color:#ef4444;font-size:12px;">获取建议失败</div>');
                        }
                    }, 'json').fail(function() {
                        $btn.prop('disabled', false).text('AI 协同建议');
                        $result.html('<div style="color:#ef4444;font-size:12px;">网络错误</div>');
                    });
                });
            }, 'json').fail(function() {
                $body.html('<div class="notice notice-error"><p>网络错误，请重试</p></div>');
            });
        }
    };

    // 关闭 GEO 弹窗（命名空间事件）
    $('#yimai-gso-geo-detail-close').off('click.geoClose').on('click.geoClose', function() {
        $('#yimai-gso-geo-detail-modal').hide();
    });
    $('#yimai-gso-geo-detail-modal').off('click.geoOverlay').on('click.geoOverlay', '.yimai-gso-geo-detail-overlay', function() {
        $('#yimai-gso-geo-detail-modal').hide();
    });

    // GEO 按钮点击
    $(document).on('click', '.yimai-gso-geo-analyze-btn', function() {
        GeoScorerPanel.showDetail($(this).data('id'));
    });

    window.GeoScorerPanel = GeoScorerPanel;
})(jQuery);

// ==================== 标题结构分析面板 ====================
(function($) {

    var HeadingPanel = {
        init: function() {
            var $root = $('#yimai-gso-heading-root');
            if (!$root.length) return;
            var available = String($root.data('available')) === '1';
            if (!available) {
                $('#yimai-gso-heading-root input, #yimai-gso-heading-root select, #yimai-gso-heading-root button').prop('disabled', true);
                return;
            }
            $('#yimai-gso-ha-search-btn').off('click.haSearch').on('click.haSearch', function() { HeadingPanel.loadPosts(); });
            $('#yimai-gso-ha-search').off('keydown.haEnter').on('keydown.haEnter', function(e) { if (e.which === 13) HeadingPanel.loadPosts(); });
            // 批量分析按钮
            $('#yimai-gso-ha-batch-btn').off('click.haBatch').on('click.haBatch', function() { HeadingPanel.batchAnalyze(); });
            // 全选/取消全选
            $('#yimai-gso-ha-select-all').off('change.haSelectAll').on('change.haSelectAll', function() {
                var checked = this.checked;
                $('.yimai-gso-ha-row-check').prop('checked', checked);
            });
            this.loadPosts();
        },

        loadPosts: function() {
            var $tbody = $('#yimai-gso-ha-tbody');
            $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>加载中...</p></div></td></tr>');
            // 重置全选复选框
            $('#yimai-gso-ha-select-all').prop('checked', false);
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_search_posts', nonce: yimaiGsoData.nonce,
                search: $('#yimai-gso-ha-search').val(), category: $('#yimai-gso-ha-category').val(), per_page: 20
            }, function(res) {
                if (!res.success || !res.data || !res.data.posts || res.data.posts.length === 0) { $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>未找到文章</p></div></td></tr>'); return; }
                var html = '';
                var posts = res.data.posts;
                for (var i = 0; i < posts.length; i++) {
                    var p = posts[i];
                    var pId = p.id || p.ID || '';
                    var pTitle = p.title || p.post_title || '';
                    // 从缓存中读取评分/H2数/关键词覆盖（持久化显示）
                    var scoreHtml = '--', scoreStyle = 'color:#94a3b8;';
                    var h2Html = '--', h2Style = 'color:#94a3b8;';
                    var kwHtml = '--', kwStyle = 'color:#94a3b8;';
                    if (p.ha_score !== null && p.ha_score !== undefined) {
                        var sc = p.ha_score;
                        var scColor = sc >= 70 ? '#22c55e' : (sc >= 50 ? '#f59e0b' : '#ef4444');
                        scoreHtml = '<strong>' + sc + '</strong>';
                        scoreStyle = 'color:' + scColor + ';';
                    }
                    if (p.ha_h2_count !== null && p.ha_h2_count !== undefined) {
                        h2Html = p.ha_h2_count;
                        h2Style = ''; // 正常文字色
                    }
                    if (p.ha_kw_rate !== null && p.ha_kw_rate !== undefined) {
                        kwHtml = p.ha_kw_rate + '%';
                        kwStyle = ''; // 正常文字色
                    }
                    html += '<tr>'
                        + '<td style="text-align:center;"><input type="checkbox" class="yimai-gso-ha-row-check" value="' + esc(pId) + '"></td>'
                        + '<td><a href="post.php?post=' + esc(pId) + '&action=edit" target="_blank">' + esc(pTitle) + '</a></td>'
                        + '<td style="' + scoreStyle + 'text-align:center;">' + scoreHtml + '</td>'
                        + '<td style="' + h2Style + 'text-align:center;">' + h2Html + '</td>'
                        + '<td style="' + kwStyle + 'text-align:center;">' + kwHtml + '</td>'
                        + '<td class="yimai-gso-ha-opt-status" style="color:#94a3b8;text-align:center;font-size:12px;" data-pid="' + esc(pId) + '">--</td>'
                        + '<td style="text-align:center;"><button class="button button-small yimai-gso-ha-analyze-btn" data-id="' + esc(pId) + '">分析</button>&nbsp;'
                        + '<button class="button button-small yimai-gso-ha-optimize-btn" data-id="' + esc(pId) + '">AI优化建议</button></td>'
                        + '</tr>';
                }
                $tbody.html(html);

                // 批量检查优化状态
                var ids = [];
                posts.forEach(function(p) { ids.push(p.id || p.ID || 0); });
                ids = ids.filter(function(v) { return v > 0; });
                if (ids.length > 0) {
                    $.post(yimaiGsoData.ajaxUrl, {
                        action: 'yimai_gso_heading_check_optimized', nonce: yimaiGsoData.nonce,
                        post_ids: ids
                    }, function(res) {
                        if (res.success && res.data) {
                            for (var pid in res.data) {
                                if (res.data[pid]) {
                                    $('.yimai-gso-ha-opt-status[data-pid="' + pid + '"]').html('<span style="color:#22c55e;font-size:12px;">已优化</span>');
                                }
                            }
                        }
                    }, 'json');
                }
            }, 'json').fail(function() { $tbody.html('<tr><td colspan="7"><div class="yimai-gso-empty"><p>请求失败</p></div></td></tr>'); });
        },

        analyze: function(postId) {
            var $result = $('#yimai-gso-ha-result');
            $result.show().html('<div class="yimai-gso-empty"><p>分析中...</p></div>');
            $.post(yimaiGsoData.ajaxUrl, { action: 'yimai_gso_heading_analyze', nonce: yimaiGsoData.nonce, post_id: postId }, function(res) {
                if (!res.success) { $result.html('<div class="notice notice-error"><p>' + esc(res.data || '分析失败') + '</p></div>'); return; }
                HeadingPanel.renderResult(res.data);
                var $row = $('.yimai-gso-ha-analyze-btn[data-id="' + postId + '"]').closest('tr');
                if ($row.length) {
                    var d = res.data, sc = d.score >= 70 ? '#22c55e' : (d.score >= 50 ? '#f59e0b' : '#ef4444');
                    $row.find('td:eq(2)').html('<strong style="color:' + sc + ';">' + esc(d.score) + '</strong>');
                    $row.find('td:eq(3)').css('color', '').text(d.h2_structure ? d.h2_structure.count : '-');
                    $row.find('td:eq(4)').css('color', '').text(d.kw_coverage ? d.kw_coverage.rate + '%' : '-');
                    // 优化状态列 - 从后端持久化状态判断
                    $.post(yimaiGsoData.ajaxUrl, {
                        action: 'yimai_gso_heading_check_optimized', nonce: yimaiGsoData.nonce,
                        post_ids: [postId]
                    }, function(optRes) {
                        if (optRes.success && optRes.data && optRes.data[postId]) {
                            $row.find('td:eq(5)').html('<span style="color:#22c55e;font-size:12px;">已优化</span>');
                        } else {
                            $row.find('td:eq(5)').html('<span style="color:#f59e0b;font-size:12px;">待优化</span>');
                        }
                    }, 'json');
                }
            }, 'json');
        },

        batchAnalyze: function() {
            var ids = [];
            $('.yimai-gso-ha-row-check:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('请先勾选需要分析的文章'); return; }
            var $btn = $('#yimai-gso-ha-batch-btn');
            var total = ids.length;
            $btn.prop('disabled', true).text('分析中（0/' + total + '）...');
            // 串行逐篇分析，避免服务器超时
            var results = [];
            var self = this;
            function processNext(index) {
                if (index >= total) {
                    // 全部完成 — 刷新表格 + 更新按钮
                    $btn.prop('disabled', false).text('批量分析所选');
                    self.loadPosts();
                    var successCount = 0;
                    for (var r = 0; r < results.length; r++) { if (results[r].ok) successCount++; }
                    var failCount = total - successCount;
                    var msg = '批量分析完成！成功 ' + successCount + ' 篇';
                    if (failCount > 0) msg += '，失败 ' + failCount + ' 篇';
                    alert(msg);
                    return;
                }
                var pid = ids[index];
                $btn.text('分析中（' + (index + 1) + '/' + total + '）...');
                $.post(yimaiGsoData.ajaxUrl, {
                    action: 'yimai_gso_heading_analyze', nonce: yimaiGsoData.nonce, post_id: pid
                }, function(res) {
                    if (res.success) {
                        results.push({ pid: pid, ok: true });
                        // 实时更新表格行评分
                        var d = res.data;
                        var $row = $('.yimai-gso-ha-row-check[value="' + pid + '"]').closest('tr');
                        if ($row.length && d) {
                            var sc = d.score >= 70 ? '#22c55e' : (d.score >= 50 ? '#f59e0b' : '#ef4444');
                            $row.find('td:eq(2)').html('<strong style="color:' + sc + ';">' + esc(d.score) + '</strong>');
                            $row.find('td:eq(3)').css('color', '').text(d.h2_structure ? d.h2_structure.count : '-');
                            $row.find('td:eq(4)').css('color', '').text(d.kw_coverage ? d.kw_coverage.rate + '%' : '-');
                        }
                    } else {
                        results.push({ pid: pid, ok: false });
                    }
                }, 'json').always(function() {
                    processNext(index + 1);
                });
            }
            processNext(0);
        },

        optimize: function(postId) {
            var $btn = $('.yimai-gso-ha-optimize-btn[data-id="' + postId + '"]');
            $btn.prop('disabled', true).text('优化中...');
            $.post(yimaiGsoData.ajaxUrl, { action: 'yimai_gso_heading_optimize', nonce: yimaiGsoData.nonce, post_id: postId }, function(res) {
                $btn.prop('disabled', false).text('一键优化');
                if (!res.success) { alert(res.data.message || '优化失败'); return; }
                HeadingPanel.showOptimizeModal(res.data, postId);
            }, 'json').fail(function() {
                $btn.prop('disabled', false).text('一键优化');
                alert('网络请求失败');
            });
        },

        showOptimizeModal: function(data, postId) {
            var opts = data.optimizations || [];
            var overall = data.overall || '';
            var html = '<div id="yimai-gso-ha-optimize-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:99998;"></div>'
                + '<div id="yimai-gso-ha-optimize-modal" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:640px;max-height:80vh;overflow-y:auto;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);z-index:99999;padding:24px;">'
                + '<h3 style="margin:0 0 16px;font-size:16px;">🤖 AI 标题优化建议</h3>'
                + '<p style="font-size:13px;color:#64748b;margin:0 0 16px;">以下是根据AI分析得到的H2标题优化建议，勾选你希望应用的优化项，点击"应用所选优化"。</p>';

            if (opts.length === 0) {
                html += '<div style="padding:20px;background:#f0f4ff;border-radius:10px;color:#4338ca;font-size:14px;">所有H2标题已包含关键词且表达精炼，无需优化。</div>';
            } else {
                html += '<div style="margin-bottom:16px;">';
                for (var i = 0; i < opts.length; i++) {
                    var o = opts[i];
                    if (o.keep) {
                        html += '<div style="padding:12px 14px;margin-bottom:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
                            + '<div style="display:flex;align-items:flex-start;gap:10px;">'
                            + '<span style="color:#22c55e;font-size:16px;margin-top:2px;">&#10003;</span>'
                            + '<div><div style="font-size:13px;font-weight:500;">' + esc(o.original) + '</div>'
                            + '<div style="font-size:12px;color:#22c55e;">无需优化，已符合要求</div></div></div></div>';
                    } else {
                        html += '<div style="padding:12px 14px;margin-bottom:8px;background:#fefce8;border:1px solid #fde68a;border-radius:10px;">'
                            + '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">'
                            + '<input type="checkbox" class="yimai-gso-ha-opt-check" checked value=\'' + JSON.stringify(o).replace(/'/g, '&#39;') + '\' style="margin-top:3px;accent-color:#6366f1;">'
                            + '<div><div style="font-size:12px;color:#94a3b8;text-decoration:line-through;">' + esc(o.original) + '</div>'
                            + '<div style="font-size:13px;font-weight:500;color:#1e293b;margin:2px 0;">→ ' + esc(o.suggested) + '</div>'
                            + '<div style="font-size:12px;color:#d97706;">' + esc(o.reason || '') + '</div></div></label></div>';
                    }
                }
                html += '</div>';
            }

            if (overall) {
                html += '<div style="padding:12px 16px;background:#f0f4ff;border:1px solid #c7d2fe;border-radius:10px;margin-bottom:16px;">'
                    + '<div style="font-size:12px;font-weight:600;color:#4338ca;margin-bottom:4px;">整体建议</div>'
                    + '<div style="font-size:13px;color:#475569;">' + esc(overall) + '</div></div>';
            }

            html += '<div style="display:flex;gap:8px;justify-content:flex-end;">'
                + '<button type="button" id="yimai-gso-ha-opt-cancel" class="button">取消</button>';

            if (opts.length > 0) {
                html += '<button type="button" id="yimai-gso-ha-opt-apply" class="button button-primary" data-post-id="' + postId + '">📝 应用所选优化</button>';
            }
            html += '</div></div>';

            $('body').append(html);

            $('#yimai-gso-ha-opt-cancel').on('click', function() { $('#yimai-gso-ha-optimize-overlay, #yimai-gso-ha-optimize-modal').remove(); });
            $('#yimai-gso-ha-optimize-overlay').on('click', function() { $('#yimai-gso-ha-optimize-overlay, #yimai-gso-ha-optimize-modal').remove(); });
            $('#yimai-gso-ha-opt-apply').on('click', function() {
                HeadingPanel.applyOptimization(postId);
            });
        },

        applyOptimization: function(postId) {
            var checks = $('.yimai-gso-ha-opt-check:checked');
            if (checks.length === 0) { alert('请至少勾选一个优化项'); return; }
            var optimizations = [];
            checks.each(function() {
                try { optimizations.push(JSON.parse($(this).val())); } catch(e) {}
            });

            var $btn = $('#yimai-gso-ha-opt-apply');
            $btn.prop('disabled', true).text('应用中...');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_heading_apply_optimization', nonce: yimaiGsoData.nonce,
                post_id: postId, optimizations: JSON.stringify(optimizations)
            }, function(res) {
                $('#yimai-gso-ha-optimize-overlay, #yimai-gso-ha-optimize-modal').remove();
                if (!res.success) { alert('应用失败：' + (res.data.message || '')); return; }
                alert('✅ 已成功优化 ' + res.data.applied + ' 个标题！');
                // 用最新分析结果更新表格行 + 底部分析面板
                var d = res.data.analysis;
                if (d) {
                    var $row = $('.yimai-gso-ha-analyze-btn[data-id="' + postId + '"]').closest('tr');
                    if ($row.length) {
                        var sc = d.score >= 70 ? '#22c55e' : (d.score >= 50 ? '#f59e0b' : '#ef4444');
                        $row.find('td:eq(2)').html('<strong style="color:' + sc + ';">' + esc(d.score) + '</strong>');
                        $row.find('td:eq(3)').text(d.h2_structure ? d.h2_structure.count : '-');
                        $row.find('td:eq(4)').text(d.kw_coverage ? d.kw_coverage.rate + '%' : '-');
                        $row.find('td:eq(5)').html('<span style="color:#22c55e;font-size:12px;">已优化</span>');
                    }
                    HeadingPanel.renderResult(d);
                }
                // 设置持久化优化状态标记
                $('.yimai-gso-ha-opt-status[data-pid="' + postId + '"]').html('<span style="color:#22c55e;font-size:12px;">已优化</span>');
            }, 'json').fail(function() {
                $('#yimai-gso-ha-optimize-overlay, #yimai-gso-ha-optimize-modal').remove();
                alert('网络请求失败');
            });
        },

        renderResult: function(d) {
            var scoreColor = d.score >= 70 ? '#22c55e' : (d.score >= 50 ? '#f59e0b' : '#ef4444');
            var html = '<h3 style="margin:0 0 16px;">' + esc(d.post_title) + '</h3>'
                + '<div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding:16px;background:#f8fafc;border-radius:12px;">'
                + '<div style="width:64px;height:64px;border-radius:50%;background:' + scoreColor + ';display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:800;">' + esc(d.score) + '</div>'
                + '<div><div style="font-size:14px;color:#64748b;">标题结构综合评分</div><div style="font-size:18px;font-weight:700;">' + (d.score >= 70 ? '结构良好' : (d.score >= 50 ? '有待优化' : '需要改进')) + '</div></div></div>';

            // H1
            var h1 = d.h1_check || {};
            html += '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #e2e8f0;border-radius:8px;"><h4 style="margin:0 0 8px;">H1 检查</h4><p style="margin:0;color:' + (h1.status === 'good' ? '#22c55e' : '#ef4444') + ';">' + esc(h1.message || '') + '</p></div>';

            // H2
            var h2 = d.h2_structure || {};
            html += '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #e2e8f0;border-radius:8px;"><h4 style="margin:0 0 8px;">H2 结构 (共' + esc(h2.count || 0) + '个)</h4>';
            var h2m = h2.messages || [];
            for (var i = 0; i < h2m.length; i++) html += '<p style="margin:0 0 4px;font-size:13px;color:#475569;">• ' + esc(h2m[i]) + '</p>';
            html += '</div>';

            // 标题列表
            if (d.headings && d.headings.length > 0) {
                html += '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #e2e8f0;border-radius:8px;"><h4 style="margin:0 0 8px;">标题层级</h4><ul class="yimai-gso-ha-heading-list">';
                for (var h = 0; h < d.headings.length; h++) {
                    var hd = d.headings[h];
                    html += '<li class="yimai-gso-ha-heading-item"><span class="yimai-gso-ha-heading-level h' + hd.level + '">H' + hd.level + '</span><span>' + esc(hd.text) + (hd.has_keyword ? ' <span class="yimai-gso-ha-heading-kw">含关键词</span>' : '') + '</span></li>';
                }
                html += '</ul></div>';
            }

            // 关键词覆盖
            var kw = d.kw_coverage || {};
            html += '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #e2e8f0;border-radius:8px;"><h4 style="margin:0 0 8px;">关键词覆盖率 (' + esc(kw.rate || 0) + '%)</h4>';
            var kwm = kw.messages || [];
            for (var k = 0; k < kwm.length; k++) html += '<p style="margin:0 0 4px;font-size:13px;color:#475569;">• ' + esc(kwm[k]) + '</p>';
            html += '</div>';

            // 分布
            var dist = d.distribution || {};
            html += '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #e2e8f0;border-radius:8px;"><h4 style="margin:0 0 8px;">分布均匀性</h4>';
            var dm = dist.messages || [];
            for (var di = 0; di < dm.length; di++) html += '<p style="margin:0 0 4px;font-size:13px;color:#475569;">• ' + esc(dm[di]) + '</p>';
            html += '</div>';

            // 建议
            var sugs = d.suggestions || [];
            if (sugs.length > 0) {
                html += '<div style="padding:12px 16px;border:1px solid #e2e8f0;border-radius:8px;"><h4 style="margin:0 0 8px;">优化建议</h4>';
                for (var s = 0; s < sugs.length; s++) {
                    var sug = sugs[s], sc2 = sug.type === 'critical' ? '#ef4444' : (sug.type === 'warning' ? '#f59e0b' : '#3b82f6');
                    html += '<p style="margin:0 0 8px;font-size:13px;color:#475569;"><span style="color:' + sc2 + ';font-weight:600;">[' + esc(sug.type) + ']</span> ' + esc(sug.message) + '</p>';
                }
                html += '</div>';
            }
            $('#yimai-gso-ha-result').html(html);
        }
    };

    $(document).on('click', '.yimai-gso-ha-analyze-btn', function() { HeadingPanel.analyze($(this).data('id')); });
    $(document).on('click', '.yimai-gso-ha-optimize-btn', function() { HeadingPanel.optimize($(this).data('id')); });
    $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="heading"]', function() { setTimeout(function() { HeadingPanel.init(); }, 100); });
    window.HeadingPanel = HeadingPanel;
})(jQuery);

// ══════════════════════════════════════════════════════════════
// Canonical URL 管理 IIFE
// ══════════════════════════════════════════════════════════════
(function($) {
    // 修复：定义 ajaxurl 变量（从 yimaiGsoData 中读取）
    var ajaxurl = (typeof yimaiGsoData !== 'undefined' && yimaiGsoData.ajaxUrl) ? yimaiGsoData.ajaxUrl : '';


    var CanonicalPanel = {
        page: 1,
        perPage: 20,
        loading: false,
        initialized: false,

        init: function() {
            if (this.initialized) return;
            var $root = $('#yimai-gso-canonical-root');
            if (!$root.length || $root.data('available') === '0') return;
            this.initialized = true;
            this.bindEvents();
            this.scan();
        },

        bindEvents: function() {
            var self = this;
            $('#yimai-gso-cm-search-btn').on('click', function() { self.page = 1; self.scan(); });
            $('#yimai-gso-cm-search').on('keypress', function(e) { if (e.which === 13) { self.page = 1; self.scan(); } });
            // 委托：编辑/保存/取消 canonical
            $(document).off('click.yimai-gso-cm-edit').on('click.yimai-gso-cm-edit', '.yimai-gso-cm-edit-btn', function() { self.startEdit($(this).closest('tr')); });
            $(document).off('click.yimai-gso-cm-save').on('click.yimai-gso-cm-save', '.yimai-gso-cm-save-btn', function() { self.saveEdit($(this).closest('tr')); });
            $(document).off('click.yimai-gso-cm-cancel').on('click.yimai-gso-cm-cancel', '.yimai-gso-cm-cancel-btn', function() { self.cancelEdit($(this).closest('tr')); });
        },

        getNonce: function() {
            return $('#yimai-gso-canonical-root').data('nonce') || '';
        },

        scan: function() {
            if (this.loading) return;
            this.loading = true;
            var self = this;
            var $btn = $('#yimai-gso-cm-search-btn').prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> 扫描中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'yimai_gso_canonical_batch_scan',
                    nonce: self.getNonce(),
                    page: self.page,
                    per_page: self.perPage,
                    filter: $('#yimai-gso-cm-filter').val(),
                    search: $('#yimai-gso-cm-search').val(),
                    category: $('#yimai-gso-cm-category').val()
                },
                success: function(res) {
                    if (res.success) {
                        self.renderStats(res.data.stats);
                        self.renderTable(res.data.items);
                        self.renderPagination(res.data.total, res.data.page, res.data.per_page);
                    } else {
                        $('#yimai-gso-cm-tbody').html('<tr><td colspan="5"><div class="notice notice-error" style="margin:0"><p>' + esc(res.data && res.data.message ? res.data.message : '扫描失败') + '</p></div></td></tr>');
                    }
                },
                error: function() {
                    $('#yimai-gso-cm-tbody').html('<tr><td colspan="5"><div class="notice notice-error" style="margin:0"><p>网络请求失败，请重试</p></div></td></tr>');
                },
                complete: function() {
                    self.loading = false;
                    $btn.prop('disabled', false).html('扫描');
                }
            });
        },

        renderStats: function(s) {
            if (!s) return;
            $('#yimai-gso-cm-total').text(s.total || 0);
            $('#yimai-gso-cm-default').text(s.default || 0);
            $('#yimai-gso-cm-custom').text(s.custom || 0);
            $('#yimai-gso-cm-external').text(s.external || 0);
        },

        renderTable: function(items) {
            var $tbody = $('#yimai-gso-cm-tbody');
            if (!items || items.length === 0) {
                $tbody.html('<tr><td colspan="5"><div class="yimai-gso-empty"><p>没有找到匹配的文章</p></div></td></tr>');
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var statusBadge, statusStyle;
                if (item.is_external) {
                    statusBadge = '跨站转载';
                    statusStyle = 'color:#8b5cf6;background:#f5f3ff;border:1px solid #ddd6fe';
                } else if (item.is_custom) {
                    statusBadge = '自定义';
                    statusStyle = 'color:#d97706;background:#fffbeb;border:1px solid #fde68a';
                } else {
                    statusBadge = '默认';
                    statusStyle = 'color:#16a34a;background:#f0fdf4;border:1px solid #bbf7d0';
                }
                html += '<tr data-id="' + item.id + '" data-custom="' + esc(item.custom_value || '') + '">'
                    + '<td><strong>' + esc(item.title) + '</strong></td>'
                    + '<td class="yimai-gso-cm-url-cell"><span class="yimai-gso-cm-url-display" title="' + encodeURIComponent(item.canonical_url) + '">' + esc(decodeURIComponent(item.canonical_url)) + '</span>'
                    + '<input type="url" class="yimai-gso-cm-url-input" value="' + esc(item.custom_value || '') + '" placeholder="留空则使用默认" style="display:none;width:100%;font-size:12px"></td>'
                    + '<td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;' + statusStyle + '">' + statusBadge + '</span></td>'
                    + '<td style="font-size:12px;color:#64748b">' + esc(item.post_date ? item.post_date.substring(0, 10) : '') + '</td>'
                    + '<td>'
                    + '<button type="button" class="button button-small yimai-gso-cm-edit-btn" title="编辑 Canonical">编辑</button>'
                    + '<button type="button" class="button button-small yimai-gso-cm-save-btn" style="display:none">保存</button> '
                    + '<button type="button" class="button button-small yimai-gso-cm-cancel-btn" style="display:none">取消</button>'
                    + '</td></tr>';
            }
            $tbody.html(html);
        },

        renderPagination: function(total, page, perPage) {
            var $pg = $('#yimai-gso-cm-pagination');
            if (total <= perPage) { $pg.html(''); return; }
            var totalPages = Math.ceil(total / perPage);
            var html = '<button class="button button-small yimai-gso-cm-pg-btn" data-page="1"' + (page <= 1 ? ' disabled' : '') + '>&laquo;</button>';
            var start = Math.max(1, page - 2), end = Math.min(totalPages, page + 2);
            for (var p = start; p <= end; p++) {
                html += '<button class="button button-small yimai-gso-cm-pg-btn' + (p === page ? ' active" style="background:#2271b1;color:#fff"' : '') + '" data-page="' + p + '">' + p + '</button>';
            }
            html += '<button class="button button-small yimai-gso-cm-pg-btn" data-page="' + totalPages + '"' + (page >= totalPages ? ' disabled' : '') + '>&raquo;</button>';
            html += '<span style="font-size:12px;color:#64748b;margin-left:8px">共 ' + total + ' 篇</span>';
            $pg.html(html);

            var self = this;
            $pg.off('click.yimai-gso-cm-pg').on('click.yimai-gso-cm-pg', '.yimai-gso-cm-pg-btn', function() {
                if ($(this).prop('disabled')) return;
                self.page = parseInt($(this).data('page'));
                self.scan();
            });
        },

        startEdit: function($tr) {
            $tr.find('.yimai-gso-cm-url-display').hide();
            $tr.find('.yimai-gso-cm-url-input').show().focus();
            $tr.find('.yimai-gso-cm-edit-btn').hide();
            $tr.find('.yimai-gso-cm-save-btn, .yimai-gso-cm-cancel-btn').show();
        },

        cancelEdit: function($tr) {
            $tr.find('.yimai-gso-cm-url-display').show();
            $tr.find('.yimai-gso-cm-url-input').hide().val($tr.data('custom') || '');
            $tr.find('.yimai-gso-cm-edit-btn').show();
            $tr.find('.yimai-gso-cm-save-btn, .yimai-gso-cm-cancel-btn').hide();
        },

        saveEdit: function($tr) {
            var self = this;
            var postId = $tr.data('id');
            var url = $tr.find('.yimai-gso-cm-url-input').val().trim();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'yimai_gso_canonical_save_custom',
                    nonce: self.getNonce(),
                    post_id: postId,
                    canonical_url: url
                },
                success: function(res) {
                    if (res.success) {
                        var d = res.data;
                        $tr.data('custom', d.is_custom ? d.canonical_url : '');
                        // 更新 URL 显示（解码后展示）
                        var displayUrl = '';
                        try { displayUrl = decodeURIComponent(d.canonical_url); } catch(e) { displayUrl = d.canonical_url; }
                        $tr.find('.yimai-gso-cm-url-display').attr('title', encodeURIComponent(d.canonical_url)).text(esc(displayUrl));
                        $tr.find('.yimai-gso-cm-url-input').val(d.is_custom ? d.canonical_url : '');
                        // 更新状态徽章
                        var $badge = $tr.find('td:nth-child(3) span');
                        if (d.is_external) {
                            $badge.text('跨站转载').attr('style', 'display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;color:#8b5cf6;background:#f5f3ff;border:1px solid #ddd6fe');
                        } else if (d.is_custom) {
                            $badge.text('自定义').attr('style', 'display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;color:#d97706;background:#fffbeb;border:1px solid #fde68a');
                        } else {
                            $badge.text('默认').attr('style', 'display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;color:#16a34a;background:#f0fdf4;border:1px solid #bbf7d0');
                        }
                        self.cancelEdit($tr);
                        self.scan(); // 刷新统计
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '保存失败');
                    }
                },
                error: function() { alert('网络请求失败'); }
            });
        }
    };

    $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="canonical"]', function() { setTimeout(function() { CanonicalPanel.init(); }, 100); });
    window.CanonicalPanel = CanonicalPanel;
})(jQuery);

// ══════════════════════════════════════════════════════════════
// 404 页面 SEO 优化 IIFE (v2.5.0 增强版)
// ══════════════════════════════════════════════════════════════
(function($) {
    var ajaxurl = (typeof yimaiGsoData !== 'undefined' && yimaiGsoData.ajaxUrl) ? yimaiGsoData.ajaxUrl : '';

    var Opt404 = {
        page: 1, perPage: 20, source: 'logs', loading: false, initialized: false,
        subTab: 'scan',
        // Redirect list state
        redPage: 1, redPerPage: 20, redLoading: false,
        botFilter: 'all', timeRange: 'all', search: '',

        init: function() {
            if (this.initialized) return;
            var $root = $('#yimai-gso-404-root');
            if (!$root.length) return;
            if ($root.data('available') === '0') {
                $('#yimai-gso-404-root input, #yimai-gso-404-root select, #yimai-gso-404-root button').prop('disabled', true);
                return;
            }
            this.initialized = true;
            this.bindEvents();
            // 自动加载持久化数据，刷新页后无需重新扫描
            this.scan();
            this.loadRedirects();
        },

        bindEvents: function() {
            var self = this;

            // ── 子标签切换 ──
            $(document).off('click.yimai-gso-404-subtab').on('click.yimai-gso-404-subtab', '.yimai-gso-404-sub-tab', function() {
                $('.yimai-gso-404-sub-tab').removeClass('active');
                $(this).addClass('active');
                var sub = $(this).data('subtab');
                self.subTab = sub;
                $('.yimai-gso-404-sub-panel').hide();
                $('.yimai-gso-404-sub-panel[data-subpanel="' + sub + '"]').show();
                if (sub === 'redirects') self.loadRedirects();
            });

            // ── 死链检测：来源切换 ──
            $(document).off('click.yimai-gso-404-source').on('click.yimai-gso-404-source', '.yimai-gso-404-source-tab', function() {
                $('.yimai-gso-404-source-tab').removeClass('active');
                $(this).addClass('active');
                self.source = $(this).data('source');
                self.page = 1;
                self.scan();
            });

            // ── 死链检测：筛选器 ──
            $('#yimai-gso-404-bot-filter').off('change').on('change', function() {
                self.botFilter = $(this).val(); self.page = 1; self.scan();
            });
            $('#yimai-gso-404-time-filter').off('change').on('change', function() {
                self.timeRange = $(this).val(); self.page = 1; self.scan();
            });
            $('#yimai-gso-404-search').off('input').on('input', function() {
                self.search = $(this).val(); self.page = 1;
            });

            // ── 扫描按钮 ──
            $('#yimai-gso-404-scan-btn').on('click', function() { self.page = 1; self.scan(); });

            // ── 导出CSV ──
            $('#yimai-gso-404-export-csv-btn').on('click', function() { self.exportCSV(); });

            // ── 清空日志 ──
            $('#yimai-gso-404-clear-btn').on('click', function() {
                if (!confirm('确定要清空所有404访问日志吗？此操作不可恢复。')) return;
                $.post(ajaxurl, { action: 'yimai_gso_404_clear_logs', nonce: self.getNonce() }, function(res) {
                    if (res.success) { alert('日志已清空'); self.page = 1; self.scan(); }
                    else alert(res.data && res.data.message ? res.data.message : '操作失败');
                });
            });

            // ── 生成404页面 ──
            $('#yimai-gso-404-gen-btn').on('click', function() { self.generatePage(); });

            // ── 自定义404页面复制 ──
            $('#yimai-gso-404-copy-btn').on('click', function() {
                var $ta = $('#yimai-gso-404-html'); $ta.select(); document.execCommand('copy');
                $(this).text('已复制!'); setTimeout(function() { $('#yimai-gso-404-copy-btn').text('复制 HTML 代码'); }, 2000);
            });

            // ── 设置保存 ──
            $('#yimai-gso-404-save-settings-btn').on('click', function() {
                var customEnabled = $('#yimai-gso-404-setting-custom').is(':checked') ? 1 : 0;
                $.post(ajaxurl, {
                    action: 'yimai_gso_404_save_settings',
                    nonce: self.getNonce(),
                    log_enabled: $('#yimai-gso-404-setting-log').is(':checked') ? 1 : 0,
                    custom_enabled: customEnabled,
                    auto_redirect: $('#yimai-gso-404-setting-auto-redirect').is(':checked') ? 1 : 0
                }, function(res) {
                    if (res.success) {
                        $('#yimai-gso-404-settings-msg').show().delay(2000).fadeOut();
                        self.updateCustomPageStatus(customEnabled === 1, '');
                        if (customEnabled === 1) {
                            $('#yimai-gso-404-custom-alert').show();
                        } else {
                            $('#yimai-gso-404-custom-alert').hide();
                        }
                    }
                });
            });

            // ── 忽略规则保存 ──
            $('#yimai-gso-404-save-ignore-btn').on('click', function() {
                $.post(ajaxurl, {
                    action: 'yimai_gso_404_save_ignore_rules',
                    nonce: self.getNonce(),
                    rules: $('#yimai-gso-404-ignore-rules').val()
                }, function(res) {
                    if (res.success) $('#yimai-gso-404-ignore-msg').show().delay(2000).fadeOut();
                    else alert(res.data && res.data.message ? res.data.message : '操作失败');
                });
            });

            // ── 重定向管理：刷新 ──
            $('#yimai-gso-404-red-refresh-btn').on('click', function() { self.redPage = 1; self.loadRedirects(); });
            $('#yimai-gso-404-red-search').off('input').on('input', function() { self.redPage = 1; self.loadRedirects(); });

            // ── 重定向导入/导出 ──
            $('#yimai-gso-404-import-csv-btn').on('click', function() {
                $('#yimai-gso-404-import-modal').css('display','flex');
            });
            $('#yimai-gso-404-import-cancel, .yimai-gso-404-modal-close').on('click', function() {
                $('#yimai-gso-404-import-modal').hide();
            });
            $('#yimai-gso-404-import-submit').on('click', function() {
                var csv = $('#yimai-gso-404-import-csv').val();
                if (!csv.trim()) { alert('请粘贴CSV数据'); return; }
                $.post(ajaxurl, { action: 'yimai_gso_404_import_redirects', nonce: self.getNonce(), csv_data: csv }, function(res) {
                    if (res.success) { alert(res.data.message); $('#yimai-gso-404-import-modal').hide(); self.loadRedirects(); }
                    else alert(res.data && res.data.message ? res.data.message : '导入失败');
                });
            });
            $('#yimai-gso-404-export-red-btn').on('click', function() {
                $.post(ajaxurl, { action: 'yimai_gso_404_export_redirects', nonce: self.getNonce() }, function(res) {
                    if (res.success && res.data.csv) {
                        self.downloadCSV(res.data.csv, 'yimai-gso-301-redirects.csv');
                    }
                });
            });

            // ── 快速添加忽略规则标签 ──
            $('.yimai-gso-404-quick-tag').off('click').on('click', function() {
                var val = $(this).text().trim();
                var $ta = $('#yimai-gso-404-ignore-rules');
                var lines = $ta.val().split('\n');
                var found = false;
                for (var i = 0; i < lines.length; i++) {
                    if (lines[i].trim() === val) { found = true; break; }
                }
                if (!found) {
                    $ta.val($ta.val().trim() + '\n' + val);
                }
            });

            // ── 初始化状态 ──
            self.updateCustomPageStatus(
                $('#yimai-gso-404-setting-custom').is(':checked'),
                ''
            );
        },

        getNonce: function() {
            return $('#yimai-gso-404-root').data('nonce') || '';
        },

        downloadCSV: function(csv, filename) {
            var BOM = '\uFEFF';
            var blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = filename;
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(url);
        },

        // ─────────── 死链检测扫描 ───────────
        scan: function() {
            if (this.loading) return;
            this.loading = true;
            var self = this;
            var $btn = $('#yimai-gso-404-scan-btn').prop('disabled', true).text('⏳ 扫描中...');
            $('#yimai-gso-404-scan-progress').show();
            $('#yimai-gso-404-scan-fill').css('width', '0%');
            $('#yimai-gso-404-scan-text').text('正在扫描...');
            // 模拟进度动画
            var progInterval = setInterval(function() {
                var w = parseInt($('#yimai-gso-404-scan-fill').css('width')) || 0;
                if (w < 90) {
                    w += Math.random() * 15;
                    $('#yimai-gso-404-scan-fill').css('width', Math.min(w, 90) + '%');
                    $('#yimai-gso-404-scan-text').text(w > 50 ? '正在扫描文章内链...' : '正在扫描404日志...');
                }
            }, 300);

            $.ajax({
                url: ajaxurl, type: 'POST', dataType: 'json',
                data: {
                    action: 'yimai_gso_404_scan',
                    nonce: self.getNonce(),
                    page: self.page, per_page: self.perPage,
                    source: self.source,
                    bot_filter: self.botFilter,
                    search: self.search,
                    time_range: self.timeRange
                },
                success: function(res) {
                    if (res.success) {
                        var d = res.data;
                        self.renderStats(d.stats);
                        self.renderTable(d.items);
                        self.renderPagination(d.total, d.page, d.per_page, '#yimai-gso-404-pagination', 'scan');
                    } else {
                        $('#yimai-gso-404-tbody').html('<tr><td colspan="8"><div class="yimai-gso-404-empty-state"><div style="color:#ef4444;text-align:center">' + esc(res.data && res.data.message ? res.data.message : '扫描失败') + '</div></div></td></tr>');
                    }
                },
                error: function() {
                    $('#yimai-gso-404-tbody').html('<tr><td colspan="8"><div class="yimai-gso-404-empty-state"><div style="color:#ef4444;text-align:center">网络请求失败</div></div></td></tr>');
                },
                complete: function() {
                    clearInterval(progInterval);
                    self.loading = false;
                    $btn.prop('disabled', false).text('🔍 开始扫描');
                    $('#yimai-gso-404-scan-fill').css('width', '100%');
                    $('#yimai-gso-404-scan-text').text('扫描完成！');
                    setTimeout(function() {
                        $('#yimai-gso-404-scan-progress').fadeOut(500);
                    }, 1500);
                }
            });
        },

        renderStats: function(s) {
            if (!s) return;
            // 死链数量
            var deadCount = s['total_' + this.source] >= 0 ? s['total_' + this.source] : '-';
            $('#yimai-gso-404-total-links').text(deadCount);
            // 总访问量
            $('#yimai-gso-404-total-hits').text(s.total_hits || 0);
            // 爬虫访问（如果有的话，在 delta 行展示；否则放 redirect count 卡片）
            if (s.total_bot_hits !== undefined) {
                // 可以用在访问量卡片的delta
            }
        },

        updateRedirectCount: function(count) {
            $('#yimai-gso-404-redirect-count').text(count || 0);
        },

        updateCustomPageStatus: function(enabled, lastGen) {
            if (enabled) {
                $('#yimai-gso-404-custom-status').text('已启用').css('color','#7c3aed');
                if (lastGen) $('#yimai-gso-404-delta-custom').text(lastGen).show();
            } else {
                $('#yimai-gso-404-custom-status').text('未启用').css('color','#1e293b');
            }
        },

        renderTable: function(items) {
            var $tbody = $('#yimai-gso-404-tbody');
            if (!items || items.length === 0) {
                var emptyMsg = this.source === 'logs' ? '暂无 404 访问记录' : '未发现内链死链';
                if (this.search) emptyMsg = '没有匹配的结果';
                $tbody.html('<tr><td colspan="8"><div class="yimai-gso-404-empty-state"><div class="empty-icon">🔍</div><div class="empty-title">' + emptyMsg + '</div></div></td></tr>');
                return;
            }
            var html = '';
            var self = this;
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                // 完整可点击链接（内链死链优先用 full_url 绝对地址，404日志直接用 url）
                var fullUrl = item.full_url || item.url;

                // 重定向建议
                var sugHtml = '';
                var hasSuggestion = false;
                var sugUrl = '', sugTitle = '', sugScore = 0;
                if (item.redirect_suggestions && item.redirect_suggestions.length > 0) {
                    var sug = item.redirect_suggestions[0];
                    // ★ 修复：sug.score || '?' 在得分为 0（策略4兜底）时被判为 falsy 显示"？分"，改用 != null 精确判断
sugHtml = '<div style="display:flex;align-items:center;gap:6px"><span style="font-size:11px;color:#94a3b8">→</span><span style="font-size:12px;color:#059669">' + esc(sug.title.length > 18 ? sug.title.substring(0, 18) + '...' : sug.title) + '</span><span class="yimai-gso-badge green" style="font-size:10px;padding:1px 6px">' + (sug.score != null ? sug.score : '?') + '分</span></div>';
                    hasSuggestion = true;
                    sugUrl = sug.url;
                    sugTitle = sug.title;
                    sugScore = sug.score;
                } else {
                    sugHtml = '<span style="color:#94a3b8;font-size:12px">无匹配文章</span>';
                }

                // 来源徽章
                var sourceBadge = item.source === 'internal'
                    ? '<span class="yimai-gso-badge" style="background:#fff7ed;color:#ea580c">内链死链</span>'
                    : '<span class="yimai-gso-badge" style="background:#fef2f2;color:#dc2626">404日志</span>';

                // 爬虫类型
                var botType = item.bot_type || 'user';
                var botLabel = item.bot_label || '用户访问';
                var botIcon = item.bot_icon || '👤';
                var botDotColor = botType === 'user' ? '#94a3b8' : (botType === 'google' ? '#4285f4' : (botType === 'baidu' ? '#ef4444' : (botType === 'bing' ? '#10b981' : '#f59e0b')));
                var botHtml = '<span style="font-size:12px;display:inline-flex;align-items:center;gap:5px"><span style="width:7px;height:7px;border-radius:50%;background:' + botDotColor + ';display:inline-block"></span>' + botIcon + ' ' + esc(botLabel) + '</span>';

                // 来路（可点击跳转：内链死链跳到来源文章，404日志跳到外部来路）
                var refDisplay = '';
                if (item.from_post) {
                    var fromLink = item.referer ? esc(item.referer) : '#';
                    refDisplay = '<a href="' + fromLink + '" target="_blank" rel="noopener noreferrer" title="打开来源文章修改死链" style="font-size:11px;color:#3b82f6;text-decoration:none">' + esc(item.from_post.length > 12 ? item.from_post.substring(0, 12) + '...' : item.from_post) + '</a>';
                } else if (item.referer) {
                    refDisplay = '<a href="' + esc(item.referer) + '" target="_blank" rel="noopener noreferrer" title="' + esc(item.referer) + '" style="font-size:11px;color:#64748b;text-decoration:none">外部来源</a>';
                } else {
                    refDisplay = '<span style="color:#94a3b8;font-size:11px">直接访问</span>';
                }

                // 操作列
                var actions = '';
                if (item.source === 'internal' && item.from_id) {
                actions += '<button type="button" class="yimai-gso-404-btn yimai-gso-404-remove-btn" data-post="' + item.from_id + '" data-href="' + esc(item.url) + '" style="color:#ef4444;border-color:#fecaca;padding:3px 10px;font-size:11px" title="移除死链，保留锚文本">✕ 移除</button> ';
            }
            if (hasSuggestion) {
                // ★ 已应用重定向则显示"已应用"，否则显示"应用"
                var alreadyApplied = item.has_redirect === true;
                actions += '<button type="button" class="yimai-gso-404-btn yimai-gso-404-apply-red" style="color:' + (alreadyApplied ? '#16a34a' : '#059669') + ';border-color:#a7f3d0;padding:3px 10px;font-size:11px;margin-right:3px" data-path="' + esc(item.path) + '" data-url="' + esc(sugUrl) + '" data-title="' + esc(sugTitle) + '"' + (alreadyApplied ? ' disabled' : '') + '>↗ ' + (alreadyApplied ? '已应用' : '应用') + '</button> ';
                }
                actions += '<button type="button" class="yimai-gso-404-btn yimai-gso-404-detail-btn" style="padding:3px 10px;font-size:11px" data-path="' + esc(item.path) + '">📋 详情</button>';

                html += '<tr>'
                    + '<td><div style="display:flex;align-items:center;gap:8px"><span style="color:#ef4444;font-size:14px">⚠️</span><a href="' + esc(fullUrl) + '" target="_blank" rel="noopener noreferrer" title="' + esc(fullUrl) + '" style="font-size:12px;font-family:monospace;word-break:break-all;color:#1e40af;text-decoration:underline">' + esc(fullUrl) + '</a></div></td>'
                    + '<td>' + sourceBadge + '</td>'
                    + '<td><strong>' + item.count + '</strong></td>'
                    + '<td>' + botHtml + '</td>'
                    + '<td style="font-size:12px;color:#64748b">' + esc(item.hit_time ? item.hit_time.substring(0, 16) : '-') + '</td>'
                    + '<td>' + sugHtml + '</td>'
                    + '<td>' + refDisplay + '</td>'
                    + '<td style="white-space:nowrap">' + actions + '</td>'
                    + '</tr>';
            }
            $tbody.html(html);

            // ── 事件绑定 ──
            // 详情
            $tbody.off('click.yimai-gso-404-detail').on('click.yimai-gso-404-detail', '.yimai-gso-404-detail-btn', function() {
                self.showDetail($(this).data('path'));
            });
            // 应用重定向
            $tbody.off('click.yimai-gso-404-apply').on('click.yimai-gso-404-apply', '.yimai-gso-404-apply-red', function() {
                var path = $(this).data('path');
                var url = $(this).data('url');
                var title = $(this).data('title');
                if (!url) { alert('无可用建议'); return; }
                if (!confirm('确定创建301重定向：\n' + path + ' → ' + title + '？')) return;
                var $btn = $(this).prop('disabled', true).text('处理中...');
                $.post(ajaxurl, {
                    action: 'yimai_gso_404_apply_redirect',
                    nonce: self.getNonce(),
                    from_path: path,
                    to_url: url,
                    to_title: title
                }, function(res) {
                    if (res.success) {
                        $btn.text('已应用').css('color','#16a34a').prop('disabled', true);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '操作失败');
                        $btn.prop('disabled', false).text('↗ 应用');
                    }
                });
            });
            // 移除死链
            $tbody.off('click.yimai-gso-404-remove').on('click.yimai-gso-404-remove', '.yimai-gso-404-remove-btn', function() {
                var postId = $(this).data('post');
                var href = $(this).data('href');
                if (!confirm('确定从文章中移除此死链吗？锚文本将保留。')) return;
                var $btn = $(this).prop('disabled', true).text('处理中...');
                $.post(ajaxurl, {
                    action: 'yimai_gso_404_remove_dead_link',
                    nonce: self.getNonce(),
                    post_id: postId,
                    href: href
                }, function(res) {
                    if (res.success) {
                        $btn.text('已移除').css('color','#16a34a').prop('disabled', true);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '操作失败');
                        $btn.prop('disabled', false).text('移除');
                    }
                });
            });
        },

        renderPagination: function(total, page, perPage, selector, caller) {
            var $pg = $(selector);
            if (total <= 0 || total < 0) { $pg.html(''); return; }
            var totalPages = Math.ceil(total / perPage);
            if (totalPages <= 1) { $pg.html('<span style="font-size:12px;color:#64748b">共 ' + total + ' 条</span>'); return; }
            var html = '<button class="yimai-gso-404-btn yimai-gso-404-pg-btn" data-page="1"' + (page <= 1 ? ' disabled' : '') + ' style="padding:6px 12px">‹</button>';
            var start = Math.max(1, page - 2), end = Math.min(totalPages, page + 2);
            for (var p = start; p <= end; p++) {
                html += '<button class="yimai-gso-404-btn yimai-gso-404-pg-btn' + (p === page ? ' active" style="background:' + '#6366f1' + ';color:#fff;border-color:transparent' : '" style="background:#fff') + '" data-page="' + p + '">' + p + '</button>';
            }
            html += '<button class="yimai-gso-404-btn yimai-gso-404-pg-btn" data-page="' + totalPages + '"' + (page >= totalPages ? ' disabled' : '') + ' style="padding:6px 12px">›</button>';
            html += '<span style="font-size:12px;color:#64748b;margin-left:8px">共 ' + total + ' 条</span>';
            $pg.html(html);

            var self = this;
            $pg.off('click.yimai-gso-404-pg').on('click.yimai-gso-404-pg', '.yimai-gso-404-pg-btn', function() {
                if ($(this).prop('disabled')) return;
                var newPage = parseInt($(this).data('page'));
                if (caller === 'redirects') {
                    self.redPage = newPage; self.loadRedirects();
                } else {
                    self.page = newPage; self.scan();
                }
            });
        },

        showDetail: function(path) {
            var self = this;
            var $overlay = $('<div id="yimai-gso-404-detail-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center">');
            var $modal = $('<div class="yimai-gso-404-modal-box" style="width:560px">');
            $modal.html('<div class="yimai-gso-404-modal-header"><h3>📋 死链详情</h3><button type="button" class="yimai-gso-404-modal-close">✕</button></div><div class="yimai-gso-404-modal-body"><div id="yimai-gso-404-detail-content" style="color:#64748b">加载中...</div></div>');
            $modal.find('.yimai-gso-404-modal-close').on('click', function() { $overlay.remove(); });
            $overlay.append($modal).on('click', function(e) { if (e.target === this) $overlay.remove(); });
            $('body').append($overlay);

            $.ajax({
                url: ajaxurl, type: 'POST', dataType: 'json',
                data: { action: 'yimai_gso_404_get_detail', nonce: self.getNonce(), path: path },
                success: function(res) {
                    var $c = $('#yimai-gso-404-detail-content');
                    if (!res.success) { $c.html('<p>加载失败</p>'); return; }
                    var d = res.data;
                    var html = '<div style="margin-bottom:16px">'
                        + '<div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">死链路径</div>'
                        + '<div style="font-family:monospace;font-size:14px;color:#ef4444;word-break:break-all">' + esc(path) + '</div>'
                        + '</div>';

                    if (d.detail) {
                        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">'
                            + '<div><div style="font-size:11px;color:#94a3b8;margin-bottom:4px">访问量</div><div style="font-size:18px;font-weight:700">' + d.detail.count + '</div></div>'
                            + '<div><div style="font-size:11px;color:#94a3b8;margin-bottom:4px">来源类型</div><div style="font-size:13px">' + (d.detail.bot_icon || '') + ' ' + esc(d.detail.bot_label || '未知') + '</div></div>'
                            + '<div><div style="font-size:11px;color:#94a3b8;margin-bottom:4px">最近访问</div><div style="font-size:13px">' + esc(d.detail.hit_time || '-') + '</div></div>'
                            + '<div><div style="font-size:11px;color:#94a3b8;margin-bottom:4px">来路</div><div style="font-size:13px">' + (d.detail.referer ? '<a href="' + esc(d.detail.referer) + '" target="_blank">参考来源</a>' : '直接访问') + '</div></div>'
                            + '</div>';
                    }

                    if (d.suggestions && d.suggestions.length > 0) {
                        html += '<div style="border-top:1px solid #e2e8f0;padding-top:16px">'
                            + '<div style="font-size:12px;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:6px">💡 <span>智能重定向建议</span><span style="font-size:11px;color:#94a3b8;font-weight:400;margin-left:8px">基于4级匹配策略</span></div>'
                            + '<ul style="margin:0;padding:0;list-style:none">';
                        for (var i = 0; i < d.suggestions.length; i++) {
                            var s = d.suggestions[i];
                            var scoreClass = s.score >= 80 ? 'high' : (s.score >= 50 ? 'medium' : 'low');
                            var scoreColors = s.score >= 80 ? 'background:#ecfdf5;color:#059669' : (s.score >= 50 ? 'background:#fff7ed;color:#d97706' : 'background:#f8fafc;color:#94a3b8');
                            html += '<li style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9">'
                                + '<span style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0;' + scoreColors + '">' + s.score + '</span>'
                                + '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:500;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(s.title) + '</div><div style="font-size:11px;color:#94a3b8;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(s.url) + '</div></div>'
                                + '<button type="button" class="yimai-gso-404-btn yimai-gso-404-apply-sug" style="color:#059669;border-color:#a7f3d0;padding:5px 14px;font-size:11px" data-path="' + esc(path) + '" data-url="' + esc(s.url) + '" data-title="' + esc(s.title) + '">↗ 应用</button>'
                                + '</li>';
                        }
                        html += '</ul></div>';
                    } else {
                        html += '<p style="color:#94a3b8">未找到相关文章建议</p>';
                    }
                    $c.html(html);

                    $c.off('click.apply-sug').on('click.apply-sug', '.yimai-gso-404-apply-sug', function() {
                        var btn = $(this).prop('disabled', true).text('处理中...');
                        $.post(ajaxurl, {
                            action: 'yimai_gso_404_apply_redirect',
                            nonce: self.getNonce(),
                            from_path: $(this).data('path'),
                            to_url: $(this).data('url'),
                            to_title: $(this).data('title')
                        }, function(res) {
                            if (res.success) btn.text('已应用').css('color','#16a34a');
                            else { alert(res.data.message || '失败'); btn.prop('disabled',false).text('应用此建议'); }
                        });
                    });
                },
                error: function() { $('#yimai-gso-404-detail-content').html('<p>请求失败</p>'); }
            });
        },

        generatePage: function() {
            var self = this;
            var $btn = $('#yimai-gso-404-gen-btn').prop('disabled', true).text('⏳ 生成中...');
            $.ajax({
                url: ajaxurl, type: 'POST', dataType: 'json',
                data: { action: 'yimai_gso_404_generate_page', nonce: self.getNonce() },
                success: function(res) {
                    if (res.success) {
                        var d = res.data;
                        $('#yimai-gso-404-html').val(d.html);
                        $('#yimai-gso-404-preview-link').attr('href', d.preview_url || '#');
                        $('#yimai-gso-404-gen-result').slideDown(200);
                        $('#yimai-gso-404-custom-alert').show();
                        self.updateCustomPageStatus(true, '刚刚生成');
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '生成失败');
                    }
                },
                error: function() { alert('网络请求失败'); },
                complete: function() { $btn.prop('disabled', false).text('🔄 生成 404 页面'); }
            });
        },

        // ─────────── 重定向管理 ───────────
        loadRedirects: function() {
            if (this.redLoading) return;
            this.redLoading = true;
            var self = this;
            $('#yimai-gso-404-red-tbody').html('<tr><td colspan="7"><div class="yimai-gso-404-empty-state"><div style="text-align:center;color:#94a3b8;padding:20px">加载中...</div></div></td></tr>');

            $.ajax({
                url: ajaxurl, type: 'POST', dataType: 'json',
                data: {
                    action: 'yimai_gso_404_get_redirects',
                    nonce: self.getNonce(),
                    page: self.redPage,
                    per_page: self.redPerPage,
                    search: $('#yimai-gso-404-red-search').val()
                },
                success: function(res) {
                    if (res.success) {
                        var d = res.data;
                        self.renderRedirectTable(d.items);
                        self.renderPagination(d.total, d.page, d.per_page, '#yimai-gso-404-red-pagination', 'redirects');
                        if (d.server_written > 0) {
                            $('#yimai-gso-404-server-status').html(' 已写入服务器：<strong>' + d.server_written + '</strong> 条');
                        } else {
                            $('#yimai-gso-404-server-status').html('');
                        }
                    } else {
                        $('#yimai-gso-404-red-tbody').html('<tr><td colspan="7"><div class="yimai-gso-404-empty-state"><div style="color:#ef4444;text-align:center">加载失败</div></div></td></tr>');
                    }
                },
                error: function() {
                    $('#yimai-gso-404-red-tbody').html('<tr><td colspan="7"><div class="yimai-gso-404-empty-state"><div style="color:#ef4444;text-align:center">网络请求失败</div></div></td></tr>');
                },
                complete: function() { self.redLoading = false; }
            });
        },

        renderRedirectTable: function(items) {
            var $tbody = $('#yimai-gso-404-red-tbody');
            if (!items || items.length === 0) {
                $tbody.html('<tr><td colspan="7"><div class="yimai-gso-404-empty-state"><div class="empty-icon">↗️</div><div class="empty-title">暂无重定向规则</div><div class="empty-desc">在死链检测中应用建议即可自动创建</div></div></td></tr>');
                this.updateRedirectCount(0);
                return;
            }
            var html = '';
            var self = this;
            for (var i = 0; i < items.length; i++) {
                var r = items[i];
                var statusDot = r.server_written ? '<span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:6px"></span>' : '<span style="width:8px;height:8px;border-radius:50%;background:#94a3b8;display:inline-block;margin-right:6px"></span>';
                var statusLabel = r.server_written ? '已写入服务器' : 'WP内部重定向';
                var statusColor = r.server_written ? '#166534' : '#64748b';
                html += '<tr>'
                    + '<td><input type="checkbox" class="yimai-gso-404-red-checkbox" data-source="' + esc(r.source) + '"></td>'
                    + '<td><div style="color:#ef4444;font-family:monospace;font-size:12px;word-break:break-all">' + esc(r.source) + '</div></td>'
                    + '<td style="text-align:center;color:#94a3b8">→</td>'
                    + '<td><div style="color:#059669;font-family:monospace;font-size:12px;word-break:break-all">' + esc(r.target) + '</div>' + (r.target_title ? '<div style="font-size:11px;color:#94a3b8">' + esc(r.target_title.length > 30 ? r.target_title.substring(0, 30) + '...' : r.target_title) + '</div>' : '') + '</td>'
                    + '<td><span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:' + statusColor + '">' + statusDot + statusLabel + '</span></td>'
                    + '<td style="font-size:12px;color:#64748b">' + esc((r.updated_at || r.created_at || '').substring(0, 16)) + '</td>'
                    + '<td><button type="button" class="yimai-gso-404-btn yimai-gso-404-red-delete" data-source="' + esc(r.source) + '" style="color:#ef4444;border-color:#fecaca;padding:3px 12px;font-size:11px">🗑 删除</button></td>'
                    + '</tr>';
            }
            $tbody.html(html);

            // 批量选中计数
            var updateBatchBar = function() {
                var checked = $('.yimai-gso-404-red-checkbox:checked').length;
                $('#yimai-gso-404-batch-count').text(checked);
                $('#yimai-gso-404-batch-bar').toggle(checked > 0);
            };
            $('.yimai-gso-404-red-checkbox').off('change').on('change', updateBatchBar);
            $('#yimai-gso-404-red-check-all').off('change').on('change', function() {
                $('.yimai-gso-404-red-checkbox').prop('checked', this.checked).trigger('change');
            });
            $('#yimai-gso-404-batch-bar').toggle($('.yimai-gso-404-red-checkbox:checked').length > 0);

            $tbody.off('click.red-delete').on('click.red-delete', '.yimai-gso-404-red-delete', function() {
                var src = $(this).data('source');
                if (!confirm('确定删除此重定向规则？\n' + src)) return;
                var $row = $(this).closest('tr');
                $.post(ajaxurl, { action: 'yimai_gso_404_delete_redirect', nonce: self.getNonce(), source: src }, function(res) {
                    if (res.success) { $row.fadeOut(300, function() { $row.remove(); self.loadRedirects(); }); }
                    else alert(res.data && res.data.message ? res.data.message : '操作失败');
                });
            });

            // 批量删除
            $('#yimai-gso-404-red-batch-delete').off('click').on('click', function() {
                var checked = [];
                $('.yimai-gso-404-red-checkbox:checked').each(function() { checked.push($(this).data('source')); });
                if (checked.length === 0) { alert('请先选择要删除的规则'); return; }
                if (!confirm('确定删除选中的 ' + checked.length + ' 条重定向规则？')) return;
                var done = 0;
                for (var i = 0; i < checked.length; i++) {
                    (function(src) {
                        $.post(ajaxurl, { action: 'yimai_gso_404_delete_redirect', nonce: self.getNonce(), source: src }, function(res) {
                            done++;
                            if (done >= checked.length) self.loadRedirects();
                        });
                    })(checked[i]);
                }
            });

            // 更新重定向计数
            if (items) self.updateRedirectCount(items.length);
        },

        exportCSV: function() {
            var self = this;
            $.post(ajaxurl, { action: 'yimai_gso_404_export_csv', nonce: self.getNonce() }, function(res) {
                if (res.success && res.data.csv) {
                    self.downloadCSV(res.data.csv, 'yimai-gso-404-log-' + new Date().toISOString().slice(0,10) + '.csv');
                } else {
                    alert('导出失败');
                }
            });
        }
    };

    $(document).on('click', '.yimai-gso-sidebar-tab[data-tab="optimizer-404"]', function() { setTimeout(function() { Opt404.init(); }, 100); });
    // 页面加载时如果404标签已激活，自动初始化
    $(document).ready(function() { if ($('.yimai-gso-main-panel[data-tab="optimizer-404"].active').length) { Opt404.init(); } });
    window.Opt404 = Opt404;
})(jQuery);






// ══════════════════════════════════════════════════════════════
// 图片库管理 IIFE
// ══════════════════════════════════════════════════════════════
(function($) {
    'use strict';

    var IL = {
        page: 1,
        perPage: 20,
        selectedIds: [],
        groups: [],

        init: function() {
            if (!$('#yimai-gso-image-library-root').length) return;
            this.loadStats();
            this.loadGroups();
            this.loadImages();
            this.bindEvents();
        },

        getNonce: function() {
            return (typeof yimaiGsoData !== 'undefined' && yimaiGsoData.nonce) ? yimaiGsoData.nonce : '';
        },

        // ── Stats ──
        loadStats: function() {
            var self = this;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_stats',
                nonce: self.getNonce()
            }, function(res) {
                if (res.success) {
                    $('#ilib-stat-total').text(res.data.total);
                    $('#ilib-stat-groups').text(res.data.groups);
                    $('#ilib-stat-used').text(res.data.total_used);
                }
            });
        },

        // ── Groups ──
        loadGroups: function() {
            var self = this;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_groups',
                nonce: self.getNonce()
            }, function(res) {
                if (res.success) {
                    self.groups = res.data.groups || [];
                    self.renderGroupFilter();
                    self.renderGroupsPanel();
                }
            });
        },

        renderGroupFilter: function() {
            var $sel = $('#ilib-filter-group');
            $sel.find('option:not(:first)').remove();
            var flat = this.flattenGroups(this.groups);
            flat.forEach(function(g) {
                var indent = '\u00A0\u00A0'.repeat(g.depth || 0);
                $sel.append($('<option>').val(g.id).text(indent + g.name + ' (' + g.count + ')'));
            });
        },

        renderGroupsPanel: function() {
            var $list = $('#ilib-groups-list');
            $list.empty();
            if (!this.groups.length) {
                $list.html('<div style="color:#999;padding:16px;">暂无分组，点击上方"新建分组"创建</div>');
                return;
            }
            this.groups.forEach(function(g) {
                var childHtml = '';
                if (g.children && g.children.length) {
                    g.children.forEach(function(c) {
                        childHtml += '<div class="ilib-group-item ilib-group-child">' +
                            '<span class="ilib-group-name">' + esc(c.name) + '</span>' +
                            '<span class="ilib-group-count">' + c.count + '</span>' +
                            '<div class="ilib-group-actions">' +
                            '<button class="button button-small ilib-rename-group" data-id="' + c.id + '" title="重命名"><span class="dashicons dashicons-edit"></span></button>' +
                            '<button class="button button-small ilib-delete-group" data-id="' + c.id + '" title="删除"><span class="dashicons dashicons-trash"></span></button>' +
                            '</div></div>';
                    });
                }
                var html = '<div class="ilib-group-item ilib-group-parent">' +
                    '<span class="ilib-group-name">' + esc(g.name) + '</span>' +
                    '<span class="ilib-group-count">' + g.count + '</span>' +
                    '<div class="ilib-group-actions">' +
                    '<button class="button button-small ilib-add-child-group" data-parent="' + g.id + '" title="添加子分组"><span class="dashicons dashicons-plus-alt"></span></button>' +
                    '<button class="button button-small ilib-rename-group" data-id="' + g.id + '" title="重命名"><span class="dashicons dashicons-edit"></span></button>' +
                    '<button class="button button-small ilib-delete-group" data-id="' + g.id + '" title="删除"><span class="dashicons dashicons-trash"></span></button>' +
                    '</div></div>' + childHtml;
                $list.append(html);
            });
        },

        flattenGroups: function(groups, depth) {
            depth = depth || 0;
            var result = [];
            groups.forEach(function(g) {
                result.push({id: g.id, name: g.name, count: g.count, depth: depth});
                if (g.children && g.children.length) {
                    result = result.concat(IL.flattenGroups(g.children, depth + 1));
                }
            });
            return result;
        },

        // ── Images ──
        loadImages: function() {
            var self = this;
            var $grid = $('#ilib-grid');
            $grid.html('<div class="ilib-loading">加载中...</div>');

            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_list',
                nonce: self.getNonce(),
                page: self.page,
                per_page: self.perPage,
                search: $('#ilib-search').val().trim(),
                group_id: $('#ilib-filter-group').val(),
                orderby: $('#ilib-orderby').val()
            }, function(res) {
                if (!res.success) {
                    $grid.html('<div class="ilib-empty">加载失败：' + (res.data.message || '未知错误') + '</div>');
                    return;
                }
                var data = res.data;
                self.renderImages(data.items || []);
                self.renderPagination(data.page, data.pages);
            });
        },

        renderImages: function(items) {
            var $grid = $('#ilib-grid');
            $grid.empty();
            if (!items.length) {
                $grid.html('<div class="ilib-empty">暂无图片，点击"上传图片"添加</div>');
                return;
            }
            var self = this;
            items.forEach(function(img) {
                var weightClass = img.weight >= 8 ? 'ilib-weight-high' : (img.weight >= 5 ? 'ilib-weight-mid' : 'ilib-weight-low');
                var isSelected = self.selectedIds.indexOf(img.id) !== -1;
                var groupsText = (img.groups || []).join(', ') || '未分组';
                var html = '<div class="ilib-card" data-id="' + img.id + '">' +
                    '<div class="ilib-card-check' + (isSelected ? ' checked' : '') + '">' +
                    '<input type="checkbox" class="ilib-cb" value="' + img.id + '"' + (isSelected ? ' checked' : '') + '>' +
                    '</div>' +
                    '<div class="ilib-card-img">' +
                    '<img src="' + esc(img.thumb) + '" alt="' + esc(img.alt || img.title) + '" loading="lazy">' +
                    '</div>' +
                    '<div class="ilib-card-info">' +
                    '<div class="ilib-card-weight ' + weightClass + '">' + img.weight + '</div>' +
                    '<div class="ilib-card-used">已用 ' + img.used_count + ' 次</div>' +
                    '<div class="ilib-card-group">' + esc(groupsText) + '</div>' +
                    '<div class="ilib-card-actions">' +
                    '<button class="button button-small ilib-edit-img" data-id="' + img.id + '" title="编辑属性"><span class="dashicons dashicons-admin-generic"></span></button>' +
                    '<button class="button button-small ilib-del-img" data-id="' + img.id + '" title="删除"><span class="dashicons dashicons-trash"></span></button>' +
                    '</div></div></div>';
                $grid.append(html);
            });
        },

        renderPagination: function(page, pages) {
            var $pag = $('#ilib-pagination');
            $pag.empty();
            if (pages <= 1) return;
            var html = '<div class="ilib-pag">';
            if (page > 1) html += '<button class="button button-small" data-page="' + (page - 1) + '">上一页</button>';
            html += '<span class="ilib-pag-info">' + page + ' / ' + pages + '</span>';
            if (page < pages) html += '<button class="button button-small" data-page="' + (page + 1) + '">下一页</button>';
            html += '</div>';
            $pag.append(html);
        },

        // ── Selection ──
        updateBatchBar: function() {
            var $bar = $('#ilib-batch-bar');
            if (this.selectedIds.length > 0) {
                $bar.show().find('#ilib-batch-count').text('已选 ' + this.selectedIds.length + ' 张');
            } else {
                $bar.hide();
            }
        },

        toggleSelect: function(id) {
            var idx = this.selectedIds.indexOf(id);
            if (idx === -1) {
                this.selectedIds.push(id);
            } else {
                this.selectedIds.splice(idx, 1);
            }
            this.updateBatchBar();
        },

        clearSelection: function() {
            this.selectedIds = [];
            $('#ilib-grid .ilib-cb').prop('checked', false);
            $('.ilib-card-check').removeClass('checked');
            this.updateBatchBar();
        },

        // ── Upload ──
        openUploader: function() {
            var self = this;
            var frame = wp.media({
                title: '选择图片添加到图片库',
                button: { text: '添加到图片库' },
                multiple: true,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                var attachments = frame.state().get('selection').toArray();
                var added = 0;
                attachments.forEach(function(att) {
                    $.post(ajaxurl, {
                        action: 'yimai_gso_image_library_upload',
                        nonce: self.getNonce(),
                        attachment_id: att.id,
                        group_id: $('#ilib-filter-group').val()
                    }, function() { added++; });
                });
                setTimeout(function() {
                    self.loadImages();
                    self.loadStats();
                    self.loadGroups();
                }, 800);
            });
            frame.open();
        },

        // ── Group CRUD ──
        createGroup: function(name, parentId) {
            var self = this;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_group_create',
                nonce: self.getNonce(),
                name: name,
                parent_id: parentId || 0
            }, function(res) {
                if (res.success) {
                    self.loadGroups();
                    self.showToast(res.data.message || '分组已创建');
                } else {
                    self.showToast(res.data.message || '创建失败', 'error');
                }
            });
        },

        renameGroup: function(termId) {
            var self = this;
            var currentName = '';
            // Find group name from DOM
            $('#ilib-groups-list .ilib-rename-group[data-id="' + termId + '"]').each(function() {
                currentName = $(this).closest('.ilib-group-item').find('.ilib-group-name').text();
            });
            var newName = prompt('输入新分组名称：', currentName);
            if (!newName || newName === currentName) return;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_group_rename',
                nonce: self.getNonce(),
                term_id: termId,
                name: newName
            }, function(res) {
                if (res.success) {
                    self.loadGroups();
                    self.showToast(res.data.message || '已重命名');
                }
            });
        },

        deleteGroup: function(termId) {
            var self = this;
            if (!confirm('确定删除此分组？分组内的图片不会被删除，但会移至其他分组。')) return;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_group_delete',
                nonce: self.getNonce(),
                term_id: termId
            }, function(res) {
                if (res.success) {
                    self.loadGroups();
                    self.loadImages();
                    self.showToast(res.data.message || '已删除');
                }
            });
        },

        // ── Batch operations ──
        batchUpdateKeywords: function() {
            var self = this;
            var kw = prompt('输入关键词（逗号分隔）：');
            if (kw === null) return;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_batch_update',
                nonce: self.getNonce(),
                ids: self.selectedIds,
                keywords: kw
            }, function(res) {
                if (res.success) {
                    self.clearSelection();
                    self.loadImages();
                    self.showToast(res.data.message);
                }
            });
        },

        batchUpdateWeight: function() {
            var self = this;
            var w = prompt('设置权重（1-10，默认5）：');
            if (w === null) return;
            w = Math.max(1, Math.min(10, parseInt(w) || 5));
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_batch_update',
                nonce: self.getNonce(),
                ids: self.selectedIds,
                weight: w
            }, function(res) {
                if (res.success) {
                    self.clearSelection();
                    self.loadImages();
                    self.showToast(res.data.message);
                }
            });
        },

        batchUpdateGroup: function() {
            var self = this;
            var groups = self.flattenGroups(self.groups);
            if (!groups.length) {
                self.showToast('请先创建分组', 'error');
                return;
            }
            var options = groups.map(function(g) {
                var indent = '\u00A0\u00A0'.repeat(g.depth || 0);
                return g.id + ':' + indent + g.name;
            }).join('\n');
            var choice = prompt('输入分组ID：\n' + options);
            if (!choice) return;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_batch_update',
                nonce: self.getNonce(),
                ids: self.selectedIds,
                group_id: parseInt(choice)
            }, function(res) {
                if (res.success) {
                    self.clearSelection();
                    self.loadImages();
                    self.loadGroups();
                    self.showToast(res.data.message);
                }
            });
        },

        batchDelete: function() {
            var self = this;
            if (!confirm('确定删除选中的 ' + self.selectedIds.length + ' 张图片？此操作不可撤销。')) return;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_delete',
                nonce: self.getNonce(),
                ids: self.selectedIds
            }, function(res) {
                if (res.success) {
                    self.clearSelection();
                    self.loadImages();
                    self.loadStats();
                    self.showToast(res.data.message);
                }
            });
        },

        // ── Single delete ──
        deleteImage: function(id) {
            var self = this;
            if (!confirm('确定删除此图片？')) return;
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_delete',
                nonce: self.getNonce(),
                ids: [id]
            }, function(res) {
                if (res.success) {
                    self.loadImages();
                    self.loadStats();
                    self.showToast(res.data.message);
                }
            });
        },

        // ── Edit single image ──
        editImage: function(id) {
            var self = this;
            var $card = $('.ilib-card[data-id="' + id + '"]');
            var groups = self.flattenGroups(self.groups);
            var groupOptions = '<option value="0">不分组</option>' +
                groups.map(function(g) {
                    var indent = '\u00A0\u00A0'.repeat(g.depth || 0);
                    return '<option value="' + g.id + '">' + indent + esc(g.name) + '</option>';
                }).join('');

            var html = '<div class="ilib-edit-overlay">' +
                '<div class="ilib-edit-modal">' +
                '<h3>编辑图片属性</h3>' +
                '<div class="ilib-edit-field"><label>权重 (1-10)</label><input type="number" id="ilib-edit-weight" min="1" max="10" value="5"></div>' +
                '<div class="ilib-edit-field"><label>关键词（逗号分隔）</label><input type="text" id="ilib-edit-keywords" placeholder="关键词1, 关键词2"></div>' +
                '<div class="ilib-edit-field"><label>分组</label><select id="ilib-edit-group">' + groupOptions + '</select></div>' +
                '<div class="ilib-edit-buttons">' +
                '<button class="button button-primary" id="ilib-edit-save">保存</button>' +
                '<button class="button" id="ilib-edit-cancel">取消</button>' +
                '</div></div></div>';

            $('body').append(html);

            // Load current values
            $.post(ajaxurl, {
                action: 'yimai_gso_image_library_list',
                nonce: self.getNonce(),
                page: 1, per_page: 100
            }, function(res) {
                if (res.success) {
                    var img = (res.data.items || []).find(function(i) { return i.id == id; });
                    if (img) {
                        $('#ilib-edit-weight').val(img.weight);
                        $('#ilib-edit-keywords').val(img.keywords || '');
                        $('#ilib-edit-group').val((img.groups && img.groups.length) ? '' : 0); // Simplified
                    }
                }
            });

            $('#ilib-edit-save').on('click', function() {
                $.post(ajaxurl, {
                    action: 'yimai_gso_image_library_update',
                    nonce: self.getNonce(),
                    id: id,
                    weight: parseInt($('#ilib-edit-weight').val()) || 5,
                    keywords: $('#ilib-edit-keywords').val(),
                    group_id: parseInt($('#ilib-edit-group').val()) || 0
                }, function(res) {
                    $('.ilib-edit-overlay').remove();
                    if (res.success) {
                        self.loadImages();
                        self.showToast(res.data.message);
                    }
                });
            });

            $('#ilib-edit-cancel').on('click', function() {
                $('.ilib-edit-overlay').remove();
            });
        },

        // ── Toast ──
        showToast: function(msg, type) {
            var cls = type === 'error' ? 'ilib-toast-error' : 'ilib-toast-success';
            var $t = $('<div class="ilib-toast ' + cls + '">' + esc(msg) + '</div>');
            $('body').append($t);
            setTimeout(function() { $t.fadeOut(300, function() { $t.remove(); }); }, 2500);
        },

        // ── Events ──
        bindEvents: function() {
            var self = this;

            // Upload
            $('#ilib-upload-btn').on('click', function() { self.openUploader(); });

            // New group
            $('#ilib-new-group-btn').on('click', function() {
                var name = prompt('输入分组名称：');
                if (name) self.createGroup(name, 0);
            });

            // Search
            var searchTimer;
            $('#ilib-search').on('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function() { self.page = 1; self.loadImages(); }, 400);
            });

            // Filter / Sort
            $('#ilib-filter-group, #ilib-orderby').on('change', function() {
                self.page = 1;
                self.loadImages();
            });

            // Pagination
            $(document).on('click', '#ilib-pagination button', function() {
                self.page = parseInt($(this).data('page'));
                self.loadImages();
            });

            // Card selection
            $(document).on('change', '.ilib-cb', function() {
                var id = parseInt($(this).val());
                self.toggleSelect(id);
                $(this).closest('.ilib-card-check').toggleClass('checked', $(this).is(':checked'));
            });

            // Edit/Delete single
            $(document).on('click', '.ilib-edit-img', function(e) {
                e.stopPropagation();
                self.editImage(parseInt($(this).data('id')));
            });
            $(document).on('click', '.ilib-del-img', function(e) {
                e.stopPropagation();
                self.deleteImage(parseInt($(this).data('id')));
            });

            // Batch actions
            $('#ilib-batch-keywords').on('click', function() { self.batchUpdateKeywords(); });
            $('#ilib-batch-weight').on('click', function() { self.batchUpdateWeight(); });
            $('#ilib-batch-group').on('click', function() { self.batchUpdateGroup(); });
            $('#ilib-batch-delete').on('click', function() { self.batchDelete(); });
            $('#ilib-batch-cancel').on('click', function() { self.clearSelection(); });

            // Groups panel toggle
            $('#ilib-groups-toggle').on('click', function() {
                var $panel = $('#ilib-groups-panel');
                var $arrow = $('#ilib-groups-arrow');
                $panel.slideToggle(200);
                $arrow.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            });

            // Group CRUD in panel
            $(document).on('click', '.ilib-rename-group', function() {
                self.renameGroup(parseInt($(this).data('id')));
            });
            $(document).on('click', '.ilib-delete-group', function() {
                self.deleteGroup(parseInt($(this).data('id')));
            });
            $(document).on('click', '.ilib-add-child-group', function() {
                var name = prompt('输入子分组名称：');
                if (name) self.createGroup(name, parseInt($(this).data('parent')));
            });
        }
    };


    $(document).ready(function() {
        IL.init();
    });

})(jQuery);

// ===== 内容改写模块 =====
(function($) {
    'use strict';

    window.initRewriteModule = function() {
        if (window._yimaiGsoRewriteInitDone) return;
        window._yimaiGsoRewriteInitDone = true;
        init();
    };

    var state = {
        singlePostId: 0,
        batchSelected: [],
        batchPage: 1,
        batchTotalPages: 1,
        batchRunning: false,
        batchProcessed: 0,
        batchTotal: 0,
        batchSuccess: 0,
        batchFailed: 0
    };

    function escapeHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function init() {
        // 子标签切换
        $(document).on('click', '.yimai-gso-rewrite-subtab', function() {
            var tab = $(this).data('rtab');
            $('.yimai-gso-rewrite-subtab').removeClass('active');
            $(this).addClass('active');
            $('.yimai-gso-rewrite-panel').removeClass('active');
            $('.yimai-gso-rewrite-panel[data-rtab="' + tab + '"]').addClass('active');
        });

        // === 粘贴原文改写 ===
        $(document).on('click', '#yimai-gso-rw-paste-btn', doPasteRewrite);

        // === 单篇文章翻新 ===
        $(document).on('click', '#yimai-gso-rw-single-search-btn', function() { state.singlePostId = 0; searchSinglePosts(); });
        $(document).on('keypress', '#yimai-gso-rw-single-search', function(e) { if (e.which === 13) { state.singlePostId = 0; searchSinglePosts(); } });
        $(document).on('click', '.yimai-gso-rw-single-rewrite-btn', function() {
            state.singlePostId = $(this).data('id');
            $('#yimai-gso-rw-single-btn').prop('disabled', false);
            doSingleRewrite();
        });
        $(document).on('click', '#yimai-gso-rw-single-btn', doSingleRewrite);
        $(document).on('click', '.yimai-gso-rw-confirm-overwrite', function() {
            var postId = $(this).data('id');
            doSingleRewriteConfirm(postId);
        });

        // === 批量翻新 ===
        $(document).on('click', '#yimai-gso-rw-batch-search-btn', function() { state.batchPage = 1; searchBatchPosts(); });
        $(document).on('keypress', '#yimai-gso-rw-batch-search', function(e) { if (e.which === 13) { state.batchPage = 1; searchBatchPosts(); } });
        $(document).on('click', '#yimai-gso-rw-batch-clear-btn', clearBatchSelection);
        $(document).on('click', '#yimai-gso-rw-batch-start-btn', startBatchRewrite);
    }

    // ==================== 粘贴原文改写 ====================

    // ── v2.6.0 输入区增强 ──
    var $pasteTextarea = $('#yimai-gso-rw-paste-text');
    var $pasteCount = $('#yimai-gso-rw-paste-count');
    var $pasteWarn = $('#yimai-gso-rw-paste-warn');
    var $pasteClear = $('#yimai-gso-rw-paste-clear');

    function updateCharCount() {
        var len = ($pasteTextarea.val() || '').replace(/\s/g, '').length;
        $pasteCount.text(len + ' 字');
        $pasteWarn.toggle(len > 8000);
        $pasteClear.toggle(len > 0);
    }
    $pasteTextarea.on('input', updateCharCount);
    updateCharCount();

    $('#yimai-gso-rw-paste-clear').on('click', function() {
        $pasteTextarea.val(''); updateCharCount(); $pasteTextarea.focus();
    });

    $('#yimai-gso-rw-paste-sample').on('click', function() {
        var sample = '人工智能正在重塑内容创作的未来。传统的内容生产需要大量的人力投入，从选题策划到资料收集，再到撰写和校对，往往需要数小时甚至数天时间。\n\n而如今，借助大型语言模型，创作者可以在几分钟内完成初稿，把更多精力放在创意和策略层面。根据研究数据，使用AI辅助创作的团队效率平均提升40%以上。\n\n但AI并非万能。它需要正确的引导、清晰的需求描述，以及人工的最终审核。最理想的状态是：AI负责快速生成，人类负责判断和优化。这种"人机协作"模式正在成为行业共识。';
        $pasteTextarea.val(sample); updateCharCount(); $pasteTextarea.focus();
    });

    function doPasteRewrite() {
        var source = $pasteTextarea.val().trim();
        if (!source) { alert('请输入需要改写的原文内容'); return; }

        var strength = $('#yimai-gso-rw-paste-strength').val();
        var style = $('#yimai-gso-rw-paste-style').val();
        var keywords = $('#yimai-gso-rw-paste-keywords').val().trim();
        var fields = [];
        $('.yimai-gso-rw-field:checked').each(function() { fields.push($(this).val()); });

        if (fields.length === 0) { alert('请至少选择一个改写字段'); return; }

        var sourceText = source;

        $('#yimai-gso-rw-paste-btn').prop('disabled', true).text('改写中...');
        $('#yimai-gso-rw-paste-progress').show();
        $('#yimai-gso-rw-paste-result-content').html('<div style="text-align:center;padding:40px 0;color:#94a3b8">AI 改写中...</div>');
        $('#yimai-gso-rw-paste-actions').hide();
        $('#yimai-gso-rw-result-stats').hide();

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_rewrite_text', nonce: yimaiGsoData.nonce,
            source_text: source, strength: strength,
            style: style, keywords: keywords, fields: fields
        }, function(res) {
            if (!res.success) {
                $('#yimai-gso-rw-paste-result-content').html('<div style="text-align:center;padding:40px 0;color:#ef4444">❌ ' + (res.data.message || '改写失败') + '</div>');
                return;
            }
            renderPasteResult(res.data, sourceText, fields);
        }).fail(function() {
            $('#yimai-gso-rw-paste-result-content').html('<div style="text-align:center;padding:40px 0;color:#ef4444">❌ 网络请求失败</div>');
        }).always(function() {
            $('#yimai-gso-rw-paste-btn').prop('disabled', false).text('开始改写');
        });
    }

    function renderPasteResult(data, sourceText, fields) {
        $('#yimai-gso-rw-paste-progress').hide();
        var $content = $('#yimai-gso-rw-paste-result-content');
        var $actions = $('#yimai-gso-rw-paste-actions');
        var $stats = $('#yimai-gso-rw-result-stats');

        if ( ! data.content || ! data.content.trim() ) {
            $content.html('<div style="text-align:center;padding:40px 0;color:#999">⚠️ AI 未返回正文内容，请重试或更换改写力度</div>');
            return;
        }

        var previewText = data.content
            .replace(/<\/?(?:p|h[1-6]|li|div|br)[^>]*>/gi, '\n')
            .replace(/<[^>]*>/g, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
        var isLong = previewText.length > 300;
        var shortPreview = isLong ? previewText.substring(0, 300) + '...' : previewText;

        // 统计信息
        var resultLen = (previewText || '').replace(/\s/g, '').length;
        var sourceLen = (sourceText || '').replace(/<[^>]*>/g, '').replace(/\s/g, '').length;
        var similarity = '-';
        if (sourceLen > 0 && resultLen > 0) {
            var diff = computeSimpleDiff(sourceText.replace(/<[^>]*>/g, ''), previewText);
            var ratio = 1 - diff.distance / Math.max(sourceText.replace(/<[^>]*>/g, '').length, previewText.length);
            similarity = Math.round(ratio * 100);
        }
        var simColor = similarity !== '-' && similarity > 80 ? '#ef4444' : (similarity > 50 ? '#f59e0b' : '#10b981');
        $stats.html('原文 ' + sourceLen + ' 字 → ' + resultLen + ' 字 | 相似度 <strong style="color:' + simColor + '">' + similarity + '%</strong>').show();

        // 构建结果内容
        var html = '';
        if (data.title) {
            html += '<div class="yimai-gso-rw-result-title" style="margin-bottom:8px">📝 ' + escapeHtml(data.title) + '</div>';
        }
        html += '<div class="yimai-gso-rw-result-preview" style="font-size:13px;line-height:1.7;color:#334155">' + escapeHtml(shortPreview).replace(/\n/g, '<br>') + '</div>';
        if (isLong) {
            html += '<button class="button button-small yimai-gso-rw-toggle-full" style="margin-top:8px">展开全文</button>';
        }

        $content.css({textAlign:'left', color:'#334155'}).html(html);
        $actions.show();

        // 复制全文（HTML→纯文本，保留段落排版）
        $actions.find('.yimai-gso-rw-copy-btn').off('click').on('click', function() {
            var plainText = data.content
                .replace(/<\/?(?:p|h[1-6]|li|div|br)[^>]*>/gi, '\n')
                .replace(/<[^>]*>/g, '')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
            var ta = document.createElement('textarea');
            ta.value = plainText; document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            $(this).text('✅ 已复制');
            setTimeout(function() { $('.yimai-gso-rw-copy-btn').text('📋 复制全文'); }, 2000);
        });

        // 存草稿
        $actions.find('.yimai-gso-rw-draft-btn').off('click').on('click', function() {
            saveAsDraft(data, $('#yimai-gso-rw-paste-strength').val());
        });

        // 展开/收起
        $content.off('click').on('click', '.yimai-gso-rw-toggle-full', function() {
            var $preview = $(this).siblings('.yimai-gso-rw-result-preview');
            if ($(this).text() === '展开全文') {
                $preview.html(escapeHtml(previewText).replace(/\n/g, '<br>'));
                $(this).text('收起全文');
            } else {
                $preview.html(escapeHtml(shortPreview).replace(/\n/g, '<br>'));
                $(this).text('展开全文');
            }
        });
    }

    function computeSimpleDiff(source, result) {
        // 长文保护：超过2000字只比较前2000字，避免浏览器冻结
        var maxLen = 2000;
        var sShort = source, rShort = result;
        if (source.length > maxLen) sShort = source.substring(0, maxLen);
        if (result.length > maxLen) rShort = result.substring(0, maxLen);

        var sLen = sShort.length, rLen = rShort.length;
        if (sLen === 0) return { distance: rLen };
        if (rLen === 0) return { distance: sLen };

        var prev = [], curr = [];
        for (var j = 0; j <= rLen; j++) prev[j] = j;

        for (var i = 1; i <= sLen; i++) {
            curr[0] = i;
            for (var j = 1; j <= rLen; j++) {
                var cost = sShort[i-1] === rShort[j-1] ? 0 : 1;
                curr[j] = Math.min(prev[j] + 1, curr[j-1] + 1, prev[j-1] + cost);
            }
            var tmp = prev; prev = curr; curr = tmp;
        }
        return { distance: prev[rLen] };
    }

    function saveAsDraft(data, strength) {
        var category = 0; // 使用默认分类
        var $btn = $('.yimai-gso-rw-draft-btn');
        $btn.prop('disabled', true).text('保存中...');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_rewrite_save_draft', nonce: yimaiGsoData.nonce,
            title: data.title || '', content: data.content || '',
            excerpt: data.excerpt || '', tags: data.tags || '',
            category: category, strength: strength
        }, function(res) {
            if (!res.success) { alert('保存失败：' + (res.data.message || '')); return; }
            var editUrl = res.data.edit_url || '#';
            var notice = '<div class="yimai-gso-rw-draft-saved-notice">' +
                '✅ 已保存为草稿！' +
                '<a href="' + editUrl + '" target="_blank" class="button button-small" style="margin-left:auto;">📝 编辑草稿 →</a>' +
                '</div>';
            $('#yimai-gso-rw-paste-result-content').prepend(notice);
            $btn.replaceWith('<span style="color:#10b981;font-weight:500;">✅ 已存草稿</span>');
        }).fail(function() {
            alert('网络请求失败');
            $btn.prop('disabled', false).text('📝 存草稿');
        });
    }

    // ==================== 单篇文章翻新 ====================

    function searchSinglePosts() {
        var keyword = $('#yimai-gso-rw-single-search').val();
        var catId = $('#yimai-gso-rw-single-category').val();
        var $results = $('#yimai-gso-rw-single-results');
        $results.html('<div class="yimai-gso-empty"><span class="yimai-gso-loading"></span> 搜索中...</div>');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_search_posts', nonce: yimaiGsoData.nonce,
            keyword: keyword, category: catId, per_page: 20
        }, function(res) {
            if (!res.success) { $results.html('<div class="yimai-gso-empty"><p>❌ ' + escapeHtml(res.data.message || '搜索失败') + '</p></div>'); return; }
            var posts = res.data.posts || [];
            if (!posts.length) { $results.html('<div class="yimai-gso-empty"><p>没有找到匹配的文章</p></div>'); return; }
            var html = '';
            posts.forEach(function(p) {
                var pTitle = p.title || p.post_title || '(无标题)';
                var statusMap = {publish:'已发布', draft:'草稿', future:'定时', pending:'待审'};
                var pStatus = statusMap[p.post_status] || p.post_status;
                var pDate = p.post_date ? p.post_date.substring(0, 10) : '';
                html += '<div class="yimai-gso-rw-single-row">' +
                    '<span class="rw-post-title">' + escapeHtml(pTitle) + '</span>' +
                    '<span class="rw-post-status">' + pStatus + '</span>' +
                    '<span class="rw-post-date">' + pDate + '</span>' +
                    '<button class="button button-small yimai-gso-rw-single-rewrite-btn" data-id="' + (p.id || p.ID) + '">翻新</button>' +
                    '</div>';
            });
            $results.html(html);
        }).fail(function() {
            $results.html('<div class="yimai-gso-empty"><p>❌ 网络请求失败</p></div>');
        });
    }

    function doSingleRewrite() {
        var postId = state.singlePostId;
        if (!postId) { alert('请先选择一篇文章'); return; }
        var strength = $('#yimai-gso-rw-single-strength').val();

        $('#yimai-gso-rw-single-btn').prop('disabled', true).text('改写中...');
        $('#yimai-gso-rw-single-progress').show();
        $('#yimai-gso-rw-single-result').hide();

        // 始终先预览（overwrite=0），勾选状态决定「确认覆盖」按钮点击后是否直接覆盖
        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_rewrite_post', nonce: yimaiGsoData.nonce,
            post_id: postId, strength: strength, overwrite: 0
        }, function(res) {
            if (!res.success) {
                $('#yimai-gso-rw-single-progress-text').text('❌ ' + (res.data.message || '改写失败'));
                return;
            }
            renderSingleResult(res.data);
        }).fail(function() {
            $('#yimai-gso-rw-single-progress-text').text('❌ 网络请求失败');
        }).always(function() {
            $('#yimai-gso-rw-single-btn').prop('disabled', false).text('翻新所选文章');
            $('#yimai-gso-rw-single-progress').hide();
        });
    }

    function renderSingleResult(data) {
        var $result = $('#yimai-gso-rw-single-result');
        var $content = $('#yimai-gso-rw-single-result-content');

        var previewText = (data.content || '').replace(/<[^>]*>/g, '').replace(/\n{3,}/g, '\n\n').trim();
        var isLong = previewText.length > 500;
        var shortPreview = isLong ? previewText.substring(0, 500) + '...' : previewText;
        var postId = data.post_id || 0;
        var fullContent = data.content || '';
        var title = data.title || '改写结果';

        var html = '';
        html += '<div class="yimai-gso-rw-result-title">📝 ' + escapeHtml(title) + '</div>';
        html += '<div class="yimai-gso-rw-result-preview">' + escapeHtml(shortPreview).replace(/\n/g, '<br>') + '</div>';
        if (isLong) {
            html += '<button class="button button-small yimai-gso-rw-toggle-full" style="margin-bottom:12px;">展开全文</button>';
        }
        html += '<div class="yimai-gso-rw-result-actions">' +
            '<button class="button yimai-gso-rw-copy-btn">📋 复制全文</button>';

        // 始终显示确认覆盖按钮
        if (postId) {
            html += '<button class="yimai-gso-rw-overwrite-btn yimai-gso-rw-confirm-overwrite" data-id="' + postId + '">✅ 确认覆盖原文章</button>';
        }
        html += '</div>';

        $content.html(html);
        $result.show();

        // 展开/收起全文
        $content.off('click', '.yimai-gso-rw-toggle-full').on('click', '.yimai-gso-rw-toggle-full', function() {
            var $preview = $(this).siblings('.yimai-gso-rw-result-preview');
            if ($(this).text() === '展开全文') {
                $preview.html(escapeHtml(previewText).replace(/\n/g, '<br>'));
                $(this).text('收起全文');
            } else {
                $preview.html(escapeHtml(shortPreview).replace(/\n/g, '<br>'));
                $(this).text('展开全文');
            }
        });

        // 复制全文（转换为格式化的纯文本，保留段落结构）
        $content.off('click', '.yimai-gso-rw-copy-btn').on('click', '.yimai-gso-rw-copy-btn', function() {
            // 将 HTML 转换为格式化的纯文本
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = fullContent;
            // 将块级标签替换为换行，保留文本结构
            var text = tempDiv.innerHTML
                .replace(/<\/(p|div|h[1-6]|li|blockquote|pre|tr|td|th|section|article|aside|header|footer|nav|main|figure|figcaption)>\s*/gi, '\n')
                .replace(/<(p|div|h[1-6]|li|blockquote|pre|tr|td|th|section|article|aside|header|footer|nav|main|figure|figcaption)\b[^>]*>\s*/gi, '')
                .replace(/<br\s*\/?>\s*/gi, '\n')
                .replace(/<[^>]+>/g, '')
                .replace(/&nbsp;/g, ' ')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&amp;/g, '&')
                .replace(/&quot;/g, '"')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            $(this).text('✅ 已复制');
            setTimeout(function() { $content.find('.yimai-gso-rw-copy-btn').text('📋 复制全文'); }, 2000);
        });
    }

    function doSingleRewriteConfirm(postId) {
        var strength = $('#yimai-gso-rw-single-strength').val();
        var autoOverwrite = $('#yimai-gso-rw-single-overwrite').is(':checked');

        // 如果勾选了「直接覆盖」，不需要确认对话框
        if (!autoOverwrite) {
            if (!confirm('确定要将改写内容覆盖原文章吗？此操作不可撤销。')) return;
        }

        var $btn = $('.yimai-gso-rw-confirm-overwrite');
        $btn.prop('disabled', true).text('覆盖中...');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_rewrite_post', nonce: yimaiGsoData.nonce,
            post_id: postId, strength: strength, overwrite: 1
        }, function(res) {
            if (!res.success) { alert('覆盖失败：' + (res.data.message || '')); return; }
            // 显示覆盖成功
            var $result = $('#yimai-gso-rw-single-result-content');
            $result.html('<div class="yimai-gso-rw-draft-saved-notice">✅ 已直接覆盖原文章！' +
                (res.data.edit_url ? ' <a href="' + res.data.edit_url + '" target="_blank" class="button button-small" style="margin-left:auto;">查看编辑 →</a>' : '') +
                '</div>');
        }).fail(function() {
            alert('网络请求失败');
        });
    }

    // ==================== 批量翻新改写 ====================

    function searchBatchPosts() {
        var keyword = $('#yimai-gso-rw-batch-search').val();
        var catId = $('#yimai-gso-rw-batch-category').val();
        var status = $('#yimai-gso-rw-batch-status').val();
        var $results = $('#yimai-gso-rw-batch-results');
        $results.html('<div class="yimai-gso-empty"><span class="yimai-gso-loading"></span> 搜索中...</div>');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_search_posts', nonce: yimaiGsoData.nonce,
            keyword: keyword, category: catId, post_status: status, per_page: 20
        }, function(res) {
            if (!res.success) { $results.html('<div class="yimai-gso-empty"><p>❌ ' + escapeHtml(res.data.message || '搜索失败') + '</p></div>'); return; }
            var posts = res.data.posts || [];
            if (!posts.length) { $results.html('<div class="yimai-gso-empty"><p>没有找到匹配的文章</p></div>'); return; }
            var html = '<div class="yimai-gso-batch-rewrite-list">';
            posts.forEach(function(p) {
                var pId = p.id || p.ID;
                var pTitle = p.title || p.post_title || '(无标题)';
                var isSelected = state.batchSelected.indexOf(pId) >= 0;
                var statusMap = {publish:'已发布', draft:'草稿', future:'定时', pending:'待审'};
                var pStatus = statusMap[p.post_status] || p.post_status;
                html += '<label class="yimai-gso-br-post-item" style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;">' +
                    '<input type="checkbox" class="yimai-gso-rw-batch-check" value="' + pId + '" ' + (isSelected ? 'checked' : '') + '>' +
                    '<span style="flex:1;font-size:13px;">' + escapeHtml(pTitle) + '</span>' +
                    '<span style="width:70px;font-size:12px;color:#64748b;">' + pStatus + '</span>' +
                    '<span style="width:90px;font-size:11px;color:#94a3b8;">' + (p.post_date || '').substring(0,10) + '</span>' +
                    '</label>';
            });
            html += '</div>';
            $results.html(html);

            // 选中事件
            $('.yimai-gso-rw-batch-check').off('change').on('change', function() {
                var id = parseInt($(this).val());
                if ($(this).is(':checked')) {
                    if (state.batchSelected.indexOf(id) < 0) state.batchSelected.push(id);
                } else {
                    state.batchSelected = state.batchSelected.filter(function(v) { return v !== id; });
                }
                updateBatchSelectedUI();
            });

            // 更新已选状态
            updateBatchSelectedUI();
        }).fail(function() {
            $results.html('<div class="yimai-gso-empty"><p>❌ 网络请求失败</p></div>');
        });
    }

    function updateBatchSelectedUI() {
        var count = state.batchSelected.length;
        $('#yimai-gso-rw-batch-count').text(count);
        if (count > 0) {
            $('#yimai-gso-rw-batch-clear-btn').show();
            var names = [];
            state.batchSelected.forEach(function(id) {
                var $label = $('.yimai-gso-rw-batch-check[value="' + id + '"]').closest('.yimai-gso-br-post-item');
                var title = $label.length ? $label.find('span:first').text() : '文章 #' + id;
                names.push('<span style="display:inline-block;background:#e0e7ff;color:#4338ca;padding:2px 10px;border-radius:12px;font-size:12px;margin:2px;">' + escapeHtml(title) + '</span>');
            });
            $('#yimai-gso-rw-batch-selected').html(names.join(''));
        } else {
            $('#yimai-gso-rw-batch-clear-btn').hide();
            $('#yimai-gso-rw-batch-selected').html('<p style="color:#999;margin:8px 0;">从下方搜索并勾选需要翻新改写的文章</p>');
        }
        var btnText = count > 0 ? '开始批量改写（' + count + ' 篇）' : '开始批量改写';
        $('#yimai-gso-rw-batch-start-btn').text(btnText);
    }

    function clearBatchSelection() {
        state.batchSelected = [];
        $('.yimai-gso-rw-batch-check').prop('checked', false);
        updateBatchSelectedUI();
    }

    function startBatchRewrite() {
        if (state.batchRunning) return;
        if (state.batchSelected.length === 0) { alert('请至少选择一篇文章'); return; }

        state.batchRunning = true;
        state.batchProcessed = 0;
        state.batchTotal = state.batchSelected.length;
        state.batchSuccess = 0;
        state.batchFailed = 0;

        var strength = $('#yimai-gso-rw-batch-strength').val();
        var $btn = $(this);
        $btn.prop('disabled', true).text('改写中...');
        $('#yimai-gso-rw-batch-progress').show();
        $('#yimai-gso-rw-batch-logs').show().empty();
        updateBatchProgress();

        var ids = state.batchSelected.slice();
        processBatchNext(ids, 0, strength);
    }

    function processBatchNext(ids, index, strength) {
        if (index >= ids.length) {
            state.batchRunning = false;
            $('#yimai-gso-rw-batch-start-btn').prop('disabled', false).text('批量改写完成 ✅');
            // 停止进度条动画并固定到100%
            $('#yimai-gso-rw-batch-fill').css({width: '100%', animation: 'none', transition: 'none'});
            $('#yimai-gso-rw-batch-progress-text').text('已完成 ' + state.batchProcessed + '/' + state.batchTotal + ' 篇');
            addBatchLog('全部 ' + ids.length + ' 篇改写完成！成功 ' + state.batchSuccess + ' 篇，失败 ' + state.batchFailed + ' 篇', '');
            return;
        }

        var postId = ids[index];
        addBatchLog('⏳ ' + getPostTitle(postId) + ' — 改写中...', 'pending');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_batch_rewrite', nonce: yimaiGsoData.nonce,
            post_id: postId, strength: strength, index: index, total: ids.length
        }, function(res) {
            state.batchProcessed++;
            if (res.success && res.data && res.data.status === 'success') {
                state.batchSuccess++;
                var displayTitle = res.data.title || res.data.new_title || '文章 #' + postId;
                var editLink = res.data.edit_url ? ' <a href="' + res.data.edit_url + '" target="_blank" style="color:#6366f1;">编辑</a>' : '';
                addBatchLog('✅ ' + displayTitle + ' — 改写完成 (' + res.data.time + 's)' + editLink, 'success');
            } else {
                state.batchFailed++;
                var errMsg = (res.data && res.data.error) ? res.data.error : ((res.data && res.data.message) || '未知错误');
                addBatchLog('❌ ' + (getPostTitle(postId)) + ' — ' + errMsg, 'failed');
            }
            updateBatchProgress();
            processBatchNext(ids, index + 1, strength);
        }).fail(function() {
            state.batchProcessed++;
            state.batchFailed++;
            addBatchLog('❌ ' + getPostTitle(postId) + ' — 网络请求失败', 'failed');
            updateBatchProgress();
            processBatchNext(ids, index + 1, strength);
        });
    }

    function updateBatchProgress() {
        var pct = state.batchTotal > 0 ? Math.round((state.batchProcessed / state.batchTotal) * 100) : 0;
        $('#yimai-gso-rw-batch-fill').css('width', pct + '%');
        $('#yimai-gso-rw-batch-progress-text').text('已完成 ' + state.batchProcessed + '/' + state.batchTotal + ' 篇');
        var statsHtml = '<span class="yimai-gso-rw-batch-stat stat-success">✅ 成功 ' + state.batchSuccess + '</span>' +
            '<span class="yimai-gso-rw-batch-stat stat-pending">⏳ 处理中 ' + (state.batchTotal - state.batchProcessed) + '</span>' +
            '<span class="yimai-gso-rw-batch-stat stat-failed">❌ 失败 ' + state.batchFailed + '</span>';
        $('#yimai-gso-rw-batch-stats').html(statsHtml);
    }

    function addBatchLog(text, cls) {
        var $logs = $('#yimai-gso-rw-batch-logs');
        var rowClass = cls ? 'yimai-gso-rw-batch-log-item ' + cls : 'yimai-gso-rw-batch-log-item';
        $logs.append('<div class="' + rowClass + '">' + text + '</div>');
        $logs.scrollTop($logs[0].scrollHeight);
    }

    function getPostTitle(postId) {
        var $item = $('.yimai-gso-rw-batch-check[value="' + postId + '"]').closest('.yimai-gso-br-post-item');
        return $item.length ? $item.find('span:first').text() : '文章 #' + postId;
    }

    // ==================== 内容改写模块结束 ====================

})(jQuery);

// ===== 智能内链高级功能模块 =====
(function($) {
    'use strict';

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ==================== 智能内链 - 批量重建历史文章内链 ====================
    var RebuildLinks = (function() {
        var isRunning = false;
        var pollTimer = null;

        function init() {
            console.log('[YIMAI GSO] RebuildLinks.init() called');
            var $startBtn = $('#yimai-gso-rebuild-links-start');
            var $stopBtn = $('#yimai-gso-rebuild-links-stop');
            console.log('[YIMAI GSO] Start button found:', $startBtn.length > 0);
            console.log('[YIMAI GSO] Stop button found:', $stopBtn.length > 0);

            if ($startBtn.length) {
                $startBtn.off('click.yimaiGso').on('click.yimaiGso', startRebuild);
            }
            if ($stopBtn.length) {
                $stopBtn.off('click.yimaiGso').on('click.yimaiGso', stopRebuild);
            }
        }

        function startRebuild() {
            if (isRunning) return;
            isRunning = true;

            $('#yimai-gso-rebuild-links-start').hide();
            $('#yimai-gso-rebuild-links-stop').show().prop('disabled', true);
            $('#yimai-gso-rebuild-links-progress').show();
            $('#yimai-gso-rebuild-links-logs').show().empty();
            $('#yimai-gso-rebuild-links-fill').css({width: '0%', animation: 'none'});
            $('#yimai-gso-rebuild-links-progress-text').text('正在启动批量重建...');
            $('#yimai-gso-rebuild-links-progress-percent').text('0%');
            $('#yimai-gso-rebuild-links-stats').html('');
            $('#yimai-gso-rebuild-history-empty').hide();
            $('#yimai-gso-rebuild-history-list').show().empty();

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_start_rebuild_links',
                nonce: yimaiGsoData.nonce
            }, function(res) {
                if (res.success) {
                    $('#yimai-gso-rebuild-links-progress-text').text(res.data.message);
                    $('#yimai-gso-rebuild-links-stop').prop('disabled', false);
                    addRebuildLog('批量重建已启动，共 ' + res.data.total + ' 篇文章', 'info');
                    // 更新顶部统计
                    $('#yimai-gso-rebuild-stat-total').text(res.data.total || 0);
                    startPolling();
                } else {
                    isRunning = false;
                    $('#yimai-gso-rebuild-links-start').show();
                    $('#yimai-gso-rebuild-links-stop').hide();
                    $('#yimai-gso-rebuild-links-progress').hide();
                    alert(res.data.message || '启动失败');
                }
            }).fail(function(xhr, status, error) {
                isRunning = false;
                $('#yimai-gso-rebuild-links-start').show();
                $('#yimai-gso-rebuild-links-stop').hide();
                $('#yimai-gso-rebuild-links-progress').hide();
                console.error('[YIMAI GSO] Rebuild start failed:', status, error, xhr.responseText);
                alert('网络请求失败: ' + status + (error ? ' (' + error + ')' : '') + '\n请检查浏览器控制台查看详细信息。');
            });
        }

        function stopRebuild() {
            isRunning = false;
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            $('#yimai-gso-rebuild-links-start').show();
            $('#yimai-gso-rebuild-links-stop').hide();
            $('#yimai-gso-rebuild-links-fill').css({width: '100%', animation: 'none'});
            $('#yimai-gso-rebuild-links-progress-text').text('已停止');
            $('#yimai-gso-rebuild-links-progress-percent').text('');
            addRebuildLog('用户手动停止重建', 'warning');
        }

        function startPolling() {
            pollTimer = setInterval(function() {
                if (!isRunning) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                    return;
                }
                $.post(yimaiGsoData.ajaxUrl, {
                    action: 'yimai_gso_get_rebuild_progress',
                    nonce: yimaiGsoData.nonce
                }, function(res) {
                    if (!res.success || !res.data) return;
                    var data = res.data;
                    var total = data.total || 0;
                    var processed = data.processed || 0;

                    if (total > 0) {
                        var pct = Math.round((processed / total) * 100);
                        $('#yimai-gso-rebuild-links-fill').css('width', pct + '%');
                        $('#yimai-gso-rebuild-links-progress-text').text('已处理 ' + processed + '/' + total + ' 篇');
                        $('#yimai-gso-rebuild-links-progress-percent').text(pct + '%');
                        $('#yimai-gso-rebuild-links-stats').html(
                            '<span style="color:#10b981;font-weight:600;">✅ 成功: ' + (data.success || 0) + '</span>' +
                            '<span style="color:#ef4444;font-weight:600;">❌ 失败: ' + (data.failed || 0) + '</span>' +
                            '<span style="color:#64748b;">⏭️ 跳过: ' + (data.skipped || 0) + '</span>'
                        );
                        // 更新顶部统计
                        $('#yimai-gso-rebuild-stat-success').text(data.success || 0);
                        $('#yimai-gso-rebuild-stat-inserted').text(data.inserted || 0);
                    }

                    // 更新日志
                    if (data.logs && data.logs.length) {
                        $('#yimai-gso-rebuild-links-logs').empty();
                        data.logs.forEach(function(log) {
                            var cls = log.status === 'success' ? 'success' : 'failed';
                            var icon = log.status === 'success' ? '✅' : '❌';
                            addRebuildLog(icon + ' ' + escapeHtml(log.title || '') + ' — ' + escapeHtml(log.message || ''), cls);
                        });
                    }

                    // 完成或空闲状态
                    if (data.status === 'completed' || data.status === 'idle') {
                        isRunning = false;
                        clearInterval(pollTimer);
                        pollTimer = null;
                        $('#yimai-gso-rebuild-links-start').show();
                        $('#yimai-gso-rebuild-links-stop').hide();
                        $('#yimai-gso-rebuild-links-fill').css({width: '100%', animation: 'none'});
                        $('#yimai-gso-rebuild-links-progress-percent').text('100%');
                        if (data.status === 'completed') {
                            $('#yimai-gso-rebuild-links-progress-text').text('批量重建完成！');
                            addRebuildLog('批量重建完成', 'success');
                        }
                    }
                });
            }, 3000);
        }

        function addRebuildLog(text, cls) {
            var $logs = $('#yimai-gso-rebuild-links-logs');
            var color = cls === 'success' ? '#10b981' : (cls === 'failed' ? '#ef4444' : (cls === 'warning' ? '#f59e0b' : '#64748b'));
            var entry = '<div class="yimai-gso-link-log-entry" style="color:' + color + ';">' + text + '</div>';
            $logs.append(entry);
            $logs.scrollTop($logs[0].scrollHeight);
        }

        return { init: init };
    })();

    // ==================== 智能内链 - 失效链接巡检 ====================
    var BrokenLinkAudit = (function() {
        var auditPollTimer = null;

        function init() {
            console.log('[YIMAI GSO] BrokenLinkAudit.init() called');
            var $startBtn = $('#yimai-gso-broken-link-start');
            console.log('[YIMAI GSO] Audit start button found:', $startBtn.length > 0);

            if ($startBtn.length) {
                $startBtn.off('click.yimaiGso').on('click.yimaiGso', startAudit);
            }
            // 重新巡检按钮
            $(document).off('click.yimai-gso-recheck').on('click.yimai-gso-recheck', '#yimai-gso-audit-recheck', startAudit);
            $(document).off('click.yimai-gso-remove').on('click.yimai-gso-remove', '.yimai-gso-broken-link-remove', removeBrokenLink);

            // 页面加载时自动获取上次巡检结果
            loadLastAuditResult();
        }

        function loadLastAuditResult() {
            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_get_broken_link_audit_result',
                nonce: yimaiGsoData.nonce
            }, function(res) {
                if (res.success && res.data && res.data.total_links) {
                    renderAuditResult(res.data);
                }
            }).fail(function(xhr, status, error) {
                console.error('[YIMAI GSO] Load last audit result failed:', status, error);
            });
        }

        function startAudit() {
            var $btn = $('#yimai-gso-broken-link-start');
            $btn.prop('disabled', true).text('启动中...');
            $('#yimai-gso-broken-link-progress').show();
            $('#yimai-gso-broken-link-result').hide().empty();
            $('#yimai-gso-audit-empty').hide();
            $('#yimai-gso-audit-quick-actions').hide();
            $('#yimai-gso-audit-result-badge').hide();
            $('#yimai-gso-broken-link-fill').css({width: '10%', animation: 'yimai-gso-progress-pulse 1.5s infinite'});
            $('#yimai-gso-broken-link-progress-text').text('正在启动巡检...');
            $('#yimai-gso-broken-link-progress-percent').text('0%');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_start_broken_link_audit',
                nonce: yimaiGsoData.nonce
            }, function(res) {
                if (res.success) {
                    $btn.text('巡检中...');
                    $('#yimai-gso-broken-link-progress-text').text(res.data.message || '巡检进行中...');
                    startAuditPolling();
                } else {
                    $btn.prop('disabled', false).text('开始巡检');
                    $('#yimai-gso-broken-link-progress').hide();
                    alert(res.data.message || '巡检启动失败');
                }
            }).fail(function(xhr, status, error) {
                $btn.prop('disabled', false).text('开始巡检');
                $('#yimai-gso-broken-link-progress').hide();
                console.error('[YIMAI GSO] Audit start failed:', status, error, xhr.responseText);
                alert('网络请求失败: ' + status + (error ? ' (' + error + ')' : '') + '\n请检查浏览器控制台查看详细信息。');
            });
        }

        function startAuditPolling() {
            if (auditPollTimer) {
                clearInterval(auditPollTimer);
            }

            auditPollTimer = setInterval(function() {
                $.post(yimaiGsoData.ajaxUrl, {
                    action: 'yimai_gso_get_broken_link_audit_progress',
                    nonce: yimaiGsoData.nonce
                }, function(res) {
                    if (!res.success || !res.data) return;
                    var data = res.data;
                    var total = data.total || 0;
                    var checked = data.checked || 0;

                    // 更新进度
                    if (total > 0) {
                        var pct = Math.round((checked / total) * 100);
                        $('#yimai-gso-broken-link-fill').css({width: pct + '%', animation: 'none'});
                        $('#yimai-gso-broken-link-progress-text').text('已检测 ' + checked + '/' + total + ' 个链接');
                        $('#yimai-gso-broken-link-progress-percent').text(pct + '%');
                    }

                    // 完成或空闲状态
                    if (data.status === 'completed' || data.status === 'idle') {
                        clearInterval(auditPollTimer);
                        auditPollTimer = null;
                        $('#yimai-gso-broken-link-start').prop('disabled', false).text('开始巡检');
                        $('#yimai-gso-broken-link-progress').hide();

                        if (data.result) {
                            renderAuditResult(data.result);
                        }
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[YIMAI GSO] Audit poll failed:', status, error);
                });
            }, 3000);
        }

        function renderAuditResult(data) {
            var $result = $('#yimai-gso-broken-link-result');
            var html = '';

            var totalLinks = data.total_links || data.total || 0;
            var brokenCount = data.broken_count || 0;
            var okCount = Math.max(0, totalLinks - brokenCount);
            var healthRate = totalLinks > 0 ? Math.round((okCount / totalLinks) * 100) + '%' : '-';

            // 更新顶部统计
            $('#yimai-gso-audit-stat-total').text(totalLinks);
            $('#yimai-gso-audit-stat-ok').text(okCount);
            $('#yimai-gso-audit-stat-broken').text(brokenCount);
            $('#yimai-gso-audit-stat-rate').text(healthRate);

            // 更新结果徽章
            var $badge = $('#yimai-gso-audit-result-badge');
            if (brokenCount > 0) {
                $badge.text(brokenCount + ' 个失效').css({background: '#fee2e2', color: '#dc2626'}).show();
            } else {
                $badge.text('全部正常').css({background: '#d1fae5', color: '#065f46'}).show();
            }

            // 显示快速操作
            $('#yimai-gso-audit-quick-actions').show();

            html += '<div style="margin-bottom:16px;padding:14px 16px;border-radius:10px;background:' + (brokenCount > 0 ? '#fef2f2' : '#f0fdf4') + ';border:1px solid ' + (brokenCount > 0 ? '#fecaca' : '#bbf7d0') + ';">';
            html += '<strong style="font-size:14px;color:' + (brokenCount > 0 ? '#991b1b' : '#065f46') + ';">';
            if (brokenCount > 0) {
                html += '⚠️ 巡检完成：发现 ' + brokenCount + ' 个失效链接（健康度 ' + healthRate + '）';
            } else {
                html += '✅ 巡检完成：全部 ' + totalLinks + ' 个链接正常';
            }
            html += '</strong></div>';

            var brokenLinks = data.broken_links || [];
            if (brokenLinks.length > 0) {
                html += '<table class="wp-list-table widefat fixed striped" style="font-size:13px;">';
                html += '<thead><tr>';
                html += '<th>链接地址</th>';
                html += '<th>HTTP状态</th>';
                html += '<th>所在文章</th>';
                html += '<th>操作</th>';
                html += '</tr></thead><tbody>';

                brokenLinks.forEach(function(item) {
                    var postTitles = (item.post_titles || []).slice(0, 3).join(', ');
                    if ((item.post_titles || []).length > 3) {
                        postTitles += ' 等' + item.post_titles.length + '篇';
                    }
                    html += '<tr>';
                    html += '<td style="word-break:break-all;"><a href="' + escapeHtml(item.url) + '" target="_blank">' + escapeHtml(item.url) + '</a></td>';
                    html += '<td><span style="color:#ef4444;font-weight:bold;">' + (item.status || '错误') + '</span></td>';
                    html += '<td>' + escapeHtml(postTitles) + '</td>';
                    html += '<td><button type="button" class="button button-small yimai-gso-broken-link-remove" data-url="' + escapeHtml(item.url) + '">移除死链</button></td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
            }

            $result.html(html).show();
        }

        function removeBrokenLink() {
            var $btn = $(this);
            var url = $btn.data('url');
            if (!url) return;

            if (!confirm('确定要移除这个失效链接吗？链接文本将保留，但不再可点击。')) {
                return;
            }

            $btn.prop('disabled', true).text('移除中...');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_remove_broken_links',
                nonce: yimaiGsoData.nonce,
                url: url
            }, function(res) {
                if (res.success) {
                    $btn.closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    $btn.prop('disabled', false).text('移除死链');
                    alert(res.data.message || '移除失败');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('移除死链');
                alert('网络请求失败');
            });
        }

        return { init: init };
    })();

    // ==================== 智能内链 - 主开关Toggle ====================
    var InternalLinkMasterToggle = (function() {
        function init() {
            var $toggle = $('#yimai-gso-internal-link-master-toggle');
            if (!$toggle.length) return;

            $toggle.off('change.yimaiGso').on('change.yimaiGso', function() {
                var enabled = $(this).is(':checked') ? 1 : 0;
                var $label = $(this).closest('.yimai-gso-link-master-toggle');

                // 立即更新UI
                $label.toggleClass('is-on', enabled);
                $label.find('.yimai-gso-link-toggle-label').text(enabled ? '已开启' : '已关闭');

                $.post(yimaiGsoData.ajaxUrl, {
                    action: 'yimai_gso_toggle_internal_link',
                    nonce: yimaiGsoData.nonce,
                    enabled: enabled
                }, function(res) {
                    if (res.success) {
                        console.log('[YIMAI GSO] Internal link toggle:', res.data.message);
                    } else {
                        // 失败时恢复UI
                        $label.toggleClass('is-on', !enabled);
                        $label.find('.yimai-gso-link-toggle-label').text(!enabled ? '已开启' : '已关闭');
                        $toggle.prop('checked', !enabled);
                        alert(res.data.message || '切换失败');
                    }
                }).fail(function() {
                    $label.toggleClass('is-on', !enabled);
                    $label.find('.yimai-gso-link-toggle-label').text(!enabled ? '已开启' : '已关闭');
                    $toggle.prop('checked', !enabled);
                    alert('网络请求失败');
                });
            });
        }
        return { init: init };
    })();

    // 全局调试：监听智能内链按钮点击
    $(document).on('click', '#yimai-gso-rebuild-links-start, #yimai-gso-broken-link-start', function(e) {
        console.log('[YIMAI GSO] Button clicked:', this.id, 'Target:', e.target.tagName);
    });

    $(document).ready(function() {
        // 优先初始化内容改写模块（放在最前面，防止被其他模块错误中断）
        if (typeof initRewriteModule === 'function') {
            initRewriteModule();
        } else {
            console.error('[YIMAI GSO] initRewriteModule not defined');
        }

        console.log('[YIMAI GSO] Document ready - initializing smart link modules');
        if (typeof RebuildLinks !== 'undefined') {
            RebuildLinks.init();
        } else {
            console.error('[YIMAI GSO] RebuildLinks not defined');
        }
        if (typeof BrokenLinkAudit !== 'undefined') {
            BrokenLinkAudit.init();
        } else {
            console.error('[YIMAI GSO] BrokenLinkAudit not defined');
        }
        if (typeof InternalLinkMasterToggle !== 'undefined') {
            InternalLinkMasterToggle.init();
        }
    });

    // ==================== 侧边栏折叠面板 ====================
    (function() {
        var STORAGE_KEY = 'yimai_gso_sidebar_groups';
        var $groups = $('.yimai-gso-sidebar-group');

        if (!$groups.length) return;

        // 读取 localStorage 保存的展开状态
        var saved = {};
        try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e) {}

        $groups.each(function() {
            var $group = $(this);
            var groupId = $group.data('group') || '';
            var $label  = $group.find('.yimai-gso-sidebar-group-label');

            // 恢复上次状态（localStorage 优先，其次看有没有 .collapsed class）
            if (saved.hasOwnProperty(groupId)) {
                $group.toggleClass('collapsed', !saved[groupId]);
            }

            // 点击标签切换折叠
            $label.off('click.sidebarGroup').on('click.sidebarGroup', function(e) {
                e.stopPropagation();
                $group.toggleClass('collapsed');
                // 保存状态
                try {
                    var state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                    state[groupId] = !$group.hasClass('collapsed');
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                } catch(e) {}
            });
        });
    })();

    // ===============================================
    // 新鲜度监控面板
    // ===============================================
    var freshnessPage = 1;
    var freshnessSelected = {};

    function loadFreshnessStats() {
        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_get_freshness_stats',
            nonce: yimaiGsoData.nonce
        }, function(res) {
            if (!res.success) return;
            var d = res.data;
            var total = parseInt(d.total) || 0;
            var fresh = parseInt(d.fresh) || 0;
            var pct = parseFloat(d.fresh_pct) || 0;

            $('#yimai-gso-freshness-stats [data-stat="fresh"]').text(d.fresh || 0);
            $('#yimai-gso-freshness-stats [data-stat="normal"]').text(d.normal || 0);
            $('#yimai-gso-freshness-stats [data-stat="stale"]').text(d.stale || 0);
            $('#yimai-gso-freshness-stats [data-stat="old"]').text(d.old || 0);

            $('#yimai-gso-freshness-pct').text(pct + '%');
            var $bar = $('#yimai-gso-freshness-bar-fill');
            $bar.css('width', pct + '%');
            $bar.removeClass('warn danger');
            if (pct < 40) $bar.addClass('danger');
            else if (pct < 70) $bar.addClass('warn');
        }).fail(function(jqXHR, textStatus, errorThrown) {
            // 静默失败：统计卡片保持默认 "-" 显示
            console.warn('[一麦GSO] 新鲜度统计数据加载失败:', textStatus, errorThrown);
        });
    }

    function loadFreshnessPosts(page) {
        freshnessPage = page || 1;
        freshnessSelected = {};
        updateBatchBar();

        var $list = $('#yimai-gso-freshness-list');
        $list.html('<div class="yimai-gso-loading-state"><span class="yimai-gso-loading"></span>加载中...</div>');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_get_freshness_posts',
            nonce: yimaiGsoData.nonce,
            page: freshnessPage,
            level: $('#yimai-gso-f-level').val(),
            search: $('#yimai-gso-f-search').val()
        }, function(res) {
            if (!res.success) {
                $list.html('<div class="yimai-gso-empty-state">加载失败</div>');
                return;
            }
            var d = res.data;
            if (!d.posts.length) {
                $list.html('<div class="yimai-gso-empty-state">没有找到匹配的文章</div>');
                $('#yimai-gso-freshness-pagination').html('');
                return;
            }
            renderFreshnessTable(d.posts);
            renderFreshnessPagination(d);
        }).fail(function(jqXHR, textStatus, errorThrown) {
            $list.html('<div class="yimai-gso-empty-state">加载失败，请检查网络连接后重试</div>');
            console.warn('[一麦GSO] 新鲜度文章列表加载失败:', textStatus, errorThrown);
        });
    }

    function renderFreshnessTable(posts) {
        var html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        html += '<th style="width:40px;"><input type="checkbox" id="yimai-gso-f-check-all" class="yimai-gso-f-checkbox"></th>';
        html += '<th>文章标题</th>';
        html += '<th style="width:90px;">类型</th>';
        html += '<th style="width:100px;">新鲜度</th>';
        html += '<th style="width:90px;">距上次更新</th>';
        html += '<th style="width:60px;">得分</th>';
        html += '<th style="width:100px;">操作</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < posts.length; i++) {
            var p = posts[i];
            html += '<tr data-id="' + p.id + '">';
            html += '<td><input type="checkbox" class="yimai-gso-f-checkbox yimai-gso-f-row-cb" data-id="' + p.id + '"></td>';
            html += '<td><strong><a href="' + (p.edit_url || '#') + '" target="_blank">' + escapeHtml(p.title || '(无标题)') + '</a></strong></td>';
            html += '<td><span style="font-size:12px;color:#64748b;">' + escapeHtml(p.type) + '</span></td>';
            html += '<td><span class="yimai-gso-freshness-badge" style="background:' + p.color + '">' + p.label + '</span></td>';
            html += '<td><span style="font-size:13px;color:#475569;">' + p.days + ' 天</span></td>';
            html += '<td><strong style="color:' + p.color + '">' + p.score + '/7</strong></td>';
            html += '<td><button class="button button-small yimai-gso-f-refresh-btn" data-id="' + p.id + '">标记已审核</button></td>';
            html += '</tr>';
        }

        html += '</tbody></table>';
        $('#yimai-gso-freshness-list').html(html);

        // 全选/取消
        $('#yimai-gso-f-check-all').on('change', function() {
            var checked = $(this).prop('checked');
            $('.yimai-gso-f-row-cb').prop('checked', checked).each(function() {
                var id = $(this).data('id');
                if (checked) freshnessSelected[id] = true;
                else delete freshnessSelected[id];
            });
            updateBatchBar();
        });

        // 单行勾选
        $('.yimai-gso-f-row-cb').on('change', function() {
            var id = $(this).data('id');
            if ($(this).prop('checked')) freshnessSelected[id] = true;
            else delete freshnessSelected[id];
            updateBatchBar();
        });

        // 单篇刷新
        $('.yimai-gso-f-refresh-btn').on('click', function() {
            var $btn = $(this);
            var postId = $btn.data('id');
            $btn.prop('disabled', true).text('处理中...');

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_refresh_content',
                nonce: yimaiGsoData.nonce,
                post_id: postId
            }, function(res) {
                if (res.success) {
                    loadFreshnessStats();
                    loadFreshnessPosts(freshnessPage);
                } else {
                    alert(res.data && res.data.message ? res.data.message : '操作失败');
                    $btn.prop('disabled', false).text('标记已审核');
                }
            });
        });
    }

    function renderFreshnessPagination(d) {
        var html = '';
        if (d.pages <= 1) {
            $('#yimai-gso-freshness-pagination').html('');
            return;
        }
        html += '<span class="displaying-num">共 ' + d.total + ' 篇</span>';
        if (d.page > 1) {
            html += '<button class="button yimai-gso-f-page-btn" data-page="' + (d.page - 1) + '">&laquo; 上一页</button>';
        }
        for (var i = 1; i <= d.pages; i++) {
            if (i === d.page) {
                html += '<span class="button button-primary">' + i + '</span>';
            } else if (Math.abs(i - d.page) <= 2 || i === 1 || i === d.pages) {
                html += '<button class="button yimai-gso-f-page-btn" data-page="' + i + '">' + i + '</button>';
            } else if (Math.abs(i - d.page) === 3) {
                html += '<span class="yimai-gso-page-dots">...</span>';
            }
        }
        if (d.page < d.pages) {
            html += '<button class="button yimai-gso-f-page-btn" data-page="' + (d.page + 1) + '">下一页 &raquo;</button>';
        }
        $('#yimai-gso-freshness-pagination').html(html);

        $('.yimai-gso-f-page-btn').on('click', function() {
            loadFreshnessPosts(parseInt($(this).data('page')));
        });
    }

    function updateBatchBar() {
        var ids = Object.keys(freshnessSelected);
        var $info = $('#yimai-gso-f-batch-info');
        if (ids.length > 0) {
            $info.show();
            $('#yimai-gso-f-selected-count').text(ids.length);
        } else {
            $info.hide();
        }
    }

    // 注意：escapeHtml 已在文件顶部定义（行12），此处不再重复定义，避免覆盖

    // 筛选按钮
    $('#yimai-gso-f-search-btn').on('click', function() {
        loadFreshnessPosts(1);
        loadFreshnessStats();
    });

    // 重置按钮
    $('#yimai-gso-f-reset-btn').on('click', function() {
        $('#yimai-gso-f-level').val('');
        $('#yimai-gso-f-search').val('');
        loadFreshnessPosts(1);
        loadFreshnessStats();
    });

    // 搜索回车
    $('#yimai-gso-f-search').on('keypress', function(e) {
        if (e.key === 'Enter') loadFreshnessPosts(1);
    });

    // 等级下拉变化
    $('#yimai-gso-f-level').on('change', function() {
        loadFreshnessPosts(1);
        loadFreshnessStats();
    });

    // 全选当前页
    $('#yimai-gso-f-select-all').on('click', function() {
        $('.yimai-gso-f-row-cb').prop('checked', true).each(function() {
            freshnessSelected[$(this).data('id')] = true;
        });
        updateBatchBar();
    });

    // 批量标记
    $('#yimai-gso-f-batch-fresh').on('click', function() {
        var ids = Object.keys(freshnessSelected);
        if (!ids.length) return;

        if (!confirm('确定要将选中的 ' + ids.length + ' 篇文章标记为已审核吗？\n这将更新文章的修改时间。')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('处理中...');

        $.post(yimaiGsoData.ajaxUrl, {
            action: 'yimai_gso_batch_mark_fresh',
            nonce: yimaiGsoData.nonce,
            post_ids: ids
        }, function(res) {
            $btn.prop('disabled', false).text('标记为已审核');
            if (res.success) {
                loadFreshnessStats();
                loadFreshnessPosts(freshnessPage);
            } else {
                alert(res.data && res.data.message ? res.data.message : '操作失败');
            }
        });
    });

    // 暴露新鲜度函数到全局作用域，供外部 IIFE 中的事件委托调用
    window.loadFreshnessStats = loadFreshnessStats;
    window.loadFreshnessPosts = loadFreshnessPosts;

    // ==================== SEO-GEO 桥接管理模块 ====================
    var SEOGeoBridge = (function () {
        'use strict';

        var $panel = $('#yimai-gso-bridge-conflict-list').closest('.yimai-gso-main-panel');
        var isScanning = false;

        // ---- 页面加载 ----
        function init() {
            // 只有在面板可见时才加载（元素始终存在，但只有 active 时才真正显示）
            if ($('.yimai-gso-main-panel[data-tab="seo-geo-bridge"].active').length === 0) return;
            loadStatus();
            loadConflicts();
        }

        // ---- 加载桥接状态摘要 ----
        function loadStatus() {
            $.post(ajaxurl, {
                action: 'yimai_gso_seo_geo_get_status',
                nonce: yimaiGsoData.nonce
            }, function (res) {
                if (!res.success) return;
                var d = res.data;

                // SEO 插件卡片
                renderPluginCards(d.plugin_info || {}, d.has_seo_plugin);

                // 状态仪表盘
                updateStatCard('meta', d.meta_check_count > 0 ? d.meta_check_count : '--', d.meta_check_count > 0 ? 'good' : '');
                updateStatCard('robots', d.crawler_count > 0 ? d.crawler_count : '--', d.crawler_count > 0 ? 'good' : 'warn');
                updateStatCard('sitemap', d.sitemap_enhanced ? '已启用' : '--', d.sitemap_enhanced ? 'good' : '');
                updateStatCard('schema', d.schema_status === 'disabled_by_conflict' ? '已禁用' : '正常', d.schema_status === 'disabled_by_conflict' ? 'warn' : 'good');
                updateStatCard('compat', d.has_seo_plugin ? '已集成' : '无需', 'good');
            });
        }

        function updateStatCard(key, value, cssClass) {
            var $card = $('#yimai-gso-bridge-stat-' + key);
            $card.find('.yimai-gso-bridge-stat-value').text(value);
            $card.removeClass('good warn bad');
            if (cssClass) $card.addClass(cssClass);
        }

        function renderPluginCards(plugins, hasAny) {
            var $container = $('#yimai-gso-bridge-plugin-cards');
            var $noPlugin = $('#yimai-gso-bridge-no-plugin');

            if (!hasAny || $.isEmptyObject(plugins)) {
                $container.hide();
                $noPlugin.show();
                return;
            }

            $noPlugin.hide();
            $container.show().empty();

            $.each(plugins, function (key, info) {
                var card = $('<div class="yimai-gso-bridge-plugin-card compatible">');
                card.append('<div class="plugin-name">' + esc(info.name || key) + '</div>');
                if (info.version) {
                    card.append('<div class="plugin-version">v' + esc(info.version) + '</div>');
                }
                card.append('<div class="plugin-status" style="color:#10b981;">● 兼容正常</div>');
                $container.append(card);
            });
        }

        // ---- 加载冲突列表 ----
        function loadConflicts() {
            var $list = $('#yimai-gso-bridge-conflict-list');
            $list.html('<div style="padding:16px;color:#94a3b8;font-size:0.85rem;text-align:center;">检测中...</div>');

            $.post(ajaxurl, {
                action: 'yimai_gso_seo_geo_get_conflicts',
                nonce: yimaiGsoData.nonce
            }, function (res) {
                if (!res.success) {
                    $list.html('<div style="padding:16px;color:#ef4444;font-size:0.85rem;text-align:center;">检测失败：' + esc(res.data.message || '') + '</div>');
                    return;
                }
                renderConflicts(res.data.conflicts || [], res.data.fixable || 0);
            }).fail(function () {
                $list.html('<div style="padding:16px;color:#ef4444;font-size:0.85rem;text-align:center;">网络错误，请重试</div>');
            });
        }

        function renderConflicts(conflicts, fixable) {
            var $list = $('#yimai-gso-bridge-conflict-list');
            var $fixAll = $('#yimai-gso-bridge-fix-all-btn');

            if (conflicts.length === 0) {
                $list.html('<div style="padding:16px;text-align:center;color:#10b981;font-size:0.85rem;">✅ 未检测到冲突，SEO 与 GEO 协同正常</div>');
                $fixAll.hide();
                return;
            }

            $list.empty();

            $.each(conflicts, function (i, c) {
                var severityLabel = { 'info': '信息', 'low': '低', 'medium': '中', 'high': '高' }[c.severity] || c.severity;
                var severityClass = c.severity;

                var item = $('<div class="yimai-gso-bridge-conflict-item severity-' + severityClass + '">');

                var header = $('<div class="yimai-gso-bridge-conflict-header">');
                header.append('<span class="yimai-gso-bridge-conflict-title">' + esc(c.title) + '</span>');

                var badgeRow = $('<span>');
                badgeRow.append('<span class="yimai-gso-bridge-severity-badge ' + severityClass + '">' + severityLabel + '</span>');

                if (c.fixed) {
                    badgeRow.append(' <span class="yimai-gso-bridge-fixed-badge">已处理</span>');
                } else if (c.auto_fix) {
                    var fixBtn = $('<button type="button" class="button button-small yimai-gso-bridge-fix-btn">修复</button>');
                    // IIFE 锁定当前迭代的 c.id 和 fixBtn，避免 var 函数级作用域闭包陷阱
                    (function(conflictId, $fixBtn) {
                        fixBtn.on('click', function () { applyFix(conflictId, $fixBtn); });
                    })(c.id, fixBtn);
                    badgeRow.append(' ');
                    badgeRow.append(fixBtn);
                }
                header.append(badgeRow);
                item.append(header);

                item.append('<div class="yimai-gso-bridge-conflict-desc">' + esc(c.description) + '</div>');
                $list.append(item);
            });

            if (fixable > 0) {
                $fixAll.show().off('click').on('click', applyAllFixes);
            } else {
                $fixAll.hide();
            }
        }

        // ---- 执行单个修复 ----
        function applyFix(conflictId, $btn) {
            if ($btn.prop('disabled')) return;
            $btn.prop('disabled', true).text('修复中...');

            $.post(ajaxurl, {
                action: 'yimai_gso_seo_geo_apply_fix',
                nonce: yimaiGsoData.nonce,
                conflict_id: conflictId
            }, function (res) {
                if (res.success) {
                    alert(res.data.message || '修复成功');
                    loadConflicts();
                    loadStatus();
                } else {
                    alert('修复失败：' + (res.data.message || '未知错误'));
                    $btn.prop('disabled', false).text('修复');
                }
            }).fail(function () {
                alert('网络错误，请重试');
                $btn.prop('disabled', false).text('修复');
            });
        }

        // ---- 一键修复 ----
        function applyAllFixes() {
            if (!confirm('将自动修复所有可自动处理的冲突项，确定继续？')) return;

            var $btn = $('#yimai-gso-bridge-fix-all-btn');
            $btn.prop('disabled', true).text('修复中...');

            // 获取可修复冲突列表
            $.post(ajaxurl, {
                action: 'yimai_gso_seo_geo_get_conflicts',
                nonce: yimaiGsoData.nonce
            }, function (res) {
                if (!res.success) { alert('获取冲突列表失败'); $btn.prop('disabled', false).text('一键修复'); return; }

                var fixableIds = [];
                $.each(res.data.conflicts || [], function (i, c) {
                    if (c.auto_fix && !c.fixed) fixableIds.push(c.id);
                });

                if (fixableIds.length === 0) {
                    alert('没有可自动修复的冲突');
                    $btn.prop('disabled', false).text('一键修复');
                    return;
                }

                applyFixesBatch(fixableIds, 0, $btn);
            });
        }

        function applyFixesBatch(ids, index, $btn) {
            if (index >= ids.length) {
                $btn.prop('disabled', false).text('一键修复');
                loadConflicts();
                loadStatus();
                return;
            }

            $.post(ajaxurl, {
                action: 'yimai_gso_seo_geo_apply_fix',
                nonce: yimaiGsoData.nonce,
                conflict_id: ids[index]
            }, function () {
                applyFixesBatch(ids, index + 1, $btn);
            }).fail(function () {
                applyFixesBatch(ids, index + 1, $btn);
            });
        }

        // ---- 全站扫描 ----
        function runScan() {
            if (isScanning) return;
            isScanning = true;

            var $btn = $('#yimai-gso-bridge-scan-btn');
            var $progress = $('#yimai-gso-bridge-scan-progress');
            var $bar = $('#yimai-gso-bridge-scan-bar');
            var $text = $('#yimai-gso-bridge-scan-text');

            $btn.prop('disabled', true);
            $progress.show();
            $bar.css('width', '0%');
            $text.text('正在扫描 Meta 一致性...');

            scanBatch(0);
        }

        function scanBatch(offset) {
            $.post(ajaxurl, {
                action: 'yimai_gso_seo_geo_run_scan',
                nonce: yimaiGsoData.nonce,
                offset: offset
            }, function (res) {
                if (!res.success) {
                    finishScan('扫描失败');
                    return;
                }

                var d = res.data;
                var pct = d.total > 0 ? Math.min(100, Math.round(d.scan_total / d.total * 100)) : 100;
                $('#yimai-gso-bridge-scan-bar').css('width', pct + '%');
                $('#yimai-gso-bridge-scan-text').text('已扫描 ' + d.scan_total + ' 篇文章...');

                if (d.has_more && d.scan_total < 50) {
                    // 继续扫描下一批
                    setTimeout(function () { scanBatch(d.offset); }, 300);
                } else {
                    finishScan('扫描完成，已检查 ' + d.scan_total + ' 篇文章');
                    loadConflicts();
                    loadStatus();
                }
            }).fail(function () {
                finishScan('扫描中断，请重试');
            });
        }

        function finishScan(msg) {
            isScanning = false;
            $('#yimai-gso-bridge-scan-btn').prop('disabled', false);
            $('#yimai-gso-bridge-scan-text').text(msg);
            setTimeout(function () {
                $('#yimai-gso-bridge-scan-progress').fadeOut();
            }, 2000);
        }

        // ---- 事件绑定 ----
        $('#yimai-gso-bridge-scan-btn').on('click', runScan);

        // 根据可见容器里是否包含我们的 panel 来判断 tab 切入
        $(document).on('click', '[data-tab="seo-geo-bridge"]', function () {
            setTimeout(function () {
                if ($('.yimai-gso-main-panel[data-tab="seo-geo-bridge"].active').length > 0) {
                    init();
                }
            }, 100);
        });

        // 首次进入页面的兜底
        if ($('.yimai-gso-main-panel[data-tab="seo-geo-bridge"].active').length > 0) {
            init();
        }

        // 暴露给外部
        return { init: init, loadConflicts: loadConflicts, runScan: runScan };
    })();

})(jQuery);
