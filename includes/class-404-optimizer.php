<?php
/**
 * 404 页面 SEO 优化
 * 检测死链、301重定向管理、自定义404页面、一键修复内链死链
 * 新增重定向管理+一键修复+爬虫识别+日志筛选导出+忽略规则
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_404_Optimizer {

    /**
     * 初始化
     */
    public static function init() {
        add_action( 'wp_ajax_yimai_gso_404_scan', array( __CLASS__, 'ajax_scan_dead_links' ) );
        add_action( 'wp_ajax_yimai_gso_404_get_detail', array( __CLASS__, 'ajax_get_link_detail' ) );
        add_action( 'wp_ajax_yimai_gso_404_generate_page', array( __CLASS__, 'ajax_generate_404_page' ) );
        add_action( 'wp_ajax_yimai_gso_404_save_settings', array( __CLASS__, 'ajax_save_settings' ) );

        //  新增 AJAX
        add_action( 'wp_ajax_yimai_gso_404_apply_redirect', array( __CLASS__, 'ajax_apply_redirect' ) );
        add_action( 'wp_ajax_yimai_gso_404_remove_dead_link', array( __CLASS__, 'ajax_remove_dead_link' ) );
        add_action( 'wp_ajax_yimai_gso_404_get_redirects', array( __CLASS__, 'ajax_get_redirects' ) );
        add_action( 'wp_ajax_yimai_gso_404_delete_redirect', array( __CLASS__, 'ajax_delete_redirect' ) );
        add_action( 'wp_ajax_yimai_gso_404_import_redirects', array( __CLASS__, 'ajax_import_redirects' ) );
        add_action( 'wp_ajax_yimai_gso_404_export_redirects', array( __CLASS__, 'ajax_export_redirects' ) );
        add_action( 'wp_ajax_yimai_gso_404_clear_logs', array( __CLASS__, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_yimai_gso_404_export_csv', array( __CLASS__, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_yimai_gso_404_save_ignore_rules', array( __CLASS__, 'ajax_save_ignore_rules' ) );

        // 自定义404页面生效
        add_action( 'template_include', array( __CLASS__, 'maybe_serve_custom_404' ), 999 );

        // ★ 关键修复：把 WordPress 内部 301 重定向钩子注册到前台请求
        //   （原先只在 AJAX 请求内通过 ensure_wp_redirect_rules() 用 static 变量注册，
        //    导致前台访问时钩子根本不存在，手动/自动重定向全部失效）
        //   这样无论 Apache / Nginx / IIS 都能通过 WP 自身完成 301 跳转。
        add_action( 'template_redirect', array( __CLASS__, 'handle_wp_redirects' ), 1 );
    }

    // ─────────── P2: 爬虫 UA 识别 ───────────

    /**
     * 检测 UA 是否属于搜索引擎爬虫
     * @return array{ bot_type: string, bot_label: string, bot_icon: string }
     */
    private static function detect_bot( $ua ) {
        $ua_lower = strtolower( $ua );
        $bots = array(
            'googlebot'     => array( 'google', 'Googlebot', '🤖' ),
            'baiduspider'   => array( 'baidu', 'Baiduspider', '🕷️' ),
            'bingbot'       => array( 'bing', 'Bingbot', '🤖' ),
            'sogou'         => array( 'sogou', 'Sogou Spider', '🕷️' ),
            '360spider'     => array( '360', '360 Spider', '🕷️' ),
            'bytespider'    => array( 'bytedance', 'ByteSpider', '🕷️' ),
            'yisouspider'   => array( 'yisou', 'YisouSpider', '🕷️' ),
            'applebot'      => array( 'apple', 'Applebot', '🤖' ),
            'duckduckbot'   => array( 'duckduckgo', 'DuckDuckBot', '🤖' ),
            'ahrefsbot'     => array( 'ahrefs', 'AhrefsBot', '🔗' ),
            'semrushbot'    => array( 'semrush', 'SEMrushBot', '🔗' ),
            'mj12bot'       => array( 'majestic', 'MJ12bot', '🔗' ),
            'petalbot'      => array( 'huawei', 'PetalBot', '🕷️' ),
            'facebookexternalhit' => array( 'facebook', 'Facebook Bot', '📱' ),
            'twitterbot'    => array( 'twitter', 'Twitterbot', '📱' ),
            'slurp'         => array( 'yahoo', 'Yahoo Slurp', '🤖' ),
        );

        foreach ( $bots as $key => $info ) {
            if ( strpos( $ua_lower, $key ) !== false ) {
                return array(
                    'bot_type'  => $info[0],
                    'bot_label' => $info[1],
                    'bot_icon'  => $info[2],
                );
            }
        }

        // 通用爬虫检测（未明确命名的）
        if ( preg_match( '/(bot|crawler|spider|scraper|curl|wget|python-requests|go-http|java\/)/i', $ua_lower ) ) {
            return array( 'bot_type' => 'other_bot', 'bot_label' => 'Other Bot', 'bot_icon' => '🤖' );
        }

        return array( 'bot_type' => 'user', 'bot_label' => '用户访问', 'bot_icon' => '👤' );
    }

    // ─────────── 404 日志记录 ───────────

    /**
     * 记录一次 404 访问
     */
    public static function log_404_hit() {
        if ( is_404() ) {
            // 设置开关（P1 修复）：后台「基础设置」里的「记录 404 日志」复选框
            // 会被写入 optimize_404_log_enabled，但本方法原先**从不读取它**，
            // 管理员以为关掉了日志、实际仍在全量记录 —— 既是性能问题也是虚假安全感。
            // 本方法每次 404 都会：读全量 option（最多 500 条 × 11 字段）→ O(n) 扫描
            // → array_unshift → 整体序列化写回 wp_options。
            // 404 流量由机器人驱动、与真实访客量无关，低流量站点反而更易被单一扫描淹没。
            if ( ! YIMAI_GSO_Settings::get( 'optimize_404_log_enabled', 1 ) ) {
                return;
            }

            $url   = $_SERVER['REQUEST_URI'] ?? '';
            $query = $_SERVER['QUERY_STRING'] ?? '';
            $key   = 'yimai_gso_404_logs';

            // 检查忽略规则
            $ignore_rules = YIMAI_GSO_Settings::get( 'optimize_404_ignore_rules', '' );
            if ( ! empty( $ignore_rules ) ) {
                $patterns = array_filter( array_map( 'trim', explode( "\n", $ignore_rules ) ) );
                foreach ( $patterns as $pattern ) {
                    if ( ! empty( $pattern ) && @preg_match( '#' . $pattern . '#i', $url ) ) {
                        return;
                    }
                }
            }

            $logs = get_option( $key, array() );
            if ( ! is_array( $logs ) ) {
                $logs = array();
            }

            $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $bot_info  = self::detect_bot( $ua );

            $entry = array(
                'url'        => esc_url_raw( home_url( $url ) ),
                'path'       => sanitize_text_field( $url ),
                'query'      => sanitize_text_field( $query ),
                'referer'    => esc_url_raw( wp_get_referer() ),
                'ip'         => self::anonymize_ip( $_SERVER['REMOTE_ADDR'] ?? '' ),
                'ua'         => sanitize_text_field( substr( $ua, 0, 200 ) ),
                'bot_type'   => $bot_info['bot_type'],
                'bot_label'  => $bot_info['bot_label'],
                'bot_icon'   => $bot_info['bot_icon'],
                'hit_time'   => current_time( 'mysql' ),
                'count'      => 1,
            );

            // 合并相同 URL 的记录
            $found = false;
            foreach ( $logs as $i => $log ) {
                if ( $log['path'] === $entry['path'] ) {
                    $logs[ $i ]['count']      = ( $logs[ $i ]['count'] ?? 1 ) + 1;
                    $logs[ $i ]['hit_time']   = $entry['hit_time'];
                    $logs[ $i ]['bot_type']   = $entry['bot_type'];
                    $logs[ $i ]['bot_label']  = $entry['bot_label'];
                    $logs[ $i ]['bot_icon']   = $entry['bot_icon'];
                    $found = true;
                    break;
                }
            }

            if ( ! $found ) {
                array_unshift( $logs, $entry );
            }

            // 写入合并（P1 修复）：原先每次 404 都做一次完整 option 序列化 + 整行 UPDATE。
            // 机器人扫描会在短时间内重复请求同一批路径，逐次写库会造成大量无谓的
            // wp_options 写入（与备份/日志争用）。此处用 5 秒窗口内的写标记合并突发写入：
            // 首次命中正常落库，随后 5 秒内的重复 404 只累加内存计数、不写库，
            // 窗口结束后下一次 404 会把累计结果一并落库。数据不丢失，写入量大幅下降。
            $write_debounce_key = 'yimai_gso_404_write_debounce';
            if ( false !== get_transient( $write_debounce_key ) ) {
                // 窗口内：把计数暂存到 transient，等窗口结束由下一次请求合并写库
                $pending = get_transient( 'yimai_gso_404_pending' );
                if ( ! is_array( $pending ) ) {
                    $pending = array();
                }
                $pending[ $entry['path'] ] = ( $pending[ $entry['path'] ] ?? 0 ) + 1;
                set_transient( 'yimai_gso_404_pending', $pending, 10 * MINUTE_IN_SECONDS );
                return;
            }
            set_transient( $write_debounce_key, 1, 5 );

            // 合并上一次窗口累积的计数
            $pending = get_transient( 'yimai_gso_404_pending' );
            if ( is_array( $pending ) && ! empty( $pending ) ) {
                foreach ( $logs as $i => $log ) {
                    if ( isset( $pending[ $log['path'] ] ) ) {
                        $logs[ $i ]['count'] = ( $logs[ $i ]['count'] ?? 1 ) + absint( $pending[ $log['path'] ] );
                        unset( $pending[ $log['path'] ] );
                    }
                }
                // 剩余的是尚未进入 logs 的新路径，补录
                foreach ( $pending as $pending_path => $pending_count ) {
                    if ( count( $logs ) >= 500 ) {
                        break;
                    }
                    array_unshift( $logs, array(
                        'url'       => esc_url_raw( home_url( $pending_path ) ),
                        'path'      => sanitize_text_field( $pending_path ),
                        'query'     => '',
                        'referer'   => '',
                        'ip'        => '',
                        'ua'        => '',
                        'bot_type'  => 'bot',
                        'bot_label' => '机器人',
                        'bot_icon'  => '🤖',
                        'hit_time'  => current_time( 'mysql' ),
                        'count'     => absint( $pending_count ),
                    ) );
                }
                delete_transient( 'yimai_gso_404_pending' );
            }

            update_option( $key, array_slice( $logs, 0, 500 ), false );

            // 自动应用重定向建议：开启且最佳匹配≥80时，实时为本次 404 建立 301
            self::maybe_auto_apply_redirect( $entry['path'] );
        }
    }

    // ─────────── AJAX：扫描死链 ───────────

    public static function ajax_scan_dead_links() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
        $per_page = min( 50, max( 5, absint( $_POST['per_page'] ?? 20 ) ) );
        $source   = sanitize_text_field( $_POST['source'] ?? 'logs' );
        $bot_filter = sanitize_text_field( $_POST['bot_filter'] ?? 'all' );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $time_range = sanitize_text_field( $_POST['time_range'] ?? 'all' );

        $dead_links = array();

        if ( 'logs' === $source ) {
            $dead_links = self::get_404_logs( $page, $per_page, $bot_filter, $search, $time_range );
        } elseif ( 'internal' === $source ) {
            $dead_links = self::scan_internal_dead_links( $page, $per_page, $search );
        }

        foreach ( $dead_links as &$link ) {
            $link['redirect_suggestions'] = self::find_redirect_suggestions( $link['path'] );
            // ★ 标记是否已有重定向规则，供前端区分"应用"/"已应用"
            $link['has_redirect'] = self::redirect_exists( $link['path'] );
        }

        // ★ 扩展：自动应用重定向建议同时支持内链扫描（internal）与死链日志（logs）
        //    只要开启自动应用且最佳匹配≥80，自动创建 301 重定向。
        if ( in_array( $source, array( 'internal', 'logs' ), true ) && 1 == YIMAI_GSO_Settings::get( 'optimize_404_auto_redirect', 0 ) ) {
            foreach ( $dead_links as $link ) {
                if ( ! empty( $link['redirect_suggestions'] ) && ( $link['redirect_suggestions'][0]['score'] ?? 0 ) >= 80 ) {
                    self::create_redirect( $link['path'], $link['redirect_suggestions'][0]['url'], $link['redirect_suggestions'][0]['title'] );
                }
            }
        }

        $stats = self::get_404_stats( $bot_filter, $time_range );

        if ( 'internal' === $source && 1 === $page ) {
            $cached_total = get_transient( 'yimai_gso_404_internal_total' );
            if ( false === $cached_total ) {
                $cached_total = self::estimate_internal_dead_link_count();
                set_transient( 'yimai_gso_404_internal_total', $cached_total, HOUR_IN_SECONDS );
            }
            $stats['total_internal'] = intval( $cached_total );
        } elseif ( 'internal' === $source && $stats['total_internal'] < 0 ) {
            $cached_total = get_transient( 'yimai_gso_404_internal_total' );
            if ( false !== $cached_total ) {
                $stats['total_internal'] = intval( $cached_total );
            }
        }

        wp_send_json_success( array(
            'items'    => $dead_links,
            'total'    => $stats['total_' . $source ],
            'page'     => $page,
            'per_page' => $per_page,
            'stats'    => $stats,
            'source'   => $source,
        ) );
    }

    /**
     * 获取死链详情 + 重定向建议
     */
    public static function ajax_get_link_detail() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $path = sanitize_text_field( $_POST['path'] ?? '' );
        if ( empty( $path ) ) {
            wp_send_json_error( array( 'message' => '缺少路径参数。' ) );
        }

        $logs   = get_option( 'yimai_gso_404_logs', array() );
        $target = null;
        foreach ( $logs as $log ) {
            if ( $log['path'] === $path ) {
                $target = $log;
                break;
            }
        }

        $suggestions = self::find_redirect_suggestions( $path, 5 );

        wp_send_json_success( array(
            'detail'      => $target,
            'suggestions' => $suggestions,
        ) );
    }

    // ─────────── P0: 301 重定向管理 ───────────

    /**
     * 获取所有重定向规则
     */
    public static function ajax_get_redirects() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
        $per_page = min( 50, max( 10, absint( $_POST['per_page'] ?? 20 ) ) );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );

        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        if ( ! is_array( $redirects ) ) {
            $redirects = array();
        }

        // 搜索过滤
        if ( ! empty( $search ) ) {
            $redirects = array_filter( $redirects, function( $r ) use ( $search ) {
                return stripos( $r['source'] ?? '', $search ) !== false
                    || stripos( $r['target'] ?? '', $search ) !== false;
            });
            $redirects = array_values( $redirects );
        }

        $total   = count( $redirects );
        $offset  = ( $page - 1 ) * $per_page;
        $items   = array_slice( $redirects, $offset, $per_page );

        // 统计已写入服务器配置的数量
        $server_written = 0;
        foreach ( $redirects as $r ) {
            if ( ! empty( $r['server_written'] ) ) {
                $server_written++;
            }
        }

        wp_send_json_success( array(
            'items'          => $items,
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $per_page,
            'server_written' => $server_written,
        ) );
    }

    /**
     * 应用/创建一条 301 重定向
     */
    public static function ajax_apply_redirect() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $from_path = sanitize_text_field( $_POST['from_path'] ?? '' );
        $to_url    = esc_url_raw( $_POST['to_url'] ?? '' );
        $to_title  = sanitize_text_field( $_POST['to_title'] ?? '' );

        if ( empty( $from_path ) || empty( $to_url ) ) {
            wp_send_json_error( array( 'message' => '来源路径和目标URL不能为空。' ) );
        }

        $exists        = self::redirect_exists( $from_path );
        $server_result = self::create_redirect( $from_path, $to_url, $to_title );

        wp_send_json_success( array(
            'message'        => $exists ? '重定向规则已更新。' : '重定向规则已创建。',
            'server_written' => (bool) $server_result,
            'exists'         => $exists,
        ) );
    }

    /**
     * 判断某来源是否已存在重定向规则（按规范化后的路径比对）
     */
    private static function redirect_exists( $from_path ) {
        $from_path = self::normalize_redirect_source( $from_path );
        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        if ( ! is_array( $redirects ) ) {
            return false;
        }
        foreach ( $redirects as $r ) {
            if ( self::normalize_redirect_source( $r['source'] ?? '' ) === $from_path ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 规范化重定向来源：去掉协议与主机，仅保留相对路径。
     * 这样无论后台存的是 /2358.html 还是 https://cs.vipck.cn/2358.html，
     * 匹配与服务器规则都一致。
     */
    private static function normalize_redirect_source( $source ) {
        $source = trim( (string) $source );
        if ( '' === $source ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $source ) ) {
            $parts = wp_parse_url( $source );
            $source = isset( $parts['path'] ) ? $parts['path'] : '';
            if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
                $source .= '?' . $parts['query'];
            }
        }
        if ( '' !== $source && strpos( $source, '/' ) !== 0 ) {
            $source = '/' . $source;
        }
        return $source;
    }

    /**
     * 创建/更新一条 301 重定向（手动应用与自动应用共用）
     * @return bool 服务器配置是否写入成功
     */
    private static function create_redirect( $from_path, $to_url, $to_title ) {
        $from_path = self::normalize_redirect_source( $from_path );
        $to_url    = esc_url_raw( $to_url );
        if ( empty( $from_path ) || empty( $to_url ) ) {
            return false;
        }

        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        if ( ! is_array( $redirects ) ) {
            $redirects = array();
        }

        $exists = false;
        foreach ( $redirects as $i => $r ) {
            if ( self::normalize_redirect_source( $r['source'] ?? '' ) === $from_path ) {
                $redirects[ $i ]['target']       = $to_url;
                $redirects[ $i ]['target_title'] = $to_title;
                $redirects[ $i ]['updated_at']   = current_time( 'mysql' );
                $exists = true;
                break;
            }
        }
        if ( ! $exists ) {
            $redirects[] = array(
                'source'        => $from_path,
                'target'        => $to_url,
                'target_title'  => $to_title,
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
                'server_written' => false,
            );
        }
        update_option( 'yimai_gso_404_redirects', $redirects, false );

        // 尝试写入 .htaccess / Nginx 配置（best-effort，真正的跳转由 WP 内部钩子保证）
        $server_result = self::write_server_redirect( $from_path, $to_url );
        if ( $server_result ) {
            $redirects = get_option( 'yimai_gso_404_redirects', array() );
            foreach ( $redirects as $i => $r ) {
                if ( self::normalize_redirect_source( $r['source'] ?? '' ) === $from_path ) {
                    $redirects[ $i ]['server_written'] = true;
                    break;
                }
            }
            update_option( 'yimai_gso_404_redirects', $redirects, false );
        }

        self::ensure_wp_redirect_rules();
        return (bool) $server_result;
    }

    /**
     * 删除一条重定向规则
     */
    public static function ajax_delete_redirect() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $source = sanitize_text_field( $_POST['source'] ?? '' );
        if ( empty( $source ) ) {
            wp_send_json_error( array( 'message' => '缺少参数。' ) );
        }

        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        $redirects = array_filter( $redirects, function( $r ) use ( $source ) {
            return ( $r['source'] ?? '' ) !== $source;
        });
        $redirects = array_values( $redirects );
        update_option( 'yimai_gso_404_redirects', $redirects, false );

        wp_send_json_success( array( 'message' => '重定向规则已删除。' ) );
    }

    /**
     * 批量应用重定向建议
     */
    public static function ajax_batch_apply_redirects() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $items = $_POST['items'] ?? array();
        if ( empty( $items ) || ! is_array( $items ) ) {
            wp_send_json_error( array( 'message' => '请选择要应用的建议。' ) );
        }

        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        if ( ! is_array( $redirects ) ) {
            $redirects = array();
        }

        $applied = 0;
        foreach ( $items as $item ) {
            $from = sanitize_text_field( $item['from_path'] ?? '' );
            $to   = esc_url_raw( $item['to_url'] ?? '' );
            $title = sanitize_text_field( $item['to_title'] ?? '' );

            if ( empty( $from ) || empty( $to ) ) {
                continue;
            }

            $found = false;
            foreach ( $redirects as $i => $r ) {
                if ( ( $r['source'] ?? '' ) === $from ) {
                    $redirects[ $i ]['target']       = $to;
                    $redirects[ $i ]['target_title'] = $title;
                    $redirects[ $i ]['updated_at']   = current_time( 'mysql' );
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $redirects[] = array(
                    'source'       => $from,
                    'target'       => $to,
                    'target_title' => $title,
                    'created_at'   => current_time( 'mysql' ),
                    'updated_at'   => current_time( 'mysql' ),
                    'server_written' => false,
                );
            }
            $applied++;

            // 写入服务器配置
            self::write_server_redirect( $from, $to );
        }

        update_option( 'yimai_gso_404_redirects', $redirects, false );
        self::ensure_wp_redirect_rules();

        wp_send_json_success( array(
            'message' => sprintf( '已应用 %d 条重定向规则。', $applied ),
            'applied' => $applied,
        ) );
    }

    /**
     * 导入重定向（CSV 格式）
     */
    public static function ajax_import_redirects() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $csv = $_POST['csv_data'] ?? '';
        if ( empty( $csv ) ) {
            wp_send_json_error( array( 'message' => '请粘贴 CSV 数据。' ) );
        }

        $lines = array_filter( explode( "\n", trim( $csv ) ) );
        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        if ( ! is_array( $redirects ) ) {
            $redirects = array();
        }

        $imported = 0;
        foreach ( $lines as $line ) {
            $cols = str_getcsv( $line );
            if ( count( $cols ) < 2 ) {
                continue;
            }
            $from = trim( $cols[0] );
            $to   = trim( $cols[1] );
            $title = isset( $cols[2] ) ? trim( $cols[2] ) : '';

            if ( empty( $from ) || empty( $to ) ) {
                continue;
            }

            $found = false;
            foreach ( $redirects as $i => $r ) {
                if ( ( $r['source'] ?? '' ) === $from ) {
                    $redirects[ $i ]['target']       = $to;
                    $redirects[ $i ]['target_title'] = $title;
                    $redirects[ $i ]['updated_at']   = current_time( 'mysql' );
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $redirects[] = array(
                    'source'       => $from,
                    'target'       => $to,
                    'target_title' => $title,
                    'created_at'   => current_time( 'mysql' ),
                    'updated_at'   => current_time( 'mysql' ),
                    'server_written' => false,
                );
            }
            $imported++;
        }

        update_option( 'yimai_gso_404_redirects', $redirects, false );
        self::ensure_wp_redirect_rules();

        wp_send_json_success( array(
            'message'  => sprintf( '已导入 %d 条重定向规则。', $imported ),
            'imported' => $imported,
        ) );
    }

    /**
     * 导出重定向为 CSV
     */
    public static function ajax_export_redirects() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        $csv = "source,target,target_title,created_at\n";
        foreach ( $redirects as $r ) {
            $csv .= '"' . ( $r['source'] ?? '' ) . '","' . ( $r['target'] ?? '' ) . '","' . ( $r['target_title'] ?? '' ) . '","' . ( $r['created_at'] ?? '' ) . '"' . "\n";
        }

        wp_send_json_success( array( 'csv' => $csv ) );
    }

    /**
     * 写服务器重定向配置（.htaccess 或 Nginx）
     * @return bool 是否成功写入
     */
    private static function write_server_redirect( $from_path, $to_url ) {
        // 确保来源路径以 / 开头
        if ( strpos( $from_path, '/' ) !== 0 ) {
            $from_path = '/' . $from_path;
        }

        // 检测服务器类型
        $is_nginx = false;
        if ( function_exists( 'apache_get_modules' ) ) {
            $is_nginx = false;
        } elseif ( strpos( $_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx' ) !== false ) {
            $is_nginx = true;
        } elseif ( isset( $_SERVER['NGINX'] ) ) {
            $is_nginx = true;
        }

        if ( $is_nginx ) {
            return self::write_nginx_redirect( $from_path, $to_url );
        } else {
            return self::write_htaccess_redirect( $from_path, $to_url );
        }
    }

    /**
     * 写 .htaccess 301 重定向
     */
    private static function write_htaccess_redirect( $from_path, $to_url ) {
        $htaccess = ABSPATH . '.htaccess';
        if ( ! is_writable( $htaccess ) ) {
            return false;
        }

        $content = file_get_contents( $htaccess );
        $rule    = 'Redirect 301 ' . $from_path . ' ' . $to_url;

        // 如果已存在同路径规则，跳过
        if ( strpos( $content, 'Redirect 301 ' . $from_path . ' ' ) !== false ) {
            return true;
        }

        $yimai_gso_marker = '# 一麦GSO 301 Redirects';
        $legacy_marker = '# WPAIP 301 Redirects'; // 旧版本写入的标记，仅用于识别已有规则块
        $wp_marker    = '# BEGIN WordPress';

        // 优先使用新标记；老站点 .htaccess 里已是旧标记时沿用旧标记追加，避免产生重复块
        $marker = $yimai_gso_marker;
        if ( strpos( $content, $yimai_gso_marker ) === false && strpos( $content, $legacy_marker ) !== false ) {
            $marker = $legacy_marker;
        }

        // 如果标记已存在，在该标记后追加规则
        if ( strpos( $content, $marker ) !== false ) {
            $content = preg_replace(
                '/(' . preg_quote( $marker, '/' ) . '.+\n)/',
                "$0" . $rule . "\n",
                $content,
                1
            );
        } elseif ( strpos( $content, $wp_marker ) !== false ) {
            // 首次插入：在 # BEGIN WordPress 前创建标记块
            $content = str_replace( $wp_marker, $yimai_gso_marker . "\n" . $rule . "\n\n" . $wp_marker, $content );
        } else {
            $content .= "\n" . $yimai_gso_marker . "\n" . $rule . "\n";
        }

        $result = file_put_contents( $htaccess, $content );
        return ( false !== $result );
    }

    /**
     * 写 Nginx 301 重定向（生成配置片段，提示用户手动添加）
     */
    private static function write_nginx_redirect( $from_path, $to_url ) {
        $nginx_config_dir = ABSPATH . '../yimai-gso-redirects/';
        if ( ! is_dir( $nginx_config_dir ) ) {
            @mkdir( $nginx_config_dir, 0755, true );
        }

        $conf_file = $nginx_config_dir . '301-redirects.conf';

        // 换行注入防护（S3）：preg_quote($from_path, '#') 只为 '#' 定界符转义，
        // 不会转义 \r\n。若来源路径含换行，写入 .conf 后会变成一条独立指令
        // （该文件由管理员 include 进 Nginx 主配置）。此处先剥离所有换行与制表符，
        // 并对写入片段做最终过滤。
        $from_path = str_replace( array( "\r", "\n", "\t", "\0" ), '', (string) $from_path );
        $to_url    = str_replace( array( "\r", "\n", "\t", "\0" ), '', (string) $to_url );

        $rule      = 'rewrite ^' . preg_quote( $from_path, '#' ) . '$ ' . $to_url . ' permanent;';

        // 避免重复
        if ( file_exists( $conf_file ) && strpos( file_get_contents( $conf_file ), $rule ) !== false ) {
            return true;
        }

        $header = "# 一麦GSO 301 重定向配置\n# 请在 Nginx 主配置中 include 此文件：\n# include " . $conf_file . ";\n\n";
        if ( ! file_exists( $conf_file ) ) {
            file_put_contents( $conf_file, $header . $rule . "\n" );
        } else {
            file_put_contents( $conf_file, rtrim( file_get_contents( $conf_file ) ) . "\n" . $rule . "\n" );
        }

        // 标记已写入
        return true;
    }

    /**
     * 确保 WordPress 内部重定向规则生效
     * 使用 parse_request 钩子实现轻量 301
     */
    private static function ensure_wp_redirect_rules() {
        // 仅在尚未注册时注册一次
        static $registered = false;
        if ( ! $registered ) {
            add_action( 'template_redirect', array( __CLASS__, 'handle_wp_redirects' ), 1 );
            $registered = true;
        }
    }

    /**
     * 在 WordPress 内部处理 301 重定向（优先于服务器配置）
     */
    public static function handle_wp_redirects() {
        $redirects = get_option( 'yimai_gso_404_redirects', array() );
        if ( empty( $redirects ) ) {
            return;
        }

        // ★ 修复：原先只用 strtok 剥离查询串再匹配，导致含查询参数的死链（如 /?author=9）
        //    永远匹配不上。现在保留两套匹配路径：
        //    - 无查询串：用纯路径匹配（兼容旧重定向）
        //    - 有查询串：用完整 URI（含 ?key=value）精确匹配
        $request_uri_full = $_SERVER['REQUEST_URI'] ?? '';
        $request_path     = strtok( $request_uri_full, '?' );
        if ( '' === $request_path ) {
            return;
        }
        $request_path = rtrim( $request_path, '/' );
        if ( '' === $request_path ) {
            $request_path = '/';
        }
        // 完整 URI 也规范化为带 / 开头（严格来说 QUERY_STRING 场景下 request_uri 本身就是完整路径+查询）
        $request_full_normalized = $request_uri_full;
        if ( '' !== $request_full_normalized && strpos( $request_full_normalized, '/' ) !== 0 ) {
            $request_full_normalized = '/' . $request_full_normalized;
        }

        foreach ( $redirects as $r ) {
            $source = self::normalize_redirect_source( $r['source'] ?? '' );
            if ( '' === $source ) {
                continue;
            }
            $source_path = rtrim( $source, '/' );
            if ( '' === $source_path ) {
                $source_path = '/';
            }

            // 判断源是否含查询串：含查询串时用完整 URI 匹配，否则用纯路径匹配
            $source_has_query = ( strpos( $source, '?' ) !== false );
            $match            = $source_has_query
                ? ( $request_full_normalized === $source )
                : ( $request_path === $source_path );

            if ( $match ) {
                wp_redirect( $r['target'], 301 );
                exit;
            }
        }
    }

    /**
     * 自动应用重定向建议（开启「自动应用重定向建议」且最佳匹配得分 ≥ 80 时）
     * 用于：① 前台真实 404 命中时自动建重定向；② 死链扫描时批量建重定向。
     */
    private static function maybe_auto_apply_redirect( $path ) {
        if ( 1 != YIMAI_GSO_Settings::get( 'optimize_404_auto_redirect', 0 ) ) {
            return;
        }
        $path = self::normalize_redirect_source( $path );
        if ( '' === $path ) {
            return;
        }

        // 已存在重定向则跳过
        if ( self::redirect_exists( $path ) ) {
            return;
        }

        // 节流：同一路径 1 小时内只计算一次建议，避免高频 404 时反复查库
        $cache_key = 'yimai_gso_auto404_' . md5( $path );
        if ( false !== get_transient( $cache_key ) ) {
            return;
        }
        set_transient( $cache_key, 1, HOUR_IN_SECONDS );

        $suggestions = self::find_redirect_suggestions( $path, 1 );
        if ( empty( $suggestions ) ) {
            return;
        }
        $top = $suggestions[0];
        if ( ( $top['score'] ?? 0 ) >= 80 ) {
            self::create_redirect( $path, $top['url'], $top['title'] );
        }
    }

    // ─────────── P1: 死链一键处理 ───────────

    /**
     * 移除文章中的死链（保留锚文本，去掉 <a> 标签）
     */
    public static function ajax_remove_dead_link() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $post_id = absint( $_POST['post_id'] ?? 0 );
        $href    = esc_url_raw( $_POST['href'] ?? '' );

        if ( empty( $post_id ) || empty( $href ) ) {
            wp_send_json_error( array( 'message' => '缺少参数。' ) );
        }

        // 越权修正（S3）：本接口会用 wp_update_post() 覆盖整篇正文，
        // 但原先只校验 edit_posts（投稿者级）且没有归属检查，
        // 于是任何能进插件页面的低权限账号都能改写「他人已发布」的文章内容。
        // 正确做法：用 edit_post 元能力（WordPress 标准），它会按文章归属/状态
        // 判定当前用户是否可编辑该篇，覆盖作者与编辑的差异。
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '权限不足：无法编辑这篇文章。' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => '文章不存在。' ) );
        }

        // 移除包含该 href 的 <a> 标签，保留锚文本
        $content = $post->post_content;
        $pattern = '/<a[^>]*href=[\'"]' . preg_quote( $href, '/' ) . '[\'"][^>]*>(.*?)<\/a>/is';
        $content = preg_replace( $pattern, '$1', $content );

        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $content,
        ) );

        // 清除相关缓存
        delete_transient( 'yimai_gso_dead_links_page_1' );
        delete_transient( 'yimai_gso_404_internal_total' );
        clean_post_cache( $post_id );

        wp_send_json_success( array( 'message' => '死链已移除，锚文本已保留。' ) );
    }

    // ─────────── P1: 自定义404页面实际生效 ───────────

    /**
     * Hook template_include，替换 404 页面
     */
    public static function maybe_serve_custom_404( $template ) {
        if ( ! is_404() ) {
            return $template;
        }

        $enabled = YIMAI_GSO_Settings::get( 'optimize_404_custom_enabled', 0 );
        if ( ! $enabled ) {
            return $template;
        }

        // 输出自定义404页面
        status_header( 404 );
        nocache_headers();

        self::render_404_page();
        exit;
    }

    /**
     * 渲染自定义 404 页面
     */
    private static function render_404_page() {
        $site_name = get_bloginfo( 'name' );
        $home_url  = home_url( '/' );
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // 获取最新文章
        $recent_posts = get_posts( array(
            'numberposts' => 8,
            'post_status' => 'publish',
            'post_type'   => 'post',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ) );

        $recent_html = '';
        foreach ( $recent_posts as $rp ) {
            $recent_html .= sprintf(
                '<li><a href="%s">%s</a> <span style="color:#94a3b8;font-size:12px">%s</span></li>',
                esc_url( get_permalink( $rp->ID ) ),
                esc_html( $rp->post_title ),
                get_the_date( 'Y-m-d', $rp )
            );
        }

        // 分类列表
        $categories = get_categories( array( 'hide_empty' => true, 'number' => 15 ) );
        $cat_html = '';
        foreach ( $categories as $cat ) {
            $cat_html .= sprintf(
                '<a href="%s" style="display:inline-block;margin:3px 6px 3px 0;padding:3px 10px;background:#f1f5f9;border-radius:4px;text-decoration:none;color:#475569;font-size:13px">%s (%d)</a>',
                esc_url( get_category_link( $cat->term_id ) ),
                esc_html( $cat->name ),
                absint( $cat->count )
            );
        }

        // 根据请求路径推荐相关文章
        $suggested_html = '';
        if ( ! empty( $request_uri ) && '/' !== $request_uri ) {
            $suggestions = self::find_redirect_suggestions( $request_uri, 4 );
            if ( ! empty( $suggestions ) ) {
                $items = '';
                foreach ( $suggestions as $s ) {
                    $score_badge = $s['score'] > 0
                        ? '<span style="color:#10b981;font-size:11px;font-weight:600">匹配度 ' . $s['score'] . '%</span>'
                        : '';
                    $items .= sprintf(
                        '<div style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center">
                            <a href="%s" style="color:#1e40af;font-weight:500;text-decoration:none">%s</a>%s
                        </div>',
                        esc_url( $s['url'] ),
                        esc_html( $s['title'] ),
                        $score_badge
                    );
                }
                $suggested_html = '<div style="margin-bottom:24px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
                    <h4 style="margin:0 0 10px;color:#166534">您可能想找：</h4>' . $items . '</div>';
            }
        }

        // 获取自定义HTML（如果有）
        $custom_html = YIMAI_GSO_Settings::get( 'optimize_404_custom_page', '' );
        $body_html = ! empty( $custom_html ) ? $custom_html : sprintf(
            '<div style="max-width:720px;margin:60px auto;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">
                <div style="text-align:center;margin-bottom:32px">
                    <h1 style="font-size:64px;margin:0;color:#1d2327;font-weight:300">404</h1>
                    <p style="font-size:18px;color:#64748b;margin:12px 0 0">抱歉，您访问的页面不存在</p>
                    <p style="color:#94a3b8;margin:6px 0 0;font-size:14px">页面可能已被移动、删除或从未存在</p>
                </div>
                <div style="margin-bottom:24px">
                    <form role="search" method="get" action="%s" style="display:flex;max-width:480px;margin:0 auto">
                        <input type="search" name="s" placeholder="搜索您想找的内容..." style="flex:1;padding:10px 16px;border:1px solid #cbd5e1;border-radius:6px 0 0 6px;font-size:15px;outline:none">
                        <button type="submit" style="padding:10px 24px;background:#2563eb;color:#fff;border:none;border-radius:0 6px 6px 0;cursor:pointer;font-size:15px;font-weight:500">搜索</button>
                    </form>
                </div>
                <div style="text-align:center;margin-bottom:24px">
                    <a href="%s" style="display:inline-block;padding:8px 20px;background:#f1f5f9;color:#475569;border-radius:6px;text-decoration:none;font-size:14px;border:1px solid #e2e8f0">&#8592; 返回首页</a>
                </div>
                %s
                <div style="display:flex;gap:24px;flex-wrap:wrap">
                    <div style="flex:1;min-width:260px">
                        <h3 style="margin:0 0 10px;padding-bottom:6px;border-bottom:2px solid #2563eb;font-size:15px">最新文章</h3>
                        <ul style="margin:0;padding:0;list-style:none">%s</ul>
                    </div>
                    <div style="flex:1;min-width:260px">
                        <h3 style="margin:0 0 10px;padding-bottom:6px;border-bottom:2px solid #2563eb;font-size:15px">分类浏览</h3>
                        <div>%s</div>
                    </div>
                </div>
            </div>',
            esc_url( $home_url ),
            esc_url( $home_url ),
            $suggested_html,
            $recent_html,
            $cat_html
        );

        echo '<!DOCTYPE html><html lang="zh-CN"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>404 - 页面未找到 | ' . esc_html( $site_name ) . '</title>
            <meta name="robots" content="noindex,follow">
        </head><body style="margin:0;background:#f8fafc">' . $body_html . '</body></html>';
    }

    // ─────────── 生成自定义404页面HTML（供管理后台预览） ───────────

    public static function ajax_generate_404_page() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $site_name = get_bloginfo( 'name' );
        $home_url  = home_url( '/' );

        $recent_posts = get_posts( array( 'numberposts' => 10, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ) );
        $recent_html = '';
        foreach ( $recent_posts as $rp ) {
            $recent_html .= sprintf(
                '<li><a href="%s">%s</a> <span style="color:#999;font-size:12px">%s</span></li>',
                esc_url( get_permalink( $rp->ID ) ),
                esc_html( $rp->post_title ),
                get_the_date( 'Y-m-d', $rp )
            );
        }

        $categories = get_categories( array( 'hide_empty' => true, 'number' => 20 ) );
        $cat_html = '';
        foreach ( $categories as $cat ) {
            $cat_html .= sprintf(
                '<a href="%s" style="display:inline-block;margin:4px 8px 4px 0;padding:4px 12px;background:#f0f0f1;border-radius:4px;text-decoration:none;color:#2271b1;font-size:13px">%s (%d)</a>',
                esc_url( get_category_link( $cat->term_id ) ),
                esc_html( $cat->name ),
                absint( $cat->count )
            );
        }

        $html = sprintf(
            '<div style="max-width:720px;margin:60px auto;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">
                <div style="text-align:center;margin-bottom:40px">
                    <h1 style="font-size:72px;margin:0;color:#1d2327">404</h1>
                    <p style="font-size:20px;color:#646970;margin:16px 0 0">抱歉，您访问的页面不存在</p>
                    <p style="color:#8c8f94;margin:8px 0 0">页面可能已被移动、删除或从未存在</p>
                </div>
                <div style="margin-bottom:32px"><form role="search" method="get" action="%s" style="display:flex;max-width:480px;margin:0 auto">
                    <input type="search" name="s" placeholder="搜索您想找的内容..." style="flex:1;padding:10px 16px;border:1px solid #8c8f94;border-radius:4px 0 0 4px;font-size:15px;outline:none">
                    <button type="submit" style="padding:10px 24px;background:#2271b1;color:#fff;border:none;border-radius:0 4px 4px 0;cursor:pointer;font-size:15px">搜索</button>
                </form></div>
                <div style="margin-bottom:32px"><a href="%s" style="display:inline-block;padding:10px 24px;background:#2271b1;color:#fff;border-radius:4px;text-decoration:none;font-size:15px">&#8592; 返回首页</a></div>
                <div style="display:flex;gap:24px;flex-wrap:wrap">
                    <div style="flex:1;min-width:280px"><h3 style="margin:0 0 12px;padding-bottom:8px;border-bottom:2px solid #2271b1;font-size:16px">最新文章</h3><ul style="margin:0;padding:0;list-style:none">%s</ul></div>
                    <div style="flex:1;min-width:280px"><h3 style="margin:0 0 12px;padding-bottom:8px;border-bottom:2px solid #2271b1;font-size:16px">分类浏览</h3><div>%s</div></div>
                </div>
            </div>',
            esc_url( $home_url ), esc_url( $home_url ), $recent_html, $cat_html
        );

        wp_send_json_success( array(
            'html'       => $html,
            'site_name'  => $site_name,
            'preview_url' => $home_url . '?yimai_gso_404_preview=1',
        ) );
    }

    // ─────────── 设置保存 ───────────

    public static function ajax_save_settings() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }
        // 404 基础设置（日志开关/自定义404/自动重定向）属于站点配置，不依赖 seo_push 能力，
        // 否则在低版本或未启用配置下「保存设置」会静默失败、刷新后还原。

        // ★ 修复：isset(0) 仍返回 true，导致取消勾选时仍然保存为 1。
        //    前端 $.post 在取消勾选时发送 0，改用 absint 正确解析。
        YIMAI_GSO_Settings::set( 'optimize_404_log_enabled', absint( $_POST['log_enabled'] ?? 0 ) );
        YIMAI_GSO_Settings::set( 'optimize_404_custom_enabled', absint( $_POST['custom_enabled'] ?? 0 ) );
        YIMAI_GSO_Settings::set( 'optimize_404_auto_redirect', absint( $_POST['auto_redirect'] ?? 0 ) );
        YIMAI_GSO_Settings::set( 'optimize_404_custom_page', wp_kses_post( $_POST['custom_page'] ?? '' ) );

        wp_send_json_success( array( 'message' => '设置已保存。' ) );
    }

    // ─────────── P3: 日志清空 ───────────

    public static function ajax_clear_logs() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        update_option( 'yimai_gso_404_logs', array(), false );
        wp_send_json_success( array( 'message' => '404 日志已清空。' ) );
    }

    // ─────────── P3: 导出CSV ───────────

    public static function ajax_export_csv() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $logs = get_option( 'yimai_gso_404_logs', array() );

        // 过滤静态资源
        $skip_ext = array( '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.map' );
        $filtered = array_filter( $logs, function( $log ) use ( $skip_ext ) {
            $path = strtolower( $log['path'] ?? '' );
            foreach ( $skip_ext as $ext ) {
                if ( substr( $path, -strlen( $ext ) ) === $ext ) return false;
            }
            if ( preg_match( '/\/(wp-login|xmlrpc\.php|robots\.txt|favicon\.ico)/i', $path ) ) return false;
            return true;
        });

        $csv = "path,url,count,hit_time,referer,bot_type,bot_label\n";
        foreach ( $filtered as $log ) {
            $csv .= '"' . ( $log['path'] ?? '' ) . '","' . ( $log['url'] ?? '' ) . '","' . ( $log['count'] ?? 1 ) . '","' . ( $log['hit_time'] ?? '' ) . '","' . ( $log['referer'] ?? '' ) . '","' . ( $log['bot_type'] ?? 'user' ) . '","' . ( $log['bot_label'] ?? '' ) . '"' . "\n";
        }

        wp_send_json_success( array( 'csv' => $csv ) );
    }

    // ─────────── P3: 忽略规则 ───────────

    /**
     * 保存忽略规则（正则表达式，每行一个）
     */
    public static function ajax_save_ignore_rules() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $rules_raw  = sanitize_textarea_field( $_POST['rules'] ?? '' );
        $lines      = array_filter( array_map( 'trim', explode( "\n", $rules_raw ) ) );
        $valid_list = array();
        foreach ( $lines as $line ) {
            // 验证正则有效性（抑制错误，避免 ReDoS / 无效模式导致 PHP 报错）
            if ( @preg_match( '#' . $line . '#i', '' ) === false ) {
                continue;
            }
            $valid_list[] = $line;
        }
        $rules = implode( "\n", $valid_list );
        YIMAI_GSO_Settings::set( 'optimize_404_ignore_rules', $rules );

        wp_send_json_success( array( 'message' => '忽略规则已保存。' ) );
    }

    // ─────────── 内部方法 ───────────

    /**
     * 从 404 日志获取死链列表（支持 bot_filter、search、time_range）
     */
    private static function get_404_logs( $page, $per_page, $bot_filter = 'all', $search = '', $time_range = 'all' ) {
        $logs = get_option( 'yimai_gso_404_logs', array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }

        // 过滤静态资源
        $skip_ext = array( '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.map' );
        $filtered = array_filter( $logs, function( $log ) use ( $skip_ext, $bot_filter, $search, $time_range ) {
            $path = strtolower( $log['path'] ?? '' );
            foreach ( $skip_ext as $ext ) {
                if ( substr( $path, -strlen( $ext ) ) === $ext ) {
                    return false;
                }
            }
            if ( preg_match( '/\/(wp-login|xmlrpc\.php|robots\.txt|favicon\.ico)/i', $path ) ) {
                return false;
            }

            // 爬虫筛选
            if ( 'bot' === $bot_filter ) {
                if ( 'user' === ( $log['bot_type'] ?? 'user' ) ) {
                    return false;
                }
            } elseif ( 'user' === $bot_filter ) {
                if ( 'user' !== ( $log['bot_type'] ?? 'user' ) ) {
                    return false;
                }
            }

            // 关键词搜索
            if ( ! empty( $search ) ) {
                if ( stripos( $log['path'] ?? '', $search ) === false
                    && stripos( $log['url'] ?? '', $search ) === false
                    && stripos( $log['referer'] ?? '', $search ) === false ) {
                    return false;
                }
            }

            // 时间范围筛选
            if ( 'all' !== $time_range && ! empty( $log['hit_time'] ) ) {
                $hit_time = strtotime( $log['hit_time'] );
                $now = current_time( 'timestamp' );

                switch ( $time_range ) {
                    case 'today':
                        if ( date( 'Y-m-d', $hit_time ) !== date( 'Y-m-d', $now ) ) {
                            return false;
                        }
                        break;
                    case 'week':
                        if ( $hit_time < strtotime( '-7 days', $now ) ) {
                            return false;
                        }
                        break;
                    case 'month':
                        if ( $hit_time < strtotime( '-30 days', $now ) ) {
                            return false;
                        }
                        break;
                }
            }

            return true;
        });

        $filtered = array_values( $filtered );
        $offset   = ( $page - 1 ) * $per_page;
        $items    = array_slice( $filtered, $offset, $per_page );

        return array_map( function( $log ) {
            return array(
                'path'      => $log['path'] ?? '',
                'url'       => $log['url'] ?? '',
                'count'     => absint( $log['count'] ?? 1 ),
                'hit_time'  => $log['hit_time'] ?? '',
                'referer'   => $log['referer'] ?? '',
                'source'    => 'logs',
                'bot_type'  => $log['bot_type'] ?? 'user',
                'bot_label' => $log['bot_label'] ?? '用户访问',
                'bot_icon'  => $log['bot_icon'] ?? '👤',
            );
        }, $items );
    }

    private static function scan_internal_dead_links( $page, $per_page, $search = '' ) {
        global $wpdb;

        $pt_list = YIMAI_GSO_Post_Type_Helper::sql_type_list();
        $cache_key = 'yimai_gso_dead_links_page_' . $page . ( $search ? '_q_' . md5( $search ) : '' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $offset = ( $page - 1 ) * $per_page;
        $posts  = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, LEFT(post_content, 5000) AS post_content
             FROM {$wpdb->posts}
             WHERE post_type IN ({$pt_list}) AND post_status = 'publish'
             ORDER BY ID DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        $dead_links = array();
        $home_url   = home_url();
        $seen       = array();

        foreach ( $posts as $post ) {
            if ( empty( $post->post_content ) ) {
                continue;
            }

            preg_match_all( '/<a[^>]+href=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $matches );
            if ( empty( $matches[1] ) ) {
                continue;
            }

            foreach ( $matches[1] as $href ) {
                if ( strpos( $href, $home_url ) !== 0 && strpos( $href, '//' ) === 0 ) {
                    continue;
                }

                $relative = str_replace( $home_url, '', $href );
                $relative = $relative ?: '/';

                if ( preg_match( '/^\/(category|tag|page|author|feed|wp-)/i', $relative ) ) {
                    continue;
                }
                if ( in_array( $relative, $seen, true ) ) {
                    continue;
                }

                // 搜索过滤
                if ( ! empty( $search ) && stripos( $relative, $search ) === false && stripos( $post->post_title, $search ) === false ) {
                    continue;
                }

                $target_post_id = self::url_to_postid_fast( $href );

                if ( 0 === $target_post_id ) {
                    $seen[] = $relative;
                    $dead_links[] = array(
                        'path'      => $relative,
                        'url'       => esc_url_raw( $href ),
                        'full_url'  => esc_url_raw( self::to_absolute_url( $href ) ),
                        'count'     => 1,
                        'hit_time'  => '',
                        'referer'   => get_permalink( $post->ID ),
                        'source'    => 'internal',
                        'from_post' => $post->post_title,
                        'from_id'   => $post->ID,
                    );
                }
            }
        }

        set_transient( $cache_key, $dead_links, 10 * MINUTE_IN_SECONDS );
        return $dead_links;
    }

    private static function estimate_internal_dead_link_count() {
        global $wpdb;
        $dead_count = 0;
        for ( $p = 1; $p <= 10; $p++ ) {
            $offset = ( $p - 1 ) * 50;
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT LEFT(post_content, 3000) AS post_content FROM {$wpdb->posts}
                 WHERE post_type IN ({$pt_list}) AND post_status = 'publish'
                 ORDER BY ID DESC LIMIT 50 OFFSET %d",
                $offset
            ), ARRAY_A );

            if ( empty( $posts ) ) break;

            foreach ( $posts as $post ) {
                if ( empty( $post['post_content'] ) ) continue;
                preg_match_all( '/<a[^>]+href=[\'"]([^\'"]+)[\'"]/i', $post['post_content'], $matches );
                foreach ( array_slice( $matches[1], 0, 15 ) as $href ) {
                    $home_url = home_url();
                    if ( strpos( $href, $home_url ) === 0 ) {
                        $pid = self::url_to_postid_fast( $href );
                        if ( 0 === $pid ) $dead_count++;
                    }
                }
            }
        }
        return $dead_count;
    }

    private static function url_to_postid_fast( $url ) {
        global $wpdb;
        // 内容类型列表（与 scan_internal_dead_links / find_redirect_suggestions 保持一致），避免未定义导致 post_type IN () 语法错误
        $pt_list = class_exists( 'YIMAI_GSO_Post_Type_Helper' )
            ? YIMAI_GSO_Post_Type_Helper::sql_type_list()
            : "'post','page'";
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( empty( $path ) ) return url_to_postid( $url );

        $slug = trim( $path, '/' );
        $slug = preg_replace( '#^[0-9]{4}/[0-9]{1,2}/?#', '', $slug );
        $slug = preg_replace( '#\.html?/?$#', '', $slug );

        if ( empty( $slug ) ) return url_to_postid( $url );

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ({$pt_list}) AND post_status = 'publish' LIMIT 1",
            sanitize_key( $slug )
        ) );

        if ( $post_id ) return absint( $post_id );
        return url_to_postid( $url );
    }

    private static function to_absolute_url( $href ) {
        if ( preg_match( '#^https?://#i', $href ) ) {
            return $href;
        }
        return home_url( $href );
    }

    private static function find_redirect_suggestions( $path, $limit = 3 ) {
        $suggestions = array();
        $home_url    = home_url();

        // ★ 策略0：解析常见 WordPress 查询参数（处理 /?author=9 / ?p=123 / ?cat=5 等纯查询串死链）
        $parsed_url = wp_parse_url( $path );
        $query_params = array();
        if ( isset( $parsed_url['query'] ) ) {
            parse_str( $parsed_url['query'], $query_params );
        }
        $path_only = isset( $parsed_url['path'] ) ? $parsed_url['path'] : $path;

        // 策略0a：?author=N → 查找用户
        if ( isset( $query_params['author'] ) ) {
            $author_id = absint( $query_params['author'] );
            $user      = get_userdata( $author_id );
            if ( $user ) {
                $author_url = get_author_posts_url( $author_id );
                return array( array( 'id' => 0, 'title' => sprintf( '作者: %s', $user->display_name ), 'url' => $author_url, 'score' => 95 ) );
            }
        }

        // 策略0b：?p=N → 按 ID 查找文章
        if ( isset( $query_params['p'] ) ) {
            $post_id = absint( $query_params['p'] );
            $post    = get_post( $post_id );
            if ( $post && 'publish' === $post->post_status ) {
                return array( array( 'id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post->ID ), 'score' => 100 ) );
            }
        }

        // 策略0c：?cat=N → 分类页面
        if ( isset( $query_params['cat'] ) ) {
            $cat_id = absint( $query_params['cat'] );
            $cat_url = get_category_link( $cat_id );
            if ( $cat_url && ! is_wp_error( $cat_url ) ) {
                $cat = get_category( $cat_id );
                $title = $cat ? sprintf( '分类: %s', $cat->name ) : '分类页面';
                return array( array( 'id' => 0, 'title' => $title, 'url' => $cat_url, 'score' => 90 ) );
            }
        }

        // 策略0d：?tag=slug → 标签页面
        if ( isset( $query_params['tag'] ) ) {
            $tag_slug = sanitize_title( $query_params['tag'] );
            $tag      = get_term_by( 'slug', $tag_slug, 'post_tag' );
            if ( $tag ) {
                $tag_url = get_tag_link( $tag->term_id );
                return array( array( 'id' => 0, 'title' => sprintf( '标签: %s', $tag->name ), 'url' => $tag_url, 'score' => 90 ) );
            }
        }

        // 策略1：完整路径解析
        $full_url = $home_url . '/' . ltrim( $path, '/' );
        $post_id  = self::url_to_postid_fast( $full_url );
        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( $post ) {
                return array( array( 'id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post->ID ), 'score' => 100 ) );
            }
        }

        // 提取关键词（从路径段 + 查询参数值中提取）
        $segments = explode( '/', trim( $path_only, '/' ) );
        $segments = array_filter( $segments );
        $keywords = array();
        $slug     = '';
        foreach ( $segments as $seg ) {
            $seg = sanitize_text_field( urldecode( $seg ) );
            if ( preg_match( '/^\d{4}$/', $seg ) || preg_match( '/^\d{1,2}$/', $seg ) ) continue;
            if ( in_array( $seg, array( 'post', 'article', 'p', 'page', 'archive', 'amp', 'feed', 'comment', 'trackback' ), true ) ) continue;
            if ( mb_strlen( $seg ) >= 2 ) {
                $keywords[] = $seg;
                $slug = $seg;
            }
        }

        // ★ 额外：从查询参数值中提取关键词（仅取非数字、长度≥2 的值）
        foreach ( $query_params as $key => $val ) {
            if ( in_array( $key, array( 'author', 'p', 'cat', 'tag', 'page', 'feed', 'replytocom' ), true ) ) continue;
            $val_str = is_array( $val ) ? implode( ' ', $val ) : (string) $val;
            $val_str = urldecode( $val_str );
            if ( mb_strlen( $val_str ) >= 2 && ! preg_match( '/^\d+$/', $val_str ) ) {
                $words = preg_split( '/[\s\-_+]+/', $val_str, -1, PREG_SPLIT_NO_EMPTY );
                foreach ( $words as $w ) {
                    if ( mb_strlen( $w ) >= 2 ) {
                        $keywords[] = sanitize_text_field( $w );
                    }
                }
            }
        }
        $keywords = array_unique( $keywords );

        // 策略2：关键词搜索
        if ( ! empty( $keywords ) ) {
            $search_term = implode( ' ', array_slice( $keywords, 0, 3 ) );
            $query = new WP_Query( array( 'post_type' => YIMAI_GSO_Post_Type_Helper::get_supported(), 'post_status' => 'publish', 'posts_per_page' => $limit * 3, 's' => $search_term, 'orderby' => 'date' ) );
            foreach ( $query->posts as $post ) {
                $score = 0;
                foreach ( $keywords as $kw ) {
                    if ( mb_stripos( $post->post_title, $kw ) !== false ) $score += 10;
                    if ( mb_stripos( $post->post_content, $kw ) !== false ) $score += 2;
                }
                if ( $score > 0 ) {
                    $suggestions[] = array( 'id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post->ID ), 'score' => $score );
                }
            }
        }

        // 策略3：slug模糊匹配
        if ( ! empty( $slug ) && count( $suggestions ) < $limit ) {
            $recent = get_posts( array( 'numberposts' => 50, 'post_status' => 'publish', 'orderby' => 'date' ) );
            $slug_lower = strtolower( $slug );
            $slug_parts = preg_split( '/[-_]+/', $slug_lower );
            foreach ( $recent as $post ) {
                $dup = false;
                foreach ( $suggestions as $s ) { if ( $s['id'] === $post->ID ) { $dup = true; break; } }
                if ( $dup ) continue;

                $score = 0;
                $post_slug = $post->post_name;
                if ( ! empty( $post_slug ) ) {
                    if ( strtolower( $post_slug ) === $slug_lower ) $score += 50;
                    elseif ( stripos( $post_slug, $slug_lower ) !== false || stripos( $slug_lower, $post_slug ) !== false ) $score += 30;
                    foreach ( $slug_parts as $part ) {
                        if ( mb_strlen( $part ) < 2 ) continue;
                        if ( stripos( $post_slug, $part ) !== false ) $score += 15;
                    }
                }
                foreach ( $slug_parts as $part ) {
                    if ( mb_strlen( $part ) < 2 ) continue;
                    if ( mb_stripos( $post->post_title, $part ) !== false ) $score += 10;
                    if ( mb_stripos( $post->post_content, $part ) !== false ) $score += 3;
                }
                if ( $score > 0 ) {
                    $suggestions[] = array( 'id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post->ID ), 'score' => $score );
                }
            }
        }

        usort( $suggestions, function( $a, $b ) { return $b['score'] - $a['score']; } );

        $seen_ids = array();
        $unique = array();
        foreach ( $suggestions as $s ) {
            if ( ! in_array( $s['id'], $seen_ids, true ) ) { $seen_ids[] = $s['id']; $unique[] = $s; }
        }

        if ( count( $unique ) >= $limit ) return array_slice( $unique, 0, $limit );

        // 策略4：热门文章兜底
        $remaining = $limit - count( $unique );
        $exclude = wp_list_pluck( $unique, 'id' );
        $popular = get_posts( array( 'numberposts' => $remaining, 'post_status' => 'publish', 'orderby' => 'comment_count', 'order' => 'DESC', 'exclude' => $exclude ) );
        foreach ( $popular as $p ) {
            $unique[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p->ID ), 'score' => 0 );
        }

        return array_slice( $unique, 0, $limit );
    }

    /**
     * 获取404统计数据（支持bot_filter和time_range筛选）
     */
    private static function get_404_stats( $bot_filter = 'all', $time_range = 'all' ) {
        $logs = get_option( 'yimai_gso_404_logs', array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        $skip_ext = array( '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.map' );
        $filtered = array_filter( $logs, function( $log ) use ( $skip_ext, $bot_filter, $time_range ) {
            $path = strtolower( $log['path'] ?? '' );
            foreach ( $skip_ext as $ext ) {
                if ( substr( $path, -strlen( $ext ) ) === $ext ) return false;
            }
            if ( preg_match( '/\/(wp-login|xmlrpc\.php|robots\.txt|favicon\.ico)/i', $path ) ) return false;

            if ( 'bot' === $bot_filter && 'user' === ( $log['bot_type'] ?? 'user' ) ) return false;
            if ( 'user' === $bot_filter && 'user' !== ( $log['bot_type'] ?? 'user' ) ) return false;

            if ( 'all' !== $time_range && ! empty( $log['hit_time'] ) ) {
                $hit_time = strtotime( $log['hit_time'] );
                $now = current_time( 'timestamp' );
                switch ( $time_range ) {
                    case 'today': if ( date( 'Y-m-d', $hit_time ) !== date( 'Y-m-d', $now ) ) return false; break;
                    case 'week':  if ( $hit_time < strtotime( '-7 days', $now ) ) return false; break;
                    case 'month': if ( $hit_time < strtotime( '-30 days', $now ) ) return false; break;
                }
            }

            return true;
        });

        $total_hits = 0;
        $bot_hits   = 0;
        foreach ( $logs as $log ) {
            $count = absint( $log['count'] ?? 1 );
            $total_hits += $count;
            if ( 'user' !== ( $log['bot_type'] ?? 'user' ) ) {
                $bot_hits += $count;
            }
        }

        return array(
            'total_logs'     => count( $filtered ),
            'total_internal' => -1,
            'total_hits'     => $total_hits,
            'total_bot_hits' => $bot_hits,
        );
    }

    private static function anonymize_ip( $ip ) {
        if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return '';
        if ( strpos( $ip, ':' ) !== false ) {
            $parts = explode( ':', $ip );
            $parts[ count( $parts ) - 1 ] = '0000';
            return implode( ':', $parts );
        }
        $parts = explode( '.', $ip );
        $parts[3] = '0';
        return implode( '.', $parts );
    }
}
