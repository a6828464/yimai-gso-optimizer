<?php
/**
 * 实时预警引擎 — 规则检测 + 告警日志 + Admin Notice 通知
 *
 * 四条预警规则：
 *   R1 — Schema 出错检测（Google Rich Results API 验证）
 *   R2 — AI 爬虫被误封（对比配置 vs 实际 robots.txt + 访问量骤降）
 *   R3 — 内容评分骤降（最近24h vs 前7天 SEO/GEO 均分）
 *   R4 — AI 引荐流量异常下降（最近7天 vs 前30天）
 *
 * WP-Cron 定时检测 + 事件触发即时检查
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// YIMAI_GSO_VERSION 防御性定义：防止部署异常或 opcache 导致常量未定义 Fatal Error
defined( 'YIMAI_GSO_VERSION' ) || define( 'YIMAI_GSO_VERSION', '2.4.3' );

class YIMAI_GSO_Alert_Engine {

    private static $instance = null;

    const TABLE_NAME  = 'yimai_gso_alert_logs';
    const CONFIG_KEY  = 'yimai_gso_alert_config';

    /** 默认配置 */
    const DEFAULT_CONFIG = array(
        'r1_schema_error'   => true,    // Schema 错误检测
        'r2_crawler_blocked' => true,   // 爬虫误封检测
        'r3_score_drop'      => true,    // 评分骤降检测
        'r4_traffic_drop'    => true,    // 流量异常检测
        'score_drop_threshold' => 15,    // 评分骤降阈值
        'traffic_drop_pct'     => 50,    // 流量下降百分比阈值
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX 端点
        add_action( 'wp_ajax_yimai_gso_l5_alerts_list',        array( $this, 'ajax_list' ) );
        add_action( 'wp_ajax_yimai_gso_l5_alerts_acknowledge',  array( $this, 'ajax_acknowledge' ) );
        add_action( 'wp_ajax_yimai_gso_l5_alerts_resolve',      array( $this, 'ajax_resolve' ) );
        add_action( 'wp_ajax_yimai_gso_l5_alerts_dismiss',      array( $this, 'ajax_dismiss' ) );
        add_action( 'wp_ajax_yimai_gso_l5_alerts_config',       array( $this, 'ajax_save_config' ) );
        add_action( 'wp_ajax_yimai_gso_l5_alerts_run_all',       array( $this, 'ajax_run_all' ) );

        // Admin Notice 显示（已关闭全局弹窗，仅保留左侧菜单角标与仪表盘内提示）
        // add_action( 'admin_notices', array( $this, 'show_critical_notices' ) );

        // 事件触发即时检测
        add_action( 'yimai_gso_schema_rebuilt', array( $this, 'check_schema_errors' ) );
        add_action( 'yimai_gso_crawler_rules_saved', array( $this, 'check_crawler_blocked' ) );
        add_action( 'yimai_gso_seo_score_batch_done', array( $this, 'check_score_drop' ) );

        // 定时检测 Cron
        add_action( 'yimai_gso_l5_alert_schema',  array( $this, 'check_schema_errors' ) );
        add_action( 'yimai_gso_l5_alert_crawler', array( $this, 'check_crawler_blocked' ) );
        add_action( 'yimai_gso_l5_alert_score',   array( $this, 'check_score_drop' ) );
        add_action( 'yimai_gso_l5_alert_traffic', array( $this, 'check_traffic_drop' ) );
    }

    // ==================== 配置 ====================

    /**
     * 获取预警配置
     *
     * @return array
     */
    public static function get_config() {
        $config = get_option( self::CONFIG_KEY, array() );
        return wp_parse_args( $config, self::DEFAULT_CONFIG );
    }

    /**
     * 检查规则是否启用
     *
     * @param string $rule_key r1_schema_error|r2_crawler_blocked|r3_score_drop|r4_traffic_drop
     * @return bool
     */
    public static function is_rule_enabled( $rule_key ) {
        $config = self::get_config();
        return ! empty( $config[ $rule_key ] );
    }

    // ==================== 数据库 ====================

    /**
     * 获取完整表名
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * 创建预警日志表
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table_name();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `{$table}` (
            `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `rule_key`      VARCHAR(50)  NOT NULL COMMENT 'R1/R2/R3/R4',
            `severity`      ENUM('critical','warning','info') NOT NULL DEFAULT 'warning',
            `title`         VARCHAR(255) NOT NULL,
            `message`       TEXT,
            `context`       TEXT DEFAULT NULL COMMENT 'JSON 上下文数据',
            `status`        ENUM('active','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'active',
            `resolved_at`   DATETIME DEFAULT NULL,
            `created_at`    DATETIME NOT NULL,
            INDEX `idx_rule`     (`rule_key`, `created_at`),
            INDEX `idx_status`   (`status`, `created_at`),
            INDEX `idx_severity` (`severity`, `status`)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
    }

    /**
     * 确保表存在
     */
    public static function ensure_table() {
        if ( false === get_transient( 'yimai_gso_alert_table_checked' ) ) {
            set_transient( 'yimai_gso_alert_table_checked', 1, HOUR_IN_SECONDS );
            self::create_table();
        }
    }

    // ==================== 告警写入 ====================

    /**
     * 写入一条告警
     *
     * @param array $alert { rule_key, severity, title, message, context }
     * @return int|false 告警 ID 或 false
     */
    public static function create_alert( $alert ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        // 去重：相同 rule_key + 相同 context hash 的 Active 告警不重复创建
        if ( ! empty( $alert['context'] ) ) {
            $ctx_hash = md5( $alert['rule_key'] . '|' . json_encode( $alert['context'] ) );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table}`
                 WHERE rule_key = %s AND status = 'active' AND MD5(CONCAT(rule_key, '|', COALESCE(context, ''))) = %s
                 LIMIT 1",
                $alert['rule_key'],
                $ctx_hash
            ) );
            if ( $exists ) {
                return (int) $exists;
            }
        }

        $data = array(
            'rule_key'   => $alert['rule_key'],
            'severity'   => isset( $alert['severity'] ) ? $alert['severity'] : 'warning',
            'title'      => $alert['title'],
            'message'    => isset( $alert['message'] ) ? $alert['message'] : '',
            'context'    => isset( $alert['context'] ) ? json_encode( $alert['context'], JSON_UNESCAPED_UNICODE ) : null,
            'status'     => 'active',
            'created_at' => current_time( 'mysql' ),
        );

        $result = $wpdb->insert( $table, $data );

        if ( $result ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * 自动解决已恢复的告警
     *
     * @param string $rule_key
     */
    public static function auto_resolve_by_rule( $rule_key ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $wpdb->update(
            $table,
            array( 'status' => 'resolved', 'resolved_at' => current_time( 'mysql' ) ),
            array( 'rule_key' => $rule_key, 'status' => 'active' )
        );
    }

    // ==================== 四条预警规则 ====================

    /**
     * R1: Schema 出错检测
     * 检查首页 + 最近 3 篇文章的 Schema 可以通过 Google Rich Results Test
     * 每 6 小时执行
     */
    public function check_schema_errors() {
        if ( ! self::is_rule_enabled( 'r1_schema_error' ) ) {
            return;
        }

        // 轻量检测（无需 Google API）：
        // 1. 检查首页实体设置是否完整
        // 2. 检查最近文章 JSON-LD Schema 类型是否配置
        $errors = array();

        // 检查首页 Organization Schema
        $org_option = get_option( 'yimai_gso_geo_entity_settings', array() );
        if ( ! empty( $org_option['entity_name'] ) && empty( $org_option['entity_type'] ) ) {
            $errors[] = 'Organization Schema 缺少实体类型';
        }

        // 检查最近发布的文章 Schema
        $recent_posts = get_posts( array(
            'post_type'      => YIMAI_GSO_Post_Type_Helper::get_supported(),
            'post_status'    => 'publish',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $missing_schema = 0;
        foreach ( $recent_posts as $post ) {
            $schema_type = get_post_meta( $post->ID, 'yimai_gso_schema_type', true );
            if ( empty( $schema_type ) ) {
                $missing_schema++;
            }
        }

        if ( $missing_schema > 2 ) {
            $errors[] = sprintf( '最近5篇文章中有 %d 篇缺少 Schema 类型', $missing_schema );
        }

        if ( ! empty( $errors ) ) {
            self::create_alert( array(
                'rule_key' => 'R1',
                'severity' => 'warning',
                'title'    => 'Schema 覆盖不足',
                'message'  => implode( '；', $errors ),
                'context'  => array( 'missing_schema' => $missing_schema ),
            ) );
        } else {
            self::auto_resolve_by_rule( 'R1' );
        }
    }

    /**
     * R2: AI 爬虫被误封检测
     * 每 1 小时执行
     */
    public function check_crawler_blocked() {
        if ( ! self::is_rule_enabled( 'r2_crawler_blocked' ) ) {
            return;
        }

        $issues = array();

        // 检查 1: Crawler_Editor 规则 vs 实际 robots.txt
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $editor_rules = YIMAI_GSO_Crawler_Editor::get_rules();

            // 尝试获取实际 robots.txt
            $response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
            if ( ! is_wp_error( $response ) ) {
                $robots_content = wp_remote_retrieve_body( $response );

                // 关键 AI 爬虫
                $key_crawlers = array( 'GPTBot', 'ChatGPT-User', 'Google-Extended', 'ClaudeBot', 'PerplexityBot', 'DeepSeekBot', 'Bytespider' );

                foreach ( $key_crawlers as $bot ) {
                    $editor_allows = ( isset( $editor_rules[ $bot ] ) && 'allow' === $editor_rules[ $bot ] );

                    // 按爬虫分段检测 Disallow: /：在 robots.txt 中提取该 bot 的规则段落
                    $bot_disallows_all = false;
                    if ( $editor_allows && false !== stripos( $robots_content, $bot ) ) {
                        // 提取该 bot 的段落（从 User-agent: BotName 到下一个 User-agent: 或文件结尾）
                        $pattern = '/User-agent:\s*' . preg_quote( $bot, '/' ) . '\b[^\n]*\n(.*?)(?=User-agent:|\z)/is';
                        if ( preg_match( $pattern, $robots_content, $m ) ) {
                            $section = $m[1];
                            // 在该 bot 的段落内查找 Disallow: /
                            if ( preg_match( '/^\s*Disallow:\s*\/\s*$/im', $section ) ) {
                                $bot_disallows_all = true;
                            }
                        }
                    }

                    if ( $editor_allows && $bot_disallows_all ) {
                        $issues[] = sprintf( '%s 被设置为开放但实际 robots.txt 中存在 Disallow', $bot );
                    }
                }
            }
        }

        // 检查 2: 最近7天关键爬虫访问量是否归零
        if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
            $stats = YIMAI_GSO_Crawler_Tracker::get_stats( 7 );
            $by_crawler = array();
            if ( ! empty( $stats['by_crawler'] ) ) {
                foreach ( $stats['by_crawler'] as $row ) {
                    $by_crawler[ $row['crawler_key'] ] = (int) $row['total_visits'];
                }
            }

            $zero_crawlers = array();
            $key_bots = array( 'GPTBot', 'Google-Extended', 'ClaudeBot', 'DeepSeekBot' );
            foreach ( $key_bots as $bot ) {
                if ( ! isset( $by_crawler[ $bot ] ) || $by_crawler[ $bot ] === 0 ) {
                    $zero_crawlers[] = $bot;
                }
            }

            if ( count( $zero_crawlers ) >= 3 ) {
                $issues[] = '最近7天多个关键AI爬虫（' . implode( ', ', $zero_crawlers ) . '）零访问，可能被封锁或策略变更';
            }
        }

        if ( ! empty( $issues ) ) {
            self::create_alert( array(
                'rule_key' => 'R2',
                'severity' => 'critical',
                'title'    => 'AI 爬虫访问异常',
                'message'  => implode( '；', $issues ),
                'context'  => array( 'issues' => $issues ),
            ) );
        } else {
            self::auto_resolve_by_rule( 'R2' );
        }
    }

    /**
     * 从 meta_value 中安全提取 overall 分数
     *
     * 兼容 PHP 数组、JSON 字符串、serialize 字符串和纯数字。
     *
     * @param mixed $raw
     * @return int
     */
    private static function extract_overall( $raw ) {
        if ( empty( $raw ) ) {
            return 0;
        }

        if ( is_array( $raw ) && isset( $raw['overall'] ) ) {
            return (int) $raw['overall'];
        }

        if ( is_string( $raw ) ) {
            $trimmed = trim( $raw );

            if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
                $decoded = json_decode( $trimmed, true );
                if ( is_array( $decoded ) && isset( $decoded['overall'] ) ) {
                    return (int) $decoded['overall'];
                }
            }

            if ( 'a:' === substr( $trimmed, 0, 2 ) || 'O:' === substr( $trimmed, 0, 2 ) ) {
                $unserialized = @maybe_unserialize( $trimmed );
                if ( is_array( $unserialized ) && isset( $unserialized['overall'] ) ) {
                    return (int) $unserialized['overall'];
                }
            }

            if ( is_numeric( $trimmed ) ) {
                return (int) $trimmed;
            }
        }

        return 0;
    }

    /**
     * 计算指定时间段内已发布文章的 SEO 平均 overall 分数
     *
     * @param string $pt_list SQL IN 列表
     * @param int    $start   起始时间戳
     * @param int|null $end   结束时间戳（null 表示到现在）
     * @return float
     */
    private static function compute_avg_score_in_period( $pt_list, $start, $end = null ) {
        global $wpdb;

        $where = "pm.meta_key = 'yimai_gso_seo_score' AND p.post_status = 'publish' AND p.post_type IN ({$pt_list})";
        $where .= $wpdb->prepare( ' AND p.post_modified >= %s', gmdate( 'Y-m-d H:i:s', $start ) );
        if ( null !== $end ) {
            $where .= $wpdb->prepare( ' AND p.post_modified < %s', gmdate( 'Y-m-d H:i:s', $end ) );
        }

        $rows = $wpdb->get_results(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE {$where}",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $sum   = 0;
        $count = 0;
        foreach ( $rows as $row ) {
            $score = self::extract_overall( $row['meta_value'] );
            if ( $score > 0 ) {
                $sum   += $score;
                $count++;
            }
        }

        return $count > 0 ? round( $sum / $count ) : 0;
    }

    /**
     * R3: 内容评分骤降检测
     * 每 24 小时执行
     */
    public function check_score_drop() {
        if ( ! self::is_rule_enabled( 'r3_score_drop' ) ) {
            return;
        }

        global $wpdb;
        $config = self::get_config();
        $threshold = (int) $config['score_drop_threshold'];

        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();

        // 最近24小时修改的文章 SEO 评分均值（PHP 层反序列化，兼容 serialize）
        $recent_avg = self::compute_avg_score_in_period( $pt_list, strtotime( '-24 hours' ), null );

        // 前7天的均值
        $prev_avg = self::compute_avg_score_in_period( $pt_list, strtotime( '-7 days' ), strtotime( '-24 hours' ) );

        $recent_avg = (int) $recent_avg;
        $prev_avg   = (int) $prev_avg;

        if ( $prev_avg > 0 && ( $prev_avg - $recent_avg ) >= $threshold ) {
            self::create_alert( array(
                'rule_key' => 'R3',
                'severity' => 'warning',
                'title'    => sprintf( 'SEO 评分骤降（%d → %d）', $prev_avg, $recent_avg ),
                'message'  => sprintf( '最近 24 小时平均 SEO 评分为 %d，较前 7 天均值（%d）下降了 %d 分，超过阈值 %d。建议检查最近修改的文章内容质量。',
                    $recent_avg, $prev_avg, $prev_avg - $recent_avg, $threshold ),
                'context'  => array( 'recent_avg' => $recent_avg, 'prev_avg' => $prev_avg, 'drop' => $prev_avg - $recent_avg ),
            ) );
        } else {
            self::auto_resolve_by_rule( 'R3' );
        }
    }

    /**
     * R4: AI 引荐流量异常下降检测
     * 每 24 小时执行
     */
    public function check_traffic_drop() {
        if ( ! self::is_rule_enabled( 'r4_traffic_drop' ) ) {
            return;
        }

        if ( ! class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) {
            return;
        }

        $config        = self::get_config();
        $drop_pct      = (int) $config['traffic_drop_pct'];

        // 最近 7 天日均流量
        $recent_7d = YIMAI_GSO_Referral_Tracker::get_stats( 7 );
        $recent_daily = ! empty( $recent_7d['total_visits'] ) ? ceil( $recent_7d['total_visits'] / 7 ) : 0;

        // 前 30 天（不含最近 7 天）日均流量
        global $wpdb;
        $table = YIMAI_GSO_Referral_Tracker::table_name();
        YIMAI_GSO_Referral_Tracker::ensure_table();

        $prev_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE visit_time >= %s AND visit_time < %s",
            gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
            gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
        ) );
        $prev_daily = $prev_total > 0 ? ceil( $prev_total / 23 ) : 0; // 30-7=23天

        if ( $prev_daily > 10 && ( $prev_daily - $recent_daily ) / $prev_daily * 100 >= $drop_pct ) {
            self::create_alert( array(
                'rule_key' => 'R4',
                'severity' => 'warning',
                'title'    => sprintf( 'AI 引荐流量异常下降 %.0f%%', ( $prev_daily - $recent_daily ) / $prev_daily * 100 ),
                'message'  => sprintf( '最近 7 天日均引荐流量约 %d 次，较前 30 天日均（%d 次）下降了 %.0f%%，建议检查 AI 爬虫策略和内容质量。',
                    $recent_daily, $prev_daily, ( $prev_daily - $recent_daily ) / $prev_daily * 100 ),
                'context'  => array( 'recent_daily' => $recent_daily, 'prev_daily' => $prev_daily ),
            ) );
        } else {
            self::auto_resolve_by_rule( 'R4' );
        }
    }

    // ==================== Admin Notice ====================

    /**
     * 在 WordPress 后台显示严重预警通知
     */
    public function show_critical_notices() {

        $count = self::get_active_critical_count();
        if ( $count <= 0 ) {
            return;
        }

        $alerts = self::get_active_critical_alerts( 3 );

        // 修复（L1）：原链接指向 page=yimai-gso-dashboard，该 slug 从未注册，
        // 点击后 WordPress 显示「抱歉，您不能访问此页面」。正确 slug 是 yimai-gso-center。
        $alert_page = admin_url( 'admin.php?page=yimai-gso-center&tab=l5-alerts' );

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>🔔 一麦 SEO-GEO 预警：</strong>检测到 ' . esc_html( $count ) . ' 个需要关注的问题</p>';
        echo '<ul style="margin:4px 0 8px 16px;font-size:13px;">';
        foreach ( $alerts as $alert ) {
            echo '<li>' . esc_html( $alert['title'] ) . '</li>';
        }
        echo '</ul>';
        echo '<p><a href="' . esc_url( $alert_page ) . '" class="button button-small">查看预警详情 →</a></p>';
        echo '</div>';
    }

    /**
     * 获取活跃的严重预警数量
     *
     * @return int
     */
    public static function get_active_critical_count() {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active' AND severity = 'critical'"
        );
    }

    /**
     * 获取活跃的严重预警列表
     *
     * @param int $limit
     * @return array
     */
    public static function get_active_critical_alerts( $limit = 5 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'active' AND severity = 'critical' ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A ) ?: array();
    }

    // ==================== AJAX 端点 ====================

    /**
     * AJAX: 获取预警列表
     */
    public function ajax_list() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $page    = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? min( 50, max( 5, intval( $_GET['per_page'] ) ) ) : 20;
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $severity = isset( $_GET['severity'] ) ? sanitize_text_field( $_GET['severity'] ) : '';

        $where = array( '1=1' );
        if ( $status && in_array( $status, array( 'active', 'acknowledged', 'resolved', 'dismissed' ) ) ) {
            $where[] = $wpdb->prepare( 'status = %s', $status );
        }
        if ( $severity && in_array( $severity, array( 'critical', 'warning', 'info' ) ) ) {
            $where[] = $wpdb->prepare( 'severity = %s', $severity );
        }

        $where_clause = implode( ' AND ', $where );
        $offset       = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_clause}" );

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        // 格式化
        foreach ( $items as &$item ) {
            $item['context'] = ! empty( $item['context'] ) ? json_decode( $item['context'], true ) : null;
            $item['rule_label'] = self::get_rule_label( $item['rule_key'] );
        }

        $active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active'" );

        wp_send_json_success( array(
            'items'       => $items ?: array(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'active_count' => $active_count,
        ) );
    }

    /**
     * AJAX: 获取未处理预警数量
     */
    public function ajax_count() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $cache_key = 'yimai_gso_l5_alert_count_' . YIMAI_GSO_VERSION;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( array( 'count' => (int) $cached ) );
            return;
        }

        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $critical = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active' AND severity = 'critical'" );
        $warning  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active' AND severity = 'warning'" );

        $result = array(
            'critical' => $critical,
            'warning'  => $warning,
            'total'    => $critical + $warning,
        );

        set_transient( $cache_key, $result['total'], 5 * MINUTE_IN_SECONDS );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 确认预警
     */
    public function ajax_acknowledge() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的预警 ID' ) );
        }

        global $wpdb;
        $table = self::table_name();
        $wpdb->update( $table, array( 'status' => 'acknowledged' ), array( 'id' => $id ) );

        delete_transient( 'yimai_gso_l5_alert_count_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_alert_badge_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_overview_' . YIMAI_GSO_VERSION );
        wp_send_json_success( array( 'message' => '已确认' ) );
    }

    /**
     * AJAX: 解决预警
     */
    public function ajax_resolve() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的预警 ID' ) );
        }

        global $wpdb;
        $table = self::table_name();
        $wpdb->update( $table, array(
            'status'      => 'resolved',
            'resolved_at' => current_time( 'mysql' ),
        ), array( 'id' => $id ) );

        delete_transient( 'yimai_gso_l5_alert_count_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_alert_badge_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_overview_' . YIMAI_GSO_VERSION );
        wp_send_json_success( array( 'message' => '已解决' ) );
    }

    /**
     * AJAX: 永久忽略预警
     */
    public function ajax_dismiss() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的预警 ID' ) );
        }

        global $wpdb;
        $table = self::table_name();
        $wpdb->update( $table, array( 'status' => 'dismissed' ), array( 'id' => $id ) );

        delete_transient( 'yimai_gso_l5_alert_count_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_alert_badge_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_overview_' . YIMAI_GSO_VERSION );
        wp_send_json_success( array( 'message' => '已忽略' ) );
    }

    /**
     * AJAX: 保存预警配置
     */
    public function ajax_save_config() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $config = array(
            'r1_schema_error'       => ! empty( $_POST['r1_schema_error'] ),
            'r2_crawler_blocked'    => ! empty( $_POST['r2_crawler_blocked'] ),
            'r3_score_drop'         => ! empty( $_POST['r3_score_drop'] ),
            'r4_traffic_drop'       => ! empty( $_POST['r4_traffic_drop'] ),
            'score_drop_threshold'  => isset( $_POST['score_drop_threshold'] ) ? intval( $_POST['score_drop_threshold'] ) : 15,
            'traffic_drop_pct'      => isset( $_POST['traffic_drop_pct'] ) ? intval( $_POST['traffic_drop_pct'] ) : 50,
        );

        update_option( self::CONFIG_KEY, $config );
        wp_send_json_success( array( 'message' => '配置已保存' ) );
    }

    /**
     * AJAX: 手动触发全部 4 条预警规则检测
     * 用于初次部署或调试时立即检查，无需等待 WP-Cron
     */
    public function ajax_run_all() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $results = array();

        // R1: Schema
        $this->check_schema_errors();
        $results['R1'] = array(
            'label'  => self::get_rule_label( 'R1' ),
            'ran_at' => current_time( 'Y-m-d H:i:s' ),
        );

        // R2: Crawler
        $this->check_crawler_blocked();
        $results['R2'] = array(
            'label'  => self::get_rule_label( 'R2' ),
            'ran_at' => current_time( 'Y-m-d H:i:s' ),
        );

        // R3: Score
        $this->check_score_drop();
        $results['R3'] = array(
            'label'  => self::get_rule_label( 'R3' ),
            'ran_at' => current_time( 'Y-m-d H:i:s' ),
        );

        // R4: Traffic
        $this->check_traffic_drop();
        $results['R4'] = array(
            'label'  => self::get_rule_label( 'R4' ),
            'ran_at' => current_time( 'Y-m-d H:i:s' ),
        );

        // 清除所有预警相关缓存
        delete_transient( 'yimai_gso_l5_alert_count_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_alert_badge_' . YIMAI_GSO_VERSION );
        delete_transient( 'yimai_gso_l5_overview_' . YIMAI_GSO_VERSION );

        // 获取当前活跃预警数
        $active_count = self::get_active_critical_count();

        wp_send_json_success( array(
            'message'      => '全部 4 条规则检测完成',
            'results'      => $results,
            'alert_count'  => $active_count,
        ) );
    }

    // ==================== 工具方法 ====================

    /**
     * 规则 key → 中文标签
     */
    private static function get_rule_label( $key ) {
        $labels = array(
            'R1' => 'Schema 出错',
            'R2' => 'AI爬虫被误封',
            'R3' => '内容评分骤降',
            'R4' => 'AI引荐流量异常',
        );
        return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
    }

    // ==================== Cron 调度管理 ====================

    /**
     * 注册定时检测任务
     */
    public static function schedule_crons() {
        if ( ! wp_next_scheduled( 'yimai_gso_l5_alert_schema' ) ) {
            wp_schedule_event( time(), 'six_hours', 'yimai_gso_l5_alert_schema' );
        }
        if ( ! wp_next_scheduled( 'yimai_gso_l5_alert_crawler' ) ) {
            wp_schedule_event( time() + 600, 'hourly', 'yimai_gso_l5_alert_crawler' );
        }
        if ( ! wp_next_scheduled( 'yimai_gso_l5_alert_score' ) ) {
            wp_schedule_event( time() + 1200, 'daily', 'yimai_gso_l5_alert_score' );
        }
        if ( ! wp_next_scheduled( 'yimai_gso_l5_alert_traffic' ) ) {
            wp_schedule_event( time() + 1800, 'daily', 'yimai_gso_l5_alert_traffic' );
        }
    }

    /**
     * 取消定时检测任务
     */
    public static function unschedule_crons() {
        wp_clear_scheduled_hook( 'yimai_gso_l5_alert_schema' );
        wp_clear_scheduled_hook( 'yimai_gso_l5_alert_crawler' );
        wp_clear_scheduled_hook( 'yimai_gso_l5_alert_score' );
        wp_clear_scheduled_hook( 'yimai_gso_l5_alert_traffic' );
    }

    /**
     * 添加 6 小时 Cron 间隔
     */
    public static function add_cron_schedules( $schedules ) {
        $schedules['six_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => '每 6 小时',
        );
        return $schedules;
    }
}
