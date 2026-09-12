<?php
/**
 * GEO 焦点关键词管理器
 *
 * 提供:
 * - 1-5 个焦点关键词设置
 * - 6 维关键词覆盖分析（标题/H1/H2/首段/URL/ALT）
 * - NLP 关键词推荐（基于 DeepSeek API）
 * - 编辑页 Meta Box 展示
 *
 * 存储: post_meta (yimai_gso_focus_keywords + yimai_gso_keyword_analysis)
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Keyword_Analyzer {

    private static $instance = null;

    /** 最多焦点关键词数量 */
    const MAX_KEYWORDS = 5;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_analyze_keywords', array( $this, 'ajax_analyze_keywords' ) );
        add_action( 'wp_ajax_yimai_gso_save_keywords', array( $this, 'ajax_save_keywords' ) );
        add_action( 'wp_ajax_yimai_gso_suggest_keywords', array( $this, 'ajax_suggest_keywords' ) );
    }

    // ==================== AJAX 关键词分析 ====================

    /**
     * AJAX: 分析关键词覆盖
     */
    public function ajax_analyze_keywords() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $keywords = isset( $_POST['keywords'] ) ? (array) $_POST['keywords'] : array();

        if ( ! $post_id || empty( $keywords ) ) {
            wp_send_json_error( '参数不全' );
        }

        $post    = get_post( $post_id );
        $title   = get_the_title( $post_id );
        $content = $post ? $post->post_content : '';
        $plain   = wp_strip_all_tags( $content );
        $html    = $content;
        $slug    = $post ? $post->post_name : '';
        $url     = get_permalink( $post_id );

        // 取首段 (前300字)
        $first_para = '';
        if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $html, $m ) ) {
            $first_para = wp_strip_all_tags( $m[1] );
        }
        if ( empty( $first_para ) ) {
            $first_para = mb_substr( $plain, 0, 300 );
        }

        // 取 H1/H2
        $h1_text = '';
        if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
            $h1_text = wp_strip_all_tags( $m[1] );
        }

        $h2_texts = array();
        if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $html, $m ) ) {
            $h2_texts = array_map( 'wp_strip_all_tags', $m[1] );
        }

        // ALT 文本
        $alt_texts = array();
        if ( preg_match_all( '/alt=["\'](.*?)["\']/is', $html, $m ) ) {
            $alt_texts = $m[1];
        }

        // 主关键词（取第一个）
        $primary = reset( $keywords );
        $results = array();

        // 6 维分析
        $results[] = $this->check_dim( '标题', $primary, $title, $title );
        $results[] = $this->check_dim( 'H1', $primary, $h1_text, '?' );
        $results[] = $this->check_dim( 'H2', $primary, implode( ' ', $h2_texts ), (string) count( $h2_texts ) . '个H2' );
        $results[] = $this->check_dim( '首段', $primary, $first_para, '前300字' );
        $results[] = $this->check_dim( 'URL', $primary, $slug, $url );
        $results[] = $this->check_dim( 'ALT', $primary, implode( ' ', $alt_texts ), (string) count( $alt_texts ) . '张图片' );

        // 密度分析
        $word_count = mb_strlen( $plain );
        $kw_count   = mb_substr_count( $plain, $primary );
        $density    = $word_count > 0 ? round( ( $kw_count * mb_strlen( $primary ) / $word_count ) * 100, 1 ) : 0;
        $density_status = ( $density >= 1.5 && $density <= 5 ) ? 'good' : ( ( $density > 0 && $density < 10 ) ? 'ok' : 'bad' );
        array_unshift( $results, array(
            'label'  => '密度',
            'status' => $density_status,
            'detail' => $density . '% (建议 1.5-5%)',
        ) );

        // 保存
        update_post_meta( $post_id, 'yimai_gso_focus_keywords', $keywords );
        update_post_meta( $post_id, 'yimai_gso_keyword_analysis', $results );

        // 渲染 HTML
        ob_start();
        $this->render_analysis_results( $results );
        $html_result = ob_get_clean();

        wp_send_json_success( array(
            'html'      => $html_result,
            'results'   => $results,
            'density'   => $density,
        ) );
    }

    /**
     * 单维度检查
     */
    private function check_dim( $label, $keyword, $text, $detail ) {
        $found = ( mb_stripos( $text, $keyword ) !== false );
        return array(
            'label'  => $label,
            'status' => $found ? 'good' : 'bad',
            'detail' => $found ? "已出现" : "未出现 ($detail)",
        );
    }

    /**
     * AJAX: 保存关键词
     */
    public function ajax_save_keywords() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $keywords = isset( $_POST['keywords'] ) ? (array) $_POST['keywords'] : array();
        $keywords = array_values( array_filter( array_map( 'sanitize_text_field', $keywords ) ) );

        if ( $post_id ) {
            update_post_meta( $post_id, 'yimai_gso_focus_keywords', array_slice( $keywords, 0, self::MAX_KEYWORDS ) );
        }

        wp_send_json_success( array( 'message' => '关键词已保存' ) );
    }

    /**
     * AJAX: AI 推荐关键词
     */
    public function ajax_suggest_keywords() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( '无效文章' );
        }

        // 使用缓存
        $cache_key = 'yimai_gso_kw_suggest_' . $post_id;
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            wp_send_json_success( array( 'keywords' => $cached ) );
        }

        $post    = get_post( $post_id );
        $title   = get_the_title( $post_id );
        $content = wp_strip_all_tags( $post->post_content );
        $sample  = mb_substr( $content, 0, 2000 );

        // 调用 AI 推荐（使用已有的 DeepSeek 通道）
        $suggested = $this->call_ai_suggest( $title, $sample );
        if ( ! empty( $suggested ) ) {
            set_transient( $cache_key, $suggested, DAY_IN_SECONDS );
            wp_send_json_success( array( 'keywords' => $suggested ) );
        }

        wp_send_json_error( 'AI 推荐失败' );
    }

    /**
     * 调用 AI API 推荐关键词
     */
    private function call_ai_suggest( $title, $sample ) {
        $api_key = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
        $api_url = YIMAI_GSO_Settings::get( 'ai_api_url', '' );
        $model   = YIMAI_GSO_Settings::get( 'ai_model', 'gpt-4o' );

        if ( empty( $api_key ) || empty( $api_url ) ) {
            return null;
        }

        $prompt = '请为以下文章推荐3-5个核心SEO关键词（简短、精确、每词不超过8个字），以JSON数组格式返回，如 ["关键词1","关键词2"]。\n\n标题：' . $title . '\n内容摘要：' . $sample;

        $response = wp_remote_post( $api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'model'    => $model,
                'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
                'max_tokens' => 100,
                'temperature'=> 0.5,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['choices'][0]['message']['content'] ?? '';

        // 从 AI 返回中提取 JSON 数组
        if ( preg_match( '/\[.*?\]/s', $text, $m ) ) {
            $arr = json_decode( $m[0], true );
            if ( is_array( $arr ) ) {
                return array_slice( array_filter( array_map( 'trim', $arr ) ), 0, self::MAX_KEYWORDS );
            }
        }

        return null;
    }
}
