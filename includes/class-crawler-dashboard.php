<?php
/**
 * AI 爬虫仪表盘 — 时序统计 + 家族聚合 + Top 页面排行
 *
 * 提供所有 AJAX 端点供 l5-dashboard.js 中的 Chart.js 图表消费
 * 数据源：wp_geo_crawler_logs 表（L1层 YIMAI_GSO_Crawler_Tracker 填充）
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Crawler_Dashboard {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_l5_crawler_stats',    array( $this, 'ajax_stats' ) );
        add_action( 'wp_ajax_yimai_gso_l5_crawler_trend',    array( $this, 'ajax_trend' ) );
        add_action( 'wp_ajax_yimai_gso_l5_crawler_family',    array( $this, 'ajax_family' ) );
        add_action( 'wp_ajax_yimai_gso_l5_crawler_ranking',   array( $this, 'ajax_ranking' ) );
        add_action( 'wp_ajax_yimai_gso_l5_crawler_top_pages', array( $this, 'ajax_top_pages' ) );
        add_action( 'wp_ajax_yimai_gso_l5_crawler_recent',    array( $this, 'ajax_recent' ) );
    }

    // ==================== 统计卡片数据 ====================

    /**
     * AJAX: yimai_gso_l5_crawler_stats
     * 返回总览统计卡片数据
     */
    public function ajax_stats() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        $days = in_array( $days, array( 7, 30, 90 ) ) ? $days : 30;

        $cache_key = 'yimai_gso_l5_crawler_stats_' . $days;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        $stats = YIMAI_GSO_Crawler_Tracker::get_stats( $days );

        // 抓活跃爬虫数
        $unique_crawlers = 0;
        if ( ! empty( $stats['by_crawler'] ) ) {
            $unique_crawlers = count( $stats['by_crawler'] );
        }

        // 统计唯一 URL 数
        $top_pages = YIMAI_GSO_Crawler_Tracker::get_top_pages( $days, 100 );
        $unique_pages = count( $top_pages );

        // 今日访问量
        $today_count = self::get_today_count();

        $result = array(
            'total_visits'    => (int) $stats['total_visits'],
            'unique_crawlers' => $unique_crawlers,
            'unique_pages'    => $unique_pages,
            'today_count'     => $today_count,
            'days'            => $days,
        );

        $ttl = ( $days <= 7 ) ? 5 * MINUTE_IN_SECONDS : ( $days <= 30 ? 10 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
        set_transient( $cache_key, $result, $ttl );

        wp_send_json_success( $result );
    }

    /**
     * 获取今日爬虫访问量
     */
    private static function get_today_count() {
        global $wpdb;
        $table = YIMAI_GSO_Crawler_Tracker::table_name();
        YIMAI_GSO_Crawler_Tracker::ensure_table();

        $today = current_time( 'Y-m-d' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE DATE(visit_time) = %s",
            $today
        ) );
    }

    // ==================== 访问趋势（折线图） ====================

    /**
     * AJAX: yimai_gso_l5_crawler_trend
     * 返回按日聚合的爬虫访问趋势
     */
    public function ajax_trend() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        $days = in_array( $days, array( 7, 30, 90 ) ) ? $days : 30;

        $cache_key = 'yimai_gso_l5_crawler_trend_' . $days;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;
        $table = YIMAI_GSO_Crawler_Tracker::table_name();
        YIMAI_GSO_Crawler_Tracker::ensure_table();

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(visit_time) as date,
                    COUNT(*) as visits,
                    COUNT(DISTINCT crawler_key) as unique_crawlers
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY DATE(visit_time)
             ORDER BY date ASC",
            $since
        ), ARRAY_A );

        $ttl = ( $days <= 7 ) ? 5 * MINUTE_IN_SECONDS : ( $days <= 30 ? 10 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
        set_transient( $cache_key, $results, $ttl );

        wp_send_json_success( $results ?: array() );
    }

    // ==================== 家族分布（饼图/环形图） ====================

    /**
     * AJAX: yimai_gso_l5_crawler_family
     * 返回按爬虫家族聚合的数据
     */
    public function ajax_family() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        $days = in_array( $days, array( 7, 30, 90 ) ) ? $days : 30;

        $cache_key = 'yimai_gso_l5_crawler_family_' . $days;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;
        $table = YIMAI_GSO_Crawler_Tracker::table_name();
        YIMAI_GSO_Crawler_Tracker::ensure_table();

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT family,
                    COUNT(*) as total,
                    COUNT(DISTINCT request_url) as unique_urls,
                    COUNT(DISTINCT crawler_key) as crawler_count
             FROM `{$table}`
             WHERE visit_time >= %s
             GROUP BY family
             ORDER BY total DESC",
            $since
        ), ARRAY_A );

        // 添加颜色
        $family_colors = array(
            'openai'     => '#10a37f',
            'google'     => '#4285f4',
            'anthropic'  => '#d97706',
            'perplexity'  => '#2563eb',
            'deepseek'   => '#4f46e5',
            'bytedance'  => '#e11d48',
            'microsoft'  => '#00a4ef',
            'baidu'      => '#de3918',
            'apple'      => '#555555',
            'research'   => '#6b7280',
            'meta'       => '#1877f2',
            'alibaba'    => '#ff6a00',
            'sogou'      => '#f04e23',
            '360'        => '#3cb043',
        );

        $family_labels = array(
            'openai'     => 'OpenAI',
            'google'     => 'Google',
            'anthropic'  => 'Anthropic',
            'perplexity'  => 'Perplexity',
            'deepseek'   => 'DeepSeek',
            'bytedance'  => '字节跳动',
            'microsoft'  => 'Microsoft',
            'baidu'      => '百度',
            'apple'      => 'Apple',
            'research'   => '研究机构',
            'meta'       => 'Meta',
            'alibaba'    => '阿里巴巴',
            'sogou'      => '搜狗',
            '360'        => '360',
        );

        foreach ( $results as &$row ) {
            $row['color'] = isset( $family_colors[ $row['family'] ] ) ? $family_colors[ $row['family'] ] : '#9ca3af';
            $row['label'] = isset( $family_labels[ $row['family'] ] ) ? $family_labels[ $row['family'] ] : $row['family'];
            $row['total'] = (int) $row['total'];
        }

        $ttl = ( $days <= 7 ) ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
        set_transient( $cache_key, $results, $ttl );

        wp_send_json_success( $results ?: array() );
    }

    // ==================== 爬虫活跃度排行（横向柱状图） ====================

    /**
     * AJAX: yimai_gso_l5_crawler_ranking
     * 返回各爬虫访问排行
     */
    public function ajax_ranking() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        $days = in_array( $days, array( 7, 30, 90 ) ) ? $days : 30;

        $cache_key = 'yimai_gso_l5_crawler_ranking_' . $days;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        $stats = YIMAI_GSO_Crawler_Tracker::get_stats( $days );

        $ranking = array();
        if ( ! empty( $stats['by_crawler'] ) ) {
            $family_colors = array(
                'openai'    => '#10a37f', 'google'  => '#4285f4', 'anthropic' => '#d97706',
                'perplexity' => '#2563eb', 'deepseek' => '#4f46e5', 'bytedance' => '#e11d48',
                'microsoft' => '#00a4ef', 'baidu'   => '#de3918',
            );
            $cached_count = 0;
            foreach ( $stats['by_crawler'] as $row ) {
                // 统计该爬虫最近多久出现过
                $last_visited = ! empty( $row['last_visit'] )
                    ? human_time_diff( strtotime( $row['last_visit'] ), current_time( 'timestamp' ) ) . ' 前'
                    : '未知';

                $ranking[] = array(
                    'crawler_key'  => $row['crawler_key'],
                    'crawler_name' => $row['crawler_name'],
                    'family'       => $row['family'],
                    'total_visits' => (int) $row['total_visits'],
                    'unique_urls'  => (int) $row['unique_urls'],
                    'last_visit'   => $last_visited,
                    'color'        => isset( $family_colors[ $row['family'] ] ) ? $family_colors[ $row['family'] ] : '#9ca3af',
                );
                $cached_count++;
            }
        }

        $ttl = ( $days <= 7 ) ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
        set_transient( $cache_key, $ranking, $ttl );

        wp_send_json_success( $ranking );
    }

    // ==================== 被爬最多页面 ====================

    /**
     * AJAX: yimai_gso_l5_crawler_top_pages
     */
    public function ajax_top_pages() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $days  = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
        $days  = in_array( $days, array( 7, 30, 90 ) ) ? $days : 30;
        $limit = isset( $_GET['limit'] ) ? min( 50, max( 5, intval( $_GET['limit'] ) ) ) : 20;

        $cache_key = 'yimai_gso_l5_crawler_top_pages_' . $days . '_' . $limit;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        $pages = YIMAI_GSO_Crawler_Tracker::get_top_pages( $days, $limit );

        // 尝试解析每页的 post_id 并获取文章标题
        $url_parts = array();
        foreach ( $pages as &$page ) {
            $page['hits'] = (int) $page['hits'];
            $page['post_title'] = '';
            $page['edit_url']   = '';

            // 从 URL 中尝试提取 slug
            $path = parse_url( $page['request_url'], PHP_URL_PATH );
            if ( $path ) {
                $path = untrailingslashit( $path );
                $slug = basename( $path );
                $url_parts[] = $slug;
            }
        }

        // 批量查找 post_id
        if ( ! empty( $url_parts ) ) {
            global $wpdb;
            $placeholders = implode( ',', array_fill( 0, count( $url_parts ), '%s' ) );
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_name IN ({$placeholders})",
                ...$url_parts
            ), ARRAY_A );

            $slug_map = array();
            if ( $results ) {
                foreach ( $results as $r ) {
                    $slug_map[ $r['post_name'] ] = $r;
                }
            }

            foreach ( $pages as &$page ) {
                $path = parse_url( $page['request_url'], PHP_URL_PATH );
                $slug = basename( untrailingslashit( $path ) );

                if ( isset( $slug_map[ $slug ] ) ) {
                    $page['post_title'] = $slug_map[ $slug ]['post_title'];
                    $page['edit_url']   = get_edit_post_link( $slug_map[ $slug ]['ID'], 'raw' );
                } else {
                    // 如果找不到，显示 URL 最后一段
                    $page['post_title'] = '/' . ltrim( $path, '/' );
                }
            }
        }

        $ttl = ( $days <= 7 ) ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
        set_transient( $cache_key, $pages, $ttl );

        wp_send_json_success( $pages );
    }

    // ==================== 最近访问日志 ====================

    /**
     * AJAX: yimai_gso_l5_crawler_recent
     * 返回最近N条爬虫访问日志
     */
    public function ajax_recent() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $limit = isset( $_GET['limit'] ) ? min( 50, max( 5, intval( $_GET['limit'] ) ) ) : 10;

        $cache_key = 'yimai_gso_l5_crawler_recent_' . $limit;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;
        $table = YIMAI_GSO_Crawler_Tracker::table_name();
        YIMAI_GSO_Crawler_Tracker::ensure_table();

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT crawler_key, crawler_name, family, request_url, visit_time, response_code
             FROM `{$table}`
             ORDER BY visit_time DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        // 格式化访问时间
        foreach ( $results as &$row ) {
            $row['time_ago'] = human_time_diff( strtotime( $row['visit_time'] ), current_time( 'timestamp' ) ) . ' 前';
        }

        set_transient( $cache_key, $results, 5 * MINUTE_IN_SECONDS );

        wp_send_json_success( $results ?: array() );
    }
}
