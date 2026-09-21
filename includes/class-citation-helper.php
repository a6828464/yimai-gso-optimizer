<?php
/**
 * 引用与数据溯源助手
 *
 * EEAT（专业度）信号的关键组成：
 * 1. 自动检测文章中的数据引用（数字+单位、引用引导词、URL 引用）
 * 2. 生成 Citation Schema JSON-LD
 * 3. 引用来源分级（一级权威 > 二级媒体 > 三级个人）
 * 4. 编辑页侧边栏反馈
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.8.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Citation_Helper {

    private static $instance = null;

    /** 引用引导词 */
    const CITATION_LEADERS = array(
        '根据', '据', '参考', '来源', '引自', '数据来源', '报告显示',
        '研究表明', '调查显示', '统计显示', '数据显示', '实验证明',
        '指出', '证实', '发布', '公布', '披露', '透露',
    );

    /** 数据单位正则 */
    const DATA_PATTERN = '/\d+(?:\.\d+)?[\s]?(?:%|％|万|亿|元|美元|家|个|人|项|次|倍|年|月|天|亿|万)/u';

    /** 权威来源域名（一级） */
    const AUTHORITY_DOMAINS = array(
        '.gov.cn', '.gov', '.edu.cn', '.edu', 'who.int', 'un.org',
        'stats.gov.cn', 'nmpa.gov.cn', 'moe.gov.cn',
    );

    /** 知名媒体域名（二级） */
    const MEDIA_DOMAINS = array(
        'xinhuanet.com', 'people.com.cn', 'cctv.com', 'chinanews.com',
        'caixin.com', 'thepaper.cn', '36kr.com', 'huxiu.com',
        'bloomberg.com', 'reuters.com', 'wsj.com', 'nytimes.com',
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'save_post', array( $this, 'schedule_analysis' ), 25 );
        add_action( 'shutdown', array( $this, 'process_scheduled' ) );
    }

    private static $pending = array();

    public function schedule_analysis( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( class_exists( 'YIMAI_GSO_Post_Type_Helper' ) && ! YIMAI_GSO_Post_Type_Helper::is_supported( $post_id ) ) return;

        self::$pending[] = $post_id;
    }

    public function process_scheduled() {
        foreach ( array_unique( self::$pending ) as $post_id ) {
            $this->analyze( $post_id );
        }
        self::$pending = array();
    }

    /* ================================================
     * 核心分析
     * ================================================ */

    /**
     * 分析文章中的引用
     * @param int $post_id
     * @return array { count, sources, score, has_data_ref, has_url_ref, has_named_ref }
     */
    public function analyze( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return $this->empty_result();

        $content = wp_strip_all_tags( $post->post_content );
        $sources = array();

        // 1. 检测硬数据引用（数字 + 单位）
        if ( preg_match_all( self::DATA_PATTERN, $content, $data_matches ) ) {
            foreach ( array_unique( $data_matches[0] ) as $data_point ) {
                // 找到数据附近的引用引导词
                $position = mb_strpos( $content, $data_point );
                $context  = $position !== false
                    ? mb_substr( $content, max( 0, $position - 50 ), 80 )
                    : '';

                $source_name = $this->extract_source_name( $context );
                $source_url  = $this->extract_url_from_context( $context );

                $sources[] = array(
                    'type'   => 'data',
                    'text'   => $data_point,
                    'context'=> trim( $context ),
                    'source' => $source_name,
                    'url'    => $source_url,
                    'tier'   => $this->classify_tier( $source_name, $source_url ),
                );
            }
        }

        // 2. 检测命名引用（"根据 XX 报告"）
        foreach ( self::CITATION_LEADERS as $leader ) {
            $pattern = '/' . preg_quote( $leader, '/' ) . '\s*(.{5,40}?)(?:[，。；\n]|$)/u';
            if ( preg_match_all( $pattern, $content, $named_matches ) ) {
                foreach ( $named_matches[1] as $named ) {
                    $named = trim( $named );
                    if ( mb_strlen( $named ) >= 5 ) {
                        $position = mb_strpos( $content, $named );
                        $context  = $position !== false
                            ? mb_substr( $content, max( 0, $position - 30 ), 100 )
                            : '';
                        $url = $this->extract_url_from_context( $context );

                        $sources[] = array(
                            'type'   => 'named',
                            'text'   => $leader . ' ' . $named,
                            'context'=> trim( $context ),
                            'source' => $named,
                            'url'    => $url,
                            'tier'   => $this->classify_tier( $named, $url ),
                        );
                    }
                }
            }
        }

        // 3. 检测 URL 引用
        if ( preg_match_all( '/https?:\/\/[^\s<>"\'\]\[，。；]+/u', $post->post_content, $url_matches ) ) {
            foreach ( array_unique( $url_matches[0] ) as $url ) {
                // 排除本站链接
                $home = home_url();
                if ( mb_strpos( $url, $home ) === 0 ) continue;

                $sources[] = array(
                    'type'   => 'url',
                    'text'   => $url,
                    'context'=> '',
                    'source' => parse_url( $url, PHP_URL_HOST ),
                    'url'    => $url,
                    'tier'   => $this->classify_tier( '', $url ),
                );
            }
        }

        // 去重
        $seen  = array();
        $unique = array();
        foreach ( $sources as $s ) {
            $key = md5( $s['source'] . $s['text'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $unique[] = $s;
            }
        }

        // 评分
        $has_data_ref  = false;
        $has_url_ref   = false;
        $has_named_ref = false;

        foreach ( $unique as $s ) {
            if ( 'data' === $s['type'] ) $has_data_ref = true;
            if ( 'url' === $s['type'] ) $has_url_ref = true;
            if ( 'named' === $s['type'] ) $has_named_ref = true;
        }

        $score = 0;
        if ( $has_data_ref ) $score += 30;
        if ( $has_url_ref ) $score += 25;
        if ( $has_named_ref ) $score += 25;
        if ( count( $unique ) >= 3 ) $score += 10;
        if ( count( $unique ) >= 5 ) $score += 10;

        $result = array(
            'count'         => count( $unique ),
            'sources'       => $unique,
            'score'         => $score,
            'has_data_ref'  => $has_data_ref,
            'has_url_ref'   => $has_url_ref,
            'has_named_ref' => $has_named_ref,
            'analyzed_at'   => current_time( 'timestamp' ),
        );

        update_post_meta( $post_id, 'yimai_gso_citations', $result );

        return $result;
    }

    /**
     * 从上下文中提取来源名称
     */
    private function extract_source_name( $context ) {
        foreach ( self::CITATION_LEADERS as $leader ) {
            $pattern = '/' . preg_quote( $leader, '/' ) . '\s*(.{3,30}?)(?:[，。；\n]|$)/u';
            if ( preg_match( $pattern, $context, $m ) ) {
                return trim( $m[1] );
            }
        }
        return '';
    }

    /**
     * 从上下文中提取 URL
     */
    private function extract_url_from_context( $context ) {
        if ( preg_match( '/https?:\/\/[^\s<>"\'\]\[，。；]+/u', $context, $m ) ) {
            return $m[0];
        }
        return '';
    }

    /**
     * 来源分级
     * @return int 1=权威 2=媒体 3=其他
     */
    private function classify_tier( $name, $url ) {
        $host = $url ? parse_url( $url, PHP_URL_HOST ) : '';
        $combined = $name . ' ' . $host;

        // 一级：权威机构
        foreach ( self::AUTHORITY_DOMAINS as $domain ) {
            if ( mb_strpos( $combined, $domain ) !== false ) return 1;
        }

        // 二级：知名媒体
        foreach ( self::MEDIA_DOMAINS as $domain ) {
            if ( mb_strpos( $combined, $domain ) !== false ) return 2;
        }

        return 3;
    }

    /* ================================================
     * Citation Schema 生成
     * ================================================ */

    /**
     * 从已分析的引用数据生成 JSON-LD Schema
     * @param array $citation_data analyze() 的返回结果
     * @return string|null
     */
    public function generate_schema_json_ld( $citation_data ) {
        if ( empty( $citation_data['sources'] ) ) return null;

        $citations = array();
        foreach ( $citation_data['sources'] as $src ) {
            $name = $src['source'] ?: $src['text'];

            // 过滤无效引用：name 为空或为纯数字/量词/碎片文本（如"5个""10%""，优化全靠玄学"）
            if ( ! $this->is_valid_citation_name( $name ) ) {
                continue;
            }

            $citation = array(
                '@type' => 'CreativeWork',
                'name'  => $name,
            );
            if ( ! empty( $src['url'] ) ) {
                $citation['url'] = $src['url'];
            }
            $citations[] = $citation;
        }

        if ( empty( $citations ) ) return null;

        $schema = array(
            '@context'  => 'https://schema.org',
            '@type'     => 'Article',
            'citation'  => $citations,
        );

        return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
    }

    /**
     * 校验引用名称是否有效（非纯数字/量词/碎片文本）
     *
     * @param string $name
     * @return bool
     */
    private function is_valid_citation_name( $name ) {
        $name = trim( (string) $name );
        if ( '' === $name ) {
            return false;
        }

        // 过短（< 3字）且主要为数字/符号 → 无效
        if ( mb_strlen( $name ) < 3 ) {
            return false;
        }

        // 纯量词模式："5个""30家""100%"等
        if ( preg_match( '/^\d+(?:\.\d+)?[\s]?(?:%|％|万|亿|元|美元|家|个|人|项|次|倍|年|月|天)/u', $name ) ) {
            return false;
        }

        // 纯英文/数字/符号组合且无中文（如 "10%""500 users""30 min"）
        if ( ! preg_match( '/[\x{4e00}-\x{9fa5}]/u', $name ) ) {
            $stripped = preg_replace( '/[\d\s\.\,\%\-\@\#\$]/u', '', $name );
            if ( mb_strlen( $stripped ) < 3 ) {
                return false;
            }
        }

        // 以标点/句末碎片开头（如"，优化全靠玄学""。数据不会骗人"）
        if ( preg_match( '/^[，。；、！？,\.;!\?\s]/u', $name ) ) {
            return false;
        }

        return true;
    }

    /* ================================================
     * 查询方法
     * ================================================ */

    /**
     * 获取引用数据（优先缓存）
     */
    public function get_citations( $post_id ) {
        $cached = get_post_meta( $post_id, 'yimai_gso_citations', true );
        if ( ! empty( $cached ) && isset( $cached['count'] ) ) {
            return $cached;
        }
        return $this->analyze( $post_id );
    }

    /* ================================================
     * AJAX
     * ================================================ */

    public function ajax_get_citations() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '无效的文章 ID' ) );
        }

        $result = $this->analyze( $post_id );

        wp_send_json_success( array(
            'citations' => $result,
            'tips'      => $this->generate_tips( $result ),
        ) );
    }

    /* ================================================
     * 建议生成
     * ================================================ */

    private function generate_tips( $data ) {
        $tips = array();

        if ( ! $data['has_data_ref'] ) {
            $tips[] = '建议添加至少 1 处数据引用（如具体数字或统计），增强内容可信度';
        }
        if ( ! $data['has_named_ref'] ) {
            $tips[] = '建议标注数据来源（如"根据XX报告/XX研究"），提升 EEAT 信号';
        }
        if ( $data['count'] >= 3 && $data['count'] < 5 ) {
            $tips[] = '引用数量良好（' . $data['count'] . '处），可考虑增加权威来源引用';
        }
        if ( $data['count'] >= 5 ) {
            $tips[] = '引用充足（' . $data['count'] . '处），内容可信度高';
        }

        return $tips;
    }

    private function empty_result() {
        return array(
            'count'         => 0,
            'sources'       => array(),
            'score'         => 0,
            'has_data_ref'  => false,
            'has_url_ref'   => false,
            'has_named_ref' => false,
            'analyzed_at'   => current_time( 'timestamp' ),
        );
    }
}
