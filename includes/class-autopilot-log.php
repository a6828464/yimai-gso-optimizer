<?php
/**
 * 永动发布日志与表结构管理
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_96EY {

    const SCHEMA_VERSION = '1.0.0';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->maybe_upgrade();
    }

    private function maybe_upgrade() {
        $stored_version = (string) get_option( 'wpaip_autopilot_schema_version', '' );
        if ( self::SCHEMA_VERSION === $stored_version ) {
            return;
        }

        self::create_tables();
        update_option( 'wpaip_autopilot_schema_version', self::SCHEMA_VERSION, false );
    }


    public static function get_runs_table() {
        global $wpdb;
        return $wpdb->prefix . 'wpaip_autopilot_runs';
    }

    public static function get_items_table() {
        global $wpdb;
        return $wpdb->prefix . 'wpaip_autopilot_items';
    }

    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $runs_table      = self::get_runs_table();
        $items_table     = self::get_items_table();

        $sql_runs = "CREATE TABLE {$runs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_key varchar(64) NOT NULL,
            trigger_source varchar(20) NOT NULL DEFAULT 'cron',
            status varchar(20) NOT NULL DEFAULT 'pending',
            run_date date DEFAULT NULL,
            topic_keyword varchar(255) NOT NULL DEFAULT '',
            daily_count smallint(5) unsigned NOT NULL DEFAULT 0,
            success_count smallint(5) unsigned NOT NULL DEFAULT 0,
            failed_count smallint(5) unsigned NOT NULL DEFAULT 0,
            published_count smallint(5) unsigned NOT NULL DEFAULT 0,
            manual_requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            settings_snapshot longtext NULL,
            last_message text NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            started_at datetime DEFAULT NULL,
            finished_at datetime DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_key (run_key),
            KEY status (status),
            KEY run_date (run_date),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE {$items_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            item_index smallint(5) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            stage varchar(30) NOT NULL DEFAULT 'pending',
            title varchar(255) NOT NULL DEFAULT '',
            prompt text NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            post_status varchar(20) NOT NULL DEFAULT '',
            scheduled_at datetime DEFAULT NULL,
            error_message text NULL,
            stage_message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_item (run_id, item_index),
            KEY run_id (run_id),
            KEY status (status),
            KEY stage (stage)
        ) {$charset_collate};";

        dbDelta( $sql_runs );
        dbDelta( $sql_items );
    }

    public static function now_mysql() {
        return current_time( 'mysql' );
    }

    public static function get_run( $run_id ) {
        global $wpdb;

        $run = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::get_runs_table() . ' WHERE id = %d LIMIT 1', absint( $run_id ) ),
            ARRAY_A
        );

        return self::normalize_run_row( $run );
    }

    public static function get_run_by_key( $run_key ) {
        global $wpdb;

        $run = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::get_runs_table() . ' WHERE run_key = %s LIMIT 1', sanitize_text_field( $run_key ) ),
            ARRAY_A
        );

        return self::normalize_run_row( $run );
    }

    /**
     * 获取待执行的手动触发任务（status=pending 且 trigger_source=manual）
     * 用于 REST API cron-trigger 拾起执行，防止 WP-Cron 未触发导致任务卡住
     *
     * @param int $limit 最多返回数
     * @return array
     */
    public static function get_pending_manual_runs( $limit = 3 ) {
        global $wpdb;
        $table = self::get_runs_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND trigger_source = 'manual' ORDER BY created_at ASC LIMIT %d",
                absint( $limit )
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array();
        }

        return array_map( array( __CLASS__, 'normalize_run_row' ), $rows );
    }

    /**
     * 获取待执行的 cron 触发任务（精准模式下 WP-Cron 可能无法及时处理，用于 REST API 救急）
     *
     * @param int $limit 最多取几条
     * @return array
     */
    public static function get_pending_cron_runs( $limit = 3 ) {
        global $wpdb;
        $table = self::get_runs_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND trigger_source = 'cron' ORDER BY created_at ASC LIMIT %d",
                absint( $limit )
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array();
        }

        return array_map( array( __CLASS__, 'normalize_run_row' ), $rows );
    }

    public static function create_run( $args ) {
        global $wpdb;

        $run_key = sanitize_text_field( $args['run_key'] ?? '' );
        if ( '' === $run_key ) {
            return new WP_Error( 'missing_run_key', '缺少 run_key，无法创建永动发布任务。' );
        }

        $snapshot = $args['settings_snapshot'] ?? array();
        if ( is_array( $snapshot ) ) {
            $snapshot_json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        } else {
            $snapshot_json = wp_json_encode( array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        $data = array(
            'run_key'             => $run_key,
            'trigger_source'      => sanitize_text_field( $args['trigger_source'] ?? 'cron' ),
            'status'              => sanitize_text_field( $args['status'] ?? 'pending' ),
            'run_date'            => self::sanitize_date( $args['run_date'] ?? null ),
            'topic_keyword'       => sanitize_text_field( $args['topic_keyword'] ?? '' ),
            'daily_count'         => max( 0, absint( $args['daily_count'] ?? 0 ) ),
            'success_count'       => max( 0, absint( $args['success_count'] ?? 0 ) ),
            'failed_count'        => max( 0, absint( $args['failed_count'] ?? 0 ) ),
            'published_count'     => max( 0, absint( $args['published_count'] ?? 0 ) ),
            'manual_requested_by' => max( 0, absint( $args['manual_requested_by'] ?? 0 ) ),
            'settings_snapshot'   => $snapshot_json,
            'last_message'        => self::sanitize_text_or_null( $args['last_message'] ?? '' ),
            'last_error'          => self::sanitize_text_or_null( $args['last_error'] ?? '' ),
            'created_at'          => self::now_mysql(),
            'started_at'          => self::sanitize_datetime( $args['started_at'] ?? null ),
            'finished_at'         => self::sanitize_datetime( $args['finished_at'] ?? null ),
            'updated_at'          => self::now_mysql(),
        );

        $format = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
        $inserted = $wpdb->insert( self::get_runs_table(), $data, $format );

        if ( false === $inserted ) {
            $existing = self::get_run_by_key( $run_key );
            if ( ! empty( $existing ) ) {
                return $existing;
            }
            return new WP_Error( 'create_run_failed', '永动发布任务写入失败：' . $wpdb->last_error );
        }

        return self::get_run( $wpdb->insert_id );
    }

    public static function update_run( $run_id, $args ) {
        global $wpdb;

        $run_id = absint( $run_id );
        if ( $run_id <= 0 ) {
            return false;
        }

        $data   = array();
        $format = array();

        $allowed_text_fields = array( 'trigger_source', 'status', 'topic_keyword', 'last_message', 'last_error' );
        foreach ( $allowed_text_fields as $field ) {
            if ( array_key_exists( $field, $args ) ) {
                $data[ $field ] = self::sanitize_text_or_null( $args[ $field ] );
                $format[]       = '%s';
            }
        }

        $allowed_int_fields = array( 'daily_count', 'success_count', 'failed_count', 'published_count', 'manual_requested_by' );
        foreach ( $allowed_int_fields as $field ) {
            if ( array_key_exists( $field, $args ) ) {
                $data[ $field ] = max( 0, absint( $args[ $field ] ) );
                $format[]       = '%d';
            }
        }

        if ( array_key_exists( 'run_date', $args ) ) {
            $data['run_date'] = self::sanitize_date( $args['run_date'] );
            $format[]         = '%s';
        }

        foreach ( array( 'created_at', 'started_at', 'finished_at' ) as $field ) {
            if ( array_key_exists( $field, $args ) ) {
                $data[ $field ] = self::sanitize_datetime( $args[ $field ] );
                $format[]       = '%s';
            }
        }

        if ( array_key_exists( 'settings_snapshot', $args ) ) {
            $snapshot = $args['settings_snapshot'];
            if ( is_array( $snapshot ) ) {
                $snapshot = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            }
            $data['settings_snapshot'] = self::sanitize_text_or_null( $snapshot );
            $format[]                  = '%s';
        }

        if ( empty( $data ) ) {
            return true;
        }

        $data['updated_at'] = self::now_mysql();
        $format[]           = '%s';

        $result = $wpdb->update(
            self::get_runs_table(),
            $data,
            array( 'id' => $run_id ),
            $format,
            array( '%d' )
        );

        return false !== $result;
    }

    public static function create_item( $run_id, $item_index, $title, $prompt = '' ) {
        global $wpdb;

        $data = array(
            'run_id'         => absint( $run_id ),
            'item_index'     => max( 0, absint( $item_index ) ),
            'status'         => 'pending',
            'stage'          => 'pending',
            'title'          => sanitize_text_field( $title ),
            'prompt'         => sanitize_textarea_field( $prompt ),
            'post_id'        => 0,
            'post_status'    => '',
            'scheduled_at'   => null,
            'error_message'  => null,
            'stage_message'  => null,
            'created_at'     => self::now_mysql(),
            'updated_at'     => self::now_mysql(),
        );

        $inserted = $wpdb->insert(
            self::get_items_table(),
            $data,
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            $existing = self::get_item_by_run_index( $run_id, $item_index );
            if ( ! empty( $existing ) ) {
                return $existing;
            }
            return new WP_Error( 'create_item_failed', '永动发布子任务写入失败：' . $wpdb->last_error );
        }

        return self::get_item( $wpdb->insert_id );
    }

    public static function get_item( $item_id ) {
        global $wpdb;

        $item = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::get_items_table() . ' WHERE id = %d LIMIT 1', absint( $item_id ) ),
            ARRAY_A
        );

        return self::normalize_item_row( $item );
    }

    public static function get_item_by_run_index( $run_id, $item_index ) {
        global $wpdb;

        $item = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::get_items_table() . ' WHERE run_id = %d AND item_index = %d LIMIT 1',
                absint( $run_id ),
                absint( $item_index )
            ),
            ARRAY_A
        );

        return self::normalize_item_row( $item );
    }

    public static function update_item( $item_id, $args ) {
        global $wpdb;

        $item_id = absint( $item_id );
        if ( $item_id <= 0 ) {
            return false;
        }

        $data   = array();
        $format = array();

        foreach ( array( 'status', 'stage', 'title', 'prompt', 'post_status', 'error_message', 'stage_message' ) as $field ) {
            if ( array_key_exists( $field, $args ) ) {
                $data[ $field ] = self::sanitize_text_or_null( $args[ $field ] );
                $format[]       = '%s';
            }
        }

        if ( array_key_exists( 'post_id', $args ) ) {
            $data['post_id'] = max( 0, absint( $args['post_id'] ) );
            $format[]        = '%d';
        }

        if ( array_key_exists( 'scheduled_at', $args ) ) {
            $data['scheduled_at'] = self::sanitize_datetime( $args['scheduled_at'] );
            $format[]             = '%s';
        }

        if ( empty( $data ) ) {
            return true;
        }

        $data['updated_at'] = self::now_mysql();
        $format[]           = '%s';

        $result = $wpdb->update(
            self::get_items_table(),
            $data,
            array( 'id' => $item_id ),
            $format,
            array( '%d' )
        );

        return false !== $result;
    }

    public static function get_recent_runs( $limit = 10 ) {
        global $wpdb;

        $limit = max( 1, min( 50, absint( $limit ) ) );
        $rows  = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM ' . self::get_runs_table() . ' ORDER BY id DESC LIMIT %d', $limit ),
            ARRAY_A
        );

        return array_map( array( __CLASS__, 'normalize_run_row' ), is_array( $rows ) ? $rows : array() );
    }

    public static function get_items_by_run( $run_id, $limit = 20 ) {
        global $wpdb;

        $limit = max( 1, min( 100, absint( $limit ) ) );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::get_items_table() . ' WHERE run_id = %d ORDER BY item_index ASC LIMIT %d',
                absint( $run_id ),
                $limit
            ),
            ARRAY_A
        );

        return array_map( array( __CLASS__, 'normalize_item_row' ), is_array( $rows ) ? $rows : array() );
    }

    public static function get_summary_stats( $days = 7 ) {
        global $wpdb;

        $days     = max( 1, min( 30, absint( $days ) ) );
        $runs     = self::get_recent_runs( 20 );
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $sql      = 'SELECT COUNT(*) as total_runs,
                            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_runs,
                            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_runs,
                            SUM(success_count) as success_items,
                            SUM(failed_count) as failed_items,
                            SUM(published_count) as published_items
                     FROM ' . self::get_runs_table() . ' WHERE created_at >= %s';
        $summary  = $wpdb->get_row( $wpdb->prepare( $sql, get_date_from_gmt( $cutoff, 'Y-m-d H:i:s' ) ), ARRAY_A );
        $latest   = ! empty( $runs ) ? $runs[0] : array();

        return array(
            'total_runs'      => absint( $summary['total_runs'] ?? 0 ),
            'completed_runs'  => absint( $summary['completed_runs'] ?? 0 ),
            'failed_runs'     => absint( $summary['failed_runs'] ?? 0 ),
            'success_items'   => absint( $summary['success_items'] ?? 0 ),
            'failed_items'    => absint( $summary['failed_items'] ?? 0 ),
            'published_items' => absint( $summary['published_items'] ?? 0 ),
            'last_run'        => $latest['finished_at_label'] ?? ( $latest['created_at_label'] ?? '暂无' ),
            'last_status'     => $latest['status'] ?? '',
        );
    }

    public static function normalize_run_row( $row ) {
        if ( empty( $row ) || ! is_array( $row ) ) {
            return array();
        }

        $snapshot = array();
        if ( ! empty( $row['settings_snapshot'] ) ) {
            $decoded = json_decode( (string) $row['settings_snapshot'], true );
            if ( is_array( $decoded ) ) {
                $snapshot = $decoded;
            }
        }

        $row['id']                 = absint( $row['id'] ?? 0 );
        $row['daily_count']        = absint( $row['daily_count'] ?? 0 );
        $row['success_count']      = absint( $row['success_count'] ?? 0 );
        $row['failed_count']       = absint( $row['failed_count'] ?? 0 );
        $row['published_count']    = absint( $row['published_count'] ?? 0 );
        $row['manual_requested_by']= absint( $row['manual_requested_by'] ?? 0 );
        $row['settings_snapshot']  = $snapshot;
        $row['created_at_label']   = self::format_datetime_label( $row['created_at'] ?? '' );
        $row['started_at_label']   = self::format_datetime_label( $row['started_at'] ?? '' );
        $row['finished_at_label']  = self::format_datetime_label( $row['finished_at'] ?? '' );
        $row['status_label']       = self::get_run_status_label( $row['status'] ?? 'pending' );
        $row['trigger_label']      = self::get_trigger_label( $row['trigger_source'] ?? 'cron' );
        $row['items']              = self::get_items_by_run( $row['id'], 30 );

        return $row;
    }

    public static function normalize_item_row( $row ) {
        if ( empty( $row ) || ! is_array( $row ) ) {
            return array();
        }

        $row['id']               = absint( $row['id'] ?? 0 );
        $row['run_id']           = absint( $row['run_id'] ?? 0 );
        $row['item_index']       = absint( $row['item_index'] ?? 0 );
        $row['post_id']          = absint( $row['post_id'] ?? 0 );
        $row['created_at_label'] = self::format_datetime_label( $row['created_at'] ?? '' );
        $row['updated_at_label'] = self::format_datetime_label( $row['updated_at'] ?? '' );
        $row['scheduled_label']  = self::format_datetime_label( $row['scheduled_at'] ?? '' );
        $row['status_label']     = self::get_item_status_label( $row['status'] ?? 'pending' );
        // v2.9.68: 把 stage_message 也暴露给前端，用于定位卡死阶段（"正在生成文章"/"正在处理配图"/"执行超时"等）
        $row['stage_message']    = (string) ( $row['stage_message'] ?? '' );
        $row['error_message']    = (string) ( $row['error_message'] ?? '' );
        $row['edit_url']         = ! empty( $row['post_id'] ) ? admin_url( 'post.php?post=' . absint( $row['post_id'] ) . '&action=edit' ) : '';
        $row['view_url']         = ! empty( $row['post_id'] ) ? get_permalink( absint( $row['post_id'] ) ) : '';

        return $row;
    }

    public static function format_datetime_label( $datetime ) {
        $datetime = trim( (string) $datetime );
        if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
            return '暂无';
        }

        // 数据库存储的是 current_time('mysql') 写入的本地时间（北京时间）。
        // strtotime() 会按服务器本地时区解析该字符串得到时间戳。
        // wp_date() 传入 wp_timezone() 会再加一次 +8 时区偏移，导致时间多出8小时（跨天时显示成第二天）。
        // 正确做法：将本地时间字符串先转为 DateTimeImmutable（明确指定 WP 时区），再格式化输出，避免双重时区转换。
        try {
            $tz = wp_timezone();
            $dt = new DateTimeImmutable( $datetime, $tz );
            return $dt->format( 'm-d H:i' );
        } catch ( Exception $e ) {
            return $datetime;
        }
    }

    public static function get_run_status_label( $status ) {
        $map = array(
            'pending'   => '待执行',
            'running'   => '执行中',
            'completed' => '已完成',
            'failed'    => '失败',
            'aborted'   => '已中止',
        );

        return $map[ $status ] ?? $status;
    }

    public static function get_item_status_label( $status ) {
        $map = array(
            'pending' => '等待中',
            'running' => '执行中',
            'success' => '成功',
            'failed'  => '失败',
            'skipped' => '已跳过',
        );

        return $map[ $status ] ?? $status;
    }

    public static function get_trigger_label( $trigger ) {
        $map = array(
            'cron'   => '定时触发',
            'manual' => '手动执行',
        );

        return $map[ $trigger ] ?? $trigger;
    }

    private static function sanitize_text_or_null( $value ) {
        if ( null === $value ) {
            return null;
        }

        $value = is_scalar( $value ) ? (string) $value : '';
        $value = trim( wp_strip_all_tags( $value ) );

        return '' === $value ? null : $value;
    }

    private static function sanitize_datetime( $value ) {
        if ( empty( $value ) ) {
            return null;
        }

        $value = str_replace( 'T', ' ', (string) $value );

        // 传入值已经是 current_time('mysql') 的本地时间（如 '2026-04-29 01:31:00'）。
        // strtotime() 按 PHP 服务器时区解析，再用 wp_date() 加 wp_timezone() 会双重转换导致 +8 小时偏差。
        // 正确做法：直接用 DateTimeImmutable 明确指定 WP 时区，然后格式化为 MySQL 格式。
        try {
            $tz = wp_timezone();
            $dt = new DateTimeImmutable( $value, $tz );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return null;
        }
    }

    private static function sanitize_date( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return null;
        }

        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
    }

    /**
     * 检查指定日期是否已有任何永动发布任务（daily 或 manual）处于运行/完成状态
     * 用于 Cron tick 的数据库级去重，防止 transient 被清除后重复触发
     *
     * @param string $date_key Y-m-d 格式的本地日期
     * @return bool
     */
    public static function has_run_today( $date_key ) {
        global $wpdb;

        $date_key = sanitize_text_field( $date_key );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_key ) ) {
            return false;
        }

        // 检查当天 00:00:00 ~ 23:59:59 之间创建的任何任务（running / completed / pending 均算）
        // created_at 存储的是 current_time('mysql') 本地时间
        $day_start = $date_key . ' 00:00:00';
        $day_end   = $date_key . ' 23:59:59';

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::get_runs_table() .
                " WHERE created_at >= %s AND created_at <= %s AND status IN ('pending','running','completed')",
                $day_start,
                $day_end
            )
        );

        return $count > 0;
    }

    /**
     *
     * @param int $days 保留天数，默认30天
     * @return array 清理结果
     */
    public static function cleanup_old_logs( $days = 30 ) {
        global $wpdb;

        $days = max( 7, min( 365, absint( $days ) ) );
        // created_at 存储的是 current_time('mysql') 本地时间，所以截止时间也要用本地时间
        $cutoff_ts  = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
        $cutoff_date = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

        $runs_table = self::get_runs_table();
        $items_table = self::get_items_table();

        // 检查表是否存在
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $runs_table ) ) !== $runs_table ) {
            return array(
                'runs_deleted'  => 0,
                'items_deleted' => 0,
                'days_kept'     => $days,
                'cutoff_date'   => $cutoff_date,
                'status'        => 'table_not_exists',
                'message'       => '日志表不存在或未初始化',
            );
        }

        // 获取超过期限的记录数（用于判断是否需要清理）
        $total_runs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs_table}" );
        $old_runs = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$runs_table} WHERE created_at < %s",
                $cutoff_date
            )
        );
        $old_items = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_table} WHERE run_id IN (SELECT id FROM {$runs_table} WHERE created_at < %s)",
                $cutoff_date
            )
        );

        // 执行删除
        $runs_deleted = 0;
        $items_deleted = 0;

        if ( $old_runs > 0 ) {
            // 先删除关联的 items
            $items_deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$items_table} WHERE run_id IN (SELECT id FROM {$runs_table} WHERE created_at < %s)",
                    $cutoff_date
                )
            );
            if ( $items_deleted === false ) {
                $items_deleted = 0;
            }

            // 再删除 runs
            $runs_deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$runs_table} WHERE created_at < %s",
                    $cutoff_date
                )
            );
            if ( $runs_deleted === false ) {
                $runs_deleted = 0;
            }
        }

        return array(
            'runs_deleted'  => (int) $runs_deleted,
            'items_deleted' => (int) $items_deleted,
            'days_kept'     => $days,
            'cutoff_date'   => $cutoff_date,
            'total_runs'    => $total_runs,
            'old_runs'      => $old_runs,
            'old_items'     => $old_items,
            'status'        => 'success',
        );
    }

    /**
     * 获取日志表记录数量
     *
     * @return array array('runs' => 0, 'items' => 0)
     */
    public static function get_log_counts() {
        global $wpdb;

        $runs_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_runs_table() );
        $items_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_items_table() );

        return array(
            'runs'  => $runs_count,
            'items' => $items_count,
        );
    }
}
