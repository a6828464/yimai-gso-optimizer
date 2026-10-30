<?php
/**
 * Sitemap生成类
 * 生成符合搜索引擎要求的XML Sitemap
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Sitemap_Generator {

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
        $this->post_types = YIMAI_GSO_Post_Type_Helper::get_supported();
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
        add_action( 'yimai_gso_generate_sitemap', array( $this, 'generate_sitemap_file' ) );
        
        // 文章发布时更新Sitemap
        add_action( 'publish_post', array( $this, 'on_post_publish' ) );
        add_action( 'delete_post', array( $this, 'on_post_delete' ) );
    }
    
    /**
     * 添加rewrite规则
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^sitemap\.xml$', 'index.php?yimai_gso_sitemap=1', 'top' );
        add_rewrite_rule( '^sitemap-post\.xml$', 'index.php?yimai_gso_sitemap_post=1', 'top' );
        // t8：页面单独成图（原先 page 混在 sitemap-post.xml 里，页面 @type=WebPage 与文章混报）
        add_rewrite_rule( '^sitemap-page\.xml$', 'index.php?yimai_gso_sitemap_page=1', 'top' );
        add_rewrite_rule( '^sitemap-taxonomy-(\w+)\.xml$', 'index.php?yimai_gso_sitemap_taxonomy=$matches[1]', 'top' );
        add_rewrite_rule( '^sitemap\.html$', 'index.php?yimai_gso_sitemap_html=1', 'top' );
        add_rewrite_rule( '^robots\.txt$', 'index.php?yimai_gso_robots=1', 'top' );
    }
    
    /**
     * 添加查询变量
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'yimai_gso_sitemap';
        $vars[] = 'yimai_gso_sitemap_post';
        $vars[] = 'yimai_gso_sitemap_page';
        $vars[] = 'yimai_gso_sitemap_taxonomy';
        $vars[] = 'yimai_gso_sitemap_html';
        $vars[] = 'yimai_gso_robots';
        return $vars;
    }

    /**
     * 版本升级后自动刷新 rewrite rules（一次性）
     */
    public function maybe_flush_rewrite_rules() {
        $current_version = defined( 'YIMAI_GSO_VERSION' ) ? YIMAI_GSO_VERSION : '0';
        // t8：新增 sitemap-page.xml 子图，重写规则变了 —— 用独立后缀强制刷新一次，
        // 不必为此提升插件版本号（主题/插件版本头保持原样）
        $current_version .= '-submap-v1';
        $flush_version = get_option( 'yimai_gso_sitemap_flush_version', '' );
        if ( $flush_version !== $current_version ) {
            flush_rewrite_rules();
            update_option( 'yimai_gso_sitemap_flush_version', $current_version, false );
        }
    }
    
    /**
     * 提供Sitemap服务
     */
    public function serve_sitemap() {
        global $wp_query;
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_sitemap'] ) ) {
            $this->render_sitemap();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_sitemap_post'] ) ) {
            $this->render_post_sitemap();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_sitemap_page'] ) ) {
            $this->render_page_sitemap();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_sitemap_taxonomy'] ) ) {
            $taxonomy = sanitize_text_field( $wp_query->query_vars['yimai_gso_sitemap_taxonomy'] );

            // A-收尾：分类归档是 noindex,follow，分类/标签 sitemap 默认关闭。
            // 关闭时必须给真 404，否则这个没被主索引收录的端点仍是「存在」的孤儿，
            // 和 noindex 自相矛盾。开关见后台「发布 → 索引控制」。
            if ( ! self::taxonomy_sitemap_enabled() ) {
                status_header( 404 );
                nocache_headers();
                exit;
            }

            $this->render_taxonomy_sitemap( $taxonomy );
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_sitemap_html'] ) ) {
            $this->render_sitemap_html();
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_robots'] ) ) {
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

        // 文章子图：只包含 post_type != page 的支持类型（t8：page 不再混进文章图）
        $post_types = $this->get_post_sitemap_types();
        if ( ! empty( $post_types ) && $this->has_indexable_posts( $post_types ) ) {
            echo "\t" . '<sitemap>' . "\n";
            echo "\t\t" . '<loc>' . esc_url( home_url( '/sitemap-post.xml' ) ) . '</loc>' . "\n";
            echo "\t\t" . '<lastmod>' . esc_html( $this->get_lastmod( $post_types ) ) . '</lastmod>' . "\n";
            echo "\t" . '</sitemap>' . "\n";
        }

        // 页面子图：page 独立成图（t8 新增）
        if ( $this->page_sitemap_enabled() && $this->has_indexable_posts( array( 'page' ) ) ) {
            echo "\t" . '<sitemap>' . "\n";
            echo "\t\t" . '<loc>' . esc_url( home_url( '/sitemap-page.xml' ) ) . '</loc>' . "\n";
            echo "\t\t" . '<lastmod>' . esc_html( $this->get_lastmod( array( 'page' ) ) ) . '</lastmod>' . "\n";
            echo "\t" . '</sitemap>' . "\n";
        }

        // A-收尾：分类/标签 sitemap 关闭时，索引里也不列（端点会 404，列了就是死链）
        if ( self::taxonomy_sitemap_enabled() ) {
            foreach ( $this->taxonomies as $taxonomy ) {
                if ( $this->taxonomy_has_terms( $taxonomy ) ) {
                    echo "\t" . '<sitemap>' . "\n";
                    echo "\t\t" . '<loc>' . esc_url( home_url( '/sitemap-taxonomy-' . $taxonomy . '.xml' ) ) . '</loc>' . "\n";
                    echo "\t\t" . '<lastmod>' . esc_html( $this->get_lastmod() ) . '</lastmod>' . "\n";
                    echo "\t" . '</sitemap>' . "\n";
                }
            }
        }
        echo '</sitemapindex>' . "\n";
    }
    
    /**
     * 渲染文章Sitemap
     */
    private function render_post_sitemap() {
        $this->render_urlset( $this->get_posts( 5000, $this->get_post_sitemap_types() ) );
    }

    /**
     * 渲染页面Sitemap（t8：page 独立成图，不再混进 sitemap-post.xml）
     */
    private function render_page_sitemap() {
        if ( ! $this->page_sitemap_enabled() ) {
            @status_header( 404 );
            exit;
        }
        $this->render_urlset( $this->get_posts( 5000, array( 'page' ) ) );
    }

    /**
     * 输出 urlset（文章子图 / 页面子图共用）
     *
     * @param array $post_ids 已按 noindex 规则过滤的文章 ID 列表
     */
    private function render_urlset( $post_ids ) {

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
            // L6: SEO-GEO Bridge sitemap enhancement ()
            echo apply_filters( 'yimai_gso_sitemap_entry_filter', '', $post_id );
            echo "\t" . '</url>' . "\n";
            unset( $post ); // 逐篇释放内存
        }
        echo '</urlset>' . "\n";
    }
    
    /**
     * 渲染分类/标签Sitemap
     */
    private function render_taxonomy_sitemap( $taxonomy ) {
        // 双保险：开关关闭时任何调用路径都 404（serve_sitemap 已拦一次）
        if ( ! self::taxonomy_sitemap_enabled() ) {
            @status_header( 404 );
            exit;
        }

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
        $categories = $this->get_terms( 'category' );
        $tags = $this->get_terms( 'post_tag' );
        $categories = is_wp_error( $categories ) ? array() : $categories;
        $tags = is_wp_error( $tags ) ? array() : $tags;
        
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
        <?php if ( $this->page_sitemap_enabled() ) : ?>
        <p><a href="<?php echo esc_url( home_url( '/sitemap-page.xml' ) ); ?>"><?php echo esc_url( home_url( '/sitemap-page.xml' ) ); ?></a></p>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
    }
    
    /**
     * 渲染 robots.txt
     */
    private function render_robots() {
        $robots_content = YIMAI_GSO_Settings::get( 'yimai_gso_robots_content', '' );

        // t8：后台保存的自定义 robots 内容同样要清掉非法地图声明
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) && method_exists( 'YIMAI_GSO_Crawler_Editor', 'sanitize_robots_text' ) ) {
            $robots_content = YIMAI_GSO_Crawler_Editor::sanitize_robots_text( $robots_content );
        }

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
            // ★ 修复（审计建议）：移除 Disallow: /wp-includes/ 与 /wp-content/plugins/——
            //   Google 搜索中心明确建议不要屏蔽 wp-includes（JS/CSS 资源位于这些目录，
            //   屏蔽会影响搜索引擎渲染页面）。缓存目录保留屏蔽。
            echo "Disallow: /wp-content/cache/\n";
            echo "Disallow: /trackback/\n";
            echo "Disallow: /?s=\n";
            echo "Disallow: /?feed=\n";
            echo "Disallow: /comments/\n";

            // 屏蔽 WordPress 自带的虚拟 robots.txt（让插件接管）
            echo "\n# 禁止访问 WordPress 默认的 ?robots=1\n";
            echo "Disallow: /?robots=1\n";

            // Sitemap 地址（t8：只声明真正的 XML 地图。
            //   /sitemap.html 是给人看的索引页、llms.txt 是给大模型看的内容清单，
            //   两者都不是 sitemap 协议的一部分，声明出来徒增无效抓取。）
            echo "\n# Sitemap\n";
            echo "Sitemap: " . esc_url( home_url( '/sitemap.xml' ) ) . "\n";
            
            // AI 爬虫规则
            $crawler_block = apply_filters( 'yimai_gso_robots_crawler_block', '', $robots_content );
            // t8：第三方过滤进来的内容同样做一次地图声明清洗
            if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) && method_exists( 'YIMAI_GSO_Crawler_Editor', 'sanitize_robots_text' ) ) {
                $crawler_block = YIMAI_GSO_Crawler_Editor::sanitize_robots_text( $crawler_block );
            }
            if ( ! empty( $crawler_block ) ) {
                echo $crawler_block;
            } else {
                echo "\n# AI 爬虫（允许访问）\n";
                echo "User-agent: GPTBot\nAllow: /\n\n";
                echo "User-agent: ChatGPT-User\nAllow: /\n\n";
                echo "User-agent: ClaudeBot\nAllow: /\n\n"; // ★ 审计建议：Claude-Web 已停用，Anthropic 现用 ClaudeBot
                echo "User-agent: PerplexityBot\nAllow: /\n\n";
                echo "User-agent: Google-Extended\nAllow: /\n";
            }
        }
        exit;
    }
    
    /**
     * 获取文章列表（分批查询，避免一次性加载过多数据到内存）
     */
    private function get_posts( $limit = 5000, $post_types = null ) {
        if ( null === $post_types ) {
            $post_types = $this->post_types;
        }
        // 全局被设为 noindex 的内容类型不生成 URL
        $post_types = array_values( array_diff( (array) $post_types, self::noindex_post_types() ) );
        if ( empty( $post_types ) ) {
            return array();
        }

        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            // t8：noindex 判定需要 post meta，一次性预热缓存，避免逐篇 N 次查询
            'update_post_meta_cache' => true,
            'fields' => 'ids', // 只查ID，大幅减少内存占用
        );
        
        $ids = get_posts( $args );
        if ( empty( $ids ) ) {
            return array();
        }
        // t8：sitemap 不收录自带 noindex 的 URL（单篇 yimai_gso_noindex=1 / 全局类型 noindex）
        return array_values( array_filter( array_map( 'absint', (array) $ids ), array( __CLASS__, 'is_post_indexable' ) ) );
    }
    
    /**
     * 获取分类/标签列表（只返回可收录的 term）
     */
    private function get_terms( $taxonomy ) {
        $terms = get_terms( array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => 5000,
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return $terms;
        }
        // t8：带 noindex 的 term（含 uncategorized）不进 sitemap
        return array_values( array_filter( $terms, array( __CLASS__, 'is_term_indexable' ) ) );
    }
    
    /**
     * 检查分类是否有可收录的内容
     */
    private function taxonomy_has_terms( $taxonomy ) {
        $terms = $this->get_terms( $taxonomy );
        return ! is_wp_error( $terms ) && ! empty( $terms );
    }

    // ==================== t8：子图类型 / noindex 判定 ====================

    /**
     * 文章子图包含的类型：支持类型中排除 page
     *
     * @return array
     */
    private function get_post_sitemap_types() {
        return array_values( array_diff( $this->post_types, array( 'page' ) ) );
    }

    /**
     * 是否提供页面子图
     *
     * @return bool
     */
    private function page_sitemap_enabled() {
        return in_array( 'page', $this->post_types, true );
    }

    /**
     * 分类/标签 Sitemap 是否启用（A-收尾）
     *
     * 分类归档线上是 noindex, follow：sitemap 里再列一遍就是信号冲突，所以默认关闭
     * （端点 404、索引不列、不再生成文件）。需要在后台「发布 → 索引控制」打开
     * 「输出分类/标签 Sitemap」才恢复；与 llms.txt 的「包含分类目录」应同开同关。
     *
     * @return bool
     */
    private static function taxonomy_sitemap_enabled() {
        if ( ! class_exists( 'YIMAI_GSO_Settings' ) ) {
            return false;
        }

        return (bool) YIMAI_GSO_Settings::get( 'sitemap_taxonomy_enabled', 0 );
    }

    /**
     * 是否存在可收录内容（用于决定 sitemap 索引里是否列出该子图）
     *
     * @param array $post_types
     * @return bool
     */
    private function has_indexable_posts( $post_types ) {
        return ! empty( $this->get_posts( 1, $post_types ) );
    }

    /**
     * 全局 noindex 的内容类型（设置 → 发布 → 索引控制，主文件 output_noindex_meta 同源）
     *
     * @return array
     */
    private static function noindex_post_types() {
        if ( ! class_exists( 'YIMAI_GSO_Settings' ) ) {
            return array();
        }
        $raw = YIMAI_GSO_Settings::get( 'noindex_post_types', '' );
        if ( is_array( $raw ) ) {
            return array_values( array_filter( array_map( 'trim', $raw ) ) );
        }
        if ( ! is_string( $raw ) || '' === $raw ) {
            return array();
        }
        return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    }

    /**
     * 单篇内容是否可进入 sitemap
     *
     * 规则（与主文件 output_noindex_meta 对齐）：
     * 1. 单篇 meta yimai_gso_noindex=1 → 不进；
     * 2. 单篇显式 =0 → 进；
     * 3. 未声明 → 看全局 noindex_post_types。
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_post_indexable( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return false;
        }

        $flag    = get_post_meta( $post_id, 'yimai_gso_noindex', true );
        $flag    = is_string( $flag ) ? trim( $flag ) : $flag;
        $noindex = false;

        if ( '1' === (string) $flag ) {
            $noindex = true;
        } elseif ( '0' === (string) $flag ) {
            $noindex = false;
        } else {
            $noindex = in_array( (string) get_post_type( $post_id ), self::noindex_post_types(), true );
        }

        if ( $noindex ) {
            return false;
        }

        return (bool) apply_filters( 'yimai_gso_sitemap_post_indexable', true, $post_id );
    }

    /**
     * 「未分类」分类判断（uncategorized 或站点默认分类）
     *
     * @param WP_Term $term
     * @return bool
     */
    public static function is_uncategorized_term( $term ) {
        if ( ! $term instanceof WP_Term ) {
            return false;
        }
        if ( 'category' !== $term->taxonomy ) {
            return false;
        }
        if ( 'uncategorized' === $term->slug ) {
            return true;
        }
        return (int) get_option( 'default_category' ) === (int) $term->term_id;
    }

    /**
     * 分类法 term 是否可进入 sitemap
     *
     * 本插件对所有分类/标签/自定义分类归档默认输出 noindex（见主文件 output_noindex_meta），
     * 因此 term 未显式声明 index 时，默认不进 sitemap —— 避免「sitemap 提交的 URL 自己带
     * noindex」这种自相矛盾。站点若放开分类法收录，用
     * yimai_gso_sitemap_taxonomy_indexable 过滤器改回 true。
     *
     * @param WP_Term|int $term
     * @return bool
     */
    public static function is_term_indexable( $term ) {
        if ( is_numeric( $term ) ) {
            $term = get_term( (int) $term );
        }
        if ( ! $term instanceof WP_Term ) {
            return false;
        }

        // 1) 未分类永远不进 sitemap
        if ( self::is_uncategorized_term( $term ) ) {
            return false;
        }

        // 2) term 级显式标记（本插件 / Yoast / Rank Math / AIOSEO）
        $declared = self::term_noindex_flag( $term );
        if ( true === $declared ) {
            return false;
        }
        if ( false === $declared ) {
            return (bool) apply_filters( 'yimai_gso_sitemap_term_indexable', true, $term );
        }

        // 3) 未声明：跟随插件默认的「分类法归档 noindex」策略
        $default = (bool) apply_filters( 'yimai_gso_sitemap_taxonomy_indexable', false, $term );
        return (bool) apply_filters( 'yimai_gso_sitemap_term_indexable', $default, $term );
    }

    /**
     * 读取 term 级 noindex 声明
     *
     * @param WP_Term $term
     * @return bool|null true=明确 noindex，false=明确 index，null=未声明
     */
    private static function term_noindex_flag( $term ) {
        $keys = array( 'yimai_gso_noindex', 'yimai_gso_term_noindex' );
        foreach ( $keys as $key ) {
            $value = get_term_meta( $term->term_id, $key, true );
            if ( is_string( $value ) ) {
                $value = strtolower( trim( $value ) );
            }
            if ( '1' === (string) $value || 'true' === $value || 'noindex' === $value ) {
                return true;
            }
            if ( '0' === (string) $value || 'false' === $value || 'index' === $value ) {
                return false;
            }
        }

        // Yoast：0=默认 1=noindex 2=index
        $yoast = get_term_meta( $term->term_id, '_yoast_wpseo_meta-robots-noindex', true );
        if ( '1' === (string) $yoast ) {
            return true;
        }
        if ( '2' === (string) $yoast ) {
            return false;
        }

        // Rank Math：数组，可能含 noindex
        $rank_math = get_term_meta( $term->term_id, 'rank_math_robots', true );
        if ( is_array( $rank_math ) && ! empty( $rank_math ) ) {
            if ( in_array( 'noindex', $rank_math, true ) ) {
                return true;
            }
            if ( in_array( 'index', $rank_math, true ) ) {
                return false;
            }
        }

        // AIOSEO：数组 / JSON
        $aioseo = get_term_meta( $term->term_id, 'aioseo_robots_meta', true );
        if ( is_string( $aioseo ) ) {
            $aioseo = json_decode( $aioseo, true );
        }
        if ( is_array( $aioseo ) && isset( $aioseo['noindex'] ) ) {
            return in_array( (string) $aioseo['noindex'], array( '1', 'true', 'noindex' ), true );
        }

        return null;
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
    private function get_lastmod( $post_types = null ) {
        if ( null === $post_types ) {
            $post_types = $this->post_types;
        }
        $types = array_values( array_diff( (array) $post_types, self::noindex_post_types() ) );
        if ( empty( $types ) ) {
            return current_time( 'c' );
        }
        $last_post = wp_get_recent_posts( array(
            'numberposts' => 1,
            'post_type'   => $types,
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
        if ( ! wp_next_scheduled( 'yimai_gso_generate_sitemap' ) ) {
            wp_schedule_event( time(), 'daily', 'yimai_gso_generate_sitemap' );
        }
    }
    
    /**
     * 文章发布时更新Sitemap
     */
    public function on_post_publish( $post_id ) {
        // 异步生成（避免阻塞）
        wp_schedule_single_event( time() + 5, 'yimai_gso_generate_sitemap' );
    }
    
    /**
     * 文章删除时更新Sitemap
     */
    public function on_post_delete( $post_id ) {
        // 仅处理插件支持的内容类型
        $post_type = get_post_type( $post_id );
        if ( ! YIMAI_GSO_Post_Type_Helper::is_supported( $post_type ) ) {
            return;
        }
        wp_schedule_single_event( time() + 5, 'yimai_gso_generate_sitemap' );
    }
    
    /**
     * 生成Sitemap文件
     */
    public function generate_sitemap_file() {
        // 获取上传目录
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $sitemap_dir = $base_dir . '/yimai-gso-sitemap';

        // 检查缓存：如果所有 sitemap 文件都已生成且未过期（1 小时内），跳过重新生成
        $sitemap_cache_key = 'yimai_gso_sitemap_generated';
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
        //
        // F-1 修复：原使用 str_starts_with()，该函数是 **PHP 8.0+** 才提供，
        // 而插件声明 Requires PHP 7.4+ —— 在 7.4 上会抛出
        // "Call to undefined function str_starts_with()" 致命错误。
        // 改用 strpos() === 0 的等价写法，兼容 PHP 7.4。
        $real_sitemap = realpath( $sitemap_dir );
        $real_base    = realpath( $base_dir );
        if (
            ! is_dir( $sitemap_dir )
            || false === $real_sitemap
            || false === $real_base
            || 0 !== strpos( $real_sitemap, $real_base )
        ) {
            return false;
        }
        
        // 额外检查：拒绝路径遍历字符
        if ( strpos( $sitemap_dir, '..' ) !== false || strpos( $sitemap_dir, '..\\' ) !== false ) {
            return false;
        }

        // 生成主Sitemap
        $sitemap_content = $this->build_sitemap_content();
        file_put_contents( $sitemap_dir . '/sitemap.xml', $sitemap_content );
        
        // 生成文章Sitemap（t8：只含 post_type != page）
        $post_sitemap = $this->build_post_sitemap_content();
        file_put_contents( $sitemap_dir . '/sitemap-post.xml', $post_sitemap );

        // 生成页面Sitemap（t8：page 独立成图）
        if ( $this->page_sitemap_enabled() ) {
            $page_sitemap = $this->build_page_sitemap_content();
            file_put_contents( $sitemap_dir . '/sitemap-page.xml', $page_sitemap );
        }
        
        // 生成分类/标签Sitemap（A-收尾：默认关闭；关闭时顺手清掉历史文件，
        // 避免 uploads 里留着一份与「端点 404」矛盾的分类图）
        foreach ( $this->taxonomies as $taxonomy ) {
            // 额外的安全检查：只允许字母数字和短横线
            $safe_taxonomy = preg_replace('/[^a-zA-Z0-9\-_]/', '', $taxonomy);
            if ( strlen( $safe_taxonomy ) <= 0 ) {
                continue;
            }

            $tax_sitemap_file = $sitemap_dir . '/sitemap-taxonomy-' . $safe_taxonomy . '.xml';

            if ( ! self::taxonomy_sitemap_enabled() ) {
                if ( file_exists( $tax_sitemap_file ) ) {
                    @unlink( $tax_sitemap_file );
                }
                continue;
            }

            if ( $this->taxonomy_has_terms( $taxonomy ) ) {
                $tax_sitemap = $this->build_taxonomy_sitemap_content( $taxonomy );
                file_put_contents( $tax_sitemap_file, $tax_sitemap );
            }
        }
        
        // 更新sitemap URL记录
        update_option( 'yimai_gso_sitemap_last_update', time() );

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
     * 构建页面Sitemap内容（t8）
     */
    private function build_page_sitemap_content() {
        ob_start();
        $this->render_page_sitemap();
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
        return file_exists( $upload_dir['basedir'] . '/yimai-gso-sitemap/sitemap.xml' );
    }
    
    /**
     * 获取Sitemap最后更新时间
     */
    public function get_last_update() {
        return get_option( 'yimai_gso_sitemap_last_update', 0 );
    }
}
