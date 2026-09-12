<?php
/**
 * 批量SEO元数据优化器
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_UZSV {

    const LOG_OPTION = 'wpaip_seo_meta_logs';
    const MAX_LOGS   = 100;

    /** @var string 异步SEO元数据队列 option 键名 */
    const SEO_META_QUEUE_OPTION = 'wpaip_seo_meta_queue';
    /** @var string 异步处理 cron 钩子名 */
    const SEO_META_QUEUE_CRON   = 'wpaip_process_seo_meta_queue';
    /** @var int 单次最多处理篇数 */
    const BATCH_SIZE = 3;
    /** @var int 队列最大容量 */
    const MAX_QUEUE_SIZE = 50;
    /** @var int 防重复窗口（秒），同一文章 5 分钟内不重复入队 */
    const DEDUP_WINDOW = 300;

    /** @var self|null 单例实例 */
    private static $instance = null;

    /** @var array 防重入：正在处理的文章ID */
    private static $processing_posts = array();

    /**
     * 获取单例实例（注册 transition_post_status + cron 钩子）
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造：注册发布后自动 SEO 元数据钩子（优先级15，在 ALT(10) 之后、SEO推送(20) 之前）
     * 以及异步队列处理钩子
     */
    private function __construct() {
        add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 15, 3 );
        add_action( self::SEO_META_QUEUE_CRON, array( $this, 'process_queue' ) );
    }

    // ==================== 发布后自动触发 ====================

    /**
     * transition_post_status 钩子回调：文章首次发布时自动入队 SEO 元数据生成
     *
     * @param string  $new_status 新状态
     * @param string  $old_status 旧状态
     * @param WP_Post $post       文章对象
     */
    public function handle_transition_post_status( $new_status, $old_status, $post ) {
        // 预览请求跳过
        if ( function_exists( 'wpaip_is_preview_request' ) && wpaip_is_preview_request() ) {
            return;
        }

        // 功能开关检查
        if ( ! self::is_auto_seo_enabled() ) {
            return;
        }

        // 权限检查：需要 batch_seo 功能权限
        if ( ! true || ! true ) {
            return;
        }

        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }

        // 仅处理插件支持的内容类型（默认：post + page）
        if ( ! WPAIP_B7PQ::is_supported( $post->post_type ) ) {
            return;
        }

        // 仅在首次发布时触发（old_status 不是 publish，new_status 是 publish）
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        // 增强B：分类白名单过滤（仅对支持分类的内容类型生效）
        if ( is_object_in_taxonomy( $post->post_type, 'category' ) && ! self::is_category_whitelisted( $post->ID ) ) {
            return;
        }

        // 增强A：跳过已有手动 SEO 的文章
        if ( self::should_skip_manual_seo() && self::has_manual_seo( $post->ID ) ) {
            return;
        }

        // 防重入：正在处理中
        if ( isset( self::$processing_posts[ $post->ID ] ) ) {
            return;
        }

        // 防重复：5分钟内已处理过
        $last_processed = get_post_meta( $post->ID, '_wpaip_seo_auto_processed', true );
        if ( $last_processed && ( time() - absint( $last_processed ) ) < self::DEDUP_WINDOW ) {
            return;
        }

        // 入队异步处理（毫秒级返回）
        $this->add_to_queue( $post->ID );
    }

    // ==================== 异步队列管理 ====================

    /**
     * 将文章加入 SEO 元数据队列（毫秒级返回）
     *
     * @param int $post_id 文章ID
     */
    private function add_to_queue( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        $queue = get_option( self::SEO_META_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }

        // 队列容量保护
        if ( count( $queue ) >= self::MAX_QUEUE_SIZE ) {
            return;
        }

        // 去重
        if ( in_array( $post_id, $queue, true ) ) {
            return;
        }

        $queue[] = $post_id;
        update_option( self::SEO_META_QUEUE_OPTION, $queue, false );

        // 安排异步处理（60秒后执行，避开发布高峰期）
        if ( ! wp_next_scheduled( self::SEO_META_QUEUE_CRON ) ) {
            wp_schedule_single_event( time() + 60, self::SEO_META_QUEUE_CRON );
        }

        // 主动触发 wp-cron.php 立即处理（防止低流量站点队列堆积）
        if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
            $last_trigger = get_transient( 'wpaip_last_cron_trigger' );
            if ( false === $last_trigger ) {
                spawn_cron();
                set_transient( 'wpaip_last_cron_trigger', time(), 1 );
            }
        }
    }

    /**
     * WP-Cron 回调：处理 SEO 元数据队列
     * 每次最多处理 BATCH_SIZE 篇（AI 调用耗时），避免单次超时
     */
    public function process_queue() {
        // Token 校验由 generate_preview() 逐篇处理，此处不重复校验

        $queue = get_option( self::SEO_META_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            delete_option( self::SEO_META_QUEUE_OPTION );
            return;
        }

        // 每次最多处理 BATCH_SIZE 篇
        $batch = array_splice( $queue, 0, self::BATCH_SIZE );

        foreach ( $batch as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id <= 0 ) {
                continue;
            }

            // 防重入标记
            if ( isset( self::$processing_posts[ $post_id ] ) ) {
                continue;
            }
            self::$processing_posts[ $post_id ] = true;

            $result = self::auto_generate_for_post( $post_id );

            unset( self::$processing_posts[ $post_id ] );

            // 标记处理时间（防重复窗口）
            update_post_meta( $post_id, '_wpaip_seo_auto_processed', time() );

            if ( is_wp_error( $result ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[WPAIP] SEO元数据自动生成失败 post_id=' . $post_id . ': ' . $result->get_error_message() );
                }
            }
        }

        // 队列还有剩余 → 60秒后继续处理
        if ( ! empty( $queue ) ) {
            update_option( self::SEO_META_QUEUE_OPTION, $queue, false );
            if ( ! wp_next_scheduled( self::SEO_META_QUEUE_CRON ) ) {
                wp_schedule_single_event( time() + 60, self::SEO_META_QUEUE_CRON );
            }
        } else {
            delete_option( self::SEO_META_QUEUE_OPTION );
        }
    }

    /**
     * 对单篇文章自动生成并写入 SEO 元数据
     * 包含重试逻辑：失败后自动重试1次
     *
     * @param int $post_id
     * @return array|WP_Error
     */
    private static function auto_generate_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return new WP_Error( 'invalid_post', '文章不存在或未发布' );
        }

        // Cron 环境下设置超时和内存限制（AJAX 路径在 ajax_generate_preview() 中设置）
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 180 );
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        // 尝试生成预览（首次）
        $preview = self::generate_preview( $post_id );
        if ( is_wp_error( $preview ) ) {
            // 重试1次
            sleep( 2 );
            $preview = self::generate_preview( $post_id );
            if ( is_wp_error( $preview ) ) {
                // 两次都失败，记录错误日志
                self::append_log( array(
                    'time'       => current_time( 'mysql' ),
                    'post_id'    => $post_id,
                    'post_title' => $post->post_title,
                    'status'     => 'error',
                    'message'    => '[自动] ' . $preview->get_error_message(),
                    'operator'   => 'auto',
                ) );
                return $preview;
            }
        }

        // 应用预览结果
        $data = array(
            'seo_title'       => $preview['suggested']['title'] ?? '',
            'seo_description' => $preview['suggested']['description'] ?? '',
            'seo_keywords'    => $preview['suggested']['keywords'] ?? '',
        );

        $result = self::apply_preview( $post_id, $data );
        if ( is_wp_error( $result ) ) {
            self::append_log( array(
                'time'       => current_time( 'mysql' ),
                'post_id'    => $post_id,
                'post_title' => $post->post_title,
                'status'     => 'error',
                'message'    => '[自动] 写回失败: ' . $result->get_error_message(),
                'operator'   => 'auto',
                'seo_title'  => $data['seo_title'] ?? '',
                'seo_desc'   => $data['seo_description'] ?? '',
            ) );
            return $result;
        }

        // 日志标记为自动生成（校验 post_id 确保修改的是正确的条目）
        $logs = get_option( self::LOG_OPTION, array() );
        if ( is_array( $logs ) && ! empty( $logs ) && absint( $logs[0]['post_id'] ?? 0 ) === $post_id ) {
            $logs[0]['operator'] = 'auto';
            $logs[0]['message']  = '[自动] ' . $logs[0]['message'];
            update_option( self::LOG_OPTION, $logs, false );
        }

        return $result;
    }

    // ==================== 增强A：手动SEO检测 ====================

    /**
     * 检测文章是否已有手动设置的 SEO 字段
     * 判断依据：三套 SEO 插件中任一有非空 title 或 description 即视为手动设置过
     *
     * @param int $post_id
     * @return bool
     */
    private static function has_manual_seo( $post_id ) {
        $snapshot = self::get_post_seo_snapshot( $post_id );

        // title 或 description 任一有值即认为手动设置过
        if ( ! empty( $snapshot['title'] ) || ! empty( $snapshot['description'] ) ) {
            return true;
        }

        return false;
    }

    /**
     * 是否跳过已有手动SEO的文章
     *
     * @return bool
     */
    private static function should_skip_manual_seo() {
        return (bool) WPAIP_9XCU::get( 'auto_seo_meta_skip_manual', 1 );
    }

    // ==================== 增强B：分类白名单 ====================

    /**
     * 检查文章是否在分类白名单内
     * 如果白名单为空，则表示不限制（所有分类都触发）
     *
     * @param int $post_id
     * @return bool
     */
    private static function is_category_whitelisted( $post_id ) {
        $categories_setting = WPAIP_9XCU::get( 'auto_seo_meta_categories', '' );
        $category_ids       = array_filter( array_map( 'absint', explode( ',', $categories_setting ) ) );

        // 白名单为空 → 不限制，全部通过
        if ( empty( $category_ids ) ) {
            return true;
        }

        $post_categories = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
        if ( empty( $post_categories ) ) {
            return false;
        }

        // 文章分类与白名单有交集即通过
        return ! empty( array_intersect( $post_categories, $category_ids ) );
    }

    // ==================== 设置读取 ====================

    /**
     * 功能开关是否开启
     *
     * @return bool
     */
    private static function is_auto_seo_enabled() {
        return (bool) WPAIP_9XCU::get( 'auto_seo_meta_enabled', 0 );
    }

    // ==================== 原有公开方法（保持不变） ====================

    public static function ajax_scan_posts() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $items = self::scan_posts( array(
            'search'       => sanitize_text_field( $_POST['keyword'] ?? '' ),
            'post_status'  => sanitize_text_field( $_POST['post_status'] ?? 'publish' ),
            'limit'        => max( 1, min( 100, absint( $_POST['limit'] ?? 20 ) ) ),
            'only_missing' => ( $_POST['only_missing'] ?? '1' ) === '1',
        ) );

        wp_send_json_success( array(
            'items' => $items,
            'total' => count( $items ),
        ) );
    }

    public static function ajax_generate_preview() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无权处理该文章' ) );
        }

        // 防止超时导致非 JSON 响应
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 180 );
        }
        ignore_user_abort( true );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        try {
            $result = self::generate_preview( $post_id );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            wp_send_json_success( $result );
        } catch ( Throwable $e ) {
            wp_send_json_error( array(
                'message' => '预览生成异常：' . $e->getMessage(),
                'debug'   => WP_DEBUG ? $e->getTraceAsString() : '',
            ) );
        }
    }

    public static function ajax_apply_preview() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无权修改该文章' ) );
        }

        $result = self::apply_preview( $post_id, array(
            'seo_title'       => sanitize_text_field( wp_unslash( $_POST['seo_title'] ?? '' ) ),
            'seo_description' => sanitize_textarea_field( wp_unslash( $_POST['seo_description'] ?? '' ) ),
            'seo_keywords'    => sanitize_text_field( wp_unslash( $_POST['seo_keywords'] ?? '' ) ),
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 获取批量SEO统计（用于页面局部刷新）
     */
    public static function ajax_get_stats() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        wp_send_json_success( self::get_summary_stats() );
    }

    public static function scan_posts( $args = array() ) {
        $search       = sanitize_text_field( $args['search'] ?? '' );
        $post_status  = sanitize_text_field( $args['post_status'] ?? 'publish' );
        $limit        = max( 1, min( 100, absint( $args['limit'] ?? 20 ) ) );
        $only_missing = ! empty( $args['only_missing'] );

        $query = new WP_Query( array(
            'post_type'      => WPAIP_B7PQ::get_supported(),
            'post_status'    => self::normalize_status_arg( $post_status ),
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            's'              => $search,
            'no_found_rows'  => true,
        ) );

        $items = array();
        foreach ( $query->posts as $post ) {
            if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                continue;
            }

            $current = self::get_post_seo_snapshot( $post->ID );
            if ( $only_missing && self::is_snapshot_complete( $current ) ) {
                continue;
            }

            $items[] = array(
                'id'          => $post->ID,
                'title'       => $post->post_title,
                'status'      => $post->post_status,
                'status_text' => self::get_post_status_label( $post->post_status ),
                'date'        => $post->post_date,
                'edit_url'    => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
                'view_url'    => get_permalink( $post->ID ),
                'current'     => $current,
            );
        }

        return $items;
    }

    public static function generate_preview( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! WPAIP_B7PQ::is_supported( $post->post_type ) ) {
            return new WP_Error( 'invalid_post', '文章不存在或类型不支持' );
        }

        $current = self::get_post_seo_snapshot( $post_id );
        $summary = self::build_post_summary( $post );
        if ( '' === $summary ) {
            return new WP_Error( 'empty_post', '文章内容过短，无法生成 SEO 预览' );
        }

        require_once WPAIP_PLUGIN_DIR . 'includes/class-ai-generator.php';
        $generator  = new WPAIP_CGGE();

        // 产品专用 Prompt
        if ( 'product' === $post->post_type ) {
            $sys_prompt = "你是一位资深电商SEO编辑，擅长为WooCommerce产品页面生成适合搜索引擎与点击率优化的元数据。\n" .
                "请严格返回 JSON，不要返回任何解释或 Markdown。\n" .
                "JSON 格式如下：\n" .
                "{\n" .
                "  \"seo_title\": \"SEO标题，建议包含产品名+核心卖点+品牌，18-32字\",\n" .
                "  \"seo_description\": \"SEO描述，建议70-140字，突出产品特色和使用场景\",\n" .
                "  \"seo_keywords\": \"关键词1,关键词2,关键词3,关键词4\"\n" .
                "}\n" .
                "要求：1. 关键词自然精准，含产品词和长尾词；2. seo_title 要有吸引力，含促销/卖点词；3. 描述中自然融入价格区间或产品场景。";
        } else {
            $sys_prompt = "你是一位资深中文SEO编辑，擅长为WordPress文章生成适合搜索引擎与点击率优化的元数据。\n" .
                "请严格返回 JSON，不要返回任何解释或 Markdown。\n" .
                "JSON 格式如下：\n" .
                "{\n" .
                "  \"seo_title\": \"SEO标题，建议18-32字，保留核心关键词，适合Yoast/Rank Math\",\n" .
                "  \"seo_description\": \"SEO描述，建议70-140字，概括价值点并引导点击\",\n" .
                "  \"seo_keywords\": \"关键词1,关键词2,关键词3,关键词4\"\n" .
                "}\n" .
                "要求：1. 关键词自然，禁止堆砌；2. 不要虚假承诺；3. 优先贴近文章真实内容；4. keywords 只返回逗号分隔文本。";
        }

        $user_message = "文章标题：{$post->post_title}\n" .
            "当前SEO标题：" . ( $current['title'] ?: '（暂无）' ) . "\n" .
            "当前SEO描述：" . ( $current['description'] ?: '（暂无）' ) . "\n" .
            "当前SEO关键词：" . ( $current['keywords'] ?: '（暂无）' ) . "\n\n" .
            "请基于以下文章摘要生成更适合批量写回的 SEO 元数据：\n\n{$summary}";

        $response = $generator->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user', 'content' => $user_message ),
        ), 1400 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $raw     = $response['choices'][0]['message']['content'] ?? '';
        $decoded = self::decode_ai_json( $raw );
        if ( is_wp_error( $decoded ) ) {
            return $decoded;
        }

        $suggested = self::normalize_suggested_fields( $decoded, $post );

        return array(
            'post_id'    => $post->ID,
            'post_title' => $post->post_title,
            'edit_url'   => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
            'view_url'   => get_permalink( $post->ID ),
            'current'    => $current,
            'suggested'  => $suggested,
        );
    }

    public static function apply_preview( $post_id, $data ) {
        $post = get_post( $post_id );
        if ( ! $post || ! WPAIP_B7PQ::is_supported( $post->post_type ) ) {
            return new WP_Error( 'invalid_post', '文章不存在或类型不支持' );
        }

        $normalized = self::normalize_suggested_fields( $data, $post );
        if ( '' === $normalized['description'] && '' === $normalized['keywords'] && '' === $normalized['title'] ) {
            return new WP_Error( 'empty_seo', 'SEO 预览内容为空，无法写回' );
        }

        $before = self::get_post_seo_snapshot( $post_id );
        WPAIP_2I0E::write_seo_fields( $post_id, $normalized['keywords'], $normalized['description'], $normalized['title'] );
        $after = self::get_post_seo_snapshot( $post_id );

        self::append_log( array(
            'time'         => current_time( 'mysql' ),
            'post_id'      => $post_id,
            'post_title'   => $post->post_title,
            'status'       => 'success',
            'message'      => '已同步写入子比 / Yoast / Rank Math SEO 字段',
            'operator'     => wp_get_current_user() ? wp_get_current_user()->user_login : '',
            'before'       => $before,
            'after'        => $after,
            'seo_title'    => $normalized['title'],
            'seo_desc'     => $normalized['description'],
            'seo_keywords' => $normalized['keywords'],
        ) );

        return array(
            'post_id'      => $post_id,
            'post_title'   => $post->post_title,
            'edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'view_url'     => get_permalink( $post_id ),
            'current'      => $after,
            'message'      => '写回完成',
        );
    }

    public static function get_post_seo_snapshot( $post_id ) {
        $yoast = array(
            'title'       => trim( (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ) ),
            'description' => trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ),
            'keywords'    => trim( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ),
        );

        $rank_math = array(
            'title'       => trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) ),
            'description' => trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) ),
            'keywords'    => trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ),
        );

        $zib = array(
            'title'       => trim( (string) get_post_meta( $post_id, 'title', true ) ),
            'description' => trim( (string) get_post_meta( $post_id, 'description', true ) ),
            'keywords'    => trim( (string) get_post_meta( $post_id, 'keywords', true ) ),
        );

        $snapshot = array(
            'title'        => self::pick_first_value( array( $yoast['title'], $rank_math['title'], $zib['title'] ) ),
            'description'  => self::pick_first_value( array( $yoast['description'], $rank_math['description'], $zib['description'] ) ),
            'keywords'     => self::pick_first_value( array( $yoast['keywords'], $rank_math['keywords'], $zib['keywords'] ) ),
            'coverage'     => 0,
            'coverage_text'=> '0/3',
            'sources'      => array(
                'yoast'     => $yoast,
                'rank_math' => $rank_math,
                'zib'       => $zib,
            ),
        );

        foreach ( array( 'title', 'description', 'keywords' ) as $field ) {
            if ( '' !== trim( (string) $snapshot[ $field ] ) ) {
                $snapshot['coverage']++;
            }
        }

        $snapshot['coverage_text'] = $snapshot['coverage'] . '/3';

        return $snapshot;
    }

    public static function get_recent_logs( $limit = 20 ) {
        $logs = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }

        return array_slice( $logs, 0, max( 1, absint( $limit ) ) );
    }

    public static function get_summary_stats() {
        $logs  = self::get_recent_logs( self::MAX_LOGS );
        $stats = array(
            'total'    => count( $logs ),
            'success'  => 0,
            'error'    => 0,
            'last_run' => '',
        );

        foreach ( $logs as $log ) {
            $status = sanitize_text_field( $log['status'] ?? 'success' );
            if ( 'success' === $status ) {
                $stats['success']++;
            } else {
                $stats['error']++;
            }

            if ( '' === $stats['last_run'] && ! empty( $log['time'] ) ) {
                $stats['last_run'] = sanitize_text_field( $log['time'] );
            }
        }

        return $stats;
    }

    private static function append_log( $entry ) {
        $logs = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        array_unshift( $logs, array(
            'time'         => sanitize_text_field( $entry['time'] ?? current_time( 'mysql' ) ),
            'post_id'      => absint( $entry['post_id'] ?? 0 ),
            'post_title'   => sanitize_text_field( $entry['post_title'] ?? '' ),
            'status'       => sanitize_text_field( $entry['status'] ?? 'success' ),
            'message'      => sanitize_text_field( $entry['message'] ?? '' ),
            'operator'     => sanitize_text_field( $entry['operator'] ?? '' ),
            'seo_title'    => sanitize_text_field( $entry['seo_title'] ?? '' ),
            'seo_desc'     => sanitize_textarea_field( $entry['seo_desc'] ?? '' ),
            'seo_keywords' => sanitize_text_field( $entry['seo_keywords'] ?? '' ),
            'before'       => is_array( $entry['before'] ?? null ) ? $entry['before'] : array(),
            'after'        => is_array( $entry['after'] ?? null ) ? $entry['after'] : array(),
        ) );

        update_option( self::LOG_OPTION, array_slice( $logs, 0, self::MAX_LOGS ), false );
        // 强制清除对象缓存（若站点使用 Redis/Memcached 持久化缓存，确保统计立即刷新）
        wp_cache_delete( self::LOG_OPTION, 'options' );
    }

    private static function normalize_status_arg( $status ) {
        switch ( $status ) {
            case 'draft':
                return array( 'draft' );
            case 'future':
                return array( 'future' );
            case 'pending':
                return array( 'pending' );
            case 'all':
                return array( 'publish', 'draft', 'future', 'pending', 'private' );
            case 'publish':
            default:
                return array( 'publish' );
        }
    }

    private static function get_post_status_label( $status ) {
        $map = array(
            'publish' => '已发布',
            'draft'   => '草稿',
            'future'  => '定时发布',
            'pending' => '待审核',
            'private' => '私密',
        );

        return isset( $map[ $status ] ) ? $map[ $status ] : $status;
    }

    private static function is_snapshot_complete( $snapshot ) {
        return ! empty( $snapshot['title'] ) && ! empty( $snapshot['description'] ) && ! empty( $snapshot['keywords'] );
    }

    private static function pick_first_value( $values ) {
        foreach ( (array) $values as $value ) {
            $value = trim( (string) $value );
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }

    private static function build_post_summary( $post ) {
        // WooCommerce 产品：使用产品数据构建摘要
        if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
            return self::build_product_summary( $post );
        }

        $plain_content = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
        $excerpt       = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
        $summary       = $excerpt;

        if ( '' === $summary ) {
            $summary = self::trim_text( $plain_content, 900 );
        }

        if ( '' === $summary ) {
            return '';
        }

        $current_categories = get_the_category( $post->ID );
        $category_names     = array();
        if ( ! empty( $current_categories ) ) {
            foreach ( $current_categories as $cat ) {
                $category_names[] = $cat->name;
            }
        }

        $tag_names = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

        return "分类：" . ( ! empty( $category_names ) ? implode( '、', $category_names ) : '未分类' ) . "\n" .
            "标签：" . ( ! empty( $tag_names ) ? implode( '、', $tag_names ) : '暂无' ) . "\n" .
            "摘要：{$summary}\n\n" .
            "正文节选：" . self::trim_text( $plain_content, 1500 );
    }

    /**
     * 构建 WooCommerce 产品摘要（#44 产品SEO）
     */
    private static function build_product_summary( $post ) {
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            $plain = trim( wp_strip_all_tags( (string) $post->post_content ) );
            return "产品：" . $post->post_title . "\n描述：" . self::trim_text( $plain, 900 );
        }

        $lines = array();

        $lines[] = "产品名称：" . $product->get_name();

        $price = $product->get_price();
        if ( $price ) {
            $lines[] = "价格：" . wc_price( $price );
        }

        $sku = $product->get_sku();
        if ( $sku ) {
            $lines[] = "SKU：" . $sku;
        }

        // 产品分类
        $prod_cats = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
        $lines[] = "产品分类：" . ( ! empty( $prod_cats ) ? implode( '、', $prod_cats ) : '未分类' );

        // 产品标签
        $prod_tags = wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) );
        if ( ! empty( $prod_tags ) ) {
            $lines[] = "产品标签：" . implode( '、', $prod_tags );
        }

        // 短描述
        $short_desc = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
        if ( $short_desc ) {
            $lines[] = "简短描述：" . $short_desc;
        }

        // 属性
        $attributes = $product->get_attributes();
        if ( ! empty( $attributes ) ) {
            $attr_lines = array();
            foreach ( $attributes as $attr ) {
                if ( $attr->get_visible() ) {
                    $attr_lines[] = $attr->get_name() . '：' . implode( ', ', $attr->get_options() );
                }
            }
            if ( ! empty( $attr_lines ) ) {
                $lines[] = "产品属性：" . implode( ' | ', $attr_lines );
            }
        }

        // 正文节选
        $plain_content = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
        $lines[] = "正文节选：" . self::trim_text( $plain_content, 1000 );

        return implode( "\n", $lines );
    }

    private static function decode_ai_json( $raw ) {
        $raw = trim( (string) $raw );
        $raw = preg_replace( '/^```json\s*/i', '', $raw );
        $raw = preg_replace( '/^```\s*/i', '', $raw );
        $raw = preg_replace( '/\s*```$/', '', $raw );

        $data = json_decode( $raw, true );
        if ( is_array( $data ) ) {
            return $data;
        }

        if ( preg_match( '/\{.*\}/s', $raw, $matches ) ) {
            $data = json_decode( $matches[0], true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }

        return new WP_Error( 'invalid_ai_response', 'AI 返回结果无法解析为 SEO JSON 结构' );
    }

    private static function normalize_suggested_fields( $data, $post ) {
        $title       = sanitize_text_field( $data['seo_title'] ?? $data['title'] ?? '' );
        $description = sanitize_textarea_field( $data['seo_description'] ?? $data['description'] ?? '' );
        $keywords    = sanitize_text_field( $data['seo_keywords'] ?? $data['keywords'] ?? '' );

        if ( '' === $title ) {
            $title = sanitize_text_field( $post->post_title );
        }
        $title = self::trim_text( preg_replace( '/\s+/u', ' ', $title ), 60 );

        if ( '' === $description ) {
            $description = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
        }
        if ( '' === $description ) {
            $description = self::trim_text( wp_strip_all_tags( (string) $post->post_content ), 140 );
        }
        $description = self::trim_text( preg_replace( '/\s+/u', ' ', $description ), 180 );

        $keywords = self::normalize_keywords( $keywords );
        if ( '' === $keywords ) {
            $keywords = self::normalize_keywords( sanitize_text_field( $post->post_title ) );
        }

        return array(
            'title'       => $title,
            'description' => $description,
            'keywords'    => $keywords,
        );
    }

    private static function normalize_keywords( $keywords ) {
        $keywords = str_replace( array( '，', '、', '；', ';', '|', "\r", "\n" ), ',', (string) $keywords );
        $parts    = array_map( 'trim', explode( ',', $keywords ) );
        $clean    = array();

        foreach ( $parts as $part ) {
            if ( '' !== $part ) {
                $clean[] = self::trim_text( sanitize_text_field( $part ), 30 );
            }
        }

        $clean = array_values( array_unique( $clean ) );
        if ( count( $clean ) > 6 ) {
            $clean = array_slice( $clean, 0, 6 );
        }

        return implode( ',', $clean );
    }

    private static function trim_text( $text, $max_length ) {
        $text = trim( (string) $text );
        if ( '' === $text ) {
            return '';
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) <= $max_length ) {
                return $text;
            }

            return trim( mb_substr( $text, 0, $max_length ) );
        }

        if ( strlen( $text ) <= $max_length ) {
            return $text;
        }

        return trim( substr( $text, 0, $max_length ) );
    }
}
