<?php
/**
 * GEO 综合评分引擎 — 第4层核心
 *
 * Read-Only Aggregator 模式：
 * 不重新分析内容，只汇总 L1-L3 层已缓存的数据。
 * 5 维度加权公式：答案前置 25% + 可提取性 25% + Schema 覆盖 20% + 爬虫友好 15% + 实体覆盖 15%
 *
 * 输入（全部来自缓存）：
 *   - yimai_gso_seo_score post_meta → 答案前置检测
 *   - yimai_gso_extractability post_meta → 可提取性 0-100
 *   - yimai_gso_schema_type post_meta → Schema 类型覆盖
 *   - yimai_gso_robots_crawler_rules option → AI 爬虫策略
 *   - yimai_gso_geo_entity_settings option → 实体完整度
 *
 * 输出：
 *   - yimai_gso_geo_score post_meta → 缓存 24h
 *   - AJAX: yimai_gso_dual_score → 合并返回 SEO+GEO 双评分
 *   - AJAX: yimai_gso_synergy_suggest → AI 协同建议（按需）
 *   - 编辑器 Meta Box：双引擎评分面板
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_GEO_Scorer {

    private static $instance = null;

    /** GEO 评分 5 维度权重（总和 = 100） */
    const DIMENSIONS = array(
        'answer_firstness' => array(
            'label'  => '答案前置',
            'weight' => 25,
        ),
        'extractability'   => array(
            'label'  => '内容可提取性',
            'weight' => 25,
        ),
        'schema_coverage'  => array(
            'label'  => 'Schema 覆盖',
            'weight' => 20,
        ),
        'crawler_friendly' => array(
            'label'  => 'AI 爬虫友好',
            'weight' => 15,
        ),
        'entity_coverage'  => array(
            'label'  => '实体覆盖度',
            'weight' => 15,
        ),
    );

    /** 缓存有效期（秒） */
    const _xX4 = 86400; // 24 小时

    /** 分差阈值：超过此值才触发协同建议 */
    const SYNERGY_GAP_THRESHOLD = 15;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX 端点
        add_action( 'wp_ajax_yimai_gso_dual_score',        array( $this, 'ajax_dual_score' ) );
        add_action( 'wp_ajax_yimai_gso_synergy_suggest',    array( $this, 'ajax_synergy_suggest' ) );

        // post_save 时清除 GEO 评分缓存
        add_action( 'save_post', array( $this, 'invalidate_cache' ), 30 );
    }

    /* ================================================
     * 核心评分计算
     * ================================================ */

    /**
     * 计算单篇文章的 GEO 综合评分
     *
     * @param int  $post_id
     * @param bool $force  是否强制重算（跳过缓存）
     * @return array { overall, grade, dimensions, tips, computed_at }
     */
    public function compute( $post_id, $force = false ) {
        // 1. 读缓存
        if ( ! $force ) {
            $cached = $this->_xCSd_internal( $post_id );
            if ( null !== $cached ) {
                return $cached;
            }
        }

        // 2. 逐维度计算
        $dim_scores = array();
        $total      = 0;
        $all_tips   = array();

        foreach ( self::DIMENSIONS as $key => $dim ) {
            $method = 'score_' . $key;
            if ( method_exists( $this, $method ) ) {
                $result = $this->$method( $post_id );
            } else {
                $result = array( 'score' => 0, 'detail' => '', 'tips' => array() );
            }

            $score      = max( 0, min( 100, (int) $result['score'] ) );
            $weight     = $dim['weight'];
            $weighted   = round( $score * $weight / 100, 2 );

            $dim_scores[ $key ] = array(
                'label'    => $dim['label'],
                'score'    => $score,
                'weight'   => $weight,
                'weighted' => $weighted,
                'detail'   => $result['detail'],
                'tips'     => $result['tips'],
            );

            $total += $weighted;
            if ( ! empty( $result['tips'] ) ) {
                $all_tips = array_merge( $all_tips, $result['tips'] );
            }
        }

        $overall = round( $total );
        $overall = max( 0, min( 100, $overall ) );

        $grade = $this->get_grade( $overall );

        $result = array(
            'overall'     => $overall,
            'grade'       => $grade,
            'dimensions'  => $dim_scores,
            'tips'        => array_slice( $all_tips, 0, 8 ),
            'computed_at' => current_time( 'mysql' ),
        );

        // 3. 写入缓存
        update_post_meta( $post_id, 'yimai_gso_geo_score', $result );

        return $result;
    }

    /* ================================================
     * 5 维度评分方法
     * ================================================ */

    /**
     * 维度 1：答案前置（25%）
     * 数据来源：SEO Scorer 的 detect_answer_first() 结果
     */
    private function score_answer_firstness( $post_id ) {
        $seo_score = get_post_meta( $post_id, 'yimai_gso_seo_score', true );

        $score  = 0;
        $detail = '未检测到答案前置数据';
        $tips   = array();

        if ( ! empty( $seo_score['geo']['answer_first']['score'] ) ) {
            $score  = (int) $seo_score['geo']['answer_first']['score'];
            $detail = '从 SEO 评分缓存读取';

            if ( ! empty( $seo_score['geo']['answer_first']['is_answer_first'] ) ) {
                $detail = '首段包含直接答案/结论，符合 AI 爬虫偏好';
            } elseif ( $score < 50 ) {
                $tips[] = '【GEO】首段未检测到直接答案。建议在文章开头1-2句给出核心结论';
            }
        } elseif ( ! empty( $seo_score['geo']['answer_first'] ) && is_array( $seo_score['geo']['answer_first'] ) ) {
            // 兼容旧格式：detect_answer_first 返回 { score, is_answer_first, tips }
            $af = $seo_score['geo']['answer_first'];
            $score  = isset( $af['score'] ) ? (int) $af['score'] : 0;
            $detail = isset( $af['is_answer_first'] ) && $af['is_answer_first'] ? '已启用答案前置' : '未启用答案前置';
            if ( ! empty( $af['tips'] ) ) {
                $tips = (array) $af['tips'];
            }
        } else {
            $tips[] = '【GEO】尚未执行 SEO 综合评分，建议先从「SEO评分」面板进行分析';
        }

        return array( 'score' => $score, 'detail' => $detail, 'tips' => $tips );
    }

    /**
     * 维度 2：内容可提取性（25%）
     * 数据来源：YIMAI_GSO_Content_Extractability → yimai_gso_extractability post_meta
     */
    private function score_extractability( $post_id ) {
        $cached = get_post_meta( $post_id, 'yimai_gso_extractability', true );

        if ( ! empty( $cached ) && is_array( $cached ) && isset( $cached['score'] ) && $cached['score'] !== '' ) {
            $score  = (int) $cached['score'];
            $level  = isset( $cached['level'] ) ? $cached['level'] : '';
            $detail = sprintf( '可提取性 %d 分（%s），%d/%d 段落可独立提取',
                $score,
                $level,
                isset( $cached['extractable_count'] ) ? (int) $cached['extractable_count'] : 0,
                isset( $cached['total_paragraphs'] ) ? (int) $cached['total_paragraphs'] : 0
            );

            $tips = array();
            if ( $score < 50 ) {
                $tips[] = '【GEO】内容可提取性偏低，AI 爬虫难以独立理解段落信息';
            }
            if ( ! empty( $cached['tips'] ) ) {
                $tips = array_merge( $tips, array_slice( $cached['tips'], 0, 3 ) );
            }

            return array( 'score' => $score, 'detail' => $detail, 'tips' => $tips );
        }

        return array(
            'score'  => 0,
            'detail' => '尚未执行可提取性分析，保存文章后自动分析',
            'tips'   => array( '【GEO】保存文章后可自动进行可提取性分析' ),
        );
    }

    /**
     * 维度 3：Schema 结构化覆盖（20%）
     * 数据来源：yimai_gso_schema_type post_meta
     *
     * 评分规则：
     *   +40 已设置 Schema 类型
     *   +20 非默认 Article（即手动选择了更合适的类型）
     *   +20 包含 FAQ/HowTo/Product 等高价值 Schema
     *   +20 有自定义 Schema 字段
     */
    private function score_schema_coverage( $post_id ) {
        $schema_type = get_post_meta( $post_id, 'yimai_gso_schema_type', true );
        $score       = 0;
        $detail_parts = array();

        if ( empty( $schema_type ) ) {
            return array(
                'score'  => 0,
                'detail' => '未设置 Schema 类型',
                'tips'   => array( '【GEO】建议为文章设置 Schema 结构化数据类型，AI 引擎依赖 Schema 理解内容结构' ),
            );
        }

        // +40：有 Schema 类型
        $score += 40;
        $detail_parts[] = "类型: {$schema_type}";

        // +20：非默认 Article
        if ( 'Article' !== $schema_type && 'WebPage' !== $schema_type ) {
            $score += 20;
            $detail_parts[] = '已选择高价值类型';
        }

        // +20：FAQ/HowTo/Product 等高价值类型
        $high_value_types = array( 'FAQPage', 'HowTo', 'Product', 'Recipe', 'Review', 'Course', 'JobPosting', 'Event' );
        if ( in_array( $schema_type, $high_value_types, true ) ) {
            $score += 20;
            $detail_parts[] = '高价值Schema类型';
        }

        // +20：检查是否有自定义 Schema 字段（custom_schema_fields meta 或 schema 生成器的自定义数据）
        $custom_schema = get_post_meta( $post_id, 'yimai_gso_custom_schema', true );
        if ( ! empty( $custom_schema ) ) {
            $score += 20;
            $detail_parts[] = '含自定义字段';
        }

        $score  = min( 100, $score );
        $detail = implode( ' · ', $detail_parts );
        $tips   = array();

        if ( $score < 60 ) {
            $tips[] = '【GEO】Schema 覆盖偏低。建议选择 FAQ/HowTo/Product 等更具体的 Schema 类型';
        }
        if ( $score < 80 ) {
            $tips[] = '【GEO】考虑添加自定义 Schema 字段（如 FAQ 问答对、产品属性）以提升丰富度';
        }

        return array( 'score' => $score, 'detail' => $detail, 'tips' => $tips );
    }

    /**
     * 维度 4：AI 爬虫友好度（15%）
     * 数据来源：yimai_gso_robots_crawler_rules option + yimai_gso_noindex meta
     *
     * 评分规则：
     *   +40 未被 noindex
     *   +30 主流 AI 爬虫全部允许
     *   +15 有 llms.txt
     *   +15 有 llms-full.txt
     */
    private function score_crawler_friendly( $post_id ) {
        $score        = 0;
        $detail_parts = array();
        $tips         = array();

        // 1. noindex 检测
        $noindex = get_post_meta( $post_id, 'yimai_gso_noindex', true );
        if ( '1' !== $noindex ) {
            $score += 40;
            $detail_parts[] = '可索引';
        } else {
            $detail_parts[] = '已设置 noindex';
            $tips[] = '【GEO】文章被设置为 noindex，AI 爬虫无法抓取。如非必要建议取消';
            // noindex 的文章直接返回
            return array( 'score' => $score, 'detail' => implode( ' · ', $detail_parts ), 'tips' => $tips );
        }

        // 2. AI 爬虫策略检测
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $rules = YIMAI_GSO_Crawler_Editor::get_rules();

            // 关键 AI 爬虫列表
            $key_crawlers = array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'Google-Extended',
                'ClaudeBot', 'PerplexityBot', 'DeepSeekBot', 'Bytespider' );

            $blocked_count = 0;
            foreach ( $key_crawlers as $bot ) {
                if ( isset( $rules[ $bot ] ) && 'disallow' === $rules[ $bot ] ) {
                    $blocked_count++;
                }
            }

            if ( 0 === $blocked_count ) {
                $score += 30;
                $detail_parts[] = 'AI爬虫全开放';
            } elseif ( $blocked_count <= 2 ) {
                $score += 15;
                $detail_parts[] = "{$blocked_count}个AI爬虫受限";
                $tips[] = '【GEO】部分主流 AI 爬虫被限制，可能影响被 AI 搜索引擎引用';
            } else {
                $detail_parts[] = "{$blocked_count}个AI爬虫受限";
                $tips[] = '【GEO】多个主流 AI 爬虫被限制，严重影响 AI 搜索引擎可见性';
            }
        } else {
            $score += 30; // 无 Crawler_Editor 时默认为全开放
            $detail_parts[] = 'AI爬虫默认开放';
        }

        // 3. llms.txt 检测（使用 transient 缓存，避免每次评分都发 HTTP 请求）
        if ( class_exists( 'YIMAI_GSO_LLMs_Txt_Generator' ) ) {
            $llms_ok = get_transient( 'yimai_gso_llms_txt_available' );
            $full_ok = get_transient( 'yimai_gso_llms_full_available' );

            if ( false === $llms_ok ) {
                $llms_url  = home_url( '/llms.txt' );
                $response  = wp_remote_head( $llms_url, array( 'timeout' => 5, 'sslverify' => false ) );
                $llms_ok   = ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) ? 1 : 0;
                set_transient( 'yimai_gso_llms_txt_available', $llms_ok, HOUR_IN_SECONDS );
            }

            if ( false === $full_ok ) {
                $full_url  = home_url( '/llms-full.txt' );
                $response2 = wp_remote_head( $full_url, array( 'timeout' => 5, 'sslverify' => false ) );
                $full_ok   = ( ! is_wp_error( $response2 ) && 200 === wp_remote_retrieve_response_code( $response2 ) ) ? 1 : 0;
                set_transient( 'yimai_gso_llms_full_available', $full_ok, HOUR_IN_SECONDS );
            }

            if ( $llms_ok ) {
                $score += 15;
                $detail_parts[] = 'llms.txt 可用';
            } else {
                $tips[] = '【GEO】llms.txt 不可访问，建议在「LLMs生成」面板中启用';
            }

            if ( $full_ok ) {
                $score += 15;
                $detail_parts[] = 'llms-full.txt 可用';
            } else {
                $tips[] = '【GEO】llms-full.txt 不可访问，建议启用完整内容导出';
            }
        } else {
            $score += 15; // 无 LLMs 模块时给默认分
            $detail_parts[] = 'llms.txt 模块未加载';
        }

        return array( 'score' => $score, 'detail' => implode( ' · ', $detail_parts ), 'tips' => $tips );
    }

    /**
     * 维度 5：实体覆盖度（15%）
     * 数据来源：yimai_gso_geo_entity_settings option
     *
     * 评分规则：
     *   +30 实体名称 + 类型已设置
     *   +15 Logo 已设置
     *   +15 描述已设置
     *   +20 ≥2 sameAs 链接
     *   +20 ≥4 sameAs 链接
     */
    private function score_entity_coverage( $post_id ) {
        $score        = 0;
        $detail_parts = array();
        $tips         = array();

        if ( ! class_exists( 'YIMAI_GSO_GEO_Entity' ) ) {
            return array(
                'score'  => 0,
                'detail' => '实体模块未加载',
                'tips'   => array( '【GEO】GEO 实体优化模块未启用' ),
            );
        }

        $entity = YIMAI_GSO_GEO_Entity::get_entity_data();

        if ( empty( $entity ) || empty( $entity['entity_name'] ) ) {
            return array(
                'score'  => 0,
                'detail' => '实体信息未配置',
                'tips'   => array( '【GEO】建议在「GEO实体」设置中配置品牌名称和类型，这是 AI 知识图谱的基础' ),
            );
        }

        // +30：名称 + 类型
        if ( ! empty( $entity['entity_name'] ) && ! empty( $entity['entity_type'] ) ) {
            $score += 30;
            $detail_parts[] = "已配置: {$entity['entity_name']} ({$entity['entity_type']})";
        } elseif ( ! empty( $entity['entity_name'] ) ) {
            $score += 15;
            $detail_parts[] = '缺少实体类型';
            $tips[] = '【GEO】建议补充实体类型（Organization/Person/Brand 等）';
        }

        // +15：Logo
        if ( ! empty( $entity['entity_logo'] ) ) {
            $score += 15;
        } else {
            $tips[] = '【GEO】建议上传实体 Logo，AI 平台在展示品牌信息时需要图片';
        }

        // +15：描述
        if ( ! empty( $entity['entity_description'] ) ) {
            $score += 15;
        } else {
            $tips[] = '【GEO】建议填写实体描述，帮助 AI 搜索引擎理解品牌定位';
        }

        // +20/40：sameAs
        $sameas_count = 0;
        if ( ! empty( $entity['same_as'] ) && is_array( $entity['same_as'] ) ) {
            $sameas_count = count( array_filter( $entity['same_as'] ) );
        }

        if ( $sameas_count >= 4 ) {
            $score += 40;
            $detail_parts[] = "{$sameas_count} 个身份验证链接";
        } elseif ( $sameas_count >= 2 ) {
            $score += 20;
            $detail_parts[] = "{$sameas_count} 个身份验证链接";
            $tips[] = '【GEO】sameAs 验证链接偏少（建议 ≥4），更多身份验证可提升 AI 信任度';
        } elseif ( $sameas_count > 0 ) {
            $detail_parts[] = "{$sameas_count} 个身份验证链接";
            $tips[] = '【GEO】sameAs 验证链接不足，建议关联知乎/百度百科/LinkedIn 等平台';
        } else {
            $tips[] = '【GEO】未设置 sameAs 身份验证链接，AI 引擎无法验证实体身份';
        }

        return array( 'score' => $score, 'detail' => implode( ' · ', $detail_parts ), 'tips' => $tips );
    }

    /* ================================================
     * 等级判定
     * ================================================ */

    /**
     * GEO 等级
     */
    private function get_grade( $score ) {
        if ( $score >= 85 ) {
            return array( 'letter' => 'A', 'label' => '优秀', 'color' => '#22c55e' );
        } elseif ( $score >= 70 ) {
            return array( 'letter' => 'B', 'label' => '良好', 'color' => '#3b82f6' );
        } elseif ( $score >= 55 ) {
            return array( 'letter' => 'C', 'label' => '中等', 'color' => '#f59e0b' );
        } elseif ( $score >= 40 ) {
            return array( 'letter' => 'D', 'label' => '较差', 'color' => '#f97316' );
        }
        return array( 'letter' => 'F', 'label' => '很差', 'color' => '#ef4444' );
    }

    /* ================================================
     * 缓存管理
     * ================================================ */

    /**
     * 公共静态方法：读取 GEO 评分缓存（供外部类调用，如 L6 Bridge）
     *
     * @param int $post_id
     * @return array|null
     */
    public static function _xCSd( $post_id ) {
        $instance = self::get_instance();
        return $instance->_xCSd_internal( $post_id );
    }

    /**
     * 内部方法：读取 GEO 评分缓存
     */
    private function _xCSd_internal( $post_id ) {
        $cached = get_post_meta( $post_id, 'yimai_gso_geo_score', true );

        if ( empty( $cached ) || ! is_array( $cached ) ) {
            return null;
        }

        // 超过 24 小时的缓存视为过期
        if ( ! empty( $cached['computed_at'] ) ) {
            $elapsed = current_time( 'timestamp' ) - strtotime( $cached['computed_at'] );
            if ( $elapsed > self::_xX4 ) {
                return null;
            }
        }

        return $cached;
    }

    /**
     * post_save 钩子：清除 GEO 评分缓存
     */
    public function invalidate_cache( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        delete_post_meta( $post_id, 'yimai_gso_geo_score' );
    }

    /* ================================================
     * AJAX 端点：双引擎评分（合并 SEO + GEO）
     * ================================================ */

    /**
     * AJAX: yimai_gso_dual_score
     * 一次请求返回 SEO 评分 + GEO 评分，减少 HTTP 往返
     */
    public function ajax_dual_score() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的文章 ID' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        // SEO 评分（优先读缓存）
        $seo = get_post_meta( $post_id, 'yimai_gso_seo_score', true );
        if ( empty( $seo ) || ! is_array( $seo ) ) {
            if ( class_exists( 'YIMAI_GSO_SEO_Scorer' ) ) {
                try {
                    $seo = YIMAI_GSO_SEO_Scorer::get_instance()->analyze( $post_id );
                } catch ( \Throwable $e ) {
                    error_log( '[一麦GSO] Geo_Scorer → SEO_Scorer.analyze() failed: ' . $e->getMessage() );
                    $seo = array( 'overall' => 0, 'grade' => array( 'letter' => '-', 'label' => 'N/A', 'color' => '#9ca3af' ), 'scores' => array() );
                }
            } else {
                $seo = array( 'overall' => 0, 'grade' => array( 'letter' => '-', 'label' => 'N/A', 'color' => '#9ca3af' ), 'scores' => array() );
            }
        }

        // GEO 评分（优先读缓存，否则首次计算）
        $geo = $this->compute( $post_id );

        wp_send_json_success( array(
            'seo' => $seo,
            'geo' => $geo,
        ) );
    }

    /* ================================================
     * AJAX 端点：SEO-GEO 协同建议
     * ================================================ */

    /**
     * AJAX: yimai_gso_synergy_suggest
     * AI 驱动的双引擎协同优化建议
     */
    public function ajax_synergy_suggest() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的文章 ID' ) );
        }

        // 1. 检查 24h 缓存
        $cache_key = 'yimai_gso_synergy_' . $post_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        // 2. 获取双引擎评分
        $seo = get_post_meta( $post_id, 'yimai_gso_seo_score', true );
        $geo = get_post_meta( $post_id, 'yimai_gso_geo_score', true );

        if ( empty( $geo ) ) {
            $geo = $this->compute( $post_id );
        }

        $seo_overall = is_array( $seo ) && isset( $seo['overall'] ) ? (int) $seo['overall'] : 0;
        $geo_overall = is_array( $geo ) && isset( $geo['overall'] ) ? (int) $geo['overall'] : 0;

        // L6: 允许 SEO-GEO Bridge 注入 SEO 插件数据
        $context = apply_filters( 'yimai_gso_synergy_context', array(), $post_id );

        // 3. 判断是否需要协同建议（分差 > 阈值）
        $gap = abs( $seo_overall - $geo_overall );
        if ( $gap <= self::SYNERGY_GAP_THRESHOLD && $seo_overall > 0 && $geo_overall > 0 ) {
            $result = array(
                'conflict_type' => 'none',
                'seo_score'     => $seo_overall,
                'geo_score'     => $geo_overall,
                'suggestions'   => array( '双引擎评分协调，SEO 和 GEO 优化方向一致，无需协同调整' ),
                'source'        => 'rule',
            );
            set_transient( $cache_key, $result, self::_xX4 );
            wp_send_json_success( $result );
            return;
        }

        // 4. 确定矛盾类型
        $conflict_type = 'balanced';
        if ( $seo_overall >= 75 && $geo_overall <= 55 ) {
            $conflict_type = 'seo_high_geo_low';
        } elseif ( $geo_overall >= 75 && $seo_overall <= 55 ) {
            $conflict_type = 'geo_high_seo_low';
        } elseif ( $seo_overall <= 50 && $geo_overall <= 50 ) {
            $conflict_type = 'both_low';
        }

        // 5. 尝试 AI 生成建议
        $ai_suggestions = $this->generate_ai_synergy( $post_id, $seo, $geo, $conflict_type );

        if ( ! empty( $ai_suggestions ) ) {
            $result = array(
                'conflict_type' => $conflict_type,
                'seo_score'     => $seo_overall,
                'geo_score'     => $geo_overall,
                'suggestions'   => $ai_suggestions,
                'source'        => 'ai',
            );
        } else {
            // 6. AI 不可用时回退规则引擎
            $result = array(
                'conflict_type' => $conflict_type,
                'seo_score'     => $seo_overall,
                'geo_score'     => $geo_overall,
                'suggestions'   => $this->get_rule_based_synergy( $conflict_type, $seo, $geo ),
                'source'        => 'rule',
            );
        }

        set_transient( $cache_key, $result, self::_xX4 );
        wp_send_json_success( $result );
    }

    /**
     * AI 协同建议生成
     */
    private function generate_ai_synergy( $post_id, $seo, $geo, $conflict_type ) {
        // 检查 AI 生成器是否可用
        if ( ! class_exists( 'YIMAI_GSO_AI_Generator' ) ) {
            return array();
        }

        // 检查 API Key 是否配置
        $api_key = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
        if ( empty( $api_key ) ) {
            return array();
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }

        // 收集评分弱项
        $seo_weak = array();
        if ( is_array( $seo ) && ! empty( $seo['scores'] ) ) {
            foreach ( $seo['scores'] as $key => $s ) {
                if ( is_array( $s ) && isset( $s['score'] ) && (int) $s['score'] < 60 ) {
                    $seo_weak[] = isset( $s['level'] ) ? $key : $key;
                }
            }
        }

        $geo_weak = array();
        if ( is_array( $geo ) && ! empty( $geo['dimensions'] ) ) {
            foreach ( $geo['dimensions'] as $key => $d ) {
                if ( isset( $d['score'] ) && (int) $d['score'] < 60 ) {
                    $geo_weak[] = isset( $d['label'] ) ? $d['label'] : $key;
                }
            }
        }

        $seo_score = is_array( $seo ) && isset( $seo['overall'] ) ? (int) $seo['overall'] : 0;
        $geo_score = is_array( $geo ) && isset( $geo['overall'] ) ? (int) $geo['overall'] : 0;

        $content_preview = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 500 );

        $prompt = sprintf(
            "你是 SEO+GEO 双引擎优化专家。分析以下内容并给出协同优化建议。\n\n" .
            "文章标题：%s\n内容片段(前500字)：%s\n\n" .
            "SEO 评分：%d/100，弱项：%s\n" .
            "GEO 评分：%d/100，弱项：%s\n" .
            "矛盾类型：%s\n\n" .
            "请给出 3 条最优先的协同优化建议（SEO 和 GEO 都受益的建议），每条不超过 60 字。\n" .
            "严格按 JSON 数组格式返回：[\"建议1\", \"建议2\", \"建议3\"]",
            $post->post_title,
            $content_preview,
            $seo_score,
            implode( '、', array_slice( $seo_weak, 0, 5 ) ) ?: '无',
            $geo_score,
            implode( '、', array_slice( $geo_weak, 0, 5 ) ) ?: '无',
            $conflict_type
        );

        try {
            $ai = new YIMAI_GSO_AI_Generator();
            $response = $ai->call_api( array(
                array( 'role' => 'user', 'content' => $prompt ),
            ), 300, array( 'temperature' => 0.7 ) );

            if ( is_wp_error( $response ) ) {
                return array();
            }

            // 解析 AI 响应（call_api 返回完整 API 响应数组）
            $content = '';
            if ( is_array( $response ) && isset( $response['choices'][0]['message']['content'] ) ) {
                $content = $response['choices'][0]['message']['content'];
            } elseif ( is_string( $response ) ) {
                $content = $response;
            } elseif ( is_wp_error( $response ) ) {
                return array(); // API 调用失败
            }

            // 清理 markdown 代码块包装
            $content = trim( $content );
            if ( preg_match( '/```(?:json)?\s*(\[.*?\])\s*```/s', $content, $m ) ) {
                $content = $m[1];
            }

            $decoded = json_decode( $content, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }

            // 尝试从文本中提取数组
            if ( preg_match( '/\[.*\]/s', $content, $matches ) ) {
                $decoded = json_decode( $matches[0], true );
                if ( is_array( $decoded ) ) {
                    return $decoded;
                }
            }
        } catch ( Exception $e ) {
            error_log( '[一麦GSO] Geo_Scorer AI synergy call failed: ' . $e->getMessage() );
            return array( 'error' => 'ai_call_failed', 'message' => $e->getMessage() );
        }

        return array();
    }

    /**
     * 规则引擎回退：基于矛盾类型匹配预置建议
     */
    private function get_rule_based_synergy( $conflict_type, $seo, $geo ) {
        $templates = array(
            'seo_high_geo_low' => array(
                'SEO 表现优秀但 GEO 偏低，建议在文章首段加入 1-2 句核心结论，提升 AI 爬虫的答案前置评分',
                '关键词布局良好但内容可提取性不足，建议将长段落拆分为 2-3 句的独立信息块',
                '已有 Schema 但覆盖不够丰富，建议补充 FAQ/HowTo 类型的结构化数据',
            ),
            'geo_high_seo_low' => array(
                '内容结构已优化但传统 SEO 元数据不足，建议完善 SEO 标题和 Meta 描述中的关键词',
                'AI 可提取性高但关键词密度偏低，建议在 H2/H3 标题中自然融入 2-3 个核心关键词',
                '实体覆盖良好但内部链接不足，建议在文章中增加 2-3 个相关文章的内部链接',
            ),
            'both_low' => array(
                '双引擎评分均偏低，建议优先从 SEO 标题优化入手，同时确保文章首段包含核心结论',
                '内容深度和结构同步需提升，建议至少写 800 字以上，并使用 H2/H3 标题组织内容',
                '建议添加至少 2 张相关图片并设置 ALT 标签，同时为文章选择合适的 Schema 类型',
            ),
            'balanced' => array(
                '双引擎评分协调，建议持续关注内容新鲜度，保持文章定期更新以获得持续爬虫关注',
                '可考虑增加数据引用和统计数字作为内容支撑，同时提升 EEAT 信号强度',
                '建议在社交媒体和外部平台推广此内容，增加反向链接和品牌引用机会',
            ),
        );

        return isset( $templates[ $conflict_type ] ) ? $templates[ $conflict_type ] : $templates['balanced'];
    }
}
