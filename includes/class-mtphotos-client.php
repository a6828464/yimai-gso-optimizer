<?php
/**
 * MT Photos 配图来源 - 通过 MT Photos 外网 API 自动匹配「_有水印」文件夹中的图片
 *
 * 认证三层（与 mtphoto.py 一致）：
 *   1. 优先使用 API Key（x-api-key 头），或账号密码经 RSA 加密登录拿 token
 *   2. 取图前需用 api_key/refresh_token 换 auth_code（服务端 24h 有效，本地缓存 20h）
 *   3. 图片 URL = /gateway/file/{id}/{md5}?auth_code=xxx（不带 type 为 1000px 预览，type=ori 为原图）
 *
 * 选图口径（与朋友圈素材站一致）：
 *   - 只在名称含「_有水印」的文件夹中取图，排除「_无水印」
 *   - 按文章关键词搜索文件夹匹配；无匹配时从全部带水印文件夹中随机选取
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_MTPhotos_Client {

    /** auth_code 本地缓存时长（服务端 24h，提前失效重取） */
    const AUTH_CODE_TTL = 72000; // 20h
    const TRANSIENT_KEY = 'yimai_gso_mtphotos_auth';
    const IMAGE_TYPES   = array( 'JPG', 'JPEG', 'PNG', 'WEBP', 'BMP', 'GIF', 'AVIF', 'HEIC', 'HEIF' );

    /** AI 审核开启时多取的候选数量（备胎，防 AI 拒掉后图不够） */
    const REVIEW_BUFFER = 3;
    /** AI 审核开启时最多翻的文件夹数（平时 5 个） */
    const REVIEW_MAX_FOLDERS = 8;

    private $host;
    private $api_key;
    private $username;
    private $password;

    public function __construct() {
        $this->host     = untrailingslashit( (string) YIMAI_GSO_Settings::get( 'mtphotos_host', 'https://photos.yimaiyoga.com' ) );
        $this->api_key  = (string) YIMAI_GSO_Settings::get( 'mtphotos_api_key', '' );
        $this->username = (string) YIMAI_GSO_Settings::get( 'mtphotos_username', '' );
        $this->password = (string) YIMAI_GSO_Settings::get( 'mtphotos_password', '' );
    }

    /* ------------------------------------------------------------------ */
    /* 认证                                                                 */
    /* ------------------------------------------------------------------ */

    private function get_cached_auth() {
        $auth = get_transient( self::TRANSIENT_KEY );
        return is_array( $auth ) ? $auth : array();
    }

    private function save_cached_auth( $auth ) {
        set_transient( self::TRANSIENT_KEY, $auth, self::AUTH_CODE_TTL );
    }

    /** 业务接口请求头：优先 jwt，其次 x-api-key */
    private function auth_headers() {
        $auth = $this->get_cached_auth();
        if ( ! empty( $auth['access_token'] ) ) {
            return array( 'jwt' => $auth['access_token'] );
        }
        if ( '' !== $this->api_key ) {
            return array( 'x-api-key' => $this->api_key );
        }
        // 无缓存 token 且无 api_key → 尝试账号密码登录
        if ( $this->login_with_password() ) {
            $auth = $this->get_cached_auth();
            if ( ! empty( $auth['access_token'] ) ) {
                return array( 'jwt' => $auth['access_token'] );
            }
        }
        return null;
    }

    /**
     * 账号密码登录：POST /auth/rsa 拿公钥 → RSA PKCS#1 v1.5 加密 → POST /auth/login
     * MT Photos 服务端开启 RSA 时，username 固定为 __MT_RSA_ENC_V2 / __MT_RSA_ENC，
     * password 为加密后的 base64（ver=2 时明文先 URL 编码）。
     */
    private function login_with_password() {
        if ( '' === $this->username || '' === $this->password ) {
            return false;
        }

        $rsa = $this->request( 'POST', '/auth/rsa', array( 'body' => array() ), true );
        if ( is_wp_error( $rsa ) ) {
            return false;
        }

        $login_user = $this->username;
        $login_pwd  = $this->password;
        $pub        = $rsa['publicKey'] ?? '';
        $ver        = intval( $rsa['ver'] ?? 1 );

        if ( $pub ) {
            $plain = wp_json_encode( array( 'u' => $this->username, 'p' => $this->password ), JSON_UNESCAPED_SLASHES );
            if ( 2 === $ver ) {
                $plain      = rawurlencode( $plain );
                $login_user = '__MT_RSA_ENC_V2';
            } else {
                $login_user = '__MT_RSA_ENC';
            }
            $login_pwd = $this->rsa_encrypt_b64( $pub, $plain );
            if ( false === $login_pwd ) {
                return false;
            }
        }

        $data = $this->request( 'POST', '/auth/login', array(
            'body' => array( 'username' => $login_user, 'password' => $login_pwd ),
        ), true );

        if ( is_wp_error( $data ) || empty( $data['access_token'] ) ) {
            return false;
        }

        $auth = $this->get_cached_auth();
        $auth['access_token']  = $data['access_token'];
        $auth['refresh_token'] = $data['refresh_token'] ?? ( $auth['refresh_token'] ?? '' );
        $this->save_cached_auth( $auth );
        return true;
    }

    /** RSA PKCS#1 v1.5 加密（复刻网页端 JSEncrypt），PEM 公钥 -> base64 密文 */
    private function rsa_encrypt_b64( $public_key_pem, $plain ) {
        if ( ! function_exists( 'openssl_public_encrypt' ) ) {
            return false;
        }
        // 允许 bare base64（无 PEM 头）格式
        $pem = $public_key_pem;
        if ( false === strpos( $pem, 'BEGIN' ) ) {
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $pem, 64, "\n" ) . '-----END PUBLIC KEY-----';
        }
        $ok = openssl_public_encrypt( $plain, $encrypted, $pem, OPENSSL_PKCS1_PADDING );
        return $ok ? base64_encode( $encrypted ) : false;
    }

    /** 取 auth_code（取图必须），优先 api_key，否则 refresh_token */
    private function ensure_auth_code( $force = false ) {
        $auth = $this->get_cached_auth();
        if ( ! $force && ! empty( $auth['auth_code'] ) && ! empty( $auth['auth_code_at'] )
            && ( time() - intval( $auth['auth_code_at'] ) ) < self::AUTH_CODE_TTL ) {
            return $auth['auth_code'];
        }

        $body = array();
        if ( '' !== $this->api_key ) {
            $body['api_key'] = $this->api_key;
        } elseif ( ! empty( $auth['refresh_token'] ) ) {
            $body['refresh_token'] = $auth['refresh_token'];
        } else {
            if ( ! $this->login_with_password() ) {
                return new WP_Error( 'mtphotos_no_auth', 'MT Photos 缺少 API Key 或账号密码，无法获取 auth_code' );
            }
            $auth = $this->get_cached_auth();
            if ( ! empty( $auth['refresh_token'] ) ) {
                $body['refresh_token'] = $auth['refresh_token'];
            } else {
                return new WP_Error( 'mtphotos_no_auth', 'MT Photos 登录成功但未返回 refresh_token' );
            }
        }

        $data = $this->request( 'POST', '/auth/auth_code', array( 'body' => $body ), true );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( empty( $data['auth_code'] ) ) {
            return new WP_Error( 'mtphotos_auth_code', 'MT Photos 未返回 auth_code：' . wp_json_encode( $data ) );
        }

        $auth['auth_code']    = $data['auth_code'];
        $auth['auth_code_at'] = time();
        $this->save_cached_auth( $auth );
        return $auth['auth_code'];
    }

    /* ------------------------------------------------------------------ */
    /* HTTP 封装                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $method GET|POST
     * @param string $path   以 / 开头，或完整 URL
     * @param array  $args   body(array) / params(array) / no_auth(bool)
     * @param bool   $no_auth 为 true 时不带业务鉴权头（auth/* 接口）
     */
    private function request( $method, $path, $args = array(), $no_auth = false ) {
        $url = ( 0 === strpos( $path, 'http' ) ) ? $path : $this->host . $path;
        if ( ! empty( $args['params'] ) ) {
            $url = add_query_arg( $args['params'], $url );
        }

        $headers = array( 'Accept' => 'application/json' );
        if ( ! $no_auth ) {
            $auth_headers = $this->auth_headers();
            if ( null === $auth_headers ) {
                return new WP_Error( 'mtphotos_no_auth', 'MT Photos 未配置 API Key 或账号密码' );
            }
            $headers = array_merge( $headers, $auth_headers );
        }

        $req = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => $headers,
        );
        if ( isset( $args['body'] ) ) {
            $req['headers']['Content-Type'] = 'application/json';
            $req['body'] = wp_json_encode( $args['body'], JSON_UNESCAPED_UNICODE );
        }

        $response = wp_remote_request( $url, $req );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // 401：清缓存后用账号密码重登重试一次
        if ( 401 === $code && ! $no_auth ) {
            delete_transient( self::TRANSIENT_KEY );
            if ( $this->login_with_password() ) {
                $auth_headers = $this->auth_headers();
                if ( null !== $auth_headers ) {
                    $req['headers'] = array_merge( $req['headers'], $auth_headers );
                    $response = wp_remote_request( $url, $req );
                    if ( ! is_wp_error( $response ) ) {
                        $code = wp_remote_retrieve_response_code( $response );
                        $data = json_decode( wp_remote_retrieve_body( $response ), true );
                    }
                }
            }
        }

        if ( $code >= 400 ) {
            return new WP_Error( 'mtphotos_http', "MT Photos HTTP {$code}：" . wp_remote_retrieve_body( $response ) );
        }

        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* 文件夹与文件                                                          */
    /* ------------------------------------------------------------------ */

    /** 名称判断：带水印目录（含「有水印」即算，兼容「_有水印」写法） */
    private function is_watermarked_folder( $name ) {
        return false !== strpos( (string) $name, '有水印' ) && false === strpos( (string) $name, '无水印' );
    }

    /**
     * 按关键词搜索带水印文件夹，返回 folderList 项
     * 口径：关键词只匹配「有水印」文件夹；搜不到时由调用方走全量兜底
     */
    public function search_watermarked_folders( $keyword ) {
        $out = array();
        $seen = array();

        $queries = array();
        $keyword = trim( (string) $keyword );
        if ( '' !== $keyword ) {
            $queries[] = $keyword;
            // 关键词本身可能是英文（配图关键词是英文的），同时按「有水印」全搜兜底
        }
        $queries[] = '有水印';

        foreach ( $queries as $q ) {
            $data = $this->request( 'GET', '/gateway/folders/search', array( 'params' => array( 'searchKey' => $q ) ) );
            if ( is_wp_error( $data ) ) {
                continue;
            }
            foreach ( (array) ( $data['folderList'] ?? array() ) as $folder ) {
                $id   = intval( $folder['id'] ?? 0 );
                $name = (string) ( $folder['folderName'] ?? $folder['name'] ?? '' );
                if ( $id <= 0 || isset( $seen[ $id ] ) || ! $this->is_watermarked_folder( $name ) ) {
                    continue;
                }
                $seen[ $id ] = true;
                $out[] = array( 'id' => $id, 'name' => $name );
            }
            // 第一轮关键词已命中就直接用，不再全量兜底
            if ( ! empty( $out ) && $q === $keyword ) {
                return $out;
            }
        }
        return $out;
    }

    /** 取文件夹内文件（不含子目录），只保留图片类型 */
    public function folder_image_files( $folder_id ) {
        $data = $this->request( 'GET', '/gateway/folderFiles/' . intval( $folder_id ), array(
            'params' => array( 'withSub' => 'no' ),
        ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $files = array();
        foreach ( $this->iter_grouped( $data ) as $item ) {
            $type = strtoupper( (string) ( $item['fileType'] ?? '' ) );
            if ( ! in_array( $type, self::IMAGE_TYPES, true ) ) {
                continue;
            }
            $md5 = $item['MD5'] ?? $item['md5'] ?? '';
            $id  = intval( $item['id'] ?? 0 );
            if ( $id <= 0 || '' === $md5 ) {
                continue;
            }
            $files[] = array(
                'id'     => $id,
                'md5'    => $md5,
                'name'   => (string) ( $item['fileName'] ?? '' ),
                'width'  => intval( $item['width'] ?? 0 ),
                'height' => intval( $item['height'] ?? 0 ),
            );
        }
        return $files;
    }

    /** 接口返回 {result:[{day,list:[...]}]} 或纯数组两种结构统一展开 */
    private function iter_grouped( $payload ) {
        if ( ! is_array( $payload ) ) {
            return array();
        }
        // 纯数组（list 形态）
        if ( array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ) {
            return $payload;
        }
        $out = array();
        foreach ( (array) ( $payload['result'] ?? array() ) as $group ) {
            if ( isset( $group['list'] ) && is_array( $group['list'] ) ) {
                foreach ( $group['list'] as $item ) {
                    $out[] = $item;
                }
            } elseif ( is_array( $group ) ) {
                $out[] = $group;
            }
        }
        return $out;
    }

    /** 构造图片 URL（带 auth_code；不带 type 参数为 1000px 预览图） */
    public function build_image_url( $file_id, $md5, $auth_code ) {
        return $this->host . '/gateway/file/' . intval( $file_id ) . '/' . rawurlencode( $md5 )
            . '?auth_code=' . rawurlencode( $auth_code );
    }

    /**
     * 选图主入口：按关键词匹配带水印文件夹 → 从中随机取 count 张不重复图片
     *
     * @param string $keyword 配图关键词（中文/英文均可）
     * @param int    $count   需要的图片数量
     * @param array  $context 文章上下文（title/keyword/excerpt），用于 AI 关联度审核；为空数组时跳过审核
     * @return array|WP_Error 与 fetch_from_pexels 一致的 {url, thumb, alt, source} 结构
     */
    public function pick_images( $keyword, $count, $context = array() ) {
        $log_prefix = '[YIMAI_GSO_IMG] ' . date( 'H:i:s' ) . ' ';
        $count      = max( 1, intval( $count ) );

        $auth_code = $this->ensure_auth_code();
        if ( is_wp_error( $auth_code ) ) {
            return $auth_code;
        }

        // AI 关联度审核：仅在开关开启且提供了文章上下文时生效（测试连接不传上下文，自动跳过）
        $review = $this->review_config( $context );

        $folders = $this->search_watermarked_folders( $keyword );
        if ( empty( $folders ) ) {
            error_log( $log_prefix . 'MTPhotos: 未找到带「_有水印」的文件夹（keyword=' . $keyword . '）' );
            return new WP_Error( 'mtphotos_no_folder', 'MT Photos 中未找到带水印的素材文件夹，请确认素材库存在「_有水印」目录' );
        }

        // 打乱文件夹顺序，避免每次都用同一个活动目录
        shuffle( $folders );

        $picked      = array();
        $picked_ids  = array();
        // AI 审核开启时多拿备胎：被拒后仍有图可用；同时多翻几个文件夹找候选
        $want        = $review['enabled'] ? $count + self::REVIEW_BUFFER : $count;
        $max_folders = min( $review['enabled'] ? self::REVIEW_MAX_FOLDERS : 5, count( $folders ) ); // 最多翻 5 个文件夹，控制请求数

        for ( $i = 0; $i < $max_folders && count( $picked ) < $want; $i++ ) {
            $files = $this->folder_image_files( $folders[ $i ]['id'] );
            if ( is_wp_error( $files ) || empty( $files ) ) {
                continue;
            }
            shuffle( $files );
            foreach ( $files as $f ) {
                if ( count( $picked ) >= $want ) {
                    break;
                }
                if ( isset( $picked_ids[ $f['id'] ] ) ) {
                    continue;
                }
                $picked_ids[ $f['id'] ] = true;
                $url = $this->build_image_url( $f['id'], $f['md5'], $auth_code );
                $picked[] = array(
                    'id'           => $f['id'],
                    'folder'       => $folders[ $i ]['name'],
                    'url'          => $url,
                    'thumb'        => $this->host . '/gateway/h220/' . rawurlencode( $f['md5'] ) . '?auth_code=' . rawurlencode( $auth_code ),
                    'photographer' => '',
                    'alt'          => $f['name'] ? preg_replace( '/\.[^.]+$/', '', $f['name'] ) : $keyword,
                    'source'       => 'MT Photos · ' . $folders[ $i ]['name'],
                );
            }
        }

        if ( empty( $picked ) ) {
            return new WP_Error( 'mtphotos_no_files', 'MT Photos 带水印文件夹中没有可用图片' );
        }

        error_log( $log_prefix . 'MTPhotos: 命中文件夹 ' . count( $folders ) . ' 个，选取 ' . count( $picked ) . ' 张（' . $folders[0]['name'] . ' 等）' );

        // ---- AI 关联度审核门：图片进正文前判定与文章的关联度 ----
        if ( $review['enabled'] ) {
            $picked = $this->apply_ai_review( $picked, $count, $review, $log_prefix );
            if ( is_wp_error( $picked ) ) {
                return $picked;
            }
        }

        // 审核用的内部字段（id/folder）不出仓
        $out = array();
        foreach ( array_slice( $picked, 0, $count ) as $img ) {
            unset( $img['id'], $img['folder'] );
            $out[] = $img;
        }
        return $out;
    }

    /**
     * 读取 AI 审核配置；未开启或缺少文章上下文时返回 enabled=false
     *
     * @param array $context 文章上下文（title/keyword/excerpt）
     * @return array { enabled:bool, threshold:int, failover:string }
     */
    private function review_config( $context ) {
        $enabled = (int) YIMAI_GSO_Settings::get( 'mtphotos_review_enabled', 1 ) === 1;
        if ( ! $enabled ) {
            return array( 'enabled' => false, 'threshold' => 0, 'failover' => 'use' );
        }

        // 没有文章上下文（如「测试连接」）就没有可判定对象，直接放行
        $title   = trim( (string) ( $context['title'] ?? '' ) );
        $keyword = trim( (string) ( $context['keyword'] ?? '' ) );
        if ( '' === $title && '' === $keyword ) {
            return array( 'enabled' => false, 'threshold' => 0, 'failover' => 'use' );
        }

        // 严格程度 → 关联分阈值（0-10）：宽松 3 / 标准 5 / 严格 7
        $level = (string) YIMAI_GSO_Settings::get( 'mtphotos_review_strictness', 'standard' );
        $threshold = in_array( $level, array( 'loose', 'strict' ), true )
            ? ( 'loose' === $level ? 3 : 7 )
            : 5;

        // AI 服务异常时的策略：use=照常用图 / reject=宁可无图
        $failover = (string) YIMAI_GSO_Settings::get( 'mtphotos_review_failover', 'use' );
        if ( 'reject' !== $failover ) {
            $failover = 'use';
        }

        return array(
            'enabled'   => true,
            'threshold' => $threshold,
            'failover'  => $failover,
            'title'     => $title,
            'keyword'   => $keyword,
            'excerpt'   => trim( (string) ( $context['excerpt'] ?? '' ) ),
        );
    }

    /**
     * AI 关联度审核：调用文章生成所用的 AI 模型，批量判定每张候选图与文章的关联度
     *
     * 判定依据是素材库的文件夹名 + 文件名（按拍摄场景/课程主题命名，是图片内容强信号）。
     * 关联分 ≥ 阈值且不涉敏感的图放行；其余弃用。全被拒时按策略返回错误或放行原始候选。
     *
     * @param array  $candidates 候选图片（含 folder 字段）
     * @param int    $count      最终需要的数量
     * @param array  $review     review_config() 的返回值
     * @param string $log_prefix 日志前缀
     * @return array|WP_Error 过滤后的图片列表（不含 id/folder 内部字段）
     */
    private function apply_ai_review( $candidates, $count, $review, $log_prefix ) {
        if ( ! class_exists( 'YIMAI_GSO_AI_Generator' ) ) {
            if ( 'reject' === $review['failover'] ) {
                return new WP_Error( 'mtphotos_review_unavailable', 'MT Photos 图片审核模块未加载，当前策略为宁可无图' );
            }
            return $candidates; // AI 模块缺失且策略为照常用图 → 放行
        }

        $generator = new YIMAI_GSO_AI_Generator();
        if ( '' === (string) YIMAI_GSO_Settings::get( 'ai_api_key', '' ) ) {
            if ( 'reject' === $review['failover'] ) {
                return new WP_Error( 'mtphotos_review_unavailable', '未配置 AI API Key，无法执行 MT Photos 图片审核，当前策略为宁可无图' );
            }
            error_log( $log_prefix . 'MTPhotos审核: 未配置 AI API Key，按策略跳过审核直接用图' );
            return $candidates;
        }

        $limit = count( $candidates );
        $lines = array();
        foreach ( $candidates as $i => $img ) {
            $lines[] = sprintf( '%d. 文件夹=%s｜文件名=%s', $i + 1, $img['folder'], $img['alt'] );
        }

        $sys_prompt = "你是一位瑜伽品牌的文章配图审核员。\n" .
            "下面给出文章信息和若干候选配图（以素材库文件夹名和文件名作为图片内容线索）。\n" .
            "请逐张判定：\n" .
            "relevance：图片内容与文章主题的关联度，0-10 分（0=完全无关，10=高度相关）\n" .
            "suitable：图片是否适合随这篇文章公开（是/否）。下列任一情况判「否」：与文章主题完全无关、含不雅/敏感/不适合品牌公开的内容、明显是私人生活照/证件/聊天截图等非宣传素材\n" .
            "严格只返回 JSON 数组，格式：[{\"idx\":1,\"relevance\":8,\"suitable\":true},{\"idx\":2,\"relevance\":1,\"suitable\":false}]";

        $user_message = "文章标题：{$review['title']}\n" .
            "核心主题/关键词：{$review['keyword']}\n" .
            ( '' !== $review['excerpt'] ? "文章摘要：{$review['excerpt']}\n" : '' ) .
            "\n候选配图：\n" . implode( "\n", $lines ) . "\n\n" .
            "共 {$limit} 张，请对每张都给出判定。";

        $response = $generator->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user', 'content' => $user_message ),
        ), 1200, array( 'temperature' => 0.1 ) );

        if ( is_wp_error( $response ) ) {
            if ( 'reject' === $review['failover'] ) {
                return new WP_Error( 'mtphotos_review_failed', 'MT Photos 图片审核失败：' . $response->get_error_message() );
            }
            error_log( $log_prefix . 'MTPhotos审核: AI 调用失败，按策略跳过审核直接用图 - ' . $response->get_error_message() );
            return $candidates;
        }

        $content = (string) ( $response['choices'][0]['message']['content'] ?? '' );
        $content = preg_replace( '/^```json\s*/m', '', $content );
        $content = preg_replace( '/^```\s*/m', '', $content );
        $content = trim( $content );

        $items = json_decode( $content, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $items ) ) {
            if ( preg_match( '/\[[\s\S]*\]/', $content, $match ) ) {
                $items = json_decode( $match[0], true );
            }
        }
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $items ) || empty( $items ) ) {
            if ( 'reject' === $review['failover'] ) {
                return new WP_Error( 'mtphotos_review_invalid', 'MT Photos 图片审核返回的结果无法解析，当前策略为宁可无图' );
            }
            error_log( $log_prefix . 'MTPhotos审核: AI 返回非 JSON，按策略跳过审核直接用图' );
            return $candidates;
        }

        // idx → {relevance, suitable}
        $verdicts = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['idx'] ) ) {
                continue;
            }
            $idx = (int) $item['idx'];
            if ( $idx < 1 || $idx > $limit ) {
                continue;
            }
            $verdicts[ $idx ] = array(
                'relevance' => max( 0, min( 10, (int) ( $item['relevance'] ?? 0 ) ) ),
                'suitable'  => ! isset( $item['suitable'] ) || false !== filter_var( $item['suitable'], FILTER_VALIDATE_BOOLEAN ),
            );
        }

        if ( empty( $verdicts ) ) {
            if ( 'reject' === $review['failover'] ) {
                return new WP_Error( 'mtphotos_review_invalid', 'MT Photos 图片审核未返回任何有效判定，当前策略为宁可无图' );
            }
            error_log( $log_prefix . 'MTPhotos审核: AI 未返回有效判定，按策略跳过审核直接用图' );
            return $candidates;
        }

        $passed = array();
        $rejected = array();
        foreach ( $candidates as $i => $img ) {
            $v = $verdicts[ $i + 1 ] ?? null;
            if ( null === $v ) {
                // AI 漏判的图按未过审处理（宁缺勿滥），但策略为照常用图时保留
                if ( 'use' === $review['failover'] ) {
                    $passed[] = $img;
                } else {
                    $rejected[] = $img['folder'] . '/' . $img['alt'];
                }
                continue;
            }
            if ( $v['suitable'] && $v['relevance'] >= $review['threshold'] ) {
                $passed[] = $img;
            } else {
                $rejected[] = sprintf( '%s/%s(relevance=%d%s)', $img['folder'], $img['alt'], $v['relevance'], $v['suitable'] ? '' : ',不适合' );
            }
        }

        if ( ! empty( $rejected ) ) {
            error_log( $log_prefix . 'MTPhotos审核: 拒绝 ' . count( $rejected ) . ' 张 - ' . implode( '；', array_slice( $rejected, 0, 5 ) ) );
        }

        if ( empty( $passed ) ) {
            if ( 'reject' === $review['failover'] ) {
                return new WP_Error( 'mtphotos_review_all_rejected', 'MT Photos 候选图片全部未通过 AI 关联度审核（阈值 ' . $review['threshold'] . '/10），当前策略为宁可无图' );
            }
            error_log( $log_prefix . 'MTPhotos审核: 全部候选被拒，按策略放行原候选' );
            return $candidates;
        }

        error_log( $log_prefix . 'MTPhotos审核: ' . count( $passed ) . '/' . count( $candidates ) . ' 张通过审核（阈值 ' . $review['threshold'] . '/10）' );
        return $passed;
    }
}
