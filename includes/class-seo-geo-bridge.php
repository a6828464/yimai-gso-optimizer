<?php
/**
 * SEO-GEO 双引擎桥接层
 *
 * 统一桥接类，包含三个子能力：
 * - SEO 插件深度集成（Yoast / Rank Math / SEOPress / AIOSEO）
 * - Meta 标签同步管理（SEO meta ↔ GEO 数据 ↔ AI 元标签）
 * - XML Sitemap 增强（GEO 视角）
 *
 * 设计原则：
 * - 零侵入：不修改 SEO 插件和 YIMAI_GSO_Sitemap_Generator 内部逻辑
 * - 降级友好：SEO 插件未安装时静默跳过
 * - 只读消费：只读取外部数据，不写回
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_SEO_GEO_Bridge {

    private static $instance = null;

    /** @var array 已检测到的 SEO 插件列表 */
    private $detected_plugins = array();

    /** @var string SEO 插件检测 Transient key */
    const DETECT_TRANSIENT = 'yimai_gso_seo_plugin_detect';

    /** @var string Meta 一致性检查结果 post_meta key */
    const META_CHECK_KEY = 'yimai_gso_meta_consistency_check';

    /** @var array 已在本请求中检查过的文章 ID（防重复） */
    private static $checked_posts = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! true || ! true ) {
            return;
        }

        // 后台：检测 SEO 插件
        add_action( 'admin_init', array( $this, 'detect_seo_plugins' ) );

        // 叠加 GEO 维度到双引擎评分
        add_filter( 'yimai_gso_synergy_context', array( $this, 'extend_with_seo_plugin_data' ), 10, 2 );

        // Meta 标签同步（wp_head 早期，在所有 SEO/GEO 输出之前）
        add_action( 'wp_head', array( $this, 'synchronize_meta_output' ), 4 );

        // Sitemap 增强 — 注册 sitemap URL 条目过滤器
        add_filter( 'yimai_gso_sitemap_entry_filter', array( $this, 'enhance_sitemap_entry' ), 10, 2 );

        // AJAX 端点
        add_action( 'wp_ajax_yimai_gso_seo_geo_plugin_info', array( $this, 'ajax_get_plugin_info' ) );
        add_action( 'wp_ajax_yimai_gso_seo_geo_meta_check', array( $this, 'ajax_check_meta_consistency' ) );
        // 桥接管理页面 AJAX
        add_action( 'wp_ajax_yimai_gso_seo_geo_get_conflicts', array( $this, 'ajax_get_conflicts' ) );
        add_action( 'wp_ajax_yimai_gso_seo_geo_apply_fix', array( $this, 'ajax_apply_fix' ) );
        add_action( 'wp_ajax_yimai_gso_seo_geo_run_scan', array( $this, 'ajax_run_scan' ) );
        add_action( 'wp_ajax_yimai_gso_seo_geo_get_status', array( $this, 'ajax_get_status' ) );
    }

    // ==================== SEO 插件深度集成 ====================

    /**
     * 检测已安装的 SEO 插件（利用 Transient 缓存结果 1 小时）
     */
    public function detect_seo_plugins() {
        $cached = get_transient( self::DETECT_TRANSIENT );
        if ( false !== $cached && is_array( $cached ) ) {
            $this->detected_plugins = $cached;
            return;
        }

        $detected = array();

        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            $detected['yoast'] = array(
                'name'    => 'Yoast SEO',
                'version' => WPSEO_VERSION,
                'active'  => true,
            );
        }

        // Rank Math
        if ( class_exists( 'RankMath' ) ) {
            $rm_version = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '';
            $detected['rankmath'] = array(
                'name'    => 'Rank Math SEO',
                'version' => $rm_version,
                'active'  => true,
            );
        }

        // SEOPress
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            $detected['seopress'] = array(
                'name'    => 'SEOPress',
                'version' => SEOPRESS_VERSION,
                'active'  => true,
            );
        }

        // All in One SEO
        if ( function_exists( 'aioseo' ) ) {
            $aioseo_version = defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : '';
            $detected['aioseo'] = array(
                'name'    => 'All in One SEO',
                'version' => $aioseo_version,
                'active'  => true,
            );
        }

        $this->detected_plugins = $detected;
        set_transient( self::DETECT_TRANSIENT, $detected, HOUR_IN_SECONDS );
    }

    /**
     * 获取已安装的 SEO 插件信息
     *
     * @return array
     */
    public static function get_seo_plugin_info() {
        $instance = self::get_instance();
        return $instance->detected_plugins;
    }

    /**
     * 判断是否有任何 SEO 插件处于激活状态
     *
     * @return bool
     */
    public static function is_any_seo_plugin_active() {
        $instance = self::get_instance();
        return ! empty( $instance->detected_plugins );
    }

    /**
     * 从 SEO 插件读取焦点关键词和 SEO 评分
     *
     * WordPress apply_filters 约定：第一个参数是被过滤的值（$current_context），
     * 第二个参数是附加参数（$post_id）。
     *
     * @param array $current_context 已有的上下文数据（来自 apply_filters 第一个参数）
     * @param int   $post_id         文章 ID（来自 apply_filters 第二个参数）
     * @return array
     */
    public function extend_with_seo_plugin_data( $current_context = array(), $post_id = 0 ) {
        if ( empty( $this->detected_plugins ) ) {
            return $current_context;
        }

        $seo_data = array();

        // 尝试从每个检测到的插件读取数据
        foreach ( array_keys( $this->detected_plugins ) as $plugin_key ) {
            $method = 'read_' . $plugin_key . '_data';
            if ( method_exists( $this, $method ) ) {
                $plugin_data = $this->$method( $post_id );
                if ( ! empty( $plugin_data ) ) {
                    $seo_data[ $plugin_key ] = $plugin_data;
                }
            }
        }

        $current_context['seo_plugins']       = $this->detected_plugins;
        $current_context['seo_plugin_data']   = $seo_data;
        $current_context['has_seo_plugin']    = ! empty( $seo_data );

        return $current_context;
    }

    /**
     * 读取 Yoast SEO 数据
     *
     * @param int $post_id
     * @return array|null
     */
    private function read_yoast_data( $post_id ) {
        if ( ! class_exists( 'WPSEO_Meta' ) ) {
            return null;
        }

        $focus_kw = WPSEO_Meta::get_value( 'focuskw', $post_id );
        $seo_score = get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );

        if ( empty( $focus_kw ) && empty( $seo_score ) ) {
            return null;
        }

        return array(
            'plugin_name'    => 'Yoast SEO',
            'focus_keyword'  => $focus_kw ? $focus_kw : '',
            'seo_score'      => $seo_score !== '' ? intval( $seo_score ) : null,
            'meta_title'     => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
            'meta_desc'      => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
        );
    }

    /**
     * 读取 Rank Math SEO 数据
     *
     * @param int $post_id
     * @return array|null
     */
    private function read_rankmath_data( $post_id ) {
        $focus_kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        $seo_score = get_post_meta( $post_id, 'rank_math_seo_score', true );

        if ( empty( $focus_kw ) && empty( $seo_score ) ) {
            return null;
        }

        return array(
            'plugin_name'    => 'Rank Math SEO',
            'focus_keyword'  => $focus_kw ? $focus_kw : '',
            'seo_score'      => $seo_score !== '' ? intval( $seo_score ) : null,
            'meta_title'     => get_post_meta( $post_id, 'rank_math_title', true ),
            'meta_desc'      => get_post_meta( $post_id, 'rank_math_description', true ),
        );
    }

    /**
     * 读取 SEOPress 数据
     *
     * @param int $post_id
     * @return array|null
     */
    private function read_seopress_data( $post_id ) {
        $focus_kw = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );

        if ( empty( $focus_kw ) ) {
            return null;
        }

        return array(
            'plugin_name'    => 'SEOPress',
            'focus_keyword'  => $focus_kw,
            'seo_score'      => null, // SEOPress 不提供评分
            'meta_title'     => get_post_meta( $post_id, '_seopress_titles_title', true ),
            'meta_desc'      => get_post_meta( $post_id, '_seopress_titles_desc', true ),
        );
    }

    /**
     * 读取 AIOSEO 数据
     *
     * @param int $post_id
     * @return array|null
     */
    private function read_aioseo_data( $post_id ) {
        $keywords = get_post_meta( $post_id, '_aioseo_keywords', true );
        $page_score = get_post_meta( $post_id, '_aioseo_page_score', true );

        if ( empty( $keywords ) && empty( $page_score ) ) {
            return null;
        }

        return array(
            'plugin_name'    => 'All in One SEO',
            'focus_keyword'  => $keywords ? $keywords : '',
            'seo_score'      => $page_score !== '' ? intval( $page_score ) : null,
            'meta_title'     => get_post_meta( $post_id, '_aioseo_title', true ),
            'meta_desc'      => get_post_meta( $post_id, '_aioseo_description', true ),
        );
    }

    // ==================== Meta 标签同步管理 ====================

    /**
     * wp_head(4): Meta 标签一致性检查与同步
     *
     * 在 SEO meta、GEO 数据、AI 元标签输出之前执行检查，
     * 不一致时在 HTML 注释中写入警告（开发者工具可见）。
     *
     * 性能优化：通过 transient 限频，同一文章每小时仅检查一次。
     */
    public function synchronize_meta_output() {
        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || in_array( $post_id, self::$checked_posts, true ) ) {
            return;
        }

        self::$checked_posts[] = $post_id;

        // 限频：同一文章 1 小时内不重复检查（避免高流量站点每次请求都写 DB）
        $rate_key = 'yimai_gso_meta_check_rate_' . $post_id;
        if ( false !== get_transient( $rate_key ) ) {
            return;
        }
        set_transient( $rate_key, 1, HOUR_IN_SECONDS );

        $result = $this->check_meta_consistency( $post_id );

        // 仅在不一致时输出 HTML 注释
        if ( ! empty( $result['warnings'] ) ) {
            echo "\n<!-- 一麦GSO Meta Consistency Warnings (L6):\n";
            foreach ( $result['warnings'] as $warning ) {
                echo '  - ' . esc_html( $warning ) . "\n";
            }
            echo "-->\n";
        }
    }

    /**
     * 检查文章的三方 Meta 一致性
     *
     * @param int $post_id
     * @return array
     */
    public function check_meta_consistency( $post_id ) {
        $warnings = array();
        $checks   = array();

        // 1. 读取三方 Meta
        $seo_meta = $this->read_legacy_seo_meta( $post_id );
        $geo_data = $this->read_legacy_geo_data( $post_id );
        $ai_meta  = $this->read_legacy_ai_meta( $post_id );

        // 2. description → ai-description 回退检测
        if ( ! empty( $seo_meta['description'] ) && empty( $ai_meta['ai_description'] ) ) {
            $warnings[] = sprintf(
                'ai-description 为空，建议从 SEO description 自动填充：%s',
                mb_substr( $seo_meta['description'], 0, 80 ) . '...'
            );
            $checks['desc_to_ai'] = 'missing';
        }

        // 3. title → Schema headline 一致性
        if ( ! empty( $seo_meta['title'] ) && ! empty( $geo_data['schema_headline'] ) ) {
            if ( $seo_meta['title'] !== $geo_data['schema_headline'] ) {
                $warnings[] = sprintf(
                    'SEO title 与 Schema headline 不一致：SEO="%s" vs Schema="%s"',
                    mb_substr( $seo_meta['title'], 0, 60 ),
                    mb_substr( $geo_data['schema_headline'], 0, 60 )
                );
                $checks['title_schema_match'] = false;
            } else {
                $checks['title_schema_match'] = true;
            }
        }

        // 4. keywords → Schema about 同步检测
        if ( ! empty( $seo_meta['keywords'] ) && empty( $geo_data['schema_about'] ) ) {
            $warnings[] = 'Schema about 字段为空，建议从焦点关键词同步';
            $checks['keywords_to_about'] = 'missing';
        }

        // 5. 作者 + sameAs 三方一致性
        if ( ! empty( $seo_meta['author'] ) && ! empty( $geo_data['schema_author'] ) ) {
            if ( $seo_meta['author'] !== $geo_data['schema_author'] ) {
                $warnings[] = sprintf(
                    'SEO meta author 与 Schema author 不一致：SEO="%s" vs Schema="%s"',
                    $seo_meta['author'],
                    $geo_data['schema_author']
                );
                $checks['author_match'] = false;
            } else {
                $checks['author_match'] = true;
            }
        }

        // 缓存检查结果
        $result = array(
            'checked_at' => current_time( 'mysql' ),
            'warnings'   => $warnings,
            'checks'     => $checks,
        );
        update_post_meta( $post_id, self::META_CHECK_KEY, wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) );

        return $result;
    }

    /**
     * 读取 一麦GSO 插件的 SEO Meta
     */
    private function read_legacy_seo_meta( $post_id ) {
        return array(
            'title'       => get_post_meta( $post_id, 'yimai_gso_seo_title', true ),
            'description' => get_post_meta( $post_id, 'yimai_gso_seo_description', true ),
            'keywords'    => get_post_meta( $post_id, 'yimai_gso_seo_keywords', true ),
            'author'      => get_post_meta( $post_id, 'yimai_gso_author_name', true ),
        );
    }

    /**
     * 读取 一麦GSO 插件的 GEO 数据
     */
    private function read_legacy_geo_data( $post_id ) {
        $schema_type = get_post_meta( $post_id, 'yimai_gso_schema_type', true );
        $geo_score_raw = get_post_meta( $post_id, 'yimai_gso_geo_score', true );

        $geo_score = null;
        if ( ! empty( $geo_score_raw ) ) {
            if ( is_array( $geo_score_raw ) ) {
                $geo_score = isset( $geo_score_raw['overall'] ) ? $geo_score_raw['overall'] : null;
            } else {
                $decoded = json_decode( $geo_score_raw, true );
                if ( is_array( $decoded ) ) {
                    $geo_score = isset( $decoded['overall'] ) ? $decoded['overall'] : null;
                }
            }
        }

        return array(
            'schema_type'    => $schema_type ? $schema_type : 'Article',
            'schema_headline'=> get_the_title( $post_id ),
            'schema_author'  => get_post_meta( $post_id, 'yimai_gso_author_name', true ),
            'schema_about'   => get_post_meta( $post_id, 'yimai_gso_seo_keywords', true ),
            'geo_score'      => $geo_score,
        );
    }

    /**
     * 读取 一麦GSO 插件的 AI Meta（与 output_ai_meta() 同字段优先级）
     */
    private function read_legacy_ai_meta( $post_id ) {
        // AI 描述：与 output_ai_meta() 同优先级链
        $ai_desc = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $post_id, 'description', true );
        if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $post_id, 'rank_math_description', true );

        // AI 关键词：与 output_ai_meta() 同优先级链
        $ai_kw = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        if ( empty( $ai_kw ) ) $ai_kw = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
        if ( empty( $ai_kw ) ) $ai_kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );

        return array(
            'ai_description' => $ai_desc,
            'ai_keywords'    => $ai_kw,
        );
    }

    // ==================== Sitemap 增强 ====================

    /**
     * 增强 Sitemap 的每个 URL 条目（L6 #78）
     *
     * 在 <url> 块内追加 GEO 维度的标记行。
     *
     * @param string $entry   始终为空字符串（由 sitemap generator 传入）
     * @param int    $post_id 文章 ID
     * @return string XML 增强片段
     */
    public function enhance_sitemap_entry( $entry, $post_id ) {
        if ( ! $post_id ) {
            return '';
        }

        $enhancements = '';

        // AI Ready 标记（GEO 评分 >= 55）
        if ( class_exists( 'YIMAI_GSO_GEO_Scorer' ) ) {
            try {
                $geo_score_data = YIMAI_GSO_GEO_Scorer::_xCSd( $post_id );
            } catch ( \Throwable $e ) {
                error_log( '[一麦GSO] Bridge → Geo_Scorer._xCSd() failed: ' . $e->getMessage() );
                $geo_score_data = null;
            }
            $geo_overall = isset( $geo_score_data['overall'] ) ? intval( $geo_score_data['overall'] ) : 0;
            $ai_ready = $geo_overall >= 55 ? 'true' : 'false';
            $enhancements .= "\t\t<ai:ready>{$ai_ready}</ai:ready>\n";
            if ( $geo_overall > 0 ) {
                $enhancements .= "\t\t<ai:geo_score>{$geo_overall}</ai:geo_score>\n";
            }
        }

        // Schema 覆盖标记
        $schema_type = get_post_meta( $post_id, 'yimai_gso_schema_type', true );
        if ( ! empty( $schema_type ) ) {
            $enhancements .= "\t\t<ai:schema_types>" . esc_xml( $schema_type ) . "</ai:schema_types>\n";
        }

        // 平台覆盖标记
        if ( class_exists( 'YIMAI_GSO_AI_Platform_Adapter' ) ) {
            $adapter = YIMAI_GSO_AI_Platform_Adapter::get_instance();
            try {
                $active = $adapter->get_active_platforms();
            } catch ( \Throwable $e ) {
                error_log( '[一麦GSO] Bridge → AI_Platform_Adapter.get_active_platforms() failed: ' . $e->getMessage() );
                $active = array();
            }
            if ( ! empty( $active ) ) {
                $enhancements .= "\t\t<ai:platforms>" . esc_xml( implode( ',', array_keys( $active ) ) ) . "</ai:platforms>\n";
            }
        }

        return $enhancements;
    }

    // ==================== AJAX 端点 ====================

    /**
     * AJAX: 获取 SEO 插件信息
     */
    public function ajax_get_plugin_info() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
