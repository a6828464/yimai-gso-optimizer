<?php
/**
 * GEO 实体优化模块 — 实体定义 / sameAs 验证 / Organization Schema 增强
 *
 * 提供:
 * - 实体定义与管理（品牌名称/类型/Logo/描述/联系方式）
 * - sameAs 身份验证链接管理
 * - 增强 Organization Schema 输出（含 sameAs + 实体信息）
 *
 * 数据存储: yimai_gso_geo_entity_settings (JSON option)
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_GEO_Entity {

    private static $instance = null;

    /** 内存缓存，z4() 可强制刷新 */
    private static $entity_cache = null;

    /** 实体类型选项 */
    const ENTITY_TYPES = array(
        'Organization'    => 'Organization (组织/公司)',
        'LocalBusiness'   => 'LocalBusiness (本地商家)',
        'HealthAndBeautyBusiness' => 'HealthAndBeautyBusiness (美容健康/瑜伽健身机构)', // ★ GEO 报告 C-2：瑜伽馆行业标准类型
        'Person'          => 'Person (个人)',
        'Brand'           => 'Brand (品牌)',
        'Corporation'     => 'Corporation (企业)',
        'NewsMediaOrganization' => 'NewsMediaOrganization (新闻媒体)',
    );

    /** 内建 sameAs 平台模板 */
    const SAMEAS_PLATFORMS = array(
        'zhihu'      => array( 'label' => '知乎',         'url_pattern' => 'https://www.zhihu.com/org/%s' ),
        'baidu_baike'=> array( 'label' => '百度百科',      'url_pattern' => 'https://baike.baidu.com/item/%s' ),
        'weibo'      => array( 'label' => '微博',          'url_pattern' => 'https://weibo.com/%s' ),
        'douyin'     => array( 'label' => '抖音',          'url_pattern' => 'https://www.douyin.com/user/%s' ),
        'xiaohongshu'=> array( 'label' => '小红书',         'url_pattern' => 'https://www.xiaohongshu.com/user/profile/%s' ),
        'github'     => array( 'label' => 'GitHub',        'url_pattern' => 'https://github.com/%s' ),
        'linkedin'   => array( 'label' => 'LinkedIn',      'url_pattern' => 'https://www.linkedin.com/company/%s' ),
        'wikipedia'  => array( 'label' => 'Wikipedia',     'url_pattern' => 'https://en.wikipedia.org/wiki/%s' ),
        'tianyancha' => array( 'label' => '天眼查',         'url_pattern' => 'https://www.tianyancha.com/company/%s' ),
        'custom'     => array( 'label' => '自定义',         'url_pattern' => '' ),
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_geo_entity_save', array( $this, 'ajax_save' ) );
        add_action( 'wp_ajax_yimai_gso_geo_entity_check_link', array( $this, 'ajax_check_link' ) );

        // 增强 Organization Schema — 在现有独立 Schema 之后输出增强版
        add_action( 'wp_head', array( $this, 'output_enhanced_organization_schema' ), 11 );
    }

    // ==================== 数据层 ====================

    /**
     * 获取实体配置（含合理默认值）
     *
     * @return array
     */
    public static function get_entity_data() {
        if ( self::$entity_cache !== null ) {
            return self::$entity_cache;
        }

        $raw = YIMAI_GSO_Settings::get( 'geo_entity_settings', '' );
        $data = array();

        if ( ! empty( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $data = $decoded;
            }
        }

        // 确保键存在
        $defaults = array(
            'entity_name'        => get_bloginfo( 'name' ),
            'entity_type'        => 'Organization',
            'entity_logo'        => '',
            'entity_description' => get_bloginfo( 'description' ),
            'entity_email'       => get_bloginfo( 'admin_email' ),
            'entity_phone'       => '',
            'entity_address'     => '',
            'same_as'            => array(),
        );

        self::$entity_cache = array_merge( $defaults, $data );
        return self::$entity_cache;
    }

    /**
     * 保存实体配置
     *
     * @param array $data
     * @return bool
     */
    public static function save_entity_data( $data ) {
        if ( ! is_array( $data ) ) {
            return false;
        }

        // 过滤 same_as：只保留合法 URL
        if ( isset( $data['same_as'] ) && is_array( $data['same_as'] ) ) {
            $data['same_as'] = array_values( array_filter(
                array_map( 'trim', $data['same_as'] ),
                function( $url ) {
                    // ★ 修复（审计 S-1）：FILTER_VALIDATE_URL 对 javascript: 等伪协议可能放行，
                    //   save_entity_data 独立调用路径无 esc_url_raw 双保险——显式限定 http/https。
                    if ( ! preg_match( '#^https?://#i', $url ) ) {
                        return false;
                    }
                    return ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL );
                }
            ) );
        }

        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        YIMAI_GSO_Settings::set( 'geo_entity_settings', $json );

        // 保存后清除缓存
        self::$entity_cache = null;

        return true;
    }

    /**
     * 获取 sameAs 链接列表
     *
     * @return array
     */
    public static function get_sameas_links() {
        $data = self::get_entity_data();
        return isset( $data['same_as'] ) ? $data['same_as'] : array();
    }

    /**
     * 刷新内存缓存
     */
    public static function z4() {
        self::$entity_cache = null;
    }

    // ==================== Schema 输出 ====================

    /**
     * 增强版 Organization Schema — 在原始 Organization Schema (priority 10) 之后输出
     * 如果实体已配置，输出含 sameAs + 联系方式 的完整 Schema
     */
    public function output_enhanced_organization_schema() {
        // 第三方SEO插件冲突检测：若已存在 Organization Schema，不重复输出
        if ( class_exists( 'YIMAI_GSO_Schema_Generator' )
             && ! empty( YIMAI_GSO_Schema_Generator::detect_conflict_plugins() ) ) {
            return;
        }

        // 只有实体已配置时才输出（与原版 Organization Schema 互斥）
        if ( ! self::is_configured() ) {
            return;
        }

        $entity = self::get_entity_data();

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => $entity['entity_type'],
            'name'        => $entity['entity_name'],
            'url'         => home_url( '/' ),
            'description' => $entity['entity_description'],
        );

        // Logo
        $logo_url = $entity['entity_logo'];
        if ( empty( $logo_url ) ) {
            $logo_id = get_theme_mod( 'custom_logo' );
            if ( $logo_id ) {
                $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
            }
            if ( empty( $logo_url ) && function_exists( 'get_site_icon_url' ) ) {
                $logo_url = get_site_icon_url( 512 );
            }
        }
        if ( ! empty( $logo_url ) ) {
            $schema['logo'] = $logo_url;
        }

        // 联系方式
        if ( ! empty( $entity['entity_email'] ) ) {
            $schema['email'] = $entity['entity_email'];
        }
        if ( ! empty( $entity['entity_phone'] ) ) {
            $schema['telephone'] = $entity['entity_phone'];
        }

        // 地址（LocalBusiness 类型族输出：含 HealthAndBeautyBusiness 等 LocalBusiness 子类型）
        // ★ GEO 报告 C-2：原先仅严格等于 LocalBusiness 才输出地址，选择行业子类型时地址静默丢失
        $is_local_family = in_array( $entity['entity_type'], array( 'LocalBusiness', 'HealthAndBeautyBusiness' ), true );
        if ( ! empty( $entity['entity_address'] ) && $is_local_family ) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'streetAddress' => $entity['entity_address'],
            );
        }

        // sameAs 链接
        if ( ! empty( $entity['same_as'] ) ) {
            $schema['sameAs'] = $entity['same_as'];
        }

        echo '<script type="application/ld+json">' .
             wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
             '</script>' . "\n";
    }

    // ==================== AJAX 处理 ====================

    /**
     * AJAX: 保存实体配置
     */
    public function ajax_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        // （原付费配置门控 if(!true) 恒假死代码已按审计 B-3 删除）

        $name        = isset( $_POST['entity_name'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_name'] ) ) : '';
        $type        = isset( $_POST['entity_type'] ) ? sanitize_text_field( $_POST['entity_type'] ) : 'Organization';
        $logo        = isset( $_POST['entity_logo'] ) ? esc_url_raw( wp_unslash( $_POST['entity_logo'] ) ) : '';
        $description = isset( $_POST['entity_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entity_description'] ) ) : '';
        $email       = isset( $_POST['entity_email'] ) ? sanitize_email( wp_unslash( $_POST['entity_email'] ) ) : '';
        $phone       = isset( $_POST['entity_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_phone'] ) ) : '';
        $address     = isset( $_POST['entity_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entity_address'] ) ) : '';

        // 校验实体类型
        if ( ! array_key_exists( $type, self::ENTITY_TYPES ) ) {
            $type = 'Organization';
        }

        // same_as 链接数组
        $same_as = array();
        if ( isset( $_POST['same_as'] ) && is_array( $_POST['same_as'] ) ) {
            foreach ( $_POST['same_as'] as $url ) {
                $url = esc_url_raw( trim( wp_unslash( $url ) ) );
                if ( ! empty( $url ) ) {
                    $same_as[] = $url;
                }
            }
        }

        $data = array(
            'entity_name'        => $name ?: get_bloginfo( 'name' ),
            'entity_type'        => $type,
            'entity_logo'        => $logo,
            'entity_description' => $description ?: get_bloginfo( 'description' ),
            'entity_email'       => $email,
            'entity_phone'       => $phone,
            'entity_address'     => $address,
            'same_as'            => $same_as,
        );

        $saved = self::save_entity_data( $data );

        if ( $saved ) {
            wp_send_json_success( array(
                'message' => '实体配置已保存',
                'data'    => $data,
            ) );
        } else {
            wp_send_json_error( array( 'message' => '保存失败' ) );
        }
    }

    /**
     * AJAX: 检测 sameAs 链接可访问性
     */
    public function ajax_check_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => 'URL 不能为空' ) );
        }

        // HEAD 请求检测（仅检查 HTTP 状态码，不下载内容）
        $response = wp_remote_head( $url, array(
            'timeout'     => 10,
            'redirection' => 3,
            'user-agent'  => 'BQAI-SEO-GEO/2.9 (sameAs Link Checker)',
        ) );

        // ★ 修复（GEO 报告 C-1）：知乎/微博等平台对 HEAD 返回 405/403，
        //   失败或非 2xx/3xx 时回退 GET 重试一次，避免误报「链接异常」。
        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        if ( 0 === $code || $code === 405 || $code === 403 || $code >= 500 ) {
            $get_response = wp_remote_get( $url, array(
                'timeout'     => 10,
                'redirection' => 3,
                'user-agent'  => 'BQAI-SEO-GEO/2.9 (sameAs Link Checker)',
            ) );
            if ( ! is_wp_error( $get_response ) ) {
                $response = $get_response;
                $code     = (int) wp_remote_retrieve_response_code( $response );
            } elseif ( 0 === $code ) {
                $response = $get_response; // keep original error for message
            }
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( array(
                'url'       => $url,
                'accessible'=> false,
                'status'    => 0,
                'message'   => '无法连接: ' . $response->get_error_message(),
            ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $accessible = ( $code >= 200 && $code < 400 );

        wp_send_json_success( array(
            'url'        => $url,
            'accessible' => $accessible,
            'status'     => $code,
            'message'    => $accessible ? '链接可达 (HTTP ' . $code . ')' : '链接异常 (HTTP ' . $code . ')',
        ) );
    }

    // ==================== 工具方法 ====================

    /**
     * 获取实体是否已完成配置（超出默认值）
     *
     * @return bool
     */
    public static function is_configured() {
        // ★ 修复（审计 B-2）：原先只看 same_as 非空——用户填了名称/电话/地址
        //   但没填 sameAs 时，增强 Schema 完全不输出，已填的联系方式与地址
        //   静默丢失（前台退回简版 Organization，contact/address 全丢）。
        //   改为核心字段有任一实质填写即视为已配置，sameAs 只作为加分项。
        $entity = self::get_entity_data();
        $core_fields = array( 'entity_name', 'entity_description', 'entity_email', 'entity_phone', 'entity_address', 'entity_logo' );
        foreach ( $core_fields as $field ) {
            if ( ! empty( $entity[ $field ] ) ) {
                return true;
            }
        }
        return ! empty( $entity['same_as'] );
    }
}
