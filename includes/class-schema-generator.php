<?php
/**
 * JSON-LD 结构化数据生成器
 * 根据文章内容自动生成 Schema.org JSON-LD 并注入 <head>
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Schema_Generator {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_head', array( $this, 'output_json_ld' ) );
    }

    /**
     * 支持的 Schema 类型及标签
     */
    public static function get_schema_types() {
        return array(
            'Article'             => 'Article (文章)',
            'WebPage'             => 'WebPage (页面)',
            'FAQPage'             => 'FAQPage (常见问答)',
            'HowTo'               => 'HowTo (教程步骤)',
            'Product'             => 'Product (产品页面)',
            'BreadcrumbList'      => 'BreadcrumbList (面包屑)',
            'Person'              => 'Person (个人/作者)',
            'Event'               => 'Event (活动/事件)',
            'Review'              => 'Review (测评/评价)',
            'Recipe'              => 'Recipe (食谱)',
            'VideoObject'         => 'VideoObject (视频)',
            'LocalBusiness'       => 'LocalBusiness (本地商家)',
            'Course'              => 'Course (课程)',
            'JobPosting'          => 'JobPosting (招聘)',
            'SoftwareApplication' => 'SoftwareApplication (软件/应用)',
        );
    }

    /**
     * 获取哪些 Schema 类型已启用（默认全部开启）
     */
    private function get_enabled_types() {
        $defaults = array_keys( self::get_schema_types() );
        $saved    = YIMAI_GSO_Settings::get( 'schema_enabled_types', array() );

        if ( empty( $saved ) || ! is_array( $saved ) ) {
            return $defaults;
        }
        return $saved;
    }

    /**
     * wp_head 钩子 - 输出 JSON-LD
     */
    /** @var string|null 缓存文章内容，避免多次 get_post_field */
    private $cached_post_content = null;

    /** @var int|null 缓存的文章ID */
    private $cached_post_id = null;

    /**
     * 获取文章内容（带缓存，避免同一请求内多次读取数据库） 
     */
    private function get_post_content_cached( $post_id ) {
        if ( $this->cached_post_id !== $post_id || null === $this->cached_post_content ) {
            $this->cached_post_id   = $post_id;
            $this->cached_post_content = get_post_field( 'post_content', $post_id );
        }
        return $this->cached_post_content;
    }

    public function output_json_ld() {
        // 预览请求跳过（防止内存溢出）
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        // Schema 冲突检测：如果已安装 Yoast/Rank Math/AIOSEO 等，跳过自身输出
        $conflicts = self::detect_conflict_plugins();
        if ( ! empty( $conflicts ) ) {
            return;
        }

        /* ─────── 首页 Schema ─────── */
        if ( is_front_page() || is_home() ) {
            $this->output_front_page_schemas();
            return;
        }

        // 仅对插件支持的内容类型输出 Schema（向后兼容：默认 post + page）
        if ( ! is_singular() ) {
            return;
        }

        $current_post_type = get_post_type();
        if ( ! YIMAI_GSO_Post_Type_Helper::is_supported( $current_post_type ) ) {
            return;
        }

        $post_id = get_the_ID();

        // ★ 只对已发布（publish）的文章生成 Schema，草稿/待审等跳过
        $post_status = get_post_status( $post_id );
        if ( 'publish' !== $post_status ) {
            return;
        }

        // ★ 内存保护
        if ( function_exists( 'memory_get_usage' ) ) {
            $mem_limit = wp_convert_hr_to_bytes( @ini_get( 'memory_limit' ) );
            $mem_used  = memory_get_usage( true );
            $mem_left  = $mem_limit - $mem_used;
            if ( $mem_limit > 0 && $mem_left < 80 * 1024 * 1024 ) {
                return;
            }
        }

        $enabled = $this->get_enabled_types();
        $schemas = array();

        // BreadcrumbList 始终输出（如果启用）
        if ( in_array( 'BreadcrumbList', $enabled, true ) ) {
            $breadcrumb = $this->build_breadcrumb( $post_id );
            if ( $breadcrumb ) {
                $schemas[] = $breadcrumb;
            }
        }

        // 获取文章存储的 schema_type（AI 生成时自动判断）
        $schema_type = get_post_meta( $post_id, 'yimai_gso_schema_type', true );
        if ( empty( $schema_type ) ) {
            $schema_type = $this->auto_detect_type( $post_id );
            update_post_meta( $post_id, 'yimai_gso_schema_type', $schema_type );
        }

        // 根据检测类型生成对应 Schema
        if ( 'Product' === $schema_type && in_array( 'Product', $enabled, true ) ) {
            $product = $this->build_product( $post_id );
            if ( $product ) {
                $schemas[] = $product;
            }
        } elseif ( 'FAQPage' === $schema_type && in_array( 'FAQPage', $enabled, true ) ) {
            $faq = $this->build_faq( $post_id );
            if ( $faq ) {
                $schemas[] = $faq;
            }
        } elseif ( 'HowTo' === $schema_type && in_array( 'HowTo', $enabled, true ) ) {
            $howto = $this->build_howto( $post_id );
            if ( $howto ) {
                $schemas[] = $howto;
            }
        } elseif ( 'Event' === $schema_type && in_array( 'Event', $enabled, true ) ) {
            $event = $this->build_event( $post_id );
            if ( $event ) $schemas[] = $event;
        } elseif ( 'Review' === $schema_type && in_array( 'Review', $enabled, true ) ) {
            $review = $this->build_review( $post_id );
            if ( $review ) $schemas[] = $review;
        } elseif ( 'Recipe' === $schema_type && in_array( 'Recipe', $enabled, true ) ) {
            $recipe = $this->build_recipe( $post_id );
            if ( $recipe ) $schemas[] = $recipe;
        } elseif ( 'VideoObject' === $schema_type && in_array( 'VideoObject', $enabled, true ) ) {
            $video = $this->build_video( $post_id );
            if ( $video ) $schemas[] = $video;
        } elseif ( 'LocalBusiness' === $schema_type && in_array( 'LocalBusiness', $enabled, true ) ) {
            $lb = $this->build_local_business( $post_id );
            if ( $lb ) $schemas[] = $lb;
        } elseif ( 'Course' === $schema_type && in_array( 'Course', $enabled, true ) ) {
            $course = $this->build_course( $post_id );
            if ( $course ) $schemas[] = $course;
        } elseif ( 'JobPosting' === $schema_type && in_array( 'JobPosting', $enabled, true ) ) {
            $job = $this->build_job( $post_id );
            if ( $job ) $schemas[] = $job;
        } elseif ( 'SoftwareApplication' === $schema_type && in_array( 'SoftwareApplication', $enabled, true ) ) {
            $sw = $this->build_software( $post_id );
            if ( $sw ) $schemas[] = $sw;
        } elseif ( 'Person' === $schema_type && in_array( 'Person', $enabled, true ) ) {
            $person = $this->build_person( $post_id );
            if ( $person ) $schemas[] = $person;
        }

        // Article Schema - 所有文章都生成（如果启用）
        if ( in_array( 'Article', $enabled, true ) ) {
            $article = $this->build_article( $post_id );
            if ( $article ) {
                $schemas[] = $article;
            }
        }

        // Citation Schema — 检测到引用时追加（第3层新增）
        // 修复：原先 echo 一个独立的 `@type: Article` + 仅 citation 的节点，
        // 与上方主 Article 同页重复，富媒体检测会报「重复 Article」。
        // 改为把 citation 并入主 Article 节点（标准属性），不再单独输出。
        if ( class_exists( 'YIMAI_GSO_Citation_Helper' ) ) {
            $citation_helper = YIMAI_GSO_Citation_Helper::get_instance();
            $citation_data   = $citation_helper->get_citations( $post_id );
            if ( ! empty( $citation_data['count'] ) && $citation_data['count'] > 0 ) {
                $cite_items = array();
                $cite_seen  = array(); // ★ 修复（审计 B-6）：按 name+url 去重，防止同页 citation 重复输出
                // get_citations() 返回 { count, sources:[{source,url,...}], ... }
                foreach ( (array) ( $citation_data['sources'] ?? array() ) as $cite ) {
                    if ( ! is_array( $cite ) ) continue;
                    $name = trim( (string) ( $cite['source'] ?? $cite['name'] ?? '' ) );
                    $url  = trim( (string) ( $cite['url'] ?? '' ) );
                    if ( '' === $name && '' === $url ) continue;
                    // ★ 修复（审计 B-6）：复用 Citation_Helper 的碎片文本过滤（纯数字/量词/标点开头等），
                    //   防止正文小标题、碎片短语被当作引用来源混进 Article.citation（线上实证：
                    //   「学员当天的状态给针对性指导」曾被重复输出两次）。
                    if ( '' !== $name && ! YIMAI_GSO_Citation_Helper::is_valid_citation_name( $name ) ) continue;
                    $dedupe_key = md5( strtolower( $name . '|' . $url ) );
                    if ( isset( $cite_seen[ $dedupe_key ] ) ) continue;
                    $cite_seen[ $dedupe_key ] = true;
                    $item = array( '@type' => 'CreativeWork' );
                    if ( '' !== $name ) $item['name'] = $name;
                    if ( '' !== $url )  $item['url']  = $url;
                    $cite_items[] = $item;
                }
                if ( ! empty( $cite_items ) ) {
                    foreach ( $schemas as &$s ) {
                        if ( isset( $s['@type'] ) && 'Article' === $s['@type'] && empty( $s['citation'] ) ) {
                            $s['citation'] = $cite_items;
                        }
                    }
                    unset( $s );
                }
            }
        }

        // 输出所有 Schema
        if ( empty( $schemas ) ) {
            return;
        }

        foreach ( $schemas as $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . '</script>' . "\n";
        }
    }

    /**
     * 自动检测文章类型
     */
    private function auto_detect_type( $post_id ) {
        // ★ 只取前 5000 字符做类型检测，避免全量加载长文导致内存压力
        // blocks.php + redis-cache 已经占用大量内存，这里尽量轻量
        $content = $this->get_post_content_cached( $post_id );
        $sample  = mb_substr( $content, 0, 5000 );

        // 检测 Product 模式：正文中包含产品/价格/购买特征关键词
        if ( preg_match_all( '/(?:价格|售价|原价|现价|¥|￥|购买|下单|加入购物车|产品规格|产品参数|购买链接|立即购买|产品详情|商品详情)/iu', $sample ) >= 3 ) {
            return 'Product';
        }

        // 检测 FAQ 模式：正文中有明确的问答结构
        if ( preg_match_all( '/<h[23][^>]*>(?:[^<]*(?:问|答|Q\b|A\b|FAQ|常见|疑问|解答)[^<]*)<\/h[23]>|<p[^>]*>(?:[^<]*\?(?:\s*<[^>]+>)*\s*<\/p>)/iu', $sample, $m ) >= 2 ) {
            return 'FAQPage';
        }

        // 检测 HowTo 模式：有 ≥2 个步骤型 H2/H3 标题（不再靠 ul/ol 判断，避免误判）
        if ( preg_match_all( '/<h[23][^>]*>(?:[^<]*(?:第[一二三四五六七八九十\d]+步|步骤\d*|Step\s*\d+|方法\d*|操作\d*)[^<]*)<\/h[23]>/isu', $sample, $m ) >= 2 ) {
            return 'HowTo';
        }

        // 检测 JobPosting：招聘关键词 ≥3（全职/兼职/实习 在普通文案里常见，如师资页的"全职导师"，不参与检测避免误判）
        if ( preg_match_all( '/(?:招聘|招人|职位|JOB|Hiring|岗位|薪资|待遇)/iu', $sample ) >= 3 ) {
            return 'JobPosting';
        }

        // 检测 Recipe：食材/烹饪关键词 ≥3
        if ( preg_match_all( '/(?:食材|食谱|配方|烘焙|烹饪|做法|食材清单|cook|recipe|调料|步骤\s*\d)/iu', $sample ) >= 3 ) {
            return 'Recipe';
        }

        // 检测 Review：评分/评价关键词 ≥3
        if ( preg_match_all( '/(?:评分|评价|评测|review|打分|rating|满分|推荐指数)/iu', $sample ) >= 3 ) {
            return 'Review';
        }

        // 检测 Event：活动/事件关键词 ≥3
        if ( preg_match_all( '/(?:活动|开始时间|结束时间|举办|门票|报名|event|startDate|venue)/iu', $sample ) >= 3 ) {
            return 'Event';
        }

        // 检测 Course：课程/培训关键词 ≥3
        if ( preg_match_all( '/(?:课程|培训|学习|课时|大纲|course|lesson|syllabus|报名费)/iu', $sample ) >= 3 ) {
            return 'Course';
        }

        return 'Article';
    }

    /**
     * 构建 Article Schema
     */
    private function build_article( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }

        // ★ Page 类型使用 WebPage Schema，而非 Article
        if ( 'page' === $post->post_type ) {
            return $this->build_webpage( $post_id );
        }

        $author_id   = $post->post_author;
        $author_name = get_the_author_meta( 'display_name', $author_id );
        $categories  = get_the_category( $post_id );
        $cat_names   = array();
        if ( $categories ) {
            foreach ( $categories as $cat ) {
                $cat_names[] = $cat->name;
            }
        }

        // 获取关键词作为 keywords
        $kw_meta = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        $keywords = '';
        if ( ! empty( $kw_meta ) ) {
            $keywords = is_array( $kw_meta ) ? implode( ', ', $kw_meta ) : $kw_meta;
        }

        // 获取摘要
        $excerpt = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $excerpt ) ) {
            // 不用 get_the_excerpt()，避免触发 wp_trim_excerpt() 的 preg_match_all 灾难性回溯
            $excerpt = $post->post_excerpt;
        }
        // 如果还是没有摘要（无手动摘要时），从 post_content 安全截取
        if ( empty( $excerpt ) ) {
            $text = wp_strip_all_tags( $post->post_content );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );
            $excerpt = mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 197 ) . '...' : $text;
        }
        $excerpt = wp_strip_all_tags( $excerpt );
        if ( mb_strlen( $excerpt ) > 200 ) {
            $excerpt = mb_substr( $excerpt, 0, 197 ) . '...';
        }

        // 特色图片
        $image_url = '';
        if ( has_post_thumbnail( $post_id ) ) {
            $image_url = get_the_post_thumbnail_url( $post_id, 'full' );
        }

        $schema = array(
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => get_the_title( $post_id ),
            'description'      => $excerpt,
            'datePublished'    => get_the_date( 'c', $post_id ),
            'dateModified'     => get_the_modified_date( 'c', $post_id ),
            'author'           => array(
                '@type' => 'Person',
                'name'  => $author_name ?: get_bloginfo( 'name' ),
            ),
            'publisher'        => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'logo'  => array(
                    '@type' => 'ImageObject',
                    'url'   => get_site_icon_url(),
                ),
            ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id'   => get_permalink( $post_id ),
            ),
        );

        if ( ! empty( $cat_names ) ) {
            $schema['articleSection'] = implode( ', ', $cat_names );
        }

        if ( ! empty( $keywords ) ) {
            $schema['keywords'] = $keywords;
        }

        if ( ! empty( $image_url ) ) {
            $schema['image'] = $image_url;
        }

        return $schema;
    }

    /**
     * 构建 WebPage Schema（用于 Page 等非文章类型页面）
     * 不含 author/articleSection 等文章专属字段
     */
    private function build_webpage( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }

        // 获取摘要
        $excerpt = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $excerpt ) ) {
            $excerpt = $post->post_excerpt;
        }
        if ( empty( $excerpt ) ) {
            $text = wp_strip_all_tags( $post->post_content );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );
            $excerpt = mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 197 ) . '...' : $text;
        }
        $excerpt = wp_strip_all_tags( $excerpt );
        if ( mb_strlen( $excerpt ) > 200 ) {
            $excerpt = mb_substr( $excerpt, 0, 197 ) . '...';
        }

        // 特色图片
        $image_url = '';
        if ( has_post_thumbnail( $post_id ) ) {
            $image_url = get_the_post_thumbnail_url( $post_id, 'full' );
        }

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'WebPage',
            'name'        => get_the_title( $post_id ),
            'description' => $excerpt,
            'url'         => get_permalink( $post_id ),
            'dateModified'=> get_the_modified_date( 'c', $post_id ),
            'publisher'   => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'logo'  => array(
                    '@type' => 'ImageObject',
                    'url'   => get_site_icon_url(),
                ),
            ),
        );

        if ( ! empty( $image_url ) ) {
            $schema['primaryImageOfPage'] = array(
                '@type' => 'ImageObject',
                'url'   => $image_url,
            );
        }

        return $schema;
    }

    /**
     * 构建 FAQPage Schema
     */
    private function build_faq( $post_id ) {
        // ★ 使用缓存的内容，避免重复 get_post_field
        $content = $this->get_post_content_cached( $post_id );
        $post    = get_post( $post_id );

        // 提取问答对
        $questions = array();

        // 模式1：H2/H3 包含问号 -> 下一个 H2/H3 之前的所有内容作为答案
        if ( preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>(.*?)(?=<h[23]|$)/isu', $content, $blocks, PREG_SET_ORDER ) ) {
            foreach ( $blocks as $block ) {
                $q_text = wp_strip_all_tags( $block[2] );
                // 标题中包含问号或问答关键词
                if ( mb_strpos( $q_text, '?' ) !== false || mb_strpos( $q_text, '？' ) !== false ||
                     preg_match( '/(?:问|答|Q\b|A\b|FAQ|常见|疑问|解答|什么|如何|怎么|为什么|哪些|多少)/iu', $q_text ) ) {
                    $a_html = wp_kses( $block[3], array(
                        'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(),
                        'ul' => array(), 'ol' => array(), 'li' => array(), 'a' => array( 'href' => array() ),
                    ) );
                    $a_text = wp_strip_all_tags( $a_html );
                    $a_text = trim( preg_replace( '/\s+/', ' ', $a_text ) );

                    if ( ! empty( $q_text ) && ! empty( $a_text ) ) {
                        $questions[] = array(
                            '@type' => 'Question',
                            'name'  => $q_text,
                            'acceptedAnswer' => array(
                                '@type' => 'Answer',
                                'text'  => $a_text,
                            ),
                        );
                    }
                }
            }
        }

        // 如果 HTML 模式未检测到，回退到纯文本模式（使用 FAQ_Detector）
        if ( empty( $questions ) && class_exists( 'YIMAI_GSO_FAQ_Detector' ) ) {
            $detector = YIMAI_GSO_FAQ_Detector::get_instance();
            $qa_pairs = $detector->detect_faq( $content, 'plain' );

            foreach ( $qa_pairs as $pair ) {
                $questions[] = array(
                    '@type' => 'Question',
                    'name'  => $pair['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => $pair['answer'],
                    ),
                );
            }
        }

        if ( empty( $questions ) ) {
            return null;
        }

        return array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_slice( $questions, 0, 10 ), // 最多 10 组
        );
    }

    /**
     * 构建 HowTo Schema
     */
    private function build_howto( $post_id ) {
        // ★ 使用缓存的内容，避免重复 get_post_field
        $content = $this->get_post_content_cached( $post_id );
        $post    = get_post( $post_id );

        $steps = array();

        // 提取步骤：H2/H3 包含步骤关键词 -> 下一个 H2/H3 之前的段落
        if ( preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>(.*?)(?=<h[23]|$)/isu', $content, $blocks, PREG_SET_ORDER ) ) {
            foreach ( $blocks as $i => $block ) {
                $heading = wp_strip_all_tags( $block[2] );
                if ( preg_match( '/(?:第[一二三四五六七八九十\d]+步|步骤\d*|Step\s*\d+|方法\d*|操作\d*|环节\d*)/iu', $heading ) ||
                     preg_match( '/^(?:\d+[\.\、\]\)]|[\(（]\d+[\)）])/u', trim( $heading ) ) ) {
                    $step_text = wp_strip_all_tags( $block[3] );
                    $step_text = trim( preg_replace( '/\s+/', ' ', $step_text ) );

                    if ( ! empty( $step_text ) ) {
                        $steps[] = array(
                            '@type'           => 'HowToStep',
                            'position'        => count( $steps ) + 1,
                            'name'            => $heading,
                            'text'            => mb_substr( $step_text, 0, 500 ),
                        );
                    }
                }
            }
        }

        // 备选：如果没匹配到步骤标题，尝试从有序列表提取
        if ( empty( $steps ) && preg_match_all( '/<li[^>]*>(.*?)<\/li>/isu', $content, $lis, PREG_SET_ORDER ) ) {
            foreach ( $lis as $i => $li ) {
                $text = wp_strip_all_tags( $li[1] );
                $text = trim( preg_replace( '/\s+/', ' ', $text ) );
                if ( mb_strlen( $text ) > 5 ) {
                    $steps[] = array(
                        '@type'    => 'HowToStep',
                        'position' => $i + 1,
                        'text'     => mb_substr( $text, 0, 500 ),
                    );
                }
            }
        }

        if ( count( $steps ) < 2 ) {
            return null;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => get_the_title( $post_id ),
            'step'     => array_slice( $steps, 0, 20 ),
        );

        $excerpt = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $excerpt ) ) {
            // 不用 get_the_excerpt()，避免触发 wp_trim_excerpt() 的 preg_match_all 灾难性回溯
            $post = get_post( $post_id );
            $excerpt = $post ? $post->post_excerpt : '';
        }
        // 如果还是没有摘要（无手动摘要时），从 post_content 安全截取
        if ( empty( $excerpt ) && $post ) {
            $text = wp_strip_all_tags( $post->post_content );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );
            $excerpt = mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 197 ) . '...' : $text;
        }
        $excerpt = wp_strip_all_tags( $excerpt );
        if ( ! empty( $excerpt ) ) {
            $schema['description'] = mb_substr( $excerpt, 0, 200 );
        }

        return $schema;
    }

    /**
     * 构建 Product Schema
     * 符合 Google 产品富摘要规范，同时兼容百度搜索结果展现
     */
    private function build_product( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }

        $title   = get_the_title( $post_id );
        $excerpt = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $excerpt ) ) {
            $excerpt = get_the_excerpt( $post_id );
        }
        if ( empty( $excerpt ) ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
        }

        // 尝试从内容中提取价格
        $price = '';
        $currency = 'CNY';
        if ( preg_match( '/(?:价格|售价|原价|现价)[:：\s]*[¥￥]?\s*(\d+(?:\.\d{1,2})?)/u', $post->post_content, $m ) ) {
            $price = $m[1];
        } elseif ( preg_match( '/[¥￥]\s*(\d+(?:\.\d{1,2})?)/u', $post->post_content, $m ) ) {
            $price = $m[1];
        }

        // 获取商品图片
        $image_url = '';
        if ( has_post_thumbnail( $post_id ) ) {
            $image_url = get_the_post_thumbnail_url( $post_id, 'full' );
        }

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => wp_strip_all_tags( $title ),
            'description' => mb_substr( wp_strip_all_tags( $excerpt ), 0, 300 ),
            'url'         => get_permalink( $post_id ),
        );

        if ( ! empty( $image_url ) ) {
            $schema['image'] = $image_url;
        }

        // 价格信息
        if ( $price !== '' ) {
            $schema['offers'] = array(
                '@type'         => 'Offer',
                'price'         => $price,
                'priceCurrency' => $currency,
                'availability'  => 'https://schema.org/InStock',
                'url'           => get_permalink( $post_id ),
            );
        }

        // 品牌/组织信息
        $schema['brand'] = array(
            '@type' => 'Organization',
            'name'  => get_bloginfo( 'name' ),
        );

        return $schema;
    }

    /**
     * 构建 Person Schema（用于作者存档页或 About 页面）
     */
    private function build_person( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $author_id = $post->post_author;
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => get_the_author_meta( 'display_name', $author_id ),
            'url'      => get_author_posts_url( $author_id ),
        );
        $title = get_user_meta( $author_id, 'yimai_gso_author_title', true );
        if ( ! empty( $title ) ) $schema['jobTitle'] = $title;
        $sameas = get_user_meta( $author_id, 'yimai_gso_author_sameas', true );
        if ( ! empty( $sameas ) ) $schema['sameAs'] = array_filter( array_map( 'trim', explode( "\n", $sameas ) ) );
        return $schema;
    }

    /**
     * 构建 Event Schema
     */
    private function build_event( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $content = $this->get_post_content_cached( $post_id );
        $schema = array(
            '@context'     => 'https://schema.org',
            '@type'        => 'Event',
            'name'         => get_the_title( $post_id ),
            'description'  => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 300 ),
            'url'          => get_permalink( $post_id ),
        );
        if ( preg_match( '/(?:开始时间|startDate)[:：\s]*([\d\-:\sT\+]+)/iu', $content, $m ) )
            $schema['startDate'] = trim( $m[1] );
        if ( preg_match( '/(?:结束时间|endDate)[:：\s]*([\d\-:\sT\+]+)/iu', $content, $m ) )
            $schema['endDate'] = trim( $m[1] );
        if ( preg_match( '/(?:地点|位置|venue|location)[:：\s]*([^\n<]+)/iu', $content, $m ) )
            $schema['location'] = array( '@type' => 'Place', 'name' => trim( $m[1] ) );
        return $schema;
    }

    /**
     * 构建 Review Schema
     */
    private function build_review( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $content = $this->get_post_content_cached( $post_id );
        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Review',
            'name'        => get_the_title( $post_id ),
            'reviewBody'  => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 500 ),
            'url'         => get_permalink( $post_id ),
            'datePublished' => get_the_date( 'c', $post_id ),
        );
        $schema['itemReviewed'] = array( '@type' => 'Product', 'name' => get_the_title( $post_id ) );
        if ( preg_match( '/(?:评分|rating|分数)[:：\s]*(\d+(?:\.\d)?)/iu', $content, $m ) ) {
            $score = min( 5, max( 1, floatval( $m[1] ) ) );
            $schema['reviewRating'] = array( '@type' => 'Rating', 'ratingValue' => $score, 'bestRating' => '5' );
        }
        return $schema;
    }

    /**
     * 构建 Recipe Schema
     */
    private function build_recipe( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $content = $this->get_post_content_cached( $post_id );
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Recipe',
            'name'     => get_the_title( $post_id ),
            'description' => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 300 ),
            'url'      => get_permalink( $post_id ),
        );
        if ( preg_match( '/(?:烹饪时间|cookTime|准备时间|prepTime)[:：\s]*(\d+)\s*(?:分钟|min)/iu', $content, $m ) )
            $schema['cookTime'] = 'PT' . absint( $m[1] ) . 'M';
        $image_url = has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
        if ( $image_url ) $schema['image'] = $image_url;
        return $schema;
    }

    /**
     * 构建 VideoObject Schema
     */
    private function build_video( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $content = $this->get_post_content_cached( $post_id );
        if ( preg_match( '/src=["\']([^"\']*(?:youtube|vimeo|bilibili|video|mp4)[^"\']*)["\']/iu', $content, $m ) ||
             preg_match( '/(https?:\/\/[^\s<>"]+(?:\.mp4|\.webm|youtube\.com|vimeo\.com|bilibili\.com)[^\s<>"]*)/iu', $content, $m ) ) {
            $video_url = $m[1];
        } else {
            return null;
        }
        $schema = array(
            '@context'     => 'https://schema.org',
            '@type'        => 'VideoObject',
            'name'         => get_the_title( $post_id ),
            'description'  => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 200 ),
            'contentUrl'   => $video_url,
            'uploadDate'   => get_the_date( 'c', $post_id ),
        );
        $image_url = has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
        if ( $image_url ) $schema['thumbnailUrl'] = $image_url;
        return $schema;
    }

    /**
     * 构建 LocalBusiness Schema（依赖 GEO 实体为 LocalBusiness 类型）
     */
    private function build_local_business( $post_id ) {
        $entity = class_exists( 'YIMAI_GSO_GEO_Entity' ) ? YIMAI_GSO_GEO_Entity::get_entity_data() : array();
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            'name'     => ! empty( $entity['entity_name'] ) ? $entity['entity_name'] : get_bloginfo( 'name' ),
            'url'      => home_url( '/' ),
        );
        if ( ! empty( $entity['entity_description'] ) ) $schema['description'] = $entity['entity_description'];
        if ( ! empty( $entity['entity_phone'] ) ) $schema['telephone'] = $entity['entity_phone'];
        if ( ! empty( $entity['entity_address'] ) ) $schema['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $entity['entity_address'] );
        if ( ! empty( $entity['entity_logo'] ) ) $schema['logo'] = $entity['entity_logo'];
        return $schema;
    }

    /**
     * 构建 Course Schema
     */
    private function build_course( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $schema = array(
            '@context'     => 'https://schema.org',
            '@type'        => 'Course',
            'name'         => get_the_title( $post_id ),
            'description'  => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 300 ),
            'url'          => get_permalink( $post_id ),
            'provider'     => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
        );
        return $schema;
    }

    /**
     * 构建 JobPosting Schema
     */
    private function build_job( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $content = $this->get_post_content_cached( $post_id );
        $schema = array(
            '@context'          => 'https://schema.org',
            '@type'             => 'JobPosting',
            'title'             => get_the_title( $post_id ),
            'description'       => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 500 ),
            'datePosted'        => get_the_date( 'c', $post_id ),
            'hiringOrganization' => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
        );
        if ( preg_match( '/(?:工作地点|location)[:：\s]*([^\n<]+)/iu', $content, $m ) )
            $schema['jobLocation'] = array( '@type' => 'Place', 'address' => trim( $m[1] ) );
        if ( preg_match( '/(?:工作类型|employmentType)[:：\s]*([^\n<]+)/iu', $content, $m ) ) {
            $schema['employmentType'] = trim( $m[1] );
        } elseif ( preg_match( '/(全职|兼职|实习|full.?time|part.?time|intern)/iu', $content, $m ) ) {
            $schema['employmentType'] = trim( $m[1] );
        }
        return $schema;
    }

    /**
     * 构建 SoftwareApplication Schema
     */
    private function build_software( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;
        $content = $this->get_post_content_cached( $post_id );
        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'SoftwareApplication',
            'name'        => get_the_title( $post_id ),
            'description' => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 300 ),
            'url'         => get_permalink( $post_id ),
        );
        if ( preg_match( '/(?:Android|安卓)/iu', $content ) ) $schema['operatingSystem'] = 'Android';
        elseif ( preg_match( '/(?:iOS|iPhone|iPad)/iu', $content ) ) $schema['operatingSystem'] = 'iOS';
        return $schema;
    }

    /**
     * 检测与主流 SEO 插件的 Schema 冲突
     * @return array 冲突的插件名称列表
     */
    public static function detect_conflict_plugins() {
        $conflicts = array();

        if ( defined( 'WPSEO_VERSION' ) ) {
            $conflicts[] = 'Yoast SEO';
        } elseif ( has_filter( 'wpseo_schema_graph' ) || has_filter( 'wpseo_json_ld_output' ) ) {
            $conflicts[] = 'Yoast SEO';
        }

        if ( defined( 'RANK_MATH_VERSION' ) ) {
            $conflicts[] = 'Rank Math';
        } elseif ( has_filter( 'rank_math/json_ld' ) ) {
            $conflicts[] = 'Rank Math';
        }

        if ( defined( 'AIOSEO_VERSION' ) ) {
            $conflicts[] = 'All in One SEO';
        }

        if ( defined( 'SEOPRESS_VERSION' ) ) {
            $conflicts[] = 'SEOPress';
        }

        return $conflicts;
    }

    /**
     * 构建 BreadcrumbList Schema
     */
    private function build_breadcrumb( $post_id ) {
        $items  = array();
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => get_bloginfo( 'name' ),
            'item'     => home_url( '/' ),
        );

        $post_type = get_post_type( $post_id );

        // Page 类型：构建父页面层级面包屑
        if ( 'page' === $post_type ) {
            $ancestors = get_post_ancestors( $post_id );
            if ( ! empty( $ancestors ) ) {
                // get_post_ancestors 返回从直接父级到最顶层的顺序，需要反转
                $ancestors = array_reverse( $ancestors );
                foreach ( $ancestors as $ancestor_id ) {
                    $items[] = array(
                        '@type'    => 'ListItem',
                        'position' => count( $items ) + 1,
                        'name'     => get_the_title( $ancestor_id ),
                        'item'     => get_permalink( $ancestor_id ),
                    );
                }
            }
        } else {
            // 文章类型：使用分类层级
            $categories = get_the_category( $post_id );
            if ( $categories ) {
                $cat = $categories[0]; // 主分类
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => 2,
                    // ★ 修复（审计 #6）：分类名可能含 HTML 实体（如「Body &amp; Soul」），
                    //   get_the_category 返回的 name 是 WP 格式化后的转义值，
                    //   直接进 JSON-LD 会让搜索引擎解析到字面 &amp;。先解码实体。
                    'name'     => html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ),
                    'item'     => get_category_link( $cat->term_id ),
                );
            }
        }

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => count( $items ) + 1,
            'name'     => get_the_title( $post_id ),
            'item'     => get_permalink( $post_id ),
        );

        return array(
            '@context'   => 'https://schema.org',
            '@type'      => 'BreadcrumbList',
            'itemListElement' => $items,
        );
    }

    /**
     * 输出首页 Schema（WebSite + Organization）
     */
    private function output_front_page_schemas() {
        $enabled = $this->get_enabled_types();
        $schemas = array();

        // WebSite Schema（含 SearchAction 支持 Sitelinks Searchbox）
        $website = $this->build_website_schema();
        if ( $website ) {
            $schemas[] = $website;
        }

        // Organization Schema
        //
        // 修复（L2）：首页此前会恒定输出两个互相冲突的 Organization 节点。
        // 三个发射器的守卫互不一致：
        //   a) yimai-gso-optimizer.php:737  实体已配置则跳过（无首页判断）
        //   b) 本方法                        仅判断 is_front_page()（无实体判断）← 恒输出
        //   c) class-geo-entity.php:155      实体已配置才输出
        // 于是「实体未配置」时为 a+b，「实体已配置」时为 b+c，两种配置下都是 2 个节点。
        // 重复 Organization 正是 GEO 工作要消除的问题，且插件自身的 GEO 审计器
        // 会拿这份自相矛盾的输出给「实体就绪度」打分。
        // 修复：本方法遵循与 (a)(c) 相同的谓词——GEO 实体已配置时，
        // 由 class-geo-entity.php 的增强版负责输出，这里不再重复。
        $geo_entity_configured = class_exists( 'YIMAI_GSO_GEO_Entity' )
            && YIMAI_GSO_GEO_Entity::is_configured();

        if ( ! $geo_entity_configured ) {
            $org = $this->build_organization_schema();
            if ( $org ) {
                $schemas[] = $org;
            }
        }

        if ( empty( $schemas ) ) {
            return;
        }

        foreach ( $schemas as $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . '</script>' . "\n";
        }
    }

    /**
     * 构建 WebSite Schema（首页）
     */
    private function build_website_schema() {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => get_bloginfo( 'name' ),
            'url'      => home_url( '/' ),
        );

        $description = get_bloginfo( 'description' );
        if ( ! empty( $description ) ) {
            $schema['description'] = $description;
        }

        // 站内搜索（Sitelinks Searchbox）
        $schema['potentialAction'] = array(
            '@type'       => 'SearchAction',
            'target'      => array(
                '@type' => 'EntryPoint',
                'urlTemplate' => home_url( '/?s={search_term_string}' ),
            ),
            'query-input' => array(
                '@type' => 'PropertyValueSpecification',
                'valueRequired' => true,
                'valueName' => 'search_term_string',
            ),
        );

        // 站点语言
        $lang = get_locale();
        if ( ! empty( $lang ) ) {
            $schema['inLanguage'] = $lang;
        }

        return $schema;
    }

    /**
     * 构建 Organization Schema（首页）
     */
    private function build_organization_schema() {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => get_bloginfo( 'name' ),
            'url'      => home_url( '/' ),
        );

        $description = get_bloginfo( 'description' );
        if ( ! empty( $description ) ) {
            $schema['description'] = $description;
        }

        // Logo
        $logo_id = get_theme_mod( 'custom_logo' );
        $logo_url = '';
        if ( $logo_id ) {
            $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
        }
        if ( empty( $logo_url ) && function_exists( 'get_site_icon_url' ) ) {
            $logo_url = get_site_icon_url();
        }
        if ( ! empty( $logo_url ) ) {
            $schema['logo'] = array(
                '@type' => 'ImageObject',
                'url'   => $logo_url,
            );
        }

        // 社交媒体链接（sameAs）
        $social = YIMAI_GSO_Settings::get( 'schema_social_profiles', '' );
        if ( ! empty( $social ) ) {
            $profiles = array_filter( array_map( 'trim', explode( "\n", $social ) ) );
            if ( ! empty( $profiles ) ) {
                $schema['sameAs'] = array_values( $profiles );
            }
        }

        return $schema;
    }

    /**
     * 获取文章的 Schema 摘要信息（供管理面板显示）
     */
    public static function get_post_schema_info( $post_id ) {
        $schema_type = get_post_meta( $post_id, 'yimai_gso_schema_type', true );
        if ( empty( $schema_type ) ) {
            return array( 'type' => 'auto', 'label' => '待生成' );
        }
        $labels = self::get_schema_types();
        return array(
            'type'  => $schema_type,
            'label' => isset( $labels[ $schema_type ] ) ? $labels[ $schema_type ] : $schema_type,
        );
    }

    /**
     * 手动设置文章的 Schema 类型
     */
    public static function set_post_schema_type( $post_id, $type ) {
        $valid = array_keys( self::get_schema_types() );
        if ( ! in_array( $type, $valid, true ) ) {
            return false;
        }
        return update_post_meta( $post_id, 'yimai_gso_schema_type', $type );
    }

    /**
     * 获取 Schema 统计数据
     */
    public static function get_summary_stats() {
        global $wpdb;
        $stats = array(
            'total'       => 0,
            'article'     => 0,
            'faq'         => 0,
            'howto'       => 0,
            'product'     => 0,
        );

        $post_type_condition = YIMAI_GSO_Post_Type_Helper::sql_in_clause( 'p.post_type' );

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN pm.meta_value = 'Article' OR pm.meta_value IS NULL OR pm.meta_value = '' THEN 1 ELSE 0 END) AS article,
                    SUM(CASE WHEN pm.meta_value = 'FAQPage' THEN 1 ELSE 0 END) AS faq,
                    SUM(CASE WHEN pm.meta_value = 'HowTo' THEN 1 ELSE 0 END) AS howto,
                    SUM(CASE WHEN pm.meta_value = 'Product' THEN 1 ELSE 0 END) AS product
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'yimai_gso_schema_type'
             WHERE {$post_type_condition} AND p.post_status = 'publish'",
            ARRAY_A
        );

        if ( $row ) {
            $stats['total']   = absint( $row['total'] );
            $stats['article'] = absint( $row['article'] );
            $stats['faq']     = absint( $row['faq'] );
            $stats['howto']   = absint( $row['howto'] );
            $stats['product'] = absint( $row['product'] );
        }

        return $stats;
    }

    /**
     * AJAX: 批量重建文章 Schema 类型检测
     */
    public static function ajax_rebuild_schemas() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        global $wpdb;
        $post_type_condition = YIMAI_GSO_Post_Type_Helper::sql_in_clause( 'p.post_type' );
        $post_ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE {$post_type_condition} AND p.post_status = 'publish'
             ORDER BY p.ID DESC LIMIT 200"
        );

        $results = array( 'updated' => 0, 'article' => 0, 'faq' => 0, 'howto' => 0 );

        $instance = self::get_instance();

        foreach ( $post_ids as $id ) {
            $type = $instance->auto_detect_type( $id );
            update_post_meta( $id, 'yimai_gso_schema_type', $type );
            $results['updated']++;
            $results[ $type === 'FAQPage' ? 'faq' : ( $type === 'HowTo' ? 'howto' : 'article' ) ]++;
        }

        wp_send_json_success( $results );
    }

    /**
     * AJAX: 保存 Schema 类型开关设置
     */
    public static function ajax_save_settings() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $types = isset( $_POST['schema_enabled_types'] ) && is_array( $_POST['schema_enabled_types'] )
            ? array_map( 'sanitize_text_field', $_POST['schema_enabled_types'] )
            : array();

        // 验证类型合法性
        $valid_types = array_keys( self::get_schema_types() );
        $types = array_intersect( $types, $valid_types );

        YIMAI_GSO_Settings::set( 'schema_enabled_types', $types );

        wp_send_json_success( array( 'message' => '设置已保存' ) );
    }

    /**
     * AJAX: 获取 Schema 统计数据
     */
    public static function ajax_get_stats() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $stats = self::get_summary_stats();
        wp_send_json_success( $stats );
    }
}
