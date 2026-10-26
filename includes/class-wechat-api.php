<?php
/**
 * 微信公众号服务端 API 共享封装（access_token 唯一实现 + 草稿箱接口）
 *
 * 背景：class-wechat-share.php 原本自带一份 get_access_token()。给「文章 → 草稿箱」
 * 再写第二份实现是这个功能最危险的写法 —— 同一个 AppID 的 access_token 在微信侧是
 * 「后取覆盖前取」，两份实现各自缓存 7000 秒，谁刷新一次，另一份手里的 token 当场作废，
 * 表现就是随机 40001 / 42001：后台刚测通、同步又失败，刷新页面可能又好了，极难复现。
 * 所以本类是插件内「取 token」接口的唯一调用点，Wechat_Share 的 get_access_token()
 * 只保留一个转调壳，供类内既有的 jsapi_ticket / 后台自检调用点继续使用。
 *
 * 本类只负责「服务端出站请求」这一件事，全部静态方法：没有实例状态，也就不需要单例，
 * 不会出现两个对象各持一份 token 的局面。
 *
 * 错误一律走 explain() 翻成中文再抛：微信的 errmsg 是英文短句（invalid credential /
 * system error），运营看到它不知道去哪里改，只有把「去公众号后台哪个位置改」写出来，
 * 后台的红色提示条才有意义。
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Wx_Api {

    /** 接口根地址：全类只出现一次，将来要换代理网关只改这里 */
    const API_BASE = 'https://api.weixin.qq.com';

    /**
     * 下面两个 transient 名必须与 class-wechat-share.php 里的常量完全一致 ——
     * 一旦不一致，就等于又出现了第二份 token 缓存，模块间互踢重新回来。
     */
    const TOKEN_TRANSIENT = 'yimai_gso_wx_access_token';
    const LOCK_TRANSIENT  = 'yimai_gso_wx_token_lock';

    /** 最近一次接口错误：后台「微信分享卡片」面板读它渲染红色提示条 */
    const ERROR_OPTION = 'yimai_gso_wx_last_error';

    /** access_token 官方有效期 7200 秒，缓存留 200 秒余量 */
    const CACHE_TTL = 7000;

    /** 超时：取 token 最轻，上传图片最重（1MB 内图片 + 微信侧转存耗时） */
    const TIMEOUT_TOKEN  = 15;
    const TIMEOUT_JSON   = 30;
    const TIMEOUT_UPLOAD = 45;

    /* ==================== access_token ==================== */

    /**
     * 取 access_token（7000 秒缓存 + 轻量互斥锁）
     *
     * 锁的作用不是防重复请求，而是防「并发刷新」：两个请求同时发现缓存为空时都会去
     * 调取 token 的接口，先拿到的那个立刻被后拿到的覆盖，随后先到的请求拿旧 token
     * 调接口就是 40001。有锁时后来者先等 1.5 秒，通常那时前者已经把 token 写进缓存。
     *
     * @param bool $force 强制刷新（token 被判失效时用）
     * @return string|WP_Error
     */
    public static function get_access_token( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TOKEN_TRANSIENT );
            if ( is_string( $cached ) && '' !== $cached ) {
                return (string) $cached;
            }
            if ( get_transient( self::LOCK_TRANSIENT ) ) {
                for ( $i = 0; $i < 6; $i++ ) {
                    usleep( 250000 );
                    $cached = get_transient( self::TOKEN_TRANSIENT );
                    if ( is_string( $cached ) && '' !== $cached ) {
                        return (string) $cached;
                    }
                }
            }
        }

        $app_id = trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_id', '' ) );
        $secret = trim( (string) YIMAI_GSO_Settings::get( 'wechat_app_secret', '' ) );
        if ( '' === $app_id || '' === $secret ) {
            return new WP_Error( 'yimai_gso_wx_unconfigured', '未填写公众号 AppID / AppSecret' );
        }

        // 锁 15 秒：比 token 请求的 15 秒超时对齐，进程被杀也不会把锁永久留在库里
        set_transient( self::LOCK_TRANSIENT, 1, self::TIMEOUT_TOKEN );

        $res = wp_remote_get(
            add_query_arg(
                array(
                    'grant_type' => 'client_credential',
                    'appid'      => $app_id,
                    'secret'     => $secret,
                ),
                self::API_BASE . '/cgi-bin/token'
            ),
            array( 'timeout' => self::TIMEOUT_TOKEN )
        );

        delete_transient( self::LOCK_TRANSIENT );

        if ( is_wp_error( $res ) ) {
            self::record_error(
                'access_token',
                array( 'errmsg' => $res->get_error_message() ),
                '服务器无法访问 api.weixin.qq.com（国内主机通常是 DNS 解析或出网限制）'
            );
            return $res;
        }

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'yimai_gso_wx_badjson', '微信接口返回非 JSON 内容，请检查服务器到 api.weixin.qq.com 的网络' );
        }
        if ( empty( $data['access_token'] ) ) {
            return self::api_error( 'access_token', $data );
        }

        // 用 expires_in 反推 TTL，但不超过 CACHE_TTL、不低于 120 —— 微信偶尔会返回
        // 异常小的 expires_in（如 60），直接写缓存会让后续请求几乎每次都重新取 token。
        $ttl = isset( $data['expires_in'] ) ? ( (int) $data['expires_in'] - 200 ) : self::CACHE_TTL;
        set_transient( self::TOKEN_TRANSIENT, (string) $data['access_token'], max( 120, min( self::CACHE_TTL, $ttl ) ) );

        delete_option( self::ERROR_OPTION );

        return (string) $data['access_token'];
    }

    /* ==================== 通用 JSON 请求 ==================== */

    /**
     * 统一 JSON POST（草稿箱、素材、发布类接口都走这里）
     *
     * @param string $path  接口路径，如 '/cgi-bin/draft/add'
     * @param array  $body  请求体，内部 JSON 编码
     * @param int    $retry 允许的 token 失效重试次数
     * @return array|WP_Error 成功返回微信原始数组（含 media_id 等字段）
     */
    public static function post( $path, array $body, $retry = 1 ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $json = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            return new WP_Error( 'yimai_gso_wx_json', '请求体 JSON 编码失败（正文里混入了非法 UTF-8 字符）' );
        }

        $res = wp_remote_post(
            self::API_BASE . $path . '?access_token=' . rawurlencode( (string) $token ),
            array(
                'timeout' => self::TIMEOUT_JSON,
                'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body'    => $json,
            )
        );

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error(
                'yimai_gso_wx_badjson',
                '微信接口 ' . (string) $path . ' 返回非 JSON 内容（HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . '）'
            );
        }

        $code = isset( $data['errcode'] ) ? (int) $data['errcode'] : 0;

        // token 类错误：先把死 token 从缓存删掉，再强制刷新。
        // 顺序很重要 —— 若先刷新而刷新失败，这份死 token 还会被读 7000 秒，错误会连续复现。
        if ( self::is_token_error( $code ) && $retry > 0 ) {
            delete_transient( self::TOKEN_TRANSIENT );
            $fresh = self::get_access_token( true );
            if ( is_wp_error( $fresh ) ) {
                return $fresh;
            }
            return self::post( $path, $body, $retry - 1 );
        }

        if ( 0 !== $code ) {
            return self::api_error( $path, $data );
        }

        return $data;
    }

    /* ==================== 图片上传 ==================== */

    /**
     * 上传正文内图片：走 uploadimg，返回微信域名下的 url
     *
     * 正文里的 <img> 必须是微信自家域名，外链图片在草稿箱里会被吞掉（预览是白框）。
     *
     * @param string $binary   图片二进制
     * @param string $filename 文件名（只影响微信侧扩展名识别）
     * @return string|WP_Error url
     */
    public static function upload_content_image( $binary, $filename = 'img.jpg' ) {
        $data = self::upload_multipart( '/cgi-bin/media/uploadimg', array(), $binary, $filename, 'image/jpeg' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( empty( $data['url'] ) ) {
            return new WP_Error( 'yimai_gso_wx_no_url', '图片上传接口未返回 url（微信侧可能正在转存，稍后重试）', $data );
        }
        return (string) $data['url'];
    }

    /**
     * 上传封面：永久图片素材，返回 media_id（草稿的 thumb_media_id 用它）
     *
     * @param string $binary
     * @param string $filename
     * @return string|WP_Error media_id
     */
    public static function upload_permanent_image( $binary, $filename = 'cover.jpg' ) {
        $data = self::upload_multipart( '/cgi-bin/material/add_material', array( 'type' => 'image' ), $binary, $filename, 'image/jpeg' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( empty( $data['media_id'] ) ) {
            return new WP_Error( 'yimai_gso_wx_no_media_id', '永久素材接口未返回 media_id', $data );
        }
        return (string) $data['media_id'];
    }

    /**
     * multipart 上传（手工拼 body）
     *
     * 为什么手工拼：WP 的 HTTP API 只接受「字符串」或「数组」两种 body，数组会被编码成
     * application/x-www-form-urlencoded；而 CURLFile 只有 curl 传输认，Requests 传输会把它
     * 当普通对象处理。想可靠地上传二进制，只能自己拼 multipart 再把整体当字符串发出去。
     *
     * @param string $path     接口路径
     * @param array  $query    额外 query 参数（如 add_material 的 type=image）
     * @param string $binary   二进制
     * @param string $filename 文件名
     * @param string $mime     该 part 的 Content-Type
     * @param int    $retry    token 失效重试次数
     * @return array|WP_Error
     */
    private static function upload_multipart( $path, array $query, $binary, $filename, $mime, $retry = 1 ) {
        $binary = (string) $binary;
        if ( '' === $binary ) {
            return new WP_Error( 'yimai_gso_wx_empty_media', '图片数据为空，未发起上传' );
        }

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // wp_generate_password(24, false) 只含字母数字，做 boundary 不会和二进制内容撞车
        $boundary = wp_generate_password( 24, false );
        $filename = self::safe_filename( $filename );

        $body  = '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="media"; filename="' . $filename . '"' . "\r\n";
        $body .= 'Content-Type: ' . (string) $mime . "\r\n\r\n";
        $body .= $binary . "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";

        $url = add_query_arg(
            array_merge( array( 'access_token' => (string) $token ), $query ),
            self::API_BASE . $path
        );

        $res = wp_remote_post(
            $url,
            array(
                'timeout' => self::TIMEOUT_UPLOAD,
                'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error(
                'yimai_gso_wx_badjson',
                '图片上传接口 ' . (string) $path . ' 返回非 JSON 内容（HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . '）'
            );
        }

        $code = isset( $data['errcode'] ) ? (int) $data['errcode'] : 0;

        // 上传也重试一次 token 失效：二进制已经在内存里，重发不额外读盘；
        // 反之若只报错不清缓存，这份死 token 会继续污染后续所有接口。
        if ( self::is_token_error( $code ) && $retry > 0 ) {
            delete_transient( self::TOKEN_TRANSIENT );
            $fresh = self::get_access_token( true );
            if ( is_wp_error( $fresh ) ) {
                return $fresh;
            }
            return self::upload_multipart( $path, $query, $binary, $filename, $mime, $retry - 1 );
        }

        if ( 0 !== $code ) {
            return self::api_error( $path, $data );
        }

        return $data;
    }

    /**
     * 洗文件名：会原样进 multipart 头，混入引号或换行会直接破坏整个 body 结构
     *
     * 顺便保证带扩展名：微信按扩展名判断图片类型，sanitize_file_name 会把中文名整段
     * 剥掉，只剩 "png" 这类无点后缀时上传会被拒。
     *
     * @param string $filename
     * @return string
     */
    private static function safe_filename( $filename ) {
        $filename = preg_replace( '/[\r\n"\\\\\/]+/', '', (string) $filename );
        $filename = sanitize_file_name( (string) $filename );
        if ( '' === $filename || false === strpos( $filename, '.' ) ) {
            $filename = 'image.jpg';
        }
        return (string) $filename;
    }

    /* ==================== 草稿箱 ==================== */

    /**
     * 新建草稿
     *
     * @param array $article 单篇文章结构（title / author / content / thumb_media_id / ...）
     * @return array|WP_Error 成功时含 media_id
     */
    public static function create_draft( array $article ) {
        return self::post( '/cgi-bin/draft/add', array( 'articles' => array( $article ) ) );
    }

    /**
     * 更新草稿中某一篇
     *
     * 注意 articles 在 draft/update 里是「单个对象」而不是数组（与 draft/add 相反），
     * 传成数组微信会报 47001 / 40007 这类结构错误；这里对误传的 [0 => article] 形式做兜底。
     *
     * @param string $media_id 草稿 media_id
     * @param int    $index    文章在草稿中的序号，从 0 开始
     * @param array  $article  新内容
     * @return array|WP_Error
     */
    public static function update_draft( $media_id, $index, array $article ) {
        if ( isset( $article[0] ) && is_array( $article[0] ) ) {
            $article = $article[0];
        }
        return self::post(
            '/cgi-bin/draft/update',
            array(
                'media_id' => (string) $media_id,
                'index'    => (int) $index,
                'articles' => $article,
            )
        );
    }

    /**
     * 读草稿详情（发布前核对正文 / 排查 media_id 是否还在）
     *
     * @param string $media_id
     * @return array|WP_Error
     */
    public static function get_draft( $media_id ) {
        return self::post( '/cgi-bin/draft/get', array( 'media_id' => (string) $media_id ) );
    }

    /* ==================== 错误处理 ==================== */

    /**
     * 这些码的语义都是「手里的 token 不对」，刷新后可重试一次；
     * 其余错误码重试只会重复消耗当日配额。
     *
     * @param int $code
     * @return bool
     */
    private static function is_token_error( $code ) {
        return in_array( (int) $code, array( 40001, 42001, 40014 ), true );
    }

    /**
     * 组装 WP_Error 并落库错误记录
     *
     * 原始 $data 放进 error_data：上层（同步器）需要拿 errcode 做分支判断，
     * 而 WP_Error 的 message 是给人看的，不能当分支条件用。
     *
     * @param string $context 出错位置
     * @param array  $data    微信原始返回
     * @return WP_Error
     */
    private static function api_error( $context, array $data ) {
        $code = isset( $data['errcode'] ) ? (int) $data['errcode'] : 0;
        $hint = self::explain( $data );
        self::record_error( $context, $data, $hint );
        return new WP_Error( 'yimai_gso_wx_' . $code, $hint, $data );
    }

    /**
     * 写 'yimai_gso_wx_last_error'：后台面板就靠这条记录展示「最近一次接口错误」
     *
     * @param string $context
     * @param array  $data
     * @param string $hint
     */
    private static function record_error( $context, array $data, $hint = '' ) {
        update_option(
            self::ERROR_OPTION,
            array(
                'context' => (string) $context,
                'errcode' => isset( $data['errcode'] ) ? (int) $data['errcode'] : 0,
                'errmsg'  => isset( $data['errmsg'] ) ? (string) $data['errmsg'] : '',
                'hint'    => (string) $hint,
                'time'    => current_time( 'mysql' ),
            ),
            false
        );
    }

    /**
     * 错误码 → 中文人话（含「去哪里改」的指引）
     *
     * @param array|mixed $data 微信返回数组
     * @return string
     */
    public static function explain( $data ) {
        $data = is_array( $data ) ? $data : array();
        $code = isset( $data['errcode'] ) ? (int) $data['errcode'] : 0;
        $msg  = isset( $data['errmsg'] ) ? (string) $data['errmsg'] : '';

        $map = array(
            40001 => 'AppSecret 不正确，或 access_token 已被别处（另一套系统 / 另一个插件）刷新失效',
            40002 => 'grant_type 参数错误',
            40006 => '上传文件大小超过接口上限',
            40007 => 'media_id 无效（草稿可能已在公众号后台被删除）',
            40009 => '图片大小超限（uploadimg 单图需 ≤ 1MB，封面永久素材 ≤ 2MB）',
            40013 => 'AppID 格式不正确',
            40014 => 'access_token 不合法（缓存里那份已被覆盖，刷新即可）',
            40125 => 'AppSecret 无效，请到公众号后台「设置与开发 → 基本配置」重置后重新填写',
            40164 => '服务器出口 IP 不在公众号「IP 白名单」内',
            41004 => '未填写 AppSecret',
            42001 => 'access_token 已过期，需重新获取',
            45002 => '正文内容超长（草稿正文上限约 2 万字符 / 1MB）',
            45003 => '标题超长（不超过 64 字）',
            45004 => '摘要超长（不超过 120 字）',
            45006 => '正文里的图片链接不合法（须为微信 mmbiz 域名下的图片）',
            45009 => '接口调用次数已达当日上限，次日 0 点自动重置（可申请提额）',
            45028 => '永久素材数量已达上限，需先在公众号后台「素材管理」里清理',
            48001 => '该公众号未获得此接口权限（草稿箱接口需已认证的服务号 / 订阅号）',
            53503 => '正文结构不支持（微信只接受受限 HTML 子集：不支持 script / style / class / id 等）',
            89503 => '调用过于频繁，请稍后再试',
        );

        $hint = isset( $map[ $code ] ) ? $map[ $code ] : '';

        // 40164 的 errmsg 里带着真实出口 IP（"invalid ip 1.2.3.4 ipv6 ::ffff:..., not in whitelist"），
        // 这是运营唯一能直接复制进白名单的信息，必须提出来。
        if ( 40164 === $code && preg_match( '/(\d{1,3}(?:\.\d{1,3}){3})/', $msg, $m ) ) {
            $hint .= '；把 ' . $m[1] . ' 加进「设置与开发 → 基本配置 → IP白名单」即可';
        }

        if ( '' === $hint ) {
            $hint = '' !== $msg ? $msg : '微信公众号接口报错';
        }

        if ( 0 === $code ) {
            return (string) $hint;
        }

        return (string) ( $hint . '（errcode ' . $code . '：' . ( '' !== $msg ? $msg : '无 errmsg' ) . '）' );
    }
}
