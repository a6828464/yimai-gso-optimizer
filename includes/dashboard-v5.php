<?php
/**
 * 一麦 GSO 仪表盘 v5 - 双引擎数据驾驶舱
 * 从 class-admin.php render_main_center() 中 include
 * 可使用所有 $dashboard_* 变量
 */
if ( ! defined( 'ABSPATH' ) ) exit;
// YIMAI_GSO_VERSION 防御性定义：防止部署异常或 opcache 导致常量未定义 Fatal Error
defined( 'YIMAI_GSO_VERSION' ) || define( 'YIMAI_GSO_VERSION', '2.4.3' );

?>

<!-- ===== 仪表盘 v5 双引擎数据驾驶舱 ===== -->

<!-- ① 页面标题 -->
<div class="yimai-gso-page-card-header">
    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-dashboard"></span></div>
    <div class="yimai-gso-card-body">
        <h2 class="yimai-gso-card-title">一麦GSO双擎优化</h2>
        <p class="yimai-gso-card-desc">SEO-GEO 双引擎数据驾驶舱，实时监控站点健康与优化效果</p>
    </div>
</div>

<!-- ② 系统运行状态 -->
<div class="yimai-gso-d5-section-title">⚙️ 系统运行状态</div>
<div class="yimai-gso-d5-syshealth-bar">
    <div class="yimai-gso-d5-syshealth-item">
        <span class="yimai-gso-d5-syshealth-dot <?php echo '' !== $dashboard_ai_api_key ? 'ok' : 'warn'; ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">AI 模型</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo esc_html( $dashboard_ai_provider_name ); ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <span class="yimai-gso-d5-syshealth-dot ok"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">配图服务</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo esc_html( $dashboard_image_provider_name ); ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <span class="yimai-gso-d5-syshealth-dot <?php echo $dashboard_autopilot_enabled ? 'ok' : ( ! empty( $dashboard_autopilot_meta['available'] ) ? 'warn' : 'off' ); ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">永动发布</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo $dashboard_autopilot_enabled ? '已开启 · 每日' . absint( $dashboard_autopilot_settings['daily_count'] ?? 3 ) . '篇' : '未启用'; ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <?php $d5_il_meta = $this->get_feature_meta( 'smart_internal_links' ); ?>
        <span class="yimai-gso-d5-syshealth-dot <?php echo ! empty( $d5_il_meta['available'] ) ? 'ok' : 'off'; ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">智能内链</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo ! empty( $d5_il_meta['available'] ) ? '已启用 · ' . $dashboard_internal_link_count . '篇文章' : '未开通'; ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <?php $d5_at_meta = $this->get_feature_meta( 'auto_tags' ); ?>
        <span class="yimai-gso-d5-syshealth-dot <?php echo ! empty( $d5_at_meta['available'] ) ? 'ok' : 'off'; ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">自动标签</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo ! empty( $d5_at_meta['available'] ) ? '已启用' : '未开通'; ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <?php $d5_det_meta = $this->get_feature_meta( 'content_check' ); ?>
        <span class="yimai-gso-d5-syshealth-dot <?php echo ! empty( $d5_det_meta['available'] ) ? 'ok' : 'off'; ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">内容检测</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo ! empty( $d5_det_meta['available'] ) ? '已启动' : '未开通'; ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <?php
        $d5_push_enabled = YIMAI_GSO_Settings::get( 'seo_push_auto_enabled', 0 );
        $d5_baidu_key = YIMAI_GSO_Settings::get( 'seo_push_baidu_token', '' );
        $d5_google_enabled = YIMAI_GSO_Settings::get( 'seo_push_google_enabled', 0 );
        $d5_push_status = ! empty( $d5_baidu_key ) ? '百度已配置' : ( $d5_push_enabled ? 'Bing已配置' : ( $d5_google_enabled ? 'Google已配置' : '百度未配置token' ) );
        ?>
        <span class="yimai-gso-d5-syshealth-dot <?php echo ( ! empty( $d5_baidu_key ) || $d5_push_enabled || $d5_google_enabled ) ? 'ok' : 'warn'; ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">SEO推送</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo esc_html( $d5_push_status ); ?></span>
        </div>
    </div>
    <div class="yimai-gso-d5-syshealth-item">
        <span class="yimai-gso-d5-syshealth-dot <?php echo $dashboard_stats['drafts'] > 0 ? 'warn' : 'ok'; ?>"></span>
        <div class="yimai-gso-d5-syshealth-info">
            <span class="yimai-gso-d5-syshealth-name">待处理</span>
            <span class="yimai-gso-d5-syshealth-detail"><?php echo $dashboard_stats['drafts'] > 0 ? $dashboard_stats['drafts'] . '篇草稿建议发布' : '无积压'; ?></span>
        </div>
    </div>
</div>

<!-- ③ 站点健康雷达 -->
<div class="yimai-gso-d5-health-panel">
    <div class="yimai-gso-d5-health-header">
        <span class="yimai-gso-d5-health-title">
            <span class="yimai-gso-d5-health-title-dot good"></span>
            站点健康雷达
        </span>
        <span class="yimai-gso-d5-health-refresh">每6小时自动刷新</span>
    </div>
    <div class="yimai-gso-d5-health-grid" id="yimai-gso-d5-health-grid">
        <div class="yimai-gso-d5-health-item"><div class="yimai-gso-d5-health-item-header"><span class="yimai-gso-d5-health-item-name">加载中...</span></div></div>
    </div>
