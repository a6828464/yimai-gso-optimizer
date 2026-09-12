<?php
/**
 * 内容可提取性评分引擎
 * 段落级分析：AI 搜索引擎是否可将每个段落独立提取为有效信息
 *
 * 4 维评分：语义完整性(40%) + 信息自足性(30%) + 长度适宜性(20%) + 结构友好性(10%)
 * 总分 0-100，段落独立打分后加权平均得文章总分
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.8.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Content_Extractability {

    private static $instance = null;

    /** 依赖上文代词列表 */
    const CONTEXT_PRONOUNS = array( '这', '那', '其', '该', '此', '前者', '后者', '它', '他', '她', '它们', '他们', '她们' );

    /** 指代词开头的过渡短语 */
    const TRANSITION_PHRASES = array( '如上所述', '基于此', '因此', '所以', '然而', '换句话说', '换言之', '由此可见', '综上所述', '有鉴于此', '正因如此', '与此同时' );

    /** 最优段落长度范围（中文字符） */
    const OPTIMAL_MIN = 80;
    const OPTIMAL_MAX = 200;
    const TOO_LONG     = 300;
    const TOO_SHORT    = 40;

    /** 首段依赖词 */
    const DEPENDENCY_WORDS = array( '例如', '比如', '此外', '另外', '而且', '并且', '同样', '同时', '再者' );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'save_post', array( $this, 'schedule_analysis' ), 20 );
        add_action( 'shutdown', array( $this, 'process_scheduled' ) );
        add_action( 'wp_ajax_yimai_gso_get_extractability', array( $this, 'ajax_get_extractability' ) );
    }

    /* ================================================
     * 调度与缓存
     * ================================================ */

    /** 待分析文章队列（shutdown 批量处理） */
    private static $pending = array();

    /**
     * save_post 钩子：将文章加入待分析队列
     */
    public function schedule_analysis( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) && ! YIMAI_GSO_Post_Type_Helper::is_supported( $post_id ) ) return;

        self::$pending[] = $post_id;
    }

    /**
     * shutdown 钩子：批量处理待分析文章（异步，不阻塞保存）
     */
    public function process_scheduled() {
        foreach ( array_unique( self::$pending ) as $post_id ) {
            $this->analyze( $post_id );
        }
        self::$pending = array();
    }

    /* ================================================
     * 核心分析引擎
     * ================================================ */

    /**
     * 分析单篇文章的可提取性
     * @param int $post_id
     * @return array { score, level, paragraphs, total_paragraphs, extractable_count, tips }
     */
    public function analyze( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return $this->empty_result();

        $content = $post->post_content;
        $content = wp_strip_all_tags( $content, true );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // 按段落分割（双换行或 HTML 段落标记转换后的双换行）
        $paragraphs = preg_split( '/\n{2,}/', $content );
        $paragraphs = array_filter( $paragraphs, function( $p ) {
            $trimmed = trim( $p );
            return mb_strlen( $trimmed ) > 5;
        } );

        if ( empty( $paragraphs ) ) return $this->empty_result();

        $results   = array();
        $total_ps  = count( $paragraphs );
        $ext_count = 0;

        foreach ( $paragraphs as $idx => $para ) {
            $score   = $this->score_paragraph( $para, $idx, $total_ps );
            $results[] = $score;

            if ( $score['score'] >= 60 ) {
                $ext_count++;
            }
        }

        // 加权平均（越靠前的段落权重越高）
        $total_score = 0;
        $total_weight = 0;

        foreach ( $results as $idx => $r ) {
            // 前 3 段权重更高（AI 优先关注开头）
            $weight = ( $idx < 3 && $total_ps > 3 ) ? 1.5 : 1.0;
            $total_score  += $r['score'] * $weight;
            $total_weight += $weight;
        }

        $final_score  = $total_weight > 0 ? round( $total_score / $total_weight ) : 0;
        $final_score  = max( 0, min( 100, $final_score ) );

        // 等级判定
        if ( $final_score >= 80 ) {
            $level = 'excellent';
        } elseif ( $final_score >= 60 ) {
            $level = 'good';
        } elseif ( $final_score >= 40 ) {
            $level = 'ok';
        } else {
            $level = 'poor';
        }

        // 收集改进建议
        $tips = array();
        $low_count = 0;
        foreach ( $results as $idx => $r ) {
            if ( $r['score'] < 50 ) {
                $low_count++;
            }
            foreach ( $r['issues'] as $issue ) {
                $tips[] = sprintf( '第 %d 段：%s', $idx + 1, $issue );
            }
        }

        if ( $low_count > 0 && $low_count / $total_ps > 0.3 ) {
            array_unshift( $tips, sprintf( '共 %d/%d 段可提取性较低，建议逐段优化', $low_count, $total_ps ) );
        }

        $result = array(
            'score'             => $final_score,
            'level'             => $level,
            'paragraphs'        => $results,
            'total_paragraphs'  => $total_ps,
            'extractable_count' => $ext_count,
            'tips'              => array_slice( $tips, 0, 5 ),
            'analyzed_at'       => current_time( 'timestamp' ),
        );

        update_post_meta( $post_id, 'yimai_gso_extractability', $result );

        return $result;
    }

    /**
     * 对单个段落评分
     * @param string $para  段落文本
     * @param int    $idx   段落序号（从0开始）
     * @param int    $total 总段落数
     * @return array { score, issues, text_preview }
     */
    private function score_paragraph( $para, $idx, $total ) {
        $issues  = array();
        $para    = trim( $para );
        $length  = mb_strlen( $para );

        // ---------- 1. 语义完整性 (40%) ---------
        $sem_score = 40;
        $first_char = mb_substr( $para, 0, 1 );

        // 检测以依赖上文代词开头
        if ( in_array( $first_char, self::CONTEXT_PRONOUNS, true ) ) {
            $sem_score -= 15;
            $issues[] = '以代词开头，依赖上文语境';
        }

        // 检测以过渡短语开头
        foreach ( self::TRANSITION_PHRASES as $phrase ) {
            if ( mb_strpos( $para, $phrase ) === 0 ) {
                $sem_score -= 10;
                $issues[] = '以过渡词开头，信息不独立';
                break;
            }
        }

        // 检测以依赖词开头（如"例如""此外"）
        foreach ( self::DEPENDENCY_WORDS as $word ) {
            if ( mb_strpos( $para, $word ) === 0 ) {
                $sem_score -= 8;
                $issues[] = '以衔接词开头，依赖上文信息';
                break;
            }
        }

        // 检测是否缺少主语（无名词短语，纯粹动宾结构）
        $has_subject = preg_match( '/[\x{4e00}-\x{9fa5}]{2,}(?:是|在|将|已|可|能|会|要|有|为|对|给|把|被|让|由|向|从|到|于|与|和|同|跟)/u', $para );
        if ( ! $has_subject && $length > 20 ) {
            $sem_score -= 10;
            $issues[] = '可能缺少独立主语';
        }

        $sem_score = max( 0, $sem_score );

        // ---------- 2. 信息自足性 (30%) ---------
        $info_score = 30;

        // 检测是否包含具体信息（数字、百分比、时间、地点）
        $has_number   = preg_match( '/\d+/', $para );
        $has_percent  = preg_match( '/\d+[\s]?[%％]|百分之/', $para );
        $has_date     = preg_match( '/\d{4}年|\d{1,2}月\d{1,2}日|近年|目前|当前/', $para );
        $has_location = preg_match( '/[\x{4e00}-\x{9fa5}]{2,}(?:地区|城市|省|市|县|国|市场|行业)/u', $para );

        $concrete_count = ( $has_number ? 1 : 0 ) + ( $has_percent ? 1 : 0 ) + ( $has_date ? 1 : 0 ) + ( $has_location ? 1 : 0 );

        if ( $concrete_count === 0 && $length > 50 ) {
            $info_score -= 12;
            $issues[] = '缺少具体数据或事实信息';
        } elseif ( $concrete_count === 1 ) {
            $info_score -= 5;
        }

        // 检测是否仅是过渡句（纯连接功能，无实际内容）
        $transition_only = preg_match( '/^(此外|另外|同时|不仅如此|更重要的是|需要说明的是|值得注意的是|有趣的是|事实上|实际上|换言之|总之)/', $para );
        if ( $transition_only && $length < 60 ) {
            $info_score -= 15;
            $issues[] = '仅包含过渡内容，无实质信息';
        }

        $info_score = max( 0, $info_score );

        // ---------- 3. 长度适宜性 (20%) ---------
        $len_score = 20;

        if ( $length > self::TOO_LONG ) {
            $len_score = 5;
            $issues[] = sprintf( '段落过长（%d字），AI 提取时易被截断', $length );
        } elseif ( $length > self::OPTIMAL_MAX ) {
            $len_score = 12;
        } elseif ( $length < self::TOO_SHORT ) {
            $len_score = 8;
            $issues[] = '段落过短，信息量不足';
        } elseif ( $length < self::OPTIMAL_MIN ) {
            $len_score = 15;
        }
        // 80-200 区间得满分 20

        // ---------- 4. 结构友好性 (10%) ---------
        $struct_score = 10;

        // 检测问答结构（问号 + 紧接着的陈述）
        $has_qa = preg_match( '/[？?]/u', $para ) && $length > 30;
        if ( $has_qa ) {
            $struct_score += 2; // 奖励问答模式
        }

        // 检测是否包含列表/分点
        $has_list = preg_match( '/[1-9一二三四五六七八九十][、.．)）]/u', $para );
        if ( $has_list ) {
            $struct_score += 2;
        }

        $struct_score = min( 10, $struct_score );

        // ---------- 综合 ---------
        $total = $sem_score * 0.4 + $info_score * 0.3 + $len_score * 0.2 + $struct_score * 0.1;
        $total = round( $total );

        $preview = mb_strlen( $para ) > 60 ? mb_substr( $para, 0, 60 ) . '...' : $para;

        return array(
            'score'        => $total,
            'issues'       => $issues,
            'text_preview' => $preview,
            'length'       => $length,
        );
    }

    /* ================================================
     * 公开查询方法
     * ================================================ */

    /**
     * 获取文章的可提取性评分（优先读缓存）
     */
    public function get_score( $post_id ) {
        $cached = get_post_meta( $post_id, 'yimai_gso_extractability', true );
        if ( ! empty( $cached ) && isset( $cached['score'] ) ) {
            return $cached;
        }
        return $this->analyze( $post_id );
    }

    /**
     * 获取纯分数（0-100），供 SEO Scorer 调用
     */
    public function get_numeric_score( $post_id ) {
        $data = $this->get_score( $post_id );
        return isset( $data['score'] ) ? (int) $data['score'] : 0;
    }

    /* ================================================
     * AJAX 接口
     * ================================================ */

    public function ajax_get_extractability() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '无效的文章 ID' ) );
        }

        $result = $this->analyze( $post_id );
        wp_send_json_success( $result );
    }

    /* ================================================
     * 辅助方法
     * ================================================ */

    private function empty_result() {
        return array(
            'score'             => 0,
            'level'             => 'poor',
            'paragraphs'        => array(),
            'total_paragraphs'  => 0,
            'extractable_count' => 0,
            'tips'              => array(),
            'analyzed_at'       => current_time( 'timestamp' ),
        );
    }
}
