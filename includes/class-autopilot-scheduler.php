<?php
/**
 * 永动发布调度器
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_0P8Z {

    const CRON_HOOK           = 'wpaip_autopilot_tick';
    const CRON_INTERVAL       = 'wpaip_every_five_minutes';
    const CLEANUP_HOOK        = 'wpaip_autopilot_cleanup';
    const CLEANUP_INTERVAL    = 'wpaip_daily_cleanup';
    const DAILY_RUN_PREFIX     = 'wpaip_autopilot_run_date_';

    // 时间常量（秒）
    const TIMEOUT_STATS_CACHE  = 300;      // 统计数据缓存时间：5分钟
    const TIMEOUT_DEDUPE       = 172800;   // 去重 transient 超时：2天
    const HTTP_TIMEOUT         = 15;       // HTTP 请求超时：15秒

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
        add_action( self::CRON_HOOK, array( $this, 'handle_tick' ) );
        add_action( self::CLEANUP_HOOK, array( $this, 'handle_cleanup' ) );
    }

    public function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
            $schedules[ self::CRON_INTERVAL ] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => '一麦GSO永动发布每 5 分钟巡检',
            );
        }

        // 定时发布守护进程间隔（每30分钟，由网站访问触发WP-Cron执行）
        if ( ! isset( $schedules['every_30_minutes'] ) ) {
            $schedules['every_30_minutes'] = array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => '一麦GSO定时发布守护每30分钟',
            );
        }

        // 每日清理间隔
        if ( ! isset( $schedules[ self::CLEANUP_INTERVAL ] ) ) {
            $schedules[ self::CLEANUP_INTERVAL ] = array(
                'interval' => DAY_IN_SECONDS,
                'display'  => '一麦GSO每日清理',
            );
        }

        return $schedules;
    }

    public static function sync_schedule() {
        $settings = WPAIP_HXVV::get_settings();
        $enabled  = ! empty( $settings['enabled'] ) && self::can_run_feature();
        $next     = wp_next_scheduled( self::CRON_HOOK );

        if ( $enabled ) {
            if ( ! $next ) {
                wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
            }
            return;
        }

        if ( $next ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }
    }

    /**
     * 同步清理计划（每日凌晨执行一次）
     */
    public static function sync_cleanup_schedule() {
        $next = wp_next_scheduled( self::CLEANUP_HOOK );

        if ( ! $next ) {
            // 安排在凌晨 3 点（北京时间）执行（避开高峰期）
            // wp_schedule_event() 接收 UTC 时间戳，需要将本地时间 03:00 转为 UTC
            $tz        = wp_timezone();
            $tomorrow  = new DateTimeImmutable( 'tomorrow 03:00', $tz );
            $utc_ts    = $tomorrow->getTimestamp(); // DateTimeImmutable 在指定时区后 getTimestamp() 返回 UTC 时间戳
            wp_schedule_event( $utc_ts, self::CLEANUP_INTERVAL, self::CLEANUP_HOOK );
        }
    }

    /**
     * 清除清理计划
     */
    public static function clear_cleanup_schedule() {
        wp_clear_scheduled_hook( self::CLEANUP_HOOK );
    }

    /**
     * 清除永动发布调度（插件禁用时调用）
     */
    public static function clear_schedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * 执行日志清理
     */
    public function handle_cleanup() {
        // 获取设置中的保留天数，默认30天
        $settings = WPAIP_HXVV::get_settings();
        $days = isset( $settings['log_retention_days'] ) ? absint( $settings['log_retention_days'] ) : 30;

        // 最小保留7天，最大365天
        $days = max( 7, min( 365, $days ) );

        $result = WPAIP_96EY::cleanup_old_logs( $days );

        // 记录清理日志（使用 WordPress 调试日志）
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
            error_log( sprintf(
                '[BaiqueAI] 日志清理完成：删除了 %d 条运行记录，%d 条子任务记录，保留最近 %d 天数据',
                $result['runs_deleted'],
                $result['items_deleted'],
                $result['days_kept']
            ) );
        }
    }

    public function handle_tick() {
        global $wpdb;

        // 快速检查：永动发布是否启用（带 transient 缓存，5 分钟有效）
        $autopilot_enabled = get_transient( 'wpaip_autopilot_enabled_check' );
        if ( false === $autopilot_enabled ) {
            $settings = WPAIP_HXVV::get_settings();
            $autopilot_enabled = ! empty( $settings['enabled'] ) ? '1' : '0';
            set_transient( 'wpaip_autopilot_enabled_check', $autopilot_enabled, 5 * MINUTE_IN_SECONDS );
        }
        if ( '0' === $autopilot_enabled ) {
            return;
        }

        $settings = WPAIP_HXVV::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( ! self::can_run_feature() ) {
            return;
        }

        if ( ! WPAIP_HXVV::has_topic_keywords( $settings ) ) {
            return;
        }

        if ( ! WPAIP_HXVV::is_due_now( $settings ) ) {
            return;
        }

        $date_key  = WPAIP_HXVV::get_local_date_key();
        $dedupe_id = self::DAILY_RUN_PREFIX . $date_key;

        // 使用 MySQL GET_LOCK 防止并发的 WP-Cron 进程同时通过去重检查
        $lock_name = 'wpaip_autopilot_dispatch_' . $date_key;
        $got_lock  = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 10)", $lock_name ) );
        if ( ! $got_lock ) {
            return; // 获取锁失败，说明其他进程正在处理
        }

        // === 持有锁后重新检查所有去重条件 ===
        if ( get_transient( $dedupe_id ) ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return;
        }

        $daily_run_key = WPAIP_HXVV::build_daily_run_key( $date_key );
        $existing_daily = WPAIP_96EY::get_run_by_key( $daily_run_key );
        if ( ! empty( $existing_daily ) ) {
            set_transient( $dedupe_id, 1, 2 * DAY_IN_SECONDS );
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return;
        }

        if ( WPAIP_96EY::has_run_today( $date_key ) ) {
            set_transient( $dedupe_id, 1, 2 * DAY_IN_SECONDS );
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return;
        }

        $this->dispatch_due_run( 'cron', false, 0, true );

        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
    }

    public function dispatch_due_run( $trigger_source = 'cron', $force = false, $requested_by = 0, $async = false ) {
        $trigger_source = 'manual' === $trigger_source ? 'manual' : 'cron';
        $settings       = WPAIP_HXVV::get_settings();

        if ( ! $force && empty( $settings['enabled'] ) ) {
            return new WP_Error( 'autopilot_disabled', '永动发布当前未启用。' );
        }

        if ( ! self::can_run_feature() ) {
            return new WP_Error( 'wpaip_feature_locked', '当前功能未启用永动发布。' );
        }

        if ( ! WPAIP_HXVV::has_topic_keywords( $settings ) ) {
            return new WP_Error( 'missing_topics', '请先在永动发布设置里至少填写 1 个主题词。' );
        }

        if ( ! $force && ! WPAIP_HXVV::is_due_now( $settings ) ) {
            return new WP_Error( 'not_due', '当前还没到永动发布时间。' );
        }

        $date_key  = WPAIP_HXVV::get_local_date_key();
        $run_key   = $force ? WPAIP_HXVV::build_manual_run_key() : WPAIP_HXVV::build_daily_run_key( $date_key );
        $dedupe_id = self::DAILY_RUN_PREFIX . $date_key;

        if ( ! $force && get_transient( $dedupe_id ) ) {
            return new WP_Error( 'already_dispatched', '今日永动发布已派发。' );
        }

        $existing = WPAIP_96EY::get_run_by_key( $run_key );
        if ( ! empty( $existing ) ) {
            if ( ! $force ) {
                set_transient( $dedupe_id, 1, 2 * DAY_IN_SECONDS );
            }
            return $existing;
        }

        $snapshot = WPAIP_HXVV::build_run_snapshot( $settings, $run_key, $trigger_source );
        $run      = WPAIP_96EY::create_run( array(
            'run_key'             => $run_key,
            'trigger_source'      => $trigger_source,
            'status'              => 'pending',
            'run_date'            => $force ? null : $date_key,
            'topic_keyword'       => $snapshot['resolved_topic_keyword'] ?? '',
            'daily_count'         => absint( $snapshot['daily_count'] ?? 0 ),
            'manual_requested_by' => absint( $requested_by ),
            'settings_snapshot'   => $snapshot,
            'last_message'        => '任务已派发，等待执行。',
        ) );

        if ( is_wp_error( $run ) ) {
            return $run;
        }

        // 无论是 cron 触发还是手动强制执行，都标记今日已派发
        // 手动执行后同样设置此标记，防止 Cron 在同天再次创建 daily 任务（二次触发 Bug）
        set_transient( $dedupe_id, 1, 2 * DAY_IN_SECONDS );

        $run_id = absint( $run['id'] ?? 0 );
        if ( $async ) {
            $queued = WPAIP_KSQ9::get_instance()->queue_run( $run_id );
            if ( is_wp_error( $queued ) ) {
                return $queued;
            }

            return WPAIP_96EY::get_run( $run_id );
        }

        $result = WPAIP_KSQ9::get_instance()->run( $run_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result;
    }

    public static function can_run_feature() {
        if ( true ) {
            return true;
        }
        return false;
    }
}