wp_send_json_success( array(
            'plugins'  => $this->detected_plugins,
            'has_any'  => $this->is_any_seo_plugin_active(),
        ) );
    }

    /**
     * AJAX: 检查指定文章的 Meta 一致性
     */
    public function ajax_check_meta_consistency() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '缺少文章 ID' ) );
        }

        $result = $this->check_meta_consistency( $post_id );

        wp_send_json_success( array(
            'post_id'     => $post_id,
            'post_title'  => get_the_title( $post_id ),
            'warnings'    => $result['warnings'],
            'checks'      => $result['checks'],
            'warning_count' => count( $result['warnings'] ),
        ) );
    }

    // ==================== 冲突检测与修复（桥接管理页面） ====================

    /** 冲突检测结果 Transient key */
    const CONFLICT_TRANSIENT = 'yimai_gso_seo_geo_conflicts';

    /** 状态摘要 Transient key */
    const STATUS_TRANSIENT = 'yimai_gso_seo_geo_status_summary';

    /**
     * 检测所有桥接冲突
     *
     * @return array
     */
    public function detect_all_conflicts() {
        $cached = get_transient( self::CONFLICT_TRANSIENT );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $conflicts = array();

        // 1. Schema 重复输出
        if ( class_exists( 'YIMAI_GSO_Schema_Generator' ) ) {
            $schema_conflicts = YIMAI_GSO_Schema_Generator::detect_conflict_plugins();
            if ( ! empty( $schema_conflicts ) ) {
                $conflicts[] = array(
                    'id'          => 'schema_duplicate',
                    'type'        => 'schema',
                    'severity'    => 'medium',
                    'title'       => 'Schema 标记重复输出风险',
                    'description' => '检测到 ' . implode( '、', $schema_conflicts ) . ' 已安装，一麦GSO 已自动禁用自身 Schema 输出来避免重复。',
                    'auto_fix'    => false,
                    'fixed'       => true,
                    'details'     => array(
                        'plugins' => $schema_conflicts,
                    ),
                );
            }
        }

        // 2. Canonical URL 冲突
        $canonical_conflict = $this->detect_canonical_conflict();
        if ( $canonical_conflict ) {
            $conflicts[] = $canonical_conflict;
        }

        // 3. Meta description 回填建议（统计 ai-description 缺失的文章数）
        $desc_missing_count = $this->count_missing_ai_descriptions();
        if ( $desc_missing_count > 0 ) {
            $conflicts[] = array(
                'id'          => 'ai_desc_missing',
                'type'        => 'meta',
                'severity'    => 'low',
                'title'       => 'AI 描述字段缺失',
                'description' => sprintf( '有 %d 篇文章的 AI 描述字段为空，但 SEO 插件已设置了 meta description，建议自动回填。', $desc_missing_count ),
                'auto_fix'    => true,
                'fixed'       => false,
                'details'     => array(
                    'affected_count' => $desc_missing_count,
                ),
            );
        }

        // 4. Robots.txt 协同状态
        $robots_status = $this->check_robots_collaboration();
        if ( ! empty( $robots_status['issues'] ) ) {
            $conflicts[] = array(
                'id'          => 'robots_collaboration',
                'type'        => 'robots',
                'severity'    => 'low',
                'title'       => 'robots.txt 协同建议',
                'description' => $robots_status['message'],
                'auto_fix'    => false,
                'fixed'       => false,
                'details'     => $robots_status,
            );
        }

        // 5. SEO 插件兼容性总览（仅在检测到插件时输出）
        if ( $this->is_any_seo_plugin_active() ) {
            $compat = $this->check_seo_plugin_compatibility();
            $conflicts[] = array(
                'id'          => 'plugin_compatibility',
                'type'        => 'compatibility',
                'severity'    => 'info',
                'title'       => 'SEO 插件兼容性检测',
                'description' => $compat['summary'] ?? '检测完成',
                'auto_fix'    => false,
                'fixed'       => true,
                'details'     => $compat,
            );
        }

        $result = array(
            'conflicts'     => $conflicts,
            'total'         => count( $conflicts ),
            'fixable'       => count( array_filter( $conflicts, function( $c ) { return $c['auto_fix'] && ! $c['fixed']; } ) ),
            'detected_at'   => current_time( 'mysql' ),
        );

        set_transient( self::CONFLICT_TRANSIENT, $result, HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * 检测 Canonical URL 冲突
     */
    private function detect_canonical_conflict() {
        if ( ! $this->is_any_seo_plugin_active() ) {
            return null;
        }

        return array(
            'id'          => 'canonical_conflict',
            'type'        => 'canonical',
            'severity'    => 'medium',
            'title'       => 'Canonical URL 潜在冲突',
            'description' => '一麦GSO 的 Canonical 管理与 ' . implode( '、', array_keys( $this->detected_plugins ) ) . ' 的 canonical 输出可能重复，建议仅在其中一个插件中启用。可前往「SEO优化 → Canonical」检查设置。',
            'auto_fix'    => false,
            'fixed'       => false,
            'details'     => array(
                'legacy_active'  => true,
                'seo_plugins'  => array_keys( $this->detected_plugins ),
            ),
        );
    }

    /**
     * 统计 AI 描述缺失但 SEO 插件有描述的文章数
     */
    private function count_missing_ai_descriptions() {
        if ( ! $this->is_any_seo_plugin_active() ) {
            return 0;
        }

        global $wpdb;
        $post_types = array( 'post', 'page' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $post_types = YIMAI_GSO_Post_Type_Helper::get_all_supported();
        }
        $pt_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // 检查 yimai_gso_excerpt 为空但 SEO meta description 不为空的文章
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'yimai_gso_excerpt'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_yoast_wpseo_metadesc'
             LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = 'rank_math_description'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ({$pt_placeholders})
               AND (pm1.meta_value IS NULL OR pm1.meta_value = '')
               AND (pm2.meta_value IS NOT NULL AND pm2.meta_value != ''
                    OR pm3.meta_value IS NOT NULL AND pm3.meta_value != '')",
            ...$post_types
        );

        return intval( $wpdb->get_var( $sql ) );
    }

    /**
     * 检查 robots.txt 协同状态
     */
    private function check_robots_collaboration() {
        $result = array(
            'issues'  => array(),
            'message' => '',
        );

        // 检查是否有 AI 爬虫规则
        $has_crawler_rules = false;
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $rules = YIMAI_GSO_Crawler_Editor::get_rules();
            $has_crawler_rules = ! empty( $rules );
        }

        if ( ! $has_crawler_rules ) {
            $result['issues'][] = 'no_crawler_rules';
            $result['message'] = '尚未配置 AI 爬虫规则，建议前往 AI爬虫编辑器 设置爬虫策略';
        } else {
            $result['message'] = 'AI 爬虫规则已通过 robots.txt 正常输出，' . count( $rules ) . ' 个爬虫已配置';
        }

        // 检查 一麦GSO robots.txt 是否接管
        $robots_content = YIMAI_GSO_Settings::get( 'yimai_gso_robots_content', '' );
        $result['custom_robots'] = ! empty( $robots_content );
        $result['crawler_rules_count'] = $has_crawler_rules ? count( $rules ) : 0;

        return $result;
    }

    /**
     * 检查 SEO 插件兼容性详情
     */
    private function check_seo_plugin_compatibility() {
        if ( ! $this->is_any_seo_plugin_active() ) {
            return array(
                'summary' => '未检测到 SEO 插件，无需桥接',
                'plugins' => array(),
            );
        }

        $plugins = array();
        $all_compatible = true;

        foreach ( $this->detected_plugins as $key => $info ) {
            $compat = array(
                'key'      => $key,
                'name'     => $info['name'],
                'version'  => $info['version'] ?? '',
                'status'   => 'compatible',
                'notes'    => array(),
            );

            // Yoast: 已通过 detect_conflict_plugins 自动禁用 一麦GSO Schema
            if ( 'yoast' === $key || 'rankmath' === $key || 'aioseo' === $key ) {
                $compat['notes'][] = '一麦GSO Schema 自动禁用（避免重复）';
            }

            // Meta 字段兼容性
            if ( 'yoast' === $key ) {
                $compat['notes'][] = '支持读取焦点关键词和 SEO 评分';
                $compat['notes'][] = '支持读取 meta title/description';
            } elseif ( 'rankmath' === $key ) {
                $compat['notes'][] = '支持读取焦点关键词和 SEO 评分';
            } elseif ( 'seopress' === $key ) {
                $compat['notes'][] = '支持读取目标关键词';
            } elseif ( 'aioseo' === $key ) {
                $compat['notes'][] = '支持读取关键词和页面评分';
            }

            $compat['notes'][] = 'Meta 一致性检查已启用';
            $plugins[] = $compat;
        }

        return array(
            'summary'    => '已检测到 ' . count( $plugins ) . ' 个 SEO 插件，全部兼容',
            'all_good'   => $all_compatible,
            'plugins'    => $plugins,
        );
    }

    /**
     * 执行自动修复
     *
     * @param string $conflict_id 冲突 ID
     * @return array 修复结果
     */
    public function apply_fix( $conflict_id ) {
        switch ( $conflict_id ) {

            case 'ai_desc_missing':
                return $this->fix_missing_ai_descriptions();

            default:
                return array(
                    'success' => false,
                    'message' => '不支持的修复类型：' . $conflict_id,
                );
        }
    }

    /**
     * 修复：回填 AI 描述
     */
    private function fix_missing_ai_descriptions() {
        global $wpdb;
        $post_types = array( 'post', 'page' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $post_types = YIMAI_GSO_Post_Type_Helper::get_all_supported();
        }
        $pt_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $sql = $wpdb->prepare(
            "SELECT p.ID, COALESCE(pm2.meta_value, pm3.meta_value) AS seo_desc
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'yimai_gso_excerpt'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_yoast_wpseo_metadesc'
             LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = 'rank_math_description'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ({$pt_placeholders})
               AND (pm1.meta_value IS NULL OR pm1.meta_value = '')
               AND (pm2.meta_value IS NOT NULL AND pm2.meta_value != ''
                    OR pm3.meta_value IS NOT NULL AND pm3.meta_value != '')
             ORDER BY p.post_date DESC
             LIMIT 20",
            ...$post_types
        );

        $posts = $wpdb->get_results( $sql );
        $fixed = 0;

        foreach ( $posts as $post ) {
            if ( ! empty( $post->seo_desc ) ) {
                update_post_meta( $post->ID, 'yimai_gso_excerpt', $post->seo_desc );
                $fixed++;
            }
        }

        // 清除冲突缓存
        delete_transient( self::CONFLICT_TRANSIENT );

        return array(
            'success' => true,
            'message' => sprintf( '已为 %d 篇文章回填 AI 描述', $fixed ),
            'fixed'   => $fixed,
            'total'   => count( $posts ),
        );
    }

    // ==================== 新增 AJAX 端点（桥接管理页面） ====================

    /**
     * AJAX: 获取冲突列表
     */
    public function ajax_get_conflicts() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
