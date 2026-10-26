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
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_post_dispatch', array( $this, 'disable_rest_cache' ), 10, 3 );
        add_action( 'wp_head', array( $this, 'output_head_bootstrap' ), 0 );
        add_action( 'wp_footer', array( $this, 'output_footer_script' ), 22 );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_admin_page' ), 20 );
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
            // 特色图是 WebP 但后台配了封面兜底时，优先用封面（安卓微信对 WebP 缩略图支持不稳）
            $prefer_cover_over_webp = ( 'image/webp' === $mime && $has_cover );
            if ( $thumb_id && ! $is_svg && ! $prefer_cover_over_webp ) {
                $src = wp_get_attachment_image_src( $thumb_id, 'full' );
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
            $title      = ( '' !== $home_title ) ? $home_title : $site;
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
     * @param bool $force 强制刷新
     * @return string|WP_Error
     */
    private function get_access_token( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TOKEN_TRANSIENT );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
            // 另一个请求正在刷新时等它一下，避免并发刷新导致刚拿到的 token 立刻失效
            if ( get_transient( self::LOCK_TRANSIENT ) ) {
                for ( $i = 0; $i < 6; $i++ ) {
                    usleep( 250000 );
                    $cached = get_transient( self::TOKEN_TRANSIENT );
                    if ( is_string( $cached ) && '' !== $cached ) {
                        return $cached;
                    }
                }
            }
        }

        $app_id = trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_id', '' ) );
        $secret = trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_secret', '' ) );
        if ( '' === $app_id || '' === $secret ) {
            return new WP_Error( 'yimai_gso_wx_unconfigured', '未填写公众号 AppID / AppSecret' );
        }

        set_transient( self::LOCK_TRANSIENT, 1, 15 );

        $api = add_query_arg(
            array(
                'grant_type' => 'client_credential',
                'appid'      => $app_id,
                'secret'     => $secret,
            ),
            'https://api.weixin.qq.com/cgi-bin/token'
        );

        $res = wp_remote_get( $api, array( 'timeout' => 15 ) );
        delete_transient( self::LOCK_TRANSIENT );

        if ( is_wp_error( $res ) ) {
            update_option( self::ERROR_OPTION, array(
                'context' => 'access_token',
                'errcode' => 0,
                'errmsg'  => $res->get_error_message(),
                'hint'    => '服务器无法访问 api.weixin.qq.com（国内主机通常是 DNS 或出网限制）',
                'time'    => current_time( 'mysql' ),
            ), false );
            return $res;
        }

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'yimai_gso_wx_badjson', '微信接口返回非 JSON 内容，请检查服务器到 api.weixin.qq.com 的网络' );
        }
        if ( empty( $data['access_token'] ) ) {
            return $this->wechat_api_error( 'access_token', $data );
        }

        $ttl = isset( $data['expires_in'] ) ? ( (int) $data['expires_in'] - 200 ) : self::CACHE_TTL;
        set_transient( self::TOKEN_TRANSIENT, (string) $data['access_token'], max( 120, min( self::CACHE_TTL, $ttl ) ) );
        delete_option( self::ERROR_OPTION );

        return (string) $data['access_token'];
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
            if (i >= srcs.length) { log('SDK 载入失败'); badge('微信 SDK 载入失败', '#b91c1c'); return; }
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
    // 真机结果回传：失败原因只在客户端 errMsg 里，服务端推不出来
    // 真机结果回传：失败原因只在客户端 errMsg 里，服务端推不出来。
    // 只在强制调试（?ymwxdebug=1 或后台开关）时发，避免每个访客都写库。
    function report(status, msg) {
        if (!isDebug()) { return; }
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
            report('signfail', (cfg && cfg.message) || '签名失败');
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

            // 新旧接口同时调用：8.x 走新接口，部分安卓老版本只认旧接口
            if (window.wx.updateAppMessageShareData) { window.wx.updateAppMessageShareData(appMsg); }
            if (window.wx.onMenuShareAppMessage) { window.wx.onMenuShareAppMessage(appMsg); }
            if (window.wx.updateTimelineShareData) { window.wx.updateTimelineShareData(timeline); }
            if (window.wx.onMenuShareTimeline) { window.wx.onMenuShareTimeline(timeline); }

            log('分享卡片已就绪', appMsg);
            badge('分享卡片已就绪 ✓', '#15803d');
            report('ready', 'ok');
        });

        window.wx.error(function (res) {
            log('wx.config 失败', res);
            badge('wx.config 失败：' + (res && res.errMsg), '#b91c1c');
            report('error', (res && res.errMsg) || 'unknown');
        });
    }

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
        <div class="wrap ymwx-wrap">
            <h1>微信分享卡片</h1>
            <p style="max-width:860px;color:#475569;line-height:1.8;">
                微信内「发送给朋友 / 分享到朋友圈」时只有一个裸链接，是因为页面没有接入微信 JS-SDK，
                微信抓不到信息就只显示一条 URL。这里把签名、分享字段和抓取元信息一次配好。
            </p>

            <?php if ( isset( $_GET['ymwx'] ) && 'saved' === $_GET['ymwx'] ) : ?>
                <div class="notice notice-success is-dismissible"><p>设置已保存，缓存已重置。</p></div>
            <?php endif; ?>

            <div class="ymwx-cards">
                <div class="ymwx-card">
                    <div class="ymwx-card-k">公众号凭据</div>
                    <div class="ymwx-card-v <?php echo ( $app_id && $has_secret ) ? 'ok' : 'bad'; ?>">
                        <?php echo ( $app_id && $has_secret ) ? '已配置' : '未配置'; ?>
                    </div>
                </div>
                <div class="ymwx-card">
                    <div class="ymwx-card-k">access_token 缓存</div>
                    <div class="ymwx-card-v <?php echo $token_ok ? 'ok' : ''; ?>"><?php echo $token_ok ? '有效' : '无'; ?></div>
                </div>
                <div class="ymwx-card">
                    <div class="ymwx-card-k">jsapi_ticket 缓存</div>
                    <div class="ymwx-card-v <?php echo $ticket_ok ? 'ok' : ''; ?>"><?php echo $ticket_ok ? '有效' : '无'; ?></div>
                </div>
                <div class="ymwx-card">
                    <div class="ymwx-card-k">站点域名</div>
                    <div class="ymwx-card-v"><?php echo esc_html( $host ); ?></div>
                </div>
            </div>

            <?php
            $err_hint = isset( $last_err['hint'] ) ? (string) $last_err['hint'] : '';
            $err_msg  = isset( $last_err['errmsg'] ) ? (string) $last_err['errmsg'] : '';
            $err_code = isset( $last_err['errcode'] ) ? (int) $last_err['errcode'] : 0;
            $err_time = isset( $last_err['time'] ) ? (string) $last_err['time'] : '';
            ?>
            <?php if ( '' !== $err_msg ) : ?>
                <div class="notice notice-error">
                    <p><strong>最近一次接口错误：</strong><?php echo esc_html( '' !== $err_hint ? $err_hint : $err_msg ); ?>
                        <br><span style="color:#64748b;">errcode <?php echo $err_code; ?> ·
                        <?php echo esc_html( $err_msg ); ?> · <?php echo esc_html( $err_time ); ?></span>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ymwx-form">
                <input type="hidden" name="action" value="yimai_gso_wechat_save" />
                <?php wp_nonce_field( 'yimai_gso_wechat_save' ); ?>

                <h2 class="title">一、公众号与开关</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">启用微信分享卡片</th>
                        <td>
                            <label><input type="checkbox" name="wechat_share_enabled" value="1" <?php checked( $enabled ); ?> /> 在微信内打开页面时接管「发送给朋友 / 分享到朋友圈」的标题、描述与缩略图</label>
                            <p class="description">关闭后前台不输出任何微信脚本，页面行为回到现状。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">AppID</th>
                        <td>
                            <input type="text" class="regular-text" name="wechat_app_id" value="<?php echo esc_attr( $app_id ); ?>" placeholder="wx1234567890abcdef" autocomplete="off" />
                            <p class="description">公众号后台「设置与开发 → 基本配置 → 开发者ID」里的 AppID。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">AppSecret</th>
                        <td>
                            <input type="text" class="regular-text" name="wechat_app_secret" value="<?php echo esc_attr( $secret ); ?>" placeholder="<?php echo $has_secret ? '已保存（留空或保持掩码则不修改）' : 'AppSecret'; ?>" autocomplete="off" />
                            <p class="description">加密存库，页面只显示后 4 位。公众号 AppSecret 泄露后请到后台重置。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">公众号校验文件托管</th>
                        <td>
                            <input type="text" class="regular-text" name="wechat_mp_verify_token" value="<?php echo esc_attr( $verify ); ?>" placeholder="例如 4QwXyz123abcDEF" />
                            <p class="description">
                                配置「JS接口安全域名」时微信要求根目录有 <code>MP_verify_xxxx.txt</code>。
                                把 xxxx 部分填在这里，插件会直接托管这个文件，不用上服务器传。
                                <?php if ( '' !== $verify ) : ?>
                                    <br>当前应可访问：<a href="<?php echo esc_url( home_url( '/MP_verify_' . $verify . '.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/MP_verify_' . $verify . '.txt' ) ); ?></a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">调试角标</th>
                        <td>
                            <label><input type="checkbox" name="wechat_share_debug" value="1" <?php checked( $debug ); ?> /> 在微信内页面左下角显示分享状态角标（配置完记得关掉）</label>
                        </td>
                    </tr>
                </table>

                <h2 class="title">二、分享文案与封面</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">首页分享标题</th>
                        <td>
                            <input type="text" class="regular-text" name="wechat_share_home_title" value="<?php echo esc_attr( $home_title ); ?>" placeholder="例如：一麦瑜伽·普拉提｜宁波高端女性瑜伽空间" />
                            <p class="description">首页和列表页没有文章标题可用，留空则回落到站点名「<?php echo esc_html( get_bloginfo( 'name' ) ); ?>」。建议 20 字内。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">分享封面图</th>
                        <td>
                            <input type="text" class="regular-text" name="wechat_share_cover" id="ymwx-cover" value="<?php echo esc_attr( $cover ); ?>" placeholder="https://<?php echo esc_attr( $host ); ?>/wp-content/uploads/...jpg" />
                            <button type="button" class="button" id="ymwx-cover-pick">从媒体库选择</button>
                            <p class="description">
                                文章有特色图时优先用特色图；没有特色图（以及特色图是 WebP 时）用这张兜底。
                                要求：JPG/PNG、≥ 500×500、单张 &lt; 300 KB、文件名用英文。
                            </p>
                            <?php if ( '' !== $cover ) : ?>
                                <p><img src="<?php echo esc_url( $cover ); ?>" alt="" style="max-width:220px;border-radius:8px;border:1px solid #e2e8f0;margin-top:6px;" /></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">描述长度</th>
                        <td>
                            <input type="number" min="20" max="120" step="1" name="wechat_share_desc_len" value="<?php echo (int) $desc_len; ?>" style="width:90px;" /> 字
                            <p class="description">微信好友卡片描述区大约显示两行，超过会被省略号截断。建议 50–70。</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">保存设置</button>
                </p>
            </form>

            <h2 class="title">三、真机诊断</h2>
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
                } elseif ( false !== stripos( $rep_msg, 'invalid signature' ) ) {
                    $verdict = array( 'bad', '签名不匹配：多为「JS接口安全域名」和当前访问域名不一致，或页面 URL 带参数时签名用的不是同一个 URL' );
                } elseif ( false !== stripos( $rep_msg, 'invalid domain' ) || false !== stripos( $rep_msg, 'invalid url domain' ) ) {
                    $verdict = array( 'bad', '域名不在公众号「JS接口安全域名」里：必须与访问域名完全一致，www 与不带 www 视为两个域名' );
                } elseif ( false !== stripos( $rep_msg, 'permission' ) || false !== stripos( $rep_msg, 'offline' ) ) {
                    $verdict = array( 'bad', '公众号没有 JS 接口权限：多为未完成微信认证，或该 AppID 是测试号/订阅号未认证' );
                } elseif ( false !== stripos( $rep_msg, 'signfail' ) ) {
                    $verdict = array( 'bad', '签名接口本身失败：看上面的最近错误与「测试公众号凭据」结果' );
                } elseif ( false !== stripos( $rep_msg, 'config:fail' ) ) {
                    $verdict = array( 'bad', 'wx.config 被微信拒绝：按 errMsg 逐项查安全域名 / AppID 归属账号 / 认证状态' );
                } else {
                    $verdict = array( 'bad', '真机报了错，见下方 errMsg' );
                }
            }
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">安全域名该填哪个</th>
                    <td>
                        <p>当前站点主机名是 <code><?php echo esc_html( $host ); ?></code>。微信按「访问时的域名」逐字比对，填错一个字就报 <code>invalid signature</code>。两个都填最稳：</p>
                        <ul style="margin-left:18px;list-style:disc;">
                            <li><code><?php echo esc_html( $host ); ?></code>（手机实际访问的就是这个）</li>
                            <?php if ( $bare_host !== $host ) : ?>
                                <li><code><?php echo esc_html( $bare_host ); ?></code>（裸域，会 301 跳到 www 时用得上）</li>
                            <?php endif; ?>
                        </ul>
                        <p class="description" style="color:#b45309;">如果你当初只填了 <code><?php echo esc_html( $bare_host ); ?></code>，而手机打开的是 <code><?php echo esc_html( $host ); ?></code>，JS 一定失败——但服务端测试照样全绿，这正是「测试通过但没卡片」最常见的原因。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">最近一次真机结果</th>
                    <td>
                        <?php if ( '' === $rep_stat ) : ?>
                            <span class="description">还没有回传。用手机微信打开任意页面即可，回传后这里会显示结论与原始 errMsg。</span>
                        <?php else : ?>
                            <p><strong style="color:<?php echo 'ok' === $verdict[0] ? '#15803d' : '#b91c1c'; ?>;"><?php echo esc_html( $verdict[1] ); ?></strong></p>
                            <p class="description">status=<code><?php echo esc_html( $rep_stat ); ?></code> · <?php echo esc_html( $rep_time ); ?> · <?php echo esc_html( $rep_page ); ?></p>
                            <?php if ( '' !== $rep_msg && 'ok' !== $rep_msg ) : ?>
                                <p><code style="word-break:break-all;"><?php echo esc_html( $rep_msg ); ?></code></p>
                            <?php endif; ?>
                            <p><button type="button" class="button" id="ymwx-clear-report">清掉这条记录</button></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2 class="title">四、连通性测试</h2>
            <p>
                <button type="button" class="button" id="ymwx-test-token">测试公众号凭据</button>
                <button type="button" class="button" id="ymwx-clear-cache">清除 token / ticket 缓存</button>
                <span id="ymwx-token-result" style="margin-left:10px;"></span>
            </p>

            <h2 class="title">五、抓取自检（模拟微信抓一遍）</h2>
            <p>
                <input type="text" class="regular-text" id="ymwx-probe-url" value="<?php echo esc_attr( $site_home ); ?>" style="width:420px;" />
                <button type="button" class="button button-primary" id="ymwx-probe">开始自检</button>
                <span class="description">用微信内置浏览器 UA 真实抓取，逐项检查卡片要素。</span>
            </p>
            <div id="ymwx-probe-result"></div>

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
            <h2 class="title">六、签名算法自证</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">官方样例</th>
                    <td>
                        <?php if ( $sample_ok ) : ?>
                            <strong style="color:#15803d;">通过</strong>
                            <p class="description">用微信文档的样例 ticket / noncestr / timestamp / url 算出的 sha1 与文档给出的 <code><?php echo esc_html( $sample_expect ); ?></code> 一致，拼接顺序与参数名正确。</p>
                        <?php else : ?>
                            <strong style="color:#b91c1c;">不通过</strong>
                            <p class="description">实得 <code><?php echo esc_html( $sample_hash ); ?></code>，预期 <code><?php echo esc_html( $sample_expect ); ?></code>。</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">当前站点实算</th>
                    <td>
                        <?php if ( $ticket_ok ) : ?>
                            <code style="word-break:break-all;">jsapi_ticket=<?php echo esc_html( substr( $sign_ticket, 0, 8 ) . '…' ); ?>&amp;noncestr=<?php echo esc_html( $sign_nonce ); ?>&amp;timestamp=<?php echo (int) $sign_ts; ?>&amp;url=<?php echo esc_html( $sign_url ); ?></code>
                            <p style="margin-top:6px;"><code style="word-break:break-all;color:#15803d;"><?php echo esc_html( $sign_hash ); ?></code></p>
                            <p class="description">接口每次请求都会生成新的 noncestr 与 timestamp，并带上请求方真实 URL 参与签名。</p>
                        <?php else : ?>
                            <span class="description">先点上面的「测试公众号凭据」拿到 ticket，这里会给出真实拼接串。</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <hr style="margin:28px 0;" />

            <h2 class="title">七、公众号后台需要配的三件事</h2>
            <ol style="max-width:900px;line-height:2;color:#334155;">
                <li><strong>JS接口安全域名</strong>：公众号后台 → 设置与开发 → 公众号设置 → 功能设置 → JS接口安全域名，填 <code><?php echo esc_html( $host ); ?></code>（不带 https://），按提示把校验文件放到根目录（上面第一栏可代管）。</li>
                <li><strong>IP 白名单</strong>：设置与开发 → 基本配置 → IP白名单，加入本服务器出口 IP。不加会报 <code>errcode 40164</code>。</li>
                <li><strong>认证状态</strong>：JS-SDK 分享接口需要已认证的服务号/订阅号；未认证会报 <code>errcode 48001</code>。</li>
            </ol>

            <h2 class="title">八、常见错误码</h2>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th style="width:110px;">errcode</th><th>含义与处理</th></tr></thead>
                <tbody>
                    <tr><td>40001</td><td>AppSecret 错，或 access_token 被别处刷新失效 —— 保存后点「清除缓存」再测</td></tr>
                    <tr><td>40013</td><td>AppID 格式不对（应为 wx 开头的 18 位）</td></tr>
                    <tr><td>40125</td><td>AppSecret 无效，去后台重置后重新填写</td></tr>
                    <tr><td>40164</td><td>服务器出口 IP 不在白名单 —— 报错信息里会带出真实 IP</td></tr>
                    <tr><td>48001</td><td>公众号没有该接口权限（多为未认证）</td></tr>
                    <tr><td>45009</td><td>当日接口调用次数用尽</td></tr>
                </tbody>
            </table>

            <h2 class="title">九、生效范围与缓存说明</h2>
            <ul style="max-width:900px;line-height:2;color:#334155;">
                <li><strong>微信内转发/分享</strong>：脚本实时取签名，改完设置立即生效（无需清缓存）。</li>
                <li><strong>把链接粘贴到聊天窗口</strong>：走微信服务端抓取，微信会把抓取结果缓存数小时到数天。改完 meta 后旧链接可能仍是旧卡片，换一个带参数的链接（如 <code>?v=2</code>）即可验证新结果。</li>
                <li>iOS 微信签名用的是「进入页面时的首个 URL」，插件已在 head 最前面记录，主题改地址栏也不会影响。</li>
            </ul>
        </div>

        <style>
            .ymwx-cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 8px;}
            .ymwx-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;min-width:180px;}
            .ymwx-card-k{font-size:12px;color:#64748b;margin-bottom:6px;}
            .ymwx-card-v{font-size:15px;font-weight:600;color:#0f172a;}
            .ymwx-card-v.ok{color:#15803d;}
            .ymwx-card-v.bad{color:#b91c1c;}
            .ymwx-form h2.title{margin-top:28px;}
            #ymwx-probe-result{margin-top:8px;}
            .ymwx-result{display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;}
            .ymwx-preview{width:320px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fafafa;}
            .ymwx-preview img{width:100%;height:170px;object-fit:cover;display:block;background:#eef2f7;}
            .ymwx-preview .t{padding:10px 12px;font-size:14px;font-weight:600;color:#111;line-height:1.5;}
            .ymwx-preview .d{padding:0 12px 8px;font-size:12px;color:#64748b;line-height:1.6;max-height:56px;overflow:hidden;}
            .ymwx-preview .h{padding:0 12px 12px;font-size:11px;color:#94a3b8;}
            .ymwx-checks{flex:1;min-width:320px;}
            .ymwx-check{display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px dashed #eef2f7;font-size:13px;}
            .ymwx-check:last-child{border-bottom:0;}
            .ymwx-dot{flex:0 0 8px;width:8px;height:8px;border-radius:50%;margin-top:6px;}
            .ymwx-dot.pass{background:#16a34a;}
            .ymwx-dot.warn{background:#f59e0b;}
            .ymwx-dot.fail{background:#dc2626;}
            .ymwx-check b{font-weight:600;color:#1e293b;min-width:150px;display:inline-block;}
            .ymwx-check span{color:#64748b;word-break:break-all;}
        </style>

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
                    out.style.color = '#64748b';
                    post('yimai_gso_wechat_test_token', {}, function (res) {
                        if (res && res.success) {
                            out.textContent = '✓ ' + res.data.message + '（' + res.data.tokenMasked + '）';
                            out.style.color = '#15803d';
                        } else {
                            out.textContent = '✗ ' + ((res && res.data && res.data.message) || '失败');
                            out.style.color = '#b91c1c';
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
                        out.style.color = (res && res.success) ? '#15803d' : '#b91c1c';
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
                    box.innerHTML = '<p style="color:#64748b;">抓取中，通常需要 3–8 秒…</p>';
                    post('yimai_gso_wechat_probe', { url: document.getElementById('ymwx-probe-url').value }, function (res) {
                        if (!res || !res.success) {
                            box.innerHTML = '<div class="notice notice-error"><p>' + ((res && res.data && res.data.message) || '抓取失败') + '</p></div>';
                            return;
                        }
                        var d = res.data;
                        var html = '<div class="ymwx-result">';
                        html += '<div class="ymwx-preview">';
                        html += d.preview.img ? '<img src="' + d.preview.img + '" alt="" />' : '<div style="height:170px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:12px;">无缩略图</div>';
                        html += '<div class="t">' + escapeHtml(d.preview.title) + '</div>';
                        html += '<div class="d">' + escapeHtml(d.preview.desc) + '</div>';
                        html += '<div class="h">' + escapeHtml(d.preview.host) + '</div>';
                        html += '</div><div class="ymwx-checks">';
                        d.checks.forEach(function (c) {
                            html += '<div class="ymwx-check"><i class="ymwx-dot ' + c.status + '"></i><div><b>' + escapeHtml(c.label) + '</b><span>' + escapeHtml(c.note) + '</span></div></div>';
                        });
                        html += '</div></div>';
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
