<?php
/**
 * AI 平台中枢适配引擎
 *
 * 从 YIMAI_GSO_AI_Platform_Registry 读取 12 个平台配置，按需调度适配逻辑：
 * - wp_head 输出平台专属 Meta 标签
 * - wp_head 追加平台优化的 Schema JSON-LD
 * - llms.txt / llms-full.txt 追加平台覆盖区块
 * - save_post 时为各平台预生成适配内容（缓存到 post_meta）
 *
 * 设计原则：
 * - 零侵入：不修改任何 L0-L5 已有类内部逻辑
 * - 按需加载：仅对已启用平台注册钩子和执行计算
 * - 性能优先：所有重计算在 save_post 时异步完成并缓存
 *
 * 数据存储：
 * - 平台配置：option yimai_gso_ai_platform_config (JSON)
 * - 文章适配缓存：post_meta yimai_gso_platform_adapt (JSON)
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_AI_Platform_Adapter {

    private static $instance = null;

    /** @var array 已激活的平台配置缓存（从 option 读取） */
    private $platforms = array();

    /** @var array 当前启用的平台 key 列表 */
    private $active_keys = array();

    /** @var string 平台配置 option key */
    const OPTION_KEY = 'yimai_gso_ai_platform_config';

    /** @var string 文章适配缓存 post_meta key */
    const META_KEY = 'yimai_gso_platform_adapt';

    /**
     * 本请求里 adapter 自己真实输出的 Schema @type（t9 F-2）
     *
     * output_platform_schema()（wp_head 13）写入，output_platform_meta()（wp_head 14）
     * 读取 —— 保证「先写后读」，adapter 自己产出的 Person / WebPage 等节点也能进 coverage。
     *
     * @var array
     */
    private $platform_emitted_types = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        // 加载平台配置
        $this->refresh_platforms();

        // 仅当有已启用平台时才注册钩子
        if ( empty( $this->active_keys ) ) {
            return;
        }

        // 前端：追加平台优化的 Schema（先输出节点）
        add_action( 'wp_head', array( $this, 'output_platform_schema' ), 13 );

        // 前端：输出平台 Meta 标签（t9 F-2：读到写之后 —— 必须晚于 13 才能把本 adapter
        // 刚输出的节点计入 ai-schema-coverage；14 在所有 schema 发射器之后，不遮挡任何节点）
        add_action( 'wp_head', array( $this, 'output_platform_meta' ), 14 );

        // 文章保存：预生成平台适配内容
        add_action( 'save_post', array( $this, 'on_post_save' ), 20, 3 );

        // llms.txt 增强：在 init 阶段启动 output buffering（必须在 LLMs Generator 的 template_redirect 之前）
        add_action( 'init', array( $this, 'maybe_start_llms_buffer' ), 1 );

        // AJAX 端点
        add_action( 'wp_ajax_yimai_gso_platform_save_config', array( $this, 'ajax_save_config' ) );
        add_action( 'wp_ajax_yimai_gso_platform_get_config', array( $this, 'ajax_get_config' ) );
    }

    // ==================== 数据层 ====================

    /**
     * 刷新平台配置（从 option 读取 + 合并注册表默认值）
     */
    private function refresh_platforms() {
        $all_platforms = YIMAI_GSO_AI_Platform_Registry::get_all();
        $saved = $this->get_saved_config();

        $this->platforms  = array();
        $this->active_keys = array();

        foreach ( $all_platforms as $key => $defaults ) {
            // 合并保存的配置覆盖默认值
            $config = $defaults;
            if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
                $config = array_merge( $defaults, $saved[ $key ] );
            }

            // 确保 enabled 状态正确
            if ( isset( $saved[ $key ] ) && array_key_exists( 'enabled', $saved[ $key ] ) ) {
                $config['enabled'] = (bool) $saved[ $key ]['enabled'];
            }

            $this->platforms[ $key ] = $config;

            if ( ! empty( $config['enabled'] ) ) {
                $this->active_keys[] = $key;
            }
        }
    }

    /**
     * 获取保存的平台配置
     *
     * @return array
     */
    private function get_saved_config() {
        $raw = YIMAI_GSO_Settings::get( self::OPTION_KEY, '' );
        if ( empty( $raw ) ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * 保存平台配置
     *
     * @param array $config
     */
    private function save_config( $config ) {
        YIMAI_GSO_Settings::set( self::OPTION_KEY, wp_json_encode( $config, JSON_UNESCAPED_UNICODE ) );
        // 刷新内存缓存
        $this->refresh_platforms();
    }

    /**
     * 获取已启用的平台配置列表
     *
     * @return array
     */
    public function get_active_platforms() {
        $active = array();
        foreach ( $this->active_keys as $key ) {
            if ( isset( $this->platforms[ $key ] ) ) {
                $active[ $key ] = $this->platforms[ $key ];
            }
        }
        return $active;
    }

    /**
     * 获取所有平台配置（含启用状态）
     *
     * @return array
     */
    public function get_all_platforms() {
        return $this->platforms;
    }

    // ==================== Meta 标签输出 ====================

    /**
     * wp_head(12): 输出平台专属 Meta 标签
     *
     * 仅对已启用平台输出，且只在 singular 页面上执行
     */
    public function output_platform_meta() {
        // 预览请求跳过
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        // singular 页面（文章/页面/自定义类型）+ **站点首页**
        //
        // t9 F-1：站点首页（「最新文章」型首页）is_singular() 为假，但 Schema_Generator
        // 确实在那里输出了 WebSite/Organization 等节点，meta 若整段 return 就会漏报。
        // 这里只放行 is_front_page()，不放行 is_home()：
        //   · 「最新文章」设为首页时 is_front_page() 与 is_home() 同时为真 → 已被覆盖；
        //   · 静态首页 + 单独「文章页」时，/blog 只是 is_home()，不属首页 ——
        //     保持 t8 之前的行为（二级归档页不输出平台 meta），避免把归档页当成首页
        //     去声明一份只有 WebSite 的 coverage。
        $is_front = is_front_page();
        if ( ! is_singular() && ! $is_front ) {
            return;
        }

        $post_id = $is_front ? 0 : get_queried_object_id();
        if ( ! $is_front && ! $post_id ) {
            return;
        }

        // 从缓存读取适配数据（首页无 post_id 时为空数组，不影响其它标签）
        $adapt_data = $post_id ? $this->get_post_adapt_data( $post_id ) : array();

        // 汇总性标签
        $platform_names = array();
        $content_formats = array();

        foreach ( $this->active_keys as $key ) {
            $platform = $this->platforms[ $key ];
            if ( ! empty( $platform['adaptations']['meta_tags'] ) ) {
                $platform_names[] = $key;
                $content_formats[] = $platform['adaptations']['content_format'];
            }
        }

        if ( empty( $platform_names ) ) {
            return;
        }

        // t8：ai-schema-coverage 只声明本页真实输出的 @type。
        //   原先直接罗列各平台 adaptations.schema_priority（FAQPage/Product/Recipe/HowTo…），
        //   等于对外声称页面已输出这些结构化数据，而实际一篇博客只有 Article —— 属于
        //   失实标注，AI 引擎按标注核验会直接判页面不可信。
        $schema_list = $this->get_emitted_schema_types( $post_id );

        // A1：首页门店节点。主题（yimaiyoga）在 wp_head(1) 用 @graph 输出两家门店的
        //   HealthAndBeautyBusiness 节点，插件读不到别人已经 echo 的 HTML，所以按「主题
        //   真实配置」判定它会不会输出，再把类型并进覆盖率去重。只做追加，不动 t8 数据源。
        $schema_list = array_values( array_unique( array_merge( $schema_list, $this->get_theme_local_business_types() ) ) );

        echo "\n<!-- 一麦GSO AI Platform Meta (L6) -->\n";

        // 汇总标签
        echo '<meta name="ai-platforms" content="' . esc_attr( implode( ',', $platform_names ) ) . '">' . "\n";
        echo '<meta name="ai-content-format" content="' . esc_attr( implode( ',', array_unique( $content_formats ) ) ) . '">' . "\n";
        // 有真实输出才声明；一页没有结构化数据时不写这个标签（不空喊）
        if ( ! empty( $schema_list ) ) {
            echo '<meta name="ai-schema-coverage" content="' . esc_attr( implode( ',', $schema_list ) ) . '">' . "\n";
        }

        // 引用可用性
        $citation_available = ( ! empty( $adapt_data['citation_count'] ) && $adapt_data['citation_count'] > 0 ) ? 'true' : 'false';
        echo '<meta name="ai-citation-available" content="' . esc_attr( $citation_available ) . '">' . "\n";

        // 实体类型：只写本页真实输出的类型（实体未配置 / 被主题接管 / 冲突插件在位时不写）
        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) && method_exists( 'YIMAI_GSO_GEO_Entity', 'get_emitted_type' ) ) {
            $entity_type = YIMAI_GSO_GEO_Entity::get_emitted_type();
            if ( ! empty( $entity_type ) ) {
                echo '<meta name="ai-entity-type" content="' . esc_attr( $entity_type ) . '">' . "\n";
            }
        }

        // 各平台专属标签（简洁输出，每个平台一行）
        //
        // t9 F-3：schema 段只能写本页真实输出的 @type。原先这里仍用平台偏好
        // schema_priority（FAQPage,Product,Recipe,HowTo…），与上面已改成真实值的
        // ai-schema-coverage 自相矛盾 —— 等于旧的失实宣称从后门原样保留。
        // 本页没有任何真实输出时，整段 schema 不写，只留 format。
        foreach ( $this->active_keys as $key ) {
            $platform = $this->platforms[ $key ];
            if ( empty( $platform['adaptations']['meta_tags'] ) ) {
                continue;
            }
            $format  = (string) $platform['adaptations']['content_format'];
            $content = ! empty( $schema_list )
                ? 'schema:' . implode( ',', $schema_list ) . '; format:' . $format
                : 'format:' . $format;
            echo '<meta name="ai-platform:' . esc_attr( $key ) . '" content="' . esc_attr( $content ) . '">' . "\n";
        }

        echo "<!-- /一麦GSO AI Platform Meta -->\n";
    }

    /**
     * 本页真实输出的 Schema @type 列表（t8）
     *
     * 数据源只有两处，都是「已经 echo 出去的」：
     * 1. YIMAI_GSO_Schema_Generator（wp_head 10）在输出前登记的 @type；
     * 2. YIMAI_GSO_GEO_Entity（wp_head 11）实际输出的实体 @type（可能被降级为 Organization）。
     *
     * 平台配置里的 schema_priority 只是「该平台更喜欢什么」，不是本页事实，不再用它声明覆盖度。
     *
     * @param int $post_id
     * @return array
     */
    private function get_emitted_schema_types( $post_id ) {
        $types = array();

        if ( class_exists( 'YIMAI_GSO_Schema_Generator' ) && method_exists( 'YIMAI_GSO_Schema_Generator', 'get_emitted_types' ) ) {
            $types = array_merge( $types, (array) YIMAI_GSO_Schema_Generator::get_emitted_types() );
        }

        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) && method_exists( 'YIMAI_GSO_GEO_Entity', 'get_emitted_type' ) ) {
            $entity_type = YIMAI_GSO_GEO_Entity::get_emitted_type();
            if ( ! empty( $entity_type ) ) {
                $types[] = (string) $entity_type;
            }
        }

        // t9 F-2：adapter 自己在 wp_head 13 输出的节点（Person / WebPage / CreativeWork …）
        if ( ! empty( $this->platform_emitted_types ) ) {
            $types = array_merge( $types, $this->platform_emitted_types );
        }

        $types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );

        return (array) apply_filters( 'yimai_gso_ai_schema_coverage', $types, $post_id );
    }

    /**
     * 首页由主题输出的本地商家 @type（A1）
     *
     * 主题（yimaiyoga）在首页用 @graph 输出两家门店的 HealthAndBeautyBusiness 节点，
     * 但输出时机是 wp_head(1) —— 插件在 wp_head(14) 拿不到别人已经打印的 HTML。
     * 因此这里按「主题真实配置」判定：
     *   · yimai_config() 存在且 studios 至少有一家带名字 → 主题确实会输出门店节点；
     *   · yimai_gso_theme_local_business_types 过滤器：换主题或主题改版时由主题自己声明。
     * 只在 is_front_page() 生效；主题不输出门店节点时返回空数组，不空喊。
     *
     * @return array
     */
    private function get_theme_local_business_types() {
        if ( ! is_front_page() ) {
            return array();
        }

        $types = array();

        if ( function_exists( 'yimai_config' ) ) {
            $config  = yimai_config();
            $studios = ( is_array( $config ) && isset( $config['studios'] ) && is_array( $config['studios'] ) )
                ? $config['studios']
                : array();

            foreach ( $studios as $studio ) {
                if ( is_array( $studio ) && '' !== trim( (string) ( $studio['name'] ?? '' ) ) ) {
                    // 主题节点的字面 @type 是 HealthAndBeautyBusiness；它同时是 LocalBusiness
                    // 的子类，两个都声明，按父类检索的引擎也能匹配。
                    $types = array( 'HealthAndBeautyBusiness', 'LocalBusiness' );
                    break;
                }
            }
        }

        $types = (array) apply_filters( 'yimai_gso_theme_local_business_types', $types );
        $types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );

        return $types;
    }

    /**
     * 登记 adapter 自己输出的 Schema @type（t9 F-2）
     *
     * @param array $schemas
     * @return void
     */
    private function register_platform_emitted_types( $schemas ) {
        foreach ( (array) $schemas as $schema ) {
            $this->collect_platform_schema_types( $schema );
        }
    }

    /**
     * 递归收集单个节点及子节点里的 @type（与 Schema_Generator 同口径）
     *
     * @param mixed $node
     * @return void
     */
    private function collect_platform_schema_types( $node ) {
        if ( ! is_array( $node ) ) {
            return;
        }

        if ( isset( $node['@type'] ) ) {
            foreach ( (array) $node['@type'] as $type ) {
                if ( is_string( $type ) && '' !== $type && ! in_array( $type, $this->platform_emitted_types, true ) ) {
                    $this->platform_emitted_types[] = $type;
                }
            }
        }

        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                $this->collect_platform_schema_types( $value );
            }
        }
    }

    // ==================== Schema 增强 ====================

    /**
     * wp_head(13): 输出平台优化的额外 Schema JSON-LD
     *
     * 在 YIMAI_GSO_Schema_Generator（priority=10）和 YIMAI_GSO_GEO_Entity（priority=11）之后，
     * 追加平台专属的 JSON-LD 块，不修改已有输出。
     */
    public function output_platform_schema() {
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $schemas = array();

        // Claude 平台：添加学术引用块
        if ( in_array( 'claude', $this->active_keys, true ) ) {
            $citation_schema = $this->build_claude_citation_schema( $post_id, $post );
            if ( ! empty( $citation_schema ) ) {
                $schemas[] = $citation_schema;
            }
        }

        // Perplexity 平台：添加引用区块链
        if ( in_array( 'perplexity', $this->active_keys, true ) ) {
            $perplexity_schema = $this->build_perplexity_citation_schema( $post_id, $post );
            if ( ! empty( $perplexity_schema ) ) {
                $schemas[] = $perplexity_schema;
            }
        }

        // Gemini 平台：EEAT 增强块
        if ( in_array( 'gemini', $this->active_keys, true ) ) {
            $eeat_schema = $this->build_gemini_eeat_schema( $post_id, $post );
            if ( ! empty( $eeat_schema ) ) {
                $schemas[] = $eeat_schema;
            }
        }

        if ( empty( $schemas ) ) {
            return;
        }

        // t9 F-2：本 adapter 自己输出的节点同样要进 ai-schema-coverage
        // （meta 已挪到 wp_head 14，晚于这里的 13，先写后读才成立）
        $this->register_platform_emitted_types( $schemas );

        echo "\n<!-- 一麦GSO AI Platform Enhanced Schema (L6) -->\n";
        foreach ( $schemas as $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
        }
        echo "<!-- /一麦GSO AI Platform Enhanced Schema -->\n";
    }

    /**
     * 构建 Claude 平台学术引用 Schema
     */
    private function build_claude_citation_schema( $post_id, $post ) {
        $citations = array();

        // 尝试从 Citation_Helper 获取引用数据
        // ★ 修复（审计 B-5）：原先读 post_meta 键 `yimai_gso_citation_data`，
        //   而 Citation_Helper 实际写入的键是 `yimai_gso_citations`（结构 {count, sources:[...]}），
        //   键名不匹配导致真实引用永远读不到、citations 只剩「文章自身」兜底项。
        if ( class_exists( 'YIMAI_GSO_Citation_Helper' ) ) {
            $citation_data = get_post_meta( $post_id, 'yimai_gso_citations', true );
            if ( ! empty( $citation_data ) && is_array( $citation_data ) ) {
                foreach ( (array) ( $citation_data['sources'] ?? array() ) as $cite ) {
                    if ( ! is_array( $cite ) ) continue;
                    $name = trim( (string) ( $cite['source'] ?? $cite['title'] ?? '' ) );
                    $url  = trim( (string) ( $cite['url'] ?? '' ) );
                    if ( '' === $name && '' === $url ) continue;
                    $item = array( '@type' => 'CreativeWork' );
                    if ( '' !== $name ) $item['name'] = $name;
                    if ( '' !== $url )  $item['url']  = $url;
                    $citations[] = $item;
                }
            }
        }

        // 至少包含文章自身作为引用
        $citations[] = array(
            '@type'      => 'CreativeWork',
            'url'        => get_permalink( $post_id ),
            'name'       => get_the_title( $post_id ),
            'datePublished' => get_the_date( 'c', $post_id ),
            'author'     => array(
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $post->post_author ),
            ),
        );

        // 修复：博客文章并非学术文献，输出 ScholarlyArticle 会被富媒体检测判为错误类型；
        // 且本块与 Schema_Generator 的主 Article 重复描述同一页面，造成同页 4 个 Article 族节点。
        // 收敛为单个 WebPage 节点：仅承载引用列表，并以 mainEntity 回指主 Article 节点。
        return array(
            '@context'   => 'https://schema.org',
            '@type'      => 'WebPage',
            '@id'        => get_permalink( $post_id ) . '#citations',
            'mainEntity' => array( '@id' => get_permalink( $post_id ) . '#article' ),
            'citation'   => $citations,
        );
    }

    /**
     * 构建 Perplexity 平台引用区块链
     */
    private function build_perplexity_citation_schema( $post_id, $post ) {
        $references = array();
        $adapt_data = $this->get_post_adapt_data( $post_id );

        if ( ! empty( $adapt_data['sources'] ) && is_array( $adapt_data['sources'] ) ) {
            foreach ( $adapt_data['sources'] as $source ) {
                if ( ! empty( $source['url'] ) ) {
                    $references[] = array(
                        '@type' => 'WebContent',
                        'url'   => $source['url'],
                        'name'  => isset( $source['name'] ) ? $source['name'] : '',
                    );
                }
            }
        }

        if ( empty( $references ) ) {
            return array();
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'WebContent',
            '@id'         => get_permalink( $post_id ) . '#references',
            'about'       => get_the_title( $post_id ),
            'citation'    => $references,
        );
    }

    /**
     * 构建 Gemini 平台 EEAT 增强 Schema
     */
    private function build_gemini_eeat_schema( $post_id, $post ) {
        // 检查作者档案是否存在
        $author_id = $post->post_author;
        $eeat_data = array();

        if ( class_exists( 'YIMAI_GSO_Author_Profile' ) ) {
            $eeat_data['author_profile'] = true;
        }

        // 读取实体数据
        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) ) {
            $entity = YIMAI_GSO_GEO_Entity::get_entity_data();
            if ( ! empty( $entity['same_as'] ) ) {
                $eeat_data['entity_verified'] = true;
                $eeat_data['same_as'] = array_values( $entity['same_as'] );
            }
        }

        // 修复：与主 Article（Schema_Generator）重复描述同一页面，且 same_as 未配置时
        // publisher 为 null 会输出非法节点。收敛为 WebPage 节点承载 EEAT 信号，
        // author 回指作者存档页的 Person 节点，不再重复声明 Article。
        $schema = array(
            '@context'          => 'https://schema.org',
            '@type'             => 'WebPage',
            '@id'               => get_permalink( $post_id ) . '#eeat',
            'about'             => array( '@id' => get_permalink( $post_id ) . '#article' ),
            'author'            => array(
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $author_id ),
                'url'   => get_author_posts_url( $author_id ),
            ),
            'datePublished'     => get_the_date( 'c', $post_id ),
            'dateModified'      => get_the_modified_date( 'c', $post_id ),
        );
        if ( ! empty( $eeat_data['same_as'] ) ) {
            $schema['publisher'] = array(
                '@type'  => 'Organization',
                'name'   => get_bloginfo( 'name' ),
                'sameAs' => $eeat_data['same_as'],
            );
        }
        return $schema;
    }

    // ==================== llms.txt 增强 ====================

    /**
     * 启动 llms.txt output buffering 以在末尾注入平台区块
     *
     * 在 init(1) 阶段通过 REQUEST_URI 检测 llms.txt 请求（早于 query_vars 解析），
     * 启动 ob_start 回调，在 LLMs Generator 输出后自动追加平台覆盖区块。
     */
    public function maybe_start_llms_buffer() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        // 匹配 /llms.txt 结尾（排除 /llms-full.txt）
        if ( '/llms.txt' === substr( $uri, -9 ) && false === strpos( $uri, 'llms-full' ) ) {
            ob_start( array( $this, 'filter_llms_output' ) );
        }
    }

    /**
     * ob_start 回调：在 llms.txt 末尾追加平台覆盖区块
     *
     * @param string $content 原始 llms.txt 输出
     * @return string 增强后的 llms.txt
     */
    public function filter_llms_output( $content ) {
        $platform_section = $this->generate_llms_platform_section();
        if ( empty( $platform_section ) ) {
            return $content;
        }
        return $content . "\n" . $platform_section;
    }

    /**
     * 生成平台覆盖区块（Markdown 格式）
     *
     * @return string
     */
    private function generate_llms_platform_section() {
        $lines = array();
        $has_llms_platforms = false;

        foreach ( $this->active_keys as $key ) {
            $platform = $this->platforms[ $key ];
            if ( empty( $platform['adaptations']['llms_txt'] ) ) {
                continue;
            }
            $has_llms_platforms = true;
            // t8：不再写「X Schema optimized」——平台偏好（schema_priority）不等于本页真的
            //   输出了那些 @type，写死在 llms.txt 里会被 AI 引擎当事实核验后判页面失实。
            $lines[] = '- **' . esc_html( $platform['name'] ) . '**: ' .
                       esc_html( $platform['adaptations']['content_format'] ) . ' 内容格式；' .
                       esc_html( $platform['note'] );
        }

        if ( ! $has_llms_platforms ) {
            return '';
        }

        return "## AI Platform Coverage\n\n" . implode( "\n", $lines ) . "\n";
    }

    // ==================== 文章保存钩子 ====================

    /**
     * 文章保存后预生成平台适配数据并缓存到 post_meta
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update
     */
    public function on_post_save( $post_id, $post, $update ) {
        // 跳过自动保存和修订
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        // 仅处理已发布文章
        if ( 'publish' !== $post->post_status ) {
            return;
        }
        // 检查内容类型支持
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) && ! YIMAI_GSO_Post_Type_Helper::is_supported( $post->post_type ) ) {
            return;
        }

        $adapt_data = $this->compute_adapt_data( $post_id, $post );
        if ( ! empty( $adapt_data ) ) {
            update_post_meta( $post_id, self::META_KEY, wp_json_encode( $adapt_data, JSON_UNESCAPED_UNICODE ) );
        }
    }

    /**
     * 计算文章的适配数据
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @return array
     */
    private function compute_adapt_data( $post_id, $post ) {
        $data = array(
            'computed_at'    => current_time( 'mysql' ),
            'active_platforms' => $this->active_keys,
            'schema_priority'  => array(),
            'content_formats'  => array(),
            'citation_count'   => 0,
            'sources'          => array(),
        );

        // 收集各平台 Schema 优先级
        foreach ( $this->active_keys as $key ) {
            $platform = $this->platforms[ $key ];
            foreach ( $platform['adaptations']['schema_priority'] as $schema_type ) {
                if ( ! in_array( $schema_type, $data['schema_priority'], true ) ) {
                    $data['schema_priority'][] = $schema_type;
                }
            }
            $format = $platform['adaptations']['content_format'];
            if ( ! in_array( $format, $data['content_formats'], true ) ) {
                $data['content_formats'][] = $format;
            }
        }

        // 尝试从 Citation_Helper 获取引用数据
        // ★ 修复（审计 B-5）：键名对齐 `yimai_gso_citations`（结构 {count, sources:[...]}）
        if ( class_exists( 'YIMAI_GSO_Citation_Helper' ) ) {
            $citation_data = get_post_meta( $post_id, 'yimai_gso_citations', true );
            if ( ! empty( $citation_data ) && is_array( $citation_data ) ) {
                $data['citation_count'] = absint( $citation_data['count'] ?? 0 );
                foreach ( (array) ( $citation_data['sources'] ?? array() ) as $cite ) {
                    if ( ! is_array( $cite ) ) continue;
                    $name = trim( (string) ( $cite['source'] ?? $cite['title'] ?? '' ) );
                    $url  = trim( (string) ( $cite['url'] ?? '' ) );
                    if ( '' === $name && '' === $url ) continue;
                    $data['sources'][] = array(
                        'url'  => $url,
                        'name' => $name,
                    );
                }
            }
        }

        return $data;
    }

    /**
     * 获取文章的适配数据（带内存缓存）
     *
     * @param int $post_id
     * @return array
     */
    private function get_post_adapt_data( $post_id ) {
        static $cache = array();

        if ( isset( $cache[ $post_id ] ) ) {
            return $cache[ $post_id ];
        }

        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $raw ) ) {
            $cache[ $post_id ] = array();
            return array();
        }

        $decoded = json_decode( $raw, true );
        $cache[ $post_id ] = is_array( $decoded ) ? $decoded : array();
        return $cache[ $post_id ];
    }

    // ==================== AJAX 端点 ====================

    /**
     * AJAX: 保存平台配置
     */
    public function ajax_save_config() {
        // 权限检查
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'yimai_gso_nonce' ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
$config_json = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';
        $config = json_decode( $config_json, true );

        if ( ! is_array( $config ) ) {
            wp_send_json_error( array( 'message' => '配置数据格式错误' ) );
        }

        // 验证并清理配置
        $all_keys = array_keys( YIMAI_GSO_AI_Platform_Registry::get_all() );
        $clean = array();
        foreach ( $config as $key => $platform_config ) {
            if ( ! in_array( $key, $all_keys, true ) ) {
                continue;
            }
            $clean[ $key ] = array(
                'enabled' => ! empty( $platform_config['enabled'] ),
            );
        }

        $this->save_config( $clean );

        wp_send_json_success( array(
            'message'       => '平台配置已保存',
            'active_keys'   => $this->active_keys,
            'active_count'  => count( $this->active_keys ),
        ) );
    }

    /**
     * AJAX: 获取平台预览（显示各平台对当前文章的适配效果）
     */
    public function ajax_preview() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'yimai_gso_nonce', '_wpnonce' );