$result = $this->detect_all_conflicts();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: 执行修复
     */
    public function ajax_apply_fix() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
$conflict_id = isset( $_POST['conflict_id'] ) ? sanitize_text_field( $_POST['conflict_id'] ) : '';
        if ( empty( $conflict_id ) ) {
            wp_send_json_error( array( 'message' => '缺少冲突 ID' ) );
        }

        $result = $this->apply_fix( $conflict_id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: 全站 Meta 一致性扫描
     */
    public function ajax_run_scan() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $limit  = 10; // 每次扫描 10 篇

        $post_types = array( 'post' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $post_types = YIMAI_GSO_Post_Type_Helper::get_all_supported();
        }

        $posts = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'post_date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ) );

        $results = array();
        foreach ( $posts as $post_id ) {
            $check = $this->check_meta_consistency( $post_id );
            $results[] = array(
                'post_id'       => $post_id,
                'post_title'    => get_the_title( $post_id ),
                'post_url'      => get_permalink( $post_id ),
                'warning_count' => count( $check['warnings'] ),
                'warnings'      => $check['warnings'],
            );
        }

        $total_posts = 0;
        $cached_total = get_transient( 'yimai_gso_scan_total_posts' );
        if ( 0 === $offset ) {
            // 统计所有支持的文章类型的已发布总数（而非仅 post）
            $total_posts = 0;
            foreach ( $post_types as $pt ) {
                $counts = wp_count_posts( $pt );
                $total_posts += isset( $counts->publish ) ? intval( $counts->publish ) : 0;
            }
            set_transient( 'yimai_gso_scan_total_posts', $total_posts, MINUTE_IN_SECONDS * 5 );
        } elseif ( false !== $cached_total ) {
            $total_posts = intval( $cached_total );
        }

        wp_send_json_success( array(
            'results'    => $results,
            'offset'     => $offset + count( $posts ),
            'total'      => $total_posts,
            'has_more'   => count( $posts ) >= $limit,
            'scan_total' => $offset + count( $posts ),
        ) );
    }

    /**
     * AJAX: 获取桥接状态摘要
     */
    public function ajax_get_status() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
