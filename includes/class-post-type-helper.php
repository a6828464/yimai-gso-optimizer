<?php
/**
 * 统一内容类型辅助类
 * 
 * 集中管理插件支持的 post_type 列表，替代所有硬编码的 'post'。
 * 各 SEO 模块通过此类获取支持的内容类型，确保一致性。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_B7PQ {

    /**
     * 默认支持的内容类型
     */
    const DEFAULT_TYPES = array( 'post', 'page' );

    /**
     * 请求级缓存（避免同请求内重复 get_option）
     * @var array|null
     */
    private static $cached_types = null;

    /**
     * 内容类型中文标签映射
     */
    private static $labels = array(
        'post'    => '文章',
        'page'    => '页面',
        'product' => '产品',
    );

    /**
     * 获取插件全局支持的内容类型列表
     * 从设置项 wpaip_supported_post_types 读取，默认 ['post', 'page']
     *
     * @return array
     */
    public static function get_supported() {
        if ( null !== self::$cached_types ) {
            return self::$cached_types;
        }
        $saved = get_option( 'wpaip_supported_post_types', self::DEFAULT_TYPES );
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            self::$cached_types = self::DEFAULT_TYPES;
            return self::$cached_types;
        }
        // 确保 'post' 始终在列表中
        if ( ! in_array( 'post', $saved, true ) ) {
            $saved[] = 'post';
        }
        self::$cached_types = array_values( array_unique( $saved ) );
        return self::$cached_types;
    }

    /**
     * 清除请求级缓存（设置页保存 post type 后调用）
     */
    public static function re_x5Z() {
        self::$cached_types = null;
    }

    /**
     * 检查某个 post_type 是否被插件支持
     *
     * @param string $post_type
     * @return bool
     */
    public static function is_supported( $post_type ) {
        if ( empty( $post_type ) ) {
            return false;
        }
        return in_array( $post_type, self::get_supported(), true );
    }

    /**
     * 获取内容类型的中文显示名
     *
     * @param string $post_type
     * @return string
     */
    public static function get_label( $post_type ) {
        if ( isset( self::$labels[ $post_type ] ) ) {
            return self::$labels[ $post_type ];
        }
        // 尝试从 WP 注册的 post type 对象获取
        $obj = get_post_type_object( $post_type );
        if ( $obj && ! empty( $obj->labels->singular_name ) ) {
            return $obj->labels->singular_name;
        }
        return ucfirst( $post_type );
    }

    /**
     * 获取所有公开且可查询的已注册内容类型（供设置UI使用）
     *
     * @return array  [ 'post_type' => '显示名' ]
     */
    public static function get_available_types() {
        $types = array();
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        foreach ( $post_types as $pt => $obj ) {
            // 排除附件、revision、nav_menu_item 等非内容类型
            if ( in_array( $pt, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ), true ) ) {
                continue;
            }
            $types[ $pt ] = $obj->labels->singular_name ?: $pt;
        }
        return $types;
    }

    /**
     * 获取安全转义的类型值列表（仅值部分，不含列名）
     * 返回如 "'post','page'"，可直接嵌入 "IN ({$var})" 模式的双引号 SQL 字符串
     * 解决 implode("','", ...) 在双引号字符串中的引号冲突问题
     *
     * @return string
     */
    public static function sql_type_list() {
        $types = self::get_supported();
        $escaped = array();
        foreach ( $types as $t ) {
            $escaped[] = "'" . esc_sql( $t ) . "'";
        }
        return implode( ',', $escaped );
    }

    /**
     * 生成 SQL IN 子句（用于数据库中 post_type 过滤）
     * 返回已完整转义的 SQL 字符串，可直接嵌入查询中
     *
     * @param string $column_alias 列别名（如 'p.post_type'）
     * @return string 如 "p.post_type IN ('post','page')"
     */
    public static function sql_in_clause( $column_alias = 'post_type' ) {
        global $wpdb;
        $types = self::get_supported();
        $escaped = array();
        foreach ( $types as $t ) {
            $escaped[] = "'" . esc_sql( $t ) . "'";
        }
        return $column_alias . ' IN (' . implode( ',', $escaped ) . ')';
    }
}