$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '缺少文章 ID' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        $preview = array();
        foreach ( $this->active_keys as $key ) {
            $platform = $this->platforms[ $key ];
            $preview[ $key ] = array(
                'name'             => $platform['name'],
                'schema_priority'  => $platform['adaptations']['schema_priority'],
                'content_format'   => $platform['adaptations']['content_format'],
                'meta_tags'        => ! empty( $platform['adaptations']['meta_tags'] ),
                'llms_txt'         => ! empty( $platform['adaptations']['llms_txt'] ),
            );
        }

        wp_send_json_success( array(
            'platforms' => $preview,
            'post_id'   => $post_id,
            'post_title'=> get_the_title( $post_id ),
        ) );
    }

    /**
     * AJAX: 批量生成文章适配数据
     */
    public function ajax_batch_adapt() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'yimai_gso_nonce', '_wpnonce' );
$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $limit  = isset( $_POST['limit'] ) ? min( intval( $_POST['limit'] ), 50 ) : 20;

        $post_types = class_exists( 'YIMAI_GSO_Post_Type_Helper' )
            ? YIMAI_GSO_Post_Type_Helper::get_supported()
            : array( 'post', 'page' );

        $posts = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );

        $processed = 0;
        foreach ( $posts as $post ) {
            $adapt_data = $this->compute_adapt_data( $post->ID, $post );
            if ( ! empty( $adapt_data ) ) {
                update_post_meta( $post->ID, self::META_KEY, wp_json_encode( $adapt_data, JSON_UNESCAPED_UNICODE ) );
                $processed++;
            }
        }

        $total_posts = 0;
        foreach ( $post_types as $pt ) {
            $counts = wp_count_posts( $pt );
            $total_posts += isset( $counts->publish ) ? $counts->publish : 0;
        }

        wp_send_json_success( array(
            'processed' => $processed,
            'offset'    => $offset + $limit,
            'total'     => $total_posts,
            'finished'  => ( $offset + $limit ) >= $total_posts,
        ) );
    }

    /**
     * AJAX: 获取当前平台配置
     */
    public function ajax_get_config() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'yimai_gso_nonce', '_wpnonce' );
$platforms_data = array();
        foreach ( $this->platforms as $key => $config ) {
            $platforms_data[ $key ] = array(
                'name'     => $config['name'],
                'priority' => $config['priority'],
                'enabled'  => $config['enabled'],
                'bots'     => $config['bots'],
                'note'     => $config['note'],
                'adaptations' => $config['adaptations'],
            );
        }

        wp_send_json_success( array(
            'platforms'    => $platforms_data,
            'active_keys'  => $this->active_keys,
            'active_count' => count( $this->active_keys ),
            'total_count'  => count( $this->platforms ),
        ) );
    }
}
