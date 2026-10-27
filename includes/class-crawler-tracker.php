<?php
/**
 * AI 爬虫追踪器 — User-Agent 识别 + 访问日志写入
 *
 * 提供:
 * - 30+ 种 AI 爬虫 UA 识别
 * - 访问日志写入 wp_geo_crawler_logs 表
 * - 统计查询接口
 *
 * 触发: shutdown 钩子，仅在匹配到 AI 爬虫 UA 时写入，正常用户零开销
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Crawler_Tracker {

    private static $instance = null;

    /** 已知 AI 爬虫 UA 映射表 */
    const KNOWN_CRAWLERS = array(
        // ─── 训练型（高频抓取，消耗资源，谨慎放行）───
        'GPTBot'              => array( 'name' => 'ChatGPT / OpenAI',       'family' => 'openai',    'type' => 'training' ),
        'ClaudeBot'           => array( 'name' => 'Claude / Anthropic',     'family' => 'anthropic', 'type' => 'training' ),
        'Bytespider'          => array( 'name' => '字节通用爬虫 (字节)',     'family' => 'bytedance', 'type' => 'training' ),
        'DoubaoBot'           => array( 'name' => '豆包 AI采集 (字节)',       'family' => 'bytedance', 'type' => 'training' ),
        'AISpider'            => array( 'name' => '文心一言 AI采集 (百度)',   'family' => 'baidu',     'type' => 'training' ),
        'HunyuanBot'          => array( 'name' => '混元大模型 (腾讯)',         'family' => 'tencent',   'type' => 'training' ),
        'KimiBot'             => array( 'name' => 'Kimi 训练采集',           'family' => 'moonshot',  'type' => 'training' ),
        'AlibabaBot'          => array( 'name' => '阿里巴巴通用采集 (阿里)', 'family' => 'alibaba',   'type' => 'training' ),
        'TongyiBot'           => array( 'name' => '通义千问 AI训练 (阿里)',   'family' => 'alibaba',   'type' => 'training' ),
        'DeepSeekBot'         => array( 'name' => 'DeepSeek',               'family' => 'deepseek',  'type' => 'training' ),
        'Meta-ExternalFetcher'=> array( 'name' => 'Meta AI',                'family' => 'meta',      'type' => 'training' ),
        'Applebot'            => array( 'name' => 'Apple Intelligence',     'family' => 'apple',     'type' => 'training' ),
        'CCBot'               => array( 'name' => 'Common Crawl',           'family' => 'research',  'type' => 'training' ),
        'Microsoft-Edge-Bot'  => array( 'name' => 'Edge AI采集 (微软)',       'family' => 'microsoft', 'type' => 'training' ),
        // ─── 搜索/引用型（建索引、带返链流量，建议放行）───
        'OAI-SearchBot'       => array( 'name' => 'ChatGPT Search',         'family' => 'openai',    'type' => 'search' ),
        'Kimi-SearchBot'      => array( 'name' => 'Kimi 搜索索引',           'family' => 'moonshot',  'type' => 'search' ),
        'PerplexityBot'       => array( 'name' => 'Perplexity',             'family' => 'perplexity','type' => 'search' ),
        'Claude-SearchBot'    => array( 'name' => 'Claude Search',          'family' => 'anthropic', 'type' => 'search' ),
        'Google-Extended'     => array( 'name' => 'Google AI Overviews',    'family' => 'google',    'type' => 'search' ),
        'Gemini'              => array( 'name' => 'Google Gemini',          'family' => 'google',    'type' => 'search' ),
        'BingBot'             => array( 'name' => 'Bing / Copilot (微软)',   'family' => 'microsoft', 'type' => 'search' ),
        'Baiduspider-mobile'  => array( 'name' => '百度移动搜索 (百度)',     'family' => 'baidu',     'type' => 'search' ),
        'Baiduspider'         => array( 'name' => '百度搜索 (百度)',         'family' => 'baidu',     'type' => 'search' ),
        'YisouSpider'         => array( 'name' => '夸克/神马搜索 (阿里)',   'family' => 'alibaba',   'type' => 'search' ),
        'Sogou web spider'    => array( 'name' => '搜狗搜索',                'family' => 'sogou',     'type' => 'search' ),
        '360Spider'           => array( 'name' => '360 搜索',                'family' => '360',       'type' => 'search' ),
        'WechatBot'           => array( 'name' => '微信搜一搜',                'family' => 'tencent',   'type' => 'search' ),
        'TencentBot'          => array( 'name' => '腾讯通用爬虫',             'family' => 'tencent',   'type' => 'search' ),
        // ─── 用户触发型（按需单次抓取，几乎无影响）───
        'ChatGPT-User'        => array( 'name' => 'ChatGPT User',           'family' => 'openai',    'type' => 'user' ),
        'Kimi-User'           => array( 'name' => 'Kimi 用户抓取',           'family' => 'moonshot',  'type' => 'user' ),
    );

    /** 数据库表名 */
    const TABLE_NAME = 'geo_crawler_logs';

    /** 日志保留天数：超出部分由每日 cron 清理（表会随爬虫访问持续增长） */
    const RETENTION_DAYS = 90;

    /** 清理 cron 钩子名 */
    const CLEANUP_HOOK = 'yimai_gso_crawler_log_cleanup';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 在 shutdown 阶段异步写入日志，不阻塞用户请求
        add_action( 'shutdown', array( $this, 'maybe_log' ), 999 );
        // 每日清理过期日志（保留 RETENTION_DAYS 天）
        add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_old_logs' ) );
    }

    // ==================== 爬虫识别 ====================

    /**
     * 从 User-Agent 检测是否为已知 AI 爬虫
     *
     * @param string|null $ua User-Agent 字符串，null 则从 $_SERVER 获取
     * @return array|null 返回爬虫信息 ['key' => ..., 'name' => ..., 'family' => ...] 或 null
     */
    public static function detect( $ua = null ) {
        if ( null === $ua ) {
            $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        }

        if ( empty( $ua ) ) {
            return null;
        }

        foreach ( self::KNOWN_CRAWLERS as $key => $info ) {
            if ( false !== stripos( $ua, $key ) ) {
                return array_merge( array( 'key' => $key ), $info );
            }
        }

        return null;
    }

    /**
     * 获取所有已知爬虫列表（用于前端）
     *
     * @return array
     */
    public static function get_known_crawlers() {
        return self::KNOWN_CRAWLERS;
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
     * 创建爬虫日志表
     *
     * @return bool
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table_name();

        // 检查表是否已存在
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `{$table}` (
            `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `crawler_key`   VARCHAR(50)  NOT NULL COMMENT '爬虫标识,如 GPTBot',
            `crawler_name`  VARCHAR(100) NOT NULL COMMENT '爬虫中文名',
            `family`        VARCHAR(30)  DEFAULT NULL COMMENT '爬虫家族,如 openai',
            `user_agent`    VARCHAR(500) DEFAULT NULL COMMENT '完整 User-Agent',
            `request_url`   VARCHAR(2083) NOT NULL COMMENT '请求 URL',
            `visit_time`    DATETIME     NOT NULL COMMENT '访问时间',
            `ip_address`    VARCHAR(45)  DEFAULT NULL COMMENT 'IP 地址',
            `response_code` SMALLINT     DEFAULT 200 COMMENT '响应状态码',
            INDEX `idx_crawler` (`crawler_key`, `visit_time`),
            INDEX `idx_url`     (`request_url`(255)),
            INDEX `idx_time`    (`visit_time`)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // 验证建表结果
        return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
    }

    /**
     * 确保表存在 — 在每次 init 时懒检查
     */
    public static function ensure_table() {
        if ( false === get_transient( 'yimai_gso_crawler_table_checked' ) ) {
            set_transient( 'yimai_gso_crawler_table_checked', 1, HOUR_IN_SECONDS );
            self::create_table();
            // 老站点升级不会重跑激活钩子，清理任务在这里补注册（幂等，每小时最多检查一次）
            self::schedule_cleanup();
        }
    }

    // ==================== 日志写入 ====================

    /**
     * Shutdown 钩子: 检测并记录 AI 爬虫访问
     */
    public function maybe_log() {
        $crawler = self::detect();
        if ( ! $crawler ) {
            return; // 非AI爬虫，零开销
        }

        self::log_visit( $crawler );
    }

    /**
     * 构造经过校验的请求 URL（安全修正 S1）
     *
     * HTTP_HOST 与 REQUEST_URI 均为攻击者可控（请求头 / 请求行）。此处曾原样拼接入库，
     * 造成未认证的存储型 XSS：攻击者用 AI 爬虫 UA 请求携带 HTML/引号的 URL 即可写库，
     * 该值随后由 ajax_recent() 返回、被 l5-dashboard.js 直接拼进 .html()，
     * 管理员访问 L5 面板时在 wp-admin 内执行脚本（会话骑乘）。
     *
     * 修复要点：
     *  1. host 必须与 home_url() 的 host 一致，否则丢弃（防 Host 头投毒）；
     *  2. path 部分经 esc_url_raw() 过滤，去掉标签与危险字符；
     *  3. 最终仍由输出侧独立转义（双保险，见 l5-dashboard.js）。
     *
     * @return string 安全的请求 URL；host 不可信时返回站点首页 URL
     */
    private static function build_validated_request_url() {
        $home_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $req_host  = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_HOST'] ) ) : '';

        // Host 头与站点 host 不一致（含带端口/畸形值）时，不信任请求中的 host
        $host_ok = ( '' !== $req_host && '' !== $home_host && strtolower( $home_host ) === $req_host );

        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $host_ok ? $req_host : $home_host;
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';

        // 先做 URL 过滤（剥离标签、编码危险字符），再拼接
        $uri = esc_url_raw( $uri );

        $url = $scheme . $host . $uri;

        // 最终再兜一层：过滤结果若仍不合法则退回首页
        $url = esc_url_raw( $url );

        return '' !== $url ? $url : home_url( '/' );
    }

    /**
     * 写入一条爬虫访问日志
     *
     * @param array $crawler detect() 返回的爬虫信息
     */
    public static function log_visit( $crawler ) {
        global $wpdb;
        $table = self::table_name();

        // 确保表存在
        self::ensure_table();

        $request_url = self::build_validated_request_url();

        $request_url = substr( $request_url, 0, 2083 );

        $data = array(
            'crawler_key'   => $crawler['key'],
            'crawler_name'  => $crawler['name'],
            'family'        => $crawler['family'],
            'user_agent'    => substr( ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' ), 0, 500 ),
            'request_url'   => $request_url,
            'visit_time'    => current_time( 'mysql' ),
            'ip_address'    => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '',
            'response_code' => http_response_code() ?: 200,
        );

        $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );
    }

    // ==================== 统计查询 ====================

    /**
     * 获取爬虫访问统计（供后续仪表盘使用）
     *
     * @param int $days 统计天数
     * @return array
     */
    public static function get_stats( $days = 30 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $since = self::since_datetime( $days );

        // 按爬虫聚合
        $by_crawler = $wpdb->get_results( $wpdb->prepare(
            "SELECT crawler_key, crawler_name, family, COUNT(*) as total_visits,
                    COUNT(DISTINCT request_url) as unique_urls,
                    MAX(visit_time) as last_visit
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY crawler_key, crawler_name, family
             ORDER BY total_visits DESC",
            $since
        ), ARRAY_A );

        // 总访问量
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE visit_time >= %s",
            $since
        ) );

        // 状态码拆分：404 撞库与真实抓取必须分开算，否则机器人扫站会让曲线虚高
        $ok   = 0;
        $fail = 0;
        $split = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(CASE WHEN response_code = 200 THEN 1 ELSE 0 END) AS ok_hits,
                    SUM(CASE WHEN response_code <> 200 THEN 1 ELSE 0 END) AS fail_hits
             FROM `{$table}`
             WHERE visit_time >= %s",
            $since
        ), ARRAY_A );
        if ( $split ) {
            $ok   = (int) $split['ok_hits'];
            $fail = (int) $split['fail_hits'];
        }

        return array(
            'total_visits' => (int) $total,
            'ok_visits'    => $ok,
            'fail_visits'  => $fail,
            'by_crawler'   => $by_crawler ?: array(),
            'days'         => $days,
        );
    }

    /**
     * 获取被爬最多的页面排行
     *
     * @param int $days
     * @param int $limit
     * @return array
     */
    public static function get_top_pages( $days = 30, $limit = 20 ) {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $since = self::since_datetime( $days );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT request_url, COUNT(*) as hits,
                    SUM(CASE WHEN response_code = 200 THEN 1 ELSE 0 END) as ok_hits,
                    SUM(CASE WHEN response_code <> 200 THEN 1 ELSE 0 END) as fail_hits,
                    SUM(CASE WHEN response_code = 404 THEN 1 ELSE 0 END) as hits_404,
                    MAX(response_code) as max_code,
                    GROUP_CONCAT(DISTINCT crawler_name ORDER BY crawler_name SEPARATOR ', ') as crawlers
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY request_url
             ORDER BY hits DESC
             LIMIT %d",
            $since, $limit
        ), ARRAY_A ) ?: array();
    }

    // ==================== 保留策略 ====================

    /**
     * 计算统计窗口起点（站点本地时间）
     *
     * visit_time 由 current_time('mysql') 写入，是站点本地时间；
     * 原先用 gmdate() 取 UTC 起点，在 +08:00 站点上窗口整体偏移 8 小时。
     *
     * @param int $days
     * @return string 'Y-m-d H:i:s'
     */
    private static function since_datetime( $days ) {
        $days = max( 1, (int) $days );
        return gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
    }

    /**
     * 注册每日清理任务（激活时调用，幂等）
     */
    public static function schedule_cleanup() {
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    /**
     * 注销清理任务（停用/卸载时调用）
     */
    public static function unschedule_cleanup() {
        $timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
        }
    }

    /**
     * 清理超过保留期的日志（分批删，避免一次性 DELETE 长时间锁表）
     *
     * @return int 删除行数
     */
    public static function cleanup_old_logs() {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();

        $cutoff = gmdate(
            'Y-m-d H:i:s',
            strtotime( '-' . self::RETENTION_DAYS . ' days', current_time( 'timestamp' ) )
        );

        $deleted = 0;
        $batch   = 5000;
        do {
            $rows = $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE visit_time < %s LIMIT %d",
                $cutoff,
                $batch
            ) );
            $rows = (int) $rows;
            $deleted += $rows;
        } while ( $rows === $batch );

        return $deleted;
    }

    /**
     * 表内现有行数（供后台展示与运维判断）
     *
     * @return int
     */
    public static function count_rows() {
        global $wpdb;
        $table = self::table_name();
        self::ensure_table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    }
}
