<?php
/**
 * Canonical URL 管理器
 * 自动设置 canonical、rel prev/next、参数清洗、跨站转载 canonical、批量巡检
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_BOQ3 {

    /**
     * 初始化：注册前端钩子和AJAX
     */
    public static function init() {
        // 前端自动 canonical
        add_filter( 'get_canonical_url', array( __CLASS__, 'filter_canonical_url' ), 10, 2 );

        // rel prev/next 分页
        add_action( 'wp_head', array( __CLASS__, 'output_rel_prev_next' ), 2 );

        // 自定义 canonical meta box（编辑文章页）
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post', array( __CLASS__, 'save_post_meta' ), 10, 2 );

        // AJAX
        add_action( 'wp_ajax_wpaip_canonical_batch_scan', array( __CLASS__, 'ajax_batch_scan' ) );
        add_action( 'wp_ajax_wpaip_canonical_save_custom', array( __CLASS__, 'ajax_save_custom' ) );
    }

    // ─────────── 前端 canonical 过滤 ───────────

    /**
     * 自定义 canonical：若文章设置了手动 canonical 则优先使用
     */
    public static function filter_canonical_url( $canonical, $post ) {
        if ( ! $post || ! is_singular() ) {
            return $canonical;
        }

        $custom = get_post_meta( $post->ID, '_wpaip_custom_canonical', true );
        if ( ! empty( $custom ) ) {
            return esc_url( $custom );
        }

        // 自动清洗跟踪参数
        $canonical = self::clean_tracking_params( $canonical );

        return $canonical;
    }

    /**
     * 清洗 URL 中的跟踪参数（utm_*、fbclid、gclid 等）
     */
    public static function clean_tracking_params( $url ) {
        $params_to_remove = array(
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid',
            '_ga', '_gl', '_gid',
            'ref', 'referrer',
            'wpaip_source', // 插件内部跟踪参数
        );

        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['query'] ) ) {
            return $url;
        }

        parse_str( $parsed['query'], $query_args );
        $changed = false;

        foreach ( $params_to_remove as $param ) {
            if ( isset( $query_args[ $param ] ) ) {
                unset( $query_args[ $param ] );
                $changed = true;
            }
        }

        if ( ! $changed ) {
            return $url;
        }

        $scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
        $host     = $parsed['host'] ?? '';
        $port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
        $path     = $parsed['path'] ?? '/';
        $fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

        $new_query = build_query( $query_args );

        return $scheme . $host . $port . $path . ( $new_query ? '?' . $new_query : '' ) . $fragment;
    }

    // ─────────── rel prev/next 分页 ───────────

    /**
     * 输出分页文章的 rel prev/next
     */
    public static function output_rel_prev_next() {
        if ( ! is_singular() ) {
            return;
        }

        global $post, $page, $numpages;
        if ( ! $post || $numpages <= 1 ) {
            return;
        }

        $permalink = get_permalink( $post->ID );

        if ( $page > 1 ) {
            $prev = $page - 1;
            $prev_url = $prev > 1 ? $permalink . $prev . '/' : $permalink;
            echo '<link rel="prev" href="' . esc_url( $prev_url ) . '">' . "\n";
        }

        if ( $page < $numpages ) {
            $next = $page + 1;
            $next_url = $permalink . $next . '/';
            echo '<link rel="next" href="' . esc_url( $next_url ) . '">' . "\n";
        }
    }

    // ─────────── 文章编辑 Meta Box ───────────

    public static function add_meta_box() {
        add_meta_box(
            'wpaip-canonical-box',
            'Canonical URL（白雀SEO）',
            array( __CLASS__, 'render_meta_box' ),
            'post',
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'wpaip_canonical_save', 'wpaip_canonical_nonce' );
        $custom = get_post_meta( $post->ID, '_wpaip_custom_canonical', true );
        ?>
        <p style="margin-top:0">
            <label for="wpaip-custom-canonical" style="font-weight:600;display:block;margin-bottom:4px">自定义 Canonical URL</label>
            <input type="url" id="wpaip-custom-canonical" name="wpaip_custom_canonical"
                   value="<?php echo esc_attr( $custom ); ?>"
                   class="large-text" placeholder="留空则使用默认（当前文章URL）" style="width:100%">
        </p>
        <p class="description" style="margin-bottom:0">
            跨站转载时填写原文URL，避免搜索引擎判定为重复内容。留空则自动使用本站URL。
        </p>
        <?php
    }

    public static function save_post_meta( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['wpaip_canonical_nonce'] ) || ! wp_verify_nonce( $_POST['wpaip_canonical_nonce'], 'wpaip_canonical_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $value = '';
        if ( ! empty( $_POST['wpaip_custom_canonical'] ) ) {
            $value = esc_url_raw( $_POST['wpaip_custom_canonical'] );
            // 验证URL格式
            if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
                $value = '';
            }
        }

        if ( empty( $value ) ) {
            delete_post_meta( $post_id, '_wpaip_custom_canonical' );
        } else {
            update_post_meta( $post_id, '_wpaip_custom_canonical', $value );
        }
    }

    // ─────────── AJAX：批量巡检 ───────────

    /**
     * 批量扫描文章的 canonical 状态
     */
    public static function ajax_batch_scan() {
        check_ajax_referer( 'wpaip_seo_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
        $per_page = min( 50, max( 5, absint( $_POST['per_page'] ?? 20 ) ) );
        $filter   = sanitize_text_field( $_POST['filter'] ?? 'all' );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $cat_id   = absint( $_POST['category'] ?? 0 );

        $args = array(
            'post_type'      => WPAIP_B7PQ::get_supported(),
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }
        if ( $cat_id > 0 ) {
            $args['cat'] = $cat_id;
        }

        // 根据 filter 筛选
        if ( 'custom' === $filter ) {
            $args['meta_key']   = '_wpaip_custom_canonical';
            $args['meta_compare'] = '!=';
            $args['meta_value']   = '';
        } elseif ( 'none' === $filter ) {
            // 查找没有设置 canonical 的（默认行为，不需要meta query）
        }

        $query = new WP_Query( $args );
        $items = array();

        // 批量预加载 canonical meta
        $canonical_ids = wp_list_pluck( $query->posts, 'ID' );
        if ( ! empty( $canonical_ids ) ) {
            update_meta_cache( 'post', $canonical_ids );
        }

        foreach ( $query->posts as $post ) {
            $custom_canonical = get_post_meta( $post->ID, '_wpaip_custom_canonical', true );
            $default_url      = get_permalink( $post->ID );
            // 确保 URL 显示时解码（避免 %XX 编码在列表中显示）
        $canonical_url = ! empty( $custom_canonical ) ? $custom_canonical : $default_url;
        if ( empty( $custom_canonical ) ) {
            // 默认URL：使用已解码的 permalink，避免出现 %XX 乱码
            $canonical_url = urldecode( $default_url );
        }
        $is_custom        = ! empty( $custom_canonical );
        $is_external      = $is_custom && ! self::is_same_domain( $canonical_url, $default_url );

        $items[] = array(
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'canonical_url'   => $canonical_url,
            'default_url'     => $default_url,
            'is_custom'       => $is_custom,
            'is_external'     => $is_external,
            'custom_value'    => $custom_canonical,
            'post_date'       => $post->post_date,
        );
        }

        // 统计数据
        $stats = self::get_canonical_stats();

        wp_send_json_success( array(
            'items'     => $items,
            'total'     => $query->found_posts,
            'page'      => $page,
            'per_page'  => $per_page,
            'stats'     => $stats,
        ) );
    }

    /**
     * AJAX 保存单篇文章的自定义 canonical
     */
    public static function ajax_save_custom() {
        check_ajax_referer( 'wpaip_seo_nonce', 'nonce' );

        if ( ! true || ! true ) {
            wp_send_json_error( array( 'message' => '当前配置不支持此操作。' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $value   = '';

        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无效的文章ID或权限不足。' ) );
        }

        if ( ! empty( $_POST['canonical_url'] ) ) {
            $raw   = esc_url_raw( $_POST['canonical_url'] );
            if ( filter_var( $raw, FILTER_VALIDATE_URL ) ) {
                $value = $raw;
            }
        }

        if ( empty( $value ) ) {
            delete_post_meta( $post_id, '_wpaip_custom_canonical' );
            $result_url = urldecode( get_permalink( $post_id ) );
        } else {
            update_post_meta( $post_id, '_wpaip_custom_canonical', $value );
            $result_url = $value;
        }

        wp_send_json_success( array(
            'post_id'       => $post_id,
            'canonical_url' => $result_url,
            'is_custom'     => ! empty( $value ),
            'is_external'   => ! empty( $value ) && ! self::is_same_domain( $value, home_url() ),
        ) );
    }

    // ─────────── 工具方法 ───────────

    /**
     * 获取 canonical 统计数据
     */
    private static function get_canonical_stats() {
        global $wpdb;

        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . WPAIP_B7PQ::sql_in_clause() . " AND post_status='publish'" );
        $custom    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_wpaip_custom_canonical' AND meta_value != ''" );
        $external  = 0;

        if ( $custom > 0 ) {
            // 查询自定义 canonical 中指向外部域名的数量
            $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
            $rows = $wpdb->get_results( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wpaip_custom_canonical' AND meta_value != '' LIMIT 500" );
            foreach ( $rows as $row ) {
                $url_host = wp_parse_url( $row->meta_value, PHP_URL_HOST );
                if ( $url_host && $url_host !== $home_host ) {
                    $external++;
                }
            }
        }

        return array(
            'total'     => $total,
            'custom'    => $custom,
            'external'  => $external,
            'default'   => $total - $custom,
        );
    }

    /**
     * 判断两个 URL 是否同域
     */
    private static function is_same_domain( $url1, $url2 ) {
        $host1 = wp_parse_url( $url1, PHP_URL_HOST );
        $host2 = wp_parse_url( $url2, PHP_URL_HOST );
        if ( ! $host1 || ! $host2 ) {
            return false;
        }
        return strtolower( $host1 ) === strtolower( $host2 );
    }
}
