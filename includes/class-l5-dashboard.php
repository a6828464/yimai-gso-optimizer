<?php
/**
 * 第5层主控制器 — 路由分发 + 双引擎仪表盘数据聚合
 *
 * 管理 L5 所有子模块的初始化、Sidebar 路由、综合仪表盘数据聚合查询。
 * 四模块：overview(#53) / crawler(#46) / diagnosis(#47) / alerts(#58)
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// YIMAI_GSO_VERSION 防御性定义：防止部署异常或 opcache 导致常量未定义 Fatal Error
defined( 'YIMAI_GSO_VERSION' ) || define( 'YIMAI_GSO_VERSION', '2.4.0' );



class YIMAI_GSO_L5_Dashboard {

    private static $instance = null;

    /** L5 子 Tab 路由表 */
    const TABS = array(
        'l5-overview'  => 'render_overview_tab',
        'l5-crawler'   => 'render_crawler_tab',
        'l5-diagnosis' => 'render_diagnosis_tab',
        'l5-alerts'    => 'render_alerts_tab',
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 仪表盘 AJAX 端点
        add_action( 'wp_ajax_yimai_gso_l5_overview_stats',     array( $this, 'ajax_overview_stats' ) );
        add_action( 'wp_ajax_yimai_gso_l5_seo_distribution',    array( $this, 'ajax_seo_distribution' ) );
        add_action( 'wp_ajax_yimai_gso_l5_geo_distribution',    array( $this, 'ajax_geo_distribution' ) );
        add_action( 'wp_ajax_yimai_gso_l5_schema_coverage',     array( $this, 'ajax_schema_coverage' ) );
        add_action( 'wp_ajax_yimai_gso_l5_traffic_trend',       array( $this, 'ajax_traffic_trend' ) );
        add_action( 'wp_ajax_yimai_gso_l5_seo_geo_scatter',     array( $this, 'ajax_seo_geo_scatter' ) );

        // 加载前端资源
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    // ==================== 子 Tab 路由 ====================

    /**
     * 分发 L5 Tab 渲染
     *
     * @param string $tab
     * @return bool 是否由 L5 处理（true = 已处理，外部不再渲染）
     */
    public function dispatch_tab( $tab ) {
        if ( ! isset( self::TABS[ $tab ] ) ) {
            return false;
        }

        $method = self::TABS[ $tab ];
        if ( method_exists( $this, $method ) ) {
            $this->$method();
        }

        return true;
    }

    /**
     * 注册 L5 前端资源
     *
     * 说明（L1 审计结论）：本方法内的守卫检查 'yimai-gso-dashboard'，而该 slug 从未注册
     * （L5 是主菜单 yimai-gso-center 内的 tab，$hook 恒为 toplevel_page_yimai-gso-center），
     * 因此本方法整体不会执行，属已失效的重复 owner。
     *
     * 但**不能**简单地把 slug 改成 'yimai-gso-center' 让它复活：L5 面板的脚本实际由
     * admin.js:loadL5Dashboard() 在点击 l5-* 标签时动态注入（这一设计是为了避免把
     * Chart.js 与 l5-dashboard.js 加载到每个插件后台页面）。两条路径同时生效会导致
     * Chart.js 重复加载，且 wp_localize_script 注入的 yimaiGsoL5Data 与
     * output_page_assets() 手工输出的同名全局变量冲突。
     * 因此保留 output_page_assets() 作为唯一 owner，本方法留空壳以免误用。
     */
    public function enqueue_assets( $hook ) {
        // 明确不执行任何入队：L5 资源统一由 output_page_assets() + admin.js 动态加载负责。
        // 保留方法体以兼容既有 add_action 注册（class-l5-dashboard.php:47）。
        return;
    }

    // ==================== Tab 渲染 ====================

    /**
     * 直接输出 CSS 和 JS 配置到页面 HTML（绕过 wp_add_inline_script 时序问题）
     * 在 admin.php 页面渲染中调用，确保 L5 面板始终有样式和数据
     */
    public static function output_page_assets() {
        $css_url = YIMAI_GSO_PLUGIN_URL . 'admin/css/l5-dashboard.css?v=' . YIMAI_GSO_VERSION . '.1';
        $data    = array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'yimai_gso_ajax_nonce' ),
            'tab'         => isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard',
            'plugin_url'  => YIMAI_GSO_PLUGIN_URL,
        );

        // 兜底 CSS（如果 wp_enqueue_style 没生效）
        echo '<link rel="stylesheet" id="yimai-gso-l5-dashboard-fallback" href="' . esc_url( $css_url ) . '" media="all">' . "\n";

        // yimaiGsoL5Data 直接注入全局作用域
        echo '<script>var yimaiGsoL5Data = ' . json_encode( $data, JSON_UNESCAPED_UNICODE ) . ';</script>' . "\n";
    }

    /**
     * #53 综合仪表盘
     */
    public function render_overview_tab() {
        ?>
        <div class="yimai-gso-page-card-header">
            <div class="yimai-gso-card-icon">
                <span class="dashicons dashicons-chart-area"></span>
            </div>
            <div class="yimai-gso-card-body">
                <h2 class="yimai-gso-card-title">双引擎综合仪表盘</h2>
                <p class="yimai-gso-card-desc">一站式查看 SEO + GEO 优化效果，追踪 AI 爬虫和引荐流量趋势</p>
            </div>
        </div>

        <!-- ① 总览统计条 -->
        <div class="yimai-gso-l5-stats-row" id="yimai-gso-l5-overview-stats">
            <div class="yimai-gso-l5-stat-card skeleton">
                <div class="yimai-gso-l5-stat-value">--</div>
                <div class="yimai-gso-l5-stat-label">SEO 均分</div>
            </div>
            <div class="yimai-gso-l5-stat-card skeleton">
                <div class="yimai-gso-l5-stat-value">--</div>
                <div class="yimai-gso-l5-stat-label">GEO 均分</div>
            </div>
            <div class="yimai-gso-l5-stat-card skeleton">
                <div class="yimai-gso-l5-stat-value">--</div>
                <div class="yimai-gso-l5-stat-label">Schema 覆盖率</div>
            </div>
            <div class="yimai-gso-l5-stat-card skeleton">
                <div class="yimai-gso-l5-stat-value">--</div>
                <div class="yimai-gso-l5-stat-label">AI爬虫访问(30d)</div>
            </div>
            <div class="yimai-gso-l5-stat-card skeleton">
                <div class="yimai-gso-l5-stat-value">--</div>
                <div class="yimai-gso-l5-stat-label">AI引荐流量(30d)</div>
            </div>
            <div class="yimai-gso-l5-stat-card skeleton">
                <div class="yimai-gso-l5-stat-value">--</div>
                <div class="yimai-gso-l5-stat-label">活跃预警</div>
            </div>
        </div>

        <!-- ②③ SEO + GEO 评分分布（环形图 双栏） -->
        <div class="yimai-gso-l5-row">
            <div class="yimai-gso-l5-col-6">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">📈 SEO 评分分布</h3>
                    <div class="yimai-gso-l5-chart-wrap">
                        <canvas id="yimai-gso-l5-seo-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="yimai-gso-l5-col-6">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🌐 GEO 可见性分布</h3>
                    <div class="yimai-gso-l5-chart-wrap">
                        <canvas id="yimai-gso-l5-geo-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ④ Schema 覆盖率（柱状图） + ⑤ 爬虫&引荐趋势（双轴折线图） -->
        <div class="yimai-gso-l5-row">
            <div class="yimai-gso-l5-col-6">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🏛️ Schema 覆盖率</h3>
                    <div class="yimai-gso-l5-chart-wrap">
                        <canvas id="yimai-gso-l5-schema-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="yimai-gso-l5-col-6">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🤖 AI爬虫 + 引荐流量趋势</h3>
                    <div class="yimai-gso-l5-chart-wrap">
                        <canvas id="yimai-gso-l5-traffic-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑥ SEO vs GEO 散点图 -->
        <div class="yimai-gso-l5-row">
            <div class="yimai-gso-l5-col-12">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🎯 SEO vs GEO 双引擎对比（每篇文章）</h3>
                    <div class="yimai-gso-l5-chart-wrap" style="height:360px">
                        <canvas id="yimai-gso-l5-scatter-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * #46 AI爬虫实时追踪仪表盘
     */
    public function render_crawler_tab() {
        ?>
        <div class="yimai-gso-page-card-header">
            <div class="yimai-gso-card-icon">
                <span class="dashicons dashicons-visibility"></span>
            </div>
            <div class="yimai-gso-card-body">
                <h2 class="yimai-gso-card-title">AI爬虫实时追踪仪表盘</h2>
                <p class="yimai-gso-card-desc">实时查看爬取站点的 AI 机器人列表，支持 7/30/90 天过滤，分平台图表 + 被爬最多页面排行</p>
            </div>
        </div>

        <!-- 时间范围切换 -->
        <div class="yimai-gso-l5-time-filter">
            <button class="yimai-gso-l5-time-btn active" data-days="7">7天</button>
            <button class="yimai-gso-l5-time-btn" data-days="30">30天</button>
            <button class="yimai-gso-l5-time-btn" data-days="90">90天</button>
            <span class="yimai-gso-l5-auto-refresh">⏱ 30秒自动刷新</span>
        </div>

        <!-- 统计卡片 -->
        <div class="yimai-gso-l5-stats-row" id="yimai-gso-l5-crawler-stats">
            <div class="yimai-gso-l5-stat-card skeleton"><div class="yimai-gso-l5-stat-value">--</div><div class="yimai-gso-l5-stat-label">总访问量</div></div>
            <div class="yimai-gso-l5-stat-card skeleton"><div class="yimai-gso-l5-stat-value">--</div><div class="yimai-gso-l5-stat-label">活跃爬虫</div></div>
            <div class="yimai-gso-l5-stat-card skeleton"><div class="yimai-gso-l5-stat-value">--</div><div class="yimai-gso-l5-stat-label">被爬页面</div></div>
            <div class="yimai-gso-l5-stat-card skeleton"><div class="yimai-gso-l5-stat-value">--</div><div class="yimai-gso-l5-stat-label">今日访问</div></div>
        </div>

        <!-- 下排列：爬虫活跃度排行 + AI爬虫访问趋势，左右对半分 -->
        <div class="yimai-gso-l5-row">
            <div class="yimai-gso-l5-col-6">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🏆 爬虫活跃度排行</h3>
                    <div class="yimai-gso-l5-chart-wrap" style="height:320px">
                        <canvas id="yimai-gso-l5-crawler-ranking-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="yimai-gso-l5-col-6">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">📈 AI爬虫访问趋势</h3>
                    <div class="yimai-gso-l5-chart-wrap">
                        <canvas id="yimai-gso-l5-crawler-trend-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 下中排列：爬虫家族分布(左1/3) + 最近访问日志(右2/3) -->
        <div class="yimai-gso-l5-row">
            <div class="yimai-gso-l5-col-4">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🥧 爬虫家族分布</h3>
                    <div class="yimai-gso-l5-chart-wrap" style="height:300px">
                        <canvas id="yimai-gso-l5-crawler-family-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="yimai-gso-l5-col-8">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">🕐 最近访问日志</h3>
                    <div class="yimai-gso-l5-recent-log" id="yimai-gso-l5-recent-log">
                        <div class="yimai-gso-l5-skeleton-item"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 底部排列：被爬最多页面，全宽显示 -->
        <div class="yimai-gso-l5-row">
            <div class="yimai-gso-l5-col-12">
                <div class="yimai-gso-l5-chart-panel">
                    <h3 class="yimai-gso-l5-chart-title">📋 被爬最多页面</h3>
                    <div class="yimai-gso-l5-top-pages" id="yimai-gso-l5-top-pages-list">
                        <div class="yimai-gso-l5-skeleton-item"></div>
                        <div class="yimai-gso-l5-skeleton-item"></div>
                        <div class="yimai-gso-l5-skeleton-item"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * #47 爬虫友好度诊断
     */
    public function render_diagnosis_tab() {
        ?>
        <div class="yimai-gso-page-card-header">
            <div class="yimai-gso-card-icon">
                <span class="dashicons dashicons-search"></span>
            </div>
            <div class="yimai-gso-card-body">
                <h2 class="yimai-gso-card-title">AI爬虫友好度诊断</h2>
                <p class="yimai-gso-card-desc">检测是否对 AI 爬虫过度封锁，检查 robots.txt + meta robots 标签，给出优化建议</p>
            </div>
        </div>

        <div class="yimai-gso-l5-action-bar yimai-gso-l5-diag-action-bar">
            <button type="button" id="yimai-gso-l5-diagnosis-run" class="button button-primary button-hero">
                <span class="dashicons dashicons-update"></span> 开始诊断
            </button>
            <button type="button" id="yimai-gso-l5-diagnosis-force" class="button button-secondary">
                <span class="dashicons dashicons-controls-repeat"></span> 强制重新诊断
            </button>
            <span class="yimai-gso-l5-diagnosis-status" id="yimai-gso-l5-diagnosis-status"></span>
        </div>

        <!-- 诊断结果区域 -->
        <div id="yimai-gso-l5-diagnosis-result" style="display:none">
            <!-- 总评分卡片 -->
            <div class="yimai-gso-l5-diag-overview" id="yimai-gso-l5-diag-overview"></div>

            <!-- 5 项检查明细 -->
            <div class="yimai-gso-l5-diag-checks" id="yimai-gso-l5-diag-checks"></div>

            <!-- 诊断详情双栏：被封锁爬虫 + 优化建议 -->
            <div class="yimai-gso-l5-diag-details-row">
                <div class="yimai-gso-l5-diag-detail-col" id="yimai-gso-l5-diag-blocked"></div>
                <div class="yimai-gso-l5-diag-detail-col" id="yimai-gso-l5-diag-issues"></div>
            </div>

            <!-- robots.txt 预览 -->
            <div class="yimai-gso-l5-diag-robots-panel" id="yimai-gso-l5-diag-robots-preview">
                <h3 class="yimai-gso-l5-diag-robots-title">
                    <span class="dashicons dashicons-media-text"></span>
                    实际 robots.txt — AI 爬虫规则预览
                </h3>
                <div id="yimai-gso-l5-robots-content"></div>
            </div>
        </div>

        <!-- 空状态 -->
        <div id="yimai-gso-l5-diagnosis-empty" class="yimai-gso-l5-diag-empty-state">
            <div class="yimai-gso-l5-diag-empty-icon">
                <span class="dashicons dashicons-search"></span>
            </div>
            <h3 class="yimai-gso-l5-diag-empty-title">全面诊断你的网站对 AI 爬虫的友好程度</h3>
            <p class="yimai-gso-l5-diag-empty-desc">检测 robots.txt、meta robots 标签、IP 封锁等配置，确保 AI 搜索引擎能正常抓取你的内容</p>
            <div class="yimai-gso-l5-diag-empty-features">
                <span class="yimai-gso-l5-diag-empty-feature"><span class="dashicons dashicons-yes-alt"></span> robots.txt 规则分析</span>
                <span class="yimai-gso-l5-diag-empty-feature"><span class="dashicons dashicons-yes-alt"></span> Meta 标签检测</span>
                <span class="yimai-gso-l5-diag-empty-feature"><span class="dashicons dashicons-yes-alt"></span> IP 封锁排查</span>
                <span class="yimai-gso-l5-diag-empty-feature"><span class="dashicons dashicons-yes-alt"></span> User-Agent 过滤</span>
                <span class="yimai-gso-l5-diag-empty-feature"><span class="dashicons dashicons-yes-alt"></span> 优化建议生成</span>
            </div>
            <p class="yimai-gso-l5-diag-empty-hint">诊断约需 3-5 秒，结果将被缓存 1 小时</p>
        </div>
        <?php
    }

    /**
     * #58 实时预警面板
     */
    public function render_alerts_tab() {
        ?>
        <div class="yimai-gso-page-card-header">
            <div class="yimai-gso-card-icon">
                <span class="dashicons dashicons-bell"></span>
            </div>
            <div class="yimai-gso-card-body">
                <h2 class="yimai-gso-card-title">实时预警</h2>
                <p class="yimai-gso-card-desc">Schema 出错 / 爬虫被误封 / 内容评分骤降 / AI 引荐流量异常下降时自动告警</p>
            </div>
        </div>

        <!-- 筛选栏 -->
        <div class="yimai-gso-l5-alert-filters">
            <select id="yimai-gso-l5-alert-status-filter">
                <option value="">全部状态</option>
                <option value="active">未处理</option>
                <option value="acknowledged">已确认</option>
                <option value="resolved">已解决</option>
                <option value="dismissed">已忽略</option>
            </select>
            <select id="yimai-gso-l5-alert-severity-filter">
                <option value="">全部严重度</option>
                <option value="critical">严重</option>
                <option value="warning">警告</option>
                <option value="info">信息</option>
            </select>
            <button type="button" id="yimai-gso-l5-alert-config-btn" class="button">⚙️ 预警规则配置</button>
            <button type="button" id="yimai-gso-l5-alert-run-all-btn" class="button button-primary">▶ 立即运行全部检测</button>
            <span class="yimai-gso-l5-alert-status-msg" id="yimai-gso-l5-alert-run-status"></span>
        </div>

        <!-- 预警列表 -->
        <div class="yimai-gso-l5-alert-list" id="yimai-gso-l5-alert-list">
            <div class="yimai-gso-l5-skeleton-item"></div>
            <div class="yimai-gso-l5-skeleton-item"></div>
        </div>

        <!-- 分页 -->
        <div class="yimai-gso-l5-alert-pagination" id="yimai-gso-l5-alert-pagination"></div>

        <!-- 预警规则配置弹窗（隐藏） -->
        <div class="yimai-gso-l5-modal-overlay" id="yimai-gso-l5-alert-config-modal" style="display:none">
            <div class="yimai-gso-l5-modal">
                <div class="yimai-gso-l5-modal-header">
                    <h3>⚙️ 预警规则配置</h3>
                    <button type="button" class="yimai-gso-l5-modal-close">&times;</button>
                </div>
                <div class="yimai-gso-l5-modal-body">
                    <label class="yimai-gso-l5-config-row">
                        <input type="checkbox" id="l5-config-r1" checked> R1 · Schema 出错检测（每6小时）
                    </label>
                    <label class="yimai-gso-l5-config-row">
                        <input type="checkbox" id="l5-config-r2" checked> R2 · AI爬虫被误封检测（每1小时）
                    </label>
                    <label class="yimai-gso-l5-config-row">
                        <input type="checkbox" id="l5-config-r3" checked> R3 · 内容评分骤降检测（每24小时）
                        <input type="number" id="l5-config-score-threshold" value="15" min="5" max="30" style="width:60px;margin-left:8px"> 分阈值
                    </label>
                    <label class="yimai-gso-l5-config-row">
                        <input type="checkbox" id="l5-config-r4" checked> R4 · AI引荐流量异常检测（每24小时）
                        <input type="number" id="l5-config-traffic-pct" value="50" min="10" max="90" style="width:60px;margin-left:8px"> %阈值
                    </label>
                </div>
                <div class="yimai-gso-l5-modal-footer">
                    <button type="button" id="yimai-gso-l5-alert-config-save" class="button button-primary">保存配置</button>
                    <button type="button" class="yimai-gso-l5-modal-cancel button">取消</button>
                </div>
            </div>
        </div>
        <?php
    }

    // ==================== 仪表盘 AJAX 端点 ====================

    /**
     * AJAX: 总览统计条数据（一次返回6张卡片）
     */
    public function ajax_overview_stats() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $cache_key = 'yimai_gso_l5_overview_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;
        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();

        // SEO 均分（PHP 层反序列化，兼容 WordPress 默认的 serialize 存储）
        $seo_avg = $this->compute_avg_score( 'yimai_gso_seo_score', $pt_list );

        // GEO 均分
        $geo_avg = $this->compute_avg_score( 'yimai_gso_geo_score', $pt_list );

        // Schema 覆盖率
        $total_posts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$pt_list})"
        );
        $schema_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = 'yimai_gso_schema_type' AND pm.meta_value != '' AND p.post_type IN ({$pt_list})"
        );
        $schema_pct = $total_posts > 0 ? round( $schema_count / $total_posts * 100 ) : 0;

        // AI 爬虫访问量（30天）
        $crawler_total = 0;
        if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
            try {
                $crawler_stats = YIMAI_GSO_Crawler_Tracker::get_stats( 30 );
                $crawler_total = (int) $crawler_stats['total_visits'];
            } catch ( \Throwable $e ) {
                error_log( '[一麦GSO] L5_Dashboard → Crawler_Tracker.get_stats() failed: ' . $e->getMessage() );
            }
        }

        // AI 引荐流量（30天）
        $referral_total = 0;
        if ( class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) {
            try {
                $ref_stats = YIMAI_GSO_Referral_Tracker::get_stats( 30 );
                $referral_total = (int) $ref_stats['total_visits'];
            } catch ( \Throwable $e ) {
                error_log( '[一麦GSO] L5_Dashboard → Referral_Tracker.get_stats() failed: ' . $e->getMessage() );
            }
        }

        // 活跃预警数
        $alert_count = 0;
        if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
            try {
                $alert_count = YIMAI_GSO_Alert_Engine::get_active_critical_count();
            } catch ( \Throwable $e ) {
                error_log( '[一麦GSO] L5_Dashboard → Alert_Engine.get_active_critical_count() failed: ' . $e->getMessage() );
            }
        }

        $result = array(
            'seo_avg'        => $seo_avg ? round( (float) $seo_avg ) : 0,
            'geo_avg'        => $geo_avg ? round( (float) $geo_avg ) : 0,
            'schema_pct'     => $schema_pct,
            'crawler_total'  => $crawler_total,
            'referral_total'  => $referral_total,
            'alert_count'    => $alert_count,
        );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: SEO 评分分布（A-F 分桶）
     */
    public function ajax_seo_distribution() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $cache_key = 'yimai_gso_l5_seo_dist_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        $distribution = $this->get_score_distribution( 'yimai_gso_seo_score' );
        set_transient( $cache_key, $distribution, 2 * MINUTE_IN_SECONDS );

        wp_send_json_success( $distribution );
    }

    /**
     * AJAX: GEO 评分分布（A-F 分桶）
     */
    public function ajax_geo_distribution() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $cache_key = 'yimai_gso_l5_geo_dist_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        $distribution = $this->get_score_distribution( 'yimai_gso_geo_score' );
        set_transient( $cache_key, $distribution, 2 * MINUTE_IN_SECONDS );

        wp_send_json_success( $distribution );
    }

    /**
     * AJAX: Schema 覆盖率
     */
    public function ajax_schema_coverage() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $cache_key = 'yimai_gso_l5_schema_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;
        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();

        $row = $wpdb->get_row(
            "SELECT
                COUNT(DISTINCT p.ID) as total_posts,
                COUNT(DISTINCT CASE WHEN pm.meta_value IS NOT NULL AND pm.meta_value != '' THEN p.ID END) as with_schema,
                COUNT(DISTINCT CASE WHEN pm.meta_value = 'Article' THEN p.ID END) as article,
                COUNT(DISTINCT CASE WHEN pm.meta_value = 'FAQPage' THEN p.ID END) as faq,
                COUNT(DISTINCT CASE WHEN pm.meta_value = 'HowTo' THEN p.ID END) as howto,
                COUNT(DISTINCT CASE WHEN pm.meta_value = 'Product' THEN p.ID END) as product
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'yimai_gso_schema_type'
             WHERE p.post_status = 'publish' AND p.post_type IN ({$pt_list})",
            ARRAY_A
        );

        $result = array(
            'total'     => (int) $row['total_posts'],
            'covered'   => (int) $row['with_schema'],
            'uncovered' => (int) $row['total_posts'] - (int) $row['with_schema'],
            'types'     => array(
                'Article' => (int) $row['article'],
                'FAQPage' => (int) $row['faq'],
                'HowTo'   => (int) $row['howto'],
                'Product' => (int) $row['product'],
            ),
        );

        set_transient( $cache_key, $result, 2 * MINUTE_IN_SECONDS );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: AI爬虫 + 引荐流量趋势（双线）
     */
    public function ajax_traffic_trend() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        $days = in_array( $days, array( 7, 30, 90 ) ) ? $days : 30;

        $cache_key = 'yimai_gso_l5_traffic_' . YIMAI_GSO_VERSION . '_' . $days;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        $result = array( 'crawler' => array(), 'referral' => array() );

        // 爬虫趋势
        if ( class_exists( 'YIMAI_GSO_Crawler_Dashboard' ) ) {
            global $wpdb;
            $table = YIMAI_GSO_Crawler_Tracker::table_name();
            YIMAI_GSO_Crawler_Tracker::ensure_table();

            $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
            $result['crawler'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(visit_time) as date, COUNT(*) as visits
                 FROM `{$table}` WHERE visit_time >= %s
                 GROUP BY DATE(visit_time) ORDER BY date ASC",
                $since
            ), ARRAY_A ) ?: array();
        }

        // 引荐趋势
        if ( class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) {
            $result['referral'] = YIMAI_GSO_Referral_Tracker::get_daily_trend( $days );
        }

        $ttl = ( $days <= 7 ) ? 5 * MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: SEO vs GEO 散点数据
     */
    public function ajax_seo_geo_scatter() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $cache_key = 'yimai_gso_l5_scatter_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;
        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();

        // 分别查询 SEO 和 GEO 评分 meta，然后在 PHP 层匹配并提取 overall
        $seo_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = %s AND p.post_type IN ({$pt_list})",
            'yimai_gso_seo_score'
        ), ARRAY_A );

        $geo_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = %s AND p.post_type IN ({$pt_list})",
            'yimai_gso_geo_score'
        ), ARRAY_A );

        $seo_map = array();
        if ( $seo_rows ) {
            foreach ( $seo_rows as $row ) {
                $pid = (int) $row['post_id'];
                $seo_map[ $pid ] = $this->extract_score_overall( $row['meta_value'] );
            }
        }

        $geo_map = array();
        if ( $geo_rows ) {
            foreach ( $geo_rows as $row ) {
                $pid = (int) $row['post_id'];
                $geo_map[ $pid ] = $this->extract_score_overall( $row['meta_value'] );
            }
        }

        // 取同时有 SEO 和 GEO 评分的文章 ID（最多 200 篇最新发布）
        $common_ids = array_intersect( array_keys( $seo_map ), array_keys( $geo_map ) );
        if ( empty( $common_ids ) ) {
            set_transient( $cache_key, array(), 2 * MINUTE_IN_SECONDS );
            wp_send_json_success( array() );
            return;
        }

        $ids_sql = implode( ',', array_map( 'intval', $common_ids ) );
        $posts   = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE ID IN ({$ids_sql}) AND post_status = 'publish'
             ORDER BY post_date DESC LIMIT 200",
            ARRAY_A
        );

        $points = array();
        if ( $posts ) {
            foreach ( $posts as $r ) {
                $pid = (int) $r['ID'];
                $points[] = array(
                    'x'     => (int) $seo_map[ $pid ],
                    'y'     => (int) $geo_map[ $pid ],
                    'label' => mb_strlen( $r['post_title'] ) > 30 ? mb_substr( $r['post_title'], 0, 30 ) . '...' : $r['post_title'],
                    'id'    => $pid,
                );
            }
        }

        set_transient( $cache_key, $points, 2 * MINUTE_IN_SECONDS );

        wp_send_json_success( $points );
    }

    // ==================== 工具方法 ====================

    /**
     * 从 meta_value 中安全提取 overall 分数
     *
     * WordPress 默认将数组序列化后存储，因此不能直接用 SQL JSON_EXTRACT。
     * 本方法兼容：PHP 数组、JSON 字符串、serialize 字符串、以及纯数字。
     *
     * @param mixed $raw postmeta 原始值
     * @return int 0-100 的分数，无效时返回 0
     */
    private function extract_score_overall( $raw ) {
        if ( empty( $raw ) ) {
            return 0;
        }

        // 1. 已经是数组
        if ( is_array( $raw ) && isset( $raw['overall'] ) ) {
            return (int) $raw['overall'];
        }

        // 2. 字符串：先尝试 JSON，再尝试反序列化
        if ( is_string( $raw ) ) {
            $trimmed = trim( $raw );

            // JSON
            if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
                $decoded = json_decode( $trimmed, true );
                if ( is_array( $decoded ) && isset( $decoded['overall'] ) ) {
                    return (int) $decoded['overall'];
                }
            }

            // PHP serialize
            if ( 'a:' === substr( $trimmed, 0, 2 ) || 'O:' === substr( $trimmed, 0, 2 ) ) {
                $unserialized = @maybe_unserialize( $trimmed );
                if ( is_array( $unserialized ) && isset( $unserialized['overall'] ) ) {
                    return (int) $unserialized['overall'];
                }
            }

            // 纯数字
            if ( is_numeric( $trimmed ) ) {
                return (int) $trimmed;
            }
        }

        return 0;
    }

    /**
     * 计算某评分类型的平均分（PHP 层反序列化）
     *
     * @param string $meta_key yimai_gso_seo_score | yimai_gso_geo_score
     * @param string $pt_list SQL IN 列表
     * @return float
     */
    private function compute_avg_score( $meta_key, $pt_list ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = %s AND p.post_type IN ({$pt_list})",
            $meta_key
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return 0;
        }

        $sum   = 0;
        $count = 0;
        foreach ( $rows as $row ) {
            $score = $this->extract_score_overall( $row['meta_value'] );
            if ( $score > 0 ) {
                $sum   += $score;
                $count++;
            }
        }

        return $count > 0 ? round( $sum / $count ) : 0;
    }

    /**
     * 获取评分分布（A-F 分桶）
     *
     * @param string $meta_key yimai_gso_seo_score | yimai_gso_geo_score
     * @return array
     */
    private function get_score_distribution( $meta_key ) {
        global $wpdb;
        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = %s AND p.post_type IN ({$pt_list})",
            $meta_key
        ), ARRAY_A );

        $distribution = array(
            'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0,
            'total' => 0,
            'avg'   => 0,
        );

        if ( $rows ) {
            $sum = 0;
            foreach ( $rows as $row ) {
                $score = $this->extract_score_overall( $row['meta_value'] );
                if ( $score <= 0 ) {
                    continue;
                }
                $sum += $score;

                if ( $score >= 85 ) {
                    $distribution['A']++;
                } elseif ( $score >= 70 ) {
                    $distribution['B']++;
                } elseif ( $score >= 55 ) {
                    $distribution['C']++;
                } elseif ( $score >= 40 ) {
                    $distribution['D']++;
                } else {
                    $distribution['F']++;
                }
            }
            $distribution['total'] = $distribution['A'] + $distribution['B'] + $distribution['C'] + $distribution['D'] + $distribution['F'];
            $distribution['avg']   = $distribution['total'] > 0 ? round( $sum / $distribution['total'] ) : 0;
        }

        return $distribution;
    }

    /**
     * 获取预警铃铛角标数量
     *
     * @return array
     */
    public function get_alert_badge_count() {
        if ( ! class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
            return array( 'critical' => 0, 'warning' => 0, 'total' => 0 );
        }

        $cache_key = 'yimai_gso_l5_alert_badge_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table = YIMAI_GSO_Alert_Engine::table_name();
        YIMAI_GSO_Alert_Engine::ensure_table();

        $result = array(
            'critical' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active' AND severity = 'critical'" ),
            'warning'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active' AND severity = 'warning'" ),
        );
        $result['total'] = $result['critical'] + $result['warning'];

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    // ==================== Sidebar 集成辅助 ====================

    /**
     * 检查 L5 功能是否对当前用户可用
     */
    public static function is_available() {
        return true;
    }
}
