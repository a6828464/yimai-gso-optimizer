<?php
/**
 * AI 爬虫 Robots.txt 可视化编辑器
 *
 * 提供:
 * - 可视化选择 AI 爬虫的 Allow/Disallow 状态
 * - 预置策略模板一键切换
 * - 动态生成 robots.txt 中的 AI 爬虫区块
 *
 * 存储: option wpaip_robots_crawler_rules (JSON)
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_9IIH {

    private static $instance = null;

    /** 28 种 AI 爬虫列表 */
    const CRAWLERS = array(
        'GPTBot'              => 'ChatGPT / OpenAI',
        'ChatGPT-User'        => 'ChatGPT 用户',
        'OAI-SearchBot'       => 'ChatGPT 搜索',
        'Google-Extended'     => 'Google AI Overviews',
        'ClaudeBot'           => 'Claude / Anthropic',
        'PerplexityBot'       => 'Perplexity',
        'DeepSeekBot'         => 'DeepSeek',
        'Bytespider'          => '字节通用爬虫 (字节)',
        'DoubaoBot'           => '豆包 AI采集 (字节)',
        'BingBot'             => 'Bing / Copilot (微软)',
        'Microsoft-Edge-Bot'  => 'Edge AI采集 (微软)',
        'Baiduspider-mobile'  => '百度移动搜索 (百度)',
        'Baiduspider'         => '百度搜索 (百度)',
        'AISpider'            => '文心一言 AI采集 (百度)',
        'Applebot'            => 'Apple Intelligence',
        'CCBot'               => 'Common Crawl',
        'Meta-ExternalFetcher'=> 'Meta AI',
        'YisouSpider'         => '夸克/神马搜索 (阿里)',
        'AlibabaBot'          => '阿里巴巴通用采集 (阿里)',
        'TongyiBot'           => '通义千问 AI训练 (阿里)',
        'Sogou web spider'    => '搜狗搜索',
        '360Spider'           => '360 搜索',
        'KimiBot'             => 'Kimi 训练采集',
        'Kimi-User'           => 'Kimi 用户抓取',
        'Kimi-SearchBot'      => 'Kimi 搜索索引',
        'TencentBot'          => '腾讯通用爬虫',
        'HunyuanBot'          => '混元大模型 (腾讯)',
        'WechatBot'           => '微信搜一搜',
    );

    /** 预置策略模板 */
    const TEMPLATES = array(
        'open_all'     => array( 'label' => '全面开放 AI',       'default' => 'allow' ),
        'mainstream'   => array( 'label' => '仅主流 AI',         'allow' => array( 'GPTBot', 'ChatGPT-User', 'Google-Extended', 'PerplexityBot' ) ),
        'search_only'  => array( 'label' => '仅搜索引擎',        'allow' => array( 'Baiduspider', 'BingBot', 'YisouSpider', 'Sogou web spider', '360Spider', 'Kimi-SearchBot' ) ),
        'block_all'    => array( 'label' => '防御模式',          'default' => 'disallow' ),
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_wpaip_crawler_save_rules', array( $this, 'ajax_save_rules' ) );

        // 注入 robots.txt 生成逻辑
        add_filter( 'wpaip_robots_crawler_block', array( $this, 'render_crawler_block' ), 10, 2 );
    }

    // ==================== 数据层 ====================

    /**
     * 获取当前爬虫规则
     */
    public static function get_rules() {
        $raw = WPAIP_9XCU::get( 'robots_crawler_rules', '' );
        $rules = array();
        if ( ! empty( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $rules = $decoded;
            }
        }
        // 填充默认值
        foreach ( array_keys( self::CRAWLERS ) as $key ) {
            if ( ! isset( $rules[ $key ] ) ) {
                $rules[ $key ] = 'allow';
            }
        }
        return $rules;
    }

    /**
     * 保存爬虫规则
     */
    public static function save_rules( $rules ) {
        $valid = array();
        foreach ( $rules as $key => $action ) {
            if ( isset( self::CRAWLERS[ $key ] ) && in_array( $action, array( 'allow', 'disallow' ), true ) ) {
                $valid[ $key ] = $action;
            }
        }
        WPAIP_9XCU::set( 'robots_crawler_rules', wp_json_encode( $valid, JSON_UNESCAPED_UNICODE ) );
    }

    // ==================== robots.txt 区块生成 ====================

    /**
     * 生成 AI 爬虫区块的文本内容
     * 由 sitemap-generator 的 render_robots() 调用
     */
    public function render_crawler_block( $block, $current ) {
        $rules = self::get_rules();
        $output = "\n# AI 爬虫规则（由 GEO 爬虫编辑器管理）\n";

        foreach ( self::CRAWLERS as $key => $name ) {
            $action = isset( $rules[ $key ] ) ? $rules[ $key ] : 'allow';
            $output .= "User-agent: {$key}\n";
            $output .= ( 'allow' === $action ) ? "Allow: /\n" : "Disallow: /\n";
        }

        return $output;
    }

    // ==================== AJAX ====================

    /**
     * AJAX: 保存爬虫规则
     */
    public function ajax_save_rules() {
        check_ajax_referer( 'wpaip_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        if ( isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ) {
            self::save_rules( $_POST['rules'] );
            wp_send_json_success( array( 'message' => '爬虫规则已保存' ) );
        }

        if ( isset( $_POST['template'] ) ) {
            $template = sanitize_text_field( $_POST['template'] );
            if ( isset( self::TEMPLATES[ $template ] ) ) {
                $def = self::TEMPLATES[ $template ];
                $rules = array();
                if ( isset( $def['default'] ) ) {
                    foreach ( array_keys( self::CRAWLERS ) as $key ) {
                        $rules[ $key ] = $def['default'];
                    }
                } elseif ( isset( $def['allow'] ) ) {
                    foreach ( array_keys( self::CRAWLERS ) as $key ) {
                        $rules[ $key ] = in_array( $key, $def['allow'], true ) ? 'allow' : 'disallow';
                    }
                }
                self::save_rules( $rules );
                wp_send_json_success( array( 'message' => '模板已应用', 'rules' => $rules ) );
            }
        }

        wp_send_json_error( array( 'message' => '参数无效' ) );
    }
}
