<?php
/**
 * 永动发布执行器
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Autopilot_Runner {

    const GENERATION_LOCK_OPTION = 'yimai_gso_generation_lock';
    const ASYNC_AJAX_ACTION      = 'yimai_gso_autopilot_process_run';
    const ASYNC_CRON_HOOK        = 'yimai_gso_autopilot_process_run_event';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_autopilot_manual_run', array( $this, 'ajax_manual_run' ) );
        add_action( 'wp_ajax_yimai_gso_autopilot_cleanup_logs', array( $this, 'ajax_cleanup_logs' ) );
        // 仅注册已登录用户的AJAX端点（移除nopriv，减少攻击面）
        // 异步任务通过 WP-Cron + wp_schedule_single_event 触发，不依赖未登录回调
        add_action( 'wp_ajax_' . self::ASYNC_AJAX_ACTION, array( $this, 'ajax_process_run' ) );
        add_action( self::ASYNC_CRON_HOOK, array( $this, 'handle_process_run' ), 10, 1 );
    }

    /**
     * 手动清理日志
     */
    public function ajax_cleanup_logs() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ), 403 );
        }

        $settings = YIMAI_GSO_Autopilot_Plan::get_settings();
        $days = isset( $settings['log_retention_days'] ) ? absint( $settings['log_retention_days'] ) : 30;
        $days = max( 7, min( 365, $days ) );

        $result = YIMAI_GSO_Autopilot_Log::cleanup_old_logs( $days );

        // 根据不同状态返回不同消息
        if ( isset( $result['status'] ) && $result['status'] === 'table_not_exists' ) {
            wp_send_json_error( array(
                'message' => '日志表不存在，请先启用永动发布功能',
            ) );
            return;
        }

        // 根据清理结果生成友好消息
        $total_deleted = $result['runs_deleted'] + $result['items_deleted'];
        if ( $total_deleted === 0 ) {
            $message = sprintf(
                '没有需要清理的旧日志（保留最近 %d 天），当前共 %d 条运行记录',
                $result['days_kept'],
                $result['total_runs'] ?? 0
            );
        } else {
            $message = sprintf(
                '已清理 %d 条运行记录和 %d 条子任务记录（保留最近 %d 天）',
                $result['runs_deleted'],
                $result['items_deleted'],
                $result['days_kept']
            );
        }

        wp_send_json_success( array(
            'message'        => $message,
            'runs_deleted'   => $result['runs_deleted'],
            'items_deleted'  => $result['items_deleted'],
            'days_kept'      => $result['days_kept'],
            'total_runs'     => $result['total_runs'] ?? 0,
            'old_runs'       => $result['old_runs'] ?? 0,
            'old_items'      => $result['old_items'] ?? 0,
        ) );
    }

    public function ajax_manual_run() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ), 403 );
        }

        if ( ! YIMAI_GSO_Autopilot_Scheduler::can_run_feature() ) {
            wp_send_json_error( array( 'message' => '当前功能未启用永动发布。' ), 403 );
        }

        // 创建运行记录（同时会派发 cron 事件做异步备份）
        $result = YIMAI_GSO_Autopilot_Scheduler::get_instance()->dispatch_due_run( 'manual', true, get_current_user_id(), true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        // 直接同步执行第一批次（绕过 cron 依赖，确保手动执行立即生效）
        $run_id = absint( $result['id'] ?? 0 );
        if ( $run_id > 0 ) {
            ignore_user_abort( true );
            // 手动执行每轮只跑 1 篇（后续由 cron 续批），300 秒足够单篇完成
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }
            $run_result = $this->handle_process_run( $run_id );
            // 如果同步执行失败（Token 验证失败/AI 异常等），run 状态可能仍是 pending
            // 必须把错误信息返回前端，否则用户只看到"任务已派发"但实际从未执行
            if ( is_wp_error( $run_result ) ) {
                YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                    'status'       => 'failed',
                    'last_error'   => $run_result->get_error_message(),
                    'last_message' => '永动发布执行失败：' . $run_result->get_error_message(),
                    'finished_at'  => current_time( 'mysql' ),
                ) );
                wp_send_json_error( array(
                    'message' => $run_result->get_error_message(),
                    'run'     => YIMAI_GSO_Autopilot_Log::get_run( $run_id ),
                ), 500 );
            }
        }

        $run = YIMAI_GSO_Autopilot_Log::get_run( $run_id );
        $status = $run['status'] ?? 'pending';
        $processed = absint( $run['success_count'] ?? 0 ) + absint( $run['failed_count'] ?? 0 );
        $total     = absint( $run['daily_count'] ?? 0 );
        $message = ( 'pending' === $status || 'running' === $status )
            ? sprintf( '永动发布已开始执行（已完成 %d/%d 篇），剩余任务继续后台处理中。', $processed, $total )
            : sprintf( '永动发布执行完成（共 %d 篇）。', $total );

        wp_send_json_success( array(
            'message' => $message,
            'run'     => $run,
        ) );
    }

    public function queue_run( $run_id ) {
        $run_id = absint( $run_id );
        if ( $run_id <= 0 ) {
            return new WP_Error( 'invalid_run_id', '缺少可执行的永动发布任务。' );
        }

        $run = YIMAI_GSO_Autopilot_Log::get_run( $run_id );
        if ( empty( $run ) ) {
            return new WP_Error( 'run_not_found', '未找到要执行的永动发布任务。' );
        }

        YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
            'status'       => 'pending',
            'last_error'   => null,
            'last_message' => '任务已派发，等待后台执行。',
        ) );

        // 统一使用 WP-Cron 调度首批（立即到期）
        $hook = self::ASYNC_CRON_HOOK;
        if ( ! wp_next_scheduled( $hook, array( $run_id ) ) ) {
            wp_schedule_single_event( time(), $hook, array( $run_id ) );
        }

        // 主动触发 wp-cron.php 立即处理
        //  修复：精准模式（DISABLE_WP_CRON=true）改用 HTTP 触发 wp-cron.php，
        // 而非跳过所有触发（原逻辑只在非精准模式用 spawn_cron，精准模式无任何触发）
        $precise_mode = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        $last_trigger = get_transient( 'yimai_gso_last_cron_trigger' );
        if ( false === $last_trigger ) {
            if ( $precise_mode ) {
                // 精准模式：spawn_cron() 不可用，通过 HTTP GET 触发 wp-cron.php 处理到期事件
                $wp_cron_url = site_url( 'wp-cron.php' );
                $trigger_resp = wp_remote_get( $wp_cron_url, array(
                    'timeout'   => 3,
                    'blocking'  => false,
                ) );
                if ( is_wp_error( $trigger_resp ) && defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                    error_log( '[一麦GSO] queue_run wp-cron.php 触发失败: ' . $trigger_resp->get_error_message() );
                }
            } elseif ( function_exists( 'spawn_cron' ) ) {
                // 默认模式：使用 spawn_cron() 发送非阻塞 POST，WordPress 核心函数，兼容性最好
                spawn_cron();
            }
            // 防抖：1秒内不重复触发
            set_transient( 'yimai_gso_last_cron_trigger', time(), 1 );
        }

        YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
            'last_message' => '任务已派发，后台触发执行中。',
        ) );

        return true;
    }

    public function ajax_process_run() {
        $run_id = absint( $_POST['run_id'] ?? 0 );
        $token  = sanitize_text_field( $_POST['token'] ?? '' );

        if ( $run_id <= 0 || ! wp_verify_nonce( $token, 'yimai_gso_autopilot_process_' . $run_id ) ) {
            wp_send_json_error( array( 'message' => '后台任务校验失败。' ), 403 );
        }

        $result = $this->handle_process_run( $run_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( array( 'run_id' => $run_id ) );
    }

    public function handle_process_run( $run_id ) {
        $run_id = absint( $run_id );
        if ( $run_id <= 0 ) {
            return new WP_Error( 'invalid_run_id', '缺少可执行的永动发布任务。' );
        }

        $run = YIMAI_GSO_Autopilot_Log::get_run( $run_id );
        if ( empty( $run ) ) {
            return new WP_Error( 'run_not_found', '未找到要执行的永动发布任务。' );
        }

        if ( ! in_array( $run['status'] ?? 'pending', array( 'pending', 'running' ), true ) ) {
            return $run;
        }

        // 超时恢复：如果状态为 running 但已超过 8 分钟未更新，视为异常退出，重置为 pending
        // 阈值从 30 分钟缩短为 8 分钟，与 run_single_item 的 $item_max_runtime 对齐
        // 锁 TTL 改为 10 分钟 + run_single_item 内部刷新后，30 分钟窗口明显偏长
        if ( 'running' === ( $run['status'] ?? '' ) ) {
            // 时区修正：started_at 是 current_time('mysql') 写入的「站点本地时间」串。
            // 直接 strtotime() 会按 PHP 的 UTC 解析（WordPress 强制 UTC），在 UTC+8 站点
            // 得到「真实时间 - 8 小时」的假过去值，使 time()-started_at 虚增 8 小时，
            // 8 分钟超时判定被恒真触发（正常续批会被误判为超时）。
            // 正确做法：用 DateTimeImmutable 显式按站点时区解析，再取时间戳。
            $stuck_timeout = 8 * MINUTE_IN_SECONDS;
            $started_ts    = 0;
            if ( ! empty( $run['started_at'] ) ) {
                try {
                    $started_ts = ( new DateTimeImmutable( (string) $run['started_at'], wp_timezone() ) )->getTimestamp();
                } catch ( Exception $e ) {
                    $started_ts = 0;
                }
            }
            if ( $started_ts > 0 && ( time() - $started_ts ) > $stuck_timeout ) {
                YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                    'status'       => 'pending',
                    'last_message' => '检测到上次执行超时（>8分钟），自动恢复重新派发。',
                ) );
                // 继续执行，不返回
            } else {
                // 未超时，可能是正常的续批执行中，跳过
                return $run;
            }
        }

        return $this->run( $run_id );
    }

    public function run( $run_id ) {
        $run = YIMAI_GSO_Autopilot_Log::get_run( $run_id );
        if ( empty( $run ) ) {
            return new WP_Error( 'run_not_found', '未找到要执行的永动发布任务。' );
        }

        // 先把恢复卡死 item 的逻辑提前到获取锁之前！
        // 旧逻辑：recover_stuck_items 在 acquire_generation_lock 之后执行，
        // 若上次执行进程被 nginx 切断导致锁未释放，acquire 直接失败 return，
        // recover_stuck_items 永远执行不到 → 卡死 item 永远恢复不了，run 永久卡死。
        // 新逻辑：无论锁是否被占，先扫描本 run 的卡死 item 并强制恢复+释放锁。
        $failed_count_from_recovery = $this->recover_stuck_items( $run_id );

        $lock = self::acquire_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );
        if ( is_wp_error( $lock ) ) {
            // 锁冲突时，若本 run 存在卡死 item（上次进程被切断），
            // recover_stuck_items 已强制释放 autopilot 锁，这里重试一次
            if ( $failed_count_from_recovery > 0 ) {
                $lock = self::acquire_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );
            }
            if ( is_wp_error( $lock ) ) {
                // 锁冲突不修改 run 状态（避免 rescue 逻辑并发触发时误标 aborted）
                // 锁冲突是正常的并发保护，不应视为失败。状态保持 pending/running，等下次调度重试。
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                    error_log( '[一麦GSO Autopilot] 获取生成锁冲突 (run_id=' . $run_id . '): ' . $lock->get_error_message() );
                }
                return $lock;
            }
        }

        ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 ); // 10 分钟上限，防止无限挂起
        }

        self::bootstrap_runtime_user( absint( $run['manual_requested_by'] ?? 0 ) );

        YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
            'status'      => 'running',
            'started_at'  => current_time( 'mysql' ),
            'last_error'  => null,
            'last_message'=> '永动发布开始执行。',
        ) );

        $snapshot       = is_array( $run['settings_snapshot'] ?? null ) ? $run['settings_snapshot'] : array();
        $topic          = sanitize_text_field( $run['topic_keyword'] ?? ( $snapshot['resolved_topic_keyword'] ?? '' ) );
        $count          = max( 1, absint( $snapshot['daily_count'] ?? $run['daily_count'] ?? 1 ) );
        $trigger_source = sanitize_text_field( $run['trigger_source'] ?? $snapshot['trigger_source'] ?? 'cron' );

        // 分批执行：无论手动还是 cron，每轮都只处理 1 篇！
        // 彻底统一手动/cron 行为。 的手动 batch_size=$count（一次跑完）
        // 经测试在多数服务器上不稳定——同一个 PHP 进程连续跑多篇 AI+发布+钩子，
        // 累计资源开销（锁刷新/Token验证/WordPress钩子/对象缓存）容易触发隐性瓶颈，
        // 导致 #2 在 AJAX 请求中卡死且无法恢复。
        // 改为每轮 1 篇 + schedule_next_batch 续批，与 cron 完全一致，已验证 cron 路径稳定。
        $batch_size = 1;

        try {
            $generator = new YIMAI_GSO_AI_Generator();

            // 首次执行时生成全部标题，存入 settings_snapshot 供后续批次复用
            $saved_titles = $snapshot['generated_titles'] ?? null;
            if ( empty( $saved_titles ) || ! is_array( $saved_titles ) ) {
                $titles = $this->prepare_titles( $generator, $topic, $count );
                if ( is_wp_error( $titles ) ) {
                    throw new Exception( $titles->get_error_message() );
                }
                if ( empty( $titles ) ) {
                    throw new Exception( 'AI 没有返回可用标题。' );
                }
                $snapshot['generated_titles'] = $titles;
                YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                    'settings_snapshot' => $snapshot,
                ) );
            } else {
                $titles = $saved_titles;
            }

            // recover_stuck_items 已提前到 acquire 锁之前执行（见 run() 开头），
            // 这里不再重复调用，避免重复释放锁

            $success_count   = (int) ( $run['success_count'] ?? 0 );
            $failed_count    = (int) ( $run['failed_count'] ?? 0 ) + $failed_count_from_recovery;
            $published_count = (int) ( $run['published_count'] ?? 0 );
            $last_message    = '永动发布执行中。';

            // 本批次起点
            $start  = $success_count + $failed_count;
            $end    = min( $start + $batch_size, count( $titles ) );

            for ( $i = $start; $i < $end; $i++ ) {
                self::refresh_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );

                $title = $titles[ $i ] ?? '';
                if ( '' === $title ) {
                    break;
                }

                $item = YIMAI_GSO_Autopilot_Log::create_item(
                    $run_id,
                    $i + 1,
                    $title,
                    YIMAI_GSO_Autopilot_Plan::build_article_prompt( $title, $snapshot )
                );

                if ( is_wp_error( $item ) ) {
                    $failed_count++;
                    $last_message = '部分子任务写入失败，已继续执行后续文章。';
                    continue;
                }

                $result = $this->run_single_item( $item, $snapshot, $topic, $i );
                if ( is_wp_error( $result ) ) {
                    $failed_count++;
                    $last_message = '本轮有部分文章执行失败，请查看日志。';
                    unset( $item, $result );
                    continue;
                }

                $success_count++;
                if ( 'publish' === ( $result['status'] ?? '' ) || 'future' === ( $result['status'] ?? '' ) ) {
                    $published_count++;
                }
                unset( $item, $result );

                // 每处理完一篇立即保存进度，防止进程中断导致统计丢失
                YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                    'success_count'   => $success_count,
                    'failed_count'    => $failed_count,
                    'published_count' => $published_count,
                ) );
            }

            // 更新进度
            $total_processed = $success_count + $failed_count;

            if ( $total_processed < count( $titles ) ) {
            // 还有剩余文章，续派下一个批次，并将状态重置为 pending
            YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                'success_count'   => $success_count,
                'failed_count'    => $failed_count,
                'published_count' => $published_count,
                'last_message'    => sprintf( '已处理 %d/%d 篇，继续处理中…', $total_processed, count( $titles ) ),
                'status'          => 'pending',
            ) );

                $this->schedule_next_batch( $run_id );

                self::release_generation_lock( 'autopilot' );
                return YIMAI_GSO_Autopilot_Log::get_run( $run_id );
            }

            // 全部完成
            $final_status = $success_count > 0 ? 'completed' : 'failed';
            if ( $success_count > 0 && $failed_count > 0 ) {
                $last_message = '本轮永动发布已完成，但有部分文章失败。';
            } elseif ( 0 === $failed_count ) {
                $last_message = '本轮永动发布已全部完成。';
            }

            // 清理 saved titles
            unset( $snapshot['generated_titles'] );
            YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                'status'          => $final_status,
                'success_count'   => $success_count,
                'failed_count'    => $failed_count,
                'published_count' => $published_count,
                'last_message'    => $last_message,
                'finished_at'     => current_time( 'mysql' ),
                'settings_snapshot' => $snapshot,
            ) );
        } catch ( \Throwable $e ) {
            // 必须捕获 \Throwable 而非仅 Exception：PHP 7+ 的 Error（TypeError、DivisionByZeroError、
            // 未定义函数调用等）不继承 Exception，只 catch Exception 会让它们逃逸出本方法，
            // 使 run 永久停在 'running' 且锁不释放（与 class-autopilot-runner.php:610 的正确写法对齐）。
            YIMAI_GSO_Autopilot_Log::update_run( $run_id, array(
                'status'       => 'failed',
                'last_error'   => $e->getMessage(),
                'last_message' => '永动发布执行失败。',
                'finished_at'  => current_time( 'mysql' ),
            ) );
        }

        self::release_generation_lock( 'autopilot' );

        return YIMAI_GSO_Autopilot_Log::get_run( $run_id );
    }

    /**
     * 续派永动发布下一批次
     *
     * 统一使用 WP-Cron 调度下一批次（立即到期）。
     * 精准模式下额外触发 wp-cron.php 以尽快执行待处理事件（带防抖机制）。
     *
     * @param int $run_id 当前运行记录 ID
     */
    private function schedule_next_batch( $run_id ) {
        $hook = self::ASYNC_CRON_HOOK;
        if ( ! wp_next_scheduled( $hook, array( $run_id ) ) ) {
            wp_schedule_single_event( time(), $hook, array( $run_id ) );
        }

        // 主动触发 wp-cron.php 立即处理续批事件
        //  修复：精准模式下使用 HTTP 触发 wp-cron.php（与 queue_run 保持一致）
        $precise_mode = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        $last_trigger = get_transient( 'yimai_gso_last_cron_trigger' );
        if ( false === $last_trigger ) {
            if ( $precise_mode ) {
                $wp_cron_url = site_url( 'wp-cron.php' );
                $trigger_resp = wp_remote_get( $wp_cron_url, array(
                    'timeout'   => 3,
                    'blocking'  => false,
                ) );
                if ( is_wp_error( $trigger_resp ) && defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                    error_log( '[一麦GSO] schedule_next_batch wp-cron.php 触发失败: ' . $trigger_resp->get_error_message() );
                }
            } elseif ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
            // 防抖：1秒内不重复触发
            set_transient( 'yimai_gso_last_cron_trigger', time(), 1 );
        }
    }

    private function run_single_item( $item, $snapshot, $topic, $article_index = 0 ) {
        $item_id   = absint( $item['id'] ?? 0 );
        $existing_status = $item['status'] ?? 'pending';
        if ( in_array( $existing_status, array( 'success', 'failed' ), true ) ) {
            // 已完成的 item 不再重复处理，防止中断恢复后重复发布
            return YIMAI_GSO_Autopilot_Log::get_item( $item_id );
        }

        $title     = sanitize_text_field( $item['title'] ?? '' );
        $prompt    = YIMAI_GSO_Autopilot_Plan::build_article_prompt( $title, $snapshot );
        $word_count= max( 300, absint( $snapshot['word_count'] ?? 1000 ) );
        $language  = sanitize_text_field( $snapshot['language'] ?? 'zh_CN' );

        // 记录单篇开始时间，用于硬性超时保护（默认 8 分钟上限，
        // 防止 AI 接口永久卡住时整篇永远卡在 running；锁和 item 必须能最终释放）
        $item_started_at = time();
        $item_max_runtime = 8 * MINUTE_IN_SECONDS;

        YIMAI_GSO_Autopilot_Log::update_item( $item_id, array(
            'status'        => 'running',
            'stage'         => 'article_generating',
            'prompt'        => $prompt,
            'stage_message' => '正在生成文章。',
            'error_message' => null,
        ) );

        // 进入子任务时主动刷新一次锁，避免被父级 run() 的锁 TTL 边界卡住
        self::refresh_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );

        try {
            $generator = new YIMAI_GSO_AI_Generator();
            // AI 调用前刷锁 + 检查单篇总执行时间
            self::refresh_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );
            if ( ( time() - $item_started_at ) > $item_max_runtime ) {
                throw new \RuntimeException( '单篇执行总时间超过 ' . ( $item_max_runtime / MINUTE_IN_SECONDS ) . ' 分钟（AI 接口可能永久卡住），已强制中止。' );
            }
            $article   = $generator->generate_article( $prompt, $word_count, $language, $title );
            if ( is_wp_error( $article ) ) {
                YIMAI_GSO_Autopilot_Log::update_item( $item_id, array(
                    'status'        => 'failed',
                    'stage'         => 'article_failed',
                    'error_message' => $article->get_error_message(),
                    'stage_message' => '文章生成失败。',
                ) );
                return $article;
            }

            $content = (string) ( $article['content'] ?? '' );

            YIMAI_GSO_Autopilot_Log::update_item( $item_id, array(
                'stage'         => 'assets_processing',
                'stage_message' => '正在处理配图与标签。',
            ) );

            // 配图阶段前刷锁 + 检查总时间
            self::refresh_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );
            if ( ( time() - $item_started_at ) > $item_max_runtime ) {
                throw new \RuntimeException( '单篇执行总时间超过 ' . ( $item_max_runtime / MINUTE_IN_SECONDS ) . ' 分钟，已强制中止。' );
            }

            $image_count = max( 0, absint( $snapshot['image_count'] ?? 0 ) );
            if ( $image_count > 0 ) {
                $image_keyword = $article['image_keywords'] ?? ( $article['keywords'] ?? $title );
                $content       = $this->attach_images_to_content( $content, $image_keyword, $image_count, array(
                    'title'    => $article['title'] ?? $title,
                    'keyword'  => $article['keywords'] ?? $topic,
                    'excerpt'  => $article['excerpt'] ?? '',
                    'language' => $language,
                    'source'   => 'autopilot',
                ) );
            }

            // 发布阶段前刷锁 + 检查总时间
            self::refresh_generation_lock( 'autopilot', 10 * MINUTE_IN_SECONDS );
            if ( ( time() - $item_started_at ) > $item_max_runtime ) {
                throw new \RuntimeException( '单篇执行总时间超过 ' . ( $item_max_runtime / MINUTE_IN_SECONDS ) . ' 分钟，已强制中止。' );
            }

            $publisher = new YIMAI_GSO_Publisher();
            $tags      = $this->build_tags( $snapshot, $article );
            $status    = sanitize_text_field( $snapshot['post_status'] ?? 'publish' );

            // 定时发布：计算 scheduled_at = 当前时间 + 延迟小时
            $scheduled_at = '';
            if ( $status === 'future' ) {
                $delay_hours = max( 1, min( 3, absint( $snapshot['future_delay_hours'] ?? 2 ) ) );
                $scheduled_ts = time() + ( $delay_hours * HOUR_IN_SECONDS );
                $scheduled_at = wp_date( 'Y-m-d\TH:i', $scheduled_ts, wp_timezone() );
            }

            // 分类自动映射：按选题 + 生成标题/关键词匹配标准分类；
            // 未命中或开关关闭时，回退到「发布参数」里选的兜底分类。
            $mapped_category_id = max( 1, absint( $snapshot['category_id'] ?? 1 ) );
            if ( ! empty( $snapshot['auto_category'] ) ) {
                $mapped_category_id = YIMAI_GSO_Autopilot_Plan::map_category_for_topic(
                    trim( ( $article['keywords'] ?? '' ) . ' ' . ( $article['title'] ?? $title ) . ' ' . $topic ),
                    $mapped_category_id
                );
            }

            $result    = $publisher->publish( array(
                'title'        => $article['title'] ?? $title,
                'content'      => $content,
                'excerpt'      => $article['excerpt'] ?? '',
                'category_id'  => $mapped_category_id,
                'tags'         => $tags,
                'status'       => $status,
                'scheduled_at' => $scheduled_at,
                'thumbnail_id' => 0,
                'keywords'     => sanitize_text_field( $article['keywords'] ?? $topic ),
                'prompt'       => $prompt,
                'word_count'   => $word_count,
                'image_count'  => $image_count,
                'ai_model'     => YIMAI_GSO_Settings::get( 'ai_model', 'gpt-4o' ),
                'language'     => $language,
                'source'       => 'autopilot',
                'article_index' => absint( $article_index ),
                // ★ 修复（审计 12 节建议 #2）：永动发布原先不传 auto_excerpt，
                //   自动发布的文章永不走「独立 AI 摘要二次生成」；现跟随全局开关
                //   「发布时自动生成 AI 摘要」（auto_excerpt_on_publish）。
                'auto_excerpt' => (bool) YIMAI_GSO_Settings::get( 'auto_excerpt_on_publish', 0 ),
            ) );

            if ( is_wp_error( $result ) ) {
                YIMAI_GSO_Autopilot_Log::update_item( $item_id, array(
                    'status'        => 'failed',
                    'stage'         => 'publish_failed',
                    'error_message' => $result->get_error_message(),
                    'stage_message' => '发布失败。',
                ) );
                return $result;
            }

            YIMAI_GSO_Autopilot_Log::update_item( $item_id, array(
                'status'        => 'success',
                'stage'         => 'published',
                'title'         => $result['title'] ?? $title,
                'post_id'       => absint( $result['post_id'] ?? 0 ),
                'post_status'   => sanitize_text_field( $result['status'] ?? $status ),
                'stage_message' => '文章已成功写入 WordPress。',
            ) );

            return $result;

        } catch ( \Throwable $e ) {
            // 兜底保护 — 无论是 PHP 致命错误还是异常，item 必须脱离 running 状态
            error_log( sprintf(
                '[一麦GSO Autopilot] Item %d (%s) crashed: %s in %s:%d',
                $item_id, $title, $e->getMessage(), $e->getFile(), $e->getLine()
            ) );
            YIMAI_GSO_Autopilot_Log::update_item( $item_id, array(
                'status'        => 'failed',
                'stage'         => 'exception',
                'error_message' => mb_substr( $e->getMessage(), 0, 500 ),
                'stage_message' => '执行过程中断（超时或异常）。',
            ) );
            return new WP_Error( 'item_crashed', $e->getMessage() );
        }
    }

    private function prepare_titles( $generator, $topic, $count ) {
        $topic = trim( (string) $topic );
        if ( '' === $topic ) {
            return new WP_Error( 'missing_topic', '缺少当天主题词。' );
        }

        $title_count = max( 5, $count );
        $titles      = $generator->generate_titles( $topic, $title_count, array() );
        if ( is_wp_error( $titles ) ) {
            return $titles;
        }

        $titles = array_values( array_filter( array_map( 'sanitize_text_field', (array) $titles ) ) );
        if ( count( $titles ) > $count ) {
            $titles = array_slice( $titles, 0, $count );
        }

        return $titles;
    }

    private function build_tags( $snapshot, $article ) {
        $manual_tags = sanitize_text_field( $snapshot['tags'] ?? '' );
        $use_ai_tags = ! empty( $snapshot['use_ai_tags'] );

        $all = array();
        $normalized_manual = str_replace( array( '，', '、', '|', '/' ), ',', $manual_tags );
        foreach ( array_map( 'trim', explode( ',', $normalized_manual ) ) as $tag ) {
            if ( '' !== $tag ) {
                $all[] = $tag;
            }
        }

        if ( $use_ai_tags ) {
            foreach ( array_map( 'trim', explode( ',', (string) ( $article['tags'] ?? '' ) ) ) as $tag ) {
                // 强化过滤 — 不仅精确匹配标题，还要排除含标题关键词的、过长/过短的标签
                $article_title = (string) ( $article['title'] ?? '' );
                $tag_len = mb_strlen( $tag );
                // 标签长度限制：2~15 字（1字太泛，15+大概率是句子/标题泄漏）
                if ( '' === $tag || $tag_len < 2 || $tag_len > 15 ) {
                    continue;
                }
                // 精确匹配标题
                if ( $tag === $article_title ) {
                    continue;
                }
                // 模糊匹配：标签是标题的子串（标题被拆成逗号分隔发出来），或标签包含标题80%以上
                if ( $article_title && mb_strlen( $article_title ) > 4 ) {
                    // 只对长标题做模糊检测
                    // 方法1：similar_text 相似度 > 70%
                    similar_text( $tag, $article_title, $sim_pct );
                    if ( $sim_pct > 70 ) {
                        continue;
                    }
                }
                if ( ! in_array( $tag, $all, true ) ) {
                    $all[] = $tag;
                }
            }
        }

        // Fallback：如果标签为空，用 keywords 或主题词生成标签，确保文章必有标签
        if ( empty( $all ) ) {
            $fallback_keywords = sanitize_text_field( $article['keywords'] ?? $article['image_keywords'] ?? '' );
            if ( ! empty( $fallback_keywords ) ) {
                foreach ( array_map( 'trim', explode( ',', $fallback_keywords ) ) as $tag ) {
                    if ( '' !== $tag && mb_strlen( $tag ) <= 80 ) {
                        $all[] = $tag;
                    }
                }
            }
        }

        return implode( ',', $all );
    }

    private function attach_images_to_content( $content, $keyword, $image_count, $context = array() ) {
        $handler = new YIMAI_GSO_Image_Handler();
        // $context（title/keyword/excerpt）透传给 MT Photos 的 AI 关联度审核
        $images  = $handler->fetch_images( $keyword, $image_count, $context );
        if ( is_wp_error( $images ) || empty( $images ) ) {
            return $content;
        }

        // ALT 生成已改为 transition_post_status 钩子（优先级10），不再在配图流程中处理

        $img_index = 0;
        $total     = count( $images );

        $content = preg_replace_callback(
            '/<\/h2>/i',
            function ( $match ) use ( $images, &$img_index, $total ) {
                if ( $img_index >= $total ) {
                    return $match[0];
                }

                $img     = $images[ $img_index++ ];
                $img_url = esc_url( $img['url'] ?? '' );
                $img_alt = esc_attr( $img['alt'] ?? '配图' );

                return $match[0] . "\n<p style=\"text-align:center;margin:20px 0;\"><img src=\"{$img_url}\" alt=\"{$img_alt}\" style=\"max-width:100%;border-radius:8px;display:inline-block;\" /></p>\n";
            },
            $content
        );

        if ( $img_index < $total ) {
            $content .= "\n<p style=\"text-align:center;color:#999;font-size:14px;margin-top:30px;\">—— 相关配图 ——</p>\n";
            for ( $j = $img_index; $j < $total; $j++ ) {
                $extra   = $images[ $j ];
                $img_url = esc_url( $extra['url'] ?? '' );
                $img_alt = esc_attr( $extra['alt'] ?? '配图' );
                $content .= "<p style=\"text-align:center;margin:20px 0;\"><img src=\"{$img_url}\" alt=\"{$img_alt}\" style=\"max-width:100%;border-radius:8px;display:inline-block;\" /></p>\n";
            }
        }

        return $content;
    }

    public static function bootstrap_runtime_user( $preferred_user_id = 0 ) {
        $preferred_user_id = absint( $preferred_user_id );
        if ( $preferred_user_id > 0 ) {
            wp_set_current_user( $preferred_user_id );
            return;
        }

        if ( get_current_user_id() > 0 && current_user_can( 'edit_posts' ) ) {
            return;
        }

        // 兼容 Redis 对象缓存：flush 后再查询，避免缓存返回空数组
        // 增加 filter 允许高级用户禁用全局刷新（改用精准 delete），并记录 WP_DEBUG 日志
        $should_flush = apply_filters( 'yimai_gso_bootstrap_user_cache_flush', false );
        if ( $should_flush && function_exists( 'wp_cache_flush' ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[一麦GSO Perf] bootstrap_runtime_user triggered wp_cache_flush() — consider using yimai_gso_bootstrap_user_cache_flush filter to disable' );
            }
            wp_cache_flush();
        }

        // Fallback 1: 通过 get_users 查询管理员
        $admins = get_users( array(
            'role'    => 'administrator',
            'number'  => 1,
            'fields'  => 'ID',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ) );

        // Fallback 2: 如果 get_users 失败，直接取数据库中第一个用户
        if ( empty( $admins[0] ) ) {
            global $wpdb;
            $first_user = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT %d", 1 ) );
            if ( $first_user ) {
                $admins[0] = absint( $first_user );
            }
        }

        if ( ! empty( $admins[0] ) ) {
            wp_set_current_user( absint( $admins[0] ) );
        }

        // 最终验证：如果仍然无法编辑文章，记录错误日志
        if ( ! current_user_can( 'edit_posts' ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                error_log( '[BaiqueAI] bootstrap_runtime_user 失败：无法获取有效的管理员用户上下文（当前用户ID=' . get_current_user_id() . '）' );
            }
        }
    }

    public static function acquire_generation_lock( $source, $ttl = 900 ) {
        $source = sanitize_key( $source );
        if ( '' === $source ) {
            $source = 'unknown';
        }

        global $wpdb;

        // 并发修正（B3）：锁状态存在 option 里，整个「读 → 检查冲突 → 写」是非原子的。
        // 两个并发 worker（例如重叠的 WP-Cron 进程）可同时通过互斥检查，各自生成并插入文章，
        // 产生重复内容——这是本插件唯一会造成文章重复的路径。
        // 修复：用 MySQL 命名锁把该临界区串行化（与 class-publisher.php / 调度器同一模式），
        // 命名锁由数据库保证互斥，且不依赖 option 的读改写原子性。
        $mutex     = 'yimai_gso_gen_lock_mutex';
        $got_mutex = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $mutex ) );
        if ( ! $got_mutex ) {
            // 拿不到互斥锁说明另一进程正在改锁状态，视为「当前有其他生成任务」以免重复生成
            return new WP_Error( 'yimai_gso_generation_locked', '当前有其他生成任务正在执行，请稍后再试。' );
        }

        try {
            return self::acquire_generation_lock_locked( $source, $ttl );
        } finally {
            // 任何路径都必须释放命名锁，否则后续所有生成都会被永久挡住
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
        }
    }

    /**
     * acquire_generation_lock() 的临界区实现——调用方必须已持有 $mutex 命名锁。
     *
     * @param string $source 已 sanitize_key 的锁来源
     * @param int    $ttl    锁有效期（秒）
     * @return true|WP_Error
     */
    private static function acquire_generation_lock_locked( $source, $ttl = 900 ) {
        $now     = time();
        $locks   = get_option( self::GENERATION_LOCK_OPTION, array() );

        // 兼容旧版单一锁格式，迁移到多槽位格式
        if ( isset( $locks['source'] ) ) {
            $old_owner   = sanitize_key( $locks['source'] ?? '' );
            $old_expires = absint( $locks['expires_at'] ?? 0 );
            $locks = array();
            if ( $old_owner && $old_expires > $now ) {
                $locks[ $old_owner ] = array(
                    'expires_at' => $old_expires,
                    'count'      => absint( $locks['count'] ?? 1 ),
                );
            }
        }

        // 清理过期槽位
        foreach ( $locks as $key => $lock ) {
            $expires = absint( $lock['expires_at'] ?? 0 );
            if ( $expires <= $now ) {
                unset( $locks[ $key ] );
            }
        }

        // 检查冲突：manual_batch_* 之间互不阻塞，但与其他 source 互斥
        $is_batch = ( strpos( $source, 'manual_batch_' ) === 0 );
        foreach ( $locks as $key => $lock ) {
            if ( $key === $source ) {
                continue; // 自己的槽位，下面处理
            }
            $other_is_batch = ( strpos( $key, 'manual_batch_' ) === 0 );
            // 批量槽位之间不互斥
            if ( $is_batch && $other_is_batch ) {
                continue;
            }
            // 非批量之间互斥（如 autopilot vs 其他）
            // 批量 vs 非批量 互斥
            $expires = absint( $lock['expires_at'] ?? 0 );
            if ( $expires > $now ) {
                return new WP_Error( 'yimai_gso_generation_locked', '当前有其他生成任务正在执行，请稍后再试。' );
            }
        }

        // 获取/创建自己的槽位
        $count = 1;
        if ( isset( $locks[ $source ] ) ) {
            $count = absint( $locks[ $source ]['count'] ?? 0 ) + 1;
        }

        $locks[ $source ] = array(
            'expires_at' => $now + max( 60, absint( $ttl ) ),
            'count'      => $count,
        );

        update_option( self::GENERATION_LOCK_OPTION, $locks, false );

        return true;
    }

    public static function refresh_generation_lock( $source, $ttl = 900 ) {
        $source  = sanitize_key( $source );
        $locks   = get_option( self::GENERATION_LOCK_OPTION, array() );

        // 兼容旧版单一锁格式
        if ( isset( $locks['source'] ) ) {
            return false;
        }

        if ( ! isset( $locks[ $source ] ) ) {
            return false;
        }

        $locks[ $source ]['expires_at'] = time() + max( 60, absint( $ttl ) );
        update_option( self::GENERATION_LOCK_OPTION, $locks, false );
        return true;
    }

    public static function release_generation_lock( $source ) {
        $source  = sanitize_key( $source );
        $locks   = get_option( self::GENERATION_LOCK_OPTION, array() );

        // 兼容旧版单一锁格式
        if ( isset( $locks['source'] ) ) {
            $old_owner = sanitize_key( $locks['source'] ?? '' );
            if ( $old_owner !== $source ) {
                return false;
            }
            delete_option( self::GENERATION_LOCK_OPTION );
            return true;
        }

        if ( ! isset( $locks[ $source ] ) ) {
            return false;
        }

        $count = max( 0, absint( $locks[ $source ]['count'] ?? 0 ) - 1 );
        if ( $count <= 0 ) {
            unset( $locks[ $source ] );
        } else {
            $locks[ $source ]['count']      = $count;
            $locks[ $source ]['expires_at'] = time() + 5 * MINUTE_IN_SECONDS;
        }

        if ( empty( $locks ) ) {
            delete_option( self::GENERATION_LOCK_OPTION );
        } else {
            update_option( self::GENERATION_LOCK_OPTION, $locks, false );
        }
        return true;
    }

    /**
     * 恢复卡死的 item（status='running' 超过 10 分钟 → 标记 failed）
     *
     * PHP 进程被 web server kill/AI 超时/OOM 等情况会导致 item 永久卡在 running 状态。
     * 每次 run() 开始时扫描本 run_id 下的卡死 item，自动标记 failed 以解除阻塞。
     *
     * 同时强制释放 autopilot 锁。如果当前 run 的所有卡死 item 都已被恢复，
     * 说明上次执行的 PHP 进程已被切断，锁必须立即释放，否则后续 cron 触发会全部因
     * 锁冲突而被挡在外面，导致 run 永远卡在 running 状态。
     *
     * @param int $run_id
     * @return int 恢复的 item 数量
     */
    private function recover_stuck_items( $run_id ) {
        $run_id = absint( $run_id );
        if ( $run_id <= 0 ) {
            return 0;
        }

        global $wpdb;
        $stuck_threshold = 5 * MINUTE_IN_SECONDS;

        // 时区修正：updated_at 由 current_time('mysql') 写入，是「站点本地时间」。
        // 早期版本这里用裸 date()（PHP 处于 WordPress 强制的 UTC），在 UTC+8 站点会得到
        // 比本地时间早 8 小时的截止值，导致 "updated_at < cutoff" 永不为真、卡死恢复完全失效。
        // 正确做法：用 current_time('timestamp') 取本地时间戳，再 gmdate() 格式化为本地字符串，
        // 与 current_time('mysql') 的写入口径一致。
        $cutoff_time = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $stuck_threshold );

        $stuck_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, updated_at FROM {$wpdb->prefix}yimai_gso_autopilot_items
             WHERE run_id = %d AND status = 'running'
             AND (updated_at < %s OR updated_at IS NULL)
             ORDER BY id ASC",
            $run_id, $cutoff_time
        ) );

        $recovered = 0;
        foreach ( $stuck_items as $stuck ) {
            YIMAI_GSO_Autopilot_Log::update_item( $stuck->id, array(
                'status'        => 'failed',
                'stage'         => 'timeout',
                'error_message' => '上次执行卡死（>5分钟无响应），已自动标记为失败。',
                'stage_message' => '执行超时。',
            ) );
            $recovered++;
            error_log( sprintf(
                '[一麦GSO Autopilot] Recovered stuck item %d: "%s" (last update: %s)',
                $stuck->id, $stuck->title, $stuck->updated_at ?: 'never'
            ) );
        }

        if ( $recovered > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
            error_log( sprintf(
                '[一麦GSO Autopilot] Run %d: recovered %d stuck item(s)',
                $run_id, $recovered
            ) );
        }

        // 卡死 item 恢复后，需要处理「上次进程被切断、锁未释放」的遗留锁。
        // 但早期实现是无条件 release_generation_lock('autopilot')：它在每次 run() 开头都会执行，
        // 因此可能删掉「另一个仍在正常执行的进程」持有的活锁，反而放行第三个进程 → 重复生成（F-18）。
        // 正确做法：只释放「确已过期」的槽位。真被切断的进程留下的锁必然已过期（TTL 10 分钟），
        // 而未过期的锁说明有进程正在工作，必须尊重它。
        if ( $recovered > 0 ) {
            $released = self::release_generation_lock_if_expired( 'autopilot' );
            if ( $released && defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                error_log( sprintf(
                    '[一麦GSO Autopilot] Run %d: 释放已过期的 autopilot 遗留锁（因卡死恢复 %d 个 item）',
                    $run_id,
                    $recovered
                ) );
            }
        }

        return $recovered;
    }

    /**
     * 仅当指定来源的锁槽位「已过期」时才释放它。
     *
     * 与 release_generation_lock() 的区别：后者无条件释放，用于持有者正常收尾；
     * 本方法用于恢复路径，必须确认锁已过期（持有进程确已死亡），避免误删活跃进程的锁。
     *
     * @param string $source 锁来源
     * @return bool 是否真的释放了
     */
    public static function release_generation_lock_if_expired( $source ) {
        $source = sanitize_key( $source );
        if ( '' === $source ) {
            return false;
        }

        global $wpdb;

        // 与 acquire 使用同一命名锁，保证「检查过期 → 删除」不会与并发 acquire 交错
        $mutex     = 'yimai_gso_gen_lock_mutex';
        $got_mutex = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $mutex ) );
        if ( ! $got_mutex ) {
            return false;
        }

        try {
            $locks = get_option( self::GENERATION_LOCK_OPTION, array() );

            if ( ! isset( $locks[ $source ] ) ) {
                return false;
            }

            $expires = absint( $locks[ $source ]['expires_at'] ?? 0 );
            // 未过期 → 有进程正在持有，尊重它，不释放
            if ( $expires > time() ) {
                return false;
            }

            unset( $locks[ $source ] );
            if ( empty( $locks ) ) {
                delete_option( self::GENERATION_LOCK_OPTION );
            } else {
                update_option( self::GENERATION_LOCK_OPTION, $locks, false );
            }

            return true;
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
        }
    }
}
