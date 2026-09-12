<?php
/**
 * 自动图片ALT后台管理类
 * 提供统计、批量处理和历史记录功能
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_9KKY {

    /**
     * AJAX：保存设置
     */
    public static function ajax_save_settings() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $enabled = isset( $_POST['enabled'] ) ? (int) $_POST['enabled'] : 1;
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'rule';
        $skip_existing = isset( $_POST['skip_existing'] ) ? (int) $_POST['skip_existing'] : 1;

        WPAIP_9XCU::set( 'auto_image_alt_enabled', $enabled );
        WPAIP_9XCU::set( 'auto_image_alt_mode', $mode );
        WPAIP_9XCU::set( 'auto_image_alt_skip_existing', $skip_existing );

        wp_send_json_success( array( 'message' => '设置已保存' ) );
    }

    /**
     * AJAX：批量处理
     */
    public static function ajax_batch_process() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $scope = isset( $_POST['scope'] ) ? sanitize_text_field( $_POST['scope'] ) : 'all';
        $category = isset( $_POST['category'] ) ? (int) $_POST['category'] : 0;
        $limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 20;
        $skip_has_alt = isset( $_POST['skip_has_alt'] ) ? (int) $_POST['skip_has_alt'] : 1;

        $options = array(
            'scope'        => $scope,
            'category'     => $category,
            'limit'        => $limit,
            'skip_has_alt' => $skip_has_alt,
            'mode'         => 'rule', // 默认使用规则模式
        );

        // 检查是否有 AI 模式权限
        if ( true && true ) {
            $current_mode = WPAIP_9XCU::get( 'auto_image_alt_mode', 'rule' );
            if ( $current_mode === 'ai' ) {
                $options['mode'] = 'ai';
            }
        }

        $results = self::batch_process( $options );

        wp_send_json_success( $results );
    }

    /**
     * AJAX：预估处理数量
     */
    public static function ajax_estimate_count() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        $scope = isset( $_POST['scope'] ) ? sanitize_text_field( $_POST['scope'] ) : 'all';
        $category = isset( $_POST['category'] ) ? (int) $_POST['category'] : 0;

        $count = self::estimate_count( array(
            'scope'   => $scope,
            'category' => $category,
        ) );

        wp_send_json_success( array( 'count' => $count ) );
    }

    /**
     * 获取统计数据
     */
    public static function get_stats() {
        global $wpdb;
        
        $pt_list = WPAIP_B7PQ::sql_type_list();
        
        // 获取已优化ALT的图片总数
        $total_optimized = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'wpaip_generated' AND pm1.meta_value = '1'
             INNER JOIN {$wpdb->posts} img_post ON img_post.post_parent = p.ID AND img_post.post_type = 'attachment' AND img_post.post_mime_type LIKE 'image/%'
             INNER JOIN {$wpdb->postmeta} pm2 ON img_post.ID = pm2.post_id AND pm2.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type IN ({$pt_list}) AND p.post_status = 'publish'"
        );
        
        // 获取本月新增优化数
        $month_start = wp_date( 'Y-m-01' );
        $month_new = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT pm3.post_id)
                 FROM {$wpdb->postmeta} pm3
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm3.post_id
                 WHERE pm3.meta_key = 'wpaip_alt_optimized_at'
                 AND pm3.meta_value >= %s
                 AND p.post_type IN ({$pt_list}) AND p.post_status = 'publish'",
                $month_start
            )
        );
        
        // 获取待处理的图片数（无ALT的图片）
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} img
             INNER JOIN {$wpdb->posts} p ON p.ID = img.post_parent
             WHERE img.post_type = 'attachment'
             AND img.post_mime_type LIKE 'image/%'
             AND p.post_type IN ({$pt_list})
             AND p.post_status = 'publish'
             AND img.ID NOT IN (
                 SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != ''
             )"
        );
        
        return array(
            'total_optimized' => $total_optimized,
            'month_new'       => $month_new,
            'pending'         => $pending,
        );
    }

    /**
     * 获取处理历史
     */
    public static function get_history( $limit = 20 ) {
        global $wpdb;
        
        $pt_list = WPAIP_B7PQ::sql_type_list();
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value as processed_at,
                        p.post_title, p.guid as post_url
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'wpaip_alt_batch_at'
                 AND p.post_type IN ({$pt_list}) AND p.post_status != 'trash'
                 ORDER BY pm.meta_value DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        
        $history = array();
        if ( ! is_array( $results ) ) {
            return $history;
        }
        foreach ( $results as $row ) {
            // 防御：确保关键字段存在且类型正确
            $post_id   = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
            $title     = isset( $row['post_title'] ) && is_string( $row['post_title'] ) ? $row['post_title'] : '';
            if ( $post_id <= 0 || $title === '' ) {
                continue;
            }
            $log_data = get_post_meta( $post_id, 'wpaip_alt_batch_log', true );
            // 如果 meta 值损坏（非数组且非空字符串），跳过
            if ( ! is_array( $log_data ) && $log_data !== '' ) {
                continue;
            }
            $history[] = array(
                'post_id'       => $post_id,
                'post_title'    => $title,
                'post_url'      => isset( $row['post_url'] ) ? $row['post_url'] : '',
                'processed_at'  => isset( $row['processed_at'] ) ? $row['processed_at'] : '',
                'image_count'   => is_array( $log_data ) ? count( $log_data ) : 0,
                'status'        => 'success',
            );
        }
        
        return $history;
    }

    /**
     * 获取待处理的文章列表
     */
    public static function get_pending_posts( $args = array() ) {
        $defaults = array(
            'category'  => 0,
            'tag'       => 0,
            'skip_has_alt' => true,
            'limit'     => 50,
            'offset'    => 0,
        );
        $args = wp_parse_args( $args, $defaults );
        
        global $wpdb;
        
        $pt_list = WPAIP_B7PQ::sql_type_list();
        
        $sql = "SELECT p.ID, p.post_title, p.guid as post_url,
                       (SELECT COUNT(*) FROM {$wpdb->posts} img 
                        WHERE img.post_parent = p.ID 
                        AND img.post_type = 'attachment' 
                        AND img.post_mime_type LIKE 'image/%'
                        %s) as image_count
                FROM {$wpdb->posts} p
                WHERE p.post_type IN ({$pt_list}) 
                AND p.post_status = 'publish'";
        
        $alt_condition = $args['skip_has_alt'] 
            ? " AND img.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != '')"
            : "";
        
        $sql = sprintf( $sql, $alt_condition );
        
        if ( $args['category'] > 0 ) {
            $sql .= $wpdb->prepare( " AND p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)", $args['category'] );
        }
        
        if ( $args['tag'] > 0 ) {
            $sql .= $wpdb->prepare( " AND p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)", $args['tag'] );
        }
        
        $limit  = absint( $args['limit'] );
        $offset = absint( $args['offset'] );
        $sql   .= $wpdb->prepare( " ORDER BY p.post_date DESC LIMIT %d OFFSET %d", $limit, $offset );
        
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * 批量处理图片ALT
     */
    public static function batch_process( $options = array() ) {
        $defaults = array(
            'post_ids'      => array(),
            'category'      => 0,
            'tag'           => 0,
            'skip_has_alt'  => true,
            'limit'         => 20,
            'mode'          => 'rule', // rule / ai
        );
        $options = wp_parse_args( $options, $defaults );
        
        // 获取要处理的文章
        if ( ! empty( $options['post_ids'] ) ) {
            $posts = get_posts( array(
                'post__in'       => $options['post_ids'],
                'post_type'      => WPAIP_B7PQ::get_supported(),
                'post_status'    => 'publish',
                'posts_per_page' => $options['limit'],
            ) );
        } else {
            $posts = get_posts( array(
                'category'       => $options['category'],
                'tag_id'         => $options['tag'],
                'post_type'      => WPAIP_B7PQ::get_supported(),
                'post_status'    => 'publish',
                'posts_per_page' => $options['limit'],
            ) );
        }
        
        // AI模式需要确保有 WPAIP_JJXL
        if ( $options['mode'] === 'ai' ) {
            if ( ! class_exists( 'WPAIP_JJXL' ) ) {
                $options['mode'] = 'rule';
            }
        }
        
        $results = array(
            'processed' => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'details'  => array(),
        );
        
        foreach ( $posts as $post ) {
            // 获取该文章的所有图片附件（一次查询，后续复用）
            // 修复：不限制 post_mime_type，在 PHP 中过滤，避免 WP_Query 通配符问题导致查不到图片
            $attachments = get_posts( array(
                'post_parent'    => $post->ID,
                'post_type'      => 'attachment',
                'posts_per_page' => 50, // 限制上限，避免单篇文章附件过多导致内存问题
            ) );

            $images = array();
            foreach ( $attachments as $att ) {
                if ( isset( $att->post_mime_type ) && strpos( $att->post_mime_type, 'image/' ) === 0 ) {
                    $images[] = $att;
                }
            }

            // 兜底：从文章内容中提取图片附件ID（处理 post_parent 未正确关联的情况）
            // WordPress 图片区块会自动添加 wp-image-{ID} CSS class
            $content_image_ids = array();
            $existing_ids      = wp_list_pluck( $images, 'ID' );
            if ( ! empty( $post->post_content ) && preg_match_all( '/wp-image-(\d+)/', $post->post_content, $content_matches ) ) {
                $content_image_ids = array_unique( array_map( 'intval', $content_matches[1] ) );
                $new_ids = array_diff( $content_image_ids, $existing_ids );
                if ( ! empty( $new_ids ) ) {
                    $content_attachments = get_posts( array(
                        'post__in'       => array_values( $new_ids ),
                        'post_type'      => 'attachment',
                        'posts_per_page' => count( $new_ids ),
                    ) );
                    foreach ( $content_attachments as $att ) {
                        if ( isset( $att->post_mime_type ) && strpos( $att->post_mime_type, 'image/' ) === 0 ) {
                            $images[] = $att;
                        }
                    }
                }
            }

            if ( empty( $images ) ) {
                $results['skipped']++;
                continue;
            }

            // 检查是否跳过已有ALT
            if ( $options['skip_has_alt'] ) {
                $all_have_alt = true;
                foreach ( $images as $img ) {
                    $alt = get_post_meta( $img->ID, '_wp_attachment_image_alt', true );
                    if ( empty( trim( $alt ) ) || self::is_low_quality_alt_static( $alt, 'zh_CN' ) ) {
                        $all_have_alt = false;
                        break;
                    }
                }
                
                if ( $all_have_alt ) {
                    $results['skipped']++;
                    continue;
                }
            }
            
            // 获取文章上下文
            $context = array(
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'keyword' => '',
            );
            
            // 获取关键词
            $keywords = get_post_meta( $post->ID, '_wpaip_seo_keywords', true );
            if ( ! empty( $keywords ) ) {
                $context['keyword'] = is_array( $keywords ) ? $keywords[0] : $keywords;
            }
            
            // 处理图片ALT（复用上面已查询的 $images）
            
            $processed_images = array();
            $slot_index = 1;
            foreach ( $images as $img ) {
                $current_alt = get_post_meta( $img->ID, '_wp_attachment_image_alt', true );
                
                // 跳过已有有效ALT（如果启用 skip_has_alt，且现有ALT非低质量）
                if ( $options['skip_has_alt'] && ! empty( trim( $current_alt ) ) ) {
                    // 进一步检查：现有ALT是否为低质量（原始文件名等）
                    if ( ! self::is_low_quality_alt_static( $current_alt, 'zh_CN' ) ) {
                        continue;
                    }
                }
                
                // 使用规则模式生成ALT
                $new_alt = self::generate_rule_alt(
                    $slot_index,
                    $context,
                    'zh_CN'
                );
                
                if ( ! empty( $new_alt ) ) {
                    update_post_meta( $img->ID, '_wp_attachment_image_alt', $new_alt );
                    $processed_images[] = array(
                        'id'  => $img->ID,
                        'url' => wp_get_attachment_url( $img->ID ),
                        'alt' => $new_alt,
                    );
                }
                $slot_index++;
            }
            
            // 记录处理结果
            if ( ! empty( $processed_images ) ) {
                update_post_meta( $post->ID, 'wpaip_alt_batch_at', current_time( 'mysql' ) );
                update_post_meta( $post->ID, 'wpaip_alt_batch_log', $processed_images );
                update_post_meta( $post->ID, 'wpaip_alt_optimized_at', current_time( 'mysql' ) );
                
                $results['processed']++;
                $results['details'][] = array(
                    'post_id'     => $post->ID,
                    'post_title'  => $post->post_title,
                    'image_count' => count( $processed_images ),
                );
            } else {
                $results['skipped']++;
            }
        }
        
        return $results;
    }

    /**
     * 生成规则ALT文本
     */
    private static function generate_rule_alt( $slot, $article_context, $language ) {
        $title   = isset( $article_context['title'] ) ? $article_context['title'] : '';
        $keyword = isset( $article_context['keyword'] ) ? $article_context['keyword'] : '';
        $slot    = max( 1, (int) $slot );

        if ( '' !== $keyword ) {
            if ( $slot > 1 ) {
                return mb_substr( $keyword, 0, 12 ) . '主题配图' . $slot;
            }
            return mb_substr( $keyword, 0, 12 ) . '主题配图';
        }

        if ( '' !== $title ) {
            if ( $slot > 1 ) {
                return mb_substr( $title, 0, 14 ) . '内容配图' . $slot;
            }
            return mb_substr( $title, 0, 14 ) . '内容配图';
        }

        return $slot > 1 ? '文章配图' . $slot : '文章配图';
    }

    /**
     * 获取处理预估数量
     */
    public static function estimate_count( $args = array() ) {
        $defaults = array(
            'scope'         => 'all',
            'category'      => 0,
            'tag'           => 0,
            'skip_has_alt'  => true,
        );
        $args = wp_parse_args( $args, $defaults );
        
        global $wpdb;
        
        $pt_list = WPAIP_B7PQ::sql_type_list();
        
        $sql = "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->posts} img ON img.post_parent = p.ID
                AND img.post_type = 'attachment'
                AND img.post_mime_type LIKE 'image/%'";
        
        $conditions = array( 'p.post_type IN ({$pt_list})', "p.post_status = 'publish'" );
        
        if ( $args['skip_has_alt'] ) {
            $conditions[] = "img.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != '')";
        }
        
        if ( $args['scope'] === 'category' && $args['category'] > 0 ) {
            $conditions[] = $wpdb->prepare( "p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)", $args['category'] );
        }
        
        if ( $args['scope'] === 'tag' && $args['tag'] > 0 ) {
            $conditions[] = $wpdb->prepare( "p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)", $args['tag'] );
        }
        
        $sql .= " WHERE " . implode( ' AND ', $conditions );
        
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * 静态低质量ALT检测（供批量处理使用，与 Image_Alt_Generator 判定逻辑一致）
     *
     * @param string $alt_text
     * @param string $language
     * @return bool
     */
    public static function is_low_quality_alt_static( $alt_text, $language = 'zh_CN' ) {
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

        // 原始文件名模式
        if ( preg_match( '/^(photo|image|img|pic|pexels|unsplash|pixabay|dalle|dall-e|ai-image|stock)[\-\_]?\d/i', $lower ) ) {
            return true;
        }

        // 纯数字+连字符
        if ( preg_match( '/^\d+[\-\_]\w+/', $lower ) ) {
            return true;
        }

        // 长串无意义字符
        if ( $length > 20 && ! preg_match( '/[\x{4e00}-\x{9fa5}]/u', $alt_text ) && ! preg_match( '/\s/', $alt_text ) ) {
            return true;
        }

        // 中文站但 alt 为纯英文/数字
        if ( 'zh_CN' === $language && ! preg_match( '/[\x{4e00}-\x{9fa5}]/u', $alt_text ) && preg_match( '/^[a-z0-9,\.\-\s_]+$/i', $alt_text ) ) {
            return true;
        }

        return false;
    }
}