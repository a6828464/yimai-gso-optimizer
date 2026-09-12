<?php
/**
 * 22 因子 AI 就绪度审计引擎 — 第4层
 *
 * 整站级别 AI 就绪度扫描，4 大类 22 因子：
 *   技术就绪度 (5因子) · 内容就绪度 (7因子) · 实体就绪度 (6因子) · 爬虫就绪度 (4因子)
 *
 * Content 审计通过 WP-Cron 异步批处理执行，其他 3 类同步完成。
 * 报告存入 option: yimai_gso_geo_audit_report
 *
 * 审计面板入口：GEO优化 → AI就绪度审计
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_GEO_Auditor {

    private static $instance = null;

    /** 批处理大小（每批处理的文章数） */
    const BATCH_SIZE = 50;

    /** 审计因子定义 */
    const FACTORS = array(
        'technical' => array(
            'T1' => array( 'label' => 'HTTPS 启用',          'desc' => '网站是否使用 HTTPS 加密' ),
            'T2' => array( 'label' => '移动端友好',          'desc' => '是否声明 viewport meta 标签' ),
            'T3' => array( 'label' => '页面加载速度',        'desc' => '页面 HTML 总大小是否合理（<150KB）' ),
            'T4' => array( 'label' => 'noindex 配置',        'desc' => 'noindex 页面比例是否合理（<10%）' ),
            'T5' => array( 'label' => 'Canonical 标签',     'desc' => 'Canonical 标签是否存在且正确' ),
        ),
        'content' => array(
            'C1' => array( 'label' => '答案前置率',          'desc' => '平均答案前置评分 ≥ 70 的文章比例' ),
            'C2' => array( 'label' => '内容可提取性',        'desc' => '平均可提取性 ≥ 50 的文章比例' ),
            'C3' => array( 'label' => '内容新鲜度',          'desc' => '超过6个月未更新的文章比例（<20%为优）' ),
            'C4' => array( 'label' => '可读性评分',          'desc' => '平均可读性 ≥ 70 的文章比例' ),
            'C5' => array( 'label' => 'FAQ 结构化',          'desc' => '包含 FAQ Schema 的文章比例' ),
            'C6' => array( 'label' => '标题层级规范',        'desc' => 'H1-H6 层级规范的文章比例' ),
            'C7' => array( 'label' => '关键词覆盖',          'desc' => '有关键词设置的文章比例' ),
        ),
        'entity' => array(
            'E1' => array( 'label' => '组织实体定义',        'desc' => '是否完成 GEO 实体信息配置' ),
            'E2' => array( 'label' => 'sameAs 验证',         'desc' => 'sameAs 身份验证链接数量是否充足（≥4）' ),
            'E3' => array( 'label' => '作者档案覆盖率',      'desc' => '有专业档案的作者比例' ),
            'E4' => array( 'label' => '引用溯源',            'desc' => '包含引用的文章比例' ),
            'E5' => array( 'label' => '内容透明性',          'desc' => '有 AI 辅助标记的文章比例' ),
            'E6' => array( 'label' => '主题分类',            'desc' => '有主题分类的文章比例' ),
        ),
        'crawler' => array(
            'R1' => array( 'label' => 'AI 爬虫 robots.txt',  'desc' => '关键 AI 爬虫未被禁止访问' ),
            'R2' => array( 'label' => 'llms.txt 存在',       'desc' => 'llms.txt 可访问' ),
            'R3' => array( 'label' => '爬虫访问活跃度',      'desc' => '最近30天有 AI 爬虫访问' ),
            'R4' => array( 'label' => 'Sitemap AI 标记',     'desc' => 'Sitemap 含 lastmod/priority 信息' ),
        ),
    );

    /** 因子类别权重（审计总分加权） */
    const CATEGORY_WEIGHTS = array(
        'technical' => 25,
        'content'   => 35,
        'entity'    => 20,
        'crawler'   => 20,
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_run_audit',      array( $this, 'ajax_run_audit' ) );
        add_action( 'wp_ajax_yimai_gso_audit_status',    array( $this, 'ajax_audit_status' ) );
        add_action( 'yimai_gso_audit_content_batch',     array( $this, 'cron_audit_content_batch' ) );
    }

    /* ================================================
     * 主审计入口
     * ================================================ */

    /**
     * AJAX: 开始/重新运行审计
     */
    public function ajax_run_audit() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // 检查是否有进行中的审计（防止并发）
        $existing = get_option( 'yimai_gso_geo_audit_report', array() );
        if ( ! empty( $existing['status'] ) && 'running' === $existing['status'] ) {
            // 取消已有的 content cron，重新开始
            wp_clear_scheduled_hook( 'yimai_gso_audit_content_batch' );
        }

        // 初始化报告骨架
        $report = array(
            'status'    => 'running',
            'started_at'=> current_time( 'mysql' ),
            'completed_at' => null,
            'overall_score' => 0,
            'categories' => array(),
            'top_priorities' => array(),
        );

        // 同步执行：technical / entity / crawler
        $report['categories']['technical'] = $this->audit_technical();
        $report['categories']['entity']    = $this->audit_entity();
        $report['categories']['crawler']   = $this->audit_crawler();

        // 保存中间结果
        update_option( 'yimai_gso_geo_audit_report', $report, false );

        // Content 审计：调度 WP-Cron 批处理
        $total_posts = $this->get_total_post_count();
        update_option( 'yimai_gso_audit_content_progress', array(
            'total'    => $total_posts,
            'processed'=> 0,
            'offset'   => 0,
        ), false );

        if ( $total_posts > 0 ) {
            if ( ! wp_next_scheduled( 'yimai_gso_audit_content_batch' ) ) {
                wp_schedule_single_event( time() + 5, 'yimai_gso_audit_content_batch' );
            }
        } else {
            // 无文章直接完成 content
            $report['categories']['content'] = $this->empty_content_result();
            $report = $this->finalize_report( $report );
        }

        wp_send_json_success( array(
            'message'      => '审计已启动，技术/实体/爬虫检测已完成，内容审计异步进行中',
            'status'       => $report['status'],
            'report'       => $report,
        ) );
    }

    /**
     * AJAX: 查询审计进度
     */
    public function ajax_audit_status() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        $report   = get_option( 'yimai_gso_geo_audit_report', array() );
        $progress = get_option( 'yimai_gso_audit_content_progress', array() );

        wp_send_json_success( array(
            'report'   => $report,
            'progress' => $progress,
        ) );
    }

    /* ================================================
     * 技术就绪度审计（同步，5 因子）
     * ================================================ */

    private function audit_technical() {
        $factors = array();
        $passed  = 0;

        // T1: HTTPS
        $site_url  = site_url();
        $is_https  = ( 0 === strpos( $site_url, 'https://' ) );
        $factors['T1'] = $this->make_factor( 'T1', $is_https ? 100 : 0,
            $is_https ? '网站已启用 HTTPS' : '网站未启用 HTTPS，搜索引擎和 AI 爬虫偏好加密站点' );
        if ( $is_https ) $passed++;

        // T2: 移动端友好
        $has_viewport = false;
        $theme_root   = get_template_directory();
        // 快速检测：查看首页输出
        $front_page = wp_remote_get( home_url( '/' ), array( 'timeout' => 10, 'sslverify' => false ) );
        if ( ! is_wp_error( $front_page ) ) {
            $html = wp_remote_retrieve_body( $front_page );
            $has_viewport = ( false !== stripos( $html, 'viewport' ) );
        }
        $factors['T2'] = $this->make_factor( 'T2', $has_viewport ? 100 : 30,
            $has_viewport ? '已检测到 viewport 声明' : '未检测到 viewport 声明，移动端友好度低' );
        if ( $has_viewport ) $passed++;

        // T3: 页面加载速度（代理：HTML 大小）
        $html_size = ! is_wp_error( $front_page ) ? strlen( $html ) : 0;
        $size_kb   = round( $html_size / 1024, 1 );
        $size_ok   = $html_size > 0 && $html_size < 153600; // <150KB
        $t3_score  = $size_ok ? 100 : ( $html_size > 0 ? max( 20, 100 - floor( ( $html_size - 153600 ) / 10240 ) ) : 0 );
        $factors['T3'] = $this->make_factor( 'T3', $t3_score,
            $html_size > 0 ? "首页 HTML 大小: {$size_kb}KB" . ( $size_ok ? '（正常）' : '（偏大）' ) : '无法获取首页大小' );
        if ( $size_ok ) $passed++;

        // T4: noindex 比例
        $noindex_count = $this->count_noindex_posts();
        $post_count    = $this->get_total_post_count();
        $noindex_ratio = $post_count > 0 ? round( $noindex_count / $post_count * 100, 1 ) : 0;
        $noindex_ok    = $noindex_ratio <= 10;
        $t4_score      = $noindex_ok ? 100 : max( 0, 100 - $noindex_ratio * 5 );
        $factors['T4'] = $this->make_factor( 'T4', $t4_score,
            "noindex 页面: {$noindex_count}/{$post_count}（{$noindex_ratio}%）" . ( $noindex_ok ? '' : '，noindex 占比偏高' ) );
        if ( $noindex_ok ) $passed++;

        // T5: Canonical
        $canonical_count = $this->count_canonical_posts();
        $canonical_ratio = $post_count > 0 ? round( $canonical_count / $post_count * 100, 1 ) : 0;
        $canonical_ok    = $canonical_ratio >= 50;
        $t5_score        = $canonical_ok ? 100 : ( $canonical_ratio >= 30 ? 60 : max( 10, $canonical_ratio ) );
        $factors['T5'] = $this->make_factor( 'T5', $t5_score,
            "有 Canonical 的文章: {$canonical_count}/{$post_count}（{$canonical_ratio}%）" );
        if ( $canonical_ok ) $passed++;

        $cat_score = count( self::FACTORS['technical'] ) > 0
            ? round( ( $passed / count( self::FACTORS['technical'] ) ) * 100 )
            : 0;

        return array(
            'score'   => $cat_score,
            'passed'  => $passed,
            'total'   => count( self::FACTORS['technical'] ),
            'factors' => $factors,
        );
    }

    /* ================================================
     * 内容就绪度审计（WP-Cron 异步批处理，7 因子）
     * ================================================ */

    /**
     * WP-Cron: 处理一批文章
     */
    public function cron_audit_content_batch() {
        $progress = get_option( 'yimai_gso_audit_content_progress', array(
            'total'     => 0,
            'processed' => 0,
            'offset'    => 0,
            'aggregates'=> array(
                'seo_scores'       => array(),
                'extract_scores'   => array(),
                'freshness'        => array(),
                'has_faq_schema'   => 0,
                'has_heading_ok'   => 0,
                'has_keywords'     => 0,
                'count'            => 0,
            ),
        ) );

        $supported_types = array( 'post', 'page' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $supported_types = YIMAI_GSO_Post_Type_Helper::get_supported();
        }

        $posts = get_posts( array(
            'post_type'      => $supported_types,
            'post_status'    => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'offset'         => $progress['offset'],
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        if ( empty( $posts ) ) {
            // 全部处理完成
            $this->finalize_content_audit( $progress );
            return;
        }

        $agg = $progress['aggregates'];

        foreach ( $posts as $post_id ) {
            // C1: 答案前置评分
            $seo = get_post_meta( $post_id, 'yimai_gso_seo_score', true );
            if ( ! empty( $seo['geo']['answer_first']['score'] ) ) {
                $agg['seo_scores'][] = (int) $seo['geo']['answer_first']['score'];
            } elseif ( ! empty( $seo['overall'] ) ) {
                $agg['seo_scores'][] = (int) $seo['overall'];
            }

            // C2: 可提取性
            $extr = get_post_meta( $post_id, 'yimai_gso_extractability', true );
            if ( ! empty( $extr['score'] ) ) {
                $agg['extract_scores'][] = (int) $extr['score'];
            } elseif ( class_exists( 'YIMAI_GSO_Content_Extractability' ) ) {
                // 缓存不存在时实时计算，避免审计结果永远为 0
                $fresh_extr = YIMAI_GSO_Content_Extractability::get_instance()->get_score( $post_id );
                if ( ! empty( $fresh_extr['score'] ) ) {
                    $agg['extract_scores'][] = (int) $fresh_extr['score'];
                }
            }

            // C3: 新鲜度
            $fresh = get_post_meta( $post_id, 'yimai_gso_content_freshness', true );
            if ( ! empty( $fresh['level'] ) ) {
                $agg['freshness'][] = $fresh['level'];
            }

            // C5: FAQ 结构化（内联 FAQ 检测器，避免依赖未触发的 yimai_gso_schema_type）
            if ( class_exists( 'YIMAI_GSO_FAQ_Detector' ) ) {
                $content = get_post_field( 'post_content', $post_id );
                if ( $content ) {
                    $qa_pairs = YIMAI_GSO_FAQ_Detector::get_instance()->detect_faq( $content );
                    if ( ! empty( $qa_pairs ) && count( $qa_pairs ) >= 2 ) {
                        $agg['has_faq_schema']++;
                    }
                }
            } else {
                $schema_type = get_post_meta( $post_id, 'yimai_gso_schema_type', true );
                if ( 'FAQPage' === $schema_type ) {
                    $agg['has_faq_schema']++;
                }
            }

            // C6: 标题层级规范（内联标题分析器，按 score≥60 判规范）
            if ( class_exists( 'YIMAI_GSO_Heading_Analyzer' ) ) {
                $h_res = YIMAI_GSO_Heading_Analyzer::get_instance()->analyze( $post_id );
                if ( isset( $h_res['score'] ) && (int) $h_res['score'] >= 60 ) {
                    $agg['has_heading_ok']++;
                }
            }

            // C7: 关键词
            $keywords = get_post_meta( $post_id, 'yimai_gso_keywords', true );
            if ( ! empty( $keywords ) ) {
                $agg['has_keywords']++;
            }

            $agg['count']++;
        }

        $progress['processed'] += count( $posts );
        $progress['offset']     = $progress['processed'];
        $progress['aggregates'] = $agg;

        update_option( 'yimai_gso_audit_content_progress', $progress, false );

        if ( $progress['processed'] >= $progress['total'] ) {
            $this->finalize_content_audit( $progress );
        } else {
            // 继续下一批
            wp_schedule_single_event( time() + 10, 'yimai_gso_audit_content_batch' );
        }
    }

    /**
     * 完成内容审计，合并结果
     */
    private function finalize_content_audit( $progress ) {
        $agg   = $progress['aggregates'];
        $count = max( 1, (int) $agg['count'] );
        $factors = array();
        $passed  = 0;

        // C1: 答案前置率
        $c1_pass = ! empty( $agg['seo_scores'] ) ? ( count( array_filter( $agg['seo_scores'], function( $s ) { return $s >= 70; } ) ) / count( $agg['seo_scores'] ) * 100 ) : 0;
        $c1_ok   = $c1_pass >= 50;
        $factors['C1'] = $this->make_factor( 'C1', $c1_ok ? 100 : round( $c1_pass * 2 ), round( $c1_pass, 1 ) . '% 的文章答案前置评分 ≥ 70' );
        if ( $c1_ok ) $passed++;

        // C2: 可提取性（阈值由 70 调整为 50，避免真实偏低内容被长期判 0）
        $c2_pass = ! empty( $agg['extract_scores'] ) ? ( count( array_filter( $agg['extract_scores'], function( $s ) { return $s >= 50; } ) ) / count( $agg['extract_scores'] ) * 100 ) : 0;
        $c2_ok   = $c2_pass >= 50;
        $factors['C2'] = $this->make_factor( 'C2', $c2_ok ? 100 : round( $c2_pass * 2 ), round( $c2_pass, 1 ) . '% 的文章可提取性 ≥ 50' );
        if ( $c2_ok ) $passed++;

        // C3: 新鲜度
        $stale_count = ! empty( $agg['freshness'] ) ? count( array_filter( $agg['freshness'], function( $l ) { return in_array( $l, array( 'stale', 'outdated' ) ); } ) ) : 0;
        $stale_ratio = $count > 0 ? ( $stale_count / $count * 100 ) : 0;
        $c3_ok       = $stale_ratio <= 20;
        $factors['C3'] = $this->make_factor( 'C3', $c3_ok ? 100 : max( 0, 100 - $stale_ratio * 3 ), round( $stale_ratio, 1 ) . '% 的文章已过期（>6个月未更新）' );
        if ( $c3_ok ) $passed++;

        // C4: 可读性（使用 SEO 评分的可读性维度）
        $c4_pass = ! empty( $agg['seo_scores'] ) ? ( count( array_filter( $agg['seo_scores'], function( $s ) { return $s >= 70; } ) ) / count( $agg['seo_scores'] ) * 100 ) : 0;
        $c4_ok   = $c4_pass >= 50;
        $factors['C4'] = $this->make_factor( 'C4', $c4_ok ? 100 : round( $c4_pass * 2 ), round( $c4_pass, 1 ) . '% 的文章可读性评分 ≥ 70' );
        if ( $c4_ok ) $passed++;

        // C5: FAQ 结构化
        $faq_ratio = $count > 0 ? ( $agg['has_faq_schema'] / $count * 100 ) : 0;
        $c5_ok     = $faq_ratio >= 10;
        $factors['C5'] = $this->make_factor( 'C5', $c5_ok ? 100 : round( $faq_ratio * 10 ), round( $faq_ratio, 1 ) . '% 的文章有 FAQ Schema' );
        if ( $c5_ok ) $passed++;

        // C6: 标题层级
        $heading_ratio = $count > 0 ? ( $agg['has_heading_ok'] / $count * 100 ) : 0;
        $c6_ok         = $heading_ratio >= 70;
        $factors['C6'] = $this->make_factor( 'C6', $c6_ok ? 100 : round( $heading_ratio * 1.4 ), round( $heading_ratio, 1 ) . '% 的文章标题层级规范' );
        if ( $c6_ok ) $passed++;

        // C7: 关键词覆盖
        $kw_ratio = $count > 0 ? ( $agg['has_keywords'] / $count * 100 ) : 0;
        $c7_ok    = $kw_ratio >= 50;
        $factors['C7'] = $this->make_factor( 'C7', $c7_ok ? 100 : round( $kw_ratio * 2 ), round( $kw_ratio, 1 ) . '% 的文章有关键词设置' );
        if ( $c7_ok ) $passed++;

        $cat_score = count( self::FACTORS['content'] ) > 0
            ? round( ( $passed / count( self::FACTORS['content'] ) ) * 100 )
            : 0;

        $content_result = array(
            'score'   => $cat_score,
            'passed'  => $passed,
            'total'   => count( self::FACTORS['content'] ),
            'factors' => $factors,
            'sample_size' => $count,
        );

        // 合并到总报告
        $report = get_option( 'yimai_gso_geo_audit_report', array() );
        $report['categories']['content'] = $content_result;
        $report = $this->finalize_report( $report );
        update_option( 'yimai_gso_geo_audit_report', $report, false );

        // 清理进度
        delete_option( 'yimai_gso_audit_content_progress' );
    }

    /* ================================================
     * 实体就绪度审计（同步，6 因子）
     * ================================================ */

    private function audit_entity() {
        $factors = array();
        $passed  = 0;

        $entity = array();
        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) ) {
            $entity = YIMAI_GSO_GEO_Entity::get_entity_data();
        }

        // E1: 组织实体定义
        $e1_ok = ! empty( $entity['entity_name'] ) && ! empty( $entity['entity_type'] );
        $factors['E1'] = $this->make_factor( 'E1', $e1_ok ? 100 : 0,
            $e1_ok ? "已配置: {$entity['entity_name']} ({$entity['entity_type']})" : '未配置实体信息' );
        if ( $e1_ok ) $passed++;

        // E2: sameAs 验证
        $sameas_count = ! empty( $entity['same_as'] ) && is_array( $entity['same_as'] )
            ? count( array_filter( $entity['same_as'] ) ) : 0;
        $e2_ok        = $sameas_count >= 4;
        $e2_score     = $sameas_count >= 4 ? 100 : ( $sameas_count >= 2 ? 50 : ( $sameas_count > 0 ? 25 : 0 ) );
        $factors['E2'] = $this->make_factor( 'E2', $e2_score, "{$sameas_count} 个身份验证链接" );
        if ( $e2_ok ) $passed++;

        // E3: 作者档案覆盖率
        $auth_count = $this->count_authors_with_profile();
        $total_auth = $this->count_authors();
        $auth_ratio = $total_auth > 0 ? round( $auth_count / $total_auth * 100, 1 ) : 0;
        $e3_ok      = $auth_ratio >= 50;
        $factors['E3'] = $this->make_factor( 'E3', $e3_ok ? 100 : round( $auth_ratio * 2 ),
            "{$auth_count}/{$total_auth} 位作者有专业档案（{$auth_ratio}%）" );
        if ( $e3_ok ) $passed++;

        // E4: 引用溯源
        $cite_count = $this->count_citation_posts();
        $total      = $this->get_total_post_count();
        $cite_ratio = $total > 0 ? round( $cite_count / $total * 100, 1 ) : 0;
        $e4_ok      = $cite_ratio >= 10;
        $factors['E4'] = $this->make_factor( 'E4', $e4_ok ? 100 : round( $cite_ratio * 10 ),
            "{$cite_count}/{$total} 篇文章含引用（{$cite_ratio}%）" );
        if ( $e4_ok ) $passed++;

        // E5: 内容透明性
        $trans_count = $this->count_transparent_posts();
        $trans_ratio = $total > 0 ? round( $trans_count / $total * 100, 1 ) : 0;
        $e5_ok       = $trans_ratio >= 30;
        $factors['E5'] = $this->make_factor( 'E5', $e5_ok ? 100 : round( $trans_ratio * 3.3 ),
            "{$trans_count}/{$total} 篇文章由 AI 生成（含辅助撰写）（{$trans_ratio}%）" );
        if ( $e5_ok ) $passed++;

        // E6: 主题分类
        $topic_count = $this->count_topic_posts();
        $topic_ratio = $total > 0 ? round( $topic_count / $total * 100, 1 ) : 0;
        $e6_ok       = $topic_ratio >= 20;
        $factors['E6'] = $this->make_factor( 'E6', $e6_ok ? 100 : round( $topic_ratio * 5 ),
            "{$topic_count}/{$total} 篇文章有主题分类（{$topic_ratio}%）" );
        if ( $e6_ok ) $passed++;

        $cat_score = count( self::FACTORS['entity'] ) > 0
            ? round( ( $passed / count( self::FACTORS['entity'] ) ) * 100 )
            : 0;

        return array(
            'score'   => $cat_score,
            'passed'  => $passed,
            'total'   => count( self::FACTORS['entity'] ),
            'factors' => $factors,
        );
    }

    /* ================================================
     * 爬虫就绪度审计（同步，4 因子）
     * ================================================ */

    private function audit_crawler() {
        $factors = array();
        $passed  = 0;

        // R1: AI 爬虫 robots.txt（≤3个被 Disallow 视为通过，每个扣15分，底分10）
        $blocked_count = 0;
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $rules = YIMAI_GSO_Crawler_Editor::get_rules();
            $key_bots = array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'Google-Extended',
                'ClaudeBot', 'PerplexityBot', 'DeepSeekBot', 'Bytespider' );
            foreach ( $key_bots as $bot ) {
                if ( isset( $rules[ $bot ] ) && 'disallow' === $rules[ $bot ] ) {
                    $blocked_count++;
                }
            }
        }
        $r1_ok    = $blocked_count <= 3; // 容忍最多 3 个 AI 爬虫被 disallow
        $r1_score = $r1_ok ? max( 55, 100 - $blocked_count * 15 ) : max( 10, 100 - $blocked_count * 15 );
        $factors['R1'] = $this->make_factor( 'R1', $r1_score,
            $blocked_count > 0
                ? "{$blocked_count}/8 个关键 AI 爬虫被禁止（≤3个视为可接受，超过将降低 AI 可见度）"
                : '关键 AI 爬虫均未被禁止' );
        if ( $r1_ok ) $passed++;

        // R2: llms.txt
        $llms_url  = home_url( '/llms.txt' );
        $response  = wp_remote_head( $llms_url, array( 'timeout' => 5, 'sslverify' => false ) );
        $llms_ok   = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
        $factors['R2'] = $this->make_factor( 'R2', $llms_ok ? 100 : 0,
            $llms_ok ? 'llms.txt 可访问' : 'llms.txt 不可访问' );
        if ( $llms_ok ) $passed++;

        // R3: 爬虫访问活跃度
        $crawler_active = false;
        if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
            $stats = YIMAI_GSO_Crawler_Tracker::get_stats( 30 );
            $crawler_active = ! empty( $stats['total_visits'] ) && (int) $stats['total_visits'] > 0;
        }
        $factors['R3'] = $this->make_factor( 'R3', $crawler_active ? 100 : 30,
            $crawler_active ? '最近30天有 AI 爬虫访问' : '最近30天无 AI 爬虫访问记录' );
        if ( $crawler_active ) $passed++;

        // R4: Sitemap
        $sitemap_ok = false;
        if ( class_exists( 'YIMAI_GSO_Sitemap_Generator' ) ) {
            $sitemap_url = home_url( '/wp-sitemap.xml' );
            $response    = wp_remote_head( $sitemap_url, array( 'timeout' => 5, 'sslverify' => false ) );
            $sitemap_ok  = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
            if ( ! $sitemap_ok ) {
                $sitemap_url = home_url( '/sitemap.xml' );
                $response    = wp_remote_head( $sitemap_url, array( 'timeout' => 5, 'sslverify' => false ) );
                $sitemap_ok  = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
            }
        }
        $factors['R4'] = $this->make_factor( 'R4', $sitemap_ok ? 100 : 0,
            $sitemap_ok ? 'Sitemap 可访问' : 'Sitemap 不可访问' );
        if ( $sitemap_ok ) $passed++;

        $cat_score = count( self::FACTORS['crawler'] ) > 0
            ? round( ( $passed / count( self::FACTORS['crawler'] ) ) * 100 )
            : 0;

        return array(
            'score'   => $cat_score,
            'passed'  => $passed,
            'total'   => count( self::FACTORS['crawler'] ),
            'factors' => $factors,
        );
    }

    /* ================================================
     * 报告最终化
     * ================================================ */

    /**
     * 合并所有类别、计算总分、生成优先级清单
     */
    private function finalize_report( $report ) {
        $report['status'] = 'completed';
        $report['completed_at'] = current_time( 'mysql' );

        // 计算加权总分
        $total_score = 0;
        $total_weight = 0;

        foreach ( self::CATEGORY_WEIGHTS as $cat => $weight ) {
            if ( isset( $report['categories'][ $cat ]['score'] ) ) {
                $total_score  += $report['categories'][ $cat ]['score'] * $weight;
                $total_weight += $weight;
            }
        }

        $report['overall_score'] = $total_weight > 0 ? round( $total_score / $total_weight ) : 0;

        // 生成优先级改进清单（所有未通过的因子，按类别排序）
        $priorities = array();
        $cat_order  = array( 'entity', 'content', 'crawler', 'technical' ); // 优先级顺序：实体 > 内容 > 爬虫 > 技术

        foreach ( $cat_order as $cat_key ) {
            if ( empty( $report['categories'][ $cat_key ]['factors'] ) ) continue;

            foreach ( $report['categories'][ $cat_key ]['factors'] as $fid => $fdata ) {
                if ( $fdata['score'] >= 100 ) continue; // 已通过

                $priorities[] = array(
                    'id'     => $fid,
                    'cat'    => $cat_key,
                    'label'  => $fdata['label'],
                    'detail' => $fdata['detail'],
                    'score'  => $fdata['score'],
                );
            }
        }

        // 按分数升序排列（最低优先）
        usort( $priorities, function( $a, $b ) { return $a['score'] - $b['score']; } );

        $report['top_priorities'] = array_slice( $priorities, 0, 10 );

        update_option( 'yimai_gso_geo_audit_report', $report, false );

        return $report;
    }

    /* ================================================
     * 辅助方法
     * ================================================ */

    private function make_factor( $id, $score, $detail ) {
        $label = '';
        foreach ( self::FACTORS as $cat_factors ) {
            if ( isset( $cat_factors[ $id ] ) ) {
                $label = $cat_factors[ $id ]['label'];
                break;
            }
        }
        return array(
            'id'     => $id,
            'label'  => $label,
            'score'  => max( 0, min( 100, (int) $score ) ),
            'detail' => $detail,
            'pass'   => $score >= 100,
        );
    }

    private function empty_content_result() {
        return array(
            'score'   => 0,
            'passed'  => 0,
            'total'   => count( self::FACTORS['content'] ),
            'factors' => array(),
        );
    }

    private function get_total_post_count() {
        $types = array( 'post', 'page' );
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) ) {
            $types = YIMAI_GSO_Post_Type_Helper::get_supported();
        }
        $count = 0;
        foreach ( $types as $type ) {
            $count_obj = wp_count_posts( $type );
            $count    += isset( $count_obj->publish ) ? (int) $count_obj->publish : 0;
        }
        return $count;
    }

    private function count_noindex_posts() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'yimai_gso_noindex' AND meta_value = '1'" );
    }

    private function count_canonical_posts() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'yimai_gso_canonical' AND meta_value != ''" );
    }

    private function count_authors() {
        $users = count_users();
        return isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;
    }

    private function count_authors_with_profile() {
        global $wpdb;
        // 与管理页口径一致：职务/擅长领域/从业年限 任一非空即视为有档案
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key IN ('yimai_gso_author_title','yimai_gso_author_expertise','yimai_gso_author_years')
             AND meta_value != ''"
        );
    }

    private function count_citation_posts() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'yimai_gso_citations' AND meta_value != '' AND meta_value != '[]'" );
    }

    private function count_transparent_posts() {
        global $wpdb;
        // 改查 publisher 生成文章时写入的 yimai_gso_generated（原 yimai_gso_ai_assisted 无写入端，永远 0）
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'yimai_gso_generated' AND meta_value = '1'" );
    }

    private function count_topic_posts() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'category'" );
    }
}
