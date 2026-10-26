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
        add_action( 'wp_ajax_yimai_gso_404_get_page', array( __CLASS__, 'ajax_get_page' ) );
        add_action( 'wp_ajax_yimai_gso_404_save_page', array( __CLASS__, 'ajax_save_page' ) );
        add_action( 'wp_ajax_yimai_gso_404_reset_page', array( __CLASS__, 'ajax_reset_page' ) );
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

        // 后台「预览效果」入口：?yimai_gso_404_preview=1（仅管理员，优先级高于 canonical 重定向）
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render_preview' ), 1 );

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
            // wp_options 写入（与备份/日志争用）。此处用 5 秒窗口合并突发写入：
            // 窗口内的重复 404 只把「命中次数」累加到 transient，窗口结束后由下一次
            // 404 一并落库。
            // 注意：暂存时必须保留**完整字段**（query/ip/ua/referer 等），
            // 不能只存计数后再拼一个缺字段的条目，否则会把日志数据降级成空值。
            // 说明（P1 复核结论）：曾尝试用 transient 做 5 秒「写合并」以降低写入次数，
            // 但实测推导后撤回 —— 无持久对象缓存时 set_transient 需写 2 行 wp_options
            // （_transient_ 与 _transient_timeout_），比这里 1 次 update_option 更贵，
            // 净收益为负且会引入计数口径风险。
            // 真正的缺陷是上方"开关从未被读取"（已修复）；写入放大的根治方案是
            // 把日志迁到自定义表（带索引），属结构性改造，不宜在本次修复中顺手做。

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

        // ★ 2.9.11：源与目标同址的规则不该写进服务器配置。
        //   nginx 的 `rewrite ^/x$ https://host/x permanent;` 会在这里空转成无限 301，
        //   请求根本到不了 PHP，插件内的短路也就没机会执行。
        $is_self = self::is_self_redirect( $from_path, $to_url );

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

        if ( $is_self ) {
            return false;
        }

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
                // ★ 修复（2.9.11）：源与目标指向同一个地址时必须放行，否则浏览器
                //   ERR_TOO_MANY_REDIRECTS。自动应用重定向建议会把「当前 URL」建成
                //   source，而 target 又正好是它自己（/archives/1287 → /archives/1287），
                //   WP 内部钩子对这个匹配无能为力，只能靠这里短路。
                if ( self::is_self_redirect( $source, (string) ( $r['target'] ?? '' ) ) ) {
                    continue;
                }
                wp_redirect( $r['target'], 301 );
                exit;
            }
        }
    }

    /**
     * 判断一条重定向是否指向自己（忽略协议、主机、结尾斜杠、查询串顺序）
     *
     * @param string $source 已规范化的源（路径，可能带查询串）
     * @param string $target 目标（可能是完整 URL 或路径）
     * @return bool
     */
    private static function is_self_redirect( $source, $target ) {
        if ( '' === $source || '' === $target ) {
            return false;
        }

        $normalize = function ( $url ) {
            $url = trim( (string) $url );
            // 相对地址补上站点主机，才能和绝对地址同口径比较
            if ( 0 === strpos( $url, '/' ) ) {
                $url = home_url( $url );
            }
            $parts = wp_parse_url( $url );
            if ( empty( $parts['host'] ) ) {
                return '';
            }
            $path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
            if ( '' === $path ) {
                $path = '/';
            }
            // 统一去掉 www. 前缀再比，避免 www / 裸域互相跳
            $host = strtolower( $parts['host'] );
            if ( 0 === strpos( $host, 'www.' ) ) {
                $host = substr( $host, 4 );
            }
            $query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
            return $host . $path . $query;
        };

        $a = $normalize( $source );
        $b = $normalize( $target );

        return ( '' !== $a && $a === $b );
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
     * 预览入口：?yimai_gso_404_preview=1（仅管理员）
     *
     * 与前台真实 404 走同一套渲染逻辑，方便在「跟随主题」模式下核对页头页脚是否协调；
     * 不需要先启用自定义 404，后台点「预览效果」即可查看。
     */
    public static function maybe_render_preview() {
        if ( empty( $_GET['yimai_gso_404_preview'] ) ) {
            return;
        }
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        nocache_headers();
        status_header( 200 );
        self::render_404_page( true );
        exit;
    }

    /**
     * 渲染自定义 404 页面
     *
     * 两种骨架：
     *  - theme      ：套用当前主题的 get_header()/get_footer()，继承主题字体、导航与配色，避免「割裂感」；
     *  - standalone ：输出独立完整 HTML 文档，自带浅色/深色自适应，适合不希望 404 出现主题导航的站点。
     *
     * @param bool $is_preview 是否后台预览（预览时隐藏请求路径）
     */
    private static function render_404_page( $is_preview = false ) {
        $ctx    = self::build_404_context( array( 'is_preview' => $is_preview ) );
        $custom = trim( (string) YIMAI_GSO_Settings::get( 'optimize_404_custom_page', '' ) );

        // 向后兼容：旧版保存的是一整篇 HTML 文档时原样输出（脚本在保存阶段已被过滤）
        if ( '' !== $custom && preg_match( '/<html[\s>]/i', $custom ) ) {
            echo $custom; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $fragment = '' !== $custom ? $custom : self::template_fragment( $ctx, true );
        $fragment = self::normalize_fragment_root( self::resolve_fragment_tokens( $fragment, $ctx ), $ctx );

        // 主题没有 header.php / footer.php（区块主题常见）时 get_header() 会是空白，
        // 页面会变成没有站点样式的裸内容 —— 这种情况自动退回独立页面骨架。
        if ( 'standalone' === $ctx['layout'] || ! self::theme_skeleton_available() ) {
            if ( 'standalone' !== $ctx['layout'] ) {
                $ctx['layout'] = 'standalone';
                $fragment      = self::normalize_fragment_root( $fragment, $ctx );
            }
            $fragment = apply_filters( 'yimai_gso_404_fragment', $fragment, $ctx );
            echo self::standalone_document( $fragment, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $fragment = apply_filters( 'yimai_gso_404_fragment', $fragment, $ctx );

        // 跟随主题：404 内容落在主题骨架内，页头页脚/字体/配色全部沿用主题
        get_header();
        echo '<div class="yimai-404-theme-shell">' . $fragment . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        get_footer();
    }

    /**
     * 当前主题是否提供 header.php / footer.php（区块主题通常没有）
     */
    private static function theme_skeleton_available() {
        if ( ! function_exists( 'locate_template' ) ) {
            return false;
        }
        return (bool) locate_template( 'header.php' ) && (bool) locate_template( 'footer.php' );
    }

    /**
     * 组装 404 页面渲染上下文（文章、分类、智能推荐、配色）
     *
     * @param array $args 可覆盖 layout / color_mode / accent / is_preview
     * @return array
     */
    private static function build_404_context( $args = array() ) {
        $args = wp_parse_args( $args, array(
            'layout'     => (string) YIMAI_GSO_Settings::get( 'optimize_404_layout', 'theme' ),
            'color_mode' => (string) YIMAI_GSO_Settings::get( 'optimize_404_color_mode', 'auto' ),
            'accent'     => (string) YIMAI_GSO_Settings::get( 'optimize_404_accent', '' ),
            'is_preview' => false,
        ) );

        $layout = in_array( $args['layout'], array( 'theme', 'standalone' ), true ) ? $args['layout'] : 'theme';
        $mode   = in_array( $args['color_mode'], array( 'auto', 'light', 'dark' ), true ) ? $args['color_mode'] : 'auto';
        $accent = self::resolve_accent( $args['accent'] );

        $home_url  = home_url( '/' );
        $posts_url = get_permalink( (int) get_option( 'page_for_posts' ) );
        if ( ! $posts_url ) {
            $posts_url = $home_url;
        }

        $request_path = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $parsed = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
            if ( is_string( $parsed ) ) {
                $request_path = $parsed;
            }
            if ( mb_strlen( $request_path ) > 80 ) {
                $request_path = mb_substr( $request_path, 0, 77 ) . '...';
            }
        }

        $recent = array();
        $recent_posts = get_posts( array(
            'numberposts'   => 6,
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'orderby'       => 'date',
            'order'         => 'DESC',
            'no_found_rows' => true,
        ) );
        foreach ( $recent_posts as $rp ) {
            $recent[] = array(
                'title' => get_the_title( $rp ),
                'url'   => get_permalink( $rp->ID ),
                'date'  => get_the_date( 'Y-m-d', $rp ),
            );
        }

        $categories = array();
        foreach ( get_categories( array( 'hide_empty' => true, 'number' => 12 ) ) as $cat ) {
            $cat_url = get_category_link( $cat->term_id );
            if ( ! $cat_url || is_wp_error( $cat_url ) ) {
                continue;
            }
            $categories[] = array(
                'name'  => $cat->name,
                'url'   => $cat_url,
                'count' => absint( $cat->count ),
            );
        }

        $suggestions = array();
        if ( '' !== $request_path && '/' !== $request_path && empty( $args['is_preview'] ) ) {
            $suggestions = self::find_redirect_suggestions( $request_path, 4 );
        }

        return array(
            'layout'        => $layout,
            'color_mode'    => $mode,
            'is_preview'    => ! empty( $args['is_preview'] ),
            'accent'        => $accent['hex'],
            'accent_2'      => $accent['light'],
            'accent_rgb'    => $accent['rgb'],
            'on_accent'     => $accent['on'],
            'accent_source' => $accent['source'],
            'accent_label'  => $accent['label'],
            'site_name'     => get_bloginfo( 'name' ),
            'home_url'      => $home_url,
            'posts_url'     => $posts_url,
            'request_path'  => $request_path,
            'recent'        => $recent,
            'categories'    => $categories,
            'suggestions'   => $suggestions,
        );
    }

    /**
     * 动态区块占位符
     *
     * 保存为「自定义 404 页面」后，这些占位符仍会在前台按本次请求实时填充，
     * 避免最新文章/分类列表冻结在保存那一刻。
     *
     * @return array<string,string>
     */
    private static function fragment_tokens() {
        return array(
            'path'        => '<!--yimai-404:path-->',
            'suggestions' => '<!--yimai-404:suggestions-->',
            'recent'      => '<!--yimai-404:recent-posts-->',
            'categories'  => '<!--yimai-404:categories-->',
        );
    }

    /**
     * 计算动态区块的真实内容
     *
     * @return array<string,string>
     */
    private static function fragment_dynamic_parts( $ctx ) {
        $path_html = '';
        if ( '' !== $ctx['request_path'] && '/' !== $ctx['request_path'] && ! $ctx['is_preview'] ) {
            $path_html = '<p class="yimai-404__path">请求地址 <code>' . esc_html( $ctx['request_path'] ) . '</code></p>';
        }

        $suggest_html = '';
        if ( ! empty( $ctx['suggestions'] ) ) {
            $items = '';
            foreach ( $ctx['suggestions'] as $s ) {
                $score = isset( $s['score'] ) ? absint( $s['score'] ) : 0;
                $items .= '<li><a href="' . esc_url( $s['url'] ) . '">' . esc_html( $s['title'] ) . '</a>'
                    . ( $score > 0 ? '<span class="yimai-404__score">' . $score . '%</span>' : '' ) . '</li>';
            }
            $suggest_html = '<div class="yimai-404__suggest">'
                . '<div class="yimai-404__suggest-head"><span>您可能想找</span><span>按请求路径智能匹配</span></div>'
                . '<ul>' . $items . '</ul>'
                . '</div>';
        }

        $recent_html = '';
        foreach ( $ctx['recent'] as $rp ) {
            $recent_html .= '<li><a href="' . esc_url( $rp['url'] ) . '">' . esc_html( $rp['title'] ) . '</a>'
                . '<time datetime="' . esc_attr( $rp['date'] ) . '">' . esc_html( $rp['date'] ) . '</time></li>';
        }
        if ( '' === $recent_html ) {
            $recent_html = '<li class="yimai-404__empty">站点还没有已发布的文章</li>';
        }

        $cat_html = '';
        foreach ( $ctx['categories'] as $cat ) {
            $cat_html .= '<a class="yimai-404__chip" href="' . esc_url( $cat['url'] ) . '">'
                . esc_html( $cat['name'] ) . '<b>' . absint( $cat['count'] ) . '</b></a>';
        }
        if ( '' === $cat_html ) {
            $cat_html = '<span class="yimai-404__empty">站点还没有分类</span>';
        }

        return array(
            'path'        => $path_html,
            'suggestions' => $suggest_html,
            'recent'      => $recent_html,
            'categories'  => $cat_html,
        );
    }

    /**
     * 把片段里的占位符替换为实时内容（没有占位符的旧自定义页面原样返回）
     */
    private static function resolve_fragment_tokens( $fragment, $ctx ) {
        $parts = self::fragment_dynamic_parts( $ctx );
        foreach ( self::fragment_tokens() as $key => $token ) {
            $fragment = str_replace( $token, isset( $parts[ $key ] ) ? $parts[ $key ] : '', $fragment );
        }
        return $fragment;
    }

    /**
     * 把片段根节点的 class 同步为当前「页面骨架 / 配色模式」
     *
     * 模板的骨架与深浅色完全由根节点 class 驱动，所以后台改选项后不必重新生成代码，
     * 保存和渲染时会自动校正；手写的其它标记不受影响。
     */
    private static function normalize_fragment_root( $fragment, $ctx ) {
        $classes = 'yimai-404 yimai-404--' . $ctx['layout'];
        if ( 'standalone' === $ctx['layout'] ) {
            $classes .= ' yimai-404--' . $ctx['color_mode'];
        }
        $result = preg_replace(
            '/class="yimai-404 yimai-404--(?:theme|standalone)(?: yimai-404--(?:auto|light|dark))?"/',
            'class="' . $classes . '"',
            $fragment,
            1
        );
        return is_string( $result ) ? $result : $fragment;
    }

    /**
     * 生成可直接编辑的 404 片段（style + 结构），后台文本框保存的就是它
     *
     * @param array $ctx         渲染上下文
     * @param bool  $with_tokens true = 保留动态占位符（编辑器/入库），false = 直接填充（前台渲染）
     */
    private static function template_fragment( $ctx, $with_tokens = false ) {
        $fragment = self::template_css( $ctx ) . self::template_markup( $ctx );
        return $with_tokens ? $fragment : self::resolve_fragment_tokens( $fragment, $ctx );
    }

    /**
     * 404 页面结构
     */
    private static function template_markup( $ctx ) {
        $classes = 'yimai-404 yimai-404--' . $ctx['layout'];
        if ( 'standalone' === $ctx['layout'] ) {
            $classes .= ' yimai-404--' . $ctx['color_mode'];
        }

        $tokens      = self::fragment_tokens();
        $search_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.8-3.8"></path></svg>';

        return sprintf(
            '<div class="%1$s" data-layout="%2$s">
    <div class="yimai-404__inner">
        <p class="yimai-404__eyebrow">页面未找到</p>
        <div class="yimai-404__code" aria-hidden="true">404</div>
        <h1 class="yimai-404__title">这个页面走丢了</h1>
        <p class="yimai-404__desc">您访问的地址可能已被移动、删除，或者从未存在过。换个入口，或直接搜索您想要的内容。</p>
        %3$s
        <form class="yimai-404__search" role="search" method="get" action="%4$s">
            <label class="yimai-404__sr" for="yimai-404-search">搜索本站内容</label>
            <input id="yimai-404-search" type="search" name="s" placeholder="想找什么？输入关键词搜索" autocomplete="off">
            <button type="submit">%5$s<span>搜索</span></button>
        </form>
        <div class="yimai-404__actions">
            <a class="yimai-404__btn yimai-404__btn--primary" href="%6$s">返回首页</a>
            <a class="yimai-404__btn" href="%7$s">浏览全部文章</a>
        </div>
        %8$s
        <div class="yimai-404__grid">
            <section class="yimai-404__card">
                <h2>最新文章</h2>
                <ul class="yimai-404__list">%9$s</ul>
            </section>
            <section class="yimai-404__card">
                <h2>按分类浏览</h2>
                <div class="yimai-404__chips">%10$s</div>
                <p class="yimai-404__card-note">没找到想要的内容？用上方搜索框直接搜关键词。</p>
            </section>
        </div>
        <p class="yimai-404__footer">%11$s · 若您是从站内链接来到这里，欢迎反馈给我们</p>
    </div>
</div>',
            esc_attr( $classes ),
            esc_attr( $ctx['layout'] ),
            $tokens['path'],
            esc_url( $ctx['home_url'] ),
            $search_icon,
            esc_url( $ctx['home_url'] ),
            esc_url( $ctx['posts_url'] ),
            $tokens['suggestions'],
            $tokens['recent'],
            $tokens['categories'],
            esc_html( $ctx['site_name'] )
        );
    }

    /**
     * 404 页面样式
     *
     * 设计要点：
     *  - 主色来自设置（默认自动识别主题调色板），按钮文字按主色亮度自动取黑/白；
     *  - 「跟随主题」模式使用半透明中性色（rgba(127,127,127,…)）与 color:inherit，
     *    在任何浅色/深色主题下都能自然融合，不做背景覆盖；
     *  - 「独立页面」模式自带浅色/深色变量，auto 时跟随系统 prefers-color-scheme。
     */
    private static function template_css( $ctx ) {
        $variables = sprintf(
            '.yimai-404{--y404-accent:%1$s;--y404-accent-2:%2$s;--y404-accent-rgb:%3$s;--y404-on-accent:%4$s}',
            esc_attr( $ctx['accent'] ),
            esc_attr( $ctx['accent_2'] ),
            esc_attr( implode( ',', $ctx['accent_rgb'] ) ),
            esc_attr( $ctx['on_accent'] )
        );

        $css = <<<'CSS'
.yimai-404,.yimai-404 *,.yimai-404 *::before,.yimai-404 *::after{box-sizing:border-box}
.yimai-404{
    --y404-radius:16px;
    --y404-surface:rgba(127,127,127,.07);
    --y404-surface-2:rgba(127,127,127,.13);
    --y404-line:rgba(127,127,127,.22);
    --y404-line-strong:rgba(127,127,127,.4);
    position:relative;
    padding:clamp(2.5rem,6vw,5rem) 1.25rem;
    font-family:inherit;
    color:inherit;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
    text-rendering:optimizeLegibility;
}
.yimai-404__inner{position:relative;max-width:940px;margin:0 auto;text-align:center}
.yimai-404__eyebrow{
    display:inline-flex;align-items:center;gap:10px;margin:0 0 18px;
    font-size:11px;font-weight:700;letter-spacing:.24em;text-transform:uppercase;opacity:.6;color:inherit;
}
.yimai-404__eyebrow::before,.yimai-404__eyebrow::after{content:"";display:block;width:24px;height:1px;background:currentColor;opacity:.45}
.yimai-404__code{
    margin:0 0 .1em;
    font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
    font-size:clamp(84px,17vw,168px);font-weight:800;line-height:.82;letter-spacing:-.055em;
    background-image:linear-gradient(135deg,var(--y404-accent) 0%,var(--y404-accent-2) 100%);
    -webkit-background-clip:text;background-clip:text;
    -webkit-text-fill-color:transparent;color:transparent;
}
@supports not ((-webkit-background-clip:text) or (background-clip:text)){
    .yimai-404__code{color:var(--y404-accent);-webkit-text-fill-color:currentColor}
}
.yimai-404__title{margin:0 0 .55em;font-size:clamp(21px,2.6vw,28px);font-weight:700;line-height:1.35;letter-spacing:-.01em;color:inherit}
.yimai-404__desc{margin:0 auto 1.3em;max-width:34em;font-size:15px;line-height:1.75;opacity:.7;color:inherit}
.yimai-404__path{margin:0 auto 1.6em;font-size:12.5px;opacity:.55}
.yimai-404__path code{
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;
    margin-left:6px;padding:2px 8px;border-radius:6px;
    background:var(--y404-surface);border:1px solid var(--y404-line);word-break:break-all;
}
.yimai-404__search{
    display:flex;align-items:center;gap:8px;max-width:560px;margin:0 auto 20px;padding:6px;
    border:1px solid var(--y404-line);border-radius:999px;background:var(--y404-surface);
    transition:border-color .2s ease,box-shadow .2s ease;
}
.yimai-404__search:focus-within{border-color:var(--y404-accent);box-shadow:0 0 0 4px rgba(var(--y404-accent-rgb),.16)}
.yimai-404__search input{
    flex:1 1 auto;min-width:0;padding:10px 16px;border:0;border-radius:999px;
    background:transparent;color:inherit;font-family:inherit;font-size:16px;line-height:1.4;outline:none;
    -webkit-appearance:none;appearance:none;box-shadow:none;
}
.yimai-404__search input::placeholder{color:currentColor;opacity:.42}
.yimai-404__search input::-webkit-search-cancel-button{-webkit-appearance:none}
.yimai-404__search button{
    display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;padding:11px 22px;
    border:0;border-radius:999px;cursor:pointer;white-space:nowrap;
    background:linear-gradient(135deg,var(--y404-accent),var(--y404-accent-2));
    color:var(--y404-on-accent);font-family:inherit;font-size:14px;font-weight:600;
    text-transform:none;letter-spacing:normal;box-shadow:none;
    transition:transform .16s ease,filter .16s ease;
}
.yimai-404__search button:hover{transform:translateY(-1px);filter:brightness(1.06)}
.yimai-404__search button:active{transform:translateY(0)}
.yimai-404__search button:focus-visible,.yimai-404__btn:focus-visible,.yimai-404__chip:focus-visible,
.yimai-404__list a:focus-visible,.yimai-404__suggest a:focus-visible{outline:2px solid var(--y404-accent);outline-offset:3px}
.yimai-404__actions{display:flex;justify-content:center;flex-wrap:wrap;gap:12px;margin-bottom:clamp(30px,4.5vw,48px)}
.yimai-404__btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 26px;
    border:1px solid var(--y404-line-strong);border-radius:999px;background:transparent;
    color:inherit;font-size:14px;font-weight:600;line-height:1.3;text-decoration:none;
    transition:transform .16s ease,background .16s ease,border-color .16s ease,box-shadow .16s ease,filter .16s ease;
}
.yimai-404__btn:hover{border-color:var(--y404-accent);color:var(--y404-accent);background:rgba(var(--y404-accent-rgb),.07)}
.yimai-404__btn--primary{
    border-color:transparent;color:var(--y404-on-accent);
    background:linear-gradient(135deg,var(--y404-accent),var(--y404-accent-2));
    box-shadow:0 8px 22px rgba(var(--y404-accent-rgb),.28);
}
.yimai-404__btn--primary:hover{
    color:var(--y404-on-accent);transform:translateY(-1px);filter:brightness(1.06);
    background:linear-gradient(135deg,var(--y404-accent),var(--y404-accent-2));
    box-shadow:0 12px 26px rgba(var(--y404-accent-rgb),.34);
}
.yimai-404__suggest{
    max-width:660px;margin:0 auto clamp(30px,4.5vw,44px);padding:16px 20px;text-align:left;
    border:1px solid var(--y404-line);border-left:3px solid var(--y404-accent);
    border-radius:14px;background:var(--y404-surface);
}
.yimai-404__suggest-head{display:flex;align-items:baseline;flex-wrap:wrap;gap:10px;margin-bottom:8px;font-size:13px;font-weight:700}
.yimai-404__suggest-head span:last-child{font-size:11px;font-weight:500;letter-spacing:.04em;opacity:.5}
.yimai-404__suggest ul{list-style:none;margin:0;padding:0}
.yimai-404__suggest li{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 0;border-top:1px dashed var(--y404-line)}
.yimai-404__suggest li:first-child{border-top:0;padding-top:2px}
.yimai-404__suggest a{color:inherit;font-size:14px;font-weight:500;text-decoration:none;overflow:hidden;text-overflow:ellipsis}
.yimai-404__suggest a:hover{color:var(--y404-accent)}
.yimai-404__score{flex:0 0 auto;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;background:rgba(var(--y404-accent-rgb),.12);color:var(--y404-accent)}
.yimai-404__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:18px;align-items:start;margin-bottom:clamp(30px,4.5vw,44px);text-align:left}
.yimai-404__card{padding:22px 24px;border:1px solid var(--y404-line);border-radius:var(--y404-radius);background:var(--y404-surface)}
.yimai-404__card h2{display:flex;align-items:center;gap:9px;margin:0 0 14px;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;opacity:.82;color:inherit}
.yimai-404__card h2::before{content:"";width:3px;height:13px;border-radius:2px;background:var(--y404-accent)}
.yimai-404__list{list-style:none;margin:0;padding:0}
.yimai-404__list li{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid var(--y404-line);font-size:14px}
.yimai-404__list li:first-child{border-top:0;padding-top:0}
.yimai-404__list a{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;color:inherit;font-weight:500;line-height:1.5;text-decoration:none}
.yimai-404__list a:hover{color:var(--y404-accent)}
.yimai-404__list time{flex:0 0 auto;font-size:11px;opacity:.5;font-variant-numeric:tabular-nums;letter-spacing:.02em}
.yimai-404__chips{display:flex;flex-wrap:wrap;gap:8px}
.yimai-404__chip{
    display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border:1px solid var(--y404-line);
    border-radius:999px;background:transparent;color:inherit;font-size:12.5px;text-decoration:none;
    transition:border-color .15s ease,color .15s ease,background .15s ease;
}
.yimai-404__chip b{font-size:10.5px;font-weight:700;opacity:.55}
.yimai-404__chip:hover{border-color:var(--y404-accent);color:var(--y404-accent);background:rgba(var(--y404-accent-rgb),.08)}
.yimai-404__chip:hover b{opacity:.9}
.yimai-404__card-note{margin:14px 0 0;padding-top:12px;border-top:1px solid var(--y404-line);font-size:12px;line-height:1.6;opacity:.5}
.yimai-404__empty{display:block;padding:4px 0;font-size:12.5px;opacity:.5}
.yimai-404__footer{margin:0;font-size:12px;line-height:1.7;opacity:.45}
.yimai-404__sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
.yimai-404--theme{background:transparent}
.yimai-404--standalone{display:flex;align-items:center;justify-content:center;min-height:100vh}
.yimai-404--standalone .yimai-404__inner{width:100%}
.yimai-404--standalone.yimai-404--light{
    --y404-surface:#fff;--y404-surface-2:#f1f5f9;--y404-line:#e4e9f2;--y404-line-strong:#cbd5e1;
    color:#0f172a;
    background:radial-gradient(58% 42% at 12% 0%,rgba(var(--y404-accent-rgb),.16),transparent 70%),
               radial-gradient(52% 40% at 92% 8%,rgba(var(--y404-accent-rgb),.1),transparent 72%),#f7f8fc;
}
.yimai-404--standalone.yimai-404--light .yimai-404__card{box-shadow:0 12px 34px rgba(15,23,42,.06)}
.yimai-404--standalone.yimai-404--dark{
    --y404-surface:rgba(255,255,255,.055);--y404-surface-2:rgba(255,255,255,.1);
    --y404-line:rgba(255,255,255,.14);--y404-line-strong:rgba(255,255,255,.26);
    color:#e9edf6;
    background:radial-gradient(58% 42% at 12% 0%,rgba(var(--y404-accent-rgb),.28),transparent 70%),
               radial-gradient(52% 40% at 92% 8%,rgba(var(--y404-accent-rgb),.18),transparent 72%),#0a0e17;
}
.yimai-404--standalone.yimai-404--dark .yimai-404__card{box-shadow:0 12px 34px rgba(0,0,0,.32)}
@media (prefers-color-scheme:dark){
    .yimai-404--standalone.yimai-404--auto{
        --y404-surface:rgba(255,255,255,.055);--y404-surface-2:rgba(255,255,255,.1);
        --y404-line:rgba(255,255,255,.14);--y404-line-strong:rgba(255,255,255,.26);
        color:#e9edf6;
        background:radial-gradient(58% 42% at 12% 0%,rgba(var(--y404-accent-rgb),.28),transparent 70%),
                   radial-gradient(52% 40% at 92% 8%,rgba(var(--y404-accent-rgb),.18),transparent 72%),#0a0e17;
    }
    .yimai-404--standalone.yimai-404--auto .yimai-404__card{box-shadow:0 12px 34px rgba(0,0,0,.32)}
}
@media (max-width:600px){
    .yimai-404{padding-left:.9rem;padding-right:.9rem}
    .yimai-404__search button{padding:11px 16px}
    .yimai-404__search button span{display:none}
    .yimai-404__card{padding:18px}
    .yimai-404__btn{width:100%}
    .yimai-404__actions{gap:10px}
}
@media (prefers-reduced-motion:reduce){
    .yimai-404 *,.yimai-404 *::before,.yimai-404 *::after{transition:none!important;animation:none!important}
}
CSS;

        return '<style id="yimai-404-style">' . $variables . $css . '</style>';
    }

    /**
     * 独立页面模式的完整 HTML 文档
     */
    private static function standalone_document( $fragment, $ctx ) {
        $body_class = 'yimai-404-body';
        if ( 'dark' === $ctx['color_mode'] ) {
            $body_class .= ' yimai-404-body--dark';
        } elseif ( 'light' === $ctx['color_mode'] ) {
            $body_class .= ' yimai-404-body--light';
        }

        return '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,follow">
<title>404 · 页面走丢了 | ' . esc_html( $ctx['site_name'] ) . '</title>
' . self::shell_css() . '
</head>
<body class="' . esc_attr( $body_class ) . '">
' . $fragment . '
</body>
</html>';
    }

    /**
     * 独立页面的文档级基础样式（只负责 body 背景与字体，页面细节由片段自带）
     */
    private static function shell_css() {
        $css = <<<'CSS'
html{-webkit-text-size-adjust:100%}
html,body{margin:0;padding:0}
body.yimai-404-body{
    min-height:100vh;background:#f7f8fc;color:#0f172a;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",Roboto,"Helvetica Neue",Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
}
body.yimai-404-body--dark{background:#0a0e17;color:#e9edf6}
body.yimai-404-body--light{background:#f7f8fc;color:#0f172a}
@media (prefers-color-scheme:dark){
    body.yimai-404-body:not(.yimai-404-body--light){background:#0a0e17;color:#e9edf6}
}
CSS;
        return '<style id="yimai-404-shell-style">' . $css . '</style>';
    }

    // ─────────── 配色：主色解析与主题取色 ───────────

    /**
     * 解析最终主色
     *
     * @param string $setting 设置值：空 = 自动跟随主题，或 #rrggbb
     * @return array{hex:string,rgb:int[],light:string,on:string,source:string,label:string}
     */
    private static function resolve_accent( $setting ) {
        $setting = trim( (string) $setting );
        $hex     = '';
        $source  = 'default';

        if ( '' !== $setting && 'theme' !== $setting ) {
            $hex = self::normalize_hex_color( $setting );
            if ( '' !== $hex ) {
                $source = 'custom';
            }
        }

        if ( '' === $hex ) {
            $detected = self::detect_theme_accent();
            if ( '' !== $detected ) {
                $hex    = $detected;
                $source = 'theme';
            }
        }

        if ( '' === $hex ) {
            $hex = '#6366f1';
        }

        // 允许主题/子主题进一步覆盖
        $hex = self::normalize_hex_color( (string) apply_filters( 'yimai_gso_404_accent_color', $hex, $source ) );
        if ( '' === $hex ) {
            $hex    = '#6366f1';
            $source = 'default';
        }

        $labels = array(
            'custom'  => '自定义主色',
            'theme'   => '站点主题色',
            'default' => '插件默认主色',
        );

        return array(
            'hex'    => $hex,
            'rgb'    => self::hex_to_rgb( $hex ),
            'light'  => self::mix_hex( $hex, '#ffffff', 0.26 ),
            'on'     => self::pick_contrast_color( $hex ),
            'source' => $source,
            'label'  => $labels[ $source ],
        );
    }

    /**
     * 从当前主题推断主色
     *
     * 优先读区块主题的全局调色板（theme / default 两个来源），再读常见 theme_mod，
     * 按饱和度与语义标签打分，跳过背景/正文一类的灰阶色。
     *
     * @return string 十六进制色值，未识别时返回空串
     */
    private static function detect_theme_accent() {
        $candidates = array();

        if ( function_exists( 'wp_get_global_settings' ) ) {
            foreach ( array( 'theme', 'default' ) as $origin ) {
                $palette = wp_get_global_settings( array( 'color', 'palette', $origin ) );
                if ( ! is_array( $palette ) ) {
                    continue;
                }
                foreach ( $palette as $entry ) {
                    if ( ! is_array( $entry ) || empty( $entry['color'] ) ) {
                        continue;
                    }
                    $candidates[] = array(
                        'hex'  => (string) $entry['color'],
                        'hint' => ( isset( $entry['slug'] ) ? (string) $entry['slug'] : '' ) . ' ' . ( isset( $entry['name'] ) ? (string) $entry['name'] : '' ),
                    );
                }
            }
        }

        foreach ( array( 'accent_color', 'primary_color', 'link_color', 'highlight_color', 'button_background_color', 'theme_color', 'color_primary', 'brand_color' ) as $mod ) {
            $value = get_theme_mod( $mod );
            if ( is_string( $value ) && '' !== $value ) {
                $candidates[] = array( 'hex' => $value, 'hint' => $mod );
            }
        }

        $best       = '';
        $best_score = 12; // 阈值：饱和度太低的灰阶色不采用
        foreach ( $candidates as $candidate ) {
            $hex = self::normalize_hex_color( $candidate['hex'] );
            if ( '' === $hex ) {
                continue;
            }
            $score = self::score_accent_candidate( $hex, $candidate['hint'] );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best       = $hex;
            }
        }

        return $best;
    }

    /**
     * 主色候选打分（饱和度 + 语义标签 - 灰阶惩罚）
     */
    private static function score_accent_candidate( $hex, $hint ) {
        $rgb = self::hex_to_rgb( $hex );
        $max = max( $rgb );
        $min = min( $rgb );
        if ( $max <= 0 ) {
            return -100;
        }

        $saturation = ( $max - $min ) / $max;
        $luminance  = self::luminance( $hex );
        if ( $luminance < 0.05 || $luminance > 0.94 ) {
            return -100;
        }

        $score = $saturation * 100;
        if ( $luminance < 0.14 || $luminance > 0.82 ) {
            $score -= 20;
        }
        if ( preg_match( '/primary|accent|brand|link|highlight|button/i', (string) $hint ) ) {
            $score += 26;
        }
        if ( preg_match( '/background|surface|base|neutral|gray|grey|muted|body|text|contrast|white|black/i', (string) $hint ) ) {
            $score -= 45;
        }

        return $score;
    }

    private static function normalize_hex_color( $color ) {
        $color = trim( (string) $color );
        if ( '' === $color ) {
            return '';
        }
        if ( '#' !== substr( $color, 0, 1 ) ) {
            $color = '#' . $color;
        }
        if ( preg_match( '/^#([0-9a-f]{3})$/i', $color, $m ) ) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if ( preg_match( '/^#([0-9a-f]{6})$/i', $color ) ) {
            return strtolower( $color );
        }
        return '';
    }

    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( self::normalize_hex_color( $hex ), '#' );
        if ( 6 !== strlen( $hex ) ) {
            return array( 99, 102, 241 );
        }
        return array(
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    /**
     * 将颜色按比例混向目标色（用于生成渐变的第二停靠点）
     */
    private static function mix_hex( $hex, $toward, $ratio ) {
        $a = self::hex_to_rgb( $hex );
        $b = self::hex_to_rgb( $toward );
        $ratio = max( 0, min( 1, (float) $ratio ) );

        $out = array();
        for ( $i = 0; $i < 3; $i++ ) {
            $out[] = (int) round( $a[ $i ] * ( 1 - $ratio ) + $b[ $i ] * $ratio );
        }
        return sprintf( '#%02x%02x%02x', $out[0], $out[1], $out[2] );
    }

    /**
     * 近似相对亮度（决定按钮上的文字用黑还是白）
     */
    private static function luminance( $hex ) {
        $rgb = self::hex_to_rgb( $hex );
        $channels = array();
        foreach ( $rgb as $value ) {
            $c = $value / 255;
            $channels[] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function pick_contrast_color( $hex ) {
        return self::luminance( $hex ) > 0.5 ? '#101828' : '#ffffff';
    }

    // ─────────── 自定义 404 页面：生成 / 读取 / 保存 / 重置 ───────────

    /**
     * 读取后台提交的样式参数（空值表示沿用已保存设置）
     *
     * @return array
     */
    private static function read_page_style_args() {
        $args = array();
        // 逐项判断「是否提交」，而不是「是否非空」：
        // accent 的空字符串是合法值（= 自动跟随主题色），不能当成未提交。
        if ( isset( $_POST['layout'] ) ) {
            $args['layout'] = sanitize_key( wp_unslash( $_POST['layout'] ) );
        }
        if ( isset( $_POST['color_mode'] ) ) {
            $args['color_mode'] = sanitize_key( wp_unslash( $_POST['color_mode'] ) );
        }
        if ( isset( $_POST['accent'] ) ) {
            $args['accent'] = sanitize_text_field( wp_unslash( $_POST['accent'] ) );
        }
        return $args;
    }

    /**
     * 预览地址（管理员专用）
     */
    private static function preview_url() {
        return add_query_arg( 'yimai_gso_404_preview', '1', home_url( '/' ) );
    }

    /**
     * 生成 404 页面 HTML（后台文本框内容 + iframe 预览文档）
     */
    public static function ajax_generate_404_page() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $ctx      = self::build_404_context( array_merge( self::read_page_style_args(), array( 'is_preview' => true ) ) );
        $fragment = self::template_fragment( $ctx, true );

        wp_send_json_success( array(
            'html'          => $fragment,
            'document'      => self::standalone_document( self::resolve_fragment_tokens( $fragment, $ctx ), $ctx ),
            'dynamic'       => self::fragment_dynamic_parts( $ctx ),
            'layout'        => $ctx['layout'],
            'color_mode'    => $ctx['color_mode'],
            'accent'        => $ctx['accent'],
            'accent_source' => $ctx['accent_source'],
            'accent_label'  => $ctx['accent_label'],
            'preview_url'   => self::preview_url(),
        ) );
    }

    /**
     * 读取已保存的自定义 404 页面（没有则返回空，由前端决定是否生成默认模板）
     */
    public static function ajax_get_page() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $ctx = self::build_404_context( array( 'is_preview' => true ) );

        wp_send_json_success( array(
            'html'           => (string) YIMAI_GSO_Settings::get( 'optimize_404_custom_page', '' ),
            'dynamic'        => self::fragment_dynamic_parts( $ctx ),
            'layout'         => $ctx['layout'],
            'color_mode'     => $ctx['color_mode'],
            'accent'         => $ctx['accent'],
            'accent_source'  => $ctx['accent_source'],
            'accent_label'   => $ctx['accent_label'],
            'custom_enabled' => absint( YIMAI_GSO_Settings::get( 'optimize_404_custom_enabled', 0 ) ),
            'preview_url'    => self::preview_url(),
        ) );
    }

    /**
     * 写入自定义 404 页面 HTML
     *
     * 这段 HTML 通常十几 KB，不必随 alloptions 在每次请求里加载，
     * 因此新建时 autoload=no；WP 6.4+ 还会把已存在的老 option 一并改为不自动加载。
     */
    private static function set_custom_page_option( $html ) {
        $option_name = 'yimai_gso_optimize_404_custom_page';
        update_option( $option_name, $html, false );
        YIMAI_GSO_Settings::set( 'optimize_404_custom_page', $html ); // 同步设置类的内存缓存
        if ( function_exists( 'wp_set_option_autoload' ) ) {
            wp_set_option_autoload( $option_name, false );
        }
    }

    /**
     * 保存后台文本框内容为自定义 404 页面，并立即启用
     */
    public static function ajax_save_page() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }

        $html = self::sanitize_page_markup( wp_unslash( $_POST['html'] ?? '' ) );
        if ( '' === $html ) {
            wp_send_json_error( array( 'message' => '内容是空的：请先生成模板再保存。' ) );
        }

        $layout = sanitize_key( wp_unslash( $_POST['layout'] ?? 'theme' ) );
        $layout = in_array( $layout, array( 'theme', 'standalone' ), true ) ? $layout : 'theme';

        $mode = sanitize_key( wp_unslash( $_POST['color_mode'] ?? 'auto' ) );
        $mode = in_array( $mode, array( 'auto', 'light', 'dark' ), true ) ? $mode : 'auto';

        $accent_raw = sanitize_text_field( wp_unslash( $_POST['accent'] ?? '' ) );
        $accent     = ( '' === $accent_raw || 'theme' === $accent_raw ) ? '' : self::normalize_hex_color( $accent_raw );

        // 让落库的片段根节点 class 与本次选择的骨架/配色一致（改选项后不必重新生成）
        $html = self::normalize_fragment_root( $html, array( 'layout' => $layout, 'color_mode' => $mode ) );

        self::set_custom_page_option( $html );
        YIMAI_GSO_Settings::set( 'optimize_404_layout', $layout );
        YIMAI_GSO_Settings::set( 'optimize_404_color_mode', $mode );
        YIMAI_GSO_Settings::set( 'optimize_404_accent', $accent );
        YIMAI_GSO_Settings::set( 'optimize_404_custom_enabled', 1 );

        wp_send_json_success( array(
            'message'     => '已保存并启用，前台 404 页面立即生效。',
            'html'        => $html,
            'dynamic'     => self::fragment_dynamic_parts( self::build_404_context( array( 'is_preview' => true ) ) ),
            'bytes'       => strlen( $html ),
            'preview_url' => self::preview_url(),
        ) );
    }

    /**
     * 清空自定义页面，回到插件默认模板
     */
    public static function ajax_reset_page() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }

        self::set_custom_page_option( '' );
        YIMAI_GSO_Settings::set( 'optimize_404_layout', 'theme' );
        YIMAI_GSO_Settings::set( 'optimize_404_color_mode', 'auto' );
        YIMAI_GSO_Settings::set( 'optimize_404_accent', '' );

        $ctx      = self::build_404_context( array( 'is_preview' => true ) );
        $fragment = self::template_fragment( $ctx, true );

        wp_send_json_success( array(
            'message'     => '已恢复默认模板，前台立即生效。',
            'html'        => $fragment,
            'document'    => self::standalone_document( self::resolve_fragment_tokens( $fragment, $ctx ), $ctx ),
            'dynamic'     => self::fragment_dynamic_parts( $ctx ),
            'layout'      => $ctx['layout'],
            'color_mode'  => $ctx['color_mode'],
            'accent'      => $ctx['accent'],
            'preview_url' => self::preview_url(),
        ) );
    }

    /**
     * 清洗后台提交的 404 页面 HTML
     *
     * 只允许排版与样式，拦掉可执行内容（脚本、内联事件、iframe/object、meta refresh、
     * javascript: 协议）。保存入口仅限 manage_options，因此不做标签白名单，
     * 以免把 <style>、data-* 等样式相关标记一并削掉。
     *
     * @param string $html
     * @return string
     */
    private static function sanitize_page_markup( $html ) {
        $html = trim( (string) $html );
        if ( '' === $html ) {
            return '';
        }
        if ( strlen( $html ) > 200000 ) {
            $html = substr( $html, 0, 200000 );
        }

        $html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
        $html = preg_replace( '#<script\b[^>]*/?>#is', '', $html );
        $html = preg_replace( '#<(iframe|object|embed|applet|base)\b[^>]*>.*?</\1>#is', '', $html );
        $html = preg_replace( '#<(iframe|object|embed|applet|base)\b[^>]*/?>#is', '', $html );
        $html = preg_replace( '#<meta\b[^>]*http-equiv\s*=\s*(["\']?)refresh\1[^>]*>#is', '', $html );
        $html = preg_replace( '#\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#is', '', $html );
        // 链接/表单目标里的 javascript: 协议：带引号与不带引号两种写法分别处理
        $html = preg_replace( '#(href|src|action|formaction)\s*=\s*(["\'])\s*javascript:[^"\']*\2#is', '$1=$2#$2', $html );
        $html = preg_replace( '#(href|src|action|formaction)\s*=\s*javascript:[^\s>]*#is', '$1="#"', $html );

        return trim( (string) $html );
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

        // ★ 修复（T4）：custom_page 只在请求显式携带该字段时更新。
        //    后台「设置」子面板的保存逻辑从不发送 custom_page 字段，
        //    原先 `$_POST['custom_page'] ?? ''` 会把已保存的自定义 404 HTML
        //    每次点「保存设置」都静默清空（数据丢失）。渲染端会回退到内置默认模板，
        //    所以线上不易察觉，但一旦用户通过其他入口保存过自定义 HTML 就会被抹掉。
        if ( isset( $_POST['custom_page'] ) ) {
            YIMAI_GSO_Settings::set( 'optimize_404_custom_page', wp_kses_post( wp_unslash( $_POST['custom_page'] ) ) );
        }

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
