<?php
/**
 * 自动图片 ALT 生成器
 * 规则 + AI 双模式；AI 失败时自动回退到规则模式
 *
 * ALT 生成改为 transition_post_status 钩子（优先级10），
 * 与智能内链(25)、内容检测(20) 架构统一，不再在发布流程中阻塞生成。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Image_Alt_Generator {

    const MODE_RULE = 'rule';
    const MODE_AI   = 'ai';

    private $enabled = false;
    private $mode    = self::MODE_RULE;

    /** @var self|null 单例实例 */
    private static $instance = null;

    /** @var array 防重入：正在处理的文章ID */
    private static $processing_posts = array();

    /**
     * 获取单例实例（注册 transition_post_status 钩子）
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->enabled = (int) YIMAI_GSO_Settings::get( 'auto_image_alt_enabled', 1 ) === 1;

        $mode = YIMAI_GSO_Settings::get( 'auto_image_alt_mode', self::MODE_RULE );
        if ( ! in_array( $mode, array( self::MODE_RULE, self::MODE_AI ), true ) ) {
            $mode = self::MODE_RULE;
        }
        $this->mode = $mode;

        // 发布后自动补全 ALT（优先级10，在内容检测20、智能内链25之前）
        add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 10, 3 );

        // 注册 ALT 异步处理队列的 WP-Cron 钩子
        add_action( 'yimai_gso_process_alt_queue', array( $this, 'process_alt_queue' ) );

        // 前端渲染时自动补全缺失的 ALT（已发布但未处理的文章）
        if ( ! is_admin() ) {
            add_filter( 'the_content', array( $this, 'filter_content_alt' ), 999 );
        }
    }

    /**
     * transition_post_status 钩子回调：文章首次发布时自动补全图片 ALT
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
        if ( ! $this->enabled ) {
            return;
        }

        // 仅处理发布和定时发布状态
        if ( ! in_array( $new_status, array( 'publish', 'future' ), true ) ) {
            return;
        }

        // 仅处理插件支持的内容类型
        if ( ! $post || ! YIMAI_GSO_Post_Type_Helper::is_supported( $post->post_type ) ) {
            return;
        }

        $post_id = $post->ID;

        // 防重入：避免 wp_update_post 二次触发钩子
        if ( isset( self::$processing_posts[ $post_id ] ) ) {
            return;
        }

        // 检查文章内容中是否有需要生成 ALT 的图片
        $content = $post->post_content;
        if ( empty( $content ) || strpos( $content, '<img' ) === false ) {
            return;
        }

        // 检查是否真的有缺 ALT 的图片
        $needs_alt = false;
        if ( preg_match_all( '/<img\s[^>]*>/i', $content, $img_tags ) ) {
            foreach ( $img_tags[0] as $tag ) {
                $alt_val = '';
                if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $tag, $m ) ) {
                    $alt_val = trim( $m[1] );
                }
                // 没有 alt 属性，或 alt 为空/低质量/原始文件名 → 需要生成
                if ( empty( $alt_val ) || $this->is_low_quality_alt( $alt_val, $this->detect_language_from_content( $content ) ) ) {
                    $needs_alt = true;
                    break;
                }
            }
        }

        if ( ! $needs_alt ) {
            return;
        }

        // 【异步化】不再同步处理，改为加入队列由 WP-Cron 异步执行
        $this->add_to_alt_queue( $post_id );
    }

    /**
     * 将文章ID加入ALT生成队列，并安排异步处理任务
     */
    private function add_to_alt_queue( $post_id ) {
        $queue = get_option( 'yimai_gso_alt_queue', array() );

        // 去重：避免同一篇文章重复入队
        if ( in_array( $post_id, $queue, true ) ) {
            return;
        }

        $queue[] = $post_id;
        update_option( 'yimai_gso_alt_queue', $queue, false );

        // 如果处理任务尚未安排，则安排一个（60秒后执行，避免发布流程阻塞）
        if ( ! wp_next_scheduled( 'yimai_gso_process_alt_queue' ) ) {
            wp_schedule_single_event( time() + 60, 'yimai_gso_process_alt_queue' );
        }

        // 主动触发 wp-cron.php 立即处理（防止低流量站点队列堆积）
        if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
            $last_trigger = get_transient( 'yimai_gso_last_cron_trigger' );
            if ( false === $last_trigger ) {
                spawn_cron();
                set_transient( 'yimai_gso_last_cron_trigger', time(), 1 );
            }
        }
    }

    /**
     * WP-Cron 回调：处理ALT生成队列（每次最多处理5篇，避免超时）
     */
    public function process_alt_queue() {
        $queue = get_option( 'yimai_gso_alt_queue', array() );
        if ( empty( $queue ) ) {
            return;
        }

        // 每次最多处理5篇，避免AI请求超时
        $batch     = array_splice( $queue, 0, 5 );
        $processed = array();

        foreach ( $batch as $post_id ) {
            $this->process_single_post_alt( $post_id );
            $processed[] = $post_id;
        }

        // 更新剩余队列
        if ( ! empty( $queue ) ) {
            update_option( 'yimai_gso_alt_queue', $queue, false );
            // 队列还有剩余，30秒后继续处理
            if ( ! wp_next_scheduled( 'yimai_gso_process_alt_queue' ) ) {
                wp_schedule_single_event( time() + 30, 'yimai_gso_process_alt_queue' );
            }
        } else {
            delete_option( 'yimai_gso_alt_queue' );
        }
    }

    /**
     * 处理单篇文章的ALT生成（供队列回调使用）
     */
    private function process_single_post_alt( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $content = $post->post_content;
        if ( empty( $content ) || strpos( $content, '<img' ) === false ) {
            return;
        }

        // 检查是否真的缺ALT
        $needs_alt = false;
        if ( preg_match_all( '/<img\s[^>]*>/i', $content, $img_tags ) ) {
            foreach ( $img_tags[0] as $tag ) {
                $alt_val = '';
                if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $tag, $m ) ) {
                    $alt_val = trim( $m[1] );
                }
                if ( empty( $alt_val ) || $this->is_low_quality_alt( $alt_val, $this->detect_language_from_content( $content ) ) ) {
                    $needs_alt = true;
                    break;
                }
            }
        }

        if ( ! $needs_alt ) {
            return;
        }

        $context = array(
            'title'    => $post->post_title,
            'keyword'  => get_post_meta( $post_id, 'yimai_gso_keywords', true ),
            'excerpt'  => $post->post_excerpt,
            'language' => YIMAI_GSO_Settings::get( 'article_language', 'zh_CN' ),
        );

        // 产品图片：添加产品上下文数据（#45 产品图片SEO）
        if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post_id );
            if ( $product ) {
                $context['product_sku']    = $product->get_sku();
                $context['product_price']  = $product->get_price();
                $context['product_type']   = 'product';

                $prod_cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
                if ( ! empty( $prod_cats ) ) {
                    $context['product_categories'] = implode( ', ', $prod_cats );
                }

                $attributes = $product->get_attributes();
                if ( ! empty( $attributes ) ) {
                    $attr_parts = array();
                    foreach ( $attributes as $attr ) {
                        if ( $attr->get_visible() ) {
                            $attr_parts[] = $attr->get_name() . ':' . implode( ',', $attr->get_options() );
                        }
                    }
                    if ( ! empty( $attr_parts ) ) {
                        $context['product_attributes'] = implode( ' | ', $attr_parts );
                    }
                }
            }
        }

        $updated_content = $this->process_html_content( $content, $context );

        if ( $updated_content !== $content ) {
            wp_update_post( array(
                'ID'           => $post_id,
                'post_content' => $updated_content,
            ) );

            $this->sync_attachment_alt_meta( $post_id, $updated_content );
        }
    }

    /**
     * 同步更新媒体库 attachment 的 _wp_attachment_image_alt 元数据
     *
     * @param int    $post_id 文章ID
     * @param string $content 更新后的文章内容
     */
    private function sync_attachment_alt_meta( $post_id, $content ) {
        if ( ! preg_match_all( '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
            return;
        }

        foreach ( $matches as $match ) {
            $img_url  = $match[1];
            $alt_text = trim( $match[2] );

            if ( empty( $alt_text ) ) {
                continue;
            }

            // 通过URL查找 attachment ID
            $attach_id = attachment_url_to_postid( $img_url );
            if ( $attach_id ) {
                $existing_alt = get_post_meta( $attach_id, '_wp_attachment_image_alt', true );
                $lang = $this->detect_language_from_content( $content );
                if ( empty( $existing_alt ) || $this->is_low_quality_alt( $existing_alt, $lang ) ) {
                    update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt_text );
                }
            }
        }
    }

    /**
     * the_content 过滤器：前端渲染时自动补全缺失的 ALT
     *
     * 解决已发布文章（未触发 transition_post_status）在前端显示时图片缺少 ALT 的问题。
     *
     * @param string $content
     * @return string
     */
    public function filter_content_alt( $content ) {
        if ( ! $this->_xTR() ) {
            return $content;
        }

        if ( empty( $content ) || strpos( $content, '<img' ) === false ) {
            return $content;
        }

        if ( wp_doing_ajax() ) {
            return $content;
        }

        // P3 修复：绝不在这条「公开页面渲染」路径上发起外部 AI 调用。
        // AI 模式的 ALT 生成由后台队列（yimai_gso_process_alt_queue）异步完成；
        // 若在访客请求里同步调用（timeout 180 秒且无缓存），会长时间占用 php-fpm worker。
        // 此处仅允许本地规则模式参与渲染期补全。
        if ( self::MODE_AI === $this->mode ) {
            return $content;
        }

        // 获取当前文章上下文
        $post = get_post();
        $context = array();
        if ( $post ) {
            $context['title']    = $post->post_title;
            $context['keyword']  = get_post_meta( $post->ID, 'yimai_gso_keywords', true );
            $context['language'] = YIMAI_GSO_Settings::get( 'article_language', 'zh_CN' );

            // P3 修复：规则模式对同一篇文章的输出是确定性的（仅取决于标题/关键词/正文图片），
            // 但原实现每次页面浏览都重新计算。按 post_id + post_modified 缓存结果，
            // 文章一旦更新（post_modified 变化）缓存键自然失效。
            $cache_key = 'yimai_gso_alt_render_' . absint( $post->ID ) . '_' . md5( (string) $post->post_modified );
            $cached    = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }

            $result = $this->process_html_content( $content, $context );

            if ( is_string( $result ) && '' !== $result ) {
                set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
            }

            return $result;
        }

        return $this->process_html_content( $content, $context );
    }

    public function _xTR() {
        // P3 修复：原实现为 $this->enabled && $this->feature_is_usable()，
        // 而 feature_is_usable() 是恒真的 `if (true) return true;` 死门控，
        // 等价于只看 $this->enabled（默认为 1）。
        // 该门控背后是一条会把请求打到外部 AI 的链路：
        //   the_content → generate_alt_map → MODE_AI 时 call_api（timeout 180 秒，无缓存）
        // 激活默认是 rule 模式（无 HTTP），但一旦后台把模式切成 ai，
        // 就会变成同步、最长 180 秒、无缓存的外部调用卡在公开页面渲染里，
        // 可能耗尽 php-fpm worker。
        // 这里只保留真实生效的条件，并删除恒真死门控（见 feature_is_usable 的移除）。
        return (bool) $this->enabled;
    }

    public function process_images( $images, $article_context = array() ) {
        if ( empty( $images ) || ! is_array( $images ) || ! $this->_xTR() ) {
            return $images;
        }

        $slots = $this->build_slots_from_images( $images, $article_context );
        if ( empty( $slots ) ) {
            return $images;
        }

        $alt_map = $this->generate_alt_map( $slots, $article_context );
        if ( empty( $alt_map ) ) {
            return $images;
        }

        foreach ( $images as $index => $image ) {
            $slot = $index + 1;
            if ( ! empty( $alt_map[ $slot ] ) ) {
                $images[ $index ]['alt'] = $alt_map[ $slot ];
            }
        }

        return $images;
    }

    public function process_html_content( $content, $article_context = array() ) {
        if ( empty( $content ) || ! $this->_xTR() ) {
            return $content;
        }

        if ( ! preg_match_all( '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
            return $content;
        }

        $slots = $this->build_slots_from_html_matches( $matches, $content, $article_context );
        if ( empty( $slots ) ) {
            return $content;
        }

        $alt_map = $this->generate_alt_map( $slots, $article_context );
        if ( empty( $alt_map ) ) {
            return $content;
        }

        $replace_index = 0;
        $updated = preg_replace_callback(
            '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>/i',
            function ( $match ) use ( &$replace_index, $alt_map ) {
                $replace_index++;
                if ( empty( $alt_map[ $replace_index ] ) ) {
                    return $match[0];
                }

                return $this->replace_or_insert_alt_attribute( $match[0], $alt_map[ $replace_index ] );
            },
            $content
        );

        return is_string( $updated ) ? $updated : $content;
    }

    // P3：已删除恒真死门控 feature_is_usable()（原为 `if (true) return true;`）。
    // 该方法是已移除的授权/功能门控系统残留，恒返回 true，唯一作用是把读者引向
    // 一个不存在的开关。_xTR() 现只依据真实条件 $this->enabled（见该处注释）。

    private function build_slots_from_images( $images, $article_context ) {
        $slots    = array();
        $headings = ! empty( $article_context['headings'] ) && is_array( $article_context['headings'] )
            ? $article_context['headings']
            : array();

        foreach ( $images as $index => $image ) {
            $slot      = $index + 1;
            $image_url = isset( $image['url'] ) ? (string) $image['url'] : '';
            $heading   = isset( $headings[ $index ] ) ? $headings[ $index ] : '';

            $slots[] = array(
                'slot'        => $slot,
                'src'         => $image_url,
                'heading'     => $this->clean_text_fragment( $heading ),
                'current_alt' => isset( $image['alt'] ) ? (string) $image['alt'] : '',
                'source'      => ! empty( $image['source'] ) ? (string) $image['source'] : $this->detect_source_from_url( $image_url ),
            );
        }

        return $slots;
    }

    private function build_slots_from_html_matches( $matches, $content, $article_context ) {
        $slots    = array();
        $headings = $this->extract_h2_headings( $content );

        foreach ( $matches as $index => $match ) {
            $slot        = $index + 1;
            $full_tag    = $match[0];
            $image_url   = isset( $match[1] ) ? (string) $match[1] : '';
            $current_alt = '';

            if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $full_tag, $alt_match ) ) {
                $current_alt = html_entity_decode( $alt_match[1], ENT_QUOTES, 'UTF-8' );
            }

            $slots[] = array(
                'slot'        => $slot,
                'src'         => $image_url,
                'heading'     => isset( $headings[ $index ] ) ? $headings[ $index ] : '',
                'current_alt' => $current_alt,
                'source'      => $this->detect_source_from_url( $image_url ),
            );
        }

        return $slots;
    }

    private function generate_alt_map( $slots, $article_context ) {
        $language      = $this->get_context_language( $article_context );
        $resolved_map  = array();
        $pending_slots = array();

        foreach ( $slots as $slot_data ) {
            $slot       = (int) $slot_data['slot'];
            $current_alt = $this->sanitize_alt_text( $slot_data['current_alt'], $language );
            $rule_alt    = $this->build_rule_alt( $slot_data, $article_context, $language );

            if ( '' !== $current_alt && ! $this->is_low_quality_alt( $current_alt, $language ) ) {
                $resolved_map[ $slot ] = $current_alt;
                continue;
            }

            $resolved_map[ $slot ] = $rule_alt;
            if ( self::MODE_AI === $this->mode ) {
                $pending_slots[ $slot ] = $slot_data;
                $pending_slots[ $slot ]['fallback_alt'] = $rule_alt;
            }
        }

        if ( self::MODE_AI === $this->mode && ! empty( $pending_slots ) ) {
            $ai_alt_map = $this->generate_ai_alt_map( $pending_slots, $article_context );
            if ( ! is_wp_error( $ai_alt_map ) && ! empty( $ai_alt_map ) ) {
                foreach ( $ai_alt_map as $slot => $alt_text ) {
                    if ( ! empty( $alt_text ) ) {
                        $resolved_map[ (int) $slot ] = $alt_text;
                    }
                }
            }
        }

        ksort( $resolved_map );
        return $resolved_map;
    }

    private function generate_ai_alt_map( $slots, $article_context ) {
        if ( empty( $slots ) || ! class_exists( 'YIMAI_GSO_AI_Generator' ) ) {
            return new WP_Error( 'yimai_gso_alt_ai_unavailable', 'AI ALT 生成器不可用' );
        }

        $generator = new YIMAI_GSO_AI_Generator();
        $result    = $generator->generate_image_alt( $article_context, array_values( $slots ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $language = $this->get_context_language( $article_context );
        $clean    = array();

        foreach ( $result as $slot => $alt_text ) {
            $slot     = (int) $slot;
            $alt_text = $this->sanitize_alt_text( $alt_text, $language );
            if ( '' === $alt_text || $this->is_low_quality_alt( $alt_text, $language ) ) {
                continue;
            }
            $clean[ $slot ] = $alt_text;
        }

        return $clean;
    }

    private function build_rule_alt( $slot_data, $article_context, $language ) {
        $title   = $this->clean_text_fragment( isset( $article_context['title'] ) ? $article_context['title'] : '' );
        $keyword = $this->clean_text_fragment( isset( $article_context['keyword'] ) ? $article_context['keyword'] : '' );
        $heading = $this->clean_text_fragment( isset( $slot_data['heading'] ) ? $slot_data['heading'] : '' );
        $slot    = max( 1, (int) $slot_data['slot'] );

        // 产品图片专用 ALT 生成（#45 产品图片SEO）
        if ( isset( $article_context['product_type'] ) && 'product' === $article_context['product_type'] ) {
            $product_cats = isset( $article_context['product_categories'] ) ? $this->clean_text_fragment( $article_context['product_categories'] ) : '';
            $sku          = isset( $article_context['product_sku'] ) ? $this->clean_text_fragment( $article_context['product_sku'] ) : '';

            $parts = array( $title );

            if ( $product_cats ) {
                $parts[] = $product_cats;
            }
            if ( $sku ) {
                $parts[] = 'SKU:' . $sku;
            }

            $alt_base = implode( ' - ', $parts );

            if ( $slot > 1 ) {
                return $this->sanitize_alt_text( $alt_base . ' 产品实拍图 ' . $slot, $language );
            }
            return $this->sanitize_alt_text( $alt_base . ' 产品实拍图', $language );
        }

        if ( 'en_US' === $language ) {
            $subject = $heading ? $heading : ( $keyword ? $keyword : $title );
            $subject = $this->truncate_multibyte( $subject, 48 );
            if ( '' === $subject ) {
                $subject = 'Article topic';
            }

            if ( $slot > 1 ) {
                return $this->sanitize_alt_text( $subject . ' related image ' . $slot, $language );
            }

            return $this->sanitize_alt_text( $subject . ' related image', $language );
        }

        if ( '' !== $heading ) {
            $heading_short = $this->truncate_multibyte( $heading, 16 );
            $keyword_short = $this->truncate_multibyte( $keyword, 8 );

            if ( '' !== $keyword_short && mb_strpos( $heading_short, $keyword_short ) === false ) {
                return $this->sanitize_alt_text( $heading_short . '：' . $keyword_short . '场景配图', $language );
            }

            return $this->sanitize_alt_text( $heading_short . '场景配图', $language );
        }

        if ( '' !== $keyword ) {
            return $this->sanitize_alt_text( $this->truncate_multibyte( $keyword, 12 ) . '主题相关配图', $language );
        }

        if ( '' !== $title ) {
            return $this->sanitize_alt_text( $this->truncate_multibyte( $title, 14 ) . '内容配图', $language );
        }

        return $this->sanitize_alt_text( '文章主题配图', $language );
    }

    private function replace_or_insert_alt_attribute( $img_tag, $alt_text ) {
        $alt_text = esc_attr( $alt_text );

        if ( preg_match( '/\salt=["\'][^"\']*["\']/i', $img_tag ) ) {
            return preg_replace( '/\salt=["\'][^"\']*["\']/i', ' alt="' . $alt_text . '"', $img_tag, 1 );
        }

        return preg_replace( '/\/>$/', ' alt="' . $alt_text . '" />', $img_tag, 1 )
            ?: preg_replace( '/>$/', ' alt="' . $alt_text . '">', $img_tag, 1 );
    }

    private function extract_h2_headings( $content ) {
        $headings = array();

        if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $matches ) ) {
            foreach ( $matches[1] as $heading ) {
                $headings[] = $this->clean_text_fragment( wp_strip_all_tags( $heading ) );
            }
        }

        return $headings;
    }

    private function get_context_language( $article_context ) {
        if ( ! empty( $article_context['language'] ) ) {
            $language = (string) $article_context['language'];
            if ( stripos( $language, 'en' ) === 0 ) {
                return 'en_US';
            }
        }

        $sample = '';
        foreach ( array( 'title', 'keyword', 'excerpt' ) as $key ) {
            if ( ! empty( $article_context[ $key ] ) ) {
                $sample .= ' ' . (string) $article_context[ $key ];
            }
        }
        $sample = trim( $sample );

        if ( '' !== $sample && ! preg_match( '/[\x{4e00}-\x{9fa5}]/u', $sample ) ) {
            return 'en_US';
        }

        return 'zh_CN';
    }

    private function is_low_quality_alt( $alt_text, $language ) {
        $alt_text = trim( (string) $alt_text );
        if ( '' === $alt_text ) {
            return true;
        }

        $length = mb_strlen( $alt_text );
        if ( $length < 2 || $length > 80 ) {
            return true;
        }

        if ( preg_match( '/https?:\/\//i', $alt_text ) ) {
            return true;
        }

        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $alt_text, 'UTF-8' ) : strtolower( $alt_text );
        $generic_values = array(
            '配图', '图片', '文章配图', '相关配图', 'image', 'photo', 'picture',
            'stock photo', 'generated image', 'default image', 'dall-e', 'pexels', 'pixabay', 'unsplash',
        );

        foreach ( $generic_values as $generic ) {
            if ( $lower === $generic ) {
                return true;
            }
        }

        // 原始文件名模式：photo-数字、image-数字、纯数字-hash 等
        // 典型示例：photo-1572635196237-14b3f281503f、image-12345-abc、pexels-photo-123456
        if ( preg_match( '/^(photo|image|img|pic|pexels|unsplash|pixabay|dalle|dall-e|ai-image|stock)[\-\_]?\d/i', $lower ) ) {
            return true;
        }

        // 纯数字+连字符（UUID/hash片段）：如 1572635196237-14b3f281503f
        if ( preg_match( '/^\d+[\-\_]\w+/', $lower ) ) {
            return true;
        }

        // 长串无意义字符（>20字符、无中文、无空格、字母数字混合）
        if ( $length > 20 && ! preg_match( '/[\x{4e00}-\x{9fa5}]/u', $alt_text ) && ! preg_match( '/\s/', $alt_text ) ) {
            return true;
        }

        if ( 'zh_CN' === $language && ! preg_match( '/[\x{4e00}-\x{9fa5}]/u', $alt_text ) && preg_match( '/^[a-z0-9,\.\-\s_]+$/i', $alt_text ) ) {
            return true;
        }

        return false;
    }

    private function sanitize_alt_text( $alt_text, $language ) {
        $alt_text = html_entity_decode( (string) $alt_text, ENT_QUOTES, 'UTF-8' );
        $alt_text = wp_strip_all_tags( $alt_text );
        $alt_text = preg_replace( '/\s+/u', ' ', $alt_text );
        $alt_text = trim( $alt_text, " \t\n\r\0\x0B\"'“”‘’" );

        if ( '' === $alt_text ) {
            return '';
        }

        $max_length = ( 'en_US' === $language ) ? 80 : 32;
        if ( mb_strlen( $alt_text ) > $max_length ) {
            $alt_text = $this->truncate_multibyte( $alt_text, $max_length );
        }

        return trim( $alt_text );
    }

    private function clean_text_fragment( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( $text );
    }

    private function truncate_multibyte( $text, $limit ) {
        $text = trim( (string) $text );
        if ( '' === $text ) {
            return '';
        }

        if ( mb_strlen( $text ) <= $limit ) {
            return $text;
        }

        return rtrim( mb_substr( $text, 0, $limit ) );
    }

    /**
     * 从文章内容快速检测语言（用于低质量ALT判断，无需完整上下文）
     */
    private function detect_language_from_content( $content ) {
        $sample = wp_strip_all_tags( (string) $content );
        if ( '' === $sample || preg_match( '/[\x{4e00}-\x{9fa5}]/u', $sample ) ) {
            return 'zh_CN';
        }
        return 'en_US';
    }

    private function detect_source_from_url( $url ) {
        $url = strtolower( (string) $url );

        if ( false !== strpos( $url, 'pexels.com' ) ) {
            return 'Pexels';
        }
        if ( false !== strpos( $url, 'unsplash.com' ) ) {
            return 'Unsplash';
        }
        if ( false !== strpos( $url, 'pixabay.com' ) ) {
            return 'Pixabay';
        }
        if ( false !== strpos( $url, 'openai.com' ) || false !== strpos( $url, 'oaidalleapiprodscus' ) ) {
            return 'DALL-E';
        }

        return '';
    }
}
