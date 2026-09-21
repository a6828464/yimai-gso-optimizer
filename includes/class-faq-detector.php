<?php
/**
 * FAQ 自动识别 + 手风琴短代码
 *
 * 1. 自动从内容中检测 FAQ 问答配对（纯文本模式 + HTML 标签模式）
 * 2. 注册 [yimai_gso_faq] 短代码，支持自动提取和手动指定两种模式
 * 3. 纯 CSS 手风琴渲染，内嵌 FAQPage Schema JSON-LD
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.8.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_FAQ_Detector {

    private static $instance = null;

    /** FAQ 检测关键词 */
    const QA_KEYWORDS = array( '？', '?', '问', '答', 'Q:', 'A:', 'FAQ', '常见', '疑问', '解答', '什么', '如何', '怎么', '为什么', '哪些', '多少' );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'yimai_gso_faq', array( $this, 'render_faq_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_faq_styles' ) );
    }

    /* ================================================
     * FAQ 自动检测
     * ================================================ */

    /**
     * 从内容中检测 FAQ 问答配对
     * @param string $content 文章内容（HTML 或纯文本）
     * @param string $mode    'html' 或 'plain'
     * @return array [{ question, answer, score }]
     */
    public function detect_faq( $content, $mode = 'html' ) {
        if ( 'html' === $mode ) {
            return $this->detect_from_html( $content );
        }
        return $this->detect_from_plain( $content );
    }

    /**
     * 从 HTML 内容检测 FAQ（增强版，比 schema-generator 多纯文本回退）
     */
    private function detect_from_html( $html ) {
        $qa_pairs = array();

        // 方法1：H2/H3 标题 + 后续内容
        if ( preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>(.*?)(?=<h[23]|$)/isu', $html, $blocks, PREG_SET_ORDER ) ) {
            foreach ( $blocks as $block ) {
                $q_text = wp_strip_all_tags( $block[2] );
                $a_html = $block[3];

                if ( $this->is_question( $q_text ) ) {
                    $a_text = wp_strip_all_tags( $a_html );
                    $a_text = trim( preg_replace( '/\s+/', ' ', $a_text ) );

                    if ( ! empty( $a_text ) && mb_strlen( $a_text ) >= 30 ) {
                        $qa_pairs[] = array(
                            'question' => trim( $q_text ),
                            'answer'   => $a_text,
                            'score'    => $this->qa_score( $q_text, $a_text ),
                        );
                    }
                }
            }
        }

        // 方法2：结构化标记 (Q:/A: 或 问:/答:)
        if ( preg_match_all( '/(?:Q:|问:|Q：|问：)\s*(.+?)(?:\n|$).*?(?:A:|答:|A：|答：)\s*(.+?)(?:\n{2,}|$)/isu', wp_strip_all_tags( $html ), $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $q = trim( $m[1] );
                $a = trim( $m[2] );
                if ( ! empty( $q ) && ! empty( $a ) ) {
                    $qa_pairs[] = array(
                        'question' => $q,
                        'answer'   => $a,
                        'score'    => $this->qa_score( $q, $a ),
                    );
                }
            }
        }

        // 如果 HTML 模式未检测到，回退到纯文本模式
        if ( empty( $qa_pairs ) ) {
            $plain = wp_strip_all_tags( $html );
            return $this->detect_from_plain( $plain );
        }

        // 去重 + 按分数排序
        return $this->deduplicate_and_sort( $qa_pairs );
    }

    /**
     * 从纯文本检测 FAQ
     */
    private function detect_from_plain( $text ) {
        $qa_pairs = array();

        // 按双换行分割为段落块
        $blocks = preg_split( '/\n{2,}/', $text );
        $blocks = array_filter( $blocks, function( $b ) {
            return mb_strlen( trim( $b ) ) > 10;
        } );
        $blocks = array_values( $blocks );

        for ( $i = 0; $i < count( $blocks ) - 1; $i++ ) {
            $current = trim( $blocks[ $i ] );
            $next    = trim( $blocks[ $i + 1 ] );

            // 当前块以问号结尾 → 下一个块是答案
            if ( preg_match( '/[？?]$/u', $current ) && ! preg_match( '/[？?]$/u', $next ) ) {
                $q_text = $current;
                $a_text = $next;

                if ( mb_strlen( $a_text ) >= 20 ) {
                    $qa_pairs[] = array(
                        'question' => $q_text,
                        'answer'   => $a_text,
                        'score'    => $this->qa_score( $q_text, $a_text ),
                    );
                }
            }

            // 当前块以 FAQ 关键词开头 + 不是问句 → 下一个块可能是答案
            if ( $this->starts_with_qa_keyword( $current ) && ! preg_match( '/[？?]$/u', $next ) ) {
                $q_text = $current;
                $a_text = $next;

                if ( mb_strlen( $a_text ) >= 30 ) {
                    $qa_pairs[] = array(
                        'question' => $q_text,
                        'answer'   => $a_text,
                        'score'    => $this->qa_score( $q_text, $a_text ) - 5, // 非问句降权
                    );
                }
            }
        }

        return $this->deduplicate_and_sort( $qa_pairs );
    }

    /**
     * 判断文本是否为问题
     */
    private function is_question( $text ) {
        if ( mb_strpos( $text, '?' ) !== false || mb_strpos( $text, '？' ) !== false ) {
            return true;
        }
        return $this->starts_with_qa_keyword( $text );
    }

    /**
     * 判断文本是否以 FAQ 关键词开头
     */
    private function starts_with_qa_keyword( $text ) {
        foreach ( self::QA_KEYWORDS as $kw ) {
            if ( mb_stripos( $text, $kw ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 问答质量评分（0-100）
     */
    private function qa_score( $question, $answer ) {
        $score = 60; // 基准分

        // 问题长度适宜（10-50字）
        $q_len = mb_strlen( $question );
        if ( $q_len >= 10 && $q_len <= 50 ) $score += 10;
        elseif ( $q_len > 50 ) $score -= 5;

        // 答案长度充分（≥50字）
        $a_len = mb_strlen( $answer );
        if ( $a_len >= 100 ) $score += 15;
        elseif ( $a_len >= 50 ) $score += 10;
        elseif ( $a_len < 30 ) $score -= 20;

        // 答案包含具体数据
        if ( preg_match( '/\d+/', $answer ) ) $score += 5;

        // 问题以纯问号结尾
        if ( preg_match( '/[？?]$/u', $question ) ) $score += 5;

        return min( 100, max( 0, $score ) );
    }

    /**
     * 去重 + 按质量分排序 + 限制10组
     */
    private function deduplicate_and_sort( $pairs ) {
        $seen = array();
        $result = array();

        foreach ( $pairs as $pair ) {
            $key = md5( $pair['question'] );
            if ( isset( $seen[ $key ] ) ) {
                // 保留分数更高的
                if ( $pair['score'] > $seen[ $key ]['score'] ) {
                    $result[ array_search( $seen[ $key ], $result, true ) ] = $pair;
                    $seen[ $key ] = $pair;
                }
            } else {
                $seen[ $key ] = $pair;
                $result[] = $pair;
            }
        }

        // 按分数降序
        usort( $result, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        return array_slice( $result, 0, 10 );
    }

    /* ================================================
     * FAQPage Schema 生成
     * ================================================ */

    /**
     * 从已检测的 FAQ 数据生成 JSON-LD Schema
     * @param array $qa_pairs  detect_faq() 返回的数组
     * @return string|null JSON-LD script 标签或 null
     */
    public function generate_schema_json_ld( $qa_pairs ) {
        if ( empty( $qa_pairs ) ) return null;

        $questions = array();
        foreach ( $qa_pairs as $pair ) {
            $questions[] = array(
                '@type'          => 'Question',
                'name'           => $pair['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $pair['answer'],
                ),
            );
        }

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $questions,
        );

        return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
    }

    /* ================================================
     * [yimai_gso_faq] 短代码
     * ================================================ */

    /**
     * 渲染 FAQ 手风琴
     *
     * 用法1 — 自动提取：
     *   [yimai_gso_faq]
     *
     * 用法2 — 手动指定：
     *   [yimai_gso_faq open_first="true"]
     *     [faq_q]问题1？[/faq_q]
     *     [faq_a]答案1[/faq_a]
     *     [faq_q]问题2？[/faq_q]
     *     [faq_a]答案2[/faq_a]
     *   [/yimai_gso_faq]
     *
     * @param array  $atts    属性
     * @param string $content 包裹内容
     * @return string HTML
     */
    public function render_faq_shortcode( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'open_first' => 'false',
        ), $atts, 'yimai_gso_faq' );

        $qa_pairs = array();
        $content  = trim( $content );

        // 模式判断：如果包裹内容包含 [faq_q] 则手动模式，否则自动模式
        if ( has_shortcode( $content, 'faq_q' ) ) {
            // 手动模式
            preg_match_all( '/\[faq_q\](.*?)\[\/faq_q\]\s*\[faq_a\](.*?)\[\/faq_a\]/s', $content, $matches, PREG_SET_ORDER );
            foreach ( $matches as $m ) {
                $qa_pairs[] = array(
                    'question' => trim( $m[1] ),
                    'answer'   => trim( $m[2] ),
                    'score'    => 80, // 手动模式默认高质量
                );
            }
        } else {
            // 自动模式：从当前文章内容检测
            global $post;
            if ( $post ) {
                $qa_pairs = $this->detect_faq( $post->post_content, 'html' );
            }
        }

        if ( empty( $qa_pairs ) ) {
            return '';
        }

        $open_first  = 'true' === $atts['open_first'];
        $html        = '';
        $schema_html = $this->generate_schema_json_ld( $qa_pairs );

        // 输出 Schema
        if ( $schema_html ) {
            $html .= $schema_html . "\n";
        }

        // 渲染手风琴
        $html .= '<div class="yimai-gso-faq-accordion">';

        foreach ( $qa_pairs as $idx => $pair ) {
            $is_open = ( 0 === $idx && $open_first ) ? ' yimai-gso-faq-open' : '';
            $id      = 'yimai-gso-faq-' . $idx . '-' . wp_rand( 1000, 9999 );

            $html .= '<div class="yimai-gso-faq-item' . $is_open . '">';
            $html .= '<div class="yimai-gso-faq-question" onclick="this.parentElement.classList.toggle(\'yimai-gso-faq-open\')">';
            $html .= '<span class="yimai-gso-faq-icon">Q</span>';
            $html .= '<span class="yimai-gso-faq-qtext">' . esc_html( $pair['question'] ) . '</span>';
            $html .= '<span class="yimai-gso-faq-arrow">&#9660;</span>';
            $html .= '</div>';
            $html .= '<div class="yimai-gso-faq-answer">';
            $html .= '<span class="yimai-gso-faq-icon">A</span>';
            $html .= '<div class="yimai-gso-faq-atext">' . wp_kses_post( wpautop( $pair['answer'] ) ) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /* ================================================
     * CSS 样式
     * ================================================ */

    /**
     * 按需加载手风琴 CSS（仅在包含短代码的页面）
     */
    public function enqueue_faq_styles() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'yimai_gso_faq' ) ) {
            return;
        }

        wp_add_inline_style( 'wp-block-library', $this->get_faq_css() );
    }

    /**
     * 返回手风琴 CSS（内联，< 1KB）
     */
    private function get_faq_css() {
        return '
.yimai-gso-faq-accordion {
    margin: 24px 0;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    background: #fff;
}
.yimai-gso-faq-item {
    border-bottom: 1px solid #e5e7eb;
}
.yimai-gso-faq-item:last-child {
    border-bottom: none;
}
.yimai-gso-faq-question {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 18px;
    cursor: pointer;
    background: #f9fafb;
    transition: background .2s;
    user-select: none;
}
.yimai-gso-faq-question:hover {
    background: #f0fdf4;
}
.yimai-gso-faq-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: #059669;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}
.yimai-gso-faq-qtext {
    flex: 1;
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
}
.yimai-gso-faq-arrow {
    font-size: 10px;
    color: #94a3b8;
    transition: transform .25s;
    flex-shrink: 0;
}
.yimai-gso-faq-open .yimai-gso-faq-arrow {
    transform: rotate(180deg);
}
.yimai-gso-faq-answer {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows .3s ease;
}
.yimai-gso-faq-open .yimai-gso-faq-answer {
    grid-template-rows: 1fr;
}
.yimai-gso-faq-answer > * {
    overflow: hidden;
    padding: 0 18px;
}
.yimai-gso-faq-open .yimai-gso-faq-answer > * {
    padding: 16px 18px;
}
.yimai-gso-faq-atext {
    font-size: 14px;
    line-height: 1.8;
    color: #475569;
}
.yimai-gso-faq-atext p {
    margin: 0 0 10px 0;
}
.yimai-gso-faq-atext p:last-child {
    margin-bottom: 0;
}
';
    }

    /* ================================================
     * AJAX 接口
     * ================================================ */

    public function ajax_detect_faq() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '无效的文章 ID' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        $qa_pairs = $this->detect_faq( $post->post_content, 'html' );

        wp_send_json_success( array(
            'count' => count( $qa_pairs ),
            'pairs' => $qa_pairs,
            'has_more' => count( $qa_pairs ) >= 10,
        ) );
    }
}