</div>

<!-- ④ KPI 卡片 -->
<div class="yimai-gso-d5-kpi-grid" id="yimai-gso-d5-kpi-grid">
    <div class="yimai-gso-d5-kpi-card"><div class="yimai-gso-d5-kpi-label">已发布文章</div><div class="yimai-gso-d5-kpi-row"><span class="yimai-gso-d5-kpi-value">--</span><span class="yimai-gso-d5-kpi-unit">篇</span></div></div>
    <div class="yimai-gso-d5-kpi-card"><div class="yimai-gso-d5-kpi-label">AI爬虫访问 (今日)</div><div class="yimai-gso-d5-kpi-row"><span class="yimai-gso-d5-kpi-value">--</span><span class="yimai-gso-d5-kpi-unit">次</span></div></div>
    <div class="yimai-gso-d5-kpi-card"><div class="yimai-gso-d5-kpi-label">Schema 覆盖率</div><div class="yimai-gso-d5-kpi-row"><span class="yimai-gso-d5-kpi-value">--</span><span class="yimai-gso-d5-kpi-unit">%</span></div></div>
    <div class="yimai-gso-d5-kpi-card"><div class="yimai-gso-d5-kpi-label">AI引荐流量 (30天)</div><div class="yimai-gso-d5-kpi-row"><span class="yimai-gso-d5-kpi-value">--</span></div></div>
    <div class="yimai-gso-d5-kpi-card"><div class="yimai-gso-d5-kpi-label">定时发布队列</div><div class="yimai-gso-d5-kpi-row"><span class="yimai-gso-d5-kpi-value">--</span><span class="yimai-gso-d5-kpi-unit">篇</span></div></div>
    <div class="yimai-gso-d5-kpi-card"><div class="yimai-gso-d5-kpi-label">活跃预警</div><div class="yimai-gso-d5-kpi-row"><span class="yimai-gso-d5-kpi-value">--</span><span class="yimai-gso-d5-kpi-unit">条</span></div></div>
</div>

<!-- ⑤ 图表区 -->
<div class="yimai-gso-d5-charts-row">
    <div class="yimai-gso-d5-chart-card">
        <div class="yimai-gso-d5-chart-header">
            <span class="yimai-gso-d5-chart-title">AI 爬虫访问趋势</span>
            <div class="yimai-gso-d5-chart-tabs" id="yimai-gso-d5-trend-tabs">
                <button class="yimai-gso-d5-chart-tab active" data-range="7">7天</button>
                <button class="yimai-gso-d5-chart-tab" data-range="30">30天</button>
                <button class="yimai-gso-d5-chart-tab" data-range="90">90天</button>
            </div>
        </div>
        <div class="yimai-gso-d5-chart-canvas"><canvas id="yimai-gso-d5-crawler-trend"></canvas></div>
    </div>
    <div class="yimai-gso-d5-chart-card">
        <div class="yimai-gso-d5-chart-header"><span class="yimai-gso-d5-chart-title">Schema 类型覆盖</span></div>
        <div class="yimai-gso-d5-chart-canvas"><canvas id="yimai-gso-d5-schema-chart"></canvas></div>
    </div>
    <div class="yimai-gso-d5-chart-card">
        <div class="yimai-gso-d5-chart-header"><span class="yimai-gso-d5-chart-title">爬虫家族分布</span></div>
        <div class="yimai-gso-d5-chart-canvas"><canvas id="yimai-gso-d5-family-chart"></canvas></div>
    </div>
</div>

<!-- ⑥ 快捷入口 -->
<div class="yimai-gso-d5-quick-panel">
    <span class="yimai-gso-d5-quick-title">快捷操作</span>
    <div class="yimai-gso-d5-quick-grid">
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="settings"><span class="yimai-gso-d5-quick-icon purple">⚙️</span>基础设置</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="rewrite"><span class="yimai-gso-d5-quick-icon amber">📝</span>内容改写</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="generate"><span class="yimai-gso-d5-quick-icon indigo">✏️</span>批量创作</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="autopilot"><span class="yimai-gso-d5-quick-icon cyan">⚡</span>永动发布</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="detection"><span class="yimai-gso-d5-quick-icon green">🛡️</span>内容检测</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="seo-meta"><span class="yimai-gso-d5-quick-icon blue">📊</span>SEO元数据</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="internal-links"><span class="yimai-gso-d5-quick-icon pink">🔗</span>智能内链</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="optimizer-404"><span class="yimai-gso-d5-quick-icon red">⚠️</span>404优化</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="seo-push"><span class="yimai-gso-d5-quick-icon teal">📢</span>SEO推送</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="seo-score"><span class="yimai-gso-d5-quick-icon orange">📈</span>SEO评分</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="geo-audit"><span class="yimai-gso-d5-quick-icon violet">🔍</span>AI就绪审计</button>
        <button class="yimai-gso-d5-quick-btn yimai-gso-dashboard-goto" data-goto="l5-crawler"><span class="yimai-gso-d5-quick-icon lime">🔬</span>AI爬虫追踪</button>
    </div>
</div>

<!-- 页脚 -->
<div class="yimai-gso-d5-footer">
    一麦GSO双擎优化 v<?php echo esc_html( YIMAI_GSO_VERSION ); ?> · 数据更新于 <span id="yimai-gso-d5-update-time">--:--:--</span>
</div>
