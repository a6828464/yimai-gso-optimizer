<?php
/**
 * LLMs.txt 生成器 - GEO 优化核心功能
 * 生成符合 llms.txt 规范的 AI 友好文件
 * 
 * 规范参考：https://llmstxt.org/
 * 
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_LLMs_Txt_Generator {

    private static $instance = null;
    
    const OPTION_KEY = 'yimai_gso_llms_txt_settings';
    
    const CACHE_KEY = 'yimai_gso_llms_txt_content';
    const CACHE_KEY_FULL = 'yimai_gso_llms_full_txt_content';
    
    const DEFAULT_MAX_POSTS = 100;
    const DEFAULT_MAX_DESC_LENGTH = 200;

    /**
     * 站点落地页（t8）
     *
     * 主题是以静态模板实现的单页式站点（page-studio.php / page-classes.php …），
     * 后台未必建了同名 page。llms.txt 的 Core Pages 必须把这些真入口全部列出，
     * 否则大模型只知道首页，站点等于只有一条 URL。
     *
     * 键为 slug，值为「标题 + 一句话用途」；若站点真建了同名 page，会改用其真实
     * 标题与固定链接（自定义固定链接结构下也正确）。
     */
    const LANDING_PAGES = array(
        'studio'      => array( 'title' => '空间与门店', 'desc' => '宁波门店地址、环境与到店方式' ),
        'classes'     => array( 'title' => '课程体系', 'desc' => '瑜伽课程分类、难度与课表' ),
        'training'    => array( 'title' => '教练培训', 'desc' => '瑜伽教培体系的课程与报名要求' ),
        'membership'  => array( 'title' => '会员方案', 'desc' => '会员卡种、权益与价格' ),
        'instructors' => array( 'title' => '师资团队', 'desc' => '教练背景、资质与专长' ),
        'booking'     => array( 'title' => '预约体验', 'desc' => '在线预约体验课与咨询入口' ),
        'contact'     => array( 'title' => '联系我们', 'desc' => '门店电话、地址与常见问答' ),
        'blog'        => array( 'title' => '博客', 'desc' => '瑜伽练习方法与生活方式文章' ),
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 注册 rewrite 规则（前台访问 /llms.txt 和 /llms-full.txt）
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        
        // 提供服务（输出 llms.txt 内容）
        add_action( 'template_redirect', array( $this, 'serve_llms_txt' ), 1 );
        
        // 文章/页面更新时刷新缓存
        add_action( 'save_post', array( $this, 'on_post_save' ), 10, 3 );
        add_action( 'delete_post', array( $this, 'on_post_delete' ) );
        
        // 分类/标签更新时刷新缓存
        add_action( 'created_term', array( $this, 'on_term_change' ), 10, 3 );
        add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
        add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );
        
        // 设置保存后刷新
        add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'on_settings_update' ), 10, 2 );
        
        // 注册设置
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // Ajax 处理程序
        add_action( 'wp_ajax_yimai_gso_generate_llms_txt_preview', array( $this, 'ajax_generate_preview' ) );
        add_action( 'wp_ajax_yimai_gso_test_llms_txt_access', array( $this, 'ajax_test_access' ) );
        add_action( 'wp_ajax_yimai_gso_flush_rewrite_rules', array( $this, 'ajax_flush_rewrite_rules' ) );

        // A-收尾：分类归档已是 noindex，旧站把 include_categories 从 1 迁到 0（只跑一次）
        $this->maybe_migrate_categories_default();
    }

    /**
     * 添加 rewrite 规则
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^llms\.txt$', 'index.php?yimai_gso_llms_txt=1', 'top' );
        add_rewrite_rule( '^llms-full\.txt$', 'index.php?yimai_gso_llms_full_txt=1', 'top' );

        // 首次注册或规则变更后自动刷新（transient 限频，避免每次请求都刷新）
        if ( false === get_transient( 'yimai_gso_llms_rewrite_flushed' ) ) {
            flush_rewrite_rules();
            set_transient( 'yimai_gso_llms_rewrite_flushed', 1, DAY_IN_SECONDS );
        }
    }

    /**
     * 添加查询变量
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'yimai_gso_llms_txt';
        $vars[] = 'yimai_gso_llms_full_txt';
        return $vars;
    }

    /**
     * 输出 llms.txt 内容
     */
    public function serve_llms_txt() {
        global $wp_query;
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_llms_txt'] ) ) {
            // 缓存优先：12 小时内命中则直接返回（save_post/settings_update 触发清除）
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached ) {
                @header( 'Content-Type: text/markdown; charset=utf-8' );
                echo $cached;
                exit;
            }
            ob_start();
            $this->render_llms_txt();
            $content = ob_get_clean();
            if ( ! empty( $content ) ) {
                set_transient( self::CACHE_KEY, $content, 12 * HOUR_IN_SECONDS );
            }
            @header( 'Content-Type: text/markdown; charset=utf-8' );
            echo $content;
            exit;
        }
        
        if ( ! empty( $wp_query->query_vars['yimai_gso_llms_full_txt'] ) ) {
            // 缓存优先：12 小时内命中则直接返回（save_post/settings_update 触发清除）
            $cached = get_transient( self::CACHE_KEY_FULL );
            if ( false !== $cached ) {
                @header( 'Content-Type: text/markdown; charset=utf-8' );
                echo $cached;
                exit;
            }
            ob_start();
            $this->render_llms_full_txt();
            $content = ob_get_clean();
            if ( ! empty( $content ) ) {
                set_transient( self::CACHE_KEY_FULL, $content, 12 * HOUR_IN_SECONDS );
            }
            @header( 'Content-Type: text/markdown; charset=utf-8' );
            echo $content;
            exit;
        }
    }

    /**
     * 生成 llms.txt（简洁版）
     * 
     * 格式规范：
     * # 网站名称
     * > 一句话简介
     * ## 章节标题
     * - [链接文本](URL): 描述
     */
    private function render_llms_txt() {
        @header( 'Content-Type: text/markdown; charset=utf-8' );
        
        $settings = $this->get_settings();
        $site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
        $site_description = wp_strip_all_tags( get_bloginfo( 'description' ) );
        
        // # 网站名称
        echo "# {$site_name}\n\n";
        
        // > 一句话简介
        $intro = ! empty( $settings['intro'] ) ? wp_strip_all_tags( $settings['intro'] ) : $site_description;
        $intro = trim( $intro );
        if ( ! empty( $intro ) ) {
            echo "> {$intro}\n\n";
        }
        
        // ## Optional: 详细说明
        if ( ! empty( $settings['details'] ) ) {
            echo "## Optional\n\n";
            echo trim( wp_strip_all_tags( $settings['details'] ) ) . "\n\n";
        }
        
        // ## Core Pages
        echo "## Core Pages\n\n";
        $this->render_core_pages( $settings );
        
        // ## Categories（如果启用）
        if ( ! empty( $settings['include_categories'] ) ) {
            echo "\n## Categories\n\n";
            $this->render_categories( $settings );
        }
        
        // ## Recent Posts（如果启用）
        if ( ! empty( $settings['include_recent_posts'] ) ) {
            echo "\n## Recent Posts\n\n";
            $this->render_recent_posts( $settings );
        }
        
        // ## AI Usage Policy
        if ( ! empty( $settings['ai_policy'] ) ) {
            echo "\n## AI Usage Policy\n\n";
            echo "- " . wp_strip_all_tags( $settings['ai_policy'] ) . "\n";
        }
    }

    /**
     * 生成 llms-full.txt（完整版）
     */
    private function render_llms_full_txt() {
        @header( 'Content-Type: text/markdown; charset=utf-8' );
        
        $settings = $this->get_settings();
        $site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
        
        // 先输出简洁版内容
        echo "# {$site_name} (Full Content)\n\n";
        
        $intro = ! empty( $settings['intro'] ) ? wp_strip_all_tags( $settings['intro'] ) : wp_strip_all_tags( get_bloginfo( 'description' ) );
        if ( ! empty( $intro ) ) {
            echo "> {$intro}\n\n";
        }
        
        echo "---\n\n";
        echo "## Full Content\n\n";
        
        // 获取所有公开文章
        $posts = $this->get_public_posts( $settings );
        
        foreach ( $posts as $post ) {
            $title   = wp_strip_all_tags( get_the_title( $post ) );
            $url     = esc_url( get_permalink( $post->ID ) );
            // ★ 修复（审计 #10）：llms-full.txt 行业语义 = 站点内容全文，原先只输出
            //   200 字截断摘要（get_post_excerpt），AI 引擎拿不到正文实质内容。
            //   改为输出清洗后的正文全文（保留 H2/H3 标题行），超长截断至 8000 字。
            // 把常见块级标记转成 Markdown 风格标题/段落，便于 AI 分段理解
            $content = preg_replace( '/<h[23][^>]*>(.*?)<\/h[23]>/is', "\n\n## $1\n", $post->post_content );
            $content = wp_strip_all_tags( $content );
            $content = trim( preg_replace( '/\n{3,}/', "\n\n", $content ) );
            if ( mb_strlen( $content ) > 8000 ) {
                $content = mb_substr( $content, 0, 8000 ) . "\n\n（正文超长，已截断，完整内容见 URL）";
            }
            
            echo "### {$title}\n\n";
            
            if ( ! empty( $content ) ) {
                echo "{$content}\n\n";
            }
            
            echo "- URL: {$url}\n";
            
            // 可选：输出文章标签
            $tags = get_the_tags( $post->ID );
            if ( ! empty( $tags ) ) {
                $tag_names = array_map( function( $t ) { return wp_strip_all_tags( $t->name ); }, $tags );
                echo "- Tags: " . implode( ', ', $tag_names ) . "\n";
            }
            
            echo "\n";
        }
    }

    /**
     * 渲染核心页面
     */
    private function render_core_pages( $settings ) {
        $pages = $this->get_core_pages( $settings );
        
        foreach ( $pages as $page ) {
            $title = wp_strip_all_tags( $page['title'] );
            $url   = esc_url( $page['url'] );
            $desc  = wp_strip_all_tags( $page['desc'] ?? '' );
            
            if ( ! empty( $desc ) ) {
                echo "- [{$title}]({$url}): {$desc}\n";
            } else {
                echo "- [{$title}]({$url})\n";
            }
        }
        
        echo "\n";
    }

    /**
     * 获取核心页面列表
     */
    private function get_core_pages( $settings ) {
        $pages = array();
        $seen  = array();

        // 首页
        $home_url = home_url( '/' );
        $pages[] = array(
            'title' => '首页',
            'url'   => $home_url,
            'desc'  => get_bloginfo( 'description' ),
        );
        $seen[] = $this->normalize_url( $home_url );

        // 落地页（t8：/studio /classes /training /membership /instructors
        // /booking /contact /blog —— 原先只输出首页一条，等于没告诉大模型站点结构）
        foreach ( $this->get_landing_pages() as $landing ) {
            $key = $this->normalize_url( $landing['url'] );
            if ( in_array( $key, $seen, true ) ) {
                continue;
            }
            $pages[] = $landing;
            $seen[]  = $key;
        }

        // 文章页面（首页或文章页）
        $posts_page_id = get_option( 'page_for_posts' );
        if ( $posts_page_id ) {
            $posts_url = get_permalink( $posts_page_id );
            $key       = $this->normalize_url( $posts_url );
            if ( ! in_array( $key, $seen, true ) ) {
                $pages[] = array(
                    'title' => '文章',
                    'url'   => $posts_url,
                    'desc'  => '最新文章列表',
                );
                $seen[] = $key;
            }
        }
        
        // 用户自定义页面
        if ( ! empty( $settings['core_pages'] ) && is_array( $settings['core_pages'] ) ) {
            foreach ( $settings['core_pages'] as $page_id ) {
                $page = get_post( $page_id );
                if ( $page && 'publish' === $page->post_status ) {
                    $page_url = get_permalink( $page->ID );
                    $key      = $this->normalize_url( $page_url );
                    if ( in_array( $key, $seen, true ) ) {
                        continue;
                    }
                    $pages[] = array(
                        'title' => get_the_title( $page ),
                        'url'   => $page_url,
                        'desc'  => $this->get_post_excerpt( $page ),
                    );
                    $seen[] = $key;
                }
            }
        }
        
        return $pages;
    }

    /**
     * 站点落地页列表（t8）
     *
     * @return array
     */
    private function get_landing_pages() {
        $pages    = array();
        $posts_id = (int) get_option( 'page_for_posts' );

        foreach ( self::LANDING_PAGES as $slug => $meta ) {
            $url   = home_url( '/' . $slug . '/' );
            $title = $meta['title'];
            $desc  = $meta['desc'];
            $post  = null;

            // 博客页优先指向「设置 → 阅读 → 文章页面」
            if ( 'blog' === $slug && $posts_id ) {
                $post = get_post( $posts_id );
            }
            if ( ! $post ) {
                $candidate = get_page_by_path( $slug );
                if ( $candidate && 'publish' === $candidate->post_status ) {
                    $post = $candidate;
                }
            }

            if ( $post ) {
                // 已被 noindex 的页面不往 llms.txt 里塞
                if ( class_exists( 'YIMAI_GSO_Sitemap_Generator' ) && method_exists( 'YIMAI_GSO_Sitemap_Generator', 'is_post_indexable' ) ) {
                    $indexable = (bool) YIMAI_GSO_Sitemap_Generator::is_post_indexable( $post->ID );
                } else {
                    // 类未加载时按同一份 post meta 判定，避免依赖加载顺序
                    $indexable = ( '1' !== (string) get_post_meta( $post->ID, 'yimai_gso_noindex', true ) );
                }
                if ( ! $indexable ) {
                    continue;
                }
                $url   = get_permalink( $post );
                $title = get_the_title( $post );
            }

            $pages[] = array(
                'title' => $title ?: $meta['title'],
                'url'   => $url,
                'desc'  => $desc,
            );
        }

        return (array) apply_filters( 'yimai_gso_llms_landing_pages', $pages );
    }

    /**
     * URL 归一化（用于去重：忽略协议、host 大小写与末尾斜杠差异）
     *
     * @param string $url
     * @return string
     */
    private function normalize_url( $url ) {
        $url = strtolower( trim( (string) $url ) );
        $url = preg_replace( '#^https?://#', '', $url );
        return rtrim( $url, '/' );
    }

    /**
     * 渲染文章分类
     */
    private function render_categories( $settings ) {
        $args = array(
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
        );
        
        // 排除分类
        $exclude = array();
        if ( ! empty( $settings['excluded_categories'] ) ) {
            $exclude = array_map( 'intval', (array) $settings['excluded_categories'] );
        }

        // t8：「未分类」不进 llms.txt —— 它只是默认分类的占位，对 AI 是纯噪声
        $uncategorized = get_term_by( 'slug', 'uncategorized', 'category' );
        if ( $uncategorized instanceof WP_Term ) {
            $exclude[] = (int) $uncategorized->term_id;
        }
        $default_category = (int) get_option( 'default_category' );
        if ( $default_category > 0 ) {
            $exclude[] = $default_category;
        }

        $exclude = array_values( array_unique( array_filter( $exclude ) ) );
        if ( ! empty( $exclude ) ) {
            $args['exclude'] = $exclude;
        }
        
        $categories = get_categories( $args );
        
        foreach ( $categories as $cat ) {
            $cat_name = wp_strip_all_tags( $cat->name );
            $url      = esc_url( get_category_link( $cat->term_id ) );
            $desc     = wp_strip_all_tags( $cat->description );

            if ( ! empty( $desc ) ) {
                echo "- [{$cat_name}]({$url}): {$desc}\n";
            } else {
                echo "- [{$cat_name}]({$url}): {$cat->count} 篇文章\n";
            }
        }
        
        echo "\n";
    }

    /**
     * 渲染最近文章
     */
    private function render_recent_posts( $settings ) {
        $posts = $this->get_public_posts( $settings );
        
        foreach ( $posts as $post ) {
            $title   = wp_strip_all_tags( get_the_title( $post ) );
            $url     = esc_url( get_permalink( $post->ID ) );
            $excerpt = wp_strip_all_tags( $this->get_post_excerpt( $post ) );
            
            if ( ! empty( $excerpt ) ) {
                echo "- [{$title}]({$url}): {$excerpt}\n";
            } else {
                echo "- [{$title}]({$url})\n";
            }
        }
        
        echo "\n";
    }

    /**
     * 获取公开文章
     */
    private function get_public_posts( $settings ) {
        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => ! empty( $settings['max_posts'] ) ? intval( $settings['max_posts'] ) : self::DEFAULT_MAX_POSTS,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'has_password'   => false,
        );
        
        // 排除分类
        if ( ! empty( $settings['excluded_categories'] ) ) {
            $args['category__not_in'] = $settings['excluded_categories'];
        }
        
        return get_posts( $args );
    }

    /**
     * 获取文章摘要
     */
    private function get_post_excerpt( $post ) {
        if ( ! empty( $post->post_excerpt ) ) {
            $excerpt = $post->post_excerpt;
        } else {
            $excerpt = wp_trim_words( $post->post_content, 30, '...' );
        }
        
        $excerpt = wp_strip_all_tags( $excerpt );
        $excerpt = trim( $excerpt );
        
        // 限制长度
        if ( mb_strlen( $excerpt ) > self::DEFAULT_MAX_DESC_LENGTH ) {
            $excerpt = mb_substr( $excerpt, 0, self::DEFAULT_MAX_DESC_LENGTH ) . '...';
        }
        
        return $excerpt;
    }

    /**
     * 文章保存时刷新缓存
     */
    public function on_post_save( $post_id, $post, $update ) {
        // 跳过自动保存
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        
        // 跳过修订版本
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        
        // 只处理已发布的文章
        if ( 'publish' !== get_post_status( $post_id ) ) {
            return;
        }
        
        $this->z4();
    }

    /**
     * 文章删除时刷新缓存
     */
    public function on_post_delete( $post_id ) {
        $this->z4();
    }

    /**
     * 分类/标签变更时刷新缓存
     */
    public function on_term_change( $term_id, $tt_id, $taxonomy ) {
        $this->z4();
    }

    /**
     * 设置更新时刷新缓存
     */
    public function on_settings_update( $old_value, $new_value ) {
        $this->z4();
    }

    /**
     * 清除缓存
     */
    private function z4() {
        delete_transient( self::CACHE_KEY );
        delete_transient( self::CACHE_KEY_FULL );
    }

    /**
     * 获取设置
     */
    public function get_settings() {
        $defaults = array(
            'intro'                  => '',
            'details'                => '',
            'core_pages'             => array(),
            'include_categories'     => 0,
            'include_recent_posts'   => 1,
            'excluded_categories'    => array(),
            'max_posts'              => self::DEFAULT_MAX_POSTS,
            'ai_policy'              => '',
        );
        
        $settings = get_option( self::OPTION_KEY, array() );
        
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * 一次性迁移：llms.txt 不再默认推荐分类（A-收尾）
     *
     * 背景：分类归档现在是 noindex, follow，llms.txt 再列分类链接等于一边叫 AI 去抓、
     * 一边叫搜索引擎别收。旧站升级时把 include_categories 从 1 改成 0，并写迁移标记
     * yimai_gso_llms_categories_migrated=1 —— 只跑一次，用户日后在后台手动勾回「包含
     * 分类目录」不会被再次覆盖。
     *
     * 幂等：标记存在即返回；option 不存在（新站）时只写标记不改数据，默认值本身就是 0。
     */
    public function maybe_migrate_categories_default() {
        if ( '1' === (string) get_option( 'yimai_gso_llms_categories_migrated', '' ) ) {
            return;
        }

        $settings = get_option( self::OPTION_KEY, array() );
        if ( is_array( $settings ) && ! empty( $settings['include_categories'] ) ) {
            $settings['include_categories'] = 0;
            update_option( self::OPTION_KEY, $settings );
            // 迁移前算好的 llms.txt 里还有 Categories 段，清缓存让它按新设置重算
            $this->z4();
        }

        update_option( 'yimai_gso_llms_categories_migrated', '1' );
    }

    /**
     * 注册设置
     */
    public function register_settings() {
        register_setting( 'yimai_gso_llms_txt_group', self::OPTION_KEY );
    }

    /**
     * 生成 llms.txt 内容（用于预览）
     */
    public function generate_content( $full = false ) {
        ob_start();
        
        if ( $full ) {
            $this->render_llms_full_txt();
        } else {
            $this->render_llms_txt();
        }
        
        $content = ob_get_clean();
        
        return $content;
    }

    /**
     * 检查功能是否可用
     */
    public static function is_available() {
        if ( ! true ) {
            return false;
        }
        
        return true;
    }

    /**
     * Ajax：生成预览
     */
    public function ajax_generate_preview() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // ★ 功能权限检查（LLMs生成仅年费/终身版可用）
        // 临时保存设置（逐字段净化，防止存储型XSS）
        parse_str( $_POST['settings'] ?? '', $settings_array );
        if ( ! empty( $settings_array['yimai_gso_llms_txt_settings'] ) ) {
            $raw        = $settings_array['yimai_gso_llms_txt_settings'];
            $sanitized  = array();
            $sanitized['intro']                = sanitize_text_field( $raw['intro'] ?? '' );
            $sanitized['details']              = wp_kses_post( $raw['details'] ?? '' );
            $sanitized['ai_policy']            = sanitize_text_field( $raw['ai_policy'] ?? '' );
            $sanitized['include_categories']   = ! empty( $raw['include_categories'] ) ? 1 : 0;
            $sanitized['include_recent_posts'] = ! empty( $raw['include_recent_posts'] ) ? 1 : 0;
            $sanitized['max_posts']            = isset( $raw['max_posts'] ) ? max( 10, min( 500, intval( $raw['max_posts'] ) ) ) : 100;
            $sanitized['core_pages']           = isset( $raw['core_pages'] ) && is_array( $raw['core_pages'] ) ? array_map( 'sanitize_text_field', $raw['core_pages'] ) : array();
            $sanitized['excluded_categories']  = isset( $raw['excluded_categories'] ) && is_array( $raw['excluded_categories'] ) ? array_map( 'absint', $raw['excluded_categories'] ) : array();
            update_option( self::OPTION_KEY, $sanitized );
        }
        
        // 生成内容
        ob_start();
        $this->render_llms_txt();
        $content = ob_get_clean();
        
        wp_send_json_success( array( 'content' => $content ) );
    }

    /**
     * Ajax：测试 llms.txt 访问
     */
    public function ajax_test_access() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // ★ 功能权限检查
        $url = home_url( '/llms.txt' );
        $response = wp_remote_head( $url, array( 'timeout' => 10 ) );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => '请求失败：' . $response->get_error_message() ) );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            wp_send_json_success( array( 'message' => 'llms.txt 可访问（状态码：' . $code . '）' ) );
        } else {
            wp_send_json_error( array( 'message' => 'llms.txt 无法访问（状态码：' . $code . '）' ) );
        }
    }

    /**
     * Ajax：刷新固定链接
     */
    public function ajax_flush_rewrite_rules() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // 清除 transient 以便 add_rewrite_rules() 在下次 init 时重新刷新
        delete_transient( 'yimai_gso_llms_rewrite_flushed' );
        flush_rewrite_rules();

        wp_send_json_success( array( 'message' => '固定链接已刷新' ) );
    }
}
