<?php
/**
 * 智能内链处理器
 * 基于关键词匹配 + AI判断，自动在正文插入站内已有文章链接
 * 改为"发布后自动执行"模式，与内容检测器架构一致
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Internal_Link {

    /** @var self|null 单例实例 */
    private static $instance = null;

    /** @var array 防重入：正在处理的文章ID */
    private static $processing_posts = array();

    /** @var string 内链队列 option 键名 */
    const LINK_QUEUE_OPTION = 'yimai_gso_internal_link_queue';
    /** @var string 异步处理 cron 钩子名 */
    const LINK_QUEUE_CRON   = 'yimai_gso_process_internal_link_queue';

    /** @var string 批量重建队列 option 键名 */
    const REBUILD_QUEUE_OPTION = 'yimai_gso_rebuild_link_queue';
    /** @var string 批量重建 cron 钩子名 */
    const REBUILD_QUEUE_CRON   = 'yimai_gso_process_rebuild_link_queue';

    /** @var string 失效链接巡检结果 option 键名 */
    const BROKEN_LINK_AUDIT_OPTION = 'yimai_gso_broken_link_audit_result';
    /** @var string 失效链接巡检队列 option 键名 */
    const BROKEN_LINK_AUDIT_QUEUE_OPTION = 'yimai_gso_broken_link_audit_queue';
    /** @var string 失效链接巡检 cron 钩子名 */
    const BROKEN_LINK_AUDIT_CRON = 'yimai_gso_process_broken_link_audit';

    /**
     * 获取单例实例（注册 transition_post_status 钩子）
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造：注册发布后自动内链钩子（优先级25，在内容检测器20之后）
     * 以及异步队列处理钩子
     */
    private function __construct() {
        add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 25, 3 );
        add_action( self::LINK_QUEUE_CRON, array( $this, 'process_link_queue' ) );
        add_action( self::REBUILD_QUEUE_CRON, array( $this, 'process_rebuild_queue' ) );
        add_action( self::BROKEN_LINK_AUDIT_CRON, array( $this, 'process_broken_link_audit' ) );
    }

    /**
     * transition_post_status 钩子回调：文章首次发布时自动执行内链
     *
     * @param string  $new_status 新状态
     * @param string  $old_status 旧状态
     * @param WP_Post $post       文章对象
     */
    /**
     * transition_post_status 钩子回调：文章首次发布时自动执行内链
     * 【异步化】不再同步处理，改为入队由 WP-Cron 异步执行
     *
     * @param string  $new_status 新状态
     * @param string  $old_status 旧状态
     * @param WP_Post $post       文章对象
     */
    public function handle_transition_post_status( $new_status, $old_status, $post ) {
        // 预览请求跳过（防止内存溢出）
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        // 功能开关检查
        if ( ! YIMAI_GSO_Settings::get( 'internal_link_enabled', 0 ) ) {
            return;
        }

        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }

        // 仅处理插件支持的内容类型（默认：post + page，可在 Post Type Helper 配置扩展）
        if ( ! YIMAI_GSO_Post_Type_Helper::is_supported( $post->post_type ) ) {
            return;
        }

        // 只在首次发布时触发（old_status 不是 publish，new_status 是 publish）
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        // 入队异步处理（不再同步阻塞发布流程）
        $this->add_to_link_queue( $post->ID );
    }

    // ==================== 异步内链队列 ====================

    /**
     * 将文章加入内链队列（transition_post_status 钩子中调用，毫秒级返回）
     *
     * @param int $post_id 文章ID
     */
    private function add_to_link_queue( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        $queue = get_option( self::LINK_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }

        // 去重
        if ( in_array( $post_id, $queue, true ) ) {
            return;
        }

        $queue[] = $post_id;
        update_option( self::LINK_QUEUE_OPTION, $queue, false );

        // 安排异步处理（60秒后执行，避开发布高峰期）
        if ( ! wp_next_scheduled( self::LINK_QUEUE_CRON ) ) {
            wp_schedule_single_event( time() + 60, self::LINK_QUEUE_CRON );
        }

        // 主动触发 wp-cron.php 立即处理（防止低流量站点队列堆积）
        // 精准模式下改用 HTTP 触发（与 autopilot 修复一致，原逻辑只处理默认模式）
        $precise_mode = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        $last_trigger = get_transient( 'yimai_gso_last_cron_trigger' );
        if ( false === $last_trigger ) {
            if ( $precise_mode ) {
                $trigger_resp = wp_remote_get( site_url( 'wp-cron.php' ), array(
                    'timeout'   => 3,
                    'blocking'  => false,
                ) );
                if ( is_wp_error( $trigger_resp ) && defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                    yimai_gso_debug_log( '[一麦GSO] InternalLink add_to_queue wp-cron.php 触发失败: ' . $trigger_resp->get_error_message() );
                }
            } elseif ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
            set_transient( 'yimai_gso_last_cron_trigger', time(), 1 );
        }
    }

    /**
     * WP-Cron 回调：处理内链队列
     * 每次最多处理 3 篇（AI 内链较耗时），避免单次超时
     */
    public function process_link_queue() {
        $queue = get_option( self::LINK_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            delete_option( self::LINK_QUEUE_OPTION );
            return;
        }

        // 每次最多处理3篇
        $batch = array_splice( $queue, 0, 3 );

        // 先保存队列（移除已取出的 batch），再处理 batch
        // 避免并发 cron 事件同时获取相同文章，导致重复 AI 调用
        if ( ! empty( $queue ) ) {
            update_option( self::LINK_QUEUE_OPTION, $queue, false );
        } else {
            delete_option( self::LINK_QUEUE_OPTION );
        }

        foreach ( $batch as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id <= 0 ) {
                continue;
            }
            self::process_published_post( $post_id );
        }

        // 队列已在上方保存，这里只调度续批，不重复写回（避免覆盖并发新增的队列项）
        if ( ! empty( $queue ) ) {
            if ( ! wp_next_scheduled( self::LINK_QUEUE_CRON ) ) {
                wp_schedule_single_event( time() + 60, self::LINK_QUEUE_CRON );
            }
        } else {
            delete_option( self::LINK_QUEUE_OPTION );
        }
    }

    /**
     * 处理已发布文章：读取内容 → 插入内链 → 写回数据库
     *
     * @param int     $post_id 文章ID
     * @param WP_Post|null $post 文章对象
     * @return array|false 处理结果或false
     */
    public static function process_published_post( $post_id, $post = null ) {
        $post = $post instanceof WP_Post ? $post : get_post( $post_id );
        if ( ! ( $post instanceof WP_Post ) || 'publish' !== $post->post_status ) {
            return false;
        }

        // 防重入
        if ( isset( self::$processing_posts[ $post_id ] ) ) {
            return false;
        }

        self::$processing_posts[ $post_id ] = true;

        $title   = $post->post_title;
        $content = $post->post_content;
        $tags    = self::get_post_tags_string( $post_id );
        $category_ids = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );

        $context = array(
            'category_ids' => array_map( 'absint', (array) $category_ids ),
            'post_status'  => 'publish',
            'source'       => 'auto_publish',
       );

        $new_content = self::insert_links( $content, $title, $tags, $post_id, $context );

        // 只有内容确实变化时才更新
        if ( $new_content !== $content ) {
            wp_update_post( array(
                'ID'           => $post_id,
                'post_content' => $new_content,
            ) );
        }

        unset( self::$processing_posts[ $post_id ] );

        return true;
    }

    /**
     * 获取文章标签的逗号分隔字符串
     *
     * @param int $post_id 文章ID
     * @return string
     */
    private static function get_post_tags_string( $post_id ) {
        $tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
        if ( is_wp_error( $tags ) || empty( $tags ) ) {
            return '';
        }
        return implode( ',', $tags );
    }

    /**
     * 为主入口：在文章正文中插入智能内链
     *
     * @param string $content    文章正文HTML
     * @param string $title      文章标题
     * @param string $tags       文章标签（逗号分隔）
     * @param int    $post_id    当前文章ID
     * @return string 插入内链后的正文
     */
    public static function insert_links( $content, $title = '', $tags = '', $post_id = 0, $context = array() ) {
        $log_context = array(
            'post_id'      => absint( $post_id ),
            'post_title'   => $title,
            'source'       => sanitize_text_field( $context['source'] ?? 'auto_publish' ),
            'post_status'  => sanitize_text_field( $context['post_status'] ?? '' ),
            'max_links'    => 0,
            'candidate_count' => 0,
            'inserted_count'  => 0,
            'status'       => 'skipped',
            'message'      => '',
            'time'         => current_time( 'mysql' ),
       );

        // 检查是否启用
        if ( ! YIMAI_GSO_Settings::get( 'internal_link_enabled', 0 ) ) {
            $log_context['message'] = '总开关未启用';
            self::append_log( $log_context );
            return $content;
        }

        $max_links = absint( YIMAI_GSO_Settings::get( 'internal_link_max', 3 ) );
        if ( $max_links < 1 ) {
            $max_links = 3;
        }
        if ( $max_links > 10 ) {
            $max_links = 10;
        }
        $log_context['max_links'] = $max_links;

        $runtime_context = array(
            'category_ids'     => array_map( 'absint', (array) ( $context['category_ids'] ?? array() ) ),
            'candidate_mode'   => self::get_setting( 'internal_link_candidate_mode', 'recent' ),
            'candidate_limit'  => max( 20, min( 200, absint( self::get_setting( 'internal_link_candidate_limit', 50 ) ) ) ),
            'excluded_categories' => self::normalize_id_list( self::get_setting( 'internal_link_excluded_category_ids', array() ) ),
            'excluded_tags'    => self::normalize_id_list( self::get_setting( 'internal_link_excluded_tag_ids', array() ) ),
            'keyword_library'  => self::get_keyword_library(),
            'anchor_policy'    => self::get_setting( 'internal_link_anchor_policy', 'natural' ),
       );

        $candidates = self::get_candidate_posts( $title, $tags, $post_id, $runtime_context );
        $log_context['candidate_count'] = count( $candidates );

        if ( empty( $candidates ) ) {
            $log_context['message'] = '未找到符合范围的候选文章';
            self::append_log( $log_context );
            return $content;
        }

        $plain_text = self::html_to_plain( $content );
        $char_count = mb_strlen( $plain_text );

        if ( $char_count < 200 ) {
            $log_context['message'] = '正文不足 200 字，跳过内链';
            self::append_log( $log_context );
            return $content;
        }

        $link_plan = self::ai_plan_links( $plain_text, $title, $candidates, $max_links, $runtime_context );
        if ( empty( $link_plan ) ) {
            $log_context['message'] = 'AI 未产出可用的内链规划';
            $log_context['candidate_count'] = count( $candidates );
            self::append_log( $log_context );
            yimai_gso_debug_log( '[一麦GSO InternalLink] 内链规划失败 | post_id=' . $post_id . ' | title=' . mb_substr($title, 0, 60) . ' | candidates=' . count($candidates) . ' | max_links=' . $max_links . ' | mode=' . $runtime_context['candidate_mode'] );
            return $content;
        }

        $apply_result = self::apply_links( $content, $link_plan );
        $content      = $apply_result['content'];

        $log_context['inserted_count'] = absint( $apply_result['inserted_count'] ?? 0 );
        $log_context['status']         = $log_context['inserted_count'] > 0 ? 'success' : 'warning';
        $log_context['message']        = $log_context['inserted_count'] > 0
            ? sprintf( '成功插入 %d 条内链', $log_context['inserted_count'] )
            : '规划成功，但正文里没有找到可替换的锚文本';
        $log_context['anchor_policy']  = self::get_anchor_strategy_label( $runtime_context['anchor_policy'] );
        $log_context['candidate_mode'] = $runtime_context['candidate_mode'];

        self::append_log( $log_context );

        return $content;
    }

    /**
     * 获取站内相关文章候选列表
     * 按分类和标签匹配，取最近发布的文章
     */
    private static function get_candidate_posts( $title, $tags, $exclude_id = 0, $context = array() ) {
        global $wpdb;

        $keywords = array();
        if ( ! empty( $title ) ) {
            $keywords[] = $title;
        }
        if ( ! empty( $tags ) ) {
            $tag_arr = array_map( 'trim', explode( ',', $tags ) );
            $keywords = array_merge( $keywords, $tag_arr );
        }
        $keywords = array_filter( array_unique( $keywords ) );
        if ( empty( $keywords ) ) {
            return array();
        }

        $candidate_mode       = sanitize_text_field( $context['candidate_mode'] ?? 'recent' );
        $candidate_limit      = max( 20, min( 200, absint( $context['candidate_limit'] ?? 50 ) ) );
        $excluded_categories  = self::normalize_id_list( $context['excluded_categories'] ?? array() );
        $excluded_tags        = self::normalize_id_list( $context['excluded_tags'] ?? array() );
        $current_category_ids = self::normalize_id_list( $context['category_ids'] ?? array() );

        $cache_key = 'yimai_gso_candidate_posts_' . md5( wp_json_encode( array(
            'mode'  => $candidate_mode,
            'limit' => $candidate_limit,
            'types' => YIMAI_GSO_Post_Type_Helper::get_supported(),
        ) ) );
        $candidates_pool = get_transient( $cache_key );

        if ( false === $candidates_pool ) {
            $post_type_condition = YIMAI_GSO_Post_Type_Helper::sql_in_clause( 'p.post_type' );
            $candidates_pool = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_date,
                        (SELECT GROUP_CONCAT(t.name SEPARATOR ',')
                         FROM {$wpdb->term_relationships} tr
                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                         WHERE tr.object_id = p.ID AND tt.taxonomy = 'post_tag') AS post_tags,
                        (SELECT GROUP_CONCAT(tt.term_id SEPARATOR ',')
                         FROM {$wpdb->term_relationships} tr
                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         WHERE tr.object_id = p.ID AND tt.taxonomy = 'category') AS category_ids,
                        (SELECT GROUP_CONCAT(tt.term_id SEPARATOR ',')
                         FROM {$wpdb->term_relationships} tr
                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         WHERE tr.object_id = p.ID AND tt.taxonomy = 'post_tag') AS tag_ids
                 FROM {$wpdb->posts} p
                 WHERE p.post_status = 'publish'
                   AND {$post_type_condition}
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                $candidate_limit
            ) );
            set_transient( $cache_key, $candidates_pool, HOUR_IN_SECONDS );
        }

        if ( $exclude_id > 0 ) {
            $candidates_pool = array_filter( $candidates_pool, function( $post ) use ( $exclude_id ) {
                return (int) $post->ID !== (int) $exclude_id;
            } );
        }

        if ( empty( $candidates_pool ) ) {
            return array();
        }

        $scored = array();
        foreach ( $candidates_pool as $post ) {
            $post_title         = $post->post_title;
            $post_tags          = $post->post_tags ?? '';
            $candidate_cat_ids  = self::normalize_id_list( $post->category_ids ?? '' );
            $candidate_tag_ids  = self::normalize_id_list( $post->tag_ids ?? '' );

            if ( ! empty( $excluded_categories ) && array_intersect( $excluded_categories, $candidate_cat_ids ) ) {
                continue;
            }

            if ( ! empty( $excluded_tags ) && array_intersect( $excluded_tags, $candidate_tag_ids ) ) {
                continue;
            }

            $same_category = ! empty( $current_category_ids ) && ! empty( array_intersect( $current_category_ids, $candidate_cat_ids ) );

            if ( 'same_category' === $candidate_mode && ! $same_category ) {
                continue;
            }

            $score = 0;
            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $post_title, $kw ) !== false ) {
                    $score += 10;
                    $score += mb_strlen( $kw );
                }

                if ( ! empty( $post_tags ) && mb_strpos( $post_tags, $kw ) !== false ) {
                    $score += 5;
                }

                if ( mb_strlen( $kw ) >= 2 ) {
                    for ( $i = 0; $i < mb_strlen( $kw ) - 1; $i++ ) {
                        $sub = mb_substr( $kw, $i, 2 );
                        if ( mb_strpos( $post_title, $sub ) !== false ) {
                            $score += 1;
                            break;
                        }
                    }
                }
            }

            if ( $same_category ) {
                $score += ( 'same_category_first' === $candidate_mode ) ? 12 : 4;
            }

            if ( $score > 0 ) {
                $scored[] = array(
                    'id'         => (int) $post->ID,
                    'title'      => $post->post_title,
                    'url'        => get_permalink( $post->ID ),
                    'tags'       => $post_tags,
                    'score'      => $score,
                    'categories' => $candidate_cat_ids,
               );
            }
        }

        usort( $scored, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        return array_slice( $scored, 0, min( 20, $candidate_limit ) );
    }

    /**
     * HTML 转纯文本（保留段落结构，去除标签）
     */
    private static function html_to_plain( $html ) {
        // 保留段落分隔
        $text = str_replace( array( '</p>', '</h2>', '</h3>', '</li>', '<br>', '<br/>' ), "\n", $html );
        $text = strip_tags( $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        return trim( $text );
    }

    /**
     * 用 AI 判断哪些位置适合插入内链
     *
     * @param string $plain_text 文章纯文本
     * @param string $title      当前文章标题
     * @param array  $candidates 候选文章列表
     * @param int    $max_links  最多插入几条内链
     * @return array 内链计划 [{keyword, url, title}, ...]
     */
    private static function ai_plan_links( $plain_text, $title, $candidates, $max_links, $context = array() ) {
        $api_key = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
        if ( empty( $api_key ) ) {
            return array();
        }

        $candidate_list = '';
        foreach ( $candidates as $i => $candidate ) {
            $candidate_list .= ( $i + 1 ) . ". 《{$candidate['title']}》 URL: {$candidate['url']}\n";
        }

        $keyword_library = array_filter( array_map( 'trim', (array) ( $context['keyword_library'] ?? array() ) ) );
        $keyword_library_text = empty( $keyword_library )
            ? '当前未设置固定关键词库。'
            : '固定关键词库（优先从这些词里挑选自然出现的锚文本）：' . implode( '、', array_slice( $keyword_library, 0, 30 ) );

        $anchor_policy_text = self::get_anchor_strategy_prompt( $context['anchor_policy'] ?? 'natural' );

        $sys_prompt = "你是一位SEO内链专家。你的任务是为文章选择合适的站内内链插入位置。\n\n" .
                      "规则：\n" .
                      "1. 只在文章正文中自然提到相关主题时插入链接，不要生硬插入\n" .
                      "2. 选择与上下文语义最相关的文章做内链\n" .
                      "3. 链接文字应该是正文中自然出现的关键词或短语（2-8个字），不要用文章标题做链接文字\n" .
                      "4. 同一个位置不要叠加多个链接\n" .
                      "5. 每个候选文章最多使用一次\n" .
                      "6. 不要在文章标题、第一个段落（首段）、HTML标签上插入链接\n" .
                      "7. 锚文本策略：{$anchor_policy_text}\n" .
                      "8. 严格返回JSON数组格式，不要返回任何其他文字\n" .
                      "9. 不要输出思考过程、推理内容、解释说明或markdown代码块，直接给出可解析的JSON数组\n\n" .
                      $keyword_library_text . "\n\n" .
                      "返回格式：\n" .
                      "[\n" .
                      "  {\"keyword\": \"要加链接的文字\", \"url\": \"文章URL\", \"target_title\": \"目标文章标题\"}\n" .
                      "]";

        $truncated = mb_substr( $plain_text, 0, 3000 );
        if ( mb_strlen( $plain_text ) > 3000 ) {
            $truncated .= '...(后文省略)';
        }

        $user_message = "当前文章标题：{$title}\n\n" .
                        "文章正文（{$max_links}条内链以内）：\n{$truncated}\n\n" .
                        "站内候选文章列表：\n{$candidate_list}\n\n" .
                        "请从候选列表中选择最多{$max_links}篇与正文内容最相关的文章，并指出在正文中哪个关键词处插入链接。";

        $candidate_count = count( $candidates );
        // -fix: 推理模型(deepseek-v4-flash等)将内部 CoT reasoning_tokens
        // 计入 max_tokens 预算。20 个候选时 reasoning 可能占用 3000+ tokens，
        // 导致 content 为空。内链 JSON 输出本身很短，因此固定最低 8192，
        // 最高 16384，并增加一次 reasoning 占满预算时的自动扩容重试。
        $dynamic_max_tokens = max( 8192, min( 16384, $candidate_count * 160 + 2048 ) );

        $generator = new YIMAI_GSO_AI_Generator();
        $max_attempts = 2;
        $attempt      = 0;
        $response     = null;

        while ( $attempt < $max_attempts ) {
            $attempt++;
            $response = $generator->call_api( array(
                array( 'role' => 'system', 'content' => $sys_prompt ),
                array( 'role' => 'user',   'content' => $user_message ),
            ), $dynamic_max_tokens );

            if ( is_wp_error( $response ) ) {
                break;
            }

            // 检测 reasoning 占满 token 预算的情况：content 为空 + finish=length + 大量 reasoning
            $tmp_finish    = $response['choices'][0]['finish_reason'] ?? '';
            $tmp_content   = $response['choices'][0]['message']['content'] ?? '';
            $tmp_reasoning = $response['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0;

            if ( '' === trim( $tmp_content ) && 'length' === $tmp_finish && $tmp_reasoning > 1000 ) {
                yimai_gso_debug_log( '[一麦GSO InternalLink] reasoning 占满预算，扩容重试 | attempt=' . $attempt . ' | old_max=' . $dynamic_max_tokens . ' | reasoning_tokens=' . $tmp_reasoning );
                $dynamic_max_tokens = min( 16384, (int) ( $dynamic_max_tokens * 1.5 ) );
                continue;
            }

            break;
        }

        if ( is_wp_error( $response ) ) {
            yimai_gso_debug_log( '[一麦GSO InternalLink] AI API 错误: ' . $response->get_error_message() );
            return array();
        }

        // 检测截断 + DeepSeek 兼容性诊断
        $finish_reason = $response['choices'][0]['finish_reason'] ?? '';
        $is_truncated  = ( 'length' === $finish_reason );

        $raw_content = $response['choices'][0]['message']['content'] ?? '';

        // -deepseek-diag: 内链失败时 dump 原始 API 响应关键字段，定位 DeepSeek 特有问题
        if ( empty( $raw_content ) || $is_truncated ) {
            $model = YIMAI_GSO_Settings::get( 'ai_model', 'unknown' );
            $usage_info = isset( $response['usage'] ) ? json_encode( $response['usage'] ) : 'N/A';
            $choice_count = count( $response['choices'] ?? array() );
            $msg_keys = isset( $response['choices'][0]['message'] ) && is_array( $response['choices'][0]['message'] )
                ? implode( ',', array_keys( $response['choices'][0]['message'] ) )
                : 'NO_MESSAGE';
            yimai_gso_debug_log( '[一麦GSO InternalLink] Diag: model=' . $model . ' | finish=' . $finish_reason
                . ' | content_len=' . strlen( $raw_content ) . ' | choice_cnt=' . $choice_count
                . ' | msg_keys=[' . $msg_keys . '] | usage=' . $usage_info
                . ' | max_tokens_req=' . $dynamic_max_tokens . ' | candidate_cnt=' . $candidate_count );
        }

        // 多策略 JSON 数组提取（参考 class-ai-generator 的 extract_and_decode_json 思路，但针对 [...] 数组）
        $links = self::extract_json_array( $raw_content );

        if ( ! is_array( $links ) ) {
            // 解析失败：记录原始响应以便排查（生产环境始终记录）
            $preview = ( function_exists( 'mb_substr' ) ? mb_substr( $raw_content, 0, 500 ) : substr( $raw_content, 0, 500 ) );
            $trunc_note = $is_truncated ? ' [TRUNCATED]' : '';
            $content_len = strlen( $raw_content );
            yimai_gso_debug_log( '[一麦GSO InternalLink] JSON 解析失败' . $trunc_note . ' | finish=' . $finish_reason . ' | raw_len=' . $content_len . ' | candidate_cnt=' . $candidate_count . ' | max_tokens=' . $dynamic_max_tokens . ' | preview: ' . $preview );
            return array();
        }

        if ( $is_truncated && defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
            yimai_gso_debug_log( '[一麦GSO InternalLink] AI 响应被截断 (finish_reason=length)，已提取 ' . count( $links ) . ' 条内链' );
        }

        $valid_links = array();
        foreach ( $links as $link ) {
            if ( ! empty( $link['keyword'] ) && ! empty( $link['url'] )
                && mb_strlen( $link['keyword'] ) >= 2
                && mb_strlen( $link['keyword'] ) <= 80 ) {
                $sanitized_url = esc_url_raw( $link['url'] );
                if ( empty( $sanitized_url ) ) {
                    continue; // URL 无效则跳过
                }
                $valid_links[] = array(
                    'keyword'      => $link['keyword'],
                    'url'          => $sanitized_url,
                    'target_title' => $link['target_title'] ?? '',
               );
            }
            if ( count( $valid_links ) >= $max_links ) {
                break;
            }
        }

        // 空结果时记录（帮助判断是 AI 返回空还是内容不匹配）
        if ( empty( $valid_links ) ) {
            $original_count = is_array( $links ) ? count( $links ) : 0;
            yimai_gso_debug_log( '[一麦GSO InternalLink] AI 返回 ' . $original_count . ' 条规划，经验证后 0 条有效 | candidate_cnt=' . $candidate_count . ' | finish=' . $finish_reason . ' | max_links=' . $max_links );
        }

        return $valid_links;
    }

    /**
     * 多策略提取 JSON 数组 — 从 AI 混合文本响应中提取 [...] 格式的内链规划
     *
     * 策略层级（参考 class-ai-generator::extract_and_decode_json 的思路，但针对数组）：
     *   1. 直接 json_decode（AI 严格返回纯 JSON 数组时）
     *   2. 正则提取 [...] 部分（处理 JSON 前后有说明文字）
     *   3. 字符定位提取（从第一个 [ 到最后一个 ]）
     *   4. 清理 markdown 代码块后再试策略 1-3
     *
     * @param string $content AI 返回的原始文本
     * @return array|null 解析成功返回数组，失败返回 null
     */
    private static function extract_json_array( $content ) {
        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            return null;
        }

        // 清理控制字符
        $content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );

        // 策略 1：直接解析
        $result = json_decode( $content, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $result ) ) {
            return $result;
        }

        // 策略 2：正则提取第一个 [...] 块（非贪婪，防止跨越多个 JSON 块误匹配）
        if ( preg_match( '/\[\s*\{.*?\}\s*\]/s', $content, $matches ) ) {
            $result = json_decode( $matches[0], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $result ) ) {
                return $result;
            }
        }

        // 策略 3：从第一个 [ 到最后一个 ]（处理嵌套/复杂格式）
        $start = mb_strpos( $content, '[' );
        $end   = mb_strrpos( $content, ']' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $json_str = mb_substr( $content, $start, $end - $start + 1 );
            $result   = json_decode( $json_str, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $result ) ) {
                return $result;
            }
        }

        // 策略 4：清理 markdown 代码块后重试策略 1-3
        $cleaned = preg_replace( '/^```(?:json)?\s*$/m', '', $content );
        $cleaned = trim( $cleaned );
        if ( $cleaned !== $content ) {
            $result = json_decode( $cleaned, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $result ) ) {
                return $result;
            }
            // 重试策略 2 with cleaned text
            if ( preg_match( '/\[\s*\{.*\}\s*\]/s', $cleaned, $matches ) ) {
                $result = json_decode( $matches[0], true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $result ) ) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * 在HTML正文中应用内链
     *
     * @param string $content   HTML正文
     * @param array  $link_plan 内链计划
     * @return string
     */
    private static function apply_links( $content, $link_plan ) {
        $inserted_count = 0;

        foreach ( $link_plan as $link ) {
            $keyword = $link['keyword'];
            $url     = $link['url'];
            $title   = $link['target_title'];

            $title_attr = ! empty( $title ) ? ' title="' . esc_attr( $title ) . '"' : '';
            $link_html  = '<a href="' . esc_url( $url ) . '"' . $title_attr . '>' . esc_html( $keyword ) . '</a>';
            $pattern    = '/(?<![a-zA-Z\-_])(' . preg_quote( $keyword, '/' ) . ')(?![a-zA-Z\-_])/u';
            $temp_content = preg_replace( '/<a\b[^>]*>(.*?)<\/a>/is', '$1', $content );

            if ( preg_match( $pattern, $temp_content, $matches, PREG_OFFSET_CAPTURE ) ) {
                $matched_text = $matches[0][0];
                $pos = mb_strpos( $content, $matched_text );
                if ( $pos !== false ) {
                    $before = mb_substr( $content, 0, $pos );
                    $last_lt = mb_strrpos( $before, '<' );
                    $last_gt = mb_strrpos( $before, '>' );
                    if ( $last_lt !== false && $last_gt !== false && $last_lt > $last_gt ) {
                        continue;
                    }
                    // 禁止在脚本/样式/代码/标题标签内插入链接（原仅检查 <a>）
                    if ( self::is_inside_block_tag( $before ) ) {
                        continue;
                    }

                    $content = mb_substr( $content, 0, $pos ) . $link_html . mb_substr( $content, $pos + mb_strlen( $matched_text ) );
                    $inserted_count++;
                }
            }
        }

        return array(
            'content'        => $content,
            'inserted_count' => $inserted_count,
       );
    }

    /**
     * 检查文本是否在某个标签内部
     */
    private static function is_inside_tag( $text, $tag ) {
        // 统计开标签和闭标签的数量
        $open_count  = substr_count( $text, "<{$tag}" );
        $close_count = substr_count( $text, "</{$tag}" );
        return $open_count > $close_count;
    }

    /**
     * 禁止插入链接的标签黑名单
     * <script>/<style> 误插入会破坏页面渲染；<h1>-<h6> 是标题不应加链接；
     * <pre>/<code>/<textarea> 是代码或表单，不应加链接
     */
    private static $BLOCK_TAGS = array( 'a', 'script', 'style', 'pre', 'code', 'textarea', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

    private static function is_inside_block_tag( $text ) {
        foreach ( self::$BLOCK_TAGS as $tag ) {
            if ( self::is_inside_tag( $text, $tag ) ) {
                return true;
            }
        }
        return false;
    }

    private static function get_setting( $key, $default = '' ) {
        return class_exists( 'YIMAI_GSO_Settings' ) ? YIMAI_GSO_Settings::get( $key, $default ) : get_option( 'yimai_gso_' . $key, $default );
    }

    private static function normalize_id_list( $items ) {
        if ( is_string( $items ) && '' !== trim( $items ) ) {
            $items = preg_split( '/[\s,]+/', trim( $items ) );
        }

        if ( ! is_array( $items ) ) {
            return array();
        }

        $items = array_map( 'absint', $items );
        $items = array_filter( $items );

        return array_values( array_unique( $items ) );
    }

    private static function get_keyword_library() {
        $raw = (string) self::get_setting( 'internal_link_keyword_library', '' );
        if ( '' === trim( $raw ) ) {
            return array();
        }

        $parts = preg_split( '/[\r\n,，]+/', $raw );
        $parts = array_map( 'trim', (array) $parts );
        $parts = array_filter( $parts );

        return array_values( array_unique( $parts ) );
    }

    private static function get_anchor_strategy_prompt( $policy ) {
        switch ( $policy ) {
            case 'exact':
                return '优先使用正文中已经完整出现的精准关键词，不要改写锚文本。';
            case 'long_tail':
                return '优先选择更具体的长尾短语作为锚文本，避免泛词。';
            case 'title_keyword':
                return '优先选择与目标文章标题核心词接近的正文短语作为锚文本，但不要整段照搬标题。';
            default:
                return '优先保持自然表达，在不影响阅读的前提下选择最顺滑的锚文本。';
        }
    }

    private static function get_anchor_strategy_label( $policy ) {
        switch ( $policy ) {
            case 'exact':
                return '精准匹配';
            case 'long_tail':
                return '优先长尾词';
            case 'title_keyword':
                return '优先标题核心词';
            default:
                return '自然优先';
        }
    }

    private static function append_log( $entry ) {
        $logs = get_option( 'yimai_gso_internal_link_logs', array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        array_unshift( $logs, array(
            'time'            => $entry['time'] ?? current_time( 'mysql' ),
            'post_id'         => absint( $entry['post_id'] ?? 0 ),
            'post_title'      => sanitize_text_field( $entry['post_title'] ?? '未命名文章' ),
            'source'          => sanitize_text_field( $entry['source'] ?? 'publish' ),
            'post_status'     => sanitize_text_field( $entry['post_status'] ?? '' ),
            'max_links'       => absint( $entry['max_links'] ?? 0 ),
            'candidate_count' => absint( $entry['candidate_count'] ?? 0 ),
            'inserted_count'  => absint( $entry['inserted_count'] ?? 0 ),
            'status'          => sanitize_text_field( $entry['status'] ?? 'skipped' ),
            'message'         => sanitize_text_field( $entry['message'] ?? '' ),
            'anchor_policy'   => sanitize_text_field( $entry['anchor_policy'] ?? '' ),
            'candidate_mode'  => sanitize_text_field( $entry['candidate_mode'] ?? '' ),
        ) );

        update_option( 'yimai_gso_internal_link_logs', array_slice( $logs, 0, 100 ), false );
    }

    public static function get_recent_logs( $limit = 10 ) {
        $logs = get_option( 'yimai_gso_internal_link_logs', array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }

        return array_slice( $logs, 0, max( 1, absint( $limit ) ) );
    }

    public static function get_summary_stats() {
        $logs = get_option( 'yimai_gso_internal_link_logs', array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        $stats = array(
            'total'         => count( $logs ),
            'success'       => 0,
            'inserted'      => 0,
            'warning'       => 0,
            'last_run'      => '',
       );

        foreach ( $logs as $index => $log ) {
            $stats['inserted'] += absint( $log['inserted_count'] ?? 0 );
            if ( 'success' === ( $log['status'] ?? '' ) ) {
                $stats['success']++;
            } elseif ( 'warning' === ( $log['status'] ?? '' ) ) {
                $stats['warning']++;
            }

            if ( 0 === $index && ! empty( $log['time'] ) ) {
                $stats['last_run'] = $log['time'];
            }
        }

        return $stats;
    }

    // ==================== 批量重建历史文章内链 ====================

    /**
     * AJAX: 启动批量重建历史文章内链
     */
    public static function ajax_start_rebuild_links() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
// 查询所有已发布文章ID
        $post_ids = get_posts( array(
            'post_type'      => YIMAI_GSO_Post_Type_Helper::get_supported(),
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ) );

        if ( empty( $post_ids ) ) {
            wp_send_json_error( array( 'message' => '没有找到已发布的文章' ) );
        }

        // 写入重建队列
        update_option( self::REBUILD_QUEUE_OPTION, array(
            'post_ids'    => $post_ids,
            'total'       => count( $post_ids ),
            'processed'   => 0,
            'success'     => 0,
            'failed'      => 0,
            'started_at'  => current_time( 'mysql' ),
            'status'      => 'running',
            'logs'        => array(),
        ), false );

        // 调度异步处理
        if ( ! wp_next_scheduled( self::REBUILD_QUEUE_CRON ) ) {
            wp_schedule_single_event( time() + 10, self::REBUILD_QUEUE_CRON );
        }

        wp_send_json_success( array(
            'message' => '批量重建已启动，共 ' . count( $post_ids ) . ' 篇文章',
            'total'   => count( $post_ids ),
        ) );
    }

    /**
     * AJAX: 获取批量重建进度
     */
    public static function ajax_get_rebuild_progress() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $queue = get_option( self::REBUILD_QUEUE_OPTION, array() );
        if ( empty( $queue ) || ! is_array( $queue ) ) {
            wp_send_json_success( array(
                'status'    => 'idle',
                'total'     => 0,
                'processed' => 0,
                'success'   => 0,
                'failed'    => 0,
                'logs'      => array(),
            ) );
        }

        wp_send_json_success( array(
            'status'    => $queue['status'] ?? 'idle',
            'total'     => $queue['total'] ?? 0,
            'processed' => $queue['processed'] ?? 0,
            'success'   => $queue['success'] ?? 0,
            'failed'    => $queue['failed'] ?? 0,
            'logs'      => array_slice( $queue['logs'] ?? array(), 0, 20 ),
        ) );
    }

    /**
     * WP-Cron 回调：处理批量重建队列
     * 每次最多处理 3 篇，避免单次超时
     */
    public function process_rebuild_queue() {
        // 系统Cron模式下设置运行时用户上下文
        if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
            YIMAI_GSO_Autopilot_Runner::bootstrap_runtime_user( 0 );
        }

        $queue = get_option( self::REBUILD_QUEUE_OPTION, array() );
        if ( empty( $queue ) || ! is_array( $queue ) || 'running' !== ( $queue['status'] ?? '' ) ) {
            return;
        }

        $post_ids  = $queue['post_ids'] ?? array();
        $processed = absint( $queue['processed'] ?? 0 );
        $success   = absint( $queue['success'] ?? 0 );
        $failed    = absint( $queue['failed'] ?? 0 );
        $logs      = $queue['logs'] ?? array();

        // 取下一批（最多3篇）
        $batch = array_slice( $post_ids, $processed, 3 );

        if ( empty( $batch ) ) {
            $queue['status'] = 'completed';
            $queue['finished_at'] = current_time( 'mysql' );
            update_option( self::REBUILD_QUEUE_OPTION, $queue, false );
            return;
        }

        foreach ( $batch as $post_id ) {
            $post_id = absint( $post_id );
            $result  = self::process_published_post( $post_id );
            $post    = get_post( $post_id );
            $title   = $post ? $post->post_title : '文章 #' . $post_id;

            if ( $result ) {
                $success++;
                $logs[] = array(
                    'time'    => current_time( 'mysql' ),
                    'post_id' => $post_id,
                    'title'   => $title,
                    'status'  => 'success',
                    'message' => '内链重建完成',
               );
            } else {
                $failed++;
                $logs[] = array(
                    'time'    => current_time( 'mysql' ),
                    'post_id' => $post_id,
                    'title'   => $title,
                    'status'  => 'failed',
                    'message' => '内链重建失败或无需更新',
               );
            }

            $processed++;
        }

        $queue['post_ids']  = $post_ids;
        $queue['processed'] = $processed;
        $queue['success']   = $success;
        $queue['failed']    = $failed;
        $queue['logs']      = array_slice( $logs, -50 ); // 保留最近50条日志

        // 检查是否完成
        if ( $processed >= count( $post_ids ) ) {
            $queue['status'] = 'completed';
            $queue['finished_at'] = current_time( 'mysql' );
        }

        update_option( self::REBUILD_QUEUE_OPTION, $queue, false );

        // 如果未完成，继续调度
        if ( 'running' === ( $queue['status'] ?? '' ) ) {
            if ( ! wp_next_scheduled( self::REBUILD_QUEUE_CRON ) ) {
                wp_schedule_single_event( time() + 60, self::REBUILD_QUEUE_CRON );
            }
        }
    }

    // ==================== 失效链接巡检 ====================

    /**
     * AJAX: 启动失效链接巡检（异步队列方式）
     */
    public static function ajax_start_broken_link_audit() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
