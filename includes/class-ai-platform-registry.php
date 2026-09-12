<?php
/**
 * AI 平台注册表 — 12 个主流 AI 平台的元数据配置
 *
 * 纯数据常量类，零依赖、零副作用，无条件加载。
 * WPAIP_P40M 从本注册表读取平台配置后按需调度适配逻辑。
 *
 * 新增/修改平台配置只需改本文件中的 PLATFORMS 常量数组。
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_H402 {

    /**
     * 12 个 AI 平台完整配置
     *
     * 每条记录字段说明：
     * - key:            内部唯一标识
     * - name:           显示名称
     * - bots:           爬虫 User-Agent 标识列表
     * - priority:       P0（核心）/ P1（扩展）
     * - adaptations:    适配策略配置
     *   - llms_txt:        是否在 llms.txt 中增加平台专用区块
     *   - schema_priority: Schema 类型优先级列表（按顺序）
     *   - content_format:  内容格式偏好标记
     *   - meta_tags:       是否输出 AI 专属 meta 标签
     *   - citation_style:  引用格式偏好
     *   - eeat_required:   是否需要 EEAT 作者信号增强
     *   - entity_granularity: 实体粒度偏好
     * - css_class:      后台图标 CSS 类名
     * - enabled:        默认启用状态
     * - note:           平台注意事项
     */
    const PLATFORMS = array(

        // ===== P0 核心平台（6个）=====

        'chatgpt' => array(
            'key'         => 'chatgpt',
            'name'        => 'ChatGPT / OpenAI',
            'bots'        => array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot' ),
            'priority'    => 'p0',
            'adaptations' => array(
                'llms_txt'           => true,
                'schema_priority'    => array( 'Article', 'FAQPage' ),
                'content_format'     => 'structured_en',
                'meta_tags'          => true,
                'citation_style'     => 'inline',
                'eeat_required'      => false,
                'entity_granularity' => 'global',
            ),
            'css_class'   => 'platform-icon-chatgpt',
            'enabled'     => true,
            'note'        => '偏好英文结构化内容、FAQ 格式、清晰段落分隔',
        ),

        'gemini' => array(
            'key'         => 'gemini',
            'name'        => 'Google Gemini',
            'bots'        => array( 'Google-Extended', 'Gemini' ),
            'priority'    => 'p0',
            'adaptations' => array(
                'llms_txt'           => true,
                'schema_priority'    => array( 'Product', 'Recipe', 'HowTo', 'Article' ),
                'content_format'     => 'google_spec',
                'meta_tags'          => true,
                'citation_style'     => 'structured',
                'eeat_required'      => true,
                'entity_granularity' => 'detailed',
            ),
            'css_class'   => 'platform-icon-gemini',
            'enabled'     => true,
            'note'        => 'Google 搜索生态、EEAT 权重高、结构化标记完整度优先',
        ),

        'deepseek' => array(
            'key'         => 'deepseek',
            'name'        => 'DeepSeek',
            'bots'        => array( 'DeepSeekBot' ),
            'priority'    => 'p0',
            'adaptations' => array(
                'llms_txt'           => true,
                'schema_priority'    => array( 'Article' ),
                'content_format'     => 'chinese_deep',
                'meta_tags'          => true,
                'citation_style'     => 'inline',
                'eeat_required'      => false,
                'entity_granularity' => 'detailed',
            ),
            'css_class'   => 'platform-icon-deepseek',
            'enabled'     => true,
            'note'        => '偏好详实中文长文、技术分层结构、知识密度高',
        ),

        'doubao' => array(
            'key'         => 'doubao',
            'name'        => '豆包（字节）',
            'bots'        => array( 'Bytespider' ),
            'priority'    => 'p0',
            'adaptations' => array(
                'llms_txt'           => true,
                'schema_priority'    => array( 'LocalBusiness', 'Product' ),
                'content_format'     => 'short_blocks',
                'meta_tags'          => true,
                'citation_style'     => 'minimal',
                'eeat_required'      => false,
                'entity_granularity' => 'local',
            ),
            'css_class'   => 'platform-icon-doubao',
            'enabled'     => true,
            'note'        => '偏好本地化信息、短平快信息块、微博/头条生态',
        ),

        'claude' => array(
            'key'         => 'claude',
            'name'        => 'Claude (Anthropic)',
            'bots'        => array( 'ClaudeBot', 'Claude-SearchBot' ),
            'priority'    => 'p0',
            'adaptations' => array(
                'llms_txt'           => true,
                'schema_priority'    => array( 'Article', 'ScholarlyArticle' ),
                'content_format'     => 'academic_longform',
                'meta_tags'          => true,
                'citation_style'     => 'academic',
                'eeat_required'      => true,
                'entity_granularity' => 'detailed',
            ),
            'css_class'   => 'platform-icon-claude',
            'enabled'     => true,
            'note'        => '偏好明确数据来源、学术引用格式、严谨论证结构',
        ),

        'perplexity' => array(
            'key'         => 'perplexity',
            'name'        => 'Perplexity',
            'bots'        => array( 'PerplexityBot' ),
            'priority'    => 'p0',
            'adaptations' => array(
                'llms_txt'           => true,
                'schema_priority'    => array( 'Article', 'FAQPage' ),
                'content_format'     => 'citation_rich',
                'meta_tags'          => true,
                'citation_style'     => 'explicit_url',
                'eeat_required'      => true,
                'entity_granularity' => 'source_level',
            ),
            'css_class'   => 'platform-icon-perplexity',
            'enabled'     => true,
            'note'        => '偏好有数据支撑的结论、可溯源引用、来源权威性',
        ),

        // ===== P1 扩展平台（4个）=====

        'yuanbao' => array(
            'key'         => 'yuanbao',
            'name'        => '腾讯元宝',
            'bots'        => array(), // 检测中
            'priority'    => 'p1',
            'adaptations' => array(
                'llms_txt'           => false,
                'schema_priority'    => array( 'Article' ),
                'content_format'     => 'wechat_style',
                'meta_tags'          => true,
                'citation_style'     => 'authority_source',
                'eeat_required'      => true,
                'entity_granularity' => 'global',
            ),
            'css_class'   => 'platform-icon-yuanbao',
            'enabled'     => false,
            'note'        => '微信公众号生态、权威信源策略、可信来源优先（爬虫标识检测中）',
        ),

        'baidu_wenxin' => array(
            'key'         => 'baidu_wenxin',
            'name'        => '百度文心一言',
            'bots'        => array( 'Baiduspider' ),
            'priority'    => 'p1',
            'adaptations' => array(
                'llms_txt'           => false,
                'schema_priority'    => array( 'Article', 'LocalBusiness' ),
                'content_format'     => 'baidu_spec',
                'meta_tags'          => true,
                'citation_style'     => 'authority_source',
                'eeat_required'      => false,
                'entity_granularity' => 'local',
            ),
            'css_class'   => 'platform-icon-baidu',
            'enabled'     => false,
            'note'        => '百度结构化数据规范、政府/行业协会权威源、本地化权威内容',
        ),

        'kimi' => array(
            'key'         => 'kimi',
            'name'        => 'Kimi（月之暗面）',
            'bots'        => array(), // 检测中
            'priority'    => 'p1',
            'adaptations' => array(
                'llms_txt'           => false,
                'schema_priority'    => array( 'Article' ),
                'content_format'     => 'longform_deep',
                'meta_tags'          => true,
                'citation_style'     => 'inline',
                'eeat_required'      => false,
                'entity_granularity' => 'detailed',
            ),
            'css_class'   => 'platform-icon-kimi',
            'enabled'     => false,
            'note'        => '偏好超级长文、多段落层级、知识密集型（爬虫标识检测中）',
        ),

        'copilot' => array(
            'key'         => 'copilot',
            'name'        => '微软 Copilot',
            'bots'        => array( 'BingBot' ),
            'priority'    => 'p1',
            'adaptations' => array(
                'llms_txt'           => false,
                'schema_priority'    => array( 'FAQPage', 'HowTo', 'Article' ),
                'content_format'     => 'bing_structure',
                'meta_tags'          => true,
                'citation_style'     => 'structured',
                'eeat_required'      => false,
                'entity_granularity' => 'global',
            ),
            'css_class'   => 'platform-icon-copilot',
            'enabled'     => false,
            'note'        => 'Bing 搜索生态、结构化数据优先、FAQ/HowTo 格式',
        ),
    );

    // ==================== 查询方法 ====================

    /**
     * 获取所有平台配置
     *
     * @return array
     */
    public static function get_all() {
        return self::PLATFORMS;
    }

    /**
     * 获取单个平台配置
     *
     * @param string $key 平台 key
     * @return array|null
     */
    public static function get( $key ) {
        return isset( self::PLATFORMS[ $key ] ) ? self::PLATFORMS[ $key ] : null;
    }

    /**
     * 获取所有 P0 平台 key 列表
     *
     * @return array
     */
    public static function get_p0_keys() {
        $keys = array();
        foreach ( self::PLATFORMS as $platform ) {
            if ( 'p0' === $platform['priority'] ) {
                $keys[] = $platform['key'];
            }
        }
        return $keys;
    }

    /**
     * 获取所有 P1 平台 key 列表
     *
     * @return array
     */
    public static function get_p1_keys() {
        $keys = array();
        foreach ( self::PLATFORMS as $platform ) {
            if ( 'p1' === $platform['priority'] ) {
                $keys[] = $platform['key'];
            }
        }
        return $keys;
    }

    /**
     * 获取默认启用的平台 key 列表
     *
     * @return array
     */
    public static function get_default_enabled_keys() {
        $keys = array();
        foreach ( self::PLATFORMS as $platform ) {
            if ( ! empty( $platform['enabled'] ) ) {
                $keys[] = $platform['key'];
            }
        }
        return $keys;
    }

    /**
     * 获取所有爬虫 User-Agent 到平台 key 的映射
     *
     * @return array [ 'GPTBot' => 'chatgpt', ... ]
     */
    public static function get_bot_platform_map() {
        $map = array();
        foreach ( self::PLATFORMS as $platform ) {
            foreach ( $platform['bots'] as $bot ) {
                $map[ $bot ] = $platform['key'];
            }
        }
        return $map;
    }

    /**
     * 根据爬虫 User-Agent 查找平台 key
     *
     * @param string $user_agent
     * @return string|null
     */
    public static function find_by_bot( $user_agent ) {
        $map = self::get_bot_platform_map();
        foreach ( $map as $bot => $key ) {
            if ( false !== stripos( $user_agent, $bot ) ) {
                return $key;
            }
        }
        return null;
    }

    /**
     * 获取平台对应的 Schema 类型优先级列表（按顺序）
     *
     * @param string $key 平台 key
     * @return array
     */
    public static function get_schema_priority( $key ) {
        $platform = self::get( $key );
        if ( ! $platform ) {
            return array( 'Article' ); // 默认
        }
        return $platform['adaptations']['schema_priority'];
    }

    /**
     * 判断平台是否启用某项适配策略
     *
     * @param string $key        平台 key
     * @param string $adaptation 适配项名（如 'llms_txt'）
     * @return bool
     */
    public static function is_adaptation_enabled( $key, $adaptation ) {
        $platform = self::get( $key );
        if ( ! $platform ) {
            return false;
        }
        return ! empty( $platform['adaptations'][ $adaptation ] );
    }
}
