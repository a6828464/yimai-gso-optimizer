<?php
/**
 * 微信分享卡片：JS-SDK 自定义分享 + 社交抓取元信息加固 + 后台自检
 *
 * 背景：官网在微信内「发送给朋友 / 分享到朋友圈」时只有一个裸链接。
 * 根因不是 OG 标签缺失（站点已有），而是页面没有接入微信 JS-SDK ——
 * 微信只能退回自己的抓取结果，抓不到图/摘要时就只剩一条 URL。
 *
 * 本模块做三件事：
 *   1) 服务端签名（access_token → jsapi_ticket → signature），前端 wx.config 后
 *      用 updateAppMessageShareData / updateTimelineShareData 指定
 *      标题、描述、缩略图、链接 —— 这是唯一能 100% 控制微信内分享卡片的方式。
 *   2) 补全微信/QQ 抓取器需要而 OG 默认没有的元信息：
 *      og:image:width/height/type、<link rel="image_src">、非 ASCII 文件名转百分号编码。
 *      覆盖「把链接直接粘贴/转发到聊天窗口」这个不经过 JS-SDK 的场景。
 *   3) 后台「微信分享卡片」面板：凭据测试、抓取自检（真实模拟微信 UA 抓一遍）、
 *      卡片预览、公众号安全域名验证文件托管、错误码速查。
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.9.9（2.9.11 增补真机诊断与重定向死循环识别）
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Wechat_Share {

    private static $instance = null;

    const REST_NAMESPACE = 'yimai-gso/v1';
    const REST_ROUTE     = '/wechat-jssdk';
    const REPORT_ROUTE   = '/wechat-jssdk-report';

    const TOKEN_TRANSIENT  = 'yimai_gso_wx_access_token';
    const TICKET_TRANSIENT = 'yimai_gso_wx_jsapi_ticket';
    const LOCK_TRANSIENT   = 'yimai_gso_wx_token_lock';
    const ERROR_OPTION     = 'yimai_gso_wx_last_error';

    /**
     * 真机上报：wx.config / wx.ready / wx.error 的真实结果都写到这个 option。
     * 微信的失败原因（invalid signature / invalid domain / the permission value is offline
     * verifying 等）只在客户端 errMsg 里，服务端凭空推不出来，这是唯一可靠的取证点。
     */
    const REPORT_OPTION = 'yimai_gso_wx_client_report';
    const LIBRARY_TRANSIENT = 'yimai_gso_wx_fallback_image';

    /** access_token 有效期 7200 秒，缓存留 200 秒余量 */
    const CACHE_TTL = 7000;

    /** 单 IP 每分钟签名请求上限，防止被人拿去刷爆公众号接口额度 */
    const RATE_LIMIT = 90;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'maybe_serve_verify_file' ), 1 );
        add_action( 'after_setup_theme', array( $this, 'register_share_size' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_post_dispatch', array( $this, 'disable_rest_cache' ), 10, 3 );
        add_action( 'wp_head', array( $this, 'output_head_bootstrap' ), 0 );
        add_action( 'wp_footer', array( $this, 'output_footer_script' ), 22 );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_admin_page' ), 20 );
            // 30 是刻意的：public 的 enqueue_assets 在 class-admin.php 里按默认优先级 10 注册，
            // 本页样式必须排在 admin → panels → components → responsive 之后，否则会被同权重规则顶掉。
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ), 30 );
            add_action( 'admin_post_yimai_gso_wechat_save', array( $this, 'handle_save' ) );
            add_action( 'wp_ajax_yimai_gso_wechat_test_token', array( $this, 'ajax_test_token' ) );
            add_action( 'wp_ajax_yimai_gso_wechat_probe', array( $this, 'ajax_probe' ) );
            add_action( 'wp_ajax_yimai_gso_wechat_clear_cache', array( $this, 'ajax_clear_cache' ) );
        }
    }

    /* ==================== 基础判定 ==================== */

    public static function is_enabled() {
        if ( 1 !== (int) YIMAI_GSO_Settings::get( 'wechat_share_enabled', 0 ) ) {
            return false;
        }
        $app_id = trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_id', '' ) );
        $secret = trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_secret', '' ) );
        return ( '' !== $app_id && '' !== $secret );
    }

    public static function is_wechat_ua( $ua = '' ) {
        if ( '' === $ua ) {
            $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        }
        return ( false !== stripos( $ua, 'MicroMessenger' ) );
    }

    /* ==================== 分享内容与封面 ==================== */

    /**
     * 注册分享专用裁切尺寸（4:3，800×600）
     *
     * 站点特色图普遍是 1000×1500 竖版，而微信卡片缩略图是近方形框，竖图上下会被裁掉。
     * 有 WP 自己按裁切位置生成一版横向图，就能既保留文章相关性、又不被裁。
     */
    public function register_share_size() {
        add_image_size( 'yimai-gso-share', 800, 600, true );
    }

    /**
     * 解析分享封面图（JS-SDK 缩略图与 og:image 共用同一份结果，避免两处不一致）
     *
     * 微信分享缩略图的硬性要求：绝对 URL、JPG/PNG、建议 ≥ 300×300、文件名尽量 ASCII。
     * SVG 一定不行（微信不渲染），WebP 在部分安卓微信的分享卡片里不显示。
     *
     * @param int    $post_id 文章 ID（0 = 站点级）
     * @param string $url     当前页面 URL
     * @return array{url:string,width:int,height:int,type:string,source:string}
     */
    public static function resolve_share_image( $post_id = 0, $url = '' ) {
        $out = array( 'url' => '', 'width' => 0, 'height' => 0, 'type' => '', 'source' => '' );

        $cover         = trim( (string) YIMAI_GSO_Settings::get( 'wechat_share_cover', '' ) );
        $has_cover     = ( '' !== $cover && preg_match( '#^https?://#i', $cover ) );

        // 1) 文章特色图
        if ( $post_id && has_post_thumbnail( $post_id ) ) {
            $thumb_id = (int) get_post_thumbnail_id( $post_id );
            $mime     = (string) get_post_mime_type( $thumb_id );
            $is_svg   = ( 'image/svg+xml' === $mime );
            // 只有 WebP 才让位封面（安卓微信分享卡片不显示 WebP）。
            // 竖图不再让位——pick_share_size() 会现场裁一张 4:3 横图，
            // 既避免被卡片裁掉上下，又能保留文章相关性。
            $prefer_cover = ( $has_cover && 'image/webp' === $mime );
            if ( $thumb_id && ! $is_svg && ! $prefer_cover ) {
                $src = self::pick_share_size( $thumb_id );
                if ( $src ) {
                    $out['url']    = $src[0];
                    $out['width']  = (int) $src[1];
                    $out['height'] = (int) $src[2];
                    $out['type']   = $mime;
                    $out['source'] = 'featured';
                }
            }
        }

        // 2) 后台指定的分享封面（品牌兜底图）
        if ( '' === $out['url'] && $has_cover ) {
            $out['url']    = $cover;
            $out['source'] = 'cover';
            $cover_id      = (int) attachment_url_to_postid( $cover );
            if ( $cover_id ) {
                $meta = wp_get_attachment_metadata( $cover_id );
                if ( is_array( $meta ) && ! empty( $meta['width'] ) ) {
                    $out['width']  = (int) $meta['width'];
                    $out['height'] = (int) $meta['height'];
                }
                $out['type'] = (string) get_post_mime_type( $cover_id );
            }
        }

        // 3) 站点 Logo（SVG 跳过——微信/Facebook/QQ 抓取器都不认）
        if ( '' === $out['url'] ) {
            $logo_id = (int) get_theme_mod( 'custom_logo' );
            if ( $logo_id ) {
                $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
                if ( $logo_url && ! preg_match( '/\.svg(\?|$)/i', $logo_url ) ) {
                    $src = wp_get_attachment_image_src( $logo_id, 'full' );
                    $out['url']    = $logo_url;
                    $out['width']  = $src ? (int) $src[1] : 0;
                    $out['height'] = $src ? (int) $src[2] : 0;
                    $out['type']   = (string) get_post_mime_type( $logo_id );
                    $out['source'] = 'logo';
                }
            }
        }

        // 3.5) 插件内置品牌封面：比 512×512 白底 logo 更适合做卡片图
        if ( '' === $out['url'] ) {
            $builtin = YIMAI_GSO_PLUGIN_URL . 'assets/images/share-cover.jpg';
            $builtin_path = YIMAI_GSO_PLUGIN_DIR . 'assets/images/share-cover.jpg';
            if ( file_exists( $builtin_path ) ) {
                $out['url']    = $builtin;
                $out['width']  = 800;
                $out['height'] = 600;
                $out['type']   = 'image/jpeg';
                $out['source'] = 'builtin';
            }
        }

        // 4) 站点图标
        if ( '' === $out['url'] && function_exists( 'get_site_icon_url' ) ) {
            $icon = get_site_icon_url( 512 );
            if ( $icon && ! preg_match( '/\.svg(\?|$)/i', $icon ) ) {
                $out['url']    = $icon;
                $out['source'] = 'site_icon';
            }
        }

        // 5) 媒体库最近一张位图（结果缓存一天，避免每次请求都查库）
        if ( '' === $out['url'] ) {
            $cached = get_transient( self::LIBRARY_TRANSIENT );
            if ( is_string( $cached ) && '' !== $cached ) {
                $out['url']    = $cached;
                $out['source'] = 'library';
            } else {
                $fallback = get_posts( array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => array( 'image/jpeg', 'image/png' ),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );
                if ( ! empty( $fallback ) ) {
                    $fid = (int) $fallback[0];
                    $src = wp_get_attachment_image_src( $fid, 'full' );
                    if ( $src ) {
                        $out['url']    = $src[0];
                        $out['width']  = (int) $src[1];
                        $out['height'] = (int) $src[2];
                        $out['type']   = (string) get_post_mime_type( $fid );
                        $out['source'] = 'library';
                        set_transient( self::LIBRARY_TRANSIENT, $src[0], DAY_IN_SECONDS );
                    }
                }
            }
        }

        if ( '' !== $out['url'] ) {
            $out['url'] = self::normalize_image_url( $out['url'] );
            if ( '' === $out['type'] ) {
                $out['type'] = self::guess_mime_from_url( $out['url'] );
            }
        }

        return $out;
    }

    /**
     * 图片 URL 规范化：
     *  - 站点是 HTTPS 时把 http:// 的图片转成 https://（微信要求缩略图走 HTTPS，否则不显示）
     *  - 把路径里的中文/空格等非 ASCII 字符转成百分号编码（微信抓取器对裸中文 URL 会抓不到）
     */
    public static function normalize_image_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }

        $home_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $is_https  = ( 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
        if ( $is_https && 0 === stripos( $url, 'http://' ) ) {
            $url_host = (string) wp_parse_url( $url, PHP_URL_HOST );
            // 只升级本站图片，外链图可能不支持 HTTPS
            if ( '' !== $url_host && strcasecmp( $url_host, $home_host ) === 0 ) {
                $url = 'https://' . substr( $url, 7 );
            }
        }

        if ( ! preg_match( '/[^\x20-\x7E]/', $url ) ) {
            return $url;
        }

        // ★ 不能对整条 URL 用 wp_parse_url() 取 path：PHP 的 parse_url() 会把路径里的
        //   多字节字符逐字截成单字节（「一」的 e4 b8 80 只留下 e4 b8），后续再编码就是错的。
        //   这里按字节自己切 scheme / host / path / query，全程不碰 UTF-8 序列。
        $scheme = '';
        if ( false !== strpos( $url, '://' ) ) {
            $scheme = substr( $url, 0, strpos( $url, '://' ) );
            $rest   = substr( $url, strpos( $url, '://' ) + 3 );
        } else {
            $rest = $url;
        }

        $host_len  = strcspn( $rest, '/?#' );
        $host      = substr( $rest, 0, $host_len );
        $tail      = substr( $rest, $host_len );
        $fragment  = '';
        if ( false !== strpos( $tail, '#' ) ) {
            $fragment = substr( $tail, strpos( $tail, '#' ) );
            $tail     = substr( $tail, 0, strpos( $tail, '#' ) );
        }
        $query = '';
        if ( false !== strpos( $tail, '?' ) ) {
            $query = substr( $tail, strpos( $tail, '?' ) + 1 );
            $tail  = substr( $tail, 0, strpos( $tail, '?' ) );
        }
        $path = ( '' === $tail ) ? '/' : $tail;

        if ( '' === $host ) {
            return $url;
        }

        if ( preg_match( '/[^\x20-\x7E]/', $path ) ) {
            $segments = explode( '/', $path );
            foreach ( $segments as $i => $seg ) {
                if ( '' !== $seg ) {
                    $segments[ $i ] = rawurlencode( rawurldecode( $seg ) );
                }
            }
            $path = implode( '/', $segments );
        }

        $rebuilt = ( '' !== $scheme ? $scheme . '://' : 'https://' ) . $host . $path;
        if ( '' !== $query ) {
            $rebuilt .= '?' . $query;
        }
        if ( '' !== $fragment ) {
            $rebuilt .= $fragment;
        }

        return $rebuilt;
    }

    /**
     * 确保附件存在 4:3 分享裁图，没有就现场裁一版并记入元数据
     *
     * 微信卡片缩略图是近方形框，站点特色图多为 1000×1500 竖版，直接用会被裁掉上下。
     * 这里按中心裁 800×600，既守住卡片比例，又保留文章配图的相关性。
     *
     * @param int $attachment_id
     * @return array|false
     */
    private static function ensure_share_size( $attachment_id ) {
        $cached = wp_get_attachment_image_src( $attachment_id, 'yimai-gso-share' );
        if ( $cached && $cached[1] >= 300 && $cached[2] >= 200 ) {
            return $cached;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return false;
        }

        // 只处理位图；SVG 没 GD 尺寸概念
        $mime = (string) get_post_mime_type( $attachment_id );
        if ( '' === $mime || 0 === strpos( $mime, 'image/svg' ) ) {
            return false;
        }

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $editor->resize( 800, 600, true );
        $target = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '-share.jpg';
        $saved  = $editor->save( $target, 'image/jpeg' );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            return false;
        }

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }
        if ( ! isset( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            $meta['sizes'] = array();
        }
        $meta['sizes']['yimai-gso-share'] = array(
            'file'      => wp_basename( $saved['path'] ),
            'width'     => (int) $saved['width'],
            'height'    => (int) $saved['height'],
            'mime-type' => 'image/jpeg',
        );
        wp_update_attachment_metadata( $attachment_id, $meta );

        $src = wp_get_attachment_image_src( $attachment_id, 'yimai-gso-share' );
        return ( $src && $src[1] >= 300 ) ? $src : false;
    }

    /**
     * 从附件里挑一个适合做分享缩略图的尺寸
     *
     * 微信卡片缩略图是近方形框：竖图（如 1000×1500）会被裁掉上下，主体只剩中间一条。
     * 所以在可用尺寸里优先横图/近方图，其次才退回原图；同时避开过大的文件。
     *
     * @param int $attachment_id
     * @return array|false wp_get_attachment_image_src() 结构
     */
    private static function pick_share_size( $attachment_id ) {
        // 首选专用裁切尺寸：正好是 4:3，且是 WP 按中心裁出来的，竖图也不会缺主体。
        // 历史附件没有这个尺寸（add_image_size 只对之后上传的生效），现场补裁一版并记进
        // 附件元数据，之后就走缓存。
        $share = self::ensure_share_size( $attachment_id );
        if ( $share ) {
            return $share;
        }

        $full = wp_get_attachment_image_src( $attachment_id, 'full' );
        if ( ! $full ) {
            return false;
        }

        $best  = $full;
        $score = -1;

        foreach ( array( 'large', 'medium_large', 'medium' ) as $size ) {
            $src = wp_get_attachment_image_src( $attachment_id, $size );
            if ( ! $src || $src[1] < 300 || $src[2] < 200 ) {
                continue;
            }
            // 宽高比越接近 1.2:1 越好；竖图按 1/ratio 折算，明显吃亏
            $ratio = $src[1] / max( 1, $src[2] );
            $s     = ( $ratio >= 1 ) ? ( 1.2 / $ratio ) : ( $ratio / 1.2 );
            $s    -= ( $src[1] > 2000 ) ? 0.15 : 0;
            if ( $s > $score ) {
                $score = $s;
                $best  = $src;
            }
        }

        return $best;
    }

    private static function guess_mime_from_url( $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
        $map  = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
        );
        return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
    }

    /**
     * 取文章分享描述：与前台 SEO 描述同源，但按微信卡片两行的显示宽度截断
     */
    public static function post_description( $post_id ) {
        $desc = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $desc ) ) $desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( empty( $desc ) ) $desc = get_post_meta( $post_id, 'rank_math_description', true );
        if ( empty( $desc ) ) $desc = get_post_meta( $post_id, 'description', true );
        if ( empty( $desc ) ) $desc = get_the_excerpt( $post_id );

        $desc = wp_strip_all_tags( (string) $desc );
        $desc = trim( preg_replace( '/\s+/u', ' ', $desc ) );

        $len = (int) YIMAI_GSO_Settings::get( 'wechat_share_desc_len', 60 );
        $len = max( 20, min( 120, $len ) );

        return mb_substr( $desc, 0, $len );
    }

    /**
     * 构建一次分享所需的全部字段（REST 接口与后台预览共用）
     *
     * @param string $url 当前页面 URL（前端 location.href 去掉 # 之后）
     * @return array
     */
    public static function build_share_payload( $url = '' ) {
        $site = (string) get_bloginfo( 'name' );
        $link = trim( (string) $url );
        if ( '' !== $link ) {
            $link = preg_replace( '/#.*$/', '', $link );
        }

        // 首页判定必须排在 url_to_postid() 之前：静态首页的根地址在部分重写规则下
        // 会被 url_to_postid() 解析成那张「首页」页面的 ID，标题就会变成 "首页"。
        $is_home = ( '' === $link );
        if ( '' !== $link ) {
            $home_path = untrailingslashit( preg_replace( '/\?.*$/', '', home_url( '/' ) ) );
            $cur_path  = untrailingslashit( preg_replace( '/\?.*$/', '', $link ) );
            $is_home   = ( 0 === strcasecmp( $home_path, $cur_path ) );
        }

        $post_id = ( ! $is_home && '' !== $link ) ? (int) url_to_postid( $link ) : 0;

        if ( $post_id ) {
            $title = (string) get_the_title( $post_id );
            if ( '' === trim( $title ) ) {
                $title = $site;
            }
            $desc = self::post_description( $post_id );
            $permalink = get_permalink( $post_id );
            if ( $permalink ) {
                $link = $permalink;
            }
        } elseif ( $is_home ) {
            $home_title = trim( (string) YIMAI_GSO_Settings::get( 'wechat_share_home_title', '' ) );
            if ( '' === $home_title ) {
                // 只给品牌名太单薄，拼一句定位；再长就交给下面的朋友圈标题截断
                $tagline    = trim( preg_replace( '/\s+/u', '', (string) get_bloginfo( 'description' ) ) );
                $tagline    = rtrim( mb_substr( $tagline, 0, 12 ), '，,。.、·' );
                $home_title = ( '' !== $tagline ) ? ( $site . '·普拉提｜' . $tagline ) : $site;
            }
            $title      = $home_title;
            $desc       = trim( (string) get_bloginfo( 'description' ) );
            $link       = home_url( '/' );
        } else {
            $title = $site;
            $desc  = trim( (string) get_bloginfo( 'description' ) );
        }

        $desc = mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $desc ) ) ), 0, 120 );

        // 朋友圈卡片标题压在图片上，显示宽度只有两行且字号大，单独给一个短标题
        $timeline_title = $title;
        if ( mb_strlen( $timeline_title ) > 22 ) {
            $cut = preg_split( '/[｜|，,。：:—\-]/u', $timeline_title );
            if ( ! empty( $cut[0] ) && mb_strlen( $cut[0] ) >= 6 ) {
                $timeline_title = $cut[0];
            }
            $timeline_title = mb_substr( $timeline_title, 0, 22 );
        }

        $image = self::resolve_share_image( $post_id, $link );

        return array(
            'title'         => $title,
            'desc'          => $desc,
            'link'          => $link ? $link : home_url( '/' ),
            'imgUrl'        => $image['url'],
            'timelineTitle' => $timeline_title,
            'imageSource'   => $image['source'],
            'imageWidth'    => $image['width'],
            'imageHeight'   => $image['height'],
            'postId'        => $post_id,
        );
    }

    /* ==================== 公众号凭据与签名 ==================== */

    private function wechat_api_error( $context, $data ) {
        $code = isset( $data['errcode'] ) ? (int) $data['errcode'] : 0;
        $msg  = isset( $data['errmsg'] ) ? (string) $data['errmsg'] : '微信公众号接口返回异常';

        $hints = array(
            40001 => 'AppSecret 不正确，或 access_token 已失效',
            40013 => 'AppID 格式不正确',
            40125 => 'AppSecret 无效，请到公众号后台「设置与开发 → 基本配置」重置后重新填写',
            40164 => '服务器出口 IP 不在公众号「IP 白名单」内',
            41004 => '缺少 AppSecret',
            45009 => '接口调用次数已达当日上限',
            48001 => '该公众号未获得此接口权限（需要已认证的服务号 / 订阅号）',
            89503 => '调用过于频繁，请稍后再试',
        );
        $hint = isset( $hints[ $code ] ) ? $hints[ $code ] : '';

        if ( 40164 === $code && preg_match( '/(\d{1,3}(?:\.\d{1,3}){3})/', $msg, $m ) ) {
            $hint .= '；把 ' . $m[1] . ' 加进「设置与开发 → 基本配置 → IP白名单」即可';
        }

        $payload = array(
            'context' => $context,
            'errcode' => $code,
            'errmsg'  => $msg,
            'hint'    => $hint,
            'time'    => current_time( 'mysql' ),
        );
        update_option( self::ERROR_OPTION, $payload, false );

        $text = '' !== $hint ? $hint : '微信公众号接口报错';
        return new WP_Error( 'yimai_gso_wx_' . $code, $text . '（errcode ' . $code . '：' . $msg . '）', $payload );
    }

    /**
     * 取 access_token（带 7000 秒缓存 + 轻量互斥锁）
     *
     * 实现已迁到共享类 YIMAI_GSO_Wx_Api：同一个 AppID 的 access_token 在微信侧是
     * 「后取覆盖前取」，分享模块和草稿箱同步各缓存一份就会互相把对方刷失效，
     * 表现是最难查的随机 40001。这里只保留同名 private 包装，让类内既有调用点
     * （get_jsapi_ticket、后台凭据自检）一处都不用改。
     *
     * @param bool $force 强制刷新
     * @return string|WP_Error
     */
    private function get_access_token( $force = false ) {
        return YIMAI_GSO_Wx_Api::get_access_token( $force );
    }

    /**
     * 取 jsapi_ticket（签名用的票据，同样 7000 秒缓存）
     *
     * @param bool $force
     * @return string|WP_Error
     */
    private function get_jsapi_ticket( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TICKET_TRANSIENT );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $token = $this->get_access_token( $attempt > 0 );
            if ( is_wp_error( $token ) ) {
                return $token;
            }

            $api = add_query_arg(
                array(
                    'access_token' => $token,
                    'type'         => 'jsapi',
                ),
                'https://api.weixin.qq.com/cgi-bin/ticket/getticket'
            );

            $res = wp_remote_get( $api, array( 'timeout' => 15 ) );
            if ( is_wp_error( $res ) ) {
                return $res;
            }

            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'yimai_gso_wx_badjson', '微信票据接口返回非 JSON 内容' );
            }

            if ( ! empty( $data['ticket'] ) && 0 === (int) ( $data['errcode'] ?? 0 ) ) {
                $ttl = isset( $data['expires_in'] ) ? ( (int) $data['expires_in'] - 200 ) : self::CACHE_TTL;
                set_transient( self::TICKET_TRANSIENT, (string) $data['ticket'], max( 120, min( self::CACHE_TTL, $ttl ) ) );
                delete_option( self::ERROR_OPTION );
                return (string) $data['ticket'];
            }

            $errcode = (int) ( $data['errcode'] ?? 0 );
            // 40001 / 42001：token 失效（可能被同 AppID 的其它系统刷掉了）→ 清缓存重试一次
            if ( in_array( $errcode, array( 40001, 42001, 40014 ), true ) && 0 === $attempt ) {
                delete_transient( self::TOKEN_TRANSIENT );
                delete_transient( self::TICKET_TRANSIENT );
                continue;
            }

            return $this->wechat_api_error( 'jsapi_ticket', $data );
        }

        return new WP_Error( 'yimai_gso_wx_ticket', '获取 jsapi_ticket 失败' );
    }

    /* ==================== REST 接口 ==================== */

    public function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_jssdk' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'url' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::REPORT_ROUTE,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_report' ),
                    'permission_callback' => '__return_true',
                ),
                array(
                    // GET：读回最近一次真机结果，方便排障时不进后台也能看到
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_report_read' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    public function rest_report_read() {
        $report = get_option( self::REPORT_OPTION, array() );
        return rest_ensure_response( is_array( $report ) ? $report : array() );
    }

    /**
     * 真机回传 wx.config 结果：写入 option + 错误日志，后台面板直接展示
     */
    public function rest_report( WP_REST_Request $request ) {
        $status = sanitize_text_field( (string) $request->get_param( 'status' ) );
        $msg    = sanitize_text_field( (string) $request->get_param( 'msg' ) );
        $page   = esc_url_raw( (string) $request->get_param( 'page' ) );
        $ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 300 ) : '';

        $report = array(
            'status' => $status,
            'msg'    => $msg,
            'page'   => $page,
            'ua'     => $ua,
            'time'   => current_time( 'mysql' ),
        );
        update_option( self::REPORT_OPTION, $report, false );

        if ( 'ready' !== $status ) {
            yimai_gso_debug_log( '微信分享卡片真机失败：' . $msg . ' @ ' . $page );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    /**
     * 禁止 CDN / 浏览器缓存签名结果：签名里的 url 与 timestamp 每次都要新鲜
     */
    public function disable_rest_cache( $result, $server, $request ) {
        if ( 0 === strpos( (string) $request->get_route(), '/' . self::REST_NAMESPACE . self::REST_ROUTE ) ) {
            // 签名里的 timestamp / noncestr 每次都必须新鲜，让 CDN 与浏览器都不要缓存
            if ( is_object( $result ) && method_exists( $result, 'header' ) ) {
                $result->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
                $result->header( 'Pragma', 'no-cache' );
            }
        }
        return $result;
    }

    public function rest_jssdk( WP_REST_Request $request ) {
        nocache_headers();

        if ( ! self::is_enabled() ) {
            return rest_ensure_response( array(
                'ok'      => false,
                'message' => '微信分享未启用或未配置公众号凭据',
            ) );
        }

        if ( ! $this->check_rate_limit() ) {
            return rest_ensure_response( array(
                'ok'      => false,
                'message' => '签名请求过于频繁',
            ) );
        }

        $url = $this->sanitize_sign_url( (string) $request->get_param( 'url' ) );
        if ( is_wp_error( $url ) ) {
            return rest_ensure_response( array( 'ok' => false, 'message' => $url->get_error_message() ) );
        }

        $signed = $this->build_signature( $url );
        if ( is_wp_error( $signed ) ) {
            return rest_ensure_response( array(
                'ok'      => false,
                'message' => $signed->get_error_message(),
                'errcode' => $signed->get_error_code(),
            ) );
        }

        $share = self::build_share_payload( $url );

        return rest_ensure_response( array(
            'ok'        => true,
            'appId'     => (string) YIMAI_GSO_Settings::get( 'wechat_app_id', '' ),
            'timestamp' => $signed['timestamp'],
            'nonceStr'  => $signed['nonce'],
            'signature' => $signed['signature'],
            'share'     => array(
                'title'         => $share['title'],
                'desc'          => $share['desc'],
                'link'          => $share['link'],
                'imgUrl'        => $share['imgUrl'],
                'timelineTitle' => $share['timelineTitle'],
            ),
        ) );
    }

    /**
     * 校验并规范化待签名 URL
     *
     * @return string|WP_Error
     */
    private function sanitize_sign_url( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return new WP_Error( 'yimai_gso_wx_nourl', '缺少 url 参数' );
        }

        // 签名用的 url 必须与浏览器地址栏一致：去掉 # 之后原样保留（esc_url_raw 会改写编码）
        $url   = preg_replace( '/#.*$/', '', $raw );
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
            return new WP_Error( 'yimai_gso_wx_badurl', 'url 不是合法地址' );
        }

        // 只给本站页面签名：别人的站点拿到签名也没用，但拒绝掉可以少暴露一次接口调用
        $allowed = $this->allowed_hosts();
        if ( ! in_array( strtolower( $parts['host'] ), $allowed, true ) ) {
            return new WP_Error( 'yimai_gso_wx_host', '该域名不在本站签名白名单内：' . $parts['host'] );
        }

        return $url;
    }

    /**
     * 生成 JS-SDK 签名
     *
     * @return array{timestamp:int,nonce:string,signature:string}|WP_Error
     */
    private function build_signature( $url ) {
        $ticket = $this->get_jsapi_ticket();
        if ( is_wp_error( $ticket ) ) {
            return $ticket;
        }

        $nonce     = wp_generate_password( 16, false, false );
        $timestamp = time();
        // 微信签名算法：四个参数按字段名字典序拼接后 sha1，顺序不能改
        $signature = sha1( 'jsapi_ticket=' . $ticket . '&noncestr=' . $nonce . '&timestamp=' . $timestamp . '&url=' . $url );

        return array(
            'timestamp' => $timestamp,
            'nonce'     => $nonce,
            'signature' => $signature,
        );
    }

    private function allowed_hosts() {
        $hosts = array();
        foreach ( array( home_url( '/' ), site_url( '/' ) ) as $u ) {
            $h = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
            if ( '' !== $h ) {
                $hosts[] = $h;
                if ( 0 === strpos( $h, 'www.' ) ) {
                    $hosts[] = substr( $h, 4 );
                } else {
                    $hosts[] = 'www.' . $h;
                }
            }
        }
        return array_values( array_unique( $hosts ) );
    }

    private function check_rate_limit() {
        $key = 'yimai_gso_wx_rate_' . md5( $this->client_ip() );
        $now = time();

        // 固定窗口计数：窗口从第一次请求起算 60 秒，过期即从 1 重新计。
        // 不能简单 set_transient( $n + 1, 60 )——那样每次请求都会续期，
        // 持续访问时计数永不衰减，第 91 次就会误伤正常访客。
        $win = get_transient( $key );
        if ( ! is_array( $win ) || empty( $win['start'] ) || ( $now - (int) $win['start'] ) >= MINUTE_IN_SECONDS ) {
            $win = array( 'start' => $now, 'n' => 0 );
        }

        if ( (int) $win['n'] >= self::RATE_LIMIT ) {
            return false;
        }

        $win['n'] = (int) $win['n'] + 1;
        set_transient( $key, $win, 2 * MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * 访客真实 IP
     *
     * 站点在 Cloudflare 后面，REMOTE_ADDR 是 CF 边缘节点——所有访客共用同一批 IP，
     * 只按 REMOTE_ADDR 限流会让整站共用一个计数器，正常流量也会被限。
     */
    private function client_ip() {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            return trim( $parts[0] );
        }
        return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /* ==================== 前端输出 ==================== */

    /**
     * 在 head 最前面记录进入页面时的真实 URL
     *
     * iOS 微信校验的是「本次 wx.config 时页面最初的 URL」，主题若调用 history API
     * 改过地址栏，签名会对不上。先存一份原样 URL，签名时优先用它。
     */
    public function output_head_bootstrap() {
        if ( ! self::is_enabled() ) {
            return;
        }
        echo '<script>window.__ymWxInitUrl=location.href;</script>' . "\n";
    }

    public function output_footer_script() {
        if ( ! self::is_enabled() ) {
            return;
        }

        $rest   = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
        $report = rest_url( self::REST_NAMESPACE . self::REPORT_ROUTE );
        $debug  = ( 1 === (int) YIMAI_GSO_Settings::get( 'wechat_share_debug', 0 ) );
        $js_api = array(
            'updateAppMessageShareData',
            'updateTimelineShareData',
            'onMenuShareAppMessage',
            'onMenuShareTimeline',
            'onMenuShareQQ',
            'onMenuShareQZone',
        );
        ?>
<script id="yimai-gso-wechat-share-js">
(function () {
    var ua = navigator.userAgent || '';
    if (ua.indexOf('MicroMessenger') === -1) { return; }

    var REST = <?php echo wp_json_encode( $rest ); ?>;
    var REPORT = <?php echo wp_json_encode( $report ); ?>;
    var DEBUG = <?php echo $debug ? 'true' : 'false'; ?>;
    var JS_API = <?php echo wp_json_encode( $js_api ); ?>;
    var initUrl = (window.__ymWxInitUrl || location.href).split('#')[0];

    function log() {
        if (window.console && console.log) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[一麦GSO/微信分享]');
            console.log.apply(console, args);
        }
    }

    /**
     * 带 ?ymwxdebug=1 时强制显示角标
     *
     * 配置期不想为了看一眼状态反复去后台开关，直接在手机上手改地址栏加参数即可；
     * 也让「到底跑没跑到这一步」在真机上一眼可见。
     */
    function isDebug() {
        return DEBUG || /[?&]ymwxdebug=1/.test(location.search);
    }

    function badge(text, color) {
        if (!isDebug() || !document.body) { return; }
        var el = document.getElementById('ymwx-debug-badge');
        if (!el) {
            el = document.createElement('div');
            el.id = 'ymwx-debug-badge';
            el.style.cssText = 'position:fixed;left:8px;bottom:8px;right:8px;z-index:2147483647;'
                + 'padding:6px 10px;border-radius:6px;font-size:12px;line-height:1.5;color:#fff;opacity:.92;'
                + 'font-family:-apple-system,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.25);'
                + 'white-space:pre-wrap;word-break:break-all;';
            document.body.appendChild(el);
        }
        el.style.background = color || '#111';
        el.textContent = text;
    }

    // 1) 载入官方 SDK（主域失败回退备用域）
    function loadSdk(cb) {
        if (window.wx && window.wx.config) { return cb(); }
        var srcs = [
            'https://res.wx.qq.com/open/js/jweixin-1.6.0.js',
            'https://res2.wx.qq.com/open/js/jweixin-1.6.0.js'
        ];
        (function next(i) {
            if (i >= srcs.length) {
                log('SDK 载入失败');
                badge('微信 SDK 载入失败', '#b91c1c');
                report('sdkfail', 'jweixin 两个 CDN 都载入失败');
                return;
            }
            var s = document.createElement('script');
            s.src = srcs[i];
            s.async = true;
            s.onload = function () { cb(); };
            s.onerror = function () { next(i + 1); };
            (document.head || document.documentElement).appendChild(s);
        })(0);
    }

    // 2) 取签名（签名必须针对当前真实 URL，所以不能写死在页面里）
    function fetchConfig(cb) {
        var url = REST + (REST.indexOf('?') > -1 ? '&' : '?') + 'url=' + encodeURIComponent(initUrl);
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 8000;
        xhr.onload = function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            cb(data);
        };
        xhr.onerror = function () { cb(null); };
        xhr.ontimeout = function () { cb(null); };
        xhr.send();
    }

    // 3) 配置并覆盖分享卡片
    // 真机结果回传：失败原因只在客户端 errMsg 里，服务端推不出来。
    // 一律上报（只写一条 option，不产生额外请求），这样用户在手机上正常打开
    // 一次页面就能在后台看到结果，不必记得加调试参数。
    function report(status, msg) {
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', REPORT, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.send('status=' + encodeURIComponent(status)
                + '&msg=' + encodeURIComponent(msg || '')
                + '&page=' + encodeURIComponent(initUrl));
        } catch (e) {}
    }

    function apply(cfg) {
        if (!cfg || !cfg.ok) {
            log('未启用或签名失败：', cfg && cfg.message);
            badge('分享卡片未生效：' + ((cfg && cfg.message) || '签名失败'), '#b91c1c');
            settle('signfail', (cfg && cfg.message) || '签名失败');
            return;
        }

        window.wx.config({
            debug: DEBUG,
            appId: cfg.appId,
            timestamp: cfg.timestamp,
            nonceStr: cfg.nonceStr,
            signature: cfg.signature,
            jsApiList: JS_API
        });

        window.wx.ready(function () {
            var s = cfg.share || {};
            var appMsg = { title: s.title, desc: s.desc, link: s.link, imgUrl: s.imgUrl };
            var timeline = { title: s.timelineTitle || s.title, link: s.link, imgUrl: s.imgUrl };

            // 逐个接口回报真实结果：某个 API 没权限时会单独 fail，不报这里就看不出来。
            // 不用 Object.assign——老安卓微信 WebView 里可能没有。
            var failures = [];
            var lastOk = {};
            function run(name, args, quiet) {
                if (typeof window.wx[name] !== 'function') {
                    if (!quiet) { failures.push(name + ':unsupported'); }
                    return;
                }
                var payload = { title: args.title, link: args.link, imgUrl: args.imgUrl };
                if (args.desc) { payload.desc = args.desc; }
                payload.fail = function (r) {
                    if (!quiet) { failures.push(name + ':' + ((r && r.errMsg) || 'fail')); }
                };
                payload.success = function () { lastOk[name] = 1; };
                window.wx[name](payload);
            }
            run('updateAppMessageShareData', appMsg);
            run('onMenuShareAppMessage', appMsg);
            run('updateTimelineShareData', timeline);
            run('onMenuShareTimeline', timeline);

            // 重复下发：微信在部分机型上会在「···」面板打开时才取分享信息，
            // 首屏设太早会被覆盖。分两拍补发，report 里带上成功计数便于验证。
            var reapply = function () {
                run('updateAppMessageShareData', appMsg, true);
                run('updateTimelineShareData', timeline, true);
            };
            setTimeout(reapply, 1200);
            setTimeout(reapply, 3000);

            if (failures.length) {
                log('部分分享接口失败', failures);
                badge('分享接口失败：' + failures.join(' | '), '#f59e0b');
                settle('apifail', failures.join(' | '));
            } else {
                log('分享卡片已就绪', appMsg);
                badge('分享卡片已就绪 ✓', '#15803d');
                settle('ready', 'ok·' + Object.keys(lastOk).length);
            }
        });

        window.wx.error(function (res) {
            log('wx.config 失败', res);
            badge('wx.config 失败：' + (res && res.errMsg), '#b91c1c');
            settle('error', (res && res.errMsg) || 'unknown');
        });
    }

    // 脚本跑到这里本身就是一条信息：能上报说明页面、脚本、REST 都通
    report('boot', 'start');

    var settled = false;
    function settle(status, msg) {
        settled = true;
        report(status, msg);
    }
    // 超时兜底：wx.config 既不 ready 也不 error 时，10 秒后把「卡在 config」上报出去，
    // 否则页面静默停住，后台只能看到 boot，看不出到底断在哪
    setTimeout(function () {
        if (!settled) { settle('configtimeout', 'wx.config 10 秒内无 ready 也无 error'); }
    }, 10000);

    loadSdk(function () {
        report('sdk', 'ok');
        fetchConfig(function (cfg) {
            if (!cfg) { report('fetchfail', '签名接口无响应或超时'); }
            apply(cfg);
        });
    });
})();
</script>
        <?php
    }

    /* ==================== 公众号域名验证文件托管 ==================== */

    /**
     * 公众号后台配置「JS接口安全域名」时要求把 MP_verify_xxx.txt 放到网站根目录。
     * 这里直接按 token 托管，省掉用户上服务器传文件的步骤。
     */
    public function maybe_serve_verify_file() {
        $token = trim( (string) YIMAI_GSO_Settings::get( 'wechat_mp_verify_token', '' ) );
        if ( '' === $token ) {
            return;
        }
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }
        $path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        if ( '' === $path ) {
            return;
        }
        // 站点装在子目录时（如 /blog/），先剥掉子目录前缀再比对
        $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
            $path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
        }
        if ( '/MP_verify_' . $token . '.txt' === $path ) {
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo $token;
            exit;
        }
    }

    /* ==================== 后台面板 ==================== */

    public function register_admin_page() {
        add_submenu_page(
            'yimai-gso-center',
            '微信分享卡片',
            '微信分享卡片',
            'manage_options',
            'yimai-gso-wechat',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * 只在「微信分享卡片」页加载本页专属样式。
     *
     * 为什么不并进 class-admin.php 的 enqueue_assets：那里的四个 handle 是所有插件页共用的
     * 设计系统底座，而 wechat.css 只有这一页用得上（手机分享卡预览、诊断条目、凭据状态的特殊样式），
     * 挂在全局等于让其它后台页白下载一份；页面级资源留在本类，改本页样式也不会牵动主面板。
     *
     * 版本号取 filemtime：改完 CSS 立刻生效，免得为了看一眼效果去 bump 插件版本。
     * 依赖 yimai-gso-components，保证输出顺序在 components.css 之后（同依赖按入队顺序）。
     */
    public function enqueue_admin_assets( $hook ) {
        // 页面 hook 形如 yimai-gso-center_page_yimai-gso-wechat，只认自己这一页
        if ( false === strpos( (string) $hook, 'yimai-gso-wechat' ) ) {
            return;
        }

        $relative = 'admin/css/wechat.css';
        $version  = YIMAI_GSO_VERSION;
        $absolute = YIMAI_GSO_PLUGIN_DIR . $relative;

        if ( file_exists( $absolute ) ) {
            $stamp = filemtime( $absolute );
            if ( $stamp ) {
                $version = (string) $stamp;
            }
        }

        wp_enqueue_style(
            'yimai-gso-wechat',
            YIMAI_GSO_PLUGIN_URL . $relative,
            array( 'yimai-gso-components' ),
            $version
        );
    }

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足' );
        }
        check_admin_referer( 'yimai_gso_wechat_save' );

        YIMAI_GSO_Settings::set( 'wechat_share_enabled', isset( $_POST['wechat_share_enabled'] ) ? 1 : 0 );

        $app_id = isset( $_POST['wechat_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wechat_app_id'] ) ) : '';
        YIMAI_GSO_Settings::set( 'wechat_app_id', $app_id );

        $secret = isset( $_POST['wechat_app_secret'] ) ? trim( (string) wp_unslash( $_POST['wechat_app_secret'] ) ) : '';
        if ( '' !== $secret && ! YIMAI_GSO_Settings::is_masked_value( $secret ) ) {
            YIMAI_GSO_Settings::set( 'wechat_app_secret', sanitize_text_field( $secret ) );
        }

        $home_title = isset( $_POST['wechat_share_home_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wechat_share_home_title'] ) ) : '';
        YIMAI_GSO_Settings::set( 'wechat_share_home_title', $home_title );

        $cover = isset( $_POST['wechat_share_cover'] ) ? esc_url_raw( wp_unslash( $_POST['wechat_share_cover'] ) ) : '';
        YIMAI_GSO_Settings::set( 'wechat_share_cover', $cover );

        $desc_len = isset( $_POST['wechat_share_desc_len'] ) ? absint( $_POST['wechat_share_desc_len'] ) : 60;
        YIMAI_GSO_Settings::set( 'wechat_share_desc_len', max( 20, min( 120, $desc_len ) ) );

        $verify = isset( $_POST['wechat_mp_verify_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wechat_mp_verify_token'] ) ) : '';
        $verify = preg_replace( '/[^A-Za-z0-9_\-]/', '', $verify );
        if ( 0 === stripos( $verify, 'MP_verify_' ) ) {
            $verify = preg_replace( '/^MP_verify_/i', '', $verify );
            $verify = preg_replace( '/\.txt$/i', '', $verify );
        }
        YIMAI_GSO_Settings::set( 'wechat_mp_verify_token', $verify );

        YIMAI_GSO_Settings::set( 'wechat_share_debug', isset( $_POST['wechat_share_debug'] ) ? 1 : 0 );

        // 凭据变更后必须清缓存，否则还在用旧 AppID 换来的 token
        delete_transient( self::TOKEN_TRANSIENT );
        delete_transient( self::TICKET_TRANSIENT );
        delete_transient( self::LIBRARY_TRANSIENT );

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'yimai-gso-wechat', 'ymwx' => 'saved' ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function ajax_clear_cache() {
        check_ajax_referer( 'yimai_gso_wechat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
        if ( 'report' === $target ) {
            delete_option( self::REPORT_OPTION );
            wp_send_json_success( array( 'message' => '已清除真机诊断记录' ) );
        }
        delete_transient( self::TOKEN_TRANSIENT );
        delete_transient( self::TICKET_TRANSIENT );
        delete_transient( self::LIBRARY_TRANSIENT );
        delete_option( self::ERROR_OPTION );
        wp_send_json_success( array( 'message' => '已清除 access_token / jsapi_ticket 缓存与错误记录' ) );
    }

    public function ajax_test_token() {
        check_ajax_referer( 'yimai_gso_wechat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $token = $this->get_access_token( true );
        if ( is_wp_error( $token ) ) {
            wp_send_json_error( array(
                'message' => $token->get_error_message(),
                'errcode' => $token->get_error_code(),
            ) );
        }

        $ticket = $this->get_jsapi_ticket( true );
        if ( is_wp_error( $ticket ) ) {
            wp_send_json_error( array(
                'message' => 'access_token 已获取，但 jsapi_ticket 失败：' . $ticket->get_error_message(),
            ) );
        }

        wp_send_json_success( array(
            'message'     => '凭据可用：access_token 与 jsapi_ticket 均已拿到',
            'tokenMasked' => substr( $token, 0, 6 ) . '…' . substr( $token, -6 ),
            'ticketMasked' => substr( $ticket, 0, 8 ) . '…' . substr( $ticket, -6 ),
            'host'        => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
        ) );
    }

    /**
     * 抓取自检：用微信内置浏览器的 UA 真实抓一遍目标页，逐项检查卡片要素
     */
    public function ajax_probe() {
        check_ajax_referer( 'yimai_gso_wechat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $url = isset( $_POST['url'] ) ? trim( (string) wp_unslash( $_POST['url'] ) ) : '';
        if ( '' === $url ) {
            $url = home_url( '/' );
        }
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            wp_send_json_error( array( 'message' => '请填写含 http(s):// 的完整地址' ) );
        }

        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
            . '(KHTML, like Gecko) Mobile/15E148 MicroMessenger/8.0.49(0x18003128) NetType/WIFI Language/zh_CN';

        $res = wp_remote_get( $url, array(
            'timeout'     => 20,
            'redirection' => 10,
            'user-agent'  => $ua,
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );

        if ( is_wp_error( $res ) ) {
            $msg = $res->get_error_message();
            $hint = ( false !== stripos( $msg, 'redirect' ) )
                ? '：跳到上限仍未落地，基本可以确定存在指向自己的 301 规则（404 模块里 source 与 target 相同）'
                : '';
            wp_send_json_error( array( 'message' => '抓取失败' . $hint . '（' . $msg . '）' ) );
        }

        $code  = (int) wp_remote_retrieve_response_code( $res );
        $body  = (string) wp_remote_retrieve_body( $res );
        $ctype = (string) wp_remote_retrieve_header( $res, 'content-type' );

        // 重定向后的最终地址（http→https、去 www 之类会被记录，便于判断是否抓到了别的页面）
        // http_response 多跳时是数组，取最后一跳；单跳时是对象。
        $final_url = $url;
        $http_resp = isset( $res['http_response'] ) ? $res['http_response'] : null;
        if ( is_array( $http_resp ) && ! empty( $http_resp ) ) {
            $http_resp = end( $http_resp );
        }
        if ( is_object( $http_resp ) && method_exists( $http_resp, 'get_response_object' ) ) {
            $obj = $http_resp->get_response_object();
            if ( is_object( $obj ) && ! empty( $obj->url ) ) {
                $final_url = (string) $obj->url;
            }
        }

        $title   = self::extract_tag_text( $body, 'title' );
        $desc    = self::extract_meta( $body, 'name', 'description' );
        $ogTitle = self::extract_meta( $body, 'property', 'og:title' );
        $ogDesc  = self::extract_meta( $body, 'property', 'og:description' );
        $ogImage = self::extract_meta( $body, 'property', 'og:image' );
        $imgSrc  = self::extract_link_rel( $body, 'image_src' );

        if ( '' === $desc && '' !== $ogDesc ) {
            $desc = $ogDesc;
        }

        $image_url = '' !== $ogImage ? $ogImage : $imgSrc;
        $img = array(
            'url'      => $image_url,
            'ok'       => false,
            'type'     => '',
            'width'    => 0,
            'height'   => 0,
            'bytes'    => 0,
            'note'     => '',
            'nonAscii' => (bool) preg_match( '/[^\x20-\x7E]/', $image_url ),
        );

        if ( '' !== $image_url ) {
            $ireq = wp_remote_get( $image_url, array(
                'timeout'             => 20,
                'limit_response_size' => 4 * MB_IN_BYTES,
                'user-agent'          => $ua,
            ) );
            if ( is_wp_error( $ireq ) ) {
                $img['note'] = '图片抓取失败：' . $ireq->get_error_message();
            } else {
                $icode = (int) wp_remote_retrieve_response_code( $ireq );
                $ibody = (string) wp_remote_retrieve_body( $ireq );
                $img['type']  = (string) wp_remote_retrieve_header( $ireq, 'content-type' );
                $img['bytes'] = strlen( $ibody );
                $size = @getimagesizefromstring( $ibody );
                if ( $size ) {
                    $img['width']  = (int) $size[0];
                    $img['height'] = (int) $size[1];
                }
                $img['ok'] = ( 200 === $icode && $size );
                if ( ! $img['ok'] ) {
                    $img['note'] = 'HTTP ' . $icode . '，或返回的不是图片';
                }
            }
        }

        // 逐项判定
        $checks = array();

        $checks[] = self::check(
            '页面可达',
            ( 200 === $code ),
            'HTTP ' . $code . ( $ctype ? '（' . $ctype . '）' : '' )
                . ( $final_url !== $url ? '，重定向到 ' . $final_url : '' )
        );

        // 301 指回自己：微信抓取器跟到上限就放弃，卡片自然什么都没有
        $self_loop = ( $code >= 300 && $code < 400
            && untrailingslashit( $final_url ) === untrailingslashit( $url ) );
        if ( $self_loop ) {
            $checks[] = self::check(
                '未陷入重定向死循环',
                false,
                'URL 301 跳回了自己（x-redirect-by: ' . (string) wp_remote_retrieve_header( $res, 'x-redirect-by' ) . '）'
                    . '，微信抓到上限就放弃。多为 404 模块里有一条 source 与 target 相同的重定向规则',
                'fail'
            );
        }

        $title_len = mb_strlen( $title );
        $checks[] = self::check(
            '页面标题',
            ( $title_len > 0 && $title_len <= 45 ),
            $title_len > 0 ? ( $title_len . ' 字：' . $title ) : '未读取到 <title>',
            $title_len > 45 ? 'warn' : 'pass'
        );

        $desc_len = mb_strlen( $desc );
        $checks[] = self::check(
            '描述 meta',
            ( $desc_len >= 20 && $desc_len <= 120 ),
            $desc_len > 0 ? ( $desc_len . ' 字' ) : '缺少 <meta name="description">',
            ( $desc_len > 0 && ( $desc_len < 20 || $desc_len > 120 ) ) ? 'warn' : ( $desc_len > 0 ? 'pass' : 'fail' )
        );

        $checks[] = self::check(
            'og:title',
            ( '' !== $ogTitle ),
            '' !== $ogTitle ? $ogTitle : '缺失（微信卡片会退回 <title>）',
            '' !== $ogTitle ? 'pass' : 'warn'
        );

        $checks[] = self::check(
            'og:image',
            ( '' !== $ogImage ),
            '' !== $ogImage ? ( $ogImage ) : '缺失（卡片无缩略图）',
            '' !== $ogImage ? 'pass' : 'fail'
        );

        $checks[] = self::check(
            '图片可访问且是位图',
            $img['ok'],
            $img['ok'] ? ( $img['width'] . '×' . $img['height'] . ' · ' . round( $img['bytes'] / 1024, 1 ) . ' KB · ' . $img['type'] )
                       : ( $img['note'] ?: '未取到图片' ),
            $img['ok'] ? 'pass' : 'fail'
        );

        if ( $img['ok'] ) {
            $checks[] = self::check(
                '图片尺寸 ≥ 300×300',
                ( $img['width'] >= 300 && $img['height'] >= 300 ),
                $img['width'] . '×' . $img['height'],
                ( $img['width'] >= 300 && $img['height'] >= 300 ) ? 'pass' : 'warn'
            );

            $bad_type = ( false !== stripos( $img['type'], 'svg' ) || false !== stripos( $img['type'], 'webp' ) );
            $checks[] = self::check(
                '图片格式适合微信',
                ! $bad_type,
                $bad_type ? ( '当前是 ' . $img['type'] . '，微信分享卡片可能不显示，建议换成 JPG/PNG' ) : $img['type'],
                $bad_type ? 'warn' : 'pass'
            );

            $checks[] = self::check(
                '图片体积 < 300 KB',
                ( $img['bytes'] > 0 && $img['bytes'] < 300 * 1024 ),
                round( $img['bytes'] / 1024, 1 ) . ' KB（过大时微信会放弃缩略图）',
                ( $img['bytes'] > 0 && $img['bytes'] < 300 * 1024 ) ? 'pass' : 'warn'
            );
        }

        $checks[] = self::check(
            '图片 URL 为 ASCII',
            ! $img['nonAscii'],
            $img['nonAscii'] ? 'URL 里有中文/空格，微信抓取器容易失败，请把文件重命名为英文' : '纯 ASCII',
            $img['nonAscii'] ? 'warn' : 'pass'
        );

        $checks[] = self::check(
            '页面走 HTTPS',
            ( 0 === stripos( $url, 'https://' ) ),
            ( 0 === stripos( $url, 'https://' ) ) ? 'HTTPS' : 'HTTP（微信内会提示不安全，卡片也可能不生成）',
            ( 0 === stripos( $url, 'https://' ) ) ? 'pass' : 'fail'
        );

        $share = self::build_share_payload( $url );

        wp_send_json_success( array(
            'url'      => $url,
            'httpCode' => $code,
            'checks'   => $checks,
            'image'    => $img,
            'preview'  => array(
                'title' => '' !== $share['title'] ? $share['title'] : $title,
                'desc'  => '' !== $share['desc'] ? $share['desc'] : $desc,
                'img'   => '' !== $share['imgUrl'] ? $share['imgUrl'] : $image_url,
                'host'  => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
            ),
        ) );
    }

    private static function check( $label, $pass, $note, $status = '' ) {
        if ( '' === $status ) {
            $status = $pass ? 'pass' : 'fail';
        }
        return array(
            'label'  => $label,
            'status' => $status,
            'note'   => $note,
        );
    }

    private static function extract_tag_text( $html, $tag ) {
        if ( preg_match( '#<' . preg_quote( $tag, '#' ) . '[^>]*>(.*?)</' . preg_quote( $tag, '#' ) . '>#is', $html, $m ) ) {
            return trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
        }
        return '';
    }

    /**
     * 从 HTML 里取 <meta {attr}="{value}" content="...">，两种属性顺序都试
     */
    private static function extract_meta( $html, $attr, $value ) {
        $a = preg_quote( $attr, '#' );
        $v = preg_quote( $value, '#' );

        if ( preg_match( '#<meta[^>]+' . $a . '\s*=\s*["\']' . $v . '["\'][^>]*content\s*=\s*["\'](.*?)["\']#is', $html, $m ) ) {
            return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }
        if ( preg_match( '#<meta[^>]+content\s*=\s*["\'](.*?)["\'][^>]*' . $a . '\s*=\s*["\']' . $v . '["\']#is', $html, $m ) ) {
            return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }
        return '';
    }

    private static function extract_link_rel( $html, $rel ) {
        if ( preg_match( '#<link[^>]+rel\s*=\s*["\']' . preg_quote( $rel, '#' ) . '["\'][^>]*href\s*=\s*["\'](.*?)["\']#is', $html, $m ) ) {
            return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }
        return '';
    }

    /* ==================== 面板渲染 ==================== */

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $enabled    = ( 1 === (int) YIMAI_GSO_Settings::get( 'wechat_share_enabled', 0 ) );
        $app_id     = (string) YIMAI_GSO_Settings::get( 'wechat_app_id', '' );
        $secret     = (string) YIMAI_GSO_Settings::get_masked( 'wechat_app_secret', '' );
        $has_secret = ( '' !== trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_secret', '' ) ) );
        $home_title = (string) YIMAI_GSO_Settings::get( 'wechat_share_home_title', '' );
        $cover      = (string) YIMAI_GSO_Settings::get( 'wechat_share_cover', '' );
        $desc_len   = (int) YIMAI_GSO_Settings::get( 'wechat_share_desc_len', 60 );
        $verify     = (string) YIMAI_GSO_Settings::get( 'wechat_mp_verify_token', '' );
        $debug      = ( 1 === (int) YIMAI_GSO_Settings::get( 'wechat_share_debug', 0 ) );

        $token_ok  = (bool) get_transient( self::TOKEN_TRANSIENT );
        $ticket_ok = (bool) get_transient( self::TICKET_TRANSIENT );
        $last_err  = get_option( self::ERROR_OPTION, array() );
        $host      = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $site_home = home_url( '/' );
        ?>
        <div class="wrap yimai-gso-wrap">
            <div class="yimai-gso-page">
                <div class="yimai-gso-page-head">
                    <div>
                        <h1><span class="dashicons dashicons-share"></span>微信分享卡片</h1>
                        <p class="yimai-gso-page-desc">
                            微信内「发送给朋友 / 分享到朋友圈」时只有一个裸链接，是因为页面没有接入微信 JS-SDK，
                            微信抓不到信息就只显示一条 URL。这里把签名、分享字段和抓取元信息一次配好。
                        </p>
                    </div>
                    <div class="yimai-gso-actions">
                        <button type="button" class="yimai-gso-btn-modern-secondary" id="ymwx-clear-cache">清除 token / ticket 缓存</button>
                        <button type="button" class="yimai-gso-btn-modern-primary" id="ymwx-test-token">测试公众号凭据</button>
                        <span class="yimai-gso-wx-inline-result" id="ymwx-token-result" aria-live="polite"></span>
                    </div>
                </div>

                <?php if ( isset( $_GET['ymwx'] ) && 'saved' === $_GET['ymwx'] ) : ?>
                    <div class="yimai-gso-notice is-success"><p>设置已保存，缓存已重置。</p></div>
                <?php endif; ?>

                <div class="yimai-gso-stat-grid">
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">公众号凭据</p>
                        <p class="yimai-gso-stat-v <?php echo ( $app_id && $has_secret ) ? 'is-pass' : 'is-fail'; ?>">
                            <?php echo ( $app_id && $has_secret ) ? '已配置' : '未配置'; ?>
                        </p>
                    </div>
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">access_token 缓存</p>
                        <p class="yimai-gso-stat-v <?php echo $token_ok ? 'is-pass' : 'is-muted'; ?>"><?php echo $token_ok ? '有效' : '无'; ?></p>
                    </div>
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">jsapi_ticket 缓存</p>
                        <p class="yimai-gso-stat-v <?php echo $ticket_ok ? 'is-pass' : 'is-muted'; ?>"><?php echo $ticket_ok ? '有效' : '无'; ?></p>
                    </div>
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">站点域名</p>
                        <p class="yimai-gso-stat-v"><?php echo esc_html( $host ); ?></p>
                    </div>
                </div>

            <?php
            $err_hint = isset( $last_err['hint'] ) ? (string) $last_err['hint'] : '';
            $err_msg  = isset( $last_err['errmsg'] ) ? (string) $last_err['errmsg'] : '';
            $err_code = isset( $last_err['errcode'] ) ? (int) $last_err['errcode'] : 0;
            $err_time = isset( $last_err['time'] ) ? (string) $last_err['time'] : '';
            ?>
            <?php if ( '' !== $err_msg ) : ?>
                <div class="yimai-gso-notice is-error">
                    <p><strong>最近一次接口错误：</strong><?php echo esc_html( '' !== $err_hint ? $err_hint : $err_msg ); ?></p>
                    <p class="yimai-gso-wx-notice-meta">errcode <?php echo $err_code; ?> ·
                        <?php echo esc_html( $err_msg ); ?> · <?php echo esc_html( $err_time ); ?></p>
                </div>
            <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="yimai_gso_wechat_save" />
                    <?php wp_nonce_field( 'yimai_gso_wechat_save' ); ?>

                    <div class="yimai-gso-section">
                        <div class="yimai-gso-section-head">
                            <h2><span class="dashicons dashicons-admin-network"></span>一、公众号与开关</h2>
                            <span class="yimai-gso-badge <?php echo ( $app_id && $has_secret ) ? 'is-pass' : 'is-warn'; ?>"><?php echo ( $app_id && $has_secret ) ? '凭据已填' : '待填凭据'; ?></span>
                        </div>
                        <div class="yimai-gso-section-body">
                            <div class="yimai-gso-field">
                                <span class="yimai-gso-field-label">启用微信分享卡片</span>
                                <div class="yimai-gso-field-control">
                                    <label><input type="checkbox" name="wechat_share_enabled" value="1" <?php checked( $enabled ); ?> /> 在微信内打开页面时接管「发送给朋友 / 分享到朋友圈」的标题、描述与缩略图</label>
                                    <p class="yimai-gso-field-hint">关闭后前台不输出任何微信脚本，页面行为回到现状。</p>
                                </div>
                            </div>
                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="wechat_app_id">AppID</label>
                                <div class="yimai-gso-field-control">
                                    <input type="text" id="wechat_app_id" name="wechat_app_id" value="<?php echo esc_attr( $app_id ); ?>" placeholder="wx1234567890abcdef" autocomplete="off" />
                                    <p class="yimai-gso-field-hint">公众号后台「设置与开发 → 基本配置 → 开发者ID」里的 AppID。</p>
                                </div>
                            </div>
                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="wechat_app_secret">AppSecret</label>
                                <div class="yimai-gso-field-control">
                                    <input type="text" id="wechat_app_secret" name="wechat_app_secret" value="<?php echo esc_attr( $secret ); ?>" placeholder="<?php echo $has_secret ? '已保存（留空或保持掩码则不修改）' : 'AppSecret'; ?>" autocomplete="off" />
                                    <p class="yimai-gso-field-hint">加密存库，页面只显示后 4 位。公众号 AppSecret 泄露后请到后台重置。</p>
                                </div>
                            </div>
                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="wechat_mp_verify_token">公众号校验文件托管</label>
                                <div class="yimai-gso-field-control">
                                    <input type="text" id="wechat_mp_verify_token" name="wechat_mp_verify_token" value="<?php echo esc_attr( $verify ); ?>" placeholder="例如 4QwXyz123abcDEF" />
                                    <p class="yimai-gso-field-hint">
                                        配置「JS接口安全域名」时微信要求根目录有 <code>MP_verify_xxxx.txt</code>。
                                        把 xxxx 部分填在这里，插件会直接托管这个文件，不用上服务器传。
                                        <?php if ( '' !== $verify ) : ?>
                                            <br>当前应可访问：<a href="<?php echo esc_url( home_url( '/MP_verify_' . $verify . '.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/MP_verify_' . $verify . '.txt' ) ); ?></a>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="yimai-gso-field">
                                <span class="yimai-gso-field-label">调试角标</span>
                                <div class="yimai-gso-field-control">
                                    <label><input type="checkbox" name="wechat_share_debug" value="1" <?php checked( $debug ); ?> /> 在微信内页面左下角显示分享状态角标（配置完记得关掉）</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="yimai-gso-section">
                        <div class="yimai-gso-section-head">
                            <h2><span class="dashicons dashicons-editor-textcolor"></span>二、分享文案</h2>
                            <span class="yimai-gso-badge is-muted">选填</span>
                        </div>
                        <div class="yimai-gso-section-body">
                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="wechat_share_home_title">首页分享标题</label>
                                <div class="yimai-gso-field-control">
                                    <input type="text" id="wechat_share_home_title" name="wechat_share_home_title" value="<?php echo esc_attr( $home_title ); ?>" placeholder="例如：一麦瑜伽·普拉提｜宁波高端女性瑜伽空间" />
                                    <p class="yimai-gso-field-hint">首页和列表页没有文章标题可用，留空则回落到站点名「<?php echo esc_html( get_bloginfo( 'name' ) ); ?>」。建议 20 字内。</p>
                                </div>
                            </div>
                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="wechat_share_desc_len">描述长度</label>
                                <div class="yimai-gso-field-control">
                                    <div class="yimai-gso-wx-inline">
                                        <input type="number" id="wechat_share_desc_len" class="yimai-gso-wx-num" min="20" max="120" step="1" name="wechat_share_desc_len" value="<?php echo (int) $desc_len; ?>" />
                                        <span class="yimai-gso-wx-unit">字</span>
                                    </div>
                                    <p class="yimai-gso-field-hint">微信好友卡片描述区大约显示两行，超过会被省略号截断。建议 50–70。</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="yimai-gso-section">
                        <div class="yimai-gso-section-head">
                            <h2><span class="dashicons dashicons-format-image"></span>三、分享封面</h2>
                        </div>
                        <div class="yimai-gso-section-body">
                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="ymwx-cover">分享封面图</label>
                                <div class="yimai-gso-field-control">
                                    <input type="text" id="ymwx-cover" name="wechat_share_cover" value="<?php echo esc_attr( $cover ); ?>" placeholder="https://<?php echo esc_attr( $host ); ?>/wp-content/uploads/...jpg" />
                                    <div class="yimai-gso-actions">
                                        <button type="button" class="button" id="ymwx-cover-pick">从媒体库选择</button>
                                    </div>
                                    <p class="yimai-gso-field-hint">
                                        文章有特色图时优先用特色图；没有特色图（以及特色图是 WebP 时）用这张兜底。
                                        要求：JPG/PNG、≥ 500×500、单张 &lt; 300 KB、文件名用英文。
                                    </p>
                                    <?php if ( '' !== $cover ) : ?>
                                        <p class="yimai-gso-wx-cover-preview"><img src="<?php echo esc_url( $cover ); ?>" alt="" /></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="yimai-gso-actions">
                        <button type="submit" class="yimai-gso-btn-modern-primary">保存设置</button>
                    </div>
                </form>

                <div class="yimai-gso-section">
                    <div class="yimai-gso-section-head">
                        <h2><span class="dashicons dashicons-smartphone"></span>四、真机诊断</h2>
                    </div>
                    <div class="yimai-gso-section-body">
                <?php
                // 安全域名只认「不带 www / 带 www」其中一种，写错了服务端完全看不出来，
                // 只有真机 wx.config 会失败。这里把两种写法和生效状态摆出来，先排除这一项。
                $bare_host = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
                $report    = get_option( self::REPORT_OPTION, array() );
                $rep_msg   = isset( $report['msg'] ) ? (string) $report['msg'] : '';
                $rep_stat  = isset( $report['status'] ) ? (string) $report['status'] : '';
                $rep_time  = isset( $report['time'] ) ? (string) $report['time'] : '';
                $rep_page  = isset( $report['page'] ) ? (string) $report['page'] : '';

                $verdict = '';
                if ( '' !== $rep_stat ) {
                    if ( 'ready' === $rep_stat ) {
                        $verdict = array( 'ok', '真机已就绪：wx.config 通过，分享卡片应该正常出图出标题了' );
                    } elseif ( 'boot' === $rep_stat ) {
                        $verdict = array( 'bad', '脚本跑到了但没走完：连 SDK 都没载入成功，多看页面报错' );
                    } elseif ( 'configtimeout' === $rep_stat ) {
                        $verdict = array( 'bad', '卡在 wx.config：10 秒内既不成功也不报错，通常是签名接口被 CDN/WAF 挡掉或超时' );
                    } elseif ( 'sdk' === $rep_stat ) {
                        $verdict = array( 'bad', 'SDK 载入了但 wx.config 一直没回调：签名接口大概率被拦（停留在这一步本身就是证据）' );
                    } elseif ( false !== stripos( $rep_msg, 'invalid signature' ) ) {
                        $verdict = array( 'bad', '签名不匹配：多为「JS接口安全域名」和当前访问域名不一致，或页面 URL 带参数时签名用的不是同一个 URL' );
                    } elseif ( false !== stripos( $rep_msg, 'invalid domain' ) || false !== stripos( $rep_msg, 'invalid url domain' ) ) {
                        $verdict = array( 'bad', '域名不在公众号「JS接口安全域名」里：必须与访问域名完全一致，www 与不带 www 视为两个域名' );
                    } elseif ( false !== stripos( $rep_msg, 'permission' ) || false !== stripos( $rep_msg, 'offline' ) ) {
                        $verdict = array( 'bad', '公众号没有 JS 接口权限：多为未完成微信认证，或该 AppID 是测试号/订阅号未认证' );
                    } elseif ( 'signfail' === $rep_stat ) {
                        $verdict = array( 'bad', '签名接口本身失败：看上面的最近错误与「测试公众号凭据」结果' );
                    } elseif ( 'fetchfail' === $rep_stat ) {
                        $verdict = array( 'bad', '签名接口没有响应：多为 CDN/WAF 拦截或 REST 被安全插件禁用，直接访问 /wp-json/yimai-gso/v1/wechat-jssdk 看返回' );
                    } elseif ( 'sdkfail' === $rep_stat ) {
                        $verdict = array( 'bad', '微信 JS-SDK 两个 CDN 都载入失败：页面可能被 CSP 限制，或手机网络到 res.wx.qq.com 不通' );
                    } elseif ( 'apifail' === $rep_stat ) {
                        $verdict = array( 'bad', 'config 通过了，但具体的分享接口调用失败（见下方明细）——多为该公众号没有对应接口权限' );
                    } elseif ( 'config:fail' === $rep_stat ) {
                        $verdict = array( 'bad', 'wx.config 被微信拒绝：按 errMsg 逐项查安全域名 / AppID 归属账号 / 认证状态' );
                    } else {
                        $verdict = array( 'bad', '真机报了错，见下方 errMsg' );
                    }
                }
                ?>
                        <div class="yimai-gso-field">
                            <span class="yimai-gso-field-label">安全域名该填哪个</span>
                            <div class="yimai-gso-field-control">
                                <p>当前站点主机名是 <code><?php echo esc_html( $host ); ?></code>。微信按「访问时的域名」逐字比对，填错一个字就报 <code>invalid signature</code>。两个都填最稳：</p>
                                <ul class="yimai-gso-wx-list">
                                    <li><code><?php echo esc_html( $host ); ?></code>（手机实际访问的就是这个）</li>
                                    <?php if ( $bare_host !== $host ) : ?>
                                        <li><code><?php echo esc_html( $bare_host ); ?></code>（裸域，会 301 跳到 www 时用得上）</li>
                                    <?php endif; ?>
                                </ul>
                                <div class="yimai-gso-notice is-warning">
                                    <p>如果你当初只填了 <code><?php echo esc_html( $bare_host ); ?></code>，而手机打开的是 <code><?php echo esc_html( $host ); ?></code>，JS 一定失败——但服务端测试照样全绿，这正是「测试通过但没卡片」最常见的原因。</p>
                                </div>
                            </div>
                        </div>

                        <div class="yimai-gso-field">
                            <span class="yimai-gso-field-label">最近一次真机结果</span>
                            <div class="yimai-gso-field-control">
                                <?php if ( '' === $rep_stat ) : ?>
                                    <p class="yimai-gso-field-hint">还没有回传。用手机微信打开任意页面即可，回传后这里会显示结论与原始 errMsg。</p>
                                <?php else : ?>
                                    <div class="yimai-gso-notice <?php echo 'ok' === $verdict[0] ? 'is-success' : 'is-error'; ?>">
                                        <p><strong><?php echo esc_html( $verdict[1] ); ?></strong></p>
                                        <p class="yimai-gso-wx-notice-meta">status=<code><?php echo esc_html( $rep_stat ); ?></code> · <?php echo esc_html( $rep_time ); ?> · <?php echo esc_html( $rep_page ); ?></p>
                                        <?php if ( '' !== $rep_msg && 'ok' !== $rep_msg ) : ?>
                                            <p><code><?php echo esc_html( $rep_msg ); ?></code></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="yimai-gso-actions">
                                        <button type="button" class="button" id="ymwx-clear-report">清掉这条记录</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="yimai-gso-field">
                            <label class="yimai-gso-field-label" for="ymwx-probe-url">抓取自检（模拟微信抓一遍）</label>
                            <div class="yimai-gso-field-control">
                                <input type="text" id="ymwx-probe-url" value="<?php echo esc_attr( $site_home ); ?>" />
                                <div class="yimai-gso-actions">
                                    <button type="button" class="yimai-gso-btn-modern-primary" id="ymwx-probe">开始自检</button>
                                </div>
                                <p class="yimai-gso-field-hint">用微信内置浏览器 UA 真实抓取，逐项检查卡片要素。</p>
                            </div>
                        </div>

                        <div id="ymwx-probe-result" class="yimai-gso-wx-probe-result"></div>
                    </div>
                </div>

                <?php
                // 签名自证：先用微信官方文档的样例参数跑一遍。拼接顺序或参数名（小写 noncestr）
                // 一旦被改动，样例立刻对不上——算法错时真机 wx.config 只会静默失败，肉眼很难定位。
                $sample_ticket = 'sM4AOVdWfPE4DxkXGEs8VMCPGGVi4C3VM0P37wVUCFvkVAy_90u5h9nbSlYy3-Sl-HhTdfl2fzFy1AOcHKP7qg';
                $sample_nonce  = 'Wm3WZYTPz0wzccnW';
                $sample_ts     = 1414587457;
                $sample_url    = 'http://mp.weixin.qq.com?params=value';
                $sample_expect = '0f9de62fce790f9a083d5c99e95740ceb90c27ed';
                $sample_hash   = sha1( 'jsapi_ticket=' . $sample_ticket . '&noncestr=' . $sample_nonce . '&timestamp=' . $sample_ts . '&url=' . $sample_url );
                $sample_ok     = hash_equals( $sample_expect, $sample_hash );

                $sign_ticket = $ticket_ok ? (string) get_transient( self::TICKET_TRANSIENT ) : '';
                $sign_nonce  = 'Wm3faQg7kP2zR9Tx';
                $sign_ts     = 1700000000;
                $sign_url    = $site_home;
                $sign_hash   = ( '' !== $sign_ticket ) ? sha1( 'jsapi_ticket=' . $sign_ticket . '&noncestr=' . $sign_nonce . '&timestamp=' . $sign_ts . '&url=' . $sign_url ) : '';
                ?>
                <div class="yimai-gso-section">
                    <div class="yimai-gso-section-head">
                        <h2><span class="dashicons dashicons-editor-code"></span>五、签名算法自证</h2>
                        <span class="yimai-gso-badge <?php echo $sample_ok ? 'is-pass' : 'is-fail'; ?>"><?php echo $sample_ok ? '算法通过' : '算法不符'; ?></span>
                    </div>
                    <div class="yimai-gso-section-body">
                        <div class="yimai-gso-kv">
                            <div class="yimai-gso-kv-k">官方样例</div>
                            <div class="yimai-gso-kv-v yimai-gso-wx-stack">
                                <?php if ( $sample_ok ) : ?>
                                    <p class="yimai-gso-wx-verdict is-pass">通过</p>
                                    <p class="yimai-gso-field-hint">用微信文档的样例 ticket / noncestr / timestamp / url 算出的 sha1 与文档给出的 <code><?php echo esc_html( $sample_expect ); ?></code> 一致，拼接顺序与参数名正确。</p>
                                <?php else : ?>
                                    <p class="yimai-gso-wx-verdict is-fail">不通过</p>
                                    <p class="yimai-gso-field-hint">实得 <code><?php echo esc_html( $sample_hash ); ?></code>，预期 <code><?php echo esc_html( $sample_expect ); ?></code>。</p>
                                <?php endif; ?>
                            </div>
                            <div class="yimai-gso-kv-k">当前站点实算</div>
                            <div class="yimai-gso-kv-v yimai-gso-wx-stack">
                                <?php if ( $ticket_ok ) : ?>
                                    <p><code>jsapi_ticket=<?php echo esc_html( substr( $sign_ticket, 0, 8 ) . '…' ); ?>&amp;noncestr=<?php echo esc_html( $sign_nonce ); ?>&amp;timestamp=<?php echo (int) $sign_ts; ?>&amp;url=<?php echo esc_html( $sign_url ); ?></code></p>
                                    <p><code class="yimai-gso-wx-hash"><?php echo esc_html( $sign_hash ); ?></code></p>
                                    <p class="yimai-gso-field-hint">接口每次请求都会生成新的 noncestr 与 timestamp，并带上请求方真实 URL 参与签名。</p>
                                <?php else : ?>
                                    <p class="yimai-gso-field-hint">先点上面的「测试公众号凭据」拿到 ticket，这里会给出真实拼接串。</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="yimai-gso-section">
                    <div class="yimai-gso-section-head">
                        <h2><span class="dashicons dashicons-admin-settings"></span>六、公众号后台需要配的三件事</h2>
                    </div>
                    <div class="yimai-gso-section-body">
                        <ol class="yimai-gso-wx-list">
                            <li><strong>JS接口安全域名</strong>：公众号后台 → 设置与开发 → 公众号设置 → 功能设置 → JS接口安全域名，填 <code><?php echo esc_html( $host ); ?></code>（不带 https://），按提示把校验文件放到根目录（上面第一栏可代管）。</li>
                            <li><strong>IP 白名单</strong>：设置与开发 → 基本配置 → IP白名单，加入本服务器出口 IP。不加会报 <code>errcode 40164</code>。</li>
                            <li><strong>认证状态</strong>：JS-SDK 分享接口需要已认证的服务号/订阅号；未认证会报 <code>errcode 48001</code>。</li>
                        </ol>
                    </div>
                </div>

                <div class="yimai-gso-section">
                    <div class="yimai-gso-section-head">
                        <h2><span class="dashicons dashicons-warning"></span>七、常见错误码</h2>
                    </div>
                    <div class="yimai-gso-section-body">
                        <div class="yimai-gso-table-wrap">
                            <table class="yimai-gso-table">
                                <thead>
                                    <tr><th class="is-nowrap">errcode</th><th>含义与处理</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td class="is-nowrap">40001</td><td>AppSecret 错，或 access_token 被别处刷新失效 —— 保存后点「清除缓存」再测</td></tr>
                                    <tr><td class="is-nowrap">40013</td><td>AppID 格式不对（应为 wx 开头的 18 位）</td></tr>
                                    <tr><td class="is-nowrap">40125</td><td>AppSecret 无效，去后台重置后重新填写</td></tr>
                                    <tr><td class="is-nowrap">40164</td><td>服务器出口 IP 不在白名单 —— 报错信息里会带出真实 IP</td></tr>
                                    <tr><td class="is-nowrap">48001</td><td>公众号没有该接口权限（多为未认证）</td></tr>
                                    <tr><td class="is-nowrap">45009</td><td>当日接口调用次数用尽</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="yimai-gso-section">
                    <div class="yimai-gso-section-head">
                        <h2><span class="dashicons dashicons-info-outline"></span>八、生效范围与缓存说明</h2>
                    </div>
                    <div class="yimai-gso-section-body">
                        <ul class="yimai-gso-wx-list">
                            <li><strong>微信内转发/分享</strong>：脚本实时取签名，改完设置立即生效（无需清缓存）。</li>
                            <li><strong>把链接粘贴到聊天窗口</strong>：走微信服务端抓取，微信会把抓取结果缓存数小时到数天。改完 meta 后旧链接可能仍是旧卡片，换一个带参数的链接（如 <code>?v=2</code>）即可验证新结果。</li>
                            <li>iOS 微信签名用的是「进入页面时的首个 URL」，插件已在 head 最前面记录，主题改地址栏也不会影响。</li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'yimai_gso_wechat' ) ); ?>;

            function post(action, data, cb) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onload = function () {
                    var res = null;
                    try { res = JSON.parse(xhr.responseText); } catch (e) { res = { success: false, data: { message: '返回解析失败' } }; }
                    cb(res);
                };
                var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(nonce);
                for (var k in data) {
                    if (Object.prototype.hasOwnProperty.call(data, k)) {
                        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
                    }
                }
                xhr.send(body);
            }

            var tokenBtn = document.getElementById('ymwx-test-token');
            if (tokenBtn) {
                tokenBtn.addEventListener('click', function () {
                    var out = document.getElementById('ymwx-token-result');
                    out.textContent = '测试中…';
                    out.className = 'yimai-gso-wx-inline-result is-muted';
                    post('yimai_gso_wechat_test_token', {}, function (res) {
                        if (res && res.success) {
                            out.textContent = '✓ ' + res.data.message + '（' + res.data.tokenMasked + '）';
                            out.className = 'yimai-gso-wx-inline-result is-ok';
                        } else {
                            out.textContent = '✗ ' + ((res && res.data && res.data.message) || '失败');
                            out.className = 'yimai-gso-wx-inline-result is-bad';
                        }
                    });
                });
            }

            var clearBtn = document.getElementById('ymwx-clear-cache');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    var out = document.getElementById('ymwx-token-result');
                    post('yimai_gso_wechat_clear_cache', {}, function (res) {
                        out.textContent = (res && res.success) ? '✓ ' + res.data.message : '✗ 失败';
                        out.className = (res && res.success)
                            ? 'yimai-gso-wx-inline-result is-ok'
                            : 'yimai-gso-wx-inline-result is-bad';
                    });
                });
            }

            var clearReport = document.getElementById('ymwx-clear-report');
            if (clearReport) {
                clearReport.addEventListener('click', function () {
                    post('yimai_gso_wechat_clear_cache', { target: 'report' }, function () {
                        window.location.reload();
                    });
                });
            }

            var pick = document.getElementById('ymwx-cover-pick');
            if (pick && window.wp && wp.media) {
                pick.addEventListener('click', function (e) {
                    e.preventDefault();
                    var frame = wp.media({ title: '选择分享封面图', button: { text: '使用这张' }, multiple: false });
                    frame.on('select', function () {
                        var a = frame.state().get('selection').first().toJSON();
                        document.getElementById('ymwx-cover').value = a.url;
                    });
                    frame.open();
                });
            }

            var probeBtn = document.getElementById('ymwx-probe');
            if (probeBtn) {
                probeBtn.addEventListener('click', function () {
                    var box = document.getElementById('ymwx-probe-result');
                    box.innerHTML = '<p class="yimai-gso-wx-loading">抓取中，通常需要 3–8 秒…</p>';
                    post('yimai_gso_wechat_probe', { url: document.getElementById('ymwx-probe-url').value }, function (res) {
                        if (!res || !res.success) {
                            box.innerHTML = '<div class="yimai-gso-notice is-error"><p>' + ((res && res.data && res.data.message) || '抓取失败') + '</p></div>';
                            return;
                        }
                        var d = res.data;
                        var html = '<div class="yimai-gso-wx-result">';
                        html += '<div class="yimai-gso-preview-card">';
                        html += d.preview.img
                            ? '<div class="yimai-gso-preview-card-media"><img src="' + d.preview.img + '" alt="" /></div>'
                            : '<div class="yimai-gso-preview-card-media is-empty"><span>无缩略图</span></div>';
                        html += '<p class="yimai-gso-preview-card-title">' + escapeHtml(d.preview.title) + '</p>';
                        html += '<p class="yimai-gso-preview-card-desc">' + escapeHtml(d.preview.desc) + '</p>';
                        html += '<p class="yimai-gso-preview-card-meta">' + escapeHtml(d.preview.host) + '</p>';
                        html += '</div>';
                        html += '<ul class="yimai-gso-wx-checks">';
                        d.checks.forEach(function (c) {
                            // c.status 只有 pass / warn / fail 三种，这里再兜一层，避免异常值拼进 class
                            var st = (c.status === 'pass' || c.status === 'fail') ? c.status : 'warn';
                            html += '<li class="yimai-gso-wx-check"><i class="yimai-gso-wx-check-dot is-' + st + '"></i><div class="yimai-gso-wx-check-body"><strong>' + escapeHtml(c.label) + '</strong><span>' + escapeHtml(c.note) + '</span></div></li>';
                        });
                        html += '</ul></div>';
                        box.innerHTML = html;
                    });
                });
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
        })();
        </script>
        <?php
    }
}