// SEO 插件信息
        $plugin_info = $this->detected_plugins;

        // Sitemap 增强状态
        $sitemap_enhanced = false;
        if ( class_exists( 'YIMAI_GSO_Sitemap_Generator' ) ) {
            $sitemap_enhanced = has_filter( 'yimai_gso_sitemap_entry_filter', array( $this, 'enhance_sitemap_entry' ) );
        }

        // 爬虫规则数
        $crawler_count = 0;
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $rules = YIMAI_GSO_Crawler_Editor::get_rules();
            $crawler_count = count( $rules );
        }

        // Meta 一致性统计
        $meta_check_count = 0;
        global $wpdb;
        $meta_check_count = intval( $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'yimai_gso_meta_consistency_check'"
        ) );

        // Schema 冲突状态
        $schema_status = 'normal';
        if ( class_exists( 'YIMAI_GSO_Schema_Generator' ) ) {
            $schema_conflicts = YIMAI_GSO_Schema_Generator::detect_conflict_plugins();
            if ( ! empty( $schema_conflicts ) ) {
                $schema_status = 'disabled_by_conflict';
            }
        }

        $status = array(
            'plugin_info'        => $plugin_info,
            'has_seo_plugin'     => ! empty( $plugin_info ),
            'sitemap_enhanced'   => $sitemap_enhanced,
            'crawler_count'      => $crawler_count,
            'meta_check_count'   => $meta_check_count,
            'schema_status'      => $schema_status,
            'robots_managed'     => class_exists( 'YIMAI_GSO_Sitemap_Generator' ),
        );

        wp_send_json_success( $status );
    }
}
