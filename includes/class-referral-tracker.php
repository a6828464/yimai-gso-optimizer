<?php
/**
 * AI 引荐流量追踪器 — Referrer 解析 + 访问日志写入
 *
 * 识别 AI 平台引荐来源（ChatGPT/Perplexity/Claude/Gemini/Copilot/DeepSeek/豆包/Kimi/元宝）
 * 写入 wp_yimai_gso_referral_logs 表，供第5层仪表盘统计使用
 *
 * 触发: shutdown 钩子，仅在匹配到 AI 平台 Referrer 时写入，正常用户零开销
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Referral_Tracker {

    private static $instance = null;

    /** 数据库表名 */
    const TABLE_NAME = 'yimai_gso_referral_logs';

    /** AI 平台 Referrer 域名匹配模式 */
    const PLATFORM_PATTERNS = array(
        'chatgpt'    => array( 'chatgpt.com', 'chat.openai.com' ),
        'perplexity' => array( 'perplexity.ai' ),
        'claude'     => array( 'claude.ai' ),
        'gemini'     => array( 'gemini.google.com' ),
        'copilot'    => array( 'copilot.microsoft.com', 'bing.com/chat' ),
        'deepseek'   => array( 'chat.deepseek.com' ),
        'doubao'     => array( 'doubao.com', 'www.doubao.com' ),
        'kimi'       => array( 'kimi.moonshot.cn' ),
        'yuanbao'    => array( 'yuanbao.tencent.com' ),
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'shutdown', array( $this, 'maybe_log' ), 998 );
    }

    // ==================== 平台识别 ====================

    /**
     * 从 Referrer URL 检测 AI 平台
     *
     * @param string $referrer
     * @return string|null 平台标识（如 'chatgpt'）或 null
     */
    public static function detect_platform( $referrer ) {
        if ( empty( $referrer ) ) {
            return null;
        }

        $host = parse_url( $referrer, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return null;
        }

        $host = strtolower( $host );

        foreach ( self::PLATFORM_PATTERNS as $platform => $domains ) {
            foreach ( $domains as $domain ) {
                if ( false !== strpos( $host, $domain ) ) {
                    return $platform;
                }
            }
        }

        return null;
    }

    /**
     * 获取平台中文名称
     *
     * @param string $platform
     * @return string
     */
    public static function get_platform_label( $platform ) {
        $labels = array(
            'chatgpt'    => 'ChatGPT / OpenAI',
            'perplexity' => 'Perplexity',
            'claude'     => 'Claude / Anthropic',
            'gemini'     => 'Google Gemini',
            'copilot'    => 'Copilot / Bing',
            'deepseek'   => 'DeepSeek',
            'doubao'     => '豆包 (字节)',
            'kimi'       => 'Kimi',
            'yuanbao'    => '腾讯元宝',
        );

        return isset( $labels[ $platform ] ) ? $labels[ $platform ] : $platform;
    }

    /**
     * 获取所有平台颜色（供仪表盘图表使用）
     *
     * @return array
     */
    public static function get_platform_colors() {
        return array(
            'chatgpt'    => '#10a37f',
            'perplexity' => '#2563eb',
            'claude'     => '#d97706',
            'gemini'     => '#4285f4',
            'copilot'    => '#00a4ef',
            'deepseek'   => '#4f46e5',
            'doubao'     => '#e11d48',
            'kimi'       => '#8b5cf6',
            'yuanbao'    => '#059669',
        );
    }

    // ==================== 数据库 ====================

    /**
     * 获取完整表名
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * 创建引荐流量日志表
     *
     * @return bool
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table_name();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `{$table}` (
            `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `platform`        VARCHAR(50)  NOT NULL COMMENT 'AI平台如chatgpt',
            `referrer_url`    VARCHAR(2083) DEFAULT NULL COMMENT '完整Referrer URL',
            `landing_url`     VARCHAR(2083) NOT NULL COMMENT '落地页URL',
            `post_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT '关联文章ID',
            `utm_source`      VARCHAR(100) DEFAULT NULL,
            `utm_medium`      VARCHAR(100) DEFAULT NULL,
            `utm_campaign`    VARCHAR(200) DEFAULT NULL,
            `user_agent`      VARCHAR(500) DEFAULT NULL,
            `ip_address`      VARCHAR(45)  DEFAULT NULL,
            `visit_time`      DATETIME NOT NULL,
            INDEX `idx_platform` (`platform`, `visit_time`),
            INDEX `idx_post`     (`post_id`, `visit_time`),
            INDEX `idx_time`     (`visit_time`)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
    }

    /**
     * 确保表存在 — 在初始化时懒检查
     */
    public static function ensure_table() {
        if ( false === get_transient( 'yimai_gso_referral_table_checked' ) ) {
            set_transient( 'yimai_gso_referral_table_checked', 1, HOUR_IN_SECONDS );
            self::create_table();
        }
    }

    // ==================== 日志写入 ====================

    /**
     * Shutdown 钩子: 检测并记录 AI 平台引荐流量
     */
    public function maybe_log() {
        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
        if ( empty( $referrer ) ) {
            return;
        }

        $platform = self::detect_platform( $referrer );
        if ( ! $platform ) {
            return;
        }

        self::log_visit( $platform, $referrer );
    }

    /**
     * 写入一条引荐流量日志
     *
     * @param string $platform
     * @param string $referrer
     */
    public static function log_visit( $platform, $referrer ) {
        global $wpdb;
        $table = self::table_name();

        self::ensure_table();

        // 安全修正（S1 同类缺陷）：HTTP_HOST / REQUEST_URI 攻击者可控，不得原样拼接入库。
        // 同时 Host 头投毒会让 url_to_postid() 解析出错误结果，故先校验 host 再使用。
        $home_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $req_host  = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_HOST'] ) ) : '';
        $host_ok   = ( '' !== $req_host && '' !== $home_host && strtolower( $home_host ) === $req_host );

        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $host_ok ? $req_host : $home_host;
        $uri    = esc_url_raw( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );

        $landing_url = esc_url_raw( $scheme . $host . $uri );
        if ( '' === $landing_url ) {
            $landing_url = home_url( '/' );
        }
        $landing_url = substr( $landing_url, 0, 2083 );

        // 尝试解析 landing URL 对应的 post_id
        $post_id = null;
        if ( function_exists( 'url_to_postid' ) ) {
            $post_id = url_to_postid( $landing_url );
            if ( 0 === $post_id ) {
                $post_id = null;
            }
        }

        $data = array(
            'platform'     => $platform,
            'referrer_url' => substr( $referrer, 0, 2083 ),
            'landing_url'  => $landing_url,
            'post_id'      => $post_id,
            'utm_source'   => isset( $_GET['utm_source'] )   ? substr( sanitize_text_field( $_GET['utm_source'] ), 0, 100 ) : null,
            'utm_medium'   => isset( $_GET['utm_medium'] )   ? substr( sanitize_text_field( $_GET['utm_medium'] ), 0, 100 ) : null,
            'utm_campaign' => isset( $_GET['utm_campaign'] ) ? substr( sanitize_text_field( $_GET['utm_campaign'] ), 0, 200 ) : null,
            'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 500 ) : null,
            'ip_address'   => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null,
            'visit_time'   => current_time( 'mysql' ),
        );

        $wpdb->insert( $table, $data );
    }

    // ==================== 统计查询 ====================

    /**
     * 获取引荐流量统计数据
     *
     * @param int $days
     * @return array { total_visits, by_platform[], unique_pages }
     */
    public static function get_stats( $days = 30 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // 按平台聚合
        $by_platform = $wpdb->get_results( $wpdb->prepare(
            "SELECT platform, COUNT(*) as total_visits,
                    COUNT(DISTINCT landing_url) as unique_pages,
                    COUNT(DISTINCT post_id) as unique_posts
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY platform
             ORDER BY total_visits DESC",
            $since
        ), ARRAY_A );

        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE visit_time >= %s",
            $since
        ) );

        return array(
            'total_visits' => (int) $total,
            'by_platform'  => $by_platform ?: array(),
            'days'         => $days,
        );
    }

    /**
     * 获取每日引荐流量趋势
     *
     * @param int $days
     * @return array
     */
    public static function get_daily_trend( $days = 30 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(visit_time) as date, COUNT(*) as visits,
                    COUNT(DISTINCT platform) as unique_platforms
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY DATE(visit_time)
             ORDER BY date ASC",
            $since
        ), ARRAY_A );

        return $results ?: array();
    }

    /**
     * 获取被引荐最多的页面排行
     *
     * @param int $days
     * @param int $limit
     * @return array
     */
    public static function get_top_landing_pages( $days = 30, $limit = 20 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT landing_url, post_id, COUNT(*) as hits,
                    GROUP_CONCAT(DISTINCT platform ORDER BY platform SEPARATOR ', ') as platforms
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY landing_url, post_id
             ORDER BY hits DESC
             LIMIT %d",
            $since, $limit
        ), ARRAY_A ) ?: array();
    }

    /**
     * AI 引荐 vs 传统搜索流量对比趋势
     *
     * 传统搜索来源通过 Referrer 中的搜索引擎域名识别
     *
     * @param int $days
     * @return array { ai[], search[] }
     */
    public static function get_ai_vs_search_trend( $days = 30 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $ai_daily = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(visit_time) as date, COUNT(*) as visits
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY DATE(visit_time)
             ORDER BY date ASC",
            $since
        ), ARRAY_A );

        // 注：传统搜索流量数据源需要额外采集（Google Analytics / Search Console）
        // 这里返回占位数据，实际前台显示 "需接入GA/GSC数据"
        return array(
            'ai'     => $ai_daily ?: array(),
            'search' => array(), // 占位
        );
    }
}
