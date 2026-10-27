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

    /**
     * 本次请求真实输出的实体 @type（t8）
     *
     * 供 ai-platform-adapter 的 ai-schema-coverage 读取——只声明实际输出的类型。
     * null 表示没有输出（实体未配置 / 被主题接管 / 冲突插件在位）。
     *
     * @var string|null
     */
    private static $emitted_type = null;

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
            // t8：LocalBusiness 实体必备字段（缺坐标不得声称本地商家）
            'entity_latitude'          => '',
            'entity_longitude'         => '',
            'entity_postal_code'       => '',
            'entity_address_locality'  => '',
            'entity_address_region'    => '',
            'entity_address_country'   => 'CN',
            'entity_opening_hours'     => '',
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
        self::$emitted_type = null;

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

        // 地址 / 坐标 / 营业时间（仅 LocalBusiness 类型族）
        //
        // ★ t8 修复：原先只看 entity_type 是否属于 LocalBusiness 族就输出地址，
        //   既没有坐标也没有营业时间——「只有 streetAddress 的 LocalBusiness」
        //   无法通过 Rich Results，Schema.org 也不认。
        //   现在两道闸门：
        //   ① apply_filters( 'yimai_gso_emit_local_business', true )：
        //      主题接管两店 LocalBusiness 输出时返回 false，插件不得再输出同类节点；
        //   ② 必须有合法经纬度，否则降级为 Organization，绝不声称本地商家。
        $emit_local = (bool) apply_filters( 'yimai_gso_emit_local_business', true, $entity );

        if ( in_array( $schema['@type'], self::local_business_types(), true ) ) {
            if ( ! $emit_local || ! self::has_coordinates( $entity ) ) {
                $schema['@type'] = 'Organization';
            }
        }

        $is_local_family = in_array( $schema['@type'], self::local_business_types(), true );

        if ( $is_local_family ) {
            $address = array( '@type' => 'PostalAddress' );
            if ( ! empty( $entity['entity_address'] ) ) {
                $address['streetAddress'] = $entity['entity_address'];
            }
            if ( ! empty( $entity['entity_address_locality'] ) ) {
                $address['addressLocality'] = $entity['entity_address_locality'];
            }
            if ( ! empty( $entity['entity_address_region'] ) ) {
                $address['addressRegion'] = $entity['entity_address_region'];
            }
            if ( ! empty( $entity['entity_postal_code'] ) ) {
                $address['postalCode'] = $entity['entity_postal_code'];
            }
            $address['addressCountry'] = ! empty( $entity['entity_address_country'] ) ? $entity['entity_address_country'] : 'CN';
            $schema['address'] = $address;

            $schema['geo'] = array(
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $entity['entity_latitude'],
                'longitude' => (float) $entity['entity_longitude'],
            );

            $specs = self::opening_hours_specification( $entity['entity_opening_hours'] );
            if ( ! empty( $specs ) ) {
                $schema['openingHoursSpecification'] = $specs;
            } elseif ( ! empty( $entity['entity_opening_hours'] ) ) {
                // 解析不了的写法（如「每天 10 点到 21 点」）原样输出，不猜
                $schema['openingHours'] = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n;；]+/', (string) $entity['entity_opening_hours'] ) ) ) );
            }
        }

        // sameAs 链接
        if ( ! empty( $entity['same_as'] ) ) {
            $schema['sameAs'] = $entity['same_as'];
        }

        self::$emitted_type = $schema['@type'];

        echo '<script type="application/ld+json">' .
             wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
             '</script>' . "\n";
    }

    /**
     * 本请求真实输出的实体 @type（t8）
     *
     * @return string|null
     */
    public static function get_emitted_type() {
        return self::$emitted_type;
    }

    /**
     * LocalBusiness 类型族（这些类型必须有真实地理坐标才能成立）
     *
     * @return array
     */
    private static function local_business_types() {
        return array(
            'LocalBusiness',
            'HealthAndBeautyBusiness',
            'HealthClub',
            'ExerciseGym',
            'SportsActivityLocation',
        );
    }

    /**
     * 实体是否配置了合法经纬度（t8）
     *
     * @param array $entity
     * @return bool
     */
    private static function has_coordinates( $entity ) {
        $lat = isset( $entity['entity_latitude'] ) ? trim( (string) $entity['entity_latitude'] ) : '';
        $lng = isset( $entity['entity_longitude'] ) ? trim( (string) $entity['entity_longitude'] ) : '';

        if ( '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return ( $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 );
    }

    /**
     * 营业时间文本 → openingHoursSpecification 数组（t8）
     *
     * 支持每行一条，例如：
     *   Mo-Su 09:00-21:00
     *   Mon-Fri 10:00-20:00
     *   周一至周日 09:00-21:00
     * 任意一行无法解析时返回空数组，调用方退回纯文本 openingHours。
     *
     * @param string $raw
     * @return array
     */
    private static function opening_hours_specification( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return array();
        }

        $order   = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
        $specs   = array();
        $day_re  = '([A-Za-z]{2,9}|周[一二三四五六日天]|星期[一二三四五六日天])';
        $lines   = preg_split( '/[\r\n;；]+/', $raw );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            if ( ! preg_match( '/(\d{1,2}:\d{2})\s*[-–~至]\s*(\d{1,2}:\d{2})/u', $line, $m ) ) {
                return array();
            }

            $opens  = self::normalize_time( $m[1] );
            $closes = self::normalize_time( $m[2] );
            $days_part = trim( str_replace( $m[0], '', $line ) );

            $days = array();
            if ( preg_match( '/' . $day_re . '\s*(?:[-–~至]|to)\s*' . $day_re . '/u', $days_part, $r ) ) {
                $from = self::day_name( $r[1] );
                $to   = self::day_name( $r[2] );
                $i    = array_search( $from, $order, true );
                $j    = array_search( $to, $order, true );
                if ( false === $i || false === $j ) {
                    return array();
                }
                while ( true ) {
                    $days[] = $order[ $i ];
                    if ( $i === $j ) {
                        break;
                    }
                    $i = ( $i + 1 ) % 7;
                }
            } else {
                preg_match_all( '/' . $day_re . '/u', $days_part, $rr );
                foreach ( $rr[0] as $token ) {
                    $day = self::day_name( $token );
                    if ( '' !== $day ) {
                        $days[] = $day;
                    }
                }
                if ( empty( $days ) ) {
                    $days = $order; // 未写星期 = 每天营业
                }
            }

            foreach ( array_unique( $days ) as $day ) {
                $specs[] = array(
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/' . $day,
                    'opens'     => $opens,
                    'closes'    => $closes,
                );
            }
        }

        return $specs;
    }

    /**
     * 星期 token → schema.org dayOfWeek 名称
     *
     * @param string $token
     * @return string 无法识别时返回空串
     */
    private static function day_name( $token ) {
        $token = trim( (string) $token );
        $cn = array(
            '周一' => 'Monday', '周二' => 'Tuesday', '周三' => 'Wednesday', '周四' => 'Thursday',
            '周五' => 'Friday', '周六' => 'Saturday', '周日' => 'Sunday', '周天' => 'Sunday',
            '星期一' => 'Monday', '星期二' => 'Tuesday', '星期三' => 'Wednesday', '星期四' => 'Thursday',
            '星期五' => 'Friday', '星期六' => 'Saturday', '星期日' => 'Sunday',
        );
        if ( isset( $cn[ $token ] ) ) {
            return $cn[ $token ];
        }

        $en = array(
            'mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday',
            'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday',
        );
        // 按前两个字母判定：Mo / Mon / Monday 都归一
        $head = substr( strtolower( $token ), 0, 2 );

        return isset( $en[ $head ] ) ? $en[ $head ] : '';
    }

    /**
     * 时间文本补零：9:00 → 09:00
     *
     * @param string $value
     * @return string
     */
    private static function normalize_time( $value ) {
        list( $h, $m ) = array_pad( explode( ':', (string) $value ), 2, '00' );
        return sprintf( '%02d:%02d', (int) $h, (int) $m );
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

        // t8：LocalBusiness 结构化字段
        $latitude  = isset( $_POST['entity_latitude'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_latitude'] ) ) : '';
        $longitude = isset( $_POST['entity_longitude'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_longitude'] ) ) : '';
        $postal    = isset( $_POST['entity_postal_code'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_postal_code'] ) ) : '';
        $locality  = isset( $_POST['entity_address_locality'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_address_locality'] ) ) : '';
        $region    = isset( $_POST['entity_address_region'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_address_region'] ) ) : '';
        $country   = isset( $_POST['entity_address_country'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_address_country'] ) ) : 'CN';
        $hours     = isset( $_POST['entity_opening_hours'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entity_opening_hours'] ) ) : '';

        // 坐标只接受合法范围，否则留空（宁可降级 Organization，也不写错坐标）
        $latitude  = ( is_numeric( $latitude ) && (float) $latitude >= -90 && (float) $latitude <= 90 ) ? (string) (float) $latitude : '';
        $longitude = ( is_numeric( $longitude ) && (float) $longitude >= -180 && (float) $longitude <= 180 ) ? (string) (float) $longitude : '';
        $country   = $country ? strtoupper( $country ) : 'CN';

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
            'entity_latitude'         => $latitude,
            'entity_longitude'        => $longitude,
            'entity_postal_code'      => $postal,
            'entity_address_locality' => $locality,
            'entity_address_region'   => $region,
            'entity_address_country'  => $country,
            'entity_opening_hours'    => $hours,
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
