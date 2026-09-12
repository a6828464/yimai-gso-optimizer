<?php
/**
 * Sitemap生成类 - v1.0.0
 * 生成符合搜索引擎要求的XML Sitemap
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_NTDL {

    private static $instance = null;
    
    // Sitemap配置
    private $post_types = array();
    private $taxonomies = array( 'category', 'post_tag' );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 从统一配置读取支持的内容类型
        $this->post_types = WPAIP_B7PQ::get_supported();
        // 注册rewrite规则
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        
        // 插件版本升级后自动刷新 rewrite rules（一次性）
        add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
        
        // 生成Sitemap
        add_action( 'template_redirect', array( $this, 'serve_sitemap' ), 1 );
        
        // 禁用 WordPress 内置 sitemap（避免冲突）
        add_filter( 'wp_sitemaps_enabled', '__return_false' );
        
        // 禁用 WordPress 默认的虚拟 robots.txt（由插件接管）
        add_filter( 'robots_txt', '__return_empty_string' );
        
        // 主动生成Sitemap文件
        add_action( 'admin_init', array( $this, 'schedule_sitemap_generation' ) );
        add_action( 'wpaip_generate_sitemap', array( $this, 'generate_sitemap_file' ) );
        
        // 文章发布时更新Sitemap
        add_action( 'publish_post', array( $this, 'on_post_publish' ) );
        add_action( 'delete_post', array( $this, 'on_post_delete' ) );
    }
    
    /**
     * 添加rewrite规则
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^sitemap\.xml$', 'index.php?wpaip_sitemap=1', 'top' );
        add_rewrite_rule( '^sitemap-post\.xml$', 'index.php?wpaip_sitemap_post=1', 'top' );
        add_rewrite_rule( '^sitemap-taxonomy-(\w+)\.xml$', 'index.php?wpaip_sitemap_taxonomy=$matches[1]', 'top' );
        add_rewrite_rule( '^sitemap\.html$', 'index.php?wpaip_sitemap_html=1', 'top' );
        add_rewrite_rule( '^robots\.txt$', 'index.php?wpaip_robots=1', 'top' );
    }
    
    /**
     * 添加查询变量
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'wpaip_sitemap';
        $vars[] = 'wpaip_sitemap_post';
        $vars[] = 'wpaip_sitemap_taxonomy';
        $vars[] = 'wpaip_sitemap_html';
        $vars[] = 'wpaip_robots';
        return $vars;
    }

    /**
     * 版本升级后自动刷新 rewrite rules（一次性）
     */
    public function maybe_flush_rewrite_rules() {
        $current_version = defined( 'WPAIP_VERSION' ) ? WPAIP_VERSION : '0';
        $flush_version = get_option( 'wpaip_sitemap_flush_version', '' );
        if ( $flush_version !== $current_version ) {
            flush_rewrite_rules();
            update_option( 'wpaip_sitemap_flush_version', $current_version, false );
        }
    }
    
    /**
     * 提供Sitemap服务
     */
    public function serve_sitemap() {
        global $wp_query;
        
        if ( ! empty( $wp_query->query_vars['wpaip_sitemap'] ) ) {
            $this->render_sitemap();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['wpaip_sitemap_post'] ) ) {
            $this->render_post_sitemap();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['wpaip_sitemap_taxonomy'] ) ) {
            $taxonomy = sanitize_text_field( $wp_query->query_vars['wpaip_sitemap_taxonomy'] );
            $this->render_taxonomy_sitemap( $taxonomy );
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['wpaip_sitemap_html'] ) ) {
            $this->render_sitemap_html();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['wpaip_robots'] ) ) {
            $this->render_robots();
            exit;
        }
    }
    
    /**
     * 渲染主Sitemap（索引文件）
     */
    private function render_sitemap() {
        @header( 'Content-Type: application/xml; charset=utf-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        echo "\t" . '<sitemap>' . "\n";
        echo "\t\t" . '<loc>' . esc_url( home_url( '/sitemap-post.xml' ) ) . '</loc>' . "\n";
        echo "\t\t" . '<lastmod>' . esc_html( $this->get_lastmod() ) . '</lastmod>' . "\n";
        echo "\t" . '</sitemap>' . "\n";
        foreach ( $this->taxonomies as $taxonomy ) {
            if ( $this->taxonomy_has_terms( $taxonomy ) ) {
                echo "\t" . '<sitemap>' . "\n";
                echo "\t\t" . '<loc>' . esc_url( home_url( '/sitemap-taxonomy-' . $taxonomy . '.xml' ) ) . '</loc>' . "\n";
                echo "\t\t" . '<lastmod>' . esc_html( $this->get_lastmod() ) . '</lastmod>' . "\n";
                echo "\t" . '</sitemap>' . "\n";
            }
        }
        echo '</sitemapindex>' . "\n";
    }
    
    /**
     * 渲染文章Sitemap
     */
    private function render_post_sitemap() {
        $post_ids = $this->get_posts();

        @header( 'Content-Type: application/xml; charset=utf-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        echo "\t" . 'xmlns:mobile="http://www.baidu.com/schemas/sitemap-mobile/1"' . "\n";
        echo "\t" . 'xmlns:ai="' . esc_url( home_url( '/schemas/ai-sitemap/1.0' ) ) . '">' . "\n";

        // 批量预加载 post meta（用于 calculate_priority/calculate_changefreq 中的 is_sticky 等查询）
        $int_ids = array_map( 'absint', $post_ids );
        if ( ! empty( $int_ids ) ) {
            update_meta_cache( 'post', $int_ids );
            clean_post_cache( $int_ids );
        }

        foreach ( $int_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) continue;
            $permalink  = get_permalink( $post_id );
            $lastmod    = get_post_modified_time( 'c', false, $post_id );
            $priority   = $this->calculate_priority( $post );
            $changefreq = $this->calculate_changefreq( $post );
            echo "\t" . '<url>' . "\n";
            echo "\t\t" . '<loc>' . esc_url( $permalink ) . '</loc>' . "\n";
            echo "\t\t" . '<lastmod>' . esc_html( $lastmod ) . '</lastmod>' . "\n";
            echo "\t\t" . '<changefreq>' . esc_html( $changefreq ) . '</changefreq>' . "\n";
            echo "\t\t" . '<priority>' . esc_html( $priority ) . '</priority>' . "\n";
            // L6: SEO-GEO Bridge sitemap enhancement (v3.0.0)
            echo apply_filters( 'wpaip_sitemap_entry_filter', '', $post_id );
            echo "\t" . '</url>' . "\n";
            unset( $post ); // 逐篇释放内存
        }
        echo '</urlset>' . "\n";
    }
    
    /**
     * 渲染分类/标签Sitemap
     */
    private function render_taxonomy_sitemap( $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            @status_header( 404 );
            exit;
        }
        
        $terms = $this->get_terms( $taxonomy );
        
        @header( 'Content-Type: application/xml; charset=utf-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            foreach ( $terms as $term ) {
                $term_url = get_term_link( $term );
                if ( is_wp_error( $term_url ) ) {
                    continue;
                }
                // 解码URL中的中文，使sitemap显示干净的链接
                $term_url = urldecode( $term_url );
                echo "\t" . '<url>' . "\n";
                echo "\t\t" . '<loc>' . esc_url( $term_url ) . '</loc>' . "\n";
                echo "\t\t" . '<lastmod>' . esc_html( $this->get_lastmod() ) . '</lastmod>' . "\n";
                echo "\t\t" . '<changefreq>weekly</changefreq>' . "\n";
                echo "\t\t" . '<priority>0.5</priority>' . "\n";
                echo "\t" . '</url>' . "\n";
            }
        }
        echo '</urlset>' . "\n";
    }
    
    /**
     * 渲染HTML格式的Sitemap（供用户浏览）
     */
    private function render_sitemap_html() {
        $post_ids = $this->get_posts();
        $post_count = count( $post_ids );
        $categories = get_categories( array( 'hide_empty' => false ) );
        $tags = get_tags( array( 'hide_empty' => false, 'number' => 100 ) );
        
        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站地图 - <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        .sitemap-section { background: #fff; border-radius: 8px; padding: 20px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .sitemap-list { list-style: none; padding: 0; columns: 3; column-gap: 20px; }
        .sitemap-list li { margin-bottom: 8px; break-inside: avoid; }
        .sitemap-list a { color: #667eea; text-decoration: none; }
        .sitemap-list a:hover { text-decoration: underline; }
        .post-meta { font-size: 12px; color: #999; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #667eea; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        @media (max-width: 768px) { .sitemap-list { columns: 1; } }
    </style>
</head>
<body>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="back-link">← 返回首页</a>
    <h1>📚 <?php echo esc_html( get_bloginfo( 'name' ) ); ?> - 网站地图</h1>
    
    <div class="sitemap-section">
        <h2>📝 文章列表 (<?php echo esc_html( $post_count ); ?>篇)</h2>
        <ul class="sitemap-list">
            <?php foreach ( $post_ids as $pid ) :
                $p = get_post( absint( $pid ) );
                if ( ! $p ) continue;
            ?>
            <li>
                <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( $p->post_title ); ?></a>
                <span class="post-meta">(<?php echo esc_html( get_the_date( 'Y-m-d', $p->ID ) ); ?>)</span>
            </li>
            <?php unset( $p ); endforeach; ?>
        </ul>
    </div>
    
    <div class="sitemap-section">
        <h2>📁 分类目录</h2>
        <ul class="sitemap-list">
            <?php foreach ( $categories as $cat ) : ?>
            <li>
                <a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
                <span class="post-meta">(<?php echo esc_html( $cat->count ); ?>篇)</span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div class="sitemap-section">
        <h2>🏷️ 标签云</h2>
        <ul class="sitemap-list">
            <?php foreach ( $tags as $tag ) : ?>
            <li>
                <a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>"><?php echo esc_html( $tag->name ); ?></a>
                <span class="post-meta">(<?php echo esc_html( $tag->count ); ?>篇)</span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div class="sitemap-section">
        <h2>🔗 XML Sitemap 链接</h2>
        <p><a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>"><?php echo esc_url( home_url( '/sitemap.xml' ) ); ?></a></p>
        <p><a href="<?php echo esc_url( home_url( '/sitemap-post.xml' ) ); ?>"><?php echo esc_url( home_url( '/sitemap-post.xml' ) ); ?></a></p>
    </div>
</body>
</html>
        <?php
    }
    
    /**
     * 渲染 robots.txt
     */
    private function render_robots() {
        $robots_content = WPAIP_9XCU::get( 'wpaip_robots_content', '' );
        $site_url = home_url( '/' );
        
        @header( 'Content-Type: text/plain; charset=utf-8' );
        
        if ( ! empty( $robots_content ) ) {
            echo esc_html( $robots_content );
        } else {
            // 默认 robots.txt 内容
            echo "# robots.txt for " . esc_html( $site_url ) . "\n";
            echo "# Generated by 一麦GSO双擎优化\n\n";

            // 所有爬虫规则
            echo "User-agent: *\n";
            echo "Allow: /\n";

            // 屏蔽 WordPress 后台
            echo "\n# WordPress 后台（不需要被收录）\n";
            echo "Disallow: /wp-admin/\n";
            echo "Disallow: /wp-login.php\n";
            echo "Disallow: /wp-register.php\n";
            echo "Disallow: /wp-includes/\n";
            echo "Disallow: /wp-content/plugins/\n";
            echo "Disallow: /wp-content/cache/\n";
            echo "Disallow: /trackback/\n";
            echo "Disallow: /?s=\n";
            echo "Disallow: /?feed=\n";
            echo "Disallow: /comments/\n";

            // 屏蔽 WordPress 自带的虚拟 robots.txt（让插件接管）
            echo "\n# 禁止访问 WordPress 默认的 ?robots=1\n";
            echo "Disallow: /?robots=1\n";

            // Sitemap 地址
            echo "\n# Sitemap\n";
            echo "Sitemap: " . esc_url( home_url( '/sitemap.xml' ) ) . "\n";
            echo "Sitemap: " . esc_url( home_url( '/sitemap.html' ) ) . "\n";
            echo "Sitemap: " . esc_url( home_url( '/llms.txt' ) ) . "\n";
            
            // AI 爬虫规则
            $crawler_block = apply_filters( 'wpaip_robots_crawler_block', '', $robots_content );
            if ( ! empty( $crawler_block ) ) {
                echo $crawler_block;
            } else {
                echo "\n# AI 爬虫（允许访问）\n";
                echo "User-agent: GPTBot\nAllow: /\n\n";
                echo "User-agent: ChatGPT-User\nAllow: /\n\n";
                echo "User-agent: Claude-Web\nAllow: /\n\n";
                echo "User-agent: PerplexityBot\nAllow: /\n\n";
                echo "User-agent: Google-Extended\nAllow: /\n";
            }
        }
        exit;
    }
    
    /**
     * 获取文章列表（分批查询，避免一次性加载过多数据到内存）
     */
    private function get_posts( $limit = 5000 ) {
        $args = array(
            'post_type' => $this->post_types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'fields' => 'ids', // 只查ID，大幅减少内存占用
        );
        
        return get_posts( $args );
    }
    
    /**
     * 获取分类/标签列表
     */
    private function get_terms( $taxonomy ) {
        return get_terms( array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => 5000,
        ) );
    }
    
    /**
     * 检查分类是否有内容
     */
    private function taxonomy_has_terms( $taxonomy ) {
        global $wpdb;
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy = %s AND count > 0",
            $taxonomy
        ) );
        return $count > 0;
    }
    
    /**
     * 计算优先级
     */
    private function calculate_priority( $post ) {
        // 首页优先级最高
        if ( is_front_page() ) {
            return '1.0';
        }
        
        // 置顶文章
        if ( is_sticky( $post->ID ) ) {
            return '0.9';
        }
        
        // 根据评论数计算
        $comment_count = $post->comment_count;
        if ( $comment_count > 50 ) {
            return '0.8';
        } elseif ( $comment_count > 20 ) {
            return '0.7';
        } elseif ( $comment_count > 5 ) {
            return '0.6';
        }
        
        // 根据文章修改频率
        $modified = get_post_modified_time( 'U', false, $post->ID );
        $created = get_post_time( 'U', false, $post->ID );
        $days_since_modify = ( time() - $modified ) / DAY_IN_SECONDS;
        
        if ( $days_since_modify < 7 ) {
            return '0.7';
        } elseif ( $days_since_modify < 30 ) {
            return '0.6';
        }
        
        return '0.5';
    }
    
    /**
     * 计算更新频率
     */
    private function calculate_changefreq( $post ) {
        $modified = get_post_modified_time( 'U', false, $post->ID );
        $created = get_post_time( 'U', false, $post->ID );
        $days_old = ( time() - $created ) / DAY_IN_SECONDS;
        
        // 新文章
        if ( $days_old < 7 ) {
            return 'daily';
        }
        
        // 最近修改
        $days_since_modify = ( time() - $modified ) / DAY_IN_SECONDS;
        if ( $days_since_modify < 3 ) {
            return 'weekly';
        }
        
        return 'monthly';
    }
    
    /**
     * 获取最后修改时间
     */
    private function get_lastmod() {
        $last_post = wp_get_recent_posts( array(
            'numberposts' => 1,
            'post_type'   => $this->post_types,
            'post_status' => 'publish',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ) );
        
        if ( ! empty( $last_post ) ) {
            return get_post_modified_time( 'c', false, $last_post[0]['ID'] );
        }
        
        return current_time( 'c' );
    }
    
    /**
     * 计划生成Sitemap
     */
    public function schedule_sitemap_generation() {
        if ( ! wp_next_scheduled( 'wpaip_generate_sitemap' ) ) {
            wp_schedule_event( time(), 'daily', 'wpaip_generate_sitemap' );
        }
    }
    
    /**
     * 文章发布时更新Sitemap
     */
    public function on_post_publish( $post_id ) {
        // 异步生成（避免阻塞）
        wp_schedule_single_event( time() + 5, 'wpaip_generate_sitemap' );
    }
    
    /**
     * 文章删除时更新Sitemap
     */
    public function on_post_delete( $post_id ) {
        // 仅处理插件支持的内容类型
        $post_type = get_post_type( $post_id );
        if ( ! WPAIP_B7PQ::is_supported( $post_type ) ) {
            return;
        }
        wp_schedule_single_event( time() + 5, 'wpaip_generate_sitemap' );
    }
    
    /**
     * 生成Sitemap文件
     */
    public function generate_sitemap_file() {
        // 获取上传目录
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $sitemap_dir = $base_dir . '/wpaip-sitemap';

        // 检查缓存：如果所有 sitemap 文件都已生成且未过期（1 小时内），跳过重新生成
        $sitemap_cache_key = 'wpaip_sitemap_generated';
        $sitemap_last_gen  = get_transient( $sitemap_cache_key );
        if ( false !== $sitemap_last_gen ) {
            return true;
        }
        
        // 安全检查：确保基础目录存在且路径安全
        if (!is_dir($base_dir) || !is_writable($base_dir)) {
            return false;
        }
        
        // 创建目录
        if ( ! file_exists( $sitemap_dir ) ) {
            wp_mkdir_p( $sitemap_dir );
        }
        
        // 安全检查：确保目录存在且是预期路径的子目录
        if (!is_dir($sitemap_dir) || !str_starts_with(realpath($sitemap_dir), realpath($base_dir))) {
            return false;
        }
        
        // 额外检查：拒绝路径遍历字符
        if ( strpos( $sitemap_dir, '..' ) !== false || strpos( $sitemap_dir, '..\\' ) !== false ) {
            return false;
        }
        
        // 定义允许的文件名
        $allowed_files = ['sitemap.xml', 'sitemap-post.xml'];
        
        // 生成主Sitemap
        $sitemap_content = $this->build_sitemap_content();
        file_put_contents( $sitemap_dir . '/sitemap.xml', $sitemap_content );
        
        // 生成文章Sitemap
        $post_sitemap = $this->build_post_sitemap_content();
        file_put_contents( $sitemap_dir . '/sitemap-post.xml', $post_sitemap );
        
        // 生成分类/标签Sitemap
        foreach ( $this->taxonomies as $taxonomy ) {
            // 额外的安全检查：只允许字母数字和短横线
            $safe_taxonomy = preg_replace('/[^a-zA-Z0-9\-_]/', '', $taxonomy);
            if ( $this->taxonomy_has_terms( $taxonomy ) && strlen($safe_taxonomy) > 0 ) {
                $tax_sitemap = $this->build_taxonomy_sitemap_content( $taxonomy );
                file_put_contents( $sitemap_dir . '/sitemap-taxonomy-' . $safe_taxonomy . '.xml', $tax_sitemap );
            }
        }
        
        // 更新sitemap URL记录
        update_option( 'wpaip_sitemap_last_update', time() );

        // 设置 transient 缓存，1 小时内不再重新生成
        set_transient( $sitemap_cache_key, time(), HOUR_IN_SECONDS );
    }
    
    /**
     * 构建Sitemap内容
     */
    private function build_sitemap_content() {
        ob_start();
        $this->render_sitemap();
        return ob_get_clean();
    }
    
    /**
     * 构建文章Sitemap内容
     */
    private function build_post_sitemap_content() {
        ob_start();
        $this->render_post_sitemap();
        return ob_get_clean();
    }
    
    /**
     * 构建分类Sitemap内容
     */
    private function build_taxonomy_sitemap_content( $taxonomy ) {
        ob_start();
        $this->render_taxonomy_sitemap( $taxonomy );
        return ob_get_clean();
    }
    
    /**
     * 获取Sitemap URL
     */
    public function get_sitemap_url() {
        return home_url( '/sitemap.xml' );
    }
    
    /**
     * 检查Sitemap是否存在
     */
    public function sitemap_exists() {
        $upload_dir = wp_upload_dir();
        return file_exists( $upload_dir['basedir'] . '/wpaip-sitemap/sitemap.xml' );
    }
    
    /**
     * 获取Sitemap最后更新时间
     */
    public function get_last_update() {
        return get_option( 'wpaip_sitemap_last_update', 0 );
    }
}
