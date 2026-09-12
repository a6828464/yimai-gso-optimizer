<?php
/**
 * 图片处理类 - 从图库获取或用AI生成配图，并上传到WordPress媒体库
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_OQ4C {

    private $provider;
    private $api_key;

    public function __construct( $api_key = null, $provider = null ) {
        // 测试连接时使用传入的provider，否则使用设置中的provider
        $this->provider = $provider ?: WPAIP_9XCU::get( 'image_provider', 'pexels' );
        $this->api_key  = $api_key;

        if ( ! $this->api_key ) {
            switch ( $this->provider ) {
                case 'pexels':
                    $this->api_key = WPAIP_9XCU::get( 'pexels_api_key', '' );
                    break;
                case 'unsplash':
                    $this->api_key = WPAIP_9XCU::get( 'unsplash_api_key', '' );
                    break;
                case 'pixabay':
                    $this->api_key = WPAIP_9XCU::get( 'image_api_key', '' );
                    break;
                case 'flickr':
                    $this->api_key = WPAIP_9XCU::get( 'flickr_api_key', '' );
                    break;
                case 'stockapi':
                    $this->api_key = WPAIP_9XCU::get( 'stockapi_api_key', '' );
                    break;
                case 'custom':
                    $this->api_key = WPAIP_9XCU::get( 'custom_img_api_key', '' );
                    break;
                case 'dalle':
                    $this->api_key = WPAIP_9XCU::get( 'ai_api_key', '' );
                    break;
                case 'ai':
                    // AI 生图模式下，API Key 和 provider 由 WPAIP_6DVW 内部管理
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
    public function fetch_images( $keyword, $count = 3 ) {
        $log_prefix = '[WPAIP_IMG] ' . date( 'H:i:s' ) . ' ';
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
        // 确保关键词为英文（图库搜索）
        $keyword = $this->sanitize_keyword( $keyword );

        $result = null;
        switch ( $this->provider ) {
            case 'local':
                if ( $wp_debug ) {
                    error_log( $log_prefix . '调用 fetch_from_local_library()' );
                }
                $result = $this->fetch_from_local_library( $keyword, $count );
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
        $log_prefix = '[WPAIP_IMG] ' . date( 'H:i:s' ) . ' ';

        if ( ! class_exists( 'WPAIP_UY9D' ) ) {
            error_log( $log_prefix . 'fetch_from_local_library: WPAIP_UY9D 类不存在！' );
            return new WP_Error( 'no_library', '图片库模块未加载' );
        }

        // 读取图片库配置
        $config = WPAIP_9XCU::get( 'local_image_config', '' );
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
        $images = WPAIP_UY9D::select_images( $keyword, $count, $group_id );

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

        $dalle_model = WPAIP_9XCU::get( 'dalle_model', 'dall-e-3' );
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
     * AI 生成图片（委托给 WPAIP_6DVW）
     *
     * @param string $keyword 图片关键词/提示词
     * @param int    $count   生成数量
     * @return array|WP_Error
     */
    private function generate_with_ai( $keyword, $count ) {
        if ( ! class_exists( 'WPAIP_6DVW' ) ) {
            return new WP_Error( 'no_class', 'AI 图片生成器未加载' );
        }

        $generator = new WPAIP_6DVW();
        $result = $generator->generate( $keyword, $count );

        return $result;
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
    public function upload_image_to_media( $image_url, $filename = '', $alt_text = '', $timeout = 60 ) {        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // 下载图片到临时文件
        $tmp = download_url( $image_url, $timeout );
        if ( is_wp_error( $tmp ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIP_IMG] download failed for: ' . $image_url . ' → ' . $tmp->get_error_message() );
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

            // 下载并上传到媒体库（v2.9.71: 30s→20s，避免多图累计拖垮发布流程）
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
                    error_log( '[WPAIP_IMG] 下载图片失败: ' . $img_url . ' → ' . $attach_id->get_error_message() );
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
