/**
 * 一麦GSO L5 监控与仪表盘 - 前端图表与交互
 *
 * 依赖: Chart.js 4.x (CDN), wpaipL5Data (wp_localize_script)
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */
(function ($) {
    'use strict';

    // 防御：wpaipL5Data 由 PHP 通过 wp_add_inline_script 注入，防止动态加载时缺失
    if (typeof wpaipL5Data === 'undefined') {
        console.error('[一麦GSO L5] wpaipL5Data 未定义，仪表盘无法初始化');
        return;
    }

    var AJAX_URL = wpaipL5Data.ajax_url;
    var NONCE    = wpaipL5Data.nonce;
    var ACTIVE_TAB = wpaipL5Data.tab || '';

    // ==================== 工具函数 ====================

    function ajaxGet(action, data, callback) {
        data = data || {};
        data.action = action;
        data.security = NONCE;
        $.get(AJAX_URL, data, callback, 'json')
         .fail(function (jqXHR) {
             console.warn('[一麦GSO L5] AJAX GET ' + action + ' failed: ' + jqXHR.status);
         });
    }

    function ajaxPost(action, data, callback) {
        data = data || {};
        data.action = action;
        data.security = NONCE;
        $.post(AJAX_URL, data, callback, 'json')
         .fail(function (jqXHR) {
             console.warn('[一麦GSO L5] AJAX POST ' + action + ' failed: ' + jqXHR.status);
         });
    }

    function formatNum(n) {
        if (n >= 10000) return (n / 10000).toFixed(1) + '万';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
        return n.toString();
    }

    // ==================== Chart.js 统一配置 ====================

    var CHART_COLORS = {
        seo:  { a:'#22c55e', b:'#3b82f6', c:'#f59e0b', d:'#f97316', f:'#ef4444' },
        geo:  { a:'#7c3aed', b:'#8b5cf6', c:'#a78bfa', d:'#c4b5fd', f:'#ddd6fe' },
        schema: ['#6366f1','#22c55e','#3b82f6','#f59e0b','#ec4899','#8b5cf6'],
        crawler: { openai:'#10a37f', google:'#4285f4', anthropic:'#d97706', perplexity:'#2563eb',
                   deepseek:'#4f46e5', bytedance:'#e11d48', microsoft:'#00a4ef', baidu:'#de3918' }
    };

    // 仓库存储已创建的 Chart 实例，便于销毁
    var chartInstances = {};

    function destroyChart(key) {
        if (chartInstances[key]) {
            chartInstances[key].destroy();
            delete chartInstances[key];
        }
    }

    function createChart(canvasId, config) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return null;
        destroyChart(canvasId);
        var ctx = canvas.getContext('2d');
        var chart = new Chart(ctx, config);
        chartInstances[canvasId] = chart;
        return chart;
    }

    // ==================== 初始化入口 ====================

    function init() {
        switch (ACTIVE_TAB) {
            case 'l5-overview':  initOverviewTab();  break;
            case 'l5-crawler':   initCrawlerTab();   break;
            case 'l5-diagnosis': initDiagnosisTab(); break;
            case 'l5-alerts':    initAlertsTab();    break;
        }
    }

    // 暴露全局入口供 admin.js 动态加载后调用
    var _l5InitedTabs = {};
    window.wpaipL5Init = function (tab) {
        // 每次切换都强制重跑，刷新数据（避免缓存导致用户看不到最新数据）
        // 但需要先销毁旧图表避免 Canvas 残留
        Object.keys(chartInstances).forEach(function (k) { destroyChart(k); });
        // 清理 setInterval 防止内存泄漏
        if (typeof crawlerRefreshTimer !== 'undefined' && crawlerRefreshTimer) {
            clearInterval(crawlerRefreshTimer);
            crawlerRefreshTimer = null;
        }
        ACTIVE_TAB = tab;
        switch (tab) {
            case 'l5-overview':  initOverviewTab();  break;
            case 'l5-crawler':   initCrawlerTab();   break;
            case 'l5-diagnosis': initDiagnosisTab(); break;
            case 'l5-alerts':    initAlertsTab();    break;
        }
    };

    // ==================== 1. 综合仪表盘 (overview) ====================

    function initOverviewTab() {
        // 加载总览统计条
        loadOverviewStats();

        // 加载各图表
        loadSEODistribution();
        loadGEODistribution();
        loadSchemaCoverage();
        loadTrafficTrend();
        loadSEOGeoScatter();
    }

    function loadOverviewStats() {
        ajaxGet('wpaip_l5_overview_stats', {}, function (resp) {
            if (!resp || !resp.success) return;
            var d = resp.data;
            var $row = $('#wpaip-l5-overview-stats');

            var cards = [
                { val: d.seo_avg,        label: 'SEO 均分', color: '#ea580c' },
                { val: d.geo_avg,        label: 'GEO 均分', color: '#7c3aed' },
                { val: d.schema_pct + '%', label: 'Schema 覆盖率', color: '#2563eb' },
                { val: formatNum(d.crawler_total),  label: 'AI爬虫访问(30d)', color: '#059669' },
                { val: formatNum(d.referral_total),  label: 'AI引荐流量(30d)', color: '#d97706' },
                { val: d.alert_count,     label: '活跃预警', color: d.alert_count > 0 ? '#dc2626' : '#22c55e' }
            ];

            var html = '';
            cards.forEach(function (c) {
                html += '<div class="wpaip-l5-stat-card"><div class="wpaip-l5-stat-value" style="color:' + c.color + '">' + c.val + '</div><div class="wpaip-l5-stat-label">' + c.label + '</div></div>';
            });
            $row.html(html);
        });
    }

    function loadSEODistribution() {
        ajaxGet('wpaip_l5_seo_distribution', {}, function (resp) {
            if (!resp || !resp.success) {
                console.warn('[一麦GSO L5] SEO分布加载失败', resp);
                return;
            }
            var d = resp.data;
            createChart('wpaip-l5-seo-chart', {
                type: 'doughnut',
                data: {
                    labels: ['A (优秀)', 'B (良好)', 'C (中等)', 'D (较差)', 'F (很差)'],
                    datasets: [{
                        data: [d.A, d.B, d.C, d.D, d.F],
                        backgroundColor: [CHART_COLORS.seo.a, CHART_COLORS.seo.b, CHART_COLORS.seo.c, CHART_COLORS.seo.d, CHART_COLORS.seo.f],
                        borderWidth: 2, borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, font: { size: 11 } } },
                        title: { display: true, text: '均分 ' + d.avg + ' | 已评分 ' + d.total + ' 篇', font: { size: 12 }, padding: 8 }
                    }
                }
            });
        });
    }

    function loadGEODistribution() {
        ajaxGet('wpaip_l5_geo_distribution', {}, function (resp) {
            if (!resp || !resp.success) return;
            var d = resp.data;
            createChart('wpaip-l5-geo-chart', {
                type: 'doughnut',
                data: {
                    labels: ['A (优秀)', 'B (良好)', 'C (中等)', 'D (较差)', 'F (很差)'],
                    datasets: [{
                        data: [d.A, d.B, d.C, d.D, d.F],
                        backgroundColor: [CHART_COLORS.geo.a, CHART_COLORS.geo.b, CHART_COLORS.geo.c, CHART_COLORS.geo.d, CHART_COLORS.geo.f],
                        borderWidth: 2, borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, font: { size: 11 } } },
                        title: { display: true, text: '均分 ' + d.avg + ' | 已评分 ' + d.total + ' 篇', font: { size: 12 }, padding: 8 }
                    }
                }
            });
        });
    }

    function loadSchemaCoverage() {
        ajaxGet('wpaip_l5_schema_coverage', {}, function (resp) {
            if (!resp || !resp.success) return;
            var d = resp.data;
            var covered = d.covered || 0;
            var uncovered = d.uncovered || 0;
            var types = d.types || {};

            createChart('wpaip-l5-schema-chart', {
                type: 'bar',
                data: {
                    labels: ['Article', 'FAQPage', 'HowTo', 'Product', '未覆盖'],
                    datasets: [{
                        label: '文章数',
                        data: [types.Article||0, types.FAQPage||0, types.HowTo||0, types.Product||0, uncovered],
                        backgroundColor: CHART_COLORS.schema,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: '覆盖率 ' + Math.round(covered/(covered+uncovered||1)*100) + '% (' + covered + '/' + (covered+uncovered) + ')', font: { size: 12 }, padding: 8 }
                    },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        });
    }

    function loadTrafficTrend(days) {
        days = days || 30;
        ajaxGet('wpaip_l5_traffic_trend', { days: days }, function (resp) {
            if (!resp || !resp.success) return;
            var d = resp.data;
            var crawler = d.crawler || [];
            var referral = d.referral || [];

            var labels = crawler.map(function (r) { return r.date; });
            var cData = crawler.map(function (r) { return parseInt(r.visits) || 0; });
            var rData = [];
            // 对齐 referral 和 crawler 的日期
            labels.forEach(function (date) {
                var found = referral.filter(function (r) { return r.date === date; });
                rData.push(found.length > 0 ? parseInt(found[0].visits) || 0 : 0);
            });

            createChart('wpaip-l5-traffic-chart', {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'AI爬虫访问量', data: cData, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.1)', fill: true, tension: 0.3, yAxisID: 'y' },
                        { label: 'AI引荐流量',  data: rData, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.1)', fill: true, tension: 0.3, yAxisID: 'y1' }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } } },
                    scales: {
                        y:  { type: 'linear', position: 'left',  title: { display: true, text: '爬虫访问量' }, beginAtZero: true },
                        y1: { type: 'linear', position: 'right', title: { display: true, text: '引荐流量' }, beginAtZero: true, grid: { drawOnChartArea: false } }
                    }
                }
            });
        });
    }

    function loadSEOGeoScatter() {
        ajaxGet('wpaip_l5_seo_geo_scatter', {}, function (resp) {
            if (!resp || !resp.success) return;
            var points = resp.data || [];

            createChart('wpaip-l5-scatter-chart', {
                type: 'scatter',
                data: {
                    datasets: [{
                        label: '文章',
                        data: points.map(function (p) { return { x: p.x, y: p.y }; }),
                        backgroundColor: points.map(function (p) {
                            if (p.x >= 70 && p.y >= 70) return CHART_COLORS.seo.a;
                            if (p.x >= 55 && p.y >= 55) return CHART_COLORS.seo.b;
                            if (p.x >= 40 && p.y >= 40) return CHART_COLORS.seo.c;
                            return CHART_COLORS.seo.d;
                        }),
                        pointRadius: 5, pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var p = points[ctx.dataIndex];
                                    return (p ? p.label : '') + ' | SEO:' + ctx.raw.x + ' GEO:' + ctx.raw.y;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { title: { display: true, text: 'SEO 评分' }, min: 0, max: 100 },
                        y: { title: { display: true, text: 'GEO 评分' }, min: 0, max: 100 }
                    }
                }
            });
        });
    }

    // ==================== 2. AI爬虫仪表盘 (crawler) ====================

    var crawlerRefreshTimer = null;

    function initCrawlerTab() {
        var currentDays = 30;

        // 清理之前的定时器
        if (crawlerRefreshTimer) { clearInterval(crawlerRefreshTimer); crawlerRefreshTimer = null; }

        // 时间范围切换
        $('.wpaip-l5-time-btn').off('click').on('click', function () {
            $('.wpaip-l5-time-btn').removeClass('active');
            $(this).addClass('active');
            currentDays = parseInt($(this).data('days'));
            refreshCrawlerData(currentDays);
        });

        refreshCrawlerData(currentDays);

        // 30秒自动刷新（仅在crawler tab活跃时）
        crawlerRefreshTimer = setInterval(function () {
            if (ACTIVE_TAB === 'l5-crawler') refreshCrawlerData(currentDays);
        }, 30000);

        // 页面离开时清理
        $(window).on('beforeunload.l5', function () {
            if (crawlerRefreshTimer) { clearInterval(crawlerRefreshTimer); crawlerRefreshTimer = null; }
        });
    }

    function refreshCrawlerData(days) {
        loadCrawlerStats(days);
        loadCrawlerTrend(days);
        loadCrawlerFamily(days);
        loadCrawlerRanking(days);
        loadCrawlerTopPages(days);
        loadCrawlerRecent();
    }

    function loadCrawlerStats(days) {
        ajaxGet('wpaip_l5_crawler_stats', { days: days }, function (resp) {
            if (!resp || !resp.success) return;
            var d = resp.data;
            $('#wpaip-l5-crawler-stats').html(
                '<div class="wpaip-l5-stat-card"><div class="wpaip-l5-stat-value" style="color:#7c3aed">' + formatNum(d.total_visits) + '</div><div class="wpaip-l5-stat-label">总访问量</div></div>' +
                '<div class="wpaip-l5-stat-card"><div class="wpaip-l5-stat-value" style="color:#2563eb">' + d.unique_crawlers + '</div><div class="wpaip-l5-stat-label">活跃爬虫</div></div>' +
                '<div class="wpaip-l5-stat-card"><div class="wpaip-l5-stat-value" style="color:#059669">' + d.unique_pages + '</div><div class="wpaip-l5-stat-label">被爬页面</div></div>' +
                '<div class="wpaip-l5-stat-card"><div class="wpaip-l5-stat-value" style="color:#d97706">' + d.today_count + '</div><div class="wpaip-l5-stat-label">今日访问</div></div>'
            );
        });
    }

    function loadCrawlerTrend(days) {
        ajaxGet('wpaip_l5_crawler_trend', { days: days }, function (resp) {
            if (!resp || !resp.success) return;
            var data = resp.data || [];
            createChart('wpaip-l5-crawler-trend-chart', {
                type: 'line',
                data: {
                    labels: data.map(function (r) { return r.date; }),
                    datasets: [
                        { label: '访问量', data: data.map(function (r) { return parseInt(r.visits) || 0; }), borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.08)', fill: true, tension: 0.3 },
                        { label: '活跃爬虫数', data: data.map(function (r) { return parseInt(r.unique_crawlers) || 0; }), borderColor: '#f59e0b', borderDash: [5,5], tension: 0.3, yAxisID: 'y1' }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: {size:11} } } },
                    scales: {
                        y:  { beginAtZero: true, title: { display: true, text: '访问量' } },
                        y1: { beginAtZero: true, position: 'right', title: { display: true, text: '活跃爬虫数' }, grid: { drawOnChartArea: false } }
                    }
                }
            });
        });
    }

    function loadCrawlerFamily(days) {
        ajaxGet('wpaip_l5_crawler_family', { days: days }, function (resp) {
            if (!resp || !resp.success) return;
            var data = resp.data || [];
            createChart('wpaip-l5-crawler-family-chart', {
                type: 'doughnut',
                data: {
                    labels: data.map(function (r) { return r.label; }),
                    datasets: [{
                        data: data.map(function (r) { return r.total; }),
                        backgroundColor: data.map(function (r) { return r.color; }),
                        borderWidth: 2, borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { padding:8, usePointStyle:true, font:{size:10} } } }
                }
            });
        });
    }

    function loadCrawlerRanking(days) {
        ajaxGet('wpaip_l5_crawler_ranking', { days: days }, function (resp) {
            if (!resp || !resp.success) return;
            var data = resp.data || [];
            // 横向柱状图：只取 Top 10
            var top10 = data.slice(0, 10);
            createChart('wpaip-l5-crawler-ranking-chart', {
                type: 'bar',
                data: {
                    labels: top10.map(function (r) { return r.crawler_name; }),
                    datasets: [{
                        label: '访问次数',
                        data: top10.map(function (r) { return r.total_visits; }),
                        backgroundColor: top10.map(function (r) { return r.color; }),
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        });
    }

    function loadCrawlerTopPages(days) {
        ajaxGet('wpaip_l5_crawler_top_pages', { days: days, limit: 15 }, function (resp) {
            if (!resp || !resp.success) return;
            var data = resp.data || [];
            var html = '<table class="wpaip-l5-table"><thead><tr><th style="width:50%">页面</th><th style="width:18%">访问次数</th><th style="width:32%">爬虫</th></tr></thead><tbody>';
            data.forEach(function (p) {
                var title = p.post_title || p.request_url || '-';
                var url   = p.edit_url ? p.edit_url : '#';
                var link  = p.edit_url ? '<a href="' + url + '" target="_blank">' + title + '</a>' : title;
                // 截断爬虫名超过4个时显示 "前3个 +N"
                var crawlers = p.crawlers || '';
                var crawlerList = crawlers.split(',').map(function (s) { return s.trim(); });
                var crawlerDisplay = crawlerList.length > 3
                    ? crawlerList.slice(0, 3).join(', ') + ' +' + (crawlerList.length - 3)
                    : crawlers;
                html += '<tr><td>' + link + '</td><td><strong>' + p.hits + '</strong></td><td class="wpaip-l5-crawler-cell" title="' + crawlers.replace(/"/g, '&quot;') + '">' + crawlerDisplay + '</td></tr>';
            });
            html += '</tbody></table>';
            $('#wpaip-l5-top-pages-list').html(html);
        });
    }

    function loadCrawlerRecent() {
        ajaxGet('wpaip_l5_crawler_recent', { limit: 10 }, function (resp) {
            if (!resp || !resp.success) return;
            var data = resp.data || [];
            var html = '<table class="wpaip-l5-table"><thead><tr><th>时间</th><th>爬虫</th><th>URL</th><th>状态码</th></tr></thead><tbody>';
            data.forEach(function (r) {
                html += '<tr><td>' + (r.time_ago || '') + '</td><td>' + r.crawler_name + '</td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (r.request_url || '') + '</td><td>' + (r.response_code || 200) + '</td></tr>';
            });
            html += '</tbody></table>';
            $('#wpaip-l5-recent-log').html(html);
        });
    }

    // ==================== 3. 爬虫友好度诊断 (diagnosis) ====================

    function initDiagnosisTab() {
        var diagnosing = false;

        function renderResult(result) {
            $('#wpaip-l5-diagnosis-empty').hide();
            $('#wpaip-l5-diagnosis-result').show();

            var g = result.grade || {};
            var scoreColor = g.color || '#9ca3af';
            var scoreBg = g.bg || '#f3f4f6';
            var scoreLabel = g.label || '未评级';

            // 总评分卡片 — 大圆环 + 右侧信息
            var overviewHtml =
                '<div class="wpaip-l5-diag-score-card">' +
                    '<div class="wpaip-l5-diag-score-ring-wrap">' +
                        '<svg class="wpaip-l5-diag-score-ring" viewBox="0 0 120 120">' +
                            '<circle class="wpaip-l5-diag-ring-bg" cx="60" cy="60" r="54"/>' +
                            '<circle class="wpaip-l5-diag-ring-fg" cx="60" cy="60" r="54" ' +
                                'stroke-dasharray="339.292" stroke-dashoffset="' + (339.292 - (339.292 * Math.min(100, Math.max(0, result.overall_score)) / 100)) + '" ' +
                                'stroke="' + scoreColor + '"/>' +
                        '</svg>' +
                        '<div class="wpaip-l5-diag-score-inner">' +
                            '<div class="wpaip-l5-diag-score-num" style="color:' + scoreColor + '">' + result.overall_score + '</div>' +
                            '<div class="wpaip-l5-diag-score-den">/ 100</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wpaip-l5-diag-score-info">' +
                        '<div class="wpaip-l5-diag-score-grade" style="background:' + scoreBg + ';color:' + scoreColor + '">' + scoreLabel + '</div>' +
                        '<div class="wpaip-l5-diag-score-label">综合友好度评分</div>' +
                        '<div class="wpaip-l5-diag-score-time">诊断时间：' + (result.checked_at || '') + '</div>' +
                    '</div>' +
                '</div>';

            // 5 项检查 — 卡片网格
            var checksHtml = '';
            (result.checks || []).forEach(function (c) {
                var statusClass = c.status === 'pass' ? 'pass' : (c.status === 'warn' ? 'warn' : 'fail');
                var statusIcon = c.status === 'pass' ? 'yes' : (c.status === 'warn' ? 'warning' : 'no');
                var barColor = c.status === 'pass' ? '#10b981' : (c.status === 'warn' ? '#f59e0b' : '#ef4444');
                var pct = Math.min(100, Math.max(0, c.score));
                checksHtml +=
                    '<div class="wpaip-l5-diag-check-card ' + statusClass + '">' +
                        '<div class="wpaip-l5-diag-check-top">' +
                            '<span class="wpaip-l5-diag-check-icon dashicons dashicons-' + statusIcon + '"></span>' +
                            '<span class="wpaip-l5-diag-check-name">' + c.name + '</span>' +
                            '<span class="wpaip-l5-diag-check-score-num" style="color:' + barColor + '">' + c.score + '<small>/' + c.max_score + '</small></span>' +
                        '</div>' +
                        '<div class="wpaip-l5-diag-check-bar">' +
                            '<div class="wpaip-l5-diag-check-fill" style="width:' + pct + '%;background:' + barColor + '"></div>' +
                        '</div>' +
                        '<div class="wpaip-l5-diag-check-detail">' + c.detail + '</div>' +
                    '</div>';
            });

            // 被封爬虫 — 卡片式
            var blockedHtml = '';
            var blocked = result.blocked_crawlers || [];
            if (blocked.length > 0) {
                blockedHtml =
                    '<div class="wpaip-l5-diag-detail-card blocked">' +
                        '<div class="wpaip-l5-diag-detail-header">' +
                            '<span class="dashicons dashicons-dismiss"></span>' +
                            '<span>被封锁的 AI 爬虫</span>' +
                            '<span class="wpaip-l5-diag-detail-count">' + blocked.length + '</span>' +
                        '</div>' +
                        '<div class="wpaip-l5-diag-detail-body">';
                blocked.forEach(function (b) {
                    blockedHtml +=
                        '<div class="wpaip-l5-diag-detail-item">' +
                            '<span class="wpaip-l5-diag-detail-bot">' + b.bot + '</span>' +
                            '<span class="wpaip-l5-diag-detail-suggestion">' + (b.suggestion || '') + '</span>' +
                        '</div>';
                });
                blockedHtml += '</div></div>';
            }

            // 优化建议 — 卡片式
            var issuesHtml = '';
            var issues = result.issues || [];
            if (issues.length > 0) {
                issuesHtml =
                    '<div class="wpaip-l5-diag-detail-card issues">' +
                        '<div class="wpaip-l5-diag-detail-header">' +
                            '<span class="dashicons dashicons-lightbulb"></span>' +
                            '<span>优化建议</span>' +
                            '<span class="wpaip-l5-diag-detail-count">' + issues.length + '</span>' +
                        '</div>' +
                        '<div class="wpaip-l5-diag-detail-body">';
                issues.forEach(function (iss, idx) {
                    issuesHtml +=
                        '<div class="wpaip-l5-diag-detail-item">' +
                            '<span class="wpaip-l5-diag-detail-idx">' + (idx + 1) + '</span>' +
                            '<span class="wpaip-l5-diag-detail-text">' + iss + '</span>' +
                        '</div>';
                });
                issuesHtml += '</div></div>';
            }

            $('#wpaip-l5-diag-overview').html(overviewHtml);
            $('#wpaip-l5-diag-checks').html(checksHtml);
            $('#wpaip-l5-diag-blocked').html(blockedHtml);
            $('#wpaip-l5-diag-issues').html(issuesHtml);

            // robots.txt 预览
            loadRobotsPreview();
        }

        $('#wpaip-l5-diagnosis-run').on('click', function () {
            if (diagnosing) return;
            diagnosing = true;
            $('#wpaip-l5-diagnosis-status').html('⏳ 诊断中...');
            ajaxPost('wpaip_l5_diagnosis_run', {}, function (resp) {
                diagnosing = false;
                if (resp.success) {
                    $('#wpaip-l5-diagnosis-status').html('✅ 诊断完成');
                    renderResult(resp.data);
                } else {
                    $('#wpaip-l5-diagnosis-status').html('❌ 诊断失败');
                }
            });
        });

        $('#wpaip-l5-diagnosis-force').on('click', function () {
            if (diagnosing) return;
            diagnosing = true;
            $('#wpaip-l5-diagnosis-status').html('⏳ 强制重诊中...');
            ajaxPost('wpaip_l5_diagnosis_run', { force: 1 }, function (resp) {
                diagnosing = false;
                if (resp.success) {
                    $('#wpaip-l5-diagnosis-status').html('✅ 诊断完成');
                    renderResult(resp.data);
                } else {
                    $('#wpaip-l5-diagnosis-status').html('❌ 诊断失败');
                }
            });
        });

        // 首次加载尝试读缓存
        ajaxPost('wpaip_l5_diagnosis_run', {}, function (resp) {
            if (resp.success && resp.data.overall_score !== undefined) {
                renderResult(resp.data);
            }
        });
    }

    function loadRobotsPreview() {
        ajaxGet('wpaip_l5_diagnosis_robots_preview', {}, function (resp) {
            if (!resp || !resp.success) return;
            var d = resp.data;
            var html = '<div class="wpaip-l5-diag-robots-meta">' +
                '<span class="wpaip-l5-diag-robots-tag">HTTP 状态码 <strong>' + d.http_code + '</strong></span>' +
                '<span class="wpaip-l5-diag-robots-tag">AI 规则 <strong>' + (d.has_ai_rules ? '已配置' : '未配置') + '</strong></span>' +
                '</div>';

            if (d.ai_uas && d.ai_uas.length > 0) {
                html += '<div class="wpaip-l5-diag-robots-uas">' +
                    '<div class="wpaip-l5-diag-robots-subtitle">检测到的 AI 爬虫 User-Agent</div>';
                d.ai_uas.forEach(function (ua) {
                    html += '<span class="wpaip-l5-diag-robots-ua">' + ua + '</span>';
                });
                html += '</div>';
            }

            if (d.ai_rules && d.ai_rules.length > 0) {
                html += '<table class="wpaip-l5-diag-robots-table"><thead><tr><th>User-Agent</th><th>指令</th><th>路径</th></tr></thead><tbody>';
                d.ai_rules.forEach(function (r) {
                    var dirClass = r.directive === 'allow' ? 'allow' : 'disallow';
                    html += '<tr><td>' + r.ua + '</td>' +
                        '<td><span class="wpaip-l5-diag-robots-directive ' + dirClass + '">' + r.directive + '</span></td>' +
                        '<td><code>' + r.path + '</code></td></tr>';
                });
                html += '</tbody></table>';
            }

            $('#wpaip-l5-robots-content').html(html);
        });
    }

    // ==================== 4. 实时预警 (alerts) ====================

    function initAlertsTab() {
        var currentPage = 1;
        var currentStatus = '';
        var currentSeverity = '';

        function loadAlerts(page) {
            page = page || 1;
            currentPage = page;
            var params = { page: page, per_page: 20 };
            if (currentStatus) params.status = currentStatus;
            if (currentSeverity) params.severity = currentSeverity;

            ajaxGet('wpaip_l5_alerts_list', params, function (resp) {
                if (!resp || !resp.success) return;
                var d = resp.data;
                var items = d.items || [];

                if (items.length === 0) {
                    $('#wpaip-l5-alert-list').html('<div class="wpaip-l5-empty-state"><div class="wpaip-l5-empty-icon">✅</div><p>暂无预警记录</p></div>');
                    $('#wpaip-l5-alert-pagination').html('');
                    return;
                }

                var html = '';
                items.forEach(function (alert) {
                    var sevClass = alert.severity === 'critical' ? 'critical' : (alert.severity === 'warning' ? 'warning' : 'info');
                    var sevLabel = alert.severity === 'critical' ? '严重' : (alert.severity === 'warning' ? '警告' : '信息');
                    var statusLabel = { active:'未处理', acknowledged:'已确认', resolved:'已解决', dismissed:'已忽略' }[alert.status] || alert.status;

                    html += '<div class="wpaip-l5-alert-item wpaip-l5-alert-' + sevClass + '" data-id="' + alert.id + '">' +
                        '<div class="wpaip-l5-alert-item-left"><span class="wpaip-l5-alert-severity wpaip-l5-alert-sev-' + sevClass + '">' + sevLabel + '</span></div>' +
                        '<div class="wpaip-l5-alert-item-body">' +
                            '<div class="wpaip-l5-alert-item-title">' + (alert.title || '') + ' <span class="wpaip-l5-alert-rule">' + (alert.rule_label || '') + '</span></div>' +
                            '<div class="wpaip-l5-alert-item-message">' + (alert.message || '') + '</div>' +
                            '<div class="wpaip-l5-alert-item-meta">' + alert.created_at + ' · ' + statusLabel + '</div>' +
                        '</div>' +
                        '<div class="wpaip-l5-alert-item-actions">';
                    if (alert.status === 'active') {
                        html += '<button class="button button-small wpaip-l5-alert-ack" data-id="' + alert.id + '">确认</button> ' +
                                '<button class="button button-small wpaip-l5-alert-resolve" data-id="' + alert.id + '">解决</button> ' +
                                '<button class="button button-small wpaip-l5-alert-dismiss" data-id="' + alert.id + '">忽略</button>';
                    }
                    html += '</div></div>';
                });

                $('#wpaip-l5-alert-list').html(html);

                // 分页
                var totalPages = Math.ceil(d.total / d.per_page);
                var pagHtml = '';
                if (totalPages > 1) {
                    pagHtml += '<span>共 ' + d.total + ' 条</span> ';
                    for (var i = 1; i <= totalPages; i++) {
                        pagHtml += '<button class="button button-small ' + (i === currentPage ? 'button-primary' : '') + '" data-page="' + i + '">' + i + '</button> ';
                    }
                }
                $('#wpaip-l5-alert-pagination').html(pagHtml);

                // 绑定分页
                $('#wpaip-l5-alert-pagination button').on('click', function () {
                    loadAlerts(parseInt($(this).data('page')));
                });
            });
        }

        // 筛选
        $('#wpaip-l5-alert-status-filter, #wpaip-l5-alert-severity-filter').on('change', function () {
            currentStatus = $('#wpaip-l5-alert-status-filter').val();
            currentSeverity = $('#wpaip-l5-alert-severity-filter').val();
            loadAlerts(1);
        });

        // 操作绑定
        $(document).on('click', '.wpaip-l5-alert-ack', function () {
            var id = $(this).data('id');
            ajaxPost('wpaip_l5_alerts_acknowledge', { id: id }, function () { loadAlerts(currentPage); });
        });
        $(document).on('click', '.wpaip-l5-alert-resolve', function () {
            var id = $(this).data('id');
            ajaxPost('wpaip_l5_alerts_resolve', { id: id }, function () { loadAlerts(currentPage); });
        });
        $(document).on('click', '.wpaip-l5-alert-dismiss', function () {
            var id = $(this).data('id');
            ajaxPost('wpaip_l5_alerts_dismiss', { id: id }, function () { loadAlerts(currentPage); });
        });

        // 配置弹窗
        $('#wpaip-l5-alert-config-btn').on('click', function () {
            $('#wpaip-l5-alert-config-modal').show();
        });
        $('.wpaip-l5-modal-close, .wpaip-l5-modal-cancel').on('click', function () {
            $('#wpaip-l5-alert-config-modal').hide();
        });
        $('#wpaip-l5-alert-config-save').on('click', function () {
            var config = {
                r1_schema_error: $('#l5-config-r1').is(':checked') ? 1 : 0,
                r2_crawler_blocked: $('#l5-config-r2').is(':checked') ? 1 : 0,
                r3_score_drop: $('#l5-config-r3').is(':checked') ? 1 : 0,
                r4_traffic_drop: $('#l5-config-r4').is(':checked') ? 1 : 0,
                score_drop_threshold: parseInt($('#l5-config-score-threshold').val()) || 15,
                traffic_drop_pct: parseInt($('#l5-config-traffic-pct').val()) || 50
            };
            ajaxPost('wpaip_l5_alerts_config', config, function (resp) {
                if (resp.success) {
                    $('#wpaip-l5-alert-config-modal').hide();
                    loadAlerts(currentPage);
                }
            });
        });

        // 手动运行全部检测
        $('#wpaip-l5-alert-run-all-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#wpaip-l5-alert-run-status');
            $btn.prop('disabled', true).text('⏳ 检测中...');
            $status.text('');
            ajaxPost('wpaip_l5_alerts_run_all', {}, function (resp) {
                $btn.prop('disabled', false).text('▶ 立即运行全部检测');
                if (!resp || !resp.success) {
                    $status.css('color', '#ef4444').text('检测失败，请查看浏览器控制台');
                    return;
                }
                var d = resp.data;
                var parts = [];
                var rules = ['R1', 'R2', 'R3', 'R4'];
                rules.forEach(function (r) {
                    if (d.results && d.results[r]) {
                        parts.push(d.results[r].label + ' 已完成');
                    }
                });
                $status.css('color', '#22c55e').text('✅ ' + d.message + ' | 活跃预警: ' + (d.alert_count || 0));
                // 刷新预警列表
                loadAlerts(1);
            });
        });

        loadAlerts(1);
    }

    // 启动逻辑改为：document.ready 时若当前 main-panel 可见的 l5 tab，调用 wpaipL5Init
    // 由于 script 是动态加载的，IIFE 内代码执行时 DOM 已 ready
    $(function () {
        if (typeof Chart === 'undefined') {
            console.warn('[一麦GSO L5] Chart.js 未加载，仪表盘图表将不可用');
        }
        // 检查 URL 或当前激活的 l5-* panel
        var initialTab = '';
        try {
            var url = new URL(window.location.href);
            initialTab = url.searchParams.get('tab') || '';
        } catch (e) {}
        if (initialTab.indexOf('l5-') === 0) {
            var $panel = $('.wpaip-main-panel[data-tab="' + initialTab + '"].active');
            if ($panel.length && typeof window.wpaipL5Init === 'function') {
                window.wpaipL5Init(initialTab);
            }
        }
    });

})(jQuery);
