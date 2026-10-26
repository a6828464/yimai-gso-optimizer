<?php
/**
 * 后台管理类 - 菜单注册、页面渲染、资源加载
 */
if ( ! defined( 'ABSPATH' ) ) exit;
// YIMAI_GSO_VERSION 防御性定义：防止部署异常或 opcache 导致常量未定义 Fatal Error
defined( 'YIMAI_GSO_VERSION' ) || define( 'YIMAI_GSO_VERSION', '2.4.3' );


class YIMAI_GSO_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 预览请求跳过管理后台初始化（防止内存溢出）
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_notices', array( $this, 'autopilot_cron_system_notice' ) );

        // 图片库 AJAX
        add_action( 'wp_ajax_yimai_gso_image_library_list', array( $this, 'ajax_image_library_list' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_upload', array( $this, 'ajax_image_library_upload' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_update', array( $this, 'ajax_image_library_update' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_batch_update', array( $this, 'ajax_image_library_batch_update' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_delete', array( $this, 'ajax_image_library_delete' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_stats', array( $this, 'ajax_image_library_stats' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_groups', array( $this, 'ajax_image_library_groups' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_group_create', array( $this, 'ajax_image_library_group_create' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_group_rename', array( $this, 'ajax_image_library_group_rename' ) );
        add_action( 'wp_ajax_yimai_gso_image_library_group_delete', array( $this, 'ajax_image_library_group_delete' ) );
        add_action( 'wp_ajax_yimai_gso_dismiss_cron_notice', array( $this, 'ajax_dismiss_cron_notice' ) );
        add_action( 'wp_ajax_yimai_gso_init_standard_categories', array( $this, 'ajax_init_standard_categories' ) );
        add_action( 'wp_ajax_yimai_gso_migrate_demo_categories', array( $this, 'ajax_migrate_demo_categories' ) );
        add_action( 'wp_ajax_yimai_gso_toggle_internal_link', array( $this, 'ajax_toggle_internal_link' ) );
        add_action( 'wp_ajax_yimai_gso_save_seo_meta_settings', array( $this, 'ajax_save_seo_meta_settings' ) );

        // L3 内容分析层 - 新鲜度列 + 仪表盘
        add_action( 'admin_init', array( $this, 'add_freshness_columns' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'add_freshness_dashboard_widget' ) );

        //  仪表盘改版 - 数据端点
        add_action( 'wp_ajax_yimai_gso_dashboard_data', array( $this, 'ajax_dashboard_data' ) );
    }

    /**
     * 注册文章/页面列表的新鲜度列
     */
    public function add_freshness_columns() {
        $post_types = array( 'post', 'page' );
        foreach ( $post_types as $pt ) {
            add_filter( "manage_{$pt}_posts_columns", array( $this, 'freshness_column_header' ) );
            add_action( "manage_{$pt}_posts_custom_column", array( $this, 'freshness_column_content' ), 10, 2 );
        }
    }

    /**
     * 新鲜度列头
     */
    public function freshness_column_header( $columns ) {
        $columns['yimai_gso_freshness'] = '新鲜度';
        return $columns;
    }

    /**
     * 新鲜度列内容
     */
    public function freshness_column_content( $column, $post_id ) {
        if ( 'yimai_gso_freshness' !== $column ) return;

        if ( ! class_exists( 'YIMAI_GSO_Content_Freshness' ) ) {
            echo '<span style="color:#94a3b8;">—</span>';
            return;
        }

        $freshness = YIMAI_GSO_Content_Freshness::get_instance();
        $data      = $freshness->get_freshness( $post_id );

        $color  = isset( $data['color'] ) ? $data['color'] : '#94a3b8';
        $label  = isset( $data['label'] ) ? $data['label'] : '未知';
        $days   = isset( $data['days_since_update'] ) ? (int) $data['days_since_update'] : 0;

        $title = $days > 0 ? "{$days}天前更新" : '今天更新';

        echo '<span style="display:inline-flex;align-items:center;gap:4px;" title="' . esc_attr( $title ) . '">';
        echo '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . esc_attr( $color ) . ';flex-shrink:0;"></span>';
        echo '<span style="font-size:12px;color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( $label ) . '</span>';
        echo '</span>';
    }

    /**
     * 仪表盘新鲜度小组件
     */
    public function add_freshness_dashboard_widget() {
        if ( ! class_exists( 'YIMAI_GSO_Content_Freshness' ) ) return;
        wp_add_dashboard_widget(
            'yimai_gso_freshness_dashboard',
            '内容新鲜度概览 — 一麦GSO GEO',
            array( $this, 'render_freshness_dashboard_widget' )
        );
    }

    public function render_freshness_dashboard_widget() {
        if ( ! class_exists( 'YIMAI_GSO_Content_Freshness' ) ) {
            echo '<p>新鲜度模块未启用</p>';
            return;
        }

        $freshness = YIMAI_GSO_Content_Freshness::get_instance();
        $stats     = $freshness->get_summary_stats();

        $total  = (int) ( $stats['total'] ?? 0 );
        $fresh  = (int) ( $stats['fresh'] ?? 0 );
        $normal = (int) ( $stats['normal'] ?? 0 );
        $stale  = (int) ( $stats['stale'] ?? 0 );
        $old    = (int) ( $stats['old'] ?? 0 );
        $pct    = (float) ( $stats['fresh_pct'] ?? 0 );

        echo '<div style="padding:4px 0;">';
        echo '<div style="font-size:28px;font-weight:800;color:#059669;margin-bottom:4px;">' . esc_html( $pct ) . '%</div>';
        echo '<div style="font-size:12px;color:#64748b;margin-bottom:12px;">内容新鲜度（共 ' . $total . ' 篇）</div>';

        $bars = array(
            array( 'label' => '新鲜', 'count' => $fresh, 'color' => '#059669' ),
            array( 'label' => '正常', 'count' => $normal, 'color' => '#0284c7' ),
            array( 'label' => '可能过时', 'count' => $stale, 'color' => '#d97706' ),
            array( 'label' => '已过时', 'count' => $old, 'color' => '#dc2626' ),
        );

        foreach ( $bars as $bar ) {
            $bar_pct = $total > 0 ? round( $bar['count'] / $total * 100, 1 ) : 0;
            echo '<div style="display:flex;align-items:center;margin-bottom:6px;font-size:12px;">';
            echo '<span style="width:60px;color:#475569;">' . esc_html( $bar['label'] ) . '</span>';
            echo '<div style="flex:1;background:#f1f5f9;border-radius:4px;height:14px;margin:0 8px;">';
            echo '<div style="width:' . esc_attr( $bar_pct ) . '%;height:100%;background:' . esc_attr( $bar['color'] ) . ';border-radius:4px;"></div>';
            echo '</div>';
            echo '<span style="width:40px;text-align:right;color:#475569;">' . esc_html( $bar['count'] ) . '</span>';
            echo '</div>';
        }

        echo '<p style="margin-top:8px;font-size:11px;color:#94a3b8;">AI 搜索引擎偏好最新内容，建议保持内容更新频率</p>';
        echo '</div>';
    }

    private function get_asset_version( $relative_path ) {
        $asset_file = YIMAI_GSO_PLUGIN_DIR . ltrim( $relative_path, '/\\' );

        if ( file_exists( $asset_file ) ) {
            return ( defined( 'YIMAI_GSO_VERSION' ) ? YIMAI_GSO_VERSION : '2.4.3' ) . '.' . filemtime( $asset_file );
        }

        return defined( 'YIMAI_GSO_VERSION' ) ? YIMAI_GSO_VERSION : '2.4.3';
    }

    public function register_menus() {
        // 主菜单：一麦GSO双擎优化
        add_menu_page(
            '一麦GSO双擎优化',
            '一麦GSO双擎优化',
            'edit_posts',
            'yimai-gso-center',
            array( $this, 'render_main_center' ),
            'dashicons-edit-large',
            25
        );

    }

    /**
     * 检测永动发布启用但未配置系统级Cron时的提示
     */
    public function autopilot_cron_system_notice() {
        // 只在插件后台页面显示
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'yimai-gso' ) === false ) {
            return;
        }

        // 只在永动发布页面显示（tab=autopilot）
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
        if ( $current_tab !== 'autopilot' ) {
            return;
        }

        // 检测条件：DISABLE_WP_CRON 未定义 且 永动发布已启用
        if ( defined( 'DISABLE_WP_CRON' ) ) {
            return;
        }

        if ( ! class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ) {
            return;
        }

        $settings = YIMAI_GSO_Autopilot_Plan::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // transient限频：每天最多提示一次
        $dismissed = get_transient( 'yimai_gso_cron_system_notice_dismissed' );
        if ( $dismissed ) {
            return;
        }
        ?>
        <div class="notice notice-warning is-dismissible yimai-gso-cron-notice">
            <p><strong>⚡ 永动发布建议配置系统级Cron：</strong>
            当前站点使用 WordPress 默认的 <code>WP-Cron</code>，需要用户访问页面才能触发定时任务。
            如果网站流量较低，永动发布可能不会准时执行。建议在服务器上配置系统级Cron定时任务以实现准时触发。
            <br>可在服务器计划任务中定期访问 WordPress，以提高 WP-Cron 的准时性。
            </p>
        </div>
        <?php
        wp_add_inline_script( 'yimai-gso-admin', 'jQuery(document).on("click", ".yimai-gso-cron-notice .notice-dismiss", function() {
            jQuery.post(ajaxurl, {
                action: "yimai_gso_dismiss_cron_notice",
                nonce: "' . wp_create_nonce( 'yimai_gso_nonce' ) . '",
            });
        });' );
    }

    public function enqueue_assets( $hook ) {
        // plugins.php 页面：只加载 admin.js 用于"检查更新"按钮
        if ( $hook === 'plugins.php' ) {
            wp_enqueue_script(
                'yimai-gso-admin',
                YIMAI_GSO_PLUGIN_URL . 'admin/js/admin.js',
                array( 'jquery' ),
                YIMAI_GSO_VERSION,
                true
            );
            wp_localize_script( 'yimai-gso-admin', 'yimaiGsoData', array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'yimai_gso_nonce' ),
                'pluginUrl' => YIMAI_GSO_PLUGIN_URL,
                'version'   => YIMAI_GSO_VERSION,
            ) );
            return;
        }

        if ( strpos( $hook, 'yimai-gso' ) === false ) {
            return;
        }

        // 禁用 WordPress 默认 emoji 图片转换，避免国内网络下 s.w.org 资源裂图
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

        // AI内容助手统一页面，需要加载编辑器和媒体库
        wp_enqueue_editor();
        wp_enqueue_media();

        wp_enqueue_style(
            'yimai-gso-admin',
            YIMAI_GSO_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            $this->get_asset_version( 'admin/css/admin.css' )
        );

        // P7：主面板样式（原为 class-admin.php 内联 <style>，2,325 行 / 占该文件 22%）。
        // 依赖 'yimai-gso-admin' 以确保在 admin.css 之后加载 ——
        // 原内联块位于页面中段，同样在 admin.css（head）之后，
        // 因此这样写可完整保留两者 45 个重叠选择器的原有层叠优先级。
        wp_enqueue_style(
            'yimai-gso-panels',
            YIMAI_GSO_PLUGIN_URL . 'admin/css/panels.css',
            array( 'yimai-gso-admin' ),
            $this->get_asset_version( 'admin/css/panels.css' )
        );

        // 仪表盘 v5 Chart.js
        wp_enqueue_script(
            'yimai-gso-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
            array(),
            '4.4.7',
            array(
                'strategy'  => 'defer',
                'in_footer' => true,
            )
        );

        // 为 Chart.js CDN 添加 SRI 完整性校验
        add_filter( 'script_loader_tag', function ( $tag, $handle ) {
            if ( 'yimai-gso-chartjs' === $handle ) {
                $tag = str_replace(
                    '></script>',
                    ' integrity="sha384-vsrfeLOOY6KuIYKDlmVH5UiBmgIdB1oEf7p01YgWHuqmOHfZr374+odEv96n9tNC=" crossorigin="anonymous"></script>',
                    $tag
                );
            }
            return $tag;
        }, 10, 2 );

        wp_enqueue_script(
            'yimai-gso-admin',
            YIMAI_GSO_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            $this->get_asset_version( 'admin/js/admin.js' ),
            true
        );

        // LLMs.txt 生成器后台交互（兼容中英文 hook 前缀）
        if ( strpos( $hook, 'yimai-gso-center' ) !== false || strpos( $hook, 'yimai-gso-llms-txt' ) !== false ) {
            wp_enqueue_script(
                'yimai-gso-llms-txt-admin',
                YIMAI_GSO_PLUGIN_URL . 'admin/js/llms-txt-admin.js',
                array( 'jquery', 'yimai-gso-admin' ),
                YIMAI_GSO_VERSION,
                true
            );
        }

        // 在线更新面板交互（yimai-gso-admin 句柄携带 yimaiGsoData）
        wp_enqueue_script(
            'yimai-gso-updater',
            YIMAI_GSO_PLUGIN_URL . 'admin/js/updater.js',
            array( 'jquery', 'yimai-gso-admin' ),
            $this->get_asset_version( 'admin/js/updater.js' ),
            true
        );

        // L4 双引擎评分面板 — 已移至插件页弹窗（SEO/GEO独立按钮），编辑页不再加载

        // 传递数据给 JS
        wp_localize_script( 'yimai-gso-admin', 'yimaiGsoData', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'yimai_gso_nonce' ),
            'seoNonce'     => wp_create_nonce( 'yimai_gso_seo_nonce' ),
            'ajaxNonce'    => wp_create_nonce( 'yimai_gso_ajax_nonce' ), // L4 GEO 弹窗用
            'pluginUrl'    => YIMAI_GSO_PLUGIN_URL,
            'version'      => YIMAI_GSO_VERSION,
            'providers'    => YIMAI_GSO_Settings::get_ai_providers(),
            'imgProviders' => YIMAI_GSO_Settings::get_image_providers(),
            'categories'   => $this->get_categories_list(),
            'featureGate'  => array(
                'plan'             => 'full',
                'featureMap'       => array_fill_keys( array(
                    'single_generate', 'batch_generate', 'auto_tags', 'auto_alt',
                    'batch_seo', 'seo_push', 'smart_link', 'smart_internal_links',
                    'autopilot', 'autopilot_publish', 'llms_txt', 'geo_entity',
                    'crawler_editor', 'crawler_tracker', 'l5_dashboard',
                    'ai_platforms', 'seo_geo_bridge', 'canonical_manager',
                ), true ),
                'lockedFeatures'   => array(),
                'readonlyFeatures' => array(),
                'paramConfig'      => YIMAI_GSO_Param_Permissions::get_client_config(),
                'canEditPosts'     => current_user_can( 'edit_posts' ),
                'canPublishPosts'  => current_user_can( 'publish_posts' ),
            ),
            // 不再传递真实 API Key 到前端，测试连接时由服务器读取
        ) );

    }

    /**
     * 获取分类列表（带缓存）
     * @param string $format 'raw' 返回原始 WP_Term 对象；'select' 返回 [{id, name}] 数组（用于JS）
     */
    private function get_categories_list( $format = 'select' ) {
        $cached = get_transient( 'yimai_gso_categories_raw' );
        if ( false !== $cached ) {
            return 'select' === $format
                ? array_map( function( $c ) { return array( 'id' => $c->term_id, 'name' => $c->name ); }, $cached )
                : $cached;
        }
        $cats = get_categories( array( 'hide_empty' => false, 'number' => 100 ) );
        set_transient( 'yimai_gso_categories_raw', $cats, 6 * HOUR_IN_SECONDS );
        return 'select' === $format
            ? array_map( function( $c ) { return array( 'id' => $c->term_id, 'name' => $c->name ); }, $cats )
            : $cats;
    }

    private function get_tags_list_raw() {
        $cached = get_transient( 'yimai_gso_tags_raw' );
        if ( false !== $cached ) {
            return $cached;
        }
        $tags = get_tags( array( 'hide_empty' => false, 'number' => 200 ) );
        set_transient( 'yimai_gso_tags_raw', $tags, 6 * HOUR_IN_SECONDS );
        return $tags;
    }

    private function get_feature_meta( $feature ) {
        return array(
            'feature'   => $feature,
            'available' => true,
            'readonly'  => false,
            'disabled'  => false,
            'label'     => $feature,
        );
    }

    private function render_feature_notice( $meta, $locked_text = '', $readonly_text = '' ) {
        unset( $meta, $locked_text, $readonly_text );
    }

    private function render_batch_schedule_controls() {
        $can_advanced_publish = $this->can_edit_feature( 'schedule_publish' )
            && current_user_can( 'publish_posts' );
        ?>
        <div class="yimai-gso-form-group">
            <label>发布方式</label>
            <div class="yimai-gso-radio-group">
                <label class="yimai-gso-radio">
                    <input type="radio" name="yimai-gso-batch-status" value="draft" checked>
                    <span>📝 存为草稿</span>
                </label>
                <label class="yimai-gso-radio<?php echo $can_advanced_publish ? '' : ' is-disabled'; ?>">
                    <input type="radio" name="yimai-gso-batch-status" value="publish"<?php echo disabled( ! $can_advanced_publish, true, false ); ?>>
                    <span>🚀 直接发布</span>
                </label>
                <label class="yimai-gso-radio<?php echo $can_advanced_publish ? '' : ' is-disabled'; ?>">
                    <input type="radio" name="yimai-gso-batch-status" value="future"<?php echo disabled( ! $can_advanced_publish, true, false ); ?>>
                    <span>⏰ 定时发布</span>
                </label>
            </div>
        </div>

        <div class="yimai-gso-form-group yimai-gso-schedule-group" id="yimai-gso-batch-schedule-group" style="display:none;">
            <label>定时发布 - 起始时间</label>
            <input type="datetime-local" id="yimai-gso-batch-schedule-start">
            <small>文章会按此时间开始，每隔指定间隔依次定时发布</small>
        </div>

        <div class="yimai-gso-form-group yimai-gso-schedule-group" id="yimai-gso-batch-schedule-interval-group" style="display:none;">
            <label>发布间隔</label>
            <select id="yimai-gso-batch-schedule-interval">
                <option value="300">每隔 5 分钟</option>
                <option value="600">每隔 10 分钟</option>
                <option value="1800">每隔 30 分钟</option>
                <option value="3600" selected>每隔 1 小时</option>
                <option value="7200">每隔 2 小时</option>
                <option value="14400">每隔 4 小时</option>
                <option value="86400">每隔 1 天</option>
            </select>
            <small>每篇文章按此间隔依次定时发布</small>
        </div>
        <?php
    }

    private function can_edit_feature( $feature ) {
        unset( $feature );
        return true;
    }

    private function save_detection_settings() {
        if ( ! check_admin_referer( 'yimai_gso_detection_nonce', '_wpnonce', false ) ) {
            wp_die( '安全验证失败，请刷新页面后重试。' );
        }
        update_option( 'yimai_gso_detection_enabled', isset( $_POST['yimai_gso_detection_enabled'] ) ? 1 : 0 );
    }

    private function save_internal_link_settings() {
        if ( ! $this->can_edit_feature( 'smart_internal_links' ) ) {
            return;
        }

        if ( $this->can_edit_feature( 'smart_internal_links_basic' ) ) {
            update_option( 'yimai_gso_internal_link_enabled', isset( $_POST['yimai_gso_internal_link_enabled'] ) ? 1 : 0 );
            update_option( 'yimai_gso_internal_link_max', max( 1, min( 10, absint( $_POST['yimai_gso_internal_link_max'] ?? 3 ) ) ) );
            update_option( 'yimai_gso_internal_link_category_nav', isset( $_POST['yimai_gso_internal_link_category_nav'] ) ? 1 : 0 );
        }

        if ( $this->can_edit_feature( 'smart_internal_links_scope' ) ) {
            $candidate_mode = sanitize_text_field( $_POST['yimai_gso_internal_link_candidate_mode'] ?? 'recent' );
            if ( ! in_array( $candidate_mode, array( 'recent', 'same_category', 'same_category_first' ), true ) ) {
                $candidate_mode = 'recent';
            }

            update_option( 'yimai_gso_internal_link_candidate_mode', $candidate_mode );
            update_option( 'yimai_gso_internal_link_candidate_limit', max( 20, min( 200, absint( $_POST['yimai_gso_internal_link_candidate_limit'] ?? 50 ) ) ) );
        }

        if ( $this->can_edit_feature( 'smart_internal_links_exclude' ) ) {
            $excluded_categories = array_values( array_filter( array_map( 'absint', (array) ( $_POST['yimai_gso_internal_link_excluded_category_ids'] ?? array() ) ) ) );
            $excluded_tags       = array_values( array_filter( array_map( 'absint', (array) ( $_POST['yimai_gso_internal_link_excluded_tag_ids'] ?? array() ) ) ) );

            update_option( 'yimai_gso_internal_link_excluded_category_ids', $excluded_categories );
            update_option( 'yimai_gso_internal_link_excluded_tag_ids', $excluded_tags );
        }

        if ( $this->can_edit_feature( 'smart_internal_links_keyword_library' ) ) {
            update_option( 'yimai_gso_internal_link_keyword_library', sanitize_textarea_field( $_POST['yimai_gso_internal_link_keyword_library'] ?? '' ) );
        }

        if ( $this->can_edit_feature( 'smart_internal_links_anchor_strategy' ) ) {
            $anchor_policy = sanitize_text_field( $_POST['yimai_gso_internal_link_anchor_policy'] ?? 'natural' );
            if ( ! in_array( $anchor_policy, array( 'natural', 'exact', 'long_tail', 'title_keyword' ), true ) ) {
                $anchor_policy = 'natural';
            }

            update_option( 'yimai_gso_internal_link_anchor_policy', $anchor_policy );
        }
    }

    private function save_autopilot_settings() {
        if ( ! $this->can_edit_feature( 'autopilot_publish' ) || ! class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ) {
            return;
        }

        // 保留当前 enabled 状态（计划开关由 AJAX 独立控制，不在 form 内提交）
        $current_settings = YIMAI_GSO_Autopilot_Plan::get_settings();

        YIMAI_GSO_Autopilot_Plan::save_settings( array(
            'enabled'        => ! empty( $current_settings['enabled'] ) ? 1 : 0,
            'run_time'       => sanitize_text_field( $_POST['yimai_gso_autopilot_run_time'] ?? '08:00' ),
            'daily_count'    => absint( $_POST['yimai_gso_autopilot_daily_count'] ?? 3 ),
            'topic_keywords' => wp_unslash( $_POST['yimai_gso_autopilot_topic_keywords'] ?? '' ),
            'extra_prompt'   => wp_unslash( $_POST['yimai_gso_autopilot_extra_prompt'] ?? '' ),
            'category_id'    => absint( $_POST['yimai_gso_autopilot_category_id'] ?? 1 ),
            'word_count'     => absint( $_POST['yimai_gso_autopilot_word_count'] ?? 1000 ),
            'image_count'    => absint( $_POST['yimai_gso_autopilot_image_count'] ?? 1 ),
            'tags'           => sanitize_text_field( $_POST['yimai_gso_autopilot_tags'] ?? '' ),
            'use_ai_tags'    => isset( $_POST['yimai_gso_autopilot_use_ai_tags'] ) ? 1 : 0,
            'auto_category'  => isset( $_POST['yimai_gso_autopilot_auto_category'] ) ? 1 : 0,
            'post_status'    => sanitize_text_field( $_POST['yimai_gso_autopilot_post_status'] ?? 'publish' ),
            'future_delay_hours' => absint( $_POST['yimai_gso_autopilot_future_delay_hours'] ?? 2 ),
            'log_retention_days' => absint( $_POST['yimai_gso_autopilot_log_retention_days'] ?? 30 ),
        ) );

        // 保存设置后同步 cron 调度（永动发布 + 日志清理）
        if ( class_exists( 'YIMAI_GSO_Autopilot_Scheduler' ) ) {
            YIMAI_GSO_Autopilot_Scheduler::sync_schedule();
            YIMAI_GSO_Autopilot_Scheduler::sync_cleanup_schedule();
        }
    }

    private function render_internal_links_panel( $active_tab ) {
        $subtab = isset( $_GET['subtab'] ) ? sanitize_text_field( $_GET['subtab'] ) : 'settings';
        $valid_subtabs = array( 'settings', 'rebuild', 'audit' );
        if ( ! in_array( $subtab, $valid_subtabs, true ) ) {
            $subtab = 'settings';
        }

        $stats      = class_exists( 'YIMAI_GSO_Internal_Link' ) ? YIMAI_GSO_Internal_Link::get_summary_stats() : array();
        $logs       = class_exists( 'YIMAI_GSO_Internal_Link' ) ? YIMAI_GSO_Internal_Link::get_recent_logs( 15 ) : array();
        $categories = $this->get_categories_list('raw');
        $tags       = $this->get_tags_list_raw();

        $enabled             = (bool) YIMAI_GSO_Settings::get( 'internal_link_enabled', 0 );
        $max_links           = absint( YIMAI_GSO_Settings::get( 'internal_link_max', 3 ) );
        $candidate_mode      = YIMAI_GSO_Settings::get( 'internal_link_candidate_mode', 'recent' );
        $candidate_limit     = absint( YIMAI_GSO_Settings::get( 'internal_link_candidate_limit', 50 ) );
        $excluded_categories = array_map( 'absint', (array) YIMAI_GSO_Settings::get( 'internal_link_excluded_category_ids', array() ) );
        $excluded_tags       = array_map( 'absint', (array) YIMAI_GSO_Settings::get( 'internal_link_excluded_tag_ids', array() ) );
        $keyword_library     = (string) YIMAI_GSO_Settings::get( 'internal_link_keyword_library', '' );
        $anchor_policy       = YIMAI_GSO_Settings::get( 'internal_link_anchor_policy', 'natural' );

        $plan_label = '一麦自用版';

        $parent_meta   = $this->get_feature_meta( 'smart_internal_links' );
        $basic_meta    = $this->get_feature_meta( 'smart_internal_links_basic' );
        $scope_meta    = $this->get_feature_meta( 'smart_internal_links_scope' );
        $exclude_meta  = $this->get_feature_meta( 'smart_internal_links_exclude' );
        $keyword_meta  = $this->get_feature_meta( 'smart_internal_links_keyword_library' );
        $anchor_meta   = $this->get_feature_meta( 'smart_internal_links_anchor_strategy' );
        $logs_meta     = $this->get_feature_meta( 'smart_internal_links_logs' );
        $rebuild_meta  = $this->get_feature_meta( 'smart_internal_links_rebuild' );
        $audit_meta    = $this->get_feature_meta( 'smart_internal_links_broken_audit' );
        $parent_locked = ! empty( $parent_meta['disabled'] );

        $candidate_mode_options = array(
            'recent'              => '最近文章优先',
            'same_category'       => '仅同分类候选',
            'same_category_first' => '同分类优先，其次全站',
        );
        $anchor_policy_options = array(
            'natural'       => '自然优先',
            'exact'         => '精准匹配',
            'long_tail'     => '优先长尾词',
            'title_keyword' => '优先标题核心词',
        );
        $source_labels = array(
            'manual'  => '手动发布',
            'batch'   => '批量生成',
            'publish' => '发布流程',
        );
        $status_labels = array(
            'success' => '成功',
            'warning' => '警告',
            'skipped' => '跳过',
        );
        $status_colors = array(
            'success' => '#10b981',
            'warning' => '#f59e0b',
            'skipped' => '#94a3b8',
        );

        $base_url = admin_url( 'admin.php?page=yimai-gso-center&tab=internal-links' );
        ?>
        <div class="yimai-gso-main-panel <?php echo $active_tab === 'internal-links' ? 'active' : ''; ?>" data-tab="internal-links">

            <div class="yimai-gso-page-card-header">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">智能内链</h2>
                    <p class="yimai-gso-card-desc">自动为关键词建立文章间内链网络，提升网站权重传递和用户体验</p>
                </div>
            </div>

            <!-- Header -->
            <div class="yimai-gso-link-header">
                <div class="yimai-gso-link-header-left">
                    <h1 class="yimai-gso-page-title" style="display:none">
                        <span class="dashicons dashicons-admin-links"></span>
                        智能内链
                    </h1>
                    <p class="yimai-gso-link-header-desc" style="display:none">自动为文章插入相关站内链接，提升SEO权重与用户体验</p>
                </div>
                <div class="yimai-gso-link-header-right">
                    <span class="yimai-gso-link-plan-badge"><?php echo esc_html( $plan_label ); ?></span>
                    <label class="yimai-gso-link-master-toggle <?php echo $enabled ? 'is-on' : ''; ?><?php echo $parent_locked ? ' is-locked' : ''; ?>">
                        <input type="checkbox" id="yimai-gso-internal-link-master-toggle" <?php checked( $enabled ); ?> <?php disabled( $parent_locked ); ?>>
                        <span class="yimai-gso-link-toggle-track"></span>
                        <span class="yimai-gso-link-toggle-label"><?php echo $enabled ? '已开启' : '已关闭'; ?></span>
                    </label>
                </div>
            </div>
            <!-- Sub Tabs -->
            <div class="yimai-gso-link-tabs yimai-gso-tabs-container">
                <a href="<?php echo esc_url( $base_url . '&subtab=settings' ); ?>" class="yimai-gso-tab-modern <?php echo $subtab === 'settings' ? 'active' : ''; ?>" data-subtab="settings">
                    <span class="dashicons dashicons-admin-generic"></span>
                    基础设置
                </a>
                <a href="<?php echo esc_url( $base_url . '&subtab=rebuild' ); ?>" class="yimai-gso-tab-modern <?php echo $subtab === 'rebuild' ? 'active' : ''; ?>" data-subtab="rebuild">
                    <span class="dashicons dashicons-update"></span>
                    批量重建
                </a>
                <a href="<?php echo esc_url( $base_url . '&subtab=audit' ); ?>" class="yimai-gso-tab-modern <?php echo $subtab === 'audit' ? 'active' : ''; ?>" data-subtab="audit">
                    <span class="dashicons dashicons-search"></span>
                    失效巡检
                </a>
            </div>

            <!-- Tab Content -->
            <div class="yimai-gso-link-tab-content">
                <?php if ( $subtab === 'settings' ) : ?>
                    <?php $this->render_internal_links_settings_tab( $stats, $logs, $categories, $tags, $enabled, $max_links, $candidate_mode, $candidate_limit, $excluded_categories, $excluded_tags, $keyword_library, $anchor_policy, $parent_locked, $parent_meta, $basic_meta, $scope_meta, $exclude_meta, $keyword_meta, $anchor_meta, $logs_meta, $candidate_mode_options, $anchor_policy_options, $source_labels, $status_labels, $status_colors ); ?>
                <?php elseif ( $subtab === 'rebuild' ) : ?>
                    <?php $this->render_internal_links_rebuild_tab( $stats, $rebuild_meta, $parent_locked ); ?>
                <?php elseif ( $subtab === 'audit' ) : ?>
                    <?php $this->render_internal_links_audit_tab( $audit_meta, $parent_locked ); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * 智能内链 - 基础设置标签页
     */
    private function render_internal_links_settings_tab( $stats, $logs, $categories, $tags, $enabled, $max_links, $candidate_mode, $candidate_limit, $excluded_categories, $excluded_tags, $keyword_library, $anchor_policy, $parent_locked, $parent_meta, $basic_meta, $scope_meta, $exclude_meta, $keyword_meta, $anchor_meta, $logs_meta, $candidate_mode_options, $anchor_policy_options, $source_labels, $status_labels, $status_colors ) {
        ?>
        <!-- Stats Cards -->
        <div class="yimai-gso-link-stats-grid">
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#eef2ff;color:#6366f1;">📊</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num"><?php echo esc_html( $stats['total'] ?? 0 ); ?></div>
                    <div class="yimai-gso-link-stat-label">最近执行次数</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#d1fae5;color:#10b981;">✅</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num"><?php echo esc_html( $stats['success'] ?? 0 ); ?></div>
                    <div class="yimai-gso-link-stat-label">成功插链</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#dbeafe;color:#3b82f6;">🔢</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num"><?php echo esc_html( $stats['inserted'] ?? 0 ); ?></div>
                    <div class="yimai-gso-link-stat-label">累计插入条数</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#fef3c7;color:#f59e0b;">🕒</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" style="font-size:15px;"><?php echo esc_html( ! empty( $stats['last_run'] ) ? $stats['last_run'] : '暂无' ); ?></div>
                    <div class="yimai-gso-link-stat-label">最近运行时间</div>
                </div>
            </div>
        </div>

        <div class="yimai-gso-link-layout">
            <!-- Left: Settings Form -->
            <div class="yimai-gso-link-left">
                <form method="post" class="yimai-gso-link-settings-form">
                    <?php wp_nonce_field( 'yimai_gso_internal_links_nonce' ); ?>

                    <!-- Basic Settings -->
                    <div class="yimai-gso-link-section">
                        <div class="yimai-gso-link-section-header">
                            <div class="yimai-gso-link-section-icon" style="background:#eff6ff;">⚙️</div>
                            <div>
                                <h3>基础配置</h3>
                                <p>控制总开关与每篇最大内链数</p>
                            </div>
                        </div>
                        <div class="yimai-gso-link-section-body" <?php echo ( $parent_locked || ! empty( $basic_meta['disabled'] ) ) ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                            <div class="yimai-gso-form-group">
                                <label class="yimai-gso-toggle-label">
                                    <input type="checkbox" name="yimai_gso_internal_link_enabled" value="1" <?php checked( $enabled ); ?>>
                                    <span class="yimai-gso-toggle-switch"></span>
                                    <span class="yimai-gso-toggle-text">启用智能内链</span>
                                </label>
                                <small>开启后，文章在真正发布或定时写入时会自动尝试插入站内相关链接。</small>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label>每篇最大内链数</label>
                                <input type="number" name="yimai_gso_internal_link_max" min="1" max="10" value="<?php echo esc_attr( $max_links ); ?>">
                                <small>建议 2-5 条，过高容易影响阅读和自然度。</small>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label class="yimai-gso-toggle">
                                    <input type="checkbox" name="yimai_gso_internal_link_category_nav" value="1" <?php checked( 1, (int) YIMAI_GSO_Settings::get( 'internal_link_category_nav', 1 ) ); ?>>
                                    <span class="yimai-gso-toggle-switch"></span>
                                    <span class="yimai-gso-toggle-text">文末追加所属分类链接</span>
                                </label>
                                <small>文章末尾自动加一条「本文属于『XX』分类，查看同类文章」的链接，指向分类归档页——分类页本身可被收录和排名，也能让站内权重向分类页集中。文章数不足 2 的分类与「未分类」会自动跳过。</small>
                            </div>
                        </div>
                    </div>

                    <!-- Scope Settings -->
                    <div class="yimai-gso-link-section">
                        <div class="yimai-gso-link-section-header">
                            <div class="yimai-gso-link-section-icon" style="background:#f0fdf4;">🎯</div>
                            <div>
                                <h3>候选范围控制</h3>
                                <p>调整候选文章的召回方式与数量</p>
                            </div>
                        </div>
                        <div class="yimai-gso-link-section-body" <?php echo ( $parent_locked || ! empty( $scope_meta['disabled'] ) ) ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                            <div class="yimai-gso-form-group">
                                <label>候选文章策略</label>
                                <select name="yimai_gso_internal_link_candidate_mode">
                                    <?php foreach ( $candidate_mode_options as $mode_value => $mode_label ) : ?>
                                        <option value="<?php echo esc_attr( $mode_value ); ?>" <?php selected( $candidate_mode, $mode_value ); ?>><?php echo esc_html( $mode_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>可选"最近文章优先 / 仅同分类 / 同分类优先"，用于控制 AI 可参考的候选集合。</small>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label>候选文章上限</label>
                                <input type="number" name="yimai_gso_internal_link_candidate_limit" min="20" max="200" value="<?php echo esc_attr( $candidate_limit ); ?>">
                                <small>系统会先从候选池里打分，再挑出最相关的文章交给 AI 决策。</small>
                            </div>
                        </div>
                    </div>

                    <!-- Exclude Settings -->
                    <div class="yimai-gso-link-section">
                        <div class="yimai-gso-link-section-header">
                            <div class="yimai-gso-link-section-icon" style="background:#fef2f2;">🚫</div>
                            <div>
                                <h3>排除分类 / 标签</h3>
                                <p>排除不希望参与候选召回的分类和标签</p>
                            </div>
                        </div>
                        <div class="yimai-gso-link-section-body" <?php echo ( $parent_locked || ! empty( $exclude_meta['disabled'] ) ) ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                            <div class="yimai-gso-form-group">
                                <label>排除分类</label>
                                <div class="yimai-gso-link-checkbox-grid">
                                    <?php if ( empty( $categories ) ) : ?>
                                        <span style="color:#94a3b8;">暂无分类数据</span>
                                    <?php else : ?>
                                        <?php foreach ( $categories as $cat ) : ?>
                                            <label class="yimai-gso-link-checkbox-item">
                                                <input type="checkbox" name="yimai_gso_internal_link_excluded_category_ids[]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( (int) $cat->term_id, $excluded_categories, true ) ); ?>>
                                                <span><?php echo esc_html( $cat->name ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label>排除标签</label>
                                <div class="yimai-gso-link-checkbox-grid">
                                    <?php if ( empty( $tags ) ) : ?>
                                        <span style="color:#94a3b8;">暂无标签数据</span>
                                    <?php else : ?>
                                        <?php foreach ( $tags as $tag ) : ?>
                                            <label class="yimai-gso-link-checkbox-item">
                                                <input type="checkbox" name="yimai_gso_internal_link_excluded_tag_ids[]" value="<?php echo esc_attr( $tag->term_id ); ?>" <?php checked( in_array( (int) $tag->term_id, $excluded_tags, true ) ); ?>>
                                                <span><?php echo esc_html( $tag->name ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Keyword Library -->
                    <div class="yimai-gso-link-section">
                        <div class="yimai-gso-link-section-header">
                            <div class="yimai-gso-link-section-icon" style="background:#faf5ff;">🔑</div>
                            <div>
                                <h3>固定关键词库</h3>
                                <p>维护一组优先参考的锚文本词库</p>
                            </div>
                        </div>
                        <div class="yimai-gso-link-section-body" <?php echo ( $parent_locked || ! empty( $keyword_meta['disabled'] ) ) ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                            <div class="yimai-gso-form-group">
                                <label>关键词库</label>
                                <textarea name="yimai_gso_internal_link_keyword_library" rows="6" placeholder="例如：&#10;AI写作&#10;SEO优化&#10;站内链接策略"><?php echo esc_textarea( $keyword_library ); ?></textarea>
                                <small>这些词会优先提供给 AI 作为锚文本候选，但仍会以正文自然出现为前提。</small>
                            </div>
                        </div>
                    </div>

                    <!-- Anchor Policy -->
                    <div class="yimai-gso-link-section">
                        <div class="yimai-gso-link-section-header">
                            <div class="yimai-gso-link-section-icon" style="background:#fff7ed;">🔗</div>
                            <div>
                                <h3>锚文本策略</h3>
                                <p>调整 AI 选择锚文本时的偏好</p>
                            </div>
                        </div>
                        <div class="yimai-gso-link-section-body" <?php echo ( $parent_locked || ! empty( $anchor_meta['disabled'] ) ) ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                            <div class="yimai-gso-form-group">
                                <label>锚文本策略</label>
                                <select name="yimai_gso_internal_link_anchor_policy">
                                    <?php foreach ( $anchor_policy_options as $policy_value => $policy_label ) : ?>
                                        <option value="<?php echo esc_attr( $policy_value ); ?>" <?php selected( $anchor_policy, $policy_value ); ?>><?php echo esc_html( $policy_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>自然优先最稳；精准匹配更适合已沉淀好关键词体系的网站。</small>
                            </div>
                        </div>
                    </div>

                    <div class="yimai-gso-link-save-bar">
                        <button type="submit" name="yimai_gso_save_internal_link_settings" class="yimai-gso-save-btn" <?php disabled( $parent_locked ); ?>>
                            <span class="dashicons dashicons-yes"></span>
                            保存智能内链设置
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right: Logs -->
            <div class="yimai-gso-link-right">
                <div class="yimai-gso-link-panel">
                    <div class="yimai-gso-link-panel-header">
                        <h2>📋 最近执行日志</h2>
                        <span class="yimai-gso-link-badge">保留最近 100 条</span>
                    </div>
                    <?php if ( empty( $logs ) ) : ?>
                        <div class="yimai-gso-link-empty">
                            <div class="yimai-gso-link-empty-icon">📭</div>
                            <p>还没有内链执行记录</p>
                            <span>等下一篇文章进入发布流程后，这里会自动出现结果</span>
                        </div>
                    <?php else : ?>
                        <div class="yimai-gso-link-log-list">
                            <?php foreach ( $logs as $log ) : ?>
                                <?php $status = $log['status'] ?? 'skipped'; ?>
                                <div class="yimai-gso-link-log-item" style="border-left-color:<?php echo esc_attr( $status_colors[ $status ] ?? '#94a3b8' ); ?>">
                                    <div class="yimai-gso-link-log-top">
                                        <strong><?php echo esc_html( $log['post_title'] ?? '未命名文章' ); ?></strong>
                                        <span class="yimai-gso-link-log-badge" style="background:<?php echo esc_attr( $status_colors[ $status ] ?? '#94a3b8' ); ?>20;color:<?php echo esc_attr( $status_colors[ $status ] ?? '#94a3b8' ); ?>">
                                            <?php echo esc_html( $status_labels[ $status ] ?? '跳过' ); ?>
                                        </span>
                                    </div>
                                    <div class="yimai-gso-link-log-meta">
                                        <span><?php echo esc_html( $source_labels[ $log['source'] ?? 'publish' ] ?? '发布流程' ); ?></span>
                                        <span><?php echo esc_html( $log['time'] ?? '' ); ?></span>
                                        <span><?php echo esc_html( $log['inserted_count'] ?? 0 ); ?> 条内链</span>
                                    </div>
                                    <div class="yimai-gso-link-log-msg"><?php echo esc_html( $log['message'] ?? '' ); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 智能内链 - 批量重建标签页
     */
    private function render_internal_links_rebuild_tab( $stats, $rebuild_meta, $parent_locked ) {
        $is_disabled = $parent_locked || ! empty( $rebuild_meta['disabled'] );
        ?>
        <!-- Stats Cards -->
        <div class="yimai-gso-link-stats-grid">
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#eef2ff;color:#6366f1;">📄</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-rebuild-stat-total">-</div>
                    <div class="yimai-gso-link-stat-label">待重建文章</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#d1fae5;color:#10b981;">✅</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-rebuild-stat-success"><?php echo esc_html( $stats['success'] ?? 0 ); ?></div>
                    <div class="yimai-gso-link-stat-label">历史成功</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#dbeafe;color:#3b82f6;">🔢</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-rebuild-stat-inserted"><?php echo esc_html( $stats['inserted'] ?? 0 ); ?></div>
                    <div class="yimai-gso-link-stat-label">累计插入</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#fef3c7;color:#f59e0b;">🕒</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" style="font-size:15px;"><?php echo esc_html( ! empty( $stats['last_run'] ) ? $stats['last_run'] : '暂无' ); ?></div>
                    <div class="yimai-gso-link-stat-label">最近重建</div>
                </div>
            </div>
        </div>

        <div class="yimai-gso-link-rebuild-layout">
            <!-- Main Operation Card -->
            <div class="yimai-gso-link-panel yimai-gso-link-rebuild-main">
                <div class="yimai-gso-link-panel-header">
                    <h2>🔄 批量重建历史文章内链</h2>
                </div>
                <div class="yimai-gso-link-rebuild-desc">
                    <p>为所有已发布文章自动重建智能内链。每篇文章会根据当前候选池和锚文本策略重新计算并插入内链。</p>
                    <div class="yimai-gso-link-rebuild-tips">
                        <span class="yimai-gso-link-tip">⚡ 异步队列处理，不影响页面响应</span>
                        <span class="yimai-gso-link-tip">🎯 按当前策略重新计算</span>
                        <span class="yimai-gso-link-tip">📝 自动跳过已排除的分类/标签</span>
                    </div>
                </div>

                <div class="yimai-gso-link-rebuild-controls" <?php echo $is_disabled ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                    <button type="button" id="yimai-gso-rebuild-links-start" class="yimai-gso-link-btn-primary">
                        <span class="dashicons dashicons-update"></span>
                        开始批量重建
                    </button>
                    <button type="button" id="yimai-gso-rebuild-links-stop" class="yimai-gso-link-btn-danger" style="display:none;">
                        <span class="dashicons dashicons-no"></span>
                        停止重建
                    </button>
                </div>

                <!-- Progress -->
                <div id="yimai-gso-rebuild-links-progress" class="yimai-gso-link-progress-wrap" style="display:none;">
                    <div class="yimai-gso-link-progress-header">
                        <span id="yimai-gso-rebuild-links-progress-text">准备中...</span>
                        <span id="yimai-gso-rebuild-links-progress-percent">0%</span>
                    </div>
                    <div class="yimai-gso-link-progress-bar">
                        <div id="yimai-gso-rebuild-links-fill" class="yimai-gso-link-progress-fill"></div>
                    </div>
                    <div id="yimai-gso-rebuild-links-stats" class="yimai-gso-link-progress-stats"></div>
                </div>

                <!-- Real-time Logs -->
                <div id="yimai-gso-rebuild-links-logs" class="yimai-gso-link-logs" style="display:none;"></div>
            </div>

            <!-- History Table -->
            <div class="yimai-gso-link-panel yimai-gso-link-rebuild-history">
                <div class="yimai-gso-link-panel-header">
                    <h2>📋 重建历史记录</h2>
                </div>
                <div class="yimai-gso-link-empty" id="yimai-gso-rebuild-history-empty">
                    <div class="yimai-gso-link-empty-icon">📭</div>
                    <p>暂无重建记录</p>
                    <span>开始重建后，这里会显示每篇文章的处理结果</span>
                </div>
                <div id="yimai-gso-rebuild-history-list" class="yimai-gso-link-history-list" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * 智能内链 - 失效巡检标签页
     */
    private function render_internal_links_audit_tab( $audit_meta, $parent_locked ) {
        $is_disabled = $parent_locked || ! empty( $audit_meta['disabled'] );

        // 读取上次巡检结果
        $last_result   = get_option( 'yimai_gso_broken_link_audit_result', array() );
        $total_links   = $last_result['total_links'] ?? 0;
        $broken_count  = $last_result['broken_count'] ?? 0;
        $ok_count      = max( 0, $total_links - $broken_count );
        $health_rate   = $total_links > 0 ? round( ( $ok_count / $total_links ) * 100 ) . '%' : '-';
        $has_result    = ! empty( $last_result['audited_at'] );
        ?>
        <!-- Stats Cards -->
        <div class="yimai-gso-link-stats-grid">
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#eef2ff;color:#6366f1;">🔍</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-audit-stat-total"><?php echo $has_result ? esc_html( $total_links ) : '-'; ?></div>
                    <div class="yimai-gso-link-stat-label">站内链接总数</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#d1fae5;color:#10b981;">✅</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-audit-stat-ok"><?php echo $has_result ? esc_html( $ok_count ) : '-'; ?></div>
                    <div class="yimai-gso-link-stat-label">正常链接</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#fee2e2;color:#ef4444;">⚠️</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-audit-stat-broken"><?php echo $has_result ? esc_html( $broken_count ) : '-'; ?></div>
                    <div class="yimai-gso-link-stat-label">失效链接</div>
                </div>
            </div>
            <div class="yimai-gso-link-stat-card">
                <div class="yimai-gso-link-stat-icon" style="background:#fef3c7;color:#f59e0b;">📊</div>
                <div class="yimai-gso-link-stat-info">
                    <div class="yimai-gso-link-stat-num" id="yimai-gso-audit-stat-rate"><?php echo $has_result ? esc_html( $health_rate ) : '-'; ?></div>
                    <div class="yimai-gso-link-stat-label">健康度</div>
                </div>
            </div>
        </div>

        <div class="yimai-gso-link-audit-layout">
            <!-- Operation Area -->
            <div class="yimai-gso-link-panel yimai-gso-link-audit-main">
                <div class="yimai-gso-link-panel-header">
                    <h2>🔍 失效链接巡检</h2>
                </div>
                <div class="yimai-gso-link-audit-desc">
                    <p>扫描全站文章中的站内链接，检测是否存在 404 或其他失效状态，支持一键移除死链。</p>
                </div>

                <div class="yimai-gso-link-audit-config">
                    <div class="yimai-gso-link-audit-config-item">
                        <span class="dashicons dashicons-media-document"></span>
                        <div>
                            <strong>扫描范围</strong>
                            <span>全站已发布文章</span>
                        </div>
                    </div>
                    <div class="yimai-gso-link-audit-config-item">
                        <span class="dashicons dashicons-admin-links"></span>
                        <div>
                            <strong>检测目标</strong>
                            <span>站内链接（本站域名）</span>
                        </div>
                    </div>
                    <div class="yimai-gso-link-audit-config-item">
                        <span class="dashicons dashicons-clock"></span>
                        <div>
                            <strong>超时时间</strong>
                            <span>10 秒/链接</span>
                        </div>
                    </div>
                </div>

                <div class="yimai-gso-link-audit-controls" <?php echo $is_disabled ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                    <button type="button" id="yimai-gso-broken-link-start" class="yimai-gso-link-btn-primary">
                        <span class="dashicons dashicons-search"></span>
                        开始巡检
                    </button>
                </div>

                <!-- Progress -->
                <div id="yimai-gso-broken-link-progress" class="yimai-gso-link-progress-wrap" style="display:none;">
                    <div class="yimai-gso-link-progress-header">
                        <span id="yimai-gso-broken-link-progress-text">正在检测链接...</span>
                        <span id="yimai-gso-broken-link-progress-percent">0%</span>
                    </div>
                    <div class="yimai-gso-link-progress-bar">
                        <div id="yimai-gso-broken-link-fill" class="yimai-gso-link-progress-fill" style="background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div id="yimai-gso-audit-quick-actions" class="yimai-gso-link-audit-actions" <?php echo $has_result ? '' : 'style="display:none;"'; ?>>
                    <button type="button" id="yimai-gso-audit-export" class="yimai-gso-link-btn-secondary">
                        <span class="dashicons dashicons-download"></span>
                        导出报告
                    </button>
                    <button type="button" id="yimai-gso-audit-recheck" class="yimai-gso-link-btn-secondary">
                        <span class="dashicons dashicons-update"></span>
                        重新巡检
                    </button>
                </div>
            </div>

            <!-- Results Panel -->
            <div class="yimai-gso-link-panel yimai-gso-link-audit-results">
                <div class="yimai-gso-link-panel-header">
                    <h2>📋 巡检结果</h2>
                    <span class="yimai-gso-link-badge" id="yimai-gso-audit-result-badge" <?php echo $has_result ? '' : 'style="display:none;"'; ?>><?php echo $broken_count > 0 ? esc_html( $broken_count . ' 个失效' ) : '全部正常'; ?></span>
                </div>

                <div class="yimai-gso-link-empty" id="yimai-gso-audit-empty" <?php echo $has_result ? 'style="display:none;"' : ''; ?>>
                    <div class="yimai-gso-link-empty-icon">🔍</div>
                    <p><?php echo $has_result ? '上次巡检时间：' . esc_html( $last_result['audited_at'] ) : '等待巡检开始'; ?></p>
                    <span><?php echo $has_result ? '点击下方"重新巡检"按钮可再次扫描' : '点击"开始巡检"按钮，系统将自动扫描全站链接'; ?></span>
                </div>

                <div id="yimai-gso-broken-link-result" <?php echo $has_result ? '' : 'style="display:none;"'; ?>></div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: 切换智能内链总开关
     */
    public function ajax_toggle_internal_link() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        if ( ! $this->can_edit_feature( 'smart_internal_links_basic' ) ) {
            wp_send_json_error( array( 'message' => '当前配置不支持此功能' ) );
        }

        $enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;
        update_option( 'yimai_gso_internal_link_enabled', $enabled );

        wp_send_json_success( array(
            'message' => $enabled ? '智能内链已开启' : '智能内链已关闭',
            'enabled' => $enabled,
        ) );
    }

    /**
     * AJAX: 保存 SEO 元数据自动设置
     */
    public function ajax_save_seo_meta_settings() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $enabled     = ! empty( $_POST['auto_seo_meta_enabled'] ) ? 1 : 0;
        $skip_manual = ! empty( $_POST['auto_seo_meta_skip_manual'] ) ? 1 : 0;
        $categories  = sanitize_text_field( wp_unslash( $_POST['auto_seo_meta_categories'] ?? '' ) );

        YIMAI_GSO_Settings::set( 'auto_seo_meta_enabled', $enabled );
        YIMAI_GSO_Settings::set( 'auto_seo_meta_skip_manual', $skip_manual );
        YIMAI_GSO_Settings::set( 'auto_seo_meta_categories', $categories );

        wp_send_json_success( array(
            'message'  => 'SEO元数据自动设置已保存',
            'enabled'  => $enabled,
        ) );
    }

    private function render_autopilot_panel( $active_tab ) {
        $feature_meta = $this->get_feature_meta( 'autopilot_publish' );
        $settings     = class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ? YIMAI_GSO_Autopilot_Plan::get_settings() : array();
        $stats        = class_exists( 'YIMAI_GSO_Autopilot_Log' ) ? YIMAI_GSO_Autopilot_Log::get_summary_stats( 7 ) : array();
        $runs         = class_exists( 'YIMAI_GSO_Autopilot_Log' ) ? YIMAI_GSO_Autopilot_Log::get_recent_runs( 8 ) : array();
        $categories   = $this->get_categories_list('raw');
        $plan_label = '一麦自用版';
        $next_run_label = class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ? YIMAI_GSO_Autopilot_Plan::get_next_run_label( $settings ) : '暂无';
        $today_topic    = class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ? YIMAI_GSO_Autopilot_Plan::pick_topic_keyword( $settings ) : '';
        $root_disabled  = ! empty( $feature_meta['disabled'] );
        $log_counts     = class_exists( 'YIMAI_GSO_Autopilot_Log' ) ? YIMAI_GSO_Autopilot_Log::get_log_counts() : array( 'runs' => 0, 'items' => 0 );
        $is_enabled     = ! empty( $settings['enabled'] );
        ?>
        <div class="yimai-gso-main-panel <?php echo $active_tab === 'autopilot' ? 'active' : ''; ?>" data-tab="autopilot">
            <div id="yimai-gso-autopilot-root"
                data-available="<?php echo ! empty( $feature_meta['available'] ) ? '1' : '0'; ?>"
                data-readonly="<?php echo ! empty( $feature_meta['readonly'] ) ? '1' : '0'; ?>">
                <!-- ====== Hero ====== -->
                <div class="apv3-hero">
                    <div class="apv3-hero-row">
                        <div>
                            <h1 class="apv3-hero-title">永动发布</h1>
                            <div class="apv3-hero-desc">到点自动执行「生成标题 → 生成文章 → 合并标签 → 自动配图 → 发布」</div>
                        </div>
                        <div class="apv3-hero-toggle">
                            <span class="apv3-hero-toggle-label">计划开关</span>
                            <label class="apv3-toggle-track <?php echo $is_enabled ? 'active' : ''; ?><?php echo $root_disabled ? ' disabled' : ''; ?>">
                                <input type="checkbox" name="yimai_gso_autopilot_enabled" value="1" <?php checked( $is_enabled ); ?> <?php disabled( $root_disabled ); ?>>
                            </label>
                        </div>
                    </div>
                    <div class="apv3-hero-stats">
                        <div class="apv3-hero-stat">
                            <span class="apv3-stat-dot <?php echo $is_enabled ? 'green' : 'gray'; ?>"></span>
                            下一次执行
                            <strong><?php echo esc_html( $next_run_label ); ?></strong>
                        </div>
                        <div class="apv3-hero-stat">
                            今日主题
                            <strong><?php echo esc_html( $today_topic ? $today_topic : '未配置' ); ?></strong>
                        </div>
                        <div class="apv3-hero-stat">
                            每日篇数
                            <strong><?php echo esc_html( $settings['daily_count'] ?? 3 ); ?> 篇</strong>
                        </div>
                        <div class="apv3-hero-stat">
                            <span class="apv3-stat-dot <?php echo $is_enabled ? 'green' : 'gray'; ?>"></span>
                            运行状态
                            <strong><?php echo $is_enabled ? '正常' : '未启用'; ?></strong>
                        </div>
                    </div>
                </div>

                <!-- ====== Main Grid ====== -->
                <div class="apv3-grid">

                    <!-- ====== Left: Settings ====== -->
                    <div class="apv3-settings">
                        <form method="post">
                            <?php wp_nonce_field( 'yimai_gso_autopilot_nonce' ); ?>

                            <!-- Section: Schedule -->
                            <div class="apv3-section" data-apv3-collapsible>
                                <div class="apv3-section-header">
                                    <div class="apv3-section-header-left">
                                        <div class="apv3-section-icon apv3-icon-schedule">🕐</div>
                                        <div>
                                            <div class="apv3-section-title">计划调度</div>
                                            <div class="apv3-section-desc">执行时间和频率</div>
                                        </div>
                                    </div>
                                    <span class="apv3-section-chevron">›</span>
                                </div>
                                <div class="apv3-section-body">
                                    <div class="apv3-form-grid">
                                        <div class="apv3-field">
                                            <label>每天执行时间</label>
                                            <input type="time" name="yimai_gso_autopilot_run_time" value="<?php echo esc_attr( $settings['run_time'] ?? '08:00' ); ?>" <?php disabled( $root_disabled ); ?>>
                                            <div class="apv3-hint">按站点时区</div>
                                        </div>
                                        <div class="apv3-field">
                                            <label>每次生成篇数</label>
                                            <input type="number" name="yimai_gso_autopilot_daily_count" min="1" max="10" value="<?php echo esc_attr( $settings['daily_count'] ?? 3 ); ?>" <?php disabled( $root_disabled ); ?>>
                                            <div class="apv3-hint">建议 1-5 篇</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Content Pool -->
                            <div class="apv3-section" data-apv3-collapsible>
                                <div class="apv3-section-header">
                                    <div class="apv3-section-header-left">
                                        <div class="apv3-section-icon apv3-icon-content">🎯</div>
                                        <div>
                                            <div class="apv3-section-title">内容池</div>
                                            <div class="apv3-section-desc">主题词与写作风格</div>
                                        </div>
                                    </div>
                                    <span class="apv3-section-chevron">›</span>
                                </div>
                                <div class="apv3-section-body">
                                    <div class="apv3-form-grid">
                                        <div class="apv3-field apv3-full">
                                            <label>主题词列表</label>
                                            <textarea name="yimai_gso_autopilot_topic_keywords" rows="5" placeholder="每行一个主题词，例如：&#10;AI写作提效&#10;WordPress SEO优化&#10;自媒体变现技巧" <?php disabled( $root_disabled ); ?>><?php echo esc_textarea( $settings['topic_keywords'] ?? '' ); ?></textarea>
                                            <div class="apv3-hint">系统按日期 hash 稳定轮换，避免内容重复</div>
                                        </div>
                                        <div class="apv3-field apv3-full">
                                            <label>补充要求</label>
                                            <textarea name="yimai_gso_autopilot_extra_prompt" rows="3" placeholder="对每篇文章的统一写作要求" <?php disabled( $root_disabled ); ?>><?php echo esc_textarea( $settings['extra_prompt'] ?? '' ); ?></textarea>
                                            <div class="apv3-hint">追加到提示词后，统一风格或口径</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Publish Settings -->
                            <div class="apv3-section" data-apv3-collapsible>
                                <div class="apv3-section-header">
                                    <div class="apv3-section-header-left">
                                        <div class="apv3-section-icon apv3-icon-publish">📤</div>
                                        <div>
                                            <div class="apv3-section-title">发布参数</div>
                                            <div class="apv3-section-desc">分类、字数、配图、标签</div>
                                        </div>
                                    </div>
                                    <span class="apv3-section-chevron">›</span>
                                </div>
                                <div class="apv3-section-body">
                                    <div class="apv3-form-grid">
                                        <div class="apv3-field">
                                            <label>文章分类（兜底）</label>
                                            <select name="yimai_gso_autopilot_category_id" <?php disabled( $root_disabled ); ?>>
                                                <?php foreach ( $categories as $cat ) : ?>
                                                    <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( absint( $settings['category_id'] ?? 1 ), $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-weight:400">
                                                <input type="checkbox" name="yimai_gso_autopilot_auto_category" value="1" <?php checked( ! empty( $settings['auto_category'] ) ); ?> <?php disabled( $root_disabled ); ?>>
                                                按选题自动归类
                                            </label>
                                            <small style="color:#757575;display:block;margin-top:4px">开启后按文章选题自动归入「新手入门 / 体态与理疗 / 产后与孕产 / 普拉提器械 / 瑜伽课程 / 塑形与减脂 / 选馆与办卡 / 门店与预约 / 教培与企业课」；未命中时归入上面的兜底分类。</small>
                                        </div>
                                        <div class="apv3-field">
                                            <label>每篇字数</label>
                                            <input type="number" name="yimai_gso_autopilot_word_count" min="300" max="5000" step="100" value="<?php echo esc_attr( $settings['word_count'] ?? 1000 ); ?>" <?php disabled( $root_disabled ); ?>>
                                        </div>
                                        <div class="apv3-field">
                                            <label>每篇配图</label>
                                            <select name="yimai_gso_autopilot_image_count" <?php disabled( $root_disabled ); ?>>
                                                <?php foreach ( array( 0, 1, 2, 3, 5 ) as $image_count ) : ?>
                                                    <option value="<?php echo esc_attr( $image_count ); ?>" <?php selected( absint( $settings['image_count'] ?? 1 ), $image_count ); ?>><?php echo esc_html( $image_count ); ?> 张</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="apv3-field">
                                            <label>统一标签</label>
                                            <input type="text" name="yimai_gso_autopilot_tags" value="<?php echo esc_attr( $settings['tags'] ?? '' ); ?>" placeholder="标签1,标签2" <?php disabled( $root_disabled ); ?>>
                                        </div>
                                        <div class="apv3-field apv3-full">
                                            <div class="apv3-inline-toggle">
                                                <label class="apv3-mini-toggle <?php echo ! empty( $settings['use_ai_tags'] ) ? 'active' : ''; ?><?php echo $root_disabled ? ' disabled' : ''; ?>">
                                                    <input type="checkbox" name="yimai_gso_autopilot_use_ai_tags" value="1" <?php checked( ! empty( $settings['use_ai_tags'] ) ); ?> <?php disabled( $root_disabled ); ?>>
                                                </label>
                                                <span>AI 自动标签</span>
                                            </div>
                                            <div class="apv3-hint" style="margin-top:4px;">未启用时自动降级为统一标签</div>
                                        </div>
                                        <div class="apv3-field apv3-full">
                                            <label>写入方式</label>
                                            <div class="apv3-radio-pills">
                                                <label class="apv3-radio-pill <?php echo ( $settings['post_status'] ?? 'publish' ) === 'publish' ? 'active' : ''; ?>">
                                                    <input type="radio" name="yimai_gso_autopilot_post_status" value="publish" <?php checked( ( $settings['post_status'] ?? 'publish' ) === 'publish' ); ?> <?php disabled( $root_disabled ); ?>>
                                                    <span>🚀 直接发布</span>
                                                </label>
                                                <label class="apv3-radio-pill <?php echo ( $settings['post_status'] ?? 'publish' ) === 'draft' ? 'active' : ''; ?>">
                                                    <input type="radio" name="yimai_gso_autopilot_post_status" value="draft" <?php checked( ( $settings['post_status'] ?? 'publish' ) === 'draft' ); ?> <?php disabled( $root_disabled ); ?>>
                                                    <span>📝 存为草稿</span>
                                                </label>
                                                <label class="apv3-radio-pill <?php echo ( $settings['post_status'] ?? 'publish' ) === 'future' ? 'active' : ''; ?>">
                                                    <input type="radio" name="yimai_gso_autopilot_post_status" value="future" <?php checked( ( $settings['post_status'] ?? 'publish' ) === 'future' ); ?> <?php disabled( $root_disabled ); ?>>
                                                    <span>⏰ 定时发布</span>
                                                </label>
                                            </div>
                                        </div>
                                        <?php
                                        $future_delay = absint( $settings['future_delay_hours'] ?? 2 );
                                        $future_delay = max( 1, min( 3, $future_delay ) );
                                        $future_display = ( $settings['post_status'] ?? 'publish' ) === 'future' ? '' : 'style="display:none;"';
                                        ?>
                                        <div class="apv3-field apv3-full" id="apv3-future-delay-row" <?php echo $future_display; ?>>
                                            <label>延迟发布</label>
                                            <select name="yimai_gso_autopilot_future_delay_hours" class="apv3-select" style="width:200px;" <?php disabled( $root_disabled ); ?>>
                                                <option value="1" <?php selected( $future_delay, 1 ); ?>>1 小时后</option>
                                                <option value="2" <?php selected( $future_delay, 2 ); ?>>2 小时后</option>
                                                <option value="3" <?php selected( $future_delay, 3 ); ?>>3 小时后</option>
                                            </select>
                                            <div class="apv3-hint" style="margin-top:4px;">生成后延迟指定小时发布，配合全局发布时间策略实现自然分散</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: 分类体系 -->
                            <div class="apv3-section" data-apv3-collapsible>
                                <div class="apv3-section-header">
                                    <div class="apv3-section-header-left">
                                        <div class="apv3-section-icon apv3-icon-publish">🗂</div>
                                        <div class="apv3-section-title-wrap">
                                            <div class="apv3-section-title">分类体系</div>
                                            <div class="apv3-section-desc">标准分类初始化与旧分类整理</div>
                                        </div>
                                    </div>
                                    <span class="apv3-section-chevron">›</span>
                                </div>
                                <div class="apv3-section-body">
                                    <div class="apv3-field apv3-full">
                                        <div class="apv3-hint" style="margin-top:0">
                                            标准分类共 9 个：新手入门 / 体态与理疗 / 产后与孕产 / 普拉提器械 / 瑜伽课程 / 塑形与减脂 / 选馆与办卡 / 门店与预约 / 教培与企业课。
                                            已存在的同名分类会直接复用，不会重复创建。
                                        </div>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                                            <button type="button" class="button button-primary" id="yimai-apv3-init-cats">初始化标准分类</button>
                                            <button type="button" class="button" id="yimai-apv3-migrate-cats" title="把英文演示分类（Body &amp; Soul / Friends / Home / Nature）里的文章按标题自动归入标准分类">整理旧分类文章</button>
                                        </div>
                                        <div id="yimai-apv3-cat-msg" style="margin-top:10px;font-size:13px"></div>
                                        <div id="yimai-apv3-cat-list" style="margin-top:12px"></div>
                                    </div>
                                </div>
                            </div>
                            <script>
                            jQuery(function($){
                                function apv3RenderCats(list){
                                    if(!list || !list.length){ return; }
                                    var html = '<table class="widefat striped" style="font-size:12px"><thead><tr><th>分类</th><th>slug</th><th>文章数</th><th>状态</th></tr></thead><tbody>';
                                    list.forEach(function(c){
                                        var st = c.standard ? '<span style="color:#2271b1">标准</span>'
                                               : (c.demo ? '<span style="color:#b32d2e">演示分类，待整理</span>'
                                                         : '<span style="color:#757575">自定义</span>');
                                        html += '<tr><td>' + c.name + '</td><td><code>' + c.slug + '</code></td><td>' + c.count + '</td><td>' + st + '</td></tr>';
                                    });
                                    html += '</tbody></table>';
                                    $('#yimai-apv3-cat-list').html(html);
                                }
                                apv3RenderCats(<?php echo wp_json_encode( $this->get_category_overview() ); ?>);

                                $('#yimai-apv3-init-cats').on('click', function(){
                                    var $b = $(this).prop('disabled', true).text('初始化中…');
                                    $('#yimai-apv3-cat-msg').text('');
                                    $.post(ajaxurl, { action:'yimai_gso_init_standard_categories', nonce: yimaiGsoData.nonce }, function(res){
                                        $b.prop('disabled', false).text('初始化标准分类');
                                        if(res.success){
                                            $('#yimai-apv3-cat-msg').css('color','#1d2327').text(res.data.message);
                                            apv3RenderCats(res.data.list);
                                        } else {
                                            $('#yimai-apv3-cat-msg').css('color','#b32d2e').text(res.data && res.data.message ? res.data.message : '初始化失败');
                                        }
                                    }).fail(function(){
                                        $b.prop('disabled', false).text('初始化标准分类');
                                        $('#yimai-apv3-cat-msg').css('color','#b32d2e').text('请求失败，请重试');
                                    });
                                });

                                $('#yimai-apv3-migrate-cats').on('click', function(){
                                    if(!window.confirm('将把 English 演示分类（Body & Soul / Friends / Home / Nature）里的文章按标题归入标准分类，只调整文章归属、不删除分类本身。继续？')){ return; }
                                    var $b = $(this).prop('disabled', true).text('整理中…');
                                    $.post(ajaxurl, { action:'yimai_gso_migrate_demo_categories', nonce: yimaiGsoData.nonce }, function(res){
                                        $b.prop('disabled', false).text('整理旧分类文章');
                                        if(res.success){
                                            var msg = res.data.message;
                                            if(res.data.details && res.data.details.length){
                                                msg += '<br><small>' + res.data.details.join('<br>') + '</small>';
                                            }
                                            $('#yimai-apv3-cat-msg').css('color','#1d2327').html(msg);
                                            apv3RenderCats(res.data.list);
                                        } else {
                                            $('#yimai-apv3-cat-msg').css('color','#b32d2e').text(res.data && res.data.message ? res.data.message : '整理失败');
                                        }
                                    }).fail(function(){
                                        $b.prop('disabled', false).text('整理旧分类文章');
                                        $('#yimai-apv3-cat-msg').css('color','#b32d2e').text('请求失败，请重试');
                                    });
                                });
                            });
                            </script>

                            <!-- Section: System -->
                            <div class="apv3-section" data-apv3-collapsible>
                                <div class="apv3-section-header">
                                    <div class="apv3-section-header-left">
                                        <div class="apv3-section-icon apv3-icon-system">⚙️</div>
                                        <div>
                                            <div class="apv3-section-title">系统设置</div>
                                            <div class="apv3-section-desc">日志与维护</div>
                                        </div>
                                    </div>
                                    <span class="apv3-section-chevron">›</span>
                                </div>
                                <div class="apv3-section-body">
                                    <div class="apv3-form-grid">
                                        <div class="apv3-field">
                                            <label>日志保留天数</label>
                                            <div class="apv3-retention-row">
                                                <input type="number" name="yimai_gso_autopilot_log_retention_days" min="7" max="365" value="<?php echo esc_attr( $settings['log_retention_days'] ?? 30 ); ?>" <?php disabled( $root_disabled ); ?>>
                                                <span class="apv3-retention-unit">天</span>
                                            </div>
                                            <div class="apv3-hint">每日凌晨 3:00 自动清理过期日志</div>
                                        </div>
                                    </div>
                                    <div class="apv3-info-cards">
                                        <div class="apv3-info-card">
                                            <strong>降级策略：</strong>配图失败则继续发文；AI 标签无权限则只保留统一标签。
                                        </div>
                                        <div class="apv3-info-card">
                                            <strong>冲突保护：</strong>永动发布运行中会锁定生成链路，避免与手动批量操作冲突。
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="apv3-settings-footer">
                                <div class="apv3-footer-left">
                                    <span class="apv3-footer-stat">运行记录 <?php echo esc_html( $log_counts['runs'] ?? 0 ); ?> 条 · 子任务 <?php echo esc_html( $log_counts['items'] ?? 0 ); ?> 条</span>
                                </div>
                                <div class="apv3-footer-right">
                                    <button type="button" id="yimai-gso-cleanup-logs-btn" class="apv3-btn apv3-btn-ghost">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                                        清理日志
                                    </button>
                                    <button type="button" id="yimai-gso-autopilot-run-btn" class="apv3-btn apv3-btn-secondary" <?php echo ( empty( $feature_meta['available'] ) || ! empty( $feature_meta['readonly'] ) ) ? 'disabled' : ''; ?>>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                        立即执行
                                    </button>
                                    <button type="submit" name="yimai_gso_save_autopilot_settings" class="apv3-btn apv3-btn-primary" <?php disabled( $root_disabled ); ?>>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                        保存设置
                                    </button>
                                </div>
                            </div>
                            <div id="yimai-gso-autopilot-run-result" style="display:none;"></div>
                            <div id="yimai-gso-cleanup-result" style="display:none;"></div>
                        </form>
                    </div>

                    <!-- ====== Right: Log ====== -->
                    <div class="apv3-log">
                        <div class="apv3-log-header">
                            <h2>运行日志</h2>
                            <div class="apv3-log-header-actions">
                                <button type="button" class="apv3-btn apv3-btn-ghost apv3-btn-sm" onclick="location.reload()">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                                    刷新
                                </button>
                            </div>
                        </div>

                        <!-- Mini stat bar -->
                        <div class="apv3-log-stats">
                            <div class="apv3-log-stat-cell apv3-stat-blue">
                                <div class="apv3-log-stat-num"><?php echo esc_html( $stats['total_runs'] ?? 0 ); ?></div>
                                <div class="apv3-log-stat-label">近 7 天运行</div>
                            </div>
                            <div class="apv3-log-stat-cell apv3-stat-green">
                                <div class="apv3-log-stat-num"><?php echo esc_html( $stats['success_items'] ?? 0 ); ?></div>
                                <div class="apv3-log-stat-label">成功文章</div>
                            </div>
                            <div class="apv3-log-stat-cell apv3-stat-amber">
                                <div class="apv3-log-stat-num"><?php echo esc_html( $stats['published_items'] ?? 0 ); ?></div>
                                <div class="apv3-log-stat-label">直接发布</div>
                            </div>
                            <div class="apv3-log-stat-cell apv3-stat-red">
                                <div class="apv3-log-stat-num"><?php echo esc_html( $stats['failed_items'] ?? 0 ); ?></div>
                                <div class="apv3-log-stat-label">失败</div>
                            </div>
                        </div>

                        <div class="apv3-log-toolbar">
                            <span class="apv3-log-count">共 <strong><?php echo count( $runs ); ?></strong> 条记录</span>
                        </div>

                        <div class="apv3-log-list">
                            <?php if ( empty( $runs ) ) : ?>
                                <div class="apv3-empty-state">
                                    <div class="apv3-empty-icon">♻️</div>
                                    <h3>还没有运行记录</h3>
                                    <p>保存计划后可等到点自动触发，也可以点击「立即执行」手动验证整条链路。</p>
                                </div>
                            <?php else : ?>
                                <?php foreach ( $runs as $run ) :
                                    $run_status  = $run['status'] ?? 'pending';
                                    $is_completed = in_array( $run_status, array( 'completed' ), true );
                                    $is_running  = in_array( $run_status, array( 'running', 'pending' ), true );
                                    $_xBR   = in_array( $run_status, array( 'failed', 'aborted' ), true );
                                    $all_ok      = $is_completed && ( ( $run['success_count'] ?? 0 ) >= ( $run['daily_count'] ?? 0 ) );
                                    $badge_class = $all_ok ? 'success' : ( $_xBR ? 'fail' : 'running' );
                                ?>
                                    <div class="apv3-log-card" data-apv3-log-card>
                                        <div class="apv3-log-card-head">
                                            <span class="apv3-log-status-dot <?php echo $is_completed ? 'completed' : ( $is_running ? 'running' : 'failed' ); ?>"></span>
                                            <div class="apv3-log-card-info">
                                                <div class="apv3-log-card-topic"><?php echo esc_html( $run['topic_keyword'] ? $run['topic_keyword'] : '未命名主题' ); ?></div>
                                                <div class="apv3-log-card-meta">
                                                    <span><?php echo esc_html( $run['trigger_label'] ?? '' ); ?></span>
                                                    <span class="apv3-meta-sep">·</span>
                                                    <span><?php echo esc_html( $run['status_label'] ?? '' ); ?></span>
                                                    <span class="apv3-meta-sep">·</span>
                                                    <span><?php echo esc_html( $run['finished_at_label'] ?? $run['created_at_label'] ?? '' ); ?></span>
                                                </div>
                                            </div>
                                            <span class="apv3-log-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $run['success_count'] ?? 0 ); ?>/<?php echo esc_html( $run['daily_count'] ?? 0 ); ?></span>
                                        </div>
                                        <?php if ( ! empty( $run['items'] ) ) : ?>
                                            <div class="apv3-log-card-items">
                                                <?php foreach ( $run['items'] as $item ) :
                                                    $item_status   = $item['status'] ?? 'pending';
                                                    $is_published  = in_array( $item_status, array( 'published' ), true );
                                                    $is_draft      = in_array( $item_status, array( 'draft' ), true );
                                                    $is_item_fail  = in_array( $item_status, array( 'article_failed', 'publish_failed', 'failed' ), true );
                                                ?>
                                                    <div class="apv3-log-item">
                                                        <span class="apv3-log-item-num">#<?php echo esc_html( $item['item_index'] ?? 0 ); ?></span>
                                                        <span class="apv3-log-item-title"><?php echo esc_html( $item['title'] ?? '未命名文章' ); ?></span>
                                                        <span class="apv3-log-item-status <?php echo $is_published ? 'published' : ( $is_draft ? 'draft' : 'failed' ); ?>"><?php echo esc_html( $item['status_label'] ?? '' ); ?></span>
                                                        <div class="apv3-log-item-actions">
                                                            <?php if ( ! empty( $item['edit_url'] ) ) : ?>
                                                                <a href="<?php echo esc_url( $item['edit_url'] ); ?>" target="_blank">编辑</a>
                                                            <?php endif; ?>
                                                            <?php if ( ! empty( $item['view_url'] ) ) : ?>
                                                                <a href="<?php echo esc_url( $item['view_url'] ); ?>" target="_blank">查看</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ( ! empty( $item['error_message'] ) ) : ?>
                                                        <div class="apv3-log-error"><?php echo esc_html( $item['error_message'] ); ?></div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ( ! empty( $run['last_message'] ) || ! empty( $run['last_error'] ) ) : ?>
                                            <div class="apv3-log-summary"><?php echo esc_html( $run['last_message'] ?: ( $run['last_error'] ?? '' ) ); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    private function render_seo_meta_panel( $active_tab ) {
        $feature_meta = $this->get_feature_meta( 'seo_enhance' );
        $stats        = class_exists( 'YIMAI_GSO_SEO_Meta_Optimizer' ) ? YIMAI_GSO_SEO_Meta_Optimizer::get_summary_stats() : array();
        $logs         = class_exists( 'YIMAI_GSO_SEO_Meta_Optimizer' ) ? YIMAI_GSO_SEO_Meta_Optimizer::get_recent_logs( 15 ) : array();
        ?>

        <div class="yimai-gso-main-panel <?php echo $active_tab === 'seo-meta' ? 'active' : ''; ?>" data-tab="seo-meta">
            <div id="yimai-gso-seo-meta-root"
                data-available="<?php echo ! empty( $feature_meta['available'] ) ? '1' : '0'; ?>"
                data-readonly="<?php echo ! empty( $feature_meta['readonly'] ) ? '1' : '0'; ?>">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-chart-line"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">批量SEO元数据</h2>
                        <p class="yimai-gso-card-desc">批量优化文章标题、描述和关键词，兼容子比主题、Yoast SEO、Rank Math 等主流 SEO 插件</p>
                    </div>
                </div>
                <div class="yimai-gso-result-box success" id="yimai-gso-seo-status-box" style="margin-bottom:18px;">
                    执行链路：先扫描候选文章 → AI 逐篇生成 SEO 标题 / 描述 / 关键词预览 → 人工确认后批量写回子比 / Yoast / Rank Math。
                </div>

                <div class="yimai-gso-detect-tips" style="margin-bottom:18px;display:flex;flex-wrap:wrap;gap:8px;">
                    <span class="yimai-gso-detect-tip">最近写回：<?php echo esc_html( $stats['total'] ?? 0 ); ?> 次</span>
                    <span class="yimai-gso-detect-tip">成功：<?php echo esc_html( $stats['success'] ?? 0 ); ?> 次</span>
                    <span class="yimai-gso-detect-tip">失败：<?php echo esc_html( $stats['error'] ?? 0 ); ?> 次</span>
                    <span class="yimai-gso-detect-tip">最近执行：<?php echo esc_html( $stats['last_run'] ?? '暂无' ); ?></span>
                </div>

                <!-- 发布后自动SEO元数据 设置 -->
                <?php
                $auto_seo_enabled     = class_exists( 'YIMAI_GSO_Settings' ) ? YIMAI_GSO_Settings::get( 'auto_seo_meta_enabled', 0 ) : 0;
                $auto_seo_skip_manual = class_exists( 'YIMAI_GSO_Settings' ) ? YIMAI_GSO_Settings::get( 'auto_seo_meta_skip_manual', 1 ) : 1;
                $auto_seo_cats        = class_exists( 'YIMAI_GSO_Settings' ) ? YIMAI_GSO_Settings::get( 'auto_seo_meta_categories', '' ) : '';
                $selected_cat_ids     = array_filter( array_map( 'absint', explode( ',', $auto_seo_cats ) ) );
                $all_categories       = $this->get_categories_list( 'select' );
                ?>
                <div class="yimai-gso-panel yimai-gso-seo-auto-section" style="margin-bottom:18px; padding:16px;">
                    <h2 style="margin-top:0;">发布后自动优化</h2>
                    <p style="color:#757575;margin-bottom:14px;">开启后，文章首次发布时自动调用 AI 生成 SEO 标题、描述和关键词，60秒后后台静默写入，不阻塞发布流程。</p>

                    <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;">
                        <!-- 主开关 -->
                        <div class="yimai-gso-form-group" style="min-width:280px;">
                            <label class="yimai-gso-toggle-label" style="font-weight:600;">
                                <input type="checkbox" id="yimai-gso-auto-seo-enabled" <?php checked( $auto_seo_enabled, 1 ); ?>>
                                <span class="yimai-gso-toggle-switch"></span>
                                <span class="yimai-gso-toggle-text">发布后自动优化SEO元数据</span>
                            </label>
                            <small style="display:block;margin-top:6px;color:#757575;">仅完整版可用，基础版开启后无效果</small>
                        </div>

                        <!-- 增强A：跳过手动SEO -->
                        <div class="yimai-gso-form-group" style="min-width:280px;">
                            <label class="yimai-gso-toggle-label">
                                <input type="checkbox" id="yimai-gso-auto-seo-skip-manual" <?php checked( $auto_seo_skip_manual, 1 ); ?>>
                                <span class="yimai-gso-toggle-switch"></span>
                                <span class="yimai-gso-toggle-text">跳过已有手动SEO的文章</span>
                            </label>
                            <small style="display:block;margin-top:6px;color:#757575;">如果文章已在 Yoast / Rank Math / 子比中手动设置了 SEO 字段，则不自动覆盖</small>
                        </div>

                        <!-- 增强B：分类白名单 -->
                        <div class="yimai-gso-form-group" style="flex:1;min-width:340px;">
                            <label style="font-weight:600;">限制分类（可选）</label>
                            <div id="yimai-gso-auto-seo-cats" style="
                                display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;
                                max-height:120px;overflow-y:auto;
                                border:1px solid #dcdcde;border-radius:4px;padding:8px;
                                background:#fff;
                            ">
                                <?php if ( ! empty( $all_categories ) ) : ?>
                                    <?php foreach ( $all_categories as $cat ) : ?>
                                        <label style="
                                            display:inline-flex;align-items:center;gap:4px;
                                            padding:3px 10px;border-radius:20px;cursor:pointer;font-size:13px;
                                            background:<?php echo in_array( $cat['id'], $selected_cat_ids ) ? '#6c5ce7' : '#f0f0f1'; ?>;
                                            color:<?php echo in_array( $cat['id'], $selected_cat_ids ) ? '#fff' : '#333'; ?>;
                                            transition:all 0.2s;
                                        ">
                                            <input type="checkbox" value="<?php echo esc_attr( $cat['id'] ); ?>"
                                                <?php checked( in_array( $cat['id'], $selected_cat_ids ) ); ?>
                                                style="display:none;">
                                            <?php echo esc_html( $cat['name'] ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span style="color:#757575;padding:8px;">暂无分类，请先创建文章分类</span>
                                <?php endif; ?>
                            </div>
                            <small style="display:block;margin-top:6px;color:#757575;">不选则全部生效；选中后仅所选分类下的文章自动优化</small>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <button type="button" id="yimai-gso-auto-seo-save-btn" class="button button-primary yimai-gso-icon-btn">
                            <span class="dashicons dashicons-yes-alt"></span>
                            保存自动设置
                        </button>
                        <span id="yimai-gso-auto-seo-save-status" style="margin-left:10px;display:none;color:#46b450;"></span>
                    </div>
                </div>

                <!-- 扫描候选文章（整宽）-->
                <div class="yimai-gso-panel yimai-gso-seo-scan-section" style="margin-bottom:18px;">
                    <h2>扫描候选文章</h2>

                    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
                        <div class="yimai-gso-form-group" style="flex:1;min-width:200px;">
                            <label>标题关键词</label>
                            <input type="text" id="yimai-gso-seo-keyword" class="large-text" placeholder="例如：AI写作、WordPress、SEO优化">
                            <small>留空则按最新文章扫描；填写后会按标题关键词筛选。</small>
                        </div>

                        <div class="yimai-gso-form-group" style="width:150px;">
                            <label>文章状态</label>
                            <select id="yimai-gso-seo-post-status">
                                <option value="publish" selected>已发布</option>
                                <option value="draft">草稿</option>
                                <option value="future">定时发布</option>
                                <option value="pending">待审核</option>
                                <option value="all">全部状态</option>
                            </select>
                        </div>

                        <div class="yimai-gso-form-group" style="width:150px;">
                            <label>扫描数量</label>
                            <select id="yimai-gso-seo-limit">
                                <option value="10">最近 10 篇</option>
                                <option value="20" selected>最近 20 篇</option>
                                <option value="30">最近 30 篇</option>
                                <option value="50">最近 50 篇</option>
                                <option value="100">最近 100 篇</option>
                            </select>
                        </div>

                        <div class="yimai-gso-form-group" style="width:180px;">
                            <label class="yimai-gso-toggle-label">
                                <input type="checkbox" id="yimai-gso-seo-only-missing" checked>
                                <span class="yimai-gso-toggle-switch"></span>
                                <span class="yimai-gso-toggle-text">只扫描缺少SEO字段</span>
                            </label>
                        </div>

                        <div class="yimai-gso-form-group" style="width:auto;align-self:flex-end;margin-bottom:0;">
                            <button id="yimai-gso-seo-scan-btn" class="button button-primary yimai-gso-icon-btn" <?php echo empty( $feature_meta['available'] ) ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-search"></span>
                                扫描候选文章
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 双栏：候选文章 | AI预览与确认 -->
                <div class="yimai-gso-layout" style="display:flex;gap:18px;margin-bottom:18px;">
                    <div class="yimai-gso-panel" style="flex:1;">
                        <div class="yimai-gso-titles-toolbar">
                            <h2>候选文章 <span id="yimai-gso-seo-scan-count" class="yimai-gso-batch-counter" style="display:none;">0</span></h2>
                            <div class="yimai-gso-titles-actions">
                                <button id="yimai-gso-seo-preview-btn" class="button button-primary" <?php echo empty( $feature_meta['available'] ) ? 'disabled' : ''; ?>>
                                    <span class="dashicons dashicons-superhero"></span>
                                    生成AI预览
                                </button>
                                <button id="yimai-gso-seo-select-all" class="button button-small">全选</button>
                                <button id="yimai-gso-seo-clear-all" class="button button-small">取消</button>
                            </div>
                        </div>
                        <div id="yimai-gso-seo-scan-results" style="max-height:400px;overflow-y:auto;">
                            <div class="yimai-gso-empty">
                                <p>点击上方"扫描候选文章"，系统会把匹配到的文章列在这里。</p>
                            </div>
                        </div>
                    </div>

                    <div class="yimai-gso-panel" style="flex:1;">
                        <div class="yimai-gso-titles-toolbar">
                            <h2>AI预览与确认 <span id="yimai-gso-seo-preview-count" class="yimai-gso-batch-counter" style="display:none;">0</span></h2>
                            <div class="yimai-gso-titles-actions">
                                <button id="yimai-gso-seo-apply-btn" class="button button-primary" <?php echo ( empty( $feature_meta['available'] ) || ! empty( $feature_meta['readonly'] ) ) ? 'disabled' : ''; ?>>
                                    <span class="dashicons dashicons-saved"></span>
                                    确认批量写回
                                </button>
                                <button id="yimai-gso-seo-preview-select-all" class="button button-small">全选写回</button>
                                <button id="yimai-gso-seo-preview-clear-all" class="button button-small">取消写回</button>
                            </div>
                        </div>
                        <div id="yimai-gso-seo-preview-results" style="max-height:400px;overflow-y:auto;">
                            <div class="yimai-gso-empty">
                                <p>勾选候选文章后点击"生成AI预览"，这里会逐篇展示建议。</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="yimai-gso-seo-column-note" style="margin-bottom:18px;">
                    <div>• 支持字段：子比关键词 / 描述、Yoast 标题 / 描述 / 关键词、Rank Math 标题 / 描述 / 关键词</div>
                    <div>• 执行策略：前端串行处理，单篇失败不会拖垮整批 | • 日志策略：仅保留最近 100 条写回记录</div>
                    <?php if ( ! empty( $feature_meta['readonly'] ) ) : ?>
                        <div style="color:#b45309;">• 当前当前为只读态：可看扫描与预览结果，但"确认批量写回"会被锁定</div>
                    <?php endif; ?>
                </div>

                <div class="yimai-gso-panel yimai-gso-seo-log-panel">
                    <div class="yimai-gso-titles-toolbar">
                        <h2>📝 最近写回日志</h2>
                    </div>

                    <?php if ( empty( $logs ) ) : ?>
                        <div class="yimai-gso-empty"><p>还没有批量SEO写回记录。完成首次写回后，这里会显示最近结果。</p></div>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:170px;">时间</th>
                                    <th>文章</th>
                                    <th style="width:90px;">状态</th>
                                    <th>结果</th>
                                    <th style="width:120px;">操作人</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $log['time'] ?? '' ); ?></td>
                                        <td>
                                            <strong><?php echo esc_html( $log['post_title'] ?? '未命名文章' ); ?></strong>
                                            <?php if ( ! empty( $log['post_id'] ) ) : ?>
                                                <div style="margin-top:6px;"><a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $log['post_id'] ) . '&action=edit' ) ); ?>" target="_blank">编辑文章</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( ( $log['status'] ?? 'success' ) === 'success' ? '成功' : '失败' ); ?></td>
                                        <td><?php echo esc_html( $log['message'] ?? '' ); ?></td>
                                        <td><?php echo esc_html( $log['operator'] ?? '-' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_detection_panel( $active_tab ) {


        $feature_meta = $this->get_feature_meta( 'content_check' );
        $enabled      = class_exists( 'YIMAI_GSO_Content_Detector' ) ? YIMAI_GSO_Content_Detector::is_enabled() : (bool) get_option( 'yimai_gso_detection_enabled', 0 );
        $stats        = class_exists( 'YIMAI_GSO_Content_Detector' ) ? YIMAI_GSO_Content_Detector::get_summary_stats() : array();
        $logs         = class_exists( 'YIMAI_GSO_Content_Detector' ) ? YIMAI_GSO_Content_Detector::get_recent_logs( 15 ) : array();
        $rules        = class_exists( 'YIMAI_GSO_Content_Detector' ) ? YIMAI_GSO_Content_Detector::get_rules() : array();
        $rule_desc_map = array(
            'forbidden'   => '重点清理违法、违禁与高风险违规表述。',
            'superlative' => '自动弱化"最佳、第一、100%"等极限承诺。',
            'sensitive'   => '自动调整敏感增长、灰黑产与夸张收益表述。',
            'ads_law'     => '自动修正疗效、安全、退款等广告法高风险承诺。',
        );
        ?>
        <!-- 图片库面板 -->
        <div class="yimai-gso-main-panel <?php echo $active_tab === 'image-library' ? 'active' : ''; ?>" data-tab="image-library">
            <div id="yimai-gso-image-library-root">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-format-gallery"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">图片库</h2>
                        <p class="yimai-gso-card-desc">管理自定义配图素材，支持分组、权重优先级和使用次数统计</p>
                    </div>
                </div>

                <!-- 统计卡片 -->
                <div class="yimai-gso-ilib-stats-row">
                    <div class="yimai-gso-ilib-stat-card">
                        <div class="yimai-gso-ilib-stat-icon">🖼️</div>
                        <div class="yimai-gso-ilib-stat-info">
                            <div class="yimai-gso-ilib-stat-value" id="ilib-stat-total">0</div>
                            <div class="yimai-gso-ilib-stat-label">图片总数</div>
                        </div>
                    </div>
                    <div class="yimai-gso-ilib-stat-card">
                        <div class="yimai-gso-ilib-stat-icon">📂</div>
                        <div class="yimai-gso-ilib-stat-info">
                            <div class="yimai-gso-ilib-stat-value" id="ilib-stat-groups">0</div>
                            <div class="yimai-gso-ilib-stat-label">分组数</div>
                        </div>
                    </div>
                    <div class="yimai-gso-ilib-stat-card">
                        <div class="yimai-gso-ilib-stat-icon">🔄</div>
                        <div class="yimai-gso-ilib-stat-info">
                            <div class="yimai-gso-ilib-stat-value" id="ilib-stat-used">0</div>
                            <div class="yimai-gso-ilib-stat-label">累计使用</div>
                        </div>
                    </div>
                </div>

                <!-- 工具栏 -->
                <div class="yimai-gso-ilib-toolbar">
                    <div class="yimai-gso-ilib-toolbar-left">
                        <button type="button" class="yimai-gso-btn-modern-primary" id="ilib-upload-btn">
                            <span class="dashicons dashicons-upload" style="vertical-align:middle;margin-top:-2px;"></span>
                            上传图片
                        </button>
                        <button type="button" class="yimai-gso-btn-modern-secondary" id="ilib-new-group-btn">
                            <span class="dashicons dashicons-plus-alt" style="vertical-align:middle;margin-top:-2px;"></span>
                            新建分组
                        </button>
                    </div>
                    <div class="yimai-gso-ilib-toolbar-right">
                        <input type="text" id="ilib-search" class="regular-text" placeholder="搜索图片..." style="width:160px;">
                        <select id="ilib-filter-group" style="width:130px;">
                            <option value="0">全部分组</option>
                        </select>
                        <select id="ilib-orderby" style="width:120px;">
                            <option value="date">最新上传</option>
                            <option value="weight">权重优先</option>
                            <option value="used">使用最多</option>
                            <option value="last_used">最近使用</option>
                        </select>
                    </div>
                </div>

                <!-- 批量操作栏 -->
                <div class="yimai-gso-ilib-batch-bar" id="ilib-batch-bar" style="display:none;">
                    <span id="ilib-batch-count">已选 0 张</span>
                    <div class="yimai-gso-ilib-batch-actions">
                        <button type="button" class="button button-small" id="ilib-batch-keywords">设关键词</button>
                        <button type="button" class="button button-small" id="ilib-batch-weight">设权重</button>
                        <button type="button" class="button button-small" id="ilib-batch-group">设分组</button>
                        <button type="button" class="button button-small button-link-delete" id="ilib-batch-delete">删除</button>
                    </div>
                    <button type="button" class="button button-small" id="ilib-batch-cancel">取消选择</button>
                </div>

                <!-- 图片网格 -->
                <div class="yimai-gso-ilib-grid" id="ilib-grid">
                    <!-- 动态渲染 -->
                </div>

                <!-- 分页 -->
                <div class="yimai-gso-ilib-pagination" id="ilib-pagination"></div>

                <!-- 分组管理（折叠） -->
                <div class="yimai-gso-ilib-groups-section">
                    <button type="button" class="yimai-gso-ilib-groups-toggle" id="ilib-groups-toggle">
                        <span class="dashicons dashicons-category"></span>
                        分组管理
                        <span class="dashicons dashicons-arrow-down-alt2" id="ilib-groups-arrow"></span>
                    </button>
                    <div class="yimai-gso-ilib-groups-panel" id="ilib-groups-panel" style="display:none;">
                        <div id="ilib-groups-list">
                            <!-- 动态渲染 -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /图片库面板 -->

        <div class="yimai-gso-main-panel <?php echo $active_tab === 'detection' ? 'active' : ''; ?>" data-tab="detection">
            <div class="yimai-gso-page-card-header">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-shield-alt"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">内容检测</h2>
                    <p class="yimai-gso-card-desc">检测文章重复率、AI痕迹和敏感词，保障内容质量安全</p>
                </div>
            </div>
            <div class="yimai-gso-history-header" style="display:none">
                <h1 class="yimai-gso-page-title">
                    <span class="dashicons dashicons-shield-alt"></span>
                    内容检测
                </h1>
            </div>
            <div class="yimai-gso-panel yimai-gso-detection-hero">
                <div>
                    <h2 class="yimai-gso-section-title" style="margin-bottom:10px;">🛡️ 发布后自动巡检 + 自动修正</h2>
                    <p class="yimai-gso-detection-hero-desc">只要文章真正进入"已发布"状态，系统就会自动检测禁用词、极限词、敏感词和广告法风险词；先做词级替换，再按需调用 AI 轻改写，让正文尽量保持自然。</p>
                </div>
                <div class="yimai-gso-detection-mode">当前策略：混合模式</div>
            </div>

            <div class="yimai-gso-dashboard-stats">
                <div class="yimai-gso-stat-card yimai-gso-stat-primary">
                    <div class="yimai-gso-stat-icon">📊</div>
                    <div class="yimai-gso-stat-content">
                        <div class="yimai-gso-stat-num"><?php echo esc_html( $stats['total'] ?? 0 ); ?></div>
                        <div class="yimai-gso-stat-label">最近检测次数</div>
                    </div>
                </div>
                <div class="yimai-gso-stat-card yimai-gso-stat-success">
                    <div class="yimai-gso-stat-icon">✨</div>
                    <div class="yimai-gso-stat-content">
                        <div class="yimai-gso-stat-num"><?php echo esc_html( $stats['fixed'] ?? 0 ); ?></div>
                        <div class="yimai-gso-stat-label">自动修正完成</div>
                    </div>
                </div>
                <div class="yimai-gso-stat-card yimai-gso-stat-warning">
                    <div class="yimai-gso-stat-icon">⚠️</div>
                    <div class="yimai-gso-stat-content">
                        <div class="yimai-gso-stat-num"><?php echo esc_html( $stats['warning'] ?? 0 ); ?></div>
                        <div class="yimai-gso-stat-label">仍需人工复查</div>
                    </div>
                </div>
            </div>

            <div class="yimai-gso-layout yimai-gso-detection-layout">
                <div class="yimai-gso-panel yimai-gso-input-panel">
                    <h2>⚙️ 自动检测设置</h2>
                    <form method="post">
                        <?php wp_nonce_field( 'yimai_gso_detection_nonce' ); ?>
                        <div class="yimai-gso-form-group">
                            <label class="yimai-gso-toggle-label">
                                <input type="checkbox" name="yimai_gso_detection_enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( ! empty( $feature_meta['disabled'] ), true ); ?>>
                                <span class="yimai-gso-toggle-switch"></span>
                                <span class="yimai-gso-toggle-text">启用发布后自动检测与自动修正</span>
                            </label>
                            <small>覆盖范围：自动发布、定时发布、人工发布；只要文章首次进入"已发布"状态，就会自动巡检并写回修正内容。</small>
                        </div>

                        <div class="yimai-gso-detect-flow-card" style="margin-bottom:16px;">
                            <div class="yimai-gso-detect-flow-title">执行链路</div>
                            <div class="yimai-gso-detect-flow-desc">文章发布 → 风险词检测 → 词级替换 → 按需 AI 轻改写 → 回写文章 → 记录检测日志</div>
                        </div>

                        <div class="yimai-gso-detection-setting-note">
                            <strong>说明：</strong> 当前版本固定使用"混合模式"，不再拆成多个复杂开关，优先保证稳定上线与后续复查可追溯。
                        </div>

                        <div class="yimai-gso-detection-rule-list">
                            <?php foreach ( $rules as $rule_key => $terms ) : ?>
                                <div class="yimai-gso-detection-rule-row">
                                    <div class="yimai-gso-detection-rule-head">
                                        <span><?php echo esc_html( YIMAI_GSO_Content_Detector::get_category_label( $rule_key ) ); ?></span>
                                        <span><?php echo esc_html( count( $terms ) ); ?> 项</span>
                                    </div>
                                    <div class="yimai-gso-detection-rule-desc"><?php echo esc_html( $rule_desc_map[ $rule_key ] ?? '' ); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" name="yimai_gso_save_detection_settings" class="button button-primary" <?php disabled( ! empty( $feature_meta['disabled'] ), true ); ?>>保存检测设置</button>
                    </form>
                </div>

                <div class="yimai-gso-panel yimai-gso-batch-results-panel">
                    <h2>📋 最近检测记录</h2>
                    <div class="yimai-gso-detect-tips" style="margin-bottom:16px;">
                        <span class="yimai-gso-detect-tip">最近运行：<?php echo esc_html( ! empty( $stats['last_run'] ) ? $stats['last_run'] : '暂无' ); ?></span>
                        <span class="yimai-gso-detect-tip">记录保留最近 50 条</span>
                        <span class="yimai-gso-detect-tip">可直接跳转查看文章或编辑修正结果</span>
                    </div>

                    <?php if ( empty( $logs ) ) : ?>
                        <div class="yimai-gso-empty"><p>还没有检测记录。等下一篇文章正式发布后，这里会自动出现巡检结果。</p></div>
                    <?php else : ?>
                        <div class="yimai-gso-detection-log-list">
                            <?php foreach ( $logs as $log ) : ?>
                                <div class="yimai-gso-detection-log-item status-<?php echo esc_attr( $log['status'] ?? 'clean' ); ?>">
                                    <div class="yimai-gso-detection-log-top">
                                        <strong><?php echo esc_html( $log['post_title'] ?? '未命名文章' ); ?></strong>
                                        <span class="yimai-gso-detection-log-status"><?php echo esc_html( $log['status_label'] ?? '检测完成' ); ?></span>
                                    </div>
                                    <div class="yimai-gso-detection-log-meta">
                                        <span>时间：<?php echo esc_html( $log['time'] ?? '' ); ?></span>
                                        <span>命中：<?php echo esc_html( $log['initial_hits'] ?? 0 ); ?> 项</span>
                                        <span>剩余：<?php echo esc_html( $log['remaining_hits'] ?? 0 ); ?> 项</span>
                                    </div>
                                    <div class="yimai-gso-detection-log-summary"><?php echo esc_html( $log['final_summary'] ?? '' ); ?></div>
                                    <div class="yimai-gso-detection-log-actions">
                                        <span class="yimai-gso-detect-tip">动作：<?php echo esc_html( empty( $log['actions'] ) ? '仅检测' : implode( ' + ', $log['actions'] ) ); ?></span>
                                        <?php if ( ! empty( $log['view_url'] ) ) : ?>
                                            <a href="<?php echo esc_url( $log['view_url'] ); ?>" target="_blank">查看文章</a>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $log['edit_url'] ) ) : ?>
                                            <a href="<?php echo esc_url( $log['edit_url'] ); ?>" target="_blank">编辑文章</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染自动图片ALT标签页
     */
    private function render_image_alt_panel( $active_tab ) {
        $feature_meta = $this->get_feature_meta( 'auto_alt' );
        $stats        = class_exists( 'YIMAI_GSO_Image_Alt_Admin' ) ? YIMAI_GSO_Image_Alt_Admin::get_stats() : array(
            'total_optimized' => 0,
            'month_new'       => 0,
            'pending'         => 0,
        );
        $history      = class_exists( 'YIMAI_GSO_Image_Alt_Admin' ) ? YIMAI_GSO_Image_Alt_Admin::get_history( 15 ) : array();
        $categories   = $this->get_categories_list( 'select' );
        $tags         = get_tags( array( 'hide_empty' => false, 'number' => 100 ) );
        $tags_select  = array_map( function( $t ) { return array( 'id' => $t->term_id, 'name' => $t->name ); }, $tags );
        
        $enabled       = (int) YIMAI_GSO_Settings::get( 'auto_image_alt_enabled', 1 ) === 1;
        $mode          = YIMAI_GSO_Settings::get( 'auto_image_alt_mode', 'rule' );
        $skip_existing = (int) YIMAI_GSO_Settings::get( 'auto_image_alt_skip_existing', 1 );
        ?>

        <div class="yimai-gso-main-panel <?php echo $active_tab === 'auto-alt' ? 'active' : ''; ?>" data-tab="auto-alt">
            <div class="yimai-gso-page-card-header">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-format-image"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">图片自动ALT</h2>
                    <p class="yimai-gso-card-desc">自动为文章图片生成 SEO 友好的 alt 属性文本，提升图片搜索排名</p>
                </div>
            </div>
            <div style="display:none">
                <h1 class="yimai-gso-page-title">
                    <span class="dashicons dashicons-format-image"></span>
                    自动图片ALT
                </h1>
                <?php if ( empty( $feature_meta['available'] ) ) : ?>
                    <span class="yimai-gso-plan-badge yimai-gso-plan-badge-locked">功能未解锁</span>
                <?php endif; ?>
            </div>
            <!-- Hero 统计卡片 -->
            <div class="yimai-gso-dashboard-stats">
                <div class="yimai-gso-stat-card yimai-gso-stat-primary">
                    <div class="yimai-gso-stat-icon">📊</div>
                    <div class="yimai-gso-stat-content">
                        <div class="yimai-gso-stat-num"><?php echo esc_html( number_format_i18n( $stats['total_optimized'] ?? 0 ) ); ?></div>
                        <div class="yimai-gso-stat-label">已优化ALT图片</div>
                    </div>
                </div>
                <div class="yimai-gso-stat-card yimai-gso-stat-success">
                    <div class="yimai-gso-stat-icon">✨</div>
                    <div class="yimai-gso-stat-content">
                        <div class="yimai-gso-stat-num">+<?php echo esc_html( number_format_i18n( $stats['month_new'] ?? 0 ) ); ?></div>
                        <div class="yimai-gso-stat-label">本月新增</div>
                    </div>
                </div>
                <div class="yimai-gso-stat-card yimai-gso-stat-warning">
                    <div class="yimai-gso-stat-icon">⏳</div>
                    <div class="yimai-gso-stat-content">
                        <div class="yimai-gso-stat-num"><?php echo esc_html( number_format_i18n( $stats['pending'] ?? 0 ) ); ?></div>
                        <div class="yimai-gso-stat-label">待处理图片</div>
                    </div>
                </div>
            </div>

            <div class="yimai-gso-layout">
                <!-- 左侧：设置 + 批量处理 -->
                <div class="yimai-gso-panel yimai-gso-input-panel">
                    <h2><span class="dashicons dashicons-admin-generic"></span> 模式设置</h2>
                    
                    <div class="yimai-gso-form-group">
                        <label class="yimai-gso-toggle-label">
                            <input type="checkbox" name="yimai_gso_auto_alt_enabled" id="yimai-gso-auto-alt-enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( ! empty( $feature_meta['disabled'] ) ); ?>>
                            <span class="yimai-gso-toggle-switch"></span>
                            <span class="yimai-gso-toggle-text">启用自动ALT</span>
                        </label>
                        <small>启用后，新生成的文章会自动为图片添加ALT属性</small>
                    </div>

                    <div class="yimai-gso-mode-cards" style="margin: 20px 0;">
                        <div class="yimai-gso-mode-card <?php echo $mode === 'rule' ? 'active' : ''; ?>">
                            <label>
                                <input type="radio" name="yimai_gso_auto_alt_mode" value="rule" <?php checked( $mode === 'rule' ); ?> <?php disabled( ! empty( $feature_meta['disabled'] ) ); ?>>
                                <div class="yimai-gso-mode-card-inner">
                                    <div class="yimai-gso-mode-icon">📋</div>
                                    <div class="yimai-gso-mode-info">
                                        <div class="yimai-gso-mode-title">规则模式</div>
                                        <div class="yimai-gso-mode-desc">基于标题/关键词/H2标题自动生成ALT，完全免费</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="yimai-gso-mode-card <?php echo $mode === 'ai' ? 'active' : ''; ?> <?php echo empty( $feature_meta['available'] ) ? 'disabled' : ''; ?>">
                            <label>
                                <input type="radio" name="yimai_gso_auto_alt_mode" value="ai" <?php checked( $mode === 'ai' ); ?> <?php disabled( ! empty( $feature_meta['disabled'] ) ); ?>>
                                <div class="yimai-gso-mode-card-inner">
                                    <div class="yimai-gso-mode-icon">🤖</div>
                                    <div class="yimai-gso-mode-info">
                                        <div class="yimai-gso-mode-title">AI智能模式</div>
                                        <div class="yimai-gso-mode-desc">AI深度理解图片内容，生成更精准的ALT（SEO版）</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="yimai-gso-form-group">
                        <label>
                            <input type="checkbox" name="yimai_gso_auto_alt_skip_existing" id="yimai-gso-auto-alt-skip-existing" value="1" <?php checked( $skip_existing ); ?> <?php disabled( ! empty( $feature_meta['disabled'] ) ); ?>>
                            <span>跳过已有ALT的图片（避免重复处理）</span>
                        </label>
                    </div>

                    <button type="button" class="button button-primary yimai-gso-save-alt-settings" <?php disabled( ! empty( $feature_meta['disabled'] ) ); ?>>
                        💾 保存设置
                    </button>

                    <hr style="margin: 30px 0;">

                    <h2>🚀 批量处理</h2>
                    <p class="yimai-gso-detection-hero-desc" style="margin-bottom:15px;">为历史文章批量补充图片ALT属性，支持按分类或标签筛选。</p>

                    <div class="yimai-gso-form-group">
                        <label>选择文章范围</label>
                        <div class="yimai-gso-radio-pills">
                            <label class="yimai-gso-radio-pill <?php echo !isset($_POST['alt_scope']) || $_POST['alt_scope'] === 'all' ? 'active' : ''; ?>">
                                <input type="radio" name="alt_scope" value="all" checked>
                                <span>全部文章</span>
                            </label>
                            <label class="yimai-gso-radio-pill <?php echo isset($_POST['alt_scope']) && $_POST['alt_scope'] === 'category' ? 'active' : ''; ?>">
                                <input type="radio" name="alt_scope" value="category">
                                <span>指定分类</span>
                            </label>
                        </div>
                    </div>

                    <div class="yimai-gso-form-group" id="yimai-gso-alt-category-wrap" style="display:none;">
                        <label>选择分类</label>
                        <select name="yimai_gso_alt_category" id="yimai-gso-alt-category" class="yimai-gso-select">
                            <option value="">-- 请选择分类 --</option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat['id'] ); ?>"><?php echo esc_html( $cat['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="yimai-gso-form-group">
                        <label>处理数量</label>
                        <select name="yimai_gso_alt_limit" id="yimai-gso-alt-limit" class="yimai-gso-select">
                            <option value="10">10 篇</option>
                            <option value="20" selected>20 篇</option>
                            <option value="50">50 篇</option>
                            <option value="100">100 篇</option>
                        </select>
                    </div>

                    <div class="yimai-gso-form-group">
                        <label>
                            <input type="checkbox" name="yimai_gso_alt_skip_has_alt_batch" value="1" checked>
                            <span>仅处理无ALT的图片</span>
                        </label>
                    </div>

                    <div id="yimai-gso-alt-estimate" class="yimai-gso-result-box" style="margin-bottom:15px;">
                        预估待处理：<span id="yimai-gso-alt-estimate-count">--</span> 篇
                    </div>

                    <button type="button" class="button button-primary yimai-gso-batch-alt-btn" id="yimai-gso-batch-alt-btn">
                        🚀 开始批量处理
                    </button>

                    <div id="yimai-gso-alt-progress" class="yimai-gso-progress-wrap" style="display:none; margin-top:15px;">
                        <div class="yimai-gso-progress-bar">
                            <div class="yimai-gso-progress-fill" style="width:0%"></div>
                        </div>
                        <div class="yimai-gso-progress-text">处理中...</div>
                    </div>

                    <div id="yimai-gso-alt-result" class="yimai-gso-result-box" style="display:none; margin-top:15px;"></div>
                </div>

                <!-- 右侧：处理历史 -->
                <div class="yimai-gso-panel yimai-gso-history-panel">
                    <h2>📜 处理历史</h2>
                    
                    <?php if ( empty( $history ) ) : ?>
                        <div class="yimai-gso-empty-state">
                            <div class="yimai-gso-empty-icon">📷</div>
                            <div class="yimai-gso-empty-text">暂无处理记录</div>
                            <div class="yimai-gso-empty-hint">批量处理后会在这里显示历史</div>
                        </div>
                    <?php else : ?>
                        <div class="yimai-gso-history-list">
                            <?php foreach ( $history as $item ) : 
                                // 防御：跳过数据不完整的历史记录
                                $post_id  = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
                                $title    = isset( $item['post_title'] ) && is_string( $item['post_title'] ) ? $item['post_title'] : '';
                                $date_raw = isset( $item['processed_at'] ) ? $item['processed_at'] : '';
                                $img_cnt  = isset( $item['image_count'] ) ? absint( $item['image_count'] ) : 0;
                                if ( $post_id <= 0 ) continue;
                                ?>
                                <div class="yimai-gso-history-item">
                                    <div class="yimai-gso-history-meta">
                                        <span class="yimai-gso-history-date"><?php echo esc_html( date_i18n( 'm-d H:i', strtotime( $date_raw ) ) ); ?></span>
                                        <span class="yimai-gso-history-count"><?php echo (int) $img_cnt; ?> 张图片</span>
                                    </div>
                                    <div class="yimai-gso-history-title">
                                        <?php 
                                        $edit_link = get_edit_post_link( $post_id );
                                        if ( $edit_link && ! empty( $title ) ) :
                                            // 使用 PHP 内置函数截断，不依赖外部自定义函数(如 wp_trunc_words)避免 fatal error
                                            $title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
                                            $title_display = $title_len > 60
                                                ? ( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 60 ) . '...' : substr( $title, 0, 60 ) . '...' )
                                                : $title;
                                            ?>
                                            <a href="<?php echo esc_url( $edit_link ); ?>" target="_blank">
                                                <?php echo esc_html( $title_display ); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="yimai-gso-history-deleted">(文章已删除或数据异常)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                .yimai-gso-mode-cards {
                    display: flex;
                    gap: 12px;
                }
                .yimai-gso-mode-card {
                    flex: 1;
                    border: 2px solid #e2e8f0;
                    border-radius: 12px;
                    padding: 16px;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .yimai-gso-mode-card:hover {
                    border-color: #cbd5e1;
                }
                .yimai-gso-mode-card.active {
                    border-color: #3b82f6;
                    background: #eff6ff;
                }
                .yimai-gso-mode-card.disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
                .yimai-gso-mode-card input {
                    display: none;
                }
                .yimai-gso-mode-card-inner {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .yimai-gso-mode-icon {
                    font-size: 24px;
                }
                .yimai-gso-mode-title {
                    font-weight: 600;
                    color: #1e293b;
                }
                .yimai-gso-mode-desc {
                    font-size: 12px;
                    color: #64748b;
                    margin-top: 4px;
                }
                .yimai-gso-radio-pills {
                    display: flex;
                    gap: 8px;
                }
                .yimai-gso-radio-pill {
                    display: inline-flex;
                    align-items: center;
                    padding: 8px 16px;
                    border: 2px solid #e2e8f0;
                    border-radius: 20px;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .yimai-gso-radio-pill:hover {
                    border-color: #cbd5e1;
                }
                .yimai-gso-radio-pill.active {
                    border-color: #3b82f6;
                    background: #eff6ff;
                }
                .yimai-gso-radio-pill input {
                    display: none;
                }
                .yimai-gso-progress-wrap {
                    margin-top: 15px;
                }
                .yimai-gso-progress-bar {
                    height: 8px;
                    background: #e2e8f0;
                    border-radius: 4px;
                    overflow: hidden;
                }
                .yimai-gso-progress-fill {
                    height: 100%;
                    background: linear-gradient(90deg, #3b82f6, #10b981);
                    transition: width 0.3s;
                }
                .yimai-gso-progress-text {
                    text-align: center;
                    margin-top: 8px;
                    font-size: 13px;
                    color: #64748b;
                }
            </style>

            <script>
            (function() {
                // 范围切换
                document.querySelectorAll('input[name="alt_scope"]').forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        var categoryWrap = document.getElementById('yimai-gso-alt-category-wrap');
                        categoryWrap.style.display = this.value === 'category' ? 'block' : 'none';
                    });
                });

                // 模式卡片切换
                document.querySelectorAll('input[name="yimai_gso_auto_alt_mode"]').forEach(function(radio) {
                    radio.closest('.yimai-gso-mode-card').addEventListener('click', function() {
                        document.querySelectorAll('.yimai-gso-mode-card').forEach(function(card) {
                            card.classList.remove('active');
                        });
                        this.classList.add('active');
                    });
                });

                // 保存设置
                document.querySelector('.yimai-gso-save-alt-settings')?.addEventListener('click', function() {
                    var enabled = document.getElementById('yimai-gso-auto-alt-enabled').checked ? 1 : 0;
                    var mode = document.querySelector('input[name="yimai_gso_auto_alt_mode"]:checked')?.value || 'rule';
                    var skipExisting = document.getElementById('yimai-gso-auto-alt-skip-existing').checked ? 1 : 0;

                    var formData = new FormData();
                    formData.append('action', 'yimai_gso_save_alt_settings');
                    formData.append('nonce', yimaiGsoData.nonce);
                    formData.append('enabled', enabled);
                    formData.append('mode', mode);
                    formData.append('skip_existing', skipExisting);

                    fetch(yimaiGsoData.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            alert('设置已保存');
                        } else {
                            alert('保存失败: ' + (data.data?.message || '未知错误'));
                        }
                    });
                });

                // 批量处理
                document.getElementById('yimai-gso-batch-alt-btn')?.addEventListener('click', function() {
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = '⏳ 处理中...';

                    var scope = document.querySelector('input[name="alt_scope"]:checked')?.value || 'all';
                    var category = document.getElementById('yimai-gso-alt-category')?.value || 0;
                    var limit = document.getElementById('yimai-gso-alt-limit')?.value || 20;
                    var skipHasAlt = document.querySelector('input[name="yimai_gso_alt_skip_has_alt_batch"]')?.checked ? 1 : 0;

                    var progressDiv = document.getElementById('yimai-gso-alt-progress');
                    var resultDiv = document.getElementById('yimai-gso-alt-result');
                    progressDiv.style.display = 'block';
                    resultDiv.style.display = 'none';

                    var formData = new FormData();
                    formData.append('action', 'yimai_gso_batch_alt');
                    formData.append('nonce', yimaiGsoData.nonce);
                    formData.append('scope', scope);
                    formData.append('category', category);
                    formData.append('limit', limit);
                    formData.append('skip_has_alt', skipHasAlt);

                    fetch(yimaiGsoData.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        btn.textContent = '🚀 开始批量处理';
                        progressDiv.style.display = 'none';
                        resultDiv.style.display = 'block';

                        if (data.success) {
                            var d = data.data;
                            resultDiv.className = 'yimai-gso-result-box success';
                            resultDiv.innerHTML = '✅ 批量处理完成<br>已处理：' + d.processed + ' 篇 | 跳过：' + d.skipped + ' 篇 | 失败：' + d.failed + ' 篇';
                        } else {
                            resultDiv.className = 'yimai-gso-result-box error';
                            resultDiv.innerHTML = '❌ 处理失败: ' + (data.data?.message || '未知错误');
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.textContent = '🚀 开始批量处理';
                        progressDiv.style.display = 'none';
                        resultDiv.style.display = 'block';
                        resultDiv.className = 'yimai-gso-result-box error';
                        resultDiv.innerHTML = '❌ 请求失败: ' + err.message;
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * 渲染AI内容助手主页（整合所有功能为标签页）
     */

    /**
     * 渲染更新日志面板，直接读取插件包内的 CHANGELOG.md。
     */
    /**
     * 解析插件包内的 CHANGELOG.md，返回版本块数组。
     */
    private function get_changelog_releases() {
        $path     = YIMAI_GSO_PLUGIN_DIR . 'CHANGELOG.md';
        $contents = file_exists( $path ) ? file( $path, FILE_IGNORE_NEW_LINES ) : array();
        $releases = array();
        $current  = null;

        foreach ( (array) $contents as $line ) {
            $line = trim( (string) $line );
            if ( preg_match( '/^##\s+(.+)$/u', $line, $heading ) ) {
                if ( is_array( $current ) ) {
                    $releases[] = $current;
                }
                $current = array(
                    'version' => trim( $heading[1] ),
                    'items'   => array(),
                );
                continue;
            }
            if ( is_array( $current ) && preg_match( '/^-\s+(.+)$/u', $line, $item ) ) {
                $current['items'][] = trim( $item[1] );
            }
        }
        if ( is_array( $current ) ) {
            $releases[] = $current;
        }
        if ( empty( $releases ) ) {
            $releases[] = array(
                'version' => defined( 'YIMAI_GSO_VERSION' ) ? YIMAI_GSO_VERSION : '2.4.3',
                'items'   => array( '当前版本更新日志文件暂不可读，请检查插件包是否完整。' ),
            );
        }
        return $releases;
    }

    /**
     * 输出更新日志列表（可嵌入其他面板复用）。
     */
    private function render_changelog_block( $releases = null ) {
        if ( null === $releases ) {
            $releases = $this->get_changelog_releases();
        }
        ?>
            <div class="yimai-gso-changelog-list">
                <?php foreach ( $releases as $index => $release ) : ?>
                    <section class="yimai-gso-changelog-release <?php echo 0 === $index ? 'is-current' : ''; ?>">
                        <div class="yimai-gso-changelog-release-head">
                            <h3>版本 <?php echo esc_html( $release['version'] ); ?></h3>
                            <?php if ( 0 === $index ) : ?><span class="yimai-gso-changelog-current">当前版本</span><?php endif; ?>
                        </div>
                        <ul>
                            <?php foreach ( $release['items'] as $item ) : ?>
                                <li><?php echo esc_html( $item ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php
    }

    /**
     * 渲染更新日志面板（保留旧路由兼容；侧边栏入口已合并进「在线更新」）。
     */
    private function render_changelog_panel( $active_tab ) {
        $releases = $this->get_changelog_releases();
        ?>
        <div class="yimai-gso-main-panel <?php echo $active_tab === 'changelog' ? 'active' : ''; ?>" data-tab="changelog">
            <div class="yimai-gso-page-card-header">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-clipboard"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">更新日志</h2>
                    <p class="yimai-gso-card-desc">查看一麦GSO双擎优化的版本变更、问题修复和兼容性说明</p>
                </div>
            </div>
            <?php $this->render_changelog_block( $releases ); ?>
        </div>
        <?php
    }

    /**
     * 渲染使用教程面板（读取插件包内 docs/使用教程.md，简易 Markdown 渲染）
     */
    private function render_tutorial_panel( $active_tab ) {
        $path  = YIMAI_GSO_PLUGIN_DIR . 'docs/使用教程.md';
        $lines = file_exists( $path ) ? file( $path, FILE_IGNORE_NEW_LINES ) : array();
        ?>
        <div class="yimai-gso-main-panel <?php echo $active_tab === 'tutorial' ? 'active' : ''; ?>" data-tab="tutorial">
            <div class="yimai-gso-page-card-header">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-book"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">使用教程</h2>
                    <p class="yimai-gso-card-desc">从配置到发布的完整操作说明；对应插件包内的 docs/使用教程.md</p>
                </div>
            </div>
            <div class="yimai-gso-tutorial-body">
                <?php if ( empty( $lines ) ) : ?>
                    <p>教程文件暂不可读（docs/使用教程.md 缺失），请重新上传完整插件包。</p>
                <?php else : ?>
                    <?php echo $this->render_simple_markdown( implode( "\n", $lines ) ); ?>
                <?php endif; ?>
            </div>
            <style>
                .yimai-gso-tutorial-body{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:22px 26px;line-height:1.85;font-size:14px;color:#1d2327;max-width:960px}
                .yimai-gso-tutorial-body h1{font-size:22px;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #2271b1}
                .yimai-gso-tutorial-body h2{font-size:18px;margin:26px 0 10px;color:#2271b1}
                .yimai-gso-tutorial-body h3{font-size:15px;margin:18px 0 8px}
                .yimai-gso-tutorial-body ul,.yimai-gso-tutorial-body ol{margin:8px 0 14px;padding-left:22px}
                .yimai-gso-tutorial-body li{margin-bottom:5px}
                .yimai-gso-tutorial-body code{background:#f0f0f1;padding:1px 6px;border-radius:3px;font-size:12.5px}
                .yimai-gso-tutorial-body pre{background:#1d2327;color:#f0f0f1;padding:12px 14px;border-radius:6px;overflow:auto;font-size:12.5px;line-height:1.6}
                .yimai-gso-tutorial-body pre code{background:none;color:inherit;padding:0}
                .yimai-gso-tutorial-body strong{color:#0f172a}
            </style>
        </div>
        <?php
    }

    /**
     * 极简 Markdown 渲染（标题/列表/代码块/粗体/段落），内容来自插件自带文件，仍先做转义。
     */
    private function render_simple_markdown( $md ) {
        $md    = str_replace( array( "\r\n", "\r" ), "\n", (string) $md );
        $lines = explode( "\n", $md );
        $html  = '';
        $in_code = false;
        $in_list = false;
        $para = array();

        $flush_para = function () use ( &$para, &$html ) {
            if ( ! empty( $para ) ) {
                $text = esc_html( implode( ' ', $para ) );
                $text = preg_replace( '/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text );
                $text = preg_replace( '/`([^`]+)`/u', '<code>$1</code>', $text );
                $html .= '<p>' . $text . '</p>';
                $para  = array();
            }
        };

        foreach ( $lines as $line ) {
            if ( preg_match( '/^```/', $line ) ) {
                $flush_para();
                if ( $in_list ) { $html .= '</ul>'; $in_list = false; }
                if ( $in_code ) { $html .= '</code></pre>'; $in_code = false; }
                else { $html .= '<pre><code>'; $in_code = true; }
                continue;
            }
            if ( $in_code ) {
                $html .= esc_html( $line ) . "\n";
                continue;
            }
            if ( '' === trim( $line ) ) {
                $flush_para();
                if ( $in_list ) { $html .= '</ul>'; $in_list = false; }
                continue;
            }
            if ( preg_match( '/^(#{1,4})\s+(.+)$/u', $line, $m ) ) {
                $flush_para();
                if ( $in_list ) { $html .= '</ul>'; $in_list = false; }
                $level = strlen( $m[1] );
                $html .= '<h' . $level . '>' . esc_html( trim( $m[2] ) ) . '</h' . $level . '>';
                continue;
            }
            if ( preg_match( '/^\s*[-*]\s+(.+)$/u', $line, $m ) ) {
                $flush_para();
                if ( ! $in_list ) { $html .= '<ul>'; $in_list = true; }
                $item = esc_html( trim( $m[1] ) );
                $item = preg_replace( '/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $item );
                $item = preg_replace( '/`([^`]+)`/u', '<code>$1</code>', $item );
                $html .= '<li>' . $item . '</li>';
                continue;
            }
            $para[] = trim( $line );
        }
        $flush_para();
        if ( $in_list ) { $html .= '</ul>'; }
        if ( $in_code ) { $html .= '</code></pre>'; }
        return $html;
    }

    /**
     * 渲染在线更新面板（双平台 auto-latest Release，逻辑在 YIMAI_GSO_Updater）
     */
    private function render_updater_panel( $active_tab ) {
        $can_update = current_user_can( 'manage_options' );
        $state      = YIMAI_GSO_Updater::state();
        ?>
        <div class="yimai-gso-main-panel <?php echo $active_tab === 'updater' ? 'active' : ''; ?>" data-tab="updater">
            <div class="yimai-gso-page-card-header">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-update"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">在线更新</h2>
                    <p class="yimai-gso-card-desc">从 Gitee / GitHub 双平台检查并安装新版本；更新前自动备份当前代码到 .backups 目录（保留最近 2 份）</p>
                </div>
            </div>
            <div class="yimai-gso-changelog-list">
                <p>
                    当前版本：<strong>v<?php echo esc_html( YIMAI_GSO_VERSION ); ?></strong>
                    <span id="yimai-gso-updater-status"></span>
                    <?php if ( ! empty( $state['version'] ) ) : ?>
                        <br><span>上次在线更新：v<?php echo esc_html( $state['version'] ); ?>（<?php echo esc_html( $state['time'] ); ?>，来源 <?php echo esc_html( $state['source'] ); ?>）</span>
                    <?php endif; ?>
                </p>
                <?php if ( $can_update ) : ?>
                <p>
                    <button type="button" class="button" id="yimai-gso-updater-check">检查更新</button>
                    <button type="button" class="button button-primary" id="yimai-gso-updater-run" style="display:none">立即更新</button>
                </p>
                <pre id="yimai-gso-updater-log" style="display:none"></pre>
                <?php else : ?>
                <p>更新插件需要管理员权限（manage_options）。</p>
                <?php endif; ?>
            </div>

            <!-- 版本更新日志（原独立菜单已合并到这里） -->
            <div class="yimai-gso-page-card-header" style="margin-top:24px">
                <div class="yimai-gso-card-icon"><span class="dashicons dashicons-clipboard"></span></div>
                <div class="yimai-gso-card-body">
                    <h2 class="yimai-gso-card-title">版本更新日志</h2>
                    <p class="yimai-gso-card-desc">查看一麦GSO双擎优化的版本变更、问题修复和兼容性说明</p>
                </div>
            </div>
            <?php $this->render_changelog_block(); ?>
        </div>
        <?php
    }

    public function render_main_center() {
        if ( isset( $_POST['yimai_gso_save_detection_settings'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'yimai_gso_detection_nonce' );
            $this->save_detection_settings();
            $redirect_url = add_query_arg( array(
                'page'  => 'yimai-gso-center',
                'tab'   => 'detection',
            ), admin_url( 'admin.php' ) );
            ?>
            <script type="text/javascript">
                sessionStorage.setItem('yimai_gso_save_tab', 'detection');
                window.location.href = <?php echo json_encode( $redirect_url ); ?>;
            </script>
            <?php
            return;
        }

        if ( isset( $_POST['yimai_gso_save_internal_link_settings'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'yimai_gso_internal_links_nonce' );
            $this->save_internal_link_settings();
            $redirect_url = add_query_arg( array(
                'page'  => 'yimai-gso-center',
                'tab'   => 'internal-links',
            ), admin_url( 'admin.php' ) );
            ?>
            <script type="text/javascript">
                sessionStorage.setItem('yimai_gso_save_tab', 'internal-links');
                window.location.href = <?php echo json_encode( $redirect_url ); ?>;
            </script>
            <?php
            return;
        }

        // SEO推送设置保存
        if ( isset( $_POST['yimai_gso_seo_push_settings'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'yimai_gso_seo_push_settings' );
            
            // 只有SEO版才能保存SEO推送设置
            $seo_push_meta = $this->get_feature_meta( 'seo_push' );
            if ( ! empty( $seo_push_meta['available'] ) ) {
                YIMAI_GSO_Settings::set( 'seo_push_baidu_enabled', isset( $_POST['yimai_gso_seo_push_baidu_enabled'] ) ? 1 : 0 );
                YIMAI_GSO_Settings::set( 'seo_push_baidu_api_address', esc_url_raw( $_POST['yimai_gso_seo_push_baidu_api_address'] ?? '' ) );
                YIMAI_GSO_Settings::set( 'seo_push_baidu_token', sanitize_text_field( $_POST['yimai_gso_seo_push_baidu_token'] ?? '' ) );
                YIMAI_GSO_Settings::set( 'seo_push_bing_enabled', isset( $_POST['yimai_gso_seo_push_bing_enabled'] ) ? 1 : 0 );
                YIMAI_GSO_Settings::set( 'seo_push_bing_indexnow_key', sanitize_text_field( $_POST['yimai_gso_seo_push_bing_indexnow_key'] ?? '' ) );
                YIMAI_GSO_Settings::set( 'seo_push_bing_api_key', sanitize_text_field( $_POST['yimai_gso_seo_push_bing_api_key'] ?? '' ) );
                YIMAI_GSO_Settings::set( 'seo_push_google_enabled', isset( $_POST['yimai_gso_seo_push_google_enabled'] ) ? 1 : 0 );
                YIMAI_GSO_Settings::set( 'seo_push_google_mode', sanitize_text_field( $_POST['yimai_gso_seo_push_google_mode'] ?? 'sitemap_ping' ) );
                $google_json = isset( $_POST['yimai_gso_seo_push_google_service_json'] ) ? sanitize_textarea_field( wp_unslash( $_POST['yimai_gso_seo_push_google_service_json'] ) ) : '';
                if ( ! YIMAI_GSO_Settings::is_masked_value( $google_json ) ) {
                    YIMAI_GSO_Settings::set( 'seo_push_google_service_json', $google_json );
                }
                YIMAI_GSO_Settings::set( 'seo_push_auto_enabled', isset( $_POST['yimai_gso_seo_push_auto_enabled'] ) ? 1 : 0 );
                YIMAI_GSO_Settings::set( 'seo_push_on_update', isset( $_POST['yimai_gso_seo_push_on_update'] ) ? 1 : 0 );
                YIMAI_GSO_Settings::set( 'seo_push_baidu_verify_code', sanitize_text_field( wp_unslash( $_POST['yimai_gso_seo_push_baidu_verify_code'] ?? '' ) ) );
                YIMAI_GSO_Settings::set( 'seo_push_baidu_tongji_code', trim( wp_unslash( $_POST['yimai_gso_seo_push_baidu_tongji_code'] ?? '' ) ) );
            }
            YIMAI_GSO_Settings::set( 'yimai_gso_robots_content', sanitize_textarea_field( wp_unslash( $_POST['yimai_gso_robots_content'] ?? '' ) ) );
            
            $redirect_url = add_query_arg( array(
                'page'  => 'yimai-gso-center',
                'tab'   => 'seo-push',
            ), admin_url( 'admin.php' ) );
            ?>
            <script type="text/javascript">
                sessionStorage.setItem('yimai_gso_save_tab', 'seo-push');
                window.location.href = <?php echo json_encode( $redirect_url ); ?>;
            </script>
            <?php
            return;
        }

        if ( isset( $_POST['yimai_gso_save_autopilot_settings'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'yimai_gso_autopilot_nonce' );
            $this->save_autopilot_settings();
            $redirect_url = add_query_arg( array(
                'page'  => 'yimai-gso-center',
                'tab'   => 'autopilot',
            ), admin_url( 'admin.php' ) );
            ?>
            <script type="text/javascript">
                sessionStorage.setItem('yimai_gso_save_tab', 'autopilot');
                window.location.href = <?php echo json_encode( $redirect_url ); ?>;
            </script>
            <?php
            return;
        }

        // 处理设置保存 - 使用JS跳转避免headers already sent问题

        if ( isset( $_POST['yimai_gso_save_settings'] ) && current_user_can( 'manage_options' ) ) {

            check_admin_referer( 'yimai_gso_settings_nonce' );
            $this->save_settings();
            $current_subtab = isset( $_POST['yimai_gso_current_subtab'] ) ? sanitize_text_field( $_POST['yimai_gso_current_subtab'] ) : 'ai';
            $redirect_url = add_query_arg( array(
                'page'   => 'yimai-gso-center',
                'tab'    => 'settings',
                'subtab' => $current_subtab,
            ), admin_url( 'admin.php' ) );
            ?>
            <script type="text/javascript">
                sessionStorage.setItem('yimai_gso_save_tab', 'settings');
                window.location.href = <?php echo json_encode( $redirect_url ); ?>;
            </script>
            <?php
            return;
        }

        // LLMs.txt 设置保存
        if ( isset( $_POST['yimai_gso_llms_txt_save'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'yimai_gso_llms_txt_save_nonce' );
            $llms_save_meta = $this->get_feature_meta( 'llms_txt' );
            if ( ! empty( $llms_save_meta['available'] ) ) {
                $new_settings = array(
                    'intro'                => isset( $_POST['yimai_gso_llms_txt_settings']['intro'] ) ? sanitize_textarea_field( $_POST['yimai_gso_llms_txt_settings']['intro'] ) : '',
                    'details'              => isset( $_POST['yimai_gso_llms_txt_settings']['details'] ) ? sanitize_textarea_field( $_POST['yimai_gso_llms_txt_settings']['details'] ) : '',
                    'include_categories'   => ! empty( $_POST['yimai_gso_llms_txt_settings']['include_categories'] ) ? 1 : 0,
                    'include_recent_posts' => ! empty( $_POST['yimai_gso_llms_txt_settings']['include_recent_posts'] ) ? 1 : 0,
                    'max_posts'            => isset( $_POST['yimai_gso_llms_txt_settings']['max_posts'] ) ? max( 10, min( 500, intval( $_POST['yimai_gso_llms_txt_settings']['max_posts'] ) ) ) : 100,
                    'ai_policy'            => isset( $_POST['yimai_gso_llms_txt_settings']['ai_policy'] ) ? sanitize_text_field( $_POST['yimai_gso_llms_txt_settings']['ai_policy'] ) : '',
                );
                update_option( 'yimai_gso_llms_txt_settings', $new_settings );
            }
            $redirect_url = add_query_arg( array(
                'page'  => 'yimai-gso-center',
                'tab'   => 'llms-txt',
            ), admin_url( 'admin.php' ) );
            ?>
            <script type="text/javascript">
                sessionStorage.setItem('yimai_gso_save_tab', 'llms-txt');
                window.location.href = <?php echo json_encode( $redirect_url ); ?>;
            </script>
            <?php
            return;
        }

        // 获取当前激活的标签（默认 dashboard）
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard';
        // 兼容旧书签/链接：更新日志已合并进「在线更新」
        if ( 'changelog' === $active_tab ) {
            $active_tab = 'updater';
        }

        // 获取需要的数据
        $default_category = YIMAI_GSO_Settings::get( 'default_category', 1 );
        $word_count       = YIMAI_GSO_Settings::get( 'word_count', 1000 );
        $image_count      = YIMAI_GSO_Settings::get( 'image_count', 1 );
        $categories       = $this->get_categories_list('raw');
        $providers        = YIMAI_GSO_Settings::get_ai_providers();
        $img_providers    = YIMAI_GSO_Settings::get_image_providers();
        $ai_provider      = YIMAI_GSO_Settings::get( 'ai_provider', 'openai' );
        $img_provider     = YIMAI_GSO_Settings::get( 'image_provider', 'pexels' );

        $dashboard_today      = current_time( 'Y-m-d' );
        $dashboard_week_start = wp_date( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ), wp_timezone() );

        // 尝试从 transient 读取缓存的仪表盘统计数据（5 分钟有效）
        $dashboard_cache_key = 'yimai_gso_dashboard_stats_' . $dashboard_today;
        $dashboard_cached    = get_transient( $dashboard_cache_key );
        if ( false !== $dashboard_cached && is_array( $dashboard_cached ) ) {
            $dashboard_stats              = $dashboard_cached['stats'];
            $dashboard_seo_pending        = $dashboard_cached['seo_pending'];
            $dashboard_internal_link_count = $dashboard_cached['internal_link_count'];
            $dashboard_detection_pending   = $dashboard_cached['detection_pending'];
            $dashboard_today_bars         = $dashboard_cached['today_bars'];
            $dashboard_daily_map          = $dashboard_cached['daily_map'];
            $dashboard_seo_note           = $dashboard_cached['seo_note'];
            $dashboard_internal_link_note  = $dashboard_cached['internal_link_note'];
            $dashboard_detection_note      = $dashboard_cached['detection_note'];
        } else {
            // 未命中缓存，执行所有数据库查询
            $dashboard_stats      = array(
            'total'     => 0,
            'today'     => 0,
            'week'      => 0,
            'published' => 0,
            'drafts'    => 0,
            'scheduled' => 0,
        );

        global $wpdb;
        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();
        $dashboard_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN DATE(p.post_date) = %s THEN 1 ELSE 0 END) AS today_count,
                    SUM(CASE WHEN DATE(p.post_date) >= %s THEN 1 ELSE 0 END) AS week_count,
                    SUM(CASE WHEN p.post_status = 'publish' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN p.post_status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                    SUM(CASE WHEN p.post_status = 'future' THEN 1 ELSE 0 END) AS scheduled
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
                 AND p.post_type IN ({$pt_list}) AND p.post_status != 'trash'",
                $dashboard_today,
                $dashboard_week_start
            ),
            ARRAY_A
        );

        if ( is_array( $dashboard_row ) ) {
            $dashboard_stats = array(
                'total'     => absint( $dashboard_row['total'] ?? 0 ),
                'today'     => absint( $dashboard_row['today_count'] ?? 0 ),
                'week'      => absint( $dashboard_row['week_count'] ?? 0 ),
                'published' => absint( $dashboard_row['published'] ?? 0 ),
                'drafts'    => absint( $dashboard_row['drafts'] ?? 0 ),
                'scheduled' => absint( $dashboard_row['scheduled'] ?? 0 ),
            );
        }

        $dashboard_yesterday        = wp_date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ), wp_timezone() );
        $dashboard_month_start      = wp_date( 'Y-m-d', strtotime( '-29 days', current_time( 'timestamp' ) ), wp_timezone() );
        $dashboard_prev_week_start  = wp_date( 'Y-m-d', strtotime( '-13 days', current_time( 'timestamp' ) ), wp_timezone() );
        $dashboard_prev_week_end    = wp_date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ), wp_timezone() );
        $dashboard_prev_month_start = wp_date( 'Y-m-d', strtotime( '-59 days', current_time( 'timestamp' ) ), wp_timezone() );
        $dashboard_prev_month_end   = wp_date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ), wp_timezone() );

        $dashboard_compare_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN DATE(p.post_date) = %s THEN 1 ELSE 0 END) AS yesterday_count,
                    SUM(CASE WHEN DATE(p.post_date) >= %s THEN 1 ELSE 0 END) AS month_count,
                    SUM(CASE WHEN DATE(p.post_date) BETWEEN %s AND %s THEN 1 ELSE 0 END) AS prev_week_count,
                    SUM(CASE WHEN DATE(p.post_date) BETWEEN %s AND %s THEN 1 ELSE 0 END) AS prev_month_count
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
                 AND p.post_type IN ({$pt_list}) AND p.post_status != 'trash'",
                $dashboard_yesterday,
                $dashboard_month_start,
                $dashboard_prev_week_start,
                $dashboard_prev_week_end,
                $dashboard_prev_month_start,
                $dashboard_prev_month_end
            ),
            ARRAY_A
        );

        $dashboard_stats['yesterday']  = absint( $dashboard_compare_row['yesterday_count'] ?? 0 );
        $dashboard_stats['month']      = absint( $dashboard_compare_row['month_count'] ?? 0 );
        $dashboard_stats['prev_week']  = absint( $dashboard_compare_row['prev_week_count'] ?? 0 );
        $dashboard_stats['prev_month'] = absint( $dashboard_compare_row['prev_month_count'] ?? 0 );

        // 第二行功能卡片：批量SEO、智能内链、内容检测（真实统计数据）
        $dashboard_seo_pending        = 0;
        $dashboard_seo_note           = '暂无待优化文章';
        $dashboard_internal_link_count = 0;
        $dashboard_internal_link_note  = '暂无已发布文章';
        $dashboard_detection_pending   = 0;
        $dashboard_detection_note      = '近 7 天已发布文章均已检测';

        // 批量SEO：查已发布文章中缺失 SEO 描述的数量
        $dashboard_seo_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type IN ({$pt_list}) AND p.post_status = 'publish'
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID AND pm.meta_key = '_yoast_wpseo_metadesc' AND pm.meta_value != ''
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID AND pm.meta_key = 'rank_math_description' AND pm.meta_value != ''
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID AND pm.meta_key = 'description' AND pm.meta_value != ''
               )"
        );
        $dashboard_seo_pending = $dashboard_seo_count;
        if ( $dashboard_seo_pending > 0 ) {
            $dashboard_seo_note = $dashboard_seo_pending . ' 篇已发布文章缺少 SEO 描述';
        }

        // 智能内链：查已发布文章总数
        $dashboard_internal_link_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ({$pt_list}) AND post_status = 'publish'"
        );
        if ( $dashboard_internal_link_count > 0 ) {
            $dashboard_internal_link_note = '已发布 ' . $dashboard_internal_link_count . ' 篇文章可作为内链候选';
        }

        // 内容检测：查近 7 天已发布但未检测过的文章数
        // post_date 是本地时间，所以截止时间也要用本地时间
        $seven_days_ago = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS );
        $dashboard_detection_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_type IN ({$pt_list}) AND p.post_status = 'publish' AND p.post_date >= %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm
                       WHERE pm.post_id = p.ID AND pm.meta_key = 'yimai_gso_detection_status'
                   )",
                $seven_days_ago
            )
        );
        $dashboard_detection_pending = $dashboard_detection_count;
        if ( $dashboard_detection_pending > 0 ) {
            $dashboard_detection_note = $dashboard_detection_pending . ' 篇近 7 天发布文章尚未检测';
        }

        // 图表数据查询（也在 else 分支内，一起缓存）
        $dashboard_today_bars = array_fill( 0, 6, 0 );
        $dashboard_hour_rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT HOUR(p.post_date) AS hour_value, COUNT(*) AS total
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
                 AND p.post_type IN ({$pt_list}) AND p.post_status != 'trash'
                 AND DATE(p.post_date) = %s
                 GROUP BY HOUR(p.post_date)",
                $dashboard_today
            ),
            ARRAY_A
        );
        foreach ( $dashboard_hour_rows as $dashboard_hour_row ) {
            $dashboard_bucket = min( 5, max( 0, (int) floor( absint( $dashboard_hour_row['hour_value'] ?? 0 ) / 4 ) ) );
            $dashboard_today_bars[ $dashboard_bucket ] += absint( $dashboard_hour_row['total'] ?? 0 );
        }

        $dashboard_day_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(p.post_date) AS day_key, COUNT(*) AS total
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
                 AND p.post_type IN ({$pt_list}) AND p.post_status != 'trash'
                 AND DATE(p.post_date) >= %s
                 GROUP BY DATE(p.post_date)",
                $dashboard_month_start
            ),
            ARRAY_A
        );
        $dashboard_daily_map = array();
        foreach ( $dashboard_day_rows as $dashboard_day_row ) {
            $dashboard_daily_map[ $dashboard_day_row['day_key'] ?? '' ] = absint( $dashboard_day_row['total'] ?? 0 );
        }

        // 缓存仪表盘统计数据
        set_transient( $dashboard_cache_key, array(
            'stats'               => $dashboard_stats,
            'seo_pending'         => $dashboard_seo_pending,
            'internal_link_count' => $dashboard_internal_link_count,
            'detection_pending'   => $dashboard_detection_pending,
            'today_bars'          => $dashboard_today_bars,
            'daily_map'           => $dashboard_daily_map,
            'seo_note'            => $dashboard_seo_note,
            'internal_link_note'  => $dashboard_internal_link_note,
            'detection_note'      => $dashboard_detection_note,
        ), 5 * MINUTE_IN_SECONDS );

        } // 结束 else（未命中缓存分支）

        // 以下变量在 if/else 分支之外定义，确保缓存命中时也能正确初始化
        $dashboard_build_compare_badge = static function ( $current, $previous, $label ) {
            if ( $previous > 0 ) {
                $delta = $current - $previous;
                $rate  = round( abs( $delta ) / max( 1, $previous ) * 100 );
                if ( $delta > 0 ) {
                    return '+' . $rate . '% 较' . $label;
                }
                if ( $delta < 0 ) {
                    return '-' . $rate . '% 较' . $label;
                }

                return '与' . $label . '持平';
            }

            return $current > 0 ? '本期开始有产出' : '暂无产出';
        };

        $dashboard_plan_label         = '一麦自用版';
        $dashboard__xAI               = true;
        $dashboard_batch_meta         = $this->get_feature_meta( 'batch_generate' );
        $dashboard_plan_badge         = $dashboard_plan_label;
        $dashboard_feature_highlights = array( '单篇生成', '批量生成', 'SEO增强', '智能内链', '自动发布', '图片ALT' );
        $dashboard_feature_text = implode( ' / ', array_slice( $dashboard_feature_highlights, 0, 5 ) );

        $dashboard_autopilot_meta     = $this->get_feature_meta( 'autopilot_publish' );
        $dashboard_autopilot_settings = class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ? YIMAI_GSO_Autopilot_Plan::get_settings() : array();
        $dashboard_autopilot_topics   = class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ? YIMAI_GSO_Autopilot_Plan::get_topic_lines( $dashboard_autopilot_settings ) : array();
        $dashboard_autopilot_summary  = class_exists( 'YIMAI_GSO_Autopilot_Log' ) ? YIMAI_GSO_Autopilot_Log::get_summary_stats( 7 ) : array();
        $dashboard_recent_runs        = class_exists( 'YIMAI_GSO_Autopilot_Log' ) ? YIMAI_GSO_Autopilot_Log::get_recent_runs( 1 ) : array();
        $dashboard_last_run           = ! empty( $dashboard_recent_runs ) ? $dashboard_recent_runs[0] : array();
        $dashboard_autopilot_enabled  = ! empty( $dashboard_autopilot_meta['available'] ) && ! empty( $dashboard_autopilot_settings['enabled'] );
        $dashboard_next_run_label     = $dashboard_autopilot_enabled && class_exists( 'YIMAI_GSO_Autopilot_Plan' )
            ? YIMAI_GSO_Autopilot_Plan::get_next_run_label( $dashboard_autopilot_settings )
            : '未启用';

        $dashboard_autopilot_status_label = '自动化正常';
        $dashboard_autopilot_status_class = 'info';
        $dashboard_autopilot_status_note  = '计划生效中';
        if ( empty( $dashboard_autopilot_meta['available'] ) ) {
            $dashboard_autopilot_status_label = '未启用';
            $dashboard_autopilot_status_class = 'warning';
            $dashboard_autopilot_status_note  = '请检查站点设置后使用永动发布';
        } elseif ( ! $dashboard_autopilot_enabled ) {
            $dashboard_autopilot_status_label = '未启用';
            $dashboard_autopilot_status_class = 'warning';
            $dashboard_autopilot_status_note  = '可前往永动发布页开启日更计划';
        } elseif ( ! empty( $dashboard_last_run['status'] ) && 'failed' === $dashboard_last_run['status'] ) {
            $dashboard_autopilot_status_label = '需要关注';
            $dashboard_autopilot_status_class = 'danger';
            $dashboard_autopilot_status_note  = '最近一次运行失败，建议查看日志';
        } elseif ( ! empty( $dashboard_last_run['status'] ) && 'running' === $dashboard_last_run['status'] ) {
            $dashboard_autopilot_status_label = '执行中';
            $dashboard_autopilot_status_class = 'info';
            $dashboard_autopilot_status_note  = '后台任务正在串行运行';
        }

        $dashboard_health_value = '未开启';
        $dashboard_health_note  = '永动发布未开启，健康度将在运行后统计';
        if ( empty( $dashboard_autopilot_meta['available'] ) ) {
            $dashboard_health_value = '未启用';
            $dashboard_health_note  = '开通永动发布后，这里会展示最近 7 天稳定度';
        } elseif ( $dashboard_autopilot_enabled ) {
            $dashboard_total_runs   = absint( $dashboard_autopilot_summary['total_runs'] ?? 0 );
            $dashboard_success_runs = absint( $dashboard_autopilot_summary['completed_runs'] ?? 0 );
            $dashboard_failed_runs  = absint( $dashboard_autopilot_summary['failed_runs'] ?? 0 );
            if ( $dashboard_total_runs > 0 ) {
                $dashboard_health_value = round( ( $dashboard_success_runs / max( 1, $dashboard_total_runs ) ) * 100 ) . '%';
                $dashboard_health_note  = sprintf( '最近 %d 次运行成功 %d 次，失败 %d 次', $dashboard_total_runs, $dashboard_success_runs, $dashboard_failed_runs );
            } else {
                $dashboard_health_value = '待首跑';
                $dashboard_health_note  = '计划已开启，等待首次执行后生成健康度统计';
            }
        }

        $dashboard_topic_preview = '未配置主题池';
        if ( ! empty( $dashboard_autopilot_topics ) ) {
            $dashboard_topic_preview = implode( ' / ', array_slice( $dashboard_autopilot_topics, 0, 3 ) );
            if ( count( $dashboard_autopilot_topics ) > 3 ) {
                $dashboard_topic_preview .= ' / +' . ( count( $dashboard_autopilot_topics ) - 3 ) . ' 个主题';
            }
        }

        $dashboard_last_run_title = '最近暂无运行记录';
        $dashboard_last_run_meta  = '开启永动发布后，这里会展示最近一次执行结果';
        $dashboard_last_run_desc  = '建议把高价值主题补进主题池，再让系统按计划自动接力生成。';
        if ( ! empty( $dashboard_last_run ) ) {
            $dashboard_last_run_title = sprintf(
                '最近一次运行 · %s %d / %d',
                esc_html( $dashboard_last_run['status_label'] ?? '已完成' ),
                absint( $dashboard_last_run['success_count'] ?? 0 ),
                max( 1, absint( $dashboard_last_run['daily_count'] ?? 0 ) )
            );
            $dashboard_last_run_meta = trim( implode( ' · ', array_filter( array(
                $dashboard_last_run['finished_at_label'] ?? ( $dashboard_last_run['created_at_label'] ?? '' ),
                $dashboard_last_run['trigger_label'] ?? '',
                ! empty( $dashboard_last_run['last_message'] ) ? wp_trim_words( wp_strip_all_tags( $dashboard_last_run['last_message'] ), 12, '…' ) : '',
            ) ) ) );
            $dashboard_last_run_desc = sprintf(
                '累计成功 %d 篇，失败 %d 篇，已发布 %d 篇。',
                absint( $dashboard_last_run['success_count'] ?? 0 ),
                absint( $dashboard_last_run['failed_count'] ?? 0 ),
                absint( $dashboard_last_run['published_count'] ?? 0 )
            );
        }

        $dashboard_hero_summary = $dashboard_autopilot_enabled
            ? '下次自动执行：' . $dashboard_next_run_label
            : ( $dashboard__xAI
                ? '建议先去永动发布页完善自动化计划'
                : ( ! empty( $dashboard_batch_meta['available'] )
                    ? '当前配置已开通批量生成，可直接进入主流程'
                    : '基础版先以单篇生成、改写和基础能力为主' ) );


        $dashboard_ai_api_key = trim( (string) YIMAI_GSO_Settings::get( 'ai_api_key', '' ) );
        $dashboard_todos      = array();

        if ( $dashboard_stats['scheduled'] > 0 ) {
            $dashboard_todos[] = array(
                'title'    => '安排 ' . $dashboard_stats['scheduled'] . ' 篇待发布文章',
                'desc'     => '内容管理里已有待发布内容，建议检查发布时间分布，避免集中堆积。',
                'priority' => 'P1',
                'tab'      => 'history',
            );
        }

        if ( $dashboard_stats['drafts'] > 0 ) {
            $dashboard_todos[] = array(
                'title'    => '处理 ' . $dashboard_stats['drafts'] . ' 篇草稿 / 待补 SEO 内容',
                'desc'     => '可先去批量SEO补标题与描述，再决定直接发布或继续改写。',
                'priority' => 'P1',
                'tab'      => 'seo-meta',
            );
        }

        if ( ! empty( $dashboard_autopilot_meta['available'] ) && $dashboard_autopilot_enabled && count( $dashboard_autopilot_topics ) < max( 3, absint( $dashboard_autopilot_settings['daily_count'] ?? 3 ) ) ) {
            $dashboard_todos[] = array(
                'title'    => '补充永动发布备选主题',
                'desc'     => '当前主题池偏少，建议至少补到 5 个以上，降低晚间自动化失速风险。',
                'priority' => 'P1',
                'tab'      => 'autopilot',
            );
        }

        if ( ! empty( $dashboard_autopilot_meta['available'] ) && ! $dashboard_autopilot_enabled ) {
            $dashboard_todos[] = array(
                'title'    => '启用永动发布计划',
                'desc'     => '已具备权限，但计划尚未开启，建议先配置每日时间、篇数和主题池。',
                'priority' => 'P1',
                'tab'      => 'autopilot',
            );
        }

        if ( ! $dashboard__xAI && empty( $dashboard_batch_meta['available'] ) ) {
            $dashboard_todos[] = array(
                'title'    => '配置批量生成',
                'desc'     => '请检查站点设置后使用批量生成、定时发布等更多能力。',
                'priority' => 'P2',
                'tab'      => 'settings',
            );
        }


        if ( '' === $dashboard_ai_api_key ) {
            $dashboard_todos[] = array(
                'title'    => '补全 AI 服务商配置',
                'desc'     => '当前还未检测到 AI Key，补齐后单篇、批量和自动化流程才能稳定运行。',
                'priority' => 'P0',
                'tab'      => 'settings',
            );
        }

        if ( empty( $dashboard_todos ) ) {
            $dashboard_todos[] = array(
                'title'    => '继续扩大今天的内容产出',
                'desc'     => '当前主流程状态平稳，可以直接去批量生成页继续铺量。',
                'priority' => 'P2',
                'tab'      => 'generate',
            );
        }

        $dashboard_matrix = array(
            '创作' => array(
                array( 'label' => '单篇生成', 'desc' => '高频入口', 'tab' => 'single', 'feature' => 'single_generate' ),
                array( 'label' => '批量生成', 'desc' => '主力产能', 'tab' => 'generate', 'feature' => 'batch_generate' ),
            ),
            '优化' => array(
                array( 'label' => '批量SEO元数据', 'desc' => '流量增强', 'tab' => 'seo-meta', 'feature' => 'seo_enhance' ),
                array( 'label' => '智能内链', 'desc' => '站内分发', 'tab' => 'internal-links', 'feature' => 'smart_internal_links' ),
                array( 'label' => '内容检测', 'desc' => '风险巡检', 'tab' => 'detection', 'feature' => '' ),
                array( 'label' => '自动图片ALT', 'desc' => '图像SEO', 'tab' => 'auto-alt', 'feature' => 'auto_alt' ),
            ),
            '自动化' => array(
                array( 'label' => '永动发布', 'desc' => '核心价值', 'tab' => 'autopilot', 'feature' => 'autopilot_publish' ),
                array( 'label' => '定时发布', 'desc' => '节奏编排', 'tab' => 'generate', 'feature' => 'schedule_publish' ),
                array( 'label' => '多站同步', 'desc' => '规模化扩展', 'tab' => 'settings', 'feature' => 'multi_site_sync' ),
            ),
            '管理' => array(
                array( 'label' => '内容管理', 'desc' => '记录中心', 'tab' => 'history', 'feature' => '' ),
                array( 'label' => '插件设置', 'desc' => '系统配置', 'tab' => 'settings', 'feature' => '' ),

            ),
        );

        $dashboard_matrix_total     = 0;
        $dashboard_matrix_available = 0;
        foreach ( $dashboard_matrix as $dashboard_group_label => $dashboard_group_items ) {
            foreach ( $dashboard_group_items as $dashboard_group_index => $dashboard_group_item ) {
                $dashboard_meta = array(
                    'available' => true,
                    'readonly'  => false,
                );
                if ( ! empty( $dashboard_group_item['feature'] ) ) {
                    $dashboard_meta = $this->get_feature_meta( $dashboard_group_item['feature'] );
                }
                $dashboard_matrix[ $dashboard_group_label ][ $dashboard_group_index ]['meta'] = $dashboard_meta;
                $dashboard_matrix_total++;
                if ( ! empty( $dashboard_meta['available'] ) ) {
                    $dashboard_matrix_available++;
                }
            }
        }

        $dashboard_todo_total      = count( $dashboard_todos );
        $dashboard_status_labels   = array(
            'publish' => '已发布',
            'draft'   => '草稿',
            'future'  => '待发布',
            'pending' => '待审核',
            'private' => '私密',
        );
        $dashboard_status_classes  = array(
            'publish' => 'success',
            'draft'   => 'neutral',
            'future'  => 'info',
            'pending' => 'warning',
            'private' => 'muted',
        );
        $dashboard_source_labels   = array(
            'single'    => '单篇生成',
            'batch'     => '批量生成',
            'manual'    => '手动触发',
            'publish'   => '发布流程',
            'autopilot' => '永动发布',
            'unknown'   => 'AI 生成',
        );
        $dashboard_today_badge     = $dashboard_build_compare_badge( $dashboard_stats['today'], $dashboard_stats['yesterday'], '昨日' );
        $dashboard_week_badge      = $dashboard_build_compare_badge( $dashboard_stats['week'], $dashboard_stats['prev_week'], '上周' );
        $dashboard_month_badge     = $dashboard_build_compare_badge( $dashboard_stats['month'], $dashboard_stats['prev_month'], '上月' );
        $dashboard_today_axis      = array( '00:00', '04:00', '08:00', '12:00', '16:00', '20:00' );

        $dashboard_week_bars = array();
        $dashboard_week_axis = array();
        for ( $dashboard_offset = 6; $dashboard_offset >= 0; $dashboard_offset-- ) {
            $dashboard_day_ts   = current_time( 'timestamp' ) - ( DAY_IN_SECONDS * $dashboard_offset );
            $dashboard_day_key  = wp_date( 'Y-m-d', $dashboard_day_ts, wp_timezone() );
            $dashboard_week_bars[] = absint( $dashboard_daily_map[ $dashboard_day_key ] ?? 0 );
            $dashboard_week_axis[] = wp_date( 'm-d', $dashboard_day_ts, wp_timezone() );
        }

        $dashboard_month_bars = array();
        $dashboard_month_axis = array();
        for ( $dashboard_range_index = 0; $dashboard_range_index < 6; $dashboard_range_index++ ) {
            $dashboard_range_start_offset = 29 - ( $dashboard_range_index * 5 );
            $dashboard_range_end_offset   = max( 0, $dashboard_range_start_offset - 4 );
            $dashboard_bucket_total       = 0;
            for ( $dashboard_offset = $dashboard_range_start_offset; $dashboard_offset >= $dashboard_range_end_offset; $dashboard_offset-- ) {
                $dashboard_day_key = wp_date( 'Y-m-d', current_time( 'timestamp' ) - ( DAY_IN_SECONDS * $dashboard_offset ), wp_timezone() );
                $dashboard_bucket_total += absint( $dashboard_daily_map[ $dashboard_day_key ] ?? 0 );
            }
            $dashboard_label_start = wp_date( 'm-d', current_time( 'timestamp' ) - ( DAY_IN_SECONDS * $dashboard_range_start_offset ), wp_timezone() );
            $dashboard_label_end   = wp_date( 'm-d', current_time( 'timestamp' ) - ( DAY_IN_SECONDS * $dashboard_range_end_offset ), wp_timezone() );
            $dashboard_month_bars[] = $dashboard_bucket_total;
            $dashboard_month_axis[] = $dashboard_label_start === $dashboard_label_end ? $dashboard_label_end : $dashboard_label_start . ' ~ ' . $dashboard_label_end;
        }

        $dashboard_trend_views = array(
            'today' => array(
                'main'  => $dashboard_stats['today'] . ' 篇',
                'sub'   => '今日总生成量，含单篇 / 批量 / 永动发布。',
                'badge' => $dashboard_today_badge,
                'bars'  => $dashboard_today_bars,
                'axis'  => $dashboard_today_axis,
            ),
            'week' => array(
                'main'  => $dashboard_stats['week'] . ' 篇',
                'sub'   => '近 7 天生成量，适合判断这周产能是否稳定。',
                'badge' => $dashboard_week_badge,
                'bars'  => $dashboard_week_bars,
                'axis'  => $dashboard_week_axis,
            ),
            'month' => array(
                'main'  => $dashboard_stats['month'] . ' 篇',
                'sub'   => '近 30 天累计生成量，适合看整体爬坡趋势。',
                'badge' => $dashboard_month_badge,
                'bars'  => $dashboard_month_bars,
                'axis'  => $dashboard_month_axis,
            ),
        );

        $dashboard_recent_items = array();
        $dashboard_recent_query = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => 4,
            'meta_query'     => array(
                array(
                    'key'   => 'yimai_gso_generated',
                    'value' => 1,
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        if ( $dashboard_recent_query->have_posts() ) {
            foreach ( $dashboard_recent_query->posts as $dashboard_recent_post ) {
                $dashboard_recent_id         = $dashboard_recent_post->ID;
                $dashboard_recent_source     = sanitize_key( (string) get_post_meta( $dashboard_recent_id, 'yimai_gso_source', true ) );
                $dashboard_recent_generated  = (string) get_post_meta( $dashboard_recent_id, 'yimai_gso_generated_at', true );
                $dashboard_recent_word_count = absint( get_post_meta( $dashboard_recent_id, 'yimai_gso_word_count', true ) );
                $dashboard_recent_categories = wp_get_post_terms( $dashboard_recent_id, 'category', array( 'fields' => 'names' ) );
                $dashboard_recent_status     = $dashboard_recent_post->post_status;
                $dashboard_recent_items[]    = array(
                    'title'        => get_the_title( $dashboard_recent_id ),
                    'status_label' => $dashboard_status_labels[ $dashboard_recent_status ] ?? '已生成',
                    'status_class' => $dashboard_status_classes[ $dashboard_recent_status ] ?? 'neutral',
                    'source_label' => $dashboard_source_labels[ $dashboard_recent_source ] ?? 'AI 生成',
                    'time_label'   => $dashboard_recent_generated ? mysql2date( 'm-d H:i', $dashboard_recent_generated ) : mysql2date( 'm-d H:i', $dashboard_recent_post->post_date ),
                    'category'     => ! empty( $dashboard_recent_categories ) ? $dashboard_recent_categories[0] : '未分类',
                    'note'         => 'future' === $dashboard_recent_status
                        ? '已安排定时发布'
                        : ( $dashboard_recent_word_count > 0 ? '字数约 ' . $dashboard_recent_word_count : '可继续补 SEO / 内链' ),
                    'view_url'     => get_permalink( $dashboard_recent_id ),
                    'edit_url'     => admin_url( 'post.php?post=' . $dashboard_recent_id . '&action=edit' ),
                );
            }
            wp_reset_postdata();
        }

        $dashboard_activity_logs = array(
            array(
                'time'  => ! empty( $dashboard_last_run['finished_at_label'] ) ? $dashboard_last_run['finished_at_label'] : '自动化',
                'title' => ! empty( $dashboard_last_run ) ? '永动发布最近一次：' . ( $dashboard_last_run['status_label'] ?? '已执行' ) : '永动发布等待首次执行',
                'desc'  => ! empty( $dashboard_last_run )
                    ? sprintf( '成功 %d 篇，失败 %d 篇，已发布 %d 篇。', absint( $dashboard_last_run['success_count'] ?? 0 ), absint( $dashboard_last_run['failed_count'] ?? 0 ), absint( $dashboard_last_run['published_count'] ?? 0 ) )
                    : '开启永动发布后，这里会自动回显最近一次运行结果与摘要。',
            ),
            array(
                'time'  => '待处理',
                'title' => $dashboard_stats['drafts'] > 0 ? '当前有 ' . $dashboard_stats['drafts'] . ' 篇草稿待补流程' : '当前草稿 / 待补内容压力较低',
                'desc'  => $dashboard_stats['drafts'] > 0
                    ? '建议优先去批量SEO或内容管理页补标题、描述与发布时间，减少内容积压。'
                    : '可以继续把精力放在批量起量或自动化扩容上。',
            ),
        );

        $dashboard_ai_provider_name    = isset( $providers[ $ai_provider ]['name'] ) ? $providers[ $ai_provider ]['name'] : strtoupper( $ai_provider );
        $dashboard_image_provider_name = isset( $img_providers[ $img_provider ]['name'] ) ? $img_providers[ $img_provider ]['name'] : strtoupper( $img_provider );
        $dashboard_primary_todo        = ! empty( $dashboard_todos ) ? $dashboard_todos[0] : array();
        $dashboard_priority_count      = 0;
        foreach ( $dashboard_todos as $dashboard_todo_item ) {
            if ( in_array( $dashboard_todo_item['priority'] ?? '', array( 'P0', 'P1' ), true ) ) {
                $dashboard_priority_count++;
            }
        }

        $dashboard_single_meta = $this->get_feature_meta( 'single_generate' );
        $dashboard_batch_meta  = $this->get_feature_meta( 'batch_generate' );
        $dashboard_seo_meta    = $this->get_feature_meta( 'seo_enhance' );

        $dashboard_describe_feature_state = static function ( $meta, $available_text ) {
            if ( empty( $meta['available'] ) ) {
                return '请检查站点设置后使用此功能';
            }
            if ( ! empty( $meta['readonly'] ) ) {
                return '当前为只读模式';
            }

            return $available_text;
        };

        $dashboard_stat_cards = array(
            array(
                'label'  => '今日生成',
                'value'  => $dashboard_stats['today'],
                'icon'   => '日',
                'accent' => 'highlight',
                'trend'  => $dashboard_today_badge,
                'note'   => '今天累计生成内容，含单篇 / 批量 / 永动发布。',
            ),
            array(
                'label'  => '本周生成',
                'value'  => $dashboard_stats['week'],
                'icon'   => '周',
                'accent' => '',
                'trend'  => $dashboard_week_badge,
                'note'   => '近 7 天总产出，适合看当前起量是否稳定。',
            ),
            array(
                'label'  => '已发布',
                'value'  => $dashboard_stats['published'],
                'icon'   => '发',
                'accent' => '',
                'trend'  => '累计已入库文章',
                'note'   => '含直接发布、定时发布和手动发布结果。',
            ),
            array(
                'label'  => '待发布',
                'value'  => $dashboard_stats['scheduled'],
                'icon'   => '排',
                'accent' => '',
                'trend'  => $dashboard_stats['scheduled'] > 0 ? '建议分时排布' : '当前排队压力较低',
                'note'   => $dashboard_stats['scheduled'] > 0 ? '可去内容管理或批量生成页继续调整发布时间。' : '当前没有明显积压，可继续铺量。',
            ),
            array(
                'label'  => '永动计划状态',
                'value'  => $dashboard_autopilot_status_label,
                'icon'   => '∞',
                'accent' => 'dark',
                'trend'  => '下次执行：' . $dashboard_next_run_label,
                'note'   => $dashboard_autopilot_status_note,
            ),
            array(
                'label'  => '待处理事项',
                'value'  => $dashboard_todo_total,
                'icon'   => '待',
                'accent' => '',
                'trend'  => $dashboard_priority_count > 0 ? $dashboard_priority_count . ' 个优先项建议今天处理' : '当前主流程相对平稳',
                'note'   => ! empty( $dashboard_primary_todo['desc'] ) ? $dashboard_primary_todo['desc'] : '没有高优先级阻塞，可继续把精力放在产出和自动化上。',
            ),
        );

        $dashboard_action_cards = array(
            array(
                'tag'      => '推荐动作 01 · 热点响应',
                'title'    => '开始写一篇',
                'desc'     => '适合新品、热点或临时营销文章，直接进入单篇生成，不用再自己翻顶部标签找入口。',
                'subnote'  => '' === $dashboard_ai_api_key ? '当前建议：先补 AI 服务商配置，再开始单篇生成。' : '当前 AI 服务商已就绪，适合直接开写。',
                'bullets'  => array(
                    '支持标题留空自动生成',
                    '可直接接入配图与发布流程',
                    '适合处理今天的临时热点任务',
                ),
                'state'    => $dashboard_describe_feature_state( $dashboard_single_meta, '预计 2~4 分钟完成一篇' ),
                'tab'      => 'single',
                'primary'  => true,
            ),
            array(
                'tag'      => '推荐动作 02 · 起量增产',
                'title'    => '批量起量',
                'desc'     => '适合今天集中铺量，直接进入批量标题 → 批量文章主流程，把内容产出做厚。',
                'subnote'  => ! empty( $dashboard_batch_meta['available'] )
                    ? ( $dashboard__xAI ? '当前可直接进入批量主流程，适合把今天的起量动作做满。' : '当前配置已开通批量生成，可直接进入主流程做内容铺量。' )
                    : '基础版暂未开放批量生成，可先用单篇生成和配图能力承接日常创作。',

                'bullets'  => array(
                    '已兼容配图、自动标签、发布策略',
                    '支持统一分类、统一标签与定时发布',
                    '适合高频商业化内容铺量场景',
                ),
                'state'    => $dashboard_describe_feature_state( $dashboard_batch_meta, '批量任务更适合今天的主线动作' ),
                'tab'      => 'generate',
                'primary'  => false,
            ),
            array(
                'tag'      => '推荐动作 03 · 自动化巡检',
                'title'    => '检查永动发布',
                'desc'     => '永动发布是高阶价值中心，工作台应该让它一眼可见，而不是继续藏在深层标签里。',
                'subnote'  => '当前状态：' . $dashboard_last_run_desc,
                'bullets'  => array(
                    '看下次执行时间与今日主题池',
                    '看最近运行日志是否稳定',
                    '快速判断要不要人工介入',
                ),
                'state'    => $dashboard_describe_feature_state( $dashboard_autopilot_meta, '建议每天至少巡检 1 次' ),
                'tab'      => 'autopilot',
                'primary'  => false,
            ),
        );
        ?>



        <div class="wrap yimai-gso-wrap yimai-gso-center-wrap yimai-gso-sidebar-layout">

            <!-- 左侧边栏导航 -->
            <aside class="yimai-gso-sidebar">
                <div class="yimai-gso-sidebar-header">
                    <div class="yimai-gso-sidebar-brand-icon">
                        <img src="<?php echo esc_url( YIMAI_GSO_PLUGIN_URL . 'assets/images/gso-logo.png' ); ?>" alt="一麦GSO" class="yimai-gso-brand-logo">
                    </div>
                    <!-- 品牌标题用 div 而非 h2：WP common.js 会把 .notice 移动到 .wrap 内
                         第一个 h1/h2 之后，这里曾因此把保存提示塞进紫色渐变头部里，
                         与半透明白字样式叠加后完全看不清（用户报告「提醒颜色重叠」）。 -->
                    <div class="yimai-gso-sidebar-brand-text">
                        <div class="yimai-gso-sidebar-brand-name">一麦GSO双擎优化</div>
                        <p>SEO排名与GEO引用双引擎协同优化</p>
                    </div>
                </div>
                <nav class="yimai-gso-sidebar-nav">
                    <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                        <span class="dashicons dashicons-dashboard"></span>
                        <span>仪表盘</span>
                    </button>

                    <div class="yimai-gso-sidebar-group collapsed" data-group="sys">
                        <div class="yimai-gso-sidebar-group-label"><span class="dashicons dashicons-admin-generic"></span> 系统设置</div>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <span>基础设置</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'tutorial' ? 'active' : ''; ?>" data-tab="tutorial">
                            <span class="dashicons dashicons-book"></span>
                            <span>使用教程</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'updater' ? 'active' : ''; ?>" data-tab="updater">
                            <span class="dashicons dashicons-update"></span>
                            <span>在线更新</span>
                        </button>
                    </div>

                    <div class="yimai-gso-sidebar-group" data-group="content">
                        <div class="yimai-gso-sidebar-group-label"><span class="dashicons dashicons-welcome-write-blog"></span> 内容创作</div>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'single' ? 'active' : ''; ?>" data-tab="single">
                            <span class="dashicons dashicons-welcome-write-blog"></span>
                            <span>单篇文章</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'rewrite' ? 'active' : ''; ?>" data-tab="rewrite">
                            <span class="dashicons dashicons-edit"></span>
                            <span>内容改写</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'generate' ? 'active' : ''; ?>" data-tab="generate">
                            <span class="dashicons dashicons-edit-large"></span>
                            <span>批量文章</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'ai-excerpt' ? 'active' : ''; ?>" data-tab="ai-excerpt">
                            <span class="dashicons dashicons-editor-paragraph"></span>
                            <span>AI摘要</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'detection' ? 'active' : ''; ?>" data-tab="detection">
                            <span class="dashicons dashicons-shield-alt"></span>
                            <span>内容检测</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'image-library' ? 'active' : ''; ?>" data-tab="image-library">
                            <span class="dashicons dashicons-format-gallery"></span>
                            <span>图片库</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'history' ? 'active' : ''; ?>" data-tab="history">
                            <span class="dashicons dashicons-list-view"></span>
                            <span>内容管理</span>
                        </button>
                    </div>

                    <div class="yimai-gso-sidebar-group collapsed" data-group="seo">
                        <div class="yimai-gso-sidebar-group-label"><span class="dashicons dashicons-search"></span> SEO 优化</div>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'seo-score' ? 'active' : ''; ?>" data-tab="seo-score">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <span>SEO评分</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'seo-meta' ? 'active' : ''; ?>" data-tab="seo-meta">
                            <span class="dashicons dashicons-chart-line"></span>
                            <span>SEO元数据</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'internal-links' ? 'active' : ''; ?>" data-tab="internal-links">
                            <span class="dashicons dashicons-admin-links"></span>
                            <span>智能内链</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'schema' ? 'active' : ''; ?>" data-tab="schema">
                            <span class="dashicons dashicons-code-standards"></span>
                            <span>结构化数据</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'seo-push' ? 'active' : ''; ?>" data-tab="seo-push">
                            <span class="dashicons dashicons-share-alt2"></span>
                            <span>SEO推送</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'heading' ? 'active' : ''; ?>" data-tab="heading">
                            <span class="dashicons dashicons-heading"></span>
                            <span>标题分析</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'auto-alt' ? 'active' : ''; ?>" data-tab="auto-alt">
                            <span class="dashicons dashicons-format-image"></span>
                            <span>图片自动ALT</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'canonical' ? 'active' : ''; ?>" data-tab="canonical">
                            <span class="dashicons dashicons-admin-links"></span>
                            <span>Canonical</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'optimizer-404' ? 'active' : ''; ?>" data-tab="optimizer-404">
                            <span class="dashicons dashicons-warning"></span>
                            <span>404优化</span>
                        </button>
                    </div>

                    <?php $l5_meta = $this->get_feature_meta( 'l5_dashboard' ); ?>
                    <div class="yimai-gso-sidebar-group collapsed" data-group="geo">
                        <div class="yimai-gso-sidebar-group-label"><span class="dashicons dashicons-admin-site"></span> GEO优化</div>
                        <?php if ( $l5_meta['available'] ) : ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'l5-overview' ? 'active' : ''; ?>" data-tab="l5-overview">
                            <span class="dashicons dashicons-chart-area"></span>
                            <span>双擎仪表盘</span>
                        </button>
                        <?php endif; ?>
                        <?php $audit_meta = $this->get_feature_meta( 'geo_auditor' ); ?>
                        <?php if ( $audit_meta['available'] ) : ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'geo-audit' ? 'active' : ''; ?>" data-tab="geo-audit">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <span>AI就绪审计</span>
                        </button>
                        <?php endif; ?>
                        <?php if ( $l5_meta['available'] ) : ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'l5-diagnosis' ? 'active' : ''; ?>" data-tab="l5-diagnosis">
                            <span class="dashicons dashicons-search"></span>
                            <span>爬虫友好度</span>
                        </button>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'l5-crawler' ? 'active' : ''; ?>" data-tab="l5-crawler">
                            <span class="dashicons dashicons-visibility"></span>
                            <span>AI爬虫追踪</span>
                        </button>
                        <?php endif; ?>
                        <?php $fresh_meta = $this->get_feature_meta( 'content_freshness' ); ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'freshness' ? 'active' : ''; ?><?php echo ! $fresh_meta['available'] ? ' locked' : ''; ?>" data-tab="freshness">
                            <span class="dashicons dashicons-update"></span>
                            <span>新鲜度监控</span>
                            <?php if ( ! $fresh_meta['available'] ) : ?>
                                <span class="yimai-gso-sidebar-lock-icon dashicons dashicons-lock"></span>
                            <?php endif; ?>
                        </button>
                        <?php $llms_meta = $this->get_feature_meta( 'llms_txt' ); ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'llms-txt' ? 'active' : ''; ?><?php echo ! $llms_meta['available'] ? ' locked' : ''; ?>" data-tab="llms-txt">
                            <span class="dashicons dashicons-media-text"></span>
                            <span>LLMs生成</span>
                            <?php if ( ! $llms_meta['available'] ) : ?>
                                <span class="yimai-gso-sidebar-lock-icon dashicons dashicons-lock"></span>
                            <?php endif; ?>
                        </button>
                        <?php $bridge_meta = $this->get_feature_meta( 'seo_geo_bridge' ); ?>
                        <?php if ( $bridge_meta['available'] ) : ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'seo-geo-bridge' ? 'active' : ''; ?>" data-tab="seo-geo-bridge">
                            <span class="dashicons dashicons-admin-links"></span>
                            <span>SEO-GEO桥接</span>
                        </button>
                        <?php endif; ?>
                        <?php if ( $l5_meta['available'] ) : ?>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'l5-alerts' ? 'active' : ''; ?>" data-tab="l5-alerts">
                            <span class="dashicons dashicons-bell"></span>
                            <span>实时预警</span>
                            <?php if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) : ?>
                            <?php $badge = YIMAI_GSO_Alert_Engine::get_active_critical_count(); ?>
                            <?php if ( $badge > 0 ) : ?>
                            <span class="yimai-gso-l5-alert-badge"><?php echo esc_html( $badge ); ?></span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <div class="yimai-gso-sidebar-group collapsed" data-group="auto">
                        <div class="yimai-gso-sidebar-group-label"><span class="dashicons dashicons-clock"></span> 自动化</div>
                        <button class="yimai-gso-sidebar-tab <?php echo $active_tab === 'autopilot' ? 'active' : ''; ?>" data-tab="autopilot">
                            <span class="dashicons dashicons-clock"></span>
                            <span>永动发布</span>
                        </button>
                    </div>
                </nav>
            </aside>

            <!-- 主内容区 -->
            <main class="yimai-gso-main-content">

            <!-- ==================== 标签1：仪表盘 v5 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                <div class="yimai-gso-dashboard-shell">
                    <!-- T1：原此处有 4 个 write-only 的 trial_* 变量
                         （$trial_banner_html / $is_trial_active / $trial_days_remaining /
                         $trial_expired_at），每个都只被赋值一次、从不被读取，
                         是已移除的试用期授权系统残留。dashboard-v5.php 也不使用它们。已删除。 -->

                    <!-- ===== 仪表盘 v5 双引擎数据驾驶舱 ===== -->
                    <?php include plugin_dir_path( __FILE__ ) . "dashboard-v5.php"; ?>

                </div>
            </div>

            <!-- ==================== 更新日志 ==================== -->
            <?php $this->render_changelog_panel( $active_tab ); ?>

            <!-- ==================== 在线更新 ==================== -->
            <!-- ==================== 使用教程 ==================== -->
            <?php $this->render_tutorial_panel( $active_tab ); ?>

            <?php $this->render_updater_panel( $active_tab ); ?>


            <!-- ==================== 标签2：单篇生成 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'single' ? 'active' : ''; ?>" data-tab="single">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-welcome-write-blog"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">单篇生成</h2>
                        <p class="yimai-gso-card-desc">输入标题或关键词，AI 一键生成高质量原创文章，支持多种风格和字数控制</p>
                    </div>
                </div>

                <div class="yimai-gso-panel" style="margin-bottom:16px;border-left:4px solid #3b82f6;background:#eff6ff;">
                    <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;">
                        <span class="dashicons dashicons-info-outline" style="color:#3b82f6;margin-right:4px;vertical-align:text-bottom;"></span>
                        生成的文章质量跟大模型有关，建议用 DeepSeek 模型，其它免费的模型都是有限制的，生成出来的文章效果不是很好。可以自己多个模型测试一下，哪个好你就用哪个。
                    </p>
                </div>

                <!-- 状态栏（单篇生成无限制，无需显示） -->
                <?php // 状态栏已隐藏 ?>

                <div class="yimai-gso-layout yimai-gso-single-layout">
                    <!-- 输入区域 -->
                    <div class="yimai-gso-panel yimai-gso-input-panel">
                        <h2>文章参数设置</h2>

                        <div class="yimai-gso-form-row">
                            <div class="yimai-gso-form-group">
                                <label>关键词 / 主题 <span class="required">*</span></label>
                                <input type="text" id="yimai-gso-single-keyword" class="large-text"
                                    placeholder="例如：AI写作技巧、电商运营攻略">
                                <small>输入文章的核心关键词或主题，AI会根据此生成内容</small>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label>文章标题 <small style="color:var(--yimai-gso-gray);font-weight:normal;display:inline;">(留空由AI自动生成)</small></label>
                                <input type="text" id="yimai-gso-single-title" class="large-text"
                                    placeholder="留空让AI自动生成标题">
                                <small>输入标题，AI会围绕此标题撰写正文；留空则AI根据关键词自动生成</small>
                            </div>
                        </div>

                        <div class="yimai-gso-form-row">
                            <div class="yimai-gso-form-group">
                                <label>文章长度</label>
                                <select id="yimai-gso-single-length" class="large-text">
                                    <option value="short">短篇 · 约 800 字</option>
                                    <option value="medium" selected>中篇 · 约 1500 字</option>
                                    <option value="long">长篇 · 约 2500 字</option>
                                </select>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label>文章类型 <span class="yimai-gso-tag-new">新增选项</span></label>
                                <select id="yimai-gso-single-type" class="large-text">
                                    <option value="guide">📖 教程攻略</option>
                                    <option value="news">📰 资讯新闻</option>
                                    <option value="opinion">💭 观点评论</option>
                                    <option value="review" selected>⭐ 产品评测</option>
                                    <option value="case">🔍 案例分析</option>
                                    <option value="listicle">📋 清单盘点</option>
                                    <option value="story">📖 故事叙事</option>
                                    <option value="qa">❓ 问答解惑</option>
                                </select>
                            </div>
                        </div>

                        <div class="yimai-gso-form-row">
                            <div class="yimai-gso-form-group">
                                <label>写作角度 <span class="yimai-gso-tag-new">新增</span></label>
                                <select id="yimai-gso-single-angle" class="large-text">
                                    <option value="">通用（AI 自动选择）</option>
                                    <option value="tutorial">教程类 · 手把手教学</option>
                                    <option value="reveal">揭秘类 · 行业秘密</option>
                                    <option value="list">盘点类 · Top榜单合集</option>
                                    <option value="pitfall">避坑类 · 新手误区</option>
                                    <option value="trend">趋势类 · 未来预测</option>
                                    <option value="case">案例类 · 真实故事</option>
                                    <option value="knowledge">干货类 · 硬核知识</option>
                                    <option value="qa">问答类 · 提出疑问解答</option>
                                    <option value="compare">对比类 · A vs B对比</option>
                                    <option value="story">故事类 · 个人经历</option>
                                    <option value="hot">热点类 · 蹭热点</option>
                                    <option value="mindset">思维类 · 认知提升</option>
                                    <option value="myth">反常识类 · 打破常规</option>
                                    <option value="data">数据类 · 数据分析</option>
                                    <option value="audience">人群类 · 细分领域</option>
                                </select>
                            </div>
                            <div class="yimai-gso-form-group">
                                <label>写作风格</label>
                                <input type="text" id="yimai-gso-single-style" class="large-text"
                                    placeholder="例如：专业严谨、轻松幽默、干货满满">
                                <small>描述你想要的写作风格，不填则使用默认风格</small>
                            </div>
                        </div>

                        <div class="yimai-gso-form-group" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:8px;">
                            <label style="font-size:13px;font-weight:400;margin:0;">
                                <input type="checkbox" id="yimai-gso-single-image-enabled" checked>
                                自动配图
                            </label>
                            <label style="font-size:13px;font-weight:400;margin:0;">
                                <input type="checkbox" id="yimai-gso-single-auto-excerpt">
                                自动生成摘要
                            </label>
                            <label style="font-size:13px;font-weight:400;margin:0;">
                                <input type="checkbox" id="yimai-gso-single-auto-seo">
                                自动提取SEO元数据
                            </label>
                        </div>

                        <button type="button" id="yimai-gso-single-generate-btn" class="button button-primary button-hero yimai-gso-generate-btn" style="margin-top:16px;">
                            🪄 开始生成
                        </button>

                        <div id="yimai-gso-single-progress" class="yimai-gso-progress" style="display:none;">
                            <div class="yimai-gso-progress-bar">
                                <div class="yimai-gso-progress-fill yimai-gso-single-fill"></div>
                            </div>
                            <p id="yimai-gso-single-progress-text">准备中...</p>
                        </div>
                    </div>

                    <!-- 右侧：结果预览 -->
                    <div class="yimai-gso-panel yimai-gso-single-preview-panel">
                        <div class="yimai-gso-titles-toolbar">
                            <h2>生成结果区 <small style="color:var(--yimai-gso-gray);font-weight:normal;">(优化后)</small></h2>
                            <div class="yimai-gso-titles-actions">
                                <button id="yimai-gso-single-copy-btn" class="button" style="display:none;">
                                    <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;margin:0;vertical-align:middle;"></span>
                                    复制内容
                                </button>
                            </div>
                        </div>

                        <div id="yimai-gso-single-results">
                            <div class="yimai-gso-empty">
                                <p>✍️ 输入关键词，选择参数<br>点击"开始生成"AI将为你创作一篇完整的文章</p>
                            </div>
                        </div>

                        <!-- 预览模式切换 -->
                        <div id="yimai-gso-single-preview-tabs" style="display:none;margin-top:16px;">
                            <div class="yimai-gso-tab-buttons" style="display:flex;gap:8px;margin-bottom:12px;">
                                <button type="button" class="yimai-gso-preview-tab active" data-mode="text">📝 纯文本</button>
                                <button type="button" class="yimai-gso-preview-tab" data-mode="html">🌐 HTML 预览</button>
                            </div>
                            <div id="yimai-gso-single-preview-text" class="yimai-gso-preview-pane"></div>
                            <div id="yimai-gso-single-preview-html" class="yimai-gso-preview-pane" style="display:none;">
                                <iframe id="yimai-gso-single-preview-iframe" style="width:100%;min-height:400px;border:1px solid #e2e8f0;border-radius:6px;" sandbox="allow-same-origin"></iframe>
                            </div>
                        </div>

                        <!-- 发布区域 -->
                        <div id="yimai-gso-single-publish-area" style="display:none;margin-top:20px;border-top:1px solid #e5e7eb;padding-top:20px;">
                            <div class="yimai-gso-form-row">
                                <div class="yimai-gso-form-group">
                                    <label>文章分类</label>
                                    <select id="yimai-gso-single-category">
                                        <?php foreach ( $categories as $cat ) : ?>
                                            <option value="<?php echo $cat->term_id; ?>" <?php selected( $default_category, $cat->term_id ); ?>>
                                                <?php echo esc_html( $cat->name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="yimai-gso-form-group">
                                    <label>标签</label>
                                    <input type="text" id="yimai-gso-single-tags" class="large-text" placeholder="标签1,标签2,标签3">
                                </div>
                            </div>

                            <div class="yimai-gso-form-group">
                                <label>发布方式</label>
                                <div class="yimai-gso-radio-group">
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai-gso-single-status" value="draft" checked>
                                        <span>💾 存为草稿</span>
                                    </label>
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai-gso-single-status" value="publish">
                                        <span>🚀 直接发布</span>
                                    </label>
                                </div>
                            </div>

                            <div class="yimai-gso-action-bar">
                                <button id="yimai-gso-single-publish-btn" class="button button-primary">
                                    <span class="dashicons dashicons-cloud-upload"></span>
                                    发布到 WordPress
                                </button>
                                <button id="yimai-gso-single-regenerate-btn" class="button">
                                    <span class="dashicons dashicons-controls-repeat"></span>
                                    重新生成
                                </button>
                            </div>

                            <div id="yimai-gso-single-publish-result" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：内容改写 ==================== -->
            <?php
            $rewrite_meta     = $this->get_feature_meta( 'content_rewrite' );
            $batch_rw_meta    = $this->get_feature_meta( 'batch_rewrite' );
            $categories_list  = $this->get_categories_list('raw');
            $default_category = YIMAI_GSO_Settings::get( 'default_category', 1 );
            ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'rewrite' ? 'active' : ''; ?>" data-tab="rewrite">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-edit"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">内容改写</h2>
                        <p class="yimai-gso-card-desc">AI 改写/翻新已有内容，支持粘贴原文改写、单篇文章翻新和批量翻新三种模式</p>
                    </div>
                </div>

                <!-- 子标签导航 -->
                <div class="yimai-gso-rewrite-subtabs">
                    <button type="button" class="yimai-gso-rewrite-subtab active" data-rtab="paste">
                        <span class="dashicons dashicons-clipboard"></span> 粘贴原文改写
                    </button>
                    <button type="button" class="yimai-gso-rewrite-subtab" data-rtab="single">
                        <span class="dashicons dashicons-welcome-write-blog"></span> 单篇文章翻新
                    </button>
                    <button type="button" class="yimai-gso-rewrite-subtab" data-rtab="batch">
                        <span class="dashicons dashicons-category"></span> 批量翻新改写
                        <?php if ( empty( $batch_rw_meta['available'] ) ) : ?>
                        <span class="yimai-gso-badge yimai-gso-badge-locked">Content+</span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- ===== 子面板1：粘贴原文改写 ===== -->
                <div class="yimai-gso-rewrite-panel active" data-rtab="paste">
                    <!-- 顶部控制栏 -->
                    <div class="yimai-gso-panel" style="margin-bottom:16px">
                        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                            <div class="yimai-gso-rewrite-options" style="margin-bottom:0">
                                <div class="yimai-gso-rewrite-option-item">
                                    <label>改写力度</label>
                                    <select id="yimai-gso-rw-paste-strength">
                                        <option value="light">轻度 — 微调措辞</option>
                                        <option value="medium" selected>中度 — 重组优化</option>
                                        <option value="deep">深度 — 全面重写</option>
                                    </select>
                                </div>
                                <div class="yimai-gso-rewrite-option-item">
                                    <label>改写风格</label>
                                    <select id="yimai-gso-rw-paste-style">
                                        <option value="default">默认风格</option>
                                        <option value="professional">更专业</option>
                                        <option value="casual">口语化</option>
                                        <option value="marketing">营销风</option>
                                        <option value="academic">学术风</option>
                                    </select>
                                </div>
                            </div>
                            <div style="display:flex;gap:12px;align-items:center;font-size:12px">
                                <label style="display:flex;align-items:center;gap:3px;cursor:pointer;white-space:nowrap"><input type="checkbox" class="yimai-gso-rw-field" value="title" checked> 标题</label>
                                <label style="display:flex;align-items:center;gap:3px;cursor:pointer;white-space:nowrap"><input type="checkbox" class="yimai-gso-rw-field" value="content" checked> 正文</label>
                                <label style="display:flex;align-items:center;gap:3px;cursor:pointer;white-space:nowrap"><input type="checkbox" class="yimai-gso-rw-field" value="excerpt" checked> 摘要</label>
                                <label style="display:flex;align-items:center;gap:3px;cursor:pointer;white-space:nowrap"><input type="checkbox" class="yimai-gso-rw-field" value="tags" checked> 标签</label>
                            </div>
                            <div style="flex:1;min-width:180px">
                                <input type="text" id="yimai-gso-rw-paste-keywords" placeholder="关键词注入（逗号分隔，可选）" style="width:100%;font-size:12px;padding:2px 8px;height:30px">
                            </div>
                            <button type="button" id="yimai-gso-rw-paste-btn" class="button button-primary" style="white-space:nowrap">
                                <span class="dashicons dashicons-update"></span> 开始改写
                            </button>
                        </div>
                        <div id="yimai-gso-rw-paste-progress" class="yimai-gso-progress" style="display:none;margin-top:8px;">
                            <div class="yimai-gso-progress-bar">
                                <div class="yimai-gso-progress-fill yimai-gso-rw-fill"></div>
                            </div>
                            <p id="yimai-gso-rw-paste-progress-text" style="text-align:center;font-size:12px">AI 改写中...</p>
                        </div>
                    </div>

                    <!-- 左右分栏：原文 | 改写结果 -->
                    <div style="display:flex;gap:16px;min-height:460px">
                        <!-- 左栏：原文输入 -->
                        <div style="flex:1;min-width:0;display:flex">
                            <div class="yimai-gso-panel" style="flex:1;display:flex;flex-direction:column">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                    <label style="font-weight:600">原文内容 <span class="required">*</span></label>
                                    <button type="button" id="yimai-gso-rw-paste-clear" style="font-size:11px;color:#94a3b8;border:none;background:none;cursor:pointer;display:none">✕ 清空</button>
                                </div>
                                <textarea id="yimai-gso-rw-paste-text" class="yimai-gso-rw-textarea" placeholder="在此粘贴需要改写的原文内容，支持任意长度的文字..." style="flex:1;width:100%;resize:vertical;min-height:0"></textarea>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
                                    <button type="button" id="yimai-gso-rw-paste-sample" style="font-size:11px;color:#64748b;border:none;background:#f1f5f9;padding:4px 10px;border-radius:4px;cursor:pointer">填入示例</button>
                                    <div style="font-size:11px;color:#94a3b8">
                                        <span id="yimai-gso-rw-paste-count">0 字</span>
                                        <span id="yimai-gso-rw-paste-warn" style="color:#f59e0b;display:none;margin-left:8px">⚠超8000字将被截断</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 右栏：改写结果 -->
                        <div style="flex:1;min-width:0;display:flex">
                            <div id="yimai-gso-rw-paste-result" class="yimai-gso-panel" style="flex:1;display:flex;flex-direction:column">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-shrink:0">
                                    <h3 style="margin:0;font-size:14px;">&#9998; 改写结果</h3>
                                    <span id="yimai-gso-rw-result-stats" style="font-size:11px;color:#94a3b8;display:none"></span>
                                </div>
                                <!-- 内容内栏（带边框） -->
                                <div id="yimai-gso-rw-paste-result-content" style="flex:1;overflow-y:auto;min-height:200px;border:1px solid #e2e8f0;border-radius:6px;padding:12px;background:#fafbfc;font-size:13px;line-height:1.7;color:#94a3b8;text-align:center">
                                    点击【开始改写】后，结果将在此处并排展示
                                </div>
                                <div id="yimai-gso-rw-paste-actions" style="display:none;flex-shrink:0;margin-top:8px;padding-top:8px;border-top:1px solid #e2e8f0">
                                    <button class="button yimai-gso-rw-copy-btn">📋 复制全文</button>
                                    <button class="button button-primary yimai-gso-rw-draft-btn">📝 存草稿</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== 子面板2：单篇文章翻新 ===== -->
                <div class="yimai-gso-rewrite-panel" data-rtab="single">
                    <!-- 改写选项 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <div class="yimai-gso-rewrite-options">
                            <div class="yimai-gso-rewrite-option-item">
                                <label>改写力度</label>
                                <select id="yimai-gso-rw-single-strength">
                                    <option value="light">轻度 — 微调措辞</option>
                                    <option value="medium" selected>中度 — 重组优化</option>
                                    <option value="deep">深度 — 全面重写</option>
                                </select>
                            </div>
                        </div>
                        <label class="yimai-gso-toggle-label" style="margin-top:12px;">
                            <input type="checkbox" id="yimai-gso-rw-single-overwrite">
                            <span class="yimai-gso-toggle-switch"></span>
                            <span class="yimai-gso-toggle-text">直接覆盖原文章（不勾选则仅预览，确认后再覆盖）</span>
                        </label>
                        <button type="button" id="yimai-gso-rw-single-btn" class="button button-primary yimai-gso-generate-btn" style="margin-top:16px;" disabled>
                            <span class="dashicons dashicons-update"></span> 翻新所选文章
                        </button>
                        <div id="yimai-gso-rw-single-progress" class="yimai-gso-progress" style="display:none;margin-top:16px;">
                            <div class="yimai-gso-progress-bar">
                                <div class="yimai-gso-progress-fill yimai-gso-rw-fill"></div>
                            </div>
                            <p id="yimai-gso-rw-single-progress-text">AI 改写中...</p>
                        </div>
                    </div>

                    <!-- 搜索 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <div class="yimai-gso-rewrite-search-row">
                            <input type="search" id="yimai-gso-rw-single-search" class="large-text" placeholder="搜索文章标题..." style="flex:1;">
                            <select id="yimai-gso-rw-single-category">
                                <option value="0">全部分类</option>
                                <?php foreach ( $categories_list as $cat ) : ?>
                                <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="yimai-gso-rw-single-search-btn" class="button button-primary">&#128269; 搜索</button>
                        </div>
                        <div id="yimai-gso-rw-single-results" class="yimai-gso-br-results" style="margin-top:12px;">
                            <div class="yimai-gso-empty"><p>搜索文章后将在下方显示结果</p></div>
                        </div>
                        <div id="yimai-gso-rw-single-pagination" style="display:none;" class="yimai-gso-post-pagination"></div>
                    </div>

                    <!-- 改写结果 -->
                    <div id="yimai-gso-rw-single-result" class="yimai-gso-panel" style="display:none;">
                        <h3 style="margin:0 0 16px;font-size:15px;">&#9998; 改写结果</h3>
                        <div id="yimai-gso-rw-single-result-content"></div>
                    </div>
                </div>

                <!-- ===== 子面板3：批量翻新改写 ===== -->
                <div class="yimai-gso-rewrite-panel" data-rtab="batch">
                    <!-- 改写选项 + 开始 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;<?php echo empty( $batch_rw_meta['available'] ) ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
                        <div class="yimai-gso-rewrite-options">
                            <div class="yimai-gso-rewrite-option-item">
                                <label>改写力度（统一应用于所有选中文章）</label>
                                <select id="yimai-gso-rw-batch-strength">
                                    <option value="light">轻度 — 微调措辞</option>
                                    <option value="medium" selected>中度 — 重组优化</option>
                                    <option value="deep">深度 — 全面重写</option>
                                </select>
                            </div>
                        </div>
                        <div class="yimai-gso-detect-flow-card">
                            <div class="yimai-gso-detect-flow-title">覆盖策略</div>
                            <div class="yimai-gso-detect-flow-desc">批量翻新改写好之后将直接覆盖原文章，并在改写日志中记录。每处理一篇实时更新进度。</div>
                        </div>
                        <button type="button" id="yimai-gso-rw-batch-start-btn" class="button button-primary button-hero yimai-gso-generate-btn" <?php echo empty( $batch_rw_meta['available'] ) ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-update"></span> 开始批量改写
                        </button>

                        <!-- 进度 -->
                        <div id="yimai-gso-rw-batch-progress" class="yimai-gso-progress" style="display:none;margin-top:16px;">
                            <div class="yimai-gso-progress-bar">
                                <div id="yimai-gso-rw-batch-fill" class="yimai-gso-progress-fill"></div>
                            </div>
                            <p id="yimai-gso-rw-batch-progress-text">准备中...</p>
                            <div id="yimai-gso-rw-batch-stats" style="display:flex;gap:20px;margin-top:8px;font-size:13px;"></div>
                        </div>

                        <!-- 日志 -->
                        <div id="yimai-gso-rw-batch-logs" style="display:none;margin-top:16px;max-height:300px;overflow-y:auto;"></div>
                    </div>

                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <!-- 搜索 — 搜索功能始终可用，不受锁定影响 -->
                        <div class="yimai-gso-rewrite-search-row">
                            <input type="search" id="yimai-gso-rw-batch-search" class="large-text" placeholder="搜索文章标题..." style="flex:1;">
                            <select id="yimai-gso-rw-batch-category">
                                <option value="0">全部分类</option>
                                <?php foreach ( $categories_list as $cat ) : ?>
                                <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="yimai-gso-rw-batch-status">
                                <option value="any">全部状态</option>
                                <option value="publish" selected>已发布</option>
                                <option value="draft">草稿</option>
                            </select>
                            <button type="button" id="yimai-gso-rw-batch-search-btn" class="button button-primary">&#128269; 搜索</button>
                        </div>

                        <!-- 已选摘要 -->
                        <div class="yimai-gso-batch-rewrite-selected-header" style="margin-top:12px;">
                            <h3 style="margin:0;">已选文章 <span id="yimai-gso-rw-batch-count" class="yimai-gso-badge">0</span></h3>
                            <button type="button" id="yimai-gso-rw-batch-clear-btn" class="button button-small" style="display:none;">清空选择</button>
                        </div>
                        <div id="yimai-gso-rw-batch-selected" class="yimai-gso-br-selected-list" style="margin-bottom:12px;">
                            <p style="color:#999;margin:8px 0;">从下方搜索并勾选需要翻新改写的文章</p>
                        </div>

                        <!-- 文章列表 -->
                        <div id="yimai-gso-rw-batch-results" class="yimai-gso-br-results">
                            <div class="yimai-gso-empty"><p>搜索文章后将在下方显示结果</p></div>
                        </div>
                        <div id="yimai-gso-rw-batch-pagination" style="display:none;" class="yimai-gso-post-pagination"></div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签3：批量生成 ==================== -->
            <?php $batch_feature_meta = $this->get_feature_meta( 'batch_generate' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'generate' ? 'active' : ''; ?>" data-tab="generate">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-edit-large"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">批量生成</h2>
                        <p class="yimai-gso-card-desc">批量输入标题自动生成多篇文章，支持存为草稿、直接发布或定时发布</p>
                    </div>
                </div>

                <div class="yimai-gso-panel" style="margin-bottom:16px;border-left:4px solid #3b82f6;background:#eff6ff;">
                    <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;">
                        <span class="dashicons dashicons-info-outline" style="color:#3b82f6;margin-right:4px;vertical-align:text-bottom;"></span>
                        生成的文章质量跟大模型有关，建议用 DeepSeek 模型，其它免费的模型都是有限制的，生成出来的文章效果不是很好。可以自己多个模型测试一下，哪个好你就用哪个。
                    </p>
                </div>
                <!-- 步骤条：批量标题 → 批量文章 -->
                <div class="yimai-gso-batch-tabs">
                    <button type="button" class="yimai-gso-batch-tab active" data-step="1">
                        <span class="dashicons dashicons-edit-large" style="font-size:18px;width:18px;height:18px;"></span>
                        批量标题
                    </button>
                    <button type="button" class="yimai-gso-batch-tab" data-step="2">
                        <span class="dashicons dashicons-media-text" style="font-size:18px;width:18px;height:18px;"></span>
                        批量文章
                    </button>
                </div>


                <!-- 步骤1：选题 -->
                <div class="yimai-gso-step-content active" data-step="1">
                    <!-- 批量标题设置（整宽）-->
                    <div class="yimai-gso-panel" style="margin-bottom:18px;">
                        <h2>📝 批量标题设置</h2>
                        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
                            <div class="yimai-gso-form-group" style="flex:1;min-width:250px;margin-bottom:0;">
                                <label>行业关键词 / 主题 <span class="required">*</span></label>
                                <input type="text" id="yimai-gso-titles-keyword" class="large-text"
                                    placeholder="例如：自媒体、短视频、AI创业、电商运营">
                                <small>输入你的行业领域或内容主题，AI 会生成相关的热门标题</small>
                            </div>

                            <div class="yimai-gso-form-group" style="width:150px;margin-bottom:0;">
                                <label>生成数量</label>
                                <select id="yimai-gso-titles-count">
                                    <option value="10">10 个标题</option>
                                    <option value="20" selected>20 个标题</option>
                                    <option value="30">30 个标题</option>
                                    <option value="50">50 个标题</option>
                                </select>
                            </div>

                            <div class="yimai-gso-form-group" style="width:auto;margin-bottom:0;">
                                <button id="yimai-gso-titles-generate-btn" class="button button-primary yimai-gso-generate-btn">
                                    <span class="dashicons dashicons-spell-check"></span>
                                    生成热门标题
                                </button>
                            </div>
                        </div>

                        <div id="yimai-gso-titles-progress" class="yimai-gso-progress" style="display:none;margin-top:16px;">
                            <div class="yimai-gso-progress-bar">
                                <div class="yimai-gso-progress-fill yimai-gso-titles-fill"></div>
                            </div>
                            <p id="yimai-gso-titles-progress-text">准备中...</p>
                        </div>
                    </div>

                    <!-- 双栏：生成历史 | 标题生成结果 -->
                    <div class="yimai-gso-layout" style="display:flex;gap:18px;">
                        <!-- 左侧：生成历史 -->
                        <div class="yimai-gso-panel" style="width:300px;flex-shrink:0;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                <h3 style="margin:0;font-size:14px;color:#64748b;">📜 生成历史</h3>
                                <button id="yimai-gso-step1-history-clear" class="button button-small" style="font-size:11px;padding:2px 8px;">清空</button>
                            </div>
                            <div id="yimai-gso-step1-titles-history" style="max-height:400px;overflow-y:auto;">
                                <div class="yimai-gso-empty" style="padding:12px 0;">
                                    <p style="font-size:12px;color:#94a3b8;">暂无历史记录</p>
                                </div>
                            </div>
                        </div>

                        <!-- 右侧：标题生成结果 -->
                        <div class="yimai-gso-panel" style="flex:1;">
                            <div class="yimai-gso-titles-toolbar">
                                <h2>标题生成结果 <span id="yimai-gso-step1-titles-counter" class="yimai-gso-batch-counter" style="display:none;">0</span></h2>
                                <div class="yimai-gso-titles-actions">
                                    <span class="yimai-gso-selected-info">已选择 <span id="yimai-gso-step1-titles-selected-count">0</span> 个</span>
                                    <button id="yimai-gso-step1-titles-select-all" class="button button-small">全选</button>
                                    <button id="yimai-gso-step1-titles-deselect-all" class="button button-small">取消</button>
                                    <button id="yimai-gso-step1-go-batch" class="button button-primary button-small">
                                        <span class="dashicons dashicons-controls-forward" style="font-size:14px;width:14px;height:14px;"></span>
                                        进入批量文章
                                    </button>
                                </div>
                            </div>
                            
                            <div id="yimai-gso-step1-titles-results" class="yimai-gso-titles-list-container" style="max-height:400px;overflow-y:auto;">
                                <div class="yimai-gso-empty">
                                    <p>✨ 输入关键词，点击「生成热门标题」<br>从生成结果中勾选标题后，直接进入批量文章配置</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 步骤2：批量文章 -->
                <div class="yimai-gso-step-content" data-step="2">

                    <!-- 批量文章配置（整宽）-->
                    <div class="yimai-gso-panel" style="margin-bottom:18px;">
                        <h2>📦 批量文章配置</h2>

                        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">
                            <div class="yimai-gso-form-group" style="flex:1;min-width:200px;">
                                <label>统一标签设置</label>
                                <input type="text" id="yimai-gso-batch-tags" class="large-text"
                                    placeholder="留空则仅使用 AI 自动标签" value="">
                            </div>

                            <div class="yimai-gso-form-group" style="width:120px;">
                                <label>每篇字数</label>
                                <select id="yimai-gso-batch-word-count">
                                    <option value="1000" <?php selected( $word_count, 1000 ); ?>>1000 字</option>
                                    <option value="1500">1500 字</option>
                                    <option value="2000">2000 字</option>
                                    <option value="3000">3000 字</option>
                                    <option value="4000">4000 字</option>
                                    <option value="5000">5000 字</option>
                                </select>
                            </div>

                            <div class="yimai-gso-form-group" style="width:100px;">
                                <label>每篇配图</label>
                                <select id="yimai-gso-batch-image-count">
                                    <option value="0">不配图</option>
                                    <option value="1" <?php selected( $image_count, 1 ); ?>>1 张</option>
                                    <option value="2">2 张</option>
                                    <option value="3">3 张</option>
                                    <option value="5">5 张</option>
                                </select>
                            </div>

                            <div class="yimai-gso-form-group" style="width:150px;">
                                <label>文章分类</label>
                                <select id="yimai-gso-batch-category">
                                    <?php foreach ( $categories as $cat ) : ?>
                                        <option value="<?php echo $cat->term_id; ?>" <?php selected( $default_category, $cat->term_id ); ?>>
                                            <?php echo esc_html( $cat->name );?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;margin-top:12px;">
                            <div class="yimai-gso-form-group" style="width:auto;">
                                <label class="yimai-gso-toggle-label" id="yimai-gso-batch-ai-tags-wrap">
                                    <input type="checkbox" id="yimai-gso-batch-ai-tags" checked>
                                    <span class="yimai-gso-toggle-switch"></span>
                                    <span class="yimai-gso-toggle-text">AI 自动生成标签</span>
                                </label>
                            </div>
                        </div>

                        <div class="yimai-gso-detect-flow-card" style="margin:16px 0;">
                            <div class="yimai-gso-detect-flow-title">发布设置</div>
                            <div class="yimai-gso-detect-flow-desc">在这一页直接确定草稿、直接发布或定时发布。选择定时发布时，系统会按起始时间和发布间隔自动排程。</div>
                        </div>

                        <?php $this->render_batch_schedule_controls(); ?>

                        <div class="yimai-gso-action-bar" style="margin-top:16px;">
                            <button type="button" id="yimai-gso-batch-start-btn" class="button button-primary button-hero yimai-gso-generate-btn">
                                <span class="dashicons dashicons-controls-forward"></span>
                                开始批量生成
                            </button>

                            <button type="button" id="yimai-gso-batch-stop-btn" class="button button-hero yimai-gso-stop-btn" style="display:none;">
                                <span class="dashicons dashicons-sticky"></span>
                                停止批量生成
                            </button>
                        </div>

                        <div id="yimai-gso-batch-progress" class="yimai-gso-progress" style="display:none;margin-top:16px;">
                            <div class="yimai-gso-progress-bar">
                                <div class="yimai-gso-progress-fill yimai-gso-batch-fill"></div>
                            </div>
                            <p id="yimai-gso-batch-progress-text">等待开始批量生成...</p>
                        </div>
                    </div>

                    <!-- 双栏：提示词/标题列表 | 生成结果 -->
                    <div class="yimai-gso-layout" style="display:flex;gap:18px;">
                        <!-- 左侧：提示词/标题列表（可编辑） -->
                        <div class="yimai-gso-panel" style="width:350px;flex-shrink:0;">
                            <div class="yimai-gso-titles-toolbar">
                                <h2>📝 提示词 / 标题列表</h2>
                            </div>
                            <textarea id="yimai-gso-batch-prompts" class="yimai-gso-batch-prompts-textarea" rows="15" style="width:100%;min-height:350px;max-height:400px;overflow-y:auto;padding:10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;line-height:1.6;resize:vertical;" placeholder="每行一个提示词或标题...&#10;输入后会自动保存到本地存储&#10;&#10;提示：从步骤1导入的标题会显示在这里，可直接编辑"></textarea>
                            <p style="font-size:12px;color:#94a3b8;margin-top:8px;">💡 提示：每行一个标题或提示词，支持直接编辑。从步骤1导入的标题也会显示在这里。</p>
                        </div>

                        <!-- 右侧：生成结果 -->
                        <div class="yimai-gso-panel" style="flex:1;">
                            <div class="yimai-gso-titles-toolbar">
                                <h2>📊 生成结果 <span id="yimai-gso-batch-counter" class="yimai-gso-batch-counter" style="display:none;">0/0</span></h2>
                            </div>
                            <div class="yimai-gso-result-box success" style="margin-bottom:16px;">
                                当前执行链路：生成文章 → 配图与标签 → 发布 / 定时发布。开始后会在下方实时追加每篇结果。
                            </div>

                            <div id="yimai-gso-batch-results" style="max-height:400px;overflow-y:auto;">
                                <div class="yimai-gso-empty">
                                    <p>在左侧完成批量文章配置后，点击「开始批量生成」即可在这里查看实时结果。</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>




            </div>

            <?php $this->render_seo_meta_panel( $active_tab ); ?>
            <?php $this->render_internal_links_panel( $active_tab ); ?>
            <?php $this->render_detection_panel( $active_tab ); ?>
            <?php $this->render_image_alt_panel( $active_tab ); ?>

            <?php $this->render_autopilot_panel( $active_tab ); ?>
            <?php $this->render_llms_txt_panel( $active_tab ); ?>
            <?php $this->render_geo_audit_panel( $active_tab ); ?>
            <?php $this->render_seo_geo_bridge_panel( $active_tab ); ?>

            <!-- ==================== 标签：AI摘要 ==================== -->
            <?php $excerpt_meta = $this->get_feature_meta( 'batch_excerpt' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'ai-excerpt' ? 'active' : ''; ?>" data-tab="ai-excerpt">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-editor-paragraph"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">AI智能摘要</h2>
                        <p class="yimai-gso-card-desc">自动为文章生成搜索引擎摘要描述，优化搜索结果片段展示，提升点击率</p>
                    </div>
                </div>



                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <p style="margin:0 0 8px;color:#64748b;font-size:13px;">
                        为没有摘要的文章自动生成 SEO 友好的智能摘要，同步写入 Yoast / Rank Math / 子比主题的 SEO 描述字段。
                    </p>
                </div>

                <!-- 搜索和筛选区 -->
                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <div class="yimai-gso-rewrite-search-row">
                        <input type="search" id="yimai-gso-ex-search" class="large-text" placeholder="搜索文章标题...">
                        <select id="yimai-gso-ex-category">
                            <option value="0">全部分类</option>
                            <?php foreach ( $this->get_categories_list('raw') as $cat ) : ?>
                                <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="yimai-gso-ex-status">
                            <option value="any">全部状态</option>
                            <option value="publish">已发布</option>
                            <option value="draft">草稿</option>
                            <option value="future">定时发布</option>
                        </select>
                        <select id="yimai-gso-ex-filter">
                            <option value="empty">无摘要</option>
                            <option value="has">有摘要</option>
                            <option value="all">全部</option>
                        </select>
                        <button type="button" id="yimai-gso-ex-search-btn" class="button button-primary">搜索</button>
                    </div>
                </div>

                <!-- 已选文章列表 -->
                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <div class="yimai-gso-batch-rewrite-selected-header">
                        <h3 style="margin:0;">已选文章 <span id="yimai-gso-ex-selected-count" class="yimai-gso-badge">0</span></h3>
                        <button type="button" id="yimai-gso-ex-clear-btn" class="button button-small" style="display:none;">清空选择</button>
                    </div>
                    <div id="yimai-gso-ex-selected-list" class="yimai-gso-br-selected-list">
                        <p style="color:#999;margin:8px 0;">从下方搜索并勾选需要生成摘要的文章</p>
                    </div>
                </div>

                <!-- 搜索结果 -->
                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <h3 style="margin:0 0 12px;">文章列表</h3>
                    <div id="yimai-gso-ex-results" class="yimai-gso-br-results">
                        <div class="yimai-gso-empty"><p>输入关键词搜索文章，或直接点击搜索查看无摘要文章</p></div>
                    </div>
                    <div id="yimai-gso-ex-pagination" style="display:none;" class="yimai-gso-post-pagination"></div>
                </div>

                <!-- 开始按钮 -->
                <button type="button" id="yimai-gso-ex-start-btn" class="button button-primary button-hero yimai-gso-generate-btn" <?php echo ( empty( $excerpt_meta['available'] ) || ! empty( $excerpt_meta['readonly'] ) ) ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-editor-paragraph" style="font-size:18px;width:18px;height:18px;margin:0 6px 0 0;vertical-align:middle;"></span>
                    批量生成AI摘要
                </button>

                <!-- 进度区域 -->
                <div id="yimai-gso-ex-progress" class="yimai-gso-progress" style="display:none;margin-top:16px;">
                    <div class="yimai-gso-progress-bar">
                        <div id="yimai-gso-ex-progress-fill" class="yimai-gso-progress-fill"></div>
                    </div>
                    <p id="yimai-gso-ex-progress-text">准备中...</p>
                </div>

                <!-- 结果区域 -->
                <div id="yimai-gso-ex-results-area" style="display:none;margin-top:20px;"></div>
            </div>

            <!-- ==================== 标签5：内容管理 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'history' ? 'active' : ''; ?>" data-tab="history">

                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-list-view"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">内容管理</h2>
                        <p class="yimai-gso-card-desc">查看和管理所有 AI 生成的文章，支持筛选、编辑和批量操作</p>
                    </div>
                </div>

                <!-- 统计卡片 -->
                <div class="yimai-gso-dashboard-stats" id="yimai-gso-history-stats">
                    <div class="yimai-gso-stat-card yimai-gso-stat-primary">
                        <div class="yimai-gso-stat-icon">📊</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="total">-</div>
                            <div class="yimai-gso-stat-label">总文章</div>
                        </div>
                    </div>
                    <div class="yimai-gso-stat-card yimai-gso-stat-success">
                        <div class="yimai-gso-stat-icon">✅</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="published">-</div>
                            <div class="yimai-gso-stat-label">已发布</div>
                        </div>
                    </div>
                    <div class="yimai-gso-stat-card yimai-gso-stat-info">
                        <div class="yimai-gso-stat-icon">⏰</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="scheduled">-</div>
                            <div class="yimai-gso-stat-label">定时发布</div>
                        </div>
                    </div>
                    <div class="yimai-gso-stat-card yimai-gso-stat-warning">
                        <div class="yimai-gso-stat-icon">📝</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="drafts">-</div>
                            <div class="yimai-gso-stat-label">草稿</div>
                        </div>
                    </div>
                </div>

                <!-- 筛选区 -->
                <div class="yimai-gso-history-filters">
                    <div class="yimai-gso-filter-row">
                        <select id="yimai-gso-h-status">
                            <option value="">全部状态</option>
                            <option value="publish">已发布</option>
                            <option value="future">定时发布</option>
                            <option value="draft">草稿</option>
                        </select>
                        <select id="yimai-gso-h-source">
                            <option value="">全部来源</option>
                            <option value="batch">批量生成</option>
                            <option value="manual">手动发布</option>
                        </select>
                        <input type="date" id="yimai-gso-h-date-from" class="yimai-gso-date-input" placeholder="开始日期">
                        <input type="date" id="yimai-gso-h-date-to" class="yimai-gso-date-input" placeholder="结束日期">
                        <input type="search" id="yimai-gso-h-search" class="yimai-gso-search-input" placeholder="搜索标题...">
                    </div>
                    <div class="yimai-gso-filter-actions">
                        <button id="yimai-gso-h-search-btn" class="button">🔍 搜索</button>
                        <button id="yimai-gso-h-reset-btn" class="button">重置</button>
                    </div>
                </div>

                <!-- 文章列表 -->
                <div class="yimai-gso-history-list" id="yimai-gso-history-list">
                    <div class="yimai-gso-loading-state">
                        <span class="yimai-gso-loading"></span>加载中...
                    </div>
                </div>

                <!-- 分页 -->
                <div class="yimai-gso-history-pagination" id="yimai-gso-history-pagination"></div>

                <!-- 详情弹窗 -->
                <div id="yimai-gso-history-modal" class="yimai-gso-modal" style="display:none;">
                    <div class="yimai-gso-modal-content">
                        <div class="yimai-gso-modal-header">
                            <h2>文章详情</h2>
                            <button class="yimai-gso-modal-close">&times;</button>
                        </div>
                        <div class="yimai-gso-modal-body" id="yimai-gso-modal-body"></div>
                    </div>
                </div>

                <!-- 换图选择弹窗：列出文章全部配图，支持单张换/一键全换 -->
                <div id="yimai-gso-image-picker-modal" class="yimai-gso-modal" style="display:none;">
                    <div class="yimai-gso-modal-content">
                        <div class="yimai-gso-modal-header">
                            <h2>选择要更换的图片</h2>
                            <button class="yimai-gso-modal-close">&times;</button>
                        </div>
                        <div class="yimai-gso-modal-body" id="yimai-gso-image-picker-body"></div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：结构化数据 ==================== -->
            <?php $schema_meta = $this->get_feature_meta( 'schema_generator' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'schema' ? 'active' : ''; ?>" data-tab="schema">
                <div id="yimai-gso-schema-root"
                    data-available="<?php echo ! empty( $schema_meta['available'] ) ? '1' : '0'; ?>"
                    data-readonly="<?php echo ! empty( $schema_meta['readonly'] ) ? '1' : '0'; ?>">
                    <div class="yimai-gso-page-card-header">
                        <div class="yimai-gso-card-icon"><span class="dashicons dashicons-code-standards"></span></div>
                        <div class="yimai-gso-card-body">
                            <h2 class="yimai-gso-card-title">结构化数据</h2>
                            <p class="yimai-gso-card-desc">自动生成 Schema.org JSON-LD 结构化数据（Article/FAQ/HowTo/Breadcrumb），获得搜索结果富摘要</p>
                        </div>
                    </div>

                    <!-- 统计卡片 -->
                    <div class="yimai-gso-seo-score-stats-row">
                        <div class="yimai-gso-seo-score-stat-card">
                            <div class="yimai-gso-seo-score-stat-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                                <span class="dashicons dashicons-code-standards"></span>
                            </div>
                            <div class="yimai-gso-seo-score-stat-body">
                                <div class="yimai-gso-seo-score-stat-value" id="yimai-gso-schema-total">-</div>
                                <div class="yimai-gso-seo-score-stat-label">已生成Schema</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-score-stat-card">
                            <div class="yimai-gso-seo-score-stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a)">
                                <span class="dashicons dashicons-media-text"></span>
                            </div>
                            <div class="yimai-gso-seo-score-stat-body">
                                <div class="yimai-gso-seo-score-stat-value" id="yimai-gso-schema-article">-</div>
                                <div class="yimai-gso-seo-score-stat-label">Article</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-score-stat-card">
                            <div class="yimai-gso-seo-score-stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">
                                <span class="dashicons dashicons-editor-help"></span>
                            </div>
                            <div class="yimai-gso-seo-score-stat-body">
                                <div class="yimai-gso-seo-score-stat-value" id="yimai-gso-schema-faq">-</div>
                                <div class="yimai-gso-seo-score-stat-label">FAQPage</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-score-stat-card">
                            <div class="yimai-gso-seo-score-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                                <span class="dashicons dashicons-list-view"></span>
                            </div>
                            <div class="yimai-gso-seo-score-stat-body">
                                <div class="yimai-gso-seo-score-stat-value" id="yimai-gso-schema-howto">-</div>
                                <div class="yimai-gso-seo-score-stat-label">HowTo</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-score-stat-card">
                            <div class="yimai-gso-seo-score-stat-icon" style="background:linear-gradient(135deg,#ec4899,#db2777)">
                                <span class="dashicons dashicons-cart"></span>
                            </div>
                            <div class="yimai-gso-seo-score-stat-body">
                                <div class="yimai-gso-seo-score-stat-value" id="yimai-gso-schema-product">-</div>
                                <div class="yimai-gso-seo-score-stat-label">Product</div>
                            </div>
                        </div>
                    </div>

                    <!-- 设置区域 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <h3 style="margin:0 0 16px;">Schema 类型开关</h3>
                        <div class="yimai-gso-schema-type-toggles">
                            <label class="yimai-gso-schema-toggle">
                                <input type="checkbox" name="schema_type_article" value="Article" checked>
                                <span class="yimai-gso-schema-toggle-label">Article (文章)</span>
                                <small>所有文章自动生成基本信息+作者+时间</small>
                            </label>
                            <label class="yimai-gso-schema-toggle">
                                <input type="checkbox" name="schema_type_faq" value="FAQPage" checked>
                                <span class="yimai-gso-schema-toggle-label">FAQPage (问答)</span>
                                <small>检测到问答结构时自动生成FAQ Schema</small>
                            </label>
                            <label class="yimai-gso-schema-toggle">
                                <input type="checkbox" name="schema_type_howto" value="HowTo" checked>
                                <span class="yimai-gso-schema-toggle-label">HowTo (教程)</span>
                                <small>检测到步骤型内容时自动生成HowTo Schema</small>
                            </label>
                            <label class="yimai-gso-schema-toggle">
                                <input type="checkbox" name="schema_type_product" value="Product" checked>
                                <span class="yimai-gso-schema-toggle-label">Product (产品)</span>
                                <small>检测到产品/价格关键词时自动生成Product Schema，支持Google产品富摘要</small>
                            </label>
                            <label class="yimai-gso-schema-toggle">
                                <input type="checkbox" name="schema_type_breadcrumb" value="BreadcrumbList" checked>
                                <span class="yimai-gso-schema-toggle-label">BreadcrumbList (面包屑)</span>
                                <small>生成面包屑导航结构化数据</small>
                            </label>
                        </div>
                        <div style="margin-top:16px;">
                            <button type="button" id="yimai-gso-schema-save-btn" class="button button-primary">保存设置</button>
                        </div>
                    </div>

                    <!-- 操作按钮 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <h3 style="margin:0 0 12px;">批量操作</h3>
                        <p style="color:#64748b;font-size:13px;margin:0 0 12px;">对已发布的文章重新检测 Schema 类型（FAQ/HowTo/Article），不会影响已生成的文章内容</p>
                        <button type="button" id="yimai-gso-schema-rebuild-btn" class="yimai-gso-btn-modern-primary">
                            <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;margin:0 4px 0 0;vertical-align:middle;"></span>
                            重新检测所有文章 Schema 类型
                        </button>
                        <div id="yimai-gso-schema-rebuild-result" style="margin-top:12px;"></div>
                    </div>

                    <!-- 说明 -->
                    <div class="yimai-gso-panel">
                        <h3 style="margin:0 0 12px;">什么是结构化数据？</h3>
                        <div style="color:#475569;font-size:13px;line-height:1.8;">
                            <p style="margin:0 0 8px;">结构化数据(Structured Data)是一种让搜索引擎更好理解你网页内容的标准格式。通过 JSON-LD 标记，你的文章可以获得：</p>
                            <ul style="margin:0 0 8px;padding-left:20px;">
                                <li><strong>富摘要(Rich Snippets)</strong> - 在搜索结果中显示评分、FAQ折叠、步骤列表等</li>
                                <li><strong>知识图谱</strong> - 帮助搜索引擎建立实体关联</li>
                                <li><strong>语音搜索优化</strong> - 提升在语音助手中的展现概率</li>
                            </ul>
                            <p style="margin:0;"><strong>必应</strong>将结构化数据列为 &#11088;&#11088;&#11088;&#11088; 权重因子，<strong>百度</strong>也在逐步加强支持。</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：SEO评分 ==================== -->
            <?php $scorer_meta = $this->get_feature_meta( 'seo_scorer' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'seo-score' ? 'active' : ''; ?>" data-tab="seo-score">
                <div id="yimai-gso-seo-scorer-root"
                    data-available="<?php echo ! empty( $scorer_meta['available'] ) ? '1' : '0'; ?>"
                    data-readonly="<?php echo ! empty( $scorer_meta['readonly'] ) ? '1' : '0'; ?>">
                    <div class="yimai-gso-page-card-header">
                        <div class="yimai-gso-card-icon"><span class="dashicons dashicons-chart-bar"></span></div>
                        <div class="yimai-gso-card-body">
                            <h2 class="yimai-gso-card-title">SEO 综合评分</h2>
                            <p class="yimai-gso-card-desc">对已发布文章进行 9 维度 SEO 健康度评分（0-100分），发现优化机会并给出改进建议</p>
                        </div>
                    </div>

                    <!-- 搜索和筛选 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <div class="yimai-gso-rewrite-search-row">
                            <input type="search" id="yimai-gso-ss-search" class="large-text" placeholder="搜索文章标题...">
                            <select id="yimai-gso-ss-category">
                                <option value="0">全部分类</option>
                                <?php foreach ( $this->get_categories_list('raw') as $cat ) : ?>
                                    <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="yimai-gso-ss-status">
                                <option value="publish">已发布</option>
                                <option value="any">全部状态</option>
                                <option value="draft">草稿</option>
                            </select>
                            <button type="button" id="yimai-gso-ss-search-btn" class="button button-primary">搜索</button>
                        </div>
                    </div>

                    <!-- 批量操作按钮 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <button type="button" id="yimai-gso-ss-batch-analyze-btn" class="yimai-gso-btn-modern-primary">
                            <span class="dashicons dashicons-chart-bar" style="font-size:16px;width:16px;height:16px;margin:0 4px 0 0;vertical-align:middle;"></span>
                            批量评分所选文章（SEO+GEO）
                        </button>
                        <small style="margin-left:8px;color:#64748b;">勾选需要评分的文章，一键执行双引擎评分</small>
                        <div id="yimai-gso-ss-batch-result" style="margin-top:12px;"></div>
                    </div>

                    <!-- 文章列表 -->
                    <div class="yimai-gso-panel">
                        <table class="widefat striped" id="yimai-gso-ss-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;"><input type="checkbox" id="yimai-gso-ss-check-all"></th>
                                    <th>文章标题</th>
                                    <th style="width:80px;">日期</th>
                                    <th style="width:70px;">SEO评分</th>
                                    <th style="width:55px;">等级</th>
                                    <th style="width:70px;">GEO评分</th>
                                    <th style="width:140px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="yimai-gso-ss-tbody">
                                <tr><td colspan="7"><div class="yimai-gso-empty"><p>搜索文章开始评分</p></div></td></tr>
                            </tbody>
                        </table>
                        <div id="yimai-gso-ss-pagination" style="display:none;" class="yimai-gso-post-pagination"></div>
                    </div>

                    <!-- 单篇分析结果弹窗 - SEO（保留，不变） -->
                    <div id="yimai-gso-ss-detail-modal" style="display:none;">
                        <div class="yimai-gso-ss-detail-overlay"></div>
                        <div class="yimai-gso-ss-detail-content">
                            <div class="yimai-gso-ss-detail-header">
                                <h2 id="yimai-gso-ss-detail-title">SEO评分详情</h2>
                                <button type="button" id="yimai-gso-ss-detail-close" class="button button-small">&times;</button>
                            </div>
                            <div class="yimai-gso-ss-detail-body" id="yimai-gso-ss-detail-body"></div>
                        </div>
                    </div>

                    <!-- 单篇分析结果弹窗 - GEO（新增） -->
                    <div id="yimai-gso-geo-detail-modal" style="display:none;">
                        <div class="yimai-gso-geo-detail-overlay"></div>
                        <div class="yimai-gso-ss-detail-content" style="max-width:680px;">
                            <div class="yimai-gso-ss-detail-header" style="border-bottom-color:#7c3aed;">
                                <h2 id="yimai-gso-geo-detail-title" style="color:#7c3aed;">GEO 评分详情</h2>
                                <button type="button" id="yimai-gso-geo-detail-close" class="button button-small">&times;</button>
                            </div>
                            <div class="yimai-gso-ss-detail-body" id="yimai-gso-geo-detail-body"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：标题分析 ==================== -->
            <?php $heading_meta = $this->get_feature_meta( 'heading_analyzer' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'heading' ? 'active' : ''; ?>" data-tab="heading">
                <div id="yimai-gso-heading-root"
                    data-available="<?php echo ! empty( $heading_meta['available'] ) ? '1' : '0'; ?>"
                    data-readonly="<?php echo ! empty( $heading_meta['readonly'] ) ? '1' : '0'; ?>">
                    <div class="yimai-gso-page-card-header">
                        <div class="yimai-gso-card-icon"><span class="dashicons dashicons-heading"></span></div>
                        <div class="yimai-gso-card-body">
                            <h2 class="yimai-gso-card-title">标题结构分析</h2>
                            <p class="yimai-gso-card-desc">检测文章 H2/H3 标题层级合理性和关键词覆盖率，提供 AI 智能优化建议</p>
                        </div>
                    </div>

                    <!-- 搜索区域 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <div class="yimai-gso-rewrite-search-row">
                            <input type="search" id="yimai-gso-ha-search" class="large-text" placeholder="输入文章标题搜索...">
                            <select id="yimai-gso-ha-category">
                                <option value="0">全部分类</option>
                                <?php foreach ( $this->get_categories_list('raw') as $cat ) : ?>
                                    <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="yimai-gso-ha-search-btn" class="button button-primary">搜索</button>
                            <button type="button" id="yimai-gso-ha-batch-btn" class="button" style="margin-left:8px;">批量分析所选</button>
                        </div>
                    </div>

                    <!-- 文章列表 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <table class="widefat striped" id="yimai-gso-ha-table">
                            <thead>
                                <tr>
                                    <th style="width:36px;"><input type="checkbox" id="yimai-gso-ha-select-all" title="全选/取消全选"></th>
                                    <th>文章标题</th>
                                    <th style="width:70px;">评分</th>
                                    <th style="width:60px;">H2数</th>
                                    <th style="width:70px;">关键词覆盖</th>
                                    <th style="width:100px;">优化状态</th>
                                    <th style="width:130px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="yimai-gso-ha-tbody">
                                <tr><td colspan="7"><div class="yimai-gso-empty"><p>搜索文章开始分析标题结构</p></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 分析结果区域 -->
                    <div class="yimai-gso-panel" id="yimai-gso-ha-result-panel" style="display:none;">
                        <div id="yimai-gso-ha-result"></div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：Canonical URL 管理 ==================== -->
            <?php $canonical_meta = $this->get_feature_meta( 'canonical_manager' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'canonical' ? 'active' : ''; ?>" data-tab="canonical">
                <div id="yimai-gso-canonical-root"
                    data-available="<?php echo ! empty( $canonical_meta['available'] ) ? '1' : '0'; ?>"
                    data-readonly="<?php echo ! empty( $canonical_meta['readonly'] ) ? '1' : '0'; ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'yimai_gso_seo_nonce' ) ); ?>">
                    <div class="yimai-gso-page-card-header">
                        <div class="yimai-gso-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
                        <div class="yimai-gso-card-body">
                            <h2 class="yimai-gso-card-title">Canonical URL 管理</h2>
                            <p class="yimai-gso-card-desc">管理文章 canonical 标签，防止重复内容问题，支持跨站转载自定义 canonical</p>
                        </div>
                    </div>

                    <!-- 统计卡片 -->
                    <div class="yimai-gso-seo-push-stats-row" style="margin-bottom:20px">
                        <div class="yimai-gso-seo-push-stat-card">
                            <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8)">
                                <span class="dashicons dashicons-admin-links"></span>
                            </div>
                            <div class="yimai-gso-seo-push-stat-info">
                                <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-cm-total">-</div>
                                <div class="yimai-gso-seo-push-stat-label">已发布文章</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-push-stat-card">
                            <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#10b981,#059669)">
                                <span class="dashicons dashicons-yes-alt"></span>
                            </div>
                            <div class="yimai-gso-seo-push-stat-info">
                                <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-cm-default">-</div>
                                <div class="yimai-gso-seo-push-stat-label">默认 canonical</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-push-stat-card">
                            <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                                <span class="dashicons dashicons-edit"></span>
                            </div>
                            <div class="yimai-gso-seo-push-stat-info">
                                <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-cm-custom">-</div>
                                <div class="yimai-gso-seo-push-stat-label">自定义 canonical</div>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-push-stat-card">
                            <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9)">
                                <span class="dashicons dashicons-external"></span>
                            </div>
                            <div class="yimai-gso-seo-push-stat-info">
                                <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-cm-external">-</div>
                                <div class="yimai-gso-seo-push-stat-label">跨站转载</div>
                            </div>
                        </div>
                    </div>

                    <!-- 搜索区域 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <div class="yimai-gso-rewrite-search-row">
                            <input type="search" id="yimai-gso-cm-search" class="large-text" placeholder="输入文章标题搜索...">
                            <select id="yimai-gso-cm-category">
                                <option value="0">全部分类</option>
                                <?php foreach ( $this->get_categories_list('raw') as $cat ) : ?>
                                    <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="yimai-gso-cm-filter">
                                <option value="all">全部状态</option>
                                <option value="custom">仅自定义 canonical</option>
                                <option value="none">仅默认 canonical</option>
                            </select>
                            <button type="button" id="yimai-gso-cm-search-btn" class="button button-primary">扫描</button>
                        </div>
                    </div>

                    <!-- 文章列表 -->
                    <div class="yimai-gso-panel" style="margin-bottom:20px;">
                        <table class="widefat striped" id="yimai-gso-cm-table">
                            <thead>
                                <tr>
                                    <th style="width:40%">文章标题</th>
                                    <th style="width:35%">Canonical URL</th>
                                    <th style="width:10%">状态</th>
                                    <th style="width:10%">发布日期</th>
                                    <th style="width:5%">操作</th>
                                </tr>
                            </thead>
                            <tbody id="yimai-gso-cm-tbody">
                                <tr><td colspan="5"><div class="yimai-gso-empty"><p>点击"扫描"开始检查文章的 Canonical URL 状态</p></div></td></tr>
                            </tbody>
                        </table>
                        <div class="yimai-gso-sp-pagination" id="yimai-gso-cm-pagination" style="padding:12px;display:flex;align-items:center;gap:8px;justify-content:center"></div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：404 页面优化 ==================== -->
            <?php $optimizer_404_meta = $this->get_feature_meta( 'seo_push' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'optimizer-404' ? 'active' : ''; ?>" data-tab="optimizer-404">
                <div id="yimai-gso-404-root"
                    data-available="<?php echo ! empty( $optimizer_404_meta['available'] ) ? '1' : '0'; ?>"
                    data-readonly="<?php echo ! empty( $optimizer_404_meta['readonly'] ) ? '1' : '0'; ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'yimai_gso_seo_nonce' ) ); ?>">

                    <!-- ====== 页面标题 ====== -->
                    <div class="yimai-gso-page-card-header">
                        <div class="yimai-gso-card-icon"><span class="dashicons dashicons-warning"></span></div>
                        <div class="yimai-gso-card-body">
                            <h2 class="yimai-gso-card-title">404 页面优化</h2>
                            <p class="yimai-gso-card-desc">检测站内死链、智能推荐重定向、一键修复内容死链、自定义用户友好的404页面</p>
                        </div>
                    </div>

                    <!-- ====== 数据概览卡片 ====== -->
                    <div class="yimai-gso-404-stats-overview">
                        <div class="yimai-gso-404-stat-card yimai-gso-404-stat-dead">
                            <div class="yimai-gso-404-stat-glow"></div>
                            <div class="yimai-gso-404-stat-icon">🔗</div>
                            <div class="yimai-gso-404-stat-body">
                                <div class="yimai-gso-404-stat-value" id="yimai-gso-404-total-links">-</div>
                                <div class="yimai-gso-404-stat-label">待处理死链</div>
                                <div class="yimai-gso-404-stat-delta" id="yimai-gso-404-delta-dead" style="display:none"></div>
                            </div>
                        </div>
                        <div class="yimai-gso-404-stat-card yimai-gso-404-stat-hits">
                            <div class="yimai-gso-404-stat-glow"></div>
                            <div class="yimai-gso-404-stat-icon">👁️</div>
                            <div class="yimai-gso-404-stat-body">
                                <div class="yimai-gso-404-stat-value" id="yimai-gso-404-total-hits">0</div>
                                <div class="yimai-gso-404-stat-label">总访问量</div>
                                <div class="yimai-gso-404-stat-delta" id="yimai-gso-404-delta-hits" style="display:none"></div>
                            </div>
                        </div>
                        <div class="yimai-gso-404-stat-card yimai-gso-404-stat-redirect">
                            <div class="yimai-gso-404-stat-glow"></div>
                            <div class="yimai-gso-404-stat-icon">↗️</div>
                            <div class="yimai-gso-404-stat-body">
                                <div class="yimai-gso-404-stat-value" id="yimai-gso-404-redirect-count">0</div>
                                <div class="yimai-gso-404-stat-label">活跃重定向</div>
                                <div class="yimai-gso-404-stat-delta neutral" id="yimai-gso-404-delta-redirect" style="display:none"></div>
                            </div>
                        </div>
                        <div class="yimai-gso-404-stat-card yimai-gso-404-stat-custom">
                            <div class="yimai-gso-404-stat-glow"></div>
                            <div class="yimai-gso-404-stat-icon">🎨</div>
                            <div class="yimai-gso-404-stat-body">
                                <div class="yimai-gso-404-stat-value" id="yimai-gso-404-custom-status">未启用</div>
                                <div class="yimai-gso-404-stat-label">自定义404页面</div>
                                <div class="yimai-gso-404-stat-delta neutral" id="yimai-gso-404-delta-custom" style="display:none"></div>
                            </div>
                        </div>
                    </div>

                    <!-- ====== 子标签导航 ====== -->
                    <div class="yimai-gso-404-sub-tabs">
                        <button type="button" class="yimai-gso-404-sub-tab active" data-subtab="scan">
                            🔍 死链检测 <span class="yimai-gso-404-tab-count" id="yimai-gso-404-scan-badge" style="display:none"></span>
                        </button>
                        <button type="button" class="yimai-gso-404-sub-tab" data-subtab="redirects">
                            ↗️ 重定向管理 <span class="yimai-gso-404-tab-count" id="yimai-gso-404-redirect-badge" style="display:none"></span>
                        </button>
                        <button type="button" class="yimai-gso-404-sub-tab" data-subtab="custom-page">
                            🎨 自定义404页面
                        </button>
                        <button type="button" class="yimai-gso-404-sub-tab" data-subtab="settings">
                            ⚙️ 设置
                        </button>
                    </div>

                    <!-- ====== 子面板：死链检测 ====== -->
                    <div class="yimai-gso-404-sub-panel" data-subpanel="scan">
                        <!-- 功能提示卡片 -->
                        <div class="yimai-gso-404-tips-row">
                            <div class="yimai-gso-404-tip-card yimai-gso-404-tip-blue">
                                <div class="yimai-gso-404-tip-icon">🔍</div>
                                <h4>双源扫描</h4>
                                <p>同时从404访问日志和文章内链中检测死链，覆盖爬虫与用户访问两条路径</p>
                            </div>
                            <div class="yimai-gso-404-tip-card yimai-gso-404-tip-green">
                                <div class="yimai-gso-404-tip-icon">💡</div>
                                <h4>智能建议</h4>
                                <p>基于4级匹配策略（路径解析→关键词搜索→模糊匹配→热门兜底）推荐最佳重定向目标</p>
                            </div>
                            <div class="yimai-gso-404-tip-card yimai-gso-404-tip-purple">
                                <div class="yimai-gso-404-tip-icon">⚡</div>
                                <h4>一键修复</h4>
                                <p>支持一键创建301重定向、从文章中移除死链保留锚文本、批量应用操作</p>
                            </div>
                        </div>

                        <!-- 筛选栏 -->
                        <div class="yimai-gso-404-filter-bar">
                            <div class="yimai-gso-404-filter-pills">
                                <button type="button" class="yimai-gso-404-filter-pill active yimai-gso-404-source-tab" data-source="logs">📋 404日志</button>
                                <button type="button" class="yimai-gso-404-filter-pill yimai-gso-404-source-tab" data-source="internal">🔗 内链死链</button>
                            </div>
                            <select id="yimai-gso-404-bot-filter" class="yimai-gso-404-filter-select">
                                <option value="all">全部来源</option>
                                <option value="bot">仅爬虫</option>
                                <option value="user">仅用户</option>
                            </select>
                            <select id="yimai-gso-404-time-filter" class="yimai-gso-404-filter-select">
                                <option value="all">全部时间</option>
                                <option value="today">今天</option>
                                <option value="week">近7天</option>
                                <option value="month">近30天</option>
                            </select>
                            <input type="text" id="yimai-gso-404-search" class="yimai-gso-404-filter-search" placeholder="搜索路径或关键词...">
                            <span class="yimai-gso-404-filter-spacer"></span>
                            <button type="button" class="yimai-gso-404-btn" id="yimai-gso-404-export-csv-btn">📥 导出CSV</button>
                            <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-danger" id="yimai-gso-404-clear-btn">🗑️ 清空日志</button>
                            <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-primary" id="yimai-gso-404-scan-btn" <?php disabled( empty( $optimizer_404_meta['available'] ), true ); ?>>
                                🔍 开始扫描
                            </button>
                        </div>

                        <!-- 扫描进度条 -->
                        <div class="yimai-gso-404-scan-progress" id="yimai-gso-404-scan-progress" style="display:none">
                            <div class="yimai-gso-404-scan-progress-bar">
                                <div class="yimai-gso-404-scan-progress-fill" id="yimai-gso-404-scan-fill"></div>
                            </div>
                            <div class="yimai-gso-404-scan-progress-text" id="yimai-gso-404-scan-text"></div>
                        </div>

                        <!-- 死链表格 -->
                        <div class="yimai-gso-404-panel">
                            <div class="yimai-gso-404-panel-body no-padding">
                                <div class="yimai-gso-404-table-wrap">
                                    <table class="yimai-gso-404-data-table" id="yimai-gso-404-table">
                                        <thead><tr>
                                            <th style="width:22%">死链路径</th>
                                            <th style="width:8%">来源</th>
                                            <th style="width:7%">访问量</th>
                                            <th style="width:9%">来源类型</th>
                                            <th style="width:11%">最近访问</th>
                                            <th style="width:18%">推荐重定向</th>
                                            <th style="width:10%">来路</th>
                                            <th style="width:15%">操作</th>
                                        </tr></thead>
                                        <tbody id="yimai-gso-404-tbody">
                                            <tr><td colspan="8"><div class="yimai-gso-404-empty-state"><div class="empty-icon">🔍</div><div class="empty-title">点击「开始扫描」检测站内死链</div></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div id="yimai-gso-404-pagination" class="yimai-gso-404-pagination"></div>
                            </div>
                        </div>
                    </div>

                    <!-- ====== 子面板：重定向管理 ====== -->
                    <div class="yimai-gso-404-sub-panel" data-subpanel="redirects" style="display:none">
                        <div class="yimai-gso-404-alert yimai-gso-404-alert-info">
                            💡 <strong>提示：</strong>应用重定向后，插件会自动尝试写入 <code>.htaccess</code> / Nginx 配置，同时通过 WordPress 内部重定向兜底。<span id="yimai-gso-404-server-status"></span>
                        </div>

                        <div class="yimai-gso-404-filter-bar">
                            <input type="text" id="yimai-gso-404-red-search" class="yimai-gso-404-filter-search" placeholder="搜索重定向规则..." style="width:240px">
                            <span class="yimai-gso-404-filter-spacer"></span>
                            <button type="button" class="yimai-gso-404-btn" id="yimai-gso-404-import-csv-btn">📥 导入CSV</button>
                            <button type="button" class="yimai-gso-404-btn" id="yimai-gso-404-export-red-btn">📤 导出CSV</button>
                            <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-primary" id="yimai-gso-404-red-refresh-btn">🔄 刷新列表</button>
                        </div>

                        <div class="yimai-gso-404-panel">
                            <div class="yimai-gso-404-panel-body no-padding">
                                <div class="yimai-gso-404-table-wrap">
                                    <table class="yimai-gso-404-data-table" id="yimai-gso-404-red-table">
                                        <thead><tr>
                                            <th style="width:8%"><input type="checkbox" id="yimai-gso-404-red-check-all"></th>
                                            <th style="width:24%">来源路径</th>
                                            <th style="width:4%" class="yimai-gso-404-arrow-col"></th>
                                            <th style="width:24%">目标URL</th>
                                            <th style="width:12%">状态</th>
                                            <th style="width:12%">更新时间</th>
                                            <th style="width:16%">操作</th>
                                        </tr></thead>
                                        <tbody id="yimai-gso-404-red-tbody">
                                            <tr><td colspan="7"><div class="yimai-gso-404-empty-state"><div class="empty-icon">↗️</div><div class="empty-title">暂无重定向规则</div><div class="empty-desc">在死链检测中应用建议即可自动创建</div></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="yimai-gso-404-batch-bar" id="yimai-gso-404-batch-bar" style="display:none">
                                    <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-danger" id="yimai-gso-404-red-batch-delete">🗑️ 批量删除选中</button>
                                    <span class="yimai-gso-404-batch-count">已选 <span id="yimai-gso-404-batch-count">0</span> 条</span>
                                </div>
                                <div id="yimai-gso-404-red-pagination" class="yimai-gso-404-pagination"></div>
                            </div>
                        </div>

                        <!-- CSV导入弹窗（隐藏） -->
                        <div id="yimai-gso-404-import-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100001;align-items:center;justify-content:center">
                            <div class="yimai-gso-404-modal-box">
                                <div class="yimai-gso-404-modal-header">
                                    <h3>导入重定向 CSV</h3>
                                    <button type="button" class="yimai-gso-404-modal-close">✕</button>
                                </div>
                                <div class="yimai-gso-404-modal-body">
                                    <p style="color:#64748b;font-size:12px;margin-bottom:12px">格式：来源路径,目标URL[,标题] (每行一条)</p>
                                    <textarea id="yimai-gso-404-import-csv" rows="8" style="width:100%;font-family:monospace;font-size:12px" placeholder="/old-page,/new-page,新页面标题
/article-123,/article-456,替代文章"></textarea>
                                </div>
                                <div class="yimai-gso-404-modal-footer">
                                    <button type="button" class="yimai-gso-404-btn" id="yimai-gso-404-import-cancel">取消</button>
                                    <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-primary" id="yimai-gso-404-import-submit">导入</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ====== 子面板：自定义404页面 ====== -->
                    <div class="yimai-gso-404-sub-panel" data-subpanel="custom-page" style="display:none">
                        <div class="yimai-gso-404-alert yimai-gso-404-alert-success" id="yimai-gso-404-custom-alert" style="display:none">
                            ✅ 自定义404页面已启用。当访客访问不存在的页面时，将显示您自定义的友好页面，而非主题默认的404模板。
                        </div>

                        <div class="yimai-gso-404-custom-layout">
                            <!-- 左侧：操作面板 -->
                            <div class="yimai-gso-404-panel">
                                <div class="yimai-gso-404-panel-header">
                                    <h3>🎨 自定义 404 页面</h3>
                                    <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-primary" id="yimai-gso-404-gen-btn">🔄 生成 404 页面</button>
                                </div>
                                <div class="yimai-gso-404-panel-body">
                                    <p style="font-size:13px;color:#64748b;margin-bottom:20px">
                                        自动生成一个包含搜索框、最新文章列表和分类导航的用户友好404页面。<strong>启用后将完全替换主题的404模板</strong>，并智能推荐与请求URL相关的文章。
                                    </p>

                                    <div class="yimai-gso-404-gen-result" id="yimai-gso-404-gen-result" style="display:none">
                                        <div style="margin-bottom:12px;display:flex;gap:8px">
                                            <button type="button" class="yimai-gso-404-btn" id="yimai-gso-404-copy-btn">📋 复制HTML代码</button>
                                            <a id="yimai-gso-404-preview-link" href="#" target="_blank" class="yimai-gso-404-btn">🔗 预览效果</a>
                                        </div>
                                        <textarea id="yimai-gso-404-html" readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;resize:vertical"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- 右侧：404 页面预览 -->
                            <div>
                                <div class="yimai-gso-404-preview-label">📱 实时预览</div>
                                <div class="yimai-gso-404-preview-card">
                                    <div class="yimai-gso-404-preview-toolbar">
                                        <span class="yimai-gso-404-preview-dot r"></span>
                                        <span class="yimai-gso-404-preview-dot y"></span>
                                        <span class="yimai-gso-404-preview-dot g"></span>
                                        <span class="yimai-gso-404-preview-url"><?php echo esc_html( home_url( '/this-page-does-not-exist' ) ); ?></span>
                                    </div>
                                    <div class="yimai-gso-404-preview-content">
                                        <div class="yimai-gso-404-preview-404">404</div>
                                        <div class="yimai-gso-404-preview-subtitle">页面未找到</div>
                                        <div class="yimai-gso-404-preview-desc">您访问的页面可能已被移动、删除或从未存在</div>
                                        <div class="yimai-gso-404-preview-search">
                                            <input type="text" placeholder="搜索站内内容..." disabled>
                                            <button disabled>搜索</button>
                                        </div>
                                        <div class="yimai-gso-404-preview-links">
                                            <a href="#">← 返回首页</a>
                                            <a href="#">📧 联系我们</a>
                                        </div>
                                        <div class="yimai-gso-404-preview-suggestions">
                                            <div class="yimai-gso-404-preview-suggest-title">您可能正在找</div>
                                            <div class="yimai-gso-404-preview-suggest-item">
                                                <span>热门文章推荐</span>
                                                <span class="yimai-gso-404-preview-suggest-score">92%</span>
                                            </div>
                                            <div class="yimai-gso-404-preview-suggest-item">
                                                <span>相关分类内容</span>
                                                <span class="yimai-gso-404-preview-suggest-score">85%</span>
                                            </div>
                                            <div class="yimai-gso-404-preview-suggest-item">
                                                <span>最新发布文章</span>
                                                <span class="yimai-gso-404-preview-suggest-score">78%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ====== 子面板：设置 ====== -->
                    <div class="yimai-gso-404-sub-panel" data-subpanel="settings" style="display:none">
                        <div class="yimai-gso-404-panel" style="margin-bottom:16px">
                            <div class="yimai-gso-404-panel-header">
                                <h3>⚙️ 功能开关</h3>
                            </div>
                            <div class="yimai-gso-404-panel-body">
                                <div class="yimai-gso-404-setting-card">
                                    <label class="yimai-gso-404-toggle">
                                        <input type="checkbox" id="yimai-gso-404-setting-log" name="log_enabled" <?php checked( YIMAI_GSO_Settings::get( 'optimize_404_log_enabled', 1 ), 1 ); ?>>
                                        <span class="yimai-gso-404-toggle-track"><span class="yimai-gso-404-toggle-thumb"></span></span>
                                    </label>
                                    <div class="yimai-gso-404-setting-info">
                                        <h4>启用 404 访问日志记录</h4>
                                        <p>记录所有404请求的URL、来源、User-Agent和访问时间。日志上限 500 条，超限自动清理最早记录。</p>
                                    </div>
                                </div>

                                <div class="yimai-gso-404-setting-card">
                                    <label class="yimai-gso-404-toggle">
                                        <input type="checkbox" id="yimai-gso-404-setting-custom" name="custom_enabled" <?php checked( YIMAI_GSO_Settings::get( 'optimize_404_custom_enabled', 0 ), 1 ); ?>>
                                        <span class="yimai-gso-404-toggle-track"><span class="yimai-gso-404-toggle-thumb"></span></span>
                                    </label>
                                    <div class="yimai-gso-404-setting-info">
                                        <h4>启用自定义404页面</h4>
                                        <p>用插件生成的友好404页面替换主题默认404模板。包含搜索框、最新文章、分类导航和智能推荐。<span style="color:#f59e0b">⚠ 此操作会 hook <code>template_include</code> 替换主题渲染</span></p>
                                    </div>
                                </div>

                                <div class="yimai-gso-404-setting-card">
                                    <label class="yimai-gso-404-toggle">
                                        <input type="checkbox" id="yimai-gso-404-setting-auto-redirect" name="auto_redirect" <?php checked( YIMAI_GSO_Settings::get( 'optimize_404_auto_redirect', 0 ), 1 ); ?>>
                                        <span class="yimai-gso-404-toggle-track"><span class="yimai-gso-404-toggle-thumb"></span></span>
                                    </label>
                                    <div class="yimai-gso-404-setting-info">
                                        <h4>自动应用重定向建议</h4>
                                        <p>当智能匹配得分 ≥ 80 分时，自动创建 301 重定向并写入服务器配置。<span style="color:#64748b">建议先手动审核后再启用</span></p>
                                    </div>
                                </div>

                                <div style="margin-top:12px">
                                    <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-primary" id="yimai-gso-404-save-settings-btn">💾 保存设置</button>
                                    <span id="yimai-gso-404-settings-msg" style="margin-left:8px;font-size:12px;color:#10b981;display:none">✅ 已保存</span>
                                </div>
                            </div>
                        </div>

                        <div class="yimai-gso-404-panel">
                            <div class="yimai-gso-404-panel-header">
                                <h3>🚫 忽略规则</h3>
                            </div>
                            <div class="yimai-gso-404-panel-body">
                                <p style="font-size:12px;color:#64748b;margin-bottom:12px">
                                    每行一个正则表达式，匹配的URL不会被记录到404日志。适用于 <code>/wp-json/</code>、<code>/wp-admin/</code> 等系统路径。
                                </p>
                                <textarea id="yimai-gso-404-ignore-rules" rows="5" style="width:100%;font-family:monospace;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;resize:vertical"><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'optimize_404_ignore_rules', '' ) ); ?></textarea>
                                <div class="yimai-gso-404-quick-add" style="margin-top:10px">
                                    <span style="font-size:11px;color:#94a3b8;line-height:2">快速添加：</span>
                                    <span class="yimai-gso-404-quick-tag">/wp-json/</span>
                                    <span class="yimai-gso-404-quick-tag">/wp-admin/</span>
                                    <span class="yimai-gso-404-quick-tag">/xmlrpc.php</span>
                                    <span class="yimai-gso-404-quick-tag">/favicon.ico</span>
                                    <span class="yimai-gso-404-quick-tag">/.well-known/</span>
                                    <span class="yimai-gso-404-quick-tag">/\.php$</span>
                                    <span class="yimai-gso-404-quick-tag">/\.map$</span>
                                </div>
                                <div style="margin-top:12px">
                                    <button type="button" class="yimai-gso-404-btn yimai-gso-404-btn-primary" id="yimai-gso-404-save-ignore-btn">💾 保存忽略规则</button>
                                    <span id="yimai-gso-404-ignore-msg" style="margin-left:8px;font-size:12px;color:#10b981;display:none">✅ 已保存</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== 标签：SEO推送 ==================== -->
            <?php $seo_push_meta = $this->get_feature_meta( 'seo_push' ); ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'seo-push' ? 'active' : ''; ?>" data-tab="seo-push">
                <div id="yimai-gso-seo-push-root"
                    data-available="<?php echo ! empty( $seo_push_meta['available'] ) ? '1' : '0'; ?>"
                    data-readonly="<?php echo ! empty( $seo_push_meta['readonly'] ) ? '1' : '0'; ?>">
                <?php
                // 获取当前激活的推送子标签（默认 config）
                $active_push_tab = isset( $_GET['sp_tab'] ) ? sanitize_text_field( $_GET['sp_tab'] ) : 'config';
                ?>
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-share-alt2"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">SEO推送</h2>
                        <p class="yimai-gso-card-desc">管理百度/必应/Google/360 搜索引擎主动推送、Sitemap 生成与推送日志</p>
                    </div>
                </div>
                <div class="yimai-gso-seo-push-header">
                    <div class="yimai-gso-seo-push-header-left" style="display:none">
                        <h1 class="yimai-gso-page-title">
                            <span class="dashicons dashicons-share-alt2"></span>
                            SEO推送设置
                        </h1>
                        <p class="yimai-gso-seo-push-desc">管理搜索引擎主动推送、Sitemap生成与推送日志，加快内容收录</p>
                    </div>
                    <div class="yimai-gso-seo-push-header-right">
                    </div>
                </div>

                <!-- 推送统计卡片 -->
                <div class="yimai-gso-seo-push-stats-row">
                    <div class="yimai-gso-seo-push-stat-card">
                        <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#4caf50,#2e7d32)">
                            <span class="dashicons dashicons-search"></span>
                        </div>
                        <div class="yimai-gso-seo-push-stat-info">
                            <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-sp-baidu-count">-</div>
                            <div class="yimai-gso-seo-push-stat-label">百度推送成功</div>
                        </div>
                    </div>
                    <div class="yimai-gso-seo-push-stat-card">
                        <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#0078d4,#005a9e)">
                            <span class="dashicons dashicons-migrate"></span>
                        </div>
                        <div class="yimai-gso-seo-push-stat-info">
                            <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-sp-bing-count">-</div>
                            <div class="yimai-gso-seo-push-stat-label">必应推送成功</div>
                        </div>
                    </div>
                    <div class="yimai-gso-seo-push-stat-card">
                        <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#ea4335,#34a853)">
                            <span class="dashicons dashicons-google"></span>
                        </div>
                        <div class="yimai-gso-seo-push-stat-info">
                            <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-sp-google-count">-</div>
                            <div class="yimai-gso-seo-push-stat-label">Google推送成功</div>
                        </div>
                    </div>
                    <div class="yimai-gso-seo-push-stat-card">
                        <div class="yimai-gso-seo-push-stat-icon" style="background:linear-gradient(135deg,#9c27b0,#7b1fa2)">
                            <span class="dashicons dashicons-chart-line"></span>
                        </div>
                        <div class="yimai-gso-seo-push-stat-info">
                            <div class="yimai-gso-seo-push-stat-value" id="yimai-gso-sp-success-rate">-</div>
                            <div class="yimai-gso-seo-push-stat-label">总成功率</div>
                        </div>
                    </div>
                </div>

                <!-- 子标签页 -->
                <div class="yimai-gso-seo-push-tabs yimai-gso-tabs-container">
                    <button type="button" class="yimai-gso-tab-modern <?php echo $active_push_tab === 'config' ? 'active' : ''; ?>" data-sp-tab="config">
                        <span class="dashicons dashicons-admin-generic"></span> 推送配置
                    </button>
                    <button type="button" class="yimai-gso-tab-modern <?php echo $active_push_tab === 'push' ? 'active' : ''; ?>" data-sp-tab="push">
                        <span class="dashicons dashicons-upload"></span> 手动推送
                    </button>
                    <button type="button" class="yimai-gso-tab-modern <?php echo $active_push_tab === 'sitemap' ? 'active' : ''; ?>" data-sp-tab="sitemap">
                        <span class="dashicons dashicons-media-spreadsheet"></span> Sitemap
                    </button>
                    <button type="button" class="yimai-gso-tab-modern <?php echo $active_push_tab === 'logs' ? 'active' : ''; ?>" data-sp-tab="logs">
                        <span class="dashicons dashicons-list-view"></span> 推送日志
                    </button>
                </div>

                <!-- ===== 子面板1：推送配置 ===== -->
                <div class="yimai-gso-seo-push-panel active" data-sp-panel="config">
                    <form method="post" action="" id="yimai-gso-seo-push-form">
                        <?php wp_nonce_field( 'yimai_gso_seo_push_settings' ); ?>
                        <input type="hidden" name="yimai_gso_seo_push_settings" value="1">

                        <!-- 全局开关 -->
                        <div class="yimai-gso-seo-push-section">
                            <h3 class="yimai-gso-seo-push-section-title">
                                <span class="dashicons dashicons-admin-settings"></span> 全局设置
                            </h3>
                            <div class="yimai-gso-seo-push-section-body">
                                <div class="yimai-gso-seo-push-field">
                                    <label class="yimai-gso-seo-push-toggle">
                                        <input type="checkbox" name="yimai_gso_seo_push_auto_enabled" value="1" <?php checked( YIMAI_GSO_Settings::get( 'seo_push_auto_enabled', 0 ), 1 ); ?> <?php disabled( empty( $seo_push_meta['available'] ), true ); ?>>
                                        <span class="yimai-gso-seo-push-toggle-slider"></span>
                                        <span class="yimai-gso-seo-push-toggle-label">发布时自动推送 — 新文章发布后自动提交到搜索引擎</span>
                                    </label>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <label class="yimai-gso-seo-push-toggle">
                                        <input type="checkbox" name="yimai_gso_seo_push_on_update" value="1" <?php checked( YIMAI_GSO_Settings::get( 'seo_push_on_update', 0 ), 1 ); ?> <?php disabled( empty( $seo_push_meta['available'] ), true ); ?>>
                                        <span class="yimai-gso-seo-push-toggle-slider"></span>
                                        <span class="yimai-gso-seo-push-toggle-label">更新时自动推送 — 文章更新后重新提交URL</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- 百度推送 -->
                        <div class="yimai-gso-seo-push-section">
                            <h3 class="yimai-gso-seo-push-section-title">
                                <span class="yimai-gso-seo-push-platform-dot" style="background:#4caf50"></span> 百度搜索推送
                            </h3>
                            <div class="yimai-gso-seo-push-section-body">
                                <div class="yimai-gso-seo-push-field">
                                    <label class="yimai-gso-seo-push-toggle">
                                        <input type="checkbox" name="yimai_gso_seo_push_baidu_enabled" value="1" <?php checked( YIMAI_GSO_Settings::get( 'seo_push_baidu_enabled', 0 ), 1 ); ?> <?php disabled( empty( $seo_push_meta['available'] ), true ); ?>>
                                        <span class="yimai-gso-seo-push-toggle-slider"></span>
                                        <span class="yimai-gso-seo-push-toggle-label">启用百度推送</span>
                                    </label>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <label>接口调用地址 <span class="yimai-gso-seo-push-required">*</span></label>
                                    <input type="text" name="yimai_gso_seo_push_baidu_api_address" value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'seo_push_baidu_api_address', '' ) ); ?>" class="large-text" placeholder="http://data.zz.baidu.com/urls?site=www.example.com&token=edk7yc4rEZP9pDQD" style="width:100%;max-width:600px">
                                    <p class="description">登录百度搜索资源平台 > 资源提交 > <strong>普通收录</strong> > API提交 > 复制完整的「接口调用地址」粘贴到这里<br>官方格式：http://data.zz.baidu.com/urls?site=<strong>你的域名</strong>&token=<strong>你的密钥</strong></p>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <button type="button" class="button yimai-gso-sp-test-btn" data-platform="baidu">
                                        <span class="dashicons dashicons-controls-play"></span> 测试连接
                                    </button>
                                    <span class="yimai-gso-sp-test-result" id="yimai-gso-sp-test-baidu"></span>
                                </div>
                                <div class="yimai-gso-seo-push-field" style="border-top:1px dashed #ddd;padding-top:12px;margin-top:4px">
                                    <label>百度站长验证码</label>
                                    <input type="text" name="yimai_gso_seo_push_baidu_verify_code" value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'seo_push_baidu_verify_code', '' ) ); ?>" class="regular-text" placeholder="粘贴百度站长平台的验证码，如 abcd1234efgh5678">
                                    <p class="description">在百度搜索资源平台添加站点后，选择 <strong>HTML标签验证</strong>，将 <code>content="..."</code> 中的验证码粘贴到这里，插件会自动在页面头部注入验证标签。</p>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <label>百度统计代码</label>
                                    <textarea name="yimai_gso_seo_push_baidu_tongji_code" rows="4" class="large-text" placeholder="粘贴百度统计（https://tongji.baidu.com）提供的完整安装代码"><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'seo_push_baidu_tongji_code', '' ) ); ?></textarea>
                                    <p class="description">登录 <a href="https://tongji.baidu.com/" target="_blank">百度统计</a>，在"管理 > 代码获取"中复制安装代码，粘贴到此处。插件会自动注入到页面底部。</p>
                                </div>
                            </div>
                        </div>

                        <!-- 必应推送 -->
                        <div class="yimai-gso-seo-push-section">
                            <h3 class="yimai-gso-seo-push-section-title">
                                <span class="yimai-gso-seo-push-platform-dot" style="background:#0078d4"></span> 必应(Bing)推送
                                <span class="yimai-gso-seo-push-notice" style="font-size:12px;color:#0078d4;font-weight:normal;margin-left:8px">推荐使用 IndexNow</span>
                            </h3>
                            <div class="yimai-gso-seo-push-section-body">
                                <div class="yimai-gso-seo-push-field">
                                    <label class="yimai-gso-seo-push-toggle">
                                        <input type="checkbox" name="yimai_gso_seo_push_bing_enabled" value="1" <?php checked( YIMAI_GSO_Settings::get( 'seo_push_bing_enabled', 0 ), 1 ); ?> <?php disabled( empty( $seo_push_meta['available'] ), true ); ?>>
                                        <span class="yimai-gso-seo-push-toggle-slider"></span>
                                        <span class="yimai-gso-seo-push-toggle-label">启用必应推送</span>
                                    </label>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <label>IndexNow Key <span class="yimai-gso-seo-push-required">*</span>（推荐）</label>
                                    <input type="text" name="yimai_gso_seo_push_bing_indexnow_key" value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'seo_push_bing_indexnow_key', '' ) ); ?>" class="regular-text" placeholder="32位十六进制密钥，如 f317d2a87c5441c98e407458b2912b64">
                                    <p class="description">
                                        在 <a href="https://www.bing.com/webmasters/indexnow" target="_blank">Bing IndexNow</a> 获取密钥。获取后需将密钥文件(如 f317...64.txt)上传到网站根目录，确保可通过 https://你的域名/密钥.txt 访问。
                                    </p>
                                </div>
                                <div class="yimai-gso-seo-push-field" style="border-top:1px dashed #ddd;padding-top:12px;margin-top:4px">
                                    <label>旧版 API Key（备选，无需填写IndexNow Key时使用）</label>
                                    <input type="text" name="yimai_gso_seo_push_bing_api_key" value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'seo_push_bing_api_key', '' ) ); ?>" class="regular-text" placeholder="Bing Webmaster API Key">
                                    <p class="description">在 Bing Webmaster Tools &gt; 设置 &gt; API访问 &gt; API密钥 中生成。填写IndexNow Key后此项无需填写。</p>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <button type="button" class="button yimai-gso-sp-test-btn" data-platform="bing">
                                        <span class="dashicons dashicons-controls-play"></span> 测试连接
                                    </button>
                                    <span class="yimai-gso-sp-test-result" id="yimai-gso-sp-test-bing"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Google推送 -->
                        <div class="yimai-gso-seo-push-section">
                            <h3 class="yimai-gso-seo-push-section-title">
                                <span class="yimai-gso-seo-push-platform-dot" style="background:#ea4335"></span> Google 推送
                                <span class="yimai-gso-seo-push-notice" style="font-size:12px;color:#34a853;font-weight:normal;margin-left:8px">Sitemap Ping 零配置</span>
                            </h3>
                            <div class="yimai-gso-seo-push-section-body">
                                <div class="yimai-gso-seo-push-field">
                                    <label class="yimai-gso-seo-push-toggle">
                                        <input type="checkbox" name="yimai_gso_seo_push_google_enabled" value="1" <?php checked( YIMAI_GSO_Settings::get( 'seo_push_google_enabled', 0 ), 1 ); ?> <?php disabled( empty( $seo_push_meta['available'] ), true ); ?>>
                                        <span class="yimai-gso-seo-push-toggle-slider"></span>
                                        <span class="yimai-gso-seo-push-toggle-label">启用 Google 推送</span>
                                    </label>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <label>推送方式</label>
                                    <?php $google_mode = YIMAI_GSO_Settings::get( 'seo_push_google_mode', 'sitemap_ping' ); ?>
                                    <select name="yimai_gso_seo_push_google_mode" id="yimai-gso-seo-push-google-mode" style="min-width:200px">
                                        <option value="sitemap_ping" <?php selected( $google_mode, 'sitemap_ping' ); ?>>Sitemap Ping（推荐，零配置）</option>
                                        <option value="indexing_api" <?php selected( $google_mode, 'indexing_api' ); ?>>Indexing API（即时推送，需GCP配置）</option>
                                    </select>
                                    <p class="description">
                                        <strong>Sitemap Ping</strong>：发布文章后自动通知Google抓取Sitemap，无需任何配置<br>
                                        <strong>Indexing API</strong>：即时推送URL到Google索引，需配置Google Cloud Service Account
                                    </p>
                                </div>
                                <div class="yimai-gso-seo-push-field yimai-gso-google-indexing-config" style="display:<?php echo $google_mode === 'indexing_api' ? 'block' : 'none'; ?>;border-top:1px dashed #ddd;padding-top:12px;margin-top:4px">
                                    <label>Service Account JSON 密钥 <span class="yimai-gso-seo-push-required">*</span></label>
                                    <textarea name="yimai_gso_seo_push_google_service_json" rows="6" class="large-text" placeholder="粘贴 Google Cloud Service Account JSON 密钥内容"><?php
                                        $gj = YIMAI_GSO_Settings::get( 'seo_push_google_service_json', '' );
                                        echo esc_textarea( $gj ? YIMAI_GSO_Settings::get_masked( 'seo_push_google_service_json' ) : '' );
                                    ?></textarea>
                                    <p class="description">
                                        1. 前往 <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a> 创建项目并启用 Indexing API<br>
                                        2. 创建 Service Account，下载 JSON 密钥文件<br>
                                        3. 在 <a href="https://search.google.com/search-console" target="_blank">Search Console</a> 中将 Service Account 添加为网站所有者<br>
                                        4. 将 JSON 文件内容完整粘贴到上方文本框
                                    </p>
                                </div>
                                <div class="yimai-gso-seo-push-field">
                                    <button type="button" class="button yimai-gso-sp-test-btn" data-platform="google">
                                        <span class="dashicons dashicons-controls-play"></span> 测试连接
                                    </button>
                                    <span class="yimai-gso-sp-test-result" id="yimai-gso-sp-test-google"></span>
                                </div>
                            </div>
                        </div>

                        <script>
                        (function() {
                            var $mode = document.getElementById('yimai-gso-seo-push-google-mode');
                            if ($mode) {
                                $mode.addEventListener('change', function() {
                                    var $config = document.querySelector('.yimai-gso-google-indexing-config');
                                    if ($config) {
                                        $config.style.display = this.value === 'indexing_api' ? 'block' : 'none';
                                    }
                                });
                            }
                        })();
                        </script>

                        <!-- robots.txt -->
                        <div class="yimai-gso-seo-push-section">
                            <h3 class="yimai-gso-seo-push-section-title">
                                <span class="dashicons dashicons-clipboard"></span> robots.txt 自定义
                            </h3>
                            <div class="yimai-gso-seo-push-section-body">
                                <div class="yimai-gso-seo-push-field">
                                    <label>自定义 robots.txt 内容</label>
                                    <textarea name="yimai_gso_robots_content" rows="8" class="large-text" placeholder="留空则使用默认配置，自动包含 Sitemap 地址"><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'yimai_gso_robots_content', '' ) ); ?></textarea>
                                    <p class="description">自定义 robots.txt 规则，留空则自动生成默认规则（Allow: / + Sitemap引用）</p>
                                </div>
                            </div>
                        </div>

                        <div class="yimai-gso-seo-push-save-bar">
                            <button type="submit" class="button button-primary button-hero">
                                <span class="dashicons dashicons-saved"></span> 保存推送配置
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ===== 子面板2：手动推送 ===== -->
                <div class="yimai-gso-seo-push-panel" data-sp-panel="push">
                    <div class="yimai-gso-seo-push-section">
                        <h3 class="yimai-gso-seo-push-section-title">
                            <span class="dashicons dashicons-upload"></span> 批量推送文章
                        </h3>
                        <div class="yimai-gso-seo-push-section-body">
                            <p class="yimai-gso-seo-push-help">选择要推送到搜索引擎的文章，支持按分类和日期范围筛选</p>
                            <div class="yimai-gso-seo-push-filter-row">
                                <div class="yimai-gso-seo-push-filter-group">
                                    <label>分类筛选</label>
                                    <select id="yimai-gso-sp-push-category">
                                        <option value="0">全部分类</option>
                                        <?php
                                        $push_categories = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );
                                        foreach ( $push_categories as $cat ) {
                                            echo '<option value="' . esc_attr( $cat->term_id ) . '">' . esc_html( $cat->name ) . ' (' . $cat->count . ')</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="yimai-gso-seo-push-filter-group">
                                    <label>每页数量</label>
                                    <select id="yimai-gso-sp-push-per-page">
                                        <option value="20">20篇</option>
                                        <option value="50" selected>50篇</option>
                                        <option value="100">100篇</option>
                                        <option value="200">200篇</option>
                                    </select>
                                </div>
                                <div class="yimai-gso-seo-push-filter-group" style="align-self:flex-end">
                                    <button type="button" class="button" id="yimai-gso-sp-load-posts">
                                        <span class="dashicons dashicons-update"></span> 加载文章
                                    </button>
                                </div>
                            </div>

                            <div id="yimai-gso-sp-posts-table-wrap" style="display:none">
                                <div class="yimai-gso-sp-select-bar">
                                    <label class="yimai-gso-sp-select-all">
                                        <input type="checkbox" id="yimai-gso-sp-check-all">
                                        <span>全选</span>
                                    </label>
                                    <span id="yimai-gso-sp-selected-count" style="margin-left:12px;color:#666">已选 0 篇</span>
                                    <span style="flex:1"></span>
                                    <button type="button" class="button button-primary" id="yimai-gso-sp-do-batch-push" disabled>
                                        <span class="dashicons dashicons-share-alt2" style="margin-top:4px"></span> 推送选中文章
                                    </button>
                                </div>
                                <table class="wp-list-table widefat fixed striped" id="yimai-gso-sp-posts-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40px" class="column-cb"><input type="checkbox" id="yimai-gso-sp-check-all-2"></th>
                                            <th>ID</th>
                                            <th>文章标题</th>
                                            <th>分类</th>
                                            <th>发布日期</th>
                                            <th>推送状态</th>
                                        </tr>
                                    </thead>
                                    <tbody id="yimai-gso-sp-posts-tbody"></tbody>
                                </table>
                            </div>

                            <div id="yimai-gso-sp-push-result" style="display:none;margin-top:16px"></div>
                        </div>
                    </div>
                </div>

                <!-- ===== 子面板3：Sitemap管理 ===== -->
                <div class="yimai-gso-seo-push-panel" data-sp-panel="sitemap">
                    <div class="yimai-gso-seo-push-section">
                        <h3 class="yimai-gso-seo-push-section-title">
                            <span class="dashicons dashicons-media-spreadsheet"></span> Sitemap 管理
                        </h3>
                        <div class="yimai-gso-seo-push-section-body">
                            <div class="yimai-gso-sp-sitemap-info-grid">
                                <div class="yimai-gso-sp-sitemap-card">
                                    <div class="yimai-gso-sp-sitemap-card-title">主 Sitemap (索引)</div>
                                    <div class="yimai-gso-sp-sitemap-url"><a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap.xml' ) ); ?></a></div>
                                </div>
                                <div class="yimai-gso-sp-sitemap-card">
                                    <div class="yimai-gso-sp-sitemap-card-title">文章 Sitemap</div>
                                    <div class="yimai-gso-sp-sitemap-url"><a href="<?php echo esc_url( home_url( '/sitemap-post.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap-post.xml' ) ); ?></a></div>
                                </div>
                                <div class="yimai-gso-sp-sitemap-card">
                                    <div class="yimai-gso-sp-sitemap-card-title">分类 Sitemap</div>
                                    <div class="yimai-gso-sp-sitemap-url"><a href="<?php echo esc_url( home_url( '/sitemap-taxonomy-category.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap-taxonomy-category.xml' ) ); ?></a></div>
                                </div>
                                <div class="yimai-gso-sp-sitemap-card">
                                    <div class="yimai-gso-sp-sitemap-card-title">标签 Sitemap</div>
                                    <div class="yimai-gso-sp-sitemap-url"><a href="<?php echo esc_url( home_url( '/sitemap-taxonomy-post_tag.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap-taxonomy-post_tag.xml' ) ); ?></a></div>
                                </div>
                                <div class="yimai-gso-sp-sitemap-card">
                                    <div class="yimai-gso-sp-sitemap-card-title">HTML 站点地图</div>
                                    <div class="yimai-gso-sp-sitemap-url"><a href="<?php echo esc_url( home_url( '/sitemap.html' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap.html' ) ); ?></a></div>
                                </div>
                                <div class="yimai-gso-sp-sitemap-card">
                                    <div class="yimai-gso-sp-sitemap-card-title">robots.txt</div>
                                    <div class="yimai-gso-sp-sitemap-url"><a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/robots.txt' ) ); ?></a></div>
                                </div>
                            </div>

                            <div class="yimai-gso-sp-sitemap-actions" style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap">
                                <button type="button" class="button button-primary" id="yimai-gso-sp-push-sitemap">
                                    <span class="dashicons dashicons-share-alt2" style="margin-top:4px"></span> 推送Sitemap到搜索引擎
                                </button>
                            </div>
                            <div id="yimai-gso-sp-sitemap-result" style="margin-top:12px"></div>
                        </div>
                    </div>
                </div>

                <!-- ===== 子面板4：推送日志 ===== -->
                <div class="yimai-gso-seo-push-panel" data-sp-panel="logs">
                    <div class="yimai-gso-seo-push-section">
                        <div class="yimai-gso-seo-push-section-title" style="display:flex;align-items:center;justify-content:space-between">
                            <h3 style="margin:0;display:flex;align-items:center;gap:6px">
                                <span class="dashicons dashicons-list-view"></span> 推送日志
                            </h3>
                            <div style="display:flex;gap:8px;align-items:center">
                                <select id="yimai-gso-sp-log-platform" style="line-height:28px;height:28px">
                                    <option value="">全部平台</option>
                                    <option value="baidu">百度</option>
                                    <option value="bing">必应</option>
                                    <option value="google">Google</option>
                                </select>
                                <button type="button" class="button button-small" id="yimai-gso-sp-clear-logs">
                                    <span class="dashicons dashicons-trash" style="margin-top:2px"></span> 清空日志
                                </button>
                            </div>
                        </div>
                        <div class="yimai-gso-seo-push-section-body" style="padding:0">
                            <table class="wp-list-table widefat fixed striped" id="yimai-gso-sp-logs-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px">ID</th>
                                        <th>推送URL</th>
                                        <th style="width:70px">类型</th>
                                        <th style="width:80px">百度</th>
                                        <th style="width:80px">必应</th>
                                        <th style="width:80px">Google</th>
                                        <th style="width:150px">时间</th>
                                    </tr>
                                </thead>
                                <tbody id="yimai-gso-sp-logs-tbody">
                                    <tr><td colspan="8" style="text-align:center;padding:40px;color:#999">加载中...</td></tr>
                                </tbody>
                            </table>
                            <div class="yimai-gso-sp-pagination" id="yimai-gso-sp-logs-pagination" style="padding:12px;display:flex;align-items:center;gap:8px;justify-content:center"></div>
                        </div>
                    </div>
                </div>

                </div><!-- end #yimai-gso-seo-push-root -->

            </div><!-- end .yimai-gso-main-panel seo-push -->

            <!-- ==================== 标签5：插件设置 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                <?php ?>
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-admin-settings"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">插件设置</h2>
                        <p class="yimai-gso-card-desc">配置 AI 接口、SEO 参数、发布策略等核心运行参数</p>
                    </div>
                </div>
                <div class="yimai-gso-settings-header">
                    <div class="yimai-gso-settings-header-left" style="display:none">
                        <h1 class="yimai-gso-page-title">
                            <span class="dashicons dashicons-admin-settings"></span>
                            插件设置
                        </h1>
                    </div>
                    <div class="yimai-gso-settings-header-right">
                        <div class="yimai-gso-edition-info-mini">
                            <span class="yimai-gso-edition-status-dot"></span>
                            <span><?php echo esc_html( $dashboard_plan_label ); ?></span>
                        </div>
                    </div>
                </div>

                <?php
                $settings_subtab = isset( $_GET['subtab'] ) ? sanitize_text_field( $_GET['subtab'] ) : 'ai';
                if ( ! in_array( $settings_subtab, array( 'ai', 'image', 'publish', 'geo', 'knowledge', 'crawler', 'authors' ), true ) ) {
                    $settings_subtab = 'ai';
                }
                ?>
                <form method="post" action="" id="yimai-gso-settings-form">
                    <?php wp_nonce_field( 'yimai_gso_settings_nonce' ); ?>
                    <input type="hidden" name="yimai_gso_current_subtab" id="yimai-gso-current-subtab" value="<?php echo esc_attr( $settings_subtab ); ?>">

                <!-- 设置子标签页 -->
                <div class="yimai-gso-settings-tabs">
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'ai' ? 'active' : ''; ?>" data-tab="ai">
                        <span class="dashicons dashicons-admin-generic"></span>
                        AI配置
                    </button>
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'image' ? 'active' : ''; ?>" data-tab="image">
                        <span class="dashicons dashicons-format-image"></span>
                        配图设置
                    </button>
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'publish' ? 'active' : ''; ?>" data-tab="publish">
                        <span class="dashicons dashicons-upload"></span>
                        发布设置
                    </button>
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'geo' ? 'active' : ''; ?>" data-tab="geo">
                        <span class="dashicons dashicons-networking"></span>
                        GEO实体
                    </button>
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'knowledge' ? 'active' : ''; ?>" data-tab="knowledge">
                        <span class="dashicons dashicons-book-alt"></span>
                        品牌资料
                    </button>
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'crawler' ? 'active' : ''; ?>" data-tab="crawler">
                        <span class="dashicons dashicons-shield"></span>
                        爬虫策略
                    </button>
                    <button type="button" class="yimai-gso-settings-tab <?php echo $settings_subtab === 'authors' ? 'active' : ''; ?>" data-tab="authors">
                        <span class="dashicons dashicons-admin-users"></span>
                        作者档案
                    </button>
                </div>

                <!-- AI配置 -->
                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'ai' ? 'active' : ''; ?>" data-tab="ai">

                    <div class="yimai-gso-settings-section">
                        <div class="yimai-gso-settings-section-header">
                            <h2>🤖 AI 接口配置</h2>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>AI 提供商</label>
                                <select name="yimai_gso_ai_provider" id="setting-ai-provider">
                                    <?php foreach ( $providers as $key => $prov ) : ?>
                                        <option value="<?php echo $key; ?>" <?php selected( $ai_provider, $key ); ?>>
                                            <?php echo esc_html( $prov['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>API Key <span class="required">*</span></label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_ai_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get_masked( 'ai_api_key' ) ); ?>"
                                           placeholder="sk-xxxx...">
                                    <button type="button" class="button" id="test-ai-api">测试连接</button>
                                </div>
                                <span id="test-ai-result"></span>
                                <small class="yimai-gso-field-hint">
                                    OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank">获取Key</a> |
                                    DeepSeek: <a href="https://platform.deepseek.com" target="_blank">获取Key</a>
                                </small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>API 接口地址</label>
                                <input type="url" name="yimai_gso_ai_api_url" class="large-text"
                                       value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'ai_api_url' ) ); ?>"
                                       placeholder="留空则使用所选提供商默认地址">
                                <small class="yimai-gso-field-hint">如使用国内中转代理，在此填写代理地址</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>模型名称</label>
                                <input type="text" name="yimai_gso_ai_model" class="regular-text"
                                       value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'ai_model', 'gpt-4o' ) ); ?>"
                                       placeholder="gpt-4o">
                                <small class="yimai-gso-field-hint" id="model-hint">常用：gpt-4o / deepseek-chat / qwen-max / glm-4</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>默认每篇字数</label>
                                <input type="number" name="yimai_gso_word_count" min="300" max="5000" step="100"
                                       value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'word_count', 1500 ) ); ?>">
                                <small>建议 800-2000 字，SEO效果好</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>系统提示词</label>
                                <div class="yimai-gso-system-prompt-section">
                                    <button type="button" class="button button-secondary" id="yimai-gso-toggle-default-prompt">
                                        <span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;"></span>
                                        <span class="btn-text">查看默认提示词</span>
                                    </button>
                                    <div id="yimai-gso-default-prompt-box" style="display:none;margin-top:10px;padding:12px;background:#1e293b;border-radius:8px;border:1px solid #334155;">
                                        <div style="color:#94a3b8;font-size:11px;margin-bottom:8px;">📋 默认提示词预览</div>
                                        <pre style="color:#e2e8f0;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto;margin:0;"><?php echo esc_textarea( YIMAI_GSO_Settings::get_default_system_prompt() ); ?></pre>
                                        <div style="margin-top:10px;padding:10px;background:#0f172a;border-radius:6px;">
                                            <div style="color:#94a3b8;font-size:11px;margin-bottom:6px;">💡 如何使用自定义提示词</div>
                                            <ul style="color:#cbd5e1;font-size:11px;margin:0;padding-left:16px;line-height:1.8;">
                                                <li>留空此输入框 → 使用上方默认提示词</li>
                                                <li>填写自定义内容 → 完全覆盖默认提示词</li>
                                                <li>基于默认修改 → 复制上方内容到输入框，再按需修改</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <textarea name="yimai_gso_system_prompt" rows="6" class="large-text"
                                          placeholder="自定义AI角色提示词，留空则使用默认..."><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'system_prompt' ) ); ?></textarea>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>单篇生成 · 系统提示词 <span style="display:inline-block;margin-left:6px;padding:1px 8px;font-size:11px;font-weight:600;background:#ede9fe;color:#7c3aed;border-radius:4px;">独立配置</span></label>
                                <small style="display:block;color:var(--yimai-gso-gray);font-size:12px;margin-top:2px;">专用于单篇生成功能，与上方批量生成的系统提示词独立配置。留空则使用内置默认。</small>
                                <div class="yimai-gso-system-prompt-section" style="margin-bottom:4px;">
                                    <button type="button" class="button button-secondary" id="yimai-gso-toggle-default-single-prompt" style="font-size:11px;padding:2px 10px;min-height:28px;line-height:24px;">
                                        <span class="dashicons dashicons-visibility" style="font-size:13px;width:13px;height:13px;"></span>
                                        查看内置默认
                                    </button>
                                    <div id="yimai-gso-default-single-prompt-box" style="display:none;margin-top:8px;padding:10px;background:#1e293b;border-radius:6px;border:1px solid #334155;">
                                        <pre style="color:#e2e8f0;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto;margin:0;"><?php echo esc_textarea( YIMAI_GSO_Settings::get_default_single_generate_system_prompt() ); ?></pre>
                                    </div>
                                </div>
                                <textarea name="yimai_gso_single_generate_system_prompt" rows="4" class="large-text"
                                          placeholder="自定义单篇生成提示词，留空则使用内置默认..."><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'single_generate_system_prompt' ) ); ?></textarea>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>用户提示词模板</label>
                                <div class="yimai-gso-system-prompt-section" style="margin-bottom:10px;">
                                    <button type="button" class="button button-secondary" id="yimai-gso-toggle-default-user-prompt">
                                        <span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;"></span>
                                        <span class="btn-text">查看默认用户提示词</span>
                                    </button>
                                    <div id="yimai-gso-default-user-prompt-box" style="display:none;margin-top:10px;padding:12px;background:#1e293b;border-radius:8px;border:1px solid #334155;">
                                        <div style="color:#94a3b8;font-size:11px;margin-bottom:8px;">📋 默认用户提示词模板预览</div>
                                        <pre style="color:#e2e8f0;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto;margin:0;"><?php echo esc_textarea( YIMAI_GSO_Settings::get_default_user_prompt() ); ?></pre>
                                        <div style="margin-top:10px;padding:10px;background:#0f172a;border-radius:6px;">
                                            <div style="color:#94a3b8;font-size:11px;margin-bottom:6px;">💡 可用变量</div>
                                            <ul style="color:#cbd5e1;font-size:11px;margin:0;padding-left:16px;line-height:1.8;">
                                                <li><code style="background:#334155;padding:1px 4px;border-radius:3px;">{{lang_name}}</code> — 语言名称（中文/English）</li>
                                                <li><code style="background:#334155;padding:1px 4px;border-radius:3px;">{{prompt}}</code> — 用户输入的关键词/提示词</li>
                                                <li><code style="background:#334155;padding:1px 4px;border-radius:3px;">{{word_count}}</code> — 目标字数</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <textarea name="yimai_gso_user_prompt" rows="6" class="large-text"
                                          placeholder="自定义用户提示词模板，留空则使用默认...&#10;可用变量：{{lang_name}} {{prompt}} {{word_count}}"><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'user_prompt' ) ); ?></textarea>
                                <small class="yimai-gso-field-hint">定义每次生成文章时发送给AI的用户指令。留空使用默认模板，填写则完全覆盖。</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 配图设置 -->
                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'image' ? 'active' : ''; ?>" data-tab="image">
                    <div class="yimai-gso-settings-section">
                        <div class="yimai-gso-settings-section-header">
                            <h2>🖼️ 自动配图配置</h2>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>配图来源</label>
                                <select name="yimai_gso_image_provider" id="setting-img-provider">
                                    <?php foreach ( $img_providers as $key => $prov ) : ?>
                                        <option value="<?php echo $key; ?>" <?php selected( $img_provider, $key ); ?>>
                                            <?php echo esc_html( $prov['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="yimai-gso-field-hint">推荐 Pexels / Unsplash / Lorem Flickr</small>
                            </div>
                            <div class="yimai-gso-field-group" id="pexels-key-row">
                                <label>Pexels API Key</label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_pexels_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'pexels_api_key' ) ); ?>"
                                           placeholder="免费申请：pexels.com/api">
                                    <button type="button" class="button" id="test-img-api">测试连接</button>
                                </div>
                                <span id="test-img-result"></span>
                            </div>
                            <div class="yimai-gso-field-group" id="unsplash-key-row">
                                <label>Unsplash Access Key</label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_unsplash_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'unsplash_api_key' ) ); ?>"
                                           placeholder="免费申请：unsplash.com/developers">
                                    <button type="button" class="button" id="test-img-api">测试连接</button>
                                </div>
                                <span id="test-img-result"></span>
                            </div>
                            <div class="yimai-gso-field-group" id="pixabay-key-row">
                                <label>Pixabay API Key</label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_image_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'image_api_key' ) ); ?>"
                                           placeholder="免费申请：pixabay.com/api/docs">
                                    <button type="button" class="button" id="test-img-api">测试连接</button>
                                </div>
                                <span id="test-img-result"></span>
                            </div>
                            <div class="yimai-gso-field-group" id="flickr-key-row">
                                <label>Flickr API Key</label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_flickr_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'flickr_api_key' ) ); ?>"
                                           placeholder="免费申请：flickr.com/services/apps">
                                    <button type="button" class="button" id="test-img-api">测试连接</button>
                                </div>
                            </div>
                            <div class="yimai-gso-field-group" id="stockapi-key-row">
                                <label>StockAPI Key</label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_stockapi_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'stockapi_api_key' ) ); ?>"
                                           placeholder="免费申请：stockapi.io">
                                    <button type="button" class="button" id="test-img-api">测试连接</button>
                                </div>
                            </div>
                            <div class="yimai-gso-field-group" id="custom-img-key-row">
                                <label>自定义图片 API Key</label>
                                <div class="yimai-gso-input-with-action">
                                    <input type="password" name="yimai_gso_custom_img_api_key" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'custom_img_api_key' ) ); ?>"
                                           placeholder="填写自定义图片接口的 API Key">
                                    <button type="button" class="button" id="test-img-api">测试连接</button>
                                </div>
                            </div>
                            <div class="yimai-gso-field-group" id="custom-img-url-row">
                                <label>自定义图片 API 地址</label>
                                <input type="url" name="yimai_gso_custom_img_api_url" class="large-text"
                                       value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'custom_img_api_url' ) ); ?>"
                                       placeholder="填写自定义图片接口地址">
                            </div>
                            <div class="yimai-gso-field-group" id="dalle-model-row">
                                <label>DALL-E 模型</label>
                                <select name="yimai_gso_dalle_model">
                                    <option value="dall-e-3" <?php selected( YIMAI_GSO_Settings::get('dalle_model'), 'dall-e-3' ); ?>>DALL-E 3</option>
                                    <option value="dall-e-2" <?php selected( YIMAI_GSO_Settings::get('dalle_model'), 'dall-e-2' ); ?>>DALL-E 2</option>
                                </select>
                                <small class="yimai-gso-field-hint">使用 OpenAI API Key，会产生额外费用</small>
                            </div>

                            <!-- ★ AI 生图配置（选择"AI生成"后显示） -->
                            <div class="yimai-gso-ai-image-section" id="ai-image-section" style="display:<?php echo $img_provider === 'ai' ? 'block' : 'none'; ?>;">
                                <div class="yimai-gso-settings-section-header" style="margin-top: 12px;">
                                    <h3>🤖 AI 生图配置</h3>
                                </div>
                                <div class="yimai-gso-field-group">
                                    <label>AI 生图服务商</label>
                                    <select name="yimai_gso_ai_image_provider" id="setting-ai-img-provider">
                                        <option value="doubao" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_provider', 'doubao' ), 'doubao' ); ?>>豆包/即梦 Seedream（推荐）</option>
                                        <option value="zhipu" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_provider' ), 'zhipu' ); ?>>智谱 CogView-4</option>
                                        <option value="wanxiang" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_provider' ), 'wanxiang' ); ?>>通义万相 wan2.6</option>
                                    </select>
                                    <small class="yimai-gso-field-hint">选择 AI 生图服务商后，下方会显示对应的 API Key 配置</small>
                                </div>

                                <!-- 豆包/即梦 API Key -->
                                <div class="yimai-gso-field-group ai-img-key-row" id="ai-key-row-doubao" data-provider="doubao">
                                    <label>豆包/即梦 API Key</label>
                                    <div class="yimai-gso-input-with-action">
                                        <input type="password" name="yimai_gso_ai_image_doubao_key" class="large-text"
                                               value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'ai_image_doubao_key' ) ); ?>"
                                               placeholder="前往火山方舟控制台获取 API Key">
                                        <button type="button" class="button yimai-gso-test-ai-img-btn" data-provider="doubao">测试连接</button>
                                    </div>
                                    <span class="yimai-gso-test-ai-img-result"></span>
                                    <small class="yimai-gso-field-hint">前往 <a href="https://console.volcengine.com/ark/region:ark+cn-beijing/apikey" target="_blank">火山方舟控制台</a> 获取 API Key</small>
                                </div>

                                <!-- 智谱 API Key -->
                                <div class="yimai-gso-field-group ai-img-key-row" id="ai-key-row-zhipu" data-provider="zhipu" style="display:none;">
                                    <label>智谱 API Key</label>
                                    <div class="yimai-gso-input-with-action">
                                        <input type="password" name="yimai_gso_ai_image_zhipu_key" class="large-text"
                                               value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'ai_image_zhipu_key' ) ); ?>"
                                               placeholder="前往智谱AI开放平台获取 API Key">
                                        <button type="button" class="button yimai-gso-test-ai-img-btn" data-provider="zhipu">测试连接</button>
                                    </div>
                                    <span class="yimai-gso-test-ai-img-result"></span>
                                    <small class="yimai-gso-field-hint">前往 <a href="https://open.bigmodel.cn/usercenter/apikeys" target="_blank">智谱AI开放平台</a> 获取 API Key</small>
                                </div>

                                <!-- 通义万相 API Key -->
                                <div class="yimai-gso-field-group ai-img-key-row" id="ai-key-row-wanxiang" data-provider="wanxiang" style="display:none;">
                                    <label>通义万相 API Key</label>
                                    <div class="yimai-gso-input-with-action">
                                        <input type="password" name="yimai_gso_ai_image_wanxiang_key" class="large-text"
                                               value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'ai_image_wanxiang_key' ) ); ?>"
                                               placeholder="前往阿里云百炼控制台获取 API Key">
                                        <button type="button" class="button yimai-gso-test-ai-img-btn" data-provider="wanxiang">测试连接</button>
                                    </div>
                                    <span class="yimai-gso-test-ai-img-result"></span>
                                    <small class="yimai-gso-field-hint">前往 <a href="https://bailian.console.aliyun.com/#/api-key" target="_blank">阿里云百炼控制台</a> 获取 API Key</small>
                                </div>

                                <!-- 模型版本 -->
                                <div class="yimai-gso-field-group">
                                    <label>模型名称</label>
                                    <input type="text" name="yimai_gso_ai_image_model" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'ai_image_model', 'doubao-seedream-5-0-260128' ) ); ?>"
                                           placeholder="例如：doubao-seedream-5-0-260128">
                                    <small class="yimai-gso-field-hint">自行填写模型名称，切换服务商时会自动填充对应推荐模型</small>
                                </div>

                                <!-- 图片尺寸 -->
                                <div class="yimai-gso-field-group">
                                    <label>图片比例</label>
                                    <select name="yimai_gso_ai_image_size" id="setting-ai-img-size">
                                        <option value="2K" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_size', '2K' ), '2K' ); ?>>2K 正方形（默认）</option>
                                        <option value="16:9" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_size' ), '16:9' ); ?>>16:9 横版宽屏</option>
                                        <option value="4:3" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_size' ), '4:3' ); ?>>4:3 标准横版</option>
                                        <option value="9:16" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_size' ), '9:16' ); ?>>9:16 竖版</option>
                                    </select>
                                    <small class="yimai-gso-field-hint">豆包/即梦仅支持2K正方形，非正方形比例通过提示词描述实现</small>
                                </div>

                                <!-- 图片风格预设 -->
                                <div class="yimai-gso-field-group">
                                    <label>图片风格预设</label>
                                    <select name="yimai_gso_ai_image_style" id="setting-ai-img-style">
                                        <option value="写实摄影" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_style', '写实摄影' ), '写实摄影' ); ?>>写实摄影风格</option>
                                        <option value="商务插画" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_style' ), '商务插画' ); ?>>商务插画风格</option>
                                        <option value="极简扁平化" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_style' ), '极简扁平化' ); ?>>极简扁平化</option>
                                        <option value="3D渲染" <?php selected( YIMAI_GSO_Settings::get( 'ai_image_style' ), '3D渲染' ); ?>>3D 渲染风格</option>
                                    </select>
                                    <small class="yimai-gso-field-hint">选择风格后会自动填充到下方提示词模板中</small>
                                </div>

                                <!-- 自定义提示词模板 -->
                                <div class="yimai-gso-field-group">
                                    <label>自定义提示词模板</label>
                                    <textarea name="yimai_gso_ai_image_custom_prompt" rows="4" class="large-text"
                                              placeholder="高清晰度，专业摄影品质，大师级构图，自然光或柔光照明，光影层次丰富，画面干净无任何文字字母logo数字，色彩鲜明自然，{style}风格，中国本土化真实场景"><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'ai_image_custom_prompt' ) ); ?></textarea>
                                    <small class="yimai-gso-field-hint">
                                        可用变量：<code>{title}</code> 文章标题、<code>{keyword}</code> 关键词、<code>{section}</code> 章节标题、<code>{style}</code> 风格预设
                                    </small>
                                </div>
                            </div>
                            <!-- ★ AI 生图配置结束 -->

                            <div class="yimai-gso-field-group">
                                <label>默认配图数量</label>
                                <div class="yimai-gso-number-input">
                                    <input type="number" name="yimai_gso_image_count" min="0" max="10"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'image_count', 1 ) ); ?>">
                                    <span>张</span>
                                </div>
                            </div>
                            <div class="yimai-gso-field-group" id="img-prompt-row">
                                <label>配图搜索提示词</label>
                                <textarea name="yimai_gso_image_prompt" rows="3" class="large-text"
                                          placeholder="留空则自动使用文章关键词搜索配图&#10;可自定义搜索风格，例如：&#10;高清商务风格，科技感背景图"><?php echo esc_textarea( YIMAI_GSO_Settings::get( 'image_prompt' ) ); ?></textarea>
                                <small class="yimai-gso-field-hint">自定义配图搜索风格提示词，留空则根据文章关键词自动搜索（AI生图模式下此设置不影响，使用上方"自定义提示词模板"）</small>
                            </div>

                            <!-- 我的图片库配置（仅配图来源=local 时显示） -->
                            <?php
                            $local_img_config_raw = YIMAI_GSO_Settings::get( 'local_image_config', '' );
                            $local_img_config = array();
                            if ( is_string( $local_img_config_raw ) && ! empty( $local_img_config_raw ) ) {
                                $local_img_config = json_decode( $local_img_config_raw, true );
                            }
                            if ( ! is_array( $local_img_config ) ) {
                                $local_img_config = array();
                            }
                            // 获取图片库分组列表
                            $local_groups_list = array();
                            if ( class_exists( 'YIMAI_GSO_Image_Library' ) ) {
                                $local_groups_list = YIMAI_GSO_Image_Library::get_groups_flat();
                            }
                            ?>
                            <div class="yimai-gso-field-group" id="local-img-config-section" style="display:none;">
                                <h3 style="margin:8px 0 4px;">📁 我的图片库筛选配置</h3>
                                <div class="yimai-gso-field-group" style="margin-top:8px;">
                                    <label>限定图片分组</label>
                                    <select name="yimai_gso_local_image_group_id">
                                        <option value="0">全部分组</option>
                                        <?php foreach ( $local_groups_list as $g ) : ?>
                                            <option value="<?php echo esc_attr( $g['id'] ); ?>" <?php selected( $local_img_config['group_id'] ?? 0, $g['id'] ); ?>>
                                                <?php echo str_repeat( '— ', $g['depth'] ?? 0 ) . esc_html( $g['name'] ) . ' (' . intval( $g['count'] ) . '张)'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="yimai-gso-field-hint">只从指定分组中选取配图，选"全部分组"则从整个图片库选取</small>
                                </div>
                                <div class="yimai-gso-field-group">
                                    <label>最小权重</label>
                                    <input type="number" name="yimai_gso_local_image_min_weight" min="1" max="10"
                                           value="<?php echo esc_attr( $local_img_config['min_weight'] ?? 1 ); ?>">
                                    <small class="yimai-gso-field-hint">仅选取权重 ≥ 此值的图片（1-10，默认1），权重越高的图片越优先被选用</small>
                                </div>
                            </div>

                            <!-- MT Photos 配置（仅配图来源=mtphotos 时显示） -->
                            <div class="yimai-gso-field-group" id="mtphotos-config-section" style="display:<?php echo $img_provider === 'mtphotos' ? 'block' : 'none'; ?>;">
                                <h3 style="margin:8px 0 4px;">🖼️ MT Photos 素材库配置</h3>
                                <div class="yimai-gso-field-group" style="margin-top:8px;">
                                    <label>MT Photos 外网地址</label>
                                    <input type="url" name="yimai_gso_mtphotos_host" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'mtphotos_host', 'https://photos.yimaiyoga.com' ) ); ?>"
                                           placeholder="https://photos.yimaiyoga.com">
                                    <small class="yimai-gso-field-hint">MT Photos 服务地址（外网可访问），结尾不要带 /</small>
                                </div>
                                <div class="yimai-gso-field-group">
                                    <label>API Key（推荐）</label>
                                    <div class="yimai-gso-input-with-action">
                                        <input type="password" name="yimai_gso_mtphotos_api_key" class="large-text"
                                               value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'mtphotos_api_key' ) ); ?>"
                                               placeholder="MT Photos 网页端 → 右上角用户名 → API密钥">
                                        <button type="button" class="button" id="test-mtphotos-api">测试连接</button>
                                    </div>
                                    <span id="test-mtphotos-result"></span>
                                    <small class="yimai-gso-field-hint">优先使用 API Key；留空则用下方账号密码登录</small>
                                </div>
                                <div class="yimai-gso-field-group">
                                    <label>登录账号（API Key 留空时使用）</label>
                                    <input type="text" name="yimai_gso_mtphotos_username" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'mtphotos_username' ) ); ?>"
                                           placeholder="MT Photos 登录用户名">
                                </div>
                                <div class="yimai-gso-field-group">
                                    <label>登录密码（API Key 留空时使用）</label>
                                    <input type="password" name="yimai_gso_mtphotos_password" class="large-text"
                                           value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'mtphotos_password' ) ); ?>"
                                           placeholder="MT Photos 登录密码（RSA 加密传输）">
                                </div>
                                <div class="yimai-gso-field-group" style="margin-top:12px;padding:12px;border:1px solid #334155;border-radius:8px;">
                                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <input type="checkbox" name="yimai_gso_mtphotos_review_enabled" value="1"
                                               <?php checked( (int) YIMAI_GSO_Settings::get( 'mtphotos_review_enabled', 1 ) === 1 ); ?>>
                                        <span>🛡️ 配图前 AI 关联度审核</span>
                                    </label>
                                    <small class="yimai-gso-field-hint" style="display:block;margin-bottom:10px;">
                                        取图后由文章 AI 模型判定每张图片与文章内容的关联度，不相关或不适合公开的图片自动弃用（判定依据为素材库文件夹名与文件名；使用「AI 设置」中配置的模型）
                                    </small>
                                    <div style="display:flex;gap:16px;flex-wrap:wrap;">
                                        <div>
                                            <label style="font-size:12px;color:#94a3b8;">严格程度</label>
                                            <select name="yimai_gso_mtphotos_review_strictness">
                                                <option value="loose" <?php selected( YIMAI_GSO_Settings::get( 'mtphotos_review_strictness', 'standard' ), 'loose' ); ?>>宽松（关联分≥3）</option>
                                                <option value="standard" <?php selected( YIMAI_GSO_Settings::get( 'mtphotos_review_strictness', 'standard' ), 'standard' ); ?>>标准（关联分≥5）(Recommended)</option>
                                                <option value="strict" <?php selected( YIMAI_GSO_Settings::get( 'mtphotos_review_strictness', 'standard' ), 'strict' ); ?>>严格（关联分≥7）</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:12px;color:#94a3b8;">AI 服务异常时</label>
                                            <select name="yimai_gso_mtphotos_review_failover">
                                                <option value="use" <?php selected( YIMAI_GSO_Settings::get( 'mtphotos_review_failover', 'use' ), 'use' ); ?>>照常用图（推荐）</option>
                                                <option value="reject" <?php selected( YIMAI_GSO_Settings::get( 'mtphotos_review_failover', 'reject' ), 'reject' ); ?>>宁可无图</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <small class="yimai-gso-field-hint" style="display:block;margin-top:8px;color:#2271b1;">
                                    📌 选图规则：自动从名称含「_有水印」的文件夹中按关键词匹配取图，排除「_无水印」目录；取的是 1000px 预览图（速度更快），如需原图可在 class-mtphotos-client.php 的 build_image_url 中加 type=ori 参数。
                                </small>
                            </div>

                        </div>
                    </div>
                </div>



                <!-- 发布设置 -->

                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'publish' ? 'active' : ''; ?>" data-tab="publish">
                    <div class="yimai-gso-settings-section">
                        <div class="yimai-gso-settings-section-header">
                            <h2>📤 发布默认设置</h2>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>默认分类</label>
                                <select name="yimai_gso_default_category" class="yimai-gso-select-full">
                                    <?php foreach ( $categories as $cat ) : ?>
                                        <option value="<?php echo $cat->term_id; ?>" <?php selected( YIMAI_GSO_Settings::get( 'default_category', 1 ), $cat->term_id ); ?>>
                                            <?php echo esc_html( $cat->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>文章语言</label>
                                <select name="yimai_gso_article_language">
                                    <option value="zh_CN" <?php selected( YIMAI_GSO_Settings::get('article_language'), 'zh_CN' ); ?>>🇨🇳 中文</option>
                                    <option value="en_US" <?php selected( YIMAI_GSO_Settings::get('article_language'), 'en_US' ); ?>>🇺🇸 English</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 发布时间策略 -->
                    <div class="yimai-gso-settings-section" style="margin-top:20px;">
                        <div class="yimai-gso-settings-section-header">
                            <h2>🕐 发布时间策略</h2>
                            <p class="description">模拟人工发布节奏，防止被搜索引擎识别为机器发布</p>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>时间随机偏移</label>
                                <div class="yimai-gso-radio-group">
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai_gso_publish_time_jitter" value="0" <?php checked( YIMAI_GSO_Settings::get( 'publish_time_jitter', '0' ), '0' ); ?>>
                                        <span>关闭</span>
                                    </label>
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai_gso_publish_time_jitter" value="300" <?php checked( YIMAI_GSO_Settings::get( 'publish_time_jitter', '0' ), '300' ); ?>>
                                        <span>±5 分钟</span>
                                    </label>
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai_gso_publish_time_jitter" value="900" <?php checked( YIMAI_GSO_Settings::get( 'publish_time_jitter', '0' ), '900' ); ?>>
                                        <span>±15 分钟</span>
                                    </label>
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai_gso_publish_time_jitter" value="1800" <?php checked( YIMAI_GSO_Settings::get( 'publish_time_jitter', '0' ), '1800' ); ?>>
                                        <span>±30 分钟</span>
                                    </label>
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai_gso_publish_time_jitter" value="3600" <?php checked( YIMAI_GSO_Settings::get( 'publish_time_jitter', '0' ), '3600' ); ?>>
                                        <span>±1 小时</span>
                                    </label>
                                </div>
                                <small class="yimai-gso-field-hint">开启后，批量发布/定时发布时每篇文章的发布时间会在设定基础上随机偏移，让发布时间更自然</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>仅在工作时间发布</label>
                                <label class="yimai-gso-radio">
                                    <input type="checkbox" name="yimai_gso_publish_work_hours_only" value="1" <?php checked( YIMAI_GSO_Settings::get( 'publish_work_hours_only', 0 ), 1 ); ?>>
                                    <span>限制在 8:00 - 23:00 发布</span>
                                </label>
                                <small class="yimai-gso-field-hint">开启后，定时发布时间如果落在非工作时间，会自动顺延到下一个工作时段</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>发布日跳过</label>
                                <div class="yimai-gso-checkbox-group">
                                    <?php
                                    $skip_days = YIMAI_GSO_Settings::get( 'publish_skip_days', array() );
                                    if ( ! is_array( $skip_days ) ) $skip_days = array();
                                    $weekdays = array( 1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 0 => '周日' );
                                    foreach ( $weekdays as $day_num => $day_label ) :
                                    ?>
                                    <label class="yimai-gso-radio" style="display:inline-flex;margin-right:8px;">
                                        <input type="checkbox" name="yimai_gso_publish_skip_days[]" value="<?php echo $day_num; ?>" <?php checked( in_array( $day_num, $skip_days ) ); ?>>
                                        <span><?php echo $day_label; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <small class="yimai-gso-field-hint">勾选的日期不会安排发布，定时文章会自动跳过这些日期</small>
                            </div>
                        </div>
                    </div>

                    <!-- 运行模式 -->
                    <div class="yimai-gso-settings-section" style="margin-top:20px;">
                        <div class="yimai-gso-settings-section-header">
                            <h2>⚙️ 运行模式</h2>
                            <p class="description">选择定时发布和永动发布的触发方式</p>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>触发模式</label>
                                <?php $current_mode = YIMAI_GSO_Settings::get( 'operation_mode', 'default' ); ?>
                                <div class="yimai-gso-radio-group yimai-gso-radio-group-stack">
                                    <label class="yimai-gso-radio">
                                        <input type="radio" name="yimai_gso_operation_mode" value="default" <?php checked( $current_mode, 'default' ); ?>>
                                        <span><strong>默认模式（访客触发）</strong></span>
                                    </label>
                                    <div style="margin:4px 0 0 24px;font-size:12px;color:#64748b;">
                                        插件安装后默认工作方式。当有访客访问网站时，WP-Cron 顺带触发执行定时发布和永动发布任务。无需额外配置，适合有稳定访问量的站点。
                                    </div>
                                    <label class="yimai-gso-radio" style="margin-top:16px;">
                                        <input type="radio" name="yimai_gso_operation_mode" value="precise" <?php checked( $current_mode, 'precise' ); ?>>
                                        <span><strong>精准模式（系统Cron）</strong></span>
                                    </label>
                                    <div style="margin:4px 0 0 24px;font-size:12px;color:#64748b;">
                                        在服务器配置系统级定时任务，由操作系统主动触发执行。定时发布和永动发布不受访客影响，准时执行。<br>
                                        选择此模式后，请在服务器上配置以下 Crontab（每1分钟触发一次）：
                                        <pre style="background:#1e293b;color:#e2e8f0;padding:10px;border-radius:6px;margin:8px 0;font-size:12px;overflow-x:auto;">* * * * * wget -q -O- https://<?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ?: 'yourdomain.com' ); ?>/wp-cron.php?doing_wp_cron=1 >/dev/null 2>&1</pre>
                                        然后在 <code>wp-config.php</code> 中添加：
                                        <pre style="background:#1e293b;color:#e2e8f0;padding:10px;border-radius:6px;margin:8px 0;font-size:12px;overflow-x:auto;">define('DISABLE_WP_CRON', true);</pre>
                                        配置后，定时发布守护和永动发布的触发延迟将从20秒缩短至5秒，单次处理上限从50篇提升至200篇。
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 前端摘要显示 -->
                    <div class="yimai-gso-settings-section" style="margin-top:20px;">
                        <div class="yimai-gso-settings-section-header">
                            <h2>📖 前端摘要显示</h2>
                            <p class="description">在文章页正文上方显示AI生成的摘要内容</p>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>文章页显示摘要</label>
                                <label class="yimai-gso-radio">
                                    <input type="checkbox" name="yimai_gso_frontend_excerpt_enabled" value="1" <?php checked( YIMAI_GSO_Settings::get( 'frontend_excerpt_enabled', 0 ), 1 ); ?>>
                                    <span>在文章页正文上方显示摘要</span>
                                </label>
                                <small class="yimai-gso-field-hint">开启后，已生成摘要的文章会在正文上方显示一个摘要卡片</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <label>摘要标签文字</label>
                                <input type="text" name="yimai_gso_frontend_excerpt_label" class="regular-text"
                                       value="<?php echo esc_attr( YIMAI_GSO_Settings::get( 'frontend_excerpt_label', '文章摘要' ) ); ?>"
                                       placeholder="文章摘要">
                                <small class="yimai-gso-field-hint">摘要框上方显示的标题文字，默认为【文章摘要】</small>
                            </div>
                            <div class="yimai-gso-field-group">
                                <!-- ★ 修复（审计 #9）：auto_excerpt_on_publish 原先是死设置键——
                                     publisher 读 2 处但无任何写入点与 UI，用户永远无法开启。
                                     补上开关：发布/更新文章时自动调用 AI 生成摘要（异步入队，不阻塞发布）。 -->
                                <label>发布时自动生成 AI 摘要</label>
                                <label class="yimai-gso-radio">
                                    <input type="checkbox" name="yimai_gso_auto_excerpt_on_publish" value="1" <?php checked( YIMAI_GSO_Settings::get( 'auto_excerpt_on_publish', 0 ), 1 ); ?>>
                                    <span>文章发布时自动调用 AI 生成摘要</span>
                                </label>
                                <small class="yimai-gso-field-hint">开启后，未填写摘要的文章在发布时会自动异步生成 AI 摘要（单篇生成的「自动摘要」勾选与此开关效果一致）</small>
                            </div>
                        </div>
                    </div>

                    <!-- 索引控制 (noindex) -->
                    <div class="yimai-gso-settings-section" style="margin-top:20px;">
                        <div class="yimai-gso-settings-section-header">
                            <h2>🔍 索引控制 (noindex)</h2>
                            <p class="description">控制哪些内容类型的页面不被搜索引擎收录</p>
                        </div>
                        <div class="yimai-gso-settings-fields">
                            <div class="yimai-gso-field-group">
                                <label>不索引的文章类型</label>
                                <div class="yimai-gso-checkbox-group">
                                    <?php
                                    $noindex_types = YIMAI_GSO_Settings::get( 'noindex_post_types', '' );
                                    $noindex_list  = array_filter( array_map( 'trim', explode( ',', $noindex_types ) ) );
                                    $supported     = YIMAI_GSO_Post_Type_Helper::get_supported();
                                    foreach ( $supported as $pt ) :
                                        $pt_obj = get_post_type_object( $pt );
                                        $pt_label = $pt_obj ? $pt_obj->labels->name : $pt;
                                    ?>
                                    <label class="yimai-gso-radio" style="display:inline-flex;margin-right:12px;margin-bottom:6px;">
                                        <input type="checkbox" name="yimai_gso_noindex_types[]" value="<?php echo esc_attr( $pt ); ?>"
                                               <?php checked( in_array( $pt, $noindex_list, true ) ); ?>>
                                        <span><?php echo esc_html( $pt_label ); ?> (<?php echo esc_html( $pt ); ?>)</span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <small class="yimai-gso-field-hint">勾选的文章类型将在全站范围内添加 noindex 标签。单篇文章可在编辑页的"索引状态"元数据框中单独覆盖。</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GEO实体设置 -->
                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'geo' ? 'active' : ''; ?>" data-tab="geo">
                    <?php $this->render_geo_entity_settings(); ?>
                </div>

                <!-- 爬虫策略设置 -->
                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'crawler' ? 'active' : ''; ?>" data-tab="crawler">
                    <?php $this->render_crawler_editor_settings(); ?>
                </div>

                <!-- 作者档案管理 -->
                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'authors' ? 'active' : ''; ?>" data-tab="authors">
                    <?php $this->render_author_profiles_page(); ?>
                </div>











                    <?php if ( in_array( $settings_subtab, array( 'ai', 'image', 'publish' ), true ) ) : ?>
                    <div class="yimai-gso-settings-footer">
                        <button type="submit" name="yimai_gso_save_settings" class="yimai-gso-save-btn">
                            <span class="dashicons dashicons-yes"></span>
                            保存设置
                        </button>
                    </div>
                    <?php endif; ?>
                </form>

                <!-- 品牌资料与知识检索（独立表单，避免嵌套 multipart 表单） -->
                <div class="yimai-gso-settings-panel <?php echo $settings_subtab === 'knowledge' ? 'active' : ''; ?>" data-tab="knowledge">
                    <?php if ( class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) : ?>
                        <?php YIMAI_GSO_Knowledge_Center::get_instance()->render_embedded_panel(); ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php // P7：原此处有 2,325 行内联 <style> 面板样式，已抽取至 admin/css/panels.css（由 enqueue_assets 以 handle 'yimai-gso-panels' 入队）。?>

            <?php if ( class_exists( 'YIMAI_GSO_L5_Dashboard' ) && YIMAI_GSO_L5_Dashboard::is_available() ) : ?>
            <?php YIMAI_GSO_L5_Dashboard::output_page_assets(); ?>
            <!-- ==================== L5-综合仪表盘 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'l5-overview' ? 'active' : ''; ?>" data-tab="l5-overview">
                <?php YIMAI_GSO_L5_Dashboard::get_instance()->render_overview_tab(); ?>
            </div>

            <!-- ==================== L5-AI爬虫追踪 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'l5-crawler' ? 'active' : ''; ?>" data-tab="l5-crawler">
                <?php YIMAI_GSO_L5_Dashboard::get_instance()->render_crawler_tab(); ?>
            </div>

            <!-- ==================== L5-爬虫友好度诊断 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'l5-diagnosis' ? 'active' : ''; ?>" data-tab="l5-diagnosis">
                <?php YIMAI_GSO_L5_Dashboard::get_instance()->render_diagnosis_tab(); ?>
            </div>

            <!-- ==================== L5-实时预警 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'l5-alerts' ? 'active' : ''; ?>" data-tab="l5-alerts">
                <?php YIMAI_GSO_L5_Dashboard::get_instance()->render_alerts_tab(); ?>
            </div>
            <?php endif; ?>

            <!-- ==================== 新鲜度监控 ==================== -->
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'freshness' ? 'active' : ''; ?>" data-tab="freshness">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-update"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">内容新鲜度监控</h2>
                        <p class="yimai-gso-card-desc">追踪文章更新状态，识别陈旧内容并及时刷新，保持 AI 搜索引擎对网站的信任度</p>
                    </div>
                </div>

                <?php $fresh_meta_panel = $this->get_feature_meta( 'content_freshness' ); ?>
                <?php if ( ! $fresh_meta_panel['available'] ) : ?>
                <?php endif; ?>

                <!-- 统计卡片 -->
                <div class="yimai-gso-dashboard-stats" id="yimai-gso-freshness-stats">
                    <div class="yimai-gso-stat-card yimai-gso-stat-success">
                        <div class="yimai-gso-stat-icon">🟢</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="fresh">-</div>
                            <div class="yimai-gso-stat-label">新鲜 (&lt;30天)</div>
                        </div>
                    </div>
                    <div class="yimai-gso-stat-card yimai-gso-stat-info">
                        <div class="yimai-gso-stat-icon">🔵</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="normal">-</div>
                            <div class="yimai-gso-stat-label">正常 (1-6月)</div>
                        </div>
                    </div>
                    <div class="yimai-gso-stat-card yimai-gso-stat-warning">
                        <div class="yimai-gso-stat-icon">🟠</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="stale">-</div>
                            <div class="yimai-gso-stat-label">可能过时 (6-12月)</div>
                        </div>
                    </div>
                    <div class="yimai-gso-stat-card yimai-gso-stat-danger">
                        <div class="yimai-gso-stat-icon">🔴</div>
                        <div class="yimai-gso-stat-content">
                            <div class="yimai-gso-stat-num" data-stat="old">-</div>
                            <div class="yimai-gso-stat-label">已过时 (&gt;12月)</div>
                        </div>
                    </div>
                </div>

                <!-- 全局新鲜度条 -->
                <div class="yimai-gso-freshness-bar-wrap">
                    <div class="yimai-gso-freshness-bar-label">
                        <span>整体新鲜度</span>
                        <strong id="yimai-gso-freshness-pct">-</strong>
                    </div>
                    <div class="yimai-gso-freshness-bar">
                        <div class="yimai-gso-freshness-bar-fill" id="yimai-gso-freshness-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- 筛选区 -->
                <div class="yimai-gso-history-filters">
                    <div class="yimai-gso-filter-row" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px">
                        <select id="yimai-gso-f-level">
                            <option value="">全部等级</option>
                            <option value="stale">可能过时</option>
                            <option value="old">已过时</option>
                            <option value="normal">正常</option>
                            <option value="fresh">新鲜</option>
                        </select>
                        <input type="search" id="yimai-gso-f-search" class="yimai-gso-search-input" placeholder="搜索标题...">
                        <button id="yimai-gso-f-search-btn" class="button">搜索</button>
                        <button id="yimai-gso-f-reset-btn" class="button">重置</button>
                        <span class="yimai-gso-batch-info" id="yimai-gso-f-batch-info" style="margin-left:auto;display:none">已选 <strong id="yimai-gso-f-selected-count">0</strong> 篇</span>
                        <button id="yimai-gso-f-select-all" class="button" style="margin-left:8px">全选当前页</button>
                        <button id="yimai-gso-f-batch-fresh" class="button button-primary">标记为已审核</button>
                    </div>
                </div>

                <!-- 文章列表 -->
                <div class="yimai-gso-history-list" id="yimai-gso-freshness-list">
                    <div class="yimai-gso-loading-state">
                        <span class="yimai-gso-loading"></span>加载中...
                    </div>
                </div>

                <!-- 分页 -->
                <div class="yimai-gso-history-pagination" id="yimai-gso-freshness-pagination"></div>
            </div>

        </main>
    </div>

        <?php
    }


    /**
     * 渲染 GEO 实体设置子页面
     */
    private function render_geo_entity_settings() {
        $entity       = YIMAI_GSO_GEO_Entity::get_entity_data();
        $entity_types = YIMAI_GSO_GEO_Entity::ENTITY_TYPES;
        $platforms    = YIMAI_GSO_GEO_Entity::SAMEAS_PLATFORMS;
        // （原 $feature_ok = true + if(!$feature_ok) 恒假门控残留已按审计 B-4 删除）
        ?>
        <div class="yimai-gso-settings-section">
            <div class="yimai-gso-settings-section-header">
                <h2>🏢 实体信息定义</h2>
                <p class="description">定义网站/品牌的实体信息，用于 Knowledge Graph 和所有 Schema 标记</p>
            </div>
            <div class="yimai-gso-settings-fields">
                <div class="yimai-gso-field-group">
                    <label>实体名称</label>
                    <input type="text" id="geo-entity-name" class="regular-text"
                           value="<?php echo esc_attr( $entity['entity_name'] ); ?>"
                           placeholder="例如：一麦瑜伽">
                </div>
                <div class="yimai-gso-field-group">
                    <label>实体类型</label>
                    <select id="geo-entity-type">
                        <?php foreach ( $entity_types as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $entity['entity_type'], $key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="yimai-gso-field-hint">选择最匹配您网站/品牌的类型。Organization 适用于公司，Person 适用于个人博主，LocalBusiness 适用于本地商家</small>
                </div>
                <div class="yimai-gso-field-group">
                    <label>实体 Logo URL</label>
                    <input type="text" id="geo-entity-logo" class="regular-text"
                           value="<?php echo esc_attr( $entity['entity_logo'] ); ?>"
                           placeholder="留空则使用主题Logo">
                    <small class="yimai-gso-field-hint">建议尺寸 512×512，留空则自动使用站点图标(site icon)或自定义Logo</small>
                </div>
                <div class="yimai-gso-field-group">
                    <label>实体描述</label>
                    <textarea id="geo-entity-description" rows="3" class="large-text"
                              placeholder="简要描述您的公司/品牌"><?php echo esc_textarea( $entity['entity_description'] ); ?></textarea>
                </div>
                <div class="yimai-gso-field-group">
                    <label>联系邮箱</label>
                    <input type="email" id="geo-entity-email" class="regular-text"
                           value="<?php echo esc_attr( $entity['entity_email'] ); ?>">
                </div>
                <div class="yimai-gso-field-group">
                    <label>联系电话</label>
                    <input type="text" id="geo-entity-phone" class="regular-text"
                           value="<?php echo esc_attr( $entity['entity_phone'] ); ?>">
                </div>
                <?php if ( 'LocalBusiness' === $entity['entity_type'] ) : ?>
                <div class="yimai-gso-field-group">
                    <label>实体地址</label>
                    <input type="text" id="geo-entity-address" class="regular-text"
                           value="<?php echo esc_attr( $entity['entity_address'] ); ?>"
                           placeholder="省/市/区/街道 详细地址">
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="yimai-gso-settings-section" style="margin-top:20px;">
            <div class="yimai-gso-settings-section-header">
                <h2>🔗 sameAs 身份验证链接</h2>
                <p class="description">添加您在各大平台的官方页面链接，帮助 AI 搜索引擎验证您的实体身份</p>
            </div>
            <div class="yimai-gso-settings-fields">
                <div class="yimai-gso-field-group">
                    <label>快速添加平台</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
                        <?php foreach ( $platforms as $key => $plat ) : ?>
                        <button type="button" class="button button-small geo-add-platform-btn"
                                data-platform="<?php echo esc_attr( $key ); ?>"
                                data-label="<?php echo esc_attr( $plat['label'] ); ?>">
                            + <?php echo esc_html( $plat['label'] ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="yimai-gso-field-group">
                    <label>sameAs 链接列表</label>
                    <div id="geo-sameas-list" style="display:flex;flex-direction:column;gap:8px;">
                        <?php
                        $same_as = $entity['same_as'];
                        if ( empty( $same_as ) ) {
                            $same_as = array( '' );
                        }
                        foreach ( $same_as as $index => $url ) :
                        ?>
                        <div class="geo-sameas-row" style="display:flex;gap:8px;align-items:center;">
                            <input type="url" class="regular-text geo-sameas-url"
                                   value="<?php echo esc_attr( $url ); ?>"
                                   placeholder="https://..."
                                   style="flex:1;">
                            <button type="button" class="button button-small geo-check-link-btn"
                                    data-index="<?php echo $index; ?>" title="检测链接可访问性">
                                <span class="dashicons dashicons-search"></span>
                            </button>
                            <button type="button" class="button button-small geo-remove-row-btn"
                                    title="删除此链接">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                            <span class="geo-link-status" style="font-size:11px;min-width:80px;"></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="geo-add-row-btn">
                        <span class="dashicons dashicons-plus"></span> 添加链接
                    </button>
                    <small class="yimai-gso-field-hint">建议添加 3-5 个权威平台的官方链接，AI 通过这些链接交叉验证您的实体身份</small>
                </div>
            </div>
        </div>

        <div class="yimai-gso-settings-footer" style="margin-top:24px;">
            <button type="button" id="geo-save-btn" class="yimai-gso-save-btn">
                <span class="dashicons dashicons-yes"></span>
                保存实体配置
            </button>
            <span id="geo-save-status" style="margin-left:12px;font-size:13px;"></span>
        </div>

        <script>
        jQuery(function($) {
            // 添加 sameAs 行
            $('#geo-add-row-btn').on('click', function() {
                var row = '<div class="geo-sameas-row" style="display:flex;gap:8px;align-items:center;">' +
                    '<input type="url" class="regular-text geo-sameas-url" placeholder="https://..." style="flex:1;">' +
                    '<button type="button" class="button button-small geo-check-link-btn" title="检测链接可访问性"><span class="dashicons dashicons-search"></span></button>' +
                    '<button type="button" class="button button-small geo-remove-row-btn" title="删除此链接"><span class="dashicons dashicons-no-alt"></span></button>' +
                    '<span class="geo-link-status" style="font-size:11px;min-width:80px;"></span>' +
                    '</div>';
                $('#geo-sameas-list').append(row);
            });

            // 删除 sameAs 行
            $(document).on('click', '.geo-remove-row-btn', function() {
                var rows = $('#geo-sameas-list .geo-sameas-row');
                if (rows.length > 1) {
                    $(this).closest('.geo-sameas-row').remove();
                }
            });

            // 快速添加平台链接
            $(document).on('click', '.geo-add-platform-btn', function() {
                var plat = $(this).data('platform');
                var url  = '';
                if (['zhihu','baidu_baike','tianyancha'].indexOf(plat) >= 0) {
                    url = prompt('请输入 ' + $(this).data('label') + ' 链接:');
                } else {
                    url = prompt('请输入您的 ' + $(this).data('label') + ' 主页完整 URL:');
                }
                if (url && url.trim()) {
                    var rows = $('#geo-sameas-list .geo-sameas-row');
                    var lastRow = rows.last();
                    var lastVal = lastRow.find('.geo-sameas-url').val().trim();
                    if (lastVal === '') {
                        lastRow.find('.geo-sameas-url').val(url.trim());
                    } else {
                        $('#geo-add-row-btn').trigger('click');
                        $('#geo-sameas-list .geo-sameas-row').last().find('.geo-sameas-url').val(url.trim());
                    }
                }
            });

            // 检测链接可访问性
            $(document).on('click', '.geo-check-link-btn', function() {
                var row = $(this).closest('.geo-sameas-row');
                var url = row.find('.geo-sameas-url').val().trim();
                var statusEl = row.find('.geo-link-status');
                if (!url) { statusEl.html('<span style="color:#f59e0b;">URL为空</span>'); return; }
                statusEl.html('<span style="color:#6b7280;">检测中...</span>');
                $.post(ajaxurl, {
                    action: 'yimai_gso_geo_entity_check_link',
                    nonce: '<?php echo wp_create_nonce( 'yimai_gso_nonce' ); ?>',
                    url: url
                }, function(res) {
                    if (res.success) {
                        var d = res.data;
                        statusEl.html(d.accessible
                            ? '<span style="color:#059669;">可达 (' + d.status + ')</span>'
                            : '<span style="color:#dc2626;">' + d.message + '</span>');
                    } else {
                        statusEl.html('<span style="color:#dc2626;">检测失败</span>');
                    }
                }).fail(function() {
                    statusEl.html('<span style="color:#dc2626;">网络错误</span>');
                });
            });

            // 保存实体配置
            $('#geo-save-btn').on('click', function() {
                var btn = $(this);
                var statusEl = $('#geo-save-status');
                btn.prop('disabled', true);
                statusEl.html('<span style="color:#6b7280;">保存中...</span>');

                var sameas = [];
                $('.geo-sameas-url').each(function() {
                    var v = $(this).val().trim();
                    if (v) sameas.push(v);
                });

                $.post(ajaxurl, {
                    action: 'yimai_gso_geo_entity_save',
                    nonce: '<?php echo wp_create_nonce( 'yimai_gso_nonce' ); ?>',
                    entity_name: $('#geo-entity-name').val(),
                    entity_type: $('#geo-entity-type').val(),
                    entity_logo: $('#geo-entity-logo').val(),
                    entity_description: $('#geo-entity-description').val(),
                    entity_email: $('#geo-entity-email').val(),
                    entity_phone: $('#geo-entity-phone').val(),
                    entity_address: $('#geo-entity-address').length ? $('#geo-entity-address').val() : '',
                    same_as: sameas
                }, function(res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        statusEl.html('<span style="color:#059669;">保存成功！</span>');
                        setTimeout(function() { statusEl.html(''); }, 3000);
                    } else {
                        statusEl.html('<span style="color:#dc2626;">保存失败: ' + (res.data && res.data.message ? res.data.message : '未知错误') + '</span>');
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    statusEl.html('<span style="color:#dc2626;">网络错误，请重试</span>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * 渲染 AI 爬虫策略编辑器
     */
    private function render_crawler_editor_settings() {
        $rules     = YIMAI_GSO_Crawler_Editor::get_rules();
        $crawlers  = YIMAI_GSO_Crawler_Editor::CRAWLERS;
        $templates = YIMAI_GSO_Crawler_Editor::TEMPLATES;
        // （原 $feature_ok = true && true + if(!$feature_ok) 恒假门控残留已按审计 B-4 同源清理）
        ?>
        <div class="yimai-gso-settings-section">
            <div class="yimai-gso-settings-section-header">
                <h2><span class="dashicons dashicons-visibility"></span> AI 爬虫访问策略</h2>
                <p class="description">控制哪些 AI 平台的爬虫可以访问您的网站内容。在 robots.txt 中动态生成对应规则</p>
            </div>

            <!-- 策略模板 -->
            <div class="yimai-gso-settings-fields">
                <div class="yimai-gso-field-group">
                    <label>快速策略模板</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                        <?php foreach ( $templates as $key => $tmpl ) : ?>
                        <button type="button" class="button button-small crawler-template-btn"
                                data-template="<?php echo esc_attr( $key ); ?>">
                            <?php echo esc_html( $tmpl['label'] ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <small class="yimai-gso-field-hint">点击模板一键应用。修改下方的单个爬虫开关后，模板按钮不会自动切换</small>
                </div>
            </div>
        </div>

        <div class="yimai-gso-settings-section" style="margin-top:20px;">
            <div class="yimai-gso-settings-section-header">
                <h2>📋 单个爬虫控制</h2>
                <p class="description">开启 → 允许访问，关闭 → 阻止访问。 
                    <span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">
                        <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#fef2f2;color:#dc2626;">🔴 训练型</span>
                        <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#ecfdf5;color:#059669;">🟢 搜索型</span>
                        <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#eff6ff;color:#2563eb;">🔵 用户触发型</span>
                    </span>
                </p>
            </div>
            <div class="yimai-gso-settings-fields">
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #e5e7eb;">
                            <th style="padding:8px 0;text-align:left;width:50px;">状态</th>
                            <th style="padding:8px 0;text-align:left;">爬虫名称</th>
                            <th style="padding:8px 0;text-align:left;width:80px;">类型</th>
                            <th style="padding:8px 0;text-align:left;">所属平台</th>
                            <th style="padding:8px 0;text-align:left;">标识符</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $crawlers as $key => $name ) : 
                            $family_key = '';
                            $crawler_type = '';
                            if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
                                $crawlers_map = YIMAI_GSO_Crawler_Tracker::KNOWN_CRAWLERS;
                                $family_key   = isset( $crawlers_map[ $key ] ) ? $crawlers_map[ $key ]['family'] : '';
                                $crawler_type = isset( $crawlers_map[ $key ] ) ? ( $crawlers_map[ $key ]['type'] ?? '' ) : '';
                            }
                            $type_badges = array(
                                'training' => array( 'label' => '训练型', 'bg' => '#fef2f2', 'color' => '#dc2626' ),
                                'search'   => array( 'label' => '搜索型', 'bg' => '#ecfdf5', 'color' => '#059669' ),
                                'user'     => array( 'label' => '用户型', 'bg' => '#eff6ff', 'color' => '#2563eb' ),
                            );
                            $badge = $type_badges[ $crawler_type ] ?? null;
                        ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:6px 0;">
                                <label class="crawler-toggle" style="display:inline-block;position:relative;width:42px;height:22px;">
                                    <input type="checkbox" class="crawler-rule-check"
                                           data-crawler="<?php echo esc_attr( $key ); ?>"
                                           <?php checked( ( isset( $rules[ $key ] ) ? $rules[ $key ] : 'allow' ), 'allow' ); ?>
                                           style="display:none;">
                                    <span class="crawler-toggle-slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;
                                        background-color:<?php echo ( isset( $rules[ $key ] ) && $rules[ $key ] === 'disallow' ) ? '#dc2626' : '#059669'; ?>;
                                        border-radius:22px;transition:0.3s;"></span>
                                </label>
                            </td>
                            <td style="padding:6px 0;"><?php echo esc_html( $name ); ?></td>
                            <td style="padding:6px 0;">
                                <?php if ( $badge ) : ?>
                                <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo $badge['bg']; ?>;color:<?php echo $badge['color']; ?>;"><?php echo esc_html( $badge['label'] ); ?></span>
                                <?php else: ?>
                                <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px 0;font-size:11px;color:#6b7280;">
                                <?php
                                $families = array(
                                    'openai' => 'OpenAI', 'google' => 'Google', 'anthropic' => 'Anthropic',
                                    'perplexity' => 'Perplexity', 'deepseek' => 'DeepSeek', 'bytedance' => '字节跳动',
                                    'microsoft' => '微软', 'baidu' => '百度', 'apple' => 'Apple',
                                    'meta' => 'Meta', 'alibaba' => '阿里巴巴', 'sogou' => '搜狗', '360' => '360',
                                    'research' => '研究机构', 'moonshot' => '月之暗面', 'tencent' => '腾讯',
                                );
                                echo isset( $families[ $family_key ] ) ? esc_html( $families[ $family_key ] ) : '—';
                                ?>
                            </td>
                            <td style="padding:6px 0;font-size:11px;color:#9ca3af;font-family:monospace;"><?php echo esc_html( $key ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="yimai-gso-settings-footer" style="margin-top:24px;">
            <button type="button" id="crawler-save-btn" class="yimai-gso-save-btn">
                <span class="dashicons dashicons-yes"></span>
                保存爬虫策略
            </button>
            <span id="crawler-save-status" style="margin-left:12px;font-size:13px;"></span>
        </div>

        <script>
        jQuery(function($) {
            // 模板一键应用
            $('.crawler-template-btn').on('click', function() {
                var tmpl = $(this).data('template');
                if (!confirm('应用"' + $(this).text().trim() + '"模板？这将覆盖当前的爬虫设置。')) return;
                $(this).prop('disabled', true).text('应用���...');
                $.post(ajaxurl, {
                    action: 'yimai_gso_crawler_save_rules',
                    nonce: '<?php echo wp_create_nonce( "yimai_gso_nonce" ); ?>',
                    template: tmpl
                }, function(res) {
                    $('.crawler-template-btn').prop('disabled', false).each(function() {
                        var t = $(this).data('template');
                        $(this).text('<?php foreach($templates as $k=>$v) echo "if(t===\"$k\") $(this).text(\"{$v['label']}\");"; ?>');
                    });
                    if (res.success && res.data && res.data.rules) {
                        $('.crawler-rule-check').each(function() {
                            var key = $(this).data('crawler');
                            var action = res.data.rules[key] || 'allow';
                            $(this).prop('checked', action === 'allow');
                            $(this).siblings('.crawler-toggle-slider').css('background-color', action === 'allow' ? '#059669' : '#dc2626');
                        });
                        $('#crawler-save-status').html('<span style="color:#059669;">模板已应用</span>');
                    }
                });
            });

            // 单个切换动画（纯CSS，只需更新保存按钮状态）
            $(document).on('change', '.crawler-rule-check', function() {
                var checked = $(this).is(':checked');
                $(this).siblings('.crawler-toggle-slider').css('background-color', checked ? '#059669' : '#dc2626');
            });

            // 保存按钮
            $('#crawler-save-btn').on('click', function() {
                var btn = $(this);
                var statusEl = $('#crawler-save-status');
                btn.prop('disabled', true);
                statusEl.html('<span style="color:#6b7280;">保存中...</span>');

                var rules = {};
                $('.crawler-rule-check').each(function() {
                    rules[$(this).data('crawler')] = $(this).is(':checked') ? 'allow' : 'disallow';
                });

                $.post(ajaxurl, {
                    action: 'yimai_gso_crawler_save_rules',
                    nonce: '<?php echo wp_create_nonce( "yimai_gso_nonce" ); ?>',
                    rules: rules
                }, function(res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        statusEl.html('<span style="color:#059669;">保存成功！</span>');
                        setTimeout(function() { statusEl.html(''); }, 3000);
                    } else {
                        statusEl.html('<span style="color:#dc2626;">保存失败</span>');
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    statusEl.html('<span style="color:#dc2626;">网络错误</span>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * 渲染作者档案管理页面
     */
    private function render_author_profiles_page() {
        $users = get_users( array( 'capability' => 'edit_posts', 'orderby' => 'display_name' ) );
        ?>
        <div class="yimai-gso-settings-section">
            <div class="yimai-gso-settings-section-header">
                <h2>👤 作者专业档案</h2>
                <p class="description">为每位作者填写专业信息，生成 Person Schema 和 EEAT 信号。作者在 WordPress 用户编辑页也可以单独修改</p>
            </div>
        </div>

        <table class="widefat striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:60px;">头像</th>
                    <th>用户名</th>
                    <th>职务/头衔</th>
                    <th>擅长领域</th>
                    <th style="width:50px;">年限</th>
                    <th style="width:100px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $users as $user ) :
                    $title     = get_user_meta( $user->ID, 'yimai_gso_author_title', true );
                    $expertise = get_user_meta( $user->ID, 'yimai_gso_author_expertise', true );
                    $years     = get_user_meta( $user->ID, 'yimai_gso_author_years', true );
                    $has_data  = ( ! empty( $title ) || ! empty( $expertise ) || ! empty( $years ) );
                ?>
                <tr class="author-row" data-user-id="<?php echo $user->ID; ?>">
                    <td><?php echo get_avatar( $user->ID, 36 ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $user->display_name ); ?></strong>
                        <br><small style="color:#6b7280;"><?php echo esc_html( $user->user_email ); ?></small>
                        <?php if ( ! $has_data ) : ?>
                        <br><small style="color:#dc2626;">未填写 GEO 信息</small>
                        <?php endif; ?>
                    </td>
                    <td><span class="author-cell-title"><?php echo esc_html( $title ?: '—' ); ?></span></td>
                    <td><span class="author-cell-expertise"><?php echo esc_html( $expertise ?: '—' ); ?></span></td>
                    <td><span class="author-cell-years"><?php echo $years ? esc_html( $years ) : '—'; ?></span></td>
                    <td>
                        <button type="button" class="button button-small author-edit-btn">
                            <span class="dashicons dashicons-edit" style="font-size:14px;vertical-align:middle;"></span> 编辑
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- 编辑弹窗 -->
        <div id="author-edit-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:99999;">
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;padding:28px;width:480px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.2);">
                <h3 style="margin:0 0 16px;">编辑作者 GEO 信息</h3>
                <input type="hidden" id="author-edit-id">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">职务/头衔</label>
                    <input type="text" id="author-edit-title" class="regular-text" placeholder="如：高级SEO工程师、创始人兼CEO" style="width:100%;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">擅长领域</label>
                    <input type="text" id="author-edit-expertise" class="regular-text" placeholder="如：SEO优化、内容营销、AI应用" style="width:100%;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">从业年限</label>
                    <input type="number" id="author-edit-years" min="0" max="100" style="width:80px;" placeholder="如 10">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">资质证书</label>
                    <textarea id="author-edit-certs" rows="3" class="large-text" placeholder="每行一个" style="width:100%;"></textarea>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">sameAs 链接</label>
                    <textarea id="author-edit-sameas" rows="2" class="large-text" placeholder="每行一个URL" style="width:100%;"></textarea>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="button" id="author-edit-cancel">取消</button>
                    <button type="button" class="button button-primary" id="author-edit-save">保存</button>
                </div>
                <span id="author-edit-status" style="font-size:12px;margin-left:8px;"></span>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var modal = $('#author-edit-modal');

            // 打开编辑弹窗
            $(document).on('click', '.author-edit-btn', function() {
                var row = $(this).closest('.author-row');
                var uid = row.data('user-id');
                $('#author-edit-id').val(uid);
                $('#author-edit-title').val(row.find('.author-cell-title').text().trim() === '—' ? '' : row.find('.author-cell-title').text().trim());
                $('#author-edit-expertise').val(row.find('.author-cell-expertise').text().trim() === '—' ? '' : row.find('.author-cell-expertise').text().trim());
                $('#author-edit-years').val(row.find('.author-cell-years').text().trim() === '—' ? '' : row.find('.author-cell-years').text().trim());
                $('#author-edit-certs').val('');
                $('#author-edit-sameas').val('');
                $('#author-edit-status').html('');
                modal.show();
            });

            // 关闭弹窗
            $('#author-edit-cancel').on('click', function() { modal.hide(); });

            // 保存
            $('#author-edit-save').on('click', function() {
                var uid = $('#author-edit-id').val();
                var btn = $(this);
                btn.prop('disabled', true).text('保存中...');
                $.post(ajaxurl, {
                    action: 'yimai_gso_save_author_profile',
                    nonce: '<?php echo wp_create_nonce("yimai_gso_nonce"); ?>',
                    user_id: uid,
                    title: $('#author-edit-title').val(),
                    expertise: $('#author-edit-expertise').val(),
                    years: $('#author-edit-years').val(),
                    certs: $('#author-edit-certs').val(),
                    sameas: $('#author-edit-sameas').val()
                }, function(res) {
                    btn.prop('disabled', false).text('保存');
                    if (res.success) {
                        var row = $('.author-row[data-user-id="' + uid + '"]');
                        row.find('.author-cell-title').text($('#author-edit-title').val() || '—');
                        row.find('.author-cell-expertise').text($('#author-edit-expertise').val() || '—');
                        row.find('.author-cell-years').text($('#author-edit-years').val() || '—');
                        $('#author-edit-status').html('<span style="color:#059669;">保存成功</span>');
                        setTimeout(function() { modal.hide(); }, 800);
                    } else {
                        $('#author-edit-status').html('<span style="color:#dc2626;">保存失败</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function save_settings() {
        $fields = array(
            'ai_provider', 'ai_api_key', 'ai_model', 'ai_api_url',
            'image_provider', 'pexels_api_key', 'unsplash_api_key', 'image_api_key',
            'flickr_api_key', 'stockapi_api_key', 'custom_img_api_key', 'custom_img_api_url',
            'dalle_model', 'default_category', 'default_status',
            'article_language', 'word_count', 'image_count', 'system_prompt', 'user_prompt', 'image_prompt',
            'auto_image_alt_enabled', 'auto_image_alt_mode',
            // AI 生图配置
            'ai_image_provider', 'ai_image_model', 'ai_image_size', 'ai_image_style',
            'ai_image_doubao_key', 'ai_image_zhipu_key', 'ai_image_wanxiang_key',
            'ai_image_custom_prompt',
            // MT Photos 配图
            'mtphotos_host', 'mtphotos_api_key', 'mtphotos_username', 'mtphotos_password',
            'mtphotos_review_strictness', 'mtphotos_review_failover',
            'publish_time_jitter',
            'frontend_excerpt_enabled', 'frontend_excerpt_label',
            'auto_excerpt_on_publish', // ★ 审计 #9：补上死设置键的写入端（checkbox 未勾选不提交，需置 0）
            'operation_mode',
        );
        foreach ( $fields as $field ) {
            $prefixed_key = 'yimai_gso_' . $field;
            $raw_key = $field;

            if ( isset( $_POST[ $prefixed_key ] ) ) {
                $val = in_array( $field, array( 'system_prompt', 'user_prompt', 'image_prompt', 'ai_image_custom_prompt' ) )
                    ? sanitize_textarea_field( wp_unslash( $_POST[ $prefixed_key ] ) )
                    : sanitize_text_field( $_POST[ $prefixed_key ] );

                // API Key 掩码保护：如果提交的是掩码占位符（****xxx），跳过更新保留原Key
                if ( in_array( $field, array( 'ai_api_key', 'ai_image_doubao_key', 'ai_image_zhipu_key', 'ai_image_wanxiang_key' ), true ) && YIMAI_GSO_Settings::is_masked_value( $val ) ) {
                    continue;
                }

                YIMAI_GSO_Settings::set( $field, $val );
            } elseif ( isset( $_POST[ $raw_key ] ) ) {
                $val = in_array( $field, array( 'system_prompt', 'user_prompt', 'image_prompt', 'ai_image_custom_prompt' ) )
                    ? sanitize_textarea_field( wp_unslash( $_POST[ $raw_key ] ) )
                    : sanitize_text_field( $_POST[ $raw_key ] );

                // AI API Key 掩码保护
                if ( 'ai_api_key' === $field && YIMAI_GSO_Settings::is_masked_value( $val ) ) {
                    continue;
                }

                YIMAI_GSO_Settings::set( $field, $val );
            } elseif ( 'auto_image_alt_enabled' === $field ) {
                $marker_present = isset( $_POST['yimai_gso_auto_image_alt_enabled_present'] ) || isset( $_POST['auto_image_alt_enabled_present'] );
                if ( $marker_present && ! isset( $_POST['yimai_gso_auto_image_alt_enabled'] ) && ! isset( $_POST['auto_image_alt_enabled'] ) ) {
                    update_option( 'yimai_gso_auto_image_alt_enabled', 0 );
                }
            } elseif ( 'mtphotos_review_enabled' === $field ) {
                // checkbox 未勾选时不提交 → 直接置 0（MT Photos 审核开关）
                update_option( 'yimai_gso_mtphotos_review_enabled', isset( $_POST['yimai_gso_mtphotos_review_enabled'] ) ? 1 : 0 );
            } elseif ( 'frontend_excerpt_enabled' === $field ) {
                if ( ! isset( $_POST[ 'yimai_gso_frontend_excerpt_enabled' ] ) && ! isset( $_POST[ 'frontend_excerpt_enabled' ] ) ) {
                    update_option( 'yimai_gso_frontend_excerpt_enabled', 0 );
                }
            } elseif ( 'auto_excerpt_on_publish' === $field ) {
                // ★ 审计 #9：checkbox 未勾选时不提交 → 直接置 0（发布时自动 AI 摘要开关）
                update_option( 'yimai_gso_auto_excerpt_on_publish', isset( $_POST['yimai_gso_auto_excerpt_on_publish'] ) || isset( $_POST['auto_excerpt_on_publish'] ) ? 1 : 0 );
            }
        }

        // 发布时间策略 - 仅工作时间 checkbox
        $work_hours_key = 'yimai_gso_publish_work_hours_only';
        update_option( 'yimai_gso_publish_work_hours_only', isset( $_POST[ $work_hours_key ] ) ? 1 : 0 );

        // 发布时间策略 - 跳过日期数组
        $skip_days = isset( $_POST['yimai_gso_publish_skip_days'] ) && is_array( $_POST['yimai_gso_publish_skip_days'] )
            ? array_map( 'absint', $_POST['yimai_gso_publish_skip_days'] )
            : array();
        update_option( 'yimai_gso_publish_skip_days', $skip_days );

        // Schema 类型开关（数组类型，需要特殊处理）
        if ( isset( $_POST['yimai_gso_schema_enabled_types'] ) && is_array( $_POST['yimai_gso_schema_enabled_types'] ) ) {
            $schema_types = array_map( 'sanitize_text_field', $_POST['yimai_gso_schema_enabled_types'] );
            update_option( 'yimai_gso_schema_enabled_types', $schema_types );
        }

        // 本地图片库配置（JSON 编码）
        $local_group_id = isset( $_POST['yimai_gso_local_image_group_id'] ) ? absint( $_POST['yimai_gso_local_image_group_id'] ) : 0;
        $local_min_w    = isset( $_POST['yimai_gso_local_image_min_weight'] ) ? max( 1, min( 10, absint( $_POST['yimai_gso_local_image_min_weight'] ) ) ) : 1;
        YIMAI_GSO_Settings::set( 'local_image_config', json_encode( array(
            'group_id'   => $local_group_id,
            'min_weight' => $local_min_w,
        ), JSON_UNESCAPED_UNICODE ) );

        // 索引控制 - noindex_post_types 数组（仅在发布设置Tab提交时处理）
        $current_subtab = isset( $_POST['yimai_gso_current_subtab'] ) ? sanitize_text_field( $_POST['yimai_gso_current_subtab'] ) : 'ai';
        if ( 'publish' === $current_subtab ) {
            $noindex_types = isset( $_POST['yimai_gso_noindex_types'] ) && is_array( $_POST['yimai_gso_noindex_types'] )
                ? implode( ',', array_map( 'sanitize_text_field', $_POST['yimai_gso_noindex_types'] ) )
                : '';
            update_option( 'yimai_gso_noindex_post_types', $noindex_types );
        }

        // 清除分类缓存
        delete_transient( 'yimai_gso_categories_raw' );
    }

    /* ========================================================
     * 图片库 AJAX 处理方法
     * ======================================================== */

    public function ajax_image_library_list() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $result = YIMAI_GSO_Image_Library::get_images( array(
            'page'     => absint( $_POST['page'] ?? 1 ),
            'per_page' => absint( $_POST['per_page'] ?? 20 ),
            'search'   => sanitize_text_field( $_POST['search'] ?? '' ),
            'group_id' => absint( $_POST['group_id'] ?? 0 ),
            'orderby'  => sanitize_key( $_POST['orderby'] ?? 'date' ),
            'order'    => sanitize_text_field( $_POST['order'] ?? 'DESC' ),
        ) );
        wp_send_json_success( $result );
    }

    public function ajax_image_library_upload() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
        $group_id = absint( $_POST['group_id'] ?? 0 );
        if ( $attachment_id > 0 ) {
            $result = YIMAI_GSO_Image_Library::add_attachment_to_library( $attachment_id, $group_id );
        } elseif ( ! empty( $_FILES['file'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $result = media_handle_upload( 'file', 0 );
            if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => '上传失败：' . $result->get_error_message() ) ); }
            $result = YIMAI_GSO_Image_Library::add_attachment_to_library( $result, $group_id );
        } else {
            wp_send_json_error( array( 'message' => '请选择要上传的图片' ) );
        }
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
        $img = array(
            'id' => $result, 'url' => wp_get_attachment_url( $result ),
            'thumb' => wp_get_attachment_image_url( $result, 'medium' ) ?: wp_get_attachment_url( $result ),
            'alt' => get_post_meta( $result, '_wp_attachment_image_alt', true ),
            'title' => get_the_title( $result ), 'weight' => 5, 'keywords' => '',
            'used_count' => 0, 'last_used' => '',
            'filename' => basename( wp_get_attachment_url( $result ) ),
            'date' => current_time( 'mysql' ),
        );
        $groups = wp_get_object_terms( $result, YIMAI_GSO_Image_Library::TAXONOMY, array( 'fields' => 'names' ) );
        $img['groups'] = is_wp_error( $groups ) ? array() : $groups;
        wp_send_json_success( array( 'image' => $img ) );
    }

    public function ajax_image_library_update() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $id = absint( $_POST['id'] ?? 0 ); $data = array();
        if ( isset( $_POST['weight'] ) )   $data['weight'] = absint( $_POST['weight'] );
        if ( isset( $_POST['keywords'] ) ) $data['keywords'] = sanitize_text_field( $_POST['keywords'] );
        if ( isset( $_POST['group_id'] ) ) $data['group_id'] = absint( $_POST['group_id'] );
        if ( empty( $data ) ) wp_send_json_error( array( 'message' => '没有需要更新的数据' ) );
        $result = YIMAI_GSO_Image_Library::update_image( $id, $data );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        wp_send_json_success( array( 'message' => '更新成功' ) );
    }

    public function ajax_image_library_batch_update() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        $data = array();
        if ( isset( $_POST['weight'] ) )   $data['weight'] = absint( $_POST['weight'] );
        if ( isset( $_POST['keywords'] ) ) $data['keywords'] = sanitize_text_field( $_POST['keywords'] );
        if ( isset( $_POST['group_id'] ) ) $data['group_id'] = absint( $_POST['group_id'] );
        if ( empty( $ids ) || empty( $data ) ) wp_send_json_error( array( 'message' => '请选择图片和要修改的属性' ) );
        $updated = YIMAI_GSO_Image_Library::batch_update( $ids, $data );
        wp_send_json_success( array( 'message' => '已更新 ' . $updated . ' 张图片', 'updated' => $updated ) );
    }

    public function ajax_image_library_delete() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'delete_posts' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        if ( empty( $ids ) ) wp_send_json_error( array( 'message' => '请选择要删除的图片' ) );
        $deleted = YIMAI_GSO_Image_Library::delete_images( $ids );
        wp_send_json_success( array( 'message' => '已删除 ' . $deleted . ' 张图片', 'deleted' => $deleted ) );
    }

    public function ajax_image_library_stats() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        wp_send_json_success( YIMAI_GSO_Image_Library::get_stats() );
    }

    public function ajax_image_library_groups() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        wp_send_json_success( array( 'groups' => YIMAI_GSO_Image_Library::get_groups( 0 ) ) );
    }

    public function ajax_image_library_group_create() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $result = YIMAI_GSO_Image_Library::create_group( sanitize_text_field( $_POST['name'] ?? '' ), absint( $_POST['parent_id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        wp_send_json_success( array( 'group' => $result, 'message' => '分组创建成功' ) );
    }

    public function ajax_image_library_group_rename() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $result = YIMAI_GSO_Image_Library::rename_group( absint( $_POST['term_id'] ?? 0 ), sanitize_text_field( $_POST['name'] ?? '' ) );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        wp_send_json_success( array( 'message' => '分组已重命名' ) );
    }

    public function ajax_image_library_group_delete() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => '权限不足' ) ); }
        $result = YIMAI_GSO_Image_Library::delete_group( absint( $_POST['term_id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        wp_send_json_success( array( 'message' => '分组已删除' ) );
    }

    /**
     * AJAX：关闭 Cron 系统提示，7天内不再显示
     */
    /**
     * 分类概览：全部文章分类 + 是否为标准分类/演示分类
     */
    private function get_category_overview() {
        $standard = class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ? YIMAI_GSO_Autopilot_Plan::get_standard_categories() : array();
        $demo     = array( 'body-soul', 'friends', 'home', 'nature', 'uncategorized' );
        $terms    = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
        $list     = array();

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $list[] = array(
                    'id'       => (int) $term->term_id,
                    'name'     => (string) $term->name,
                    'slug'     => (string) $term->slug,
                    'count'    => (int) $term->count,
                    'standard' => isset( $standard[ $term->slug ] ),
                    'demo'     => in_array( $term->slug, $demo, true ),
                );
            }
        }

        return $list;
    }

    /**
     * AJAX：初始化标准分类（已存在的同名 slug 直接复用，不重复创建）
     */
    public function ajax_init_standard_categories() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }
        if ( ! class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ) {
            wp_send_json_error( array( 'message' => '自动发布模块未加载。' ) );
        }

        $created = array();
        $reused  = array();

        foreach ( YIMAI_GSO_Autopilot_Plan::get_standard_categories() as $slug => $category ) {
            $existing = term_exists( $slug, 'category' );

            if ( $existing ) {
                $term_id  = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
                $term     = get_term( $term_id, 'category' );
                $reused[] = $category['name'];
                // 空描述才补，避免覆盖站长自定义文案
                if ( $term && ! is_wp_error( $term ) && '' === trim( (string) $term->description ) ) {
                    wp_update_term( $term_id, 'category', array( 'description' => $category['desc'] ) );
                }
                continue;
            }

            $result = wp_insert_term( $category['name'], 'category', array(
                'slug'        => $slug,
                'description' => $category['desc'],
            ) );
            if ( ! is_wp_error( $result ) ) {
                $created[] = $category['name'];
            }
        }

        wp_send_json_success( array(
            'message' => sprintf( '初始化完成：新建 %d 个，复用 %d 个。', count( $created ), count( $reused ) ),
            'created' => $created,
            'reused'  => $reused,
            'list'    => $this->get_category_overview(),
        ) );
    }

    /**
     * AJAX：把英文演示分类里的文章按标题归入标准分类（只调整文章归属，不删除分类）
     */
    public function ajax_migrate_demo_categories() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }
        if ( ! class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ) {
            wp_send_json_error( array( 'message' => '自动发布模块未加载。' ) );
        }

        $demo_slugs = array( 'body-soul', 'friends', 'home', 'nature' );
        $moved      = 0;
        $skipped    = 0;
        $details    = array();

        foreach ( $demo_slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, 'category' );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }

            $post_ids = get_posts( array(
                'category'    => $term->term_id,
                'numberposts' => 200,
                'post_status' => 'any',
                'post_type'   => 'post',
                'fields'      => 'ids',
            ) );

            foreach ( $post_ids as $post_id ) {
                $title  = get_the_title( $post_id );
                $target = YIMAI_GSO_Autopilot_Plan::map_category_for_topic( $title, 0 );

                if ( $target < 1 ) {
                    $skipped++;
                    $details[] = sprintf( '「%s」未匹配到标准分类，保留在 %s', $title, $term->name );
                    continue;
                }

                // 只移除演示分类，保留其它分类
                $current = wp_get_post_categories( $post_id );
                $next    = array_values( array_unique( array_merge( array_diff( $current, array( (int) $term->term_id ) ), array( $target ) ) ) );
                wp_set_post_categories( $post_id, $next );

                $target_term = get_term( $target, 'category' );
                $details[]   = sprintf( '「%s」→ %s', $title, ( $target_term && ! is_wp_error( $target_term ) ) ? $target_term->name : ( '分类 #' . $target ) );
                $moved++;
            }
        }

        wp_send_json_success( array(
            'message' => sprintf( '整理完成：迁移 %d 篇，跳过 %d 篇（未匹配到标准分类）。英文分类本身未删除，可在「文章 → 分类」里确认无文章后手动删除。', $moved, $skipped ),
            'moved'   => $moved,
            'skipped' => $skipped,
            'details' => array_slice( $details, 0, 30 ),
            'list'    => $this->get_category_overview(),
        ) );
    }

    public function ajax_dismiss_cron_notice() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        set_transient( 'yimai_gso_cron_system_notice_dismissed', 1, 7 * DAY_IN_SECONDS );
        wp_send_json_success();
    }
    private function render_llms_txt_panel( $active_tab ) {
        $feature_meta = $this->get_feature_meta( 'llms_txt' );
        $settings = get_option( 'yimai_gso_llms_txt_settings', array() );
        $generator_available = class_exists( 'YIMAI_GSO_LLMs_Txt_Generator' ) && YIMAI_GSO_LLMs_Txt_Generator::is_available();

        ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'llms-txt' ? 'active' : ''; ?>" data-tab="llms-txt">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-media-text"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">LLMs生成</h2>
                        <p class="yimai-gso-card-desc">一键生成 llms.txt / llms-full.txt，为 AI 搜索引擎和 LLMs 提供结构化站点内容</p>
                    </div>
                </div>

            <div class="yimai-gso-panel" style="margin-bottom:20px;">
                <p style="margin:0;color:#64748b;font-size:13px;">
                    为 AI 大模型生成友好的网站内容文件，提升 AI 搜索引用率（GEO 优化）
                </p>
            </div>

            <div class="yimai-gso-layout" style="display:grid;grid-template-columns:1fr 300px;gap:20px;margin-top:20px;">
                <div class="yimai-gso-panel<?php echo ! $feature_meta['available'] ? ' yimai-gso-llms-locked-overlay' : ''; ?>">
                    <form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => 'yimai-gso-center', 'tab' => 'llms-txt' ), admin_url( 'admin.php' ) ) ); ?>">
                        <?php wp_nonce_field( 'yimai_gso_llms_txt_save_nonce' ); ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">网站简介</th>
                                <td>
                                    <textarea name="yimai_gso_llms_txt_settings[intro]" rows="2" cols="80" class="large-text"<?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>><?php echo esc_textarea( $settings['intro'] ?? '' ); ?></textarea>
                                    <p class="description">一句话介绍你的网站（会出现在 llms.txt 的 > 引用中）</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">详细说明</th>
                                <td>
                                    <textarea name="yimai_gso_llms_txt_settings[details]" rows="4" cols="80" class="large-text"<?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>><?php echo esc_textarea( $settings['details'] ?? '' ); ?></textarea>
                                    <p class="description">可选：网站的详细说明，会出现在 llms.txt 的 ## Optional 章节</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">包含分类目录</th>
                                <td>
                                    <input type="checkbox" name="yimai_gso_llms_txt_settings[include_categories]" value="1" <?php checked( ! empty( $settings['include_categories'] ) ); ?><?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>>
                                    <span>在 llms.txt 中列出文章分类</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">包含最近文章</th>
                                <td>
                                    <input type="checkbox" name="yimai_gso_llms_txt_settings[include_recent_posts]" value="1" <?php checked( ! empty( $settings['include_recent_posts'] ) ); ?><?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>>
                                    <span>在 llms.txt 中列出最近文章链接</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">最大文章数</th>
                                <td>
                                    <input type="number" name="yimai_gso_llms_txt_settings[max_posts]" min="10" max="500" value="<?php echo esc_attr( $settings['max_posts'] ?? 100 ); ?>" class="small-text"<?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>>
                                    <span>llms-full.txt 中包含的最大文章数</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">AI 使用政策</th>
                                <td>
                                    <input type="text" name="yimai_gso_llms_txt_settings[ai_policy]" value="<?php echo esc_attr( $settings['ai_policy'] ?? '' ); ?>" class="regular-text"<?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>>
                                    <p class="description">例如："允许 AI 训练和引用本站内容，请注明出处"</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="yimai_gso_llms_txt_save" class="button-primary" value="保存设置"<?php echo ! $feature_meta['available'] ? ' disabled' : ''; ?>>
                        </p>
                    </form>
                </div>

                <div class="yimai-gso-panel">
                    <h2>预览与测试</h2>
                    <div id="llms-txt-notice-area" style="display:none;"></div>
                    <p>
                        <a href="<?php echo home_url( '/llms.txt' ); ?>" target="_blank" class="button">预览 llms.txt</a>
                        <a href="<?php echo home_url( '/llms-full.txt' ); ?>" target="_blank" class="button">预览 llms-full.txt</a>
                    </p>
                    <p>
                        <button type="button" id="yimai-gso-flush-rewrite-rules-btn" class="button">刷新固定链接</button>
                        <span id="yimai-gso-flush-rewrite-result" style="display:none;margin-left:8px;font-size:13px;"></span>
                    </p>

                    <h3>规范说明</h3>
                    <ul style="font-size:13px;color:#646970;">
                        <li><code># 网站名称</code> - 一级标题</li>
                        <li><code>> 一句话简介</code> - 引用块</li>
                        <li><code>## 章节标题</code> - 二级标题</li>
                        <li><code>- [链接文本](URL): 描述</code> - 链接列表</li>
                    </ul>

                    <h3>AI 爬虫友好</h3>
                    <p style="font-size:13px;color:#646970;">llms.txt 会被以下 AI 爬虫读取：</p>
                    <ul style="font-size:13px;color:#646970;">
                        <li>GPTBot (ChatGPT)</li>
                        <li>Claude-Web (Claude)</li>
                        <li>PerplexityBot (Perplexity)</li>
                        <li>Google-Extended (Gemini)</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AI 就绪度审计面板（GEO 第4层）
     */
    private function render_geo_audit_panel( $active_tab ) {
        $feature_meta = $this->get_feature_meta( 'geo_auditor' );
        if ( ! $feature_meta['available'] ) {
            return;
        }
        $report = get_option( 'yimai_gso_geo_audit_report', array() );
        ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'geo-audit' ? 'active' : ''; ?>" data-tab="geo-audit">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-chart-bar"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">AI 就绪度审计 <span class="yimai-gso-audit-beta-badge">Beta</span></h2>
                        <p class="yimai-gso-card-desc">整站级别 AI 就绪度扫描 — 4 大类 22 因子，产出优先级改进清单</p>
                    </div>
                </div>

                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <p style="margin:0;color:#64748b;font-size:13px;">
                        扫描网站的技术基础、内容质量、实体完整度和 AI 爬虫友好度，帮助你发现影响 AI 搜索引用的改进项
                    </p>
                </div>

                <button type="button" class="yimai-gso-audit-btn" id="yimai-gso-run-audit-btn">
                    <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
                    <?php echo empty( $report['status'] ) || $report['status'] === 'completed' ? '运行审计' : '重新审计'; ?>
                </button>

                <!-- 进度提示 -->
                <div class="yimai-gso-audit-progress" id="yimai-gso-audit-progress" style="display:none;">
                    <span class="spinner is-active" style="float:none;margin:0;vertical-align:middle;"></span>
                    <span id="yimai-gso-audit-progress-text">审计进行中...</span>
                </div>

                <!-- 审计结果区域 -->
                <div id="yimai-gso-audit-results">
                    <?php if ( ! empty( $report ) && 'completed' === $report['status'] ) : ?>
                        <?php $this->render_audit_results( $report ); ?>
                    <?php elseif ( ! empty( $report ) && 'running' === $report['status'] ) : ?>
                        <div class="yimai-gso-audit-progress">
                            <span class="spinner is-active" style="float:none;margin:0;vertical-align:middle;"></span>
                            <span>审计进行中，请稍候...</span>
                        </div>
                        <script>
                        (function(){var auditNonce='<?php echo wp_create_nonce("yimai_gso_ajax_nonce"); ?>';var s=setInterval(function(){var d=new FormData();d.append('action','yimai_gso_audit_status');d.append('security',auditNonce);fetch(ajaxurl,{method:'POST',body:d}).then(function(r){return r.json()}).then(function(res){if(res.success&&res.data.report&&res.data.report.status==='completed'){clearInterval(s);location.reload();}});},5000);})();
                        </script>
                    <?php else : ?>
                        <div style="text-align:center;padding:40px;color:#9ca3af;font-size:0.85rem;">
                            <span class="dashicons dashicons-info-outline" style="font-size:32px;width:32px;height:32px;margin-bottom:8px;"></span>
                            <p>尚未运行审计。点击上方按钮开始扫描</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
            (function(){
                var btn = document.getElementById('yimai-gso-run-audit-btn');
                if (!btn) return;
                var auditNonce = '<?php echo wp_create_nonce( "yimai_gso_ajax_nonce" ); ?>';
                btn.addEventListener('click', function(){
                    if (btn.disabled) return;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>正在审计...';

                    var progress = document.getElementById('yimai-gso-audit-progress');
                    var progressText = document.getElementById('yimai-gso-audit-progress-text');
                    var results = document.getElementById('yimai-gso-audit-results');
                    if (progress) progress.style.display = 'flex';
                    if (results) results.innerHTML = '';

                    var data = new FormData();
                    data.append('action', 'yimai_gso_run_audit');
                    data.append('security', auditNonce);

                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.success) {
                                if (progressText) progressText.textContent = '审计已启动，正在分析内容数据...';
                                var poll = setInterval(function(){
                                    var d = new FormData();
                                    d.append('action', 'yimai_gso_audit_status');
                                    d.append('security', auditNonce);
                                    fetch(ajaxurl, { method: 'POST', body: d })
                                        .then(function(r) { return r.json(); })
                                        .then(function(sr) {
                                            if (sr.success && sr.data.report && sr.data.report.status === 'completed') {
                                                clearInterval(poll);
                                                location.reload();
                                            }
                                            if (sr.data && sr.data.progress && sr.data.progress.total > 0 && progressText) {
                                                progressText.textContent = '内容审计中: ' + sr.data.progress.processed + '/' + sr.data.progress.total;
                                            }
                                        });
                                }, 3000);
                            } else {
                                btn.disabled = false;
                                btn.innerHTML = '运行审计';
                                if (progress) progress.style.display = 'none';
                                if (results) results.innerHTML = '<div style="color:#ef4444;padding:12px;">审计启动失败，请重试</div>';
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            btn.innerHTML = '运行审计';
                            if (progress) progress.style.display = 'none';
                        });
                });
            })();
            </script>
        <?php
    }

    /**
     * 渲染审计结果（总览 + 4类因子 + 优先级清单）
     */
    private function render_audit_results( $report ) {
        $overall = isset( $report['overall_score'] ) ? (int) $report['overall_score'] : 0;
        $color = $overall >= 80 ? '#22c55e' : ( $overall >= 60 ? '#3b82f6' : ( $overall >= 40 ? '#f59e0b' : '#ef4444' ) );

        // 优先改进清单操作映射表
        $action_map = array(
            'T1' => array( 'tab' => '', 'subtab' => '', 'label' => '去检查' ),
            'T2' => array( 'tab' => '', 'subtab' => '', 'label' => '去检查' ),
            'T3' => array( 'tab' => '', 'subtab' => '', 'label' => '去检查' ),
            'T4' => array( 'tab' => 'seo-meta', 'label' => '去设置' ),
            'T5' => array( 'tab' => 'canonical', 'label' => '去设置' ),
            'C1' => array( 'tab' => 'rewrite', 'label' => '去改写' ),
            'C2' => array( 'tab' => 'single', 'label' => '去生成' ),
            'C3' => array( 'tab' => 'freshness', 'label' => '去更新' ),
            'C4' => array( 'tab' => 'rewrite', 'label' => '去改写' ),
            'C5' => array( 'tab' => 'schema', 'label' => '去生成' ),
            'C6' => array( 'tab' => 'heading', 'label' => '去分析' ),
            'C7' => array( 'tab' => 'seo-meta', 'label' => '去补全' ),
            'E1' => array( 'tab' => 'settings', 'subtab' => 'geo', 'label' => '去配置' ),
            'E2' => array( 'tab' => 'settings', 'subtab' => 'geo', 'label' => '去配置' ),
            'E3' => array( 'tab' => 'settings', 'subtab' => 'authors', 'label' => '去配置' ),
            'E4' => array( 'tab' => 'rewrite', 'label' => '去补充' ),
            'E5' => array( 'tab' => 'settings', 'subtab' => 'ai', 'label' => '去配置' ),
            'E6' => array( 'tab' => 'history', 'label' => '去管理' ),
            'R1' => array( 'tab' => 'settings', 'subtab' => 'crawler', 'label' => '去配置' ),
            'R2' => array( 'tab' => 'llms-txt', 'label' => '去生成' ),
            'R3' => array( 'tab' => 'l5-crawler', 'label' => '去查看' ),
            'R4' => array( 'tab' => 'seo-push', 'label' => '去配置' ),
        );
        $audit_base_url = admin_url( 'admin.php?page=yimai-gso-center' );
        ?>
        <!-- 总览 -->
        <div class="yimai-gso-audit-overview">
            <div class="yimai-gso-audit-score-card">
                <div class="yimai-gso-audit-big-score" style="color:<?php echo $color; ?>"><?php echo $overall; ?></div>
                <div class="yimai-gso-audit-label">AI 就绪度总分</div>
            </div>
            <?php
            $cat_labels = array( 'technical' => '技术', 'content' => '内容', 'entity' => '实体', 'crawler' => '爬虫' );
            foreach ( $cat_labels as $ck => $cl ) :
                $cs = isset( $report['categories'][ $ck ]['score'] ) ? (int) $report['categories'][ $ck ]['score'] : 0;
                $cp = isset( $report['categories'][ $ck ]['passed'] ) ? (int) $report['categories'][ $ck ]['passed'] : 0;
                $ct = isset( $report['categories'][ $ck ]['total'] ) ? (int) $report['categories'][ $ck ]['total'] : 0;
                $cc = $cs >= 80 ? '#22c55e' : ( $cs >= 60 ? '#3b82f6' : ( $cs >= 40 ? '#f59e0b' : '#ef4444' ) );
            ?>
            <div class="yimai-gso-audit-score-card">
                <div class="yimai-gso-audit-big-score" style="color:<?php echo $cc; ?>"><?php echo $cs; ?></div>
                <div class="yimai-gso-audit-label"><?php echo $cl; ?> (<?php echo $cp; ?>/<?php echo $ct; ?>)</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 4 类因子详情 -->
        <div class="yimai-gso-audit-categories">
            <?php foreach ( $cat_labels as $ck => $cl ) :
                if ( empty( $report['categories'][ $ck ]['factors'] ) ) continue;
                $cat = $report['categories'][ $ck ];
                $cat_color = $cat['score'] >= 80 ? '#22c55e' : ( $cat['score'] >= 60 ? '#3b82f6' : '#f59e0b' );
            ?>
            <div class="yimai-gso-audit-cat-card">
                <h4>
                    <?php echo $cl; ?> 就绪度
                    <span class="yimai-gso-audit-cat-score" style="color:<?php echo $cat_color; ?>"><?php echo $cat['score']; ?> 分</span>
                </h4>
                <?php foreach ( $cat['factors'] as $fid => $f ) : ?>
                <div class="yimai-gso-audit-factor" title="<?php echo esc_attr( $f['detail'] ); ?>">
                    <span class="yimai-gso-audit-factor-label"><?php echo esc_html( $f['label'] ); ?></span>
                    <span class="yimai-gso-audit-factor-status">
                        <?php if ( $f['pass'] ) : ?>
                            <span style="color:#22c55e">✓</span>
                        <?php else : ?>
                            <span style="color:<?php echo $f['score'] >= 50 ? '#f59e0b' : '#ef4444'; ?>"><?php echo $f['score']; ?>分</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 优先级改进清单 -->
        <?php if ( ! empty( $report['top_priorities'] ) ) : ?>
        <div class="yimai-gso-audit-priorities">
            <h4>优先改进清单（按紧急度排序）</h4>
            <?php foreach ( $report['top_priorities'] as $p ) :
                $fid   = $p['id'] ?? '';
                $act   = isset( $action_map[ $fid ] ) ? $action_map[ $fid ] : null;
                $href  = '';
                $label = '';
                if ( $act && ! empty( $act['tab'] ) ) {
                    $href  = $audit_base_url . '&tab=' . urlencode( $act['tab'] );
                    if ( ! empty( $act['subtab'] ) ) {
                        $href .= '&subtab=' . urlencode( $act['subtab'] );
                    }
                    $label = $act['label'];
                }
            ?>
            <div class="yimai-gso-audit-priority-item">
                <span class="yimai-gso-audit-priority-id"><?php echo esc_html( $fid ); ?></span>
                <span class="yimai-gso-audit-priority-text">
                    <strong><?php echo esc_html( $p['label'] ); ?></strong>
                    <span style="color:#6b7280;margin-left:4px;">— <?php echo esc_html( $p['detail'] ); ?></span>
                </span>
                <?php if ( $href && $label ) : ?>
                <a href="<?php echo esc_url( $href ); ?>" class="yimai-gso-audit-action-btn" title="前往<?php echo esc_attr( $label ); ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p style="margin-top:12px;font-size:0.75rem;color:#9ca3af;">
            审计完成时间：<?php echo esc_html( $report['completed_at'] ?? '-' ); ?>
        </p>
        <?php
    }

    /**
     * 仪表盘 AJAX 数据端点 —  改版
     * 聚合并返回双引擎评分、系统状态、健康雷达、爬虫趋势等全部仪表盘数据
     */
    public function ajax_dashboard_data() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        global $wpdb;
        $data = array(
            'kpi'             => array( 'articles' => 0, 'crawler_today' => 0, 'schema_rate' => 0, 'referral_30d' => 0, 'scheduled' => 0, 'active_alerts' => 0 ),
            'crawler_trend'   => array( '7' => array(), '30' => array(), '90' => array() ),
            'schema_coverage' => array(),
            'crawler_family'  => array(),
            'health_radar'    => array(),
        );

        try {
            // ── KPI 统计 ──
            $pt_list = class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ? YIMAI_GSO_Post_Type_Helper::sql_type_list() : "'post','page'";
            $post_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$pt_list})"
            );
            $data['kpi']['articles']     = $post_count;
            $data['kpi']['scheduled']    = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'future' AND post_type IN ({$pt_list})"
            );
            if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
                $today = current_time( 'Y-m-d' );
                $data['kpi']['crawler_today'] = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}geo_crawler_logs WHERE DATE(visit_time) = %s", $today
                ) );
            }
            // Schema 覆盖率：DISTINCT + JOIN publish posts + 匹配 post_type（与双引擎仪表盘口径一致）
            $schema_total = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
                 WHERE pm.meta_key = 'yimai_gso_schema_type' AND pm.meta_value != '' AND p.post_type IN ({$pt_list})"
            );
            $data['kpi']['schema_rate'] = $post_count > 0 ? (int) round( ( $schema_total / $post_count ) * 100 ) : 0;
            if ( class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) {
                $referral_table = $wpdb->prefix . 'yimai_gso_referral_logs';
                $referral_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$referral_table}'" );
                if ( $referral_table_exists ) {
                    $data['kpi']['referral_30d'] = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$referral_table} WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );
                }
            }
            if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
                $data['kpi']['active_alerts'] = YIMAI_GSO_Alert_Engine::get_active_critical_count();
            }

            // ── 爬虫趋势（5 分钟缓存） ──
            $trend_cache = get_transient( 'yimai_gso_d5_crawler_trend' );
            if ( false === $trend_cache || ! is_array( $trend_cache ) ) {
                $trend_cache = array(
                    '7'  => $this->_get_crawler_trend_data(7),
                    '30' => $this->_get_crawler_trend_data(30),
                    '90' => $this->_get_crawler_trend_data(90),
                );
                set_transient( 'yimai_gso_d5_crawler_trend', $trend_cache, 5 * MINUTE_IN_SECONDS );
            }
            $data['crawler_trend'] = $trend_cache;

            // ── Schema 覆盖（DISTINCT 去重，防止 revision 重复计数）──
            if ( class_exists( 'YIMAI_GSO_Schema_Generator' ) ) {
                $types = $wpdb->get_results(
                    "SELECT pm.meta_value AS schema_type, COUNT(DISTINCT pm.post_id) AS cnt
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
                     WHERE pm.meta_key = 'yimai_gso_schema_type' AND pm.meta_value != '' AND p.post_type IN ({$pt_list})
                     GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 8"
                );
                if ( is_array( $types ) ) {
                    foreach ( $types as $t ) {
                        $data['schema_coverage'][] = array( 'type' => $t->schema_type, 'count' => (int) $t->cnt );
                    }
                }
            }
            if ( empty( $data['schema_coverage'] ) ) {
                $data['schema_coverage'] = array(
                    array( 'type' => 'Article', 'count' => 0 ),
                    array( 'type' => 'FAQPage', 'count' => 0 ),
                    array( 'type' => 'Breadcrumb', 'count' => 0 ),
                );
            }

            // ── 爬虫家族 ──
            $data['crawler_family'] = $this->_get_crawler_family_data();

            // ── 健康雷达 ──
            // 读取各数据源评分
            $audit_score = 0;
            $audit_report = get_option( 'yimai_gso_geo_audit_report', array() );
            if ( ! empty( $audit_report['overall_score'] ) ) {
                $audit_score = (int) $audit_report['overall_score'];
            }
            $ag = $audit_score >= 85 ? 'A' : ( $audit_score >= 70 ? 'B' : ( $audit_score >= 50 ? 'C' : 'D' ) );

            $crawl_score = 0;
            $diag_cached = get_transient( 'yimai_gso_l5_diagnosis_result' );
            if ( is_array( $diag_cached ) && isset( $diag_cached['overall_score'] ) ) {
                $crawl_score = (int) $diag_cached['overall_score'];
            } elseif ( class_exists( 'YIMAI_GSO_Crawler_Diagnosis' ) ) {
                // 缓存不存在时主动计算，避免仪表盘显示 0
                try {
                    $fresh_diag = YIMAI_GSO_Crawler_Diagnosis::get_instance()->get_diagnosis_result( false );
                    if ( is_array( $fresh_diag ) && isset( $fresh_diag['overall_score'] ) ) {
                        $crawl_score = (int) $fresh_diag['overall_score'];
                    }
                } catch ( \Throwable $e ) {
                    error_log( '[一麦GSO Dashboard] Crawler_Diagnosis.get_diagnosis_result() failed: ' . $e->getMessage() );
                }
            }

            $entity_score = 0;
            if ( ! empty( $audit_report['categories']['entity']['score'] ) ) {
                $entity_score = (int) $audit_report['categories']['entity']['score'];
            }

            $freshness_score = $this->_get_freshness_score();
            $referral_health_score = $this->_get_referral_health_score();
            $llms_score = $this->_get_llms_status_score();
            $data['health_radar'] = array(
                array( 'name' => 'AI就绪度审计', 'score' => $audit_score, 'detail' => '22项因子 · ' . $ag . '级' ),
                array( 'name' => '爬虫友好度', 'score' => $crawl_score, 'detail' => '15种AI爬虫监控' ),
                array( 'name' => '内容新鲜度', 'score' => $freshness_score, 'detail' => $this->_get_freshness_detail( $freshness_score ) ),
                array( 'name' => 'llms.txt 状态', 'score' => $llms_score, 'detail' => $this->_get_llms_status_detail( $llms_score ) ),
                array( 'name' => '实体定义完整度', 'score' => $entity_score, 'detail' => 'sameAs 验证' ),
                array( 'name' => 'AI引荐流量健康', 'score' => $referral_health_score, 'detail' => $this->_get_referral_health_detail( $referral_health_score ) ),
            );

        } catch ( \Throwable $e ) {
            // 单个查询失败或 PHP Error 不应阻断整个响应，已初始化为空值
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[一麦GSO Dashboard] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            }
        }

        $data['generated_at'] = current_time( 'mysql' );

        wp_send_json_success( $data );
    }

    /**
     * 获取爬虫趋势数据（单次 SQL GROUP BY，无论多少天都是 1 次查询）
     */
    private function _get_crawler_trend_data( $days ) {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_crawler_logs';
        $families = array( 'OpenAI', 'Anthropic', 'DeepSeek', 'Perplexity', 'ByteDance' );
        $result = array( 'labels' => array() );

        // 生成日期标签并初始化各家族数据
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $d = date( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
            $result['labels'][] = date( 'n/j', strtotime( $d ) );
        }
        foreach ( $families as $f ) {
            $result[ $f ] = array_fill( 0, $days, 0 );
        }
        // 初始化总访问量
        $result['Total'] = array_fill( 0, $days, 0 );

        // 检查表是否存在
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $table_exists ) return $result;

        // 单次查询所有趋势数据
        $start_date = date( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
        $family_placeholders = implode( ',', array_fill( 0, count( $families ), '%s' ) );
        $query = $wpdb->prepare(
            "SELECT family, DATE(visit_time) AS visit_date, COUNT(*) AS cnt
             FROM {$table}
             WHERE family IN ({$family_placeholders})
               AND visit_time >= %s
             GROUP BY family, DATE(visit_time)
             ORDER BY visit_date ASC",
            array_merge( $families, array( $start_date . ' 00:00:00' ) )
        );
        $rows = $wpdb->get_results( $query );

        // 回填到对应日期索引
        $date_index_map = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $d = date( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
            $date_index_map[ $d ] = $days - 1 - $i;
        }

        foreach ( $rows as $row ) {
            $idx = $date_index_map[ $row->visit_date ] ?? null;
            if ( null !== $idx && isset( $result[ $row->family ] ) ) {
                $result[ $row->family ][ $idx ] = (int) $row->cnt;
            }
        }

        // 查询每日总访问量（所有爬虫，不限于主要家族）
        $total_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(visit_time) AS visit_date, COUNT(*) AS cnt
             FROM {$table}
             WHERE visit_time >= %s
             GROUP BY DATE(visit_time)
             ORDER BY visit_date ASC",
            $start_date . ' 00:00:00'
        ) );
        foreach ( $total_rows as $row ) {
            $idx = $date_index_map[ $row->visit_date ] ?? null;
            if ( null !== $idx ) {
                $result['Total'][ $idx ] = (int) $row->cnt;
            }
        }

        return $result;
    }

    /**
     * 获取爬虫家族分布数据
     */
    private function _get_crawler_family_data() {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_crawler_logs';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

        if ( ! $table_exists ) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT family, COUNT(*) AS cnt FROM {$table} GROUP BY family ORDER BY cnt DESC LIMIT 8"
        );

        $data = array();
        foreach ( $rows as $r ) {
            $data[] = array( 'family' => $r->family ?: '其他', 'count' => (int) $r->cnt );
        }
        return $data;
    }

    /**
     * 获取全站内容新鲜度评分
     * 调用内容新鲜度监控模块的 get_summary_stats() 获取整体新鲜度百分比
     */
    private function _get_freshness_score() {
        if ( ! class_exists( 'YIMAI_GSO_Content_Freshness' ) ) return 0;
        $stats = YIMAI_GSO_Content_Freshness::get_instance()->get_summary_stats();
        return isset( $stats['fresh_pct'] ) ? (int) round( $stats['fresh_pct'] ) : 0;
    }

    private function _get_freshness_detail( $score = null ) {
        if ( null === $score ) $score = $this->_get_freshness_score();
        if ( $score >= 80 ) return "{$score}%新鲜";
        if ( ! class_exists( 'YIMAI_GSO_Content_Freshness' ) ) return '暂无数据';
        $stats = YIMAI_GSO_Content_Freshness::get_instance()->get_summary_stats();
        $stale = ( $stats['stale'] ?? 0 ) + ( $stats['old'] ?? 0 );
        if ( $stale > 0 ) return "{$stale}篇需更新";
        return '内容新鲜';
    }

    /**
     * llms.txt 状态（带缓存，5分钟）
     */
    private function _get_llms_status_score() {
        $cached = get_transient( 'yimai_gso_d5_llms_status' );
        if ( false !== $cached ) return (int) $cached;

        $llms_url = home_url( '/llms.txt' );
        $response = wp_remote_head( $llms_url, array( 'timeout' => 5 ) );
        $score = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) ? 100 : 0;

        set_transient( 'yimai_gso_d5_llms_status', $score, 5 * MINUTE_IN_SECONDS );
        return $score;
    }

    private function _get_llms_status_detail( $score = null ) {
        if ( null === $score ) $score = $this->_get_llms_status_score();
        if ( $score >= 100 ) {
            $ts = get_option( 'yimai_gso_llms_txt_updated', 0 );
            $ago = $ts ? human_time_diff( $ts, current_time( 'timestamp' ) ) . '前更新' : '已生成';
            return $ago;
        }
        return '未生成';
    }

    /**
     * AI引荐流量健康度
     */
    private function _get_referral_health_score() {
        if ( ! class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) return 0;
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_referral_logs';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $table_exists ) return 0;

        $active = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT platform) FROM {$table} WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        return min( 100, $active * 20 );
    }

    private function _get_referral_health_detail( $score = null ) {
        if ( null === $score ) $score = $this->_get_referral_health_score();
        if ( $score >= 80 ) return '多平台活跃 · 无异常';
        if ( $score >= 40 ) return '部分平台有流量';
        return '暂无AI引荐流量';
    }

    /**
     * 最近爬虫访问
     */
    private function _get_recent_crawler_visits() {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_crawler_logs';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $table_exists ) return array();

        $rows = $wpdb->get_results(
            "SELECT crawler_key, family, request_url, visit_time, response_code
             FROM {$table} ORDER BY visit_time DESC LIMIT 8"
        );

        $data = array();
        foreach ( $rows as $r ) {
            $data[] = array(
                'key'       => substr( $r->crawler_key ?: '?', 0, 2 ),
                'family'    => $r->family ?: '未知',
                'url'       => $r->request_url ?: '/',
                'code'      => (int) $r->response_code,
                'time_ago'  => human_time_diff( strtotime( $r->visit_time ), current_time( 'timestamp' ) ) . '前',
            );
        }
        return $data;
    }

    /**
     * 最新预警 — 使用 Alert Engine 的公共方法获取活跃严重预警
     */
    private function _get_recent_alerts() {
        if ( ! class_exists( 'YIMAI_GSO_Alert_Engine' ) ) return array();

        $rows = YIMAI_GSO_Alert_Engine::get_active_critical_alerts( 8 );
        if ( empty( $rows ) ) return array();

        $data = array();
        foreach ( $rows as $r ) {
            $data[] = array(
                'rule_key'  => $r['rule_key'] ?? '',
                'severity'  => $r['severity'] ?? 'warning',
                'title'     => $r['title'] ?? '',
                'message'   => $r['message'] ?? '',
                'status'    => $r['status'] ?? 'active',
                'time_ago'  => ! empty( $r['created_at'] ) ? human_time_diff( strtotime( $r['created_at'] ), current_time( 'timestamp' ) ) . '前' : '',
            );
        }
        return $data;
    }
    
    /**
     * SEO-GEO 桥接管理面板
     */
    private function render_seo_geo_bridge_panel( $active_tab ) {
        $feature_meta = $this->get_feature_meta( 'seo_geo_bridge' );
        if ( ! $feature_meta['available'] ) {
            return;
        }
        ?>
            <div class="yimai-gso-main-panel <?php echo $active_tab === 'seo-geo-bridge' ? 'active' : ''; ?>" data-tab="seo-geo-bridge">
                <div class="yimai-gso-page-card-header">
                    <div class="yimai-gso-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
                    <div class="yimai-gso-card-body">
                        <h2 class="yimai-gso-card-title">SEO-GEO 桥接</h2>
                        <p class="yimai-gso-card-desc">自动与主流 SEO 插件集成，确保 SEO 和 GEO 元数据一致，避免两个体系的设置互相冲突</p>
                    </div>
                </div>

                <!-- ① SEO 插件兼容状态 -->
                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <h3 style="margin:0 0 12px;font-size:14px;font-weight:600;">已安装 SEO 插件</h3>
                    <div id="yimai-gso-bridge-plugin-cards" style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div style="padding:16px;color:#94a3b8;font-size:0.85rem;">检测中...</div>
                    </div>
                    <div id="yimai-gso-bridge-no-plugin" style="display:none;padding:16px;text-align:center;color:#64748b;font-size:0.85rem;">
                        <span class="dashicons dashicons-info-outline" style="vertical-align:text-bottom;"></span>
                        未检测到 SEO 插件，桥接功能无需干预即可正常运行
                    </div>
                </div>

                <!-- ② 5 项桥接能力仪表盘 -->
                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <h3 style="margin:0 0 12px;font-size:14px;font-weight:600;">桥接能力状态</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;" id="yimai-gso-bridge-status-cards">
                        <div class="yimai-gso-bridge-stat-card" id="yimai-gso-bridge-stat-meta">
                            <div class="yimai-gso-bridge-stat-icon">🔗</div>
                            <div class="yimai-gso-bridge-stat-label">Meta 一致性</div>
                            <div class="yimai-gso-bridge-stat-value">--</div>
                            <div class="yimai-gso-bridge-stat-sub">已检查文章数</div>
                        </div>
                        <div class="yimai-gso-bridge-stat-card" id="yimai-gso-bridge-stat-robots">
                            <div class="yimai-gso-bridge-stat-icon">🤖</div>
                            <div class="yimai-gso-bridge-stat-label">robots.txt 协同</div>
                            <div class="yimai-gso-bridge-stat-value">--</div>
                            <div class="yimai-gso-bridge-stat-sub">AI 爬虫规则数</div>
                        </div>
                        <div class="yimai-gso-bridge-stat-card" id="yimai-gso-bridge-stat-sitemap">
                            <div class="yimai-gso-bridge-stat-icon">🗺️</div>
                            <div class="yimai-gso-bridge-stat-label">Sitemap 增强</div>
                            <div class="yimai-gso-bridge-stat-value">--</div>
                            <div class="yimai-gso-bridge-stat-sub">GEO 标记注入</div>
                        </div>
                        <div class="yimai-gso-bridge-stat-card" id="yimai-gso-bridge-stat-schema">
                            <div class="yimai-gso-bridge-stat-icon">🏛️</div>
                            <div class="yimai-gso-bridge-stat-label">Schema 去重</div>
                            <div class="yimai-gso-bridge-stat-value">--</div>
                            <div class="yimai-gso-bridge-stat-sub">冲突检测</div>
                        </div>
                        <div class="yimai-gso-bridge-stat-card" id="yimai-gso-bridge-stat-compat">
                            <div class="yimai-gso-bridge-stat-icon">📋</div>
                            <div class="yimai-gso-bridge-stat-label">兼容性检测</div>
                            <div class="yimai-gso-bridge-stat-value">--</div>
                            <div class="yimai-gso-bridge-stat-sub">综合状态</div>
                        </div>
                    </div>
                </div>

                <!-- ③ 冲突详情与操作区 -->
                <div class="yimai-gso-panel" style="margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                        <h3 style="margin:0;font-size:14px;font-weight:600;">冲突与建议</h3>
                        <div style="display:flex;gap:8px;">
                            <button type="button" class="button button-primary" id="yimai-gso-bridge-scan-btn">
                                <span class="dashicons dashicons-search" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
                                重新检测
                            </button>
                            <button type="button" class="button" id="yimai-gso-bridge-fix-all-btn" style="display:none;">
                                <span class="dashicons dashicons-admin-tools" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
                                一键修复
                            </button>
                        </div>
                    </div>
                    <div id="yimai-gso-bridge-conflict-list">
                        <div style="padding:16px;color:#94a3b8;font-size:0.85rem;text-align:center;">点击「重新检测」开始分析...</div>
                    </div>
                    
                    <!-- 扫描进度 -->
                    <div id="yimai-gso-bridge-scan-progress" style="display:none;margin-top:12px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span class="spinner is-active" style="float:none;margin:0;"></span>
                            <span id="yimai-gso-bridge-scan-text">正在扫描 Meta 一致性...</span>
                        </div>
                        <div style="background:#e2e8f0;border-radius:4px;height:6px;overflow:hidden;">
                            <div id="yimai-gso-bridge-scan-bar" style="background:#3b82f6;height:100%;width:0%;transition:width 0.3s;"></div>
                        </div>
                    </div>
                </div>

                <!-- CSS -->
                <style>
                    .yimai-gso-bridge-stat-card {
                        background:#f8fafc;
                        border:1px solid #e2e8f0;
                        border-radius:8px;
                        padding:14px;
                        text-align:center;
                        transition:border-color 0.2s;
                    }
                    .yimai-gso-bridge-stat-card:hover { border-color:#cbd5e1; }
                    .yimai-gso-bridge-stat-icon { font-size:20px; margin-bottom:6px; }
                    .yimai-gso-bridge-stat-label { font-size:12px; color:#64748b; margin-bottom:4px; }
                    .yimai-gso-bridge-stat-value { font-size:20px; font-weight:700; color:#1e293b; }
                    .yimai-gso-bridge-stat-sub { font-size:11px; color:#94a3b8; margin-top:2px; }
                    .yimai-gso-bridge-stat-card.good { border-color:#10b981; background:#ecfdf5; }
                    .yimai-gso-bridge-stat-card.warn { border-color:#f59e0b; background:#fffbeb; }
                    .yimai-gso-bridge-stat-card.bad { border-color:#ef4444; background:#fef2f2; }
                    
                    .yimai-gso-bridge-plugin-card {
                        background:#f8fafc;
                        border:1px solid #e2e8f0;
                        border-radius:8px;
                        padding:14px 18px;
                        min-width:200px;
                        flex:1;
                    }
                    .yimai-gso-bridge-plugin-card .plugin-name { font-weight:600; font-size:14px; margin-bottom:2px; }
                    .yimai-gso-bridge-plugin-card .plugin-version { font-size:12px; color:#94a3b8; margin-bottom:8px; }
                    .yimai-gso-bridge-plugin-card .plugin-status { font-size:12px; }
                    .yimai-gso-bridge-plugin-card.compatible { border-left:3px solid #10b981; }
                    .yimai-gso-bridge-plugin-card.warning { border-left:3px solid #f59e0b; }
                    
                    .yimai-gso-bridge-conflict-item {
                        background:#f8fafc;
                        border:1px solid #e2e8f0;
                        border-radius:6px;
                        padding:12px 16px;
                        margin-bottom:8px;
                    }
                    .yimai-gso-bridge-conflict-item.severity-info { border-left:3px solid #6366f1; }
                    .yimai-gso-bridge-conflict-item.severity-low { border-left:3px solid #10b981; }
                    .yimai-gso-bridge-conflict-item.severity-medium { border-left:3px solid #f59e0b; }
                    .yimai-gso-bridge-conflict-item.severity-high { border-left:3px solid #ef4444; }
                    .yimai-gso-bridge-conflict-header {
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        gap:8px;
                        flex-wrap:wrap;
                    }
                    .yimai-gso-bridge-conflict-title { font-weight:600; font-size:13px; }
                    .yimai-gso-bridge-conflict-desc { font-size:12px; color:#64748b; margin-top:4px; }
                    .yimai-gso-bridge-severity-badge {
                        font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600;
                    }
                    .yimai-gso-bridge-severity-badge.info { background:#eef2ff; color:#6366f1; }
                    .yimai-gso-bridge-severity-badge.low { background:#ecfdf5; color:#10b981; }
                    .yimai-gso-bridge-severity-badge.medium { background:#fffbeb; color:#f59e0b; }
                    .yimai-gso-bridge-severity-badge.high { background:#fef2f2; color:#ef4444; }
                    .yimai-gso-bridge-fix-btn {
                        font-size:11px; padding:2px 10px; height:auto; line-height:1.6;
                    }
                    .yimai-gso-bridge-fixed-badge {
                        font-size:11px; color:#10b981; font-weight:600;
                    }
                </style>
            </div>
        <?php
    }
}
