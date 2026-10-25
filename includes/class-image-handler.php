<?php
/**
 * 图片处理类 - 从图库获取或用AI生成配图，并上传到WordPress媒体库
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Image_Handler {

    private $provider;
    private $api_key;

    public function __construct( $api_key = null, $provider = null ) {
        // 测试连接时使用传入的provider，否则使用设置中的provider
        $this->provider = $provider ?: YIMAI_GSO_Settings::get( 'image_provider', 'pexels' );
        $this->api_key  = $api_key;

        if ( ! $this->api_key ) {
            switch ( $this->provider ) {
                case 'pexels':
                    $this->api_key = YIMAI_GSO_Settings::get( 'pexels_api_key', '' );
                    break;
                case 'unsplash':
                    $this->api_key = YIMAI_GSO_Settings::get( 'unsplash_api_key', '' );
                    break;
                case 'pixabay':
                    $this->api_key = YIMAI_GSO_Settings::get( 'image_api_key', '' );
                    break;
                case 'flickr':
                    $this->api_key = YIMAI_GSO_Settings::get( 'flickr_api_key', '' );
                    break;
                case 'stockapi':
                    $this->api_key = YIMAI_GSO_Settings::get( 'stockapi_api_key', '' );
                    break;
                case 'custom':
                    $this->api_key = YIMAI_GSO_Settings::get( 'custom_img_api_key', '' );
                    break;
                case 'mtphotos':
                    // MT Photos 走自建 client（api_key 或账号密码均可），无需在此预设 key
                    $this->api_key = 'delegated';
                    break;
                case 'dalle':
                    $this->api_key = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
                    break;
                case 'ai':
                    // AI 生图模式下，API Key 和 provider 由 YIMAI_GSO_AI_Image_Generator 内部管理
                    $this->api_key = 'delegated';
                    break;
                case 'local':
                    // 本地图片库不需要 API key
                    $this->api_key = '';
                    break;
            }
        }
    }

    /**
     * 获取图片列表（返回图片URL数组）
     */
    public function fetch_images( $keyword, $count = 3, $context = array() ) {
        $log_prefix = '[YIMAI_GSO_IMG] ' . date( 'H:i:s' ) . ' ';
        $wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

        if ( $wp_debug ) {
            error_log( $log_prefix . 'fetch_images() 被调用 | provider=' . $this->provider . ' | keyword=' . substr( $keyword, 0, 80 ) . ' | count=' . $count );
        }

        if ( empty( $keyword ) ) {
            $keyword = 'technology business';
            if ( $wp_debug ) {
                error_log( $log_prefix . 'keyword 为空，使用默认值: ' . $keyword );
            }
        }
        // MT Photos 关键词需要保留中文（文件夹名是中文），其他外部图库保留英文 sanitize
        if ( 'mtphotos' !== $this->provider ) {
            $keyword = $this->sanitize_keyword( $keyword );
        }

        $result = null;
        switch ( $this->provider ) {
            case 'local':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 fetch_from_local_library()' );
                }
                $result = $this->fetch_from_local_library( $keyword, $count );
                break;
            case 'mtphotos':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 fetch_from_mtphotos()' );
                }
                $result = $this->fetch_from_mtphotos( $keyword, $count, $context );
                break;
            case 'pexels':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 fetch_from_pexels()' );
                }
                $result = $this->fetch_from_pexels( $keyword, $count );
                break;
            case 'unsplash':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 fetch_from_unsplash()' );
                }
                $result = $this->fetch_from_unsplash( $keyword, $count );
                break;
            case 'pixabay':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 fetch_from_pixabay()' );
                }
                $result = $this->fetch_from_pixabay( $keyword, $count );
                break;
            case 'dalle':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 generate_with_dalle()' );
                }
                $result = $this->generate_with_dalle( $keyword, $count );
                break;
            case 'ai':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 AI 生成图片 (provider=' . $this->provider . ')' );
                }
                $result = $this->generate_with_ai( $keyword, $count );
                break;
            default:
                if ( $wp_debug ) {
                    error_log( $log_prefix . 'WARNING: provider=' . $this->provider . ' 进入 default 分支，回退到 Pexels！' );
                }
                $result = $this->fetch_from_pexels( $keyword, $count );
                break;
        }

        // 记录返回结果（仅 WP_DEBUG 开启时）
        if ( $wp_debug ) {
            if ( is_wp_error( $result ) ) {
                error_log( $log_prefix . '返回 WP_Error: ' . $result->get_error_message() );
            } elseif ( is_array( $result ) ) {
                error_log( $log_prefix . '返回 ' . count( $result ) . ' 张图片:' );
                foreach ( $result as $idx => $img ) {
                    error_log( $log_prefix . '  图片' . ( $idx + 1 ) . ': url=' . ( $img['url'] ?? 'N/A' ) . ' | source=' . ( $img['source'] ?? 'N/A' ) );
                }
            } else {
                error_log( $log_prefix . '返回异常类型: ' . gettype( $result ) );
            }
        }

        return $result;
    }

    /**
     * 从本地图片库选取图片（权重+使用次数均衡算法）
     */
    private function fetch_from_local_library( $keyword, $count ) {
        $log_prefix = '[YIMAI_GSO_IMG] ' . date( 'H:i:s' ) . ' ';

        if ( ! class_exists( 'YIMAI_GSO_Image_Library' ) ) {
            error_log( $log_prefix . 'fetch_from_local_library: YIMAI_GSO_Image_Library 类不存在！' );
            return new WP_Error( 'no_library', '图片库模块未加载' );
        }

        // 读取图片库配置
        $config = YIMAI_GSO_Settings::get( 'local_image_config', '' );
        if ( is_string( $config ) && ! empty( $config ) ) {
            $config = json_decode( $config, true );
        }
        if ( ! is_array( $config ) ) {
            $config = array();
        }

        $group_id   = absint( $config['group_id'] ?? 0 );
        $min_weight = max( 1, absint( $config['min_weight'] ?? 1 ) );

        error_log( $log_prefix . "fetch_from_local_library: keyword=" . substr( $keyword, 0, 80 ) . " | count={$count} | group_id={$group_id} | min_weight={$min_weight}" );

        // 从图片库选图
        $images = YIMAI_GSO_Image_Library::select_images( $keyword, $count, $group_id );

        if ( is_wp_error( $images ) ) {
            error_log( $log_prefix . 'fetch_from_local_library: select_images 返回 WP_Error: ' . $images->get_error_message() );
            return $images;
        }

        if ( ! is_array( $images ) || empty( $images ) ) {
            error_log( $log_prefix . 'fetch_from_local_library: select_images 返回空或异常，将可能导致外部图片被使用！' );
        } else {
            error_log( $log_prefix . "fetch_from_local_library: 成功选取 " . count( $images ) . " 张图片:" );
            foreach ( $images as $idx => $img ) {
                error_log( $log_prefix . "  图片" . ( $idx + 1 ) . ": url=" . ( $img['url'] ?? 'N/A' ) . " | source=" . ( $img['source'] ?? 'N/A' ) );
            }
        }

        return $images;
    }

    /**
     * 从 MT Photos（NAS 素材库）选取带水印图片
     *
     * @param string $keyword 配图关键词
     * @param int    $count   数量
     * @param array  $context 文章上下文（title/keyword/excerpt），用于 AI 关联度审核
     */
    private function fetch_from_mtphotos( $keyword, $count, $context = array() ) {
        if ( ! class_exists( 'YIMAI_GSO_MTPhotos_Client' ) ) {
            return new WP_Error( 'no_mtphotos', 'MT Photos 模块未加载' );
        }
        $client = new YIMAI_GSO_MTPhotos_Client();
        return $client->pick_images( $keyword, $count, $context );
    }

    /**
     * 从 Pexels 获取图片
     */
    private function fetch_from_pexels( $keyword, $count ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', '请在设置中填写 Pexels API Key（免费申请：pexels.com/api）' );
        }

        $url = add_query_arg( array(
            'query'       => urlencode( $keyword ),
            'per_page'    => $count,
            'orientation' => 'landscape',
        ), 'https://api.pexels.com/v1/search' );

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array( 'Authorization' => $this->api_key ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $images = array();

        foreach ( ( $body['photos'] ?? array() ) as $photo ) {
            $images[] = array(
                'url'          => $photo['src']['large2x'] ?? $photo['src']['large'],
                'thumb'        => $photo['src']['medium'],
                'photographer' => $photo['photographer'] ?? '',
                'alt'          => $photo['alt'] ?? $keyword,
                'source'       => 'Pexels',
            );
        }

        if ( empty( $images ) ) {
            return new WP_Error( 'no_results', "未找到关键词「{$keyword}」的图片，请尝试其他关键词" );
        }

        return $images;
    }

    /**
     * 从 Unsplash 获取图片
     */
    private function fetch_from_unsplash( $keyword, $count ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', '请在设置中填写 Unsplash Access Key' );
        }

        $url = add_query_arg( array(
            'query'       => urlencode( $keyword ),
            'per_page'    => $count,
            'orientation' => 'landscape',
        ), 'https://api.unsplash.com/search/photos' );

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array( 'Authorization' => 'Client-ID ' . $this->api_key ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $images = array();

        foreach ( ( $body['results'] ?? array() ) as $photo ) {
            $images[] = array(
                'url'          => $photo['urls']['regular'],
                'thumb'        => $photo['urls']['thumb'],
                'photographer' => $photo['user']['name'] ?? '',
                'alt'          => $photo['alt_description'] ?? $keyword,
                'source'       => 'Unsplash',
            );
        }

        if ( empty( $images ) ) {
            return new WP_Error( 'no_results', "未找到关键词「{$keyword}」的图片" );
        }

        return $images;
    }

    /**
     * 从 Pixabay 获取图片
     */
    private function fetch_from_pixabay( $keyword, $count ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', '请在设置中填写 Pixabay API Key' );
        }

        $url = add_query_arg( array(
            'key'          => $this->api_key,
            'q'            => urlencode( $keyword ),
            'per_page'     => $count,
            'image_type'   => 'photo',
            'orientation'  => 'horizontal',
            'safesearch'   => 'true',
        ), 'https://pixabay.com/api/' );

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $images = array();

        foreach ( ( $body['hits'] ?? array() ) as $photo ) {
            $images[] = array(
                'url'          => $photo['largeImageURL'],
                'thumb'        => $photo['previewURL'],
                'photographer' => $photo['user'] ?? '',
                'alt'          => $keyword,
                'source'       => 'Pixabay',
            );
        }

        if ( empty( $images ) ) {
            return new WP_Error( 'no_results', "未找到关键词「{$keyword}」的图片" );
        }

        return $images;
    }

    /**
     * DALL-E AI 生成图片
     */
    private function generate_with_dalle( $keyword, $count ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', '请在设置中填写 OpenAI API Key' );
        }

        $dalle_model = YIMAI_GSO_Settings::get( 'dalle_model', 'dall-e-3' );
        $images      = array();
        $gen_count   = min( $count, 3 ); // DALL-E 3 每次最多1张，这里循环

        for ( $i = 0; $i < $gen_count; $i++ ) {
            $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => json_encode( array(
                    'model'   => $dalle_model,
                    'prompt'  => "A high quality photo about: {$keyword}, photorealistic, no text",
                    'n'       => 1,
                    'size'    => '1792x1024',
                ) ),
            ) );

            if ( is_wp_error( $response ) ) {
                continue;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $url  = $body['data'][0]['url'] ?? '';
            if ( $url ) {
                $images[] = array(
                    'url'          => $url,
                    'thumb'        => $url,
                    'photographer' => 'DALL-E AI',
                    'alt'          => $keyword,
                    'source'       => 'DALL-E',
                );
            }
        }

        if ( empty( $images ) ) {
            return new WP_Error( 'no_results', 'DALL-E 生成图片失败，请检查 API Key 或额度' );
        }

        return $images;
    }

    /**
     * AI 生成图片（委托给 YIMAI_GSO_AI_Image_Generator）
     *
     * @param string $keyword 图片关键词/提示词
     * @param int    $count   生成数量
     * @return array|WP_Error
     */
    private function generate_with_ai( $keyword, $count ) {
        if ( ! class_exists( 'YIMAI_GSO_AI_Image_Generator' ) ) {
            return new WP_Error( 'no_class', 'AI 图片生成器未加载' );
        }

        $generator = new YIMAI_GSO_AI_Image_Generator();
        $result = $generator->generate( $keyword, $count );

        return $result;
    }

    /**
     * 校验远端图片 URL，防止 SSRF（安全修正 S3）
     *
     * upload_image_to_media() 会把 URL 交给 download_url() 发起服务端 GET。
     * 若 URL 由用户提交（ajax_upload_image），攻击者可借服务端身份探测内网。
     * 本方法在真正下载前拦截：
     *   1. 只允许 http / https 协议（拒绝 file:// gopher:// dict:// 等）；
     *   2. 解析主机名后校验其所有解析结果，任一落在私有/保留网段即拒绝；
     *   3. 同时拒绝字面量 IP 形式的内网地址与常见内部主机名。
     *
     * 说明：DNS 重绑定无法在纯 PHP 层完全消除（解析与连接之间存在窗口），
     * 但对本站的实际威胁模型（低权限账号探测内网）已构成有效阻断。
     *
     * @param string $url 待校验的 URL
     * @return true|WP_Error
     */
    public static function validate_remote_image_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return new WP_Error( 'yimai_gso_ssrf_empty', '图片URL不能为空。' );
        }

        $parts  = wp_parse_url( $url );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = (string) ( $parts['host'] ?? '' );

        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'yimai_gso_ssrf_scheme', '只允许 http 或 https 协议的图片地址。' );
        }
        if ( '' === $host ) {
            return new WP_Error( 'yimai_gso_ssrf_host', '图片地址缺少有效主机名。' );
        }

        $host_lc = strtolower( trim( $host, '[]' ) );

        // 常见内部主机名直接拒绝
        // 注意：此处不能用 str_ends_with()（PHP 8.0+），插件声明兼容 PHP 7.4，
        // 必须用 substr 比较以免引入新的版本兼容缺陷。
        foreach ( array( 'localhost', 'localhost.localdomain', 'metadata', 'metadata.google.internal' ) as $blocked ) {
            if ( $host_lc === $blocked ) {
                return new WP_Error( 'yimai_gso_ssrf_host', '出于安全考虑，不允许下载该地址的图片。' );
            }
            $suffix = '.' . $blocked;
            if ( strlen( $host_lc ) > strlen( $suffix ) && substr( $host_lc, -strlen( $suffix ) ) === $suffix ) {
                return new WP_Error( 'yimai_gso_ssrf_host', '出于安全考虑，不允许下载该地址的图片。' );
            }
        }

        // 收集待检查的 IP：字面量 IP，或主机名解析出的所有地址
        $ips = array();
        if ( filter_var( $host_lc, FILTER_VALIDATE_IP ) ) {
            $ips[] = $host_lc;
        } else {
            $records = @gethostbynamel( $host_lc );
            if ( is_array( $records ) ) {
                $ips = $records;
            }
            // gethostbynamel 只返回 IPv4；再尝试 AAAA
            if ( function_exists( 'dns_get_record' ) ) {
                $aaaa = @dns_get_record( $host_lc, DNS_AAAA );
                if ( is_array( $aaaa ) ) {
                    foreach ( $aaaa as $rec ) {
                        if ( ! empty( $rec['ipv6'] ) ) {
                            $ips[] = $rec['ipv6'];
                        }
                    }
                }
            }
        }

        // 一个地址都解析不出来 → 让后续下载自然失败，这里放行以免误伤
        foreach ( $ips as $ip ) {
            if ( ! self::is_public_ip( $ip ) ) {
                return new WP_Error( 'yimai_gso_ssrf_private', '出于安全考虑，不允许下载内网或保留地址的图片。' );
            }
        }

        return true;
    }

    /**
     * 判断 IP 是否属于公网可路由地址（非私有/保留/环回/链路本地）
     *
     * @param string $ip
     * @return bool
     */
    private static function is_public_ip( $ip ) {
        // FILTER_FLAG_NO_PRIV_RANGE 排除 10/8、172.16/12、192.168/16、fc00::/7
        // FILTER_FLAG_NO_RES_RANGE  排除 0.0.0.0/8、169.254/16、127/8、::1、240/4 等
        $is_ip = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return false !== $is_ip;
    }

    /**
     * 将图片下载并上传到 WordPress 媒体库
     * 返回 attachment ID
     *
     * @param string $image_url 图片URL
     * @param string $filename  自定义文件名
     * @param string $alt_text  alt文本
     * @param int    $timeout   下载超时（秒），默认60
     * @return int|WP_Error
     */
    public function upload_image_to_media( $image_url, $filename = '', $alt_text = '', $timeout = 60 ) {
        // SSRF 防护：无论调用方是否已校验，这里再校验一次作为最终防线
        $ssrf_check = self::validate_remote_image_url( $image_url );
        if ( is_wp_error( $ssrf_check ) ) {
            return $ssrf_check;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // 下载图片到临时文件
        $tmp = download_url( $image_url, $timeout );
        if ( is_wp_error( $tmp ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI_GSO_IMG] download failed for: ' . $image_url . ' → ' . $tmp->get_error_message() );
            }
            return $tmp;
        }

        // 获取文件扩展名
        $ext = pathinfo( $image_url, PATHINFO_EXTENSION );
        if ( empty( $ext ) || strlen( $ext ) > 4 ) {
            $ext = 'jpg';
        }
        $ext = strtok( $ext, '?' ); // 去除URL参数

        if ( empty( $filename ) ) {
            $filename = 'ai-image-' . time() . '-' . wp_rand( 100, 999 ) . '.' . $ext;
        }

        $file_array = array(
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, 0, $alt_text );

        // 清理临时文件
        @unlink( $tmp );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        // 设置 alt text
        if ( $alt_text ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
        }

        return $attachment_id;
    }

    /**
     * 确保关键词格式正确
     */
    private function sanitize_keyword( $keyword ) {
        // 移除特殊字符，保留字母数字空格
        return preg_replace( '/[^\w\s,]/u', ' ', $keyword );
    }

    /**
     * 测试 API 连通性
     */
    public function test_connection() {
        if ( 'mtphotos' === $this->provider ) {
            if ( ! class_exists( 'YIMAI_GSO_MTPhotos_Client' ) ) {
                return new WP_Error( 'no_mtphotos', 'MT Photos 模块未加载' );
            }
            $client = new YIMAI_GSO_MTPhotos_Client();
            $result = $client->pick_images( '', 1 );
            return is_wp_error( $result ) ? $result : true;
        }

        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', '请填写图片 API Key' );
        }

        $result = $this->fetch_images( 'nature', 1 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * 下载外链图片并替换为本地媒体库图片
     * 每张图只上传一次，返回处理后的正文和图片数量
     *
     * @param string $content       文章正文HTML
     * @param string $image_keyword 配图关键词（兼容旧调用）
     * @param array  $context       文章上下文
     * @return array  [ 'content' => string, 'count' => int ]
     */
    public function download_and_replace_images( $content, $image_keyword = '', $context = array() ) {
        // ALT 生成已改为 transition_post_status 钩子（优先级10），不再在下载流程中处理

        $result = array( 'content' => $content, 'count' => 0, 'first_attach_id' => 0 );


        // 匹配所有 <img src="xxx"> 标签
        $pattern = '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>/i';

        if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            return $result; // 正文中没有图片
        }

        $replaced   = array(); // 避免重复下载同一张图
        $home_url   = home_url();
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        foreach ( $matches as $match ) {
            $full_tag = $match[0]; // 完整的 <img ...> 标签
            $img_url  = html_entity_decode( $match[1], ENT_QUOTES ); // 解码 &amp; → &（豆包URL含签名参数）

            // 跳过已经是本地的图片
            if ( strpos( $img_url, $home_url ) !== false ) {
                continue;
            }

            // 跳过 base64 图片
            if ( strpos( $img_url, 'data:image' ) === 0 ) {
                continue;
            }

            // 同一张图不重复下载
            if ( isset( $replaced[ $img_url ] ) ) {
                $result['content'] = str_replace( $full_tag, $replaced[ $img_url ]['tag'], $result['content'] );
                continue;
            }

            // 提取 alt 属性
            $alt = '';
            if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $full_tag, $alt_match ) ) {
                $alt = $alt_match[1];
            }

            // 下载并上传到媒体库（30s→20s，避免多图累计拖垮发布流程）
            $attach_id = $this->upload_image_to_media( $img_url, '', $alt, 20 );

            if ( ! is_wp_error( $attach_id ) ) {
                $local_url = wp_get_attachment_url( $attach_id );

                // 替换为新标签（使用本地URL）
                $new_tag = str_replace( $img_url, $local_url, $full_tag );
                $result['content'] = str_replace( $full_tag, $new_tag, $result['content'] );
                $replaced[ $img_url ] = array( 'tag' => $new_tag, 'attach_id' => $attach_id );
                $result['count']++;

                // 记录第一张成功上传的图片ID（用于封面）
                if ( $result['first_attach_id'] === 0 ) {
                    $result['first_attach_id'] = $attach_id;
                }
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[YIMAI_GSO_IMG] 下载图片失败: ' . $img_url . ' → ' . $attach_id->get_error_message() );
                }
            }
        }

        return $result;
    }

    /**
     * 通过本地图片URL获取 attachment ID
     */
    public function url_to_attachment_id( $url ) {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // 将完整URL转为相对路径
        $relative = str_replace( $base_url . '/', '', $url );
        $relative = str_replace( $base_url, '', $relative );
        $relative = ltrim( $relative, '/' );

        // 查询 attachment
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
            $relative
        ) );

        return $attachment_id ? absint( $attachment_id ) : 0;
    }
}
