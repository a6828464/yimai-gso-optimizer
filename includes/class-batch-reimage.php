<?php
/**
 * 历史文章批量换图（A-收尾 · 批量换图）
 *
 * 目标：把正文里仍指向外部图库（主要是 images.pexels.com）的图片，换成本站 MT Photos
 * 带「_有水印」素材库的图片：挑图沿用 YIMAI_GSO_MTPhotos_Client::pick_images()，
 * 入库沿用 YIMAI_GSO_Image_Handler::upload_image_to_media()，不另造下载器。
 *
 * 安全边界：
 *  - 只处理 post（不碰 page / 自定义类型），不碰特色图；
 *  - MT Photos 取不到图或单张上传失败时保持原图不动，绝不写入空 src；
 *  - 替换前把原正文存进 post_meta `yimai_gso_content_backup_<时间戳>`（只保留最近一份），
 *    后台可「回滚本篇」；
 *  - 幂等：`yimai_gso_reimage_at` 已存在且正文已无外部图 → 跳过。
 *
 * 关键词口径与现有 auto 配图链路一致（class-publisher.php:252「keywords ?: 标题」、
 * class-autopilot-runner.php:543「image_keywords ?? keywords ?? title」）：
 * yimai_gso_keywords → 文章标题 → 分类名，逐级尝试；MT Photos 文件夹名是中文，
 * 所以不强制英文（class-image-handler.php:73 同口径：「MT Photos 关键词需要保留中文」）。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Batch_Reimage {

    /** 换图完成标记（幂等） */
    const META_AT = 'yimai_gso_reimage_at';
    /** 换图来源标记 */
    const META_SOURCE = 'yimai_gso_reimage_source';
    /** 正文备份前缀（后接时间戳） */
    const BACKUP_PREFIX = 'yimai_gso_content_backup_';
    /** 替换审计（JSON：原图 / 附件 ID / URL / 命中文件夹） */
    const META_LOG = 'yimai_gso_reimage_log';
    /** 特色图审计（旧缩略图 → 新缩略图 / 命中文件夹），支撑回滚 */
    const META_FEATURED_LOG = 'yimai_gso_reimage_featured_log';
    /** 特色图来源判定结果（写在附件 meta 上：pexels / unknown） */
    const META_FEATURED_SOURCE = '_yimai_gso_featured_source';
    /** 无图补图审计与完成标记 */
    const META_APPEND_LOG = 'yimai_gso_reimage_append_log';
    const META_APPEND_AT  = 'yimai_gso_reimage_append_at';

    /** 单张图片下载超时（秒） */
    const DOWNLOAD_TIMEOUT = 30;

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_reimage_scan', array( $this, 'ajax_scan' ) );
        add_action( 'wp_ajax_yimai_gso_reimage_batch', array( $this, 'ajax_batch' ) );
        add_action( 'wp_ajax_yimai_gso_reimage_rollback', array( $this, 'ajax_rollback' ) );
    }

    /* ==================================================================
     * 一、纯判定工具（可脱离 WP 请求单测）
     * ================================================================== */

    /**
     * 视为「本站」的域名列表
     *
     * @return array
     */
    public static function internal_hosts() {
        static $hosts = null;
        if ( is_array( $hosts ) ) {
            return $hosts;
        }

        $hosts = array();

        foreach ( array( home_url(), site_url() ) as $url ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( ! empty( $host ) ) {
                $hosts[] = strtolower( $host );
            }
        }

        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
        if ( ! empty( $uploads['baseurl'] ) ) {
            $host = wp_parse_url( $uploads['baseurl'], PHP_URL_HOST );
            if ( ! empty( $host ) ) {
                $hosts[] = strtolower( $host );
            }
        }

        // MT Photos 自身域名：已经存在素材库里的图不算外部图
        if ( class_exists( 'YIMAI_GSO_Settings' ) ) {
            $mt_host = (string) YIMAI_GSO_Settings::get( 'mtphotos_host', '' );
            if ( '' !== $mt_host ) {
                $host = wp_parse_url( $mt_host, PHP_URL_HOST );
                if ( ! empty( $host ) ) {
                    $hosts[] = strtolower( $host );
                }
            }
        }

        $hosts = array_values( array_unique( array_filter( $hosts ) ) );

        /**
         * 白名单补充（CDN / 图床迁移时由站点自己加）
         *
         * @param array $hosts
         */
        return (array) apply_filters( 'yimai_gso_reimage_internal_hosts', $hosts );
    }

    /**
     * 图片 URL 是否属于本站（含相对路径 / data: 图）
     *
     * @param string $src
     * @return bool
     */
    public static function is_internal_src( $src ) {
        $src = trim( html_entity_decode( (string) $src, ENT_QUOTES ) );

        if ( '' === $src ) {
            return true; // 空 src 不处理，避免写入空图
        }
        if ( 0 === stripos( $src, 'data:' ) ) {
            return true;
        }
        if ( 0 === strpos( $src, '//' ) ) {
            $src = 'https:' . $src;
        }

        $host = wp_parse_url( $src, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return true; // 相对路径 = 本站
        }

        return in_array( strtolower( $host ), self::internal_hosts(), true );
    }

    /**
     * 是否为需要替换的外部图
     *
     * @param string $src
     * @return bool
     */
    public static function is_external_src( $src ) {
        $src = trim( html_entity_decode( (string) $src, ENT_QUOTES ) );
        if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
            return false;
        }
        if ( 0 !== strpos( $src, '//' ) && ! preg_match( '#^https?://#i', $src ) ) {
            return false; // 相对路径 / 其它 scheme 不当作外部图
        }

        return ! self::is_internal_src( $src );
    }

    /**
     * 取出正文所有 <img> 标签（带偏移，供按位置替换）
     *
     * @param string $content
     * @return array [ ['tag'=>string,'offset'=>int,'length'=>int], ... ]
     */
    public static function extract_images( $content ) {
        $images = array();
        if ( ! preg_match_all( '/<img\b[^>]*>/i', (string) $content, $matches, PREG_OFFSET_CAPTURE ) ) {
            return $images;
        }

        foreach ( $matches[0] as $m ) {
            $images[] = array(
                'tag'    => $m[0],
                'offset' => (int) $m[1],
                'length' => strlen( $m[0] ),
            );
        }

        return $images;
    }

    /**
     * 读取标签属性值（已解码实体）
     *
     * @param string $tag
     * @param string $name
     * @return string
     */
    public static function tag_attr( $tag, $name ) {
        if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', (string) $tag, $m ) ) {
            return html_entity_decode( $m[2], ENT_QUOTES );
        }
        return '';
    }

    /**
     * 改写标签属性：存在则替换值，不存在则补在标签末尾
     *
     * @param string $tag
     * @param string $name
     * @param string $value
     * @return string
     */
    public static function set_tag_attr( $tag, $name, $value ) {
        $attr = $name . '="' . esc_attr( $value ) . '"';

        if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag ) ) {
            return preg_replace(
                '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is',
                $attr,
                $tag,
                1
            );
        }

        return preg_replace( '/\s*\/?>$/', ' ' . $attr . ' />', $tag, 1 );
    }

    /**
     * 生成替换后的 <img> 标签：只改 src / alt / width / height，class 等原样保留
     *
     * @param string $tag          原标签
     * @param string $local_url    新图 URL
     * @param string $alt_fallback alt 为空时的兜底（一般用文章标题）
     * @param int    $width
     * @param int    $height
     * @return string
     */
    public static function build_replaced_tag( $tag, $local_url, $alt_fallback = '', $width = 0, $height = 0 ) {
        $new_tag = self::set_tag_attr( $tag, 'src', $local_url );

        $alt = trim( self::tag_attr( $new_tag, 'alt' ) );
        if ( '' === $alt ) {
            $alt = trim( (string) $alt_fallback );
        }
        if ( '' !== $alt ) {
            $new_tag = self::set_tag_attr( $new_tag, 'alt', $alt );
        }

        if ( $width > 0 ) {
            $new_tag = self::set_tag_attr( $new_tag, 'width', (string) (int) $width );
        }
        if ( $height > 0 ) {
            $new_tag = self::set_tag_attr( $new_tag, 'height', (string) (int) $height );
        }

        return $new_tag;
    }

    /**
     * 活动词表：MT Photos 的带水印文件夹按「活动/课程」命名（空中瑜伽、普拉提、核心床…），
     * 长词优先匹配，命中后文件夹更贴文章主题
     */
    const ACTIVITY_TERMS = array( '空中瑜伽', '椅子瑜伽', '轮瑜伽', '墙绳瑜伽', '阴瑜伽', '流瑜伽', '核心床', '普拉提', '颂钵', '内观流', '孕产', '产后', '私教', '团课', '冥想', '呼吸', '拉伸', '瑜伽' );

    /**
     * 从关键词/标题里挑活动词（长词优先，最多 2 个）
     *
     * @param string $text
     * @return array
     */
    public static function detect_activity_terms( $text ) {
        $text = (string) $text;
        $out  = array();
        foreach ( self::ACTIVITY_TERMS as $term ) {
            if ( false !== mb_strpos( $text, $term ) ) {
                $out[] = $term;
            }
            if ( count( $out ) >= 2 ) {
                break;
            }
        }
        return $out;
    }

    /**
     * 关键词候选：活动词 → yimai_gso_keywords → 标题 → 分类名
     *
     * 活动词必须排在最前：MT Photos 文件夹是「空中瑜伽/普拉提/核心床」这种活动名，
     * 而 yimai_gso_keywords 的第一项经常是品牌词「一麦瑜伽」——拿品牌词去搜会命中
     * 全部 271 个带水印文件夹，等于随机配图。品牌词在这里直接丢弃。
     *
     * @param WP_Post $post
     * @return array
     */
    public static function build_keyword_candidates( $post ) {
        $candidates = array();

        $keywords = trim( (string) get_post_meta( $post->ID, 'yimai_gso_keywords', true ) );
        $title    = trim( (string) $post->post_title );

        // 活动词：先按关键词找，找不到再从标题找
        $activity = self::detect_activity_terms( $keywords );
        if ( empty( $activity ) ) {
            $activity = self::detect_activity_terms( $title );
        }
        $candidates = array_merge( $candidates, $activity );

        if ( '' !== $keywords ) {
            foreach ( preg_split( '/[,，、;；|]+/u', $keywords ) as $term ) {
                $term = trim( $term );
                if ( '' !== $term ) {
                    $candidates[] = $term;
                }
                if ( count( $candidates ) >= 4 ) {
                    break;
                }
            }
        }

        if ( '' !== $title ) {
            $candidates[] = $title;
        }

        $categories = function_exists( 'get_the_category' ) ? get_the_category( $post->ID ) : array();
        if ( ! empty( $categories[0] ) && ! empty( $categories[0]->name ) ) {
            $candidates[] = (string) $categories[0]->name;
        }

        // 品牌词（含站点名）会命中全部带水印文件夹，对配图无意义，直接丢弃
        $brand = trim( (string) get_bloginfo( 'name' ) );
        $candidates = array_filter( $candidates, function ( $term ) use ( $brand ) {
            $term = trim( (string) $term );
            if ( '' === $term ) {
                return false;
            }
            if ( '' !== $brand && $term === $brand ) {
                return false;
            }
            return ! in_array( $term, array( '一麦瑜伽', '一麦', '一麦瑜伽官网' ), true );
        } );

        $candidates = array_values( array_unique( array_map( 'trim', $candidates ) ) );

        return array_slice( $candidates, 0, 4 );
    }

    /* ==================================================================
     * 二、单篇：扫描 / 计划 / 执行 / 回滚
     * ================================================================== */

    /**
     * 单篇 dry-run：只算「要换几张、会命中哪个文件夹」，不写库、不下载
     *
     * @param int   $post_id
     * @param array $args
     * @return array
     */
    public static function plan_post( $post_id, $args = array() ) {
        $post = get_post( $post_id );
        $plan = array(
            'post_id'        => (int) $post_id,
            'title'          => '',
            'status'         => 'skipped',
            'reason'         => '',
            'external_count' => 0,
            'keyword'        => '',
            'folders'        => array(),
            'available'      => 0,
        );

        if ( ! $post || 'post' !== $post->post_type ) {
            $plan['reason'] = 'not_post';
            return $plan;
        }

        $plan['title'] = (string) $post->post_title;

        $external = self::collect_external_images( $post->post_content );
        $plan['external_count'] = count( $external );
        $plan['featured']       = self::featured_status( $post->ID );
        $plan['has_images']     = (bool) preg_match( '/<img\b/i', (string) $post->post_content );
        $plan['mode']           = empty( $external ) ? ( $plan['has_images'] ? 'skip' : 'append' ) : 'reimage';

        if ( empty( $external ) ) {
            $plan['status'] = 'skipped';
            $plan['reason'] = get_post_meta( $post->ID, self::META_AT, true ) ? 'already_done' : 'no_external_image';
            return $plan;
        }

        $candidates = self::build_keyword_candidates( $post );
        $keyword    = $candidates[0] ?? $post->post_title;
        $plan['keyword'] = $keyword;

        // dry-run 不传 context：跳过 MT Photos 的 AI 关联度审核，扫描 62 篇才不会被 AI 拖慢
        $probe = self::fetch_candidate_images( $keyword, min( 3, $plan['external_count'] ), array() );
        if ( is_wp_error( $probe ) ) {
            $plan['status'] = 'no_source';
            $plan['reason'] = $probe->get_error_message();
            return $plan;
        }

        $plan['status']    = 'ready';
        $plan['available'] = count( $probe );
        foreach ( $probe as $img ) {
            $folder = (string) ( $img['source'] ?? '' );
            if ( '' !== $folder ) {
                $plan['folders'][] = $folder;
            }
        }
        $plan['folders'] = array_values( array_unique( $plan['folders'] ) );

        return $plan;
    }

    /**
     * 单篇真跑
     *
     * @param int   $post_id
     * @param array $args {
     *     @type bool $dry_run      只计划不写库（默认 false）
     *     @type bool $pass_context 是否把 title/keyword 传给 MT Photos 的 AI 审核（默认 true）
     *     @type int  $max_replace  最多替换几张（0 = 全部，默认 0）
     * }
     * @return array
     */
    public static function reimage_post( $post_id, $args = array() ) {
        $dry_run      = ! empty( $args['dry_run'] );
        $pass_context = ! isset( $args['pass_context'] ) || (bool) $args['pass_context'];
        $max_replace  = isset( $args['max_replace'] ) ? max( 0, (int) $args['max_replace'] ) : 0;

        $result = array(
            'post_id'       => (int) $post_id,
            'title'         => '',
            'status'        => 'skipped',
            'reason'        => '',
            'external_count'=> 0,
            'replaced'      => array(),
            'failed'        => array(),
            'dry_run'       => $dry_run,
            'keyword'       => '',
        );

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            $result['reason'] = 'not_post';
            return $result;
        }

        $result['title'] = (string) $post->post_title;

        $external = self::collect_external_images( $post->post_content );
        $result['external_count'] = count( $external );

        if ( empty( $external ) ) {
            $result['reason'] = get_post_meta( $post->ID, self::META_AT, true ) ? 'already_done' : 'no_external_image';

            if ( ! empty( $args['featured'] ) ) {
                $kw = self::build_keyword_candidates( $post );
                $feat = self::update_featured_image(
                    $post,
                    (string) ( $kw[0] ?? $post->post_title ),
                    array( 'title' => (string) $post->post_title, 'keyword' => (string) ( $kw[0] ?? '' ), 'excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ) ),
                    self::content_attachment_md5s( (string) $post->post_content )
                );
                if ( is_wp_error( $feat ) ) {
                    $result['featured_reason'] = $feat->get_error_message();
                } else {
                    $result['featured'] = $feat;
                }
            }

            return $result;
        }

        if ( $dry_run ) {
            return self::plan_post( $post_id, $args );
        }

        $need = $max_replace > 0 ? min( $max_replace, count( $external ) ) : count( $external );

        $candidates = self::build_keyword_candidates( $post );
        if ( empty( $candidates ) ) {
            $candidates = array( (string) $post->post_title );
        }

        $context = $pass_context ? array(
            'title'   => (string) $post->post_title,
            'keyword' => (string) ( $candidates[0] ?? '' ),
            'excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ),
        ) : array();

        // 逐级换关键词补图：一个关键词不够就试下一个，仍不够则只替换拿到的数量
        $pool   = array();
        $seen   = array();
        $errors = array();

        foreach ( $candidates as $keyword ) {
            if ( count( $pool ) >= $need ) {
                break;
            }

            $images = self::fetch_candidate_images( $keyword, $need - count( $pool ), $context );
            if ( is_wp_error( $images ) ) {
                $errors[] = $keyword . '：' . $images->get_error_message();
                continue;
            }

            $result['keyword'] = $keyword;
            foreach ( $images as $img ) {
                $url = (string) ( $img['url'] ?? '' );
                if ( '' === $url || isset( $seen[ $url ] ) ) {
                    continue;
                }
                $seen[ $url ] = true;
                $pool[] = $img;
                if ( count( $pool ) >= $need ) {
                    break;
                }
            }
        }

        if ( empty( $pool ) ) {
            // 取不到图：保持原图不动
            $result['reason'] = 'no_source: ' . ( $errors ? implode( ' | ', $errors ) : 'MT Photos 未返回可用图片' );
            return $result;
        }

        $handler = class_exists( 'YIMAI_GSO_Image_Handler' ) ? new YIMAI_GSO_Image_Handler() : null;
        if ( ! $handler ) {
            $result['reason'] = 'no_image_handler';
            return $result;
        }

        $replacements = array();
        $pool_index   = 0;

        foreach ( $external as $item ) {
            if ( $pool_index >= count( $pool ) ) {
                break;
            }
            if ( $max_replace > 0 && count( $replacements ) >= $max_replace ) {
                break;
            }

            $img = $pool[ $pool_index ];
            $alt = trim( self::tag_attr( $item['tag'], 'alt' ) );
            if ( '' === $alt ) {
                $alt = (string) $post->post_title;
            }

            $attachment_id = self::upload_image( (string) $img['url'], $alt, $handler );
            if ( is_wp_error( $attachment_id ) ) {
                $result['failed'][] = array(
                    'src'    => $item['src'],
                    'reason' => $attachment_id->get_error_message(),
                );
                // 这张图不可用：换下一张候选，原图保持不动
                $pool_index++;
                continue;
            }

            $local_url = wp_get_attachment_url( $attachment_id );
            if ( empty( $local_url ) ) {
                $result['failed'][] = array( 'src' => $item['src'], 'reason' => '附件 URL 为空' );
                $pool_index++;
                continue;
            }

            $meta   = wp_get_attachment_metadata( $attachment_id );
            $width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
            $height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

            $new_tag = self::build_replaced_tag( $item['tag'], $local_url, $alt, $width, $height );

            $replacements[] = array(
                'offset'        => $item['offset'],
                'length'        => $item['length'],
                'tag'           => $new_tag,
                'old_src'       => $item['src'],
                'attachment_id' => (int) $attachment_id,
                'url'           => $local_url,
                'folder'        => (string) ( $img['source'] ?? '' ),
                'alt'           => $alt,
            );
            $pool_index++;
        }

        if ( empty( $replacements ) ) {
            $result['reason'] = 'all_uploads_failed';
            return $result;
        }

        $new_content = self::apply_replacements( $post->post_content, $replacements );

        self::save_content_backup( $post->ID, $post->post_content );

        $updated = wp_update_post( array(
            'ID'           => $post->ID,
            'post_content' => wp_slash( $new_content ),
        ), true );

        if ( is_wp_error( $updated ) ) {
            $result['reason'] = 'update_failed: ' . $updated->get_error_message();
            return $result;
        }

        update_post_meta( $post->ID, self::META_AT, current_time( 'mysql' ) );
        update_post_meta( $post->ID, self::META_SOURCE, 'mtphotos' );
        // 落一份替换审计：文章 → 原图 / 新附件 / 命中文件夹，便于验收与逐张回溯
        // 直接存数组（WP 会序列化）；存 JSON 字符串会被 metadata 的 wp_unslash 打掉转义引号
        update_post_meta( $post->ID, self::META_LOG, $replacements );

        // 旧正文算出来的结构数据必须作废：citation / 平台适配缓存里还留着 pexels 图片 URL，
        // 不清掉前台 JSON-LD 会继续对外声明已经不存在的图（A-收尾）
        delete_post_meta( $post->ID, 'yimai_gso_citations' );
        delete_post_meta( $post->ID, 'yimai_gso_platform_adapt' );

        if ( class_exists( 'YIMAI_GSO_Citation_Helper' ) ) {
            YIMAI_GSO_Citation_Helper::get_instance()->analyze( $post->ID );
        }
        if ( class_exists( 'YIMAI_GSO_AI_Platform_Adapter' ) && class_exists( 'YIMAI_GSO_AI_Platform_Registry' ) ) {
            YIMAI_GSO_AI_Platform_Adapter::get_instance()->on_post_save( $post->ID, get_post( $post->ID ), true );
        }

        if ( ! empty( $args['featured'] ) ) {
            $kw     = self::build_keyword_candidates( $post );
            $kw_str = (string) ( $kw[0] ?? $post->post_title );
            $feat   = self::update_featured_image(
                $post,
                $kw_str,
                array( 'title' => (string) $post->post_title, 'keyword' => $kw_str, 'excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ) ),
                self::content_attachment_md5s( $new_content )
            );
            if ( is_wp_error( $feat ) ) {
                $result['featured_reason'] = $feat->get_error_message();
            } else {
                $result['featured'] = $feat;
            }
        }

        $result['status']   = 'done';
        $result['replaced'] = $replacements;

        return $result;
    }

    /**
     * 正文里已有图片的附件文件 MD5（防止特色图与正文图重复）
     *
     * @param string $content
     * @return array
     */
    private static function content_attachment_md5s( $content ) {
        $md5 = array();
        if ( ! preg_match_all( '#https?://[^"\'\s>]+/wp-content/uploads/[^"\'\s>]+#i', (string) $content, $m ) ) {
            return $md5;
        }
        foreach ( array_unique( $m[0] ) as $url ) {
            $id   = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
            $path = ( $id && function_exists( 'get_attached_file' ) ) ? get_attached_file( $id ) : '';
            if ( '' !== $path && file_exists( $path ) ) {
                $md5[] = md5_file( $path );
            }
        }
        return array_values( array_unique( $md5 ) );
    }

    /**
     * 回滚单篇：恢复最近一份正文备份，并清掉换图标记
     *
     * @param int $post_id
     * @return array|WP_Error
     */
    public static function rollback_post( $post_id ) {
        $post_id = absint( $post_id );
        $post    = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'invalid_post', '文章不存在' );
        }

        $all = get_post_meta( $post_id );
        if ( ! is_array( $all ) ) {
            return new WP_Error( 'no_backup', '没有可回滚的备份' );
        }

        $latest_key = '';
        $latest_ts  = -1;
        foreach ( array_keys( $all ) as $key ) {
            if ( 0 !== strpos( $key, self::BACKUP_PREFIX ) ) {
                continue;
            }
            $ts = (int) substr( $key, strlen( self::BACKUP_PREFIX ) );
            if ( $ts > $latest_ts ) {
                $latest_ts  = $ts;
                $latest_key = $key;
            }
        }

        $featured_log = get_post_meta( $post_id, self::META_FEATURED_LOG, true );
        $featured_log = is_array( $featured_log ) ? $featured_log : array();

        if ( '' === $latest_key && empty( $featured_log ) ) {
            return new WP_Error( 'no_backup', '没有可回滚的备份（正文与特色图都没有换过）' );
        }

        $restored_thumb = '';
        if ( '' !== $latest_key ) {
            $backup = get_post_meta( $post_id, $latest_key, true );
            if ( ! is_string( $backup ) || '' === $backup ) {
                return new WP_Error( 'empty_backup', '备份内容为空' );
            }

            $updated = wp_update_post( array(
                'ID'           => $post_id,
                'post_content' => wp_slash( $backup ),
            ), true );

            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            delete_post_meta( $post_id, $latest_key );
        }

        if ( ! empty( $featured_log ) ) {
            $last = end( $featured_log );
            $old  = isset( $last['old_thumbnail_id'] ) ? (int) $last['old_thumbnail_id'] : 0;
            if ( $old > 0 && function_exists( 'set_post_thumbnail' ) ) {
                set_post_thumbnail( $post_id, $old );
            } elseif ( function_exists( 'delete_post_thumbnail' ) ) {
                delete_post_thumbnail( $post_id );
            }
            $restored_thumb = $old;
        }

        delete_post_meta( $post_id, self::META_AT );
        delete_post_meta( $post_id, self::META_SOURCE );
        delete_post_meta( $post_id, self::META_LOG );
        delete_post_meta( $post_id, self::META_FEATURED_LOG );
        delete_post_meta( $post_id, self::META_APPEND_LOG );
        delete_post_meta( $post_id, self::META_APPEND_AT );

        // 正文已还原：旧结构缓存（按替换后的正文算的）一并作废
        delete_post_meta( $post_id, 'yimai_gso_citations' );
        delete_post_meta( $post_id, 'yimai_gso_platform_adapt' );

        return array(
            'post_id'            => $post_id,
            'restored_from'      => $latest_key,
            'restored_thumbnail' => $restored_thumb,
        );
    }

    /* ==================================================================
     * 三、批量：候选列表 / 进度
     * ================================================================== */

    /**
     * 扫描全站统计（dry-run 报告用）
     *
     * @return array
     */
    public static function scan_summary() {
        $ids = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish' ),
            'numberposts'    => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        $summary = array(
            'total_posts'        => count( $ids ),
            'posts_with_external'=> 0,
            'external_images'    => 0,
            'pexels_images'      => 0,
            'distinct_urls'      => array(),
            'posts_without_image'=> 0,
            'already_reimaged'   => 0,
        );

        foreach ( $ids as $id ) {
            $content = (string) get_post_field( 'post_content', $id );
            $external = self::collect_external_images( $content );

            if ( empty( self::extract_images( $content ) ) ) {
                $summary['posts_without_image']++;
            }

            if ( ! empty( $external ) ) {
                $summary['posts_with_external']++;
                $summary['external_images'] += count( $external );
                foreach ( $external as $item ) {
                    $summary['distinct_urls'][ $item['src'] ] = true;
                    if ( false !== stripos( $item['src'], 'images.pexels.com' ) ) {
                        $summary['pexels_images']++;
                    }
                }
            } elseif ( get_post_meta( $id, self::META_AT, true ) ) {
                $summary['already_reimaged']++;
            }
        }

        $summary['distinct_url_count'] = count( $summary['distinct_urls'] );
        unset( $summary['distinct_urls'] );

        return $summary;
    }

    /**
     * 待处理文章 ID（按 ID 升序，支持 offset 续跑）
     *
     * @param array $args
     * @return array
     */
    public static function get_candidate_ids( $args = array() ) {
        $limit  = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
        $offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

        $ids = get_posts( array(
            'post_type'     => 'post',
            'post_status'   => array( 'publish' ),
            'numberposts'   => -1,
            'fields'        => 'ids',
            'orderby'       => 'ID',
            'order'         => 'ASC',
            'no_found_rows' => true,
        ) );

        $candidates = array();
        foreach ( $ids as $id ) {
            if ( ! empty( self::collect_external_images( (string) get_post_field( 'post_content', $id ) ) ) ) {
                $candidates[] = (int) $id;
            }
        }

        if ( $offset > 0 ) {
            $candidates = array_slice( $candidates, $offset );
        }
        if ( $limit > 0 ) {
            $candidates = array_slice( $candidates, 0, $limit );
        }

        return $candidates;
    }

    /**
     * 跑一批
     *
     * @param array $args { offset, batch_size, dry_run, pass_context, max_replace }
     * @return array
     */
    public static function run_batch( $args = array() ) {
        $batch_size = isset( $args['batch_size'] ) ? max( 1, (int) $args['batch_size'] ) : 5;
        $offset     = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
        $dry_run    = ! empty( $args['dry_run'] );
        $raw_mode   = ! empty( $args['raw_mode'] ); // AJAX 直推模式：按候选下标续跑

        $ids = self::get_candidate_ids( array( 'offset' => $offset, 'limit' => $batch_size ) );

        $report = array(
            'offset'     => $offset,
            'batch_size' => $batch_size,
            'dry_run'    => $dry_run,
            'processed'  => array(),
            'done'       => 0,
            'skipped'    => 0,
        );

        $append  = ! empty( $args['append'] );
        $featured = ! empty( $args['featured'] );

        foreach ( $ids as $id ) {
            $content  = (string) get_post_field( 'post_content', $id );
            $has_img  = (bool) preg_match( '/<img\b/i', $content );

            if ( $append && ! $has_img && ! $dry_run ) {
                $item = self::append_images_post( $id, array(
                    'pass_context' => isset( $args['pass_context'] ) ? (bool) $args['pass_context'] : true,
                    'featured'     => $featured,
                ) );
                $item['mode'] = 'append';
            } else {
                $item = self::reimage_post( $id, array(
                    'dry_run'      => $dry_run,
                    'pass_context' => isset( $args['pass_context'] ) ? (bool) $args['pass_context'] : true,
                    'max_replace'  => isset( $args['max_replace'] ) ? (int) $args['max_replace'] : 0,
                    'featured'     => $featured,
                ) );
                $item['mode'] = 'reimage';
            }
            $report['processed'][] = $item;
            if ( 'done' === ( $item['status'] ?? '' ) ) {
                $report['done']++;
            } else {
                $report['skipped']++;
            }
        }

        $report['next_offset'] = $offset + count( $ids );
        $report['remaining']   = count( self::get_candidate_ids( array( 'offset' => $report['next_offset'] ) ) );

        return $report;
    }

    /* ==================================================================
     * 四、内部工具
     * ================================================================== */

    /**
     * 正文里的外部图（src + 标签 + 偏移）
     *
     * @param string $content
     * @return array
     */
    public static function collect_external_images( $content ) {
        $out = array();
        foreach ( self::extract_images( $content ) as $img ) {
            $src = self::tag_attr( $img['tag'], 'src' );
            if ( self::is_external_src( $src ) ) {
                $img['src'] = $src;
                $out[] = $img;
            }
        }
        return $out;
    }

    /**
     * 取候选图：过滤器优先（测试/运维接管），否则走 MT Photos 客户端
     *
     * @param string $keyword
     * @param int    $count
     * @param array  $context
     * @return array|WP_Error
     */
    public static function fetch_candidate_images( $keyword, $count, $context = array() ) {
        $count = max( 1, (int) $count );

        $override = apply_filters( 'yimai_gso_reimage_fetch_images', null, $keyword, $count, $context );
        if ( null !== $override ) {
            return $override;
        }

        if ( ! class_exists( 'YIMAI_GSO_MTPhotos_Client' ) ) {
            return new WP_Error( 'no_mtphotos', 'MT Photos 模块未加载' );
        }

        $client = new YIMAI_GSO_MTPhotos_Client();

        return $client->pick_images( $keyword, $count, $context );
    }

    /**
     * 入库单张图：过滤器优先，否则复用 Image_Handler 的 sideload 逻辑
     *
     * @param string                     $url
     * @param string                     $alt
     * @param YIMAI_GSO_Image_Handler    $handler
     * @return int|WP_Error
     */
    public static function upload_image( $url, $alt, $handler ) {
        $override = apply_filters( 'yimai_gso_reimage_upload_image', null, $url, $alt );
        if ( null !== $override ) {
            return $override;
        }

        return $handler->upload_image_to_media( $url, '', $alt, self::DOWNLOAD_TIMEOUT );
    }

    /**
     * 按偏移从后往前替换正文中的标签
     *
     * @param string $content
     * @param array  $replacements 每项需含 offset/length/tag
     * @return string
     */
    public static function apply_replacements( $content, $replacements ) {
        $content = (string) $content;

        usort( $replacements, function ( $a, $b ) {
            return (int) $b['offset'] - (int) $a['offset'];
        } );

        foreach ( $replacements as $r ) {
            $content = substr_replace( $content, $r['tag'], (int) $r['offset'], (int) $r['length'] );
        }

        return $content;
    }

    /**
     * 保存正文备份（只保留最近一份）
     *
     * @param int    $post_id
     * @param string $content
     * @return string 备份 meta key
     */
    public static function save_content_backup( $post_id, $content ) {
        $post_id = absint( $post_id );
        $all     = get_post_meta( $post_id );

        if ( is_array( $all ) ) {
            foreach ( array_keys( $all ) as $key ) {
                if ( 0 === strpos( $key, self::BACKUP_PREFIX ) ) {
                    delete_post_meta( $post_id, $key );
                }
            }
        }

        $key = self::BACKUP_PREFIX . time();
        update_post_meta( $post_id, $key, wp_slash( (string) $content ) );

        return $key;
    }

    /* ==================================================================
     * 四·B、特色图（缩略图）与无图补图
     * ================================================================== */

    /**
     * 特色图来源判定
     *
     * 口径（按优先级）：
     *  a. 附件 meta 里有 pexels 证据（survey_featured 写回的 _yimai_gso_featured_source）
     *  b. 文件名 / guid 含 pexels
     *  c. 判不出来 → unknown（默认不换，列进待人工确认）
     *
     * @param int $post_id
     * @return array {status:none|pexels|unknown, thumb_id:int, file:string, evidence:string}
     */
    public static function featured_status( $post_id ) {
        $thumb_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
        $out = array( 'status' => 'none', 'thumb_id' => $thumb_id, 'file' => '', 'evidence' => '' );

        if ( $thumb_id <= 0 ) {
            $out['evidence'] = 'no_thumbnail';
            return $out;
        }

        $out['file'] = (string) get_post_meta( $thumb_id, '_wp_attached_file', true );

        $marked = (string) get_post_meta( $thumb_id, self::META_FEATURED_SOURCE, true );
        if ( 'pexels' === $marked ) {
            $out['status']   = 'pexels';
            $out['evidence'] = 'meta';
            return $out;
        }
        if ( 'unknown' === $marked ) {
            $out['status']   = 'unknown';
            $out['evidence'] = 'meta_unknown';
            return $out;
        }

        foreach ( get_post_meta( $thumb_id ) as $key => $value ) {
            $val = is_array( $value ) ? wp_json_encode( $value ) : (string) ( $value[0] ?? '' );
            if ( false !== stripos( $val, 'images.pexels.com' ) ) {
                $out['status']   = 'pexels';
                $out['evidence'] = 'attachment_meta:' . $key;
                return $out;
            }
        }

        $guid = function_exists( 'get_post_field' ) ? (string) get_post_field( 'guid', $thumb_id ) : '';
        if ( false !== stripos( $out['file'], 'pexels' ) || false !== stripos( $guid, 'pexels' ) ) {
            $out['status']   = 'pexels';
            $out['evidence'] = 'filename';
            return $out;
        }

        $out['status']   = 'unknown';
        $out['evidence'] = 'no_evidence';
        return $out;
    }

    /**
     * 特色图证据调查：遍历已发布文章，逐篇判定特色图来源
     *
     * 判定不了时用「附件文件 MD5 == 正文/备份里某张 pexels 图 MD5」做字节级比对；
     * 对不上就记 unknown，绝不猜。结果写回附件 meta，后续批量换图直接复用。
     *
     * @param bool $write 是否把判定结果写回附件 meta
     * @return array {stats:array, rows:array}
     */
    public static function survey_featured( $write = true ) {
        $ids = get_posts( array(
            'post_type'     => 'post',
            'post_status'   => array( 'publish' ),
            'numberposts'   => -1,
            'fields'        => 'ids',
            'orderby'       => 'ID',
            'order'         => 'ASC',
            'no_found_rows' => true,
        ) );

        $uploads  = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
        $basedir  = (string) ( $uploads['basedir'] ?? '' );
        $stats    = array( 'total' => count( $ids ), 'has_thumb' => 0, 'pexels' => 0, 'unknown' => 0, 'no_thumb' => 0 );
        $rows     = array();

        foreach ( $ids as $post_id ) {
            $status = self::featured_status( $post_id );

            if ( 'none' === $status['status'] ) {
                $stats['no_thumb']++;
                $rows[] = array( 'id' => (int) $post_id, 'thumb' => 0, 'class' => 'no_thumb', 'file' => '', 'evidence' => '' );
                continue;
            }

            $stats['has_thumb']++;

            if ( 'unknown' === $status['status'] && '' !== $basedir ) {
                $path = $basedir . '/' . $status['file'];
                $local_md5 = ( '' !== $status['file'] && file_exists( $path ) ) ? md5_file( $path ) : '';

                if ( '' !== $local_md5 ) {
                    foreach ( self::collect_pexels_urls( $post_id ) as $url ) {
                        $res = wp_remote_get( $url, array( 'timeout' => 20 ) );
                        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
                            continue;
                        }
                        if ( md5( wp_remote_retrieve_body( $res ) ) === $local_md5 ) {
                            $status['status']   = 'pexels';
                            $status['evidence'] = 'md5_match';
                            break;
                        }
                    }
                }
            }

            if ( $write && $status['thumb_id'] > 0 ) {
                update_post_meta( $status['thumb_id'], self::META_FEATURED_SOURCE, $status['status'] );
            }

            if ( 'pexels' === $status['status'] ) {
                $stats['pexels']++;
            } else {
                $stats['unknown']++;
            }

            $rows[] = array(
                'id'       => (int) $post_id,
                'thumb'    => (int) $status['thumb_id'],
                'class'    => $status['status'],
                'file'     => $status['file'],
                'evidence' => $status['evidence'],
            );
        }

        return array( 'stats' => $stats, 'rows' => $rows );
    }

    /**
     * 收集文章（含正文备份）里出现过的 pexels 图片 URL
     *
     * @param int $post_id
     * @return array
     */
    private static function collect_pexels_urls( $post_id ) {
        $urls = array();
        $sources = array( (string) get_post_field( 'post_content', $post_id ) );

        foreach ( get_post_meta( $post_id ) as $key => $value ) {
            if ( 0 === strpos( $key, self::BACKUP_PREFIX ) ) {
                $sources[] = (string) ( $value[0] ?? '' );
            }
        }

        foreach ( $sources as $text ) {
            if ( preg_match_all( '#https?://images\.pexels\.com/[^"\'\s>]+#i', $text, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $urls[] = html_entity_decode( $url, ENT_QUOTES );
                }
            }
        }

        return array_values( array_unique( $urls ) );
    }

    /**
     * 替换 / 补齐特色图
     *
     * @param WP_Post $post
     * @param string  $keyword
     * @param array   $context
     * @param array   $avoid_md5 正文里已有图片的文件 MD5（避免特色图与正文重复）
     * @return array|WP_Error {old_thumbnail_id,new_thumbnail_id,url,folder,alt}
     */
    private static function update_featured_image( $post, $keyword, $context, $avoid_md5 = array() ) {
        $status = self::featured_status( $post->ID );

        if ( 'unknown' === $status['status'] ) {
            return new WP_Error( 'featured_unknown', '特色图来源判定不了，按口径跳过（待人工确认）' );
        }

        $handler = class_exists( 'YIMAI_GSO_Image_Handler' ) ? new YIMAI_GSO_Image_Handler() : null;
        if ( ! $handler ) {
            return new WP_Error( 'no_image_handler', '图片入库模块未加载' );
        }

        $candidates = self::fetch_candidate_images( $keyword, 3, $context );
        if ( is_wp_error( $candidates ) ) {
            return $candidates;
        }

        $alt = trim( (string) $post->post_title ) . ' · 宁波瑜伽馆实拍';

        foreach ( $candidates as $img ) {
            $url = (string) ( $img['url'] ?? '' );
            if ( '' === $url ) {
                continue;
            }

            $attachment_id = self::upload_image( $url, $alt, $handler );
            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

            $local_url = wp_get_attachment_url( $attachment_id );
            if ( empty( $local_url ) ) {
                continue;
            }

            if ( ! empty( $avoid_md5 ) ) {
                $uploads = wp_upload_dir();
                $path    = function_exists( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : '';
                if ( '' !== $path && file_exists( $path ) && in_array( md5_file( $path ), $avoid_md5, true ) ) {
                    continue; // 与正文某张图字节相同，换下一张候选
                }
            }

            $old_id = (int) $status['thumb_id'];
            $ok     = function_exists( 'set_post_thumbnail' ) ? set_post_thumbnail( $post->ID, $attachment_id ) : false;
            if ( ! $ok ) {
                return new WP_Error( 'set_thumbnail_failed', '设置特色图失败' );
            }

            $entry = array(
                'old_thumbnail_id' => $old_id,
                'new_thumbnail_id' => (int) $attachment_id,
                'url'              => $local_url,
                'folder'           => (string) ( $img['source'] ?? '' ),
                'alt'              => $alt,
                'mode'             => ( 'none' === $status['status'] ) ? 'added' : 'replaced',
            );

            $log   = get_post_meta( $post->ID, self::META_FEATURED_LOG, true );
            $log   = is_array( $log ) ? $log : array();
            $log[] = $entry;
            update_post_meta( $post->ID, self::META_FEATURED_LOG, $log );

            return $entry;
        }

        return new WP_Error( 'featured_no_image', 'MT Photos 未取到可用的特色图（或都已被正文使用）' );
    }

    /**
     * 无图文章补图：按段落分布插入 1-3 张 MT Photos 图
     *
     * @param int   $post_id
     * @param array $args { pass_context:bool, featured:bool }
     * @return array
     */
    public static function append_images_post( $post_id, $args = array() ) {
        $post = get_post( $post_id );
        $result = array(
            'post_id'  => (int) $post_id,
            'title'    => '',
            'status'   => 'skipped',
            'reason'   => '',
            'inserted' => array(),
            'featured' => null,
            'featured_reason' => '',
        );

        if ( ! $post || 'post' !== $post->post_type ) {
            $result['reason'] = 'not_post';
            return $result;
        }
        $result['title'] = (string) $post->post_title;

        if ( preg_match( '/<img\b/i', (string) $post->post_content ) ) {
            $result['reason'] = 'has_images';
            return $result;
        }
        if ( get_post_meta( $post->ID, self::META_APPEND_AT, true ) ) {
            $result['reason'] = 'already_appended';
            return $result;
        }

        $content   = (string) $post->post_content;
        $p_count   = preg_match_all( '/<\/p>/i', $content );
        if ( $p_count < 1 ) {
            $result['reason'] = 'no_paragraphs';
            return $result;
        }

        $need = ( $p_count >= 6 ) ? 3 : ( ( $p_count >= 3 ) ? 2 : 1 );

        $pass_context = ! isset( $args['pass_context'] ) || (bool) $args['pass_context'];
        $candidates   = self::build_keyword_candidates( $post );
        $keyword      = (string) ( $candidates[0] ?? $post->post_title );
        $context      = $pass_context ? array(
            'title'   => (string) $post->post_title,
            'keyword' => $keyword,
            'excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ),
        ) : array();

        $images = self::fetch_candidate_images( $keyword, $need + 2, $context );
        if ( is_wp_error( $images ) ) {
            $result['reason'] = 'no_source: ' . $images->get_error_message();
            return $result;
        }

        // 同篇不重复文件 + 尽量跨文件夹：先挑不同文件夹的前两张，不够再从剩余里补
        $picked   = array();
        $seen     = array();
        $folders  = array();
        $leftover = array();

        foreach ( $images as $img ) {
            $url = (string) ( $img['url'] ?? '' );
            if ( '' === $url || isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;
            $folder = (string) ( $img['source'] ?? '' );

            if ( count( $picked ) < 2 && isset( $folders[ $folder ] ) ) {
                $leftover[] = $img; // 同文件夹，先留着补位
                continue;
            }
            $folders[ $folder ] = true;
            $picked[] = $img;
            if ( count( $picked ) >= $need ) {
                break;
            }
        }

        foreach ( $leftover as $img ) {
            if ( count( $picked ) >= $need ) {
                break;
            }
            $picked[] = $img;
        }

        if ( empty( $picked ) ) {
            $result['reason'] = 'no_source: MT Photos 未返回可用图片';
            return $result;
        }

        $handler = class_exists( 'YIMAI_GSO_Image_Handler' ) ? new YIMAI_GSO_Image_Handler() : null;
        if ( ! $handler ) {
            $result['reason'] = 'no_image_handler';
            return $result;
        }

        $blocks = array();
        foreach ( $picked as $img ) {
            $folder_short = trim( str_replace( array( 'MT Photos ·', '_有水印', '_导出', '_PixCake' ), '', (string) ( $img['source'] ?? '' ) ) );
            $alt = trim( (string) $post->post_title ) . ( '' !== $folder_short ? ' · ' . $folder_short : ' · 宁波瑜伽馆实拍' );

            $attachment_id = self::upload_image( (string) $img['url'], $alt, $handler );
            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }
            $local_url = wp_get_attachment_url( $attachment_id );
            if ( empty( $local_url ) ) {
                continue;
            }
            $meta   = wp_get_attachment_metadata( $attachment_id );
            $width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
            $height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

            $tag = '<img src="' . esc_url( $local_url ) . '" alt="' . esc_attr( $alt ) . '"'
                . ( $width > 0 ? ' width="' . $width . '"' : '' )
                . ( $height > 0 ? ' height="' . $height . '"' : '' )
                . ' loading="lazy" style="max-width:100%;border-radius:8px;display:inline-block;" />';

            $blocks[] = array(
                'attachment_id' => (int) $attachment_id,
                'url'           => $local_url,
                'folder'        => (string) ( $img['source'] ?? '' ),
                'alt'           => $alt,
                'html'          => "\n\n<p style=\"text-align:center;margin:24px 0;\">" . $tag . '</p>',
            );
        }

        if ( empty( $blocks ) ) {
            $result['reason'] = 'all_uploads_failed';
            return $result;
        }

        // 按段落均分插入：3 张 → 1/4、2/4、3/4 处，绝不堆在文末
        $new_content = $content;
        $total       = count( $blocks );
        $inserted    = array();

        $paragraph_offsets = array();
        if ( preg_match_all( '/<\/p>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as $idx => $match ) {
                $paragraph_offsets[ $idx + 1 ] = $match[1] + strlen( $match[0] );
            }
        }

        // 从后往前插，避免偏移量互相影响
        for ( $i = $total - 1; $i >= 0; $i-- ) {
            $p_index = (int) round( $p_count * ( $i + 1 ) / ( $total + 1 ) );
            $p_index = max( 1, min( $p_count, $p_index ) );
            $offset  = $paragraph_offsets[ $p_index ] ?? strlen( $new_content );

            $new_content = substr( $new_content, 0, $offset ) . $blocks[ $i ]['html'] . substr( $new_content, $offset );

            $inserted[] = array(
                'attachment_id'   => $blocks[ $i ]['attachment_id'],
                'url'             => $blocks[ $i ]['url'],
                'folder'          => $blocks[ $i ]['folder'],
                'alt'             => $blocks[ $i ]['alt'],
                'after_paragraph' => $p_index,
            );
        }
        $inserted = array_reverse( $inserted );

        self::save_content_backup( $post->ID, $content );

        $updated = wp_update_post( array(
            'ID'           => $post->ID,
            'post_content' => wp_slash( $new_content ),
        ), true );

        if ( is_wp_error( $updated ) ) {
            $result['reason'] = 'update_failed: ' . $updated->get_error_message();
            return $result;
        }

        update_post_meta( $post->ID, self::META_APPEND_AT, current_time( 'mysql' ) );
        update_post_meta( $post->ID, self::META_APPEND_LOG, $inserted );

        delete_post_meta( $post->ID, 'yimai_gso_citations' );
        delete_post_meta( $post->ID, 'yimai_gso_platform_adapt' );
        if ( class_exists( 'YIMAI_GSO_Citation_Helper' ) ) {
            YIMAI_GSO_Citation_Helper::get_instance()->analyze( $post->ID );
        }
        if ( class_exists( 'YIMAI_GSO_AI_Platform_Adapter' ) && class_exists( 'YIMAI_GSO_AI_Platform_Registry' ) ) {
            YIMAI_GSO_AI_Platform_Adapter::get_instance()->on_post_save( $post->ID, get_post( $post->ID ), true );
        }

        if ( ! empty( $args['featured'] ) ) {
            $featured = self::update_featured_image( $post, $keyword, $context );
            if ( is_wp_error( $featured ) ) {
                $result['featured_reason'] = $featured->get_error_message();
            } else {
                $result['featured'] = $featured;
            }
        }

        $result['status']   = 'done';
        $result['inserted'] = $inserted;

        return $result;
    }

    /* ==================================================================
     * 五、后台 AJAX
     * ================================================================== */

    private function guard() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
    }

    /** AJAX：dry-run 扫描 */
    public function ajax_scan() {
        $this->guard();

        $summary = self::scan_summary();
        $ids     = self::get_candidate_ids( array( 'limit' => 100 ) );

        $plans = array();
        foreach ( $ids as $id ) {
            $plans[] = self::plan_post( $id );
        }

        wp_send_json_success( array(
            'summary' => $summary,
            'plans'   => $plans,
        ) );
    }

    /** AJAX：跑一批 */
    public function ajax_batch() {
        $this->guard();

        $report = self::run_batch( array(
            'offset'       => absint( $_POST['offset'] ?? 0 ),
            'batch_size'   => max( 1, min( 20, absint( $_POST['batch_size'] ?? 5 ) ) ),
            'dry_run'      => ! empty( $_POST['dry_run'] ),
            'pass_context' => ! empty( $_POST['pass_context'] ),
            'max_replace'  => absint( $_POST['max_replace'] ?? 0 ),
            'featured'     => ! empty( $_POST['featured'] ),
            'append'       => ! empty( $_POST['append'] ),
        ) );

        wp_send_json_success( $report );
    }

    /** AJAX：回滚单篇 */
    public function ajax_rollback() {
        $this->guard();

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $result  = self::rollback_post( $post_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /* ==================================================================
     * 六、后台面板
     * ================================================================== */

    /**
     * 渲染「批量换图」面板（由 class-admin.php 主面板调用）
     */
    public static function render_panel() {
        $summary = self::scan_summary();
        $nonce   = wp_create_nonce( 'yimai_gso_nonce' );
        ?>
        <div class="yimai-gso-page-card-header">
            <div class="yimai-gso-card-icon"><span class="dashicons dashicons-images-alt2"></span></div>
            <div class="yimai-gso-card-body">
                <h2 class="yimai-gso-card-title">批量换图</h2>
                <p class="yimai-gso-card-desc">把历史正文里的外部图库图片（images.pexels.com 等）替换为本站 MT Photos 带水印素材；只处理文章，不动特色图。</p>
            </div>
        </div>

        <div class="yimai-gso-panel" style="margin-bottom:16px;">
            <p style="margin:0 0 8px;color:#334155;">
                当前扫描：<strong><?php echo esc_html( (string) $summary['total_posts'] ); ?></strong> 篇文章，
                其中 <strong><?php echo esc_html( (string) $summary['posts_with_external'] ); ?></strong> 篇含外部图，
                共 <strong><?php echo esc_html( (string) $summary['external_images'] ); ?></strong> 处
                （pexels <?php echo esc_html( (string) $summary['pexels_images'] ); ?> 处，去重后
                <?php echo esc_html( (string) $summary['distinct_url_count'] ); ?> 张），
                无图 <strong><?php echo esc_html( (string) $summary['posts_without_image'] ); ?></strong> 篇，
                已完成换图 <strong><?php echo esc_html( (string) $summary['already_reimaged'] ); ?></strong> 篇。
            </p>
            <p style="margin:0;color:#64748b;font-size:13px;">
                流程：先「Dry-run 扫描」看计划（不写库）→「开始批量换图」每批 5 篇 → 中断后点「继续下一批」从上次位置续跑。
                每篇替换前会存一份正文备份（只保留最近一份），失败可点结果行里的「回滚本篇」。
            </p>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
            <button type="button" class="button button-secondary" id="yimai-gso-reimage-scan">Dry-run 扫描全站</button>
            <button type="button" class="button button-primary" id="yimai-gso-reimage-run">开始批量换图（每批 5 篇）</button>
            <button type="button" class="button" id="yimai-gso-reimage-next" disabled>继续下一批</button>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;color:#475569;">
                <input type="checkbox" id="yimai-gso-reimage-context" checked> 启用 MT Photos AI 关联度审核
            </label>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;color:#475569;">
                <input type="checkbox" id="yimai-gso-reimage-featured" checked> 同时替换/补齐特色图
            </label>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;color:#475569;">
                <input type="checkbox" id="yimai-gso-reimage-append" checked> 无图文章补图
            </label>
        </div>

        <div id="yimai-gso-reimage-log" style="font-family:Menlo,Consolas,monospace;font-size:12px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;max-height:280px;overflow:auto;">等待操作…</div>

        <div id="yimai-gso-reimage-results" style="margin-top:16px;"></div>

        <script>
        (function () {
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var offset = 0;
            var logBox = document.getElementById('yimai-gso-reimage-log');
            var resultBox = document.getElementById('yimai-gso-reimage-results');
            var nextBtn = document.getElementById('yimai-gso-reimage-next');

            function log(line) {
                logBox.textContent += "\n" + line;
                logBox.scrollTop = logBox.scrollHeight;
            }

            function post(action, data) {
                var body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce', nonce);
                Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
                return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
            }

            function renderPlans(plans) {
                if (!plans || !plans.length) { resultBox.innerHTML = '<p>没有需要处理的文章。</p>'; return; }
                var rows = plans.map(function (p) {
                    return '<tr><td>' + p.post_id + '</td><td>' + (p.title || '') + '</td><td>' + p.external_count +
                        '</td><td>' + (p.keyword || '') + '</td><td>' + ((p.folders || []).join('<br>') || (p.reason || '')) + '</td></tr>';
                }).join('');
                resultBox.innerHTML = '<table class="widefat striped"><thead><tr><th>ID</th><th>标题</th><th>外部图</th><th>关键词</th><th>命中文件夹 / 说明</th></tr></thead><tbody>' + rows + '</tbody></table>';
            }

            function renderBatch(report) {
                var rows = (report.processed || []).map(function (item) {
                    if (item.status === 'done') {
                        var detail = (item.replaced || []).map(function (r) {
                            return '#' + r.attachment_id + ' ← ' + r.old_src + '（' + (r.folder || '') + '）';
                        }).join('<br>');
                        return '<tr><td>' + item.post_id + '</td><td>' + (item.title || '') + '</td><td>' + (item.replaced || []).length +
                            '</td><td>' + detail + '</td><td><button type="button" class="button button-small yimai-gso-reimage-rollback" data-post="' + item.post_id + '">回滚本篇</button></td></tr>';
                    }
                    return '<tr><td>' + item.post_id + '</td><td>' + (item.title || '') + '</td><td>0</td><td>' + (item.reason || '跳过') + '</td><td></td></tr>';
                }).join('');
                resultBox.innerHTML = '<table class="widefat striped"><thead><tr><th>ID</th><th>标题</th><th>替换</th><th>明细（附件 ← 原图 / 文件夹）</th><th>回滚</th></tr></thead><tbody>' + rows + '</tbody></table>';
            }

            document.getElementById('yimai-gso-reimage-scan').addEventListener('click', function () {
                log('[dry-run] 扫描中…');
                post('yimai_gso_reimage_scan', {}).then(function (res) {
                    if (!res.success) { log('扫描失败：' + (res.data && res.data.message || '')); return; }
                    var s = res.data.summary;
                    log('扫描完成：文章 ' + s.total_posts + '，含外部图 ' + s.posts_with_external + ' 篇 / ' + s.external_images + ' 处（pexels ' + s.pexels_images + '），去重 ' + s.distinct_url_count + ' 张');
                    renderPlans(res.data.plans);
                    offset = 0;
                });
            });

            document.getElementById('yimai-gso-reimage-run').addEventListener('click', function () { runBatch(0); });
            nextBtn.addEventListener('click', function () { runBatch(offset); });

            function runBatch(from) {
                var useContext = document.getElementById('yimai-gso-reimage-context').checked ? 1 : 0;
                log('[batch] 从第 ' + from + ' 篇开始，每批 5 篇…');
                post('yimai_gso_reimage_batch', { offset: from, batch_size: 5, pass_context: useContext, featured: (document.getElementById('yimai-gso-reimage-featured').checked ? 1 : 0), append: (document.getElementById('yimai-gso-reimage-append').checked ? 1 : 0) }).then(function (res) {
                    if (!res.success) { log('执行失败：' + (res.data && res.data.message || '')); return; }
                    var r = res.data;
                    offset = r.next_offset;
                    log('本批完成：替换 ' + r.done + ' 篇，跳过 ' + r.skipped + ' 篇，剩余候选 ' + r.remaining + ' 篇');
                    renderBatch(r);
                    nextBtn.disabled = r.remaining <= 0;
                });
            }

            resultBox.addEventListener('click', function (e) {
                var btn = e.target.closest('.yimai-gso-reimage-rollback');
                if (!btn) { return; }
                if (!window.confirm('确认回滚这篇文章的正文？换图标记会一并清除。')) { return; }
                post('yimai_gso_reimage_rollback', { post_id: btn.getAttribute('data-post') }).then(function (res) {
                    log(res.success ? ('已回滚 #' + res.data.post_id) : ('回滚失败：' + (res.data && res.data.message || '')));
                });
            });
        })();
        </script>
        <?php
    }
}