// 检查是否已有巡检在进行中
        $queue = get_option( self::BROKEN_LINK_AUDIT_QUEUE_OPTION, array() );
        if ( ! empty( $queue['status'] ) && 'running' === $queue['status'] ) {
            wp_send_json_success( array(
                'message' => '巡检正在进行中，共 ' . ( $queue['total'] ?? 0 ) . ' 个链接',
                'total'   => $queue['total'] ?? 0,
            ) );
        }

        // 提取全站内链（限制最多500篇文章，避免内存溢出）
        $links = self::extract_all_internal_links( 500 );

        if ( empty( $links ) ) {
            wp_send_json_error( array( 'message' => '未找到站内链接' ) );
        }

        // 写入巡检队列
        update_option( self::BROKEN_LINK_AUDIT_QUEUE_OPTION, array(
            'links'       => $links,
            'total'       => count( $links ),
            'checked'     => 0,
            'broken'      => array(),
            'started_at'  => current_time( 'mysql' ),
            'status'      => 'running',
        ), false );

        // 调度异步处理
        if ( ! wp_next_scheduled( self::BROKEN_LINK_AUDIT_CRON ) ) {
            wp_schedule_single_event( time() + 10, self::BROKEN_LINK_AUDIT_CRON );
        }

        wp_send_json_success( array(
            'message' => '巡检已启动，共 ' . count( $links ) . ' 个链接',
            'total'   => count( $links ),
        ) );
    }

    /**
     * AJAX: 获取失效链接巡检进度
     */
    public static function ajax_get_broken_link_audit_progress() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $queue  = get_option( self::BROKEN_LINK_AUDIT_QUEUE_OPTION, array() );
        $result = get_option( self::BROKEN_LINK_AUDIT_OPTION, array() );

        wp_send_json_success( array(
            'status'       => $queue['status'] ?? 'idle',
            'total'        => $queue['total'] ?? 0,
            'checked'      => $queue['checked'] ?? 0,
            'broken_count' => count( $queue['broken'] ?? array() ),
            'result'       => $result,
        ) );
    }

    /**
     * AJAX: 获取最近一次巡检结果
     */
    public static function ajax_get_broken_link_audit_result() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $result = get_option( self::BROKEN_LINK_AUDIT_OPTION, array() );

        wp_send_json_success( $result ?: array() );
    }

    /**
     * AJAX: 移除失效链接（将 <a> 变为 <span>）
     */
    public static function ajax_remove_broken_links() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => '链接地址不能为空' ) );
        }

        $result    = get_option( self::BROKEN_LINK_AUDIT_OPTION, array() );
        $broken    = $result['broken_links'] ?? array();
        $target    = null;

        foreach ( $broken as $item ) {
            if ( $item['url'] === $url ) {
                $target = $item;
                break;
            }
        }

        if ( empty( $target ) ) {
            wp_send_json_error( array( 'message' => '未找到该失效链接记录' ) );
        }

        $removed_count = 0;
        foreach ( $target['post_ids'] as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            $content = $post->post_content;
            // 将 <a href="url">text</a> 替换为 <span class="yimai-gso-broken-link">text</span>
            $pattern = '/<a\b[^>]*href=["\']' . preg_quote( $url, '/' ) . '["\'][^>]*>(.*?)<\/a>/is';
            $new_content = preg_replace( $pattern, '<span class="yimai-gso-broken-link">$1</span>', $content );

            if ( $new_content !== $content ) {
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $new_content,
                ) );
                $removed_count++;
            }
        }

        wp_send_json_success( array(
            'message'       => "已移除 {$removed_count} 篇文章中的失效链接",
            'removed_count' => $removed_count,
        ) );
    }

    /**
     * 提取全站所有内部链接
     *
     * @param int $limit 最大文章数量，防止内存溢出
     * @return array [url => ['post_ids' => [], 'post_titles' => []]]
     */
    private static function extract_all_internal_links( $limit = 0 ) {
        global $wpdb;

        $site_url = home_url();
        $post_type_condition = YIMAI_GSO_Post_Type_Helper::sql_in_clause();
        $sql = "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                WHERE post_status = 'publish' AND {$post_type_condition}";
        if ( $limit > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', $limit );
        }
        $posts = $wpdb->get_results( $sql );

        $links = array();

        foreach ( $posts as $post ) {
            if ( preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    // 只保留站内链接
                    if ( strpos( $url, $site_url ) === 0 || ( strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) ) {
                        $normalized = esc_url_raw( $url );
                        if ( ! isset( $links[ $normalized ] ) ) {
                            $links[ $normalized ] = array(
                                'post_ids'    => array(),
                                'post_titles' => array(),
                           );
                        }
                        if ( ! in_array( $post->ID, $links[ $normalized ]['post_ids'], true ) ) {
                            $links[ $normalized ]['post_ids'][]    = $post->ID;
                            $links[ $normalized ]['post_titles'][] = $post->post_title;
                        }
                    }
                }
            }
        }

        return $links;
    }

    /**
     * 检测单个链接的HTTP状态码
     *
     * @param string $url 链接地址
     * @return int|false HTTP状态码或false
     */
    private static function check_link_status( $url ) {
        // 相对路径转为绝对路径
        if ( strpos( $url, 'http' ) !== 0 ) {
            $url = home_url( $url );
        }

        $response = wp_remote_head( $url, array(
            'timeout'     => 10,
            'user-agent'  => 'Mozilla/5.0 (compatible; BaiqueAI-LinkChecker/1.0)',
        ) );

        if ( is_wp_error( $response ) ) {
            return 0; // 网络错误标记为0
        }

        $status = wp_remote_retrieve_response_code( $response );

        // HEAD 请求可能返回 405，改用 GET
        if ( 405 === $status ) {
            $response = wp_remote_get( $url, array(
                'timeout'     => 10,
                'user-agent'  => 'Mozilla/5.0 (compatible; BaiqueAI-LinkChecker/1.0)',
            ) );
            if ( ! is_wp_error( $response ) ) {
                $status = wp_remote_retrieve_response_code( $response );
            }
        }

        return $status;
    }

    /**
     * Cron: 异步处理失效链接巡检队列
     */
    public function process_broken_link_audit() {
        // 系统Cron模式下设置运行时用户上下文
        if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
            YIMAI_GSO_Autopilot_Runner::bootstrap_runtime_user( 0 );
        }

        $queue = get_option( self::BROKEN_LINK_AUDIT_QUEUE_OPTION, array() );

        if ( empty( $queue['status'] ) || 'running' !== $queue['status'] ) {
            return;
        }

        $links      = $queue['links'] ?? array();
        $total      = $queue['total'] ?? 0;
        $checked    = $queue['checked'] ?? 0;
        $broken     = $queue['broken'] ?? array();
        $batch_size = 5;
        $processed  = 0;

        // 每次处理一批（关联数组用 array_slice 不可靠，改用 array_keys 切片）
        $link_keys = array_keys( $links );
        $batch_keys = array_slice( $link_keys, $checked, $batch_size );

        foreach ( $batch_keys as $url ) {
            $data = $links[ $url ];
            $status = self::check_link_status( $url );
            $checked++;
            $processed++;

            if ( false !== $status && $status >= 400 ) {
                $broken[] = array(
                    'url'         => $url,
                    'status'      => $status,
                    'post_ids'    => $data['post_ids'],
                    'post_titles' => $data['post_titles'],
               );
            }
        }

        // 更新队列状态
        $queue['checked'] = $checked;
        $queue['broken']  = $broken;

        // 如果全部检测完成
        if ( $checked >= $total ) {
            $queue['status'] = 'completed';

            // 保存最终结果
            $result = array(
                'audited_at'   => current_time( 'mysql' ),
                'total_links'  => $total,
                'checked'      => $checked,
                'broken_count' => count( $broken ),
                'broken_links' => $broken,
           );
            update_option( self::BROKEN_LINK_AUDIT_OPTION, $result, false );
        }

        update_option( self::BROKEN_LINK_AUDIT_QUEUE_OPTION, $queue, false );

        // 如果还有剩余，继续调度下一批
        if ( $checked < $total ) {
            wp_schedule_single_event( time() + 30, self::BROKEN_LINK_AUDIT_CRON );
        }
    }
}
