<?php
/**
 * 内容新鲜度监控
 *
 * 追踪文章更新状态，AI 搜索引擎偏好最新信息。
 * 时间分层：新鲜(<1月) / 正常(1-6月) / 可能过时(6-12月) / 过时(>12月)
 * 功能：文章列表列、批量标记、仪表盘统计
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.8.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Content_Freshness {

    private static $instance = null;

    /** 新鲜度阈值（秒） */
    const FRESH_DAYS     = 30;
    const NORMAL_DAYS    = 180;
    const STALE_DAYS     = 365;

    /** 新鲜度等级 */
    const LEVEL_FRESH  = 'fresh';
    const LEVEL_NORMAL = 'normal';
    const LEVEL_STALE  = 'stale';
    const LEVEL_OLD    = 'old';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'save_post', array( $this, 'on_save_post' ), 20 );
        add_action( 'wp_ajax_yimai_gso_refresh_content', array( $this, 'ajax_refresh_content' ) );
        add_action( 'wp_ajax_yimai_gso_batch_mark_fresh', array( $this, 'ajax_batch_mark_fresh' ) );
        add_action( 'wp_ajax_yimai_gso_get_freshness_stats', array( $this, 'ajax_get_stats' ) );
        add_action( 'wp_ajax_yimai_gso_get_freshness_posts', array( $this, 'ajax_get_freshness_posts' ) );
    }

    /* ================================================
     * 新鲜度计算
     * ================================================ */

    /**
     * 计算文章新鲜度
     * @param int $post_id
     * @return array { status, label, color, days_since_update, last_modified, score }
     */
    public function calculate( $post_id ) {
        $modified = get_post_modified_time( 'U', false, $post_id );
        if ( ! $modified ) {
            return $this->unknown_status();
        }

        $now   = current_time( 'timestamp' );
        $days  = floor( ( $now - $modified ) / DAY_IN_SECONDS );

        if ( $days <= self::FRESH_DAYS ) {
            $status = self::LEVEL_FRESH;
            $label  = '新鲜';
            $color  = '#059669';
            $score  = 7; // 满分
        } elseif ( $days <= self::NORMAL_DAYS ) {
            $status = self::LEVEL_NORMAL;
            $label  = '正常';
            $color  = '#0284c7';
            $score  = 6;
        } elseif ( $days <= self::STALE_DAYS ) {
            $status = self::LEVEL_STALE;
            $label  = '可能过时';
            $color  = '#d97706';
            $score  = 4;
        } else {
            $status = self::LEVEL_OLD;
            $label  = '已过时';
            $color  = '#dc2626';
            $score  = 2;
        }

        $result = array(
            'status'            => $status,
            'label'             => $label,
            'color'             => $color,
            'days_since_update' => $days,
            'last_modified'     => $modified, // Unix 时间戳，用于缓存过期判断
            'last_modified_fmt' => date_i18n( 'Y-m-d', $modified ), // 格式化日期，用于展示
            'score'             => $score,
            'checked_at'        => $now,
        );

        update_post_meta( $post_id, 'yimai_gso_content_freshness', $result );

        return $result;
    }

    /**
     * 获取文章新鲜度（优先读缓存，缓存基于 post_modified 时间戳防过期）
     */
    public function get_freshness( $post_id ) {
        $cached    = get_post_meta( $post_id, 'yimai_gso_content_freshness', true );
        $modified  = get_post_modified_time( 'U', false, $post_id );

        // 缓存失效条件：不存在 或 文章被修改过（用 Unix 时间戳精确比较）
        if ( empty( $cached ) || ! isset( $cached['last_modified'] ) ) {
            return $this->calculate( $post_id );
        }

        $cached_modified = (int) $cached['last_modified'];
        if ( $cached_modified < $modified ) {
            return $this->calculate( $post_id );
        }

        return $cached;
    }

    /**
     * 获取新鲜度 SEO 评分（0-7），供 SEO Scorer 调用
     */
    public function get_freshness_score( $post_id ) {
        $data = $this->get_freshness( $post_id );
        return isset( $data['score'] ) ? (int) $data['score'] : 0;
    }

    /* ================================================
     * 钩子回调
     * ================================================ */

    /**
     * 文章保存/更新时刷新新鲜度
     */
    public function on_save_post( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) && ! YIMAI_GSO_Post_Type_Helper::is_supported( $post_id ) ) return;

        $this->calculate( $post_id );
    }

    /* ================================================
     * 批量统计
     * ================================================ */

    /**
     * 获取站点内容新鲜度统计摘要
     * @return array { total, fresh, normal, stale, old, fresh_pct }
     */
    public function get_summary_stats() {
        $cache_key = 'yimai_gso_freshness_summary';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $now = current_time( 'timestamp' );

        // 动态获取支持的内容类型
        $supported_types = array( 'post', 'page' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $supported_types = YIMAI_GSO_Post_Type_Helper::get_supported();
        }
        $pt_placeholders = implode( ',', array_fill( 0, count( $supported_types ), '%s' ) );

        $fresh_threshold  = $now - ( self::FRESH_DAYS * DAY_IN_SECONDS );
        $normal_threshold = $now - ( self::NORMAL_DAYS * DAY_IN_SECONDS );
        $stale_threshold  = $now - ( self::STALE_DAYS * DAY_IN_SECONDS );

        $query = $wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN (UNIX_TIMESTAMP(post_modified) > %d) THEN 1 ELSE 0 END) as fresh,
                SUM(CASE WHEN (UNIX_TIMESTAMP(post_modified) <= %d AND UNIX_TIMESTAMP(post_modified) > %d) THEN 1 ELSE 0 END) as normal,
                SUM(CASE WHEN (UNIX_TIMESTAMP(post_modified) <= %d AND UNIX_TIMESTAMP(post_modified) > %d) THEN 1 ELSE 0 END) as stale,
                SUM(CASE WHEN (UNIX_TIMESTAMP(post_modified) <= %d) THEN 1 ELSE 0 END) as old
            FROM {$wpdb->posts}
            WHERE post_type IN ($pt_placeholders)
              AND post_status = 'publish'",
            $fresh_threshold,
            $fresh_threshold, $normal_threshold,
            $normal_threshold, $stale_threshold,
            $stale_threshold,
            ...array_values( $supported_types )
        );

        $results = $wpdb->get_results( $query, ARRAY_A );

        $stats = ! empty( $results ) ? $results[0] : array( 'total' => 0, 'fresh' => 0, 'normal' => 0, 'stale' => 0, 'old' => 0 );
        $stats['fresh_pct'] = $stats['total'] > 0 ? round( $stats['fresh'] / $stats['total'] * 100, 1 ) : 0;

        set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    /* ================================================
     * AJAX 接口
     * ================================================ */

    /**
     * 一键刷新：将文章标记为已审核（更新 post_modified）
     */
    public function ajax_refresh_content() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '无效的文章 ID' ) );
        }

        // 更新 post_modified 时间戳
        wp_update_post( array(
            'ID'                => $post_id,
            'post_modified'     => current_time( 'mysql' ),
            'post_modified_gmt' => current_time( 'mysql', true ),
        ) );

        // 重新计算新鲜度
        $freshness = $this->calculate( $post_id );

        // 清除统计缓存
        delete_transient( 'yimai_gso_freshness_summary' );

        wp_send_json_success( array(
            'message'   => '已标记为已审核',
            'freshness' => $freshness,
        ) );
    }

    /**
     * 批量标记为新鲜
     */
    public function ajax_batch_mark_fresh() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', $_POST['post_ids'] ) : array();
        if ( empty( $post_ids ) ) {
            wp_send_json_error( array( 'message' => '请选择文章' ) );
        }

        $count   = 0;
        $skipped = 0;
        foreach ( $post_ids as $pid ) {
            // 越权修正（S3）：批量接口原先只校验 edit_posts，对任意 post_ids 直接
            // wp_update_post()，没有逐篇归属检查 → 低权限账号可改动他人已发布文章。
            // 正确做法：逐篇校验 edit_post 元能力，无权限的跳过而非整批失败。
            if ( ! current_user_can( 'edit_post', $pid ) ) {
                $skipped++;
                continue;
            }

            wp_update_post( array(
                'ID'                => $pid,
                'post_modified'     => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', true ),
            ) );
            $this->calculate( $pid );
            $count++;
        }

        delete_transient( 'yimai_gso_freshness_summary' );

        $message = sprintf( '已刷新 %d 篇文章的新鲜度', $count );
        if ( $skipped > 0 ) {
            $message .= sprintf( '，%d 篇因权限不足已跳过', $skipped );
        }

        wp_send_json_success( array(
            'message' => $message,
            'count'   => $count,
            'skipped' => $skipped,
        ) );
    }

    /**
     * 获取新鲜度统计数据
     */
    public function ajax_get_stats() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $stats = $this->get_summary_stats();
        wp_send_json_success( $stats );
    }

    /**
     * 获取新鲜度文章列表（分页、可筛选）
     */
    public function ajax_get_freshness_posts() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $per_page = 20;
        $level    = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : '';
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

        global $wpdb;

        $supported_types = array( 'post', 'page' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $supported_types = YIMAI_GSO_Post_Type_Helper::get_supported();
        }
        $pt_placeholders = implode( ',', array_fill( 0, count( $supported_types ), '%s' ) );

        $fresh_sec  = self::FRESH_DAYS * DAY_IN_SECONDS;
        $normal_sec = self::NORMAL_DAYS * DAY_IN_SECONDS;
        $stale_sec  = self::STALE_DAYS * DAY_IN_SECONDS;

        // 新鲜度 SQL CASE
        $freshness_case = "CASE
            WHEN (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(p.post_modified)) <= {$fresh_sec} THEN 'fresh'
            WHEN (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(p.post_modified)) <= {$normal_sec} THEN 'normal'
            WHEN (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(p.post_modified)) <= {$stale_sec} THEN 'stale'
            ELSE 'old'
        END";

        // 构建 WHERE
        $where = "p.post_type IN ($pt_placeholders) AND p.post_status = 'publish'";
        $params = array_values( $supported_types );

        if ( $level && in_array( $level, array( 'fresh', 'normal', 'stale', 'old' ) ) ) {
            $where .= " AND {$freshness_case} = %s";
            $params[] = $level;
        }

        if ( $search ) {
            $where .= ' AND p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        // 总数
        $count_query = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_query, ...$params ) );

        // 分页数据
        $offset = ( $page - 1 ) * $per_page;
        $data_query = "SELECT
            p.ID, p.post_title, p.post_type,
            UNIX_TIMESTAMP(p.post_modified) as modified_ts,
            {$freshness_case} as freshness_level,
            (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(p.post_modified)) as days_since,
            p.post_modified as last_modified_raw
        FROM {$wpdb->posts} p
        WHERE {$where}
        ORDER BY p.post_modified ASC
        LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $results = $wpdb->get_results( $wpdb->prepare( $data_query, ...$params ), ARRAY_A );

        $posts = array();
        $level_labels = array(
            'fresh'  => array( 'label' => '新鲜', 'color' => '#059669', 'score' => 7 ),
            'normal' => array( 'label' => '正常', 'color' => '#0284c7', 'score' => 6 ),
            'stale'  => array( 'label' => '可能过时', 'color' => '#d97706', 'score' => 4 ),
            'old'    => array( 'label' => '已过时', 'color' => '#dc2626', 'score' => 2 ),
        );

        foreach ( $results as $row ) {
            $lvl = $row['freshness_level'];
            $meta = isset( $level_labels[ $lvl ] ) ? $level_labels[ $lvl ] : array( 'label' => '未知', 'color' => '#94a3b8', 'score' => 3 );
            $posts[] = array(
                'id'            => (int) $row['ID'],
                'title'         => $row['post_title'],
                'edit_url'      => get_edit_post_link( (int) $row['ID'], '' ),
                'type'          => $row['post_type'],
                'level'         => $lvl,
                'label'         => $meta['label'],
                'color'         => $meta['color'],
                'score'         => $meta['score'],
                'days'          => (int) floor( $row['days_since'] / DAY_IN_SECONDS ),
                'last_modified' => $row['last_modified_raw'],
            );
        }

        $pages = max( 1, (int) ceil( $total / $per_page ) );

        wp_send_json_success( array(
            'posts' => $posts,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ) );
    }

    /* ================================================
     * 辅助
     * ================================================ */

    private function unknown_status() {
        return array(
            'status'            => 'unknown',
            'label'             => '未知',
            'color'             => '#94a3b8',
            'days_since_update' => 0,
            'last_modified'     => 0,
            'last_modified_fmt' => '',
            'score'             => 3,
        );
    }
}
