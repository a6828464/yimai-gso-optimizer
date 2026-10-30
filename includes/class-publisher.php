<?php
/**
 * 文章发布类
 * 修复：get_date_from_gmt() 使用方向修正（本地时间→UTC 应该用 get_gmt_from_date）
 * 性能优化：download_and_replace_images 支持跳过已上传图片，缩短下载超时
 * 跳过已有ALT的图片处理、图片下载超时10秒
 * 智能内链恢复同步执行（WP-Cron异步不可靠导致候选数为0）
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Publisher {

    /**
     * 发布后自动摘要异步队列 cron 钩子
     * 自动摘要从 publish() 内的同步调用改为 WP-Cron 异步执行，
     * 避免永动发布 publish() 卡在 AI 摘要调用上导致 item 永久卡 running。
     */
    const ASYNC_EXCERPT_HOOK = 'yimai_gso_async_excerpt_generate';

    /**
     * 注册异步摘要 cron 处理钩子（在插件初始化时调用）
     */
    public static function register_async_excerpt_hook() {
        add_action( self::ASYNC_EXCERPT_HOOK, array( __CLASS__, 'async_generate_excerpt' ), 10, 1 );
    }

    /**
     * 异步生成文章摘要（WP-Cron 回调）
     * 从 publish() 主流程剥离，避免同步 AI 调用阻塞发布，导致永动发布 item 卡 running。
     *
     * @param int $post_id
     */
    public static function async_generate_excerpt( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        // 与 maybe_auto_generate_excerpt 相同的开关检查
        $post = get_post( $post_id );
        if ( ! $post || ! empty( $post->post_excerpt ) ) {
            return;
        }
        $auto_excerpt = YIMAI_GSO_Settings::get( 'auto_excerpt_on_publish', 0 );
        if ( ! $auto_excerpt ) {
            return;
        }
        $api_key = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
        if ( empty( $api_key ) ) {
            return;
        }

        // 设置超时，防止 cron 进程被 AI 卡死
        ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 180 );
        }

        try {
            require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-ai-generator.php';
            $generator = new YIMAI_GSO_AI_Generator();
            $excerpt   = $generator->generate_excerpt( $post );

            if ( ! is_wp_error( $excerpt ) && ! empty( $excerpt ) ) {
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_excerpt' => $excerpt,
                ), true );
            }
        } catch ( Exception $e ) {
            // 自动摘要生成失败不影响发布流程，静默忽略
        }
    }

    /**
     * 应用发布时间策略（随机偏移 + 工作时间限制 + 跳过日期）
     * 在 publish() 中定时发布前调用
     *
     * @param string $scheduled_at  原始定时发布时间（datetime-local格式）
     * @param int    $article_index 文章序号（0起），用于每篇文章独立随机
     * @return string 处理后的 datetime-local 格式时间
     */
    private function apply_time_strategy( $scheduled_at, $article_index = 0 ) {
        $normalized = $this->normalize_datetime( $scheduled_at );
        if ( empty( $normalized ) ) {
            return $scheduled_at;
        }

        $tz  = wp_timezone();
        $dto = new DateTimeImmutable( $normalized, $tz );

        // 0. 顺序偏移：同一次批量中，每篇文章间隔2分钟，避免全部堆在同一时刻
        // $article_index 在永动发布分批执行时会累积之前的 success/failed count，
        // 上限 10 篇（20 分钟），防止多批次后偏移膨胀到数小时。
        if ( $article_index > 0 ) {
            $capped_index = min( absint( $article_index ), 10 );
            $sequential_seconds = $capped_index * 120;
            $dto = $dto->modify( "+{$sequential_seconds} seconds" );
        }

        // 1. 时间随机偏移
        $jitter = absint( YIMAI_GSO_Settings::get( 'publish_time_jitter', 0 ) );
        if ( $jitter > 0 ) {
            // 用文章序号做种子，让同一次批量中每篇的偏移不同但可复现
            $random_offset = wp_rand( -$jitter, $jitter );
            $dto = $dto->modify( "{$random_offset} seconds" );
        }

        // 2. 仅在工作时间（8:00-23:00）
        $work_hours = (bool) YIMAI_GSO_Settings::get( 'publish_work_hours_only', 0 );
        if ( $work_hours ) {
            $hour = intval( $dto->format( 'H' ) );
            $minute = intval( $dto->format( 'i' ) );
            $second = intval( $dto->format( 's' ) );
            $total_seconds = $hour * 3600 + $minute * 60 + $second;

            if ( $total_seconds < 8 * 3600 ) {
                // 早于8:00，移到8:00 + 随机偏移
                $dto = $dto->setTime( 8, 0, 0 )->modify( '+' . wp_rand( 0, 1800 ) . ' seconds' );
            } elseif ( $total_seconds >= 23 * 3600 ) {
                // 晚于23:00，移到第二天8:00
                $dto = $dto->modify( '+1 day' )->setTime( 8, 0, 0 )->modify( '+' . wp_rand( 0, 1800 ) . ' seconds' );
            }
        }

        // 3. 跳过指定日期
        $skip_days = YIMAI_GSO_Settings::get( 'publish_skip_days', array() );
        if ( is_array( $skip_days ) && ! empty( $skip_days ) ) {
            $day_of_week = intval( $dto->format( 'w' ) ); // 0=Sunday, 1=Monday ...
            $max_retries = 14; // 最多顺延14天
            while ( in_array( $day_of_week, $skip_days, false ) && $max_retries > 0 ) {
                $dto = $dto->modify( '+1 day' );
                $day_of_week = intval( $dto->format( 'w' ) );
                $max_retries--;
            }
        }

        return $dto->format( 'Y-m-d\TH:i' );
    }

    /**
     * 把各种格式（2026/03/27 15:06、2026-03-27T15:06 等）统一转为 Y-m-d H:i:s
     *
     * 修复说明
     * 原来使用 strtotime() + date() 会受 PHP 服务器时区影响（很多主机默认 UTC），
     * 导致传入的北京时间被按 UTC 解析，再经 get_gmt_from_date() 二次偏移，
     * 最终 UTC 时间比实际早8小时，触发"必须是未来时间"错误，造成定时发布失败。
     * 修复：改用 WordPress 时区安全的 DateTimeImmutable + wp_timezone()。
     */
    private function normalize_datetime( $dt_str ) {
        if ( empty( $dt_str ) ) return '';

        // 把 datetime-local 格式的 T 分隔符替换为空格
        $dt_str = str_replace( 'T', ' ', $dt_str );
        // 把斜杠替换为横杠（strtotime 对斜杠的解析和美国日期格式冲突）
        $dt_str = str_replace( '/', '-', $dt_str );
        // 去掉中文12小时制的"上午""下午"
        $dt_str = str_replace( '上午', '', $dt_str );
        $dt_str = str_replace( '下午', '', $dt_str );
        // 秒数如果缺省则补齐
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', trim( $dt_str ) ) ) {
            $dt_str .= ':00';
        }
        $dt_str = trim( $dt_str );

        // 使用 WordPress 时区（wp_timezone() 读取 WP 后台时区设置，不依赖 PHP 服务器时区）
        try {
            $tz  = wp_timezone();
            $dto = new DateTimeImmutable( $dt_str, $tz );
            if ( ! $dto ) return '';
            // 返回的是在 WP 本地时区下的标准格式字符串
            return $dto->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    /**
     * 预校验定时发布时间并返回本地时间 / UTC 时间
     *
     * @param string $scheduled_at
     * @return array|WP_Error
     */
    public function validate_scheduled_at( $scheduled_at ) {
        $normalized = $this->normalize_datetime( $scheduled_at );
        if ( empty( $normalized ) ) {
            return new WP_Error( 'invalid_date', '无法解析定时发布时间：' . $scheduled_at );
        }

        $gmt_date = get_gmt_from_date( $normalized, 'Y-m-d H:i:s' );
        //  修复：strtotime() 依赖 PHP 默认时区，若时区非 UTC 会误将 GMT 时间当本地时间解析，
        // 导致 timestamp 偏差（东八区偏移 8h → 被误判为过去时间 → 定时发布失败）。
        // 改用 DateTimeImmutable + 显式 UTC 时区，消除时区依赖。
        try {
            $gmt_dto = new DateTimeImmutable( $gmt_date, new DateTimeZone( 'UTC' ) );
            $gmt_ts  = $gmt_dto->getTimestamp();
        } catch ( Exception $e ) {
            $gmt_ts = 0;
        }
        // 允许 120 秒缓冲：AI生成+配图下载+批量耗时可能导致校验时时间已过，但 wp_insert_post 内部仍有校验
        if ( ! $gmt_ts || $gmt_ts <= time() + 120 ) {
            $wp_now = current_time( 'Y-m-d H:i:s' );
            return new WP_Error(
                'invalid_date',
                '定时发布时间必须是未来时间（当前WordPress时间：' . $wp_now . '，你设置的时间：' . $normalized . '，对应的UTC：' . $gmt_date . '）'
            );
        }

        return array(
            'local' => $normalized,
            'gmt'   => $gmt_date,
        );
    }

    /**
     * 发布或保存文章
     */
    public function publish( $data ) {
        $source = sanitize_text_field( $data['source'] ?? 'batch' );
        $title        = sanitize_text_field( $data['title'] ?? '' );
        // 安全兜底：截断 prompt 指令泄漏到标题中的内容（补充要求/字数要求等）
        $title        = $this->sanitize_article_title( $title );
        // 使用 wp_kses_post 过滤HTML：允许正常标签（img/a/p/h1-h6等），禁止危险标签（script/iframe/on*事件）
        $content      = wp_kses_post( $data['content'] ?? '' );
        $content      = self::normalize_table_styles( $content );
        $excerpt      = sanitize_textarea_field( $data['excerpt'] ?? '' );
        $category_id  = absint( $data['category_id'] ?? get_option( 'yimai_gso_default_category', 1 ) );
        $tags         = sanitize_text_field( $data['tags'] ?? '' );
        $status       = sanitize_text_field( $data['status'] ?? 'draft' );
        $scheduled_at = sanitize_text_field( $data['scheduled_at'] ?? '' );
        $thumbnail_id = absint( $data['thumbnail_id'] ?? 0 );
        $keywords     = sanitize_text_field( $data['keywords'] ?? '' );
        // 扩展记录字段
        $prompt       = sanitize_textarea_field( $data['prompt'] ?? '' );
        $word_count   = absint( $data['word_count'] ?? 0 );
        $image_count  = absint( $data['image_count'] ?? 0 );
        $ai_model     = sanitize_text_field( $data['ai_model'] ?? '' );
        $article_index = absint( $data['article_index'] ?? 0 ); // 文章序号（用于时间策略随机偏移）
        $auto_excerpt  = ! empty( $data['auto_excerpt'] );     // 是否自动生成AI摘要
        $auto_seo      = ! empty( $data['auto_seo'] );         // 是否自动提取SEO元数据

        if ( empty( $title ) || empty( $content ) ) {
            return new WP_Error( 'missing_data', '标题和正文不能为空' );
        }

        // ===== AI 生图 Phase 2：生成阶段跳过的剩余图片，现在生成并注入 =====
        $pending_prompts = isset( $data['pending_image_prompts'] ) ? $data['pending_image_prompts'] : array();
        if ( ! empty( $pending_prompts ) && class_exists( 'YIMAI_GSO_AI_Image_Generator' ) ) {
            $ai_gen = new YIMAI_GSO_AI_Image_Generator();
            $images = array();
            $section_map = array();
            $image_keyword = $keywords ? $keywords : $prompt;

            foreach ( $pending_prompts as $pp ) {
                $prompt_text = is_array( $pp ) ? ( $pp['prompt'] ?? '' ) : $pp;
                $section_name = is_array( $pp ) ? ( $pp['section'] ?? '' ) : '';

                if ( empty( $prompt_text ) ) {
                    continue;
                }

                $gen_result = $ai_gen->generate( $prompt_text, 1, array(
                    'title'   => $title,
                    'keyword' => $image_keyword,
                    'section' => $section_name,
                ) );

                if ( ! is_wp_error( $gen_result ) && ! empty( $gen_result ) ) {
                    $images[] = $gen_result[0];
                    $section_map[] = $section_name;
                }
            }

            if ( ! empty( $images ) ) {
                // 累加图片计数
                $image_count += count( $images );
                // 按章节匹配注入
                $img_idx = 0;
                $total_pending = count( $images );
                $content = preg_replace_callback(
                    '/<h2[^>]*>(.*?)<\/h2>/i',
                    function ( $h2_match ) use ( $images, $section_map, &$img_idx, $total_pending ) {
                        $tag = $h2_match[0];
                        if ( $img_idx >= $total_pending ) {
                            return $tag;
                        }
                        $img = $images[ $img_idx ];
                        $img_tag = '<p><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ?? '' ) . '" /></p>';
                        $img_idx++;
                        return $tag . "\n" . $img_tag;
                    },
                    $content
                );
                // 如果图片比 H2 多，剩余图片追加到末尾
                while ( $img_idx < $total_pending ) {
                    $img = $images[ $img_idx ];
                    $content .= "\n" . '<p><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ?? '' ) . '" /></p>';
                    $img_idx++;
                }
            }
        }

        // ===== 核心：把正文中的外链图片下载到本地媒体库并替换URL =====
        // 统一处理所有图片（包括封面），每张图只上传一次
        $download_result = $this->download_and_replace_images( $content, array(), array(
            'title'    => $title,
            'keyword'  => $keywords ? $keywords : $prompt,
            'excerpt'  => $excerpt,
            'language' => $data['language'] ?? YIMAI_GSO_Settings::get( 'article_language', 'zh_CN' ),
            'source'   => $source,
        ) );
        $content        = $download_result['content'];


        // 如果调用方没传 thumbnail_id，从正文第一张图自动取
        if ( $thumbnail_id === 0 && $download_result['first_attach_id'] > 0 ) {
            $thumbnail_id = $download_result['first_attach_id'];
        }

        // 智能内链已改为发布后自动执行（transition_post_status 钩子），不再在发布前处理

        // 构建 post data
        $post_data = array(
            'post_title'    => $title,
            'post_content'  => $content,
            'post_excerpt'  => $excerpt,
            'post_status'   => 'draft',
            'post_author'   => get_current_user_id(),
            'post_category' => array( $category_id ),
        );

        // 定时发布
        if ( $status === 'future' && ! empty( $scheduled_at ) ) {
            // 应用发布时间策略（随机偏移 + 工作时间 + 跳过日期）
            $scheduled_at = $this->apply_time_strategy( $scheduled_at, $article_index );

            // 1. 先做统一校验，拿到本地时间和 UTC 时间
            $schedule_check = $this->validate_scheduled_at( $scheduled_at );
            if ( is_wp_error( $schedule_check ) ) {
                return $schedule_check;
            }

            // 2. 写入 post_date（本地时间）和 post_date_gmt（UTC时间）
            $post_data['post_date']     = $schedule_check['local'];
            $post_data['post_date_gmt'] = $schedule_check['gmt'];
            $post_data['post_status']   = 'future';
            $post_data['edit_date']     = true;
        } else {
            $post_data['post_status'] = ( $status === 'publish' ) ? 'publish' : 'draft';
        }

        // 插入文章
        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // 设置标签
        if ( ! empty( $tags ) ) {
            $tag_arr = array_map( 'trim', explode( ',', $tags ) );
            wp_set_post_tags( $post_id, $tag_arr );
        }

        // 设置封面图
        if ( $thumbnail_id > 0 ) {
            set_post_thumbnail( $post_id, $thumbnail_id );
        }

        // 子比主题扩展字段 + AI生成记录
        $this->set_zibll_meta( $post_id, $keywords, $excerpt, $thumbnail_id, $source, $prompt, $ai_model, $word_count, $image_count, $title );

        // 存储结构化数据类型（AI 生成时自动判断或默认 Article）
        $schema_type = sanitize_text_field( $data['schema_type'] ?? '' );
        if ( empty( $schema_type ) ) {
            $schema_type = 'Article';
        }
        update_post_meta( $post_id, 'yimai_gso_schema_type', $schema_type );

        // 发布后自动生成 AI 摘要（仅当用户勾选时）
        if ( $auto_excerpt && ( 'publish' === $post_data['post_status'] || 'future' === $post_data['post_status'] ) ) {
            $this->maybe_auto_generate_excerpt( $post_id );
        }

        // 自动提取SEO元数据（Meta Title / Description）
        if ( $auto_seo && ! empty( $keywords ) ) {
            $plain_text = wp_strip_all_tags( $content, true );
            $plain_text = mb_substr( $plain_text, 0, 200 );

            if ( function_exists( 'get_plugin_data' ) && ! function_exists( 'yimai_gso_auto_seo_meta' ) ) {
                // 使用关键词生成 meta description
                $meta_desc = mb_substr( $plain_text, 0, 150 );
                update_post_meta( $post_id, '_seo_description', $meta_desc );
                update_post_meta( $post_id, 'description', $meta_desc ); // 子比主题
            }
        }

        return array(
            'post_id'    => $post_id,
            'post_url'   => get_permalink( $post_id ),
            'edit_url'   => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'status'     => $post_data['post_status'],
            'title'      => $title,
        );
    }

    /**
     * 核心方法：扫描正文中的外链图片，下载到本地媒体库并替换URL
     * 每张图只上传一次，返回处理后的正文和第一张图的 attachment_id（用于封面）
     *
     * @param string $content     文章正文HTML
     * @param array  $skip_urls   需要跳过的已上传图片URL列表（避免重复下载）
     * @param array  $context     文章上下文
     * @return array  [ 'content' => string, 'first_attach_id' => int ]
     */
    private function download_and_replace_images( $content, $skip_urls = array(), $context = array() ) {
        // ALT 生成已改为 transition_post_status 钩子（优先级10），不再在发布流程中处理

        $result = array( 'content' => $content, 'first_attach_id' => 0, 'download_errors' => array() );


        // 匹配所有 <img src="xxx"> 标签
        $pattern = '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>/i';
        
        if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            return $result; // 正文中没有图片
        }

        $handler   = new YIMAI_GSO_Image_Handler();
        $replaced  = array(); // 避免重复下载同一张图

        foreach ( $matches as $match ) {
            $full_tag  = $match[0];     // 完整的 <img ...> 标签
            $img_url   = html_entity_decode( $match[1], ENT_QUOTES ); // 解码 &amp; → &（豆包URL含签名参数）

            // 跳过已经是本地的图片（用 attachment_url_to_postid 精确匹配，避免协议/域名差异导致误判）
            $existing_attach_id = attachment_url_to_postid( $img_url );
            if ( $existing_attach_id > 0 ) {
                if ( $result['first_attach_id'] === 0 ) {
                    $result['first_attach_id'] = $existing_attach_id;
                }
                continue;
            }

            // 跳过base64图片
            if ( strpos( $img_url, 'data:image' ) === 0 ) {
                continue;
            }

            // 跳过已经上传过的图片
            foreach ( $skip_urls as $local_url ) {
                if ( $local_url && strpos( $img_url, $local_url ) !== false ) {
                    continue 2;
                }
            }

            // 同一张图不重复下载
            if ( isset( $replaced[ $img_url ] ) ) {
                $result['content'] = str_replace( $full_tag, $replaced[ $img_url ]['tag'], $result['content'] );
                if ( $result['first_attach_id'] === 0 && $replaced[ $img_url ]['attach_id'] ) {
                    $result['first_attach_id'] = $replaced[ $img_url ]['attach_id'];
                }
                continue;
            }

            // 提取 alt 属性
            $alt = '';
            if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $full_tag, $alt_match ) ) {
                $alt = $alt_match[1];
            }

            // 下载并上传到媒体库（超时从 60s 缩短为 20s，单张图 20s 足够，
            // 慢图直接跳过，避免多张图累计几百秒拖垮整个发布流程导致 PHP 超时进程被杀）
            $attach_id = $handler->upload_image_to_media( $img_url, '', $alt, 20 );

            if ( ! is_wp_error( $attach_id ) ) {
                $local_url = wp_get_attachment_url( $attach_id );
                
                // 替换为新标签（使用本地URL）
                $new_tag = str_replace( $img_url, $local_url, $full_tag );
                $result['content'] = str_replace( $full_tag, $new_tag, $result['content'] );
                $replaced[ $img_url ] = array( 'tag' => $new_tag, 'attach_id' => $attach_id );

                // 记录第一张成功上传的图片ID（用于封面）
                if ( $result['first_attach_id'] === 0 ) {
                    $result['first_attach_id'] = $attach_id;
                }
            } else {
                $err_msg = $attach_id->get_error_message();
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[YIMAI_GSO_PUB] 封面下载失败: ' . substr( $img_url, 0, 80 ) . ' → ' . $err_msg );
                }
                $result['download_errors'][] = $err_msg;
            }
        }

        return $result;
    }

    /**
     * 公共SEO字段写回（子比 / Yoast / Rank Math）
     *
     * @param int    $post_id
     * @param string $keywords
     * @param string $excerpt
     * @param string $title
     * @return void
     */
    public static function write_seo_fields( $post_id, $keywords, $excerpt, $title = '' ) {
        $post_id  = absint( $post_id );
        $keywords = sanitize_text_field( $keywords );
        $excerpt  = sanitize_textarea_field( $excerpt );
        $title    = sanitize_text_field( $title );

        if ( $post_id <= 0 ) {
            return;
        }

        if ( ! empty( $keywords ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keywords );
            update_post_meta( $post_id, 'rank_math_focus_keyword', $keywords );
            update_post_meta( $post_id, 'keywords', $keywords );
        }

        if ( ! empty( $excerpt ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $excerpt );
            update_post_meta( $post_id, 'rank_math_description', $excerpt );
            update_post_meta( $post_id, 'description', $excerpt );
            // A1：本站没装 Yoast / Rank Math，主题只认插件自己的 yimai_gso_excerpt。
            // 摘要必须在发布时直写，否则 AI 生成的描述要等后台手动点「修复」才会上前台。
            update_post_meta( $post_id, 'yimai_gso_excerpt', $excerpt );
        }

        if ( ! empty( $title ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $title );
            update_post_meta( $post_id, 'rank_math_title', $title );
            update_post_meta( $post_id, 'title', $title );
            // A1：主题不读 Yoast / Rank Math 的标题 meta，必须落一份插件自己的标题键，
            // 否则 AI 生成的 SEO 标题只停在数据库里，前台永远拿不到。
            update_post_meta( $post_id, 'yimai_gso_seo_title', $title );
        }
    }

    /**
     * 设置子比主题专属 Meta 字段（v8.6 兼容）
     */
    private function set_zibll_meta( $post_id, $keywords, $excerpt, $thumbnail_id, $source = 'batch', $prompt = '', $ai_model = '', $word_count = 0, $image_count = 0, $title = '' ) {
        // 子比主题文章头图
        if ( $thumbnail_id > 0 ) {
            update_post_meta( $post_id, 'zib_post_image', $thumbnail_id );
        } elseif ( has_post_thumbnail( $post_id ) ) {
            update_post_meta( $post_id, 'zib_post_image', get_post_thumbnail_id( $post_id ) );
        }

        // 积分/付费（默认免费）
        update_post_meta( $post_id, 'zib_post_coins', 0 );
        update_post_meta( $post_id, 'zib_post_password', '' );

        self::write_seo_fields( $post_id, $keywords, $excerpt, $title );

        // AI生成标记
        update_post_meta( $post_id, 'yimai_gso_generated', 1 );
        update_post_meta( $post_id, 'yimai_gso_generated_at', current_time( 'mysql' ) );

        // 关键词存储（供SEO评分、Schema、标题分析使用）
        if ( ! empty( $keywords ) ) {
            update_post_meta( $post_id, 'yimai_gso_keywords', $keywords );
        }

        // 扩展记录：生成来源、提示词、AI模型、字数、图片数
        update_post_meta( $post_id, 'yimai_gso_source', $source );
        update_post_meta( $post_id, 'yimai_gso_prompt', ( function_exists( 'mb_substr' ) ? mb_substr( $prompt, 0, 500 ) : substr( $prompt, 0, 500 ) ) );
        update_post_meta( $post_id, 'yimai_gso_ai_model', $ai_model );
        update_post_meta( $post_id, 'yimai_gso_word_count', $word_count );
        update_post_meta( $post_id, 'yimai_gso_image_count', $image_count );
    }


    /**
     * 单篇文章发布
     * @param array $params {
     *   @type string $title     文章标题
     *   @type string $content   文章内容（HTML格式）
     *   @type string $excerpt   文章摘要
     *   @type int    $category  分类ID
     *   @type string $tags     标签（逗号分隔）
     *   @type string $status   状态：draft/publish
     * }
     * @return array|WP_Error
     */
    /**
     * 给正文表格补内联样式（主题通常不给文章内 table 加边框，导致表格「没有框」）。
     * 只补缺失的样式，不覆盖已有的自定义样式。
     */
    public static function normalize_table_styles( $content ) {
        if ( ! is_string( $content ) || false === stripos( $content, '<table' ) ) {
            return $content;
        }
        $base = 'border-collapse:collapse;width:100%;margin:16px 0;font-size:14px;';

        $content = preg_replace_callback( '/<table\b([^>]*)>/i', function ( $m ) use ( $base ) {
            $attrs = $m[1];
            if ( preg_match( '/style\s*=\s*(["\x27])(.*?)\1/is', $attrs ) ) {
                $attrs = preg_replace( '/style\s*=\s*(["\x27])(.*?)\1/is', 'style="' . $base . '$2"', $attrs, 1 );
            } else {
                $attrs .= ' style="' . $base . '"';
            }
            return '<table' . $attrs . '>';
        }, $content );

        foreach ( array( 'th', 'td' ) as $cell ) {
            $extra = ( 'th' === $cell )
                ? 'border:1px solid #d9d9d9;padding:8px 10px;background:#f6f7f7;text-align:left;font-weight:600;'
                : 'border:1px solid #d9d9d9;padding:8px 10px;';
            $content = preg_replace_callback( '/<' . $cell . '\b([^>]*)>/i', function ( $m ) use ( $extra, $cell ) {
                $attrs = $m[1];
                if ( preg_match( '/style\s*=\s*(["\x27])(.*?)\1/is', $attrs ) ) {
                    $attrs = preg_replace( '/style\s*=\s*(["\x27])(.*?)\1/is', 'style="' . $extra . '$2"', $attrs, 1 );
                } else {
                    $attrs .= ' style="' . $extra . '"';
                }
                return '<' . $cell . $attrs . '>';
            }, $content );
        }

        return $content;
    }

    public function publish_single( $params = array() ) {
        $title = sanitize_text_field( $params['title'] ?? '' );
        // 使用 wp_kses_post 过滤HTML，防止XSS但保留正常标签
        $content = wp_kses_post( $params['content'] ?? '' );
        $content = self::normalize_table_styles( $content );
        $excerpt = sanitize_textarea_field( $params['excerpt'] ?? '' );
        $category = absint( $params['category'] ?? 0 );
        $tags = sanitize_text_field( $params['tags'] ?? '' );
        $status = sanitize_text_field( $params['status'] ?? 'draft' );
        $keywords = sanitize_text_field( $params['keywords'] ?? '' );

        if ( empty( $title ) || empty( $content ) ) {
            return new WP_Error( 'missing_data', '标题和内容不能为空' );
        }

        // 确保运行时用户上下文（防止 Cron/REST 环境下用户 ID 为 0）
        if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
            YIMAI_GSO_Autopilot_Runner::bootstrap_runtime_user( 0 );
        }

        // 验证分类
        if ( $category > 0 ) {
            $cat = get_category( $category );
            if ( ! $cat || is_wp_error( $cat ) ) {
                $category = 0;
            }
        }

        // 处理标签
        $tag_ids = array();
        if ( ! empty( $tags ) ) {
            $tag_names = array_map( 'trim', explode( ',', $tags ) );
            foreach ( $tag_names as $tag_name ) {
                if ( empty( $tag_name ) ) continue;
                $tag = wp_insert_term( $tag_name, 'post_tag' );
                if ( ! is_wp_error( $tag ) ) {
                    $tag_ids[] = (int) $tag['term_id'];
                }
            }
        }

        // 下载并替换图片
        require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-handler.php';
        $image_handler = new YIMAI_GSO_Image_Handler();
        $download_result = $image_handler->download_and_replace_images( $content, array(), array(
            'title'    => $title,
            'keyword'  => $tags,
            'excerpt'  => $excerpt,
            'language' => YIMAI_GSO_Settings::get( 'article_language', 'zh_CN' ),
            'source'   => 'single',
            'skip_alt' => true, // ALT 由 transition_post_status 钩子统一处理
        ) );
        $content = $download_result['content'];


        // 获取封面图ID
        $thumbnail_id = 0;
        if ( ! empty( $download_result['first_attach_id'] ) && $download_result['first_attach_id'] > 0 ) {
            $thumbnail_id = $download_result['first_attach_id'];
        }

        // 智能内链已改为发布后自动执行（transition_post_status 钩子），不再在发布前处理

        // 处理SEO关键词和描述
        $seo_desc = $excerpt;

        // 构建文章数据
        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $status === 'publish' ? 'publish' : 'draft',
            'post_author'  => get_current_user_id(),
            'post_category'=> $category > 0 ? array( $category ) : array(),
            'tags_input'   => $tag_ids,
        );

        // 插入文章
        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // 设置封面图
        if ( $thumbnail_id > 0 ) {
            set_post_thumbnail( $post_id, $thumbnail_id );
        }

        self::write_seo_fields( $post_id, $keywords, $seo_desc, $title );

        // AI生成标记与扩展记录
        update_post_meta( $post_id, 'yimai_gso_generated', 1 );
        update_post_meta( $post_id, 'yimai_gso_generated_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'yimai_gso_source', sanitize_text_field( $params['source'] ?? 'single' ) );
        update_post_meta( $post_id, 'yimai_gso_word_count', ( function_exists( 'mb_strlen' ) ? mb_strlen( preg_replace( '/\s+/', '', strip_tags( $content ) ) ) : strlen( preg_replace( '/\s+/', '', strip_tags( $content ) ) ) ) );
        update_post_meta( $post_id, 'yimai_gso_image_count', max( absint( $params['image_count'] ?? 0 ), absint( $download_result['count'] ?? 0 ) ) );
        update_post_meta( $post_id, 'yimai_gso_prompt', ( function_exists( 'mb_substr' ) ? mb_substr( $params['prompt'] ?? '', 0, 500 ) : substr( $params['prompt'] ?? '', 0, 500 ) ) );
        update_post_meta( $post_id, 'yimai_gso_ai_model', sanitize_text_field( $params['ai_model'] ?? '' ) );
        if ( ! empty( $keywords ) ) {
            update_post_meta( $post_id, 'yimai_gso_keywords', $keywords );
        }
        // 子比主题扩展字段
        if ( $thumbnail_id > 0 ) {
            update_post_meta( $post_id, 'zib_post_image', $thumbnail_id );
        } elseif ( has_post_thumbnail( $post_id ) ) {
            update_post_meta( $post_id, 'zib_post_image', get_post_thumbnail_id( $post_id ) );
        }
        update_post_meta( $post_id, 'zib_post_coins', 0 );
        update_post_meta( $post_id, 'zib_post_password', '' );
        // 存储结构化数据类型
        $schema_type = sanitize_text_field( $params['schema_type'] ?? 'Article' );
        update_post_meta( $post_id, 'yimai_gso_schema_type', $schema_type );

        // 发布后自动生成 AI 摘要
        if ( 'publish' === $post_data['post_status'] ) {
            $this->maybe_auto_generate_excerpt( $post_id );
        }

        return array(
            'post_id'    => $post_id,
            'post_url'   => get_permalink( $post_id ),
            'edit_url'   => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'status'     => $post_data['post_status'],
            'title'      => $title,
        );
    }

    /**
     * 发布后自动生成 AI 摘要
     * 仅在 post_excerpt 为空且开启了自动生成时执行
     *
     * 改为异步调度。原实现是 publish() 内同步 AI 调用，
     * 永动发布 publish() 会被 AI 摘要卡住，导致 run_single_item 里
     * update_item(success) 永远执行不到 → item 永久卡 running。
     * 现在只做开关检查 + 入队，AI 调用由 WP-Cron 异步执行。
     *
     * @param int $post_id 文章ID
     */
    private function maybe_auto_generate_excerpt( $post_id ) {
        // 检查是否已存在摘要
        $post = get_post( $post_id );
        if ( ! $post || ! empty( $post->post_excerpt ) ) {
            return;
        }

        // 检查是否开启了自动摘要生成（默认关闭）
        $auto_excerpt = YIMAI_GSO_Settings::get( 'auto_excerpt_on_publish', 0 );
        if ( ! $auto_excerpt ) {
            return;
        }

        // 检查 API Key 是否配置
        $api_key = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
        if ( empty( $api_key ) ) {
            return;
        }

        // 异步化 — 不再同步调用 AI，入队后由 WP-Cron 异步处理，
        // 发布主流程（publish()）立即返回，永动发布 item 不再被 AI 摘要卡死。
        if ( ! wp_next_scheduled( self::ASYNC_EXCERPT_HOOK, array( $post_id ) ) ) {
            wp_schedule_single_event( time() + 5, self::ASYNC_EXCERPT_HOOK, array( $post_id ) );
        }
    }

    /**
     * 定时发布守护进程（WP-Cron 每30分钟触发）
     * 扫描已过期的 future 文章，异步派发到独立请求中执行发布
     * 仅处理带有 yimai_gso_generated 标记的文章（插件生成的文章）
     */
    public static function guardian_publish() {
        global $wpdb;

        // MySQL GET_LOCK 防并发派发（同一时刻只允许1个WP-Cron进程通过）
        $lock_name = 'yimai_gso_guardian_dispatch';
        $got_lock  = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 5)", $lock_name ) );
        if ( ! $got_lock ) {
            return;
        }

        // 距上次派发不足20分钟则跳过（与30分钟interval配合，留出窗口）
        if ( get_transient( 'yimai_gso_guardian_dispatched' ) ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return;
        }

        // 查询已过期的 future 文章（仅处理插件生成的）
        // 精准模式下单次处理上限更高
        $mode       = YIMAI_GSO_Settings::get( 'operation_mode', 'default' );
        $batch_size = ( $mode === 'precise' ) ? 200 : 50;

        $overdue_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_date_gmt
             FROM {$wpdb->posts} p
             WHERE p.post_status = 'future'
               AND p.post_date_gmt <= UTC_TIMESTAMP()
               AND p.post_type IN ('post', 'page')
               AND EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key = 'yimai_gso_generated'
                     AND pm.meta_value = '1'
               )
             ORDER BY p.post_date_gmt ASC
             LIMIT %d",
                $batch_size
            ),
            OBJECT
        );

        if ( empty( $overdue_posts ) ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return;
        }

        $post_ids = wp_list_pluck( $overdue_posts, 'ID' );

        /**
         * 统一使用 WP-Cron 调度（立即到期）
         * 精准模式下额外触发 wp-cron.php 以尽快执行待处理事件（带防抖）
         */
        $hook = 'yimai_gso_guardian_process';
        if ( ! wp_next_scheduled( $hook, array( $post_ids ) ) ) {
            wp_schedule_single_event( time(), $hook, array( $post_ids ) );
        }

        // 精准模式（DISABLE_WP_CRON=true）：触发 wp-cron.php 立即处理到期的定时任务（带防抖）
        $precise_mode = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        if ( $precise_mode ) {
            $last_trigger = get_transient( 'yimai_gso_guardian_cron_trigger' );
            if ( false === $last_trigger ) {
                $wp_cron_url = site_url( 'wp-cron.php' );
                wp_remote_get( $wp_cron_url, array(
                    'timeout'   => 3,
                    'blocking'  => false,
                ) );
                // 防抖：1秒内不重复触发
                set_transient( 'yimai_gso_guardian_cron_trigger', time(), 1 );
            }
        }

        // 标记已派发（3分钟有效，失败后能快速重试，与30分钟interval配合）
        set_transient( 'yimai_gso_guardian_dispatched', 1, 3 * MINUTE_IN_SECONDS );

        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
    }

    /**
     * 异步执行定时发布（由 wp_schedule_single_event 触发）
     *
     * @param array $post_ids 待发布的文章ID数组
     */
    public static function handle_guardian_process( $post_ids ) {
        if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
            return;
        }

        // 获取生成锁，防止与永动发布等任务同时执行
        $lock = YIMAI_GSO_Autopilot_Runner::acquire_generation_lock( 'guardian', 30 * MINUTE_IN_SECONDS );
        if ( is_wp_error( $lock ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                error_log( sprintf(
                    '[BaiqueAI Guardian] 获取生成锁失败: %s',
                    $lock->get_error_message()
                ) );
            }
            return;
        }

        // 无时间限制，防止发布过程中被中断
        ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 ); // 10 分钟上限，防止无限挂起
        }

        // 设置运行时用户上下文（wp_publish_post 需要 edit_post 权限）
        // 与永动发布的 bootstrap_runtime_user() 逻辑一致
        if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
            YIMAI_GSO_Autopilot_Runner::bootstrap_runtime_user( 0 );
        }

        $published = 0;
        $failed    = 0;
        $skipped   = 0;

        // 批次限流：每轮最多发布 20 篇，避免 transition_post_status 触发海量异步队列
        // 可通过 yimai_gso_guardian_batch_limit filter 自定义（设为 0 取消限制）
        $batch_limit = apply_filters( 'yimai_gso_guardian_batch_limit', 20 );

        foreach ( $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id <= 0 ) {
                continue;
            }

            // 检查重试次数，超过3次则放弃并改为草稿状态
            $retries = (int) get_post_meta( $post_id, '_yimai_gso_publish_retries', true );
            if ( $retries >= 3 ) {
                // 超过3次，改为草稿状态，避免无限重试
                wp_update_post( array(
                    'ID'          => $post_id,
                    'post_status' => 'draft',
                ) );
                update_post_meta( $post_id, '_yimai_gso_publish_failed', '1' );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                    error_log( sprintf(
                        '[BaiqueAI Guardian] 文章 #%d 发布失败超过3次，已改为草稿状态',
                        $post_id
                    ) );
                }
                $failed++;
                continue;
            }

            // 再次确认文章仍然是 future 状态（防止并发）
            $current_status = get_post_status( $post_id );
            if ( $current_status !== 'future' ) {
                $skipped++;
                continue;
            }

            $post_title = get_the_title( $post_id );

            // 双重权限检查：确保当前用户有编辑权限（Redis缓存可能导致bootstrap失败）
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
                    YIMAI_GSO_Autopilot_Runner::bootstrap_runtime_user( 0 );
                }
                if ( ! current_user_can( 'edit_post', $post_id ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                        error_log( sprintf(
                            '[BaiqueAI Guardian] 权限不足 #%d (用户ID=%d)，跳过',
                            $post_id,
                            get_current_user_id()
                        ) );
                    }
                    update_post_meta( $post_id, '_yimai_gso_publish_retries', $retries + 1 );
                    $failed++;
                    continue;
                }
            }

            // 调用 WordPress 核心函数发布文章
            $result = wp_publish_post( $post_id );

            if ( is_wp_error( $result ) ) {
                // 发布失败，增加重试计数
                update_post_meta( $post_id, '_yimai_gso_publish_retries', $retries + 1 );
                error_log( sprintf(
                    '[BaiqueAI Guardian] 发布失败 #%d (第%d次): %s',
                    $post_id,
                    $retries + 1,
                    $result->get_error_message()
                ) );
                $failed++;
                continue;
            }

            // 验证发布是否成功（强制清除缓存，避免Redis返回旧状态）
            clean_post_cache( $post_id );
            $new_status = get_post_status( $post_id );
            if ( $new_status === 'publish' ) {
                $published++;
                // 发布成功，清除重试计数
                delete_post_meta( $post_id, '_yimai_gso_publish_retries' );

                // 批次限流：达到上限停止本轮，剩余留到 30 分钟后自动处理
                if ( $batch_limit > 0 && $published >= $batch_limit ) {
                    break;
                }
                // 每发布 5 篇 sleep 2 秒，给 WP-Cron 喘息空间
                if ( $published > 0 && $published % 5 === 0 ) {
                    sleep( 2 );
                }

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
                    error_log( sprintf(
                        '[BaiqueAI Guardian] 成功发布 #%d: %s',
                        $post_id,
                        $post_title
                    ) );
                }
            } else {
                // 状态异常，增加重试计数
                update_post_meta( $post_id, '_yimai_gso_publish_retries', $retries + 1 );
                $failed++;
                error_log( sprintf(
                    '[BaiqueAI Guardian] 发布状态异常 #%d (第%d次): 期望 publish，实际 %s',
                    $post_id,
                    $retries + 1,
                    $new_status
                ) );
            }

            unset( $result );
        }

        // 释放生成锁
        YIMAI_GSO_Autopilot_Runner::release_generation_lock( 'guardian' );

        // 记录执行结果
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
            error_log( sprintf(
                '[BaiqueAI Guardian] 异步执行完成: 成功 %d, 失败 %d, 跳过 %d',
                $published,
                $failed,
                $skipped
            ) );
        }
    }

    /**
     * 安全兜底：清洗标题中的 prompt 指令泄漏（补充要求、字数要求等）
     *
     * AI 生成回退或 JSON 解析失败时，prompt 中的补充要求可能泄漏到标题中。
     * 此方法在发布前做最终截断，确保标题中不会暴露系统指令。
     */
    private function sanitize_article_title( $title ) {
        $title = trim( (string) $title );
        if ( '' === $title ) {
            return $title;
        }

        // 检测常见泄漏模式并按最早匹配截断
        $leak_patterns = array(
            '/\s*(补充要求|additional_requirements)\b/u',
            '/\s*(字数要求|你必须输出|如果字数不足|字数达标|请继续展开)\b/u',
            '/\s*(以上为|请输出|要求输出|输出要求)\b/u',
            '/\n{2,}.*$/s',
        );

        foreach ( $leak_patterns as $pat ) {
            if ( preg_match( $pat, $title, $m, PREG_OFFSET_CAPTURE ) ) {
                $cut_pos   = $m[0][1];
                $cut_title = trim( substr( $title, 0, $cut_pos ) );
                if ( ! empty( $cut_title ) ) {
                    error_log( '[一麦GSO] Publisher sanitized title: "' . substr( $title, $cut_pos, 80 ) . '"' );
                    return $cut_title;
                }
                break;
            }
        }

        return $title;
    }
}
