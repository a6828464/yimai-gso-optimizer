<?php
/**
 * 图片库管理类 - 自定义图片库：分组、权重、使用次数统计、选图算法
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_UY9D {

    const TAXONOMY   = 'wpaip_image_group';
    const META_WEIGHT    = 'wpaip_img_weight';
    const META_KEYWORDS  = 'wpaip_img_keywords';
    const META_USED      = 'wpaip_img_used_count';
    const META_LAST_USED = 'wpaip_img_last_used';

    /**
     * 注册 taxonomy（插件激活时调用）
     */
    public static function register_taxonomy() {
        if ( taxonomy_exists( self::TAXONOMY ) ) {
            return;
        }
        register_taxonomy( self::TAXONOMY, 'attachment', array(
            'labels'       => array(
                'name'          => '图片分组',
                'singular_name' => '分组',
                'search_items'  => '搜索分组',
                'all_items'     => '所有分组',
                'edit_item'     => '编辑分组',
                'update_item'   => '更新分组',
                'add_new_item'  => '新建分组',
                'new_item_name' => '新分组名称',
                'parent_item'   => '父分组',
                'parent_item_colon' => '父分组:',
            ),
            'hierarchical' => true,
            'public'       => false,
            'show_ui'      => false,
            'show_in_rest' => false,
            'rewrite'      => false,
        ) );
    }

    /**
     * 获取图片列表（分页/搜索/分组/排序）
     *
     * @return array [ 'items' => [], 'total' => int, 'pages' => int ]
     */
    public static function get_images( $args = array() ) {
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
        $search   = sanitize_text_field( $args['search'] ?? '' );
        $group_id = absint( $args['group_id'] ?? 0 );
        $orderby  = sanitize_key( $args['orderby'] ?? 'date' );
        $order    = in_array( strtoupper( $args['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ) : 'DESC';

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby,
            'order'          => $order,
            'meta_query'     => array(
                array(
                    'key'     => self::META_WEIGHT,
                    'compare' => 'EXISTS',
                ),
            ),
        );

        // 分组过滤
        if ( $group_id > 0 ) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => self::get_group_term_ids( $group_id ),
                    'include_children' => true,
                ),
            );
        } else {
            // 只查图片库中的图片（有 taxonomy 关联的）
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'operator' => 'EXISTS',
                ),
            );
        }

        // 搜索
        if ( ! empty( $search ) ) {
            $query_args['s'] = $search;
        }

        // 按权重排序用 meta_value_num
        if ( 'weight' === $orderby ) {
            $query_args['meta_key'] = self::META_WEIGHT;
            $query_args['orderby']  = 'meta_value_num';
        } elseif ( 'used' === $orderby ) {
            $query_args['meta_key'] = self::META_USED;
            $query_args['orderby']  = 'meta_value_num';
        } elseif ( 'last_used' === $orderby ) {
            $query_args['meta_key'] = self::META_LAST_USED;
            $query_args['orderby']  = 'meta_value';
        }

        $query = new WP_Query( $query_args );
        $items = array();

        // 批量预加载所有 attachment 的 meta，避免循环内 N+1 查询
        $post_ids = wp_list_pluck( $query->posts, 'ID' );
        if ( ! empty( $post_ids ) ) {
            update_meta_cache( 'post', $post_ids );
        }

        foreach ( $query->posts as $post ) {
            $groups = wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'names' ) );
            if ( is_wp_error( $groups ) ) {
                $groups = array();
            }

            $items[] = array(
                'id'         => $post->ID,
                'url'        => wp_get_attachment_url( $post->ID ),
                'thumb'      => wp_get_attachment_image_url( $post->ID, 'medium' ) ?: wp_get_attachment_url( $post->ID ),
                'alt'        => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                'title'      => $post->post_title,
                'weight'     => absint( get_post_meta( $post->ID, self::META_WEIGHT, true ) ) ?: 5,
                'keywords'   => get_post_meta( $post->ID, self::META_KEYWORDS, true ),
                'used_count' => absint( get_post_meta( $post->ID, self::META_USED, true ) ),
                'last_used'  => get_post_meta( $post->ID, self::META_LAST_USED, true ),
                'groups'     => $groups,
                'filename'   => basename( wp_get_attachment_url( $post->ID ) ),
                'date'       => $post->post_date,
            );
        }

        return array(
            'items'    => $items,
            'total'    => (int) $query->found_posts,
            'pages'    => (int) $query->max_num_pages,
            'page'     => $page,
            'per_page' => $per_page,
        );
    }

    /**
     * 上传图片到图片库
     *
     * @return int|WP_Error attachment ID
     */
    public static function upload_to_library( $file_path, $filename = '', $title = '', $group_id = 0 ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $file_array = array(
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $file_path,
        );

        $attach_id = media_handle_sideload( $file_array, 0, $title );

        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }

        // 初始化图片库 meta
        update_post_meta( $attach_id, self::META_WEIGHT, 5 );
        update_post_meta( $attach_id, self::META_KEYWORDS, '' );
        update_post_meta( $attach_id, self::META_USED, 0 );
        update_post_meta( $attach_id, self::META_LAST_USED, '' );

        // 分配到分组
        if ( $group_id > 0 && term_exists( $group_id, self::TAXONOMY ) ) {
            wp_set_object_terms( $attach_id, absint( $group_id ), self::TAXONOMY );
        } else {
            // 如果没有指定分组，查第一个可用分组，没有则创建默认分组
            $terms = get_terms( array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'number'     => 1,
            ) );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                wp_set_object_terms( $attach_id, $terms[0]->term_id, self::TAXONOMY );
            } else {
                // 创建默认分组
                $default_term = wp_insert_term( '默认分组', self::TAXONOMY );
                if ( ! is_wp_error( $default_term ) ) {
                    wp_set_object_terms( $attach_id, $default_term['term_id'], self::TAXONOMY );
                }
            }
        }

        return $attach_id;
    }

    /**
     * 通过 WordPress media uploader 的 attachment_id 添加到图片库
     */
    public static function add_attachment_to_library( $attachment_id, $group_id = 0 ) {
        if ( ! get_post( $attachment_id ) ) {
            return new WP_Error( 'invalid_id', '无效的附件ID' );
        }

        // 检查是否已在图片库中
        $existing = wp_get_object_terms( $attachment_id, self::TAXONOMY );
        if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
            // 已在库中，只更新分组
            if ( $group_id > 0 && term_exists( $group_id, self::TAXONOMY ) ) {
                wp_set_object_terms( $attachment_id, absint( $group_id ), self::TAXONOMY );
            }
            return $attachment_id;
        }

        // 初始化 meta（如果尚未设置）
        if ( '' === get_post_meta( $attachment_id, self::META_WEIGHT, true ) ) {
            update_post_meta( $attachment_id, self::META_WEIGHT, 5 );
            update_post_meta( $attachment_id, self::META_KEYWORDS, '' );
            update_post_meta( $attachment_id, self::META_USED, 0 );
            update_post_meta( $attachment_id, self::META_LAST_USED, '' );
        }

        // 分配分组
        if ( $group_id > 0 && term_exists( $group_id, self::TAXONOMY ) ) {
            wp_set_object_terms( $attachment_id, absint( $group_id ), self::TAXONOMY );
        } else {
            $terms = get_terms( array(
                'taxonomy' => self::TAXONOMY,
                'hide_empty' => false,
                'number'     => 1,
            ) );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                wp_set_object_terms( $attachment_id, $terms[0]->term_id, self::TAXONOMY );
            } else {
                $default_term = wp_insert_term( '默认分组', self::TAXONOMY );
                if ( ! is_wp_error( $default_term ) ) {
                    wp_set_object_terms( $attachment_id, $default_term['term_id'], self::TAXONOMY );
                }
            }
        }

        return $attachment_id;
    }

    /**
     * 更新图片属性
     *
     * @return true|WP_Error
     */
    public static function update_image( $attachment_id, $data = array() ) {
        $attach = get_post( $attachment_id );
        if ( ! $attach || 'attachment' !== $attach->post_type ) {
            return new WP_Error( 'invalid_id', '图片不存在' );
        }

        if ( isset( $data['weight'] ) ) {
            $weight = max( 1, min( 10, absint( $data['weight'] ) ) );
            update_post_meta( $attachment_id, self::META_WEIGHT, $weight );
        }

        if ( isset( $data['keywords'] ) ) {
            update_post_meta( $attachment_id, self::META_KEYWORDS, sanitize_text_field( $data['keywords'] ) );
        }

        if ( isset( $data['group_id'] ) ) {
            $gid = absint( $data['group_id'] );
            if ( $gid > 0 && term_exists( $gid, self::TAXONOMY ) ) {
                wp_set_object_terms( $attachment_id, $gid, self::TAXONOMY );
            }
        }

        return true;
    }

    /**
     * 批量更新图片属性
     */
    public static function batch_update( $ids, $data = array() ) {
        $updated = 0;
        foreach ( $ids as $id ) {
            $result = self::update_image( absint( $id ), $data );
            if ( ! is_wp_error( $result ) ) {
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * 删除图片（从图片库移除，同时删除 taxonomy 关联和 meta）
     */
    public static function delete_images( $ids ) {
        $deleted = 0;
        foreach ( $ids as $id ) {
            $id = absint( $id );
            // 删除 taxonomy 关联
            wp_delete_object_term_relationships( $id, self::TAXONOMY );
            // 删除自定义 meta
            delete_post_meta( $id, self::META_WEIGHT );
            delete_post_meta( $id, self::META_KEYWORDS );
            delete_post_meta( $id, self::META_USED );
            delete_post_meta( $id, self::META_LAST_USED );
            // 删除 attachment
            if ( wp_delete_attachment( $id, true ) ) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * 获取分组列表
     */
    public static function get_groups( $parent_id = 0 ) {
        $args = array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'parent'     => absint( $parent_id ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        );
        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return array();
        }

        $groups = array();
        foreach ( $terms as $term ) {
            $count = self::get_group_image_count( $term->term_id );
            $groups[] = array(
                'id'       => $term->term_id,
                'name'     => $term->name,
                'slug'     => $term->slug,
                'parent'   => $term->parent,
                'count'    => $count,
                'children' => self::get_groups( $term->term_id ),
            );
        }
        return $groups;
    }

    /**
     * 获取分组（扁平列表，含层级标记）
     */
    public static function get_groups_flat() {
        $hierarchical = self::get_groups( 0 );
        $flat = array();

        foreach ( $hierarchical as $group ) {
            $flat[] = $group;
            if ( ! empty( $group['children'] ) ) {
                self::flatten_groups( $group['children'], $flat, 1 );
            }
        }
        return $flat;
    }

    /**
     * 递归扁平化分组
     */
    private static function flatten_groups( $groups, &$flat, $depth ) {
        foreach ( $groups as $group ) {
            $group['depth'] = $depth;
            $flat[] = $group;
            if ( ! empty( $group['children'] ) ) {
                self::flatten_groups( $group['children'], $flat, $depth + 1 );
            }
        }
    }

    /**
     * 获取分组内的图片数量
     */
    private static function get_group_image_count( $term_id ) {
        $term_ids = self::get_group_term_ids( $term_id );
        global $wpdb;
        $id_list = implode( ',', array_map( 'absint', $term_ids ) );
        if ( empty( $id_list ) ) return 0;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT tr.object_id)
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id AND pm.meta_key = '" . self::META_WEIGHT . "'
             WHERE tr.term_taxonomy_id IN (
                 SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id IN ({$id_list})
             )"
        );
    }

    /**
     * 获取分组及其所有子分组的 term_id
     */
    private static function get_group_term_ids( $term_id ) {
        $term_ids = array( absint( $term_id ) );
        $children = get_term_children( $term_id, self::TAXONOMY );
        if ( ! is_wp_error( $children ) ) {
            $term_ids = array_merge( $term_ids, array_map( 'absint', $children ) );
        }
        return array_unique( $term_ids );
    }

    /**
     * 创建分组
     */
    public static function create_group( $name, $parent_id = 0 ) {
        $name = sanitize_text_field( $name );
        if ( empty( $name ) ) {
            return new WP_Error( 'empty_name', '分组名称不能为空' );
        }

        $result = wp_insert_term( $name, self::TAXONOMY, array(
            'parent' => absint( $parent_id ),
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'id'     => $result['term_id'],
            'name'   => $name,
            'parent' => absint( $parent_id ),
        );
    }

    /**
     * 重命名分组
     */
    public static function rename_group( $term_id, $new_name ) {
        $new_name = sanitize_text_field( $new_name );
        if ( empty( $new_name ) ) {
            return new WP_Error( 'empty_name', '分组名称不能为空' );
        }

        $result = wp_update_term( absint( $term_id ), self::TAXONOMY, array(
            'name' => $new_name,
        ) );

        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * 删除分组（图片不会被删除，只是移除关联）
     */
    public static function delete_group( $term_id ) {
        // 将该分组下的图片移到默认分组或第一个可用分组
        $term_ids = self::get_group_term_ids( absint( $term_id ) );
        global $wpdb;
        $id_list = implode( ',', array_map( 'absint', $term_ids ) );

        // 查找该分组下的所有图片
        $object_ids = $wpdb->get_col(
            "SELECT DISTINCT tr.object_id
             FROM {$wpdb->term_relationships} tr
             WHERE tr.term_taxonomy_id IN (
                 SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id IN ({$id_list})
             )"
        );

        if ( ! empty( $object_ids ) ) {
            // 找一个不是当前删除目标的分组作为替代
            $other_terms = get_terms( array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'exclude'    => $term_ids,
                'number'     => 1,
            ) );
            $fallback_id = 0;
            if ( ! empty( $other_terms ) && ! is_wp_error( $other_terms ) ) {
                $fallback_id = $other_terms[0]->term_id;
            }

            foreach ( $object_ids as $oid ) {
                // 移除旧分组关联
                wp_delete_object_term_relationships( $oid, self::TAXONOMY );
                // 如果有替代分组，关联到替代分组
                if ( $fallback_id > 0 ) {
                    wp_set_object_terms( $oid, $fallback_id, self::TAXONOMY, true );
                }
            }
        }

        // 删除分组（递归删除子分组）
        $result = wp_delete_term( absint( $term_id ), self::TAXONOMY, array(
            'force_default' => true,
        ) );

        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * 获取统计信息
     */
    public static function get_stats() {
        global $wpdb;

        // 总图片数
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '" . self::TAXONOMY . "'
             WHERE pm.meta_key = '" . self::META_WEIGHT . "'"
        );

        // 分组数
        $groups_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                self::TAXONOMY
            )
        );

        // 总使用次数
        $total_used = (int) $wpdb->get_var(
            "SELECT SUM(CAST(meta_value AS UNSIGNED))
             FROM {$wpdb->postmeta}
             WHERE meta_key = '" . self::META_USED . "'"
        );

        return array(
            'total'      => $total,
            'groups'     => $groups_count,
            'total_used' => $total_used ?: 0,
        );
    }

    /**
     * 选取图片（配图使用，含权重+使用次数均衡算法）
     *
     * @param string|array $keywords 关键词（逗号分隔字符串或数组）
     * @param int          $count    需要数量
     * @param int          $group_id 分组过滤（0=全部）
     * @return array 图片列表 [{url, thumb, alt, source}]
     */
    public static function select_images( $keywords, $count = 3, $group_id = 0 ) {
        $count = max( 1, min( 20, absint( $count ) ) );

        // 关键词标准化
        if ( is_string( $keywords ) ) {
            $keywords = array_filter( array_map( 'trim', explode( ',', $keywords ) ) );
        }
        if ( empty( $keywords ) ) {
            $keywords = array();
        }

        // 构建查询
        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $count * 3, // 取多一些，后面排序截取
            'meta_query'     => array(
                array( 'key' => self::META_WEIGHT, 'compare' => 'EXISTS' ),
            ),
            'tax_query' => array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'operator' => 'EXISTS',
                ),
            ),
        );

        // 分组过滤
        if ( $group_id > 0 && term_exists( $group_id, self::TAXONOMY ) ) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy'         => self::TAXONOMY,
                    'field'            => 'term_id',
                    'terms'            => self::get_group_term_ids( $group_id ),
                    'include_children' => true,
                ),
            );
        }

        // 关键词过滤（匹配 keywords meta 或标题）
        if ( ! empty( $keywords ) ) {
            $meta_keywords_conditions = array();
            foreach ( $keywords as $kw ) {
                $meta_keywords_conditions[] = array(
                    'key'     => self::META_KEYWORDS,
                    'value'   => $kw,
                    'compare' => 'LIKE',
                );
            }
            $meta_keywords_conditions['relation'] = 'OR';

            $query_args['meta_query'] = array(
                'relation' => 'AND',
                array( 'key' => self::META_WEIGHT, 'compare' => 'EXISTS' ),
                $meta_keywords_conditions,
            );
        }

        $query = new WP_Query( $query_args );
        $candidates = array();

        // 批量预加载 attachment meta
        $candidate_ids = wp_list_pluck( $query->posts, 'ID' );
        if ( ! empty( $candidate_ids ) ) {
            update_meta_cache( 'post', $candidate_ids );
        }

        foreach ( $query->posts as $post ) {
            $weight     = absint( get_post_meta( $post->ID, self::META_WEIGHT, true ) ) ?: 5;
            $used_count = absint( get_post_meta( $post->ID, self::META_USED, true ) );
            $alt        = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );

            $candidates[] = array(
                'id'         => $post->ID,
                'url'        => wp_get_attachment_url( $post->ID ),
                'thumb'      => wp_get_attachment_image_url( $post->ID, 'medium' ) ?: wp_get_attachment_url( $post->ID ),
                'alt'        => $alt ?: $post->post_title,
                'weight'     => $weight,
                'used_count' => $used_count,
                'score'      => $weight * 1000 - $used_count, // 权重优先 + 使用次数均衡
            );
        }

        // 如果关键词匹配不够，补充随机图片
        if ( count( $candidates ) < $count ) {
            $remaining = $count * 2 - count( $candidates );
            $existing_ids = wp_list_pluck( $candidates, 'id' );

            $fill_args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $remaining,
                'post__not_in'   => $existing_ids,
                'meta_query'     => array(
                    array( 'key' => self::META_WEIGHT, 'compare' => 'EXISTS' ),
                ),
                'tax_query' => array(
                    array(
                        'taxonomy' => self::TAXONOMY,
                        'operator' => 'EXISTS',
                    ),
                ),
                'orderby' => 'rand',
            );

            if ( $group_id > 0 && term_exists( $group_id, self::TAXONOMY ) ) {
                $fill_args['tax_query'] = array(
                    array(
                        'taxonomy'         => self::TAXONOMY,
                        'field'            => 'term_id',
                        'terms'            => self::get_group_term_ids( $group_id ),
                        'include_children' => true,
                    ),
                );
            }

            $fill_query = new WP_Query( $fill_args );
            // 批量预加载补充查询的 attachment meta
            $fill_ids = wp_list_pluck( $fill_query->posts, 'ID' );
            if ( ! empty( $fill_ids ) ) {
                update_meta_cache( 'post', $fill_ids );
            }
            foreach ( $fill_query->posts as $post ) {
                $weight     = absint( get_post_meta( $post->ID, self::META_WEIGHT, true ) ) ?: 5;
                $used_count = absint( get_post_meta( $post->ID, self::META_USED, true ) );
                $alt        = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
                $candidates[] = array(
                    'id'         => $post->ID,
                    'url'        => wp_get_attachment_url( $post->ID ),
                    'thumb'      => wp_get_attachment_image_url( $post->ID, 'medium' ) ?: wp_get_attachment_url( $post->ID ),
                    'alt'        => $alt ?: $post->post_title,
                    'weight'     => $weight,
                    'used_count' => $used_count,
                    'score'      => $weight * 1000 - $used_count,
                );
            }
        }

        if ( empty( $candidates ) ) {
            return new WP_Error( 'no_images', '图片库为空，请先上传图片' );
        }

        // 按 score 降序排序
        usort( $candidates, function( $a, $b ) {
            return $b['score'] - $a['score'];
        });

        // 取前 $count 张
        $selected = array_slice( $candidates, 0, $count );

        // 更新使用统计
        $now = current_time( 'mysql' );
        foreach ( $selected as $img ) {
            $new_count = $img['used_count'] + 1;
            update_post_meta( $img['id'], self::META_USED, $new_count );
            update_post_meta( $img['id'], self::META_LAST_USED, $now );
        }

        // 返回标准格式（与 fetch_images 输出一致）
        $result = array();
        foreach ( $selected as $img ) {
            $result[] = array(
                'url'          => $img['url'],
                'thumb'        => $img['thumb'],
                'photographer' => '',
                'alt'          => $img['alt'],
                'source'       => 'Local',
            );
        }

        return $result;
    }
}
